package logger_test

import (
	"bytes"
	"encoding/json"
	"errors"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"testing"
	"time"

	"github.com/magecode/shared/go/logger"
)

// decodeLine unmarshals a single JSON log line into a map.
func decodeLine(t *testing.T, buf *bytes.Buffer) map[string]any {
	t.Helper()
	var record map[string]any
	if err := json.Unmarshal(buf.Bytes(), &record); err != nil {
		t.Fatalf("output is not valid JSON: %v\nraw: %q", err, buf.String())
	}
	return record
}

func TestRequiredFieldsAndRenames(t *testing.T) {
	var buf bytes.Buffer
	log := logger.NewWithOptions("api", logger.Options{Writer: &buf})

	log.Info("hello world")

	record := decodeLine(t, &buf)
	for _, key := range []string{"timestamp", "level", "service", "message"} {
		if _, ok := record[key]; !ok {
			t.Errorf("required key %q missing in output: %v", key, record)
		}
	}
	for _, key := range []string{"time", "msg"} {
		if _, ok := record[key]; ok {
			t.Errorf("slog default key %q must be renamed, still present: %v", key, record)
		}
	}
	if record["service"] != "api" {
		t.Errorf("service = %v, want %q", record["service"], "api")
	}
	if record["message"] != "hello world" {
		t.Errorf("message = %v, want %q", record["message"], "hello world")
	}
}

func TestLevelsAreLowercase(t *testing.T) {
	tests := []struct {
		name  string
		logFn func(*slog.Logger, string, ...any)
		want  string
	}{
		{"debug", (*slog.Logger).Debug, "debug"},
		{"info", (*slog.Logger).Info, "info"},
		{"warn", (*slog.Logger).Warn, "warn"},
		{"error", (*slog.Logger).Error, "error"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			var buf bytes.Buffer
			log := logger.NewWithOptions("api", logger.Options{Writer: &buf, Level: slog.LevelDebug})

			tt.logFn(log, "x")

			record := decodeLine(t, &buf)
			if record["level"] != tt.want {
				t.Errorf("level = %v, want %q", record["level"], tt.want)
			}
		})
	}
}

func TestTimestampIsRFC3339UTC(t *testing.T) {
	var buf bytes.Buffer
	log := logger.NewWithOptions("api", logger.Options{Writer: &buf})

	log.Info("x")

	record := decodeLine(t, &buf)
	raw, ok := record["timestamp"].(string)
	if !ok {
		t.Fatalf("timestamp is not a string: %v", record["timestamp"])
	}
	parsed, err := time.Parse(time.RFC3339Nano, raw)
	if err != nil {
		t.Fatalf("timestamp %q does not parse as RFC 3339: %v", raw, err)
	}
	if _, offset := parsed.Zone(); offset != 0 {
		t.Errorf("timestamp %q is not UTC (offset %d)", raw, offset)
	}
}

func TestOptionalFields(t *testing.T) {
	var buf bytes.Buffer
	log := logger.NewWithOptions("code-executor", logger.Options{Writer: &buf})

	log.Error("judge0 call failed",
		slog.String(logger.KeyTraceID, "trace-123"),
		slog.Any(logger.KeyData, map[string]any{"submission_id": 42}),
		logger.Err(errors.New("connection refused")),
	)

	record := decodeLine(t, &buf)
	if record["trace_id"] != "trace-123" {
		t.Errorf("trace_id = %v, want %q", record["trace_id"], "trace-123")
	}
	data, ok := record["data"].(map[string]any)
	if !ok {
		t.Fatalf("data is not an object: %v", record["data"])
	}
	if data["submission_id"] != float64(42) {
		t.Errorf("data.submission_id = %v, want 42", data["submission_id"])
	}
	if record["error"] != "connection refused" {
		t.Errorf("error = %v, want %q", record["error"], "connection refused")
	}
}

func TestDefaultLevelSuppressesDebug(t *testing.T) {
	var buf bytes.Buffer
	log := logger.NewWithOptions("api", logger.Options{Writer: &buf})

	log.Debug("noise")

	if buf.Len() != 0 {
		t.Errorf("debug record emitted at default (info) level: %q", buf.String())
	}
}

func TestNewReturnsLogger(t *testing.T) {
	if logger.New("api") == nil {
		t.Fatal("New returned nil")
	}
}

var timestampPattern = regexp.MustCompile(`"timestamp":"[^"]*"`)

// TestGoldenOutputShape pins the exact serialized shape of a record after the
// field renames. The timestamp value is normalized before comparison.
func TestGoldenOutputShape(t *testing.T) {
	var buf bytes.Buffer
	log := logger.NewWithOptions("plagiarism-checker", logger.Options{Writer: &buf})

	log.Warn("dolos slow",
		slog.String(logger.KeyTraceID, "trace-abc"),
		slog.Any(logger.KeyData, map[string]any{"duration_ms": 1500}),
	)

	got := timestampPattern.ReplaceAllString(buf.String(), `"timestamp":"<TIMESTAMP>"`)
	goldenPath := filepath.Join("testdata", "record.golden")
	want, err := os.ReadFile(goldenPath)
	if err != nil {
		t.Fatalf("reading golden file: %v", err)
	}
	if got != string(want) {
		t.Errorf("output shape mismatch\ngot:  %q\nwant: %q", got, string(want))
	}
}
