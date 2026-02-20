# Ashchan Makefile
# mTLS ServiceMesh Management

.PHONY: help install up down logs migrate test lint phpstan phpstan-all mtls-init mtls-certs mtls-verify mtls-rotate

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
	@echo "  phpstan    Run PHPStan analysis on all services"
	@echo "  phpstan-all Run PHPStan on root and all services"
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

phpstan:
	@echo "Running PHPStan analysis on all services..."
	@for svc in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do \
		echo "Analyzing $$svc..."; \
		cd services/$$svc && composer phpstan || exit 1; \
	done

phpstan-all:
	@echo "Running PHPStan (root)..."
	@composer phpstan
	@echo ""
	@echo "Running PHPStan on all services..."
	@$(MAKE) phpstan

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
