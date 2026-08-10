// Package logger provides the shared slog JSON logger for MageCode Go
// services, emitting the unified log format defined by D-88: required fields
// timestamp/level/service/message plus optional trace_id/data/error.
package logger

import (
	"io"
	"log/slog"
	"os"
	"strings"
)

// Attribute keys for the optional D-88 fields. Callers pass these with
// slog.String/slog.Any so field names stay consistent across services.
const (
	KeyTraceID = "trace_id"
	KeyData    = "data"
	KeyError   = "error"
)

// Options configures NewWithOptions. Zero values mean: write to os.Stdout at
// slog.LevelInfo.
type Options struct {
	Writer io.Writer
	Level  slog.Level
}

// New returns a logger writing D-88 JSON records to stdout at info level.
func New(service string) *slog.Logger {
	return NewWithOptions(service, Options{})
}

// NewWithOptions returns a logger writing D-88 JSON records to opts.Writer
// at opts.Level, with the service name injected into every record.
func NewWithOptions(service string, opts Options) *slog.Logger {
	writer := opts.Writer
	if writer == nil {
		writer = os.Stdout
	}
	handler := slog.NewJSONHandler(writer, &slog.HandlerOptions{
		Level:       opts.Level,
		ReplaceAttr: replaceAttr,
	})
	return slog.New(handler).With(slog.String("service", service))
}

// Err builds the D-88 error attribute from an error value.
func Err(err error) slog.Attr {
	return slog.String(KeyError, err.Error())
}

// replaceAttr renames slog's built-in keys to the D-88 field names:
// time→timestamp (forced to UTC), msg→message, and lowercases the level.
func replaceAttr(groups []string, attr slog.Attr) slog.Attr {
	if len(groups) > 0 {
		return attr
	}
	switch attr.Key {
	case slog.TimeKey:
		return slog.Time("timestamp", attr.Value.Time().UTC())
	case slog.MessageKey:
		return slog.String("message", attr.Value.String())
	case slog.LevelKey:
		level := attr.Value.Any().(slog.Level)
		return slog.String(slog.LevelKey, strings.ToLower(level.String()))
	}
	return attr
}
