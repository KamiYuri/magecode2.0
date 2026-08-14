# MageCode 2.0 — Phase 1: Technical Design Document

> **Version**: 1.0 — 16/03/2026
> **Authors**: Gideon & Claude (Anthropic)

---

## 1. Project Overview

### 1.1. What is MageCode?

MageCode is a microservices-based code assessment platform designed for higher education environments. Instructors create coding problems within courses, students submit source code, and the system automatically performs four types of analysis: code execution, plagiarism detection, AI-generated code detection, and vulnerability scanning.

Version 2.0 is a complete rewrite from zero. The entire domain model, database schema, API contracts, and source code are redesigned from scratch. Version 1.0 serves only as a reference for domain knowledge and lessons learned about technical debt.

### 1.2. Product Strategy

- **MageCode for Education:** Code assessment platform for university courses. Deployed first. This document covers this product exclusively.
- **MageCode for Community:** Contest and code analysis for the developer community. Deployed later, out of scope for this document.

### 1.3. Target Users

The platform serves programming courses at the university level. Initial target: BKCS and SoICT at Hanoi University of Science and Technology (HUST). First users: instructors and students of courses IT3080 and IT4062.

### 1.4. Four Analysis Pillars

| # | Service | Internal Name | Abbr | Technology | Purpose |
|---|---|---|---|---|---|
| 1 | Code Execution | `code-executor` | CES | Judge0 CE | Run code against test cases, auto-grading |
| 2 | Plagiarism Check | `plagiarism-checker` | SIM | Dolos (k-gram) | Detect code plagiarism across submissions |
| 3 | AI Detection | `ai-detector` | AID | CodeBERT + PyTorch | Detect AI-generated code |
| 4 | Vulnerability Scan | `vuln-scanner` | VUL | CodeQL | Static security analysis (optional, off by default) |

### 1.5. Key Design Principles

- **Rewrite from zero:** 100% new design, no legacy code carried over.
- **Open-source first:** Academic budget constraints; prioritize free and open-source tools.
- **Test-first:** Write tests before implementation.
- **Structured logging & connection pooling:** From day one, via PgBouncer and JSON logs.
- **Self-hosted:** Deployed on BKCS/HUST servers.
- **Agent-friendly:** Monorepo with context files for AI-assisted development.

---

## 2. Domain Model

The domain model is designed to closely reflect Vietnamese higher education terminology. Each entity maps 1:1 to a concept that instructors and students use daily.

### 2.1. Entity Hierarchy

| Entity | Vietnamese | Example | Description |
|---|---|---|---|
| Organization | Đơn vị | BKCS, SoICT | Top-level organizational unit: faculty, center, school |
| Course | Môn học | IT3080, IT4062 | Persists across semesters. Owns Problem Bank and Tags |
| Semester | Kỳ triển khai | IT3080-20252 | One specific run of a Course. Has lifecycle policies |
| Section | Lớp | L01, L02, L03 | Primary isolation boundary. Instructors + TAs + Students are assigned here |
| Problem | Bài toán | Bai 1: Linked List | A specific coding problem with test cases, deadline, belongs to Section |
| Submission | Bài nộp | SV A submits Bai 1 | Source code + grading results |

### 2.2. Entity Relationships

Organization → Course (many courses) → Semester (many semesters) → Section (many sections) → Problem (many problems) → Submission (many submissions).

- One Organization has many Courses
- One Course has many Semesters (each deployment is a Semester)
- One Semester has many Sections (classes)
- One Section has many Problems
- One Problem has many Submissions and many Test Cases
- Problem Bank belongs to Course, contains many Bank Problems (versioned)
- Tags belong to Course, many-to-many with Bank Problem
- Enrollment (Student) is attached directly to Section, not Semester

### 2.3. Terminology Definitions

| Term | Vietnamese | Definition in MageCode | Is NOT |
|---|---|---|---|
| Organization | Đơn vị | Highest-level org unit: faculty, center, school | Not a company or team |
| Course | Môn học | A course that persists across many semesters | Not a single class instance |
| Semester | Kỳ triển khai | One specific run of a Course. E.g., IT3080-20252 | Not the university-wide semester |
| Section | Lớp | A specific class within a Semester. E.g., L01, L02 | Not a problem group |
| Problem | Bài toán | A specific coding problem with test cases and deadline | Not an assignment or problem set |
| Submission | Bài nộp | One student submission of source code for one Problem | Not a draft |
| Problem Bank | Ngân hàng đề | Reusable problem repository, belongs to Course, versioned | Not the active problem list |
| Bank Problem | Đề trong bank | A problem template in the Bank, not yet assigned to any Section | Different from Problem (assigned) |
| group_label | Nhãn nhóm | Free-text label to group Problems. E.g., "Week 5", "Midterm Review" | Not a separate entity |

### 2.4. Problem Bank (Fork Model)

Problem Bank belongs to Course and operates on a fork model:

- Each bank entry is a Bank Problem with a version number
- When creating a Problem for a Section, Instructor chooses "Clone from bank" or "Create new"
- Cloned copies are fully independent — editing a Problem does not affect the bank, and vice versa
- "Publish to bank" creates a new version, does not overwrite the old one
- Bank keeps history: instructors in future semesters can choose a specific version to clone

