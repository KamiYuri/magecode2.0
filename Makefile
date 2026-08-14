.PHONY: up down logs seed reset status up-app up-analysis up-full up-judge0 up-all dev-api dev-web dev-reverb migrate fresh logs-all backup judge0-health

# ── Infrastructure ──
up:  ## Dev: infrastructure only (postgres, rabbitmq, minio, loki, etc.)
	docker network create magecode-judge0-link 2>/dev/null || true
	docker compose up -d

up-app:  ## Deploy: infrastructure + app services (api, web, reverb, code-executor, traefik)
	docker network create magecode-judge0-link 2>/dev/null || true
	docker compose --profile app up -d

up-analysis:
	docker network create magecode-judge0-link 2>/dev/null || true
	docker compose --profile analysis up -d

up-full:
	docker network create magecode-judge0-link 2>/dev/null || true
	docker compose --profile full up -d

up-judge0:
	docker network create magecode-judge0-link 2>/dev/null || true
	docker compose -f docker-compose.judge0.yml up -d

up-all: up-full up-judge0

down:
	docker compose --profile full down
	docker compose -f docker-compose.judge0.yml down 2>/dev/null || true

# ── Local Dev ──
dev-api:
	cd services/api && php artisan serve --port=8000

dev-web:
	cd services/web && npm run dev

dev-reverb:
	cd services/api && php artisan reverb:start --port=8080 $(if $(debug),--debug,)

# ── API quality gates (run in Docker: host PHP lacks pdo_pgsql/amqp) ──
# The contract is mounted one level above the app root so the suite's route
# conformance test finds it by walking up, exactly as it does on the host.
#
# The MinIO-backed tests skip unless MINIO_TEST_* reaches the container. Gate on
# MINIO_ROOT_PASSWORD (the one .env marks REQUIRED) and default the rest, so a
# half-exported environment skips cleanly instead of failing with a 403:
#   set -a && . ./.env && set +a && make test-api
MINIO_TEST_ENV = $(if $(MINIO_ROOT_PASSWORD),\
	-e MINIO_TEST_ENDPOINT=http://minio:9000 \
	-e MINIO_TEST_ACCESS_KEY=$(or $(MINIO_ROOT_USER),magecode) \
	-e MINIO_TEST_SECRET_KEY=$(MINIO_ROOT_PASSWORD) \
	-e MINIO_TEST_BUCKET=$(or $(MINIO_BUCKET),magecode),)

# Same gate for the RabbitMQ-backed publisher tests, keyed on the password .env
# marks REQUIRED. The URL mirrors RMQ_TEST_URL in the Go integration suites.
RMQ_TEST_ENV = $(if $(RABBITMQ_DEFAULT_PASS),\
	-e RMQ_TEST_URL=amqp://$(or $(RABBITMQ_DEFAULT_USER),magecode):$(RABBITMQ_DEFAULT_PASS)@rabbitmq:5672/$(or $(RABBITMQ_DEFAULT_VHOST),magecode),)

API_RUN = docker run --rm --network magecode-backend -v $(PWD)/services/api:/var/www/html \
	-v $(PWD)/docs/api-contracts:/var/www/docs/api-contracts:ro \
	-v $(PWD)/shared/schemas:/var/www/shared/schemas:ro \
	-e DB_HOST=postgres -e DB_PORT=5432 $(MINIO_TEST_ENV) $(RMQ_TEST_ENV) magecode-api:test

api-image:
	docker build --target test -t magecode-api:test services/api

test-api: api-image
	$(API_RUN) php artisan test

lint-api: api-image
	$(API_RUN) ./vendor/bin/pint --test
	$(API_RUN) ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M

composer-api: api-image
	$(API_RUN) composer $(args)

# ── Database ──
# Run through the api image against PgBouncer: the host PHP has no pdo_pgsql.
DB_RUN = docker run --rm --network magecode-backend -v $(PWD)/services/api:/var/www/html \
	-e DB_HOST=pgbouncer -e DB_PORT=6432 magecode-api:test

migrate: api-image
	$(DB_RUN) php artisan migrate --force

seed: api-image
	$(DB_RUN) php artisan db:seed --force

fresh: api-image
	$(DB_RUN) php artisan migrate:fresh --seed --force

# ── Logs ──
logs:
	docker compose logs -f $(service)

logs-all:
	docker compose logs -f api reverb code-executor plagiarism-checker ai-detector vuln-scanner

# ── Maintenance ──
reset:
	docker compose --profile full down -v
	docker compose -f docker-compose.judge0.yml down -v 2>/dev/null || true
	docker compose up -d

status:
	@echo "═══ MageCode Stack ═══"
	@docker compose --profile full ps
	@echo ""
	@echo "═══ Judge0 Stack ═══"
	@docker compose -f docker-compose.judge0.yml ps 2>/dev/null || echo "(not running)"

backup:
	./scripts/backup.sh

judge0-health:
	@curl -sf http://localhost:2358/system_info | python3 -m json.tool 2>/dev/null || echo "Judge0 not reachable"
