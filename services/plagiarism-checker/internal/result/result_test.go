package result

import (
	"encoding/json"
	"errors"
	"testing"
	"time"

	"github.com/magecode/plagiarism-checker/internal/dolos"
	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/shared/go/apperror"
)

func fixtureJob() job.Similarity {
	return job.Similarity{
		AnalysisProblemID:  7,
		Language:           job.Python,
		LanguageGroupIndex: 1,
		LanguageGroupTotal: 3,
		Submissions: []job.Submission{
			{SubmissionID: 11, AnalysisSubmissionID: 21, FileURL: "http://minio:9000/a"},
			{SubmissionID: 12, AnalysisSubmissionID: 22, FileURL: "http://minio:9000/b"},
			{SubmissionID: 13, AnalysisSubmissionID: 23, FileURL: "http://minio:9000/c"},
		},
		TraceID:   "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
		Timestamp: "2026-08-19T09:30:00Z",
		Version:   job.Version,
	}
}

func ptrInt(v int) *int          { return &v }
func ptrString(v string) *string { return &v }

func samplePairs() []dolos.Pair {
	return []dolos.Pair{{
		SubmissionAID:   11,
		SubmissionBID:   12,
		Similarity:      0.9125,
		LongestFragment: ptrInt(14),
		TotalOverlap:    ptrInt(28),
		ARegions:        ptrString("1,1,8,43"),
		BRegions:        ptrString("2,1,9,43"),
	}}
}

func TestCompletedEchoesTheJobAndCarriesThePairs(t *testing.T) {
	message := Completed(fixtureJob(), samplePairs(), nil, time.Date(2026, 8, 19, 9, 31, 0, 0, time.UTC))

	if message.Service != job.QueueName {
		t.Errorf("service = %q, want %q", message.Service, job.QueueName)
	}
	if message.Status != StatusCompleted {
		t.Errorf("status = %q", message.Status)
	}
	// api tracks completion on the echoed index (U-9), so a message that
	// does not echo it faithfully can never complete its batch.
	if message.LanguageGroupIndex != 1 || message.LanguageGroupTotal != 3 {
		t.Errorf("group = %d/%d, want 1/3", message.LanguageGroupIndex, message.LanguageGroupTotal)
	}
	if message.Language != string(job.Python) {
		t.Errorf("language = %q", message.Language)
	}
	if message.TraceID != "1b4e28ba-2fa1-11d2-883f-0016d3cca427" {
		t.Errorf("trace_id = %q — it must be the job's, so the two sides join in Loki", message.TraceID)
	}
	if message.Timestamp != "2026-08-19T09:31:00Z" {
		t.Errorf("timestamp = %q", message.Timestamp)
	}
	if len(message.Pairs) != 1 || message.Pairs[0].SubmissionAID != 11 {
		t.Fatalf("pairs = %+v", message.Pairs)
	}
	if message.Queue() != ResultQueue {
		t.Errorf("queue = %q, want %q", message.Queue(), ResultQueue)
	}
}

// Every submission the job named gets a status, whether it was compared or
// not: api writes plagiarism_status from this list and a submission missing
// from it keeps in_queue until D-82's sweeper.
func TestCompletedReportsEverySubmission(t *testing.T) {
	message := Completed(fixtureJob(), samplePairs(), nil, time.Now())

	if len(message.SubmissionStatuses) != 3 {
		t.Fatalf("len(submission_statuses) = %d, want 3", len(message.SubmissionStatuses))
	}
	for _, status := range message.SubmissionStatuses {
		if status.Status != StatusCompleted {
			t.Errorf("analysis_submission %d is %q", status.AnalysisSubmissionID, status.Status)
		}
	}
}