**Bank Problem contains:** problem description (rich text + LaTeX), sample test cases, default difficulty (easy/medium/hard), tags (many-to-many, Course scope), allowed programming languages, default time/memory limits.

**When cloned into a Section as Problem:** all content is copied; difficulty can be overridden by instructor; tags follow without override; test cases can be independently edited; instructor adds `activation_time`, `lock_time`, `group_label`.

---

## 3. Roles & Permissions

### 3.1. Five Roles

| Role | Scope | Key Permissions |
|---|---|---|
| System Admin | Entire platform | Bootstrap and manage Organizations, assign Org Admins, platform configuration. Operates above the Organization scope |
| Organization Admin | Entire Organization | Manage Courses, full cross-section visibility, set Semester policies, manage Problem Bank approvals |
| Instructor | Assigned Sections | Create/edit Problems, trigger analysis, view submissions in own sections, clone from Problem Bank |
| Teaching Assistant | Assigned Sections | View submissions and CES execution results, assist grading. Optional per Section. Cannot create Problems, cannot trigger analysis, and cannot read analysis results (SIM/AID/VUL) |
| Student | Own Section | View problems (when published), submit code, view own results |

### 3.2. Isolation Model

**Section is the primary isolation boundary:**

- Instructor of L01 cannot see submissions from students in L02 and vice versa
- Student can only see their own Section, not other Sections
- Organization Admin is the only role with full cross-section visibility
- TA has the same visibility as Instructor but with more limited action permissions

### 3.3. Enrollment

Students are enrolled directly into a Section (not Semester). This reflects reality: students register for a specific class (L01, L02), not a generic course. Import via Excel/CSV per Section. Org Admin can view aggregate across all Sections in a Semester.

### 3.4. Problem Bank Access

- **Org Admin:** Full CRUD on bank for all Courses. Configure approval policies.
- **Instructor:** View and clone from bank of own Course. Publish new entries to bank.
- **TA / Student:** No access to bank.

**Publish workflow (D-25):** All Instructors teaching the same Course can see and clone Bank Problems. Org Admin configures per Course: `require_bank_approval` (boolean). If approval is on: Instructor publishes → status "pending" → Org Admin approves → appears in bank. If off: publish immediately. Each publish creates a new version; other instructors see only the latest version when cloning.

---

## 4. Cross-Section Similarity Detection

### 4.1. The Problem

Code plagiarism occurs most frequently between sections of the same course in the same semester, because students know each other and share identical problem statements. If the plagiarism checker only compares within a single section, the most common case is missed. However, giving instructors full access to another section's submissions would break the isolation model.

### 4.2. Solution: Two-Tier Similarity Results

SIM always runs comparison across the entire Semester (all sections with equivalent problems). Results are tagged with `match_type` and displayed in two tiers:

| Tier | Who Sees | Sees What | Does NOT See |
|---|---|---|---|
| Within-Section | Instructor | Both student names, both code sides, % similarity, highlight regions | — |
| Cross-Section | Instructor | Other student name, % similarity, own student's code + highlight | Other student's code |
| Full Detail | Org Admin | Everything: names, sections, both code sides | — |

- Instructor has a "Report to Org Admin" button for escalation when needed
- Display threshold: cross-section flag only shown when similarity ≥ configurable threshold (default 70%)
- `match_type` (`WITHIN_SECTION` / `CROSS_SECTION`) is assigned by the api service after SIM returns results, based on section membership of the two students

---

## 5. Problem Lifecycle & Visibility

### 5.1. Semester-Level Policy

Org Admin sets policy for the entire Semester:

| Field | Type | Description |
|---|---|---|
| `publish_mode` | enum: `auto` \| `manual` | Default mode for activation (showing problem to students) |
| `lock_mode` | enum: `auto` \| `manual` | Default mode for lock (closing submission) |
| `allow_publish_override` | boolean | Whether Instructor can override publish mode per Problem |
| `allow_lock_override` | boolean | Whether Instructor can override lock mode per Problem |

> **Example — Regular semester:** `publish_mode=auto`, `lock_mode=auto`, both `allow_override=true` → Instructor has full control.
>
> **Example — Final exam:** `allow_lock_override=false` → Instructor controls publish time (each section has different hours) but all must close simultaneously per `lock_time`.

### 5.2. Problem Fields

| Field | Type | Description |
|---|---|---|
| `activation_time` | datetime | When to show to students (if auto mode) |
| `lock_time` | datetime | When to close submissions (if auto mode) |
| `publish_mode_override` | `auto` \| `manual` \| null | Override publish mode. null = follow Semester |
| `lock_mode_override` | `auto` \| `manual` \| null | Override lock mode. Same logic |
| `group_label` | text | Free-text grouping: "Week 5", "Module 2: Data Structures" |
| `difficulty` | `easy` \| `medium` \| `hard` | Can be overridden from Bank Problem |
| `max_submissions` | integer | Limit number of submissions per student |

