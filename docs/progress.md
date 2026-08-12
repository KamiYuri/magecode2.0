# MageCode 2.0 — Progress Board

> **Live state document** — update at the start (`wip` claim) and end (result) of every
> session per `docs/session-guide.md`. Task definitions live in `docs/roadmap.md`; do not
> duplicate them here.
>
> **Status values**: `todo` · `wip` · `review` · `done` · `blocked` (+ one-line reason in Notes)

**Current milestone: M1** — exit: authz matrix test green; full CRUD via `/api/v1` on compose Postgres. (M0 passed 2026-08-12, see gate table.)

## M0 — Plan A: Shared Go Packages & Scaffolds

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| A1 logger | P0 | done | `ab7f1e5` | slog JSON handler per D-88; tests green (GOWORK=off — go.work still broken until A7) |
| A2 config | P0 | done | `5dff1fe` | fail-fast Load(spec) validates presence + coercion up front; getters infallible |
| A3 apperror | P0 | done | `3c32d1b` | outermost classification wins; unclassified errors are neither (consumer picks default) |
| A4 rmq | P0 | done | `fd47c34` | topology Q/Q.retry/Q.dlq; retry via republish + per-message TTL; integration suite: `go test -tags integration` (needs compose rabbitmq + RMQ_TEST_URL) |
| A5 db | P0 | done | `330280b` | simple_protocol enforced + conflicting mode rejected; integration via pgbouncer :6432 (DB_TEST_DSN) |
| A6 storage | P0 | done | `7215b0e` | presign verified credential-free + expiry 403; integration needs MINIO_TEST_* env |
| A7 scaffolds + go.work | P0 | done | `36c11f9` | 3 Go images 23.8MB (<30MB); E2E smoke: container consumed mgmt-API-published msg with trace_id |

## M1 — Plan B: API Core

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| B1 laravel skeleton | P0 | done | `5ce6da2` | PHP commands run in Docker (`make test-api`/`lint-api`): host PHP lacks pdo_pgsql+amqp. phpstan level 6 |
| B2 migrations | P0 | done | `c15faea` | Spatie tables dropped (U-2); `chk_analysis_scope` uses doc's OR not prototype's XOR — **needs your call**, see session log |
| B3 models + seeders | P0 | done | `512e2c3` | 11 backed enums added from schema §10; factories encode CHECK invariants; models carry `@property` for phpstan |
| B4 auth | P0 | wip | `api/feat/auth` | — |
| B5 rbac + policies | P0 | todo | — | privacy-tagged: authz matrix gates M1 |
| B6 org/course/semester/section crud | P0 | todo | — | — |
| B7 roster import + transfer | P1 | todo | — | — |
| B8 problems + test cases lifecycle | P0 | todo | — | — |
| B9 tags | P2 | todo | — | — |
| B10 problem bank | P1 | todo | — | — |
| B11 route conformance /api/v1 | P0 | todo | — | — |
| B12 profile + notifications | P2 | todo | — | — |

## M2 — Plan C: Submission Loop & CES

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| C1 minio storage service | P0 | todo | — | — |
| C2 submission endpoints + quota | P0 | todo | — | — |
| C3 amqp publisher | P0 | todo | — | — |
| C4 ces consumer loop | P0 | todo | — | — |
| C5 ces repository + upsert | P0 | todo | — | — |
| C6 ces judge0 client | P0 | todo | — | — |
| C7 result signalling + reverb | P0 | todo | — | — |
| C8 e2e submission script | P0 | todo | — | milestone gate M2 |

## M3 — Plan D: Analysis Orchestration (api)

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| D1 trigger + scope + is_latest | P0 | todo | — | — |
| D2 sim job building | P0 | todo | — | — |
| D3 aid/vul job publishing | P0 | todo | — | — |
| D4 sim result handler | P0 | todo | — | — |
| D5 aid/vul result handlers | P0 | todo | — | — |
| D6 batch completion + events | P0 | todo | — | — |
| D7 timeout scheduler | P1 | todo | — | — |
| D8 analysis read apis two-tier | P0 | todo | — | privacy-tagged: gates M3 |

## M3 — Plan E: Batch Workers

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| E1 sim skeleton + downloads | P0 | todo | — | — |
| E2 dolos wrapper + parsing | P0 | todo | — | — |
| E3 sim result assembly | P0 | todo | — | — |
| E4 aid consumer | P0 | todo | — | — |
| E5 aid codebert inference | P0 | todo | — | — |
| E6 aid result publish | P0 | todo | — | — |
| E7 vul codeql service | P1 | todo | — | — |
| E8 worker images + compose | P1 | todo | — | — |
| E9 e2e analysis script | P0 | todo | — | milestone gate M3 |

## M4–M5 — Plan F: Web Frontend

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| F1 bootstrap + tokens | P0 | todo | — | — |
| F2 api client + auth store | P0 | todo | — | — |
| F3 auth screens | P0 | todo | — | — |
| F4 app shell + dashboards | P0 | todo | — | — |
| F5 verdict kit + echo | P0 | todo | — | signature component |
| F6 student flow e2e | P0 | todo | — | — |
| F7 staff section workspace | P1 | todo | — | — |
| F8 analysis ui | P1 | todo | — | — |
| F9 similarity viewer two-tier | P0 | todo | — | privacy-tagged: gates M4 |
| F10 bank + admin screens | P1 | todo | — | — |
| F11 a11y + responsive pass | P1 | todo | — | — |

