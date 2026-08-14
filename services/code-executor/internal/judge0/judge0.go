// Package judge0 runs one test case at a time against Judge0 CE.
//
// Submissions are posted with `wait=true` so the verdict comes back on the
// same call: CES already runs D-75's five handlers concurrently, so the
// throughput comes from the pool rather than from juggling Judge0 tokens, and
// a synchronous call keeps a failure attached to the delivery that caused it.
//
// The status mapping is v3 §7 (2026-08-14). Judge0 publishes fourteen statuses
// and schema §10 defines eight verdicts; the two places the mapping had to
// decide rather than transcribe are memory exhaustion, which Judge0 does not
// report as such, and the meaning of `timeout`.
package judge0

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"time"

	"github.com/magecode/code-executor/internal/repository"
	"github.com/magecode/shared/go/apperror"
)

// MaxErrorContent is D-38's cap, counted in runes so a multi-byte character
// is never cut in half.
const MaxErrorContent = 5000

var (
	// ErrLanguageNotSupported means Judge0 refused the language outright. The
	// submission cannot be graded and a retry will not change that.
	ErrLanguageNotSupported = errors.New("judge0 does not support this language")

	// ErrJudge0Timeout means CES asked and got nothing back — D-37, and the
	// only thing `timeout` means at submission level (v3 §7). It is not a
	// program that exceeded its own time limit; that is status 5.
	ErrJudge0Timeout = errors.New("judge0 did not answer in time")
)

// Judge0 CE status ids, from its own documentation.
const (
	statusInQueue           = 1
	statusProcessing        = 2
	statusAccepted          = 3
	statusWrongAnswer       = 4
	statusTimeLimitExceeded = 5
	statusCompilationError  = 6
	statusRuntimeFirst      = 7 // SIGSEGV
	statusRuntimeLast       = 12
	statusInternalError     = 13
	statusExecFormatError   = 14
)

type Config struct {
	BaseURL   string
	AuthToken string
	// Timeout bounds one call. It has to exceed the problem's own time limit,
	// or CES would give up on a program Judge0 is still legitimately running.
	Timeout time.Duration
}

type Client struct {
	config Config
	http   *http.Client
}

func New(config Config) *Client {
	return &Client{
		config: config,
		http:   &http.Client{Timeout: config.Timeout},
	}
}

// submissionRequest is Judge0's POST /submissions body.
type submissionRequest struct {
	SourceCode     string  `json:"source_code"`
	LanguageID     int     `json:"language_id"`
	Stdin          string  `json:"stdin"`
	ExpectedOutput string  `json:"expected_output"`
	CPUTimeLimit   float64 `json:"cpu_time_limit"`
	MemoryLimit    int     `json:"memory_limit"`
}

// submissionResponse is the subset of Judge0's reply CES reads.
type submissionResponse struct {
	Status struct {
		ID          int    `json:"id"`
		Description string `json:"description"`
	} `json:"status"`
	Stdout        string `json:"stdout"`
	Stderr        string `json:"stderr"`
	CompileOutput string `json:"compile_output"`
	Message       string `json:"message"`
	// Judge0 reports seconds as a string, and null when it never ran.
	Time   string `json:"time"`
	Memory int    `json:"memory"`
}

// Run executes one test case and returns the verdict in the shape the
// repository stores.
//
// The source is passed in rather than read from job: it lives in MinIO, not
// in the database, and it is fetched once per submission — passing it here
// makes that "once, not once per test case" visible at the call site.
func (c *Client) Run(
	ctx context.Context,
	job repository.Job,
	testCase repository.TestCase,
	source string,
) (repository.TestCaseResult, error) {
	body, err := json.Marshal(submissionRequest{
		SourceCode:     source,
		LanguageID:     job.Judge0LanguageID,
		Stdin:          testCase.Input,
		ExpectedOutput: testCase.ExpectedOutput,
		// Judge0 counts seconds; problems store milliseconds.
		CPUTimeLimit: float64(job.TimeLimitMs) / 1000,
		MemoryLimit:  job.MemoryLimitKB,
	})
	if err != nil {
		return repository.TestCaseResult{}, apperror.Wrap(apperror.Permanent, "encoding judge0 request", err)
	}

	// base64_encoded=false: the source and the expected output travel as
	// plain UTF-8, which keeps a Vietnamese comment readable in the payload.
	url := c.config.BaseURL + "/submissions?base64_encoded=false&wait=true"

	request, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return repository.TestCaseResult{}, apperror.Wrap(apperror.Permanent, "building judge0 request", err)
	}
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("X-Auth-Token", c.config.AuthToken)

	response, err := c.http.Do(request)
	if err != nil {
		// Retryable: Judge0 restarting or a slow queue is a passing condition,
		// and the submission is already durable.
		return repository.TestCaseResult{}, apperror.Wrap(apperror.Transient,
			ErrJudge0Timeout.Error(), errors.Join(ErrJudge0Timeout, err))
	}
	defer func() { _ = response.Body.Close() }()

	payload, err := io.ReadAll(response.Body)
	if err != nil {
		return repository.TestCaseResult{}, apperror.Wrap(apperror.Transient, "reading judge0 response", err)
	}

	if err := c.checkStatusCode(response.StatusCode, payload); err != nil {
		return repository.TestCaseResult{}, err
	}

	var decoded submissionResponse
	if err := json.Unmarshal(payload, &decoded); err != nil {
		return repository.TestCaseResult{}, apperror.Wrap(apperror.Transient, "decoding judge0 response", err)
	}

	return c.verdict(job, testCase, decoded)
}

