# MageCode 2.0 — Docker Compose & Project Architecture v1.1

> **Version**: 1.1 — 18/03/2026
> **Authors**: Gideon & Claude (Anthropic)
> **Stack**: Docker Compose, Traefik, Grafana Loki log driver, Judge0 CE
> **Status**: Phase 1 Deliverable — System Design
> **Depends on**: Decision Log v3 (D-80–D-89), RabbitMQ Message Schemas v1, Database Schema v2

### Changelog from v1.0

- **NEW** Judge0 CE sub-stack (4 containers) in separate `docker-compose.judge0.yml` (D-90)
- **FIX #1** Loki chicken-and-egg: Loki moved to default profile; only Prometheus/Grafana/exporters in `observability`
- **FIX #2** Traefik metrics entryPoint: added `metrics` entryPoint on port 8082
- **FIX #3** Judge0 now documented and integrated (was missing from v1.0)
- **FIX #4** Loki URL: documented that port 3100 MUST bind to host for Docker log driver
- **FIX #5** Removed phantom `pgbouncer-data` volume (not used)
- **FIX #6** Removed `web` depends_on `api` (unnecessary coupling for static SPA)
- **FIX #7** Loki logging YAML anchor: added `loki-relabel-config` fallback note for non-observability mode
- **NEW** Resource limits (`deploy.resources.limits`) for ai-detector and vuln-scanner
- **NEW** RabbitMQ Prometheus plugin enablement
- **NEW** MinIO bucket auto-creation via init container
- **NEW** `tmpfs` mounts for ephemeral workspaces (CES, SIM, VUL)
- **NEW** Host prerequisites section (cgroup v1 for Judge0)

---

## 1. Overview

This document defines the complete Docker Compose configuration and monorepo project architecture for MageCode 2.0. It covers 19 MageCode containers + 4 Judge0 containers (23 total), networking, Traefik routing, Loki log aggregation, volume management, environment configuration, and the AI-friendly monorepo layout.

### 1.1. Container Inventory (19 MageCode + 4 Judge0 = 23 Total)

**MageCode Stack** (`docker-compose.yml` — 19 containers):

| # | Category | Container | Image | Exposed Port | Notes |
|---|---|---|---|---|---|
| 1 | Application | `api` | Custom (PHP 8.4 + PHP-FPM) | — (behind Traefik) | Laravel 13, 50 FPM workers |
| 2 | Application | `web` | Custom (Node + nginx) | — (behind Traefik) | Vue 3 SPA, static build served by nginx |
| 3 | Application | `reverb` | Custom (reuses `api` image) | — (behind Traefik) | Laravel Reverb, separate process |
| 4 | Application | `code-executor` | Custom (Go 1.26+) | — | CES, Judge0 API client |
| 5 | Application | `plagiarism-checker` | Custom (Go 1.26+) | — | SIM, Dolos CLI |
| 6 | Application | `ai-detector` | Custom (Python 3.12+) | — | AID, PyTorch + CodeBERT |
| 7 | Application | `vuln-scanner` | Custom (Go 1.26+) | — | VUL, CodeQL CLI |
| 8 | Data | `postgres` | postgres:16-alpine | 5432 (internal) | Primary database |
| 9 | Data | `pgbouncer` | bitnami/pgbouncer | 6432 (internal) | Connection pooler (D-89) |
| 10 | Data | `rabbitmq` | rabbitmq:3-management-alpine | 15672 (mgmt UI) | 6 queues (D-83) |
| 11 | Data | `minio` | minio/minio | 9001 (console) | S3-compatible storage (D-31) |
| 12 | Data | `minio-init` | minio/mc | — | One-shot: creates `magecode` bucket |
| 13 | Routing | `traefik` | traefik:v3 | 80, 443, 8080 | API gateway (D-22, D-86) |
| 14 | Logging | `loki` | grafana/loki:3 | 3100 (host-bound) | Log aggregation — DEFAULT profile (D-87) |
| 15 | Observability | `prometheus` | prom/prometheus | 9090 (internal) | Metrics scraper |
| 16 | Observability | `grafana` | grafana/grafana | 3000 | Dashboards + Alerts |
| 17 | Exporter | `postgres-exporter` | prometheuscommunity/postgres-exporter | — | PG metrics → Prometheus |
| 18 | Exporter | `pgbouncer-exporter` | prometheuscommunity/pgbouncer-exporter | — | Pool metrics → Prometheus |
| 19 | Exporter | `php-fpm-exporter` | hipages/php-fpm_exporter | — | FPM metrics → Prometheus |

**Judge0 Sub-stack** (`docker-compose.judge0.yml` — 4 containers):

| # | Category | Container | Image | Exposed Port | Notes |
|---|---|---|---|---|---|
| 20 | Judge0 | `judge0-server` | judge0/judge0:1.13.1 | 2358 (internal) | API server, `privileged: true` |
| 21 | Judge0 | `judge0-worker` | judge0/judge0:1.13.1 | — | Execution worker, `privileged: true` |
| 22 | Judge0 | `judge0-db` | postgres:16-alpine | — | Judge0 internal DB (separate from MageCode) |
| 23 | Judge0 | `judge0-redis` | redis:7-alpine | — | Judge0 job queue |

### 1.2. Design Principles

- **Single `docker-compose.yml`** — one file for MageCode, no override stack. Profiles for optional services.
- **Separate `docker-compose.judge0.yml`** — Judge0 CE isolated due to `privileged: true` requirement (D-90).
- **Traefik auto-discovery** — labels on containers, no static config files for routing.
- **Loki log driver** — all application containers ship JSON logs to Loki via Docker log driver. Loki runs in DEFAULT profile (not observability) because the log driver depends on it.
- **Named volumes** — all persistent data on named Docker volumes, never bind mounts for data.
- **Bind mounts for config** — Traefik, Prometheus, Grafana, PgBouncer config files from `deploy/docker/`.
- **AI-friendly** — every configuration file has comments explaining purpose. `CLAUDE.md` at root provides agent context.
- **Profiles** — `observability` profile for Prometheus/Grafana/exporters. Loki is in default profile. `analysis` profile for SIM/AID/VUL.

---

## 2. Network Architecture

### 2.1. Docker Networks

Three isolated networks + one shared link for Judge0:

| Network | Purpose | Connected Containers |
|---|---|---|
| `frontend` | Traefik ↔ application services | traefik, api, web, reverb |
| `backend` | Application ↔ data services | api, reverb, code-executor, plagiarism-checker, ai-detector, vuln-scanner, pgbouncer, rabbitmq, minio |
| `monitoring` | Observability stack | prometheus, grafana, loki, postgres-exporter, pgbouncer-exporter, php-fpm-exporter, traefik, rabbitmq, minio |
| `db-internal` | Postgres isolation | postgres, pgbouncer, postgres-exporter, pgbouncer-exporter |
| `judge0-link` | CES ↔ Judge0 API (external, shared between compose files) | code-executor, judge0-server |

**Key isolation rules:**
- `postgres` connects to NO named network directly — only `pgbouncer` talks to it via a private `db-internal` network.
- SIM, AID, VUL have NO access to `pgbouncer` or `postgres` (D-80 stateless).
- CES connects to `pgbouncer` via `backend` (D-81 direct DB).
- All application containers connect to `backend` for RabbitMQ and MinIO access.

```
                           ┌─────────────────────────────────────────────────┐
                           │              DOCKER HOST                        │
                           │                                                 │
  ┌────────────────────────┼─── frontend network ────────────────────────────┤
  │                        │                                                 │
  │   ┌─────────┐          │                                                 │
  │   │ traefik │ :80/:443 │                                                 │
  │   └──┬──┬──┬┘          │                                                 │
  │      │  │  │           │                                                 │
  │      │  │  └─── /ws/* ─┼──► reverb                                       │
  │      │  └── /api/* ────┼──► api (php-fpm + nginx)                        │
  │      └───── /* ────────┼──► web (nginx static)                           │
  │                        │                                                 │
  ├────────────────────────┼─── backend network ─────────────────────────────┤
  │                        │                                                 │
  │    api ◄───────────────┼──► pgbouncer ◄──┐                               │
  │    reverb ◄────────────┼──► rabbitmq     │  db-internal                  │
  │    code-executor ◄─────┼──► minio        │  network                      │
  │    plagiarism-checker ◄┤                 ├──► postgres                    │
  │    ai-detector ◄───────┤                 │                               │
  │    vuln-scanner ◄──────┤                 │                               │
  │                        │                 │                               │
  ├────────────────────────┼─── monitoring network ──────────────────────────┤
  │                        │                                                 │
  │    prometheus ──────►  │  grafana :3000                                   │
  │    loki ◄───── docker  │  log driver                                     │
  │    *-exporter(s) ──►   │  prometheus                                     │
  │                        │                                                 │
  └────────────────────────┴─────────────────────────────────────────────────┘
```

### 2.2. Network Definitions

```yaml
networks:
  frontend:
    name: magecode-frontend
    driver: bridge
  backend:
    name: magecode-backend
    driver: bridge
  monitoring:
    name: magecode-monitoring
    driver: bridge
  db-internal:
    name: magecode-db-internal
    driver: bridge
    internal: true          # No external access
  judge0-link:
    name: magecode-judge0-link
    # Shared with docker-compose.judge0.yml — CES ↔ Judge0 only
```

