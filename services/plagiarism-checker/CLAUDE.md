# plagiarism-checker (SIM) — Agent Context

## Purpose
Stateless batch worker (D-80: no DB access). Consumes `plagiarism-checker` jobs
(one per language group, full data + pre-signed file URLs), downloads sources,
runs Dolos similarity analysis, publishes full results to `result-analysis`.

## Status
A7 scaffold: smoke consumer only (logs deliveries). Real pipeline lands in Plan E
(E1 downloads, E2 dolos wrapper, E3 result assembly).

## Tech Stack
Go 1.26+, shared packages from `shared/go` (logger, config, apperror, rmq).
No `db` package — D-80 forbids DB access.

## Key Files
- `cmd/main.go` — entrypoint: signal handling, config load, queue consume
- `Dockerfile` — multi-stage build, context = monorepo root (D-79a)

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 1 | D-76: SIM prefetch 1 (heavy jobs) |

## Testing
```bash
go test ./...                                   # unit
go run ./cmd/main.go                            # needs compose rabbitmq + RABBITMQ_URL
```
Follow TDD per `.agents/workflows/dev-rules.md`; task definitions in `docs/roadmap.md` §7.
