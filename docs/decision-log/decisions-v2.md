# MageCode 2.0 — Decision Log v2: Detailed Design Decisions

> **Version**: 1.0 — 20/03/2026
> **Authors**: Gideon & Claude (Anthropic)
> **Scope**: Decisions D-34 through D-79e (Submission, Analysis, Problem Bank, Infrastructure)

> This document captures the detailed design decisions made after the initial architecture (D-01–D-33 in [decisions-v1.md](decisions-v1.md)). These decisions are referenced throughout `database-schema.md`, `rabbitmq-schemas.md`, and `docker-compose-architecture.md`.

---

## 1. Summary

75 decisions covering four areas:

| Category | Range | Count | Covers |
|---|---|---|---|
| Submission & Execution | D-34 – D-45 | 12 | File limits, quota, Judge0, test cases, soft delete, ordering |
| Analysis Pipeline | D-46 – D-62 | 17 | Cross-section matching, re-trigger, timeout, thresholds |
| Problem Bank | D-63 – D-70 | 8 | Versioning, soft delete, approval workflow |
| Infrastructure & Operations | D-71 – D-79e | 38 | Connection limits, workers, logging, deployment |

---

## 2. Master Decision Table

### 2.1. Submission & Execution (D-34 – D-45)

| # | Decision | Conclusion | Schema/Implementation Impact |
|---|---|---|---|
| **D-34** | Max source file size | 50KB per file | Application-level validation. Pre-signed URL download ~10ms on internal network (D-85) |
| **D-35** | Allowed languages scope | Per-problem, configurable by instructor | `problem_programming_languages` pivot table |
| **D-36** | Submission quota enforcement | `SELECT ... FOR UPDATE` in transaction | Prevents concurrent quota bypass on `max_submissions` |
| **D-37** | Judge0 timeout handling | Map Judge0 status to `timeout` | `code_execution_results.status = 'timeout'` |
| **D-38** | Error content truncation | Max 5,000 characters | `code_execution_results.error_content` truncated at service level |
| **D-39** | Concurrent submission protection | Database-level locking | Combined with D-36: `FOR UPDATE` on submission count query |
| **D-40** | Problem edit tracking | Append-only audit log | `problem_edit_logs` table: `field`, `old_value`, `new_value`, `changed_by` |
| **D-41** | Test case update impact | Flag outdated submissions | `problems.testcases_updated_at` + `submissions.is_outdated = true` when test cases change after submission |
| **D-42** | Submission storage format | Single file per submission in MinIO | Stored at `submissions/{problem_id}/{submission_id}/{filename}` |
| **D-43** | Problem deletion | Soft delete only | `problems.deleted_at` — submissions preserved (D-52), problems can be restored |
| **D-44** | Problem display ordering | `order` field + `group_label` grouping | `problems.order` (INTEGER, NULLABLE), displayed within `group_label` groups |
| **D-45** | Test case limits | Max 50 per problem, max 1MB per input/output | Application-level validation during creation |

### 2.2. Analysis Pipeline (D-46 – D-62)

| # | Decision | Conclusion | Schema/Implementation Impact |
|---|---|---|---|
| **D-46** | Analysis trigger timing | Manual trigger by GV/Org Admin after problem locked | API endpoint: `POST /api/problems/{id}/analyze` |
| **D-47** | Cross-section problem matching (auto) | Same `bank_problem_id` = equivalent problems | `problems.bank_problem_id` FK auto-groups problems cloned from same bank entry |
| **D-48** | Analysis scope for SIM | Semester-level, all equivalent problems | `analysis_problems.semester_id` (not per-problem). "Phương án B" design |
| **D-49** | Latest submission per student | Only 1 submission per student enters analysis | `SELECT DISTINCT ON (creator_id) ... ORDER BY created_at DESC` |
| **D-50** | Student section transfer | Track transfers for audit | `section_transfer_logs` table: `student_id`, `from_section_id`, `to_section_id`, `transferred_by` |
| **D-51** | Multi-section enrollment constraint | A student cannot be in multiple sections of same Course in same Semester | Application-level validation during import (not DB constraint) |
| **D-52** | Submission deletion policy | **Never delete submissions** | No `deleted_at` on `submissions`. FK from `problems` is RESTRICT. Submissions are permanent academic records |
| **D-53** | Analysis re-trigger | Mark old as `is_latest = false`, create new batch | `analysis_problems.is_latest` + partial unique indexes ensure only 1 latest per scope |
| **D-54** | Partial re-run (specific services) | Per-service status with `not_applicable` | `analysis_submissions.{service}_status` can be `not_applicable` if service not selected |
| **D-55** | Analysis result aggregation | Batch completion checked per-problem | api checks: all `analysis_submissions` for this `analysis_problem` completed? |
| **D-56** | Analysis timeout detection | Polling: scheduled job every 5 min, timeout at 30 min | `analysis_problems.status = 'timeout'` when `started_at` > 30 min ago. See also D-82 |
| **D-57** | Analysis on unlocked problem | Allowed, marked as partial | `analysis_problems.is_partial = true` — results may be incomplete since students can still submit |
| **D-58** | Cross-section matching (manual) | UUID-based manual grouping for non-banked problems | `problems.manual_match_group_id` (UUID). GV assigns same UUID to equivalent problems across sections |
| **D-59** | Graceful degradation | Completed submissions keep results even if batch times out | Per-submission status independent of batch status |
| **D-60** | SIM result storage | Ordered pairs, 1 row per pair | `submission_a_id < submission_b_id` always. Halves storage |
| **D-61** | match_type assignment | api assigns after SIM returns | Based on `section_id` of the two submissions: same section = `WITHIN_SECTION`, different = `CROSS_SECTION` |
| **D-62** | Alert thresholds | Configurable per-semester | `semesters.similarity_threshold` (default 0.70), `semesters.ai_detection_threshold` (default 0.80) |

