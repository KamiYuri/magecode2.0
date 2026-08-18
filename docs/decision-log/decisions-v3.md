# MageCode 2.0 — Decision Log v3: Infrastructure Update

> **Version**: 3.0 — 17/03/2026
> **Authors**: Gideon & Claude (Anthropic)
> **Scope**: Supplement to Decision Log v2 (D-80 through D-89)

---

## 1. Summary

This document records 10 new infrastructure decisions (D-80 through D-89) made during the inter-service communication redesign session on 17/03/2026. These decisions fundamentally change how analysis services interact with the database and result flow.

| Category | Decisions | Count |
|---|---|---|
| Architecture (D-01–D-33) | Tech stack, domain, services | 33 |
| Decision Log v1 + v2 (D-34–D-79e) | Submission, analysis, bank, infra | 75 |
| Decision Log v3 (D-80–D-89) | Inter-service communication | 10 |
| Later additions (D-90, D-91) | Judge0 isolation, client API style | 2 |
| **TOTAL** | | **120** |

> **D-90** is recorded in `docs/docker-compose-architecture.md` §15 (Judge0 CE in its own
> compose file because it needs `privileged: true`), not here. **D-91** (REST as the client
> API style) is recorded in the amendments table in §7.

**Open questions remaining:** 0

---

## 2. Key Architectural Changes

The core change is splitting services into two distinct paths based on their timing and data requirements:

### 2.1. Two Processing Paths

| | **Real-time Path (CES)** | **Batch Path (SIM/AID/VUL)** |
|---|---|---|
| **Trigger** | Student submits code | GV triggers analysis |
| **DB access** | Direct (reads + writes) | None — stateless workers |
| **MinIO access** | Direct (has credentials) | Pre-signed URL from api |
| **Job message** | `submission_id` only | Full info: `file_url`, language, IDs |
| **Result message** | Completion status only | Status + full result data |
| **Result queue** | `result-execution` | `result-analysis` |
| **api writes DB?** | No — CES writes | Yes — api writes all results |
| **WebSocket push** | To student (immediate) | To GV (when batch done) |

### 2.2. Updated Queue Map (6 Queues)

| Queue | Producer | Consumer | Payload |
|---|---|---|---|
| `code-executor` | api | CES | `submission_id` |
| `plagiarism-checker` | api | SIM | `analysis_problem_id`, `submissions[]`, `file_urls`, languages |
| `ai-detector` | api | AID | `analysis_submission_id`, `file_url`, language |
| `vuln-scanner` | api | VUL | `analysis_submission_id`, `file_url`, language |
| `result-execution` | CES | api | `submission_id`, service, status |
| `result-analysis` | SIM/AID/VUL | api | `analysis_submission_id`, service, status, results, `trace_id` |

### 2.3. Infrastructure Containers (18 Total)

| Category | Containers |
|---|---|
| **Application (7)** | api, web, reverb, code-executor, plagiarism-checker, ai-detector, vuln-scanner |
| **Data (4)** | postgres, pgbouncer, rabbitmq, minio |
| **Routing (1)** | traefik |
| **Observability (3)** | prometheus, grafana, loki |
| **Exporters (3)** | postgres-exporter, pgbouncer-exporter, php-fpm-exporter |

---

## 3. Master Decision Table (D-80 – D-89)

| # | Decision | Conclusion | Rationale |
|---|---|---|---|
| **D-80** | SIM/AID/VUL DB access | Stateless. No DB access. api writes results. | Decoupling, simpler deployment, failure handling |
| **D-81** | CES DB access | Keep direct DB access. | Real-time UX, per-test-case streaming, stable schema |
| **D-82** | Analysis timeout | Polling: scheduled job 5min, timeout 30min, warn 15min. | Simple, sufficient for batch workload, covers crash |
| **D-83** | Result queues | Split: `result-execution` + `result-analysis`. | Different logic, failure isolation, clean separation |
| **D-84** | Job message payload | CES: ID only. SIM/AID/VUL: full info + `file_url`. | CES has DB. Others stateless, need all info upfront |
| **D-85** | MinIO access | Pre-signed URL for SIM/AID/VUL (6h). CES direct. | Stateless services need no MinIO credentials |
| **D-86** | Traefik | Phase 2. Path-based, single domain, nginx+PHP-FPM. | No CORS, auto-discovery, TLS in production |
| **D-87** | Observability | Full setup Phase 2. Prometheus+Grafana+Loki. | ~600MB RAM, Docker Loki log driver, worth it early |
| **D-88** | Log format | JSON stdout. Required: timestamp, level, service, message. `trace_id` across services. | Unified query in Loki, cross-service debugging |
| **D-89** | PgBouncer config | Transaction pooling, `pool_size=30`, `max_client_conn=150`. Only api+CES+reverb+exporters. | Reduced from 6 to 3 DB consumers after D-80 |

---

## 4. Detailed Decisions

### 4.1. D-80: SIM/AID/VUL Stateless Workers

SIM, AID, and VUL no longer access PostgreSQL. They receive all necessary information via RabbitMQ job messages (`file_url`, language, submission IDs) and return full result data via the `result-analysis` queue. api is the single source of truth for all DB writes in the batch path.

**Impact:** SIM/AID/VUL only need RabbitMQ connection + HTTP client (for MinIO pre-signed URLs). No DB driver, no PgBouncer config, no schema knowledge. Simplifies deployment and secret management.

