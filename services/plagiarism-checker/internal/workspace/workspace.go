// Package workspace owns the temporary directory one SIM job runs in.
//
// Dolos is handed a directory of files, and the only identity a file carries
// through it is its name — so the name is the submission id, and reading it
// back is how a reported pair becomes two submission ids again. The directory
// lives under /tmp, which compose mounts as tmpfs, and is removed when the job
// settles whether it succeeded or not.
package workspace

import (
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
)

// Workspace is one job's directory. It is safe for concurrent Write calls,
// which is what lets the downloads run in parallel.
type Workspace struct {
	dir  string
	once sync.Once
	mu   sync.Mutex
}

// New creates a fresh directory under root. Pass an empty root for the
// system temporary directory.
func New(root string) (*Workspace, error) {
	dir, err := os.MkdirTemp(root, "sim-")
	if err != nil {
		return nil, fmt.Errorf("creating workspace: %w", err)
	}
	return &Workspace{dir: dir}, nil
}

// Dir is the absolute path of the workspace directory.
func (w *Workspace) Dir() string { return w.dir }

// Write stores one submission's source as `{submissionID}.{extension}` and
// returns its path.
func (w *Workspace) Write(submissionID int64, extension string, content []byte) (string, error) {
	path := filepath.Join(w.dir, fmt.Sprintf("%d.%s", submissionID, extension))

	w.mu.Lock()
	defer w.mu.Unlock()

	// 0600: nothing outside this process has any business reading student
	// source, and the container runs as one user anyway.
	if err := os.WriteFile(path, content, 0o600); err != nil {
		return "", fmt.Errorf("writing %s: %w", filepath.Base(path), err)
	}
	return path, nil
}

// Close removes the workspace. It is idempotent, so an explicit Close on an
// error path and a deferred one can coexist.
func (w *Workspace) Close() error {
	var err error
	w.once.Do(func() { err = os.RemoveAll(w.dir) })
	return err
}

// SubmissionIDFromPath recovers the submission id Write encoded in a filename.
// The second return is false for anything this package did not name.
func SubmissionIDFromPath(path string) (int64, bool) {
	base := filepath.Base(path)
	name := strings.TrimSuffix(base, filepath.Ext(base))
	if name == "" || name == "." {
		return 0, false
	}

	id, err := strconv.ParseInt(name, 10, 64)
	if err != nil || id < 1 {
		return 0, false
	}
	return id, true
}
