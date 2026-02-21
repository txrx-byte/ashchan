# Copyright 2026 txrx-byte
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.

# Ashchan Makefile
# Native PHP-CLI Deployment via Swoole

.PHONY: help install up down logs migrate seed test lint phpstan bootstrap dev-quick
.PHONY: mtls-init mtls-certs mtls-verify mtls-rotate mtls-status
.PHONY: start-gateway start-auth start-boards start-media start-search start-moderation
.PHONY: stop-gateway stop-auth stop-boards stop-media stop-search stop-moderation
.PHONY: restart health clean clean-certs

# Service directories
SERVICES = api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam

# PID file directory
PID_DIR = /tmp/ashchan-pids

help:
	@echo "Ashchan Makefile Commands"
	@echo ""
	@echo "Development:"
	@echo "  bootstrap      Complete setup (deps, certs, services, migrations, seed)"
	@echo "  dev-quick      Quick development restart (assumes setup is done)"
	@echo "  install        Copy .env.example to .env for all services"
	@echo "  up             Start all services (native PHP processes)"
	@echo "  down           Stop all services"
	@echo "  logs           View combined logs"
	@echo "  migrate        Run database migrations"
	@echo "  seed           Seed the database"
	@echo "  test           Run all service tests"
	@echo "  lint           Lint all PHP code"
	@echo "  phpstan        Run PHPStan static analysis"
	@echo ""
	@echo "Service Management:"
	@echo "  start-<svc>    Start a specific service (gateway, auth, boards, media, search, moderation)"
	@echo "  stop-<svc>     Stop a specific service"
	@echo "  restart        Restart all services"
	@echo "  health         Check health of all services"
	@echo ""
	@echo "mTLS Certificates:"
	@echo "  mtls-init      Generate Root CA"
	@echo "  mtls-certs     Generate all service certificates"
	@echo "  mtls-verify    Verify mTLS configuration"
	@echo "  mtls-rotate    Rotate all service certificates"
	@echo "  mtls-status    Show certificate expiration status"
	@echo ""
	@echo "Cleanup:"
	@echo "  clean          Clean runtime artifacts"
	@echo "  clean-certs    Remove all generated certificates"
	@echo ""
	@echo "Quick Start:"
	@echo "  make bootstrap"

# ─────────────────────────────────────────────────────────────
# Setup & Configuration
# ─────────────────────────────────────────────────────────────

install:
	@echo "Copying .env.example to .env for all services..."
	@for svc in $(SERVICES); do \
		if [ -f "services/$$svc/.env.example" ]; then \
			cp -n services/$$svc/.env.example services/$$svc/.env 2>/dev/null || true; \
			echo "  ✓ $$svc"; \
		fi; \
	done
	@echo "Installing composer dependencies..."
	@for svc in $(SERVICES); do \
		echo "  Installing $$svc..."; \
		(cd services/$$svc && composer install --no-interaction --quiet) || echo "  ⚠ Failed to install $$svc"; \
	done
	@echo "Done. Edit .env files as needed."

# ─────────────────────────────────────────────────────────────
# Service Control (Native PHP Processes)
# ─────────────────────────────────────────────────────────────

$(PID_DIR):
	@mkdir -p $(PID_DIR)

up: $(PID_DIR)
	@echo "Starting all Ashchan services..."
	@$(MAKE) start-gateway
	@$(MAKE) start-auth
	@$(MAKE) start-boards
	@$(MAKE) start-media
	@$(MAKE) start-search
	@$(MAKE) start-moderation
	@echo "All services started. Use 'make logs' to view output."

down:
	@echo "Stopping all Ashchan services..."
	@$(MAKE) stop-gateway
	@$(MAKE) stop-auth
	@$(MAKE) stop-boards
	@$(MAKE) stop-media
	@$(MAKE) stop-search
	@$(MAKE) stop-moderation
	@echo "All services stopped."

