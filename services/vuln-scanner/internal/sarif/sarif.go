// Package sarif turns CodeQL's SARIF output into the findings
// `result.analysis.v1` describes.
//
// Two things about SARIF make this more than a field rename. Severity is not
// on the result: CodeQL leaves `level` off entirely and the answer lives on
// the rule, in `defaultConfiguration.level` or `properties["problem.severity"]`
// — verified against real output in testdata. And a location is optional at
// every level, so all four coordinates are nullable in the schema and every
// one of them has to survive being absent.
package sarif

import (
	"bytes"
	"encoding/json"
	"regexp"
	"strings"

	"github.com/magecode/shared/go/apperror"
)

// Severities are the schema's three, which SARIF's four levels map onto.
const (
	SeverityRecommendation = "recommendation"
	SeverityWarning        = "warning"
	SeverityError          = "error"
)

// levels maps SARIF's `level` (and CodeQL's `problem.severity`, which uses the
// same words) onto the schema's enum. SARIF's "none" and "note" are both
// advisory, and the schema has one word for that.
var levels = map[string]string{
	"error":   SeverityError,
	"warning": SeverityWarning,
	"note":    SeverityRecommendation,
	"none":    SeverityRecommendation,
}

// defaultSeverity is used when neither the result nor its rule says anything.
// Warning rather than error: an unlabelled finding is not evidence of the
// worst case, and an instructor's attention is finite.
const defaultSeverity = SeverityWarning

// linkPattern matches SARIF's message links, `[text](1)`, which are references
// into relatedLocations and read as noise once the location is gone.
var linkPattern = regexp.MustCompile(`\[([^\]]*)\]\(\d+\)`)

// Finding is one vulnerability in the schema's terms.
type Finding struct {
	Name        string
	Description *string
	Severity    string
	FilePath    *string
	StartLine   *int
	StartColumn *int
	EndLine     *int
	EndColumn   *int
}

type region struct {
	StartLine   *int `json:"startLine"`
	StartColumn *int `json:"startColumn"`
	EndLine     *int `json:"endLine"`
	EndColumn   *int `json:"endColumn"`
}

type physicalLocation struct {
	ArtifactLocation struct {
		URI string `json:"uri"`
	} `json:"artifactLocation"`
	Region *region `json:"region"`
}

type location struct {
	PhysicalLocation *physicalLocation `json:"physicalLocation"`
}

type rule struct {
	ID                   string `json:"id"`
	ShortDescription     *text  `json:"shortDescription"`
	DefaultConfiguration *struct {
		Level string `json:"level"`
	} `json:"defaultConfiguration"`
	Properties map[string]any `json:"properties"`
}

type text struct {
	Text string `json:"text"`
}

type result struct {
	RuleID    string     `json:"ruleId"`
	RuleIndex *int       `json:"ruleIndex"`
	Level     string     `json:"level"`
	Message   text       `json:"message"`
	Locations []location `json:"locations"`
}

type run struct {
	Tool struct {
		Driver struct {
			Rules []rule `json:"rules"`
		} `json:"driver"`
	} `json:"tool"`
	Results []result `json:"results"`
}

type document struct {
	Version string `json:"version"`
	Runs    []run  `json:"runs"`
}

// Parse reads a SARIF document into findings.
//
// A document VUL cannot read is Permanent: it will read the same on a retry,
// and the handler answers api with status=error rather than dead-lettering a
// submission the batch is waiting for.
func Parse(raw []byte) ([]Finding, error) {
	var decoded document
	if err := json.NewDecoder(bytes.NewReader(raw)).Decode(&decoded); err != nil {
		return nil, apperror.Wrap(apperror.Permanent, "decoding codeql sarif", err)
	}

	findings := make([]Finding, 0)
	for _, r := range decoded.Runs {
		byID := make(map[string]rule, len(r.Tool.Driver.Rules))
		for _, declared := range r.Tool.Driver.Rules {
			byID[declared.ID] = declared
		}

		for _, found := range r.Results {
			findings = append(findings, convert(found, byID, r.Tool.Driver.Rules))
		}
	}

	return findings, nil
}

func convert(found result, byID map[string]rule, ordered []rule) Finding {
	declared, known := byID[found.RuleID]
	// ruleIndex is how SARIF names a rule with no id on the result; both are
	// optional, so both are tried.
	if !known && found.RuleIndex != nil && *found.RuleIndex >= 0 && *found.RuleIndex < len(ordered) {
		declared = ordered[*found.RuleIndex]
		known = true
	}

	finding := Finding{
		Name:     found.RuleID,
		Severity: severity(found, declared, known),
	}
	if finding.Name == "" && known {
		finding.Name = declared.ID
	}

	if description := describe(found, declared, known); description != "" {
		finding.Description = &description
	}

	if len(found.Locations) > 0 && found.Locations[0].PhysicalLocation != nil {
		physical := found.Locations[0].PhysicalLocation
		if uri := physical.ArtifactLocation.URI; uri != "" {
			finding.FilePath = &uri
		}
		if physical.Region != nil {
			finding.StartLine = physical.Region.StartLine
			finding.StartColumn = physical.Region.StartColumn
			finding.EndLine = physical.Region.EndLine
			finding.EndColumn = physical.Region.EndColumn
		}
	}

	return finding
}

// severity reads the result, then the rule's default configuration, then the
// rule's problem.severity property. CodeQL's own output takes the second of
// those, which is why the first alone is not enough.
func severity(found result, declared rule, known bool) string {
	if mapped, ok := levels[strings.ToLower(found.Level)]; ok {
		return mapped
	}
	if known && declared.DefaultConfiguration != nil {
		if mapped, ok := levels[strings.ToLower(declared.DefaultConfiguration.Level)]; ok {
			return mapped
		}
	}
	if known {
		if raw, ok := declared.Properties["problem.severity"].(string); ok {
			if mapped, ok := levels[strings.ToLower(raw)]; ok {
				return mapped
			}
		}
	}
	return defaultSeverity
}

// describe prefers the result's own message, which names the specific problem,
// over the rule's general description — with SARIF's link syntax flattened,
// since the locations it points at are not carried.
func describe(found result, declared rule, known bool) string {
	if message := strings.TrimSpace(found.Message.Text); message != "" {
		return strings.TrimSpace(linkPattern.ReplaceAllString(message, "$1"))
	}
	if known && declared.ShortDescription != nil {
		return strings.TrimSpace(declared.ShortDescription.Text)
	}
	return ""
}
