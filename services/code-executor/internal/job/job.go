// Package job decodes the messages CES consumes from the code-executor queue.
//
// The wire format is `shared/schemas/job.code-executor.v1.schema.json`, which
// is the top of the source-of-truth hierarchy. Decoding is strict on purpose:
// the schema sets additionalProperties to false, so a field CES does not know
// about means the producer and this consumer disagree about the contract, and
// silently ignoring it would hide that until something downstream broke.
package job

import (
	"bytes"
	"encoding/json"
	"fmt"

	"github.com/magecode/shared/go/apperror"
)

// Version is the only schema version this build understands.
const Version = "1.0"

// CodeExecution is one job: a submission id and the tracing metadata that
// travels with it. D-84 keeps the payload this small because CES reads
// everything else — test cases, language, file path — straight from the
// database (D-81), so a test case edited while the message waited in the
// queue is used in its current form.
type CodeExecution struct {
	SubmissionID int64  `json:"submission_id"`
	TraceID      string `json:"trace_id"`
	Timestamp    string `json:"timestamp"`
	Version      string `json:"version"`
}

// Decode parses and validates a message body.
//
// Every failure is classified Permanent: a body this service cannot read will
// read no better on the third attempt, so it goes to the DLQ now instead of
// occupying a worker three more times (D-79e).
func Decode(body []byte) (CodeExecution, error) {
	var decoded CodeExecution

	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.DisallowUnknownFields()

	if err := decoder.Decode(&decoded); err != nil {
		return CodeExecution{}, apperror.Wrap(apperror.Permanent, "decoding code-executor job", err)
	}

	if err := decoded.validate(); err != nil {
		return CodeExecution{}, err
	}

	return decoded, nil
}

func (j CodeExecution) validate() error {
	// The schema's minimum is 1, and a zero here is also what an absent
	// field decodes to — both mean the message names no submission.
	if j.SubmissionID < 1 {
		return apperror.New(apperror.Permanent,
			fmt.Sprintf("code-executor job has no usable submission_id (%d)", j.SubmissionID))
	}

	if j.Version != Version {
		return apperror.New(apperror.Permanent,
			fmt.Sprintf("code-executor job version %q is not %q", j.Version, Version))
	}

	return nil
}
