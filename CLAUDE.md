# MageCode 2.0 — Agent Context

## What is this project?
MageCode is a microservices-based code assessment platform for university education.
Students submit code, the system auto-grades and analyzes for plagiarism, AI-generation,
and security vulnerabilities.

## Session Start (REQUIRED)
1. Read `docs/session-guide.md` — workflow, SoT hierarchy, checklists for every session.
2. Read `docs/progress.md` — live task board; continue `wip` tasks or pick the next
   unblocked P0 of the current milestone (definitions in `docs/roadmap.md`).
3. Branch before any change per `.agents/workflows/branch.md` — never commit to `main`,
   `dev`, or `{service}/dev` directly. TDD per `.agents/workflows/dev-rules.md`.
4. `/home/gideon/Documents/BKCS/deprecated_magecode` is an abandoned 2.0 prototype —
   reference/salvage only, NEVER source of truth.

## Architecture
- **Monorepo** with 7 services under `services/`
- **Stack**: PHP (Laravel 13), Go 1.26+, Python 3.12+, Vue 3 + TypeScript
- **Data**: PostgreSQL 16 + PgBouncer, RabbitMQ, MinIO (S3)
- **Code Execution**: Judge0 CE (separate compose, privileged containers)
- **Routing**: Traefik (Docker auto-discovery)
- **Observability**: Prometheus + Grafana + Loki

## Key Design Decisions
- Two processing paths: Real-time (CES → DB) and Batch (SIM/AID/VUL → stateless)
- SIM, AID, VUL have NO database access (D-80). They receive full data via RabbitMQ.
- CES keeps DB access for real-time per-test-case streaming (D-81).
- All logs: JSON to stdout, collected by Docker Loki driver (D-88).
- PgBouncer: transaction pooling, pool_size=30 (D-89).
- Judge0 CE in separate compose due to privileged: true (D-90).

## Service Map
| Service | Path | Language | DB Access |
|---------|------|----------|-----------|
| api | services/api/ | PHP 8.4 | Yes (via PgBouncer) |
| web | services/web/ | TypeScript | No |
| reverb | (uses api image) | PHP | Yes (channel auth) |
| code-executor | services/code-executor/ | Go | Yes (via PgBouncer) |
| plagiarism-checker | services/plagiarism-checker/ | Go | No |
| ai-detector | services/ai-detector/ | Python | No |
| vuln-scanner | services/vuln-scanner/ | Go | No |

## Shared Code
- `shared/go/` — `logger` (D-88 JSON), `config` (fail-fast env), `apperror`
  (Transient/Permanent → retry-vs-DLX), `rmq` (publisher/consumer, per-queue
  `Q`/`Q.retry`/`Q.dlq`), `db` (sqlx+pgx, simple protocol for PgBouncer), `storage` (MinIO)
- `shared/schemas/` — JSON Schema for all RabbitMQ messages
- `services/api/app/Enums/` — every closed value set from `database-schema.md` §10;
  resolve roles through `OrganizationRole`/`SectionRole`, never raw strings

## Infrastructure (Docker)
```bash
docker compose up -d                                          # Dev: infrastructure only
docker compose --profile app up -d                            # Deploy: + app services
docker compose --profile analysis up -d                       # + analysis workers
docker compose --profile full up -d                           # All MageCode
docker compose -f docker-compose.judge0.yml up -d             # Judge0 (separate)
```

## Local Dev
```bash
# api — runs inside the magecode-api:test image, so no host PHP is needed
make test-api                   # php artisan test vs magecode_test on compose Postgres
make lint-api                   # pint --test + phpstan (level 6)
make migrate / make seed        # against PgBouncer :6432; seed is idempotent
make composer-api args="..."    # composer inside the image

# host toolchains
make dev-api                    # Laravel dev server :8000 — needs host pdo_pgsql + amqp
make dev-web                    # Vite HMR :5173
make dev-reverb                 # WebSocket :8080 — same host PHP requirement
cd services/code-executor && go run ./cmd/main.go
cd services/ai-detector && python src/main.py
```
Go tests: `cd shared/go && go test ./...`; integration suites need the compose stack and
their env var (`RMQ_TEST_URL`, `DB_TEST_DSN`, `MINIO_TEST_*`) — see `docs/progress.md`.

## Documentation Map
| Question | Read |
|---|---|
| What to build next, task definitions | `docs/roadmap.md` + `docs/progress.md` |
| Session workflow & checklists | `docs/session-guide.md` |
| System design, domain, decisions | `docs/technical-design.md`, `docs/decision-log/` (amendments: v3 §7) |
| DB / API / queue contracts | `docs/database-schema.md`, `docs/api-contracts/openapi.yml`, `shared/schemas/` |
| Frontend UX/visual spec | `docs/ui-ux-design.md` |
| Infra & containers | `docs/docker-compose-architecture.md` |

## Per-Service Context
Each service has its own `CLAUDE.md` with purpose, tech stack, key files, env vars, and testing
(created together with the service scaffold — tasks A7/B1/F1).
