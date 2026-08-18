# api — Agent Context

## Purpose
Laravel 13 REST API: auth, RBAC, entity CRUD, submissions, analysis orchestration.
Single writer to PostgreSQL in the batch path (D-80) and the only service exposed
through Traefik (D-86).

## Status
Through D7: everything of M1 (auth, RBAC, entity CRUD, roster, problems), the submission
endpoints and their MinIO storage, the AMQP publisher and the execution-result consumer, and
Plan D's trigger, SIM/AID/VUL job publishing, `result-analysis` ingestion, batch completion with
its Reverb frames, and the D-82 timeout sweeper.
Next: D8 analysis read APIs (privacy-tagged, gates M3). Open from M1: B9 tags,
B10 problem bank, B12 profile + notifications.

**Adding an endpoint?** `tests/Feature/Contract/RouteConformanceTest.php` fails until the
route matches openapi.yml — strike your operation from its `PENDING` list in the same commit.

## Tech Stack
PHP 8.4 (Docker) / 8.3+ (host constraint `^8.3`), Laravel 13, Sanctum bearer tokens,
`php-amqplib` for RabbitMQ (C3 dropped `bschmitt/laravel-amqp`; see v3 §7), Reverb for
WebSockets, Pint + Larastan (level 6).

## Key Files
- `routes/api.php` — every endpoint under `/api/v1` (U-3); `/api/health` alias for probes.
  Parameters are named for implicit binding (`{organization}`) while openapi.yml spells
  them `{organization_id}` — B11's conformance test normalises the placeholders
- `app/Http/Responses/CursorPage.php` — the `{data, meta}` envelope; use it for every
  paginated listing rather than Laravel's paginated resource response, whose meta differs
- `app/Exceptions/ConflictException.php` — 409 + the contract's `code`; renders itself and
  is safe to throw inside a transaction
- `app/Services/` — `MembershipService` (who is what, where), `OrganizationMemberService`
  (bulk add + last-admin guard), `UserProvisioningService` (email → first-time account),
  `ProblemVisibilityService` (D-16 effective publish/lock), `ProblemService`, `TestCaseService`
- `app/Services/Analysis/AnalysisJobDispatcher.php` — the only publisher of analysis jobs
  (SIM/AID/VUL), called after the trigger's transaction commits and only for a batch that
  request created. D4/D5 must not add a second publisher. Each service has its own language
  gate — `dolos_language`, `monaco_language`, `codeql_language` — and a value outside that
  job schema's enum parks the submission as `not_applicable` rather than publishing a message
  the worker would reject (v3 §7, 2026-08-18)
- `app/Services/Analysis/SimJobPlan.php` — SIM's grouping rule with no DB and no broker;
  group and submission ordering is contractual, because `language_group_index` is positional
- `app/Services/SubmissionStorageService.php` — the only writer of submission objects; its key
  must stay byte-identical to `shared/go/storage/storage.go::SubmissionPath`, which the
  code-executor uses to read the same objects back
- `config/minio.php` + the `minio` disk in `config/filesystems.php` — pre-signed URLs are signed
  against the internal endpoint, so they work for the batch workers and not for a browser.
  Anything a browser must read is streamed by an api route (v3 §7, 2026-08-14); no bucket
  is ever published, so do not reach for a public MinIO endpoint
- `app/Models/Problem.php::visibleIn()` — the SQL mirror of `ProblemVisibilityService::isVisible()`;
  change one and change the other, or a listing disagrees with the `is_visible` it reports
- `app/Services/Analysis/AnalysisCompletionService.php` — the only writer of
  `analysis_problems.status` after creation. `close()` is conditional on `processing`, which is
  what keeps `analysis.completed` firing once; D8's cancel must go through it too. Completion is
  read from the rows, never from the cached SIM set (v3 §7, 2026-08-18)
- `routes/channels.php` — `submission.{id}` is creator-only; `section.{id}` is section
  instructors + Org Admins, TAs excluded. Suites that dispatch analysis events need
  `Tests\Support\FakesAnalysisBroadcasts`: the tests run against the reverb driver on purpose
- `app/Messaging/AnalysisResultHandler.php` + `app/Services/Analysis/*ResultIngestor.php` — the
  `result-analysis` side, run by `php artisan amqp:consume-analysis` (its twin
  `amqp:consume-execution` handles CES). api is the single writer of analysis rows (D-80), so
  every result write in the batch path goes through here
- `app/Http/Controllers/HealthController.php` — readiness probe (503 when DB is down)
- `config/database.php` — pgsql `ATTR_EMULATE_PREPARES` for PgBouncer transaction pooling (D-89)
- `Dockerfile` — targets `api` (nginx+FPM), `reverb`, `test`; deps resolved inside the
  runtime image because php-amqplib needs ext-sockets
- `docker/nginx.conf` — FPM proxy (build context is this directory)

## Local Development
The host PHP lacks `pdo_pgsql` and `amqp`, so every PHP command runs in the test image:

```bash
make test-api          # php artisan test against compose Postgres (magecode_test)
make lint-api          # pint --test + phpstan
make composer-api args="require foo/bar"
```

`make dev-api` (host `php artisan serve`) only works once the host has pdo_pgsql.

## Env Vars
`DB_*` (PgBouncer :6432 in compose, direct :5432 in tests), `RABBITMQ_*`, `MINIO_*`,
`MINIO_TEST_*` (storage tests only), `REVERB_*`, `SANCTUM_STATEFUL_DOMAINS`. See `.env.example`
and the api block in `docker-compose.yml`.

## Testing
Postgres-only (U-6): the suite runs against `magecode_test` on the compose Postgres,
created by `deploy/docker/postgres/init.sql`. SQLite cannot run the partial unique
indexes and CHECK constraints the migrations use. TDD per `.agents/workflows/dev-rules.md`.

The MinIO-backed tests (`tests/Feature/Storage/`) skip unless `MINIO_TEST_*` reaches the
container. To run them, export the repo-root `.env` first:

```bash
set -a && . ./.env && set +a && make test-api
```
