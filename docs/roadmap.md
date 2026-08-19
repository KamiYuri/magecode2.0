# MageCode 2.0 — Execution Roadmap & Task Breakdown v1.0

> **Version**: 1.0 — 10/08/2026
> **Status**: Execution SoT. Strategy & rationale live in
> `docs/superpowers/plans/2026-08-10-magecode-2.0-upgrade-roadmap.md` (salvage matrix,
> U-1…U-10); UI specs in `docs/ui-ux-design.md`. This document adds milestones and the
> per-task breakdown. Tasks reference decisions (D-xx) and upgrade items (U-x) directly.

## 1. Reading Guide

**Impact** — blast radius if the task is wrong or late:
- **High**: cross-service contract, security/privacy, or data integrity — errors propagate.
- **Med**: single-service feature — errors contained to one service/screen.
- **Low**: local polish — errors cosmetic or easily reverted.

**Priority**:
- **P0**: blocks the milestone's exit criterion — do first, never skip.
- **P1**: required for phase completeness — schedule within the milestone.
- **P2**: deferrable polish — do when P0/P1 of the milestone are green.

**Global Definition of Done** (applies to every task, in addition to its Verify column):
tests green; JSON structured logging only (D-88); no `panic()` for business errors; RabbitMQ
payloads validate against `shared/schemas/*.json`; `pint` + `phpstan` (PHP), `gofmt` + `go vet`
(Go), `eslint` + `vue-tsc` (web) clean; commit per task following the repo commit convention.

## 2. Milestones (roadmap tổng)

| Milestone | Content | Sub-plans | Exit criterion |
|---|---|---|---|
| **M0** Foundations | Shared Go packages, service scaffolds | A | `go test ./...` green; smoke pub/sub vs compose RabbitMQ |
| **M1** API core | Auth, RBAC, entity CRUD, import | B | Authz matrix test green; full CRUD via `/api/v1` on compose Postgres |
| **M2** Submission loop | MinIO upload, CES, Judge0, realtime | C | E2E: submit → per-test-case rows → WebSocket event (docs Phase 2 deliverable) |
| **M3** Analysis pipeline | Orchestration + 3 batch workers | D, E | E2E: trigger → SIM/AID(/VUL) results in DB → instructor WebSocket (docs Phase 3 deliverable) |
| **M4** Instructor UX | Web app: student + staff + analysis UI | F | Playwright suites green incl. two-tier redaction (docs Phase 2–4 UI) |
| **M5** Bank & polish | Bank UI, notifications, exports, a11y | B10, F10, F11 | Bank clone/publish/approve E2E; axe pass (docs Phase 4 deliverable) |
| **M6** Production | Metrics, security, load, deploy docs | G | Load report 500 CCU; security checklist signed; alert drills pass (docs Phase 5) |

Dependencies: M0 → M1 → M2 → M3; M4 starts after M1 (tracks API as it lands); M5 after M4;
M6 last. Within a milestone, tasks are ordered — each task may assume everything above it.

## 3. Plan A — Shared Go Packages & Scaffolds (M0)

