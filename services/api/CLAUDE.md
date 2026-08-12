# api — Agent Context

## Purpose
Laravel 13 REST API: auth, RBAC, entity CRUD, submissions, analysis orchestration.
Single writer to PostgreSQL in the batch path (D-80) and the only service exposed
through Traefik (D-86).

## Status
B1 scaffold: bootable app with `/api/v1/health`. Migrations (B2), models (B3),
auth (B4), and RBAC (B5) land next.

## Tech Stack
PHP 8.4 (Docker) / 8.3+ (host constraint `^8.3`), Laravel 13, Sanctum bearer tokens,
`bschmitt/laravel-amqp` for RabbitMQ, Reverb for WebSockets, Pint + Larastan (level 6).

## Key Files
- `routes/api.php` — every endpoint under `/api/v1` (U-3); `/api/health` alias for probes
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
`REVERB_*`, `SANCTUM_STATEFUL_DOMAINS`. See `.env.example` and the api block in
`docker-compose.yml`.

## Testing
Postgres-only (U-6): the suite runs against `magecode_test` on the compose Postgres,
created by `deploy/docker/postgres/init.sql`. SQLite cannot run the partial unique
indexes and CHECK constraints the migrations use. TDD per `.agents/workflows/dev-rules.md`.
