# Services

This directory contains independently deployable Hyperf services. Each service has its own config, dependencies, and runtime settings.

## Services Overview

| Service | Port | Description |
|---------|------|-------------|
| api-gateway | 9501 | API Gateway, routing, rate limiting |
| auth-accounts | 9502 | Authentication and user accounts |
| boards-threads-posts | 9503 | Boards, threads, and posts |
| media-uploads | 9504 | Media uploads and processing |
| search-indexing | 9505 | Search backend |
| moderation-anti-spam | 9506 | Moderation and anti-spam |

## Running a Service

Each service runs as a standalone PHP process using Swoole:

```bash
cd services/api-gateway
composer install
cp .env.example .env
# Edit .env to configure database, redis, etc.
php bin/hyperf.php start
```

## Service Structure

Each service follows this structure:

```
service-name/
├── app/                    # Application code
│   ├── Controller/        # HTTP controllers
│   ├── Service/           # Business logic
│   └── Model/             # Database models
├── bin/
│   └── hyperf.php         # Entry point
├── config/
│   ├── autoload/          # Auto-loaded configuration
│   └── routes.php         # Route definitions
├── tests/                 # Unit and feature tests
├── .env.example           # Environment template
├── composer.json          # Dependencies
└── phpunit.xml            # Test configuration
```

## Environment Configuration

Each service has a `.env.example` file with configuration options:

- `APP_ENV` - Environment (dev, production)
- `DB_*` - Database connection settings
- `REDIS_*` - Redis connection settings
- `MTLS_*` - mTLS certificate paths (for production)
- Service-specific settings

## mTLS Configuration

For production deployments with mTLS:

```bash
# Environment variables for mTLS
MTLS_ENABLED=true
MTLS_CERT_FILE=/path/to/certs/services/service-name/service.crt
MTLS_KEY_FILE=/path/to/certs/services/service-name/service.key
MTLS_CA_FILE=/path/to/certs/ca/ca.crt
```

## Testing

```bash
cd services/service-name
composer test
```

## Linting

```bash
cd services/service-name
composer lint
```

## Static Analysis

```bash
cd services/service-name
composer phpstan
```
