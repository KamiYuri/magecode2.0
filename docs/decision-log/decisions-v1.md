# MageCode 2.0 — Decision Log v1: Preliminary Report & Implementation Plan

> **Version**: 4.0 — 16/03/2026
> **Authors**: Gideon & Claude (Anthropic)
> **Scope**: Decisions D-01 through D-33 (Architecture, Domain Model, Tech Stack)

> **Implementation approach:** Rewrite from zero — completely redesigned, no refactoring from v1.0. Domain knowledge and analysis engine from v1.0 are referenced, but source code, schema, and API are 100% new.

---

## 1. Project Overview

**MageCode** is a code assessment platform for higher education environments, built on a microservices architecture. Instructors create coding problems, students submit code, and the system automatically performs 4 types of analysis.

Version 2.0 is **written entirely from zero** — not refactored from v1.0. 100% new design for the domain model, database schema, API contracts, and source code. V1.0 is referenced as a source of domain knowledge and lessons about tech debt, not as a codebase to modify.

### 1.1. Products and Strategy

- **MageCode for Education**: Code assessment platform for universities. **Deployed first.**
- **MageCode for Community**: Contests and code analysis for the developer community. Deployed later.

This document focuses entirely on MageCode for Education.

### 1.2. Target Users

Serves programming courses at the university level. Initial target: BKCS and SoICT at HUST. First users: instructors and students of courses IT3080 and IT4062.

### 1.3. Four Analysis Pillars

| # | Service | Internal Name | Abbr | Technology | Purpose |
|---|---|---|---|---|---|
| 1 | Code Execution | `code-executor` | CES | Judge0 CE | Run code against test cases, auto-grading |
| 2 | Plagiarism Check | `plagiarism-checker` | SIM | Dolos (k-gram) | Detect code plagiarism across submissions |
| 3 | AI Detection | `ai-detector` | AID | CodeBERT + PyTorch | Detect AI-generated code |
| 4 | Vulnerability Scan | `vuln-scanner` | VUL | CodeQL | Static security analysis (optional, off by default) |

---

## 2. Terminology and Definitions

This section clarifies the terms used throughout the document, avoiding confusion between concepts.

| Term | Vietnamese | Definition in MageCode | Is NOT |
|---|---|---|---|
| **Organization** | Đơn vị | Top-level organizational unit: faculty, center, school. E.g., BKCS, SoICT | Not a company or team |
| **Course** | Môn học | A course persisting across many semesters. E.g., IT3080 | Not a single teaching instance |
| **Semester** | Kỳ triển khai | One specific run of a Course. E.g., IT3080-20252 | Not the university-wide semester |
| **Section** | Lớp | A specific class within a Semester. E.g., L01, L02 | Not a problem group |
| **Problem** | Bài toán | A specific coding problem with test cases and deadline | Not an "assignment" or "problem set" |
| **Submission** | Bài nộp | One student submission of source code for one Problem | Not a draft |
| **Problem Bank** | Ngân hàng đề | Reusable problem repository, belongs to Course, versioned | Not the active problem list |
| **Bank Problem** | Đề trong ngân hàng | A problem template in the Problem Bank, not yet assigned to a Section/deadline | Different from Problem (assigned) |
| **group_label** | Nhãn nhóm | Free-text label to group Problems. E.g., "Week 5", "Midterm Review" | Not a separate entity |

> **Clarification: Problem vs Assignment vs Bank Problem**
>
> **Problem** = a specific coding problem belonging to a Section, with a deadline. Students submit here. Equivalent to `CodingProblem` in v1.0.
>
> **Bank Problem** = a template in the Problem Bank, not attached to any Section. When an instructor clones a Bank Problem into a Section, it becomes a Problem.
>
> **Assignment** = term not used in MageCode 2.0 to avoid confusion. Use `group_label` to group multiple Problems.

---

## 3. Domain Model

Designed to closely reflect Vietnamese higher education terminology. Each entity maps 1:1 to a concept instructors and students use daily.

### 3.1. Entity Hierarchy

