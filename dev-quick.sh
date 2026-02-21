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
# Ashchan Quick Development Script
# 
# Lightweight version of bootstrap.sh for rapid development iterations.
# Assumes mTLS certs and .env files are already configured.
#
# Usage: ./dev-quick.sh [--rooted]
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${SCRIPT_DIR}/podman-compose.yml"

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
NC='\033[0m'

info() { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
step() { echo -e "${CYAN}[STEP]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

# Parse command line arguments
ROOTED_MODE=false

for arg in "$@"; do
    case $arg in
        --rooted)
            ROOTED_MODE=true
            warn "Rooted mode enabled (for dev containers/testing)"
            ;;
        *)
            echo "Usage: $0 [--rooted]"
            exit 1
            ;;
    esac
done

# Set podman command prefix based on mode
if [[ "$ROOTED_MODE" == "true" ]]; then
    PODMAN_CMD="sudo podman"
    PODMAN_COMPOSE_CMD="sudo podman-compose"
else
    PODMAN_CMD="podman"
    PODMAN_COMPOSE_CMD="podman-compose"
fi

echo
echo "🚀 Ashchan Quick Development Setup"
echo "=================================="

# Step 1: Clean restart
step "1/4 Restarting services..."
$PODMAN_COMPOSE_CMD -f "$COMPOSE_FILE" down --remove-orphans 2>/dev/null || true
$PODMAN_COMPOSE_CMD -f "$COMPOSE_FILE" up -d

# Wait for PostgreSQL
info "Waiting for PostgreSQL..."
for i in {1..20}; do
    if $PODMAN_CMD exec ashchan-postgres-1 pg_isready -U ashchan -d ashchan >/dev/null 2>&1; then
        break
    fi
    if [[ $i -eq 20 ]]; then
        echo "PostgreSQL not ready, continuing anyway..."
        break
    fi
    sleep 1
done

# Step 2: Quick migration check (only run latest if needed)
step "2/4 Checking migrations..."
if $PODMAN_CMD exec ashchan-boards-1 php /app/bin/hyperf.php migrate:status >/dev/null 2>&1; then
    info "Running any pending migrations..."
    $PODMAN_CMD exec ashchan-boards-1 php /app/bin/hyperf.php migrate
else
    # Fallback to key SQL migrations
    info "Running key SQL migrations..."
    $PODMAN_CMD exec ashchan-postgres-1 psql -U ashchan -d ashchan -f "/app/db/migrations/002_boards_threads_posts.sql" 2>/dev/null || true
fi

# Step 3: Quick seed (only boards if empty)
step "3/4 Quick seeding..."
BOARD_COUNT=$($PODMAN_CMD exec ashchan-postgres-1 psql -U ashchan -d ashchan -t -c "SELECT COUNT(*) FROM boards;" 2>/dev/null | tr -d ' ' || echo "0")
if [[ "$BOARD_COUNT" == "0" ]]; then
    info "Seeding boards..."
    $PODMAN_CMD exec ashchan-postgres-1 psql -U ashchan -d ashchan -f "/app/db/seeders/boards.sql" 2>/dev/null || true
else
    info "Boards already seeded (${BOARD_COUNT} boards found)"
fi

# Step 4: Quick health check
step "4/4 Health check..."
sleep 3
if curl -s -f "http://localhost:9501/health" >/dev/null 2>&1; then
    success "✓ API Gateway is healthy"
else
    info "API Gateway still starting... (this is normal)"
fi

echo
success "🎯 Quick setup complete!"
echo "=================================="
echo "API Gateway: http://localhost:9501"
if [[ "$ROOTED_MODE" == "true" ]]; then
echo "Logs:        sudo podman-compose logs -f"
else
echo "Logs:        podman-compose logs -f"
fi
echo