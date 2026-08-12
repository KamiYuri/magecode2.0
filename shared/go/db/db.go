// Package db provides the shared PostgreSQL pool for MageCode Go services.
// Connections go through PgBouncer in transaction pooling mode (D-89), which
// breaks server-side prepared statements — Connect therefore enforces pgx's
// simple query protocol on every DSN and fails fast on a conflicting mode.
package db

import (
	"context"
	"fmt"
	"net/url"
	"strings"
	"time"

	_ "github.com/jackc/pgx/v5/stdlib" // database/sql driver "pgx"
	"github.com/jmoiron/sqlx"

	"github.com/magecode/shared/go/apperror"
)

const (
	execModeParam   = "default_query_exec_mode"
	simpleProtocol  = "simple_protocol"
	defaultMaxOpen  = 10
	defaultMaxIdle  = 5
	defaultLifetime = 5 * time.Minute
)

// Config configures Connect. DSN is required (URL or keyword form); pool
// limits default to values safe under PgBouncer's pool_size=30 budget.
type Config struct {
	DSN             string
	MaxOpenConns    int
	MaxIdleConns    int
	ConnMaxLifetime time.Duration
}

func (c Config) withDefaults() Config {
	if c.MaxOpenConns == 0 {
		c.MaxOpenConns = defaultMaxOpen
	}
	if c.MaxIdleConns == 0 {
		c.MaxIdleConns = defaultMaxIdle
	}
	if c.ConnMaxLifetime == 0 {
		c.ConnMaxLifetime = defaultLifetime
	}
	return c
}

// Connect opens a sqlx pool over the pgx stdlib driver with the simple
// protocol enforced, applies pool limits, and verifies the connection with
// a ping before returning.
func Connect(ctx context.Context, cfg Config) (*sqlx.DB, error) {
	cfg = cfg.withDefaults()
	dsn, err := enforceSimpleProtocol(cfg.DSN)
	if err != nil {
		return nil, err
	}

	pool, err := sqlx.Open("pgx", dsn)
	if err != nil {
		return nil, apperror.Wrap(apperror.Permanent, "opening postgres pool", err)
	}
	pool.SetMaxOpenConns(cfg.MaxOpenConns)
	pool.SetMaxIdleConns(cfg.MaxIdleConns)
	pool.SetConnMaxLifetime(cfg.ConnMaxLifetime)

	if err := pool.PingContext(ctx); err != nil {
		_ = pool.Close()
		return nil, apperror.Wrap(apperror.Transient, "pinging postgres", err)
	}
	return pool, nil
}

// Healthcheck returns a ping function suitable for HTTP health endpoints
// (D-72).
func Healthcheck(pool *sqlx.DB) func(context.Context) error {
	return func(ctx context.Context) error {
		if err := pool.PingContext(ctx); err != nil {
			return apperror.Wrap(apperror.Transient, "database healthcheck", err)
		}
		return nil
	}
}

// enforceSimpleProtocol returns dsn with default_query_exec_mode set to
// simple_protocol, accepting both URL and keyword DSNs. A DSN that already
// pins a different exec mode is a misconfiguration that would break under
// PgBouncer transaction pooling, so it is rejected rather than overridden.
func enforceSimpleProtocol(dsn string) (string, error) {
	if strings.HasPrefix(dsn, "postgres://") || strings.HasPrefix(dsn, "postgresql://") {
		parsed, err := url.Parse(dsn)
		if err != nil {
			return "", apperror.Wrap(apperror.Permanent, "parsing postgres DSN", err)
		}
		query := parsed.Query()
		if mode := query.Get(execModeParam); mode != "" && mode != simpleProtocol {
			return "", apperror.New(apperror.Permanent, fmt.Sprintf(
				"DSN pins %s=%s but PgBouncer transaction pooling requires %s (D-89)",
				execModeParam, mode, simpleProtocol))
		}
		query.Set(execModeParam, simpleProtocol)
		parsed.RawQuery = query.Encode()
		return parsed.String(), nil
	}

	// Keyword DSN: "host=... port=... dbname=...".
	for _, field := range strings.Fields(dsn) {
		key, value, found := strings.Cut(field, "=")
		if found && key == execModeParam && value != simpleProtocol {
			return "", apperror.New(apperror.Permanent, fmt.Sprintf(
				"DSN pins %s=%s but PgBouncer transaction pooling requires %s (D-89)",
				execModeParam, value, simpleProtocol))
		}
		if found && key == execModeParam {
			return dsn, nil
		}
	}
	return dsn + " " + execModeParam + "=" + simpleProtocol, nil
}
