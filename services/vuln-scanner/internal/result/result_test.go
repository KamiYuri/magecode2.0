package result

import (
	"bytes"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/santhosh-tekuri/jsonschema/v6"

	"github.com/magecode/vuln-scanner/internal/job"
	"github.com/magecode/vuln-scanner/internal/sarif"
)

// The contract itself, at the top of the source-of-truth hierarchy —
// validating against a copy would only prove VUL agrees with VUL.
const schemaPath = "../../../../shared/schemas/result.analysis.v1.schema.json"

func fixtureJob() job.Scan {
	return job.Scan{
		AnalysisSubmissionID: 501,
		SubmissionID:         42,
		FileURL:              "http://minio:9000/a",
		Language:             job.Python,
		TraceID:              "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
		Timestamp:            "2026-08-19T09:30:00Z",
		Version:              job.Version,
	}
}

func ptrInt(v int) *int          { return &v }
func ptrString(v string) *string { return &v }

func sampleFindings() []sarif.Finding {
	return []sarif.Finding{{
		Name:        "py/sql-injection",
		Description: ptrString("This SQL query depends on a user-provided value."),
		Severity:    sarif.SeverityError,
		FilePath:    ptrString("submission.py"),
		StartLine:   ptrInt(13),
		StartColumn: ptrInt(20),
		EndColumn:   ptrInt(69),
	}}
}

func compiledSchema(t *testing.T) *jsonschema.Schema {
	t.Helper()

	absolute, err := filepath.Abs(schemaPath)
	if err != nil {
		t.Fatalf("resolving schema path: %v", err)
	}
	raw, err := os.ReadFile(absolute)
	if err != nil {
		t.Fatalf("reading the result schema: %v", err)
	}
	document, err := jsonschema.UnmarshalJSON(bytes.NewReader(raw))
	if err != nil {
		t.Fatalf("parsing the result schema: %v", err)
	}

	compiler := jsonschema.NewCompiler()
	if err := compiler.AddResource("result.analysis.v1.schema.json", document); err != nil {
		t.Fatalf("adding the result schema: %v", err)
	}
	schema, err := compiler.Compile("result.analysis.v1.schema.json")
	if err != nil {
		t.Fatalf("compiling the result schema: %v", err)
	}
	return schema
}

func validate(t *testing.T, schema *jsonschema.Schema, message Analysis) {
	t.Helper()

	encoded, err := message.Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}
	instance, err := jsonschema.UnmarshalJSON(bytes.NewReader(encoded))
	if err != nil {
		t.Fatalf("re-reading the encoded message: %v", err)
	}
	if err := schema.Validate(instance); err != nil {
		t.Fatalf("message does not satisfy result.analysis.v1:\n%v\n\nmessage: %s", err, encoded)
	}
}

func TestCompletedCarriesTheFindings(t *testing.T) {
	message := Completed(fixtureJob(), sampleFindings(), time.Date(2026, 8, 19, 9, 31, 0, 0, time.UTC))

	if message.Service != job.QueueName {
		t.Errorf("service = %q", message.Service)
	}
	if message.Status != StatusCompleted {
		t.Errorf("status = %q", message.Status)
	}
	if len(message.Findings) != 1 || message.Findings[0].Name != "py/sql-injection" {
		t.Fatalf("findings = %+v", message.Findings)
	}
	if message.Timestamp != "2026-08-19T09:31:00Z" {
		t.Errorf("timestamp = %q", message.Timestamp)
	}
	if message.TraceID != fixtureJob().TraceID {
		t.Errorf("trace_id = %q — it must be the job's", message.TraceID)
	}
}

// Clean code is the ordinary outcome. api replaces a submission's findings
// (D5), so an empty list is what erases a previous run's — it must be an
// array, never null.
func TestCleanCodeIsCompletedWithAnEmptyArray(t *testing.T) {
	message := Completed(fixtureJob(), nil, time.Now())

	encoded, err := message.Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}
	var decoded map[string]json.RawMessage
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}
	if string(decoded["findings"]) != "[]" {
		t.Errorf("findings encoded as %s, want []", decoded["findings"])
	}
	if message.Status != StatusCompleted {
		t.Errorf("status = %q — finding nothing is a completed scan", message.Status)
	}
}

func TestAbsentLocationsEncodeAsNull(t *testing.T) {
	findings := []sarif.Finding{{Name: "py/x", Severity: sarif.SeverityWarning}}

	encoded, err := Completed(fixtureJob(), findings, time.Now()).Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}
	var decoded struct {
		Findings []map[string]any `json:"findings"`
	}
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}
	for _, field := range []string{"description", "file_path", "start_line", "start_column",
		"end_line", "end_column"} {
		if decoded.Findings[0][field] != nil {
			t.Errorf("%s = %v, want null", field, decoded.Findings[0][field])
		}
	}
}

// `vulnerability_results.name` is varchar(255); a rejected insert would cost
// api the whole message, not just the finding.
func TestALongRuleNameIsTruncatedToItsColumn(t *testing.T) {
	findings := []sarif.Finding{{Name: strings.Repeat("x", 400), Severity: sarif.SeverityWarning}}

	message := Completed(fixtureJob(), findings, time.Now())

	if len(message.Findings[0].Name) != 255 {
		t.Errorf("name is %d characters", len(message.Findings[0].Name))
	}
}

func TestFailedAndNotApplicableAreAnswers(t *testing.T) {
	failed := Failed(fixtureJob(), errors.New("codeql exceeded its 10m0s timeout"), time.Now())
	if failed.Status != StatusError || failed.ErrorMessage == "" {
		t.Errorf("failed = %+v", failed)
	}

	skipped := NotApplicable(fixtureJob(), time.Now())
	if skipped.Status != StatusNotApplicable {
		t.Errorf("status = %q", skipped.Status)
	}
	if len(skipped.Findings) != 0 {
		t.Errorf("findings = %+v", skipped.Findings)
	}
}

func TestEveryOutcomeSatisfiesTheSchema(t *testing.T) {
	schema := compiledSchema(t)

	validate(t, schema, Completed(fixtureJob(), sampleFindings(), time.Now()))
	validate(t, schema, Completed(fixtureJob(), nil, time.Now()))
	validate(t, schema, Completed(fixtureJob(),
		[]sarif.Finding{{Name: "py/x", Severity: sarif.SeverityRecommendation}}, time.Now()))
	validate(t, schema, NotApplicable(fixtureJob(), time.Now()))
	validate(t, schema, Failed(fixtureJob(), errors.New("boom"), time.Now()))
}

// Without this the validations above could be passing against a schema that
// accepts anything.
func TestTheSchemaRejectsAnInvalidSeverity(t *testing.T) {
	message := Completed(fixtureJob(), sampleFindings(), time.Now())
	message.Findings[0].Severity = "critical"

	encoded, err := message.Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}
	instance, err := jsonschema.UnmarshalJSON(bytes.NewReader(encoded))
	if err != nil {
		t.Fatalf("re-reading: %v", err)
	}
	if err := compiledSchema(t).Validate(instance); err == nil {
		t.Fatal("the schema accepted a severity outside its enum")
	}
}