| Entity | Vietnamese | Example | Description |
|---|---|---|---|
| **Organization** | Đơn vị | BKCS, SoICT | Top-level organizational unit (faculty, center, school) |
| **Course** | Môn học | IT3080, IT4062 | Persists across semesters. Owns Problem Bank and Tags |
| **Semester** | Kỳ triển khai | IT3080-20252 | One specific run of a Course. Has lifecycle and policies |
| **Section** | Lớp | L01, L02, L03 | Primary isolation boundary. Instructor + TA + Students assigned here |
| **Problem** | Bài toán | "Bài 1: Linked List" | A specific coding problem with test cases, deadline, belongs to Section |
| **Submission** | Bài nộp | "Student A submits Problem 1" | Source code + grading results |

### 3.2. Entity Relationships

Organization → Course (many) → Semester (many) → Section (many) → Problem (many) → Submission (many)

- One Organization has many Courses
- One Course has many Semesters (each deployment is a Semester)
- One Semester has many Sections (classes)
- One Section has many Problems
- One Problem has many Submissions and many Test Cases
- Problem Bank belongs to Course, contains many Bank Problems (versioned)
- Tags belong to Course, many-to-many with Bank Problem
- Enrollment (student) is attached directly to Section, not Semester

### 3.3. Problem Bank (Fork Model)

Problem Bank belongs to Course and operates on a "fork" model:

- Each entry in the bank is a Bank Problem with a version number
- When creating a Problem for a Section, instructor chooses "Clone from bank" or "Create new"
- **Cloned copies are fully independent** — editing a Problem does not affect the bank, and vice versa
- "Publish to bank" creates a new version, does not overwrite the old one
- Bank keeps history: instructors in future semesters can choose a specific version to clone

**Bank Problem contents:**

- Problem description (rich text + LaTeX)
- Sample test cases
- Default difficulty (easy / medium / hard)
- Tags (many-to-many, Course scope)
- Allowed programming languages
- Default time/memory limits

**When cloned into Section as Problem:**

- All content is copied
- **Difficulty can be overridden** by instructor (e.g., same problem but advanced class changes from medium to hard)
- Tags follow without override
- Test cases can be independently edited
- Instructor adds `activation_time`, `lock_time`, `group_label` for the Problem

**Problem Bank access:**

- **Org Admin**: Full CRUD on bank for all Courses. Configure approval policies.
- **Instructor**: View and clone from bank of own Course. Publish new entries to bank.
- **TA / Student**: No access to bank.

**Publish workflow (D-25):**

All instructors teaching the same Course can see and clone Bank Problems. Publish process:

- **Org Admin configures per Course:** `require_bank_approval` (boolean)
- If approval enabled: Instructor publishes → status "pending" → Org Admin approves → appears in bank
- If approval disabled: Instructor publishes → appears immediately
- **Versioning:** each publish creates a new version. Other instructors only see the latest version when cloning
- **Edit/delete rights:** only original author and Org Admin

---

## 4. Roles and Permissions

### 4.1. Four Roles

| Role | Scope | Key Permissions |
|---|---|---|
| **Organization Admin** | Entire Organization | Manage Courses, full cross-section visibility, set Semester policies, manage Problem Bank |
| **Instructor** | Assigned Sections | Create/edit Problems, trigger analysis, view submissions in own sections, clone from Problem Bank |
| **Teaching Assistant** | Assigned Sections | View submissions, assist grading. Optional per Section. Cannot create Problems or trigger analysis (unless Instructor permits) |
| **Student** | Own Section | View problems (when published), submit code, view own results |

### 4.2. Isolation Model

**Section is the primary isolation boundary:**

- Instructor of L01 cannot see submissions from students in L02 and vice versa
- Student can only see their own section, not other sections
- Organization Admin is the only role with full cross-section visibility
- TA has the same visibility as Instructor but with more limited action permissions

### 4.3. Enrollment

Students are enrolled directly into a **Section** (not Semester). This reflects reality: students register for a specific class (L01, L02), not a generic course.

- Import student list per Section (Excel/CSV)
- Org Admin can view aggregate across all students in a Semester by combining Sections

---

## 5. Cross-Section Similarity Detection

### 5.1. The Problem

Code plagiarism occurs most frequently **between sections** of the same course in the same semester, because students know each other and have identical problem statements. If the plagiarism-checker only compares within-section, the most common case is missed. However, allowing instructors full access to another section's submissions would break the isolation model.

### 5.2. Solution: Two-Tier Similarity Results

