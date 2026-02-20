# PHPStan 10 Compliance Guide

## Overview

The Ashchan project implements comprehensive PHPStan Level 10 (maximum strictness) static analysis across all microservices. This guide explains the configuration, usage, and best practices.

## Architecture

### Configuration Hierarchy

```
ashchan/
├── phpstan.neon                    # Root configuration for project-wide analysis
├── composer.json                   # Root composer with PHPStan scripts
└── services/
    ├── api-gateway/
    │   ├── phpstan.neon           # Service-specific configuration
    │   ├── phpstan-bootstrap.php  # Bootstrap file for analysis
    │   ├── phpstan-config-extension.php  # Dynamic type extensions
    │   └── composer.json          # Service composer with PHPStan scripts
    ├── auth-accounts/
    │   ├── phpstan.neon
    │   ├── phpstan-bootstrap.php
    │   └── composer.json
    ├── boards-threads-posts/
    │   ├── phpstan.neon
    │   ├── phpstan-bootstrap.php
    │   └── composer.json
    ├── media-uploads/
    │   ├── phpstan.neon
    │   ├── phpstan-bootstrap.php
    │   └── composer.json
    ├── moderation-anti-spam/
    │   ├── phpstan.neon
    │   ├── phpstan-bootstrap.php
    │   └── composer.json
    └── search-indexing/
        ├── phpstan.neon
        ├── phpstan-bootstrap.php
        └── composer.json
```

## Strictness Features

All configurations implement PHPStan Level 10 with the following enhanced strictness rules:

- **`checkMissingIterableValueType: true`** - Requires explicit type hints for array values
- **`checkGenericClassInNonGenericObjectType: true`** - Enforces generic type parameters
- **`treatPhpDocTypesAsCertain: false`** - Validates runtime types, not just PHPDocs
- **`checkAlwaysTrueCheckTypeFunctionCall: true`** - Detects redundant type checks
- **`checkAlwaysTrueInstanceof: true`** - Detects redundant instanceof checks
- **`checkAlwaysTrueStrictComparison: true`** - Detects always-true comparisons
- **`checkExplicitMixedMissingReturn: true`** - Requires explicit mixed return types
- **`checkFunctionNameCase: true`** - Validates function name casing
- **`checkInternalClassCaseSensitivity: true`** - Validates class name casing
- **`checkMissingCallableSignature: true`** - Requires callable signatures
- **`checkTooWideReturnTypesInProtectedAndPublicMethods: true`** - Prevents overly broad return types
- **`checkUninitializedProperties: true`** - Validates property initialization
- **`checkDynamicProperties: true`** - Detects dynamic property access

## Usage

### Prerequisites

Install dependencies for each service:

```bash
# Install root dependencies
composer install

# Install service dependencies (example for api-gateway)
cd services/api-gateway
composer install
cd ../..
```

### Running PHPStan

#### Project-Wide Analysis

Analyze all services and shared code from the root directory:

```bash
composer phpstan
```

#### Individual Service Analysis

Analyze a specific service:

```bash
# From root directory
composer phpstan:api-gateway
composer phpstan:auth-accounts
composer phpstan:boards-threads-posts
composer phpstan:media-uploads
composer phpstan:moderation-anti-spam
composer phpstan:search-indexing

# Or from within a service directory
cd services/api-gateway
composer phpstan
```

#### All Services Analysis

Analyze all services sequentially:

```bash
composer phpstan:all-services
```

### Memory Configuration

PHPStan is configured with appropriate memory limits:
- **Root analysis**: 1GB (`--memory-limit=1G`)
- **Service analysis**: 512MB (`--memory-limit=512M`)

If you encounter memory issues, increase the limit:

```bash
# For root analysis
composer phpstan -- --memory-limit=2G

# For service analysis
cd services/api-gateway
composer phpstan -- --memory-limit=1G
```

## Strict Typing Declaration

All PHP files must include the strict types declaration:

```php
<?php

declare(strict_types=1);

// Your code here
```

### Current Status

