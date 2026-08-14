package judge0

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/magecode/code-executor/internal/repository"
	"github.com/magecode/shared/go/apperror"
)

// stub answers every request with one canned Judge0 response, and records the
// last request so the tests can check what CES actually sent.
type stub struct {
	server   *httptest.Server
	lastPath string
	lastAuth string
	lastBody map[string]any
}

func newStub(t *testing.T, status int, body any) *stub {
	t.Helper()
	s := &stub{}
	s.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s.lastPath = r.URL.RequestURI()
		s.lastAuth = r.Header.Get("X-Auth-Token")
		_ = json.NewDecoder(r.Body).Decode(&s.lastBody)

		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_ = json.NewEncoder(w).Encode(body)
	}))
	t.Cleanup(s.server.Close)
	return s
}

func (s *stub) client() *Client {
	return New(Config{BaseURL: s.server.URL, AuthToken: "secret", Timeout: 5 * time.Second})
}

func testCase() repository.TestCase {
	return repository.TestCase{ID: 7, Input: "1 2\n", ExpectedOutput: "3\n", Order: 0}
}

func job() repository.Job {
	return repository.Job{
		SubmissionID: 42, Judge0LanguageID: 71, TimeLimitMs: 1500, MemoryLimitKB: 65536,
	}
}

// The response shape Judge0 returns from POST /submissions?wait=true.
func response(statusID int, extra map[string]any) map[string]any {
	body := map[string]any{
		"status": map[string]any{"id": statusID, "description": "whatever"},
		"stdout": "3\n",
		"time":   "0.012",
		"memory": 3072,
	}
	for k, v := range extra {
		body[k] = v
	}
	return body
}

// C6's headline requirement: every Judge0 status has a defined verdict, and
// none of them silently becomes something else.
func TestRunMapsEveryJudge0Status(t *testing.T) {
	cases := []struct {
		statusID int
		want     repository.TestCaseStatus
	}{
		{3, repository.StatusAccepted},
		{4, repository.StatusWrongAnswer},
		{5, repository.StatusTimeLimitExceeded},
		{6, repository.StatusCompilationError},
		{7, repository.StatusRuntimeError},  // SIGSEGV
		{8, repository.StatusRuntimeError},  // SIGXFSZ
		{9, repository.StatusRuntimeError},  // SIGFPE
		{10, repository.StatusRuntimeError}, // SIGABRT
		{11, repository.StatusRuntimeError}, // NZEC
		{12, repository.StatusRuntimeError}, // other
		{13, repository.StatusInternalError},
		{14, repository.StatusInternalError}, // exec format error
	}

	for _, tc := range cases {
		t.Run(http.StatusText(200)+"-"+string(rune('0'+tc.statusID%10)), func(t *testing.T) {
			s := newStub(t, http.StatusCreated, response(tc.statusID, nil))

			got, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
			if err != nil {
				t.Fatalf("Run: %v", err)
			}
			if got.Status != tc.want {
				t.Errorf("status %d mapped to %q, want %q", tc.statusID, got.Status, tc.want)
			}
		})
	}
}

// Judge0 has no memory-limit status: isolate kills the process and it arrives
// as a signal. Reading the reported memory is the only way to tell a student
// they ran out of memory rather than crashed (v3 §7).
func TestRunReadsAnOverLimitRuntimeErrorAsMemoryExhaustion(t *testing.T) {
	s := newStub(t, http.StatusCreated, response(7, map[string]any{"memory": 65536}))

	got, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	if got.Status != repository.StatusMemoryLimitExceeded {
		t.Errorf("status = %q, want memory_limit_exceeded", got.Status)
	}
}

// The inference only ever narrows an error that is already an error: a passing
// run that happened to touch the cap is still accepted.
func TestRunDoesNotReinterpretANonErrorStatus(t *testing.T) {
	s := newStub(t, http.StatusCreated, response(3, map[string]any{"memory": 65536}))

	got, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	if got.Status != repository.StatusAccepted {
		t.Errorf("status = %q, want accepted", got.Status)
	}
}

func TestRunSendsTheProblemLimitsAndTheAuthToken(t *testing.T) {
	s := newStub(t, http.StatusCreated, response(3, nil))

	if _, err := s.client().Run(context.Background(), job(), testCase(), "print(1)"); err != nil {
		t.Fatalf("Run: %v", err)
	}

	if !strings.Contains(s.lastPath, "wait=true") {
		t.Errorf("path = %q, want wait=true (C6 runs synchronously)", s.lastPath)
	}
	if s.lastAuth != "secret" {
		t.Errorf("X-Auth-Token = %q, want the configured token", s.lastAuth)
	}
	// Judge0 counts seconds, the problem stores milliseconds.
	if got := s.lastBody["cpu_time_limit"]; got != 1.5 {
		t.Errorf("cpu_time_limit = %v, want 1.5", got)
	}
	if got := s.lastBody["memory_limit"]; got != float64(65536) {
		t.Errorf("memory_limit = %v, want 65536", got)
	}
	if got := s.lastBody["language_id"]; got != float64(71) {
		t.Errorf("language_id = %v, want 71", got)
	}
	if got := s.lastBody["stdin"]; got != "1 2\n" {
		t.Errorf("stdin = %v", got)
	}
	if got := s.lastBody["expected_output"]; got != "3\n" {
		t.Errorf("expected_output = %v", got)
	}
}

