package job

import (
	"encoding/json"
	"time"

	"github.com/magecode/code-executor/internal/repository"
)

// ResultQueue is where api listens for finished submissions (D-83).
const ResultQueue = "result-execution"

// Result statuses, distinct from the submission's own verdict: this says
// whether CES got through the work, not how the student's program did.
const (
	ResultProcessing = "processing"
	ResultCompleted  = "completed"
	ResultError      = "error"
)

// LatestResult is the test case that just finished — one cell of the verdict
// strip (ui-ux §4.2). Present only on a progress message.
type LatestResult struct {
	TestCaseOrder int    `json:"test_case_order"`
	Status        string `json:"status"`
}

// ExecutionResult is `result.execution` v1.0.
//
// It carries the verdict and the counters even though CES has already written
// them to the database, so api can push the WebSocket frame without a query
// standing between the student and their result (rabbitmq-schemas §4.1).
type ExecutionResult struct {
	SubmissionID    int64  `json:"submission_id"`
	Service         string `json:"service"`
	Status          string `json:"status"`
	ExecutionStatus string `json:"execution_status"`
	TestcasesPassed int    `json:"testcases_passed"`
	TestcasesTotal  int    `json:"testcases_total"`
	// ErrorMessage is omitted unless Status is "error"; the schema marks it
	// optional and an empty string would read as "an error with no detail".
	ErrorMessage string `json:"error_message,omitempty"`
	// LatestResult accompanies status=processing and is absent otherwise.
	LatestResult *LatestResult `json:"latest_result,omitempty"`
	TraceID      string        `json:"trace_id"`
	Timestamp    string        `json:"timestamp"`
	Version      string        `json:"version"`
}

// Progress builds the message sent after one test case finishes, so the
// student's verdict strip fills in as the run goes rather than all at once
// (v3 §7, 2026-08-14).
//
// The counters are the run so far, not the final tally: passed is what has
// passed up to now and total is how many test cases the run will cover.
func Progress(
	submissionID int64,
	passed, total int,
	latest LatestResult,
	traceID string,
	at time.Time,
) ExecutionResult {
	return ExecutionResult{
		SubmissionID:    submissionID,
		Service:         QueueName,
		Status:          ResultProcessing,
		ExecutionStatus: string(repository.ExecutionProcessing),
		TestcasesPassed: passed,
		TestcasesTotal:  total,
		LatestResult:    &latest,
		TraceID:         traceID,
		Timestamp:       at.UTC().Format("2006-01-02T15:04:05Z"),
		Version:         Version,
	}
}

// Completed builds the message for a submission CES graded to the end.
func Completed(submissionID int64, summary repository.Summary, traceID string, at time.Time) ExecutionResult {
	return ExecutionResult{
		SubmissionID:    submissionID,
		Service:         QueueName,
		Status:          ResultCompleted,
		ExecutionStatus: string(summary.Status),
		TestcasesPassed: summary.Passed,
		TestcasesTotal:  summary.Total,
		TraceID:         traceID,
		Timestamp:       at.UTC().Format("2006-01-02T15:04:05Z"),
		Version:         Version,
	}
}

func (r ExecutionResult) Queue() string { return ResultQueue }

func (r ExecutionResult) TraceIdentifier() string { return r.TraceID }

func (r ExecutionResult) Encode() ([]byte, error) { return json.Marshal(r) }
