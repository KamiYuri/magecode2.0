package result

import (
	"bytes"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/santhosh-tekuri/jsonschema/v6"

	"github.com/magecode/plagiarism-checker/internal/dolos"
	"github.com/magecode/shared/go/apperror"
)

// schemaPath is the contract itself, at the top of the source-of-truth
// hierarchy — validating against a copy would only prove SIM agrees with SIM.
const schemaPath = "../../../../shared/schemas/result.analysis.v1.schema.json"

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

func TestCompletedMessageSatisfiesTheSchema(t *testing.T) {
	validate(t, compiledSchema(t), Completed(fixtureJob(), samplePairs(), nil, time.Now()))
}

func TestCompletedMessageWithNullMetricsSatisfiesTheSchema(t *testing.T) {
	pairs := []dolos.Pair{{SubmissionAID: 11, SubmissionBID: 12, Similarity: 0}}

	validate(t, compiledSchema(t), Completed(fixtureJob(), pairs, nil, time.Now()))
}

// The error paths are the ones a synthetic fixture never exercises and the
// ones api sees on a bad day, so they are validated too.
func TestPartialFailureMessageSatisfiesTheSchema(t *testing.T) {
	failures := map[int64]error{22: apperror.New(apperror.Permanent, "source download answered 403 Forbidden")}

	validate(t, compiledSchema(t), Completed(fixtureJob(), samplePairs(), failures, time.Now()))
}

func TestFailedMessageSatisfiesTheSchema(t *testing.T) {
	validate(t, compiledSchema(t), Failed(fixtureJob(), errors.New("dolos exceeded its 5m0s timeout"), time.Now()))
}

// The schema discriminates on `service` through a oneOf, so a SIM message must
// match the SIM branch and only that one — proving the test above is not
// passing against the AID or VUL shape by accident.
func TestSchemaRejectsAMessageMissingTheGroupIndex(t *testing.T) {
	message := Completed(fixtureJob(), samplePairs(), nil, time.Now())

	encoded, err := message.Encode()
	if err != nil {
		t.Fatalf("Encode: %v", err)
	}
	var fields map[string]any
	if err := json.Unmarshal(encoded, &fields); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}
	delete(fields, "language_group_index")

	stripped, err := json.Marshal(fields)
	if err != nil {
		t.Fatalf("Marshal: %v", err)
	}
	instance, err := jsonschema.UnmarshalJSON(bytes.NewReader(stripped))
	if err != nil {
		t.Fatalf("re-reading: %v", err)
	}
	if err := compiledSchema(t).Validate(instance); err == nil {
		t.Fatal("the schema accepted a SIM message with no language_group_index")
	}
}
