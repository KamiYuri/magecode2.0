//go:build integration

// Integration tests against the compose MinIO. Run with:
//
//	MINIO_TEST_ENDPOINT=localhost:9000 MINIO_TEST_ACCESS_KEY=... \
//	MINIO_TEST_SECRET_KEY=... MINIO_TEST_BUCKET=magecode \
//	  go test -tags integration ./storage/ -v
//
// The suite skips when MINIO_TEST_ENDPOINT is unset.
package storage_test

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net/http"
	"os"
	"testing"
	"time"

	"github.com/magecode/shared/go/storage"
)

func testConfig(t *testing.T) storage.Config {
	t.Helper()
	endpoint := os.Getenv("MINIO_TEST_ENDPOINT")
	if endpoint == "" {
		t.Skip("MINIO_TEST_ENDPOINT not set; start compose minio and export creds")
	}
	return storage.Config{
		Endpoint:  endpoint,
		AccessKey: os.Getenv("MINIO_TEST_ACCESS_KEY"),
		SecretKey: os.Getenv("MINIO_TEST_SECRET_KEY"),
		Bucket:    os.Getenv("MINIO_TEST_BUCKET"),
	}
}

func newClient(t *testing.T) *storage.Client {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	client, err := storage.New(ctx, testConfig(t))
	if err != nil {
		t.Fatalf("storage.New: %v", err)
	}
	return client
}

// putTestObject uploads content at a unique submission path.
func putTestObject(t *testing.T, client *storage.Client, content string) string {
	t.Helper()
	path := storage.SubmissionPath(999, time.Now().UnixNano(), t.Name()+".txt")
	err := client.Put(context.Background(), path, bytes.NewReader([]byte(content)),
		int64(len(content)), "text/plain")
	if err != nil {
		t.Fatalf("Put: %v", err)
	}
	return path
}

func TestPutGetRoundtrip(t *testing.T) {
	client := newClient(t)
	path := putTestObject(t, client, "package main")

	reader, err := client.Get(context.Background(), path)
	if err != nil {
		t.Fatalf("Get: %v", err)
	}
	defer reader.Close()
	body, err := io.ReadAll(reader)
	if err != nil {
		t.Fatalf("reading object: %v", err)
	}
	if string(body) != "package main" {
		t.Errorf("body = %q", body)
	}
}

func TestPresignedGetWithoutCredentials(t *testing.T) {
	client := newClient(t)
	path := putTestObject(t, client, "presigned content")

	url, err := client.PresignGet(context.Background(), path, time.Minute)
	if err != nil {
		t.Fatalf("PresignGet: %v", err)
	}

	// Plain http.Get carries no MinIO credentials — the signature in the
	// URL must be the only thing granting access (D-85).
	resp, err := http.Get(url)
	if err != nil {
		t.Fatalf("GET presigned URL: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("status = %d, want 200", resp.StatusCode)
	}
	body, _ := io.ReadAll(resp.Body)
	if string(body) != "presigned content" {
		t.Errorf("body = %q", body)
	}
}

func TestExpiredPresignedURLRejected(t *testing.T) {
	client := newClient(t)
	path := putTestObject(t, client, "soon gone")

	url, err := client.PresignGet(context.Background(), path, time.Second)
	if err != nil {
		t.Fatalf("PresignGet: %v", err)
	}
	time.Sleep(1500 * time.Millisecond)

	resp, err := http.Get(url)
	if err != nil {
		t.Fatalf("GET expired URL: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusForbidden {
		t.Errorf("status = %d, want 403 for expired signature", resp.StatusCode)
	}
}

func TestGetMissingObjectFails(t *testing.T) {
	client := newClient(t)

	_, err := client.Get(context.Background(), storage.SubmissionPath(1, 1, "nonexistent.go"))
	if err == nil {
		t.Error("Get on missing object succeeded")
	}
}

func TestNewRejectsMissingBucket(t *testing.T) {
	cfg := testConfig(t)
	cfg.Bucket = fmt.Sprintf("nonexistent-%d", time.Now().UnixNano())

	if _, err := storage.New(context.Background(), cfg); err == nil {
		t.Error("New accepted a nonexistent bucket")
	}
}
