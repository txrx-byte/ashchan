#!/usr/bin/env bash

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

#
# Ashchan Bootstrap Script
# 
# This script performs a complete setup of the Ashchan development environment:
# - Verifies PHP and required extensions
# - Initializes mTLS certificates
# - Sets up service environment files
# - Installs composer dependencies
# - Runs database migrations
# - Seeds the database
# - Starts all services
#
# Usage: ./bootstrap.sh [--force]
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Print colored output
info() { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }
step() { echo -e "${CYAN}[STEP]${NC} $1"; }

# Parse command line arguments
FORCE_REBUILD=false

for arg in "$@"; do
    case $arg in
        --force)
            FORCE_REBUILD=true
            warn "Force rebuild mode enabled"
            ;;
        *)
            error "Unknown argument: $arg"
            echo "Usage: $0 [--force]"
            exit 1
            ;;
    esac
done

# Show header
echo
echo "=================================================="
echo " ▄▀█ █▀ █░█ █▀▀ █░█ █▀█ █▄░█"
echo " █▀█ ▄█ █▀█ █▄▄ █▀█ █▀█ █░▀█"
echo "=================================================="
echo "         Bootstrap Development Environment"
echo "           (Native PHP-CLI via Swoole)"
echo "=================================================="
echo

# Step 1: Verify PHP and required extensions
step "1/7 Verifying PHP environment..."

if ! command -v php &> /dev/null; then
    error "PHP is not installed. Please install PHP 8.2+ with Swoole extension."
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
info "PHP Version: $PHP_VERSION"

# Check for required extensions
REQUIRED_EXTENSIONS=("swoole" "openssl" "pdo" "pdo_pgsql" "redis" "mbstring" "json" "curl" "pcntl" "gd" "fileinfo")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -qiE "^${ext}\$"; then
        MISSING_EXTENSIONS+=("$ext")
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -gt 0 ]; then
    error "Missing PHP extensions: ${MISSING_EXTENSIONS[*]}"
    echo ""
    echo "Install missing extensions:"
    echo "  Ubuntu/Debian: sudo apt-get install php-swoole php-pgsql php-redis"
    echo "  Or via PECL:   pecl install swoole redis"
    exit 1
fi

success "PHP environment verified"

# Step 2: Initialize mTLS certificates
step "2/7 Initializing mTLS certificates..."
CERTS_DIR="${SCRIPT_DIR}/certs"

if [[ "$FORCE_REBUILD" == "true" ]] || [[ ! -f "${CERTS_DIR}/ca/ca.crt" ]]; then
    info "Generating Root CA..."
    make mtls-init
    
    info "Generating service certificates..."
    make mtls-certs
    
    success "mTLS certificates generated"
else
    info "mTLS certificates already exist (use --force to regenerate)"
fi

# Step 3: Set up service environment files
step "3/7 Setting up service configurations..."
SERVICES=("api-gateway" "auth-accounts" "boards-threads-posts" "media-uploads" "search-indexing" "moderation-anti-spam")

# Local development defaults (override with environment variables)
DB_HOST="${DB_HOST:-localhost}"
REDIS_HOST="${REDIS_HOST:-localhost}"
REDIS_PASSWORD="${REDIS_PASSWORD:-}"
REDIS_AUTH="${REDIS_AUTH:-}"

for svc in "${SERVICES[@]}"; do
    ENV_FILE="services/${svc}/.env"
    ENV_EXAMPLE="services/${svc}/.env.example"
    
    if [[ "$FORCE_REBUILD" == "true" ]] || [[ ! -f "$ENV_FILE" ]]; then
        if [[ -f "$ENV_EXAMPLE" ]]; then
            sed -e "s|__PROJECT_ROOT__|${SCRIPT_DIR}|g" \
                -e "s|__DB_HOST__|${DB_HOST}|g" \
                -e "s|__REDIS_HOST__|${REDIS_HOST}|g" \
                -e "s|__REDIS_PASSWORD__|${REDIS_PASSWORD}|g" \
                -e "s|__REDIS_AUTH__|${REDIS_AUTH}|g" \
                "$ENV_EXAMPLE" > "$ENV_FILE"
            info "Created ${ENV_FILE}"
        else
            warn "No .env.example found for ${svc}"
        fi
    else
        info "${ENV_FILE} already exists"
    fi
done

success "Service configurations ready"

