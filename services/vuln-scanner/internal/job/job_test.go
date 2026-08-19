package job

import (
	"strings"
	"testing"

	"github.com/magecode/shared/go/apperror"
)

const validBody = `{"analysis_submission_id":501,"submission_id":42,` +
	`"file_url":"http://minio:9000/magecode/submissions/1/42/main.py",` +
	`"language":"python","trace_id":"1b4e28ba-2fa1-11d2-883f-0016d3cca427",` +
	`"timestamp":"2026-08-19T09:30:00Z","version":"1.0"}`

func TestDecodeAcceptsAWellFormedJob(t *testing.T) {
	got, err := Decode([]byte(validBody))
	if err != nil {
		t.Fatalf("Decode: %v", err)
	}
	if got.AnalysisSubmissionID != 501 || got.SubmissionID != 42 {
		t.Errorf("ids = (%d, %d), want (501, 42)", got.AnalysisSubmissionID, got.SubmissionID)
	}
	if got.Language != Python {
		t.Errorf("language = %q", got.Language)
	}
}

func TestDecodeRejectsUnusableMessagesPermanently(t *testing.T) {
	cases := map[string]string{
		"not json":      `{`,
		"empty body":    ``,
		"unknown field": strings.Replace(validBody, `"language":"python"`, `"language":"python","severity":"high"`, 1),
		"version 2.0":   strings.Replace(validBody, `"version":"1.0"`, `"version":"2.0"`, 1),
		"no analysis_submission_id": strings.Replace(validBody,
			`"analysis_submission_id":501`, `"analysis_submission_id":0`, 1),
		"no submission_id": strings.Replace(validBody, `"submission_id":42`, `"submission_id":0`, 1),
		"empty file_url":   strings.Replace(validBody, `"file_url":"http://minio:9000/magecode/submissions/1/42/main.py"`, `"file_url":""`, 1),
		// CodeQL says `cpp` for both C and C++, so `c` never reaches VUL —
		// api maps the column before publishing.
		"language c":       strings.Replace(validBody, `"language":"python"`, `"language":"c"`, 1),
		"unknown language": strings.Replace(validBody, `"language":"python"`, `"language":"rust"`, 1),
		"no trace_id":      strings.Replace(validBody, `"trace_id":"1b4e28ba-2fa1-11d2-883f-0016d3cca427"`, `"trace_id":""`, 1),
	}

	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			_, err := Decode([]byte(body))
			if err == nil {
				t.Fatal("Decode accepted an unusable message")
			}
			if !apperror.IsPermanent(err) {
				t.Errorf("error is not Permanent: %v", err)
			}
		})
	}
}

func TestLanguageExtensions(t *testing.T) {
	want := map[Language]string{Python: "py", Java: "java", Cpp: "cpp"}

	for language, extension := range want {
		if got := language.Extension(); got != extension {
			t.Errorf("%s.Extension() = %q, want %q", language, got, extension)
		}
	}
}
