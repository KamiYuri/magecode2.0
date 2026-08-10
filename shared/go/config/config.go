// Package config provides the shared env-based configuration loader for
// MageCode Go services. Load validates the whole Spec up front — presence of
// required keys and type coercion for every declared field — and aggregates
// all problems into one error, so a service fails fast at startup with a
// complete report instead of dying one missing key at a time.
package config

import (
	"errors"
	"fmt"
	"os"
	"sort"
	"strconv"
	"time"
)

// Type is the coercion target for a Field. The zero value is String.
type Type int

const (
	String Type = iota
	Int
	Bool
	Duration
)

// Field declares how one env key is loaded. An empty env value is treated as
// unset: Required rejects it, Default replaces it.
type Field struct {
	Type     Type
	Required bool
	Default  string
}

// Spec maps env key names to their Field declarations.
type Spec map[string]Field

// Config holds values already validated and coerced by Load. Getters never
// fail; a key absent from the Spec yields the type's zero value.
type Config struct {
	strings   map[string]string
	ints      map[string]int
	bools     map[string]bool
	durations map[string]time.Duration
}

// Load reads the process environment against spec. It returns an error
// naming every missing required key and every value (env or Default) that
// fails coercion to its declared Type.
func Load(spec Spec) (*Config, error) {
	cfg := &Config{
		strings:   make(map[string]string),
		ints:      make(map[string]int),
		bools:     make(map[string]bool),
		durations: make(map[string]time.Duration),
	}

	// Sorted keys keep multi-error output deterministic.
	keys := make([]string, 0, len(spec))
	for key := range spec {
		keys = append(keys, key)
	}
	sort.Strings(keys)

	var problems []error
	for _, key := range keys {
		field := spec[key]
		raw := os.Getenv(key)
		if raw == "" {
			if field.Required {
				problems = append(problems, fmt.Errorf("missing required key %s", key))
				continue
			}
			raw = field.Default
		}
		// Optional key with no value and no default: leave the getter's
		// zero value instead of failing coercion on "".
		if raw == "" {
			continue
		}
		if err := cfg.store(key, field.Type, raw); err != nil {
			problems = append(problems, fmt.Errorf("invalid value for %s: %w", key, err))
		}
	}
	if len(problems) > 0 {
		return nil, errors.Join(problems...)
	}
	return cfg, nil
}

func (c *Config) store(key string, typ Type, raw string) error {
	switch typ {
	case Int:
		value, err := strconv.Atoi(raw)
		if err != nil {
			return fmt.Errorf("%q is not an integer", raw)
		}
		c.ints[key] = value
	case Bool:
		value, err := strconv.ParseBool(raw)
		if err != nil {
			return fmt.Errorf("%q is not a boolean", raw)
		}
		c.bools[key] = value
	case Duration:
		value, err := time.ParseDuration(raw)
		if err != nil {
			return fmt.Errorf("%q is not a duration", raw)
		}
		c.durations[key] = value
	default:
		c.strings[key] = raw
	}
	return nil
}

// String returns the value of a String-typed key, or "" if not in the Spec.
func (c *Config) String(key string) string { return c.strings[key] }

// Int returns the value of an Int-typed key, or 0 if not in the Spec.
func (c *Config) Int(key string) int { return c.ints[key] }

// Bool returns the value of a Bool-typed key, or false if not in the Spec.
func (c *Config) Bool(key string) bool { return c.bools[key] }

// Duration returns the value of a Duration-typed key, or 0 if not in the Spec.
func (c *Config) Duration(key string) time.Duration { return c.durations[key] }