### 4.2. D-81: CES Keeps DB Access

CES retains direct PostgreSQL access for the real-time path. It reads test cases, writes `code_execution_results` per test case, and updates submission status. This enables per-test-case streaming to students via WebSocket without additional latency.

**Justification:** The `code_execution_results` schema is highly stable (`submission_id`, `test_case_id`, `status`, `output`, `time`, `memory`, `error`). Schema coupling risk is minimal. Real-time UX priority outweighs decoupling benefit.

### 4.3. D-82: Analysis Timeout via Polling

A scheduled job runs every 5 minutes, checking for analysis batches (`analysis_problems`) with `status=processing` and `started_at` older than 30 minutes. Timed-out batches are marked as `timeout`. Completed submissions within the batch keep their results (graceful degradation per D-59). A warning is logged at 15 minutes for debugging.

**Why not heartbeat/event-driven:** Analysis is batch, not urgent. 5-minute detection is acceptable. Heartbeat adds infrastructure complexity for minimal gain at this scale.

### 4.4. D-83: Two Result Queues

Split the single result queue into `result-execution` (CES to api) and `result-analysis` (SIM/AID/VUL to api). Each has a dedicated consumer in api with distinct logic.

- **`result-execution`:** Lightweight. api receives completion status, pushes WebSocket to student.
- **`result-analysis`:** Heavier. api receives full result data, writes to DB, updates status, checks batch completion, pushes WebSocket to instructor.

**Benefit:** Failure isolation. Heavy SIM batch results do not block real-time CES results.

### 4.5. D-84: Job Message Payload Design

CES receives `submission_id` only and queries DB for test cases, language, and file path. SIM/AID/VUL receive complete information in the job message including `file_url` (pre-signed), language, and all necessary IDs.

**CES rationale:** CES has DB access. If test cases are updated while message is in queue, CES queries latest version — correct behavior.

**SIM/AID/VUL rationale:** Stateless. All info must be in the message. No fallback to DB.

### 4.6. D-85: MinIO Pre-signed URLs

api generates pre-signed URLs with 6-hour expiry and includes them in job messages for SIM/AID/VUL. Services download source code via standard HTTP GET. No MinIO SDK or credentials needed. CES uses direct MinIO access via `shared/go/storage/` package.

**Performance:** Source files are max 50KB (D-34). HTTPS download on internal Docker network takes ~10ms per file. SIM downloading 100+ files sequentially takes ~1 second, negligible compared to Dolos processing time.

### 4.7. D-86: Traefik in Phase 2

Path-based routing on a single domain. api container runs nginx + PHP-FPM. Laravel `TrustProxies` middleware configured for Traefik. WebSocket routed to Reverb container. No CORS needed with single-domain setup.

| Path | Container | Port | Notes |
|---|---|---|---|
| `/api/*` | api (nginx + PHP-FPM) | 80 | Laravel API |
| `/ws/*` | reverb | 8080 | WebSocket |
| `/grafana/*` | grafana | 3000 | Dashboards |
| `/* (fallback)` | web (nginx static) | 80 | Vue SPA |

### 4.8. D-87: Full Observability in Phase 2

Three pillars: Metrics (Prometheus + Grafana), Logs (Loki with Docker log driver), Alerts (Grafana Alerts). Estimated resource overhead: ~600MB RAM total.

**Metrics Sources:**

| Source | Metrics | Method |
|---|---|---|
| **Traefik** | Request rate, latency, status codes | Built-in `/metrics` |
| **PHP-FPM** | Workers, request duration | php-fpm-exporter |
| **PostgreSQL** | Connections, query duration, cache | postgres-exporter |
| **PgBouncer** | Pool usage, wait time | pgbouncer-exporter |
| **RabbitMQ** | Queue depth, message rate | Built-in Prometheus plugin |
| **MinIO** | Storage, request rate | Built-in `/metrics` |
| **Go services** | Jobs processed, duration, errors | promhttp `/metrics` |
| **ai-detector** | Inference time, backlog | prometheus-client |

**Basic Alerts (Phase 2):**

| Alert | Condition | Channel |
|---|---|---|
| **Service down** | Container restart >3x in 5min | Email |
| **Queue backlog** | Queue depth >100 for 10min | Email |
| **DB connections** | PgBouncer `wait_time` >5s | Email |
| **Analysis timeout** | Scheduled job (D-82) | In-app + Email |
| **Disk space** | >80% usage | Email |

### 4.9. D-88: Unified Log Format

All services output JSON logs to stdout. Docker Loki log driver collects and sends to Loki. Unified format enables consistent querying across all services in Grafana.

**Required Fields:**

| Field | Type | Description |
|---|---|---|
| `timestamp` | ISO 8601 UTC | Log time. Renamed from `time` (Go) / `datetime` (PHP) |
| `level` | string (lowercase) | `debug`, `info`, `warn`, `error` — 4 levels only |
| `service` | string | `api`, `code-executor`, `plagiarism-checker`, `ai-detector`, `vuln-scanner`, `reverb` |
| `message` | string | Human-readable description. Renamed from `msg` (Go) |

**Optional Fields:**

| Field | Type | Description |
|---|---|---|
| `trace_id` | string | UUID/nanoid for cross-service tracing. Generated by api, passed in job messages. |
| `data` | object | Structured context: `submission_id`, `duration_ms`, error detail, etc. |
| `error` | string | Error message or stack trace (only when `level = error`) |

