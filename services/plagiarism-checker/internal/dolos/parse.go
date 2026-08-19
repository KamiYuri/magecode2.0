// Package dolos runs the bundled Node reporter over a prepared workspace and
// turns what it says into the pairs `result.analysis.v1` describes.
//
// The reporter exists because the `dolos` CLI's CSV export has no fragment
// coordinates and `a_regions`/`b_regions` are exactly those coordinates; see
// reporter/report.mjs. This file owns the translation between the two: Dolos
// counts rows and columns from 0, the schema's regions are read by a Monaco
// viewer that counts from 1 (F9), and the pair order the schema calls CRITICAL
// is imposed here rather than hoped for.
package dolos

import (
	"bytes"
	"encoding/json"
	"fmt"
	"sort"
	"strings"

	"github.com/magecode/plagiarism-checker/internal/workspace"
	"github.com/magecode/shared/go/apperror"
)

// reportVersion is the reporter contract this build understands. The reporter
// ships in the same image, so a mismatch means a half-updated deployment.
const reportVersion = "1.0"

// Limits bound what one comparison may produce.
type Limits struct {
	// MinSimilarity drops pairs at or below the floor. The default 0 keeps
	// every pair the reporter reports, which is already only those with at
	// least one shared fingerprint.
	MinSimilarity float64
	// MaxRegionsPerPair caps the highlight list of one pair. Regions land in
	// a TEXT column and a long pair of files can produce hundreds; the cap
	// bounds the message, not the finding, so the similarity score is
	// unaffected.
	MaxRegionsPerPair int
}

const defaultMaxRegionsPerPair = 100

// Pair is one comparison in the schema's terms: ordered ids, nullable metrics
// and the two region strings.
type Pair struct {
	SubmissionAID   int64
	SubmissionBID   int64
	Similarity      float64
	LongestFragment *int
	TotalOverlap    *int
	ARegions        *string
	BRegions        *string
}

type reportSelection struct {
	StartRow int `json:"startRow"`
	StartCol int `json:"startCol"`
	EndRow   int `json:"endRow"`
	EndCol   int `json:"endCol"`
}

type reportFragment struct {
	Left  reportSelection `json:"left"`
	Right reportSelection `json:"right"`
}

type reportPair struct {
	LeftPath        string           `json:"leftPath"`
	RightPath       string           `json:"rightPath"`
	Similarity      float64          `json:"similarity"`
	LongestFragment *int             `json:"longestFragment"`
	TotalOverlap    *int             `json:"totalOverlap"`
	Fragments       []reportFragment `json:"fragments"`
}

type report struct {
	Version string       `json:"version"`
	Pairs   []reportPair `json:"pairs"`
}

// Parse turns one reporter document into ordered pairs.
//
// Every failure is Permanent: a report this build cannot read will read the
// same on a retry. The handler answers api with status=error rather than
// letting the message dead-letter, because a batch waiting on a message that
// never arrives burns D-82's 30-minute timeout for an answer SIM already has.
func Parse(raw []byte, limits Limits) ([]Pair, error) {
	if limits.MaxRegionsPerPair <= 0 {
		limits.MaxRegionsPerPair = defaultMaxRegionsPerPair
	}

	var decoded report
	decoder := json.NewDecoder(bytes.NewReader(raw))
	if err := decoder.Decode(&decoded); err != nil {
		return nil, apperror.Wrap(apperror.Permanent, "decoding dolos report", err)
	}
	if decoded.Version != reportVersion {
		return nil, apperror.New(apperror.Permanent,
			fmt.Sprintf("dolos report version %q is not %q — image and reporter disagree",
				decoded.Version, reportVersion))
	}

	pairs := make([]Pair, 0, len(decoded.Pairs))
	for _, reported := range decoded.Pairs {
		pair, err := convert(reported, limits)
		if err != nil {
			return nil, err
		}
		if pair.Similarity <= limits.MinSimilarity && limits.MinSimilarity > 0 {
			continue
		}
		pairs = append(pairs, pair)
	}

	// Most similar first, then by id, so two runs of the same group produce
	// the same message and a truncated log still shows the worst pair.
	sort.SliceStable(pairs, func(i, j int) bool {
		if pairs[i].Similarity != pairs[j].Similarity {
			return pairs[i].Similarity > pairs[j].Similarity
		}
		if pairs[i].SubmissionAID != pairs[j].SubmissionAID {
			return pairs[i].SubmissionAID < pairs[j].SubmissionAID
		}
		return pairs[i].SubmissionBID < pairs[j].SubmissionBID
	})

	return pairs, nil
}

func convert(reported reportPair, limits Limits) (Pair, error) {
	leftID, ok := workspace.SubmissionIDFromPath(reported.LeftPath)
	if !ok {
		return Pair{}, apperror.New(apperror.Permanent,
			fmt.Sprintf("dolos reported %q, which is not a file this workspace wrote", reported.LeftPath))
	}
	rightID, ok := workspace.SubmissionIDFromPath(reported.RightPath)
	if !ok {
		return Pair{}, apperror.New(apperror.Permanent,
			fmt.Sprintf("dolos reported %q, which is not a file this workspace wrote", reported.RightPath))
	}
	if leftID == rightID {
		return Pair{}, apperror.New(apperror.Permanent,
			fmt.Sprintf("dolos paired submission %d with itself", leftID))
	}
	if reported.Similarity < 0 || reported.Similarity > 1 {
		return Pair{}, apperror.New(apperror.Permanent,
			fmt.Sprintf("dolos reported similarity %v for (%d, %d), which is outside 0..1",
				reported.Similarity, leftID, rightID))
	}

	leftRegions := regions(reported.Fragments, func(f reportFragment) reportSelection { return f.Left }, limits)
	rightRegions := regions(reported.Fragments, func(f reportFragment) reportSelection { return f.Right }, limits)

	pair := Pair{
		SubmissionAID:   leftID,
		SubmissionBID:   rightID,
		Similarity:      reported.Similarity,
		LongestFragment: reported.LongestFragment,
		TotalOverlap:    reported.TotalOverlap,
		ARegions:        leftRegions,
		BRegions:        rightRegions,
	}

	// schema §5.5 calls submission_a_id < submission_b_id CRITICAL: the unique
	// index is on the triple, so the same pair sent both ways round stores
	// twice instead of replacing itself. The regions swap with the ids —
	// a highlight belongs to its own submission, not to a position.
	if pair.SubmissionAID > pair.SubmissionBID {
		pair.SubmissionAID, pair.SubmissionBID = pair.SubmissionBID, pair.SubmissionAID
		pair.ARegions, pair.BRegions = pair.BRegions, pair.ARegions
	}

	return pair, nil
}

// regions renders one side's fragments as the schema's pipe-separated string,
// converting Dolos's 0-based rows and columns to the 1-based coordinates a
// code viewer uses. A side with no fragments has nothing to highlight and is
// null, which the column and the schema both allow — an empty string would
// render as a region.
func regions(fragments []reportFragment, side func(reportFragment) reportSelection, limits Limits) *string {
	if len(fragments) == 0 {
		return nil
	}

	kept := fragments
	if len(kept) > limits.MaxRegionsPerPair {
		kept = kept[:limits.MaxRegionsPerPair]
	}

	parts := make([]string, 0, len(kept))
	for _, fragment := range kept {
		selection := side(fragment)
		parts = append(parts, fmt.Sprintf("%d,%d,%d,%d",
			selection.StartRow+1, selection.StartCol+1, selection.EndRow+1, selection.EndCol+1))
	}

	rendered := strings.Join(parts, "|")
	return &rendered
}