// One unreadable file costs its own submission and nothing else — the group
// still reports completed, with its other comparisons intact.
func TestCompletedMarksTheFailedSubmissionsOnly(t *testing.T) {
	failures := map[int64]error{22: apperror.New(apperror.Permanent, "source download answered 403 Forbidden")}

	message := Completed(fixtureJob(), samplePairs(), failures, time.Now())

	if message.Status != StatusCompleted {
		t.Errorf("group status = %q, want completed — one bad file is not a failed group", message.Status)
	}
	byID := map[int64]SubmissionStatus{}
	for _, status := range message.SubmissionStatuses {
		byID[status.AnalysisSubmissionID] = status
	}
	if byID[22].Status != StatusError {
		t.Errorf("analysis_submission 22 is %q, want error", byID[22].Status)
	}
	if byID[22].ErrorMessage == "" {
		t.Error("a failed submission carries no error_message")
	}
	for _, id := range []int64{21, 23} {
		if byID[id].Status != StatusCompleted {
			t.Errorf("analysis_submission %d is %q, want completed", id, byID[id].Status)
		}
	}
}

// A comparison that could not run is answered, not swallowed: api is waiting
// for this message and a dead-letter would leave the batch to burn D-82's
// 30-minute timeout for an answer SIM already has.
func TestFailedReportsTheWholeGroup(t *testing.T) {
	message := Failed(fixtureJob(), errors.New("dolos exceeded its 5m0s timeout"), time.Now())

	if message.Status != StatusError {
		t.Errorf("status = %q, want error", message.Status)
	}
	if message.ErrorMessage == "" {
		t.Error("an error message carries no error_message")
	}
	if len(message.Pairs) != 0 {
		t.Errorf("pairs = %+v, want empty", message.Pairs)
	}
	if len(message.SubmissionStatuses) != 3 {
		t.Fatalf("len(submission_statuses) = %d, want 3", len(message.SubmissionStatuses))
	}
	for _, status := range message.SubmissionStatuses {
		if status.Status != StatusError {
			t.Errorf("analysis_submission %d is %q, want error", status.AnalysisSubmissionID, status.Status)
		}
	}
	// The index still has to be echoed, or the batch never completes.
	if message.LanguageGroupIndex != 1 || message.LanguageGroupTotal != 3 {
		t.Errorf("group = %d/%d", message.LanguageGroupIndex, message.LanguageGroupTotal)
	}
}

// The schema types pairs as an array; a nil slice would encode as null and
// api's decoder would see a field of the wrong type.
func TestEmptyPairsEncodeAsAnArray(t *testing.T) {
	message := Completed(fixtureJob(), nil, nil, time.Now())

	encoded, err := json.Marshal(message)
	if err != nil {
		t.Fatalf("Marshal: %v", err)
	}
	var decoded map[string]json.RawMessage
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}
	if string(decoded["pairs"]) != "[]" {
		t.Errorf("pairs encoded as %s, want []", decoded["pairs"])
	}
}

// error_message is optional in the schema, and an empty string reads as "an
// error with no detail" rather than "no error".
func TestSuccessfulMessagesOmitErrorMessage(t *testing.T) {
	encoded, err := Completed(fixtureJob(), samplePairs(), nil, time.Now()).Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}
	var decoded map[string]any
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}
	if _, present := decoded["error_message"]; present {
		t.Error("a completed message carries error_message")
	}
	pairs := decoded["pairs"].([]any)
	pair := pairs[0].(map[string]any)
	for _, field := range []string{"longest_fragment", "total_overlap", "a_regions", "b_regions"} {
		if _, present := pair[field]; !present {
			t.Errorf("pair is missing %s", field)
		}
	}
}

// Nullable metrics must encode as null, not be dropped: the columns are
// nullable and api distinguishes "not measured" from "zero".
func TestNullMetricsEncodeAsNull(t *testing.T) {
	pairs := []dolos.Pair{{SubmissionAID: 11, SubmissionBID: 12, Similarity: 0.5}}

	encoded, err := Completed(fixtureJob(), pairs, nil, time.Now()).Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}
	var decoded struct {
		Pairs []map[string]any `json:"pairs"`
	}
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}
	for _, field := range []string{"longest_fragment", "total_overlap", "a_regions", "b_regions"} {
		if decoded.Pairs[0][field] != nil {
			t.Errorf("%s = %v, want null", field, decoded.Pairs[0][field])
		}
	}
}
