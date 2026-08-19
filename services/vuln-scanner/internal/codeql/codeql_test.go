package codeql

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/magecode/shared/go/apperror"
	"github.com/magecode/vuln-scanner/internal/job"
)

// stubRunner points the runner at a shell script instead of the CLI. What is
// under test is the invocation and the process handling; the real CLI is
// covered by the tagged integration test.
func stubRunner(t *testing.T, script string, timeout time.Duration) *Runner {
	t.Helper()
	path := filepath.Join(t.TempDir(), "codeql.sh")
	if err := os.WriteFile(path, []byte(script), 0o700); err != nil {
		t.Fatalf("writing stub: %v", err)
	}
	return New(Config{Binary: path, Timeout: timeout, WorkspaceRoot: t.TempDir()})
}

func TestSupportedLanguages(t *testing.T) {
	for _, language := range []job.Language{job.Python, job.Java, job.Cpp} {
		if !Supports(language) {
			t.Errorf("%s has no suite", language)
		}
	}
	if Supports(job.Language("rust")) {
		t.Error("rust reports a suite it does not have")
	}
}

// Each language reaches CodeQL differently, and the differences are the whole
// reason this package is not three lines.
func TestCreateArgumentsPerLanguage(t *testing.T) {
	runner := New(Config{Threads: "2"})

	python := strings.Join(runner.createArgs("/db", "/src", job.Python), " ")
	if strings.Contains(python, "--build-mode") || strings.Contains(python, "--command") {
		t.Errorf("python asks for a build: %s", python)
	}

	java := strings.Join(runner.createArgs("/db", "/src", job.Java), " ")
	// A single .java file has no project; autobuild would fail looking for
	// one (CodeQL >= 2.16 answers this with build-mode=none).
	if !strings.Contains(java, "--build-mode=none") {
		t.Errorf("java does not ask for build-mode=none: %s", java)
	}

	cpp := strings.Join(runner.createArgs("/db", "/src", job.Cpp), " ")
	// C++ has no build-mode=none, so the database is built around a
	// syntax-only compile — which is why the image carries g++.
	if !strings.Contains(cpp, "--command=g++ -fsyntax-only submission.cpp") {
		t.Errorf("cpp does not carry a build command: %s", cpp)
	}
}

func TestAnalyzeArgumentsNameTheSuiteAndSarif(t *testing.T) {
	args := strings.Join(New(Config{}).analyzeArgs("/db", "/out.sarif", suites[job.Python]), " ")

	if !strings.Contains(args, "codeql/python-queries:codeql-suites/python-security-and-quality.qls") {
		t.Errorf("suite is missing: %s", args)
	}
	if !strings.Contains(args, "--format=sarif-latest") {
		t.Errorf("format is missing: %s", args)
	}
}

func TestScanReturnsTheFindingsCodeqlReported(t *testing.T) {
	runner := stubRunner(t, `#!/bin/sh
# `+"`database create`"+` makes nothing this stub cares about; `+"`analyze`"+`
# is told where to write, in --output=<path>.
for arg in "$@"; do
  case "$arg" in
    --output=*) printf '%s' "${arg#--output=}" > /tmp/vul-stub-output ;;
  esac
done
if [ "$2" = "analyze" ]; then
  cat > "$(cat /tmp/vul-stub-output)" <<'SARIF'
{"version":"2.1.0","runs":[{"tool":{"driver":{"rules":[]}},"results":[
 {"ruleId":"py/sql-injection","level":"error","message":{"text":"unsafe"},
  "locations":[{"physicalLocation":{"artifactLocation":{"uri":"submission.py"},
   "region":{"startLine":3,"startColumn":5}}}]}]}]}
SARIF
fi
`, 30*time.Second)

	findings, err := runner.Scan(context.Background(), []byte("print(1)"), job.Python)
	if err != nil {
		t.Fatalf("Scan: %v", err)
	}
	if len(findings) != 1 || findings[0].Name != "py/sql-injection" {
		t.Fatalf("findings = %+v", findings)
	}
	if findings[0].Severity != "error" {
		t.Errorf("severity = %q", findings[0].Severity)
	}
}

// The workspace holds student source and a CodeQL database, which is the
// larger of the two; compose puts it on tmpfs.
func TestScanRemovesItsWorkspace(t *testing.T) {
	root := t.TempDir()
	runner := New(Config{Binary: "/bin/true", Timeout: 30 * time.Second, WorkspaceRoot: root})

	_, _ = runner.Scan(context.Background(), []byte("print(1)"), job.Python)

	leftovers, err := filepath.Glob(filepath.Join(root, "vul-*"))
	if err != nil {
		t.Fatalf("globbing: %v", err)
	}
	if len(leftovers) != 0 {
		t.Errorf("workspaces left behind: %v", leftovers)
	}
}

func TestScanKillsARunThatOverrunsItsTimeout(t *testing.T) {
	runner := stubRunner(t, "#!/bin/sh\nsleep 30\n", 150*time.Millisecond)

	started := time.Now()
	_, err := runner.Scan(context.Background(), []byte("print(1)"), job.Python)
	if err == nil {
		t.Fatal("Scan waited for a run that never finished")
	}
	if elapsed := time.Since(started); elapsed > 5*time.Second {
		t.Errorf("Scan took %v — the timeout did not kill the process", elapsed)
	}
	if !strings.Contains(err.Error(), "timeout") {
		t.Errorf("error should say the run timed out: %v", err)
	}
	// Answered as status=error rather than retried: a scan that exceeded its
	// wall clock does so again, and api is waiting.
	if !apperror.IsPermanent(err) {
		t.Errorf("a timeout should be Permanent: %v", err)
	}
}

func TestScanReportsAFailedRunWithItsDiagnostics(t *testing.T) {
	runner := stubRunner(t, `#!/bin/sh
echo "A fatal error occurred: Could not detect a suitable build command" >&2
exit 2
`, 30*time.Second)

	_, err := runner.Scan(context.Background(), []byte("int main(){}"), job.Cpp)
	if err == nil {
		t.Fatal("Scan ignored a failing CLI")
	}
	if !strings.Contains(err.Error(), "suitable build command") {
		t.Errorf("error should carry the CLI's output: %v", err)
	}
	if !apperror.IsPermanent(err) {
		t.Errorf("a failed scan should be Permanent: %v", err)
	}
}

func TestScanRefusesALanguageWithNoSuite(t *testing.T) {
	_, err := New(Config{WorkspaceRoot: t.TempDir()}).Scan(
		context.Background(), []byte("fn main(){}"), job.Language("rust"))

	if err == nil {
		t.Fatal("Scan accepted a language with no suite")
	}
	if !apperror.IsPermanent(err) {
		t.Errorf("error is not Permanent: %v", err)
	}
}

func TestScanFailsWhenTheBinaryIsMissing(t *testing.T) {
	runner := New(Config{Binary: "/nonexistent/codeql", Timeout: time.Second, WorkspaceRoot: t.TempDir()})

	_, err := runner.Scan(context.Background(), []byte("print(1)"), job.Python)
	if err == nil {
		t.Fatal("Scan succeeded without a CLI")
	}
	// A missing binary is a broken image, not a bad moment on the network.
	if !apperror.IsPermanent(err) {
		t.Errorf("a missing binary should be Permanent: %v", err)
	}
}
