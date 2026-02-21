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
# - Initializes mTLS certificates
# - Configures Podman registries
# - Sets up service environment files
# - Starts all services
# - Runs database migrations
# - Seeds the database
# - Verifies the installation
#
# Usage: ./bootstrap.sh [--force] [--rooted]
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${SCRIPT_DIR}/podman-compose.yml"

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
ROOTED_MODE=false

for arg in "$@"; do
    case $arg in
        --force)
            FORCE_REBUILD=true
            warn "Force rebuild mode enabled"
            ;;
        --rooted)
            ROOTED_MODE=true
            warn "Rooted mode enabled (for dev containers/testing)"
            ;;
        *)
            error "Unknown argument: $arg"
            echo "Usage: $0 [--force] [--rooted]"
            exit 1
            ;;
    esac
done

# Set podman command prefix based on mode
if [[ "$ROOTED_MODE" == "true" ]]; then
    PODMAN_CMD="sudo podman"
    PODMAN_COMPOSE_CMD="sudo podman-compose"
    warn "Using rooted Podman (sudo) - only use for dev containers!"
else
    PODMAN_CMD="podman"
    PODMAN_COMPOSE_CMD="podman-compose"
    info "Using rootless Podman (recommended for production)"
fi

# Show header
echo
echo "=================================================="
echo " ▄▀█ █▀ █░█ █▀▀ █░█ █▀█ █▄░█"
echo " █▀█ ▄█ █▀█ █▄▄ █▀█ █▀█ █░▀█"
echo "=================================================="
echo "         Bootstrap Development Environment"
echo "=================================================="
echo

# Step 1: Configure Podman registries
step "1/8 Configuring Podman registries..."
CONFIG_DIR="$HOME/.config/containers"
CONFIG_FILE="$CONFIG_DIR/registries.conf"

mkdir -p "$CONFIG_DIR"
cat << 'EOF' > "$CONFIG_FILE"
[registries.search]
registries = ["docker.io", "registry.redhat.io", "quay.io"]

[registries.insecure]
registries = []

[registries.block]
registries = []

[registries.mirrors."docker.io"]
mirror = ["https://mirror.gcr.io", "https://registry-1.docker.io"]
EOF

success "Podman registries configured"

# Step 2: Initialize mTLS certificates
step "2/8 Initializing mTLS ServiceMesh..."
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
step "3/8 Setting up service configurations..."
SERVICES=("api-gateway" "auth-accounts" "boards-threads-posts" "media-uploads" "search-indexing" "moderation-anti-spam")

for svc in "${SERVICES[@]}"; do
    ENV_FILE="services/${svc}/.env"
    ENV_EXAMPLE="services/${svc}/.env.example"
    
    if [[ "$FORCE_REBUILD" == "true" ]] || [[ ! -f "$ENV_FILE" ]]; then
        if [[ -f "$ENV_EXAMPLE" ]]; then
            cp "$ENV_EXAMPLE" "$ENV_FILE"
            info "Created ${ENV_FILE}"
        else
            warn "No .env.example found for ${svc}"
        fi
    else
        info "${ENV_FILE} already exists"
    fi
done

success "Service configurations ready"

# Step 4: Stop any existing services
step "4/8 Cleaning up existing services..."
$PODMAN_COMPOSE_CMD -f "$COMPOSE_FILE" down --remove-orphans 2>/dev/null || true
success "Existing services stopped"

# Step 5: Start all services
step "5/8 Starting all services..."
info "Starting infrastructure (PostgreSQL, Redis, MinIO)..."
info "Starting microservices (API Gateway, Auth, Boards, Media, Search, Moderation)..."

$PODMAN_COMPOSE_CMD -f "$COMPOSE_FILE" up -d

# Wait for services to be ready
info "Waiting for services to start..."
sleep 10

# Check if PostgreSQL is ready
info "Waiting for PostgreSQL to be ready..."
for i in {1..30}; do
    if $PODMAN_CMD exec ashchan-postgres-1 pg_isready -U ashchan -d ashchan >/dev/null 2>&1; then
        success "PostgreSQL is ready"
        break
    fi
    if [[ $i -eq 30 ]]; then
        error "PostgreSQL failed to start within 30 seconds"
        exit 1
    fi
    sleep 1
done

success "All services started"

# Step 6: Run database migrations
step "6/8 Running database migrations..."

