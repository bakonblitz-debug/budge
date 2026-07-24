# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  BudgetApp — Makefile
#  Usage: make <target>
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.DEFAULT_GOAL := help
DOCKER_COMPOSE = docker compose
APP_CONTAINER = budgetapp-app
NODE_CONTAINER = budgetapp-node
MYSQL_CONTAINER = budgetapp-mysql

# Colors
GREEN  := \033[0;32m
YELLOW := \033[0;33m
CYAN   := \033[0;36m
RESET  := \033[0m

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  🚀 Lifecycle
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: up down restart build rebuild fresh init preflight

# ──────────────────────────────────────────────
# Required files for Docker build
# ──────────────────────────────────────────────
REQUIRED_FILES = \
	docker/php/Dockerfile \
	docker/php/entrypoint.sh \
	docker/php/php-custom.ini \
	docker/php/xdebug.ini \
	docker/node/Dockerfile \
	docker/node/entrypoint.sh \
	docker/nginx/default.conf \
	docker-compose.yml

preflight: ## Verify project structure before building
	@echo "$(CYAN)🔍 Preflight check...$(RESET)"
	@MISSING=0; \
	for f in $(REQUIRED_FILES); do \
		if [ ! -f "$$f" ]; then \
			echo "   $(YELLOW)❌ Missing: $$f$(RESET)"; \
			MISSING=1; \
		fi; \
	done; \
	if [ ! -f ".env" ]; then \
		echo "   $(YELLOW)⚠️  No .env file — run 'make setup' for first-time setup$(RESET)"; \
		MISSING=1; \
	fi; \
	if [ $$MISSING -eq 1 ]; then \
		echo ""; \
		echo "$(YELLOW)⚠️  Missing files detected. Run 'make doctor' for details.$(RESET)"; \
		exit 1; \
	fi; \
	echo "   $(GREEN)✅ All required files present.$(RESET)"

up: preflight ## Start all containers
	@echo "$(GREEN)🚀 Starting BudgetApp...$(RESET)"
	$(DOCKER_COMPOSE) up -d --build
	@echo ""
	@echo "$(GREEN)✅ BudgetApp is running:$(RESET)"
	@echo "   App:   http://localhost:$${APP_PORT:-8080}"
	@echo "   Vite:  http://localhost:$${VITE_PORT:-5173}"
	@echo "   MySQL: localhost:$${DB_EXTERNAL_PORT:-3306}"
	@echo ""

down: ## Stop all containers
	@echo "$(YELLOW)⏹ Stopping BudgetApp...$(RESET)"
	$(DOCKER_COMPOSE) down

restart: ## Restart all containers
	@$(MAKE) down
	@$(MAKE) up

build: preflight ## Build containers (no cache)
	$(DOCKER_COMPOSE) build --no-cache

rebuild: preflight ## Full rebuild and start
	@$(MAKE) down
	$(DOCKER_COMPOSE) build --no-cache
	@$(MAKE) up

fresh: preflight ## Full reset: volumes, rebuild, fresh migrate + seed
	@echo "$(YELLOW)⚠️  This will destroy all data. Continue? [y/N]$(RESET)"
	@read -r ans; [ "$$ans" = "y" ] || exit 1
	$(DOCKER_COMPOSE) down -v
	$(DOCKER_COMPOSE) build --no-cache
	$(DOCKER_COMPOSE) up -d
	@echo "$(GREEN)✅ Fresh environment ready.$(RESET)"

init: preflight ## First-time setup: create Laravel project inside src/
	@echo "$(CYAN)🏗️  Initializing Laravel project...$(RESET)"
	@if [ -f "src/artisan" ]; then \
		echo "$(YELLOW)⚠️  Laravel project already exists in src/. Skipping.$(RESET)"; \
	else \
		mkdir -p src; \
		echo "$(CYAN)   Building PHP image...$(RESET)"; \
		$(DOCKER_COMPOSE) build app; \
		echo "$(CYAN)   Creating Laravel project (this may take a minute)...$(RESET)"; \
		docker run --rm -v "$$(pwd)/src:/var/www/html" --entrypoint /bin/sh budge-app \
			-c "composer create-project laravel/laravel /tmp/laravel --prefer-dist --no-interaction && cp -a /tmp/laravel/. /var/www/html/ && rm -rf /tmp/laravel"; \
		if [ -f "src/artisan" ]; then \
			echo "$(GREEN)✅ Laravel project created in src/$(RESET)"; \
		else \
			echo "$(YELLOW)❌ Laravel project creation failed. Check output above.$(RESET)"; \
			exit 1; \
		fi; \
	fi

setup: ## ⭐ First-time setup — run this on a fresh clone
	@echo ""
	@echo "$(CYAN)━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━$(RESET)"
	@echo "$(CYAN)  BudgetApp — First-Time Setup$(RESET)"
	@echo "$(CYAN)━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━$(RESET)"
	@echo ""
	@if [ ! -f ".env.example" ]; then \
		echo "$(CYAN)📋 Generating .env.example...$(RESET)"; \
		printf '%s\n' \
			'# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' \
			'#  BudgetApp — Environment Configuration' \
			'# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' \
			'' \
			'# Docker Ports' \
			'APP_PORT=8080' \
			'VITE_PORT=5173' \
			'DB_EXTERNAL_PORT=3306' \
			'' \
			'# Database' \
			'DB_DATABASE=budgetapp' \
			'DB_USERNAME=budgetapp' \
			'DB_PASSWORD=secret' \
			'DB_ROOT_PASSWORD=rootsecret' \
			'' \
			'# Xdebug (off|debug|profile|coverage)' \
			'XDEBUG_MODE=off' \
			'' \
			'# Encryption (toggle for production)' \
			'DB_ENCRYPT=false' \
			'# BLIND_INDEX_KEY=    # Generate: php -r "echo bin2hex(random_bytes(32));"' \
			> .env.example; \
	fi
	@if [ ! -f ".env" ]; then \
		echo "$(CYAN)📋 Creating .env from .env.example...$(RESET)"; \
		cp .env.example .env; \
	else \
		echo "$(GREEN)📋 .env already exists.$(RESET)"; \
	fi
	@$(MAKE) init
	@$(MAKE) up
	@echo ""
	@echo "$(GREEN)━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━$(RESET)"
	@echo "$(GREEN)  🎉 BudgetApp is ready!$(RESET)"
	@echo ""
	@echo "  App:   http://localhost:$${APP_PORT:-8080}"
	@echo "  Vite:  http://localhost:$${VITE_PORT:-5173}"
	@echo "  MySQL: localhost:$${DB_EXTERNAL_PORT:-3306}"
	@echo ""
	@echo "  Run $(CYAN)make help$(RESET) to see all commands."
	@echo "$(GREEN)━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━$(RESET)"
	@echo ""

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  🐚 Shell Access
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: shell shell-node shell-mysql

shell: ## Bash into the PHP container
	docker exec -it $(APP_CONTAINER) bash

shell-node: ## Shell into the Node container
	docker exec -it $(NODE_CONTAINER) sh

shell-mysql: ## MySQL CLI
	docker exec -it $(MYSQL_CONTAINER) mysql -u$${DB_USERNAME:-budgetapp} -p$${DB_PASSWORD:-secret} $${DB_DATABASE:-budgetapp}

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  🔧 Laravel Commands
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: artisan migrate migrate-fresh seed tinker routes

artisan: ## Run artisan command: make artisan CMD="make:model Post"
	docker exec -it $(APP_CONTAINER) php artisan $(CMD)

migrate: ## Run migrations
	docker exec -it $(APP_CONTAINER) php artisan migrate

migrate-fresh: ## Fresh migrate + seed
	docker exec -it $(APP_CONTAINER) php artisan migrate:fresh --seed

seed: ## Run seeders
	docker exec -it $(APP_CONTAINER) php artisan db:seed

tinker: ## Laravel Tinker REPL
	docker exec -it $(APP_CONTAINER) php artisan tinker

routes: ## List all routes
	docker exec -it $(APP_CONTAINER) php artisan route:list

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  📦 Dependencies
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: composer npm

composer: ## Run composer command: make composer CMD="require laravel/fortify"
	docker exec -it $(APP_CONTAINER) composer $(CMD)

npm: ## Run npm command: make npm CMD="install vuetify"
	docker exec -it $(NODE_CONTAINER) npm $(CMD)

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  🧪 Testing
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: test test-unit test-feature test-coverage test-filter

test: ## Run full test suite (Pest)
	docker exec -it $(APP_CONTAINER) php artisan test --parallel

test-unit: ## Run unit tests only
	docker exec -it $(APP_CONTAINER) php artisan test --testsuite=Unit

test-feature: ## Run feature tests only
	docker exec -it $(APP_CONTAINER) php artisan test --testsuite=Feature

test-coverage: ## Run tests with coverage (requires XDEBUG_MODE=coverage)
	docker exec -it -e XDEBUG_MODE=coverage $(APP_CONTAINER) php artisan test --coverage

test-filter: ## Run filtered tests: make test-filter F="ImportCsv"
	docker exec -it $(APP_CONTAINER) php artisan test --filter=$(F)

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  🐛 Debugging
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: debug-on debug-off debug-status debug-profile

debug-on: ## Enable Xdebug step debugging
	@echo "$(GREEN)🐛 Enabling Xdebug (debug mode)...$(RESET)"
	XDEBUG_MODE=debug $(DOCKER_COMPOSE) up -d app
	@echo "$(GREEN)✅ Xdebug active — listening on port 9003$(RESET)"
	@echo "   IDE path mapping: ./src → /var/www/html"

debug-off: ## Disable Xdebug
	@echo "$(YELLOW)🐛 Disabling Xdebug...$(RESET)"
	XDEBUG_MODE=off $(DOCKER_COMPOSE) up -d app
	@echo "$(GREEN)✅ Xdebug disabled$(RESET)"

debug-status: ## Check Xdebug status
	@docker exec $(APP_CONTAINER) php -r "echo 'Xdebug mode: ' . ini_get('xdebug.mode') . PHP_EOL;"

debug-profile: ## Enable Xdebug profiling
	@echo "$(CYAN)📊 Enabling Xdebug profiler...$(RESET)"
	XDEBUG_MODE=profile $(DOCKER_COMPOSE) up -d app
	@echo "$(GREEN)✅ Profiler active — cachegrind files in storage/$(RESET)"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  📋 Logs
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: logs logs-app logs-node logs-mysql logs-nginx logs-laravel

logs: ## Tail all container logs
	$(DOCKER_COMPOSE) logs -f

logs-app: ## Tail PHP container logs
	$(DOCKER_COMPOSE) logs -f app

logs-node: ## Tail Node/Vite container logs
	$(DOCKER_COMPOSE) logs -f node

logs-mysql: ## Tail MySQL logs
	$(DOCKER_COMPOSE) logs -f mysql

logs-nginx: ## Tail Nginx logs
	$(DOCKER_COMPOSE) logs -f nginx

logs-laravel: ## Tail Laravel log file
	@docker exec $(APP_CONTAINER) tail -f storage/logs/laravel.log 2>/dev/null || echo "No Laravel log file yet."

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  ✨ Code Quality
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: lint analyse check format

lint: ## Run Laravel Pint (PSR-12 + Laravel preset)
	docker exec -it $(APP_CONTAINER) ./vendor/bin/pint

analyse: ## Run PHPStan static analysis
	docker exec -it $(APP_CONTAINER) ./vendor/bin/phpstan analyse --memory-limit=512M

check: ## Full check: lint + analyse + test
	@echo "$(CYAN)━━━ Running full check ━━━$(RESET)"
	@$(MAKE) lint
	@$(MAKE) analyse
	@$(MAKE) test
	@echo "$(GREEN)✅ All checks passed!$(RESET)"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  📖 Help
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

.PHONY: help status

status: ## Show container status
	$(DOCKER_COMPOSE) ps

doctor: ## Full project health check with diagnostics
	@echo ""
	@echo "$(CYAN)🩺 BudgetApp Doctor$(RESET)"
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	@echo ""
	@echo "$(CYAN)📁 Required Docker files:$(RESET)"
	@for f in $(REQUIRED_FILES); do \
		if [ -f "$$f" ]; then \
			echo "   ✅ $$f"; \
		else \
			echo "   ❌ $$f $(YELLOW)(MISSING)$(RESET)"; \
		fi; \
	done
	@echo ""
	@echo "$(CYAN)⚙️  Environment:$(RESET)"
	@if [ -f ".env" ]; then echo "   ✅ .env"; else echo "   ❌ .env $(YELLOW)(run: cp .env.example .env)$(RESET)"; fi
	@if [ -f ".env.example" ]; then echo "   ✅ .env.example"; else echo "   ❌ .env.example $(YELLOW)(MISSING)$(RESET)"; fi
	@echo ""
	@echo "$(CYAN)📦 Laravel project:$(RESET)"
	@if [ -f "src/artisan" ]; then echo "   ✅ src/ (Laravel installed)"; else echo "   ⚠️  src/ $(YELLOW)(not initialized — run: make init)$(RESET)"; fi
	@if [ -f "src/package.json" ]; then echo "   ✅ src/package.json"; else echo "   ⚠️  src/package.json $(YELLOW)(not yet — created after make init)$(RESET)"; fi
	@echo ""
	@echo "$(CYAN)🐳 Docker:$(RESET)"
	@if command -v docker >/dev/null 2>&1; then echo "   ✅ docker $$(docker --version | grep -oP '\d+\.\d+\.\d+')"; else echo "   ❌ docker $(YELLOW)(not installed)$(RESET)"; fi
	@if command -v docker compose >/dev/null 2>&1 || docker compose version >/dev/null 2>&1; then echo "   ✅ docker compose available"; else echo "   ❌ docker compose $(YELLOW)(not available)$(RESET)"; fi
	@echo ""

help: ## Show this help
	@echo ""
	@echo "$(CYAN)BudgetApp — Available Commands$(RESET)"
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-18s$(RESET) %s\n", $$1, $$2}'
	@echo ""