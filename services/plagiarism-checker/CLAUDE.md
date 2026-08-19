# plagiarism-checker (SIM) — Agent Context

## Purpose
Stateless batch worker (D-80: no DB access). Consumes `plagiarism-checker` jobs
(one per language group, full data + pre-signed file URLs), downloads sources,
runs Dolos similarity analysis, publishes full results to `result-analysis`.

## Status
Plan E complete for SIM (E1–E3): job decode, downloads, Dolos comparison and the
`result-analysis` message. Images and compose wiring belong to E8.

**The rule this service follows is that SIM always answers.** A failure it can describe —
an unreadable source, a comparison that timed out — is published as `status: error` and
acked, because api is waiting on the message and a dead-letter costs the batch D-82's full
30-minute timeout. Only an undecodable body (it names no submission to report against) and
an unreachable broker are not answered.

## Tech Stack
Go 1.26+, shared packages from `shared/go` (logger, config, apperror, rmq).
No `db` package — D-80 forbids DB access.

## Key Files
- `cmd/main.go` — entrypoint: signal handling, config load, consume loop, workspace lifecycle
- `internal/job` — strict decode of `job.plagiarism-checker.v1`; every rejection Permanent
- `internal/downloader` — pre-signed URL GET with a retry budget; 4xx Permanent, 5xx Transient
- `internal/workspace` — the per-job temp directory; files are named `{submission_id}.{ext}`,
  which is the only identity a file carries through Dolos
- `internal/dolos` — runs `reporter/report.mjs` and parses it into ordered pairs; owns the
  0-based → 1-based coordinate conversion and the `a < b` pair ordering
- `reporter/report.mjs` — Node script over `@dodona/dolos-lib`. **It exists because the
  `dolos` CLI's CSV export has no fragment coordinates** and `a_regions`/`b_regions` are
  exactly those coordinates (v3 §7, 2026-08-19). Its native modules are built for the
  image's Node ABI, so run it through the image rather than a host node
- `internal/result` — the `result.analysis.v1` SIM branch; nullable metrics are pointers so
  "not measured" encodes as null rather than 0. Validated against the schema file itself
- `internal/handler` — per-job orchestration and the always-answer rule; one failed download
  costs its own submission only
- `Dockerfile` — multi-stage build, context = monorepo root (D-79a)

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 1 | D-76: SIM prefetch 1 (heavy jobs) |
| `DOLOS_TIMEOUT` | no | 300s | Wall clock for one Dolos run |
| `MAX_SOURCE_BYTES` | no | 1048576 | Refuses a body that is not a submission (D-34 caps at 50KB) |
| `WORKSPACE_ROOT` | no | /tmp | tmpfs in compose — student source never reaches a disk |
| `REPORTER_SCRIPT` | no | /app/reporter/report.mjs | Path to the bundled Dolos reporter |
| `NODE_BIN` | no | node | Interpreter for the reporter |
| `MAX_REGIONS_PER_PAIR` | no | 100 | Bounds one pair's highlight list (TEXT column) |

## Testing
```bash
go test ./...                                   # unit
RMQ_TEST_URL=amqp://... go test -tags integration ./...   # needs compose rabbitmq
SIM_REPORTER_SCRIPT=$PWD/reporter/report.mjs go test -tags integration ./internal/dolos/
# reporter deps: install with the image's Node ABI, e.g.
#   docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD/reporter:/w" -w /w node:22 npm ci
go run ./cmd/main.go                            # needs compose rabbitmq + RABBITMQ_URL
```
Follow TDD per `.agents/workflows/dev-rules.md`; task definitions in `docs/roadmap.md` §7.
