// Package storage provides the shared MinIO client for MageCode Go
// services: object get/put, pre-signed GET URLs with caller-chosen TTL
// (D-85: 6h for batch jobs), and the deterministic submission path layout
// of D-42/D-77.
package storage

import (
	"context"
	"fmt"
	"io"
	"net/url"
	"strings"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"

	"github.com/magecode/shared/go/apperror"
)

// Config configures New. All fields except UseSSL are required.
type Config struct {
	Endpoint  string // host:port, no scheme
	AccessKey string
	SecretKey string
	Bucket    string
	UseSSL    bool
}

func (c Config) validate() error {
	var missing []string
	if c.Endpoint == "" {
		missing = append(missing, "Endpoint")
	}
	if c.AccessKey == "" {
		missing = append(missing, "AccessKey")
	}
	if c.SecretKey == "" {
		missing = append(missing, "SecretKey")
	}
	if c.Bucket == "" {
		missing = append(missing, "Bucket")
	}
	if len(missing) > 0 {
		return apperror.New(apperror.Permanent,
			"storage config missing required fields: "+strings.Join(missing, ", "))
	}
	return nil
}

// Client wraps a MinIO client pinned to one bucket.
type Client struct {
	minio  *minio.Client
	bucket string
}

// New validates cfg, connects, and verifies the bucket exists so services
// fail at startup rather than on first upload.
func New(ctx context.Context, cfg Config) (*Client, error) {
	if err := cfg.validate(); err != nil {
		return nil, err
	}
	minioClient, err := minio.New(cfg.Endpoint, &minio.Options{
		Creds:  credentials.NewStaticV4(cfg.AccessKey, cfg.SecretKey, ""),
		Secure: cfg.UseSSL,
	})
	if err != nil {
		return nil, apperror.Wrap(apperror.Permanent, "creating minio client", err)
	}
	exists, err := minioClient.BucketExists(ctx, cfg.Bucket)
	if err != nil {
		return nil, apperror.Wrap(apperror.Transient, "checking bucket", err)
	}
	if !exists {
		return nil, apperror.New(apperror.Permanent,
			fmt.Sprintf("bucket %q does not exist", cfg.Bucket))
	}
	return &Client{minio: minioClient, bucket: cfg.Bucket}, nil
}

// Put uploads size bytes from r to path.
func (c *Client) Put(ctx context.Context, path string, r io.Reader, size int64, contentType string) error {
	_, err := c.minio.PutObject(ctx, c.bucket, path, r, size,
		minio.PutObjectOptions{ContentType: contentType})
	if err != nil {
		return apperror.Wrap(apperror.Transient, fmt.Sprintf("putting object %s", path), err)
	}
	return nil
}

// Get returns a reader for the object at path. The caller must close it.
func (c *Client) Get(ctx context.Context, path string) (io.ReadCloser, error) {
	object, err := c.minio.GetObject(ctx, c.bucket, path, minio.GetObjectOptions{})
	if err != nil {
		return nil, apperror.Wrap(apperror.Transient, fmt.Sprintf("getting object %s", path), err)
	}
	// GetObject is lazy; surface missing objects now instead of at first read.
	if _, err := object.Stat(); err != nil {
		_ = object.Close()
		return nil, apperror.Wrap(apperror.Permanent, fmt.Sprintf("stat object %s", path), err)
	}
	return object, nil
}

// PresignGet returns a URL that grants unauthenticated GET access to path
// until ttl elapses (D-85).
func (c *Client) PresignGet(ctx context.Context, path string, ttl time.Duration) (string, error) {
	presigned, err := c.minio.PresignedGetObject(ctx, c.bucket, path, ttl, url.Values{})
	if err != nil {
		return "", apperror.Wrap(apperror.Transient, fmt.Sprintf("presigning %s", path), err)
	}
	return presigned.String(), nil
}

// SubmissionPath builds the canonical object path for a submission file:
// submissions/{problem_id}/{submission_id}/{original_filename} (D-42/D-77).
func SubmissionPath(problemID, submissionID int64, filename string) string {
	return fmt.Sprintf("submissions/%d/%d/%s", problemID, submissionID, filename)
}