| ID | Work | Impact | Prio | Output | Test mechanism |
|---|---|---|---|---|---|
| A1 | `shared/go/logger`: slog JSON handler — rename `time`→`timestamp`, `msg`→`message`, inject `service`, 4 levels, optional `trace_id`/`data`/`error` fields (D-88) | High | P0 | `logger.New(service string)` returning `*slog.Logger` | Unit: capture output, assert exact JSON keys/values for each level; golden-file for field renames |
| A2 | `shared/go/config`: env loader with required-key validation, typed getters (string/int/bool/duration), fail-fast on missing | High | P0 | `config.Load(spec)` + per-service config structs | Unit: table-driven — missing key errors, type coercion, defaults |
| A3 | `shared/go/apperror`: error taxonomy `Transient` vs `Permanent` + `Wrap`/`Is` helpers; drives retry-vs-DLX routing (D-79e) | Med | P0 | `apperror` package API | Unit: wrap chains preserve category through `errors.Is/As` |
| A4 | `shared/go/rmq`: connect with reconnect backoff, durable declare + DLX per queue (3 retries, D-79e), publish persistent, consume with manual ack + configurable prefetch (D-76), graceful shutdown (D-73) | High | P0 | `rmq.Publisher`, `rmq.Consumer` interfaces + impl | Unit: interface mocks for handler logic. Integration (compose rabbitmq): publish→consume roundtrip; nack→DLX after 3 retries; SIGTERM drains in-flight message |
| A5 | `shared/go/db`: sqlx+pgx pool, `simple_protocol` DSN enforcement (D-89), ping healthcheck helper | Med | P0 | `db.Connect(cfg)` returning `*sqlx.DB` | Integration (compose pgbouncer): connect through port 6432, run prepared-style query proving simple protocol works |
| A6 | `shared/go/storage`: MinIO client — get/put object, pre-signed GET (TTL param), path builder `submissions/{problem}/{submission}/{file}` (D-42/77) | Med | P0 | `storage.Client` API | Integration (compose minio): put→presign→HTTP GET without credentials succeeds; expired URL returns 403 |
| A7 | Move worker modules to `services/{code-executor,plagiarism-checker,vuln-scanner}/`, port `services/ai-detector` skeleton, fix `go.work`, port Dockerfiles (multi-stage Alpine, D-79a) | Med | P0 | Building scaffolds wired to shared packages | `go build ./...` all modules; `docker build` each image < 30MB for Go; compose `--profile analysis config` validates |

## 4. Plan B — API Core (M1)

| ID | Work | Impact | Prio | Output | Test mechanism |
|---|---|---|---|---|---|
| B1 | `services/api` Laravel 13 skeleton (U-1): composer deps (sanctum, php-amqplib — `bschmitt/laravel-amqp` was dropped in C3, reverb), pint+phpstan config, PgBouncer PDO settings (`ATTR_EMULATE_PREPARES`, D-89), Docker api+reverb targets (salvage), Postgres-only test setup (U-6) | High | P0 | Bootable app: `make dev-api` serves `/api/v1/health` | `php artisan test` runs against compose Postgres; healthcheck returns 200; phpstan level set and green |
| B2 | Port 24 migrations from prototype; fix duplicate timestamp pair; verify column-by-column against `database-schema.md` (partial unique indexes, `chk_analysis_scope`, FK policies) | High | P0 | Migration set = schema doc | `migrate:fresh` clean; schema assertion test dumps information_schema and compares FK on-delete rules + indexes to expected list |
| B3 | Port 22 models + factories; rewrite seeders (programming_languages seed from schema doc §3.2; demo org/course/section) | High | P0 | Models with relations + working factories | Unit: relation smoke tests per model via factories; `db:seed` idempotent |
| B4 | Auth per openapi: register, login (bearer token), logout, email verify, forgot/reset password, first-time setup | High | P0 | `/api/v1/auth/*` endpoints | Feature tests per endpoint: happy path + invalid credentials + token revocation on logout |
| B5 | RBAC core (U-2/U-5): membership resolution service, Policies per model, section-isolation scoping (D-04), System Admin bootstrap | **High — security** | P0 | Policy layer + route middleware | **Authz matrix test**: parameterized role × endpoint × expected status (200/403/404); explicit cases: instructor L01 → L02 resources = 403/404; TA cannot trigger analysis; student sees only own submissions |
| B6 | CRUD Organization/Course/Semester/Section incl. semester policy fields (D-16) + org member management | Med | P0 | `/api/v1` CRUD endpoints | Feature tests: CRUD + validation errors envelope + unique constraints (course code per org) |
| B7 | Section roster: add/remove members, CSV/Excel import with row-level error report, D-51 duplicate-enrollment validation, transfer + `section_transfer_logs` (D-50) | Med | P1 | Import + transfer endpoints | Feature tests: fixture files — valid, duplicate student, malformed row; assert partial-success report shape and log rows |
| B8 | Problems + test cases: CRUD, publish/lock/reorder, effective-mode logic (semester policy + override), edit logs observer (D-40), `testcases_updated_at` + `is_outdated` flagging (D-41), limits (D-45) | High | P0 | Problem lifecycle endpoints | Feature tests: visibility matrix (mode × override × time); test-case edit flips `is_outdated` on existing submissions; 51st test case rejected |
| B9 | Tags CRUD + pivots (course-scoped, D-15) | Low | P2 | Tag endpoints | Feature tests: scope uniqueness, attach/detach |
| B10 | Problem bank: CRUD, versioning `original_id`/`version` (D-63/64), latest-approved query, clone deep-copy (D-65), publish new version (D-66), approval flow + notification (D-25/70) | Med | P1 | Bank endpoints incl. `/approve`, `/versions` | Feature tests: version chain integrity; clone copies test cases/languages/tags and detaches; pending entries hidden from non-authors until approved |
| B11 | Route conformance: `/api/v1` prefix, envelope `{data, meta}`, cursor pagination, `?include=` (values enumerated per operation), rate limit 10/min on submissions (U-3) | Med | P0 | Route table = openapi.yml | Contract test: compare `route:list` to openapi.yml at **(method, path)** level — 90 operations across 62 paths, not 62 routes; pagination + envelope feature tests |
| B12 | Profile (update, password, avatar upload to MinIO), notifications list/mark-read; avatars are **streamed by api** (`GET /users/{id}/avatar`, `GET /organizations/{id}/avatar`) rather than pre-signed, per v3 §7 | Low | P2 | Profile + notification endpoints | Feature tests: avatar mime/size validation; unread → read transitions; a stream request returns the bytes and 404s when unset |

