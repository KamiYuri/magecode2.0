package config_test

import (
	"strings"
	"testing"
	"time"

	"github.com/magecode/shared/go/config"
)

func TestLoadRequiredKeyPresent(t *testing.T) {
	t.Setenv("MC_TEST_DSN", "postgres://localhost:6432/magecode")

	cfg, err := config.Load(config.Spec{
		"MC_TEST_DSN": {Required: true},
	})
	if err != nil {
		t.Fatalf("Load returned error: %v", err)
	}
	if got := cfg.String("MC_TEST_DSN"); got != "postgres://localhost:6432/magecode" {
		t.Errorf("String = %q, want the env value", got)
	}
}

func TestLoadFailsFastOnMissingRequired(t *testing.T) {
	tests := []struct {
		name        string
		setEmpty    bool
		wantMissing []string
	}{
		{"unset key", false, []string{"MC_TEST_ABSENT"}},
		{"empty value counts as missing", true, []string{"MC_TEST_ABSENT"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if tt.setEmpty {
				t.Setenv("MC_TEST_ABSENT", "")
			}
			_, err := config.Load(config.Spec{
				"MC_TEST_ABSENT": {Required: true},
			})
			if err == nil {
				t.Fatal("Load succeeded, want error for missing required key")
			}
			for _, key := range tt.wantMissing {
				if !strings.Contains(err.Error(), key) {
					t.Errorf("error %q does not name missing key %q", err, key)
				}
			}
		})
	}
}

func TestLoadReportsAllMissingKeysAtOnce(t *testing.T) {
	_, err := config.Load(config.Spec{
		"MC_TEST_MISS_A": {Required: true},
		"MC_TEST_MISS_B": {Required: true},
	})
	if err == nil {
		t.Fatal("Load succeeded, want error")
	}
	for _, key := range []string{"MC_TEST_MISS_A", "MC_TEST_MISS_B"} {
		if !strings.Contains(err.Error(), key) {
			t.Errorf("error %q does not name missing key %q (all must be aggregated)", err, key)
		}
	}
}

func TestDefaultsApplyWhenUnset(t *testing.T) {
	cfg, err := config.Load(config.Spec{
		"MC_TEST_PORT":    {Type: config.Int, Default: "8080"},
		"MC_TEST_DEBUG":   {Type: config.Bool, Default: "false"},
		"MC_TEST_TIMEOUT": {Type: config.Duration, Default: "30s"},
		"MC_TEST_NAME":    {Default: "magecode"},
	})
	if err != nil {
		t.Fatalf("Load returned error: %v", err)
	}
	if got := cfg.Int("MC_TEST_PORT"); got != 8080 {
		t.Errorf("Int default = %d, want 8080", got)
	}
	if got := cfg.Bool("MC_TEST_DEBUG"); got != false {
		t.Errorf("Bool default = %v, want false", got)
	}
	if got := cfg.Duration("MC_TEST_TIMEOUT"); got != 30*time.Second {
		t.Errorf("Duration default = %v, want 30s", got)
	}
	if got := cfg.String("MC_TEST_NAME"); got != "magecode" {
		t.Errorf("String default = %q, want %q", got, "magecode")
	}
}

func TestTypeCoercionFromEnv(t *testing.T) {
	t.Setenv("MC_TEST_PORT", "6432")
	t.Setenv("MC_TEST_DEBUG", "true")
	t.Setenv("MC_TEST_TIMEOUT", "1m30s")

	cfg, err := config.Load(config.Spec{
		"MC_TEST_PORT":    {Type: config.Int, Required: true},
		"MC_TEST_DEBUG":   {Type: config.Bool, Required: true},
		"MC_TEST_TIMEOUT": {Type: config.Duration, Required: true},
	})
	if err != nil {
		t.Fatalf("Load returned error: %v", err)
	}
	if got := cfg.Int("MC_TEST_PORT"); got != 6432 {
		t.Errorf("Int = %d, want 6432", got)
	}
	if got := cfg.Bool("MC_TEST_DEBUG"); got != true {
		t.Errorf("Bool = %v, want true", got)
	}
	if got := cfg.Duration("MC_TEST_TIMEOUT"); got != 90*time.Second {
		t.Errorf("Duration = %v, want 1m30s", got)
	}
}

func TestLoadFailsOnCoercionErrors(t *testing.T) {
	tests := []struct {
		name  string
		key   string
		value string
		field config.Field
	}{
		{"non-numeric int", "MC_TEST_BAD_INT", "eighty", config.Field{Type: config.Int}},
		{"non-boolean bool", "MC_TEST_BAD_BOOL", "yep", config.Field{Type: config.Bool}},
		{"non-duration", "MC_TEST_BAD_DUR", "fast", config.Field{Type: config.Duration}},
		{"invalid default", "MC_TEST_BAD_DEF", "", config.Field{Type: config.Int, Default: "NaN"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if tt.value != "" {
				t.Setenv(tt.key, tt.value)
			}
			_, err := config.Load(config.Spec{tt.key: tt.field})
			if err == nil {
				t.Fatal("Load succeeded, want coercion error")
			}
			if !strings.Contains(err.Error(), tt.key) {
				t.Errorf("error %q does not name offending key %q", err, tt.key)
			}
		})
	}
}

func TestOptionalTypedKeyWithoutDefaultYieldsZero(t *testing.T) {
	cfg, err := config.Load(config.Spec{
		"MC_TEST_OPT_INT": {Type: config.Int},
		"MC_TEST_OPT_DUR": {Type: config.Duration},
	})
	if err != nil {
		t.Fatalf("Load returned error for optional unset keys: %v", err)
	}
	if got := cfg.Int("MC_TEST_OPT_INT"); got != 0 {
		t.Errorf("Int = %d, want 0", got)
	}
	if got := cfg.Duration("MC_TEST_OPT_DUR"); got != 0 {
		t.Errorf("Duration = %v, want 0", got)
	}
}

func TestGettersReturnZeroForUnspeccedKey(t *testing.T) {
	cfg, err := config.Load(config.Spec{})
	if err != nil {
		t.Fatalf("Load returned error: %v", err)
	}
	if got := cfg.String("MC_TEST_NOWHERE"); got != "" {
		t.Errorf("String = %q, want empty", got)
	}
	if got := cfg.Int("MC_TEST_NOWHERE"); got != 0 {
		t.Errorf("Int = %d, want 0", got)
	}
	if got := cfg.Bool("MC_TEST_NOWHERE"); got != false {
		t.Errorf("Bool = %v, want false", got)
	}
	if got := cfg.Duration("MC_TEST_NOWHERE"); got != 0 {
		t.Errorf("Duration = %v, want 0", got)
	}
}