start-gateway: $(PID_DIR)
	@echo "Starting API Gateway (port 9501)..."
	@if [ -f "$(PID_DIR)/gateway.pid" ] && kill -0 $$(cat $(PID_DIR)/gateway.pid) 2>/dev/null; then \
		echo "  Already running (PID $$(cat $(PID_DIR)/gateway.pid))"; \
	else \
		cd services/api-gateway && \
		nohup php bin/hyperf.php start > /tmp/ashchan-gateway.log 2>&1 & \
		echo $$! > $(PID_DIR)/gateway.pid; \
		echo "  Started (PID $$!)"; \
	fi

start-auth: $(PID_DIR)
	@echo "Starting Auth/Accounts (port 9502)..."
	@if [ -f "$(PID_DIR)/auth.pid" ] && kill -0 $$(cat $(PID_DIR)/auth.pid) 2>/dev/null; then \
		echo "  Already running (PID $$(cat $(PID_DIR)/auth.pid))"; \
	else \
		cd services/auth-accounts && \
		nohup php bin/hyperf.php start > /tmp/ashchan-auth.log 2>&1 & \
		echo $$! > $(PID_DIR)/auth.pid; \
		echo "  Started (PID $$!)"; \
	fi

start-boards: $(PID_DIR)
	@echo "Starting Boards/Threads/Posts (port 9503)..."
	@if [ -f "$(PID_DIR)/boards.pid" ] && kill -0 $$(cat $(PID_DIR)/boards.pid) 2>/dev/null; then \
		echo "  Already running (PID $$(cat $(PID_DIR)/boards.pid))"; \
	else \
		cd services/boards-threads-posts && \
		nohup php bin/hyperf.php start > /tmp/ashchan-boards.log 2>&1 & \
		echo $$! > $(PID_DIR)/boards.pid; \
		echo "  Started (PID $$!)"; \
	fi

start-media: $(PID_DIR)
	@echo "Starting Media/Uploads (port 9504)..."
	@if [ -f "$(PID_DIR)/media.pid" ] && kill -0 $$(cat $(PID_DIR)/media.pid) 2>/dev/null; then \
		echo "  Already running (PID $$(cat $(PID_DIR)/media.pid))"; \
	else \
		cd services/media-uploads && \
		nohup php bin/hyperf.php start > /tmp/ashchan-media.log 2>&1 & \
		echo $$! > $(PID_DIR)/media.pid; \
		echo "  Started (PID $$!)"; \
	fi

start-search: $(PID_DIR)
	@echo "Starting Search/Indexing (port 9505)..."
	@if [ -f "$(PID_DIR)/search.pid" ] && kill -0 $$(cat $(PID_DIR)/search.pid) 2>/dev/null; then \
		echo "  Already running (PID $$(cat $(PID_DIR)/search.pid))"; \
	else \
		cd services/search-indexing && \
		nohup php bin/hyperf.php start > /tmp/ashchan-search.log 2>&1 & \
		echo $$! > $(PID_DIR)/search.pid; \
		echo "  Started (PID $$!)"; \
	fi

start-moderation: $(PID_DIR)
	@echo "Starting Moderation/Anti-Spam (port 9506)..."
	@if [ -f "$(PID_DIR)/moderation.pid" ] && kill -0 $$(cat $(PID_DIR)/moderation.pid) 2>/dev/null; then \
		echo "  Already running (PID $$(cat $(PID_DIR)/moderation.pid))"; \
	else \
		cd services/moderation-anti-spam && \
		nohup php bin/hyperf.php start > /tmp/ashchan-moderation.log 2>&1 & \
		echo $$! > $(PID_DIR)/moderation.pid; \
		echo "  Started (PID $$!)"; \
	fi

stop-gateway:
	@echo "Stopping API Gateway..."
	@if [ -f "$(PID_DIR)/gateway.pid" ]; then \
		PID=$$(cat $(PID_DIR)/gateway.pid); \
		if kill -0 $$PID 2>/dev/null; then \
			kill $$PID 2>/dev/null || true; \
			echo "  Stopped (PID $$PID)"; \
		else \
			echo "  Not running"; \
		fi; \
		rm -f $(PID_DIR)/gateway.pid; \
	else \
		echo "  Not running"; \
	fi