SIM **always runs comparison across the entire Semester** (all sections with equivalent problems). Results are tagged with `match_type` and displayed in two tiers:

| Tier | Who Sees | Sees What | Does NOT See |
|---|---|---|---|
| Within-Section | GV/TA | Both student names, both code sides, % similarity, highlight regions | — |
| Cross-Section | GV/TA | Other student's name, % similarity, own student's code + highlight | Other student's code |
| Full Detail | Org Admin | Everything: names, sections, both code sides | — |

- GV has a "Report to Org Admin" button to escalate when needed
- Display threshold: cross-section flag only shown when similarity ≥ configurable threshold (default 70%)
- `match_type` (`WITHIN_SECTION` / `CROSS_SECTION`) is assigned by the api service after SIM returns results, based on section membership of the two students

---

## 6. Problem Lifecycle & Visibility

### 6.1. Semester-Level Policy

Org Admin sets policy for the entire Semester:

| Field | Type | Description |
|---|---|---|
| `publish_mode` | enum: `auto` \| `manual` | Default mode for activation (showing problem to students) |
| `lock_mode` | enum: `auto` \| `manual` | Default mode for lock (closing submissions) |
| `allow_publish_override` | boolean | Whether Instructor can override publish mode per Problem |
| `allow_lock_override` | boolean | Whether Instructor can override lock mode per Problem |

> **Example — Regular semester:** `publish_mode=auto`, `lock_mode=auto`, both `allow_override=true` → Instructor has full control.
>
> **Example — Final exam:** `allow_lock_override=false` → Instructor controls the open time (each section has different hours) but all must close simultaneously per `lock_time`.

### 6.2. Problem Fields

| Field | Type | Description |
|---|---|---|
| `activation_time` | datetime | When to show to students (if auto mode) |
| `lock_time` | datetime | When to close submissions (if auto mode) |
| `publish_mode_override` | `auto` \| `manual` \| null | Override publish mode. null = follow Semester |
| `lock_mode_override` | `auto` \| `manual` \| null | Override lock mode. Same logic |
| `group_label` | text | Free-text grouping: "Week 5", "Module 2: Data Structures" |
| `difficulty` | `easy` \| `medium` \| `hard` | Can be overridden from Bank Problem |
| `max_submissions` | integer | Limit number of submissions per student |

### 6.3. Visibility Logic

To determine the effective publish mode of a Problem:

1. If `Semester.allow_publish_override = false` → use `Semester.publish_mode`
2. If `allow_publish_override = true` AND `Problem.publish_mode_override != null` → use override
3. Otherwise → use `Semester.publish_mode`

Same logic applies for lock mode. If mode = `auto`: student sees problem when `current_time >= activation_time`, cannot submit when `current_time >= lock_time`. If mode = `manual`: instructor manually publishes/locks.

---

## 7. Core Requirements

### 7.1. Organization Management

- CRUD Organization, Course, Semester, Section
- Import student list per Section (Excel/CSV)
- 4-role permission system with isolation boundary at Section
- Problem Bank belonging to Course: fork model, version history, tags

### 7.2. Problem Management

- Create Problem from Problem Bank (clone) or create new
- Metadata: difficulty (overridable), tags, `group_label`
- Lifecycle: `activation_time`, `lock_time`, `publish_mode`, `lock_mode`
- Semester policy: Org Admin sets publish/lock mode + `allow_override`
- Test cases with activate flag (used for grading) and visibility flag (shown to students)

### 7.3. Submission and Grading

- Students submit source code via code editor (Monaco) or file upload
- Submission limit (`max_submissions`)
- Auto-execute with Judge0 immediately upon submission (CES runs automatically)
- Real-time results via WebSocket (Reverb)

### 7.4. Analysis Pipeline

- GV/Org Admin triggers analysis after problem is locked
- Select services to run: SIM (checked by default), AID (checked by default), VUL (unchecked by default)
- SIM compares across entire Semester (cross-section), assigns `match_type`
- Results displayed by role and tier

**Analysis Pipeline detailed workflow:**