// D-38: the cap is applied here, at the producer, because the repository
// stores error_content verbatim on purpose.
func TestRunTruncatesErrorContentAtTheDocumentedBoundary(t *testing.T) {
	cases := map[string]int{
		"just under": MaxErrorContent - 1,
		"exactly at": MaxErrorContent,
		"over":       MaxErrorContent + 500,
	}

	for name, length := range cases {
		t.Run(name, func(t *testing.T) {
			s := newStub(t, http.StatusCreated, response(6, map[string]any{
				"compile_output": strings.Repeat("e", length),
			}))

			got, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
			if err != nil {
				t.Fatalf("Run: %v", err)
			}

			want := min(length, MaxErrorContent)
			if len(got.ErrorContent) != want {
				t.Errorf("len(ErrorContent) = %d, want %d", len(got.ErrorContent), want)
			}
		})
	}
}

// Truncation counts runes, not bytes: cutting UTF-8 mid-character would store
// a broken string, and Judge0 output carries whatever the program printed.
func TestRunTruncatesWithoutSplittingACharacter(t *testing.T) {
	s := newStub(t, http.StatusCreated, response(7, map[string]any{
		"stderr": strings.Repeat("é", MaxErrorContent+10),
	}))

	got, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
	if err != nil {
		t.Fatalf("Run: %v", err)
	}

	if count := len([]rune(got.ErrorContent)); count != MaxErrorContent {
		t.Errorf("rune count = %d, want %d", count, MaxErrorContent)
	}
	if !utf8Valid(got.ErrorContent) {
		t.Error("truncation produced invalid UTF-8")
	}
}

// A language Judge0 refuses is not a student error and not worth retrying:
// the submission ends as language_not_supported (schema §10).
func TestRunReportsAnUnsupportedLanguage(t *testing.T) {
	s := newStub(t, http.StatusUnprocessableEntity, map[string]any{
		"language_id": []string{"language with id 999 doesn't exist"},
	})

	_, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
	if err == nil {
		t.Fatal("Run accepted a language Judge0 rejected")
	}
	if !errors.Is(err, ErrLanguageNotSupported) {
		t.Errorf("error = %v, want ErrLanguageNotSupported", err)
	}
	if !apperror.IsPermanent(err) {
		t.Errorf("error is not Permanent, so the job would be retried: %v", err)
	}
}

// Judge0 being down is worth retrying; the queue's budget exists for this.
func TestRunReportsAServerErrorAsTransient(t *testing.T) {
	s := newStub(t, http.StatusInternalServerError, map[string]any{"error": "boom"})

	_, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
	if err == nil {
		t.Fatal("Run accepted a 500")
	}
	if !apperror.IsTransient(err) {
		t.Errorf("error is not Transient, so it would dead-letter: %v", err)
	}
}

// D-37, given the meaning settled in v3 §7: `timeout` is CES asking Judge0 and
// getting nothing back, not the program exceeding its own limit.
func TestRunTimesOutWaitingForJudge0(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		time.Sleep(200 * time.Millisecond)
		w.WriteHeader(http.StatusCreated)
	}))
	defer server.Close()

	client := New(Config{BaseURL: server.URL, AuthToken: "t", Timeout: 20 * time.Millisecond})

	_, err := client.Run(context.Background(), job(), testCase(), "print(1)")
	if err == nil {
		t.Fatal("Run returned before the timeout elapsed")
	}
	if !errors.Is(err, ErrJudge0Timeout) {
		t.Errorf("error = %v, want ErrJudge0Timeout", err)
	}
	if !apperror.IsTransient(err) {
		t.Errorf("a timeout should be retried, got: %v", err)
	}
}

func TestRunCarriesTheMeasurements(t *testing.T) {
	s := newStub(t, http.StatusCreated, response(3, map[string]any{
		"time": "0.123", "memory": 4096, "stdout": "hello\n",
	}))

	got, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
	if err != nil {
		t.Fatalf("Run: %v", err)
	}

	if got.TimeMs != 123 {
		t.Errorf("TimeMs = %v, want 123 (Judge0 reports seconds)", got.TimeMs)
	}
	if got.MemoryKB != 4096 {
		t.Errorf("MemoryKB = %d, want 4096", got.MemoryKB)
	}
	if got.Output != "hello\n" {
		t.Errorf("Output = %q", got.Output)
	}
	if got.TestCaseID != 7 {
		t.Errorf("TestCaseID = %d, want the case it ran", got.TestCaseID)
	}
}

// Judge0 answers "in queue"/"processing" only when wait=true did not actually
// wait; treating that as a verdict would record a result for a run that has
// not happened.
func TestRunRejectsAnUnfinishedVerdict(t *testing.T) {
	for _, statusID := range []int{1, 2} {
		s := newStub(t, http.StatusCreated, response(statusID, nil))

		_, err := s.client().Run(context.Background(), job(), testCase(), "print(1)")
		if err == nil {
			t.Fatalf("Run accepted status %d as a verdict", statusID)
		}
		if !apperror.IsTransient(err) {
			t.Errorf("status %d should be retried, got: %v", statusID, err)
		}
	}
}

func utf8Valid(s string) bool {
	for _, r := range s {
		if r == '�' {
			return false
		}
	}
	return true
}