### 5.3. Visibility Logic

To determine the effective publish mode of a Problem:

- If `Semester.allow_publish_override = false` → use `Semester.publish_mode`
- If `allow_publish_override = true` AND `Problem.publish_mode_override != null` → use override
- Otherwise → use `Semester.publish_mode`

Same logic applies for lock mode. If mode = `auto`: student sees problem when `current_time >= activation_time`, cannot submit when `current_time >= lock_time`. If mode = `manual`: instructor manually publishes/locks.

---

## 6. System Architecture

### 6.1. Service Map & Tech Stack

| Service | Name | Language | Tech Stack |
|---|---|---|---|
| Management API | `api` | PHP 8.4+ | Laravel 13, PHP-FPM, Sanctum, Spatie Permission, Eloquent ORM |
| Frontend | `web` | TypeScript | Vue 3, Vite, shadcn-vue, Tailwind CSS, Monaco Editor |
| WebSocket | `reverb` | PHP | Laravel Reverb (separate process, not Octane) |
| Code Execution | `code-executor` | Go 1.26+ | sqlx + pgx, slog, Judge0 API |
| Plagiarism Check | `plagiarism-checker` | Go 1.26+ | sqlx + pgx, slog, Dolos CLI |
| AI Detection | `ai-detector` | Python 3.12+ | psycopg3, pika, structlog, PyTorch, Transformers |
| Vuln Scan | `vuln-scanner` | Go 1.26+ | sqlx + pgx, slog, CodeQL CLI |

### 6.2. Infrastructure Stack

| Component | Technology | Decision # | Notes |
|---|---|---|---|
| Database | PostgreSQL 16 + PgBouncer | D-19 | JSONB, CTE, excellent Go ecosystem support |
| Message Broker | RabbitMQ | — | Dedicated job queue for analysis pipeline |
| File Storage | MinIO (S3-compatible) | D-31 | Replaces FTP from v1.0; pre-signed URLs |
| API Gateway | Traefik | D-22 | Docker auto-discovery, K8s ready |
| WebSocket | Laravel Reverb | D-32 | Separate process, Pusher-compatible |
| Observability | Prometheus + Grafana + Loki | D-33 | Full stack monitoring from day one |
| Repository | Monorepo | D-23 | Agent context files, atomic changes |
| PHP Runtime | PHP-FPM (no Octane) | D-27 | Simple, stable; Reverb runs separately |

### 6.3. Go Services: Shared Packages (D-29)

Three Go services (`code-executor`, `plagiarism-checker`, `vuln-scanner`) share common packages in the monorepo. No frameworks — bare Go with shared utilities:

- `shared/go/rmq/` — RabbitMQ: connect, reconnect, consume, publish
- `shared/go/db/` — sqlx + pgx setup, PgBouncer-friendly config
- `shared/go/storage/` — MinIO S3 client: download/upload source code
- `shared/go/logger/` — slog wrapper with JSON handler + service name
- `shared/go/config/` — env loading, validation
- `shared/go/apperror/` — Custom error types, error propagation (no panic)

### 6.4. Data Flow

- **web ↔ api:** REST API (Sanctum auth) + WebSocket (Reverb)
- **api → analysis services:** RabbitMQ work queues, one queue per service
- **Analysis services → api:** Results via shared result queue
- **All services → DB:** Direct connections via PgBouncer
- **All services → storage:** Download source code from MinIO

### 6.5. Monorepo Structure (D-23)

```
magecode/
├── CLAUDE.md                  # Agent context file
├── docker-compose.yml
├── services/
│   ├── api/                   # Laravel 13 (PHP)
│   ├── web/                   # Vue 3 (TypeScript)
│   ├── code-executor/         # Go
│   ├── plagiarism-checker/    # Go
│   ├── ai-detector/           # Python
│   └── vuln-scanner/          # Go
├── shared/
│   ├── go/                    # Shared Go packages
│   └── schemas/               # JSON Schema for RabbitMQ messages
├── deploy/
│   ├── docker/
│   └── k8s/
└── docs/
```

---

## 7. Database Schema Design

All tables are designed from zero for the new domain model. PostgreSQL 16 with PgBouncer for connection pooling. All services share the same database with a configurable schema.

### 7.1. Core Tables

#### 7.1.1. `users`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | Auto-increment primary key |
| `username` | VARCHAR(50) | UNIQUE, NOT NULL | Login username |
| `email` | VARCHAR(255) | UNIQUE, NOT NULL | Email address |
| `password` | VARCHAR(255) | NOT NULL | Hashed password (bcrypt) |
| `first_name` | VARCHAR(100) | NOT NULL | First name |
| `last_name` | VARCHAR(100) | NOT NULL | Last name |
| `student_id` | VARCHAR(20) | NULLABLE, UNIQUE | University student ID (e.g., 20210001) |
| `avatar_path` | VARCHAR(500) | NULLABLE | Path to avatar image in MinIO |
| `email_verified_at` | TIMESTAMP | NULLABLE | When email was verified |
| `is_first_time_register` | BOOLEAN | DEFAULT false | First-time registration flag |
| `created_at` | TIMESTAMP | NOT NULL | Record creation time |
| `updated_at` | TIMESTAMP | NOT NULL | Last update time |

