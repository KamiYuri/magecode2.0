# vuln-scanner (VUL) — Agent Context

## Purpose
Stateless batch worker (D-80: no DB access). Consumes `vuln-scanner` jobs (one per
analysis submission, pre-signed file URL), runs CodeQL over the source, publishes the
findings to `result-analysis`.

## Status
E7 done: job decode, CodeQL wrapper for python/java/cpp, SARIF parsing and the
`result-analysis` message. Image and compose wiring belong to E8.

**VUL always answers.** A failure it can describe — an expired URL, a scan that timed out,
a language with no suite — is published as `status: error` (or `not_applicable`) and acked,
because api is waiting and a dead-letter costs the batch D-82's 30-minute timeout. Only an
undecodable body (it names no `analysis_submission_id`) and an unreachable broker are not
answered.

## Tech Stack
Go 1.26+, shared packages from `shared/go` (logger, config, apperror, rmq, httpsource).
CodeQL CLI from the bundle. No `db` package — D-80 forbids DB access.

## Key Files
- `cmd/main.go` — wiring: config, consumer, publisher, handler
- `internal/job` — strict decode of `job.vuln-scanner.v1`; every rejection Permanent
- `internal/codeql` — the CLI wrapper. **The per-language differences are the point**: a
  submission here is one file with no build, so python needs nothing, java needs
  `--build-mode=none` (no project to compile), and cpp has no such mode and is built around
  `g++ -fsyntax-only` — which is why the image carries a compiler. All three are pinned by
  the tagged integration test, because a wrong flag surfaces as "Unknown option" from the
  CLI and not as a failing unit test
- `internal/sarif` — SARIF → findings. **Severity is not on the result**: CodeQL leaves
  `level` off and the answer is on the rule (`defaultConfiguration.level`, then
  `properties["problem.severity"]`). Locations are optional at every level, so all four
  coordinates are nullable and none of them is invented
- `internal/result` — the `result.analysis.v1` VUL branch, validated against the schema file
- `internal/handler` — per-job orchestration and the always-answer rule

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 3 | D-76: VUL prefetch 3 |
| `CODEQL_TIMEOUT` | no | 600s | Bounds database build + analyze |
| `CODEQL_BIN` | no | codeql | CLI path |
| `CODEQL_THREADS` | no | 2 | Passed to both steps; VUL shares the box |
| `MAX_SOURCE_BYTES` | no | 1048576 | Refuses a body that is not a submission (D-34 caps at 50KB) |
| `WORKSPACE_ROOT` | no | /tmp | tmpfs in compose — the database never reaches a disk |

## Testing
```bash
go test ./...                                          # unit
CODEQL_BIN=/path/to/codeql go test -tags integration -timeout 40m ./internal/codeql/
```
The integration suite needs the CodeQL bundle
(`github/codeql-action` releases, `codeql-bundle-linux64.tar.zst`) and takes ~80s.
Task definitions in `docs/roadmap.md` §7.
