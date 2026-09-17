# ─────────────────────────────────────────────────────────────────────────────
# Impact Score Dashboard — Makefile
#
# All developer + operator workflows live here. Run `make help` for the list.
# Variables can be overridden on the command line, e.g. `make build VERSION=1.0.0`.
# ─────────────────────────────────────────────────────────────────────────────

.DEFAULT_GOAL := help

# Load .env if present so `make db-shell`, `make db-dump`, etc. can read creds.
-include .env
export

COMPOSE    := docker compose
WEB        := impact_web
DB         := impact_db
PMA        := impact_pma
IMAGE      := impact-score
IMAGE_DB   := impact-score-db
CONTAINERS := $(WEB) $(DB) $(PMA)

VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)

# Optional registry prefix used by `push` / `pull` targets.
REGISTRY ?=

.PHONY: help setup up down build build-db build-all rebuild restart \
        logs ps shell db-shell db-migrate db-dump db-restore db-reset \
        seed-dev composer-install version release push pull clean

help: ## Show this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / { \
		printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

# ── Lifecycle ────────────────────────────────────────────────────────────────

setup: ## First-time: copy .env.example, build both images, start services
	@test -f .env || { cp .env.example .env && echo "Created .env — edit it, then re-run 'make setup'."; exit 1; }
	@touch .env.placeholder
	$(MAKE) build-all
	$(COMPOSE) up -d
	@echo ""
	@echo "Stack is starting. Watch with: make logs"
	@echo "Bootstrap admin token is the BOOTSTRAP_ADMIN_TOKEN value in your .env."

up: ## Start all services
	@touch .env.placeholder
	$(COMPOSE) up -d

down: ## Stop + force-remove containers including orphans
	$(COMPOSE) down --remove-orphans
	-docker rm -f $(CONTAINERS) 2>/dev/null || true

restart: ## Restart without rebuilding
	$(MAKE) down
	$(MAKE) up

# ── Image builds ─────────────────────────────────────────────────────────────

build: ## Build web image; tag :latest and :VERSION
	docker build -t $(IMAGE):latest .
	docker tag $(IMAGE):latest $(IMAGE):$(VERSION)
	@echo "Tagged: $(IMAGE):latest, $(IMAGE):$(VERSION)"

build-db: ## Build db image; tag :latest and :VERSION
	docker build -t $(IMAGE_DB):latest ./db
	docker tag $(IMAGE_DB):latest $(IMAGE_DB):$(VERSION)
	@echo "Tagged: $(IMAGE_DB):latest, $(IMAGE_DB):$(VERSION)"

build-all: build build-db ## Build both web and db images

rebuild: ## Force rebuild (no cache) of both images, then start
	$(MAKE) down
	docker build --no-cache -t $(IMAGE):latest .
	docker build --no-cache -t $(IMAGE_DB):latest ./db
	$(MAKE) up

# ── Observability ────────────────────────────────────────────────────────────

logs: ## Follow logs. Filter: make logs service=db
	@if [ -n "$(service)" ]; then $(COMPOSE) logs -f $(service); else $(COMPOSE) logs -f; fi

ps: ## Show container status
	$(COMPOSE) ps

shell: ## bash shell in the web container
	docker exec -it $(WEB) bash

db-shell: ## MySQL shell in the db container
	docker exec -it $(DB) mysql -u$${DB_USER} -p$${DB_PASSWORD} $${DB_NAME}

# ── Database ─────────────────────────────────────────────────────────────────

db-migrate: ## Apply pending migrations from db/migrations/ via host runner
	bash scripts/migrate.sh

db-dump: ## Dump database to db/backups/YYYY-MM-DD_HHMMSS.sql
	@mkdir -p db/backups
	@stamp=$$(date +%Y-%m-%d_%H%M%S); \
	docker exec $(DB) mysqldump --single-transaction --quick --routines --triggers \
	  -u$${DB_USER} -p$${DB_PASSWORD} $${DB_NAME} > db/backups/$${stamp}.sql && \
	echo "Wrote: db/backups/$${stamp}.sql"

