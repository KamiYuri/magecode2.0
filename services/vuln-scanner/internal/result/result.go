// Package result builds the messages VUL sends back to api on the
// result-analysis queue.
//
// The wire format is the VUL branch of
// `shared/schemas/result.analysis.v1.schema.json`. api is the single writer of
// analysis rows (D-80), and it **replaces** a submission's findings rather
// than upserting them (D5) — `vulnerability_results` has no key to upsert on,
// since two findings can differ only by line. So this message is the complete
// picture for the submission, and an empty findings list means the scan found
// nothing rather than "no news".
package result

import (
	"encoding/json"
	"time"

	"github.com/magecode/vuln-scanner/internal/job"
	"github.com/magecode/vuln-scanner/internal/sarif"
)

// ResultQueue is where api listens for finished analysis work (D-79).
const ResultQueue = "result-analysis"

// Statuses this service reports. They describe whether VUL got through the
// work, not how dangerous the code turned out to be.
const (
	StatusCompleted     = "completed"
	StatusError         = "error"
	StatusNotApplicable = "not_applicable"
)

const timeFormat = "2006-01-02T15:04:05Z"

// Finding is one vulnerability on the wire. Every location field is a pointer:
// SARIF makes them all optional, the columns are nullable, and a zero would be
// stored as line 0 of a file that has none.
type Finding struct {
	Name        string  `json:"name"`
	Description *string `json:"description"`
	Severity    string  `json:"severity"`
	FilePath    *string `json:"file_path"`
	StartLine   *int    `json:"start_line"`
	StartColumn *int    `json:"start_column"`
	EndLine     *int    `json:"end_line"`
	EndColumn   *int    `json:"end_column"`
}

// Analysis is `result.analysis` v1.0, VUL branch.
type Analysis struct {
	AnalysisSubmissionID int64     `json:"analysis_submission_id"`
	Service              string    `json:"service"`
	Status               string    `json:"status"`
	ErrorMessage         string    `json:"error_message,omitempty"`
	Findings             []Finding `json:"findings"`
	TraceID              string    `json:"trace_id"`
	Timestamp            string    `json:"timestamp"`
	Version              string    `json:"version"`
}

// Completed builds the message for a submission VUL scanned. An empty findings
// list is the ordinary outcome and says the code is clean.
func Completed(j job.Scan, findings []sarif.Finding, at time.Time) Analysis {
	message := envelope(j, StatusCompleted, at)
	message.Findings = wire(findings)
	return message
}

// NotApplicable builds the message for a language VUL cannot scan. api also
// reaches this state on its own — a null `codeql_language` is never published
// (D3) — so this covers a language whose suite this build does not carry.
func NotApplicable(j job.Scan, at time.Time) Analysis {
	return envelope(j, StatusNotApplicable, at)
}

// Failed builds the message for a scan VUL could not complete.
func Failed(j job.Scan, cause error, at time.Time) Analysis {
	message := envelope(j, StatusError, at)
	// Optional in the schema and for debugging only: api logs it and never
	// shows it to a user (§2.5).
	message.ErrorMessage = truncate(cause.Error(), 1000)
	return message
}

func envelope(j job.Scan, status string, at time.Time) Analysis {
	return Analysis{
		AnalysisSubmissionID: j.AnalysisSubmissionID,
		Service:              job.QueueName,
		Status:               status,
		Findings:             []Finding{},
		// The job's trace id, not a new one: it is what joins the two sides
		// of this submission in Loki (D-88).
		TraceID:   j.TraceID,
		Timestamp: at.UTC().Format(timeFormat),
		Version:   job.Version,
	}
}

func wire(findings []sarif.Finding) []Finding {
	converted := make([]Finding, 0, len(findings))
	for _, finding := range findings {
		converted = append(converted, Finding{
			Name:        truncate(finding.Name, 255),
			Description: finding.Description,
			Severity:    finding.Severity,
			FilePath:    finding.FilePath,
			StartLine:   finding.StartLine,
			StartColumn: finding.StartColumn,
			EndLine:     finding.EndLine,
			EndColumn:   finding.EndColumn,
		})
	}
	return converted
}

// truncate keeps a value inside its column. `name` is varchar(255) in
// `vulnerability_results`, and a rejected insert would lose the whole message.
func truncate(value string, limit int) string {
	if len(value) <= limit {
		return value
	}
	return value[:limit]
}

// Queue is where the message goes.
func (a Analysis) Queue() string { return ResultQueue }

// TraceIdentifier is the header value the publisher sets (D-88).
func (a Analysis) TraceIdentifier() string { return a.TraceID }

// Encode renders the message body.
func (a Analysis) Encode() ([]byte, error) { return json.Marshal(a) }
