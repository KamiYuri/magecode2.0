// Package job decodes the messages VUL consumes from the vuln-scanner queue.
//
// The wire format is `shared/schemas/job.vuln-scanner.v1.schema.json`. Decoding
// is strict because the schema sets additionalProperties to false: a field VUL
// does not know about means the producer and this consumer disagree about the
// contract.
package job

import (
	"bytes"
	"encoding/json"
	"fmt"

	"github.com/magecode/shared/go/apperror"
)

// Version is the only schema version this build understands.
const Version = "1.0"

// QueueName is the job queue and this service's identifier in the messages it
// sends back.
const QueueName = "vuln-scanner"

// Language is a CodeQL language key. The job schema's enum is narrower than
// the set CodeQL supports because api routes on
// `programming_languages.codeql_language`, and CodeQL uses `cpp` for both C
// and C++ — a language with a null column is never published at all, api marks
// it not_applicable (D3).
type Language string

const (
	Python Language = "python"
	Java   Language = "java"
	Cpp    Language = "cpp"
)

// extensions name the file CodeQL's extractor will find in the workspace.
var extensions = map[Language]string{
	Python: "py",
	Java:   "java",
	Cpp:    "cpp",
}

// Extension returns the source file extension for the language, without the
// leading dot.
func (l Language) Extension() string { return extensions[l] }

func (l Language) valid() bool {
	_, ok := extensions[l]
	return ok
}

// Scan is one job: a single submission to scan. VUL holds no database (D-80),
// so this message is the whole of what it knows.
type Scan struct {
	AnalysisSubmissionID int64    `json:"analysis_submission_id"`
	SubmissionID         int64    `json:"submission_id"`
	FileURL              string   `json:"file_url"`
	Language             Language `json:"language"`
	TraceID              string   `json:"trace_id"`
	Timestamp            string   `json:"timestamp"`
	Version              string   `json:"version"`
}

// Decode parses and validates a message body.
//
// Every failure is classified Permanent: a body VUL cannot read reads no
// better on the third attempt, so it dead-letters now rather than occupying a
// prefetch slot three more times (D-79e).
func Decode(body []byte) (Scan, error) {
	var decoded Scan

	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.DisallowUnknownFields()

	if err := decoder.Decode(&decoded); err != nil {
		return Scan{}, apperror.Wrap(apperror.Permanent, "decoding vuln-scanner job", err)
	}

	if err := decoded.validate(); err != nil {
		return Scan{}, err
	}

	return decoded, nil
}

func (j Scan) validate() error {
	reject := func(format string, args ...any) error {
		return apperror.New(apperror.Permanent, fmt.Sprintf(format, args...))
	}

	if j.Version != Version {
		return reject("vuln-scanner job version %q is not %q", j.Version, Version)
	}
	// The schema's minimum is 1, and a zero is also what an absent field
	// decodes to — both mean the message names no submission.
	if j.AnalysisSubmissionID < 1 {
		return reject("vuln-scanner job has no usable analysis_submission_id (%d)", j.AnalysisSubmissionID)
	}
	if j.SubmissionID < 1 {
		return reject("vuln-scanner job has no usable submission_id (%d)", j.SubmissionID)
	}
	if j.FileURL == "" {
		return reject("vuln-scanner job carries no file_url")
	}
	if !j.Language.valid() {
		return reject("vuln-scanner job language %q is not one of python, java, cpp", j.Language)
	}
	if j.TraceID == "" {
		return reject("vuln-scanner job carries no trace_id")
	}

	return nil
}