#### 7.1.2. `organizations`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `name` | VARCHAR(255) | NOT NULL | Organization name |
| `description` | TEXT | NULLABLE | Rich text description |
| `email` | VARCHAR(255) | NULLABLE | Contact email |
| `avatar_path` | VARCHAR(500) | NULLABLE | Logo path in MinIO |
| `creator_id` | BIGINT | FK → `users.id` | Who created this org |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

#### 7.1.3. `courses`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `organization_id` | BIGINT | FK → `organizations.id` | Parent organization |
| `code` | VARCHAR(20) | NOT NULL | Course code, e.g., IT3080 |
| `name` | VARCHAR(255) | NOT NULL | Course name |
| `description` | TEXT | NULLABLE | Course description |
| `require_bank_approval` | BOOLEAN | DEFAULT false | D-25: require approval for bank publish |
| `creator_id` | BIGINT | FK → `users.id` | Who created this course |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

#### 7.1.4. `semesters`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `course_id` | BIGINT | FK → `courses.id` | Parent course |
| `name` | VARCHAR(100) | NOT NULL | e.g., 20252 (year + semester) |
| `description` | TEXT | NULLABLE | Semester description |
| `publish_mode` | VARCHAR(10) | DEFAULT `'auto'` | Default problem publish mode |
| `lock_mode` | VARCHAR(10) | DEFAULT `'auto'` | Default problem lock mode |
| `allow_publish_override` | BOOLEAN | DEFAULT true | GV can override publish mode |
| `allow_lock_override` | BOOLEAN | DEFAULT true | GV can override lock mode |
| `start_date` | DATE | NULLABLE | Semester start date |
| `end_date` | DATE | NULLABLE | Semester end date |
| `similarity_threshold` | DECIMAL(3,2) | DEFAULT 0.70 | SIM alert threshold (D-62) |
| `ai_detection_threshold` | DECIMAL(3,2) | DEFAULT 0.80 | AID alert threshold (D-62) |
| `creator_id` | BIGINT | FK → `users.id` | Who created this semester |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

#### 7.1.5. `sections`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `semester_id` | BIGINT | FK → `semesters.id` | Parent semester |
| `name` | VARCHAR(50) | NOT NULL | e.g., L01, L02 |
| `description` | TEXT | NULLABLE | Section description |
| `creator_id` | BIGINT | FK → `users.id` | Who created this section |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

#### 7.1.6. `problems`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `section_id` | BIGINT | FK → `sections.id` | Parent section |
| `bank_problem_id` | BIGINT | FK → `bank_problems.id`, NULLABLE | Source bank problem (if cloned) |
| `creator_id` | BIGINT | FK → `users.id` | Problem creator |
| `name` | VARCHAR(255) | NOT NULL | Problem title |
| `description` | TEXT | NOT NULL | Rich text + LaTeX description |
| `difficulty` | VARCHAR(10) | DEFAULT `'medium'` | `easy` \| `medium` \| `hard` |
| `group_label` | VARCHAR(100) | NULLABLE | Free-text grouping label |
| `order` | INTEGER | NULLABLE | Display order within group |
| `max_submissions` | INTEGER | NULLABLE | Max submissions per student |
| `time_limit` | INTEGER | NOT NULL | Execution time limit (ms) |
| `memory_limit` | INTEGER | NOT NULL | Memory limit (KB) |
| `activation_time` | TIMESTAMP | NULLABLE | When visible to students (auto mode) |
| `lock_time` | TIMESTAMP | NULLABLE | When submissions close (auto mode) |
| `publish_mode_override` | VARCHAR(10) | NULLABLE | Override: `auto` \| `manual` \| null |
| `lock_mode_override` | VARCHAR(10) | NULLABLE | Override: `auto` \| `manual` \| null |
| `is_published` | BOOLEAN | DEFAULT false | Manual publish flag |
| `is_locked` | BOOLEAN | DEFAULT false | Manual lock flag |
| `manual_match_group_id` | UUID | NULLABLE | D-58: manual cross-section matching for SIM. Only set when problem has no `bank_problem_id` |
| `testcases_updated_at` | TIMESTAMP | NULLABLE | D-41: last time test cases were changed |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |
| `deleted_at` | TIMESTAMP | NULLABLE | D-43: soft delete |

#### 7.1.7. `submissions`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `problem_id` | BIGINT | FK → `problems.id` | Which problem |
| `creator_id` | BIGINT | FK → `users.id` | Who submitted |
| `programming_language_id` | BIGINT | FK → `programming_languages.id` | Language used |
| `file_path` | VARCHAR(500) | NOT NULL | Source code path in MinIO |
| `file_name` | VARCHAR(255) | NOT NULL | Original filename |
| `execution_status` | VARCHAR(30) | DEFAULT `'in_queue'` | Overall execution status |
| `testcases_passed` | INTEGER | DEFAULT 0 | Number of test cases passed |
| `testcases_total` | INTEGER | DEFAULT 0 | Total test cases |
| `is_outdated` | BOOLEAN | DEFAULT false | D-41: true when test cases changed after submission |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