### 2.3. Container ↔ Network Matrix

| Container | frontend | backend | monitoring | db-internal | judge0-link |
|---|:---:|:---:|:---:|:---:|:---:|
| traefik | ✅ | — | ✅ | — | — |
| api | ✅ | ✅ | — | — | — |
| web | ✅ | — | — | — | — |
| reverb | ✅ | ✅ | — | — | — |
| code-executor | — | ✅ | — | — | ✅ |
| plagiarism-checker | — | ✅ | — | — | — |
| ai-detector | — | ✅ | — | — | — |
| vuln-scanner | — | ✅ | — | — | — |
| postgres | — | — | — | ✅ | — |
| pgbouncer | — | ✅ | — | ✅ | — |
| rabbitmq | — | ✅ | ✅ | — | — |
| minio | — | ✅ | ✅ | — | — |
| loki | — | — | ✅ | — | — |
| prometheus | — | — | ✅ | — | — |
| grafana | — | — | ✅ | — | — |
| postgres-exporter | — | — | ✅ | ✅ | — |
| pgbouncer-exporter | — | — | ✅ | ✅ | — |
| php-fpm-exporter | — | ✅ | ✅ | — | — |
| *judge0-server* | — | — | — | — | ✅ |

---

## 3. Traefik Routing (D-22, D-86)

### 3.1. Routing Rules

Single domain, path-based routing. No CORS needed since all services share the same origin.

| Path | Target | Priority | Description |
|---|---|---|---|
| `/api/*` | api:9000 | 3 | Laravel API (PHP-FPM via nginx sidecar) |
| `/ws/*` | reverb:8080 | 3 | WebSocket (Laravel Reverb) |
| `/minio/*` | minio:9001 | 2 | MinIO Console (dev only, disabled in prod) |
| `/rabbitmq/*` | rabbitmq:15672 | 2 | RabbitMQ Management (dev only) |
| `/*` | web:80 | 1 | Vue 3 SPA (catch-all, lowest priority) |

### 3.2. Traefik Static Config

```yaml
# deploy/docker/traefik/traefik.yml
api:
  dashboard: true
  insecure: true                    # Dev only — disable in production

entryPoints:
  web:
    address: ":80"
  websecure:
    address: ":443"
  metrics:
    address: ":8082"               # FIX #2: dedicated metrics entryPoint

providers:
  docker:
    endpoint: "unix:///var/run/docker.sock"
    exposedByDefault: false         # Only route containers with labels
    network: magecode-frontend      # Use frontend network for routing
    watch: true

# Prometheus metrics endpoint
metrics:
  prometheus:
    entryPoint: metrics             # Now correctly references the defined entryPoint
    addEntryPointsLabels: true
    addServicesLabels: true

# Access log for debugging
accessLog:
  format: json
  filters:
    statusCodes:
      - "400-599"

log:
  level: WARN
  format: json
```

### 3.3. Traefik Labels on Containers

```yaml
# api service labels
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.api.rule=PathPrefix(`/api`)"
  - "traefik.http.routers.api.entrypoints=web"
  - "traefik.http.routers.api.priority=3"
  - "traefik.http.services.api.loadbalancer.server.port=9000"
  # Production TLS
  # - "traefik.http.routers.api-secure.rule=PathPrefix(`/api`)"
  # - "traefik.http.routers.api-secure.entrypoints=websecure"
  # - "traefik.http.routers.api-secure.tls=true"

# reverb (WebSocket) labels
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.reverb.rule=PathPrefix(`/ws`)"
  - "traefik.http.routers.reverb.entrypoints=web"
  - "traefik.http.routers.reverb.priority=3"
  - "traefik.http.services.reverb.loadbalancer.server.port=8080"

# web (SPA) labels
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.web.rule=PathPrefix(`/`)"
  - "traefik.http.routers.web.entrypoints=web"
  - "traefik.http.routers.web.priority=1"
  - "traefik.http.services.web.loadbalancer.server.port=80"
```

### 3.4. TLS in Production

For BKCS/HUST deployment with real certificate:

```yaml
# deploy/docker/traefik/dynamic/tls.yml
tls:
  certificates:
    - certFile: /etc/traefik/certs/fullchain.pem
      keyFile: /etc/traefik/certs/privkey.pem
  stores:
    default:
      defaultCertificate:
        certFile: /etc/traefik/certs/fullchain.pem
        keyFile: /etc/traefik/certs/privkey.pem
```

---

## 4. Loki Log Aggregation (D-87, D-88)

### 4.1. Strategy

All application containers use Docker's Loki log driver to ship JSON stdout logs directly to Loki. No Promtail, no sidecar. The Docker daemon sends logs via HTTP to the Loki container.

