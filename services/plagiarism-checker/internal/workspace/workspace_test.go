package workspace

import (
	"os"
	"path/filepath"
	"testing"
)

func TestWriteNamesFilesBySubmissionID(t *testing.T) {
	ws, err := New(t.TempDir())
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	defer func() { _ = ws.Close() }()

	path, err := ws.Write(11, "py", []byte("print(1)\n"))
	if err != nil {
		t.Fatalf("Write: %v", err)
	}
	if got := filepath.Base(path); got != "11.py" {
		t.Errorf("file is named %q, want 11.py", got)
	}

	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading back: %v", err)
	}
	// SIM compares bytes, so what lands on disk must be what was downloaded —
	// C2 excluded source_code from TrimStrings for the same reason.
	if string(content) != "print(1)\n" {
		t.Errorf("content = %q", content)
	}
}

// The submission id is recovered from the filename when Dolos reports a pair,
// so the mapping has to survive the round trip.
func TestSubmissionIDFromPath(t *testing.T) {
	cases := map[string]struct {
		id int64
		ok bool
	}{
		"/tmp/sim-1/11.py":     {11, true},
		"12.java":              {12, true},
		"/tmp/sim-1/notanid.c": {0, false},
		"/tmp/sim-1/.py":       {0, false},
		"":                     {0, false},
	}

	for path, want := range cases {
		t.Run(path, func(t *testing.T) {
			got, ok := SubmissionIDFromPath(path)
			if ok != want.ok {
				t.Fatalf("ok = %v, want %v", ok, want.ok)
			}
			if got != want.id {
				t.Errorf("id = %d, want %d", got, want.id)
			}
		})
	}
}

func TestCloseRemovesTheWorkspace(t *testing.T) {
	root := t.TempDir()
	ws, err := New(root)
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	if _, err := ws.Write(1, "py", []byte("x")); err != nil {
		t.Fatalf("Write: %v", err)
	}

	dir := ws.Dir()
	if err := ws.Close(); err != nil {
		t.Fatalf("Close: %v", err)
	}
	if _, err := os.Stat(dir); !os.IsNotExist(err) {
		t.Errorf("workspace %s survived Close (err = %v)", dir, err)
	}
	// Closing twice happens whenever a deferred Close follows an explicit
	// one on an error path; it must not be an error.
	if err := ws.Close(); err != nil {
		t.Errorf("second Close: %v", err)
	}
}

func TestTwoWorkspacesDoNotShareADirectory(t *testing.T) {
	root := t.TempDir()
	first, err := New(root)
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	defer func() { _ = first.Close() }()
	second, err := New(root)
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	defer func() { _ = second.Close() }()

	if first.Dir() == second.Dir() {
		t.Errorf("both workspaces are %s", first.Dir())
	}
}
