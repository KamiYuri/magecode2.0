// Package downloader fetches submission sources from the pre-signed URLs the
// job message carries.
//
// SIM holds no MinIO credentials (D-80/D-85): the URL is the whole of its
// access, signed against the internal endpoint and valid for six hours. So
// this is a plain HTTP GET with a retry budget, and its only real job is to
// classify what went wrong — §2.5 asks services to retry transient failures
// internally before reporting an error, and `shared/go/rmq` retries whatever
// escapes classified as Transient.
package downloader

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"time"

	"github.com/magecode/shared/go/apperror"
)

// Config bounds one download. Zero values take the documented defaults.
type Config struct {
	// MaxBytes caps a single body. D-34 caps a submission at 50KB, so
	// anything past a generous multiple of that is not a submission this
	// system wrote.
	MaxBytes int64
	// Attempts is the total number of tries, not the number of retries.
	Attempts int
	// BaseDelay is the first backoff; each retry doubles it.
	BaseDelay time.Duration
	// Timeout bounds one attempt, not the whole budget.
	Timeout time.Duration
}

const (
	defaultMaxBytes  = 1 << 20
	defaultAttempts  = 3
	defaultBaseDelay = 500 * time.Millisecond
	defaultTimeout   = 30 * time.Second
)

func (c Config) withDefaults() Config {
	if c.MaxBytes <= 0 {
		c.MaxBytes = defaultMaxBytes
	}
	if c.Attempts <= 0 {
		c.Attempts = defaultAttempts
	}
	if c.BaseDelay <= 0 {
		c.BaseDelay = defaultBaseDelay
	}
	if c.Timeout <= 0 {
		c.Timeout = defaultTimeout
	}
	return c
}

// Client downloads submission sources. It is safe for concurrent use.
type Client struct {
	cfg  Config
	http *http.Client
}

// New returns a Client with cfg's defaults filled in.
func New(cfg Config) *Client {
	cfg = cfg.withDefaults()
	return &Client{cfg: cfg, http: &http.Client{Timeout: cfg.Timeout}}
}

// Download fetches url, retrying transient failures within the attempt budget.
//
// The returned error is always classified: Permanent for a 4xx (an expired or
// wrong URL reads the same on every attempt) or an oversized body, Transient
// for anything the network might do differently next time.
func (c *Client) Download(ctx context.Context, url string) ([]byte, error) {
	delay := c.cfg.BaseDelay
	var lastErr error

	for attempt := 1; attempt <= c.cfg.Attempts; attempt++ {
		if err := ctx.Err(); err != nil {
			return nil, apperror.Wrap(apperror.Transient, "download cancelled", err)
		}

		body, err := c.attempt(ctx, url)
		if err == nil {
			return body, nil
		}
		if apperror.IsPermanent(err) {
			return nil, err
		}
		lastErr = err

		if attempt < c.cfg.Attempts {
			select {
			case <-ctx.Done():
				return nil, apperror.Wrap(apperror.Transient, "download cancelled", ctx.Err())
			case <-time.After(delay):
			}
			delay *= 2
		}
	}

	return nil, apperror.Wrap(apperror.Transient,
		fmt.Sprintf("downloading source after %d attempts", c.cfg.Attempts), lastErr)
}

func (c *Client) attempt(ctx context.Context, url string) ([]byte, error) {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		// A URL the http package will not even build a request for came
		// from the message, and the message will not change.
		return nil, apperror.Wrap(apperror.Permanent, "building download request", err)
	}

	response, err := c.http.Do(request)
	if err != nil {
		return nil, apperror.Wrap(apperror.Transient, "requesting source", err)
	}
	defer func() { _ = response.Body.Close() }()

	if response.StatusCode >= http.StatusBadRequest && response.StatusCode < http.StatusInternalServerError {
		return nil, apperror.New(apperror.Permanent,
			fmt.Sprintf("source download answered %d %s", response.StatusCode, http.StatusText(response.StatusCode)))
	}
	if response.StatusCode != http.StatusOK {
		return nil, apperror.New(apperror.Transient,
			fmt.Sprintf("source download answered %d %s", response.StatusCode, http.StatusText(response.StatusCode)))
	}

	// One byte past the cap is read deliberately: it is how an oversized
	// body is told apart from one that exactly fills it.
	body, err := io.ReadAll(io.LimitReader(response.Body, c.cfg.MaxBytes+1))
	if err != nil {
		return nil, apperror.Wrap(apperror.Transient, "reading source body", err)
	}
	if int64(len(body)) > c.cfg.MaxBytes {
		return nil, apperror.New(apperror.Permanent,
			fmt.Sprintf("source exceeds the %d byte cap", c.cfg.MaxBytes))
	}

	return body, nil
}