1. GV/Org Admin calls API to trigger analysis for a Problem
2. api creates `AnalysisProblem` record (`status: processing`)
3. api retrieves the latest submission per student for that Problem
4. For each submission, creates `AnalysisSubmission` record
5. Publishes messages to RabbitMQ per selected services:
   - **SIM:** 1 message with all submission IDs (pairwise comparison across entire Semester)
   - **AID:** 1 message per submission
   - **VUL:** 1 message per submission (if selected)
6. Each analysis service: download code from MinIO → process → write results to DB → publish completion to result queue
7. api consumes result queue: update status, check if all services are done
8. When all done → push real-time update via WebSocket (Reverb) to instructor

> **Service identifiers (D-26):** Use string enum instead of numbers: `"code-executor"`, `"plagiarism-checker"`, `"ai-detector"`, `"vuln-scanner"`. Self-documenting, easy to debug when reading logs/messages, consistent with service naming convention (D-17).

### 7.5. Submission Modes

Instructors view the submission list in 2 modes:

- **all** — all submissions from all students (default)
- **best** — only the submission with the highest test cases passed per student (used for grading)

Students only see their own submissions, always in `all` mode.

### 7.6. Dashboard by Role

- **Student**: Sees Problem list immediately, grouped by `group_label`. 1 click to problem.
- **Instructor**: Sees assigned Sections, quick stats (how many students submitted, average score, similarity warnings), quick actions.
- **Org Admin**: Organization overview, full drill-down, policy management, cross-section similarity dashboard.

---

## 8. System Architecture

### 8.1. Service Map and Tech Stack

All tech stack finalized. Each service has a clear stack:

| Service | Name | Language | Tech Stack |
|---|---|---|---|
| Management API | `api` | PHP 8.4+ | Laravel 13, PHP-FPM, Sanctum, Spatie, Eloquent |
| Frontend | `web` | TypeScript | Vue 3, Vite, shadcn-vue, Tailwind CSS, Monaco Editor |
| WebSocket | `reverb` | PHP | Laravel Reverb (separate process, not Octane) |
| Code Execution | `code-executor` | Go 1.26+ | sqlx + pgx, slog, Judge0 API |
| Plagiarism | `plagiarism-checker` | Go 1.26+ | sqlx + pgx, slog, Dolos CLI |
| AI Detection | `ai-detector` | Python 3.12+ | psycopg3, pika, structlog, PyTorch, Transformers |
| Vuln Scan | `vuln-scanner` | Go 1.26+ | sqlx + pgx, slog, CodeQL CLI |

**Go services: shared packages (D-29)**

3 Go services share common packages in the monorepo, no framework:

- `shared/go/rmq/` — RabbitMQ: connect, reconnect, consume, publish
- `shared/go/db/` — sqlx + pgx setup, PgBouncer-friendly config
- `shared/go/storage/` — MinIO S3 client: download/upload source code
- `shared/go/logger/` — slog wrapper with JSON handler + service name
- `shared/go/config/` — env loading, validation
- `shared/go/apperror/` — custom error types, error propagation (no panic)

### 8.2. Infrastructure Stack (Finalized)

| Component | Technology | Decision |
|---|---|---|
| Database | **PostgreSQL 16 + PgBouncer** | D-19 |
| Message Broker | **RabbitMQ** | Dedicated job queue |
| File Storage | **MinIO (S3-compatible)** | D-31 |
| API Gateway | **Traefik** | D-22, Docker auto-discovery |
| WebSocket | **Laravel Reverb** | D-32, separate process |
| Observability | **Prometheus + Grafana + Loki** | D-33, setup from day one |
| Repository | **Monorepo** | D-23, agent context files |
| Runtime | **PHP-FPM (no Octane)** | D-27, simple, stable |

### 8.3. Core Data Flows

1. **web** ↔ **api**: REST API (Sanctum auth) + WebSocket (Reverb)
2. **api** → analysis services: RabbitMQ work queues, one queue per service
3. Analysis services → api: Results via shared result queue
4. All services → DB: Direct connections via PgBouncer
5. All services → storage: Download source code from MinIO

### 8.4. Monorepo Structure (D-23)

```
magecode/ (root)
├── CLAUDE.md                  # Agent context file, project overview
├── docker-compose.yml
├── services/                  # Each service in its own folder (own go.mod, composer.json, etc.)
├── shared/go/                 # Shared packages for 3 Go services (rmq, db, storage, logger, config, apperror)
├── shared/schemas/            # JSON Schema for RabbitMQ messages (source of truth)
├── deploy/docker/, deploy/k8s/  # Deployment configs
└── docs/                      # Documentation
```

