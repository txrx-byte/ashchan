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
# Ashchan Podman Management Script
# Usage: ./ashchan.sh <command>
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${SCRIPT_DIR}/podman-compose.yml"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Print colored output
info() { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Show help
show_help() {
    cat << EOF
Ashchan Podman Management Script

Usage: ./ashchan.sh <command>

Commands:
  up          Start all services (creates network and volumes)
  down        Stop and remove all containers, network, and volumes
  stop        Stop all containers (keeps volumes and network)
  restart     Restart all containers
  status      Show container status
  logs        Tail logs from all services (use Ctrl+C to stop)
  logs <svc>  Tail logs from a specific service
  ps          List running containers
  network     Show network configuration
  clean       Remove all containers, networks, volumes, and build cache
  rebuild     Rebuild all service images
  rebuild <svc>  Rebuild a specific service image
  migrate     Run database migrations
  health      Check health of all services
  setup       Initial setup (copy .env files and start services)
  help        Show this help message

Examples:
  ./ashchan.sh setup      # First-time setup
  ./ashchan.sh up         # Start services
  ./ashchan.sh logs       # View logs
  ./ashchan.sh health     # Check service health
  ./ashchan.sh down       # Stop everything

EOF
}

# Check if podman is installed
check_podman() {
    if ! command -v podman &> /dev/null; then
        error "Podman is not installed. Please install podman first."
        exit 1
    fi
}

# Check if podman-compose is installed
check_podman_compose() {
    if ! command -v podman-compose &> /dev/null; then
        error "podman-compose is not installed. Please install it:"
        echo "  pip install podman-compose"
        echo "  or: sudo dnf install podman-docker"
        exit 1
    fi
}

# Initialize .env files for all services
init_env_files() {
    info "Setting up .env files for all services..."
    local services=("api-gateway" "auth-accounts" "boards-threads-posts" "media-uploads" "search-indexing" "moderation-anti-spam")
    
    for svc in "${services[@]}"; do
        local env_file="${SCRIPT_DIR}/services/${svc}/.env"
        local env_example="${SCRIPT_DIR}/services/${svc}/.env.example"
        
        if [[ ! -f "$env_file" ]] && [[ -f "$env_example" ]]; then
            cp "$env_example" "$env_file"
            success "Created ${svc}/.env"
        elif [[ -f "$env_file" ]]; then
            info "${svc}/.env already exists"
        else
            warn "No .env.example found for ${svc}"
        fi
    done
}

# Start all services
start_services() {
    info "Cleaning up stale containers and networks..."
    cd "$SCRIPT_DIR"
    podman-compose down --remove-orphans 2>/dev/null || true
    
    info "Starting Ashchan services..."
    podman-compose up -d
    success "Services started!"
    info "Run './ashchan.sh health' to check service status"
}

# Stop all services
stop_services() {
    info "Stopping all services..."
    cd "$SCRIPT_DIR"
    podman-compose stop
    success "Services stopped"
}

# Stop and remove everything (including volumes)
down_services() {
    info "Stopping and removing all containers, networks, and volumes..."
    cd "$SCRIPT_DIR"
    podman-compose down -v
    success "All services, networks, and volumes removed"
}

# Restart services
restart_services() {
    info "Restarting all services..."
    cd "$SCRIPT_DIR"
    podman-compose restart
    success "Services restarted"
}

# Show container status
show_status() {
    cd "$SCRIPT_DIR"
    podman-compose ps
}

# Show running containers
show_ps() {
    podman ps -a
}

# Tail logs
tail_logs() {
    cd "$SCRIPT_DIR"
    if [[ -n "$1" ]]; then
        info "Tailing logs for: $1"
        podman-compose logs -f "$1"
    else
        info "Tailing logs from all services (Ctrl+C to stop)..."
        podman-compose logs -f
    fi
}

# Show network info
show_network() {
    info "Ashchan Networks:"
    podman network ls | grep -E "NAME|ashchan" || true
    
    info "Network Details:"
    podman network inspect ashchan 2>/dev/null | head -20 || warn "Network 'ashchan' not found"
}

# Clean everything
clean_all() {
    warn "This will remove ALL containers, networks, volumes, and build cache!"
    read -p "Are you sure? (y/N): " confirm
    
    if [[ "$confirm" =~ ^[Yy]$ ]]; then
        info "Stopping services..."
        cd "$SCRIPT_DIR"
        podman-compose down -v 2>/dev/null || true
        
        info "Removing unused networks..."
        podman network prune -f
        
        info "Removing unused volumes..."
        podman volume prune -f
        
        info "Removing unused images..."
        podman image prune -f
        
        info "Removing build cache..."
        podman builder prune -f
        
        success "Cleanup complete!"
    else
        info "Cleanup cancelled"
    fi
}

# Rebuild service images
rebuild_images() {
    cd "$SCRIPT_DIR"
    if [[ -n "$1" ]]; then
        info "Rebuilding image for: $1"
        podman-compose build "$1"
    else
        info "Rebuilding all service images..."
        podman-compose build
    fi
    success "Build complete"
}

# Run database migrations
run_migrations() {
    info "Running database migrations..."
    
    # Wait for postgres to be ready
    info "Waiting for PostgreSQL to be ready..."
    local max_attempts=30
    local attempt=0
    
    while [[ $attempt -lt $max_attempts ]]; do
        if podman exec ashchan-postgres-1 pg_isready -U ashchan &>/dev/null; then
            success "PostgreSQL is ready"
            break
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    
    if [[ $attempt -eq $max_attempts ]]; then
        error "PostgreSQL did not become ready in time"
        exit 1
    fi
    
    # Run migrations from db/migrations directory
    local db_dir="${SCRIPT_DIR}/db/migrations"
    if [[ -d "$db_dir" ]]; then
        for migration in "$db_dir"/*.sql; do
            if [[ -f "$migration" ]]; then
                info "Running: $(basename "$migration")"
                podman exec -i ashchan-postgres-1 psql -U ashchan -d ashchan < "$migration"
            fi
        done
        success "Migrations complete"
    else
        warn "No db/ directory found. Running service-specific migrations..."
        
        # Run Hyperf migrations for each service
        local services=("moderation-anti-spam" "auth-accounts" "boards-threads-posts" "media-uploads")
        for svc in "${services[@]}"; do
            info "Running migrations for ${svc}..."
            podman exec -w "/app" "ashchan-${svc}-1" php bin/hyperf.php db:migrate 2>/dev/null || \
                warn "Migration failed or not available for ${svc}"
        done
    fi
}

# Check health of all services
check_health() {
    info "Checking service health..."
    echo ""
    
    local services=(
        "postgres-1:5432:tcp"
        "redis-1:6379:tcp"
        "minio-1:9000:http" # Corrected port for health check
        "gateway-1:9501:http"
        "auth-1:8502:mtls"
        "boards-1:8503:mtls"
        "media-1:8504:mtls"
        "search-1:8505:mtls"
        "moderation-1:8506:mtls"
    )
    
    for svc in "${services[@]}"; do
        IFS=':' read -r name port type <<< "$svc"
        
        # Check if container is running
        local status=$(podman inspect "ashchan-${name}" --format '{{.State.Status}}' 2>/dev/null || echo "not_found")
        
        if [[ "$status" == "running" ]]; then
            if [[ "$type" == "http" ]]; then
                local response=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${port}/health" 2>/dev/null || echo "000")
                if [[ "$name" == "minio-1" ]]; then
                    response=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${port}/minio/health/live" 2>/dev/null || echo "000")
                fi
                if [[ "$response" == "200" ]]; then
                    success "${name}: healthy (HTTP 200)"
                else
                    warn "${name}: running but health check returned ${response}"
                fi
            elif [[ "$type" == "mtls" ]]; then
                # Use gateway certs to test mTLS connection from INSIDE the gateway container
                local service_short_name="${name%-1}" # e.g., auth-1 -> auth
                local dns_name="${service_short_name}.ashchan.local"

                local response=$(podman exec ashchan-gateway-1 curl -s -k -o /dev/null -w "%{http_code}" \
                    --cert /etc/mtls/gateway/gateway.crt \
                    --key /etc/mtls/gateway/gateway.key \
                    --cacert /etc/mtls/ca/ca.crt \
                    "https://${dns_name}:8443/health" 2>/dev/null || echo "000")
                
                if [[ "$response" == "200" ]]; then
                    success "${name}: healthy (mTLS 200)"
                else
                    warn "${name}: running but mTLS check returned ${response} (from gateway: https://${dns_name}:8443/health)"
                fi
            else
                # TCP check
                success "${name}: running"
            fi
        elif [[ "$status" == "not_found" ]]; then
            error "${name}: container not found"
        else
            error "${name}: ${status}"
        fi
    done
    
    echo ""
    info "Quick access URLs:"
    echo "  API Gateway:    http://localhost:9501"
    echo "  Staff Interface: http://localhost:9501/staff"
    echo "  MinIO Console:  http://localhost:9001"
}

# Full setup
setup_all() {
    info "Running Ashchan initial setup..."
    echo ""

    # ── mTLS Certificate Generation ──────────────────────────────
    info "Configuring mTLS ServiceMesh..."

    if [[ ! -f "${SCRIPT_DIR}/certs/ca/ca.crt" ]]; then
        info "Generating mTLS Certificate Authority..."
        bash "${SCRIPT_DIR}/scripts/mtls/generate-ca.sh"
        echo ""
    else
        success "CA certificate already exists"
    fi

    local all_certs_present=true
    local cert_services=("gateway" "auth" "boards" "media" "search" "moderation")
    for svc in "${cert_services[@]}"; do
        if [[ ! -f "${SCRIPT_DIR}/certs/services/${svc}/${svc}.crt" ]] || \
           [[ ! -f "${SCRIPT_DIR}/certs/services/${svc}/${svc}.key" ]]; then
            all_certs_present=false
            break
        fi
    done

    if [[ "$all_certs_present" == "false" ]]; then
        info "Generating service certificates..."
        bash "${SCRIPT_DIR}/scripts/mtls/generate-all-certs.sh"
        echo ""
    else
        success "All service certificates already exist"
    fi

    # Verify certificate chain
    info "Verifying certificate chain..."
    local cert_ok=true
    for svc in "${cert_services[@]}"; do
        local cert="${SCRIPT_DIR}/certs/services/${svc}/${svc}.crt"
        if ! openssl verify -CAfile "${SCRIPT_DIR}/certs/ca/ca.crt" "${cert}" &>/dev/null; then
            error "Certificate verification failed for ${svc}"
            cert_ok=false
        fi
    done
    if [[ "$cert_ok" == "true" ]]; then
        success "All certificates verified against CA"
    fi

    # Ensure correct permissions: 644 for certs, 600 for keys
    info "Setting certificate permissions..."
    find "${SCRIPT_DIR}/certs" -name "*.crt" -exec chmod 644 {} \;
    find "${SCRIPT_DIR}/certs" -name "*.key" -exec chmod 600 {} \;
    success "Certificate permissions set (644 certs, 600 keys)"

    echo ""

    # ── Environment Files ────────────────────────────────────────
    init_env_files
    echo ""

    # ── Build and Start ──────────────────────────────────────────
    info "Building and starting services (this may take a few minutes)..."
    start_services
    echo ""

    # ── Database Migrations ──────────────────────────────────────
    info "Running database migrations..."
    run_migrations
    echo ""

    info "Waiting for services to initialize..."
    sleep 10

    check_health
}

# Main command handler
main() {
    check_podman
    
    local command="${1:-help}"
    shift || true
    
    case "$command" in
        up)
            check_podman_compose
            start_services
            ;;
        down)
            check_podman_compose
            down_services
            ;;
        stop)
            check_podman_compose
            stop_services
            ;;
        restart)
            check_podman_compose
            restart_services
            ;;
        status|ps)
            check_podman_compose
            show_status
            ;;
        logs)
            check_podman_compose
            tail_logs "$@"
            ;;
        network)
            show_network
            ;;
        clean)
            clean_all
            ;;
        rebuild|build)
            check_podman_compose
            rebuild_images "$@"
            ;;
        migrate)
            run_migrations
            ;;
        health)
            check_health
            ;;
        setup)
            check_podman_compose
            setup_all
            ;;
        help|--help|-h)
            show_help
            ;;
        *)
            error "Unknown command: $command"
            echo ""
            show_help
            exit 1
            ;;
    esac
}

main "$@"
