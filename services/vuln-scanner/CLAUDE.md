# vuln-scanner (VUL) — Agent Context

## Purpose
Stateless batch worker (D-80: no DB access). Consumes `vuln-scanner` jobs
(one per analysis submission, pre-signed file URL), downloads the source,
runs CodeQL scanning, publishes full results to `result-analysis`.

## Status
A7 scaffold: smoke consumer only (logs deliveries). Real pipeline lands in Plan E
(E7 codeql service).

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
| `RMQ_PREFETCH` | no | 3 | D-76: VUL prefetch 3 |

## Testing
```bash
go test ./...                                   # unit
go run ./cmd/main.go                            # needs compose rabbitmq + RABBITMQ_URL
```
Follow TDD per `.agents/workflows/dev-rules.md`; task definitions in `docs/roadmap.md` §7.
