// Package result builds the messages SIM sends back to api on the
// result-analysis queue.
//
// The wire format is the SIM branch of
// `shared/schemas/result.analysis.v1.schema.json`. api is the single writer of
// analysis rows (D-80), so this message is the whole of what a batch will ever
// know about this language group — including, deliberately, its failures: a
// group SIM cannot compare is answered with status=error rather than
// dead-lettered, because api is waiting and a message that never arrives costs
// the batch D-82's full 30-minute timeout for an answer SIM already has.
package result

import (
	"encoding/json"
	"time"

	"github.com/magecode/plagiarism-checker/internal/dolos"
	"github.com/magecode/plagiarism-checker/internal/job"
)

// ResultQueue is where api listens for finished analysis work (D-79).
const ResultQueue = "result-analysis"

// Statuses this service reports. They describe whether SIM got through the
// work, not how similar anything turned out to be.
const (
	StatusCompleted = "completed"
	StatusError     = "error"
)

// timeFormat is the schema's date-time, in UTC.
const timeFormat = "2006-01-02T15:04:05Z"

// Pair is one comparison on the wire. The nullable metrics are pointers so an
// unmeasured value encodes as null rather than as zero — api's columns are
// nullable and the two mean different things.
type Pair struct {
	SubmissionAID   int64   `json:"submission_a_id"`
	SubmissionBID   int64   `json:"submission_b_id"`
	Similarity      float64 `json:"similarity"`
	LongestFragment *int    `json:"longest_fragment"`
	TotalOverlap    *int    `json:"total_overlap"`
	ARegions        *string `json:"a_regions"`
	BRegions        *string `json:"b_regions"`
}

// SubmissionStatus is one submission's outcome inside the group.
type SubmissionStatus struct {
	AnalysisSubmissionID int64  `json:"analysis_submission_id"`
	Status               string `json:"status"`
	// ErrorMessage is omitted unless Status is error: the schema marks it
	// optional and an empty string reads as an error with no detail.
	ErrorMessage string `json:"error_message,omitempty"`
}

// Analysis is `result.analysis` v1.0, SIM branch.
type Analysis struct {
	AnalysisProblemID  int64              `json:"analysis_problem_id"`
	Service            string             `json:"service"`
	Status             string             `json:"status"`
	ErrorMessage       string             `json:"error_message,omitempty"`
	Language           string             `json:"language"`
	LanguageGroupIndex int                `json:"language_group_index"`
	LanguageGroupTotal int                `json:"language_group_total"`
	Pairs              []Pair             `json:"pairs"`
	SubmissionStatuses []SubmissionStatus `json:"submission_statuses"`
	TraceID            string             `json:"trace_id"`
	Timestamp          string             `json:"timestamp"`
	Version            string             `json:"version"`
}

// Completed builds the message for a group SIM compared.
//
// failures maps an analysis_submission_id to the reason its source never
// reached the workspace; those submissions are reported as errors while the
// rest of the group keeps its comparisons. api (D4) ingests both halves of
// that from the one message.
func Completed(j job.Similarity, pairs []dolos.Pair, failures map[int64]error, at time.Time) Analysis {
	message := envelope(j, at)
	message.Status = StatusCompleted
	message.Pairs = wirePairs(pairs)
	message.SubmissionStatuses = statuses(j, failures)
	return message
}

// Failed builds the message for a group SIM could not compare at all.
func Failed(j job.Similarity, cause error, at time.Time) Analysis {
	message := envelope(j, at)
	message.Status = StatusError
	message.ErrorMessage = cause.Error()
	message.Pairs = []Pair{}

	// Every submission carries the group's failure: api derives each
	// submission's plagiarism_status from this list, and one left out stays
	// in_queue until the sweeper.
	message.SubmissionStatuses = make([]SubmissionStatus, 0, len(j.Submissions))
	for _, submission := range j.Submissions {
		message.SubmissionStatuses = append(message.SubmissionStatuses, SubmissionStatus{
			AnalysisSubmissionID: submission.AnalysisSubmissionID,
			Status:               StatusError,
			ErrorMessage:         cause.Error(),
		})
	}

	return message
}

// envelope fills the fields both outcomes share. The group index and total are
// echoed rather than recomputed: api's completion set (U-9) is keyed on them,
// so a message that reports a different split can never complete its batch.
func envelope(j job.Similarity, at time.Time) Analysis {
	return Analysis{
		AnalysisProblemID:  j.AnalysisProblemID,
		Service:            job.QueueName,
		Language:           string(j.Language),
		LanguageGroupIndex: j.LanguageGroupIndex,
		LanguageGroupTotal: j.LanguageGroupTotal,
		Pairs:              []Pair{},
		SubmissionStatuses: []SubmissionStatus{},
		// The job's trace id, not a new one: it is what joins the two sides
		// of this batch in Loki (D-88).
		TraceID:   j.TraceID,
		Timestamp: at.UTC().Format(timeFormat),
		Version:   job.Version,
	}
}

func wirePairs(pairs []dolos.Pair) []Pair {
	wire := make([]Pair, 0, len(pairs))
	for _, pair := range pairs {
		wire = append(wire, Pair{
			SubmissionAID:   pair.SubmissionAID,
			SubmissionBID:   pair.SubmissionBID,
			Similarity:      pair.Similarity,
			LongestFragment: pair.LongestFragment,
			TotalOverlap:    pair.TotalOverlap,
			ARegions:        pair.ARegions,
			BRegions:        pair.BRegions,
		})
	}
	return wire
}

func statuses(j job.Similarity, failures map[int64]error) []SubmissionStatus {
	reported := make([]SubmissionStatus, 0, len(j.Submissions))
	for _, submission := range j.Submissions {
		status := SubmissionStatus{
			AnalysisSubmissionID: submission.AnalysisSubmissionID,
			Status:               StatusCompleted,
		}
		if cause, failed := failures[submission.AnalysisSubmissionID]; failed && cause != nil {
			status.Status = StatusError
			status.ErrorMessage = cause.Error()
		}
		reported = append(reported, status)
	}
	return reported
}

// Queue is where the message goes.
func (a Analysis) Queue() string { return ResultQueue }

// TraceIdentifier is the header value the publisher sets (D-88).
func (a Analysis) TraceIdentifier() string { return a.TraceID }

// Encode renders the message body.
func (a Analysis) Encode() ([]byte, error) { return json.Marshal(a) }
