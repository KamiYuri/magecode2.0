package health

import (
	"context"
	"net/http"
	"testing"
	"time"
)

// Port 0 asks the kernel for a free port, so two tests never collide.

func TestLivenessAnswersOkWhenReady(t *testing.T) {
	server := New("127.0.0.1:0", nil, nil)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	if err := server.Start(ctx); err != nil {
		t.Fatalf("Start: %v", err)
	}
	defer server.Stop()

	response, err := http.Get("http://" + server.address() + Path)
	if err != nil {
		t.Fatalf("GET %s: %v", Path, err)
	}
	defer func() { _ = response.Body.Close() }()

	if response.StatusCode != http.StatusOK {
		t.Errorf("status = %d, want 200", response.StatusCode)
	}
}

// A worker that has lost its broker still has a process; without this the
// container looks healthy while consuming nothing (D-72).
func TestLivenessAnswers503WhenNotReady(t *testing.T) {
	healthy := false
	server := New("127.0.0.1:0", func() bool { return healthy }, nil)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	if err := server.Start(ctx); err != nil {
		t.Fatalf("Start: %v", err)
	}
	defer server.Stop()

	response, err := http.Get("http://" + server.address() + Path)
	if err != nil {
		t.Fatalf("GET: %v", err)
	}
	_ = response.Body.Close()
	if response.StatusCode != http.StatusServiceUnavailable {
		t.Errorf("status = %d, want 503", response.StatusCode)
	}

	healthy = true
	response, err = http.Get("http://" + server.address() + Path)
	if err != nil {
		t.Fatalf("GET: %v", err)
	}
	_ = response.Body.Close()
	if response.StatusCode != http.StatusOK {
		t.Errorf("status = %d after recovery, want 200", response.StatusCode)
	}
}

func TestCancellingTheContextStopsTheServer(t *testing.T) {
	server := New("127.0.0.1:0", nil, nil)
	ctx, cancel := context.WithCancel(context.Background())
	if err := server.Start(ctx); err != nil {
		t.Fatalf("Start: %v", err)
	}
	address := server.address()

	cancel()

	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if _, err := http.Get("http://" + address + Path); err != nil {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatal("the health server was still answering after its context was cancelled")
}

func TestAnUnbindableAddressIsReported(t *testing.T) {
	// A misconfigured port should fail loudly at startup rather than leaving
	// a worker with no health endpoint and a compose healthcheck that never
	// passes.
	server := New("256.256.256.256:1", nil, nil)

	if err := server.Start(context.Background()); err == nil {
		server.Stop()
		t.Fatal("Start accepted an address it cannot bind")
	}
}

// The empty address is the dangerous one: net.Listen takes it and binds a
// random port, so a service with an unset HEALTH_ADDR appears to start and
// then fails every probe. E8 lost a cycle to exactly this.
func TestAnEmptyAddressIsRefusedRatherThanBoundAtRandom(t *testing.T) {
	server := New("", nil, nil)

	if err := server.Start(context.Background()); err == nil {
		server.Stop()
		t.Fatal("Start accepted an empty address")
	}
}

func TestMuxIsAvailableForMetrics(t *testing.T) {
	if New("127.0.0.1:0", nil, nil).Mux() == nil {
		t.Error("Mux is nil, so G1 has nowhere to register /metrics")
	}
}
