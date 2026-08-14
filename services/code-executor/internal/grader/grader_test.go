package grader

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"strings"
	"testing"

	"github.com/magecode/code-executor/internal/judge0"
	"github.com/magecode/code-executor/internal/repository"
	"github.com/magecode/shared/go/apperror"
)

type fakeStore struct {
	job repository.Job

	loadErr    error
	saveErr    error
	saved      []repository.TestCaseResult
	processing int
	failedWith []repository.ExecutionStatus
	finalised  int
	summary    repository.Summary
}

func (f *fakeStore) Load(context.Context, int64) (repository.Job, error) {
	return f.job, f.loadErr
}

func (f *fakeStore) MarkProcessing(context.Context, int64) error {
	f.processing++
	return nil
}

func (f *fakeStore) MarkFailed(_ context.Context, _ int64, status repository.ExecutionStatus) error {
	f.failedWith = append(f.failedWith, status)
	return nil
}

func (f *fakeStore) SaveResult(_ context.Context, _ int64, result repository.TestCaseResult) error {
	if f.saveErr != nil {
		return f.saveErr
	}
	f.saved = append(f.saved, result)
	return nil
}

func (f *fakeStore) Finalise(context.Context, int64) (repository.Summary, error) {
	f.finalised++
	return f.summary, nil
}

type fakeSource struct {
	body string
	err  error
}

func (f fakeSource) Get(context.Context, string) (io.ReadCloser, error) {
	if f.err != nil {
		return nil, f.err
	}
	return io.NopCloser(strings.NewReader(f.body)), nil
}

type fakeRunner struct {
	results   []repository.TestCaseResult
	errs      []error
	calls     int
	gotSource string
}

func (f *fakeRunner) Run(
	_ context.Context, _ repository.Job, tc repository.TestCase, source string,
) (repository.TestCaseResult, error) {
	f.gotSource = source
	index := f.calls
	f.calls++

	if index < len(f.errs) && f.errs[index] != nil {
		return repository.TestCaseResult{}, f.errs[index]
	}
	result := repository.TestCaseResult{TestCaseID: tc.ID, Status: repository.StatusAccepted}
	if index < len(f.results) {
		result = f.results[index]
	}
	return result, nil
}

func jobWith(testCases int) repository.Job {
	job := repository.Job{
		SubmissionID: 42, FilePath: "submissions/1/42/main.py",
		Judge0LanguageID: 71, TimeLimitMs: 1000, MemoryLimitKB: 65536,
	}
	for i := range testCases {
		job.TestCases = append(job.TestCases, repository.TestCase{ID: int64(i + 1), Order: i})
	}
	return job
}

type fakeNotifier struct{ updates []Update }

func (f *fakeNotifier) TestCaseFinished(_ context.Context, u Update) {
	f.updates = append(f.updates, u)
}

func newGrader(store *fakeStore, source fakeSource, runner *fakeRunner) *Grader {
	return New(store, source, runner, &fakeNotifier{}, slog.New(slog.DiscardHandler))
}

func TestGradeRunsEveryTestCaseAndStoresEachVerdict(t *testing.T) {
	store := &fakeStore{
		job:     jobWith(3),
		summary: repository.Summary{Passed: 3, Total: 3, Status: repository.ExecutionAccepted},
	}
	runner := &fakeRunner{}

	summary, err := newGrader(store, fakeSource{body: "print(1)"}, runner).
		Grade(context.Background(), 42, "trace")
	if err != nil {
		t.Fatalf("Grade: %v", err)
	}

	if runner.calls != 3 {
		t.Errorf("runner called %d times, want 3", runner.calls)
	}
	if len(store.saved) != 3 {
		t.Errorf("stored %d results, want 3", len(store.saved))
	}
	if store.processing != 1 || store.finalised != 1 {
		t.Errorf("processing=%d finalised=%d, want 1 and 1", store.processing, store.finalised)
	}
	if summary.Status != repository.ExecutionAccepted {
		t.Errorf("Status = %q", summary.Status)
	}
}

// The source is read once, not once per test case: it is the same bytes every
// time and each read is a round trip to MinIO.
func TestGradeReadsTheSourceOnce(t *testing.T) {
	store := &fakeStore{job: jobWith(4)}
	runner := &fakeRunner{}

	if _, err := newGrader(store, fakeSource{body: "print(2)"}, runner).
		Grade(context.Background(), 42, "trace"); err != nil {
		t.Fatalf("Grade: %v", err)
	}

	if runner.gotSource != "print(2)" {
		t.Errorf("runner saw source %q", runner.gotSource)
	}
}

// Results are stored as they arrive so the verdict strip fills in live (D-81),
// rather than appearing all at once when the last test case finishes.
func TestGradeStoresEachResultBeforeRunningTheNext(t *testing.T) {
	store := &fakeStore{job: jobWith(3)}
	// The second test case fails, so if results were batched nothing at all
	// would have been stored.
	runner := &fakeRunner{errs: []error{nil, apperror.New(apperror.Transient, "judge0 down")}}

	_, err := newGrader(store, fakeSource{body: "x"}, runner).Grade(context.Background(), 42, "trace")
	if err == nil {
		t.Fatal("Grade swallowed the runner failure")
	}

	if len(store.saved) != 1 {
		t.Errorf("stored %d results, want the 1 that succeeded before the failure", len(store.saved))
	}
	if store.finalised != 0 {
		t.Error("a failed run must not finalise: the message is retried and grades again")
	}
}

