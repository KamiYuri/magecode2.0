# MageCode 2.0 — Progress Board

> **Live state document** — update at the start (`wip` claim) and end (result) of every
> session per `docs/session-guide.md`. Task definitions live in `docs/roadmap.md`; do not
> duplicate them here.
>
> **Status values**: `todo` · `wip` · `review` · `done` · `blocked` (+ one-line reason in Notes)

**Current milestone: M2** — exit: E2E submit → per-test-case rows → WebSocket event. (M0 passed
2026-08-12, M1 passed 2026-08-13; see gate table. M1's P2 leftovers — B9 tags, B12 profile +
notifications — and B10, which M5 owns, stay open and can land alongside M2.)

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
| B2 migrations | P0 | done | `c15faea` | Spatie tables dropped (U-2); `chk_analysis_scope` is **XOR** per v3 §7 (2026-08-14) — exactly one scope identifier, enforced by `num_nonnulls(...) = 1` |
| B3 models + seeders | P0 | done | `512e2c3` | 11 backed enums added from schema §10; factories encode CHECK invariants; models carry `@property` for phpstan |
| B4 auth | P0 | done | `5005c64` | email verify is unauthenticated (signed link + hash check); reset revokes all tokens, logout only the current one |
| B5 rbac + policies | P0 | done | `96c6f13` | 66-case matrix green; System Admin = `users.is_system_admin` (artisan only), TA excluded from analysis. Matrix is ability-level — B6+ must attach policies to controllers, G3 re-runs it over HTTP |
| B6 org/course/semester/section crud | P0 | done | `59cada5` | 21 endpoints; `{data, meta}` envelope via `CursorPage`, 409 + code via `ConflictException` — reuse both in B7–B12. Avatar endpoints belong to B12 (C1 landed the storage layer only) |
| B7 roster import + transfer | P1 | done | `82d0fbd` | D-51 lives in `EnrollmentGuard` — every path that enrolls or promotes must go through it. Import is partial-success; adds `openspout/openspout` for CSV + XLSX |
| B8 problems + test cases lifecycle | P0 | done | `ed83290` | Visibility resolved in `ProblemVisibilityService` + `Problem::visibleIn()` — keep the two in step. Clone-from-bank stays with B10, match-groups with Plan D |
| B9 tags | P2 | todo | — | — |
| B10 problem bank | P1 | todo | — | — |
| B11 route conformance /api/v1 | P0 | done | `826c78d` | 39/88 operations routed; the rest sit in `RouteConformanceTest::PENDING` by task — strike your entries as you add routes |
| B12 profile + notifications | P2 | todo | — | Avatars stream through api (`GET /users/{}/avatar`, `GET /organizations/{}/avatar`) — both already in `RouteConformanceTest::PENDING`; no public MinIO endpoint (v3 §7) |

## M2 — Plan C: Submission Loop & CES

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| C1 minio storage service | P0 | done | `a71e86b` | `SubmissionStorageService` is the only writer of submission objects — keep its key byte-identical to `shared/go/storage.SubmissionPath`. Allowlist reads `programming_languages.file_extensions` (new column). Pre-signed URLs are internal-endpoint only |
| C2 submission endpoints + quota | P0 | done | `api/feat/submissions` | 5 endpoints (50 of 90 routed). `SubmissionService` is the only writer — quota + in-flight gate run under `pg_advisory_xact_lock(problem_id, creator_id)`; C3 hangs its publish off the same service. `TrimStrings` now excludes `source_code` |
| C3 amqp publisher | P0 | done | `api/feat/amqp-publisher` | Default exchange, routing key = queue name — the roadmap's `magecode` exchange would have dropped every job. `AmqpPublisher::declareTopology` is a transcription of `shared/go/rmq/client.go`; an integration test redeclares with the Go arguments so drift fails here, not in C4 |
| C4 ces consumer loop | P0 | done | `ces/feat/consumer-loop` | Concurrency lives in `shared/go/rmq` (`Config.Concurrency`, capped by prefetch) — settling is mutex-guarded because a multi-frame publish is not concurrency-safe on one channel. `job.Decode` is strict; C5/C6 **must classify their errors** or a bare one is retried 3× |
| C5 ces repository + upsert | P0 | todo | — | — |
| C6 ces judge0 client | P0 | todo | — | — |
| C7 result signalling + reverb | P0 | todo | — | — |
| C8 e2e submission script | P0 | todo | — | milestone gate M2 |

