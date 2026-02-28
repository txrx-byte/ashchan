#!/usr/bin/env bash
#
# Migrate secrets from .env files to OpenBao
#
# Usage: sudo tools/openbao/migrate-secrets.sh [--dry-run|--rollback]
#
# License: Apache 2.0
#

set -euo pipefail

# ─────────────────────────────────────────────────────────────
# Configuration
# ─────────────────────────────────────────────────────────────

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly SERVICES_DIR="$ROOT_DIR/services"
readonly OPENBAO_CONFIG="/etc/openbao/openbao.hcl"
readonly ASHCHAN_OPENBAO_DIR="/etc/ashchan/openbao"
readonly CLIENT_CERT="$ASHCHAN_OPENBAO_DIR/client/client.crt"
readonly CLIENT_KEY="$ASHCHAN_OPENBAO_DIR/client/client.key"
readonly CA_CERT="$ASHCHAN_OPENBAO_DIR/ca.crt"

readonly SERVICES=("api-gateway" "auth-accounts" "boards-threads-posts" "media-uploads" "search-indexing" "moderation-anti-spam")

# Colors
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m'

# Mode
DRY_RUN=false
ROLLBACK=false
OPENBAO_TOKEN=""

# ─────────────────────────────────────────────────────────────
# Helper Functions
# ─────────────────────────────────────────────────────────────

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[✓]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_header() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

check_prerequisites() {
    if [[ $EUID -ne 0 ]]; then
        log_error "This script must be run as root"
        exit 1
    fi

    if [[ ! -f "$OPENBAO_CONFIG" ]]; then
        log_error "OpenBao not installed. Run: sudo tools/openbao/install.sh"
        exit 1
    fi

    if [[ ! -f "$CLIENT_CERT" ]] || [[ ! -f "$CLIENT_KEY" ]]; then
        log_error "Client certificates not found. Re-run installer."
        exit 1
    fi

    # Check if OpenBao is running
    if ! systemctl is-active --quiet openbao 2>/dev/null; then
        log_error "OpenBao service is not running"
        exit 1
    fi
}

get_openbao_token() {
    # Check if token is already set
    if [[ -n "${OPENBAO_TOKEN:-}" ]]; then
        return
    fi

    # Try to get token from environment
    if [[ -n "${OPENBAO_TOKEN:-}" ]]; then
        return
    fi

    # For dev mode, try to read from file
    if [[ -f "/var/lib/openbao/token.json" ]]; then
        OPENBAO_TOKEN=$(jq -r '.token' "/var/lib/openbao/token.json")
        return
    fi

    # Prompt for token
    echo ""
    log_info "Enter OpenBao root token (from initialization)"
    read -p "Token: " -s OPENBAO_TOKEN
    echo ""
}

openbao_write() {
    local path="$1"
    local data="$2"

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $OPENBAO_TOKEN" \
        -H "Content-Type: application/json" \
        -X POST \
        -d "$data" \
        "http://localhost:8200/v1/$path"
}

openbao_read() {
    local path="$1"

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $OPENBAO_TOKEN" \
        "http://localhost:8200/v1/$path" | jq -r '.data'
}

openbao_delete() {
    local path="$1"

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $OPENBAO_TOKEN" \
        -X DELETE \
        "http://localhost:8200/v1/$path"
}

# ─────────────────────────────────────────────────────────────
# Migration Functions
# ─────────────────────────────────────────────────────────────

