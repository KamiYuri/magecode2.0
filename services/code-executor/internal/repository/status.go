package repository

// TestCaseStatus is Judge0's verdict for one test case, spelled exactly as
// schema §10 and App\Enums\TestCaseStatus do — the api reads these strings
// back out of the same column.
type TestCaseStatus string

const (
	StatusAccepted            TestCaseStatus = "accepted"
	StatusWrongAnswer         TestCaseStatus = "wrong_answer"
	StatusTimeLimitExceeded   TestCaseStatus = "time_limit_exceeded"
	StatusMemoryLimitExceeded TestCaseStatus = "memory_limit_exceeded"
	StatusRuntimeError        TestCaseStatus = "runtime_error"
	StatusCompilationError    TestCaseStatus = "compilation_error"
	StatusInternalError       TestCaseStatus = "internal_error"
	StatusTimeout             TestCaseStatus = "timeout"
)

// ExecutionStatus is the submission-level verdict CES writes to
// submissions.execution_status.
type ExecutionStatus string

const (
	ExecutionInQueue              ExecutionStatus = "in_queue"
	ExecutionProcessing           ExecutionStatus = "processing"
	ExecutionAccepted             ExecutionStatus = "accepted"
	ExecutionPartiallyAccepted    ExecutionStatus = "partially_accepted"
	ExecutionError                ExecutionStatus = "error"
	ExecutionTimeout              ExecutionStatus = "timeout"
	ExecutionLanguageNotSupported ExecutionStatus = "language_not_supported"
)

// DeriveStatus turns the recount into the submission verdict (v3 §7,
// 2026-08-14): all passed is accepted, at least one is partially accepted,
// none is error.
//
// It never returns timeout. That value means the grading run itself did not
// finish, which is something only the caller knows; a test case that timed out
// is D-37's per-test-case status and counts as an ordinary failure here.
func DeriveStatus(passed, total int) ExecutionStatus {
	// Checked first, and not merely for tidiness: with no active test cases
	// `passed == total` holds trivially, so a submission nothing ran against
	// would otherwise be reported as accepted.
	if total <= 0 {
		return ExecutionError
	}
	if passed >= total {
		return ExecutionAccepted
	}
	if passed > 0 {
		return ExecutionPartiallyAccepted
	}
	return ExecutionError
}
