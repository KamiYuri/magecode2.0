package dolos

import (
	"encoding/json"
	"os"
	"testing"

	"github.com/magecode/shared/go/apperror"
)

func loadFixture(t *testing.T, name string) []byte {
	t.Helper()
	raw, err := os.ReadFile("testdata/" + name)
	if err != nil {
		t.Fatalf("reading fixture: %v", err)
	}
	return raw
}

// The golden file is real reporter output over three Python fixtures, two of
// which are near-identical. It is what pins the library's field names to the
// schema's — Dolos calls them `longest` and `overlap`, the schema calls them
// longest_fragment and total_overlap.
func TestParseReadsRealReporterOutput(t *testing.T) {
	pairs, err := Parse(loadFixture(t, "report-python.json"), Limits{MaxRegionsPerPair: 100})
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if len(pairs) != 1 {
		t.Fatalf("len(pairs) = %d, want 1", len(pairs))
	}

	pair := pairs[0]
	if pair.SubmissionAID != 11 || pair.SubmissionBID != 12 {
		t.Errorf("pair is (%d, %d), want (11, 12)", pair.SubmissionAID, pair.SubmissionBID)
	}
	if pair.Similarity != 1 {
		t.Errorf("similarity = %v, want 1", pair.Similarity)
	}
	if pair.LongestFragment == nil || *pair.LongestFragment != 14 {
		t.Errorf("longest_fragment = %v, want 14", pair.LongestFragment)
	}
	if pair.TotalOverlap == nil || *pair.TotalOverlap != 28 {
		t.Errorf("total_overlap = %v, want 28", pair.TotalOverlap)
	}
	// Dolos counts rows and columns from 0; the regions are consumed by a
	// Monaco viewer, which counts from 1 (F9). The conversion happens here,
	// once, rather than in the frontend.
	if pair.ARegions == nil || *pair.ARegions != "1,1,8,43" {
		t.Errorf("a_regions = %v, want \"1,1,8,43\"", pair.ARegions)
	}
	if pair.BRegions == nil || *pair.BRegions != "1,1,8,43" {
		t.Errorf("b_regions = %v, want \"1,1,8,43\"", pair.BRegions)
	}
}

// schema §5.5 calls submission_a_id < submission_b_id CRITICAL, and api (D4)
// normalises again on the way in. Doing it here too means the two never
// disagree about which side's regions are which.
func TestParseOrdersPairsAndFollowsTheRegionsAcross(t *testing.T) {
	pairs, err := Parse(loadFixture(t, "report-mixed.json"), Limits{MaxRegionsPerPair: 100})
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if len(pairs) != 2 {
		t.Fatalf("len(pairs) = %d, want 2", len(pairs))
	}

	// The reporter listed 12 on the left and 9 on the right; the pair must
	// come out as (9, 12) with the regions swapped alongside the ids.
	swapped := pairs[0]
	if swapped.SubmissionAID != 9 || swapped.SubmissionBID != 12 {
		t.Fatalf("pair is (%d, %d), want (9, 12)", swapped.SubmissionAID, swapped.SubmissionBID)
	}
	if *swapped.ARegions != "5,3,8,21|13,2,14,7" {
		t.Errorf("a_regions = %q — 9's own highlights are the reporter's right side", *swapped.ARegions)
	}
	if *swapped.BRegions != "1,1,4,13|11,1,12,6" {
		t.Errorf("b_regions = %q", *swapped.BRegions)
	}
}

// A pair with no fragments has nothing to highlight, and the schema types both
// region fields as nullable — an empty string would render as a region.
func TestParseLeavesRegionsNullWhenThereAreNoFragments(t *testing.T) {
	pairs, err := Parse(loadFixture(t, "report-mixed.json"), Limits{MaxRegionsPerPair: 100})
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	fragmentless := pairs[1]
	if fragmentless.ARegions != nil || fragmentless.BRegions != nil {
		t.Errorf("regions = %v / %v, want null", fragmentless.ARegions, fragmentless.BRegions)
	}
}

// Results are sorted most-similar first so a truncating log or an eyeball on
// the worker sees the interesting pair, and two runs of the same group emit
// the same order.
func TestParseSortsMostSimilarFirst(t *testing.T) {
	pairs, err := Parse(loadFixture(t, "report-mixed.json"), Limits{MaxRegionsPerPair: 100})
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if pairs[0].Similarity < pairs[1].Similarity {
		t.Errorf("pairs are ordered %v then %v", pairs[0].Similarity, pairs[1].Similarity)
	}
}

func TestParseDropsPairsBelowTheFloor(t *testing.T) {
	pairs, err := Parse(loadFixture(t, "report-mixed.json"),
		Limits{MinSimilarity: 0.5, MaxRegionsPerPair: 100})
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if len(pairs) != 1 {
		t.Fatalf("len(pairs) = %d, want 1 — the 0.05 pair is below the floor", len(pairs))
	}
}

// a_regions is a TEXT column and a pair of large files can produce hundreds of
// fragments; the cap bounds the message rather than the truth, so it is
// applied per pair and in file order.
func TestParseCapsRegionsPerPair(t *testing.T) {
	pairs, err := Parse(loadFixture(t, "report-mixed.json"), Limits{MaxRegionsPerPair: 1})
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if *pairs[0].ARegions != "5,3,8,21" {
		t.Errorf("a_regions = %q, want only the first region", *pairs[0].ARegions)
	}
}

// A report SIM cannot read is not a report it will read on a retry — and E3
// turns this into a status=error result rather than a silent dead-letter.
func TestParseRejectsUnusableReportsPermanently(t *testing.T) {
	cases := map[string]string{
		"not json":        `{`,
		"wrong version":   `{"version":"2.0","pairs":[]}`,
		"missing version": `{"pairs":[]}`,
		"filename is not an id": `{"version":"1.0","pairs":[{"leftPath":"/tmp/a.py",` +
			`"rightPath":"/tmp/12.py","similarity":0.5,"fragments":[]}]}`,
		"similarity out of range": `{"version":"1.0","pairs":[{"leftPath":"/tmp/11.py",` +
			`"rightPath":"/tmp/12.py","similarity":1.5,"fragments":[]}]}`,
		"pair of one submission": `{"version":"1.0","pairs":[{"leftPath":"/tmp/11.py",` +
			`"rightPath":"/tmp/11.py","similarity":1,"fragments":[]}]}`,
	}

	for name, raw := range cases {
		t.Run(name, func(t *testing.T) {
			_, err := Parse([]byte(raw), Limits{MaxRegionsPerPair: 100})
			if err == nil {
				t.Fatal("Parse accepted an unusable report")
			}
			if !apperror.IsPermanent(err) {
				t.Errorf("error is not Permanent: %v", err)
			}
		})
	}
}

// An empty report is ordinary: a language group where nobody copied anybody.
func TestParseAcceptsAReportWithNoPairs(t *testing.T) {
	pairs, err := Parse([]byte(`{"version":"1.0","language":"python","files":[],"pairs":[]}`),
		Limits{MaxRegionsPerPair: 100})
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if len(pairs) != 0 {
		t.Errorf("len(pairs) = %d, want 0", len(pairs))
	}
	// The schema types pairs as an array, never null.
	encoded, err := json.Marshal(pairs)
	if err != nil {
		t.Fatalf("Marshal: %v", err)
	}
	if string(encoded) != "[]" {
		t.Errorf("empty pairs encode as %s, want []", encoded)
	}
}