parse_env_file() {
    local env_file="$1"
    local service_name="$2"

    if [[ ! -f "$env_file" ]]; then
        log_warn ".env file not found: $env_file"
        return 1
    fi

    # Parse key=value pairs, skip comments and empty lines
    declare -gA SECRETS
    while IFS='=' read -r key value; do
        # Skip comments and empty lines
        [[ -z "$key" || "$key" =~ ^[[:space:]]*# ]] && continue

        # Remove leading/trailing whitespace and quotes
        key=$(echo "$key" | xargs)
        value=$(echo "$value" | sed 's/^["'"'"']//;s/["'"'"']$//')

        # Skip empty values
        [[ -z "$value" ]] && continue

        SECRETS["$key"]="$value"
    done < "$env_file"

    return 0
}

migrate_service_secrets() {
    local service="$1"
    local env_file="$SERVICES_DIR/$service/.env"

    print_step "Migrating secrets for: $service"

    if [[ ! -f "$env_file" ]]; then
        log_warn "No .env file for $service, skipping"
        return 0
    fi

    declare -A SECRETS
    parse_env_file "$env_file" "$service" || return 0

    if [[ ${#SECRETS[@]} -eq 0 ]]; then
        log_warn "No secrets found in $env_file"
        return 0
    fi

    # Build JSON payload
    local json_data='{"data":{'
    local first=true
    for key in "${!SECRETS[@]}"; do
        if [[ "$first" == "true" ]]; then
            first=false
        else
            json_data+=','
        fi
        # Escape special characters in value
        local escaped_value
        escaped_value=$(echo "${SECRETS[$key]}" | jq -Rs '.')
        json_data+="\"$key\":$escaped_value"
    done
    json_data+='}}'

    if [[ "$DRY_RUN" == "true" ]]; then
        echo "  Would write to: secret/ashchan/services/$service"
        echo "  Keys: ${!SECRETS[*]}"
    else
        local response
        response=$(openbao_write "secret/ashchan/services/$service" "$json_data" 2>&1) || {
            log_error "Failed to write secrets for $service: $response"
            return 1
        }
        log_success "Migrated ${#SECRETS[@]} secrets for $service"
    fi
}

migrate_global_secrets() {
    print_step "Migrating global secrets..."

    # Collect common secrets from all services
    declare -A GLOBAL_SECRETS

    for service in "${SERVICES[@]}"; do
        local env_file="$SERVICES_DIR/$service/.env"
        [[ ! -f "$env_file" ]] && continue

        declare -A SECRETS
        parse_env_file "$env_file" "$service" || continue

        # Extract common secrets
        for key in JWT_SECRET PII_ENCRYPTION_KEY IP_HMAC_KEY REDIS_PASSWORD; do
            if [[ -n "${SECRETS[$key]:-}" ]]; then
                GLOBAL_SECRETS["$key"]="${SECRETS[$key]}"
            fi
        done
    done

    if [[ ${#GLOBAL_SECRETS[@]} -eq 0 ]]; then
        log_warn "No global secrets found"
        return 0
    fi

    # Build JSON payload
    local json_data='{"data":{'
    local first=true
    for key in "${!GLOBAL_SECRETS[@]}"; do
        if [[ "$first" == "true" ]]; then
            first=false
        else
            json_data+=','
        fi
        local escaped_value
        escaped_value=$(echo "${GLOBAL_SECRETS[$key]}" | jq -Rs '.')
        json_data+="\"$key\":$escaped_value"
    done
    json_data+='}}'

    if [[ "$DRY_RUN" == "true" ]]; then
        echo "  Would write to: secret/ashchan/global"
        echo "  Keys: ${!GLOBAL_SECRETS[*]}"
    else
        local response
        response=$(openbao_write "secret/ashchan/global" "$json_data" 2>&1) || {
            log_error "Failed to write global secrets: $response"
            return 1
        }
        log_success "Migrated ${#GLOBAL_SECRETS[@]} global secrets"
    fi
}

backup_env_files() {
    print_step "Backing up .env files..."

    local backup_dir="/var/backups/openbao/env-files-$(date +%Y%m%d-%H%M%S)"
    mkdir -p "$backup_dir"

    for service in "${SERVICES[@]}"; do
        local env_file="$SERVICES_DIR/$service/.env"
        if [[ -f "$env_file" ]]; then
            cp "$env_file" "$backup_dir/${service}.env"
        fi
    done

    log_success "Backed up .env files to: $backup_dir"
    echo "  Restore with: sudo tools/openbao/migrate-secrets.sh --rollback"
}

create_backup_script() {
    if [[ "$DRY_RUN" == "true" ]]; then
        return
    fi

    print_step "Creating backup script..."

    cat > /usr/local/bin/openbao-backup << 'EOF'
#!/usr/bin/env bash
# OpenBao Backup Script for Ashchan

set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/openbao}"
DATE=$(date +%Y%m%d-%H%M%S)

mkdir -p "$BACKUP_DIR"

# Backup Raft storage (if applicable)
if [[ -d "/var/lib/openbao/raft" ]]; then
    tar -czf "$BACKUP_DIR/raft-$DATE.tar.gz" -C /var/lib/openbao raft/
    echo "Raft storage backed up to: $BACKUP_DIR/raft-$DATE.tar.gz"
fi

# Backup configuration
tar -czf "$BACKUP_DIR/config-$DATE.tar.gz" -C /etc/openbao .
echo "Configuration backed up to: $BACKUP_DIR/config-$DATE.tar.gz"

# Backup client certificates
tar -czf "$BACKUP_DIR/certs-$DATE.tar.gz" -C /etc/ashchan/openbao .
echo "Certificates backed up to: $BACKUP_DIR/certs-$DATE.tar.gz"

echo "Backup complete: $BACKUP_DIR"
EOF

    chmod +x /usr/local/bin/openbao-backup

    log_success "Backup script created: /usr/local/bin/openbao-backup"
}

# ─────────────────────────────────────────────────────────────
# Rollback Functions
# ─────────────────────────────────────────────────────────────

rollback_secrets() {
    print_header "Rollback Mode"

    log_warn "This will delete secrets from OpenBao"
    echo ""
    read -p "Continue? [y/N]: " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        log_info "Rollback cancelled"
        exit 0
    fi

    print_step "Deleting service secrets..."

    for service in "${SERVICES[@]}"; do
        if [[ "$DRY_RUN" == "true" ]]; then
            echo "  Would delete: secret/ashchan/services/$service"
        else
            openbao_delete "secret/ashchan/services/$service" 2>/dev/null || true
            log_success "Deleted secrets for: $service"
        fi
    done

    print_step "Deleting global secrets..."

    if [[ "$DRY_RUN" == "true" ]]; then
        echo "  Would delete: secret/ashchan/global"
    else
        openbao_delete "secret/ashchan/global" 2>/dev/null || true
        log_success "Deleted global secrets"
    fi

    print_step "Restoring .env files..."

    # Find most recent backup
    local backup_dir
    backup_dir=$(ls -td /var/backups/openbao/env-files-* 2>/dev/null | head -1)

    if [[ -z "$backup_dir" ]]; then
        log_error "No backup found. Manual restore required."
        exit 1
    fi

    log_info "Restoring from: $backup_dir"

    for service in "${SERVICES[@]}"; do
        local env_backup="$backup_dir/${service}.env"
        local env_target="$SERVICES_DIR/$service/.env"

        if [[ -f "$env_backup" ]]; then
            if [[ "$DRY_RUN" == "true" ]]; then
                echo "  Would restore: $env_target"
            else
                cp "$env_backup" "$env_target"
                log_success "Restored: $service"
            fi
        fi
    done

    print_header "Rollback Complete"

    echo "Secrets have been removed from OpenBao and .env files restored."
    echo ""
    log_warn "Remember to restart services to pick up .env files"
}

# ─────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────

print_step() {
    echo -e "${GREEN}[*]${NC} $1"
}

main() {
    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --dry-run)
                DRY_RUN=true
                shift
                ;;
            --rollback)
                ROLLBACK=true
                shift
                ;;
            --token)
                OPENBAO_TOKEN="$2"
                shift 2
                ;;
            *)
                log_error "Unknown option: $1"
                echo "Usage: $0 [--dry-run|--rollback] [--token TOKEN]"
                exit 1
                ;;
        esac
    done

    print_header "OpenBao Secrets Migration for Ashchan"

    check_prerequisites

    if [[ "$ROLLBACK" == "true" ]]; then
        rollback_secrets
        exit 0
    fi

    get_openbao_token

    print_header "Migration Plan"

    echo "The following secrets will be migrated to OpenBao:"
    echo ""

    for service in "${SERVICES[@]}"; do
        local env_file="$SERVICES_DIR/$service/.env"
        if [[ -f "$env_file" ]]; then
            local count
            count=$(grep -v '^#' "$env_file" | grep -v '^$' | wc -l)
            echo "  $service: $count secrets"
        else
            echo "  $service: (no .env file)"
        fi
    done

    echo ""

    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY RUN MODE - No changes will be made"
    else
        log_warn "After migration, services will read secrets from OpenBao"
        log_warn "Backup of .env files will be created"
    fi

    echo ""
    read -p "Continue? [Y/n]: " confirm
    if [[ "$confirm" =~ ^[Nn]$ ]]; then
        log_info "Migration cancelled"
        exit 0
    fi

    if [[ "$DRY_RUN" != "true" ]]; then
        backup_env_files
    fi

    print_header "Migrating Secrets"

    migrate_global_secrets

    for service in "${SERVICES[@]}"; do
        migrate_service_secrets "$service"
    done

    if [[ "$DRY_RUN" != "true" ]]; then
        create_backup_script
    fi

    print_header "Migration Summary"

    echo "Secrets have been migrated to OpenBao."
    echo ""
    echo "Next steps:"
    echo ""
    if [[ "$DRY_RUN" != "true" ]]; then
        echo "  1. Update systemd service files to use OpenBao secrets"
        echo "     Run: make openbao-update-services"
        echo ""
        echo "  2. Restart services to pick up new configuration"
        echo "     Run: make restart"
        echo ""
        echo "  3. Verify services can read secrets"
        echo "     Run: make openbao-audit"
        echo ""
        echo "  4. (Optional) Remove .env files after verification"
        echo "     Backup location: /var/backups/openbao/"
    else
        echo "  This was a dry run. Run without --dry-run to migrate."
    fi

    log_success "Migration complete!"
}

main "$@"
