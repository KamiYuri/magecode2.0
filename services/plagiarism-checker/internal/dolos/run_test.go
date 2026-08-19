package dolos

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/magecode/shared/go/apperror"
)

// stubRunner points the runner at a shell script instead of node. What is
// under test here is the process handling — timeout, exit status, output
// discipline — not Dolos, which the parse tests and the tagged integration
// test cover.
func stubRunner(t *testing.T, script string, timeout time.Duration) *Runner {
	t.Helper()
	path := filepath.Join(t.TempDir(), "stub.sh")
	if err := os.WriteFile(path, []byte(script), 0o700); err != nil {
		t.Fatalf("writing stub: %v", err)
	}
	return New(Config{Node: "/bin/sh", Script: path, Timeout: timeout})
}

func TestRunReturnsTheReporterDocument(t *testing.T) {
	runner := stubRunner(t, `#!/bin/sh
printf '{"version":"1.0","language":"python","files":[],"pairs":[]}'
`, 5*time.Second)

	raw, err := runner.Run(context.Background(), "/tmp/does-not-matter", "python")
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	if !strings.Contains(string(raw), `"version":"1.0"`) {
		t.Errorf("stdout = %q", raw)
	}
}

func TestRunPassesLanguageAndDirectory(t *testing.T) {
	runner := stubRunner(t, `#!/bin/sh
printf '{"version":"1.0","args":"%s","pairs":[]}' "$*"
`, 5*time.Second)

	raw, err := runner.Run(context.Background(), "/tmp/sim-42", "java")
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	for _, want := range []string{"--language java", "--dir /tmp/sim-42"} {
		if !strings.Contains(string(raw), want) {
			t.Errorf("reporter was called with %q, missing %q", raw, want)
		}
	}
}

// DOLOS_TIMEOUT exists because a pathological pair of files can keep
// tree-sitter busy indefinitely, and SIM's prefetch is 1 — a hung run stops
// the service, not just the job.
func TestRunKillsAReporterThatOverrunsItsTimeout(t *testing.T) {
	runner := stubRunner(t, `#!/bin/sh
sleep 30
`, 150*time.Millisecond)

	started := time.Now()
	_, err := runner.Run(context.Background(), "/tmp", "python")
	if err == nil {
		t.Fatal("Run waited for a reporter that never finished")
	}
	if elapsed := time.Since(started); elapsed > 5*time.Second {
		t.Errorf("Run took %v — the timeout did not kill the process", elapsed)
	}
	if !strings.Contains(err.Error(), "timeout") {
		t.Errorf("error should say the run timed out, got: %v", err)
	}
	// The whole group is answered with status=error rather than retried:
	// a run that exceeded its wall clock does so again, and api is waiting.
	if !apperror.IsPermanent(err) {
		t.Errorf("a timeout should be Permanent, got: %v", err)
	}
}

// A killed reporter must not leave its children behind — the process group is
// what makes that true, since node spawns workers of its own.
func TestRunKillsTheWholeProcessGroup(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "child-alive")
	runner := stubRunner(t, `#!/bin/sh
( sleep 5; touch `+marker+` ) &
sleep 30
`, 150*time.Millisecond)

	if _, err := runner.Run(context.Background(), "/tmp", "python"); err == nil {
		t.Fatal("Run returned no error for a timed-out reporter")
	}

	time.Sleep(1500 * time.Millisecond)
	if _, err := os.Stat(marker); err == nil {
		t.Error("a child of the reporter outlived the kill and wrote its marker")
	}
}

func TestRunReportsAFailedReporterWithItsDiagnostics(t *testing.T) {
	runner := stubRunner(t, `#!/bin/sh
echo "Error: unsupported language rust" >&2
exit 1
`, 5*time.Second)

	_, err := runner.Run(context.Background(), "/tmp", "rust")
	if err == nil {
		t.Fatal("Run ignored a failing reporter")
	}
	if !strings.Contains(err.Error(), "unsupported language rust") {
		t.Errorf("error should carry the reporter's stderr, got: %v", err)
	}
	if !apperror.IsPermanent(err) {
		t.Errorf("a failed comparison should be Permanent, got: %v", err)
	}
}

func TestRunFailsWhenTheReporterIsMissing(t *testing.T) {
	runner := New(Config{Node: "/nonexistent/node", Script: "/nonexistent/report.mjs", Timeout: time.Second})

	_, err := runner.Run(context.Background(), "/tmp", "python")
	if err == nil {
		t.Fatal("Run succeeded without a reporter")
	}
	// A missing binary is a broken image, not a bad moment on the network:
	// three retries would only delay the error result api is waiting for.
	if !apperror.IsPermanent(err) {
		t.Errorf("a missing reporter should be Permanent, got: %v", err)
	}
}

// Compare is the whole of what the handler calls: run, then parse.
func TestCompareRunsAndParses(t *testing.T) {
	raw, err := os.ReadFile("testdata/report-python.json")
	if err != nil {
		t.Fatalf("reading fixture: %v", err)
	}
	path := filepath.Join(t.TempDir(), "stub.sh")
	script := "#!/bin/sh\ncat <<'REPORT'\n" + string(raw) + "\nREPORT\n"
	if err := os.WriteFile(path, []byte(script), 0o700); err != nil {
		t.Fatalf("writing stub: %v", err)
	}
	runner := New(Config{Node: "/bin/sh", Script: path, Timeout: 5 * time.Second})

	pairs, err := runner.Compare(context.Background(), "/tmp/sim-1", "python")
	if err != nil {
		t.Fatalf("Compare: %v", err)
	}
	if len(pairs) != 1 || pairs[0].SubmissionAID != 11 {
		t.Errorf("pairs = %+v", pairs)
	}
}
