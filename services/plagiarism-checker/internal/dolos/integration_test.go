//go:build integration

// Integration test for E2: the real reporter, the real Dolos library, real
// Python sources. Run:
//
//	SIM_REPORTER_SCRIPT=$PWD/reporter/report.mjs go test -tags integration ./internal/dolos/ -v
//
// The reporter's native tree-sitter modules are built for the Node ABI of the
// image (node 22), so SIM_NODE_BIN exists for hosts whose own node differs:
// point it at a wrapper that runs node inside the image. The suite skips when
// SIM_REPORTER_SCRIPT is unset.
package dolos_test

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/magecode/plagiarism-checker/internal/dolos"
	"github.com/magecode/plagiarism-checker/internal/job"
)

// Two solutions of the same exercise where one has been renamed throughout —
// the case Dolos exists for, and the case a diff would miss.
const original = `def solve(numbers):
    total = 0
    for n in numbers:
        if n % 2 == 0:
            total += n
    return total

print(solve([int(x) for x in input().split()]))
`

const renamed = `def solve(values):
    total = 0
    for v in values:
        if v % 2 == 0:
            total += v
    return total

print(solve([int(y) for y in input().split()]))
`

const unrelated = `import sys
print(sum(map(int, sys.stdin.readline().split())))
`

func runner(t *testing.T) (*dolos.Runner, string) {
	t.Helper()
	script := os.Getenv("SIM_REPORTER_SCRIPT")
	if script == "" {
		t.Skip("SIM_REPORTER_SCRIPT not set; point it at reporter/report.mjs")
	}
	node := os.Getenv("SIM_NODE_BIN")
	if node == "" {
		node = "node"
	}

	dir := t.TempDir()
	for name, content := range map[string]string{
		"11.py": original, "12.py": renamed, "13.py": unrelated,
	} {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o600); err != nil {
			t.Fatalf("writing %s: %v", name, err)
		}
	}

	return dolos.New(dolos.Config{
		Node: node, Script: script, Timeout: 2 * time.Minute,
		Limits: dolos.Limits{MaxRegionsPerPair: 100},
	}), dir
}

func TestCompareFindsTheRenamedCopy(t *testing.T) {
	runner, dir := runner(t)

	pairs, err := runner.Compare(context.Background(), dir, job.Python)
	if err != nil {
		t.Fatalf("Compare: %v", err)
	}
	if len(pairs) == 0 {
		t.Fatal("Dolos found no pairs at all between two renamed copies")
	}

	top := pairs[0]
	if top.SubmissionAID != 11 || top.SubmissionBID != 12 {
		t.Fatalf("the most similar pair is (%d, %d), want (11, 12)", top.SubmissionAID, top.SubmissionBID)
	}
	if top.Similarity < 0.8 {
		t.Errorf("similarity of a renamed copy is %v, want > 0.8", top.Similarity)
	}
	// The regions are the point of driving the library rather than the CLI.
	if top.ARegions == nil || top.BRegions == nil {
		t.Fatalf("regions are null: a = %v, b = %v", top.ARegions, top.BRegions)
	}
	if top.LongestFragment == nil || *top.LongestFragment < 1 {
		t.Errorf("longest_fragment = %v", top.LongestFragment)
	}
	if top.TotalOverlap == nil || *top.TotalOverlap < 1 {
		t.Errorf("total_overlap = %v", top.TotalOverlap)
	}

	// The unrelated one-liner shares nothing, so it must not appear as a
	// pair at all — otherwise every batch stores a row per combination.
	for _, pair := range pairs {
		if pair.SubmissionAID == 13 || pair.SubmissionBID == 13 {
			t.Errorf("the unrelated submission appears in pair (%d, %d) at %v",
				pair.SubmissionAID, pair.SubmissionBID, pair.Similarity)
		}
	}
}