## 5. Plan C — Submission Loop & CES (M2)

| ID | Work | Impact | Prio | Output | Test mechanism |
|---|---|---|---|---|---|
| C1 | MinIO integration in api (U-4): flysystem-s3 disk, upload service with 50KB limit (D-34) + extension allowlist from `programming_languages`, deterministic paths (D-42/77), pre-signed URL generator (TTL 6h) | High | P0 | `SubmissionStorageService` | Unit: path scheme, size/extension rejection. Integration: upload → object exists in compose MinIO; presign → anonymous GET ok |
| C2 | Submission endpoints: editor JSON + multipart upload; validations — deadline/lock, allowed language, quota with `SELECT ... FOR UPDATE` (D-36/39) | High | P0 | `POST /problems/{id}/submissions[/upload]` | Feature tests: each rejection reason; **concurrency test**: two parallel submits at quota-1 → exactly one accepted |
| C3 | AMQP publisher in api (php-amqplib): default exchange with the queue name as routing key and the `declareTopology` trio, byte-identical to `shared/go/rmq`; persistent + confirms, publish `job.code-executor` (ID-only, D-84) with a `trace_id` header | High | P0 | `AmqpPublisher` + job DTOs | Unit: generated payload validates against `job.code-executor.v1.schema.json` (justinrainbow/json-schema); publish called on submission create (fake); integration: message round-trips off `code-executor` and the Go topology redeclares without `PRECONDITION_FAILED` |
| C4 | CES consumer loop on shared packages: prefetch 5 (D-75/76), DLX retry via apperror taxonomy, SIGTERM drain | High | P0 | Running `services/code-executor` daemon | Integration: malformed message → DLX; transient error → redelivered ≤3; SIGTERM mid-job completes then exits 0 |
| C5 | CES repository: load submission + active test cases + judge0_id; **upsert** `code_execution_results` on `(submission_id, test_case_id)`; recount then update `submissions` counters/status | High | P0 | `internal/repository` | Integration vs Postgres: re-processing same submission produces no duplicate rows; counters correct after partial crash simulation |
| C6 | CES Judge0 client: `POST /submissions?wait=true` per test case with limits + `X-Auth-Token`; status mapping incl. `timeout` (D-37); `error_content` truncation 5000 (D-38); `language_not_supported` path | High | P0 | `internal/judge0` client | Unit: `httptest` fixtures for every Judge0 status → expected mapping; truncation boundary. Integration (judge0 compose): real C/Python accepted + TLE cases |
| C7 | Result signalling: CES publishes `result.execution` after recount; api consumer command (`artisan amqp:consume-execution`) → Reverb `execution.updated`/`execution.completed` on `private-submission.{id}`; channel auth by ownership only, per openapi (U-8, v3 §7) | High | P0 | End-to-end realtime path | Unit: payload vs `result.execution.v1.schema.json`. Feature: fake consumer input → broadcast assertions (`Event::fake`); channel auth matrix (owner ok, other student 403) |
| C8 | E2E happy path automation: compose up (+judge0), seed, submit via API, poll DB, assert WebSocket frame | High | P0 | `scripts/e2e-submission.sh` (or Pest E2E group) | Scripted E2E in CI-able form: exit 0 = M2 exit criterion met; run matrix: accepted, wrong_answer, TLE, compile error |

