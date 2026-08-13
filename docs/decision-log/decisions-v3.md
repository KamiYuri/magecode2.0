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

---

*— End of Decision Log v3 —*