- ✅ All controllers, models, services, and configuration files
- ✅ All database migrations and seeders
- ✅ Root utility scripts (setup_admin.php)
- ⚠️ Template files (views/*.php) - Excluded from analysis (see below)

### Template Files

Template files that start with HTML (e.g., `services/api-gateway/views/home.php`, `layout.php`) are excluded from PHPStan analysis via `excludePaths`:

```yaml
excludePaths:
    - services/api-gateway/views/*
```

These files contain mixed HTML/PHP and are rendered templates, not executable code requiring strict analysis.

## Framework-Specific Patterns

PHPStan is configured to ignore common Hyperf framework patterns that are safe:

### Dependency Injection
```php
#[Inject]
private LoggerInterface $logger;
// PHPStan ignores "never written" warnings for DI-injected properties
```

### Static Facade Calls
```php
Db::table('users')->get();
Router::get('/path', 'Handler');
// PHPStan allows these Hyperf static facades
```

### Response Helpers
```php
return $response->json(['data' => $data], 200);
// PHPStan allows the optional second parameter
```

### Mixed Types from ORM
```php
$user->getAttribute('name'); // Returns mixed
$name = (string) $user->getAttribute('name'); // Explicit cast
// PHPStan allows explicit casts from mixed
```

## Baseline Generation

If you encounter a large number of existing errors, generate a baseline:

```bash
# Project-wide baseline
composer phpstan-baseline

# Service-specific baseline
cd services/api-gateway
composer phpstan-baseline
```

This creates a `phpstan-baseline.neon` file that ignores existing errors. **Important**: New code should not add to the baseline; fix errors instead.

## CI/CD Integration

### GitHub Actions Example

```yaml
name: PHPStan Analysis

on: [push, pull_request]

jobs:
  phpstan:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2
      
      - name: Install root dependencies
        run: composer install --no-interaction --prefer-dist
      
      - name: Run PHPStan (root)
        run: composer phpstan
      
      - name: Install service dependencies
        run: |
          cd services/api-gateway && composer install --no-interaction --prefer-dist
          cd ../auth-accounts && composer install --no-interaction --prefer-dist
          cd ../boards-threads-posts && composer install --no-interaction --prefer-dist
          cd ../media-uploads && composer install --no-interaction --prefer-dist
          cd ../moderation-anti-spam && composer install --no-interaction --prefer-dist
          cd ../search-indexing && composer install --no-interaction --prefer-dist
      
      - name: Run PHPStan (all services)
        run: composer phpstan:all-services
```

### Makefile Integration

Add to your Makefile:

```makefile
.PHONY: phpstan phpstan-services phpstan-all

phpstan:
	composer phpstan

phpstan-services:
	composer phpstan:all-services

phpstan-all: phpstan phpstan-services
```

## Troubleshooting

### Common Issues

#### "Class not found" errors

**Solution**: Ensure composer dependencies are installed:
```bash
cd services/[service-name]
composer install
```

#### Memory limit exceeded

**Solution**: Increase memory limit:
```bash
composer phpstan -- --memory-limit=2G
```

#### False positives from Hyperf framework

**Solution**: Add specific error patterns to the `ignoreErrors` section in `phpstan.neon`:

```yaml
ignoreErrors:
    - '#Your specific error pattern here#'
```

#### Unmatched ignored errors

If you see "Ignored error pattern ... was not matched", it means:
1. The error no longer exists (good!)
2. The pattern is incorrect (fix the pattern)

Set `reportUnmatchedIgnoredErrors: true` to catch these issues.

## Best Practices

### 1. Run PHPStan Locally Before Committing

```bash
# Quick check on changed service
cd services/api-gateway
composer phpstan
```

### 2. Use Explicit Types

❌ Bad:
```php
public function getUsers()
{
    return $this->users;
}
```

✅ Good:
```php
/**
 * @return array<int, User>
 */
public function getUsers(): array
{
    return $this->users;
}
```

### 3. Avoid Mixed Types

❌ Bad:
```php
public function process($data)
{
    return $data['key'];
}
```

✅ Good:
```php
/**
 * @param array{key: string, value: int} $data
 */
public function process(array $data): string
{
    return $data['key'];
}
```

### 4. Use PHPDoc for Complex Types

```php
/**
 * @param array<string, array{id: int, name: string}> $users
 * @return array<int, string>
 */
public function extractNames(array $users): array
{
    return array_map(fn($user) => $user['name'], $users);
}
```

### 5. Explicit Casts for ORM Data

```php
// From Hyperf Model
$userId = (int) $user->getAttribute('id');
$userName = (string) $user->getAttribute('name');
```

## Maintenance

### Updating PHPStan

```bash
# Update root
composer update phpstan/phpstan

# Update each service
cd services/api-gateway && composer update phpstan/phpstan
cd ../auth-accounts && composer update phpstan/phpstan
# ... etc
```

### Adding New Rules

Edit the respective `phpstan.neon` file:

```yaml
parameters:
    # Add new strictness rules
    checkMissingOverrideMethodAttribute: true
```

### Service-Specific Exceptions

Add to service's `phpstan.neon`:

```yaml
parameters:
    ignoreErrors:
        - '#Your service-specific pattern#'
```

## Resources

- [PHPStan Documentation](https://phpstan.org/)
- [PHPStan Level 10 Blog Post](https://phpstan.org/blog/phpstan-1-0-released)
- [Hyperf Framework](https://hyperf.io/)
- [PHP Type System](https://www.php.net/manual/en/language.types.php)

## Support

For issues or questions:
1. Check this guide first
2. Review PHPStan error messages carefully
3. Check existing `ignoreErrors` patterns in `phpstan.neon`
4. Consult the Hyperf documentation for framework-specific patterns
5. Open an issue in the project repository
