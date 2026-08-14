package repository

import "testing"

func TestDeriveStatus(t *testing.T) {
	cases := []struct {
		name   string
		passed int
		total  int
		want   ExecutionStatus
	}{
		{"every test case passed", 3, 3, ExecutionAccepted},
		{"some passed", 2, 3, ExecutionPartiallyAccepted},
		{"one passed", 1, 3, ExecutionPartiallyAccepted},
		{"none passed", 0, 3, ExecutionError},
		{"single test case passed", 1, 1, ExecutionAccepted},

		// The case the counting rule gets wrong on its own: with no active
		// test cases, passed == total is trivially true, so a submission
		// nothing ran against would report accepted.
		{"no active test cases", 0, 0, ExecutionError},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := DeriveStatus(tc.passed, tc.total); got != tc.want {
				t.Errorf("DeriveStatus(%d, %d) = %q, want %q", tc.passed, tc.total, got, tc.want)
			}
		})
	}
}

// v3 §7: submission-level `timeout` means the run never finished. A submission
// whose test cases all timed out is an ordinary zero, so it reports `error`
// like any other — the per-test-case verdicts never reach this function.
func TestDeriveStatusIsBlindToWhyATestCaseFailed(t *testing.T) {
	if got := DeriveStatus(0, 5); got != ExecutionError {
		t.Errorf("DeriveStatus(0, 5) = %q, want %q regardless of the failure reason", got, ExecutionError)
	}
}

// The strings cross a service boundary: the api reads them back through
// App\Enums\ExecutionStatus and schema §10 pins the set.
func TestExecutionStatusValuesMatchTheSchema(t *testing.T) {
	want := map[ExecutionStatus]string{
		ExecutionInQueue:              "in_queue",
		ExecutionProcessing:           "processing",
		ExecutionAccepted:             "accepted",
		ExecutionPartiallyAccepted:    "partially_accepted",
		ExecutionError:                "error",
		ExecutionTimeout:              "timeout",
		ExecutionLanguageNotSupported: "language_not_supported",
	}

	for status, spelling := range want {
		if string(status) != spelling {
			t.Errorf("%v = %q, want %q", status, string(status), spelling)
		}
	}
}

func TestTestCaseStatusValuesMatchTheSchema(t *testing.T) {
	want := map[TestCaseStatus]string{
		StatusAccepted:            "accepted",
		StatusWrongAnswer:         "wrong_answer",
		StatusTimeLimitExceeded:   "time_limit_exceeded",
		StatusMemoryLimitExceeded: "memory_limit_exceeded",
		StatusRuntimeError:        "runtime_error",
		StatusCompilationError:    "compilation_error",
		StatusInternalError:       "internal_error",
		StatusTimeout:             "timeout",
	}

	for status, spelling := range want {
		if string(status) != spelling {
			t.Errorf("%v = %q, want %q", status, string(status), spelling)
		}
	}
}