### 2.3. Problem Bank (D-63 – D-70)

| # | Decision | Conclusion | Schema/Implementation Impact |
|---|---|---|---|
| **D-63** | Bank problem versioning | `original_id` FK groups all versions | `bank_problems.original_id` → self-reference. NULL = this is the original |
| **D-64** | Version numbering | Auto-increment per original | `bank_problems.version` (INTEGER, DEFAULT 1). New version = max(version) + 1 for same original_id |
| **D-65** | Clone to Section | Deep copy: metadata + test cases | New `problems` row + copies from `bank_problem_test_cases` → `test_cases` |
| **D-66** | Publish to bank | Creates new version, not overwrite | `INSERT new bank_problems` with `original_id = first version's id`, `version = n+1` |
| **D-67** | Bank problem deletion | Soft delete only | `bank_problems.deleted_at`. Problems already cloned from it are unaffected |
| **D-68** | Bank search and filter | By tags, difficulty, language, keyword | Index on `[course_id, status]`, tag pivot table with Course-scoped tags |
| **D-69** | Bank problem preview | Rich text + LaTeX + sample test cases | Read-only view before clone decision |
| **D-70** | Bank approval notification | In-app notification to Org Admin | `notifications` table (polymorphic) when `require_bank_approval = true` |

### 2.4. Infrastructure & Operations (D-71 – D-79e)

| # | Decision | Conclusion | Schema/Implementation Impact |
|---|---|---|---|
| **D-71** | Environment configuration | `.env` per service, shared `.env.common` | Docker Compose env_file directive |
| **D-72** | Health checks | HTTP health endpoints for all services | Docker Compose `healthcheck` config |
| **D-73** | Graceful shutdown | Handle SIGTERM in all services | Go: `signal.NotifyContext`. PHP: FPM handles. Python: signal handler |
| **D-74** | Data volume management | Named Docker volumes for persistence | `pgdata`, `rabbitmq_data`, `minio_data` volumes |
| **D-75** | CES worker concurrency | 5 goroutines per instance | `CES_WORKER_COUNT=5`. 5 DB connections in PgBouncer budget (D-89) |
| **D-76** | RabbitMQ prefetch | Per-service prefetch limits | CES: 5 (matches workers). SIM: 1 (heavy). AID/VUL: 3 |
| **D-77** | File naming in MinIO | Deterministic path structure | `submissions/{problem_id}/{submission_id}/{original_filename}` |
| **D-78** | Log retention | 7 days in Loki, 30 days in Grafana | Docker Loki driver config with `loki-pipeline-stages` |
| **D-79** | Network isolation | Internal Docker network for services | Only Traefik exposed externally, all services on `magecode_internal` network |
| **D-79a** | Docker image strategy | Multi-stage builds, Alpine base for Go | Minimize image size for Go services (~20MB final) |
| **D-79b** | PHP-FPM tuning | 50 workers for api | `pm.max_children = 50`, transaction pooling via PgBouncer |
| **D-79c** | MinIO bucket policy | Private buckets, pre-signed URLs for access | 6-hour expiry for analysis services (D-85) |
| **D-79d** | RabbitMQ durability | Durable queues, persistent messages | All queues declared durable, delivery mode = 2 (persistent) |
| **D-79e** | Error retry strategy | Dead letter queues with 3 retries | DLX per queue, exponential backoff via message TTL |

---

## 3. Cross-References

These decisions are referenced in other documents:

| Document | Decisions Referenced |
|---|---|
| [database-schema.md](../database-schema.md) | D-36, D-37, D-38, D-39, D-40, D-41, D-43, D-44, D-45, D-47, D-50, D-51, D-52, D-53, D-54, D-56, D-57, D-58, D-62, D-67, D-75 |
| [rabbitmq-schemas.md](../rabbitmq-schemas.md) | D-34 (file size), analysis message formats |
| [docker-compose-architecture.md](../docker-compose-architecture.md) | D-75 (CES workers) |
| [decisions-v3.md](decisions-v3.md) | D-34, D-59, D-75 (referenced by D-80–D-89) |

---

*— End of Decision Log v2 —*