db-restore: ## Restore: make db-restore file=path/to/dump.sql
	@test -n "$(file)" || { echo "Usage: make db-restore file=path/to/dump.sql"; exit 1; }
	@test -f "$(file)" || { echo "File not found: $(file)"; exit 1; }
	docker exec -i $(DB) mysql -u$${DB_USER} -p$${DB_PASSWORD} $${DB_NAME} < "$(file)"
	@echo "Restored from: $(file)"

db-reset: ## DANGER: destroy db volume, recreate from init.sql
	@echo "This will destroy ALL database data. Ctrl-C now to abort."
	@sleep 3
	$(MAKE) down
	-docker volume rm impact-score_db_data 2>/dev/null || true
	$(MAKE) up

seed-dev: ## Apply db/seed_dev.sql (dev convenience accounts). Refuses in production.
	@if [ "$${APP_ENV}" = "production" ]; then \
	  echo "Refusing: APP_ENV=production. Aborting."; exit 1; \
	fi
	docker exec -i $(DB) mysql -u$${DB_USER} -p$${DB_PASSWORD} $${DB_NAME} < db/seed_dev.sql
	@echo "Seed applied. See db/seed_dev.sql for credentials."

# ── Composer / dependencies ──────────────────────────────────────────────────

composer-install: ## Run composer install in a transient container (no local PHP needed)
	docker run --rm -v "$$PWD":/app -w /app composer:2 install --no-dev --optimize-autoloader

# ── Release / registry ───────────────────────────────────────────────────────

version: ## Print VERSION string
	@echo $(VERSION)

release: ## Tag git, build both images. Usage: make release VERSION=1.0.0
	@if echo "$(VERSION)" | grep -q "dirty"; then \
	  echo "Error: uncommitted changes. Commit everything first."; exit 1; \
	fi
	@if [ "$(VERSION)" = "dev" ]; then \
	  echo "Error: pass an explicit VERSION, e.g. make release VERSION=1.0.0"; exit 1; \
	fi
	git tag -a "v$(VERSION)" -m "Release v$(VERSION)"
	$(MAKE) build-all VERSION=$(VERSION)
	@echo ""
	@echo "v$(VERSION) tagged. Push with: git push origin v$(VERSION)"

push: ## Push both images to REGISTRY. Usage: make push REGISTRY=ghcr.io/org VERSION=1.0.0
	@test -n "$(REGISTRY)" || { echo "Usage: make push REGISTRY=ghcr.io/your-org [VERSION=x.y.z]"; exit 1; }
	docker tag $(IMAGE):$(VERSION)    $(REGISTRY)/$(IMAGE):$(VERSION)
	docker tag $(IMAGE):latest        $(REGISTRY)/$(IMAGE):latest
	docker tag $(IMAGE_DB):$(VERSION) $(REGISTRY)/$(IMAGE_DB):$(VERSION)
	docker tag $(IMAGE_DB):latest     $(REGISTRY)/$(IMAGE_DB):latest
	docker push $(REGISTRY)/$(IMAGE):$(VERSION)
	docker push $(REGISTRY)/$(IMAGE):latest
	docker push $(REGISTRY)/$(IMAGE_DB):$(VERSION)
	docker push $(REGISTRY)/$(IMAGE_DB):latest

pull: ## Pull both images from REGISTRY. Usage: make pull REGISTRY=ghcr.io/org VERSION=1.0.0
	@test -n "$(REGISTRY)" || { echo "Usage: make pull REGISTRY=ghcr.io/your-org [VERSION=x.y.z]"; exit 1; }
	docker pull $(REGISTRY)/$(IMAGE):$(VERSION)
	docker pull $(REGISTRY)/$(IMAGE_DB):$(VERSION)
	docker tag  $(REGISTRY)/$(IMAGE):$(VERSION)    $(IMAGE):latest
	docker tag  $(REGISTRY)/$(IMAGE_DB):$(VERSION) $(IMAGE_DB):latest

# ── Destructive ──────────────────────────────────────────────────────────────

clean: ## DANGER: remove all containers and named volumes
	@echo "This will destroy ALL local state for this stack. Ctrl-C now to abort."
	@sleep 3
	$(MAKE) down
	-docker volume rm impact-score_db_data 2>/dev/null || true
	-docker volume rm impact-score_uploads 2>/dev/null || true
