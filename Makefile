# GEREQ / RealEstates — Docker-only workflow (no PHP on host)
#
# Requirements: Docker + docker compose. Nothing else (no PHP / Composer / Bun on host).
#
# Quick start:
#   make install          # .env + image + vendor + assets + migrate + up
#   make up               # start stack (detach)
#   make down             # stop
#   make help
#
# Optional:
#   make up PORT=8090
#   make up HTTPS=1
#   make demo             # install including demo data
#   make gereq ARGS="cache:clear"
#   make shell

.DEFAULT_GOAL := help

ROOT        := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
PORT        ?= 8080
HTTPS       ?= 0
BUILD       ?= 0
SEED_FLAGS  ?= --fresh --seed --no-demo --no-sample --no-interaction
DEMO_FLAGS  ?= --fresh --seed --no-interaction

COMPOSE_FILES := -f $(ROOT)/docker-compose.yml
ifeq ($(HTTPS),1)
COMPOSE_FILES += -f $(ROOT)/docker-compose.https.yml
endif

export GEREQ_PORT := $(PORT)
export APP_URL    := $(if $(filter 1,$(HTTPS)),https://127.0.0.1,http://127.0.0.1:$(PORT))
export TRUSTED_PROXIES := *

COMPOSE := docker compose $(COMPOSE_FILES) --project-directory $(ROOT)
PHP_RUN := $(COMPOSE) run --rm --no-deps php
PHP_EXEC := $(COMPOSE) exec php
BUN_IMG := oven/bun:1.2
BUN_RUN := docker run --rm -u "$$(id -u):$$(id -g)" -v "$(ROOT):/app" -w /app $(BUN_IMG)

.PHONY: help check-docker env certs build up down restart logs ps shell bash \
	composer assets migrate migrate-fresh seed install demo gereq \
	adminer adminer-down hot status urls

help: ## Show help
	@printf '\n\033[1mGEREQ — Docker only\033[0m (bez PHP na hostiteli)\n\n'
	@printf '  make install          první setup: .env, build, composer, assets, migrace, up\n'
	@printf '  make demo             jako install + demo data (demo@gereq.cz / demo123)\n'
	@printf '  make up               spustit stack (PORT=%s HTTPS=%s)\n' '$(PORT)' '$(HTTPS)'
	@printf '  make down             zastavit stack\n'
	@printf '  make logs             sledovat logy\n'
	@printf '  make shell            bash v PHP kontejneru\n'
	@printf '  make gereq ARGS="…"   php gereq … uvnitř kontejneru\n'
	@printf '  make migrate          migrace (bez fresh)\n'
	@printf '  make assets           bun install + build (Docker image)\n'
	@printf '  make composer         composer install (Docker)\n'
	@printf '  make adminer          Adminer na :8086\n'
	@printf '  make hot              Bun asset watch (Docker bun → public/build)\n'
	@printf '  make urls             vypsat URL\n'
	@printf '\n  Proměnné: PORT=8080 HTTPS=0 BUILD=1\n'
	@printf '  Příklad:  make up PORT=8090 HTTPS=1 BUILD=1\n\n'

check-docker:
	@command -v docker >/dev/null 2>&1 || { echo 'Chybí docker.'; exit 1; }
	@docker compose version >/dev/null 2>&1 || { echo 'Chybí docker compose.'; exit 1; }

env: check-docker ## Create .env from .env.example (if missing)
	@if [ -f "$(ROOT)/.env" ]; then \
		echo '✓ .env už existuje'; \
	else \
		test -f "$(ROOT)/.env.example" || { echo 'Chybí .env.example'; exit 1; }; \
		cp "$(ROOT)/.env.example" "$(ROOT)/.env"; \
		APP_KEY=$$(openssl rand -hex 32); \
		DB_PASS=$$(openssl rand -base64 24 | tr -d '/+=' | head -c 32); \
		sed -i \
			-e "s|^APP_KEY=.*|APP_KEY=$$APP_KEY|" \
			-e "s|^DB_PASSWORD=.*|DB_PASSWORD=$$DB_PASS|" \
			-e "s|^APP_URL=.*|APP_URL=http://127.0.0.1:$(PORT)|" \
			-e "s|^APP_DEBUG=.*|APP_DEBUG=true|" \
			-e "s|^DB_CONNECTION=.*|DB_CONNECTION=pgsql|" \
			-e "s|^DB_HOST=.*|DB_HOST=postgres|" \
			-e "s|^REDIS_HOST=.*|REDIS_HOST=redis|" \
			-e "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|" \
			-e "s|^QUEUE_DRIVER=.*|QUEUE_DRIVER=redis|" \
			-e "s|^CACHE_DRIVER=.*|CACHE_DRIVER=redis|" \
			"$(ROOT)/.env"; \
		mkdir -p "$(ROOT)/storage/"{cache,logs,sessions,tmp/queue,uploads,invoices,database}; \
		chmod -R a+rwX "$(ROOT)/storage" 2>/dev/null || true; \
		echo '✓ .env vytvořen (APP_KEY + DB_PASSWORD vygenerovány, Docker Postgres/Redis)'; \
	fi

certs: check-docker ## Self-signed HTTPS certs (for HTTPS=1)
	@mkdir -p "$(ROOT)/docker/nginx/certs"
	@if [ -f "$(ROOT)/docker/nginx/certs/dev.crt" ] && [ -f "$(ROOT)/docker/nginx/certs/dev.key" ]; then \
		echo '✓ Certifikáty už existují (docker/nginx/certs/)'; \
	else \
		CNF="$(ROOT)/docker/nginx/certs/openssl.cnf"; \
		printf '%s\n' \
			'[req]' 'distinguished_name = req_distinguished_name' 'x509_extensions = v3_req' 'prompt = no' \
			'' '[req_distinguished_name]' 'CN = localhost' \
			'' '[v3_req]' \
			'subjectAltName = DNS:localhost,IP:127.0.0.1,DNS:realestates.local,DNS:realestates.dev,DNS:gereq.local,DNS:gereq.dev' \
			'basicConstraints = CA:FALSE' \
			'keyUsage = digitalSignature, keyEncipherment' \
			'extendedKeyUsage = serverAuth' > "$$CNF"; \
		if command -v openssl >/dev/null 2>&1; then \
			openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
				-keyout "$(ROOT)/docker/nginx/certs/dev.key" \
				-out "$(ROOT)/docker/nginx/certs/dev.crt" \
				-config "$$CNF"; \
		else \
			docker run --rm -v "$(ROOT)/docker/nginx/certs:/certs" alpine/openssl \
				req -x509 -nodes -newkey rsa:2048 -days 825 \
				-keyout /certs/dev.key -out /certs/dev.crt -config /certs/openssl.cnf; \
		fi; \
		rm -f "$$CNF"; \
		chmod 600 "$(ROOT)/docker/nginx/certs/dev.key" 2>/dev/null || true; \
		echo '✓ Self-signed cert vygenerován (prohlížeč jednou varuje)'; \
	fi

build: check-docker env ## Rebuild Docker image
	@$(COMPOSE) build

up: check-docker env ## Start stack in background
	@if [ "$(HTTPS)" = "1" ]; then $(MAKE) --no-print-directory certs; fi
	@echo "→ Spouštím stack (port $(PORT)$(if $(filter 1,$(HTTPS)), + HTTPS:443,))…"
	@$(COMPOSE) up -d $(if $(filter 1,$(BUILD)),--build,)
	@$(MAKE) --no-print-directory urls

down: check-docker ## Stop stack
	@$(COMPOSE) down
	@rm -f "$(ROOT)/storage/tmp/bun-assets.pid" "$(ROOT)/storage/tmp/vite-dev.pid" 2>/dev/null || true
	@echo '✓ Stack zastaven'

restart: down up ## Restart stack

logs: check-docker ## Tail logs (Ctrl+C does not stop stack)
	@$(COMPOSE) logs -f --tail=200

ps: check-docker ## Container status
	@$(COMPOSE) ps

status: ps

shell bash: check-docker ## Shell in PHP container
	@$(PHP_EXEC) bash

composer: check-docker env build ## composer install in PHP image
	@echo '→ composer install…'
	@$(PHP_RUN) composer install --no-interaction --prefer-dist
	@echo '✓ vendor/'

assets: check-docker ## Frontend build via oven/bun (no Bun on host)
	@echo '→ bun install + build…'
	@$(BUN_RUN) bun install
	@$(BUN_RUN) bun run build
	@echo '✓ public/build'

migrate: check-docker env ## Run migrations (stack must be up, or DB starts briefly)
	@echo '→ migrate…'
	@$(COMPOSE) up -d postgres redis
	@$(COMPOSE) run --rm php php gereq migrate --no-interaction
	@echo '✓ Migrace hotova'

migrate-fresh: check-docker env ## Fresh migrate + seed (clean client)
	@echo '→ migrate $(SEED_FLAGS)…'
	@$(COMPOSE) up -d postgres redis
	@$(COMPOSE) run --rm php php gereq migrate $(SEED_FLAGS)
	@echo '✓ Čistá DB + seed'

seed: check-docker ## Additional demo data
	@$(COMPOSE) run --rm php php gereq seed:demo --no-interaction

install: check-docker env build composer assets migrate-fresh up ## Full Docker setup (clean client)
	@printf '\n\033[1;32mHotovo\033[0m — Docker stack běží.\n'
	@$(MAKE) --no-print-directory urls
	@printf '  Admin účet z .env (ADMIN_EMAIL / ADMIN_PASSWORD)\n\n'

demo: check-docker env ## Setup with demo data
	@$(MAKE) --no-print-directory build
	@$(MAKE) --no-print-directory composer
	@$(MAKE) --no-print-directory assets
	@echo '→ migrate $(DEMO_FLAGS)…'
	@$(COMPOSE) up -d postgres redis
	@$(COMPOSE) run --rm php php gereq migrate $(DEMO_FLAGS)
	@$(MAKE) --no-print-directory up
	@printf '\n\033[1;32mHotovo\033[0m — demo prostředí.\n'
	@$(MAKE) --no-print-directory urls
	@printf '  Účet: demo@gereq.cz / demo123  (pokud seed nastavil ADMIN_* v .env, použijte ty)\n\n'

gereq: check-docker ## Run php gereq ARGS="…"
	@test -n "$(ARGS)" || { echo 'Použití: make gereq ARGS="migrate --seed"'; exit 1; }
	@$(COMPOSE) run --rm php php gereq $(ARGS)

adminer: check-docker env ## Start Adminer (tools profile) on :8086
	@$(COMPOSE) --profile tools up -d adminer
	@echo '✓ Adminer: http://127.0.0.1:$${GEREQ_ADMINER_PORT:-8086}'

adminer-down: check-docker
	@$(COMPOSE) --profile tools stop adminer 2>/dev/null || true
	@$(COMPOSE) --profile tools rm -f adminer 2>/dev/null || true

hot: check-docker ## Bun asset watch via Docker bun (rebuilds public/build)
	@echo '→ Bun watch (Ctrl+C ukončí jen watch, stack nechá běžet)…'
	@docker run --rm -it \
		-u "$$(id -u):$$(id -g)" \
		-v "$(ROOT):/app" \
		-w /app \
		$(BUN_IMG) \
		bun run dev

urls: ## Print access URLs
	@printf '\n'
	@if [ "$(HTTPS)" = "1" ]; then \
		printf '  HTTPS  https://127.0.0.1/admin\n'; \
		printf '  HTTP   http://127.0.0.1:%s/admin\n' '$(PORT)'; \
	else \
		printf '  Local  http://127.0.0.1:%s/admin\n' '$(PORT)'; \
		printf '         http://localhost:%s/admin\n' '$(PORT)'; \
	fi
	@printf '  Stop   make down\n'
	@printf '\n'