# SQL migrations
SQL_MIGRATIONS=(
    "001_auth_accounts.sql"
    "001_moderation_system.sql"
    "002_boards_threads_posts.sql"
    "002_staff_auth_security.sql"
    "003_additional_logs.sql"
    "003_media_uploads.sql"
    "004_account_management.sql"
    "004_moderation_anti_spam.sql"
    "005_blotter.sql"
    "006_fix_media_objects.sql"
    "007_add_archived_to_boards.sql"
    "008_janitor_stats.sql"
    "20260219000000_boards_add_ip_address.sql"
    "20260219000001_add_sfs_pending_reports.sql"
    "20260220000001_pii_encryption_retention.sql"
    "20260220000002_create_site_settings.sql"
    "20260221000001_ip_encryption_hash_columns.sql"
)

for migration in "${SQL_MIGRATIONS[@]}"; do
    if [[ -f "db/migrations/${migration}" ]]; then
        info "Running migration: ${migration}"
        $PODMAN_CMD exec ashchan-postgres-1 psql -U ashchan -d ashchan -f "/app/db/migrations/${migration}"
    else
        warn "Migration file not found: ${migration}"
    fi
done

# PHP migrations (using Hyperf/Phinx if available)
PHP_MIGRATIONS=(
    "20260218000001_create_reports_table.php"
    "20260218000002_create_report_categories_table.php"
    "20260218000003_create_ban_templates_table.php"
    "20260218000004_create_banned_users_table.php"
    "20260218000005_create_ban_requests_table.php"
    "20260218000006_create_report_clear_log_table.php"
    "20260218000007_create_janitor_stats_table.php"
)

# Check if we have Hyperf migration command available
if $PODMAN_CMD exec ashchan-boards-1 php /app/bin/hyperf.php migrate:status >/dev/null 2>&1; then
    info "Running PHP migrations via Hyperf..."
    $PODMAN_CMD exec ashchan-boards-1 php /app/bin/hyperf.php migrate
else
    warn "Hyperf migration command not available, skipping PHP migrations"
fi

success "Database migrations completed"

# Step 7: Seed the database
step "7/8 Seeding the database..."

# Run SQL seeders
SQL_SEEDERS=(
    "boards.sql"
)

for seeder in "${SQL_SEEDERS[@]}"; do
    if [[ -f "db/seeders/${seeder}" ]]; then
        info "Running seeder: ${seeder}"
        $PODMAN_CMD exec ashchan-postgres-1 psql -U ashchan -d ashchan -f "/app/db/seeders/${seeder}"
    else
        warn "Seeder file not found: ${seeder}"
    fi
done

# Run PHP seeders if available
PHP_SEEDERS=(
    "BanTemplateSeeder.php"
    "ReportCategorySeeder.php"
)

if $PODMAN_CMD exec ashchan-boards-1 php /app/bin/hyperf.php db:seed --help >/dev/null 2>&1; then
    info "Running PHP seeders via Hyperf..."
    for seeder in "${PHP_SEEDERS[@]}"; do
        seeder_class=$(basename "$seeder" .php)
        if $PODMAN_CMD exec ashchan-boards-1 php /app/bin/hyperf.php db:seed --class="$seeder_class" 2>/dev/null; then
            info "Seeded: ${seeder_class}"
        else
            warn "Seeder not found or failed: ${seeder_class}"
        fi
    done
else
    warn "Hyperf seeder command not available, skipping PHP seeders"
fi

success "Database seeding completed"

# Step 8: Verify installation
step "8/8 Verifying installation..."

# Check service health
info "Checking service health..."
sleep 5  # Give services a moment to fully start

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

# Check mTLS mesh
info "Verifying mTLS ServiceMesh..."
if make mtls-verify >/dev/null 2>&1; then
    success "✓ mTLS ServiceMesh is operational"
else
    warn "✗ mTLS verification failed (services may still be starting)"
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
info "Infrastructure services:"
echo "  • PostgreSQL:     localhost:5432 (ashchan/ashchan)"
echo "  • Redis:          localhost:6379"
echo "  • MinIO:          localhost:9000"
echo
info "Useful commands:"
if [[ "$ROOTED_MODE" == "true" ]]; then
echo "  • View logs:      sudo podman-compose logs -f"
echo "  • Stop services:  sudo podman-compose down"
echo "  • Restart:        ./bootstrap.sh --force --rooted"
else
echo "  • View logs:      podman-compose logs -f"
echo "  • Stop services:  podman-compose down"
echo "  • Restart:        ./bootstrap.sh --force"
fi
echo
info "Flags:"
echo "  • --force         Force rebuild (regenerate certs, recreate .env files)"
echo "  • --rooted        Use sudo podman (dev containers only, not production)"
echo
info "Check the README.md for more information!"
echo