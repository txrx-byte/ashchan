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
# mTLS ServiceMesh Management

.PHONY: help install up down logs migrate test lint mtls-init mtls-certs mtls-verify mtls-rotate
.PHONY: swoole-cli-download swoole-cli-build runtime-build runtime-up runtime-down runtime-logs

help:
	@echo "Ashchan Makefile Commands"
	@echo ""
	@echo "Development:"
	@echo "  install    Copy .env files for all services"
	@echo "  up         Start all services with podman-compose"
	@echo "  down       Stop all services"
	@echo "  logs       Tail logs from all services"
	@echo "  migrate    Run database migrations"
	@echo "  test       Run all service tests"
	@echo "  lint       Lint all PHP code"
	@echo ""
	@echo "Static Runtime (swoole-cli):"
	@echo "  swoole-cli-download  Download pre-built swoole-cli binary"
	@echo "  swoole-cli-build     Build swoole-cli from source (Docker)"
	@echo "  runtime-build        Build ashchan-runtime single image"
	@echo "  runtime-up           Start all services (single image mode)"
	@echo "  runtime-down         Stop all services (single image mode)"
	@echo "  runtime-logs         Tail logs (single image mode)"
	@echo ""
	@echo "mTLS ServiceMesh:"
	@echo "  mtls-init      Generate Root CA for ServiceMesh"
	@echo "  mtls-certs     Generate all service certificates"
	@echo "  mtls-verify    Verify mTLS mesh configuration"
	@echo "  mtls-rotate    Rotate all service certificates"
	@echo "  mtls-status    Show certificate status"
	@echo ""
	@echo "Quick Start (with mTLS):"
	@echo "  make mtls-init && make mtls-certs && make install && make up"
	@echo ""
	@echo "Quick Start (static runtime):"
	@echo "  make swoole-cli-download && make runtime-build && make install && make runtime-up"

install:
	@echo "Copying .env.example to .env for all services..."
	@for svc in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do \
		cp services/$$svc/.env.example services/$$svc/.env; \
	done
	@echo "Done. Edit .env files as needed."

up:
	podman-compose down --remove-orphans 2>/dev/null || true
	podman-compose up -d

down:
	podman-compose down

logs:
	podman-compose logs -f

migrate:
	@echo "Running migrations..."
	podman exec -it ashchan-postgres-1 psql -U ashchan -d ashchan -f /app/db/migrations/001_auth_accounts.sql
	podman exec -it ashchan-postgres-1 psql -U ashchan -d ashchan -f /app/db/migrations/002_boards_threads_posts.sql
	podman exec -it ashchan-postgres-1 psql -U ashchan -d ashchan -f /app/db/migrations/003_media_uploads.sql
	podman exec -it ashchan-postgres-1 psql -U ashchan -d ashchan -f /app/db/migrations/004_moderation_anti_spam.sql

test:
	@echo "Running tests for boards-threads-posts..."
	podman exec -it ashchan-boards-1 php /app/vendor/bin/phpunit /app/tests/Feature

lint:
	@echo "Linting PHP code..."
	@for svc in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do \
		echo "Linting $$svc..."; \
		cd services/$$svc && composer lint; \
	done

# ─────────────────────────────────────────────────────────────
# mTLS ServiceMesh Commands
# ─────────────────────────────────────────────────────────────

mtls-init:
	@echo "=== Initializing mTLS ServiceMesh CA ==="
	@./scripts/mtls/generate-ca.sh

mtls-certs:
	@echo "=== Generating ServiceMesh Certificates ==="
	@./scripts/mtls/generate-all-certs.sh

mtls-verify:
	@echo "=== Verifying mTLS ServiceMesh ==="
	@./scripts/mtls/verify-mesh.sh

mtls-rotate:
	@echo "=== Rotating ServiceMesh Certificates ==="
	@./scripts/mtls/rotate-certs.sh

mtls-status:
	@echo "=== ServiceMesh Certificate Status ==="
	@echo ""
	@CERTS_DIR="certs/services" && \
	for svc in gateway auth boards media search moderation; do \
		CERT_FILE="$$CERTS_DIR/$$svc/$$svc.crt"; \
		if [ -f "$$CERT_FILE" ]; then \
			echo "Service: $$svc"; \
			openssl x509 -in "$$CERT_FILE" -noout -subject -dates | sed 's/^/  /'; \
			EXPIRY=$$(openssl x509 -in "$$CERT_FILE" -noout -enddate | cut -d= -f2); \
			EXPIRY_EPOCH=$$(date -d "$$EXPIRY" +%s); \
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
		fi; \
	done

