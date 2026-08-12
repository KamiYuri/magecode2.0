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
API_RUN = docker run --rm --network magecode-backend -v $(PWD)/services/api:/var/www/html \
	-e DB_HOST=postgres -e DB_PORT=5432 magecode-api:test

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
migrate:
	cd services/api && php artisan migrate

seed:
	cd services/api && php artisan db:seed

fresh:
	cd services/api && php artisan migrate:fresh --seed

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