stop-auth:
	@echo "Stopping Auth/Accounts..."
	@if [ -f "$(PID_DIR)/auth.pid" ]; then \
		PID=$$(cat $(PID_DIR)/auth.pid); \
		if kill -0 $$PID 2>/dev/null; then \
			kill $$PID 2>/dev/null || true; \
			echo "  Stopped (PID $$PID)"; \
		else \
			echo "  Not running"; \
		fi; \
		rm -f $(PID_DIR)/auth.pid; \
	else \
		echo "  Not running"; \
	fi

stop-boards:
	@echo "Stopping Boards/Threads/Posts..."
	@if [ -f "$(PID_DIR)/boards.pid" ]; then \
		PID=$$(cat $(PID_DIR)/boards.pid); \
		if kill -0 $$PID 2>/dev/null; then \
			kill $$PID 2>/dev/null || true; \
			echo "  Stopped (PID $$PID)"; \
		else \
			echo "  Not running"; \
		fi; \
		rm -f $(PID_DIR)/boards.pid; \
	else \
		echo "  Not running"; \
	fi

stop-media:
	@echo "Stopping Media/Uploads..."
	@if [ -f "$(PID_DIR)/media.pid" ]; then \
		PID=$$(cat $(PID_DIR)/media.pid); \
		if kill -0 $$PID 2>/dev/null; then \
			kill $$PID 2>/dev/null || true; \
			echo "  Stopped (PID $$PID)"; \
		else \
			echo "  Not running"; \
		fi; \
		rm -f $(PID_DIR)/media.pid; \
	else \
		echo "  Not running"; \
	fi

stop-search:
	@echo "Stopping Search/Indexing..."
	@if [ -f "$(PID_DIR)/search.pid" ]; then \
		PID=$$(cat $(PID_DIR)/search.pid); \
		if kill -0 $$PID 2>/dev/null; then \
			kill $$PID 2>/dev/null || true; \
			echo "  Stopped (PID $$PID)"; \
		else \
			echo "  Not running"; \
		fi; \
		rm -f $(PID_DIR)/search.pid; \
	else \
		echo "  Not running"; \
	fi

stop-moderation:
	@echo "Stopping Moderation/Anti-Spam..."
	@if [ -f "$(PID_DIR)/moderation.pid" ]; then \
		PID=$$(cat $(PID_DIR)/moderation.pid); \
		if kill -0 $$PID 2>/dev/null; then \
			kill $$PID 2>/dev/null || true; \
			echo "  Stopped (PID $$PID)"; \
		else \
			echo "  Not running"; \
		fi; \
		rm -f $(PID_DIR)/moderation.pid; \
	else \
		echo "  Not running"; \
	fi

restart: down up

logs:
	@echo "=== Combined Ashchan Logs ==="
	@echo "Use Ctrl+C to exit"
	@tail -f /tmp/ashchan-*.log 2>/dev/null || echo "No log files found. Start services first with 'make up'"

# ─────────────────────────────────────────────────────────────
# Database Operations
# ─────────────────────────────────────────────────────────────