**Per-Language Implementation:**

| Language | Library | Key Config |
|---|---|---|
| **Go** | slog (stdlib) | Custom handler in `shared/go/logger/` renames `time`→`timestamp`, `msg`→`message` |
| **Python** | structlog | `TimeStamper(key=timestamp)`, `EventRenamer(message)`, `JSONRenderer()` |
| **PHP** | Monolog (Laravel) | `JsonFormatter` + custom tap class: `datetime`→`timestamp`, `level_name`→`level` |

**Prohibited:** `fmt.Println`, `error_log`, `dd()`, `var_dump`, `console.log` — all logging must go through the structured logger.

### 4.10. D-89: PgBouncer Config Update

After D-80, only 3 application services need DB access (down from 6). PgBouncer config adjusted accordingly.

**Connection Budget:**

| Consumer | Connections | Notes |
|---|---|---|
| api (PHP-FPM 50 workers) | 50 | 1 per worker |
| api (RabbitMQ consumers) | 2 | `result-execution` + `result-analysis` |
| api (scheduled jobs) | 2 | Timeout + deadline checker |
| reverb | 2 | Channel auth |
| code-executor (5 goroutines) | 5 | D-75 |
| postgres-exporter | 1 | Read-only |
| pgbouncer-exporter | 1 | Read-only |
| **Total** | **~63** | |

**PgBouncer Settings:**

| Setting | Value | Rationale |
|---|---|---|
| `pool_mode` | `transaction` | Required for PHP-FPM (no persistent connections) |
| `default_pool_size` | `30` | Actual PostgreSQL connections. Fits default `max_connections=100` |
| `max_client_conn` | `150` | Buffer above 63 actual. Room for spikes |
| `min_pool_size` | `5` | Keep warm connections |
| `reserve_pool_size` | `5` | Burst handling |
| `reserve_pool_timeout` | `3` | Seconds before using reserve pool |

**Laravel caveat:** `PDO::ATTR_EMULATE_PREPARES = true` required for transaction pooling mode.

**Go caveat:** `pgx simple_protocol=true` (`default_query_exec_mode=simple_protocol` in DSN).

---

## 5. Schema & API Impact

Decisions D-80 through D-89 do not change the database schema or API endpoints. They change the data flow between services:

- Result messages from SIM/AID/VUL now carry full result data (not just status)
- Job messages from api to SIM/AID/VUL now carry `file_url` and language (not just IDs)
- All job messages include `trace_id` for cross-service tracing
- Six RabbitMQ queues instead of five
- Three DB consumers instead of six

---

## 6. Next Steps

With all 118 decisions finalized, Phase 1 deliverables to complete:

1. Update database schema (ERD) — no changes from D-80–D-89, validate existing design
2. Finalize OpenAPI spec for all API endpoints
3. Update RabbitMQ JSON Schemas with new message formats (`trace_id`, `file_url`, result data)
4. Docker Compose with all 18 containers + Traefik routing + Loki log driver
5. Initialize monorepo with `CLAUDE.md` and service scaffolds

---

## 7. Amendments

Corrections applied in place across the decision logs and design docs after Phase 1 sign-off.
Original text is updated inline; this table records what changed and why.

