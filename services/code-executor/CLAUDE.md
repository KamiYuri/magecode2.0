# code-executor (CES) — Agent Context

## Purpose
Real-time code execution service. Consumes `code-executor` jobs (submission IDs),
reads test cases from PostgreSQL (direct DB access per D-81), runs code via Judge0 CE,
streams per-test-case results to the DB and signals api through `result-execution`.

## Status
Through C4: the consumer loop and its failure semantics. `internal/job` decodes
`job.code-executor.v1` strictly — an unknown field or an unusable id is a **Permanent**
error, so it dead-letters instead of burning the retry budget. The handler itself is a
stub; C5 (repository) and C6 (Judge0 client) fill it in and **must classify their own
errors**, because a bare error is treated as transient and retried three times.

## Tech Stack
Go 1.26+, shared packages from `shared/go` (logger, config, apperror, rmq, db, storage).

## Key Files
- `cmd/main.go` — entrypoint: signal handling, config load, queue consume, delivery handler
- `internal/job/` — wire format of the code-executor queue; `Decode` is the only reader
- `Dockerfile` — multi-stage build, context = monorepo root (D-79a)

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 5 | D-76: CES prefetch 5 |
| `WORKER_COUNT` | no | 5 | D-75: 5 handlers draining the prefetch window |

DB/MinIO/Judge0 vars arrive with Plan C tasks.

## Testing
```bash
go test ./...                                   # unit
RMQ_TEST_URL="amqp://user:pass@localhost:5672/vhost" \
  go test -tags integration -race ./internal/job/   # loop semantics vs compose rabbitmq
go run ./cmd/main.go                            # needs compose rabbitmq + RABBITMQ_URL
```
Follow TDD per `.agents/workflows/dev-rules.md`; task definitions in `docs/roadmap.md` §5.
