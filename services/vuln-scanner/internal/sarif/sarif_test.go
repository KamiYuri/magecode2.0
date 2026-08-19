package sarif

import (
	"os"
	"testing"

	"github.com/magecode/shared/go/apperror"
)

// The fixture is real CodeQL 2.26 output over a deliberately vulnerable Flask
// handler, trimmed to the rules it references. It is what pins the two things
// this package exists for: severity is not on the result, and the region has
// no endLine.
func TestParseReadsRealCodeqlOutput(t *testing.T) {
	raw, err := os.ReadFile("testdata/python-sql-injection.sarif")
	if err != nil {
		t.Fatalf("reading fixture: %v", err)
	}

	findings, err := Parse(raw)
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if len(findings) != 1 {
		t.Fatalf("len(findings) = %d, want 1", len(findings))
	}

	finding := findings[0]
	if finding.Name != "py/sql-injection" {
		t.Errorf("name = %q", finding.Name)
	}
	// CodeQL left `level` off the result entirely; the answer is on the rule.
	if finding.Severity != SeverityError {
		t.Errorf("severity = %q, want error", finding.Severity)
	}
	if finding.FilePath == nil || *finding.FilePath != "42.py" {
		t.Errorf("file_path = %v", finding.FilePath)
	}
	if finding.StartLine == nil || *finding.StartLine != 13 {
		t.Errorf("start_line = %v, want 13", finding.StartLine)
	}
	if finding.StartColumn == nil || *finding.StartColumn != 20 {
		t.Errorf("start_column = %v, want 20", finding.StartColumn)
	}
	// The region carries no endLine, which is why the column is nullable in
	// the schema and must not be invented here.
	if finding.EndLine != nil {
		t.Errorf("end_line = %v, want null", *finding.EndLine)
	}
	if finding.EndColumn == nil || *finding.EndColumn != 69 {
		t.Errorf("end_column = %v, want 69", finding.EndColumn)
	}
	if finding.Description == nil {
		t.Fatal("description is null")
	}
	// SARIF's `[text](1)` links point into relatedLocations, which do not
	// travel with the finding; the text stays, the reference goes.
	if got := *finding.Description; got != "This SQL query depends on a user-provided value." {
		t.Errorf("description = %q", got)
	}
}

func TestSeverityMapping(t *testing.T) {
	cases := []struct {
		name     string
		document string
		want     string
	}{
		{"result level error", resultWithLevel("error"), SeverityError},
		{"result level warning", resultWithLevel("warning"), SeverityWarning},
		{"result level note", resultWithLevel("note"), SeverityRecommendation},
		{"result level none", resultWithLevel("none"), SeverityRecommendation},
		{"rule default configuration", ruleWithSeverity(`"defaultConfiguration":{"level":"note"}`), SeverityRecommendation},
		{"rule problem.severity", ruleWithSeverity(`"properties":{"problem.severity":"error"}`), SeverityError},
		// An unlabelled finding is not evidence of the worst case, and an
		// instructor's attention is finite.
		{"nothing says anything", ruleWithSeverity(`"properties":{}`), SeverityWarning},
	}

	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			findings, err := Parse([]byte(testCase.document))
			if err != nil {
				t.Fatalf("Parse: %v", err)
			}
			if findings[0].Severity != testCase.want {
				t.Errorf("severity = %q, want %q", findings[0].Severity, testCase.want)
			}
		})
	}
}

func resultWithLevel(level string) string {
	return `{"version":"2.1.0","runs":[{"tool":{"driver":{"rules":[]}},"results":[` +
		`{"ruleId":"py/x","level":"` + level + `","message":{"text":"m"},"locations":[]}]}]}`
}

func ruleWithSeverity(ruleFields string) string {
	return `{"version":"2.1.0","runs":[{"tool":{"driver":{"rules":[` +
		`{"id":"py/x",` + ruleFields + `}]}},"results":[` +
		`{"ruleId":"py/x","message":{"text":"m"},"locations":[]}]}]}`
}

// A finding with no location at all is legal SARIF — a rule about the project
// rather than a line — and all four coordinates plus the path are nullable.
func TestParseKeepsFindingsWithNoLocation(t *testing.T) {
	findings, err := Parse([]byte(`{"version":"2.1.0","runs":[{"tool":{"driver":{"rules":[]}},` +
		`"results":[{"ruleId":"py/x","level":"warning","message":{"text":"m"}}]}]}`))
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if len(findings) != 1 {
		t.Fatalf("len(findings) = %d, want 1", len(findings))
	}

	finding := findings[0]
	if finding.FilePath != nil || finding.StartLine != nil || finding.StartColumn != nil ||
		finding.EndLine != nil || finding.EndColumn != nil {
		t.Errorf("a location was invented: %+v", finding)
	}
}

// Clean code is the ordinary outcome, and it must be an empty list rather than
// nil — the schema types findings as an array.
func TestParseAcceptsARunWithNoResults(t *testing.T) {
	findings, err := Parse([]byte(`{"version":"2.1.0","runs":[{"tool":{"driver":{"rules":[]}},"results":[]}]}`))
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if findings == nil {
		t.Fatal("findings is nil, which encodes as null rather than []")
	}
	if len(findings) != 0 {
		t.Errorf("len(findings) = %d", len(findings))
	}
}

func TestParseResolvesARuleByIndexWhenTheResultHasNoLevel(t *testing.T) {
	findings, err := Parse([]byte(`{"version":"2.1.0","runs":[{"tool":{"driver":{"rules":[` +
		`{"id":"py/other","defaultConfiguration":{"level":"note"}},` +
		`{"id":"py/x","defaultConfiguration":{"level":"error"}}]}},` +
		`"results":[{"ruleId":"py/x","ruleIndex":1,"message":{"text":"m"}}]}]}`))
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if findings[0].Severity != SeverityError {
		t.Errorf("severity = %q, want error", findings[0].Severity)
	}
}

func TestParseRejectsUnreadableOutputPermanently(t *testing.T) {
	_, err := Parse([]byte(`{`))
	if err == nil {
		t.Fatal("Parse accepted a broken document")
	}
	if !apperror.IsPermanent(err) {
		t.Errorf("error is not Permanent: %v", err)
	}
}

// Findings from every run are kept: a scan of one submission produces one run
// today, but dropping the rest silently would be the wrong failure if that
// ever changed.
func TestParseReadsEveryRun(t *testing.T) {
	findings, err := Parse([]byte(`{"version":"2.1.0","runs":[` +
		`{"tool":{"driver":{"rules":[]}},"results":[{"ruleId":"a","level":"error","message":{"text":"m"}}]},` +
		`{"tool":{"driver":{"rules":[]}},"results":[{"ruleId":"b","level":"note","message":{"text":"m"}}]}]}`))
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if len(findings) != 2 {
		t.Fatalf("len(findings) = %d, want 2", len(findings))
	}
}