| Date | Decision(s) | Change | Rationale |
|---|---|---|---|
| 2026-08-10 | D-10 | Laravel 12 → 13, PHP 8.3 → 8.4, Go 1.22 → 1.26, Python 3.11 → 3.12 | D-10's rationale is "latest version"; Laravel 13 + PHP 8.4 validated by the deprecated 2.0 prototype. See `docs/superpowers/plans/2026-08-10-magecode-2.0-upgrade-roadmap.md` (U-1) |
| 2026-08-10 | D-85, D-79c | Pre-signed URL TTL 2h → 6h | `rabbitmq-schemas.md` §2.6 (final Phase 1 deliverable) already specified 6h to cover max queue wait + 30-min analysis timeout + buffer; decision logs and technical-design brought in line (U-4) |
| 2026-08-10 | D-12 | 4 roles → 5 roles (adds System Admin) | `openapi.yml` already defines System Admin for platform bootstrap (creating Organizations, assigning Org Admins); technical-design §3 and decision logs unified (U-5) |
| 2026-08-12 | **D-91** (new) | Client API style is **REST over HTTP**, contract in `openapi.yml`; GraphQL evaluated and rejected | REST was assumed project-wide but never decided, so this closes the gap rather than changing course. GraphQL was assessed before B3: it targets over-fetching we do not have (3 of 37 GETs use `include`), while it weakens the three privacy-gated P0s — B5's `role × endpoint × status` matrix becomes `role × field`, and D-05/D-06 cross-section redaction must hold against arbitrary client-chosen field sets. It also breaks HTTP-shaped commitments already made: G4's `zero 5xx` threshold (GraphQL returns 200 with in-body errors), per-route metrics behind Traefik path routing (D-86, G1/G2), and route-level rate limits (U-3). Uploads/exports (4 multipart + 2 binary) would stay REST regardless, so the result is a hybrid. Cost: rewriting `openapi.yml` (2596 lines) plus B4–B12, C2, D8, F2, F8, F9, B11 and two milestone gates. **Revisit when** a second client with different payload needs appears, or M4 measures over-fetch/round-trips as a real bottleneck, or `include` spreads past ~10 operations |
| 2026-08-12 | D-12, schema §2.1 | System Admin stored in `users.is_system_admin` | The 5-role amendment added System Admin but left it nowhere to live: `users` has no role column and the role is platform-scoped, so neither `organization_members` (admin\|instructor) nor `section_members` (instructor\|ta\|student) can express it, yet `openapi.yml` gates `POST /organizations` and `GET /admin/organizations` on it. A boolean column plus an artisan bootstrap keeps membership tables untouched. The flag is never settable through the API |
| 2026-08-12 | D-12, technical-design §3.1/§4.2 | TA cannot read analysis results (SIM/AID/VUL) | The docs contradicted each other: `openapi.yml:41` grants a TA "CES results only (no analysis results)" while technical-design §4.2 listed TA alongside Instructor in both similarity tiers. `openapi.yml` is the higher source, and for a privacy-tagged surface the narrower reading is the safe default — widening later is easy, retracting access is not. technical-design brought in line |
| 2026-08-12 | D-11, schema §1.3/§9.2 | `personal_access_tokens` added to the documented table set (34 → 35) | `openapi.yml` declares `BearerAuth` with `bearerFormat: Sanctum`, and Sanctum stores those tokens in `personal_access_tokens`. The schema doc listed the other seven framework tables but omitted this one, so the contract required a table the data model denied. Found while porting migrations in B2 |
| 2026-08-12 | D-46 | Endpoint corrected to `POST /api/v1/problems/{problem_id}/analysis` | Three documents spelled the same endpoint three ways: D-46 had the unversioned `POST /api/problems/{id}/analyze`, while `openapi.yml` and roadmap D1 use `/problems/{problem_id}/analysis`. `openapi.yml` outranks the decision log for endpoint shape |
| 2026-08-13 | D-16, schema §2.4 | Semester `end_date` must be greater than or equal to `start_date`; enforced at the request layer, no migration | Both dates are nullable with no ordering rule in any source — not in D-16, not in `openapi.yml`, and `semesters` carries no CHECK — so an inverted range was accepted silently. A CHECK would need a migration and would break `SchemaConformanceTest`'s documented-constraint set, and the rule is a UI-level guard rather than a data invariant other services depend on. Validated in `StoreSemesterRequest`/`UpdateSemesterRequest`; on a partial PUT the comparison falls back to the persisted value. Added in B6 |
| 2026-08-13 | D-16 §6.3, schema §2.6 | In `auto` mode a NULL `activation_time` means "not yet open" and a NULL `lock_time` means "never closes" | The visibility rule is written as `NOW() >= activation_time`, but both columns are nullable and no source says what an unset gate means. Reading NULL as "no gate" would publish every auto-mode problem the moment it is created — a problem drafted for next week would be visible today, which is the failure that cannot be undone. The lock side takes the opposite default for the same reason: a missing close time must not close a problem nobody has finished. Implemented in `ProblemVisibilityService` and mirrored in `Problem::visibleIn()` (B8) |
| 2026-08-13 | D-16 §6.3, openapi `UpdateProblemRequest` | "Locked", wherever a rule depends on it, means the *effective* lock — auto mode past `lock_time` counts, not only the manual `is_locked` flag | openapi freezes the core fields (test cases, limits, languages) on a "locked" problem without saying which lock it means. Using the flag alone would leave an auto-mode problem editable after its deadline, so the set a submission was judged against could change once students could no longer respond. An instructor who does need to edit moves `lock_time`, which stays editable. Applied to PROBLEM_LOCKED on both `PUT /problems/{id}` and the test-case batch (B8) |
| 2026-08-13 | D-12, D-16 §6.1 | Organization management widened: `OrganizationPolicy::update` and `::manageMembers` become `is_system_admin OR isOrganizationAdmin`, and `POST /organizations` auto-enrols the creator as an `organization_members` admin | B5 read System Admin as strictly platform-scoped ("creates and lists organizations and nothing else"), which deadlocked the entity: only a System Admin may create an organization, but only an org admin may rename it or add its first member, so every new organization was born unmanageable. decisions-v1 §4.1 already grants System Admin "bootstrap and manage Organizations, assign Org Admins", so the narrow reading was the deviation. Creation stays System-Admin-only per `openapi.yml`; the auto-enrolment also makes `my_role: "admin"` correct in the 201 body and gives the `LAST_ADMIN` guard something to protect from the first request. The flag still grants no view of student work anywhere — `view`, section abilities and analysis surfaces are unchanged. Applied in B6 |
| 2026-08-13 | D-34, U-4, schema §3.2 | `programming_languages.file_extensions` (JSONB) added; the upload allowlist is read from it | U-4 requires the extension allowlist to be "derived from `programming_languages`", but the table carried no extension anywhere — only `name`/`monaco_language`/`dolos_language`/`codeql_language`, none of which yields `.py` or `.cpp`. A map hard-coded in PHP would sit out of reach of C2's request validation and of the frontend's file picker, so adding a language would become a code change instead of seed data. A list rather than a single value because C++ arrives as `.cpp`, `.cc` or `.cxx`; the first entry is canonical and names the file when a submission comes in as a JSON `source_code` body with no filename of its own. Added in C1 |
| 2026-08-13 | D-34, openapi `SubmitCodeRequest`/`SubmitFileRequest` | Source file limit corrected from "max 1MB (D-33)" to 50KB (D-34); `maxLength` 1048576 → 51200 | Two errors in one line: 1MB contradicts D-34, which the storage layer now enforces, and D-33 is Observability — the citation was never right. U-4 already flagged the prototype's 1MB as wrong. `openapi.yml` outranks code, so it is corrected here rather than worked around. 50KB is read as 51200 bytes, matching Laravel's `max:50`. Applied in C1 |
| 2026-08-13 | D-42, D-77, D-85 | api sanitises the client-supplied filename before it enters the object key; pre-signed URLs are signed against the internal endpoint only | The key format `submissions/{problem_id}/{submission_id}/{filename}` is unchanged and `shared/go/storage.SubmissionPath` remains its authority — but the Go helper takes the filename verbatim, and the api is the only writer of these objects, so narrowing what can be produced (traversal segments, control characters, the 500-byte `file_path` ceiling) costs no parity. Sanitising stays deliberately narrow — no slug, no ASCII filter — so `Giải Thuật.py` still round-trips, which a unit test pins on both sides. Separately: SigV4 signs the Host header, so a URL signed against `http://minio:9000` is fetchable by SIM/AID/VUL on the backend network (D-80/D-85) and by nothing else. That is the whole of what C1 needs; a browser-reachable download surface (avatars, source download) needs a public MinIO endpoint and is deferred to whichever task first requires one. Applied in C1 |
| 2026-08-14 | D-47, D-48, D-58, schema §5.2 | `chk_analysis_scope` tightened from OR to XOR: `CHECK (num_nonnulls(bank_problem_id, manual_match_group_id) = 1)` | B2 implemented the doc's OR and flagged it, because every other source reads as XOR: `roadmap.md` D1 spells the scope resolution "(D-47/58, XOR)", technical-design §7.1.6 says `manual_match_group_id` is "only set when problem has no `bank_problem_id`", and the prototype used XOR. The concrete failure OR permits is not hypothetical — the scope logic in §5.2 is written as two independent `IF`s with no precedence rule, so a row carrying both identifiers resolves to two different sets of problems and D1 would have to invent a tie-break the design never specified. `num_nonnulls` over `(a IS NOT NULL) <> (b IS NOT NULL)` because it reads as the rule it enforces. schema §5.2 and technical-design §7.4.1 amended first, then the migration; `SchemaConformanceTest` gained three semantic cases (both / neither / either alone) since it previously only asserted the constraint's *name*, so the change of meaning would have gone unnoticed. No production database exists — `make fresh` rebuilds |
| 2026-08-14 | D-48, D-58, schema §5.2, openapi `triggerAnalysis` | A problem with neither scope identifier gets a one-problem `manual_match_group_id` generated when analysis is first triggered | XOR made an existing hole visible: a problem written from scratch (no bank entry, no manual group) satisfies neither branch of the scope query, so under either constraint it could never be analysed — and that is the ordinary case for a section teaching its own exercises. The alternative, rejecting the trigger with a `NO_MATCH_GROUP` error until the instructor calls `POST /semesters/{id}/match-groups`, adds a mandatory step to the most common path in order to describe a group of one. So api generates a UUID v4, writes it to `problems.manual_match_group_id` in the same transaction as the `analysis_problems` row, and scopes to it. Nothing degrades: SIM still compares that problem's submissions against each other (D-49 picks the latest per student), AID/VUL never needed matching, and a later match-group call widens the group by handing the same UUID to other problems. The problem's `manual_match_group_id` therefore changes from null on first trigger — noted in the endpoint description so the frontend does not treat it as immutable. To be implemented in D1 |
| 2026-08-14 | D-13, decisions-v1 §4.1 | The "unless Instructor permits" escape hatch is dropped: a TA can never create problems or trigger analysis in 2.0 | decisions-v1 §4.1 granted a TA those two abilities at an Instructor's discretion, but nothing was ever designed to carry the grant: `section_members` has no permission column, `openapi.yml` declares no endpoint that awards one, and the role is a plain `instructor` / `ta` / `student` string. B5 shipped the absolute denial and its 66-case matrix pins it, so the clause was already dead text that a future task would have read as a missing feature. Implementing it instead would mean a migration, two endpoints and an `openapi.yml` addition on a privacy-tagged surface — for a case the design never fleshed out. Consistent with the 2026-08-12 amendment that removed a TA's read access to analysis results for the same reason. Widening later stays cheap; decisions-v1 §4.1 keeps the sentence with a superseded marker rather than being rewritten, since it is a historical log |
| 2026-08-14 | D-42, D-77, D-85, openapi `User`/`UserSummary`/`Organization` | Bytes bound for a browser are streamed by api; pre-signed URLs stay internal-only, and no public MinIO endpoint is opened | C1 deferred this to the first task that needed it — B12's avatars, which are blocked without it. Two options were real: publish the S3 API through Traefik and presign against a public host, or serve objects through api. The second wins on the facts of this system: every object a browser would want is small (avatars; source files capped at 50KB by D-34), `openapi.yml` already commits api to streaming binaries in two export operations so the pattern is not new, and authorisation is re-checked per request — where a pre-signed URL cannot be withdrawn for its full 6h TTL, which matters for source downloads that cross section boundaries. It also leaves G3 nothing new to defend. `avatar_url` therefore stops meaning "pre-signed MinIO URL" in all three schemas and names an api route; `GET /users/{user_id}/avatar` and `GET /organizations/{organization_id}/avatar` are added to the contract (88 → 90 operations, 61 → 62 paths) because roster and dashboard views show other people's avatars, which `/profile/avatar` cannot serve. Contract only here — implementation belongs to B12, and both operations sit in `RouteConformanceTest::PENDING` until then |
| 2026-08-14 | openapi `createSubmission`, ui-ux §4.2 | `SUBMISSION_PROCESSING` defined: a student may hold only one unfinished submission (`in_queue` or `processing`) per problem at a time | `openapi.yml` lists the code among the 422 reasons but no source says what triggers it, so C2 would have had to either invent the rule or ship a code nothing raises. Defining it is the better half: without the gate one student can spend their whole quota into the queue in a single burst, ahead of a class waiting on the same Judge0 workers, and the quota count becomes a race against results that have not landed. The `submissions` limiter (10/min, U-3) caps the rate but not the depth. Scope is deliberately per problem, not per student: working on two problems at once is ordinary. `ui-ux-design.md` §4.2 only disables the submit button on quota and lock, so it gains this third reason — the pending row is already rendered, so the frontend has the state it needs. Applied in C2 |
| 2026-08-14 | D-37, D-38, schema §10 | Judge0's status ids map to `TestCaseStatus` as 3→`accepted`, 4→`wrong_answer`, 5→`time_limit_exceeded`, 6→`compilation_error`, 7–12→`runtime_error`, 13–14→`internal_error`; a runtime error whose reported memory reaches the requested cap becomes `memory_limit_exceeded`; and `timeout` is reserved for the call to Judge0 itself failing to return | Nothing in the repository maps Judge0's fourteen statuses onto our eight, so C6 would have invented it and every verdict a student ever sees would rest on that invention. Two spots needed a decision rather than a transcription. First, `memory_limit_exceeded` exists in schema §10 but Judge0 CE has no such status — `isolate` kills an over-limit process and it surfaces as a signal, indistinguishable from an ordinary crash unless the reported memory is read. Leaving the value unused would have made a documented verdict dead code and told a student their program crashed when it in fact ran out of memory, so the memory figure is compared against the cap the request set. It is inference and can be wrong at the boundary, which is why it only ever narrows an error that is already an error. Second, D-37 says "map Judge0 status to `timeout`" without saying which: status 5 is the program exceeding the problem's own time limit, which is `time_limit_exceeded` and a normal verdict, so `timeout` is given the only meaning left that is not already covered — CES asked Judge0 and got nothing back. D-38's 5000-character cap is applied here, at the producer, because the repository deliberately stores `error_content` verbatim. Applied in C6 |
| 2026-08-14 | D-53, schema §5.1, openapi `triggerAnalysis` | Triggering a scope whose latest batch is already `completed` for the same services answers **200** with that batch; `force: true` runs it again | `roadmap.md` D1 asks for an "existing-completed-results response" and openapi declared only 201/409/422, so the branch had nowhere to land — while D-53 describes re-triggering as creating a new batch, which reads as "always run". Both are right about different callers. A batch covers every equivalent problem in the semester, and §5.1 already says other instructors "see results immediately without re-triggering", so two instructors teaching the same semester both pressing the button would run Dolos twice over identical data and produce a second batch whose only effect is to make the first one stale. Returning the existing batch makes the common case free and honest. `force` keeps D-53 meaningful for the case that actually needs it — new submissions arrived, or a service was added — rather than making a re-run impossible. The comparison includes the service set: asking for a service the completed batch never ran is not the same request, and falls through to a new batch. Applied in D1 |
| 2026-08-14 | D-81, D-83, U-8, `result.execution.v1` | CES also publishes a `status: "processing"` message after each test case, carrying `latest_result`; both kinds travel on `result-execution` | `openapi.yml` declares `execution.updated` with a `latest_result`, `ui-ux-design.md` §4.2 describes the verdict strip filling cell by cell, and D-81 justifies giving CES database access precisely so results can stream — but `rabbitmq-schemas.md` §4.1 said "1 message per completed submission", so the event had no producer and the promise had no mechanism. Rather than delete a feature three documents describe, the message gains a third `status` value and an optional `latest_result`; `execution_status` gains `processing`, which `submissions.execution_status` already allowed, so the message keeps mirroring the column rather than inventing a parallel vocabulary. They share the existing queue instead of getting one of their own, which is the part worth being deliberate about: two queues would let a completion frame overtake the progress frames that precede it, and a student would watch their strip fill in *after* being told the grade. One queue with one consumer keeps the order the student sees the same as the order the work happened. Schema and `rabbitmq-schemas.md` amended before any code, per session-guide §3. Applied in C7 |
| 2026-08-14 | U-8, roadmap C7, openapi §WebSocket Channels | `private-submission.{id}` authorises the submission's creator only; section staff are not admitted to the channel | `openapi.yml` says "Submission creator" for both events on that channel and `roadmap.md` C7 says "ownership/staff" — the contract outranks the roadmap (session-guide §1), and on a realtime surface the narrower reading is the safe default for the same reason the TA amendment took it in August. Nothing is lost that matters: staff already read the same submission, source included, through `GET /submissions/{id}` (C2), so this decides who gets a live push, not who may see the work. Widening a channel later costs one line; discovering that a channel was broader than the contract promised costs a security review. `roadmap.md` C7 corrected. Applied in C7 |
| 2026-08-14 | D-81, schema §3.7 | `submissions.execution_status` is derived by count: all active test cases passed → `accepted`, at least one → `partially_accepted`, none → `error`; submission-level `timeout` means the grading run did not finish, not that a test case timed out | The enum is listed in three places and defined in none — nothing says which per-test-case verdicts produce which submission verdict, so CES would have invented it and the frontend's verdict strip would have inherited whatever it invented. Counting is the rule a student can predict from the strip they are already looking at. `timeout` is deliberately kept out of the count: `code_execution_results.status = 'timeout'` is D-37's per-test-case mapping of a Judge0 timeout, and reusing the same word for "this submission was never graded" would make the field mean two unrelated things — a submission whose test cases all time out is a wrong answer that is also slow, and reports `error` like any other zero. The uncomfortable edge is that `error` is what a working-but-wrong program gets, which reads as a system fault; the enum offers nothing better, and inventing a value would change a contract three documents and the UI palette already depend on. Applied in C5 |
| 2026-08-14 | D-81, schema §3.7 | The recount counts only results whose test case is still `is_active`, and `testcases_total` is the number of active test cases | Schema §3.7 says both "CES MUST recount from `code_execution_results`" and that `testcases_total` is the "total test cases at submission time"; those disagree the moment an instructor deactivates a test case, which D-40/D-41 explicitly allow after submissions exist. Counting every stored row would keep a deactivated test case in the denominator forever, so a problem showing eight test cases could report a submission as 7/10 — a number no one can reconcile against what they see. Joining the recount to `is_active = true` makes the counters always describe the current test set, and D-41's `is_outdated` flag remains the thing that tells a reader the set has changed since the run. Old result rows are left in place rather than deleted: they are the record of what actually ran, and a test case can be reactivated. Applied in C5 |
| 2026-08-14 | D-75, D-76 | Delivery concurrency moves into `shared/go/rmq`: one channel keeps the prefetch window and a pool of `Concurrency` workers drains it | The consumer A4 shipped handles deliveries sequentially, so CES ran one job at a time no matter what prefetch said — D-75's five goroutines existed only as `CES_WORKER_COUNT` in compose, read by nothing. D-76 words the pair as "CES: 5 (matches workers)", which is one channel holding five unacked messages with five handlers draining it, not five channels of one. Putting the pool in each service instead would mean writing it three more times for SIM (1), AID and VUL (3), and would make `RMQ_PREFETCH` mean per-channel in one service and per-instance in another. The cost is reopening a package the M0 gate signed off, so the drain guarantee is re-proven rather than assumed: cancellation stops new deliveries and every in-flight handler still finishes and settles its own message before `Consume` returns, and the retry/DLQ routing stays per-delivery so two failures cannot share a retry count. Applied in C4 |
| 2026-08-14 | D-79e, D-84, roadmap C3 | api publishes to the **default exchange** with the queue name as routing key, declaring the same `{queue}` / `{queue}.retry` / `{queue}.dlq` trio as `shared/go/rmq`; `bschmitt/laravel-amqp` is dropped for `php-amqplib` | `roadmap.md` C3 called for an `exchange magecode` of type direct, and it is the only place in the repository that mentions an exchange at all — not technical-design, not `rabbitmq-schemas.md`, not compose. What A4 actually shipped and the M0 gate smoke-tested end to end publishes with an empty exchange and the queue name as routing key (`shared/go/rmq/publisher.go`), and `declareTopology` declares no exchange. Building the api to the roadmap's letter would have published into an exchange with no binding, so **every execution job would have been dropped silently** — a failure C4 or C8 would have paid a session to find. The library goes with it, not by preference: `Publisher::setup()` unconditionally calls `declareExchange()` then `declareAndBind()`, and AMQP forbids binding to the default exchange; worse, its default `queue_properties` carry `x-max-length => 1` and `x-ha-policy`, which would either collide with the Go worker's argument-free declare as `PRECONDITION_FAILED` or cap the queue at one message. `php-amqplib` is already present as that package's own dependency, so the swap adds nothing new to install. The topology is now pinned from both sides by an integration test that redeclares with the Go arguments. Applied in C3 |
| 2026-08-14 | D-79e, D-84 | A publish that fails after the submission has committed returns 201 and logs at ERROR; the row stays `in_queue` | The submission is durable in Postgres and MinIO before the message is sent — it has to be, because the message carries only the id (D-84) and a broker ack for a rolled-back row would name a job that can never succeed. That ordering leaves one window: the row exists and the broker is unreachable. Failing the request would tell a student their work was rejected when it was in fact stored, which at a deadline is the worst possible lie; deleting the submission to match the failure is worse still. So the refusal is swallowed, logged with `submission_id` and `trace_id` so it is greppable in Loki (D-88), and the row waits. The cost is honest and bounded: a submission stuck at `in_queue` until something re-publishes it, which belongs to C7's result signalling or D7's timeout sweeper — neither exists yet, and inventing an outbox table for it here would add a table and a worker the architecture does not have. Applied in C3 |
| 2026-08-14 | D-36, D-39, D-89 | Quota serialisation uses `pg_advisory_xact_lock(problem_id, creator_id)` rather than a literal `SELECT ... FOR UPDATE` on a row | D-36 names the mechanism, not the target, and no row is the right one to lock: `FOR UPDATE` over the student's existing submissions locks nothing when they have none, so two concurrent first submissions both pass a `max_submissions = 1` check — precisely the case C2's concurrency test covers. Locking the `problems` row instead does serialise correctly but serialises the entire class, and the transaction cannot be made short: `file_path` is NOT NULL and the object key needs the submission id, so the MinIO write happens inside it. A whole section would then queue behind one student's storage round-trip at the deadline, which G4 measures at 500 CCU. The advisory lock is keyed on exactly the pair the rule is about, is released at commit — the transaction-scoped variant specifically, since D-89's transaction pooling would leak the session-scoped one — and needs no schema change. The guarantee D-36 asks for is unchanged: the count and the insert are serialised per student per problem. Applied in C2 |

