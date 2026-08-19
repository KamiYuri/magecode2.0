// Package health exposes the HTTP liveness endpoint D-72 requires of every
// service.
//
// The batch workers are queue consumers with no HTTP surface of their own, so
// without this compose has nothing to ask: a container whose consumer died but
// whose process is still running would sit in the service list looking fine.
// The mux is exported because G1 hangs `/metrics` on the same listener (U-10).
package health

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"time"
)

// Path is the liveness endpoint. Compose's healthcheck asks for this.
const Path = "/healthz"

const shutdownGrace = 5 * time.Second

// Server is the worker's HTTP listener.
type Server struct {
	mux    *http.ServeMux
	server *http.Server
	log    *slog.Logger
	// bound is the address the listener actually took, which differs from
	// the configured one when the port is 0.
	bound string
}

// New returns a server listening on addr (host:port) with the liveness route
// registered. `ready` reports whether the service is able to work; a service
// that has lost its broker answers 503 so compose restarts it rather than
// leaving a container that consumes nothing.
func New(addr string, ready func() bool, log *slog.Logger) *Server {
	mux := http.NewServeMux()

	mux.HandleFunc(Path, func(w http.ResponseWriter, _ *http.Request) {
		if ready != nil && !ready() {
			w.WriteHeader(http.StatusServiceUnavailable)
			_, _ = fmt.Fprintln(w, "not ready")
			return
		}
		w.WriteHeader(http.StatusOK)
		_, _ = fmt.Fprintln(w, "ok")
	})

	return &Server{
		mux:    mux,
		server: &http.Server{Addr: addr, Handler: mux, ReadHeaderTimeout: 5 * time.Second},
		log:    log,
	}
}

// Mux is where G1 registers /metrics.
func (s *Server) Mux() *http.ServeMux { return s.mux }

// Start binds the listener and serves in the background. It returns an error
// only when the address cannot be bound, which is a misconfiguration worth
// failing on rather than a worker that silently has no health endpoint.
func (s *Server) Start(ctx context.Context) error {
	// net.Listen("tcp", "") binds a *random* port on every interface, so an
	// unset address does not fail — it produces a health server nothing can
	// find, and a container that looks broken for a reason nobody can see.
	// Found exactly that way in E8.
	if s.server.Addr == "" {
		return errors.New("health server has no listen address")
	}

	listener, err := net.Listen("tcp", s.server.Addr)
	if err != nil {
		return fmt.Errorf("binding %s: %w", s.server.Addr, err)
	}

	s.bound = listener.Addr().String()

	go func() {
		if err := s.server.Serve(listener); err != nil && !errors.Is(err, http.ErrServerClosed) && s.log != nil {
			s.log.Error("health server stopped", "error", err.Error())
		}
	}()

	go func() {
		<-ctx.Done()
		s.Stop()
	}()

	return nil
}

// address is the bound listen address, empty before Start.
func (s *Server) address() string { return s.bound }

// Stop shuts the listener down, giving in-flight requests a moment to finish.
func (s *Server) Stop() {
	ctx, cancel := context.WithTimeout(context.Background(), shutdownGrace)
	defer cancel()
	_ = s.server.Shutdown(ctx)
}
