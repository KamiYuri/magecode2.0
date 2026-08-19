// Package job decodes the messages SIM consumes from the plagiarism-checker
// queue.
//
// The wire format is `shared/schemas/job.plagiarism-checker.v1.schema.json`,
// the top of the source-of-truth hierarchy. Decoding is strict on purpose: the
// schema sets additionalProperties to false, so a field SIM does not know about
// means the producer and this consumer disagree about the contract, and
// ignoring it silently would hide that until something downstream broke.
package job

import (
	"bytes"
	"encoding/json"
	"fmt"

	"github.com/magecode/shared/go/apperror"
)

// Version is the only schema version this build understands.
const Version = "1.0"

// QueueName is the job queue and, per the result schema, this service's
// identifier in the messages it sends back.
const QueueName = "plagiarism-checker"

// Language is the Dolos parser key for a group. The set is the job schema's
// enum, which is narrower than `programming_languages.dolos_language` — api
// parks anything outside it as not_applicable rather than publishing (D3).
type Language string

const (
	Python Language = "python"
	Java   Language = "java"
	C      Language = "c"
	Cpp    Language = "cpp"
)

// extensions is how a language reaches Dolos. Files are named with these
// because tree-sitter selects its grammar from the extension when the caller
// does not pin one, and a mismatch parses a Java file as plain text.
var extensions = map[Language]string{
	Python: "py",
	Java:   "java",
	C:      "c",
	Cpp:    "cpp",
}

// Extension returns the source file extension for the language, without the
// leading dot. An unknown language returns the empty string, which Decode
// rejects before any caller can reach it.
func (l Language) Extension() string { return extensions[l] }

func (l Language) valid() bool {
	_, ok := extensions[l]
	return ok
}

// Submission is one file to compare, named by both of its ids: the submission
// for the pairs, the analysis submission for the per-submission statuses.
type Submission struct {
	SubmissionID         int64  `json:"submission_id"`
	AnalysisSubmissionID int64  `json:"analysis_submission_id"`
	FileURL              string `json:"file_url"`
}

// Similarity is one job: every submission of one language within one batch.
// SIM holds no database (D-80), so this message is the whole of what it knows.
type Similarity struct {
	AnalysisProblemID  int64        `json:"analysis_problem_id"`
	Language           Language     `json:"language"`
	LanguageGroupIndex int          `json:"language_group_index"`
	LanguageGroupTotal int          `json:"language_group_total"`
	Submissions        []Submission `json:"submissions"`
	TraceID            string       `json:"trace_id"`
	Timestamp          string       `json:"timestamp"`
	Version            string       `json:"version"`
}

// Decode parses and validates a message body.
//
// Every failure is classified Permanent: SIM's prefetch is 1 (D-76), so a body
// it cannot read would otherwise hold the only delivery slot for three more
// attempts that fail identically.
func Decode(body []byte) (Similarity, error) {
	var decoded Similarity

	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.DisallowUnknownFields()

	if err := decoder.Decode(&decoded); err != nil {
		return Similarity{}, apperror.Wrap(apperror.Permanent, "decoding plagiarism-checker job", err)
	}

	if err := decoded.validate(); err != nil {
		return Similarity{}, err
	}

	return decoded, nil
}

func (j Similarity) validate() error {
	reject := func(format string, args ...any) error {
		return apperror.New(apperror.Permanent, fmt.Sprintf(format, args...))
	}

	if j.Version != Version {
		return reject("plagiarism-checker job version %q is not %q", j.Version, Version)
	}
	// The schema's minimum is 1, and a zero is also what an absent field
	// decodes to — both mean the message names no batch.
	if j.AnalysisProblemID < 1 {
		return reject("plagiarism-checker job has no usable analysis_problem_id (%d)", j.AnalysisProblemID)
	}
	if !j.Language.valid() {
		return reject("plagiarism-checker job language %q is not one of python, java, c, cpp", j.Language)
	}
	if j.LanguageGroupTotal < 1 {
		return reject("plagiarism-checker job language_group_total is %d", j.LanguageGroupTotal)
	}
	// The index is positional within the total (§3.2), and api's completion
	// set (U-9) is keyed on it — one outside its own total can never complete
	// the batch, whoever else arrives.
	if j.LanguageGroupIndex < 0 || j.LanguageGroupIndex >= j.LanguageGroupTotal {
		return reject("plagiarism-checker job language_group_index %d is outside its total %d",
			j.LanguageGroupIndex, j.LanguageGroupTotal)
	}
	// Pairwise comparison of one file is not a smaller job, it is no job; api
	// marks such groups not_applicable and never publishes them.
	if len(j.Submissions) < 2 {
		return reject("plagiarism-checker job carries %d submissions, need at least 2", len(j.Submissions))
	}

	seen := make(map[int64]struct{}, len(j.Submissions))
	for i, submission := range j.Submissions {
		switch {
		case submission.SubmissionID < 1:
			return reject("submission %d has no usable submission_id (%d)", i, submission.SubmissionID)
		case submission.AnalysisSubmissionID < 1:
			return reject("submission %d has no usable analysis_submission_id (%d)",
				i, submission.AnalysisSubmissionID)
		case submission.FileURL == "":
			return reject("submission %d (id %d) carries no file_url", i, submission.SubmissionID)
		}
		// Two entries sharing an id would collide on one workspace filename,
		// and Dolos would compare the survivor against itself.
		if _, duplicate := seen[submission.SubmissionID]; duplicate {
			return reject("submission_id %d appears twice in one language group", submission.SubmissionID)
		}
		seen[submission.SubmissionID] = struct{}{}
	}

	return nil
}
