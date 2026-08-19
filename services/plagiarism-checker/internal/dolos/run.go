package dolos

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os/exec"
	"strings"
	"syscall"
	"time"

	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/shared/go/apperror"
)

// Config points the runner at the bundled reporter.
type Config struct {
	// Node is the interpreter. The image puts it on PATH, so "node" is the
	// usual value.
	Node string
	// Script is the reporter's path inside the image.
	Script string
	// Timeout bounds one comparison (DOLOS_TIMEOUT).
	Timeout time.Duration
	Limits  Limits
}

const (
	defaultNode    = "node"
	defaultScript  = "/app/reporter/report.mjs"
	defaultTimeout = 5 * time.Minute
	// stderrExcerpt bounds how much of a failing reporter's output travels
	// into the error message and from there into api's error_message.
	stderrExcerpt = 2000
)

// Runner invokes the reporter. It is safe for concurrent use, though SIM's
// prefetch of 1 (D-76) means one comparison runs at a time.
type Runner struct {
	cfg Config
}

// New returns a Runner with cfg's defaults filled in.
func New(cfg Config) *Runner {
	if cfg.Node == "" {
		cfg.Node = defaultNode
	}
	if cfg.Script == "" {
		cfg.Script = defaultScript
	}
	if cfg.Timeout <= 0 {
		cfg.Timeout = defaultTimeout
	}
	return &Runner{cfg: cfg}
}

// Compare runs the reporter over dir and returns the ordered pairs.
func (r *Runner) Compare(ctx context.Context, dir string, language job.Language) ([]Pair, error) {
	raw, err := r.Run(ctx, dir, string(language))
	if err != nil {
		return nil, err
	}
	return Parse(raw, r.cfg.Limits)
}

// Run executes the reporter and returns its stdout.
//
// Failures are Permanent throughout — a timeout, a crash and a missing binary
// all describe a comparison this build cannot make, and repeating it three
// times only delays the error result api is waiting on. The handler turns
// that into a status=error message rather than a dead-letter.
func (r *Runner) Run(ctx context.Context, dir, language string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, r.cfg.Timeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, r.cfg.Node, r.cfg.Script, "--language", language, "--dir", dir)

	// node spawns workers of its own, so killing the process leaves them
	// running against a workspace that is about to be removed. Its own
	// process group makes the kill cover the tree.
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return nil
		}
		return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
	// Anything that survives SIGKILL is not going to release the pipes, and
	// Wait would block on them forever.
	cmd.WaitDelay = 5 * time.Second

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err := cmd.Run()
	if err == nil {
		return stdout.Bytes(), nil
	}

	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		return nil, apperror.New(apperror.Permanent,
			fmt.Sprintf("dolos exceeded its %s timeout comparing %s sources", r.cfg.Timeout, language))
	}
	if errors.Is(ctx.Err(), context.Canceled) {
		// A shutdown mid-comparison is worth retrying: the job is intact and
		// the next instance can run it (D-73).
		return nil, apperror.Wrap(apperror.Transient, "dolos run cancelled", ctx.Err())
	}

	detail := strings.TrimSpace(stderr.String())
	if len(detail) > stderrExcerpt {
		detail = detail[:stderrExcerpt] + "…"
	}
	if detail == "" {
		detail = err.Error()
	}
	return nil, apperror.New(apperror.Permanent, fmt.Sprintf("dolos failed: %s", detail))
}
