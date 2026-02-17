# Makefile for Ashchan

.PHONY: help install up down logs migrate test lint

help:
	@echo "Ashchan Makefile Commands"
	@echo "  install    Copy .env files for all services"
	@echo "  up         Start all services with podman-compose"
	@echo "  down       Stop all services"
	@echo "  logs       Tail logs from all services"
	@echo "  migrate    Run database migrations"
	@echo "  test       Run all service tests"
	@echo "  lint       Lint all PHP code"

install:
	@echo "Copying .env.example to .env for all services..."
	@for svc in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do \
		cp services/$$svc/.env.example services/$$svc/.env; \
	done
	@echo "Done. Edit .env files as needed."

up:
	podman-compose up -d

down:
	podman-compose down

logs:
	podman-compose logs -f

migrate:
	@echo "Running migrations..."
	podman exec -it ashchan-postgres-1 psql -U ashchan -d ashchan -f /docker-entrypoint-initdb.d/001_auth_accounts.sql
	podman exec -it ashchan-postgres-1 psql -U ashchan -d ashchan -f /docker-entrypoint-initdb.d/002_boards_threads_posts.sql
	podman exec -it ashchan-postgres-1 psql -U ashchan -d ashchan -f /docker-entrypoint-initdb.d/003_media_uploads.sql
	podman exec -it ashchan-postgres-1 psql -U ashchan -d ashchan -f /docker-entrypoint-initdb.d/004_moderation_anti_spam.sql

test:
	@echo "Running tests..."
	@for svc in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do \
		echo "Testing $$svc..."; \
		cd services/$$svc && composer test; \
	done

lint:
	@echo "Linting PHP code..."
	@for svc in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do \
		echo "Linting $$svc..."; \
		cd services/$$svc && composer lint; \
	done
