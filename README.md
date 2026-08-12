# MageCode 2.0 — Code Assessment Platform for Education

> Bach Khoa Cyber Security Center (BKCS) — Hanoi University of Science and Technology

MageCode is a microservices-based platform for automated code assessment. Students submit code; the system auto-grades, detects plagiarism, identifies AI-generated code, and scans for security vulnerabilities.

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                     Traefik (:80)                       │
│              /api → api    /ws → reverb    /* → web     │
└────────┬───────────────┬───────────────────┬────────────┘
         │               │                   │
   ┌─────┴──────┐   ┌────┴────┐          ┌───┴───┐
   │  api (PHP) │   │ reverb  │          │  web  │
   │ Laravel 13 │   │  (WS)   │          │Vue SPA│
   └─────┬──────┘   └────┬────┘          └───────┘
         │               │
    ┌────┴───────────────┴─────────────────────────┐
    │               Backend Network                │
    │  PgBouncer ↔ PostgreSQL    RabbitMQ    MinIO │
    └──┬──────────────────┬──────────┬─────────────┘
       │                  │          │
  ┌────┴────┐     ┌───────┴────┐  ┌──┴───────────┐
  │  code-  │     │ plagiarism │  │ ai-detector  │
  │executor │     │  checker   │  │ vuln-scanner │
  │  (Go)   │     │   (Go)     │  │ (Python/Go)  │
  └────┬────┘     └────────────┘  └──────────────┘
       │
  ┌────┴────┐
  │ Judge0  │  (separate compose, privileged)
  └─────────┘
```

### Service Map

| Service | Language | DB Access | Purpose |
|---------|----------|:---------:|---------|
| `api` | PHP 8.4 (Laravel 13) | ✅ | REST API, queue dispatch |
| `web` | TypeScript (Vue 3) | — | SPA frontend |
| `reverb` | PHP (reuses api image) | ✅ | WebSocket server |
| `code-executor` | Go | ✅ | Submit code to Judge0, stream results |
| `plagiarism-checker` | Go | ❌ | Dolos-based similarity detection |
| `ai-detector` | Python | ❌ | CodeBERT inference |
| `vuln-scanner` | Go | ❌ | CodeQL security analysis |

---

## Development Requirements

### Required (all developers)

| Tool | Version | Purpose |
|------|---------|---------|
| Docker Engine | ≥ 24 | Container runtime |
| Docker Compose | v2 | Orchestration (`docker compose`) |
| GNU Make | any | Dev/deploy commands via `Makefile` |

### Per-service (install only what you work on)

| Service | Tool | Version | Install |
|---------|------|---------|---------|
| `api`, `reverb` | PHP | ≥ 8.3 (containers run 8.4) | `sudo apt install php8.4-cli php8.4-pgsql php8.4-amqp php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath php8.4-intl php8.4-curl` — only needed for `make dev-api`/`dev-reverb`; `pdo_pgsql` and `amqp` are mandatory |
| `api`, `reverb` | Composer | 2.x | Optional — `make composer-api` runs it in the image |
| `web` | Node.js | ^20.19 or ≥22.12 | [nodejs.org](https://nodejs.org/) or `nvm install 22` |
| `web` | npm | ≥ 10 | Bundled with Node.js |
| `code-executor`, `plagiarism-checker`, `vuln-scanner` | Go | 1.26+ | [go.dev/dl](https://go.dev/dl/) |
| `ai-detector` | Python | ≥ 3.12 | `sudo apt install python3.12 python3.12-venv` |

### Required before any `--profile app` / `--profile analysis` container starts

| Tool | Purpose | Install |
|------|---------|---------|
| Loki Docker driver | Application containers declare `logging.driver: loki` (D-88), so Docker refuses to start them until the plugin exists | `docker plugin install grafana/loki-docker-driver:latest --alias loki --grant-all-permissions` |

Infrastructure containers (`make up`) do not use the driver and start without it.

## Quick Start (Development)

```bash
# 1. Copy environment config
cp .env.example .env
# Fill in required passwords (POSTGRES_PASSWORD, RABBITMQ_DEFAULT_PASS, etc.)

# 2. Start infrastructure containers
make up
# Starts: postgres, pgbouncer, rabbitmq, minio, loki (6 containers)

# 3. Run Laravel migrations (executes inside the api image, against PgBouncer)
make migrate

# 4. Seed database — idempotent, safe to re-run
make seed

# 5. API quality gates (also run in the api image)
make test-api     # php artisan test against the magecode_test database
make lint-api     # pint --test + phpstan
make composer-api args="require foo/bar"

# 6. Start application services on host (needs host toolchains, see below)
make dev-api      # Laravel dev server → localhost:8000
make dev-web      # Vite HMR          → localhost:5173
make dev-reverb   # WebSocket server  → localhost:8080

# 7. (Optional) Start Judge0 for code execution
make up-judge0
```

> **PHP on the host is optional.** Every `make *-api` target runs in the `magecode-api:test`
> image, so migrations, tests and linters work with Docker alone. `make dev-api` and
> `make dev-reverb` are the exceptions: they run `php artisan` directly and therefore need a
> host PHP with the `pdo_pgsql` and `amqp` extensions.

## Docker Compose Profiles

The stack uses Docker Compose profiles to separate **dev** and **deploy** workflows:

| Command | Profile | Containers | Use Case |
|---------|---------|:----------:|----------|
| `make up` | *(default)* | 6 | **Dev** — infrastructure only |
| `make up-app` | `app` | 11 | **Deploy** — + api, web, reverb, code-executor, traefik |
| `make up-analysis` | `analysis` | + 3 | + plagiarism-checker, ai-detector, vuln-scanner |
| `make up-full` | `full` | 19 | Everything |
| `make up-judge0` | — | + 4 | Judge0 CE (separate compose) |
| `make up-all` | `full` + judge0 | 23 | Complete stack |

### Default Containers (Dev Infrastructure)

| Container | Port (host) | Purpose |
|-----------|:-----------:|---------|
| `postgres` | — | PostgreSQL 16 |
| `pgbouncer` | `6432` | Connection pooler |
| `rabbitmq` | `5672`, `15672` | Message broker + management UI |
| `minio` | `9000`, `9001` | S3-compatible storage + console |
| `loki` | `3100` | Log aggregation |

> **Dev mode**: Infrastructure ports are exposed to the host so that application code running locally (via `make dev-*`) can connect at `localhost:<port>`.

## Makefile Targets

```
Infrastructure          Local Dev              Database
─────────────          ──────────             ────────
make up                make dev-api           make migrate
make up-app            make dev-web           make seed
make test-api          make lint-api          make composer-api
make up-analysis       make dev-reverb        make fresh
make up-full
make up-judge0         Monitoring             Maintenance
make up-all            ──────────             ───────────
make down              make logs service=api  make reset
                       make logs-all          make status
                                              make backup
                                              make judge0-health
```

## Project Structure

```
magecode/
├── services/
│   ├── api/                # Laravel 13 REST API
│   ├── web/                # Vue 3 SPA
│   ├── code-executor/      # Go — Judge0 integration
│   ├── plagiarism-checker/ # Go — Dolos CLI wrapper
│   ├── ai-detector/        # Python — CodeBERT inference
│   └── vuln-scanner/       # Go — CodeQL analysis
├── shared/
│   ├── go/                 # Shared Go packages (rabbitmq, db, minio, logger)
│   └── schemas/            # JSON Schema for RabbitMQ messages
├── deploy/docker/          # Config files (traefik, prometheus, grafana, nginx, etc.)
├── docker-compose.yml      # Main stack (19 services)
├── docker-compose.judge0.yml  # Judge0 CE (4 services, privileged)
├── Makefile                # Dev/deploy commands
├── .env.example            # Environment template
└── docs/                   # Technical docs, API contracts, decision logs
```

## Documentation

- `docs/` — Technical design, database schema, API contracts, decision logs
- `docs/docker-compose-architecture.md` — Full Docker infrastructure documentation
- `CLAUDE.md` — AI agent context for each service
