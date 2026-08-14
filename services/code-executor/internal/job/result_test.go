package job

import (
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/magecode/code-executor/internal/repository"
)

func TestCompletedCarriesTheVerdictAndCounters(t *testing.T) {
	at := time.Date(2026, 8, 14, 9, 30, 12, 0, time.UTC)
	summary := repository.Summary{Passed: 7, Total: 10, Status: repository.ExecutionPartiallyAccepted}

	result := Completed(1042, summary, "a1b2c3d4-e5f6-7890-abcd-ef1234567890", at)

	if result.SubmissionID != 1042 {
		t.Errorf("SubmissionID = %d", result.SubmissionID)
	}
	if result.Service != "code-executor" {
		t.Errorf("Service = %q, want the fixed value the schema requires", result.Service)
	}
	if result.Status != ResultCompleted {
		t.Errorf("Status = %q, want completed", result.Status)
	}
	if result.ExecutionStatus != "partially_accepted" {
		t.Errorf("ExecutionStatus = %q", result.ExecutionStatus)
	}
	if result.TestcasesPassed != 7 || result.TestcasesTotal != 10 {
		t.Errorf("counters = %d/%d, want 7/10", result.TestcasesPassed, result.TestcasesTotal)
	}
	if result.Timestamp != "2026-08-14T09:30:12Z" {
		t.Errorf("Timestamp = %q, want ISO 8601 UTC", result.Timestamp)
	}
	if result.Version != "1.0" {
		t.Errorf("Version = %q", result.Version)
	}
}

// The schema marks error_message optional; an empty string on a completed run
// would read as "an error whose detail nobody recorded".
func TestCompletedOmitsTheErrorMessage(t *testing.T) {
	encoded, err := Completed(1, repository.Summary{}, "t", time.Now()).Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}

	if strings.Contains(string(encoded), "error_message") {
		t.Errorf("payload carries error_message on a completed run: %s", encoded)
	}
}

// The keys are the contract's, not Go's field names: api reads them.
func TestEncodeUsesTheDocumentedKeys(t *testing.T) {
	encoded, err := Completed(1042, repository.Summary{
		Passed: 7, Total: 10, Status: repository.ExecutionPartiallyAccepted,
	}, "trace", time.Now()).Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}

	var decoded map[string]any
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}

	for _, key := range []string{
		"submission_id", "service", "status", "execution_status",
		"testcases_passed", "testcases_total", "trace_id", "timestamp", "version",
	} {
		if _, ok := decoded[key]; !ok {
			t.Errorf("payload is missing required key %q", key)
		}
	}
}

func TestResultGoesToTheResultQueue(t *testing.T) {
	if got := Completed(1, repository.Summary{}, "t", time.Now()).Queue(); got != "result-execution" {
		t.Errorf("Queue() = %q, want result-execution (D-83)", got)
	}
}
