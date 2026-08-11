//go:build integration

// Integration tests against the compose PgBouncer (transaction pooling).
// Run with:
//
//	DB_TEST_DSN="postgres://user:pass@localhost:6432/magecode" \
//	  go test -tags integration ./db/ -v
//
// The suite skips when DB_TEST_DSN is unset.
package db_test

import (
	"context"
	"os"
	"testing"
	"time"

	"github.com/jmoiron/sqlx"

	"github.com/magecode/shared/go/db"
)

func testDSN(t *testing.T) string {
	t.Helper()
	dsn := os.Getenv("DB_TEST_DSN")
	if dsn == "" {
		t.Skip("DB_TEST_DSN not set; start compose pgbouncer and export it")
	}
	return dsn
}

func connect(t *testing.T) *sqlx.DB {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	pool, err := db.Connect(ctx, db.Config{DSN: testDSN(t)})
	if err != nil {
		t.Fatalf("Connect through pgbouncer: %v", err)
	}
	t.Cleanup(func() { _ = pool.Close() })
	return pool
}

// TestConnectThroughPgBouncer proves the pool works over :6432.
func TestConnectThroughPgBouncer(t *testing.T) {
	pool := connect(t)

	var one int
	if err := pool.Get(&one, "SELECT 1"); err != nil {
		t.Fatalf("SELECT 1: %v", err)
	}
	if one != 1 {
		t.Errorf("SELECT 1 = %d", one)
	}
}

// TestParameterizedQueriesUnderTransactionPooling runs prepared-style
// ($n placeholder) queries repeatedly. Under PgBouncer transaction pooling
// these fail with "prepared statement does not exist" unless the simple
// protocol is active — passing proves the D-89 enforcement works end to end.
func TestParameterizedQueriesUnderTransactionPooling(t *testing.T) {
	pool := connect(t)

	for i := 0; i < 10; i++ {
		var sum int
		if err := pool.Get(&sum, "SELECT $1::int + $2::int", i, 10); err != nil {
			t.Fatalf("iteration %d: parameterized query failed: %v", i, err)
		}
		if sum != i+10 {
			t.Errorf("iteration %d: sum = %d, want %d", i, sum, i+10)
		}
	}
}

// TestStructScanMapping exercises sqlx struct mapping through the pool.
func TestStructScanMapping(t *testing.T) {
	pool := connect(t)

	var row struct {
		Total    int    `db:"total"`
		Greeting string `db:"greeting"`
	}
	err := pool.Get(&row, "SELECT $1::int + $2::int AS total, $3::text AS greeting", 2, 3, "hello")
	if err != nil {
		t.Fatalf("struct scan: %v", err)
	}
	if row.Total != 5 || row.Greeting != "hello" {
		t.Errorf("row = %+v", row)
	}
}

func TestHealthcheck(t *testing.T) {
	pool := connect(t)
	ctx := context.Background()

	check := db.Healthcheck(pool)
	if err := check(ctx); err != nil {
		t.Errorf("healthcheck on live pool: %v", err)
	}

	_ = pool.Close()
	if err := check(ctx); err == nil {
		t.Error("healthcheck on closed pool must return an error")
	}
}

func TestConnectRejectsConflictingExecMode(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	dsn := testDSN(t) + "?default_query_exec_mode=cache_statement"
	if _, err := db.Connect(ctx, db.Config{DSN: dsn}); err == nil {
		t.Error("Connect accepted a DSN pinning a non-simple exec mode")
	}
}
