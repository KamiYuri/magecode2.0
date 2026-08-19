//go:build integration

// Integration test for E7: the real CodeQL CLI over a deliberately vulnerable
// Python fixture. Run:
//
//	CODEQL_BIN=/path/to/codeql go test -tags integration ./internal/codeql/ -v
//
// It skips when CODEQL_BIN is unset. It is slow — a database build plus a full
// query suite is a minute or two — which is why it sits behind the tag rather
// than in the default loop (roadmap §10).
package codeql_test

import (
	"context"
	"os"
	"testing"
	"time"

	"github.com/magecode/vuln-scanner/internal/codeql"
	"github.com/magecode/vuln-scanner/internal/job"
)

// The exact shape E7 names: user input concatenated into a SQL string.
const vulnerablePython = `import sqlite3
from flask import Flask, request

app = Flask(__name__)


@app.route("/user")
def show_user():
    name = request.args.get("name")
    connection = sqlite3.connect("app.db")
    cursor = connection.cursor()
    cursor.execute("SELECT * FROM users WHERE name = '" + name + "'")
    return str(cursor.fetchall())
`

// A single .java file with no project: the case --build-mode=none exists for.
const vulnerableJava = `import java.sql.*;

public class submission {
    public static void main(String[] args) throws Exception {
        Connection c = DriverManager.getConnection("jdbc:sqlite:app.db");
        Statement s = c.createStatement();
        ResultSet r = s.executeQuery("SELECT * FROM users WHERE name = '" + args[0] + "'");
        while (r.next()) System.out.println(r.getString(1));
    }
}
`

// C/C++ has no build-mode=none, so this is the path that runs a compiler.
const vulnerableCpp = `#include <cstdio>
#include <cstring>

int main(int argc, char** argv) {
    char buffer[16];
    if (argc > 1) {
        strcpy(buffer, argv[1]);
    }
    printf("%s\n", buffer);
    return 0;
}
`

const cleanPython = `def add(a, b):
    return a + b


if __name__ == "__main__":
    print(add(2, 3))
`

func runner(t *testing.T) *codeql.Runner {
	t.Helper()
	binary := os.Getenv("CODEQL_BIN")
	if binary == "" {
		t.Skip("CODEQL_BIN not set; point it at the codeql CLI from the bundle")
	}
	return codeql.New(codeql.Config{
		Binary:        binary,
		Timeout:       15 * time.Minute,
		Threads:       "2",
		WorkspaceRoot: t.TempDir(),
	})
}

func TestScanFindsSqlInjectionInAVulnerableFixture(t *testing.T) {
	findings, err := runner(t).Scan(context.Background(), []byte(vulnerablePython), job.Python)
	if err != nil {
		t.Fatalf("Scan: %v", err)
	}

	var found *codeqlFinding
	for i := range findings {
		if findings[i].Name == "py/sql-injection" {
			found = &codeqlFinding{findings[i].Severity, findings[i].StartLine, findings[i].FilePath}
			break
		}
	}
	if found == nil {
		names := make([]string, 0, len(findings))
		for _, finding := range findings {
			names = append(names, finding.Name)
		}
		t.Fatalf("py/sql-injection was not reported; got %v", names)
	}

	// The severity is not on the SARIF result — CodeQL leaves `level` off and
	// the answer is on the rule. This is the assertion that proves the parser
	// looks there.
	if found.severity != "error" {
		t.Errorf("severity = %q, want error", found.severity)
	}
	if found.startLine == nil {
		t.Error("the finding carries no line")
	}
	if found.filePath == nil {
		t.Error("the finding carries no file path")
	}
}

// Java and C++ each reach CodeQL differently, and both differences are
// invisible until the CLI is actually run — a wrong flag comes back as
// "Unknown option", not as a failing unit test.
func TestScanHandlesTheLanguagesThatNeedABuildDecision(t *testing.T) {
	cases := []struct {
		language job.Language
		source   string
		expect   string
	}{
		{job.Java, vulnerableJava, "java/concatenated-sql-query"},
		{job.Cpp, vulnerableCpp, "cpp/unbounded-write"},
	}

	for _, testCase := range cases {
		t.Run(string(testCase.language), func(t *testing.T) {
			findings, err := runner(t).Scan(context.Background(), []byte(testCase.source), testCase.language)
			if err != nil {
				t.Fatalf("Scan: %v", err)
			}

			for _, finding := range findings {
				if finding.Name == testCase.expect {
					return
				}
			}
			names := make([]string, 0, len(findings))
			for _, finding := range findings {
				names = append(names, finding.Name)
			}
			t.Errorf("%s was not reported; got %v", testCase.expect, names)
		})
	}
}

type codeqlFinding struct {
	severity  string
	startLine *int
	filePath  *string
}

// Clean code is the ordinary outcome and must come back as an empty list, not
// as a failure: api replaces a submission's findings with what this message
// carries (D5).
func TestScanReportsNoFindingsForCleanCode(t *testing.T) {
	findings, err := runner(t).Scan(context.Background(), []byte(cleanPython), job.Python)
	if err != nil {
		t.Fatalf("Scan: %v", err)
	}
	for _, finding := range findings {
		if finding.Severity == "error" {
			t.Errorf("clean code reported %s at error severity", finding.Name)
		}
	}
}
