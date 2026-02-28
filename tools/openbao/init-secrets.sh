#!/usr/bin/env bash
#
# Initialize OpenBao secrets engines for Ashchan
#
# Usage: tools/openbao/init-secrets.sh
#
# License: Apache 2.0
#

set -euo pipefail

readonly ASHCHAN_OPENBAO_DIR="/etc/ashchan/openbao"
readonly CLIENT_CERT="$ASHCHAN_OPENBAO_DIR/client/client.crt"
readonly CLIENT_KEY="$ASHCHAN_OPENBAO_DIR/client/client.key"
readonly CA_CERT="$ASHCHAN_OPENBAO_DIR/ca.crt"

readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[✓]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

print_header() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

# Get token from environment or prompt
get_token() {
    if [[ -n "${OPENBAO_TOKEN:-}" ]]; then
        echo "$OPENBAO_TOKEN"
        return
    fi

    log_info "Enter OpenBao root token:"
    read -p "Token: " -s token
    echo ""
    echo "$token"
}

TOKEN=$(get_token)

enable_kv_engine() {
    log_info "Enabling KV v2 secrets engine..."

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $TOKEN" \
        -X POST \
        -d '{"type": "kv-v2"}' \
        http://localhost:8200/v1/sys/mounts/secret/ashchan 2>/dev/null && \
        log_success "KV v2 engine enabled at secret/ashchan" || \
        log_info "KV engine may already be enabled"
}

enable_database_engine() {
    log_info "Enabling Database secrets engine..."

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $TOKEN" \
        -X POST \
        -d '{"type": "database"}' \
        http://localhost:8200/v1/sys/mounts/database/ashchan 2>/dev/null && \
        log_success "Database engine enabled at database/ashchan" || \
        log_info "Database engine may already be enabled"
}

enable_transit_engine() {
    log_info "Enabling Transit encryption engine..."

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $TOKEN" \
        -X POST \
        -d '{"type": "transit"}' \
        http://localhost:8200/v1/sys/mounts/transit/ashchan 2>/dev/null && \
        log_success "Transit engine enabled at transit/ashchan" || \
        log_info "Transit engine may already be enabled"
}

create_pii_key() {
    log_info "Creating PII encryption key..."

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $TOKEN" \
        -X POST \
        -d '{"type": "aes256-gcm96"}' \
        http://localhost:8200/v1/transit/ashchan/keys/ashchan-pii 2>/dev/null && \
        log_success "PII encryption key created" || \
        log_info "PII key may already exist"
}

configure_postgresql_database() {
    log_info "Configuring PostgreSQL database connection..."

    # Read DB config from environment or prompt
    read -p "PostgreSQL host [localhost]: " DB_HOST
    DB_HOST="${DB_HOST:-localhost}"

    read -p "PostgreSQL port [5432]: " DB_PORT
    DB_PORT="${DB_PORT:-5432}"

    read -p "PostgreSQL database [ashchan]: " DB_NAME
    DB_NAME="${DB_NAME:-ashchan}"

    read -p "PostgreSQL admin username [ashchan]: " DB_USER
    DB_USER="${DB_USER:-ashchan}"

    read -p "PostgreSQL admin password: " -s DB_PASSWORD
    echo ""

    # Configure database connection
    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $TOKEN" \
        -X POST \
        -d "{
            \"plugin_name\": \"postgresql-database-plugin\",
            \"allowed_roles\": [\"ashchan\"],
            \"connection_url\": \"postgresql://{{username}}:{{password}}@${DB_HOST}:${DB_PORT}/${DB_NAME}?sslmode=disable\",
            \"username\": \"${DB_USER}\",
            \"password\": \"${DB_PASSWORD}\"
        }" \
        http://localhost:8200/v1/database/ashchan/config/ashchan 2>/dev/null && \
        log_success "PostgreSQL connection configured" || \
        log_error "Failed to configure PostgreSQL"
}

create_database_role() {
    log_info "Creating dynamic credentials role..."

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $TOKEN" \
        -X POST \
        -d '{
            "db_name": "ashchan",
            "creation_statements": [
                "CREATE ROLE \"{{name}}\" WITH LOGIN PASSWORD '\''{{password}}'\'' VALID UNTIL '\''{{expiration}}'\'';",
                "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO \"{{name}}\";",
                "GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO \"{{name}}\";"
            ],
            "default_ttl": "1h",
            "max_ttl": "24h"
        }' \
        http://localhost:8200/v1/database/ashchan/roles/ashchan 2>/dev/null && \
        log_success "Database role created" || \
        log_error "Failed to create database role"
}

create_ashchan_policy() {
    log_info "Creating Ashchan services policy..."

    curl -sf \
        --cacert "$CA_CERT" \
        --cert "$CLIENT_CERT" \
        --key "$CLIENT_KEY" \
        -H "X-Vault-Token: $TOKEN" \
        -X POST \
        -d '{
            "policy": "path \"secret/ashchan/global\" {\n  capabilities = [\"read\"]\n}\npath \"secret/ashchan/services/*\" {\n  capabilities = [\"read\"]\n}\npath \"database/creds/ashchan\" {\n  capabilities = [\"read\"]\n}\npath \"transit/encrypt/ashchan-pii\" {\n  capabilities = [\"update\"]\n}\npath \"transit/decrypt/ashchan-pii\" {\n  capabilities = [\"update\"]\n}"
        }' \
        http://localhost:8200/v1/sys/policies/acl/ashchan-services 2>/dev/null && \
        log_success "Policy created" || \
        log_info "Policy may already exist"
}

print_header "Initializing OpenBao Secrets Engines for Ashchan"

enable_kv_engine
enable_database_engine
enable_transit_engine
create_pii_key

echo ""
read -p "Configure PostgreSQL dynamic credentials? [Y/n]: " configure_db
if [[ ! "$configure_db" =~ ^[Nn]$ ]]; then
    configure_postgresql_database
    create_database_role
fi

create_ashchan_policy

print_header "Initialization Complete"

echo "Secrets engines initialized:"
echo ""
echo "  ✓ KV v2 at:        secret/ashchan/"
echo "  ✓ Database at:     database/ashchan/"
echo "  ✓ Transit at:      transit/ashchan/"
echo "  ✓ PII key:         transit/ashchan/keys/ashchan-pii"
echo "  ✓ Policy:          ashchan-services"
echo ""
echo "Next steps:"
echo "  1. Run: make openbao-migrate-secrets"
echo "  2. Run: make openbao-update-services"
echo "  3. Run: make restart"
echo ""

log_success "Initialization complete!"
