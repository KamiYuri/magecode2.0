// Package handler runs one SIM job: fetch the group's sources, compare them,
// and answer api. This file is the fetch half.
package handler

import (
	"context"
	"sync"

	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/plagiarism-checker/internal/workspace"
	"github.com/magecode/shared/go/httpsource"
)

// maxParallelDownloads bounds how many pre-signed URLs are in flight at once.
// A language group can hold a whole semester's submissions, and SIM's prefetch
// is 1 (D-76) precisely because one job is already heavy — opening a socket
// per submission would move that weight onto MinIO instead.
const maxParallelDownloads = 8

// Fetched is one submission's outcome: either a path in the workspace or the
// error that keeps it out of the comparison.
type Fetched struct {
	Submission job.Submission
	Path       string
	Err        error
}

// Fetch downloads every submission in the job into ws, in the job's order.
//
// A failure is recorded against its own submission and the rest continue: the
// result message carries a per-submission status, so one expired URL costs the
// group one file rather than every comparison in it.
func Fetch(ctx context.Context, ws *workspace.Workspace, client *httpsource.Client, j job.Similarity) []Fetched {
	results := make([]Fetched, len(j.Submissions))
	extension := j.Language.Extension()

	var wg sync.WaitGroup
	slots := make(chan struct{}, maxParallelDownloads)

	for i, submission := range j.Submissions {
		wg.Add(1)
		go func() {
			defer wg.Done()
			slots <- struct{}{}
			defer func() { <-slots }()

			results[i] = Fetched{Submission: submission}

			content, err := client.Download(ctx, submission.FileURL)
			if err != nil {
				results[i].Err = err
				return
			}
			path, err := ws.Write(submission.SubmissionID, extension, content)
			if err != nil {
				results[i].Err = err
				return
			}
			results[i].Path = path
		}()
	}

	wg.Wait()
	return results
}