## M3 — Plan D: Analysis Orchestration (api)

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| D1 trigger + scope + is_latest | P0 | todo | — | Scope is XOR; a problem with neither identifier gets a one-problem `manual_match_group_id` generated in the trigger transaction (v3 §7) |
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
| M1 exit (authz matrix) | 2026-08-13 | PASS | 401 tests green (1383 assertions) against compose Postgres, incl. the 69-case authz matrix and the route/envelope contract tests; pint + phpstan(6) clean. Container smoke through nginx + FPM + PgBouncer (`magecode-api`, healthy): register → login → organization → org member → course (duplicate code → 409 `DUPLICATE_COURSE_CODE`) → semester → section → enrol student → problem → test cases → publish → list problems → roster, all 200/201, list carrying `{data, meta}`, and an unauthenticated request 401. Two bugs the in-process suite could not see were fixed on the way: the compose api service built the test stage, and guests without an `Accept` header got 500. |
| M2 exit (C8) | — | — | — |
| M3 exit (E9) | — | — | — |
| M4 exit (Playwright suites) | — | — | — |
| M5 exit (bank e2e + axe) | — | — | — |
| M6 exit (load + security) | — | — | — |

## Session Log

> Append one line per session: date · task(s) · outcome. Keep it terse.

| Date | Task(s) | Outcome |
|---|---|---|
| 2026-08-14 | C4 | done — CES consumer loop; `go test -race ./...` green in both modules, all 6 rmq integration tests and all 6 CES integration tests green vs compose RabbitMQ under `-race`, `go vet` clean in all four Go modules. **D-75 was configured but unimplemented**: compose has passed `WORKER_COUNT` since A7 and nothing read it, because A4's consumer handles deliveries sequentially — so CES ran one job at a time whatever prefetch said. Concurrency now lives in `shared/go/rmq` as `Config.Concurrency` (default 1, capped to `PrefetchCount`, since a worker the broker will never feed is only a goroutine), which is what D-76's "CES: 5 (matches workers)" describes: one channel, one prefetch window, N handlers draining it. Doing it per service would have meant writing it three more times for SIM/AID/VUL. **The subtle part is the settle, not the pool**: `amqp091-go` takes the connection send lock *per frame* and a publish is several frames, so two goroutines republishing to `.retry`/`.dlq` on one channel would interleave frames and corrupt the stream. Settling is therefore serialised by a mutex while the handler — the slow part — runs outside it. Drain is re-proven rather than assumed: a new test starts three handlers, cancels, and asserts all three finish and the queue drains to zero. **`internal/job.Decode` is strict** (`DisallowUnknownFields`), and every rejection is Permanent: a body CES cannot read reads no better on the third attempt, so it dead-letters immediately instead of occupying a worker three more times. **For C5/C6**: a bare error is treated as transient and retried three times, so the repository and Judge0 client must classify their own failures — a missing submission is Permanent, a broker or Judge0 outage is Transient. Also fixed a doc gap: `.agents/workflows/branch.md` listed no prefixes for the four worker services, so this task had no legal branch name; `ces/`, `sim/`, `aid/`, `vul/` added. |
| 2026-08-14 | C3 | done — 487 tests green (1619 assertions) with the stack up / 478 + 9 skipped without it, pint + phpstan(6) clean. **The roadmap was wrong in a way that would have cost C4 a session**: it called for an `exchange magecode` of type direct, but that string is the only mention of an exchange anywhere in the repo, and `shared/go/rmq` — shipped by A4, smoke-tested by the M0 gate — publishes over the **default exchange** with the queue name as routing key and declares no exchange at all. Publishing into a named exchange with no binding drops every message silently. Both halves corrected in roadmap + v3 §7. `bschmitt/laravel-amqp` went with it: `Publisher::setup()` unconditionally calls `declareExchange()` then `declareAndBind()` and AMQP forbids binding to the default exchange, while its default `queue_properties` carry `x-max-length => 1` — which would either collide with the Go worker's argument-free declare or cap the queue at one message. Replaced by `php-amqplib` (already its own dependency). **`AmqpPublisher::declareTopology` is a line-by-line transcription of `shared/go/rmq/client.go`** and must stay one: RabbitMQ answers a mismatched redeclare with PRECONDITION_FAILED, so whichever process declares second dies. That is pinned by an integration test which redeclares using the Go arguments, plus a negative case proving this broker really does refuse mismatched arguments (using bschmitt's own defaults as the wrong ones) — otherwise the positive test would be vacuous. Publishing is persistent + confirms + `mandatory`, so an unroutable message raises instead of vanishing. **Publish happens after the commit**, never inside it, and a broker refusal is swallowed: the submission is already durable, so it returns 201 and logs ERROR with `submission_id`/`trace_id` (v3 §7) — the row waits at `in_queue` and re-publishing belongs to C7/D7. Also fixed: `docker-compose.yml` set `QUEUE_CONNECTION: rabbitmq` for the api, a driver `config/queue.php` does not define, so any `dispatch()` would have thrown. **Open for G1**: `config/logging.php` is still stock Laravel with no JSON-to-stdout channel, which D-88 requires — the ERROR above currently lands in the default format. **For C4**: the message carries `submission_id` only (D-84); the `trace_id` header is spelled `trace_id`, matching `headerTraceID`. |
| 2026-08-14 | C2 | done — 5 submission endpoints (50 of 90 operations routed), 473 tests green (1585 assertions) with the stack up / 468 + 5 skipped without it, pint + phpstan(6) clean. **`SubmissionService` is the only writer of submissions** and the place C3 attaches its publish. The write is ordered around one constraint: `file_path` is NOT NULL and the object key embeds the submission id, so the id is taken from the sequence, the object is written, then the row is inserted — all inside the transaction, and the object is deleted again if anything downstream throws (this is the case C1 left `delete()` for). That puts a MinIO round-trip inside the transaction, which is why the quota lock is `pg_advisory_xact_lock(problem_id, creator_id)` and not a row lock: a row lock over the student's own submissions holds nothing before their first one — exactly the `max_submissions = 1` race — and locking the `problems` row would queue a whole class behind one storage call at the deadline. Both cases are covered by **forked processes**, not a simulated interleaving, which forced `DatabaseTruncation` + an explicit `tearDown` truncate (committed rows would otherwise survive into the RefreshDatabase suites). **Bug found on the way**: Laravel's `TrimStrings` was eating the trailing newline of every editor submission, so the stored bytes differed from what the student wrote — `source_code` is now excluded in `bootstrap/app.php`, and it matters beyond tidiness because SIM compares those bytes. Two decisions taken with the human and recorded (v3 §7): **`SUBMISSION_PROCESSING` defined** (one unfinished submission per student per problem — the code existed in openapi with nothing to raise it; `ui-ux-design.md` §4.2 gained the third disable reason) and **the advisory lock over the literal `FOR UPDATE`**. Reads: a student's listing is silently narrowed to their own rows rather than refused, so `?student_id=` cannot widen it; `best`/`latest` resolve through a `DISTINCT ON` subquery so the outer query stays cursor-paginable; hidden test cases show a student order + verdict but never input or expected output. **For C3**: publish from `SubmissionService` after the transaction commits, never inside it — a message naming a rolled-back id is a job that can never succeed. |
| 2026-08-14 | open decisions | done — four questions left open by B2/B5/C1 closed with the human and recorded as amendments (v3 §7); 432 tests green (1454 assertions) / 437 with the stack up, pint + phpstan(6) clean. **`chk_analysis_scope` is now XOR** (`num_nonnulls(...) = 1`): the doc's OR let one row name two different problem sets, since the §5.2 scope query branches on each identifier with no precedence rule. `SchemaConformanceTest` only asserted the constraint's *name*, so three semantic cases were added (both / neither / either alone) — the "both" case was red before the migration changed, which is the whole evidence that OR was permissive. **A problem with no scope** (no bank entry, no match group) can never satisfy either branch, so D1 generates a UUID into `problems.manual_match_group_id` inside the trigger transaction — a one-problem group; `POST /semesters/{}/match-groups` widens it later. **D-13's "unless Instructor permits"** is dropped: no column, no endpoint, and B5's matrix already shipped the absolute denial; decisions-v1 §4.1 carries a superseded marker rather than being rewritten. **Browser-reachable objects are streamed by api** — presign stays internal-only and no bucket is published, because every such object is small (avatar; source ≤50KB), openapi already commits api to streaming two exports, and a pre-signed URL cannot be withdrawn inside its 6h TTL. That adds `GET /users/{}/avatar` + `GET /organizations/{}/avatar` to the contract (**88 → 90 operations, 61 → 62 paths**; `EXPECTED_OPERATIONS` bumped, both in `PENDING` under B12) and unblocks B12 with no infra change. |
| 2026-08-13 | C1 | done — MinIO storage layer, 435 tests green (1459 assertions) with the stack up / 430 + 5 skipped without it, pint + phpstan(6) clean. `SubmissionStorageService` is the **only writer of submission objects**, and its key must stay byte-identical to `shared/go/storage.SubmissionPath` — the parity vectors (`main.go`, `Giải Thuật.py`) are copied into the unit test from the Go suite, so a change on either side fails loudly. Multipart and JSON `source_code` funnel into one private writer, so the stored object does not depend on how the student submitted; the editor path names the file `main.{first extension}`. Filenames are sanitised (basename, control chars, leading dots, 120-byte cap for the varchar(500) `file_path`) but **not slugged** — a Vietnamese filename must round-trip. Three decisions taken with the human and recorded as amendments (decisions-v3 §7): **`programming_languages.file_extensions`** (new jsonb column — U-4 wanted the allowlist "from `programming_languages`" and no column existed; a list because C++ is .cpp/.cc/.cxx), **openapi's "max 1MB (D-33)" corrected to 50KB (D-34)** (1MB contradicted the enforced decision and D-33 is Observability), and **pre-signed URLs are signed against the internal endpoint only** — SigV4 covers the Host, so they serve SIM/AID/VUL on the backend network (D-80/D-85) and no browser. **For B12/F**: a browser-reachable download (avatars, source download) needs a public MinIO endpoint plus a Traefik route for the S3 API — the four avatar endpoints stay in `RouteConformanceTest::PENDING` under B12. **For C2**: `delete()` exists for the rollback case — an object written before a transaction that then fails is an orphan nobody cleans up. PHP-side infra tests now have a gate: `tests/Support/RequiresMinio` skips on `MINIO_TEST_ENDPOINT`, filled in by the Makefile from an exported repo-root `.env` (`set -a && . ./.env && set +a && make test-api`). |
| 2026-08-13 | B7 | done — 6 roster endpoints (45 of 88 operations now routed), 400 tests green (1381 assertions), pint + phpstan(6) clean. **D-51 is centralised in `EnrollmentGuard`**: four paths can break it — bulk add, import, role change, transfer — and the constraint spans `section_members → sections → semesters`, so no DB check can hold it; anything that enrolls or promotes must ask the guard. Only students are constrained (teaching L01 and L02 is ordinary). Import is partial-success: rows are validated and enrolled one transaction each, bad rows come back as `{row, error}` with spreadsheet line numbers, a D-51 clash is a row error, and an already-enrolled person is skipped rather than failed; a file with no `email` column is rejected outright instead of reporting every row. **New dependency: `openspout/openspout`** (agreed with the human) so CSV and XLSX read identically — the XLSX path is covered by a test that writes its own workbook. Transfers are Org-Admin-only (the move crosses a section boundary), must stay inside the semester, and write the D-50 log in the same transaction as the move. New codes: `DUPLICATE_ENROLLMENT`, `TRANSFER_OUTSIDE_SEMESTER`, `TRANSFER_NOT_A_STUDENT`. **M1 exit is now reachable** — the milestone's content (auth, RBAC, entity CRUD, import) is complete; B9/B10/B12 remain as P1/P2. |
| 2026-08-13 | B11 | done — 369 tests green (1264 assertions), pint + phpstan(6) clean. `tests/Feature/Contract/RouteConformanceTest.php` diffs `route:list` against openapi.yml at (method, path) level with placeholder names normalised away: **an undeclared route fails outright**, while a declared-but-unrouted operation must appear in `PENDING` tagged with its task. `PENDING` is checked from both sides, so it can only shrink and cannot name an operation the contract lacks — **every later task must strike its own entries**. 39 of 88 routed today. `ApiConventionsTest` pins the `{data}` / `{data, meta}` envelope, all four error shapes and the rate-limit table. `throttle:api` (120/min) now guards every authenticated v1 route, and the `submissions` (10), `analysis` (5) and `uploads` (10) limiters are registered ahead of their endpoints — C2/D1/B7/B12 attach a name, not a number; all key on the user, not the IP, so one student in a lab cannot lock out the room. openapi gained `GET /health` (it existed in code but not in the contract), moving the B11 criterion to 88 operations across 61 paths. **M1 exit is not claimed**: the milestone's content includes roster import (B7), still todo. |
| 2026-08-13 | B8 | done — 10 problem/test-case endpoints, 350 tests green (1205 assertions), pint + phpstan(6) clean. The visibility matrix (21 cases over mode × override × time) is the spec everything else reads: the listing filter, `is_visible`/`is_submittable`, `edit_rules` and both PROBLEM_LOCKED sites. **Keep `ProblemVisibilityService::isVisible()` and `Problem::visibleIn()` in step** — the scope is the SQL mirror of the service, and a student seeing a row the flag denies is the bug that pairing prevents. D-40 edit logs come from a `ProblemObserver` that stays silent when nobody is authenticated, so seeders and factories do not fabricate an editor. D-41 flags submissions and notifies each author once (stored notification, no mail). D-45 counts *surviving* test cases, so a swap at exactly 50 passes. Two amendments recorded (decisions-v3 §7): NULL time semantics in auto mode, and "locked" meaning the effective lock everywhere. **For B10**: `POST /sections/{id}/problems/clone` deliberately left out — it is D-65's deep copy. **For Plan D**: `/semesters/{id}/match-groups` likewise. **For B11**: `?include=` machinery lives in `AcceptsIncludes` + `ProblemController::INCLUDE_RELATIONS`; `my_submissions_count`/`my_best_status` come from `Problem::withMyProgress()` and stay untested until submissions exist (C2). |
| 2026-08-13 | B6 | done — 21 endpoints (org/course/semester/section + org members), 268 tests green (953 assertions), pint + phpstan(6) clean. First `{data, meta}` producer: `CursorPage` builds the contract's `CursorMeta` (Laravel's paginated resource response emits `links`/`path` and no `has_more`); `ConflictException` renders the 409 + `code`. **For B7–B12**: reuse both, plus `UserProvisioningService` (roster import needs the same invite-an-unknown-email path) and `tests/Support/CreatesAcademicFixtures`. **For B11**: routes use implicit-binding parameter names (`{organization}`), so the conformance test must normalise placeholders before diffing against openapi's `{organization_id}`; `throttle:api` is still attached to nothing. Deliberately out of scope: org avatar upload/delete (needs C1's MinIO disk), section members (B7), `/me/sections`. Three decisions taken with the human and recorded as amendments: 409-with-code over 422 for duplicates, `end_date >= start_date`, and System Admin widened to manage organizations with the creator auto-enrolled as Org Admin. |
| 2026-08-10 | — | Docs phase complete: design SoT, roadmap, UI/UX spec, session workflow. Code not started. |
| 2026-08-10 | A1 | done — `shared/go/logger` implemented TDD, 7 tests green, merged to `shared/dev`. Note: `go.work` lists nonexistent `services/*` modules + stale go directive; run shared/go tooling with `GOWORK=off` until A7 fixes it. |
| 2026-08-10 | A2 | done — `shared/go/config` fail-fast env loader, 8 tests green, merged to `shared/dev`. |
| 2026-08-10 | A3 | done — `shared/go/apperror` Transient/Permanent taxonomy, 7 tests green, merged to `shared/dev`. A4 must decide default routing for unclassified errors. |
| 2026-08-11 | infra | Deployed compose infra + observability (postgres, pgbouncer, rabbitmq, minio, loki, prometheus, grafana, exporters); Loki docker plugin installed. Two issues found and since fixed (2026-08-12): `php-fpm-exporter` depended on `api`, which made `--profile observability` invalid on its own; and the Prometheus `minio` target returned 403 without `MINIO_PROMETHEUS_AUTH_TYPE=public`. |
| 2026-08-11 | A4 | done — `shared/go/rmq` publisher/consumer, 7 unit + 4 integration tests green vs compose RabbitMQ, merged to `shared/dev`. M0 smoke pub/sub requirement covered by roundtrip integration test. |
| 2026-08-11 | A5 | done — `shared/go/db` sqlx+pgx pool, 6 unit + 5 integration tests green vs compose pgbouncer, merged to `shared/dev`. Included `fix(infra)`: postgres conf lacked `listen_addresses='*'`, pgbouncer upstream was down. |
| 2026-08-11 | A6 | done — `shared/go/storage` minio client, 7 unit + 5 integration tests green vs compose minio, merged to `shared/dev`. |
| 2026-08-12 | B5 | done — MembershipService + 9 policies + 66-case authz matrix, 163 tests green (606 assertions), linters clean, `magecode:make-system-admin` verified on the compose DB. Two SoT conflicts resolved by the human and recorded as amendments: System Admin storage (`users.is_system_admin`) and TA excluded from analysis results. **Carry-over for B6–B10**: the matrix asserts abilities via `Gate`, not HTTP — each CRUD task must call `authorize()`/`can` in its controllers and extend the matrix with its endpoints; G3 re-runs the whole thing against final routes. Deliberately out of scope: the "unless Instructor permits" TA grant (D-13) has no column and no endpoint anywhere, so TA denial is currently absolute. |
| 2026-08-12 | B4 | done — 7 auth endpoints, 86 tests green (522 assertions), pint + phpstan clean. `User` now implements `MustVerifyEmail`. Auth throttle (10/min/IP) registered as the `auth` limiter in `AppServiceProvider`; the global `api` limiter (120/min) is defined there too but not yet attached to routes — B11 wires it. |
| 2026-08-12 | infra fixes | All four compose profiles now validate (`observability` was invalid on its own) and every infrastructure Prometheus target is up: postgres, pgbouncer, rabbitmq, minio. Remaining `down` targets are services not yet running (api, traefik, the four workers); the Go workers additionally have no `/metrics` until G1. Open for G3: MinIO metrics are public on the same port the API listens on. |
| 2026-08-12 | REST vs GraphQL | Evaluated before B3 and rejected; recorded as **D-91** in decisions-v3 §7 with revisit conditions. Fixed the real gap it exposed (`?include=` had no declared values) plus two doc inconsistencies (D-46 endpoint spelling, B11 paths-vs-operations criterion). |
| 2026-08-12 | B3 | done — 22 models + 22 factories + 3 seeders, 48 tests green (395 assertions), pint + phpstan(6) clean, `make seed` idempotent against compose Postgres (5 users / 4 languages / 4 members unchanged on re-run). Added 11 backed enums from schema §10 — B5 should resolve roles through `OrganizationRole`/`SectionRole` rather than strings. Prototype's `CodeExecutionResult.updated_at` dropped (not in final schema). |
| 2026-08-12 | B2 | done — 20 migrations (27 domain tables) written from `database-schema.md`, 15 schema-conformance tests + 4 health tests green, `make migrate` clean on compose Postgres via PgBouncer (36 tables, both CHECKs, both partial unique indexes). **Open question for the human**: the doc's `chk_analysis_scope` is OR ("at least one scope identifier") while the prototype used XOR; scope-resolution pseudocode and `problems.manual_match_group_id` semantics both suggest XOR is intended. Implemented OR per SoT hierarchy — if XOR is wanted, amend `database-schema.md` §5.2 first, then the migration. |
| 2026-08-12 | B1 | done — Laravel 13.25 skeleton in `services/api`, 6 tests + pint + phpstan(6) green, `/api/v1/health` 200 through nginx+FPM+PgBouncer in a real container. Two infra bugs fixed on the way: PgBouncer listened on 5432 while everything dialled 6432 (`dd31cef`), and the nginx health location resolved a doubled `public/` path. Note: host PHP 8.3 has no pdo_pgsql/amqp, so `make dev-api` needs those extensions — use `make test-api`/`lint-api` (Docker) meanwhile. |
| 2026-08-12 | A7, M0 gate | done — worker scaffolds + ai-detector skeleton + go.work fixed (`GOWORK=off` no longer needed); Go images 23.8MB; `--profile analysis config` valid. M0 exit PASS: all unit + integration suites green, container-level pub/sub smoke OK. Next: M1/B1 (Laravel skeleton). |