### 7.2. Test Cases & Programming Languages

#### 7.2.1. `test_cases`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `problem_id` | BIGINT | FK → `problems.id` | Parent problem |
| `input` | TEXT | NOT NULL | Test input (stdin) |
| `expected_output` | TEXT | NOT NULL | Expected output |
| `is_active` | BOOLEAN | DEFAULT true | Used for grading? |
| `is_visible` | BOOLEAN | DEFAULT false | Shown to students? |
| `order` | INTEGER | DEFAULT 0 | Display order |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

#### 7.2.2. `programming_languages`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `name` | VARCHAR(50) | NOT NULL | Language name (e.g., Python, Java, C++) |
| `version` | VARCHAR(20) | NULLABLE | Language version |
| `judge0_id` | INTEGER | NOT NULL | Judge0 language ID |
| `monaco_language` | VARCHAR(30) | NOT NULL | Monaco editor language key |
| `dolos_language` | VARCHAR(30) | NULLABLE | Dolos parser language key |
| `codeql_language` | VARCHAR(30) | NULLABLE | CodeQL language key |
| `file_extensions` | JSONB | NOT NULL | Accepted source extensions, no leading dot (U-4). First entry names the file when a submission arrives without one |

### 7.3. Problem Bank Tables

#### 7.3.1. `bank_problems`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `course_id` | BIGINT | FK → `courses.id` | Parent course |
| `author_id` | BIGINT | FK → `users.id` | Original author |
| `name` | VARCHAR(255) | NOT NULL | Problem title |
| `description` | TEXT | NOT NULL | Rich text + LaTeX |
| `difficulty` | VARCHAR(10) | DEFAULT `'medium'` | Default difficulty |
| `time_limit` | INTEGER | NOT NULL | Default time limit (ms) |
| `memory_limit` | INTEGER | NOT NULL | Default memory limit (KB) |
| `version` | INTEGER | DEFAULT 1 | Version number |
| `status` | VARCHAR(20) | DEFAULT `'approved'` | `pending` \| `approved` \| `rejected` (D-25) |
| `original_id` | BIGINT | FK → `bank_problems.id`, NULLABLE | D-63: groups versions. NULL = this is the original |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |
| `deleted_at` | TIMESTAMP | NULLABLE | D-67: soft delete |

#### 7.3.2. `bank_problem_test_cases`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `bank_problem_id` | BIGINT | FK → `bank_problems.id` | Parent bank problem |
| `input` | TEXT | NOT NULL | Test input |
| `expected_output` | TEXT | NOT NULL | Expected output |
| `is_active` | BOOLEAN | DEFAULT true | Used for grading? |
| `is_visible` | BOOLEAN | DEFAULT false | Shown to students? |
| `order` | INTEGER | DEFAULT 0 | Display order |

### 7.4. Analysis Tables

#### 7.4.1. `analysis_problems`

One analysis batch. Scope is **Semester-level** for SIM (cross-section comparison). Equivalence determined by exactly one of `bank_problem_id` (auto) or `manual_match_group_id` (manual) — `chk_analysis_scope` rejects a row carrying both or neither. A problem with no scope yet gets a one-problem match group generated at trigger time.

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `semester_id` | BIGINT | FK → `semesters.id` | Scope: which semester |
| `bank_problem_id` | BIGINT | FK → `bank_problems.id`, NULLABLE | Auto-match: all problems with same bank_problem_id |
| `manual_match_group_id` | UUID | NULLABLE | Manual-match: all problems with same UUID (D-58) |
| `triggered_by_problem_id` | BIGINT | FK → `problems.id` | Which problem triggered this analysis |
| `analyst_id` | BIGINT | FK → `users.id` | Who triggered analysis |
| `services` | JSONB | NOT NULL | Array of service identifiers enabled |
| `status` | VARCHAR(20) | DEFAULT `'processing'` | `processing` \| `completed` \| `timeout` |
| `is_latest` | BOOLEAN | DEFAULT true | D-53: only 1 latest per scope |
| `is_partial` | BOOLEAN | DEFAULT false | D-57: triggered on unlocked problem |
| `started_at` | TIMESTAMP | NULLABLE | When analysis started |
| `completed_at` | TIMESTAMP | NULLABLE | When all services completed |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

#### 7.4.2. `analysis_submissions`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `submission_id` | BIGINT | FK → `submissions.id` | The submission being analyzed |
| `analysis_problem_id` | BIGINT | FK → `analysis_problems.id` | Parent analysis batch |
| `plagiarism_status` | VARCHAR(20) | DEFAULT `'in_queue'` | SIM service status |
| `ai_detection_status` | VARCHAR(20) | DEFAULT `'in_queue'` | AID service status |
| `vuln_scan_status` | VARCHAR(20) | DEFAULT `'in_queue'` | VUL service status |
| `started_at` | TIMESTAMP | NULLABLE | |
| `completed_at` | TIMESTAMP | NULLABLE | |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

