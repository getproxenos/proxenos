# =============================================================================
# Makefile — bug-free-happiness developer convenience targets
# =============================================================================
MAKEFLAGS += --no-print-directory

# Host UID/GID exported so compose can pass them to serversideup (PUID/PGID) for
# correct bind-mount ownership in dev.
UID := $(shell id -u)
GID := $(shell id -g)
export UID
export GID

COMPOSE := docker compose -f compose.common.yaml -f compose.dev.yaml

.PHONY: help
help: ## Show available targets
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  %-18s %s\n", $$1, $$2}'

.PHONY: setup
setup: ## Install git hooks (core.hooksPath = .githooks)
	git config core.hooksPath .githooks
	@echo "Git hooks installed (core.hooksPath = .githooks)."

.PHONY: install
install: ## Install PHP dependencies (run in the nix shell)
	composer install

.PHONY: dev
dev: ## Build local dev images (app + worker)
	docker buildx bake dev --load

.PHONY: build
build: ## Build prod images locally (no push)
	docker buildx bake prod --load

.PHONY: up
up: ## Start the dev stack (foreground)
	$(COMPOSE) up

.PHONY: up-d
up-d: ## Start the dev stack (detached)
	$(COMPOSE) up -d

.PHONY: down
down: ## Stop the dev stack
	$(COMPOSE) down

.PHONY: logs
logs: ## Tail stack logs
	$(COMPOSE) logs -f

.PHONY: migrate
migrate: ## Run Doctrine migrations inside the app container
	$(COMPOSE) exec -T app php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: shell
shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

.PHONY: smoke
smoke: ## Run the symfony/ai smoke test inside the app container
	$(COMPOSE) exec -T app php bin/console app:ai:smoke

.PHONY: cs
cs: ## Apply php-cs-fixer to src/ and tests/
	vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

.PHONY: stan
stan: ## Run PHPStan static analysis
	vendor/bin/phpstan analyze --no-progress

.PHONY: test
test: ## Run the PHPUnit suite
	composer test

.PHONY: lint
lint: ## Run linters (php-cs-fixer dry-run, phpstan, yamllint, hadolint)
	vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php
	vendor/bin/phpstan analyze --no-progress
	yamllint .
	hadolint docker/php/Dockerfile

# ---- frontend (scaffolded in the post-bootstrap frontend slice) -------------
.PHONY: front-install front-build front-dev
front-install front-build front-dev: ## (placeholder) JS toolchain not scaffolded yet
	@echo "frontend/ is not scaffolded yet — pending the Node/Vite(+) research pass."

.DEFAULT_GOAL := help
