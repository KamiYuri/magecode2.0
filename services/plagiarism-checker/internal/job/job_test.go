package job

import (
	"strings"
	"testing"

	"github.com/magecode/shared/go/apperror"
)

const validBody = `{"analysis_problem_id":7,"language":"python",` +
	`"language_group_index":0,"language_group_total":2,` +
	`"submissions":[` +
	`{"submission_id":11,"analysis_submission_id":21,"file_url":"http://minio:9000/a"},` +
	`{"submission_id":12,"analysis_submission_id":22,"file_url":"http://minio:9000/b"}],` +
	`"trace_id":"1b4e28ba-2fa1-11d2-883f-0016d3cca427",` +
	`"timestamp":"2026-08-19T09:30:00Z","version":"1.0"}`

func TestDecodeAcceptsAWellFormedJob(t *testing.T) {
	got, err := Decode([]byte(validBody))
	if err != nil {
		t.Fatalf("Decode: %v", err)
	}
	if got.AnalysisProblemID != 7 {
		t.Errorf("AnalysisProblemID = %d, want 7", got.AnalysisProblemID)
	}
	if got.Language != Python {
		t.Errorf("Language = %q, want python", got.Language)
	}
	if got.LanguageGroupTotal != 2 {
		t.Errorf("LanguageGroupTotal = %d, want 2", got.LanguageGroupTotal)
	}
	if len(got.Submissions) != 2 {
		t.Fatalf("len(Submissions) = %d, want 2", len(got.Submissions))
	}
	if got.Submissions[1].AnalysisSubmissionID != 22 {
		t.Errorf("second AnalysisSubmissionID = %d, want 22", got.Submissions[1].AnalysisSubmissionID)
	}
	if got.TraceID != "1b4e28ba-2fa1-11d2-883f-0016d3cca427" {
		t.Errorf("TraceID = %q", got.TraceID)
	}
}

// Every rejection is Permanent: a body SIM cannot read reads no better on the
// third attempt, so it dead-letters now instead of occupying the single
// prefetch slot three more times (D-79e, D-76).
func TestDecodeRejectsUnusableMessagesPermanently(t *testing.T) {
	cases := map[string]string{
		"not json":   `{`,
		"empty body": ``,
		"unknown field": strings.Replace(validBody,
			`"language":"python"`, `"language":"python","compared_submissions":3`, 1),
		"unknown language": strings.Replace(validBody, `"language":"python"`, `"language":"rust"`, 1),
		"missing language": strings.Replace(validBody, `"language":"python",`, ``, 1),
		"version 2.0":      strings.Replace(validBody, `"version":"1.0"`, `"version":"2.0"`, 1),
		"no batch id":      strings.Replace(validBody, `"analysis_problem_id":7`, `"analysis_problem_id":0`, 1),
		"negative index":   strings.Replace(validBody, `"language_group_index":0`, `"language_group_index":-1`, 1),
		"zero total":       strings.Replace(validBody, `"language_group_total":2`, `"language_group_total":0`, 1),
		// A positional index outside its own total means the producer and
		// this consumer disagree about how the batch was split, and api's
		// completion set (U-9) would never fill.
		"index beyond total": strings.Replace(validBody, `"language_group_index":0`, `"language_group_index":2`, 1),
		"one submission": strings.Replace(validBody,
			`{"submission_id":12,"analysis_submission_id":22,"file_url":"http://minio:9000/b"}`, ``, 1),
		"no submissions": strings.Replace(validBody,
			`{"submission_id":11,"analysis_submission_id":21,"file_url":"http://minio:9000/a"},`+
				`{"submission_id":12,"analysis_submission_id":22,"file_url":"http://minio:9000/b"}`, ``, 1),
		"submission_id 0": strings.Replace(validBody, `"submission_id":11`, `"submission_id":0`, 1),
		"analysis_submission_id 0": strings.Replace(validBody,
			`"analysis_submission_id":21`, `"analysis_submission_id":0`, 1),
		"empty file_url": strings.Replace(validBody, `"file_url":"http://minio:9000/a"`, `"file_url":""`, 1),
		// Two submissions with one id would land in one workspace file and
		// silently compare a file against itself.
		"duplicate submission_id": strings.Replace(validBody, `"submission_id":12`, `"submission_id":11`, 1),
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

func TestDecodeNamesTheOffendingField(t *testing.T) {
	body := strings.Replace(validBody, `"language":"python"`,
		`"language":"python","compared_submissions":3`, 1)

	_, err := Decode([]byte(body))
	if err == nil {
		t.Fatal("Decode accepted an unknown field")
	}
	if !strings.Contains(err.Error(), "compared_submissions") {
		t.Errorf("error should name the unknown field, got: %v", err)
	}
}

// Dolos routes on the file extension of what it is handed, so the mapping is
// contractual rather than cosmetic.
func TestLanguageExtensions(t *testing.T) {
	want := map[Language]string{Python: "py", Java: "java", C: "c", Cpp: "cpp"}

	for language, extension := range want {
		if got := language.Extension(); got != extension {
			t.Errorf("%s.Extension() = %q, want %q", language, got, extension)
		}
	}
}
