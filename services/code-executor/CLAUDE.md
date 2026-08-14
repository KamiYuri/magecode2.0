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
- `internal/repository/` — every read and write against Postgres. `SaveResult` **upserts**
  on `(submission_id, test_case_id)` (schema §3.7, Issue #4) because a redelivered message
  grades the submission again; `Finalise` recounts joined to `is_active` and derives the
  verdict via `DeriveStatus`
- `internal/config/` — joins compose's `DB_*` pieces into the single DSN `shared/go/db` wants
- `Dockerfile` — multi-stage build, context = monorepo root (D-79a)

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 5 | D-76: CES prefetch 5 |
| `WORKER_COUNT` | no | 5 | D-75: 5 handlers; also the DB pool size (D-89) |
| `DB_HOST` | yes | — | PgBouncer, never Postgres directly (D-89) |
| `DB_PORT` | no | 6432 | PgBouncer's port |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | yes | — | credentials, escaped into the DSN |

MinIO/Judge0 vars arrive with C6. Note `DB_SIMPLE_PROTOCOL` in compose controls nothing —
`shared/go/db` enforces the simple protocol unconditionally.

## Testing
```bash
go test ./...                                   # unit: DeriveStatus, DSN, job decoding
RMQ_TEST_URL="amqp://user:pass@localhost:5672/vhost" \
DB_TEST_DSN="postgres://user:pass@localhost:6432/magecode" \
  go test -tags integration -race ./...         # vs compose rabbitmq + postgres
go run ./cmd/main.go                            # needs compose rabbitmq + postgres
```

**Adding work to the handler?** An unclassified error is treated as transient and retried
three times (D-79e). Say `apperror.Permanent` for anything a retry cannot fix.
Follow TDD per `.agents/workflows/dev-rules.md`; task definitions in `docs/roadmap.md` §5.