// v3 §7: Judge0 refusing the language ends the run — every remaining test case
// would be refused for the same reason.
func TestGradeStopsAndMarksAnUnsupportedLanguage(t *testing.T) {
	store := &fakeStore{job: jobWith(5)}
	runner := &fakeRunner{errs: []error{judge0.ErrLanguageNotSupported}}

	summary, err := newGrader(store, fakeSource{body: "x"}, runner).
		Grade(context.Background(), 42, "trace")
	if err != nil {
		t.Fatalf("Grade: %v", err)
	}

	if runner.calls != 1 {
		t.Errorf("runner called %d times, want 1 (the rest are pointless)", runner.calls)
	}
	if len(store.failedWith) != 1 || store.failedWith[0] != repository.ExecutionLanguageNotSupported {
		t.Errorf("marked %v, want one language_not_supported", store.failedWith)
	}
	if summary.Status != repository.ExecutionLanguageNotSupported {
		t.Errorf("Status = %q", summary.Status)
	}
	if store.finalised != 0 {
		t.Error("nothing ran, so there is nothing to recount")
	}
}

// A submission that cannot be loaded keeps the repository's classification, or
// C4 would retry a permanent failure three times.
func TestGradePropagatesTheLoadClassification(t *testing.T) {
	store := &fakeStore{loadErr: apperror.New(apperror.Permanent, "submission 42 does not exist")}

	_, err := newGrader(store, fakeSource{}, &fakeRunner{}).Grade(context.Background(), 42, "trace")
	if err == nil {
		t.Fatal("Grade accepted a missing submission")
	}
	if !apperror.IsPermanent(err) {
		t.Errorf("classification lost: %v", err)
	}
	if store.processing != 0 {
		t.Error("a submission that could not be loaded must not be marked processing")
	}
}

// An unreachable bucket is worth retrying: the submission is durable and the
// object was written before the row committed (C2).
func TestGradeReportsAnUnreadableSourceAsTransient(t *testing.T) {
	store := &fakeStore{job: jobWith(2)}
	source := fakeSource{err: errors.New("dial minio: connection refused")}

	_, err := newGrader(store, source, &fakeRunner{}).Grade(context.Background(), 42, "trace")
	if err == nil {
		t.Fatal("Grade accepted an unreadable source")
	}
	if !apperror.IsTransient(err) {
		t.Errorf("error is not Transient, so it would dead-letter: %v", err)
	}
}

// A problem with no active test cases still finalises: C5's DeriveStatus is
// what decides 0/0 is an error, and it only gets the chance if we recount.
func TestGradeFinalisesEvenWithNoTestCases(t *testing.T) {
	store := &fakeStore{
		job:     jobWith(0),
		summary: repository.Summary{Status: repository.ExecutionError},
	}
	runner := &fakeRunner{}

	summary, err := newGrader(store, fakeSource{body: "x"}, runner).
		Grade(context.Background(), 42, "trace")
	if err != nil {
		t.Fatalf("Grade: %v", err)
	}

	if runner.calls != 0 {
		t.Errorf("runner called %d times, want 0", runner.calls)
	}
	if store.finalised != 1 {
		t.Error("the recount must still run")
	}
	if summary.Status != repository.ExecutionError {
		t.Errorf("Status = %q, want error", summary.Status)
	}
}

// v3 §7: a frame per finished test case, carrying the running tally so the
// strip fills in as the run goes.
func TestGradeNotifiesAfterEachTestCase(t *testing.T) {
	store := &fakeStore{job: jobWith(3)}
	runner := &fakeRunner{results: []repository.TestCaseResult{
		{Status: repository.StatusAccepted},
		{Status: repository.StatusWrongAnswer},
		{Status: repository.StatusAccepted},
	}}
	notifier := &fakeNotifier{}

	if _, err := New(store, fakeSource{body: "x"}, runner, notifier, slog.New(slog.DiscardHandler)).
		Grade(context.Background(), 42, "trace"); err != nil {
		t.Fatalf("Grade: %v", err)
	}

	if len(notifier.updates) != 3 {
		t.Fatalf("sent %d updates, want one per test case", len(notifier.updates))
	}

	// The tally is cumulative: 1, then still 1 after the failure, then 2.
	wantPassed := []int{1, 1, 2}
	for i, update := range notifier.updates {
		if update.Passed != wantPassed[i] {
			t.Errorf("update %d passed = %d, want %d", i, update.Passed, wantPassed[i])
		}
		if update.Total != 3 {
			t.Errorf("update %d total = %d, want 3", i, update.Total)
		}
		if update.Order != i {
			t.Errorf("update %d order = %d, want %d", i, update.Order, i)
		}
		if update.TraceID != "trace" {
			t.Errorf("update %d lost the trace id", i)
		}
	}
	if notifier.updates[1].Status != repository.StatusWrongAnswer {
		t.Errorf("update 1 status = %q, want the verdict that just happened", notifier.updates[1].Status)
	}
}

// The frame follows the write. A student told a cell passed, on a row that is
// not there yet, would refresh and watch it disappear.
func TestGradeDoesNotNotifyForAResultItCouldNotStore(t *testing.T) {
	store := &fakeStore{job: jobWith(2), saveErr: apperror.New(apperror.Transient, "db down")}
	notifier := &fakeNotifier{}

	_, err := New(store, fakeSource{body: "x"}, &fakeRunner{}, notifier, slog.New(slog.DiscardHandler)).
		Grade(context.Background(), 42, "trace")
	if err == nil {
		t.Fatal("Grade swallowed the store failure")
	}

	if len(notifier.updates) != 0 {
		t.Errorf("sent %d updates for results that were never stored", len(notifier.updates))
	}
}