# ─────────────────────────────────────────────────────────────
# Development Helpers
# ─────────────────────────────────────────────────────────────

rebuild:
	@echo "Rebuilding all services..."
	podman-compose build $(BUILD_ARGS)

rebuild-%:
	@echo "Rebuilding $* service..."
	podman-compose build $(BUILD_ARGS) $*

restart:
	@echo "Restarting all services..."
	podman-compose restart

restart-%:
	@echo "Restarting $* service..."
	podman restart ashchan-$*-1

health:
	@echo "Checking service health..."
	@for svc in gateway auth boards media search moderation; do \
		PORT=$$(printf "950%d" $$(($$(echo $$svc | cut -c1-1 | tr 'gabmsm' '012345') + 0))); \
		echo -n "Checking $$svc (port $$PORT)... "; \
		if curl -s --max-time 2 http://localhost:$$PORT/health > /dev/null 2>&1; then \
			echo "✓ OK"; \
		else \
			echo "✗ DOWN"; \
		fi; \
	done

clean:
	@echo "Cleaning up Podman artifacts..."
	podman-compose down -v
	podman system prune -f

clean-certs:
	@echo "Removing all generated certificates..."
	rm -rf certs/
	@echo "Certificates removed. Run 'make mtls-init' to regenerate."

# ─────────────────────────────────────────────────────────────
# Static Runtime (swoole-cli) Commands
# ─────────────────────────────────────────────────────────────

SWOOLE_CLI_VERSION ?= v6.0.0
RUNTIME_COMPOSE = docker-compose -f docker-compose.runtime.yml

swoole-cli-download:
	@echo "=== Downloading swoole-cli $(SWOOLE_CLI_VERSION) ==="
	@bash docker/swoole-cli/download-swoole-cli.sh $(SWOOLE_CLI_VERSION)

swoole-cli-build:
	@echo "=== Building swoole-cli from source ==="
	docker build \
		-f docker/swoole-cli/Dockerfile.build \
		--build-arg SWOOLE_CLI_VERSION=$(SWOOLE_CLI_VERSION) \
		-t ashchan-swoole-cli-builder:latest \
		docker/swoole-cli/
	@echo "=== Extracting binary ==="
	docker create --name swoole-tmp ashchan-swoole-cli-builder:latest
	docker cp swoole-tmp:/output/swoole-cli docker/swoole-cli/swoole-cli
	docker rm swoole-tmp
	chmod +x docker/swoole-cli/swoole-cli
	@echo "=== Binary ready at docker/swoole-cli/swoole-cli ==="
	@file docker/swoole-cli/swoole-cli
	@ls -lh docker/swoole-cli/swoole-cli

runtime-build:
	@echo "=== Building ashchan-runtime image ==="
	@test -f docker/swoole-cli/swoole-cli || { echo "ERROR: swoole-cli binary not found. Run 'make swoole-cli-download' or 'make swoole-cli-build' first."; exit 1; }
	docker build -f Dockerfile.runtime -t ashchan-runtime:latest .
	@echo "=== Image built ==="
	@docker images ashchan-runtime:latest --format 'Size: {{.Size}}'

runtime-up: runtime-build
	$(RUNTIME_COMPOSE) down --remove-orphans 2>/dev/null || true
	$(RUNTIME_COMPOSE) up -d

runtime-down:
	$(RUNTIME_COMPOSE) down

runtime-logs:
	$(RUNTIME_COMPOSE) logs -f

runtime-restart:
	$(RUNTIME_COMPOSE) restart

runtime-restart-%:
	$(RUNTIME_COMPOSE) restart $*

runtime-health:
	@echo "Checking runtime service health..."
	@for svc in 9501 9502 9503 9504 9505 9506; do \
		echo -n "Port $$svc... "; \
		if curl -sf --max-time 2 http://localhost:$$svc/health > /dev/null 2>&1; then \
			echo "✓ OK"; \
		else \
			echo "✗ DOWN"; \
		fi; \
	done

runtime-clean:
	$(RUNTIME_COMPOSE) down -v
	docker rmi ashchan-runtime:latest 2>/dev/null || true
	docker rmi ashchan-swoole-cli-builder:latest 2>/dev/null || true
	rm -f docker/swoole-cli/swoole-cli
