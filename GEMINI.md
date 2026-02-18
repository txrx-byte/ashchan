# Gemini Context for Ashchan

This document provides context and architectural overview for the Ashchan project to assist Gemini in future tasks.

## Project Overview
Ashchan is a microservices-based imageboard software (similar to 4chan) built with PHP and the Hyperf framework. It is designed to be scalable and modular.

## Architecture
The system is composed of several microservices, each running in its own container (likely orchestrated by Kubernetes as suggested by the `k8s/` directory).

### Services
*   **`services/api-gateway`**: The entry point for all client requests. It likely handles routing, authentication (via `auth-accounts`), and composition of responses.
*   **`services/auth-accounts`**: Manages user accounts, authentication, and authorization.
*   **`services/boards-threads-posts`**: The core service managing boards, threads, and posts. It handles the business logic for imageboard functionality.
*   **`services/media-uploads`**: Handles file uploads, storage (likely S3/MinIO), and media processing (thumbnails, etc.).
*   **`services/moderation-anti-spam`**: Provides spam filtering and moderation tools.
*   **`services/search-indexing`**: Handles indexing of content for search functionality (likely using Elasticsearch or similar, though implementation details need verification).

### Infrastructure
*   **Language**: PHP 8.x (using Hyperf framework)
*   **Database**: PostgreSQL (as seen in `db/migrations` and `PostgresConnection` usage).
*   **Caching**: Redis (used in `BoardService` for caching board lists and threads).
*   **Containerization**: Docker (each service has a `Dockerfile`).
*   **Orchestration**: Kubernetes (`k8s/` directory contains manifests).

## Key Components

### Database Layer
*   **Migrations**: Located in `db/migrations/`.
    *   `001_auth_accounts.sql`
    *   `002_boards_threads_posts.sql`
    *   `003_media_uploads.sql`
    *   `004_moderation_anti_spam.sql`
*   **ORM**: Hyperf Database (based on Laravel Eloquent).
*   **Custom Connection**: `services/boards-threads-posts/app/Database/PostgresConnection.php` implements a custom connection handling (recently fixed).

### Communication
*   **Synchronous**: Likely HTTP/REST or gRPC between services (API Gateway -> Services).
*   **Asynchronous**: Event-driven architecture using JSON schemas in `contracts/events/` suggests usage of a message queue (e.g., RabbitMQ, Kafka, or Redis Pub/Sub).

## Recent Investigations & Fixes

### 2026-02-18: Fix `relation "id" does not exist` error
**Issue**: Creating a post (specifically the OP post of a thread) failed with `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "id" does not exist`.
**Cause**: The `PostgresConnection` class in `services/boards-threads-posts` was incorrectly using the generic `QueryGrammar` and `Processor` instead of the PostgreSQL-specific ones. This caused `insertGetId` (used by Eloquent for the `Post` model) to rely on `PDO::lastInsertId('id')`, which fails in Postgres because it treats `'id'` as a sequence name, and no sequence named `"id"` exists (the correct sequence is `posts_id_seq`).
**Fix**: Updated `services/boards-threads-posts/app/Database/PostgresConnection.php` to use:
*   `Hyperf\Database\Query\Grammars\PostgresGrammar`
*   `Hyperf\Database\Schema\Grammars\PostgresGrammar`
*   `Hyperf\Database\Query\Processors\PostgresProcessor`

This ensures that `INSERT ... RETURNING id` is used, bypassing the need for `lastInsertId` with a sequence name guess.

## Development Guidelines
*   **Code Style**: Follow PSR-12.
*   **Framework**: Use Hyperf conventions (Annotations, Dependency Injection).
*   **Database**: Use Migrations for schema changes. Avoid raw SQL where Eloquent/Query Builder suffices, but use raw SQL for performance-critical complex queries if needed (carefully).