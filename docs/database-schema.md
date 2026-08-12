# MageCode 2.0 — Database Schema Design v2.0

> **Version**: 2.0 — 18/03/2026
> **Authors**: Gideon & Claude (Anthropic)
> **Stack**: Laravel 13 migrations on PostgreSQL 16 + PgBouncer
> **Status**: Phase 1 Deliverable — Complete Schema Specification

---

## 1. Overview

### 1.1. Design Principles

- **Designed from zero** — v1.0 schema is reference only, not a starting point.
- **Laravel 13 conventions** throughout: `$table->id()`, `$table->timestamps()`, `$table->softDeletes()`, `foreignId()->constrained()`.
- **PostgreSQL 16 features**: JSONB for flexible data, UUID for group identifiers, DECIMAL for precision scores, partial unique indexes.
- **FK RESTRICT by default** — data preservation in education context. CASCADE only where explicitly noted.
- **Soft deletes** only on `problems` (D-43) and `bank_problems` (D-67).
- **String enum status fields** — VARCHAR not INT (D-26). Values are snake_case lowercase.
- **Indexes designed for 500 concurrent students** (D-75).

### 1.2. Naming Conventions

| Convention | Rule | Example |
|---|---|---|
| Table names | Plural, snake_case | `users`, `bank_problems`, `similarity_results` |
| Column names | snake_case | `programming_language_id`, `is_published` |
| Primary key | `$table->id()` | `id BIGINT UNSIGNED AUTO_INCREMENT` |
| Foreign key | `$table->foreignId('x_id')->constrained()` | Auto-references `x` table |
| Timestamps | `$table->timestamps()` on all mutable tables | `created_at`, `updated_at` |
| Soft deletes | `$table->softDeletes()` | `deleted_at TIMESTAMP NULLABLE` |
| Boolean flags | Prefix `is_` or `has_` | `is_published`, `is_locked`, `is_active` |
| Status fields | VARCHAR with string enum | `in_queue`, `processing`, `completed` |
| JSONB | Used sparingly | Only `analysis_problems.services` |
| Composite indexes | Named `idx_{table}_{columns}` | `idx_submissions_problem_creator` |

### 1.3. Table Summary (35 tables)

| Category | Tables | Count |
|---|---|---|
| Core Entities | users, organizations, courses, semesters, sections, problems, submissions | 7 |
| Test & Languages | test_cases, programming_languages | 2 |
| Problem Bank | bank_problems, bank_problem_test_cases | 2 |
| Analysis | analysis_problems, analysis_submissions, code_execution_results, similarity_results, ai_detection_results, vulnerability_results | 6 |
| Membership | organization_members, section_members | 2 |
| Tags & Pivots | tags, problem_programming_languages, bank_problem_programming_languages, bank_problem_tags, problem_tags | 5 |
| Audit | problem_edit_logs, section_transfer_logs | 2 |
| Notifications | notifications | 1 |
| Laravel Framework | password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs, personal_access_tokens | 8 |
| **TOTAL** | | **35** |

### 1.4. FK Cascade Policy

Default: **RESTRICT** — block parent deletion if child records exist.

| Parent | Child | On Delete | Rationale |
|---|---|---|---|
| organizations | courses | RESTRICT | Must clean up courses first |
| courses | semesters | RESTRICT | Preserve academic history |
| semesters | sections | RESTRICT | Sections contain student data |
| sections | problems | RESTRICT | Problems have submissions |
| problems | submissions | RESTRICT | Never delete submissions (D-52) |
| problems | test_cases | **CASCADE** | Test cases belong to problem |
| bank_problems | bank_problem_test_cases | **CASCADE** | Test cases belong to bank entry |
| bank_problems (original_id) | bank_problems (versions) | **RESTRICT** | Must handle versions before deleting original |
| analysis_problems | analysis_submissions | **CASCADE** | Delete batch deletes entries |
| analysis_problems | similarity_results | **CASCADE** | Delete batch deletes results |
| users | all FK references | RESTRICT | Never orphan records |

### 1.5. Important Design Notes (for AI agents)

