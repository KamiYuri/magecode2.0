package downloader

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/magecode/shared/go/apperror"
)

func testClient(server *httptest.Server) *Client {
	return New(Config{
		MaxBytes: 64 * 1024,
		Attempts: 3,
		// Kept tiny so the retry tests do not pay real backoff.
		BaseDelay: time.Millisecond,
		Timeout:   2 * time.Second,
	})
}

func TestDownloadReturnsTheBody(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte("print('hello')\n"))
	}))
	defer server.Close()

	got, err := testClient(server).Download(context.Background(), server.URL)
	if err != nil {
		t.Fatalf("Download: %v", err)
	}
	if string(got) != "print('hello')\n" {
		t.Errorf("body = %q", got)
	}
}

// A pre-signed URL is served by MinIO on the backend network; a 5xx or a
// dropped connection is the network having a bad moment, which is exactly
// what a retry is for (rabbitmq-schemas §2.5).
func TestDownloadRetriesServerErrors(t *testing.T) {
	var calls atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		if calls.Add(1) < 3 {
			w.WriteHeader(http.StatusBadGateway)
			return
		}
		_, _ = w.Write([]byte("ok"))
	}))
	defer server.Close()

	got, err := testClient(server).Download(context.Background(), server.URL)
	if err != nil {
		t.Fatalf("Download: %v", err)
	}
	if string(got) != "ok" {
		t.Errorf("body = %q", got)
	}
	if calls.Load() != 3 {
		t.Errorf("server saw %d calls, want 3", calls.Load())
	}
}

func TestDownloadGivesUpAfterTheAttemptBudget(t *testing.T) {
	var calls atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls.Add(1)
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer server.Close()

	_, err := testClient(server).Download(context.Background(), server.URL)
	if err == nil {
		t.Fatal("Download succeeded against a server that never answered")
	}
	if !apperror.IsTransient(err) {
		t.Errorf("exhausted retries should stay Transient, got: %v", err)
	}
	if calls.Load() != 3 {
		t.Errorf("server saw %d calls, want 3", calls.Load())
	}
}

// 403 is what an expired pre-signed URL answers (D-85: 6h TTL). Retrying it
// three times only delays the error result api is waiting for.
func TestDownloadTreatsClientErrorsAsPermanent(t *testing.T) {
	var calls atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls.Add(1)
		w.WriteHeader(http.StatusForbidden)
	}))
	defer server.Close()

	_, err := testClient(server).Download(context.Background(), server.URL)
	if !apperror.IsPermanent(err) {
		t.Fatalf("a 403 should be Permanent, got: %v", err)
	}
	if calls.Load() != 1 {
		t.Errorf("server saw %d calls, want 1 — a 4xx must not be retried", calls.Load())
	}
	if !strings.Contains(err.Error(), "403") {
		t.Errorf("error should carry the status, got: %v", err)
	}
}

// D-34 caps a submission at 50KB, so anything larger is not a submission this
// system produced and reading it into memory is the wrong answer.
func TestDownloadRefusesOversizedBodies(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(strings.Repeat("x", 200)))
	}))
	defer server.Close()

	client := New(Config{MaxBytes: 100, Attempts: 1, BaseDelay: time.Millisecond, Timeout: time.Second})
	_, err := client.Download(context.Background(), server.URL)
	if !apperror.IsPermanent(err) {
		t.Fatalf("an oversized body should be Permanent, got: %v", err)
	}
}

func TestDownloadStopsWhenTheContextIsCancelled(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusBadGateway)
	}))
	defer server.Close()

	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	_, err := testClient(server).Download(ctx, server.URL)
	if err == nil {
		t.Fatal("Download ignored a cancelled context")
	}
}