#### 7.4.3. `code_execution_results`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `submission_id` | BIGINT | FK → `submissions.id` | Which submission |
| `test_case_id` | BIGINT | FK → `test_cases.id` | Which test case |
| `status` | VARCHAR(30) | NOT NULL | `accepted` \| `wrong_answer` \| `time_limit_exceeded` \| etc. |
| `actual_output` | TEXT | NULLABLE | Program output |
| `consumed_time_ms` | DECIMAL | NULLABLE | Execution time in ms |
| `consumed_memory_kb` | INTEGER | NULLABLE | Memory used in KB |
| `error_content` | TEXT | NULLABLE | Compilation/runtime error message, truncated 5000 chars (D-38) |
| `created_at` | TIMESTAMP | NOT NULL | |

#### 7.4.4. `similarity_results`

Pairwise similarity from SIM (Dolos). Written by **api** from `result-analysis` queue (D-80). **1 row per ordered pair** (`submission_a_id < submission_b_id`).

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `analysis_problem_id` | BIGINT | FK → `analysis_problems.id`, CASCADE | Parent analysis batch |
| `submission_a_id` | BIGINT | FK → `submissions.id` | Ordered: always < `submission_b_id` |
| `submission_b_id` | BIGINT | FK → `submissions.id` | Ordered: always > `submission_a_id` |
| `similarity` | DECIMAL(5,4) | NOT NULL | Similarity score 0.0000–1.0000 |
| `longest_fragment` | INTEGER | NULLABLE | Longest matching fragment (tokens) |
| `total_overlap` | INTEGER | NULLABLE | Total overlapping tokens |
| `match_type` | VARCHAR(20) | NOT NULL | `WITHIN_SECTION` \| `CROSS_SECTION` |
| `a_regions` | TEXT | NULLABLE | Highlight regions for submission A (pipe-separated: startRow,startCol,endRow,endCol) |
| `b_regions` | TEXT | NULLABLE | Highlight regions for submission B |
| `created_at` | TIMESTAMP | NOT NULL | |

#### 7.4.5. `ai_detection_results`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `analysis_submission_id` | BIGINT | FK → `analysis_submissions.id` | Which analysis submission |
| `probability` | DECIMAL(5,4) | NOT NULL | AI-generated probability 0.0000–1.0000 |
| `created_at` | TIMESTAMP | NOT NULL | |

#### 7.4.6. `vulnerability_results`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `analysis_submission_id` | BIGINT | FK → `analysis_submissions.id` | Which analysis submission |
| `name` | VARCHAR(255) | NOT NULL | Vulnerability name |
| `description` | TEXT | NULLABLE | Vulnerability description |
| `severity` | VARCHAR(20) | NOT NULL | `recommendation` \| `warning` \| `error` |
| `file_path` | VARCHAR(500) | NULLABLE | File path where found |
| `start_line` | INTEGER | NULLABLE | Start line number |
| `start_column` | INTEGER | NULLABLE | Start column |
| `end_line` | INTEGER | NULLABLE | End line number |
| `end_column` | INTEGER | NULLABLE | End column |
| `created_at` | TIMESTAMP | NOT NULL | |

### 7.5. Membership & Pivot Tables

#### 7.5.1. `organization_members`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `organization_id` | BIGINT | FK → `organizations.id` | |
| `user_id` | BIGINT | FK → `users.id` | |
| `role` | VARCHAR(20) | NOT NULL | `admin` \| `instructor` |
| `added_by` | BIGINT | FK → `users.id`, NULLABLE | Who added this member |
| `created_at` | TIMESTAMP | NOT NULL | |

#### 7.5.2. `section_members`

| Column | Type | Constraints | Description |
|---|---|---|---|
| `id` | BIGSERIAL | PK | |
| `section_id` | BIGINT | FK → `sections.id` | |
| `user_id` | BIGINT | FK → `users.id` | |
| `role` | VARCHAR(20) | NOT NULL | `instructor` \| `ta` \| `student` |
| `added_by` | BIGINT | FK → `users.id`, NULLABLE | Who enrolled this member |
| `created_at` | TIMESTAMP | NOT NULL | |

#### 7.5.3. Other Pivot Tables

| Table | Links | Extra Columns |
|---|---|---|
| `problem_programming_languages` | `problems` ↔ `programming_languages` | — |
| `bank_problem_programming_languages` | `bank_problems` ↔ `programming_languages` | — |
| `tags` | Standalone (belongs to Course) | `course_id`, `name`, `color` |
| `bank_problem_tags` | `bank_problems` ↔ `tags` | — |
| `problem_tags` | `problems` ↔ `tags` | — |

### 7.6. Supporting Tables

| Table | Purpose |
|---|---|
| `notifications` | In-app notifications (polymorphic) |
| `password_reset_tokens` | Password reset flow |
| `sessions` | Laravel session management |
| `cache` / `cache_locks` | Laravel cache tables |
| `jobs` / `job_batches` / `failed_jobs` | Laravel queue tables (internal jobs only) |

---

## 8. RabbitMQ Message Schemas