---

## 9. Lessons from v1.0

V1.0 is not refactored but referenced as a source of lessons:

| v1.0 Issue | Original Service | v2.0 Principle |
|---|---|---|
| No connection pooling | ai-detector (Python) | PgBouncer mandatory for all services from day one |
| `panic()` for business errors | code-executor (Go) | Proper error propagation, no panic for business logic |
| Zero automated tests | All services | Test-first: write tests before implementation |
| Inconsistent message formats | CES vs others | Unified JSON Schema for all queues from design phase |
| No structured logging | All services | JSON structured logging standard from day one |
| Commented-out / deprecated code | VDS, web | Clean code only, no dead code committed |
| Entity model didn't match reality | Management | Domain model follows Vietnamese education terminology |
| Similarity only within-section | SDS | Cross-section comparison with two-tier visibility |
| FTP for file storage | All services | MinIO (S3-compatible), pre-signed URLs |
| No API Gateway | Management | Traefik with Docker auto-discovery |

---

## 10. Decision Log (D-01 – D-33)

All 33 architectural and design decisions have been finalized. Tech stack is complete and ready for Phase 1 implementation.

| # | Decision | Conclusion | Rationale |
|---|---|---|---|
| D-01 | Number of products | 2: Education + Community | Avoid generalization trap |
| D-02 | Deployment priority | Education first | Target users at BKCS/HUST |
| D-03 | Entity hierarchy | Org → Course → Semester → Section → Problem | Match Vietnamese education model |
| D-04 | Isolation boundary | Section is primary isolation | Instructor L01 cannot see L02 |
| D-05 | Cross-section similarity | Two-tier: within (full) + cross (partial) | Balance privacy and anti-plagiarism |
| D-06 | Cross visibility for GV | Partial: other student name + % + own code | Sufficient info without breaking isolation |
| D-07 | Problem Bank | Fork model, version history | Avoid data drift |
| D-08 | Enrollment | Attach to Section | Students register for specific classes |
| D-09 | UX navigation | Dashboard per role | Student: 1 click, Instructor: 2 clicks |
| D-10 | Laravel version | Laravel 13 | Latest version |
| D-11 | Cost | Open-source, free tier priority | Academic budget constraints |
| D-12 | Roles | 4: Org Admin, Instructor, TA, Student | Reflects reality |
| D-13 | TA | Optional per Section | Not every section has a TA |
| D-14 | Problem metadata | `group_label` + difficulty (overridable) + time | Group by week, classify difficulty |
| D-15 | Tags | Course scope, M2M with Bank Problem | Filter/search Problem Bank |
| D-16 | Problem visibility | Org Admin sets policy, GV overrides if allowed | Flexible, admin-controlled |
| D-17 | Service naming | No prefix, self-explanatory names | Clean, consistent |
| **D-18** | **Backend languages** | **PHP + Go + Python** | PHP for API, Go for workers, Python for ML |
| **D-19** | **Database** | **PostgreSQL 16 + PgBouncer** | JSONB, CTE, Go ecosystem |
| **D-20** | **Entity naming** | **Assignment → Problem** | Industry standard, clear meaning, 1 entity = 1 problem |
| **D-21** | **Top-level entity** | **Department → Organization** | More generic, multi-unit support |
| **D-22** | **API Gateway** | **Traefik** | Auto-discovery, Docker+K8s |
| **D-23** | **Repo structure** | **Monorepo** | Agent-friendly, atomic changes, 1–2 person team |
| **D-24** | **VUL service** | **Optional, off by default** | Not all courses need SAST |
| **D-25** | **Bank visibility** | **All GV in same Course, with approval** | Share resources, Org Admin control |
| **D-26** | **Service identifiers** | **String enum, not numbers** | Self-documenting, easy to debug |
| **D-27** | **PHP runtime** | **PHP-FPM (no Octane)** | Simple, stable, Reverb runs separately |
| **D-28** | **Frontend stack** | **Vue 3 + shadcn-vue + Tailwind** | Own source, lightweight, agent-friendly |
| **D-29** | **Go services** | **Bare Go + shared pkg, sqlx+pgx, slog** | Lightweight, no framework, stdlib logging |
| **D-30** | **Python ai-detector** | **psycopg3 + pika + structlog** | Connection pooling built-in, JSON logs |
| **D-31** | **File storage** | **MinIO (S3-compatible)** | S3 standard, ready to scale |
| **D-32** | **WebSocket** | **Laravel Reverb** | Laravel integration, Pusher-compatible |
| **D-33** | **Observability** | **Prometheus + Grafana + Loki** | Full stack, setup from day one |

