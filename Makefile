# ──────────────────────────────────────────────────────────────
# Ashchan Makefile
#
# Development:   make up / make down / make restart
# Static Build:  make build-static   (optional, requires Linux)
# ──────────────────────────────────────────────────────────────

SHELL  := /bin/bash
ROOT   := $(shell pwd)
SERVICES := api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam
SHORT    := gateway auth boards media search moderation
PHP    := php

# Service port mapping (matches config/autoload/server.php defaults)
PORT_gateway    := 9501
PORT_auth       := 9502
PORT_boards     := 9503
PORT_media      := 9504
PORT_search     := 9505
PORT_moderation := 9506

# mTLS port mapping
MTLS_PORT_gateway    := 8443
MTLS_PORT_auth       := 8444
MTLS_PORT_boards     := 8445
MTLS_PORT_media      := 8446
MTLS_PORT_search     := 8447
MTLS_PORT_moderation := 8448

# Service directory mapping
DIR_gateway    := api-gateway
DIR_auth       := auth-accounts
DIR_boards     := boards-threads-posts
DIR_media      := media-uploads
DIR_search     := search-indexing
DIR_moderation := moderation-anti-spam

# Log directory
LOG_DIR := /tmp/ashchan

# ── Common targets ──────────────────────────────────────────

.PHONY: help up down restart status health install deps migrate seed \
        bootstrap dev-quick mtls-init mtls-certs clean \
        build-static build-static-php build-static-clean \
        varnish-start varnish-stop varnish-reload varnish-status varnish-stats varnish-ban \
        $(addprefix start-,$(SHORT)) $(addprefix stop-,$(SHORT)) \
        $(addprefix log-,$(SHORT))

help: ## Show this help
	@echo ""
	@echo "Ashchan — Microservices Imageboard"
	@echo "══════════════════════════════════════════"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'
	@echo ""

# ── Service lifecycle ───────────────────────────────────────

$(LOG_DIR):
	@mkdir -p $(LOG_DIR)

define start_service
start-$(1): | $(LOG_DIR) ## Start $(1) service
	@echo "[*] Starting $(1) (port $$(PORT_$(1)))..."
	@cd services/$$(DIR_$(1)) && \
		PORT=$$(PORT_$(1)) MTLS_PORT=$$(MTLS_PORT_$(1)) \
		$(PHP) bin/hyperf.php start > $(LOG_DIR)/$(1).log 2>&1 &
	@sleep 1 && echo "[✓] $(1) started (PID $$$$(lsof -ti :$$(PORT_$(1)) 2>/dev/null | head -1))"
endef

define stop_service
stop-$(1): ## Stop $(1) service
	@echo "[*] Stopping $(1)..."
	@lsof -ti :$$(PORT_$(1)) 2>/dev/null | xargs -r kill -15 2>/dev/null || true
	@lsof -ti :$$(MTLS_PORT_$(1)) 2>/dev/null | xargs -r kill -15 2>/dev/null || true
	@sleep 1
	@lsof -ti :$$(PORT_$(1)) 2>/dev/null | xargs -r kill -9 2>/dev/null || true
	@echo "[✓] $(1) stopped"
endef

define log_service
log-$(1): ## Tail logs for $(1)
	@tail -f $(LOG_DIR)/$(1).log 2>/dev/null || echo "No log file for $(1)"
endef

$(foreach s,$(SHORT),$(eval $(call start_service,$(s))))
$(foreach s,$(SHORT),$(eval $(call stop_service,$(s))))
$(foreach s,$(SHORT),$(eval $(call log_service,$(s))))

up: ## Start all services
	@echo "Starting all services..."
	@$(MAKE) --no-print-directory start-boards
	@sleep 2
	@$(MAKE) --no-print-directory start-auth
	@$(MAKE) --no-print-directory start-media
	@$(MAKE) --no-print-directory start-search
	@$(MAKE) --no-print-directory start-moderation
	@sleep 1
	@$(MAKE) --no-print-directory start-gateway
	@echo ""
	@echo "All services started. Run 'make status' to verify."

down: ## Stop all services
	@echo "Stopping all services..."
	@for svc in $(SHORT); do $(MAKE) --no-print-directory stop-$$svc; done
	@echo "All services stopped."

restart: down up ## Restart all services

status: ## Check which services are running
	@echo ""
	@echo "Service Status"
	@echo "═══════════════════════════════════════"
	@for svc in $(SHORT); do \
		port=$${PORT_$${svc}}; \
		pid=$$(lsof -ti :$$port 2>/dev/null | head -1); \
		if [ -n "$$pid" ]; then \
			printf "  %-14s \033[32m● running\033[0m  (port $$port, PID $$pid)\n" "$$svc"; \
		else \
			printf "  %-14s \033[31m○ stopped\033[0m  (port $$port)\n" "$$svc"; \
		fi; \
	done
	@echo ""

health: ## Health-check all services
	@echo "Running health checks..."
	@for svc in $(SHORT); do \
		port=$$(eval echo \$$PORT_$$svc); \
		if curl -sf http://localhost:$$port/health > /dev/null 2>&1; then \
			printf "  %-14s \033[32m✓ healthy\033[0m\n" "$$svc"; \
		else \
			printf "  %-14s \033[31m✗ unhealthy\033[0m\n" "$$svc"; \
		fi; \
	done

# ── Setup targets ───────────────────────────────────────────

install: ## Copy .env.example → .env for all services
	@for dir in $(SERVICES); do \
		if [ -f "services/$$dir/.env.example" ] && [ ! -f "services/$$dir/.env" ]; then \
			cp "services/$$dir/.env.example" "services/$$dir/.env"; \
			echo "[✓] Created services/$$dir/.env"; \
		fi; \
	done