func (c *Client) checkStatusCode(code int, payload []byte) error {
	switch {
	case code >= 200 && code < 300:
		return nil
	case code == http.StatusUnprocessableEntity:
		// Judge0 answers 422 for a language id it does not know. Nothing about
		// the submission will change on a retry.
		return apperror.Wrap(apperror.Permanent, ErrLanguageNotSupported.Error(),
			errors.Join(ErrLanguageNotSupported, fmt.Errorf("judge0 said: %s", payload)))
	case code == http.StatusTooManyRequests, code >= 500:
		return apperror.New(apperror.Transient, fmt.Sprintf("judge0 returned %d: %s", code, payload))
	default:
		return apperror.New(apperror.Permanent, fmt.Sprintf("judge0 returned %d: %s", code, payload))
	}
}

func (c *Client) verdict(
	job repository.Job,
	testCase repository.TestCase,
	response submissionResponse,
) (repository.TestCaseResult, error) {
	if response.Status.ID == statusInQueue || response.Status.ID == statusProcessing {
		// wait=true should have blocked until a verdict existed. Recording
		// this as a result would store a verdict for a run that has not
		// happened, so it goes back on the queue instead.
		return repository.TestCaseResult{}, apperror.New(apperror.Transient,
			fmt.Sprintf("judge0 returned status %d before finishing", response.Status.ID))
	}

	result := repository.TestCaseResult{
		TestCaseID:   testCase.ID,
		Status:       mapStatus(response.Status.ID, response.Memory, job.MemoryLimitKB),
		Output:       response.Stdout,
		TimeMs:       parseSeconds(response.Time),
		MemoryKB:     response.Memory,
		ErrorContent: truncate(errorContent(response), MaxErrorContent),
	}

	return result, nil
}

// mapStatus is the table from v3 §7.
//
// The memory case is inference, not a status Judge0 reports: isolate kills an
// over-limit process and it arrives as a signal, so the only signal available
// is the memory figure reaching the cap the request set. It narrows an error
// that is already an error and never touches a passing run.
func mapStatus(statusID, memoryKB, memoryLimitKB int) repository.TestCaseStatus {
	switch {
	case statusID == statusAccepted:
		return repository.StatusAccepted
	case statusID == statusWrongAnswer:
		return repository.StatusWrongAnswer
	case statusID == statusTimeLimitExceeded:
		return repository.StatusTimeLimitExceeded
	case statusID == statusCompilationError:
		return repository.StatusCompilationError
	case statusID >= statusRuntimeFirst && statusID <= statusRuntimeLast:
		if memoryLimitKB > 0 && memoryKB >= memoryLimitKB {
			return repository.StatusMemoryLimitExceeded
		}
		return repository.StatusRuntimeError
	case statusID == statusInternalError, statusID == statusExecFormatError:
		return repository.StatusInternalError
	default:
		// A status Judge0 added since this was written. Calling it an internal
		// error is honest — CES does not know what happened — and keeps an
		// unknown value out of a column the api reads as a closed enum.
		return repository.StatusInternalError
	}
}

// errorContent picks the field that explains the failure: a compile error
// leaves compile_output, a crash leaves stderr, and isolate's own complaints
// arrive in message.
func errorContent(response submissionResponse) string {
	for _, candidate := range []string{response.CompileOutput, response.Stderr, response.Message} {
		if candidate != "" {
			return candidate
		}
	}
	return ""
}

// truncate cuts at a rune boundary (D-38). Judge0 output is whatever the
// program printed, so it may be UTF-8, and slicing bytes would leave a broken
// character in the database.
func truncate(value string, limit int) string {
	runes := []rune(value)
	if len(runes) <= limit {
		return value
	}
	return string(runes[:limit])
}

// parseSeconds converts Judge0's seconds-as-string into milliseconds. An
// absent or unparsable value is zero: the program never ran long enough to
// measure, which is what a compilation error looks like.
func parseSeconds(value string) float64 {
	seconds, err := strconv.ParseFloat(value, 64)
	if err != nil {
		return 0
	}
	return seconds * 1000
}