---

## 11. Implementation Roadmap

Five-phase roadmap. Rewrite from zero. Each phase has an independent deliverable.

### Phase 1: Technical Design

**Goal:** Produce a complete Technical Design Document.

- Design new database schema from zero (ERD + migrations)
- Design API contracts (OpenAPI/Swagger)
- Design RabbitMQ message schemas (JSON Schema)
- System design: Docker Compose, networking, Traefik config
- Setup monorepo structure with `CLAUDE.md`

**Deliverable:** Technical Design Document + ERD + API spec + Message schemas + System design

### Phase 2: Core Platform (MVP)

**Goal:** End-to-end system where students can submit code.

- Setup infrastructure: PostgreSQL + PgBouncer + RabbitMQ + MinIO + Traefik + Docker Compose
- New api service: Auth, RBAC 4 roles, CRUD entities (Org/Course/Semester/Section/Problem), import students
- New web service: Login, Dashboard per role, CRUD UI
- New code-executor: Written from zero in Go, Judge0 integration, standardized error handling
- Submission flow: Submit → Judge0 → real-time results

**Deliverable:** Students can submit code, instructors can view grading results

### Phase 3: Analysis Pipeline

**Goal:** Complete remaining 3 analysis services.

- New plagiarism-checker: Go, Dolos CLI, cross-section comparison, `match_type`
- New ai-detector: Python, CodeBERT, connection pooling via PgBouncer, structured logging
- New vuln-scanner: Go, CodeQL integration
- api: Analysis orchestration, two-tier result display by role
- web: Analysis UI, similarity visualization (D3.js), cross-section results

**Deliverable:** Instructors can trigger analysis and view all 4 analysis types

### Phase 4: Problem Bank & Polish

**Goal:** Complete instructor experience.

- Problem Bank: CRUD Bank Problems, versioning, clone to Section, publish, tags
- Problem lifecycle: publish/lock mode, Semester policy, override
- UX polish: Dashboard optimization, loading states, error handling
- Email + in-app notifications

**Deliverable:** Complete platform for instructor use

### Phase 5: Production Readiness

**Goal:** Deploy for BKCS/HUST.

- Monitoring: Prometheus + Grafana + Loki setup
- Security audit: Input validation, SQL injection, XSS, CSRF
- Load testing: Simulate 500+ students submitting simultaneously
- Documentation: API docs, deployment guide, user guide
- CI/CD pipeline (if needed): GitHub Actions, path-filtered builds

**Deliverable:** Production deployment for IT3080 semester 20261

> **Development principles:**
>
> - **Rewrite from zero** — 100% new design, no legacy code carried over
> - Open-source and free tier priority (academic budget constraints)
> - Each phase has an independent deliverable that can be demoed and evaluated
> - Test-first: write tests before implementation
> - Structured logging and connection pooling (PgBouncer) from day one
> - Self-hosted on BKCS/HUST servers
> - CI/CD not needed during dev phase, added when deployment requires it

---

## 12. Next Steps — Phase 1: Technical Design

All open questions have been resolved. Phase 1 focuses on detailed design:

1. **Database schema (ERD)**: Design from zero for the new domain model (Organization/Course/Semester/Section/Problem/Submission + Problem Bank + Analysis Results)
2. **API contracts**: OpenAPI spec for all endpoints, grouped by role and resource
3. **Message schemas**: JSON Schema for all RabbitMQ queues (`code-executor`, `plagiarism-checker`, `ai-detector`, `vuln-scanner`, result)
4. **System design**: Docker Compose, Traefik routing, PgBouncer config, MinIO setup, networking
5. **Monorepo setup**: Initialize repo structure, `CLAUDE.md`, `.gitignore`, README per service

---

*— End of Decision Log v1 + v2 —*