| 2026-08-18 | D-79, `job.ai-detector.v1`, schema §3.2 | AID's `language` is read from `programming_languages.monaco_language`; a language identifier outside a job schema's enum parks that service as `not_applicable` instead of being published | The AID job schema requires a `language` enum of `python\|java\|c\|cpp` but no column was ever designated to supply it: `programming_languages` carries `dolos_language` (SIM's identifier), `codeql_language` (VUL's) and `monaco_language` (the editor's), and D-79's message table says only "language". `monaco_language` is the one that fits — it is NOT NULL, so every language row can answer it, and its seeded values are exactly the enum's members. `dolos_language` was the near alternative and is worse in a way that matters: it is nullable, so a language Dolos cannot parse would silently disable AI detection for it too, coupling two independent services through one tool's capability. Adding a fourth column was rejected as a migration plus seeder, factory and conformance-test churn for the same result. The second half generalises what SIM already needed: all three language columns are free-form `varchar(30)` while all three job schemas close their enums, so a mis-seeded or simply unsupported value has to park the submission rather than publish a message the worker would reject at the consumer — a rejected job is a submission that waits for a result until D7 times it out, where `not_applicable` closes it truthfully. SIM logs the unrecognised value at WARNING, since a `dolos_language` the schema lacks is a data error nothing else surfaces; a null is ordinary and stays silent. Applied in D2/D3 |
| 2026-08-18 | D-55, roadmap D2/D6, `rabbitmq-schemas.md` §3.2 | §3.2's rule 5 is split: D2/D3 write the per-submission `not_applicable` statuses, and closing `analysis_problems.status` stays with D6 | §3.2 ends "if 0 groups qualify, mark SIM completed immediately", which reads as batch-level completion inside the publishing step. Doing that in D2 would mean writing a completion rule for one service that D6 must then generalise for three, and D6 owns the events and the WebSocket push that go with it. The publishing tasks therefore stop at the row level, which is where their knowledge ends. The consequence is stated rather than hidden: until D6 lands, a batch whose services all resolve to `not_applicable` sits at `processing` until D7's sweeper reaches it — pinned by a test in `SimJobPublishingTest`, so the day D6 changes it, that test says so |

| 2026-08-18 | D-61, schema §5.5, `result.analysis.v1` | A SIM pair naming a submission outside the batch is dropped with a WARNING and the rest of the group is ingested; pairs are normalised to `submission_a_id < submission_b_id` on the way in | Two holes on the same table. First: `similarity_results.submission_a_id`/`_b_id` are RESTRICT foreign keys, so a pair naming an unknown or out-of-batch submission aborts the whole message — and every redelivery of it identically, which C4 classifies as Permanent and would dead-letter. Dead-lettering costs the group's valid pairs, and for a 150-submission language group that is roughly eleven thousand comparisons thrown away because one was wrong; dropping the pair keeps them and the WARNING (with `analysis_problem_id` and `trace_id`, greppable in Loki per D-88) is what says the producer misbehaved. Second: schema §5.5 calls `submission_a_id < submission_b_id` **CRITICAL** but nothing enforces it — the unique index is on the triple, so the same pair sent both ways round stores twice instead of replacing itself, and D8's "all matches of submission X" query would return it twice with two different `match_type`-neutral halves. api normalises, swapping `a_regions`/`b_regions` with the ids so each side's highlights follow their own submission. Applied in D4 |
| 2026-08-18 | U-9, `rabbitmq-schemas.md` §5.3 | The SIM completion state is the **set of received `language_group_index` values** plus the total, not a running count | §5.3 and U-9 both describe a counter (`received++`), which is wrong for the one event a queue guarantees: at-least-once delivery. A redelivered group would advance the count, and a batch of three groups would be reported complete after the second one arrived twice — with a third group's results still to come and the batch closed against them. The set makes a repeat delivery a no-op at no extra cost, since the index is already in the message for exactly this purpose. Stored in Laravel's `cache` (database store, per U-9 — there is no Redis) under a cache lock, because two consumers read-modify-writing the key would otherwise lose a group; D-82's sweeper stays the backstop for a key lost entirely to a restart. Applied in D4 |
| 2026-08-18 | D-54, `result.analysis.v1` | An AID result of `completed` carrying a null `probability` is stored as `error`; VUL findings are replaced wholesale per submission rather than appended | The result schema marks `probability` nullable ("null when status != completed") but does not forbid the combination, while `ai_detection_results.probability` is NOT NULL — so a producer sending it would either crash the ingest or need a null written into a column the reader assumes is filled. `completed` with nothing behind it tells an instructor a detection ran and found nothing to report, which is not what happened, so it is recorded as the failure it is and logged. Separately, `vulnerability_results` has no unique key to upsert on — a submission legitimately carries many findings, two of which can differ only by line — so idempotence under redelivery is achieved by deleting the submission's findings and re-inserting inside the same transaction. Appending would double every finding on the second delivery, and a re-scan that now finds nothing must leave nothing behind. Applied in D5 |

---

*— End of Decision Log v3 —*
