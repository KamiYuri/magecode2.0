package storage

import (
	"strings"
	"testing"
)

func TestSubmissionPath(t *testing.T) {
	tests := []struct {
		name                    string
		problemID, submissionID int64
		filename                string
		want                    string
	}{
		{"simple", 12, 1042, "main.go", "submissions/12/1042/main.go"},
		{"keeps original filename", 3, 7, "Giải Thuật.py", "submissions/3/7/Giải Thuật.py"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := SubmissionPath(tt.problemID, tt.submissionID, tt.filename)
			if got != tt.want {
				t.Errorf("SubmissionPath = %q, want %q", got, tt.want)
			}
		})
	}
}

func TestConfigValidate(t *testing.T) {
	valid := Config{Endpoint: "localhost:9000", AccessKey: "ak", SecretKey: "sk", Bucket: "magecode"}
	if err := valid.validate(); err != nil {
		t.Errorf("valid config rejected: %v", err)
	}

	tests := []struct {
		name    string
		mutate  func(Config) Config
		wantKey string
	}{
		{"missing endpoint", func(c Config) Config { c.Endpoint = ""; return c }, "Endpoint"},
		{"missing access key", func(c Config) Config { c.AccessKey = ""; return c }, "AccessKey"},
		{"missing secret key", func(c Config) Config { c.SecretKey = ""; return c }, "SecretKey"},
		{"missing bucket", func(c Config) Config { c.Bucket = ""; return c }, "Bucket"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := tt.mutate(valid).validate()
			if err == nil {
				t.Fatal("invalid config accepted")
			}
			if !strings.Contains(err.Error(), tt.wantKey) {
				t.Errorf("error %q does not name field %s", err, tt.wantKey)
			}
		})
	}
}