**CRITICAL: Loki runs in the DEFAULT profile** (FIX #1). The Docker Loki log driver operates at the Docker daemon level, outside of container networking. It connects to Loki via `localhost:3100` on the host. Therefore:
1. Loki MUST start before any container using the Loki log driver.
2. Loki MUST bind port 3100 to the host (FIX #4) — this is NOT for external access, but because the Docker daemon needs `localhost:3100`.
3. Loki cannot be in the `observability` profile — it must be in the default profile.

**Prerequisites:**
1. Install the Loki Docker driver plugin on the host:
   ```bash
   docker plugin install grafana/loki-docker-driver:3-amd64 --alias loki --grant-all-permissions
   ```
2. Verify: `docker plugin ls` should show `loki` as enabled.
3. If Loki is not running, containers with Loki log driver will **fail to start**. Always start Loki first or use `docker compose up -d` which handles ordering.

### 4.2. Log Driver Configuration

Applied to each application container via `logging` block:

```yaml
# Common logging config (YAML anchor)
x-loki-logging: &loki-logging
  driver: loki
  options:
    loki-url: "http://localhost:3100/loki/api/v1/push"
    loki-batch-size: "400"
    loki-retries: "3"
    loki-max-backoff: "5s"
    loki-pipeline-stages: ""
    labels: "service"                         # Docker label → Loki label
    loki-external-labels: "env=production"    # Static label on all logs
```

**Which containers get Loki driver:**

| Container | Loki Driver | Reason |
|---|---|---|
| api | ✅ | Monolog JSON → stdout |
| web | ❌ | nginx access logs only, not critical |
| reverb | ✅ | WebSocket connection events |
| code-executor | ✅ | slog JSON → stdout |
| plagiarism-checker | ✅ | slog JSON → stdout |
| ai-detector | ✅ | structlog JSON → stdout |
| vuln-scanner | ✅ | slog JSON → stdout |
| traefik | ✅ | Access + error logs |
| postgres | ❌ | Default Docker driver, logs via exporter metrics |
| pgbouncer | ❌ | Default Docker driver |
| rabbitmq | ❌ | Built-in management UI for log viewing |
| minio | ❌ | Default Docker driver |

### 4.3. Unified Log Format (D-88)

All application services output JSON logs to stdout with these mandatory fields:

```json
{
  "timestamp": "2026-09-15T08:30:00.123Z",
  "level": "info",
  "service": "code-executor",
  "message": "Submission processed successfully",
  "trace_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "data": {
    "submission_id": 1042,
    "duration_ms": 1523
  }
}
```

### 4.4. Grafana LogQL Queries

Example queries for common debugging scenarios:

```logql
# All errors across all services
{env="production"} | json | level = "error"

# Trace a specific submission across services
{env="production"} | json | trace_id = "a1b2c3d4-..."

# CES processing times > 5s
{service="code-executor"} | json | data_duration_ms > 5000

# All logs for a specific service in the last hour
{service="api"} | json
```

### 4.5. Loki Config

```yaml
# deploy/docker/loki/loki.yml
auth_enabled: false

server:
  http_listen_port: 3100

common:
  ring:
    instance_addr: 0.0.0.0
    kvstore:
      store: inmemory
  replication_factor: 1
  path_prefix: /loki

schema_config:
  configs:
    - from: "2026-01-01"
      store: tsdb
      object_store: filesystem
      schema: v13
      index:
        prefix: index_
        period: 24h

storage_config:
  filesystem:
    directory: /loki/chunks

limits_config:
  retention_period: 168h          # 7 days log retention
  max_query_series: 500
  ingestion_rate_mb: 10
  ingestion_burst_size_mb: 20

compactor:
  working_directory: /loki/compactor
  compaction_interval: 5m
  retention_enabled: true
  delete_request_store: filesystem
```

---

## 5. Volume Management

### 5.1. Named Volumes

```yaml
volumes:
  postgres-data:          # PostgreSQL data directory
    name: magecode-postgres-data
  rabbitmq-data:          # RabbitMQ persistent queues + mnesia
    name: magecode-rabbitmq-data
  minio-data:             # MinIO object storage (source code, avatars)
    name: magecode-minio-data
  prometheus-data:        # Prometheus TSDB
    name: magecode-prometheus-data
  grafana-data:           # Grafana dashboards, datasources, alerts
    name: magecode-grafana-data
  loki-data:              # Loki chunks + index
    name: magecode-loki-data
  codeql-cache:           # CodeQL database cache (vuln-scanner)
    name: magecode-codeql-cache
  model-cache:            # CodeBERT model weights (ai-detector)
    name: magecode-model-cache
```

> **FIX #5**: `pgbouncer-data` removed — PgBouncer is stateless, Bitnami image needs no persistent volume.

### 5.2. Bind Mounts (Config Only)

```
deploy/docker/
├── traefik/
│   ├── traefik.yml                    → /etc/traefik/traefik.yml
│   └── dynamic/                       → /etc/traefik/dynamic/
├── prometheus/
│   └── prometheus.yml                 → /etc/prometheus/prometheus.yml
├── grafana/
│   ├── datasources/
│   │   └── datasources.yml           → /etc/grafana/provisioning/datasources/
│   └── dashboards/
│       ├── dashboards.yml             → /etc/grafana/provisioning/dashboards/
│       └── *.json                     → /var/lib/grafana/dashboards/
├── loki/
│   └── loki.yml                       → /etc/loki/local-config.yaml
├── pgbouncer/
│   ├── pgbouncer.ini                  → /etc/pgbouncer/pgbouncer.ini
│   └── userlist.txt                   → /etc/pgbouncer/userlist.txt
├── postgres/
│   └── init.sql                       → /docker-entrypoint-initdb.d/
└── nginx/
    └── api.conf                       → api container nginx config
```

---

## 6. Docker Compose Profiles

### 6.1. Profile Design

| Profile | Containers | Use Case |
|---|---|---|
| (default) | api, web, reverb, code-executor, loki, postgres, pgbouncer, rabbitmq, minio, minio-init, traefik | Minimum for development: core app + CES + logging |
| `analysis` | + plagiarism-checker, ai-detector, vuln-scanner | Full analysis pipeline |
| `observability` | + prometheus, grafana, postgres-exporter, pgbouncer-exporter, php-fpm-exporter | Metrics + dashboards (Loki already in default) |
| `full` | All 19 MageCode containers | Production or full integration test |

> **FIX #1/#7**: Loki moved to default profile. All containers with `logging: *loki-logging` now have Loki available at startup. Prometheus/Grafana/exporters remain in `observability` profile.

**Usage:**

```bash
# Development (core only)
docker compose up -d

# Development + all analysis services
docker compose --profile analysis up -d

# Full production stack
docker compose --profile full up -d

# Just add observability to current setup
docker compose --profile observability up -d
```

---

## 7. Environment Configuration

### 7.1. `.env` File Structure

Single `.env` at monorepo root. Docker Compose interpolates variables. Services read their own subset.

```env
# ─────────────────────────────────────────────
# MageCode 2.0 Environment Configuration
# Copy to .env and fill in values
# ─────────────────────────────────────────────

# ── Global ──
COMPOSE_PROJECT_NAME=magecode
APP_ENV=local                        # local | staging | production
APP_DOMAIN=localhost                 # magecode.bkcs.hust.edu.vn in prod
APP_URL=http://${APP_DOMAIN}

# ── PostgreSQL ──
POSTGRES_DB=magecode
POSTGRES_USER=magecode
POSTGRES_PASSWORD=                   # REQUIRED: generate strong password
POSTGRES_PORT=5432

# ── PgBouncer (D-89) ──
PGBOUNCER_PORT=6432
PGBOUNCER_POOL_MODE=transaction
PGBOUNCER_DEFAULT_POOL_SIZE=30
PGBOUNCER_MAX_CLIENT_CONN=150
PGBOUNCER_MIN_POOL_SIZE=5
PGBOUNCER_RESERVE_POOL_SIZE=5
PGBOUNCER_RESERVE_POOL_TIMEOUT=3

# ── RabbitMQ ──
RABBITMQ_DEFAULT_USER=magecode
RABBITMQ_DEFAULT_PASS=               # REQUIRED
RABBITMQ_DEFAULT_VHOST=magecode
RABBITMQ_MANAGEMENT_PORT=15672

# ── MinIO (D-31) ──
MINIO_ROOT_USER=magecode
MINIO_ROOT_PASSWORD=                 # REQUIRED: min 8 chars
MINIO_BUCKET=magecode
MINIO_CONSOLE_PORT=9001
MINIO_PRESIGNED_URL_TTL=21600       # 6 hours in seconds (D-85)

# ── Laravel API ──
APP_KEY=                             # REQUIRED: php artisan key:generate
APP_DEBUG=true
DB_CONNECTION=pgsql
DB_HOST=pgbouncer                    # Goes through PgBouncer, not direct
DB_PORT=${PGBOUNCER_PORT}
DB_DATABASE=${POSTGRES_DB}
DB_USERNAME=${POSTGRES_USER}
DB_PASSWORD=${POSTGRES_PASSWORD}
QUEUE_CONNECTION=rabbitmq
MAIL_MAILER=smtp
SANCTUM_STATEFUL_DOMAINS=${APP_DOMAIN}

# ── Laravel Reverb (D-32) ──
REVERB_APP_ID=magecode
REVERB_APP_KEY=                      # REQUIRED
REVERB_APP_SECRET=                   # REQUIRED
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http

# ── Code Executor (CES) ──
CES_JUDGE0_URL=http://judge0:2358    # Or external Judge0 CE endpoint
CES_JUDGE0_AUTH_TOKEN=               # If using Judge0 auth
CES_WORKER_COUNT=5                   # Goroutines (D-75)
CES_DB_HOST=pgbouncer                # CES has DB access (D-81)
CES_DB_PORT=${PGBOUNCER_PORT}
CES_DB_SIMPLE_PROTOCOL=true          # Required for PgBouncer transaction mode

# ── Plagiarism Checker (SIM) — Stateless (D-80) ──
SIM_DOLOS_TIMEOUT=300                # Seconds per batch
# No DB_* vars — SIM is stateless

# ── AI Detector (AID) — Stateless (D-80) ──
AID_MODEL_NAME=microsoft/codebert-base
AID_BATCH_SIZE=8
AID_DEVICE=cpu                       # cpu | cuda
# No DB_* vars — AID is stateless

# ── Vulnerability Scanner (VUL) — Stateless (D-80) ──
VUL_CODEQL_TIMEOUT=600              # Seconds per submission
# No DB_* vars — VUL is stateless

# ── Observability (D-33, D-87) ──
GRAFANA_ADMIN_PASSWORD=              # REQUIRED
LOKI_RETENTION_PERIOD=168h           # 7 days
PROMETHEUS_RETENTION_DAYS=15d
```

### 7.2. Service Environment Variable Matrix

| Variable Category | api | reverb | CES | SIM | AID | VUL |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| DB_* (PgBouncer) | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| RABBITMQ_* | ✅ | ❌ | ✅ | ✅ | ✅ | ✅ |
| MINIO_* | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| REVERB_* | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| trace_id (via message) | generates | — | echoes | echoes | echoes | echoes |

---

## 8. Full Docker Compose File

```yaml
# docker-compose.yml
# MageCode 2.0 — 18 containers, 4 networks, 9 volumes
# Usage:
#   docker compose up -d                          # Core services
#   docker compose --profile analysis up -d       # + analysis workers
#   docker compose --profile observability up -d  # + monitoring
#   docker compose --profile full up -d           # Everything

x-loki-logging: &loki-logging
  driver: loki
  options:
    loki-url: "http://localhost:3100/loki/api/v1/push"
    loki-batch-size: "400"
    loki-retries: "3"
    loki-max-backoff: "5s"
    labels: "service"

x-common-env: &common-env
  TZ: Asia/Ho_Chi_Minh

services:
  # ══════════════════════════════════════════════
  # APPLICATION SERVICES (7)
  # ══════════════════════════════════════════════

  api:
    build:
      context: ./services/api
      dockerfile: Dockerfile
    container_name: magecode-api
    labels:
      - service=api
      - "traefik.enable=true"
      - "traefik.http.routers.api.rule=PathPrefix(`/api`)"
      - "traefik.http.routers.api.entrypoints=web"
      - "traefik.http.routers.api.priority=3"
      - "traefik.http.services.api.loadbalancer.server.port=9000"
    environment:
      <<: *common-env
      APP_ENV: ${APP_ENV}
      APP_KEY: ${APP_KEY}
      APP_DEBUG: ${APP_DEBUG}
      APP_URL: ${APP_URL}
      DB_CONNECTION: pgsql
      DB_HOST: pgbouncer
      DB_PORT: ${PGBOUNCER_PORT}
      DB_DATABASE: ${POSTGRES_DB}
      DB_USERNAME: ${POSTGRES_USER}
      DB_PASSWORD: ${POSTGRES_PASSWORD}
      QUEUE_CONNECTION: rabbitmq
      RABBITMQ_HOST: rabbitmq
      RABBITMQ_PORT: 5672
      RABBITMQ_USER: ${RABBITMQ_DEFAULT_USER}
      RABBITMQ_PASSWORD: ${RABBITMQ_DEFAULT_PASS}
      RABBITMQ_VHOST: ${RABBITMQ_DEFAULT_VHOST}
      FILESYSTEM_DISK: minio
      MINIO_ENDPOINT: http://minio:9000
      MINIO_ACCESS_KEY: ${MINIO_ROOT_USER}
      MINIO_SECRET_KEY: ${MINIO_ROOT_PASSWORD}
      MINIO_BUCKET: ${MINIO_BUCKET}
      MINIO_PRESIGNED_URL_TTL: ${MINIO_PRESIGNED_URL_TTL:-21600}
      REVERB_APP_ID: ${REVERB_APP_ID}
      REVERB_APP_KEY: ${REVERB_APP_KEY}
      REVERB_APP_SECRET: ${REVERB_APP_SECRET}
      SANCTUM_STATEFUL_DOMAINS: ${APP_DOMAIN}
    networks:
      - frontend
      - backend
    depends_on:
      pgbouncer:
        condition: service_healthy
      rabbitmq:
        condition: service_healthy
      minio:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9000/api/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 30s
    restart: unless-stopped
    logging:
      <<: *loki-logging

  web:
    build:
      context: ./services/web
      dockerfile: Dockerfile
    container_name: magecode-web
    labels:
      - service=web
      - "traefik.enable=true"
      - "traefik.http.routers.web.rule=PathPrefix(`/`)"
      - "traefik.http.routers.web.entrypoints=web"
      - "traefik.http.routers.web.priority=1"
      - "traefik.http.services.web.loadbalancer.server.port=80"
    networks:
      - frontend
    # FIX #6: No depends_on api — web is static SPA, starts independently
    restart: unless-stopped

  reverb:
    build:
      context: ./services/api
      dockerfile: Dockerfile
      target: reverb
    container_name: magecode-reverb
    labels:
      - service=reverb
      - "traefik.enable=true"
      - "traefik.http.routers.reverb.rule=PathPrefix(`/ws`)"
      - "traefik.http.routers.reverb.entrypoints=web"
      - "traefik.http.routers.reverb.priority=3"
      - "traefik.http.services.reverb.loadbalancer.server.port=8080"
    command: ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
    environment:
      <<: *common-env
      APP_ENV: ${APP_ENV}
      APP_KEY: ${APP_KEY}
      DB_CONNECTION: pgsql
      DB_HOST: pgbouncer
      DB_PORT: ${PGBOUNCER_PORT}
      DB_DATABASE: ${POSTGRES_DB}
      DB_USERNAME: ${POSTGRES_USER}
      DB_PASSWORD: ${POSTGRES_PASSWORD}
      REVERB_APP_ID: ${REVERB_APP_ID}
      REVERB_APP_KEY: ${REVERB_APP_KEY}
      REVERB_APP_SECRET: ${REVERB_APP_SECRET}
      REVERB_HOST: "0.0.0.0"
      REVERB_PORT: 8080
    networks:
      - frontend
      - backend
    depends_on:
      pgbouncer:
        condition: service_healthy
    restart: unless-stopped
    logging:
      <<: *loki-logging

  code-executor:
    build:
      context: .
      dockerfile: ./services/code-executor/Dockerfile
    container_name: magecode-code-executor
    labels:
      - service=code-executor
    environment:
      <<: *common-env
      DB_HOST: pgbouncer
      DB_PORT: ${PGBOUNCER_PORT}
      DB_NAME: ${POSTGRES_DB}
      DB_USER: ${POSTGRES_USER}
      DB_PASSWORD: ${POSTGRES_PASSWORD}
      DB_SIMPLE_PROTOCOL: "true"
      RABBITMQ_URL: "amqp://${RABBITMQ_DEFAULT_USER}:${RABBITMQ_DEFAULT_PASS}@rabbitmq:5672/${RABBITMQ_DEFAULT_VHOST}"
      MINIO_ENDPOINT: minio:9000
      MINIO_ACCESS_KEY: ${MINIO_ROOT_USER}
      MINIO_SECRET_KEY: ${MINIO_ROOT_PASSWORD}
      MINIO_BUCKET: ${MINIO_BUCKET}
      MINIO_USE_SSL: "false"
      JUDGE0_URL: ${CES_JUDGE0_URL}
      JUDGE0_AUTH_TOKEN: ${CES_JUDGE0_AUTH_TOKEN}
      WORKER_COUNT: ${CES_WORKER_COUNT:-5}
    tmpfs:
      - /tmp:size=128m             # Ephemeral workspace
    networks:
      - backend
      - judge0-link                  # Access Judge0 API
    depends_on:
      pgbouncer:
        condition: service_healthy
      rabbitmq:
        condition: service_healthy
    restart: unless-stopped
    logging:
      <<: *loki-logging

  plagiarism-checker:
    build:
      context: .
      dockerfile: ./services/plagiarism-checker/Dockerfile
    container_name: magecode-plagiarism-checker
    labels:
      - service=plagiarism-checker
    profiles: ["analysis", "full"]
    environment:
      <<: *common-env
      # Stateless — NO DB_* vars (D-80)
      RABBITMQ_URL: "amqp://${RABBITMQ_DEFAULT_USER}:${RABBITMQ_DEFAULT_PASS}@rabbitmq:5672/${RABBITMQ_DEFAULT_VHOST}"
      DOLOS_TIMEOUT: ${SIM_DOLOS_TIMEOUT:-300}
    networks:
      - backend
    depends_on:
      rabbitmq:
        condition: service_healthy
    restart: unless-stopped
    logging:
      <<: *loki-logging

  ai-detector:
    build:
      context: ./services/ai-detector
      dockerfile: Dockerfile
    container_name: magecode-ai-detector
    labels:
      - service=ai-detector
    profiles: ["analysis", "full"]
    environment:
      <<: *common-env
      # Stateless — NO DB_* vars (D-80)
      RABBITMQ_URL: "amqp://${RABBITMQ_DEFAULT_USER}:${RABBITMQ_DEFAULT_PASS}@rabbitmq:5672/${RABBITMQ_DEFAULT_VHOST}"
      MODEL_NAME: ${AID_MODEL_NAME:-microsoft/codebert-base}
      BATCH_SIZE: ${AID_BATCH_SIZE:-8}
      DEVICE: ${AID_DEVICE:-cpu}
    volumes:
      - model-cache:/app/models    # Persist model weights across restarts
    tmpfs:
      - /tmp:size=256m             # Ephemeral workspace for downloaded code
    deploy:
      resources:
        limits:
          memory: 3G               # CodeBERT + PyTorch on CPU
    networks:
      - backend
    depends_on:
      rabbitmq:
        condition: service_healthy
    restart: unless-stopped
    logging:
      <<: *loki-logging

  vuln-scanner:
    build:
      context: .
      dockerfile: ./services/vuln-scanner/Dockerfile
    container_name: magecode-vuln-scanner
    labels:
      - service=vuln-scanner
    profiles: ["analysis", "full"]
    environment:
      <<: *common-env
      # Stateless — NO DB_* vars (D-80)
      RABBITMQ_URL: "amqp://${RABBITMQ_DEFAULT_USER}:${RABBITMQ_DEFAULT_PASS}@rabbitmq:5672/${RABBITMQ_DEFAULT_VHOST}"
      CODEQL_TIMEOUT: ${VUL_CODEQL_TIMEOUT:-600}
    volumes:
      - codeql-cache:/home/codeql/.codeql    # Cache CodeQL databases
    tmpfs:
      - /tmp:size=512m             # Ephemeral workspace for CodeQL analysis
    deploy:
      resources:
        limits:
          memory: 2G               # CodeQL can be memory-hungry
    networks:
      - backend
    depends_on:
      rabbitmq:
        condition: service_healthy
    restart: unless-stopped
    logging:
      <<: *loki-logging

  # ══════════════════════════════════════════════
  # DATA SERVICES (4)
  # ══════════════════════════════════════════════

  postgres:
    image: postgres:16-alpine
    container_name: magecode-postgres
    labels:
      - service=postgres
    environment:
      POSTGRES_DB: ${POSTGRES_DB}
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
    volumes:
      - postgres-data:/var/lib/postgresql/data
      - ./deploy/docker/postgres/init.sql:/docker-entrypoint-initdb.d/init.sql:ro
    networks:
      - db-internal                  # Only PgBouncer + exporters can reach
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER} -d ${POSTGRES_DB}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 10s
    restart: unless-stopped

  pgbouncer:
    image: bitnami/pgbouncer:latest
    container_name: magecode-pgbouncer
    labels:
      - service=pgbouncer
    environment:
      POSTGRESQL_HOST: postgres
      POSTGRESQL_PORT: ${POSTGRES_PORT}
      POSTGRESQL_DATABASE: ${POSTGRES_DB}
      POSTGRESQL_USERNAME: ${POSTGRES_USER}
      POSTGRESQL_PASSWORD: ${POSTGRES_PASSWORD}
      PGBOUNCER_POOL_MODE: ${PGBOUNCER_POOL_MODE}
      PGBOUNCER_DEFAULT_POOL_SIZE: ${PGBOUNCER_DEFAULT_POOL_SIZE}
      PGBOUNCER_MAX_CLIENT_CONN: ${PGBOUNCER_MAX_CLIENT_CONN}
      PGBOUNCER_MIN_POOL_SIZE: ${PGBOUNCER_MIN_POOL_SIZE}
      PGBOUNCER_RESERVE_POOL_SIZE: ${PGBOUNCER_RESERVE_POOL_SIZE}
      PGBOUNCER_RESERVE_POOL_TIMEOUT: ${PGBOUNCER_RESERVE_POOL_TIMEOUT}
    networks:
      - backend
      - db-internal
    depends_on:
      postgres:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -h localhost -p 6432"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped

  rabbitmq:
    image: rabbitmq:3-management-alpine
    container_name: magecode-rabbitmq
    labels:
      - service=rabbitmq
    environment:
      RABBITMQ_DEFAULT_USER: ${RABBITMQ_DEFAULT_USER}
      RABBITMQ_DEFAULT_PASS: ${RABBITMQ_DEFAULT_PASS}
      RABBITMQ_DEFAULT_VHOST: ${RABBITMQ_DEFAULT_VHOST}
      RABBITMQ_SERVER_ADDITIONAL_ERL_ARGS: "-rabbitmq_prometheus return_per_object_metrics true"
    volumes:
      - rabbitmq-data:/var/lib/rabbitmq
      - ./deploy/docker/rabbitmq/enabled_plugins:/etc/rabbitmq/enabled_plugins:ro
    networks:
      - backend
      - monitoring
    ports:
      - "${RABBITMQ_MANAGEMENT_PORT:-15672}:15672"  # Management UI (dev)
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "-q", "ping"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 30s
    restart: unless-stopped

  minio:
    image: minio/minio
    container_name: magecode-minio
    labels:
      - service=minio
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}
      # Prometheus scrapes /minio/v2/metrics/cluster, which demands a bearer
      # JWT unless auth is public. Safe here only because those metrics ride
      # the same :9000 port as the S3 API and that port is bound to loopback
      # below — Prometheus reaches minio:9000 over the monitoring network.
      # G3: switch to token auth before anything binds :9000 more widely.
      MINIO_PROMETHEUS_AUTH_TYPE: public
    volumes:
      - minio-data:/data
    networks:
      - backend
      - monitoring
    ports:
      # Loopback only: the S3 API also serves unauthenticated Prometheus
      # metrics (see MINIO_PROMETHEUS_AUTH_TYPE above). Host access exists for
      # the storage integration tests; services use minio:9000 internally.
      - "127.0.0.1:9000:9000"                 # S3 API (host-run tests only)
      - "${MINIO_CONSOLE_PORT:-9001}:9001"    # Console UI (dev)
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"]
      interval: 15s
      timeout: 5s
      retries: 3
    restart: unless-stopped

  minio-init:
    image: minio/mc:latest
    container_name: magecode-minio-init
    entrypoint: >
      /bin/sh -c "
      mc alias set magecode http://minio:9000 $${MINIO_ROOT_USER} $${MINIO_ROOT_PASSWORD};
      mc mb --ignore-existing magecode/$${MINIO_BUCKET};
      exit 0;
      "
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}
      MINIO_BUCKET: ${MINIO_BUCKET}
    networks:
      - backend
    depends_on:
      minio:
        condition: service_healthy

  # ══════════════════════════════════════════════
  # ROUTING (1)
  # ══════════════════════════════════════════════

  traefik:
    image: traefik:v3
    container_name: magecode-traefik
    labels:
      - service=traefik
    ports:
      - "80:80"
      - "443:443"
      - "8080:8080"                  # Traefik dashboard (dev)
      - "8082:8082"                  # FIX #2: Prometheus metrics endpoint
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./deploy/docker/traefik/traefik.yml:/etc/traefik/traefik.yml:ro
      - ./deploy/docker/traefik/dynamic:/etc/traefik/dynamic:ro
    networks:
      - frontend
      - monitoring
    restart: unless-stopped
    logging:
      <<: *loki-logging

  # ══════════════════════════════════════════════
  # LOGGING (1) — DEFAULT profile (FIX #1)
  # Loki MUST be in default profile because
  # Docker Loki log driver depends on it at startup
  # ══════════════════════════════════════════════

  loki:
    image: grafana/loki:3
    container_name: magecode-loki
    labels:
      - service=loki
    # NO profiles — runs in default (FIX #1)
    volumes:
      - loki-data:/loki
      - ./deploy/docker/loki/loki.yml:/etc/loki/local-config.yaml:ro
    networks:
      - monitoring
    ports:
      - "3100:3100"                  # MUST bind to host — Docker daemon sends logs here (FIX #4)
    restart: unless-stopped

  # ══════════════════════════════════════════════
  # OBSERVABILITY (2) — profile: observability
  # (Loki is above in default profile)
  # ══════════════════════════════════════════════

  prometheus:
    image: prom/prometheus:latest
    container_name: magecode-prometheus
    labels:
      - service=prometheus
    profiles: ["observability", "full"]
    volumes:
      - prometheus-data:/prometheus
      - ./deploy/docker/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro
    command:
      - "--config.file=/etc/prometheus/prometheus.yml"
      - "--storage.tsdb.retention.time=${PROMETHEUS_RETENTION_DAYS:-15d}"
      - "--web.enable-lifecycle"
    networks:
      - monitoring
    restart: unless-stopped

  grafana:
    image: grafana/grafana:latest
    container_name: magecode-grafana
    labels:
      - service=grafana
    profiles: ["observability", "full"]
    environment:
      GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_ADMIN_PASSWORD}
      GF_USERS_ALLOW_SIGN_UP: "false"
    volumes:
      - grafana-data:/var/lib/grafana
      - ./deploy/docker/grafana/datasources:/etc/grafana/provisioning/datasources:ro
      - ./deploy/docker/grafana/dashboards:/etc/grafana/provisioning/dashboards:ro
    networks:
      - monitoring
    ports:
      - "3000:3000"
    restart: unless-stopped

  # ══════════════════════════════════════════════
  # EXPORTERS (3) — profile: observability
  # ══════════════════════════════════════════════

  postgres-exporter:
    image: prometheuscommunity/postgres-exporter:latest
    container_name: magecode-postgres-exporter
    labels:
      - service=postgres-exporter
    profiles: ["observability", "full"]
    environment:
      DATA_SOURCE_NAME: "postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@postgres:${POSTGRES_PORT}/${POSTGRES_DB}?sslmode=disable"
    networks:
      - monitoring
      - db-internal
    depends_on:
      postgres:
        condition: service_healthy
    restart: unless-stopped

  pgbouncer-exporter:
    image: prometheuscommunity/pgbouncer-exporter:latest
    container_name: magecode-pgbouncer-exporter
    labels:
      - service=pgbouncer-exporter
    profiles: ["observability", "full"]
    environment:
      PGBOUNCER_EXPORTER_HOST: pgbouncer
      PGBOUNCER_EXPORTER_PORT: 6432
      PGBOUNCER_EXPORTER_USER: ${POSTGRES_USER}
      PGBOUNCER_EXPORTER_PASS: ${POSTGRES_PASSWORD}
    networks:
      - monitoring
      - db-internal
    depends_on:
      pgbouncer:
        condition: service_healthy
    restart: unless-stopped

  php-fpm-exporter:
    image: hipages/php-fpm_exporter:latest
    container_name: magecode-php-fpm-exporter
    labels:
      - service=php-fpm-exporter
    profiles: ["observability", "full"]
    environment:
      PHP_FPM_SCRAPE_URI: "tcp://api:9001/status"
    networks:
      - monitoring
      - backend
    # No depends_on api: api belongs to the app/full profiles, so requiring it
    # makes `--profile observability` alone an invalid project. The exporter
    # polls and simply reports the target down until api is up.
    restart: unless-stopped

# ══════════════════════════════════════════════
# NETWORKS
# ══════════════════════════════════════════════

networks:
  frontend:
    name: magecode-frontend
    driver: bridge
  backend:
    name: magecode-backend
    driver: bridge
  monitoring:
    name: magecode-monitoring
    driver: bridge
  db-internal:
    name: magecode-db-internal
    driver: bridge
    internal: true
  judge0-link:
    name: magecode-judge0-link       # Shared with docker-compose.judge0.yml

# ══════════════════════════════════════════════
# VOLUMES
# ══════════════════════════════════════════════

volumes:
  postgres-data:
    name: magecode-postgres-data
  rabbitmq-data:
    name: magecode-rabbitmq-data
  minio-data:
    name: magecode-minio-data
  prometheus-data:
    name: magecode-prometheus-data
  grafana-data:
    name: magecode-grafana-data
  loki-data:
    name: magecode-loki-data
  codeql-cache:
    name: magecode-codeql-cache
  model-cache:
    name: magecode-model-cache
```

---

## 9. Monorepo Project Architecture

### 9.1. Complete Directory Tree

```
magecode/
│
├── CLAUDE.md                           # AI agent context file (root)
├── docker-compose.yml                  # MageCode services (19 containers)
├── docker-compose.judge0.yml           # Judge0 CE sub-stack (4 containers, privileged)
├── .env.example                        # Template for .env
├── .env                                # Local config (gitignored)
├── .gitignore
├── Makefile                            # Common commands (make up, make down, make logs)
├── README.md                           # Project overview, setup guide
│
├── services/
│   ├── api/                            # Laravel 13 (PHP 8.4)
│   │   ├── CLAUDE.md                   # Agent context: api service
│   │   ├── Dockerfile                  # Multi-stage: composer → PHP-FPM + nginx
│   │   ├── .env.example
│   │   ├── app/
│   │   │   ├── Console/
│   │   │   │   └── Commands/
│   │   │   │       ├── ConsumeResultExecution.php
│   │   │   │       ├── ConsumeResultAnalysis.php
│   │   │   │       └── CheckAnalysisTimeout.php
│   │   │   ├── Http/
│   │   │   │   ├── Controllers/
│   │   │   │   ├── Middleware/
│   │   │   │   └── Resources/
│   │   │   ├── Models/
│   │   │   ├── Services/
│   │   │   │   ├── RabbitMQ/
│   │   │   │   │   ├── PublisherService.php
│   │   │   │   │   └── ConsumerService.php
│   │   │   │   ├── MinIO/
│   │   │   │   │   └── StorageService.php
│   │   │   │   └── Analysis/
│   │   │   │       └── OrchestratorService.php
│   │   │   ├── Events/
│   │   │   ├── Policies/
│   │   │   └── Providers/
│   │   ├── config/
│   │   ├── database/
│   │   │   ├── migrations/              # All 35 tables
│   │   │   ├── factories/
│   │   │   └── seeders/
│   │   ├── routes/
│   │   │   ├── api.php
│   │   │   └── channels.php
│   │   ├── tests/
│   │   │   ├── Unit/
│   │   │   └── Feature/
│   │   ├── composer.json
│   │   └── phpunit.xml
│   │
│   ├── web/                            # Vue 3 + shadcn-vue (TypeScript)
│   │   ├── CLAUDE.md                   # Agent context: web service
│   │   ├── Dockerfile                  # Multi-stage: node build → nginx static
│   │   ├── nginx.conf                  # SPA fallback config
│   │   ├── src/
│   │   │   ├── components/
│   │   │   │   └── ui/                 # shadcn-vue components
│   │   │   ├── views/
│   │   │   ├── composables/
│   │   │   ├── stores/                 # Pinia stores
│   │   │   ├── router/
│   │   │   ├── lib/
│   │   │   │   ├── api.ts              # Axios instance, interceptors
│   │   │   │   └── echo.ts             # Laravel Echo (Reverb) client
│   │   │   ├── types/
│   │   │   ├── App.vue
│   │   │   └── main.ts
│   │   ├── public/
│   │   ├── package.json
│   │   ├── tsconfig.json
│   │   ├── vite.config.ts
│   │   └── tailwind.config.ts
│   │
│   ├── code-executor/                  # Go 1.26+ (CES)
│   │   ├── CLAUDE.md                   # Agent context: CES
│   │   ├── Dockerfile                  # Multi-stage: go build → scratch/alpine
│   │   ├── cmd/
│   │   │   └── main.go                 # Entry point
│   │   ├── internal/
│   │   │   ├── consumer/               # RabbitMQ consumer
│   │   │   ├── judge0/                 # Judge0 API client
│   │   │   ├── repository/             # DB queries (sqlx)
│   │   │   └── handler/                # Business logic
│   │   ├── go.mod
│   │   └── go.sum
│   │
│   ├── plagiarism-checker/             # Go 1.26+ (SIM)
│   │   ├── CLAUDE.md                   # Agent context: SIM
│   │   ├── Dockerfile
│   │   ├── cmd/
│   │   │   └── main.go
│   │   ├── internal/
│   │   │   ├── consumer/               # RabbitMQ consumer
│   │   │   ├── dolos/                  # Dolos CLI wrapper
│   │   │   ├── downloader/             # Pre-signed URL downloader
│   │   │   └── handler/                # Business logic
│   │   ├── go.mod
│   │   └── go.sum
│   │
│   ├── ai-detector/                    # Python 3.12+ (AID)
│   │   ├── CLAUDE.md                   # Agent context: AID
│   │   ├── Dockerfile                  # Multi-stage: deps → runtime
│   │   ├── src/
│   │   │   ├── __init__.py
│   │   │   ├── main.py                 # Entry point
│   │   │   ├── consumer.py             # RabbitMQ consumer (pika)
│   │   │   ├── model.py                # CodeBERT inference
│   │   │   ├── downloader.py           # Pre-signed URL downloader
│   │   │   └── handler.py              # Business logic
│   │   ├── requirements.txt
│   │   └── pyproject.toml
│   │
│   └── vuln-scanner/                   # Go 1.26+ (VUL)
│       ├── CLAUDE.md                   # Agent context: VUL
│       ├── Dockerfile
│       ├── cmd/
│       │   └── main.go
│       ├── internal/
│       │   ├── consumer/               # RabbitMQ consumer
│       │   ├── codeql/                 # CodeQL CLI wrapper
│       │   ├── downloader/             # Pre-signed URL downloader
│       │   └── handler/                # Business logic
│       ├── go.mod
│       └── go.sum
│
├── shared/
│   ├── go/                             # Shared Go packages (D-29)
│   │   ├── go.mod                      # Go workspace module
│   │   ├── rmq/                        # RabbitMQ: connect, reconnect, consume, publish
│   │   │   ├── connection.go
│   │   │   ├── consumer.go
│   │   │   ├── publisher.go
│   │   │   └── rmq_test.go
│   │   ├── db/                         # sqlx + pgx setup, PgBouncer-friendly
│   │   │   ├── connect.go
│   │   │   └── db_test.go
│   │   ├── storage/                    # MinIO S3 client (upload/download)
│   │   │   ├── client.go
│   │   │   └── storage_test.go
│   │   ├── logger/                     # slog wrapper: JSON, renames per D-88
│   │   │   ├── logger.go
│   │   │   └── logger_test.go
│   │   ├── config/                     # Env loading + validation
│   │   │   └── config.go
│   │   └── apperror/                   # Custom error types, no panic()
│   │       └── errors.go
│   │
│   └── schemas/                        # JSON Schema for RabbitMQ messages
│       ├── job.code-executor.v1.schema.json
│       ├── job.plagiarism-checker.v1.schema.json
│       ├── job.ai-detector.v1.schema.json
│       ├── job.vuln-scanner.v1.schema.json
│       ├── result.execution.v1.schema.json
│       └── result.analysis.v1.schema.json
│
├── deploy/
│   ├── docker/                         # All Docker config files
│   │   ├── traefik/
│   │   │   ├── traefik.yml             # Traefik static config
│   │   │   └── dynamic/
│   │   │       └── tls.yml             # TLS cert config (production)
│   │   ├── prometheus/
│   │   │   └── prometheus.yml          # Scrape targets
│   │   ├── grafana/
│   │   │   ├── datasources/
│   │   │   │   └── datasources.yml     # Prometheus + Loki datasources
│   │   │   └── dashboards/
│   │   │       ├── dashboards.yml      # Auto-provision config
│   │   │       ├── overview.json       # MageCode overview dashboard
│   │   │       ├── api.json            # API service dashboard
│   │   │       └── analysis.json       # Analysis pipeline dashboard
│   │   ├── loki/
│   │   │   └── loki.yml                # Loki config (schema, retention)
│   │   ├── pgbouncer/
│   │   │   ├── pgbouncer.ini           # Pool config (D-89)
│   │   │   └── userlist.txt            # Auth file
│   │   ├── rabbitmq/
│   │   │   └── enabled_plugins         # [rabbitmq_management,rabbitmq_prometheus].
│   │   ├── judge0/
│   │   │   └── judge0.conf             # Judge0 CE config (security-hardened)
│   │   ├── postgres/
│   │   │   └── init.sql                # Create extensions, initial setup
│   │   └── nginx/
│   │       └── api.conf                # nginx config for api container
│   │
│   └── k8s/                            # Future Kubernetes manifests
│       └── README.md                   # Placeholder
│
├── docs/
│   ├── technical-design.md             # Phase 1 technical design doc
│   ├── database-schema.md              # Schema v2 (35 tables)
│   ├── rabbitmq-schemas.md             # Message schemas v1
│   ├── docker-compose-architecture.md  # THIS document
│   ├── api-contracts/                  # OpenAPI specs
│   │   └── openapi.yml
│   ├── decision-log/
│   │   ├── decisions-v1.md              # D-01 to D-33
│   │   ├── decisions-v2.md              # D-34 to D-79e
│   │   └── decisions-v3.md             # D-80 to D-89
│   └── erd/
│       └── magecode-erd-v2.mermaid
│
└── scripts/
    ├── setup.sh                        # First-time setup: create .env, init MinIO bucket
    ├── seed.sh                         # Seed dev data: users, org, course, problems
    ├── reset.sh                        # Drop all volumes, fresh start
    └── backup.sh                       # Backup PostgreSQL + MinIO data
```

### 9.2. CLAUDE.md (Root Agent Context File)

```markdown
# MageCode 2.0 — Agent Context

## What is this project?
MageCode is a microservices-based code assessment platform for university education.
Students submit code, the system auto-grades and analyzes for plagiarism, AI-generation,
and security vulnerabilities.

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
- `shared/go/` — RabbitMQ, DB, MinIO, logger, config, errors
- `shared/schemas/` — JSON Schema for all RabbitMQ messages

## Commands
```bash
docker compose up -d                                          # Core services + Loki
docker compose --profile analysis up -d                       # + analysis workers
docker compose --profile full up -d                           # All MageCode containers
docker compose -f docker-compose.judge0.yml up -d             # Judge0 CE (separate)
make logs service=api                                         # Tail logs for a service
make seed                                                     # Seed dev data
```

## Per-Service Context
Each service has its own `CLAUDE.md` with:
- Purpose and responsibilities
- Tech stack and dependencies
- Key files and entry points
- Environment variables
- Testing instructions
```

### 9.3. Go Workspace Setup

The three Go services share packages via Go workspace:

```
# magecode/go.work
go 1.22

use (
    ./services/code-executor
    ./services/plagiarism-checker
    ./services/vuln-scanner
    ./shared/go
)
```

Each Go service's `go.mod` references shared packages:

```go
// services/code-executor/go.mod
module github.com/magecode/code-executor

go 1.22

require (
    github.com/magecode/shared/go v0.0.0
)

replace github.com/magecode/shared/go => ../../shared/go
```

**Dockerfile context for Go services** is set to monorepo root (`.`) so the build can access `shared/go/`:

```dockerfile
# services/code-executor/Dockerfile
FROM golang:1.22-alpine AS builder
WORKDIR /build

# Copy shared packages first (cache layer)
COPY shared/go/ shared/go/
COPY services/code-executor/ services/code-executor/

WORKDIR /build/services/code-executor
RUN go build -o /app/code-executor ./cmd/main.go

FROM alpine:3.19
COPY --from=builder /app/code-executor /usr/local/bin/
CMD ["code-executor"]
```

---

## 10. Prometheus Scrape Config

```yaml
# deploy/docker/prometheus/prometheus.yml
global:
  scrape_interval: 15s
  evaluation_interval: 15s

scrape_configs:
  # ── Traefik ──
  - job_name: "traefik"
    static_configs:
      - targets: ["traefik:8082"]    # FIX #2: metrics entryPoint on 8082

  # ── PHP-FPM ──
  - job_name: "php-fpm"
    static_configs:
      - targets: ["php-fpm-exporter:9253"]

  # ── PostgreSQL ──
  - job_name: "postgres"
    static_configs:
      - targets: ["postgres-exporter:9187"]

  # ── PgBouncer ──
  - job_name: "pgbouncer"
    static_configs:
      - targets: ["pgbouncer-exporter:9127"]

  # ── RabbitMQ ──
  - job_name: "rabbitmq"
    static_configs:
      - targets: ["rabbitmq:15692"]
    metrics_path: /metrics

  # ── MinIO ──
  - job_name: "minio"
    static_configs:
      - targets: ["minio:9000"]
    metrics_path: /minio/v2/metrics/cluster

  # ── Go Services (promhttp) ──
  - job_name: "code-executor"
    static_configs:
      - targets: ["code-executor:9090"]
    metrics_path: /metrics

  - job_name: "plagiarism-checker"
    static_configs:
      - targets: ["plagiarism-checker:9090"]
    metrics_path: /metrics

  - job_name: "vuln-scanner"
    static_configs:
      - targets: ["vuln-scanner:9090"]
    metrics_path: /metrics

  # ── AI Detector (prometheus-client) ──
  - job_name: "ai-detector"
    static_configs:
      - targets: ["ai-detector:9090"]
    metrics_path: /metrics
```

---

## 11. Grafana Datasource Provisioning

```yaml
# deploy/docker/grafana/datasources/datasources.yml
apiVersion: 1

datasources:
  - name: Prometheus
    type: prometheus
    access: proxy
    url: http://prometheus:9090
    isDefault: true
    editable: false

  - name: Loki
    type: loki
    access: proxy
    url: http://loki:3100
    editable: false
    jsonData:
      derivedFields:
        - name: TraceID
          matcherRegex: '"trace_id":"([a-f0-9-]+)"'
          url: ""
          datasourceUid: ""
```

---

## 12. Health Check & Startup Order

### 12.1. Dependency Graph (startup order)

```
Level 0 (no deps):     postgres, loki
Level 1:               pgbouncer (← postgres)
Level 2:               rabbitmq, minio, prometheus
Level 3:               minio-init (← minio)
                        api (← pgbouncer, rabbitmq, minio)
                        code-executor (← pgbouncer, rabbitmq) + judge0-server via judge0-link
                        grafana (← prometheus, loki)
Level 4:               reverb (← pgbouncer)
                        web (no deps — static SPA)
                        plagiarism-checker (← rabbitmq)
                        ai-detector (← rabbitmq)
                        vuln-scanner (← rabbitmq)
                        exporters (← postgres/pgbouncer/api)
Separate:              Judge0 stack (own dependency chain, independent lifecycle)
```

### 12.2. Health Checks Summary

| Container | Health Check | Interval | Start Period |
|---|---|---|---|
| postgres | `pg_isready` | 10s | 10s |
| pgbouncer | `pg_isready -p 6432` | 10s | — |
| rabbitmq | `rabbitmq-diagnostics ping` | 15s | 30s |
| minio | `curl /minio/health/live` | 15s | — |
| api | `curl /api/health` | 30s | 30s |

---

## 13. Makefile (Developer Experience)

```makefile
# Makefile
.PHONY: up down logs seed reset backup status

# Start core services + Loki
up:
	docker compose up -d

# Start with analysis workers
up-analysis:
	docker compose --profile analysis up -d

# Start everything (MageCode)
up-full:
	docker compose --profile full up -d

# Start Judge0 (separate stack)
up-judge0:
	docker network create magecode-judge0-link 2>/dev/null || true
	docker compose -f docker-compose.judge0.yml up -d

# Start everything (MageCode + Judge0)
up-all: up-full up-judge0

# Stop all
down:
	docker compose --profile full down
	docker compose -f docker-compose.judge0.yml down

# Tail logs for a service
logs:
	docker compose logs -f $(service)

# Tail all application logs
logs-all:
	docker compose logs -f api reverb code-executor plagiarism-checker ai-detector vuln-scanner

# Run Laravel migrations
migrate:
	docker compose exec api php artisan migrate

# Seed dev data
seed:
	docker compose exec api php artisan db:seed

# Reset everything (destructive)
reset:
	docker compose --profile full down -v
	docker compose -f docker-compose.judge0.yml down -v
	docker compose up -d

# Backup database
backup:
	./scripts/backup.sh

# Show container status
status:
	docker compose --profile full ps
	docker compose -f docker-compose.judge0.yml ps

# Open Grafana
grafana:
	@echo "http://localhost:3000"

# Open RabbitMQ management
rabbitmq:
	@echo "http://localhost:$(RABBITMQ_MANAGEMENT_PORT)"

# Test Judge0 health
judge0-health:
	@curl -s http://localhost:2358/system_info | head -20
```

---

## 14. Security Considerations

### 14.1. Network Isolation

- `postgres` is on `db-internal` (internal: true) — unreachable from outside Docker.
- SIM/AID/VUL have NO credentials for PgBouncer or PostgreSQL.
- Traefik Dashboard and RabbitMQ Management UI exposed only in dev (disable `ports:` in production).
- MinIO Console port exposed only in dev.

### 14.2. Secret Management

- All secrets in `.env` file, never in `docker-compose.yml`.
- `.env` is in `.gitignore`.
- `.env.example` has placeholder values with `# REQUIRED` markers.
- Production: consider Docker secrets or external vault.

### 14.3. Docker Socket

Traefik requires read-only access to Docker socket (`/var/run/docker.sock:ro`). This is standard for Traefik but should be noted as a security consideration. In hardened environments, use Traefik's Docker socket proxy.

---

## 15. Judge0 CE Sub-stack (D-90)

### 15.1. Why Separate Compose File

Judge0 is deployed as an independent `docker-compose.judge0.yml` for these reasons:

- **`privileged: true`** — Judge0 uses `isolate` sandbox which requires cgroup v1 access. Privileged containers have full host access. Isolating them limits blast radius.
- **Independent lifecycle** — Judge0 updates on its own release schedule (currently v1.13.1, GPL-3.0). MageCode can upgrade Judge0 without touching application services.
- **Separate data** — Judge0 has its own PostgreSQL and Redis. No shared state with MageCode database.
- **Resource isolation** — `docker compose restart` on MageCode won't kill in-progress code executions.

### 15.2. Integration Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  docker-compose.yml (MageCode — 19 containers)              │
│                                                             │
│  code-executor (Go) ────── HTTP POST ──────┐                │
│    reads submission from DB                │                │
│    calls Judge0 API per test case          │                │
│    writes results back to DB               │                │
│                                            │                │
│  backend network                           │                │
└────────────────────────────────────────────┼────────────────┘
                                             │
                                    judge0-link network
                                    (external, shared)
                                             │
┌────────────────────────────────────────────┼────────────────┐
│  docker-compose.judge0.yml (4 containers)  │                │
│                                            ▼                │
│  judge0-server (:2358) ◄──── privileged: true               │
│       │                                                     │
│       ├──► judge0-worker ◄──── privileged: true             │
│       │                                                     │
│       ├──► judge0-db (postgres:16)                          │
│       │                                                     │
│       └──► judge0-redis (redis:7)                           │
│                                                             │
│  judge0-internal network (isolated)                         │
└─────────────────────────────────────────────────────────────┘
```

CES calls Judge0 via `POST http://judge0-server:2358/submissions?wait=true` with the submission source code, language_id, stdin (test input), and resource limits. Judge0 compiles, executes in isolate sandbox, and returns stdout, stderr, status, time, and memory.

### 15.3. `docker-compose.judge0.yml`

```yaml
# docker-compose.judge0.yml
# Judge0 CE v1.13.1 — Code execution engine for MageCode
# SEPARATE from main compose due to privileged: true requirement
#
# Usage:
#   docker compose -f docker-compose.judge0.yml up -d
#
# Prerequisites:
#   - Host must support cgroup v1 (see §17 Host Prerequisites)
#   - External network 'magecode-judge0-link' must exist

services:
  judge0-server:
    image: judge0/judge0:1.13.1
    container_name: magecode-judge0-server
    volumes:
      - ./deploy/docker/judge0/judge0.conf:/judge0.conf:ro
    ports:
      - "2358:2358"                  # Dev only — remove in production
    privileged: true                 # Required for isolate sandbox
    networks:
      - judge0-internal
      - judge0-link                  # Shared with MageCode code-executor
    restart: always
    logging:
      driver: json-file
      options:
        max-size: "100m"

  judge0-worker:
    image: judge0/judge0:1.13.1
    container_name: magecode-judge0-worker
    command: ["./scripts/workers"]
    volumes:
      - ./deploy/docker/judge0/judge0.conf:/judge0.conf:ro
    privileged: true                 # Required for isolate sandbox
    networks:
      - judge0-internal
    restart: always
    logging:
      driver: json-file
      options:
        max-size: "100m"

  judge0-db:
    image: postgres:16-alpine
    container_name: magecode-judge0-db
    environment:
      POSTGRES_DB: judge0
      POSTGRES_USER: ${JUDGE0_DB_USER:-judge0}
      POSTGRES_PASSWORD: ${JUDGE0_DB_PASSWORD}
    volumes:
      - judge0-postgres-data:/var/lib/postgresql/data
    networks:
      - judge0-internal
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U judge0 -d judge0"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: always

  judge0-redis:
    image: redis:7-alpine
    container_name: magecode-judge0-redis
    command:
      - "bash"
      - "-c"
      - 'docker-entrypoint.sh --appendonly no --requirepass "$$REDIS_PASSWORD"'
    environment:
      REDIS_PASSWORD: ${JUDGE0_REDIS_PASSWORD}
    volumes:
      - judge0-redis-data:/data
    networks:
      - judge0-internal
    restart: always

networks:
  judge0-internal:
    name: magecode-judge0-internal
    driver: bridge
  judge0-link:
    name: magecode-judge0-link
    external: true                   # Created by MageCode compose or manually

volumes:
  judge0-postgres-data:
    name: magecode-judge0-postgres-data
  judge0-redis-data:
    name: magecode-judge0-redis-data
```

### 15.4. Judge0 Configuration

```conf
# deploy/docker/judge0/judge0.conf
# Security-hardened configuration for MageCode

# ── Database ──
POSTGRES_HOST=judge0-db
POSTGRES_DB=judge0
POSTGRES_USER=judge0
POSTGRES_PASSWORD=<CHANGE_ME>

# ── Redis ──
REDIS_HOST=judge0-redis
REDIS_PORT=6379
REDIS_PASSWORD=<CHANGE_ME>

# ── Authentication ──
# Only CES should call Judge0 — protect with auth token
AUTHN_HEADER=X-Auth-Token
AUTHN_TOKEN=<GENERATE_STRONG_TOKEN>

# ── Security Hardening ──
# CRITICAL: Disable network access in sandbox (prevents SSRF CVE-2024-29021)
ALLOW_ENABLE_NETWORK=false

# Disable features not needed by MageCode
ENABLE_BATCHED_SUBMISSIONS=true
ENABLE_ADDITIONAL_FILES=false
ENABLE_CALLBACKS=false

# ── Resource Limits ──
CPU_TIME_LIMIT=5
MAX_CPU_TIME_LIMIT=15
CPU_EXTRA_TIME=1
WALL_TIME_LIMIT=10
MAX_WALL_TIME_LIMIT=30
MEMORY_LIMIT=256000
MAX_MEMORY_LIMIT=512000
STACK_LIMIT=64000
MAX_STACK_LIMIT=128000
MAX_FILE_SIZE=4096
MAX_PROCESSES_AND_OR_THREADS=60

# ── Workers ──
RAILS_MAX_THREADS=8
```

### 15.5. MageCode Side — Judge0 Link Network

Add to `docker-compose.yml` networks section:

```yaml
networks:
  # ... existing networks ...
  judge0-link:
    name: magecode-judge0-link       # Shared with Judge0 compose
```

Add `judge0-link` to `code-executor`:

```yaml
  code-executor:
    networks:
      - backend
      - judge0-link                  # Access Judge0 API
```

### 15.6. Judge0 Environment Variables in `.env`

```env
# ── Judge0 (separate compose) ──
JUDGE0_DB_USER=judge0
JUDGE0_DB_PASSWORD=                  # REQUIRED: different from MageCode PG password
JUDGE0_REDIS_PASSWORD=               # REQUIRED
JUDGE0_AUTH_TOKEN=                   # REQUIRED: CES uses this to authenticate
CES_JUDGE0_URL=http://judge0-server:2358
CES_JUDGE0_AUTH_TOKEN=${JUDGE0_AUTH_TOKEN}
```

---

## 16. Resource Estimates (Updated)

Estimated RAM usage for all 23 containers on a single BKCS/HUST server:

| Category | Containers | Estimated RAM |
|---|---|---|
| Application | api (PHP-FPM 50 workers) | ~500 MB |
| Application | web (nginx static) | ~50 MB |
| Application | reverb | ~100 MB |
| Application | code-executor | ~100 MB |
| Application | plagiarism-checker | ~200 MB |
| Application | ai-detector (CodeBERT) | ~2 GB (CPU, capped at 3G) |
| Application | vuln-scanner (CodeQL) | ~500 MB (capped at 2G) |
| Data | postgres | ~500 MB |
| Data | pgbouncer | ~50 MB |
| Data | rabbitmq | ~200 MB |
| Data | minio | ~200 MB |
| Routing | traefik | ~50 MB |
| Logging | loki | ~200 MB |
| Observability | prometheus + grafana | ~400 MB |
| Exporters | 3 exporters | ~100 MB |
| **Judge0** | server + worker | ~500 MB |
| **Judge0** | judge0-db + judge0-redis | ~300 MB |
| **Judge0 image** | (disk, not RAM) | ~1.9 GB disk for compilers |
| **TOTAL** | **23 containers** | **~6–7 GB RAM** |

**Recommendation:** 16 GB RAM minimum. 32 GB recommended if running ai-detector with GPU or scaling Judge0 workers.

---

## 17. Host Prerequisites

### 17.1. cgroup v1 for Judge0

Judge0 uses `isolate` sandbox which requires cgroup v1. Modern Linux distributions (Ubuntu 24.04+) default to cgroup v2.

**Ubuntu 22.04 LTS (recommended):** cgroup v1 works out of the box. No changes needed.

**Ubuntu 24.04 LTS:** Edit `/etc/default/grub`:

```bash
GRUB_CMDLINE_LINUX_DEFAULT="quiet systemd.unified_cgroup_hierarchy=0 systemd.legacy_systemd_cgroup_controller=1"
```

Then:

```bash
sudo update-grub
sudo reboot
```

Verify after reboot:

```bash
stat -fc %T /sys/fs/cgroup/
# Should output: tmpfs (cgroup v1)
# If output is: cgroup2fs → cgroup v2 still active, fix grub
```

### 17.2. Docker Loki Plugin

```bash
docker plugin install grafana/loki-docker-driver:3-amd64 --alias loki --grant-all-permissions
docker plugin ls  # Verify: loki enabled
```

### 17.3. Docker Compose v2

```bash
docker compose version  # Must be v2.20+ for profiles support
```

### 17.4. Minimum System Requirements

| Resource | Minimum | Recommended |
|---|---|---|
| RAM | 16 GB | 32 GB |
| CPU | 4 cores | 8 cores |
| Disk | 50 GB (SSD) | 100 GB (SSD) |
| OS | Ubuntu 22.04 LTS | Ubuntu 22.04 LTS |

---

## 18. Decision Traceability

| Section | Decisions Referenced |
|---|---|
| Container Inventory | D-22, D-31, D-32, D-33, D-83, D-87, D-90 |
| Network Architecture | D-80, D-81, D-89 |
| Traefik Routing | D-22, D-86 |
| Loki Log Aggregation | D-87, D-88 |
| Environment Config | D-80, D-81, D-85, D-89 |
| PgBouncer Settings | D-89 |
| Stateless Workers | D-80 |
| CES DB Access | D-81 |
| Pre-signed URLs | D-85 |
| Monorepo Structure | D-23, D-29 |
| Judge0 Sub-stack | D-90 (new — Judge0 CE separated due to privileged requirement) |

### New Decision: D-90

| # | Decision | Conclusion | Rationale |
|---|---|---|---|
| **D-90** | Judge0 deployment | Separate `docker-compose.judge0.yml` | `privileged: true` isolation, independent lifecycle, own PG+Redis, security blast radius control |

*— End of Docker Compose & Project Architecture v1.1 —*