# Step 4: Install composer dependencies
step "4/7 Installing composer dependencies..."

for svc in "${SERVICES[@]}"; do
    info "Installing dependencies for ${svc}..."
    (cd "services/${svc}" && composer install --no-interaction --quiet) || warn "Failed to install deps for ${svc}"
done

success "Composer dependencies installed"

# Step 5: Verify database connectivity (if psql available)
step "5/7 Checking database connectivity..."

if command -v psql &> /dev/null; then
    DB_HOST="${DB_HOST:-localhost}"
    DB_USER="${DB_USER:-ashchan}"
    DB_NAME="${DB_NAME:-ashchan}"
    
    if psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1" > /dev/null 2>&1; then
        success "PostgreSQL connection verified"
        
        # Run migrations
        step "5b/7 Running database migrations..."
        for migration in db/migrations/*.sql; do
            if [ -f "$migration" ]; then
                MIGRATION_NAME=$(basename "$migration")
                info "Running migration: ${MIGRATION_NAME}"
                psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -f "$migration" 2>/dev/null || \
                    warn "Migration may have already been applied: ${MIGRATION_NAME}"
            fi
        done
        success "Database migrations completed"
        
        # Seed database
        step "5c/7 Seeding database..."
        for seeder in db/seeders/*.sql; do
            if [ -f "$seeder" ]; then
                SEEDER_NAME=$(basename "$seeder")
                info "Running seeder: ${SEEDER_NAME}"
                psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -f "$seeder" 2>/dev/null || \
                    warn "Seeder may have already been applied: ${SEEDER_NAME}"
            fi
        done
        success "Database seeding completed"
    else
        warn "Cannot connect to PostgreSQL. Skipping migrations."
        warn "Ensure PostgreSQL is running and accessible:"
        warn "  Host: $DB_HOST, User: $DB_USER, Database: $DB_NAME"
    fi
else
    warn "psql not found. Skipping database setup."
    warn "Install PostgreSQL client: apt-get install postgresql-client"
fi

# Step 6: Start all services
step "6/7 Starting all services..."

# Stop any existing services first (graceful shutdown)
info "Stopping any existing services..."
make down 2>/dev/null || true

# Brief pause to ensure processes have terminated
sleep 2

# Start services
make up

# Wait for services to start
info "Waiting for services to start..."
sleep 5

success "Services started"

# Step 7: Verify health
step "7/7 Verifying service health..."

HEALTH_CHECKS=(
    "http://localhost:9501/health:API Gateway"
    "http://localhost:9502/health:Auth Service"
    "http://localhost:9503/health:Boards Service"
    "http://localhost:9504/health:Media Service"
    "http://localhost:9505/health:Search Service"
    "http://localhost:9506/health:Moderation Service"
)

for check in "${HEALTH_CHECKS[@]}"; do
    url="${check%:*}"
    name="${check#*:}"
    
    if curl -s -f "$url" >/dev/null 2>&1; then
        success "✓ ${name} is healthy"
    else
        warn "✗ ${name} health check failed (may still be starting)"
    fi
done

# Verify mTLS certificates
info "Verifying mTLS certificates..."
if make mtls-verify >/dev/null 2>&1; then
    success "✓ mTLS certificates are valid"
else
    warn "✗ mTLS verification failed (check certificate configuration)"
fi

# Final status
echo
echo "=================================================="
success "🚀 Ashchan bootstrap completed!"
echo "=================================================="
echo
info "Services are running on the following ports:"
echo "  • API Gateway:    http://localhost:9501"
echo "  • Auth Service:   http://localhost:9502"  
echo "  • Boards Service: http://localhost:9503"
echo "  • Media Service:  http://localhost:9504"
echo "  • Search Service: http://localhost:9505"
echo "  • Moderation:     http://localhost:9506"
echo
info "Infrastructure services (required separately):"
echo "  • PostgreSQL:     localhost:5432"
echo "  • Redis:          localhost:6379"
echo "  • MinIO:          localhost:9000"
echo
info "Useful commands:"
echo "  • View logs:      make logs"
echo "  • Stop services:  make down"
echo "  • Check health:   make health"
echo "  • Restart:        make restart"
echo
info "Flags:"
echo "  • --force         Force rebuild (regenerate certs, recreate .env files)"
echo
info "Check the README.md for more information!"
echo
