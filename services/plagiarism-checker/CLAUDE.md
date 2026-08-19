# plagiarism-checker (SIM) — Agent Context

## Purpose
Stateless batch worker (D-80: no DB access). Consumes `plagiarism-checker` jobs
(one per language group, full data + pre-signed file URLs), downloads sources,
runs Dolos similarity analysis, publishes full results to `result-analysis`.

## Status
E1 done: strict job decode, pre-signed-URL downloads into a temp workspace, cleanup on
settle. E2 (dolos wrapper) and E3 (result assembly + publish) still to come.

## Tech Stack
Go 1.26+, shared packages from `shared/go` (logger, config, apperror, rmq).
No `db` package — D-80 forbids DB access.

## Key Files
- `cmd/main.go` — entrypoint: signal handling, config load, consume loop, workspace lifecycle
- `internal/job` — strict decode of `job.plagiarism-checker.v1`; every rejection Permanent
- `internal/downloader` — pre-signed URL GET with a retry budget; 4xx Permanent, 5xx Transient
- `internal/workspace` — the per-job temp directory; files are named `{submission_id}.{ext}`,
  which is the only identity a file carries through Dolos
- `internal/handler` — per-job orchestration; one failed download costs its own submission only
- `Dockerfile` — multi-stage build, context = monorepo root (D-79a)

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 1 | D-76: SIM prefetch 1 (heavy jobs) |
| `DOLOS_TIMEOUT` | no | 300s | Wall clock for one Dolos run |
| `MAX_SOURCE_BYTES` | no | 1048576 | Refuses a body that is not a submission (D-34 caps at 50KB) |
| `WORKSPACE_ROOT` | no | /tmp | tmpfs in compose — student source never reaches a disk |

## Testing
```bash
go test ./...                                   # unit
RMQ_TEST_URL=amqp://... go test -tags integration ./...   # needs compose rabbitmq
go run ./cmd/main.go                            # needs compose rabbitmq + RABBITMQ_URL
```
Follow TDD per `.agents/workflows/dev-rules.md`; task definitions in `docs/roadmap.md` §7.