- **CES (code-executor) writes directly to DB**: `code_execution_results` and `submissions.execution_status`, `testcases_passed`, `testcases_total`. CES **MUST** use upsert: `INSERT ... ON CONFLICT (submission_id, test_case_id) DO UPDATE SET ...` (D-81, Issue #4).
- **SIM/AID/VUL are stateless**: They do NOT access DB. Results come via RabbitMQ `result-analysis` queue. **api** writes all results for these services (D-80).
- **SIM scope = Semester-level**: `analysis_problems` for SIM covers ALL equivalent problems across sections, not just the triggering problem (Phương án B).
- **Force delete Problem is NEVER allowed** if submissions exist. Application guard required.
- **Submissions are NEVER deleted** (D-52). No soft delete on submissions.
- **analysis_submissions per-service status columns are intentional tech debt**. If a 5th analysis service is added in v3.0, refactor to `analysis_service_statuses` table.

---

## 2. Core Entity Tables

### 2.1. users

All authenticated users. Roles are assigned through membership tables, not on this table.

**Eloquent Model**: `App\Models\User` — uses `HasFactory`, `Notifiable`. No `SoftDeletes`.

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('username', 50)->unique();
    $table->string('email')->unique();
    $table->string('password');
    $table->string('first_name', 100);
    $table->string('last_name', 100);
    $table->string('student_id', 20)->unique()->nullable();
    $table->string('avatar_path', 500)->nullable();
    $table->timestamp('email_verified_at')->nullable();
    $table->boolean('is_first_time_register')->default(false);
    $table->timestamps();
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK, auto-increment | Unsigned BIGINT |
| username | `string('username', 50)` | UNIQUE, NOT NULL | Login username |
| email | `string('email')` | UNIQUE, NOT NULL | Email address |
| password | `string('password')` | NOT NULL | Bcrypt hashed |
| first_name | `string('first_name', 100)` | NOT NULL | |
| last_name | `string('last_name', 100)` | NOT NULL | |
| student_id | `string('student_id', 20)` | UNIQUE, NULLABLE | University ID, e.g. 20210001 |
| avatar_path | `string('avatar_path', 500)` | NULLABLE | MinIO path |
| email_verified_at | `timestamp(...)` | NULLABLE | |
| is_first_time_register | `boolean(...)` | DEFAULT false | Onboarding flag |
| created_at | `timestamps()` | NOT NULL | |
| updated_at | `timestamps()` | NOT NULL | |

### 2.2. organizations

Top-level organizational unit: faculty, center, school. E.g., BKCS, SoICT.

**Eloquent Model**: `App\Models\Organization`

```php
Schema::create('organizations', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('email')->nullable();
    $table->string('avatar_path', 500)->nullable();
    $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
    $table->timestamps();
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| name | `string('name')` | NOT NULL | Organization name |
| description | `text('description')` | NULLABLE | Rich text |
| email | `string('email')` | NULLABLE | Contact email |
| avatar_path | `string('avatar_path', 500)` | NULLABLE | Logo in MinIO |
| creator_id | `foreignId('creator_id')` | FK → users.id, RESTRICT | Who created |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |

### 2.3. courses

A course persisting across semesters. Owns Problem Bank and Tags. E.g., IT3080.

**Eloquent Model**: `App\Models\Course`

```php
Schema::create('courses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->restrictOnDelete();
    $table->string('code', 20);
    $table->string('name');
    $table->text('description')->nullable();
    $table->boolean('require_bank_approval')->default(false);
    $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
    $table->timestamps();

    $table->unique(['organization_id', 'code']);
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| organization_id | `foreignId(...)` | FK → organizations.id, RESTRICT | Parent org |
| code | `string('code', 20)` | NOT NULL | Course code: IT3080 |
| name | `string('name')` | NOT NULL | Course name |
| description | `text(...)` | NULLABLE | |
| require_bank_approval | `boolean(...)` | DEFAULT false | D-25 |
| creator_id | `foreignId('creator_id')` | FK → users.id, RESTRICT | |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |

**Indexes**: `UNIQUE(organization_id, code)` — course code unique per org.

### 2.4. semesters

One specific run of a Course. E.g., IT3080-20252. Contains policies for problem visibility (D-16).

**Eloquent Model**: `App\Models\Semester`

```php
Schema::create('semesters', function (Blueprint $table) {
    $table->id();
    $table->foreignId('course_id')->constrained()->restrictOnDelete();
    $table->string('name', 100);
    $table->text('description')->nullable();
    $table->string('publish_mode', 10)->default('auto');
    $table->string('lock_mode', 10)->default('auto');
    $table->boolean('allow_publish_override')->default(true);
    $table->boolean('allow_lock_override')->default(true);
    $table->decimal('similarity_threshold', 3, 2)->default(0.70);
    $table->decimal('ai_detection_threshold', 3, 2)->default(0.80);
    $table->date('start_date')->nullable();
    $table->date('end_date')->nullable();
    $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
    $table->timestamps();

    $table->unique(['course_id', 'name']);
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| course_id | `foreignId(...)` | FK → courses.id, RESTRICT | Parent course |
| name | `string('name', 100)` | NOT NULL | E.g., 20252 (YYYYS) |
| description | `text(...)` | NULLABLE | |
| publish_mode | `string(..., 10)` | DEFAULT 'auto' | auto \| manual (D-16) |
| lock_mode | `string(..., 10)` | DEFAULT 'auto' | auto \| manual (D-16) |
| allow_publish_override | `boolean(...)` | DEFAULT true | GV can override per Problem |
| allow_lock_override | `boolean(...)` | DEFAULT true | GV can override per Problem |
| similarity_threshold | `decimal(..., 3, 2)` | DEFAULT 0.70 | SIM alert threshold (D-62) |
| ai_detection_threshold | `decimal(..., 3, 2)` | DEFAULT 0.80 | AID alert threshold (D-62) |
| start_date | `date(...)` | NULLABLE | Semester start |
| end_date | `date(...)` | NULLABLE | Semester end |
| creator_id | `foreignId('creator_id')` | FK → users.id, RESTRICT | |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |

**Indexes**: `UNIQUE(course_id, name)` — semester name unique per course.

**publish_mode / lock_mode values**: `auto`, `manual`

### 2.5. sections

A specific class within a Semester. Primary isolation boundary (D-04).

**Eloquent Model**: `App\Models\Section`

```php
Schema::create('sections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('semester_id')->constrained()->restrictOnDelete();
    $table->string('name', 50);
    $table->text('description')->nullable();
    $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
    $table->timestamps();

    $table->unique(['semester_id', 'name']);
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| semester_id | `foreignId(...)` | FK → semesters.id, RESTRICT | Parent semester |
| name | `string('name', 50)` | NOT NULL | E.g., L01, L02 |
| description | `text(...)` | NULLABLE | |
| creator_id | `foreignId('creator_id')` | FK → users.id, RESTRICT | |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |

**Indexes**: `UNIQUE(semester_id, name)` — section name unique per semester.

### 2.6. problems

A coding problem assigned to a Section. May be cloned from Bank Problem. Supports soft delete (D-43).

**Eloquent Model**: `App\Models\Problem` — uses `SoftDeletes`.

**IMPORTANT for AI agents**: Scoped queries MUST always include `section_id` context for isolation (D-04). Force delete is NEVER allowed if submissions exist.

```php
Schema::create('problems', function (Blueprint $table) {
    $table->id();
    $table->foreignId('section_id')->constrained()->restrictOnDelete();
    $table->foreignId('bank_problem_id')->nullable()->constrained('bank_problems')->nullOnDelete();
    $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
    $table->string('name');
    $table->text('description');
    $table->string('difficulty', 10)->default('medium');
    $table->string('group_label', 100)->nullable();
    $table->integer('order')->nullable();
    $table->integer('max_submissions')->nullable();
    $table->integer('time_limit');
    $table->integer('memory_limit');
    $table->timestamp('activation_time')->nullable();
    $table->timestamp('lock_time')->nullable();
    $table->string('publish_mode_override', 10)->nullable();
    $table->string('lock_mode_override', 10)->nullable();
    $table->boolean('is_published')->default(false);
    $table->boolean('is_locked')->default(false);
    $table->uuid('manual_match_group_id')->nullable();
    $table->timestamp('testcases_updated_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index('section_id');
    $table->index(['section_id', 'group_label']);
    $table->index('manual_match_group_id');
    $table->index('activation_time');
    $table->index('lock_time');
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| section_id | `foreignId(...)` | FK → sections.id, RESTRICT | Parent section |
| bank_problem_id | `foreignId(...)` | NULLABLE, FK → bank_problems.id, SET NULL | Source bank problem if cloned |
| creator_id | `foreignId('creator_id')` | FK → users.id, RESTRICT | Problem creator |
| name | `string('name')` | NOT NULL | Problem title |
| description | `text('description')` | NOT NULL | Rich text + LaTeX |
| difficulty | `string('difficulty', 10)` | DEFAULT 'medium' | easy \| medium \| hard |
| group_label | `string('group_label', 100)` | NULLABLE | Free-text: "Week 5", "Midterm" |
| order | `integer('order')` | NULLABLE | Display order in section (D-44) |
| max_submissions | `integer(...)` | NULLABLE | Max per student. NULL = unlimited |
| time_limit | `integer('time_limit')` | NOT NULL | Execution time limit (ms) |
| memory_limit | `integer('memory_limit')` | NOT NULL | Memory limit (KB) |
| activation_time | `timestamp(...)` | NULLABLE | When visible to students (auto mode) |
| lock_time | `timestamp(...)` | NULLABLE | When submissions close (auto mode) |
| publish_mode_override | `string(..., 10)` | NULLABLE | auto \| manual \| NULL (follow semester) |
| lock_mode_override | `string(..., 10)` | NULLABLE | auto \| manual \| NULL (follow semester) |
| is_published | `boolean(...)` | DEFAULT false | Manual publish flag |
| is_locked | `boolean(...)` | DEFAULT false | Manual lock flag |
| manual_match_group_id | `uuid(...)` | NULLABLE | D-58: manual cross-section matching for SIM. Only set when problem has no bank_problem_id. |
| testcases_updated_at | `timestamp(...)` | NULLABLE | D-41: last time test cases were changed |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |
| deleted_at | `softDeletes()` | NULLABLE | D-43: soft delete |

**Indexes**: `section_id`, `(section_id, group_label)`, `manual_match_group_id`, `activation_time`, `lock_time`.

**Visibility logic** (for AI agents):
```
effective_publish_mode:
  IF semester.allow_publish_override = false → use semester.publish_mode
  ELIF problem.publish_mode_override IS NOT NULL → use problem.publish_mode_override
  ELSE → use semester.publish_mode

IF mode = 'auto': visible when NOW() >= activation_time
IF mode = 'manual': visible when is_published = true
Same logic for lock_mode / is_locked / lock_time.
```

**difficulty values**: `easy`, `medium`, `hard`

### 2.7. submissions

One student submission of source code for one Problem. **Never deleted** (D-52).

**Eloquent Model**: `App\Models\Submission` — NO `SoftDeletes`.

**IMPORTANT for AI agents**:
- `execution_status`, `testcases_passed`, `testcases_total` are written by CES directly (D-81).
- CES MUST recount `testcases_passed` and `testcases_total` from `code_execution_results` after all test cases complete to ensure accuracy.
- Quota check: `SELECT COUNT(*) FROM submissions WHERE problem_id = ? AND creator_id = ?`. Use `SELECT ... FOR UPDATE` in transaction to prevent concurrent quota bypass (D-36, D-39).

```php
Schema::create('submissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('problem_id')->constrained()->restrictOnDelete();
    $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
    $table->foreignId('programming_language_id')->constrained('programming_languages')->restrictOnDelete();
    $table->string('file_path', 500);
    $table->string('file_name');
    $table->string('execution_status', 30)->default('in_queue');
    $table->integer('testcases_passed')->default(0);
    $table->integer('testcases_total')->default(0);
    $table->boolean('is_outdated')->default(false);
    $table->timestamps();

    $table->index(['problem_id', 'creator_id']);
    $table->index('creator_id');
    $table->index('execution_status');
    $table->index(['problem_id', 'created_at']);
    $table->index('created_at');
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| problem_id | `foreignId(...)` | FK → problems.id, RESTRICT | Which problem |
| creator_id | `foreignId('creator_id')` | FK → users.id, RESTRICT | Who submitted (student) |
| programming_language_id | `foreignId(...)` | FK → programming_languages.id, RESTRICT | Language used |
| file_path | `string('file_path', 500)` | NOT NULL | MinIO path: submissions/{id}/main.py |
| file_name | `string('file_name')` | NOT NULL | Original filename |
| execution_status | `string(..., 30)` | DEFAULT 'in_queue' | Overall CES status |
| testcases_passed | `integer(...)` | DEFAULT 0 | Count of passed test cases |
| testcases_total | `integer(...)` | DEFAULT 0 | Total test cases at submission time |
| is_outdated | `boolean(...)` | DEFAULT false | D-41: true when test cases changed after submission |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |

**execution_status values**: `in_queue`, `processing`, `accepted`, `partially_accepted`, `error`, `timeout`, `language_not_supported`

---

## 3. Test Cases & Programming Languages

### 3.1. test_cases

Test cases for a Problem. Max 50 per Problem, max 1MB per input/output (D-45). CASCADE deleted with parent Problem.

**Eloquent Model**: `App\Models\TestCase`

```php
Schema::create('test_cases', function (Blueprint $table) {
    $table->id();
    $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
    $table->text('input');
    $table->text('expected_output');
    $table->boolean('is_active')->default(true);
    $table->boolean('is_visible')->default(false);
    $table->integer('order')->default(0);
    $table->timestamps();

    $table->index(['problem_id', 'order']);
    $table->index(['problem_id', 'is_active']);
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| problem_id | `foreignId(...)` | FK → problems.id, **CASCADE** | Parent problem |
| input | `text('input')` | NOT NULL | Test input (stdin) |
| expected_output | `text('expected_output')` | NOT NULL | Expected output |
| is_active | `boolean(...)` | DEFAULT true | Used for grading? |
| is_visible | `boolean(...)` | DEFAULT false | Shown to students? (sample test cases) |
| order | `integer('order')` | DEFAULT 0 | Display/execution order |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |

### 3.2. programming_languages

Reference/seed table. Maps to Judge0, Monaco Editor, Dolos, and CodeQL identifiers.

**Eloquent Model**: `App\Models\ProgrammingLanguage`

```php
Schema::create('programming_languages', function (Blueprint $table) {
    $table->id();
    $table->string('name', 50);
    $table->string('version', 20)->nullable();
    $table->integer('judge0_id');
    $table->string('monaco_language', 30);
    $table->string('dolos_language', 30)->nullable();
    $table->string('codeql_language', 30)->nullable();
    $table->timestamps();
});
```

**Seed data** (for AI agents — use in DatabaseSeeder):
```php
// judge0_id values from Judge0 CE
['name' => 'Python',  'version' => '3.11', 'judge0_id' => 71, 'monaco_language' => 'python',  'dolos_language' => 'python', 'codeql_language' => 'python'],
['name' => 'Java',    'version' => '17',   'judge0_id' => 62, 'monaco_language' => 'java',    'dolos_language' => 'java',   'codeql_language' => 'java'],
['name' => 'C',       'version' => '11',   'judge0_id' => 50, 'monaco_language' => 'c',       'dolos_language' => 'c',      'codeql_language' => 'cpp'],
['name' => 'C++',     'version' => '17',   'judge0_id' => 54, 'monaco_language' => 'cpp',     'dolos_language' => 'cpp',    'codeql_language' => 'cpp'],
```

**NOTE**: When upgrading Judge0 CE version, verify `judge0_id` mappings have not changed.

---

## 4. Problem Bank

Problem Bank belongs to Course, fork model (D-07).

### 4.1. Versioning Model (for AI agents)

- First version: `original_id = NULL`, `version = 1`. This row IS the original.
- Subsequent versions: `original_id = first_version.id`, `version` increments.
- Query latest approved version:
  ```sql
  SELECT * FROM bank_problems
  WHERE (id = :original_id OR original_id = :original_id)
    AND status = 'approved'
    AND deleted_at IS NULL
  ORDER BY version DESC
  LIMIT 1
  ```
- Soft delete does NOT affect cloned Problems (they are independent copies).
- **original_id FK uses RESTRICT** — cannot delete original if versions exist.

### 4.2. bank_problems

**Eloquent Model**: `App\Models\BankProblem` — uses `SoftDeletes`.

```php
Schema::create('bank_problems', function (Blueprint $table) {
    $table->id();
    $table->foreignId('course_id')->constrained()->restrictOnDelete();
    $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
    $table->foreignId('original_id')->nullable()->constrained('bank_problems')->restrictOnDelete();
    $table->string('name');
    $table->text('description');
    $table->string('difficulty', 10)->default('medium');
    $table->integer('time_limit');
    $table->integer('memory_limit');
    $table->integer('version')->default(1);
    $table->string('status', 20)->default('approved');
    $table->timestamps();
    $table->softDeletes();

    $table->index(['course_id', 'status']);
    $table->index(['original_id', 'version']);
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| course_id | `foreignId(...)` | FK → courses.id, RESTRICT | Parent course |
| author_id | `foreignId('author_id')` | FK → users.id, RESTRICT | Original author |
| original_id | `foreignId('original_id')` | NULLABLE, FK → bank_problems.id, **RESTRICT** | Groups versions. NULL = this is the original. |
| name | `string('name')` | NOT NULL | Problem title |
| description | `text('description')` | NOT NULL | Rich text + LaTeX |
| difficulty | `string('difficulty', 10)` | DEFAULT 'medium' | easy \| medium \| hard |
| time_limit | `integer('time_limit')` | NOT NULL | Default time limit (ms) |
| memory_limit | `integer('memory_limit')` | NOT NULL | Default memory limit (KB) |
| version | `integer('version')` | DEFAULT 1 | Version number |
| status | `string('status', 20)` | DEFAULT 'approved' | pending \| approved \| rejected |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |
| deleted_at | `softDeletes()` | NULLABLE | D-67 |

**status values**: `pending`, `approved`, `rejected` (D-25 approval workflow)

### 4.3. bank_problem_test_cases

Test cases for Bank Problems. CASCADE deleted with parent.

```php
Schema::create('bank_problem_test_cases', function (Blueprint $table) {
    $table->id();
    $table->foreignId('bank_problem_id')->constrained()->cascadeOnDelete();
    $table->text('input');
    $table->text('expected_output');
    $table->boolean('is_active')->default(true);
    $table->boolean('is_visible')->default(false);
    $table->integer('order')->default(0);
    $table->timestamps();
});
```

---

## 5. Analysis Tables

### 5.1. Analysis Architecture (CRITICAL for AI agents)

**Two processing paths**:

1. **Real-time path (CES)**: Student submits → CES runs immediately → writes `code_execution_results` directly to DB → pushes completion to `result-execution` queue → api pushes WebSocket to student.

2. **Batch path (SIM/AID/VUL)**: GV triggers → api creates `analysis_problems` + `analysis_submissions` → publishes jobs to RabbitMQ → services process → send results to `result-analysis` queue → **api writes all results to DB** → api pushes WebSocket to GV.

**SIM scope = Semester-level (Phương án B)**:
- SIM compares ALL submissions across ALL sections with equivalent problems in the same Semester.
- `analysis_problems` for SIM scopes to a **group of equivalent problems**, not a single problem.
- Equivalence determined by: same `bank_problem_id` (auto) or same `manual_match_group_id` (manual).
- When any GV triggers SIM, results are generated for ALL submissions in scope. Other GVs see results immediately without re-triggering.

### 5.2. analysis_problems

One analysis batch. Scope depends on services enabled:
- If SIM enabled: scope = all equivalent problems in Semester (cross-section).
- If only AID/VUL: scope can still be per-Problem, but analysis_submissions cover all equivalent.

**Eloquent Model**: `App\Models\AnalysisProblem`

```php
Schema::create('analysis_problems', function (Blueprint $table) {
    $table->id();
    $table->foreignId('semester_id')->constrained()->restrictOnDelete();
    $table->foreignId('bank_problem_id')->nullable()->constrained('bank_problems')->restrictOnDelete();
    $table->uuid('manual_match_group_id')->nullable();
    $table->foreignId('triggered_by_problem_id')->constrained('problems')->restrictOnDelete();
    $table->foreignId('analyst_id')->constrained('users')->restrictOnDelete();
    $table->jsonb('services');
    $table->string('status', 20)->default('processing');
    $table->boolean('is_latest')->default(true);
    $table->boolean('is_partial')->default(false);
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['semester_id', 'bank_problem_id', 'is_latest']);
    $table->index(['status', 'started_at']);
});

// Partial unique indexes — only 1 is_latest=true per scope
DB::statement('
    CREATE UNIQUE INDEX idx_analysis_problems_latest_bank
    ON analysis_problems (semester_id, bank_problem_id)
    WHERE is_latest = true AND bank_problem_id IS NOT NULL
');
DB::statement('
    CREATE UNIQUE INDEX idx_analysis_problems_latest_manual
    ON analysis_problems (semester_id, manual_match_group_id)
    WHERE is_latest = true AND manual_match_group_id IS NOT NULL
');

// CHECK constraint: at least one scope identifier must be set
DB::statement('
    ALTER TABLE analysis_problems
    ADD CONSTRAINT chk_analysis_scope
    CHECK (bank_problem_id IS NOT NULL OR manual_match_group_id IS NOT NULL)
');
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| semester_id | `foreignId(...)` | FK → semesters.id, RESTRICT | Scope: which semester |
| bank_problem_id | `foreignId(...)` | NULLABLE, FK → bank_problems.id, RESTRICT | Auto-match: all problems with same bank_problem_id |
| manual_match_group_id | `uuid(...)` | NULLABLE | Manual-match: all problems with same UUID |
| triggered_by_problem_id | `foreignId(...)` | FK → problems.id, RESTRICT | Which problem triggered this analysis (traceability) |
| analyst_id | `foreignId('analyst_id')` | FK → users.id, RESTRICT | Who triggered |
| services | `jsonb('services')` | NOT NULL | Enabled services. E.g., `["plagiarism-checker", "ai-detector"]` |
| status | `string('status', 20)` | DEFAULT 'processing' | processing \| completed \| timeout |
| is_latest | `boolean(...)` | DEFAULT true | D-53: only 1 latest per scope |
| is_partial | `boolean(...)` | DEFAULT false | D-57: triggered on unlocked problem |
| started_at | `timestamp(...)` | NULLABLE | When processing started |
| completed_at | `timestamp(...)` | NULLABLE | When all services completed |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |

**Scope logic** (for AI agents):
```
IF bank_problem_id IS NOT NULL:
    scope = SELECT p.* FROM problems p
            JOIN sections s ON p.section_id = s.id
            WHERE s.semester_id = :semester_id
              AND p.bank_problem_id = :bank_problem_id
              AND p.deleted_at IS NULL

IF manual_match_group_id IS NOT NULL:
    scope = SELECT p.* FROM problems p
            JOIN sections s ON p.section_id = s.id
            WHERE s.semester_id = :semester_id
              AND p.manual_match_group_id = :manual_match_group_id
              AND p.deleted_at IS NULL
```

**Re-trigger logic** (for AI agents):
```php
DB::transaction(function () use ($semesterId, $bankProblemId, $analystId, $services, $triggeredByProblemId) {
    // 1. Mark old as not latest
    AnalysisProblem::where('semester_id', $semesterId)
        ->where('bank_problem_id', $bankProblemId)
        ->where('is_latest', true)
        ->update(['is_latest' => false]);

    // 2. Create new
    $analysis = AnalysisProblem::create([
        'semester_id' => $semesterId,
        'bank_problem_id' => $bankProblemId,
        'triggered_by_problem_id' => $triggeredByProblemId,
        'analyst_id' => $analystId,
        'services' => $services,
        'is_latest' => true,
        'started_at' => now(),
    ]);

    // Partial unique index ensures only 1 is_latest=true per scope
    // If race condition, second transaction fails at INSERT

    return $analysis;
});
```

**Existing results check** (for AI agents — when GV triggers and results already exist):
```php
$existing = AnalysisProblem::where('semester_id', $semesterId)
    ->where('bank_problem_id', $bankProblemId)
    ->where('is_latest', true)
    ->where('status', 'completed')
    ->first();

if ($existing) {
    // Results already available — show to GV, filtered by their section
    // Ask: "Kết quả đã có từ {$existing->created_at}. Xem kết quả hay chạy lại?"
}
```

**status values**: `processing`, `completed`, `timeout`

**Timeout check** (D-82 — scheduled job every 5 min):
```sql
UPDATE analysis_problems
SET status = 'timeout', updated_at = NOW()
WHERE status = 'processing'
  AND started_at < NOW() - INTERVAL '30 minutes';
```

### 5.3. analysis_submissions

Per-submission tracking within an analysis batch. Contains submissions from ALL sections in scope. CASCADE deleted with parent.

**Eloquent Model**: `App\Models\AnalysisSubmission`

**NOTE for AI agents**: The three per-service status columns (`plagiarism_status`, `ai_detection_status`, `vuln_scan_status`) are intentional tech debt. They are hardcoded for 4 known services. If a 5th service is added in v3.0, refactor this into a separate `analysis_service_statuses` table.

```php
Schema::create('analysis_submissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('submission_id')->constrained()->restrictOnDelete();
    $table->foreignId('analysis_problem_id')->constrained('analysis_problems')->cascadeOnDelete();
    $table->string('plagiarism_status', 20)->default('in_queue');
    $table->string('ai_detection_status', 20)->default('in_queue');
    $table->string('vuln_scan_status', 20)->default('in_queue');
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index('analysis_problem_id');
    $table->index('submission_id');
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| submission_id | `foreignId(...)` | FK → submissions.id, RESTRICT | The submission being analyzed |
| analysis_problem_id | `foreignId(...)` | FK → analysis_problems.id, **CASCADE** | Parent batch |
| plagiarism_status | `string(..., 20)` | DEFAULT 'in_queue' | SIM service status |
| ai_detection_status | `string(..., 20)` | DEFAULT 'in_queue' | AID service status |
| vuln_scan_status | `string(..., 20)` | DEFAULT 'in_queue' | VUL service status |
| started_at | `timestamp(...)` | NULLABLE | |
| completed_at | `timestamp(...)` | NULLABLE | |
| created_at | `timestamps()` | | |
| updated_at | `timestamps()` | | |

**Per-service status values**: `in_queue`, `processing`, `completed`, `error`, `timeout`, `not_applicable` (D-54)

### 5.4. code_execution_results

Per-test-case execution results. Written **directly by CES** (D-81). Append-only (only `created_at`, no `updated_at`).

**CRITICAL for AI agents (CES service)**:
- CES MUST use upsert: `INSERT ... ON CONFLICT (submission_id, test_case_id) DO UPDATE SET status = EXCLUDED.status, actual_output = EXCLUDED.actual_output, ...`
- This handles crash recovery: if CES restarts and re-processes a submission, existing results are updated, not duplicated.
- After all test cases complete, CES recounts and updates `submissions.testcases_passed` and `submissions.testcases_total`.

```php
Schema::create('code_execution_results', function (Blueprint $table) {
    $table->id();
    $table->foreignId('submission_id')->constrained()->restrictOnDelete();
    $table->foreignId('test_case_id')->constrained()->restrictOnDelete();
    $table->string('status', 30);
    $table->text('actual_output')->nullable();
    $table->decimal('consumed_time_ms', 10, 3)->nullable();
    $table->integer('consumed_memory_kb')->nullable();
    $table->text('error_content')->nullable();
    $table->timestamp('created_at');

    $table->unique(['submission_id', 'test_case_id']);
    $table->index('submission_id');
    $table->index('created_at');
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| submission_id | `foreignId(...)` | FK → submissions.id, RESTRICT | |
| test_case_id | `foreignId(...)` | FK → test_cases.id, RESTRICT | |
| status | `string('status', 30)` | NOT NULL | Judge0 result status |
| actual_output | `text(...)` | NULLABLE | Program stdout |
| consumed_time_ms | `decimal(..., 10, 3)` | NULLABLE | Execution time |
| consumed_memory_kb | `integer(...)` | NULLABLE | Memory used |
| error_content | `text(...)` | NULLABLE | Error message, truncated 5000 chars (D-38) |
| created_at | `timestamp(...)` | NOT NULL | No updated_at — use upsert instead |

**UNIQUE constraint**: `(submission_id, test_case_id)` — one result per test case per submission. Enables upsert.

**status values**: `accepted`, `wrong_answer`, `time_limit_exceeded`, `memory_limit_exceeded`, `runtime_error`, `compilation_error`, `internal_error`, `timeout`

### 5.5. similarity_results

Pairwise similarity from SIM (Dolos). Written by **api** from `result-analysis` queue (D-80). **1 row per ordered pair** (submission_a_id < submission_b_id).

**CRITICAL for AI agents**:
- Pairs are ORDERED: `submission_a_id < submission_b_id` always. This halves storage vs 2 rows per pair.
- `match_type` is assigned by api based on section membership of the two submissions.
- `a_regions` / `b_regions` contain highlight coordinates from Dolos output. Pipe-separated format: `startRow,startCol,endRow,endCol|startRow,startCol,endRow,endCol|...`
- Query for all matches of submission X: `WHERE submission_a_id = X OR submission_b_id = X`

```php
Schema::create('similarity_results', function (Blueprint $table) {
    $table->id();
    $table->foreignId('analysis_problem_id')->constrained('analysis_problems')->cascadeOnDelete();
    $table->foreignId('submission_a_id')->constrained('submissions')->restrictOnDelete();
    $table->foreignId('submission_b_id')->constrained('submissions')->restrictOnDelete();
    $table->decimal('similarity', 5, 4);
    $table->integer('longest_fragment')->nullable();
    $table->integer('total_overlap')->nullable();
    $table->string('match_type', 20);
    $table->text('a_regions')->nullable();
    $table->text('b_regions')->nullable();
    $table->timestamp('created_at');

    $table->unique(['analysis_problem_id', 'submission_a_id', 'submission_b_id']);
    $table->index(['analysis_problem_id', 'submission_a_id']);
    $table->index(['analysis_problem_id', 'submission_b_id']);
    $table->index(['match_type', 'similarity']);
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| analysis_problem_id | `foreignId(...)` | FK → analysis_problems.id, **CASCADE** | Parent batch |
| submission_a_id | `foreignId(...)` | FK → submissions.id, RESTRICT | Ordered: always < submission_b_id |
| submission_b_id | `foreignId(...)` | FK → submissions.id, RESTRICT | Ordered: always > submission_a_id |
| similarity | `decimal('similarity', 5, 4)` | NOT NULL | Score 0.0000 – 1.0000 |
| longest_fragment | `integer(...)` | NULLABLE | Longest matching fragment (tokens) |
| total_overlap | `integer(...)` | NULLABLE | Total overlapping tokens |
| match_type | `string('match_type', 20)` | NOT NULL | WITHIN_SECTION \| CROSS_SECTION |
| a_regions | `text(...)` | NULLABLE | Highlight regions for submission A |
| b_regions | `text(...)` | NULLABLE | Highlight regions for submission B |
| created_at | `timestamp(...)` | NOT NULL | |

**match_type logic** (for AI agents — api assigns this after SIM returns raw results):
```php
$sectionA = $submissionA->problem->section_id;
$sectionB = $submissionB->problem->section_id;
$matchType = ($sectionA === $sectionB) ? 'WITHIN_SECTION' : 'CROSS_SECTION';
```

**match_type values**: `WITHIN_SECTION`, `CROSS_SECTION`

**Two-tier visibility** (D-05, D-06):
| Tier | Who sees | What they see |
|---|---|---|
| Within-Section | GV/TA | Both names, both code sides, %, highlight |
| Cross-Section | GV/TA | Other student name + % + own student's code. NOT other student's code. |
| Full | Org Admin | Everything |

### 5.6. ai_detection_results

AI-generated code detection probability. One result per analysis_submission. Written by **api**.

```php
Schema::create('ai_detection_results', function (Blueprint $table) {
    $table->id();
    $table->foreignId('analysis_submission_id')->constrained('analysis_submissions')->cascadeOnDelete();
    $table->decimal('probability', 5, 4);
    $table->timestamp('created_at');

    $table->unique('analysis_submission_id');
});
```

| Column | Type | Constraints | Description |
|---|---|---|---|
| id | `$table->id()` | PK | |
| analysis_submission_id | `foreignId(...)` | FK → analysis_submissions.id, CASCADE | |
| probability | `decimal('probability', 5, 4)` | NOT NULL | 0.0000 – 1.0000 |
| created_at | `timestamp(...)` | NOT NULL | |

**UNIQUE on analysis_submission_id** — one result per submission per analysis batch.

### 5.7. vulnerability_results

CodeQL findings. Multiple results per analysis_submission. Written by **api**.

```php
Schema::create('vulnerability_results', function (Blueprint $table) {
    $table->id();
    $table->foreignId('analysis_submission_id')->constrained('analysis_submissions')->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('severity', 20);
    $table->string('file_path', 500)->nullable();
    $table->integer('start_line')->nullable();
    $table->integer('start_column')->nullable();
    $table->integer('end_line')->nullable();
    $table->integer('end_column')->nullable();
    $table->timestamp('created_at');

    $table->index('analysis_submission_id');
});
```

**severity values**: `recommendation`, `warning`, `error`

---

## 6. Membership Tables

Roles assigned through membership tables, not on users table. Replaces v1.0 polymorphic memberables.

### 6.1. organization_members

```php
Schema::create('organization_members', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->restrictOnDelete();
    $table->foreignId('user_id')->constrained()->restrictOnDelete();
    $table->string('role', 20);
    $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
    $table->timestamp('created_at');

    $table->unique(['organization_id', 'user_id']);
    $table->index('user_id');
});
```

**role values**: `admin`, `instructor`

### 6.2. section_members

```php
Schema::create('section_members', function (Blueprint $table) {
    $table->id();
    $table->foreignId('section_id')->constrained()->restrictOnDelete();
    $table->foreignId('user_id')->constrained()->restrictOnDelete();
    $table->string('role', 20);
    $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
    $table->timestamp('created_at');

    $table->unique(['section_id', 'user_id']);
    $table->index('user_id');
    $table->index(['section_id', 'role']);
});
```

**role values**: `instructor`, `ta`, `student`

**D-51 constraint** (application-level, not DB): A student cannot be in multiple sections of the same Course in the same Semester. Enforced during import validation.

---

## 7. Tags & Pivot Tables

### 7.1. tags

Tags belong to Course (D-15). Many-to-many with bank_problems and problems.

```php
Schema::create('tags', function (Blueprint $table) {
    $table->id();
    $table->foreignId('course_id')->constrained()->restrictOnDelete();
    $table->string('name', 50);
    $table->string('color', 7)->nullable();
    $table->timestamps();

    $table->unique(['course_id', 'name']);
});
```

### 7.2. Pivot Tables

Standard Laravel pivot tables. No id column. Composite primary key.

```php
// problem_programming_languages
Schema::create('problem_programming_languages', function (Blueprint $table) {
    $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
    $table->foreignId('programming_language_id')->constrained()->cascadeOnDelete();
    $table->primary(['problem_id', 'programming_language_id']);
});

// bank_problem_programming_languages
Schema::create('bank_problem_programming_languages', function (Blueprint $table) {
    $table->foreignId('bank_problem_id')->constrained()->cascadeOnDelete();
    $table->foreignId('programming_language_id')->constrained()->cascadeOnDelete();
    $table->primary(['bank_problem_id', 'programming_language_id']);
});

// bank_problem_tags
Schema::create('bank_problem_tags', function (Blueprint $table) {
    $table->foreignId('bank_problem_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
    $table->primary(['bank_problem_id', 'tag_id']);
});

// problem_tags
Schema::create('problem_tags', function (Blueprint $table) {
    $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
    $table->primary(['problem_id', 'tag_id']);
});
```

All pivot FKs use **CASCADE** — removing a problem/bank_problem/tag removes pivot entries.

---

## 8. Audit Tables

### 8.1. problem_edit_logs

Tracks changes to Problem fields (D-40). Append-only.

```php
Schema::create('problem_edit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('problem_id')->constrained()->restrictOnDelete();
    $table->foreignId('edited_by')->constrained('users')->restrictOnDelete();
    $table->string('field_changed', 50);
    $table->text('old_value')->nullable();
    $table->text('new_value')->nullable();
    $table->timestamp('edited_at');

    $table->index('problem_id');
});
```

**NOTE for AI agents**: Create a log entry for EACH field changed. Use `$problem->getChanges()` in Eloquent observer to detect changed fields. `old_value` and `new_value` are TEXT to accommodate `description` field changes.

### 8.2. section_transfer_logs

Tracks student transfers between sections (D-50). Append-only.

```php
Schema::create('section_transfer_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->restrictOnDelete();
    $table->foreignId('from_section_id')->constrained('sections')->restrictOnDelete();
    $table->foreignId('to_section_id')->constrained('sections')->restrictOnDelete();
    $table->foreignId('transferred_by')->constrained('users')->restrictOnDelete();
    $table->timestamp('transferred_at');
});
```

---

## 9. Notifications & Framework Tables

### 9.1. notifications

Laravel built-in. Created via `php artisan notifications:table`.

```php
Schema::create('notifications', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable');
    $table->text('data');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();

    // Custom index for unread notifications query
    $table->index(['notifiable_id', 'notifiable_type', 'read_at', 'created_at'], 'idx_notifications_unread');
});
```

**Cleanup plan** (Phase 4/5): Scheduled job deletes notifications older than 6 months.

### 9.2. Laravel Framework Tables

Standard tables created via Artisan commands. Not customized.

| Table | Command | Purpose |
|---|---|---|
| password_reset_tokens | Built into auth | Password reset flow |
| sessions | `php artisan session:table` | Session storage |
| cache | `php artisan cache:table` | Cache storage |
| cache_locks | `php artisan cache:table` | Cache locks |
| jobs | `php artisan queue:table` | Internal queue jobs |
| job_batches | `php artisan queue:batches-table` | Job batch tracking |
| failed_jobs | `php artisan queue:failed-table` | Failed jobs |
| personal_access_tokens | `php artisan install:api` | Sanctum bearer tokens — required by the `BearerAuth` scheme in `openapi.yml` |

**NOTE**: RabbitMQ is for inter-service messaging. Laravel queue tables are for INTERNAL jobs only (scheduled tasks, email sending, etc.).

---

## 10. Enum Values Reference

All status fields use VARCHAR with string enum values (D-26).

| Table | Column | Valid Values |
|---|---|---|
| semesters | publish_mode | `auto`, `manual` |
| semesters | lock_mode | `auto`, `manual` |
| problems | difficulty | `easy`, `medium`, `hard` |
| problems | publish_mode_override | `auto`, `manual`, `NULL` (follow semester) |
| problems | lock_mode_override | `auto`, `manual`, `NULL` (follow semester) |
| submissions | execution_status | `in_queue`, `processing`, `accepted`, `partially_accepted`, `error`, `timeout`, `language_not_supported` |
| analysis_problems | status | `processing`, `completed`, `timeout` |
| analysis_submissions | plagiarism_status | `in_queue`, `processing`, `completed`, `error`, `timeout`, `not_applicable` |
| analysis_submissions | ai_detection_status | `in_queue`, `processing`, `completed`, `error`, `timeout`, `not_applicable` |
| analysis_submissions | vuln_scan_status | `in_queue`, `processing`, `completed`, `error`, `timeout`, `not_applicable` |
| code_execution_results | status | `accepted`, `wrong_answer`, `time_limit_exceeded`, `memory_limit_exceeded`, `runtime_error`, `compilation_error`, `internal_error`, `timeout` |
| similarity_results | match_type | `WITHIN_SECTION`, `CROSS_SECTION` |
| vulnerability_results | severity | `recommendation`, `warning`, `error` |
| bank_problems | status | `pending`, `approved`, `rejected` |
| organization_members | role | `admin`, `instructor` |
| section_members | role | `instructor`, `ta`, `student` |

---

## 11. Migration Order

Migrations MUST be created in this order to satisfy FK dependencies:

```
01. users
02. organizations
03. courses
04. semesters
05. sections
06. programming_languages
07. bank_problems
08. bank_problem_test_cases
09. problems
10. test_cases
11. submissions
12. analysis_problems
13. analysis_submissions
14. code_execution_results
15. similarity_results
16. ai_detection_results
17. vulnerability_results
18. organization_members
19. section_members
20. tags
21. problem_programming_languages
22. bank_problem_programming_languages
23. bank_problem_tags
24. problem_tags
25. problem_edit_logs
26. section_transfer_logs
27. notifications
28. password_reset_tokens
29. sessions
30. cache + cache_locks
31. jobs + job_batches + failed_jobs
```

---

## 12. Decision Traceability

| Decision | Schema Impact |
|---|---|
| D-03 Entity hierarchy | Core 6 tables: organizations → courses → semesters → sections → problems → submissions |
| D-04 Section isolation | section_members with role. Queries scoped by section_id. |
| D-07 Bank fork model | bank_problems with original_id + version |
| D-15 Tags = Course scope | tags.course_id FK |
| D-16 Semester policies | semesters: publish_mode, lock_mode, allow_*_override |
| D-25 Bank approval | courses.require_bank_approval + bank_problems.status |
| D-26 String enums | All status columns = VARCHAR |
| D-37 Judge0 timeout | execution_status + code_execution_results.status: 'timeout' |
| D-40 Edit tracking | problem_edit_logs table with old_value, new_value |
| D-41 Test case update | problems.testcases_updated_at, submissions.is_outdated |
| D-43 Soft delete Problem | problems.deleted_at |
| D-44 Problem ordering | problems.order, problems.group_label |
| D-47/D-58 Cross-section matching | problems.manual_match_group_id UUID + bank_problem_id auto-match |
| D-50 Student transfer | section_transfer_logs table |
| D-53 Re-trigger | analysis_problems.is_latest + partial unique indexes |
| D-54 Partial re-run | Per-service status: not_applicable |
| D-56 Timeout | analysis_problems.status = 'timeout', scheduled job on started_at |
| D-57 Unlocked analysis | analysis_problems.is_partial |
| D-62 Thresholds | semesters.similarity_threshold, ai_detection_threshold |
| D-67 Soft delete Bank | bank_problems.deleted_at |
| D-80 Stateless workers | No schema change. api writes SIM/AID/VUL results. |
| D-81 CES keeps DB | code_execution_results written by CES. Upsert required. |
| D-83 Two result queues | No schema change. Routing only. |
| D-89 PgBouncer | Fewer DB consumers. No schema change. |
| Phương án B | analysis_problems scope = Semester-level for SIM. New columns: semester_id, bank_problem_id, manual_match_group_id, triggered_by_problem_id. similarity_results: submission_a_id/b_id ordered pairs. |

---

*— End of Database Schema Design v2.0 —*
