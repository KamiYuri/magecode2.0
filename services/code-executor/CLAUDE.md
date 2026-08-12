# code-executor (CES) — Agent Context

## Purpose
Real-time code execution service. Consumes `code-executor` jobs (submission IDs),
reads test cases from PostgreSQL (direct DB access per D-81), runs code via Judge0 CE,
streams per-test-case results to the DB and signals api through `result-execution`.

## Status
A7 scaffold: smoke consumer only (logs deliveries). Real pipeline lands in Plan C
(C4 consumer loop, C5 repository, C6 judge0 client).

## Tech Stack
Go 1.26+, shared packages from `shared/go` (logger, config, apperror, rmq, db, storage).

## Key Files
- `cmd/main.go` — entrypoint: signal handling, config load, queue consume
- `Dockerfile` — multi-stage build, context = monorepo root (D-79a)

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 5 | D-76: CES prefetch 5 |

DB/MinIO/Judge0 vars arrive with Plan C tasks.

## Testing
```bash
go test ./...                                   # unit
go run ./cmd/main.go                            # needs compose rabbitmq + RABBITMQ_URL
```
Follow TDD per `.agents/workflows/dev-rules.md`; task definitions in `docs/roadmap.md` §5.