migrate:
	@echo "Running database migrations..."
	@echo "Note: Ensure PostgreSQL is running and accessible"
	@for migration in db/migrations/*.sql; do \
		if [ -f "$$migration" ]; then \
			echo "  Running $$migration..."; \
			psql -h $${DB_HOST:-localhost} -U $${DB_USER:-ashchan} -d $${DB_NAME:-ashchan} -f "$$migration" 2>/dev/null || \
			echo "  ⚠ Failed (check DB connection or migration already applied)"; \
		fi; \
	done
	@echo "Migrations complete."

seed:
	@echo "Seeding database..."
	@for seeder in db/seeders/*.sql; do \
		if [ -f "$$seeder" ]; then \
			echo "  Running $$seeder..."; \
			psql -h $${DB_HOST:-localhost} -U $${DB_USER:-ashchan} -d $${DB_NAME:-ashchan} -f "$$seeder" 2>/dev/null || \
			echo "  ⚠ Failed (check DB connection)"; \
		fi; \
	done
	@echo "Seeding complete."

# ─────────────────────────────────────────────────────────────
# Testing & Code Quality
# ─────────────────────────────────────────────────────────────

test:
	@echo "Running tests for all services..."
	@for svc in $(SERVICES); do \
		echo "Testing $$svc..."; \
		(cd services/$$svc && composer test 2>/dev/null) || echo "  ⚠ Tests failed or not configured for $$svc"; \
	done

lint:
	@echo "Linting PHP code..."
	@for svc in $(SERVICES); do \
		echo "Linting $$svc..."; \
		(cd services/$$svc && composer lint 2>/dev/null) || echo "  ⚠ Lint failed or not configured for $$svc"; \
	done

phpstan:
	@echo "Running PHPStan static analysis..."
	@for svc in $(SERVICES); do \
		echo "Analyzing $$svc..."; \
		(cd services/$$svc && composer phpstan 2>/dev/null) || echo "  ⚠ PHPStan failed or not configured for $$svc"; \
	done

# ─────────────────────────────────────────────────────────────
# Bootstrap & Quick Development
# ─────────────────────────────────────────────────────────────

bootstrap:
	@echo "=== Complete Ashchan Bootstrap ==="
	@./bootstrap.sh

dev-quick:
	@echo "=== Quick Development Restart ==="
	@./dev-quick.sh

# ─────────────────────────────────────────────────────────────
# mTLS Certificate Commands
# ─────────────────────────────────────────────────────────────

mtls-init:
	@echo "=== Initializing mTLS CA ==="
	@./scripts/mtls/generate-ca.sh

mtls-certs:
	@echo "=== Generating Service Certificates ==="
	@./scripts/mtls/generate-all-certs.sh

mtls-verify:
	@echo "=== Verifying mTLS Configuration ==="
	@./scripts/mtls/verify-mesh.sh

mtls-rotate:
	@echo "=== Rotating Service Certificates ==="
	@./scripts/mtls/rotate-certs.sh

mtls-status:
	@echo "=== Certificate Status ==="
	@echo ""
	@CERTS_DIR="certs/services" && \
	for svc in gateway auth boards media search moderation; do \
		CERT_FILE="$$CERTS_DIR/$$svc/$$svc.crt"; \
		if [ -f "$$CERT_FILE" ]; then \
			echo "Service: $$svc"; \
			openssl x509 -in "$$CERT_FILE" -noout -subject -dates | sed 's/^/  /'; \
			EXPIRY=$$(openssl x509 -in "$$CERT_FILE" -noout -enddate | cut -d= -f2); \
			EXPIRY_EPOCH=$$(date -d "$$EXPIRY" +%s 2>/dev/null || date -j -f "%b %d %H:%M:%S %Y %Z" "$$EXPIRY" +%s 2>/dev/null); \
			NOW_EPOCH=$$(date +%s); \
			DAYS_LEFT=$$(( (EXPIRY_EPOCH - NOW_EPOCH) / 86400 )); \
			if [ $$DAYS_LEFT -lt 30 ]; then \
				echo "  ⚠ Expires in $$DAYS_LEFT days"; \
			else \
				echo "  ✓ Expires in $$DAYS_LEFT days"; \
			fi; \
			echo ""; \
		else \
			echo "Service: $$svc - Certificate not found"; \
			echo ""; \
		fi; \
	done

# ─────────────────────────────────────────────────────────────
# Health Check
# ─────────────────────────────────────────────────────────────

health:
	@echo "Checking service health..."
	@for pair in gateway:9501 auth:9502 boards:9503 media:9504 search:9505 moderation:9506; do \
		svc=$${pair%%:*}; \
		PORT=$${pair##*:}; \
		echo -n "  $$svc (port $$PORT)... "; \
		if curl -s --max-time 2 http://localhost:$$PORT/health > /dev/null 2>&1; then \
			echo "✓ OK"; \
		else \
			echo "✗ DOWN"; \
		fi; \
	done

# ─────────────────────────────────────────────────────────────
# Cleanup
# ─────────────────────────────────────────────────────────────

clean:
	@echo "Cleaning up runtime artifacts..."
	@rm -rf $(PID_DIR)
	@rm -f /tmp/ashchan-*.log
	@for svc in $(SERVICES); do \
		rm -rf services/$$svc/runtime 2>/dev/null || true; \
	done
	@echo "Cleanup complete."

clean-certs:
	@echo "Removing all generated certificates..."
	@rm -rf certs/
	@echo "Certificates removed. Run 'make mtls-init' to regenerate."
