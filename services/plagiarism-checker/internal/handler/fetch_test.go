package handler

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/magecode/shared/go/httpsource"
	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/plagiarism-checker/internal/workspace"
)

func fixtureJob(urls ...string) job.Similarity {
	submissions := make([]job.Submission, 0, len(urls))
	for i, url := range urls {
		submissions = append(submissions, job.Submission{
			SubmissionID:         int64(11 + i),
			AnalysisSubmissionID: int64(21 + i),
			FileURL:              url,
		})
	}
	return job.Similarity{
		AnalysisProblemID:  7,
		Language:           job.Python,
		LanguageGroupTotal: 1,
		Submissions:        submissions,
		TraceID:            "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
		Version:            job.Version,
	}
}

func testDownloader() *httpsource.Client {
	return httpsource.New(httpsource.Config{
		MaxBytes: 64 * 1024, Attempts: 2, BaseDelay: time.Millisecond, Timeout: 2 * time.Second,
	})
}

func TestFetchWritesEverySubmissionNamedByItsID(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("source of " + r.URL.Path))
	}))
	defer server.Close()

	ws, err := workspace.New(t.TempDir())
	if err != nil {
		t.Fatalf("workspace.New: %v", err)
	}
	defer func() { _ = ws.Close() }()

	fetched := Fetch(context.Background(), ws, testDownloader(), fixtureJob(server.URL+"/a", server.URL+"/b"))

	if len(fetched) != 2 {
		t.Fatalf("len(fetched) = %d, want 2", len(fetched))
	}
	for _, result := range fetched {
		if result.Err != nil {
			t.Fatalf("submission %d: %v", result.Submission.SubmissionID, result.Err)
		}
		if filepath.Base(result.Path) != map[int64]string{11: "11.py", 12: "12.py"}[result.Submission.SubmissionID] {
			t.Errorf("submission %d landed at %s", result.Submission.SubmissionID, result.Path)
		}
		if _, err := os.Stat(result.Path); err != nil {
			t.Errorf("submission %d: %v", result.Submission.SubmissionID, err)
		}
	}
}

// One unreadable file must not cost the group its other comparisons: the
// result schema carries a per-submission status for exactly this, and api
// (D4) ingests the pairs alongside the failure.
func TestFetchReportsPerSubmissionFailuresAndKeepsGoing(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/gone" {
			w.WriteHeader(http.StatusForbidden)
			return
		}
		_, _ = w.Write([]byte("fine"))
	}))
	defer server.Close()

	ws, err := workspace.New(t.TempDir())
	if err != nil {
		t.Fatalf("workspace.New: %v", err)
	}
	defer func() { _ = ws.Close() }()

	fetched := Fetch(context.Background(), ws, testDownloader(),
		fixtureJob(server.URL+"/a", server.URL+"/gone", server.URL+"/c"))

	byID := map[int64]Fetched{}
	for _, result := range fetched {
		byID[result.Submission.SubmissionID] = result
	}
	if byID[12].Err == nil {
		t.Error("the 403 submission reports no error")
	}
	if byID[12].Path != "" {
		t.Errorf("the failed submission has a path: %s", byID[12].Path)
	}
	for _, id := range []int64{11, 13} {
		if byID[id].Err != nil {
			t.Errorf("submission %d failed alongside its neighbour: %v", id, byID[id].Err)
		}
	}
}

// Order is the job's order, whatever order the downloads finished in — the
// per-submission statuses are read positionally by nothing, but a stable
// order keeps logs and test fixtures comparable between runs.
func TestFetchPreservesJobOrder(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/slow" {
			time.Sleep(20 * time.Millisecond)
		}
		_, _ = w.Write([]byte("x"))
	}))
	defer server.Close()

	ws, err := workspace.New(t.TempDir())
	if err != nil {
		t.Fatalf("workspace.New: %v", err)
	}
	defer func() { _ = ws.Close() }()

	fetched := Fetch(context.Background(), ws, testDownloader(),
		fixtureJob(server.URL+"/slow", server.URL+"/fast"))

	if fetched[0].Submission.SubmissionID != 11 || fetched[1].Submission.SubmissionID != 12 {
		t.Errorf("order = %d, %d", fetched[0].Submission.SubmissionID, fetched[1].Submission.SubmissionID)
	}
}