All messages use JSON format with persistent delivery mode. Service identifiers use string enum (D-26): `"code-executor"`, `"plagiarism-checker"`, `"ai-detector"`, `"vuln-scanner"`. All messages include `trace_id` for cross-service tracing (D-88).

### 8.1. Queue Map (6 Queues per D-83)

| Queue Name | Producer | Consumer | Message Pattern |
|---|---|---|---|
| `code-executor` | api | code-executor | 1 message per submission (ID only, D-84) |
| `plagiarism-checker` | api | plagiarism-checker | 1 message with all submission `file_url`s (batch) |
| `ai-detector` | api | ai-detector | 1 message per analysis submission (full payload, D-84) |
| `vuln-scanner` | api | vuln-scanner | 1 message per analysis submission (full payload, D-84) |
| `result-execution` | code-executor | api | 1 message per completed execution (D-83) |
| `result-analysis` | SIM/AID/VUL | api | 1 message per completed analysis task + full result data (D-83) |

### 8.2. Message Schemas

#### 8.2.1. `code-executor` queue (D-84: ID only, CES has DB access)

```json
{
  "submission_id": 123,
  "trace_id": "abc-123-def",
  "timestamp": "2026-03-16T10:00:00Z"
}
```

#### 8.2.2. `plagiarism-checker` queue (D-84: full payload, stateless)

```json
{
  "analysis_problem_id": 45,
  "submissions": [
    {"submission_id": 101, "file_url": "https://minio:9000/...", "language": "python"},
    {"submission_id": 102, "file_url": "https://minio:9000/...", "language": "python"}
  ],
  "compared_submissions": [
    {"submission_id": 201, "file_url": "https://minio:9000/...", "language": "python"}
  ],
  "trace_id": "abc-123-def",
  "timestamp": "2026-03-16T10:00:00Z"
}
```

> Note: `compared_submissions` includes submissions from other sections in the semester (cross-section comparison). `file_url` values are MinIO pre-signed URLs with 6-hour expiry (D-85).

#### 8.2.3. `ai-detector` queue (D-84: full payload, stateless)

```json
{
  "analysis_submission_id": 789,
  "file_url": "https://minio:9000/...",
  "language": "python",
  "trace_id": "abc-123-def",
  "timestamp": "2026-03-16T10:00:00Z"
}
```

#### 8.2.4. `vuln-scanner` queue (D-84: full payload, stateless)

```json
{
  "analysis_submission_id": 789,
  "file_url": "https://minio:9000/...",
  "language": "cpp",
  "trace_id": "abc-123-def",
  "timestamp": "2026-03-16T10:00:00Z"
}
```

#### 8.2.5. `result-execution` queue (D-83: CES results)

```json
{
  "submission_id": 123,
  "service": "code-executor",
  "status": "completed",
  "trace_id": "abc-123-def",
  "timestamp": "2026-03-16T10:00:15Z"
}
```

> CES writes results directly to DB (D-81). This message only notifies api for WebSocket push to student.

#### 8.2.6. `result-analysis` queue (D-83: SIM/AID/VUL results)

```json
{
  "analysis_submission_id": 789,
  "service": "ai-detector",
  "status": "completed",
  "results": { "probability": 0.8521 },
  "trace_id": "abc-123-def",
  "timestamp": "2026-03-16T10:01:30Z"
}
```

> Stateless workers (D-80) include full result data. api writes results to DB and pushes WebSocket to instructor.

### 8.3. Status Values

| Status | Description |
|---|---|
| `in_queue` | Message published, not yet consumed |
| `processing` | Service has started processing |
| `completed` | Successfully processed, results written |
| `failed` | Processing error occurred |
| `timeout` | Service did not respond within 30 min (D-82) |
| `language_not_supported` | The programming language is not supported by this service |

---

## 9. Analysis Pipeline Workflow

### 9.1. Submission Flow (CES — Real-time Path)

Code execution runs automatically when a student submits code:

1. Student submits source code via web (Monaco editor or file upload)
2. api validates submission (deadline, `max_submissions`, allowed languages)
3. api uploads source code to MinIO, creates Submission record
4. api publishes message to `code-executor` queue (ID only, D-84)
5. code-executor queries DB for test cases, downloads code from MinIO, runs via Judge0 API
6. code-executor writes `code_execution_results` per test case to DB directly (D-81)
7. code-executor publishes completion to `result-execution` queue (D-83)
8. api consumes result, pushes real-time update via Reverb to student

### 9.2. Analysis Flow (SIM / AID / VUL — Batch Path)

Analysis is triggered manually by Instructor or Org Admin after the problem is locked:

1. Instructor/Org Admin calls API to trigger analysis for a Problem
2. api creates `analysis_problems` record (Semester-level scope, D-48)
3. api retrieves the latest submission per student for all equivalent problems in Semester
4. For each submission, creates `analysis_submission` record
5. api generates pre-signed MinIO URLs (6h expiry, D-85) and publishes messages to RabbitMQ per selected services:
   - **SIM:** 1 message with all submission `file_url`s (pairwise comparison across entire Semester)
   - **AID:** 1 message per submission with `file_url`
   - **VUL:** 1 message per submission with `file_url` (if selected)