## 6. Plan D — Analysis Orchestration (M3, api side)

| ID | Work | Impact | Prio | Output | Test mechanism |
|---|---|---|---|---|---|
| D1 | Trigger endpoint: scope resolution auto/manual (D-47/58, XOR), latest-per-student selection (D-49), `is_latest` swap in transaction (D-53), `is_partial` when unlocked (D-57), existing-completed-results response branch | High | P0 | `POST /problems/{id}/analysis` | Feature tests: scope across 2+ sections; re-trigger flips `is_latest` exactly once under parallel calls (unique index race test); partial flag; "results exist" response; a problem with no scope yet gets a `manual_match_group_id` generated on trigger and the second trigger reuses that same UUID |
| D2 | SIM job building: group by `dolos_language`, skip groups <2 (`not_applicable`), presigned URLs, `language_group_index/total`, publish per group | High | P0 | SIM publisher path | Unit: grouping edge cases (0/1/2 groups, mixed null dolos_language); payload validates vs `job.plagiarism-checker.v1.schema.json` |
| D3 | AID/VUL job publishing: per analysis_submission; VUL gated on `codeql_language` null → `not_applicable` upstream | Med | P0 | AID/VUL publisher paths | Unit: schema validation; null-gate sets status without publishing (fake AMQP) |
| D4 | `result-analysis` consumer — SIM handler: write ordered pairs, assign `match_type` by section membership (D-61), update per-submission statuses, completion **set** of received `language_group_index` values in the cache table (U-9, refined in v3 §7 2026-08-18: a counter double-counts a redelivery) | High | P0 | SIM ingestion | Feature: synthetic SIM result message → rows with correct match_type for same/cross section fixtures; the set fills to `language_group_total`; duplicate delivery idempotent for both rows and completion |
| D5 | AID + VUL handlers: probability row (unique per analysis_submission), findings rows, status transitions incl. `not_applicable`/`error` | Med | P0 | AID/VUL ingestion | Feature: synthetic messages per status; unique-constraint upsert on repeated AID delivery |
| D6 | Batch completion: per-submission `completed_at` when all enabled services done → batch `completed` → Reverb `analysis.progress`/`analysis.completed` on `private-section.{id}` (U-8) | Med | P0 | Completion + realtime | Feature: staged synthetic results drive progress events (Event::fake asserts counts); channel auth staff-only |
| D7 | Timeout scheduler (D-82): every 5min mark `processing` >30min as `timeout`, warn-log at 15min; graceful degradation preserved (D-59) | Med | P1 | Scheduled command | Feature with time travel (`travel()`): 29min untouched, 31min → timeout; completed submissions keep results |
| D8 | Analysis read APIs: batch detail, submissions, similarity list/detail with **two-tier field filtering** (D-05/06 — cross-section hides other code), ai-detection, vulnerabilities, history, cancel, match-groups CRUD (D-58), semester analysis-overview | **High — privacy** | P0 | Read endpoints per openapi | Feature: per-role response snapshots — instructor cross-section payload MUST lack other submission's code/regions; org admin sees full; threshold flags computed from semester settings (D-62) |

## 7. Plan E — Batch Workers (M3, worker side)