## M6 — Plan G: Production Readiness

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| G1 metrics endpoints | P1 | todo | — | — |
| G2 dashboards + alert drills | P2 | todo | — | — |
| G3 security pass | P0 | todo | — | privacy-tagged: gates M6 |
| G4 load test 500 ccu | P1 | todo | — | — |
| G5 ops docs + restore drill | P2 | todo | — | — |
| G6 ci pipeline | P2 | todo | — | — |

## Milestone Gate Results

| Gate | Date | Result | Evidence |
|---|---|---|---|
| M0 exit | 2026-08-12 | PASS | `go test ./...` green in shared/go (6 pkgs, unit); integration green vs compose: rmq (roundtrip/DLX/drain), db (pgbouncer 6432 simple protocol), storage (presign). Smoke pub/sub: code-executor container consumed a mgmt-API-published message end to end (trace_id `smoke-a7`). |
| M1 exit (authz matrix) | — | — | — |
| M2 exit (C8) | — | — | — |
| M3 exit (E9) | — | — | — |
| M4 exit (Playwright suites) | — | — | — |
| M5 exit (bank e2e + axe) | — | — | — |
| M6 exit (load + security) | — | — | — |

## Session Log

> Append one line per session: date · task(s) · outcome. Keep it terse.

| Date | Task(s) | Outcome |
|---|---|---|
| 2026-08-10 | — | Docs phase complete: design SoT, roadmap, UI/UX spec, session workflow. Code not started. |
| 2026-08-10 | A1 | done — `shared/go/logger` implemented TDD, 7 tests green, merged to `shared/dev`. Note: `go.work` lists nonexistent `services/*` modules + stale go directive; run shared/go tooling with `GOWORK=off` until A7 fixes it. |
| 2026-08-10 | A2 | done — `shared/go/config` fail-fast env loader, 8 tests green, merged to `shared/dev`. |
| 2026-08-10 | A3 | done — `shared/go/apperror` Transient/Permanent taxonomy, 7 tests green, merged to `shared/dev`. A4 must decide default routing for unclassified errors. |
| 2026-08-11 | infra | Deployed compose infra + observability (postgres, pgbouncer, rabbitmq, minio, loki, prometheus, grafana, exporters); Loki docker plugin installed. Two issues found and since fixed (2026-08-12): `php-fpm-exporter` depended on `api`, which made `--profile observability` invalid on its own; and the Prometheus `minio` target returned 403 without `MINIO_PROMETHEUS_AUTH_TYPE=public`. |
| 2026-08-11 | A4 | done — `shared/go/rmq` publisher/consumer, 7 unit + 4 integration tests green vs compose RabbitMQ, merged to `shared/dev`. M0 smoke pub/sub requirement covered by roundtrip integration test. |
| 2026-08-11 | A5 | done — `shared/go/db` sqlx+pgx pool, 6 unit + 5 integration tests green vs compose pgbouncer, merged to `shared/dev`. Included `fix(infra)`: postgres conf lacked `listen_addresses='*'`, pgbouncer upstream was down. |
| 2026-08-11 | A6 | done — `shared/go/storage` minio client, 7 unit + 5 integration tests green vs compose minio, merged to `shared/dev`. |
| 2026-08-12 | infra fixes | All four compose profiles now validate (`observability` was invalid on its own) and every infrastructure Prometheus target is up: postgres, pgbouncer, rabbitmq, minio. Remaining `down` targets are services not yet running (api, traefik, the four workers); the Go workers additionally have no `/metrics` until G1. Open for G3: MinIO metrics are public on the same port the API listens on. |
| 2026-08-12 | REST vs GraphQL | Evaluated before B3 and rejected; recorded as **D-91** in decisions-v3 §7 with revisit conditions. Fixed the real gap it exposed (`?include=` had no declared values) plus two doc inconsistencies (D-46 endpoint spelling, B11 paths-vs-operations criterion). |
| 2026-08-12 | B3 | done — 22 models + 22 factories + 3 seeders, 48 tests green (395 assertions), pint + phpstan(6) clean, `make seed` idempotent against compose Postgres (5 users / 4 languages / 4 members unchanged on re-run). Added 11 backed enums from schema §10 — B5 should resolve roles through `OrganizationRole`/`SectionRole` rather than strings. Prototype's `CodeExecutionResult.updated_at` dropped (not in final schema). |
| 2026-08-12 | B2 | done — 20 migrations (27 domain tables) written from `database-schema.md`, 15 schema-conformance tests + 4 health tests green, `make migrate` clean on compose Postgres via PgBouncer (36 tables, both CHECKs, both partial unique indexes). **Open question for the human**: the doc's `chk_analysis_scope` is OR ("at least one scope identifier") while the prototype used XOR; scope-resolution pseudocode and `problems.manual_match_group_id` semantics both suggest XOR is intended. Implemented OR per SoT hierarchy — if XOR is wanted, amend `database-schema.md` §5.2 first, then the migration. |
| 2026-08-12 | B1 | done — Laravel 13.25 skeleton in `services/api`, 6 tests + pint + phpstan(6) green, `/api/v1/health` 200 through nginx+FPM+PgBouncer in a real container. Two infra bugs fixed on the way: PgBouncer listened on 5432 while everything dialled 6432 (`dd31cef`), and the nginx health location resolved a doubled `public/` path. Note: host PHP 8.3 has no pdo_pgsql/amqp, so `make dev-api` needs those extensions — use `make test-api`/`lint-api` (Docker) meanwhile. |
| 2026-08-12 | A7, M0 gate | done — worker scaffolds + ai-detector skeleton + go.work fixed (`GOWORK=off` no longer needed); Go images 23.8MB; `--profile analysis config` valid. M0 exit PASS: all unit + integration suites green, container-level pub/sub smoke OK. Next: M1/B1 (Laravel skeleton). |
