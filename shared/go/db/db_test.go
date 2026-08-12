package db

import (
	"strings"
	"testing"
	"time"
)

func TestEnforceSimpleProtocolURLDSN(t *testing.T) {
	tests := []struct {
		name string
		dsn  string
		want string
	}{
		{
			"no query params",
			"postgres://user:pass@pgbouncer:6432/magecode",
			"postgres://user:pass@pgbouncer:6432/magecode?default_query_exec_mode=simple_protocol",
		},
		{
			"existing other params kept",
			"postgres://user:pass@pgbouncer:6432/magecode?sslmode=disable",
			"postgres://user:pass@pgbouncer:6432/magecode?default_query_exec_mode=simple_protocol&sslmode=disable",
		},
		{
			"already enforced unchanged",
			"postgres://user:pass@pgbouncer:6432/magecode?default_query_exec_mode=simple_protocol",
			"postgres://user:pass@pgbouncer:6432/magecode?default_query_exec_mode=simple_protocol",
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := enforceSimpleProtocol(tt.dsn)
			if err != nil {
				t.Fatalf("enforceSimpleProtocol returned error: %v", err)
			}
			if got != tt.want {
				t.Errorf("got  %q\nwant %q", got, tt.want)
			}
		})
	}
}

func TestEnforceSimpleProtocolKeywordDSN(t *testing.T) {
	got, err := enforceSimpleProtocol("host=pgbouncer port=6432 dbname=magecode")
	if err != nil {
		t.Fatalf("enforceSimpleProtocol returned error: %v", err)
	}
	if !strings.Contains(got, "default_query_exec_mode=simple_protocol") {
		t.Errorf("keyword DSN missing enforcement: %q", got)
	}
	if !strings.Contains(got, "host=pgbouncer") {
		t.Errorf("keyword DSN lost original params: %q", got)
	}
}

func TestEnforceSimpleProtocolRejectsConflictingMode(t *testing.T) {
	tests := []string{
		"postgres://u:p@pgbouncer:6432/magecode?default_query_exec_mode=cache_statement",
		"host=pgbouncer dbname=magecode default_query_exec_mode=exec",
	}
	for _, dsn := range tests {
		_, err := enforceSimpleProtocol(dsn)
		if err == nil {
			t.Errorf("expected error for conflicting exec mode in %q (D-89 fail-fast)", dsn)
			continue
		}
		if !strings.Contains(err.Error(), "simple_protocol") {
			t.Errorf("error %q does not explain the D-89 requirement", err)
		}
	}
}

func TestEnforceSimpleProtocolRejectsMalformedURL(t *testing.T) {
	if _, err := enforceSimpleProtocol("postgres://user:pass@[::bad/db"); err == nil {
		t.Error("expected error for malformed URL DSN")
	}
}

func TestConfigDefaults(t *testing.T) {
	cfg := Config{DSN: "postgres://x"}.withDefaults()

	if cfg.MaxOpenConns != 10 {
		t.Errorf("MaxOpenConns = %d, want 10", cfg.MaxOpenConns)
	}
	if cfg.MaxIdleConns != 5 {
		t.Errorf("MaxIdleConns = %d, want 5", cfg.MaxIdleConns)
	}
	if cfg.ConnMaxLifetime != 5*time.Minute {
		t.Errorf("ConnMaxLifetime = %v, want 5m", cfg.ConnMaxLifetime)
	}
}

func TestConfigExplicitValuesKept(t *testing.T) {
	cfg := Config{DSN: "postgres://x", MaxOpenConns: 25, MaxIdleConns: 2}.withDefaults()

	if cfg.MaxOpenConns != 25 {
		t.Errorf("MaxOpenConns = %d, want explicit 25", cfg.MaxOpenConns)
	}
	if cfg.MaxIdleConns != 2 {
		t.Errorf("MaxIdleConns = %d, want explicit 2", cfg.MaxIdleConns)
	}
}
