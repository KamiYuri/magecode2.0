# api — Agent Context

## Purpose
Laravel 13 REST API: auth, RBAC, entity CRUD, submissions, analysis orchestration.
Single writer to PostgreSQL in the batch path (D-80) and the only service exposed
through Traefik (D-86).

## Status
Through B8 + B11 + C1: migrations + models + seeders, auth, the RBAC policy layer, CRUD for
organizations/courses/semesters/sections + org members, roster import/transfer, the problem &
test-case lifecycle, the route/envelope contract tests, and the MinIO storage layer.
Next: C2 submission endpoints, B10 problem bank.

**Adding an endpoint?** `tests/Feature/Contract/RouteConformanceTest.php` fails until the
route matches openapi.yml — strike your operation from its `PENDING` list in the same commit.

## Tech Stack
PHP 8.4 (Docker) / 8.3+ (host constraint `^8.3`), Laravel 13, Sanctum bearer tokens,
`bschmitt/laravel-amqp` for RabbitMQ, Reverb for WebSockets, Pint + Larastan (level 6).

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
- `app/Services/SubmissionStorageService.php` — the only writer of submission objects; its key
  must stay byte-identical to `shared/go/storage/storage.go::SubmissionPath`, which the
  code-executor uses to read the same objects back
- `config/minio.php` + the `minio` disk in `config/filesystems.php` — pre-signed URLs are signed
  against the internal endpoint, so they work for the batch workers and not for a browser
- `app/Models/Problem.php::visibleIn()` — the SQL mirror of `ProblemVisibilityService::isVisible()`;
  change one and change the other, or a listing disagrees with the `is_visible` it reports
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