| ID | Work | Impact | Prio | Output | Test mechanism |
|---|---|---|---|---|---|
| E1 | SIM service skeleton: consumer prefetch 1 (D-76), download all files to temp workspace, cleanup on exit | Med | P0 | Running SIM daemon | Integration: fixture job → files on disk named by submission_id; workspace removed after ack |
| E2 | Dolos wrapper: bundled Node reporter over `@dodona/dolos-lib` per language (the CLI's CSV export carries no fragment coordinates — corrected 2026-08-19, v3 §7), JSON output parsing → ordered pairs + regions pipe-format, `DOLOS_TIMEOUT` enforcement | High | P0 | `internal/dolos` + `reporter/` | Unit: golden reporter output fixtures → parsed pairs (ordering a<b, region strings); timeout kills the process group; malformed output → Permanent error. Integration (tagged): real library over renamed-copy fixtures |
| E3 | SIM result assembly + publish: pairs + `submission_statuses`, echo group index/total, error paths (partial per-submission errors) | High | P0 | SIM → `result-analysis` | Unit: payload validates vs `result.analysis.v1.schema.json` (SIM variant); error-path payload for unreadable file |
| E4 | AID service: pika consumer prefetch 3, structlog JSON, downloader, graceful shutdown | Med | P0 | Running AID daemon | Integration: fixture job consumed, ack after publish; SIGTERM drains |
| E5 | AID model: CodeBERT (`microsoft/codebert-base-mlm` — the plain `codebert-base` has no trained head at all, corrected 2026-08-19, v3 §7) load from cache volume, inference → probability via a pluggable scorer, `not_applicable` for unsupported language, batch size env | High | P0 | `src/model.py` | Unit: tokenizer/head mocked — deterministic tensor → probability mapping, language gate, calibration maths. Integration (slow, tagged): real model on 2 fixtures returns 0..1, deterministically, and a checkpoint with an untrained head is refused |
| E6 | AID result publish + error convention (3 retries transient then `status: error`, §2.5 rabbitmq-schemas) | Med | P0 | AID → `result-analysis` | Unit: schema validation; retry counter test with fake downloader failing 2x then ok |
| E7 | VUL service: CodeQL wrapper (db create + analyze per language, SARIF parse → findings, severity map), tmpfs workspace, `VUL_CODEQL_TIMEOUT` | Med | P1 | Running VUL daemon | Unit: SARIF fixtures → findings incl. null locations; severity mapping table. Integration (tagged): real CodeQL on vulnerable Python fixture finds `py/sql-injection` |
| E8 | Worker images + compose `analysis` profile wiring, healthchecks (D-72), Loki logging labels, model/codeql cache volumes | Med | P1 | Deployable worker images | `docker compose --profile analysis up`: all healthy; logs visible in Loki with `service` label |
| E9 | Pipeline E2E: seed multi-section data → trigger via API → assert similarity/ai(/vuln) rows + statuses + events | High | P0 | `scripts/e2e-analysis.sh` | Scripted E2E = M3 exit criterion: cross-section pair exists with `CROSS_SECTION`, every language group received, batch `completed` |

## 8. Plan F — Web Frontend (M4–M5)

| ID | Work | Impact | Prio | Output | Test mechanism |
|---|---|---|---|---|---|
| F1 | Bootstrap per `ui-ux-design.md` §2: Tailwind tokens, Be Vietnam Pro + JetBrains Mono, dark mode, shadcn-vue base components | Med | P0 | Themed app shell buildable | `npm run build` + `vue-tsc` clean; visual review vs spec tokens (light/dark) |
| F2 | API layer: axios client, auth store (token, refresh-on-401 → login), `{data, meta}` + error envelope mapping, cursor pagination composable | High | P0 | `src/api/*`, `stores/auth` | Unit (vitest + msw): envelope parsing, 401 flow, field-error mapping |
| F3 | Auth screens: login, register, first-time setup, forgot/reset (vi copy per spec §8) | Med | P0 | Auth flow pages | Component tests: validation states; Playwright: login → dashboard redirect by role |
| F4 | App shell: sidebar per memberships, role dashboards (student 1-click, staff 2-click, D-09), notifications dropdown | Med | P0 | Navigable shell | Playwright: role fixtures see correct nav items; deep-link guard redirects |
| F5 | Verdict components: `VerdictStrip` (aria labels, glyph+color), submission status chips, Echo plugin + store wiring per spec §5 | High | P0 | Reusable verdict kit | Component tests: cell states from store mutations; mocked Echo event updates strip without refetch; reduced-motion snapshot |
| F6 | Student flow: dashboard groups, problem detail (statement+KaTeX, samples, Monaco, quota, deadline states), submit both modes, history with `is_outdated` badge, realtime updates | High | P0 | Complete student journey | Playwright E2E vs real stack: submit → strip fills live; quota exhausted disables; locked problem read-only |
| F7 | Staff section workspace: problems tab (create/clone/reorder), members tab (import wizard with row errors, transfer), submissions tab (filters, all/best, export download) | Med | P1 | Staff workspace | Playwright: import fixture CSV → error report rendered; best-mode shows one row per student |
| F8 | Analysis UI: trigger dialog (defaults D-24, scope preview, partial warning, rerun-vs-view), progress cards via events, results tabs incl. threshold flags | High | P1 | Analysis screens | Playwright with synthetic results: progress counts advance; flags appear at threshold boundary (parameterized fixture) |
| F9 | Similarity pair viewer: linked-scroll Monaco panes, region highlights, **cross-section redaction panel + escalation** (spec §4.5) | **High — privacy** | P0 | Two-tier viewer | Playwright per role: instructor cross-section sees redaction (and network response contains no code — asserted); org admin sees both panes |
| F10 | Bank UI (browse/filter/preview/clone/publish/approve/versions) + admin screens (semester policy form, match-groups picker, analysis-overview, `/admin`) | Med | P1 | Bank + admin screens | Playwright: clone from bank → problem appears in section; approval flow hides pending from other instructors |
| F11 | A11y + responsive pass (spec §9): axe clean, keyboard paths, Monaco Esc hatch, ≤768 read-only fallback | Med | P1 | Accessibility conformance | Automated axe on all routes; Playwright keyboard-only submit; viewport tests at 768/375 |

## 9. Plan G — Production Readiness (M6)

| ID | Work | Impact | Prio | Output | Test mechanism |
|---|---|---|---|---|---|
| G1 | Metrics (U-10): promhttp in 3 Go services, prometheus-client in AID, php-fpm exporter target confirm; jobs_processed/duration/errors counters | Med | P1 | `/metrics` on all services | Curl each endpoint; Prometheus targets page all UP; counters increment in E2E run |
| G2 | Grafana dashboards + alert rules verification: service down, queue backlog, PgBouncer wait, analysis timeout, disk (D-87) | Low | P2 | Working dashboards/alerts | Staged drills: stop a worker / flood a queue → alert fires within rule window |
| G3 | Security pass: full authz matrix re-run vs final routes, upload hardening review, security headers, secret scan, dependency audit (`composer audit`, `npm audit`, `govulncheck`) | **High — security** | P0 | Signed security checklist in `docs/` | Automated: authz matrix suite + audits in CI; manual: checklist review of D-79 network isolation + Judge0 hardening (D-90) |
| G4 | Load test: k6 scenario — 500 students submitting within 5min window; measure queue depth, PgBouncer waits, p95 latency | High | P1 | Load report committed to `docs/` | k6 thresholds: p95 submit < 2s, zero 5xx, queue drains < 10min; report includes tuning actions taken |
| G5 | Ops docs: deployment guide, backup/restore drill (`scripts/backup.sh` verified restore), runbook for common failures | Med | P2 | `docs/deployment.md`, runbook | Tabletop: restore latest backup into clean compose; runbook steps executed once for real |
| G6 | CI (optional per docs Phase 5): GitHub Actions path-filtered per service — lint + tests + image build | Med | P2 | `.github/workflows/*` | PR dry-run: only touched service's jobs execute; all suites green |

## 10. Cross-cutting Rules

- **Task = commit unit**: each task lands as one commit (or a small series) referencing its ID,
  e.g. `feat(api): add submission quota locking [C2]`.
- **Schema conformance is non-negotiable**: any payload change starts in `shared/schemas/` +
  `docs/rabbitmq-schemas.md`, then code (both sides), never the reverse.
- **Privacy-tagged tasks (B5, D8, F9, G3)** require test evidence in the PR description —
  they gate their milestone regardless of other progress.
- **Slow integration suites** (Judge0, CodeBERT, CodeQL) run under an explicit tag/group so
  the default test loop stays fast; E2E scripts (C8, E9) are the milestone gates.

---

*— End of Execution Roadmap v1.0 —*