deps: ## Install composer dependencies for all services
	@for dir in $(SERVICES); do \
		echo "[*] Installing deps for $$dir..."; \
		(cd "services/$$dir" && composer install --prefer-dist --no-progress --quiet) || true; \
	done
	@echo "[✓] Dependencies installed"

migrate: ## Run database migrations
	@echo "[*] Running migrations..."
	@PGPASSWORD="$${DB_PASSWORD:-ashchan}" psql \
		-h "$${DB_HOST:-localhost}" -U "$${DB_USER:-ashchan}" \
		-d "$${DB_NAME:-ashchan}" -f db/install.sql
	@echo "[✓] Migrations complete"

seed: ## Seed the database
	@echo "[*] Seeding database..."
	@PGPASSWORD="$${DB_PASSWORD:-ashchan}" psql \
		-h "$${DB_HOST:-localhost}" -U "$${DB_USER:-ashchan}" \
		-d "$${DB_NAME:-ashchan}" -f db/seed.sql
	@echo "[✓] Seed complete"

bootstrap: install deps mtls-certs migrate seed ## Full bootstrap: deps, certs, migrate, seed

dev-quick: down up ## Quick restart for development

# ── mTLS targets ────────────────────────────────────────────

mtls-init: ## Initialize CA for mTLS certificates
	@echo "[*] Initializing CA..."
	@cd certs/ca && ./init-ca.sh 2>/dev/null || echo "[WARN] CA init script not found"

mtls-certs: ## Generate service mTLS certificates
	@echo "[*] Generating service certificates..."
	@cd certs && ./gen-certs.sh 2>/dev/null || echo "[WARN] Cert gen script not found"

# ── Static Build (optional) ────────────────────────────────
# Builds portable single-binary executables for each service
# using static-php-cli. No PHP installation needed at runtime.

build-static: ## Build all services as static binaries
	@./build/static-php/build.sh

build-static-php: ## Build only the static PHP binary (no service packing)
	@./build/static-php/build.sh --php-only

build-static-clean: ## Remove static build artifacts
	@./build/static-php/build.sh --clean

# Per-service static build targets
define static_service
build-static-$(1): ## Build static binary for $(1)
	@./build/static-php/build.sh $(1)
endef

$(foreach s,$(SHORT),$(eval $(call static_service,$(s))))

# ── Event Bus ──────────────────────────────────────────────

events-stats: ## Show event stream statistics
	@$(PHP) services/api-gateway/bin/hyperf.php events:stats

events-dlq: ## Show dead-lettered events
	@$(PHP) services/api-gateway/bin/hyperf.php events:dlq:list

events-dlq-retry: ## Replay all dead-lettered events
	@$(PHP) services/api-gateway/bin/hyperf.php events:dlq:retry

events-trim: ## Trim event stream to configured MAXLEN
	@$(PHP) services/api-gateway/bin/hyperf.php events:trim

# ── Varnish Cache ──────────────────────────────────────────

VARNISH_VCL    := $(ROOT)/config/varnish/default.vcl
VARNISH_PORT   := 6081
VARNISH_ADMIN  := 127.0.0.1:6082
VARNISH_STORAGE := malloc,256M

varnish-start: ## Start Varnish cache (port 6081)
	@echo "[*] Starting Varnish (port $(VARNISH_PORT))..."
	@varnishd \
		-a 127.0.0.1:$(VARNISH_PORT) \
		-T $(VARNISH_ADMIN) \
		-f $(VARNISH_VCL) \
		-s $(VARNISH_STORAGE) \
		-p ban_lurker_age=60 \
		-p ban_lurker_sleep=0.1 \
		-p http_resp_hdr_len=16384 \
		-p workspace_client=64k 2>/dev/null || echo "[WARN] Varnish may already be running"
	@sleep 1 && echo "[✓] Varnish started"

varnish-stop: ## Stop Varnish cache
	@echo "[*] Stopping Varnish..."
	@pkill -f 'varnishd.*$(VARNISH_PORT)' 2>/dev/null || true
	@echo "[✓] Varnish stopped"

varnish-reload: ## Reload Varnish VCL configuration
	@echo "[*] Reloading Varnish VCL..."
	@varnishadm -T $(VARNISH_ADMIN) vcl.load reload $(VARNISH_VCL) && \
		varnishadm -T $(VARNISH_ADMIN) vcl.use reload
	@echo "[✓] VCL reloaded"

varnish-status: ## Show Varnish backend and cache status
	@varnishadm -T $(VARNISH_ADMIN) status 2>/dev/null && \
		varnishadm -T $(VARNISH_ADMIN) backend.list 2>/dev/null || \
		echo "[!] Varnish is not running"

varnish-stats: ## Show Varnish hit/miss statistics
	@varnishstat -1 -f MAIN.cache_hit -f MAIN.cache_miss -f MAIN.n_object \
		-f MAIN.client_req -f MAIN.backend_req 2>/dev/null || \
		echo "[!] Varnish is not running"

varnish-ban: ## Ban all cached content (full cache flush)
	@echo "[*] Banning all cached content..."
	@varnishadm -T $(VARNISH_ADMIN) 'ban req.url ~ .' 2>/dev/null || \
		echo "[!] Varnish is not running"
	@echo "[✓] All content banned"

# ── Housekeeping ────────────────────────────────────────────

clean: ## Remove caches and runtime files
	@for dir in $(SERVICES); do \
		rm -rf "services/$$dir/runtime/container" 2>/dev/null; \
		rm -rf "services/$$dir/runtime/logs/"* 2>/dev/null; \
	done
	@echo "[✓] Caches cleared"
