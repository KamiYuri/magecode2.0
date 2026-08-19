// Package codeql runs the CodeQL CLI over one submission.
//
// Two steps: build a database from the source, then run a query suite over it
// and read the SARIF. The per-language differences are all in the first step,
// and they exist because a submission here is **one file with no build**:
//
//   - python needs no build at all;
//   - java needs `--build-mode=none` (CodeQL ≥ 2.16), because a single .java
//     file has no project to compile and autobuild would fail;
//   - cpp has no build-mode=none, so the database is built around a
//     syntax-only compile, which is why the image carries a compiler.
package codeql

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"github.com/magecode/shared/go/apperror"
	"github.com/magecode/vuln-scanner/internal/job"
	"github.com/magecode/vuln-scanner/internal/sarif"
)

// suites are the query packs run per language. security-and-quality is the
// broader of the two standard suites: this is coursework, and a resource leak
// is worth telling a student about even though it is not an exploit.
var suites = map[job.Language]string{
	job.Python: "codeql/python-queries:codeql-suites/python-security-and-quality.qls",
	job.Java:   "codeql/java-queries:codeql-suites/java-security-and-quality.qls",
	job.Cpp:    "codeql/cpp-queries:codeql-suites/cpp-security-and-quality.qls",
}

// buildCommands are the compile invocations for languages CodeQL cannot
// observe without one. Only C/C++ needs it; the command is syntax-only,
// because the object file is worthless here and a link step would fail on a
// fragment that has no main.
var buildCommands = map[job.Language]string{
	job.Cpp: "g++ -fsyntax-only",
}

const (
	defaultBinary  = "codeql"
	defaultTimeout = 10 * time.Minute
	// stderrExcerpt bounds how much of a failing run travels into the error
	// message and from there into api's error_message.
	stderrExcerpt = 2000
	// defaultThreads leaves the box responsive: VUL shares it with SIM, AID
	// and the database.
	defaultThreads = "2"
)

// Config points the runner at the CLI.
type Config struct {
	// Binary is the codeql executable; the image puts it on PATH.
	Binary string
	// Timeout bounds one scan, database build included (CODEQL_TIMEOUT).
	Timeout time.Duration
	// Threads is passed to both steps; "0" means one per core.
	Threads string
	// WorkspaceRoot is where databases are built. tmpfs in compose.
	WorkspaceRoot string
}

// Runner scans submissions.
type Runner struct {
	cfg Config
}

// New returns a Runner with cfg's defaults filled in.
func New(cfg Config) *Runner {
	if cfg.Binary == "" {
		cfg.Binary = defaultBinary
	}
	if cfg.Timeout <= 0 {
		cfg.Timeout = defaultTimeout
	}
	if cfg.Threads == "" {
		cfg.Threads = defaultThreads
	}
	return &Runner{cfg: cfg}
}

// Supports reports whether a language has a query suite here.
func Supports(language job.Language) bool {
	_, ok := suites[language]
	return ok
}

// Scan writes source to a workspace, builds a database and returns the
// findings. The workspace is removed before it returns, whatever happened.
//
// Failures are Permanent throughout: a timeout, a crashed extractor and a
// missing binary all describe a scan this build cannot make, and repeating it
// three times only delays the error result api is waiting for. The handler
// publishes that as status=error rather than dead-lettering.
func (r *Runner) Scan(ctx context.Context, source []byte, language job.Language) ([]sarif.Finding, error) {
	suite, ok := suites[language]
	if !ok {
		return nil, apperror.New(apperror.Permanent,
			fmt.Sprintf("no codeql suite for language %q", language))
	}

	ctx, cancel := context.WithTimeout(ctx, r.cfg.Timeout)
	defer cancel()

	workspace, err := os.MkdirTemp(r.cfg.WorkspaceRoot, "vul-")
	if err != nil {
		// Nothing can be staged; a bare error is retried, which is right for
		// a tmpfs that is momentarily full.
		return nil, fmt.Errorf("creating workspace: %w", err)
	}
	defer func() { _ = os.RemoveAll(workspace) }()

	sourceRoot := filepath.Join(workspace, "src")
	if err := os.Mkdir(sourceRoot, 0o700); err != nil {
		return nil, fmt.Errorf("creating source root: %w", err)
	}
	// The name matters only to the extractor, which selects on the
	// extension; the submission id plays no part in CodeQL's output beyond
	// the file path it reports.
	sourceFile := filepath.Join(sourceRoot, "submission."+language.Extension())
	if err := os.WriteFile(sourceFile, source, 0o600); err != nil {
		return nil, fmt.Errorf("writing source: %w", err)
	}

	database := filepath.Join(workspace, "db")
	if err := r.run(ctx, r.createArgs(database, sourceRoot, language)...); err != nil {
		return nil, err
	}

	output := filepath.Join(workspace, "results.sarif")
	if err := r.run(ctx, r.analyzeArgs(database, output, suite)...); err != nil {
		return nil, err
	}

	raw, err := os.ReadFile(output)
	if err != nil {
		return nil, apperror.Wrap(apperror.Permanent, "reading codeql sarif", err)
	}

	return sarif.Parse(raw)
}

func (r *Runner) createArgs(database, sourceRoot string, language job.Language) []string {
	args := []string{
		"database", "create", database,
		"--language=" + string(language),
		"--source-root=" + sourceRoot,
		"--threads=" + r.cfg.Threads,
		"--overwrite",
	}

	if command, needsBuild := buildCommands[language]; needsBuild {
		args = append(args, "--command="+command+" "+"submission."+language.Extension())
		return args
	}
	if language == job.Java {
		// A single .java file has no project to build, and autobuild would
		// fail looking for one. build-mode=none extracts the source directly
		// (CodeQL ≥ 2.16).
		args = append(args, "--build-mode=none")
	}

	return args
}

func (r *Runner) analyzeArgs(database, output, suite string) []string {
	return []string{
		"database", "analyze", database, suite,
		"--format=sarif-latest",
		"--output=" + output,
		"--threads=" + r.cfg.Threads,
	}
}

func (r *Runner) run(ctx context.Context, args ...string) error {
	cmd := exec.CommandContext(ctx, r.cfg.Binary, args...)

	// CodeQL forks extractors and compilers; killing only the parent leaves
	// them running against a workspace that is about to be removed.
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return nil
		}
		return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
	cmd.WaitDelay = 10 * time.Second

	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	cmd.Stdout = &stderr

	err := cmd.Run()
	if err == nil {
		return nil
	}

	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		return apperror.New(apperror.Permanent,
			fmt.Sprintf("codeql exceeded its %s timeout during `%s`", r.cfg.Timeout, args[1]))
	}
	if errors.Is(ctx.Err(), context.Canceled) {
		// A shutdown mid-scan is worth retrying: the job is intact and the
		// next instance can run it (D-73).
		return apperror.Wrap(apperror.Transient, "codeql run cancelled", ctx.Err())
	}

	detail := strings.TrimSpace(stderr.String())
	if len(detail) > stderrExcerpt {
		detail = detail[len(detail)-stderrExcerpt:]
	}
	if detail == "" {
		detail = err.Error()
	}
	return apperror.New(apperror.Permanent, fmt.Sprintf("codeql %s failed: %s", args[1], detail))
}