6. Each stateless service (D-80): download code via pre-signed URL → process → publish results + data to `result-analysis` queue
7. api consumes `result-analysis` queue: **writes results to DB**, updates status, checks batch completion
8. When all done → push real-time update via Reverb to instructor

### 9.3. Default Analysis Selection

- **SIM (plagiarism-checker):** Checked by default
- **AID (ai-detector):** Checked by default
- **VUL (vuln-scanner):** Unchecked by default (D-24: optional, not all courses need SAST)

---

## 10. Lessons from v1.0

Version 1.0 is not refactored but referenced as a source of lessons:

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

## 11. Decision Log

All 33 architectural and design decisions have been finalized. The tech stack is complete and ready for Phase 1 implementation.

| # | Decision | Conclusion | Rationale |
|---|---|---|---|
| D-01 | Number of products | 2: Education + Community | Avoid generalization trap |
| D-02 | Deployment priority | Education first | Target users at BKCS/HUST |
| D-03 | Entity hierarchy | Org→Course→Semester→Section→Problem | Match Vietnamese education model |
| D-04 | Isolation boundary | Section is primary isolation | Instructor L01 cannot see L02 |
| D-05 | Cross-section similarity | Two-tier: within (full) + cross (partial) | Balance privacy and anti-plagiarism |
| D-06 | Cross visibility for GV | Partial: student name + % + own code | Sufficient info without breaking isolation |
| D-07 | Problem Bank | Fork model, version history | Avoid data drift |
| D-08 | Enrollment | Attach to Section | Students register for specific classes |
| D-09 | UX navigation | Dashboard per role | Student: 1 click, Instructor: 2 clicks |
| D-10 | Laravel version | Laravel 13 | Latest version |
| D-11 | Cost | Open-source, free tier priority | Academic budget constraints |
| D-12 | Roles | 5: System Admin, Org Admin, Instructor, TA, Student | Reflects reality (amended 2026-08-10) |
| D-13 | TA role | Optional per Section | Not every section has a TA |
| D-14 | Problem metadata | `group_label` + difficulty + time | Group by week, classify difficulty |
| D-15 | Tags | Course scope, M2M with Bank Problem | Filter/search Problem Bank |
| D-16 | Problem visibility | Org Admin sets policy, GV overrides if allowed | Flexible, admin-controlled |
| D-17 | Service naming | No prefix, self-explanatory names | Clean, consistent |
| D-18 | Backend languages | PHP + Go + Python | PHP for API, Go for workers, Python for ML |
| D-19 | Database | PostgreSQL 16 + PgBouncer | JSONB, CTE, Go ecosystem |
| D-20 | Entity naming | Assignment → Problem | Industry standard, clear meaning |
| D-21 | Top-level entity | Department → Organization | More generic, multi-unit support |
| D-22 | API Gateway | Traefik | Auto-discovery, Docker+K8s |
| D-23 | Repo structure | Monorepo | Agent-friendly, atomic changes |
| D-24 | VUL service | Optional, off by default | Not all courses need SAST |
| D-25 | Bank visibility | All GV in same Course, with approval | Share resources, admin control |
| D-26 | Service identifiers | String enum, not numbers | Self-explanatory, easy debug |
| D-27 | PHP runtime | PHP-FPM (no Octane) | Simple, stable; Reverb separate |
| D-28 | Frontend stack | Vue 3 + shadcn-vue + Tailwind | Own source, lightweight, agent-friendly |
| D-29 | Go services | Bare Go + shared pkg, sqlx+pgx, slog | Lightweight, no framework, stdlib logging |
| D-30 | Python ai-detector | psycopg3 + pika + structlog | Connection pooling built-in, JSON logs |
| D-31 | File storage | MinIO (S3-compatible) | S3 standard, ready to scale |
| D-32 | WebSocket | Laravel Reverb | Laravel integration, Pusher-compatible |
| D-33 | Observability | Prometheus + Grafana + Loki | Full stack, setup from day one |

---

## 12. Implementation Roadmap

Five-phase roadmap. Rewrite from zero. Each phase has an independent deliverable.

### Phase 1: Technical Design (Current)

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
- New api service: Auth, RBAC (5 roles), CRUD entities, import students
- New web service: Login, role-based dashboard, CRUD UI
- New code-executor: Go, Judge0 integration, standardized error handling
- Submission flow: Submit → Judge0 → real-time results

**Deliverable:** Students can submit code and see grading results

### Phase 3: Analysis Pipeline

**Goal:** Complete remaining 3 analysis services.

- New plagiarism-checker: Go, Dolos CLI, cross-section comparison, `match_type`
- New ai-detector: Python, CodeBERT, PgBouncer connection pooling, structured logging
- New vuln-scanner: Go, CodeQL integration
- api: Analysis orchestration, two-tier result display by role
- web: Analysis UI, similarity visualization (D3.js), cross-section results

**Deliverable:** Instructors can trigger and view all 4 analysis types

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
