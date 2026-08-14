package job

import (
	"strings"
	"testing"

	"github.com/magecode/shared/go/apperror"
)

func TestDecodeAcceptsAWellFormedJob(t *testing.T) {
	body := []byte(`{"submission_id":42,"trace_id":"1b4e28ba-2fa1-11d2-883f-0016d3cca427",` +
		`"timestamp":"2026-08-14T09:30:00Z","version":"1.0"}`)

	got, err := Decode(body)
	if err != nil {
		t.Fatalf("Decode: %v", err)
	}
	if got.SubmissionID != 42 {
		t.Errorf("SubmissionID = %d, want 42", got.SubmissionID)
	}
	if got.TraceID != "1b4e28ba-2fa1-11d2-883f-0016d3cca427" {
		t.Errorf("TraceID = %q", got.TraceID)
	}
}

// Every rejection here must be Permanent: a message this service cannot read
// will not become readable on a retry, so it belongs on the DLQ immediately
// rather than after burning the retry budget (D-79e).
func TestDecodeRejectsUnusableMessagesPermanently(t *testing.T) {
	cases := map[string]string{
		"not json":            `{`,
		"empty body":          ``,
		"missing id":          `{"trace_id":"t","timestamp":"2026-08-14T09:30:00Z","version":"1.0"}`,
		"zero id":             `{"submission_id":0,"trace_id":"t","timestamp":"2026-08-14T09:30:00Z","version":"1.0"}`,
		"negative id":         `{"submission_id":-1,"trace_id":"t","timestamp":"2026-08-14T09:30:00Z","version":"1.0"}`,
		"unknown version":     `{"submission_id":1,"trace_id":"t","timestamp":"2026-08-14T09:30:00Z","version":"2.0"}`,
		"missing version":     `{"submission_id":1,"trace_id":"t","timestamp":"2026-08-14T09:30:00Z"}`,
		"id as string":        `{"submission_id":"1","trace_id":"t","timestamp":"2026-08-14T09:30:00Z","version":"1.0"}`,
		"unexpected property": `{"submission_id":1,"problem_id":7,"trace_id":"t","timestamp":"2026-08-14T09:30:00Z","version":"1.0"}`,
	}

	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			_, err := Decode([]byte(body))
			if err == nil {
				t.Fatal("Decode accepted an unusable message")
			}
			if !apperror.IsPermanent(err) {
				t.Errorf("error is not Permanent, so it would be retried: %v", err)
			}
		})
	}
}

// The schema forbids extra properties, and CES rejecting them is what keeps a
// producer from quietly adding a field CES ignores (D-84: the id is the whole
// message).
func TestDecodeNamesTheOffendingField(t *testing.T) {
	_, err := Decode([]byte(`{"submission_id":1,"problem_id":7,"trace_id":"t",` +
		`"timestamp":"2026-08-14T09:30:00Z","version":"1.0"}`))
	if err == nil {
		t.Fatal("Decode accepted an unknown field")
	}
	if !strings.Contains(err.Error(), "problem_id") {
		t.Errorf("error should name the unknown field, got: %v", err)
	}
}
