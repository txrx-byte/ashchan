#!/usr/bin/env bash
#
# OpenBao Installer for Ashchan
#
# Interactive installer that configures OpenBao secrets management
# with sensible defaults and deployment-mode-specific options.
#
# Usage: sudo tools/openbao/install.sh
#
# License: Apache 2.0
#

set -euo pipefail

# ─────────────────────────────────────────────────────────────
# Configuration
# ─────────────────────────────────────────────────────────────

readonly OPENBAO_VERSION="2.5.0"
readonly OPENBAO_DOWNLOAD_URL="https://github.com/openbao/openbao/releases/download/v${OPENBAO_VERSION}/openbao_${OPENBAO_VERSION}_linux_amd64.zip"
readonly OPENBAO_CHECKSUM_URL="https://github.com/openbao/openbao/releases/download/v${OPENBAO_VERSION}/openbao_${OPENBAO_VERSION}_SHA256SUMS"

readonly INSTALL_DIR="/opt/openbao"
readonly CONFIG_DIR="/etc/openbao"
readonly DATA_DIR="/var/lib/openbao"
readonly LOG_DIR="/var/log/openbao"
readonly AUDIT_DIR="/var/log/openbao/audit"

readonly SYSTEMD_SERVICE="/etc/systemd/system/openbao.service"
readonly ENV_FILE="/etc/openbao/openbao.env"

readonly ASHCHAN_CONFIG_DIR="/etc/ashchan/openbao"
readonly CLIENT_CERT_DIR="/etc/ashchan/openbao/client"

# Colors for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m' # No Color

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

print_step() {
    echo -e "${GREEN}[*]${NC} $1"
}

# ─────────────────────────────────────────────────────────────
# Prerequisites Check
# ─────────────────────────────────────────────────────────────

check_prerequisites() {
    print_header "Checking Prerequisites"

    local missing=()

    # Check for root
    if [[ $EUID -ne 0 ]]; then
        log_error "This script must be run as root"
        exit 1
    fi

    # Check required commands
    for cmd in curl jq openssl unzip systemctl; do
        if ! command -v "$cmd" &> /dev/null; then
            missing+=("$cmd")
        fi
    done

    if [[ ${#missing[@]} -gt 0 ]]; then
        log_error "Missing required commands: ${missing[*]}"
        log_info "Install them with:"
        echo "  Ubuntu/Debian: sudo apt-get install -y ${missing[*]}"
        echo "  Alpine: sudo apk add --no-cache ${missing[*]}"
        echo "  RHEL/CentOS: sudo dnf install -y ${missing[*]}"
        exit 1
    fi

    log_success "All prerequisites met"
}

# ─────────────────────────────────────────────────────────────
# Interactive Configuration
# ─────────────────────────────────────────────────────────────

DEPLOYMENT_MODE=""
STORAGE_BACKEND=""
OPENBAO_PORT=""
CLUSTER_PORT=""
TLS_ENABLED=""
AUTO_UNSEAL=""
BACKUP_ENABLED=""

ask_deployment_mode() {
    print_header "Deployment Mode"

    echo "Select deployment mode:"
    echo ""
    echo "  1) Development (dev)"
    echo "     - In-memory storage, auto-unsealed"
    echo "     - No persistence, restart loses data"
    echo "     - Best for: local testing, CI/CD"
    echo ""
    echo "  2) Standalone (standalone)"
    echo "     - Single server with file/Raft storage"
    echo "     - Requires manual unseal (3 of 5 keys)"
    echo "     - Best for: small deployments, testing"
    echo ""
    echo "  3) High Availability (ha)"
    echo "     - Raft consensus with 3+ nodes"
    echo "     - Automatic failover, horizontal read scaling"
    echo "     - Best for: production"
    echo ""

    while true; do
        read -p "Choose mode [1-3]: " choice
        case $choice in
            1)
                DEPLOYMENT_MODE="dev"
                STORAGE_BACKEND="mem"
                OPENBAO_PORT="8200"
                CLUSTER_PORT="8201"
                TLS_ENABLED="false"
                AUTO_UNSEAL="true"
                BACKUP_ENABLED="false"
                log_info "Development mode selected - no persistence"
                break
                ;;
            2)
                DEPLOYMENT_MODE="standalone"
                OPENBAO_PORT="8200"
                CLUSTER_PORT="8201"
                log_info "Standalone mode selected"
                ask_storage_backend
                break
                ;;
            3)
                DEPLOYMENT_MODE="ha"
                OPENBAO_PORT="8200"
                CLUSTER_PORT="8201"
                log_info "High Availability mode selected"
                ask_ha_configuration
                break
                ;;
            *)
                log_error "Invalid choice"
                ;;
        esac
    done
}

ask_storage_backend() {
    echo ""
    echo "Select storage backend:"
    echo ""
    echo "  1) File (simple, single-node)"
    echo "  2) Raft (built-in, recommended)"
    echo "  3) PostgreSQL (existing cluster)"
    echo "  4) Redis (fast, in-memory)"
    echo ""

    while true; do
        read -p "Choose storage [1-4]: " choice
        case $choice in
            1)
                STORAGE_BACKEND="file"
                break
                ;;
            2)
                STORAGE_BACKEND="raft"
                break
                ;;
            3)
                STORAGE_BACKEND="postgresql"
                ask_postgresql_config
                break
                ;;
            4)
                STORAGE_BACKEND="redis"
                ask_redis_config
                break
                ;;
            *)
                log_error "Invalid choice"
                ;;
        esac
    done
}

ask_ha_configuration() {
    STORAGE_BACKEND="raft"
    echo ""
    log_info "HA mode uses Raft consensus (built-in)"
    echo ""
    read -p "Enter node ID (e.g., openbao-1): " NODE_ID
    NODE_ID="${NODE_ID:-openbao-1}"

    read -p "Enter cluster peers (comma-separated, e.g., openbao-2:8201,openbao-3:8201): " RAFT_PEERS
    RAFT_PEERS="${RAFT_PEERS:-}"

    read -p "Enable TLS for Raft replication? [y/N]: " enable_tls
    if [[ "$enable_tls" =~ ^[Yy]$ ]]; then
        TLS_ENABLED="true"
    else
        TLS_ENABLED="false"
    fi
}

ask_postgresql_config() {
    echo ""
    log_info "PostgreSQL storage configuration"
    echo ""

    read -p "PostgreSQL host [localhost]: " PG_HOST
    PG_HOST="${PG_HOST:-localhost}"

    read -p "PostgreSQL port [5432]: " PG_PORT
    PG_PORT="${PG_PORT:-5432}"

    read -p "PostgreSQL database [openbao]: " PG_DATABASE
    PG_DATABASE="${PG_DATABASE:-openbao}"

    read -p "PostgreSQL user [openbao]: " PG_USER
    PG_USER="${PG_USER:-openbao}"

    read -p "PostgreSQL password: " -s PG_PASSWORD
    echo ""

    read -p "PostgreSQL table name [openbao_store]: " PG_TABLE
    PG_TABLE="${PG_TABLE:-openbao_store}"
}

ask_redis_config() {
    echo ""
    log_info "Redis storage configuration"
    echo ""

    read -p "Redis host [localhost]: " REDIS_HOST
    REDIS_HOST="${REDIS_HOST:-localhost}"

    read -p "Redis port [6379]: " REDIS_PORT
    REDIS_PORT="${REDIS_PORT:-6379}"

    read -p "Redis password (optional): " -s REDIS_PASSWORD
    echo ""

    read -p "Redis database [0]: " REDIS_DB
    REDIS_DB="${REDIS_DB:-0}"
}

ask_tls_configuration() {
    if [[ "$DEPLOYMENT_MODE" == "dev" ]]; then
        TLS_ENABLED="false"
        return
    fi

    echo ""
    read -p "Enable TLS for OpenBao API? [Y/n]: " enable_tls
    if [[ ! "$enable_tls" =~ ^[Nn]$ ]]; then
        TLS_ENABLED="true"
        echo ""
        log_info "TLS configuration"
        echo ""
        read -p "TLS certificate path [/etc/openbao/tls/server.crt]: " TLS_CERT_PATH
        TLS_CERT_PATH="${TLS_CERT_PATH:-/etc/openbao/tls/server.crt}"

        read -p "TLS key path [/etc/openbao/tls/server.key]: " TLS_KEY_PATH
        TLS_KEY_PATH="${TLS_KEY_PATH:-/etc/openbao/tls/server.key}"
    else
        TLS_ENABLED="false"
    fi
}

ask_backup_configuration() {
    if [[ "$DEPLOYMENT_MODE" == "dev" ]]; then
        BACKUP_ENABLED="false"
        return
    fi

    echo ""
    read -p "Enable automatic backups? [Y/n]: " enable_backup
    if [[ ! "$enable_backup" =~ ^[Nn]$ ]]; then
        BACKUP_ENABLED="true"
        echo ""
        log_info "Backup configuration"
        echo ""
        read -p "Backup directory [/var/backups/openbao]: " BACKUP_DIR
        BACKUP_DIR="${BACKUP_DIR:-/var/backups/openbao}"

        read -p "Backup retention (days) [30]: " BACKUP_RETENTION
        BACKUP_RETENTION="${BACKUP_RETENTION:-30}"

        read -p "Backup schedule (cron, e.g., 0 2 * * *) [0 2 * * *]: " BACKUP_CRON
        BACKUP_CRON="${BACKUP_CRON:-0 2 * * *}"
    else
        BACKUP_ENABLED="false"
    fi
}

ask_audit_configuration() {
    echo ""
    read -p "Enable audit logging? [Y/n]: " enable_audit
    if [[ ! "$enable_audit" =~ ^[Nn]$ ]]; then
        AUDIT_ENABLED="true"
        echo ""
        log_info "Audit log configuration"
        echo ""
        echo "Select audit log type:"
        echo "  1) File (local files)"
        echo "  2) Syslog (system logging)"
        echo "  3) Both"
        echo ""

        while true; do
            read -p "Choose audit type [1-3]: " audit_choice
            case $audit_choice in
                1)
                    AUDIT_TYPE="file"
                    break
                    ;;
                2)
                    AUDIT_TYPE="syslog"
                    break
                    ;;
                3)
                    AUDIT_TYPE="both"
                    break
                    ;;
                *)
                    log_error "Invalid choice"
                    ;;
            esac
        done
    else
        AUDIT_ENABLED="false"
        AUDIT_TYPE="file"
    fi
}

# ─────────────────────────────────────────────────────────────
# Installation
# ─────────────────────────────────────────────────────────────

download_openbao() {
    print_step "Downloading OpenBao v${OPENBAO_VERSION}..."

    local temp_dir
    temp_dir=$(mktemp -d)
    cd "$temp_dir"

    curl -L -o openbao.zip "$OPENBAO_DOWNLOAD_URL"
    curl -L -o checksums.txt "$OPENBAO_CHECKSUM_URL"

    # Verify checksum
    if ! grep -q "$(sha256sum openbao.zip | awk '{print $1}')" checksums.txt; then
        log_error "Checksum verification failed"
        exit 1
    fi

    unzip -o openbao.zip
    chmod +x openbao

    mkdir -p "$INSTALL_DIR/bin"
    mv openbao "$INSTALL_DIR/bin/"

    cd - > /dev/null
    rm -rf "$temp_dir"

    log_success "OpenBao installed to $INSTALL_DIR"
}

create_directories() {
    print_step "Creating directories..."

    mkdir -p "$CONFIG_DIR"
    mkdir -p "$CONFIG_DIR/tls"
    mkdir -p "$CONFIG_DIR/policies"
    mkdir -p "$DATA_DIR"
    mkdir -p "$LOG_DIR"
    mkdir -p "$AUDIT_DIR"
    mkdir -p "$ASHCHAN_CONFIG_DIR"
    mkdir -p "$CLIENT_CERT_DIR"

    chown -R openbao:openbao "$DATA_DIR" "$LOG_DIR" "$AUDIT_DIR"
    chmod 700 "$DATA_DIR" "$CONFIG_DIR"

    log_success "Directories created"
}

create_system_user() {
    print_step "Creating system user..."

    if ! id -u openbao &> /dev/null; then
        useradd --system --no-create-home --shell /usr/sbin/nologin openbao
        log_success "System user 'openbao' created"
    else
        log_info "System user 'openbao' already exists"
    fi
}

generate_config() {
    print_step "Generating configuration..."

    cat > "$CONFIG_DIR/openbao.hcl" << EOF
# OpenBao Configuration for Ashchan
# Generated: $(date -Iseconds)
# Deployment Mode: $DEPLOYMENT_MODE

# API listener
listener "tcp" {
  address         = "0.0.0.0:${OPENBAO_PORT}"
  cluster_address = "0.0.0.0:${CLUSTER_PORT}"
  tls_disable     = $([ "$TLS_ENABLED" = "true" ] && echo "false" || echo "true")
$(if [ "$TLS_ENABLED" = "true" ]; then
    echo "  tls_cert_file   = \"$TLS_CERT_PATH\""
    echo "  tls_key_file    = \"$TLS_KEY_PATH\""
fi)
}

# Storage backend
EOF

    case $STORAGE_BACKEND in
        file)
            cat >> "$CONFIG_DIR/openbao.hcl" << EOF
storage "file" {
  path = "$DATA_DIR"
}
EOF
            ;;
        raft)
            cat >> "$CONFIG_DIR/openbao.hcl" << EOF
storage "raft" {
  path = "$DATA_DIR"
  node_id = "${NODE_ID:-openbao-1}"
$(if [ -n "${RAFT_PEERS:-}" ]; then
    echo "  retry_join = [\"${RAFT_PEERS//,/\", \"}\"]"
fi)
}
EOF
            ;;
        postgresql)
            cat >> "$CONFIG_DIR/openbao.hcl" << EOF
storage "postgresql" {
  connection_url = "postgresql://${PG_USER}:${PG_PASSWORD}@${PG_HOST}:${PG_PORT}/${PG_DATABASE}?sslmode=disable"
  table = "${PG_TABLE}"
}
EOF
            ;;
        redis)
            cat >> "$CONFIG_DIR/openbao.hcl" << EOF
storage "redis" {
  address = "${REDIS_HOST}:${REDIS_PORT}"
$(if [ -n "${REDIS_PASSWORD:-}" ]; then
    echo "  password = \"$REDIS_PASSWORD\""
fi)
  db = ${REDIS_DB}
}
EOF
            ;;
    esac

    # Dev mode specific config
    if [[ "$DEPLOYMENT_MODE" == "dev" ]]; then
        cat >> "$CONFIG_DIR/openbao.hcl" << EOF

# Development mode settings
disable_mlock = true
ui = true

# Auto-unseal (dev only)
seal "shamir" {
  type = "auto"
}
EOF
    else
        cat >> "$CONFIG_DIR/openbao.hcl" << EOF

# Production settings
api_addr = "http://localhost:${OPENBAO_PORT}"
cluster_addr = "http://localhost:${CLUSTER_PORT}"

disable_mlock = false
ui = true

# Logging
log_level = "info"
log_file = "$LOG_DIR/openbao.log"
EOF
    fi

    log_success "Configuration generated"
}

generate_tls_certs() {
    if [[ "$TLS_ENABLED" != "true" ]]; then
        return
    fi

    print_step "Generating TLS certificates..."

    mkdir -p "$(dirname "$TLS_CERT_PATH")"

    # Generate CA
    openssl genrsa -out "$CONFIG_DIR/tls/ca.key" 4096
    openssl req -x509 -new -nodes -sha384 -key "$CONFIG_DIR/tls/ca.key" \
        -days 3650 -out "$CONFIG_DIR/tls/ca.crt" \
        -subj "/CN=OpenBao CA/O=Ashchan/C=US"

    # Generate server cert
    openssl genrsa -out "$CONFIG_DIR/tls/server.key" 2048
    openssl req -new -key "$CONFIG_DIR/tls/server.key" \
        -out "$CONFIG_DIR/tls/server.csr" \
        -subj "/CN=localhost/O=Ashchan/C=US"

    # Create SAN config
    cat > /tmp/san.cnf << EOF
authorityKeyIdentifier=keyid,issuer
basicConstraints=CA:FALSE
keyUsage = digitalSignature, nonRepudiation, keyEncipherment, dataEncipherment
subjectAltName = @alt_names

[alt_names]
DNS.1 = localhost
DNS.2 = openbao
IP.1 = 127.0.0.1
EOF

    openssl x509 -req -in "$CONFIG_DIR/tls/server.csr" \
        -CA "$CONFIG_DIR/tls/ca.crt" -CAkey "$CONFIG_DIR/tls/ca.key" \
        -CAcreateserial -out "$TLS_CERT_PATH" -days 365 -sha256 \
        -extfile /tmp/san.cnf

    rm /tmp/san.cnf

    chmod 600 "$CONFIG_DIR/tls/server.key"
    chmod 644 "$CONFIG_DIR/tls/server.crt" "$CONFIG_DIR/tls/ca.crt"

    log_success "TLS certificates generated"
}

create_systemd_service() {
    print_step "Creating systemd service..."

    cat > "$SYSTEMD_SERVICE" << 'EOF'
[Unit]
Description=OpenBao Secrets Management
Documentation=https://openbao.org/docs/
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
User=openbao
Group=openbao
ExecStart=/opt/openbao/bin/openbao server -config=/etc/openbao/openbao.hcl
ExecReload=/bin/kill -SIGHUP $MAINPID
KillMode=process
KillSignal=SIGINT
TimeoutStopSec=30
Restart=on-failure
RestartSec=5
StartLimitBurst=3
StartLimitInterval=30

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
ReadWritePaths=/var/lib/openbao /var/log/openbao

# Environment
EnvironmentFile=-/etc/openbao/openbao.env

# Capabilities
CapabilityBoundingSet=CAP_IPC_LOCK
AmbientCapabilities=CAP_IPC_LOCK

[Install]
WantedBy=multi-user.target
EOF

    log_success "Systemd service created"
}

enable_and_start() {
    print_step "Enabling and starting OpenBao..."

    systemctl daemon-reload
    systemctl enable openbao
    systemctl start openbao

    # Wait for startup
    sleep 3

    if systemctl is-active --quiet openbao; then
        log_success "OpenBao started successfully"
    else
        log_error "OpenBao failed to start"
        systemctl status openbao --no-pager
        exit 1
    fi
}

# ─────────────────────────────────────────────────────────────
# Ashchan Integration
# ─────────────────────────────────────────────────────────────

configure_ashchan_integration() {
    print_header "Configuring Ashchan Integration"

    print_step "Creating Ashchan client certificates..."

    # Generate client cert for Ashchan services
    openssl genrsa -out "$CLIENT_CERT_DIR/client.key" 2048
    openssl req -new -key "$CLIENT_CERT_DIR/client.key" \
        -out "$CLIENT_CERT_DIR/client.csr" \
        -subj "/CN=ashchan-services/O=Ashchan/C=US"

    openssl x509 -req -in "$CLIENT_CERT_DIR/client.csr" \
        -CA "$CONFIG_DIR/tls/ca.crt" -CAkey "$CONFIG_DIR/tls/ca.key" \
        -CAcreateserial -out "$CLIENT_CERT_DIR/client.crt" -days 365 -sha256

    chmod 600 "$CLIENT_CERT_DIR/client.key"
    chmod 644 "$CLIENT_CERT_DIR/client.crt"

    # Copy CA cert for service verification
    cp "$CONFIG_DIR/tls/ca.crt" "$ASHCHAN_CONFIG_DIR/ca.crt"

    log_success "Client certificates created"

    print_step "Creating Ashchan policy..."

    cat > "$CONFIG_DIR/policies/ashchan-services.hcl" << 'EOF'
# Ashchan Services Policy
# Allows services to read their own secrets and use transit encryption

# Read global secrets
path "secret/ashchan/global" {
  capabilities = ["read"]
}

# Read service-specific secrets (path templating)
path "secret/ashchan/services/*" {
  capabilities = ["read"]
}

# Dynamic database credentials
path "database/creds/ashchan" {
  capabilities = ["read"]
}

# Transit encryption/decryption for PII
path "transit/encrypt/ashchan-pii" {
  capabilities = ["update"]
}

path "transit/decrypt/ashchan-pii" {
  capabilities = ["update"]
}

# List available secrets engines
path "sys/mounts" {
  capabilities = ["list"]
}
EOF

    log_success "Ashchan policy created"
}

# ─────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────

main() {
    print_header "OpenBao Installer for Ashchan"

    echo "This installer will configure OpenBao secrets management"
    echo "for your Ashchan imageboard platform."
    echo ""
    echo "OpenBao is the open source (MPL 2.0) alternative to HashiCorp Vault,"
    echo "managed by the Linux Foundation."
    echo ""

    read -p "Continue with installation? [Y/n]: " confirm
    if [[ "$confirm" =~ ^[Nn]$ ]]; then
        log_info "Installation cancelled"
        exit 0
    fi

    check_prerequisites
    ask_deployment_mode
    ask_tls_configuration
    ask_audit_configuration
    ask_backup_configuration

    print_header "Installation Summary"

    echo "Deployment Mode:  $DEPLOYMENT_MODE"
    echo "Storage Backend:  $STORAGE_BACKEND"
    echo "API Port:         $OPENBAO_PORT"
    echo "TLS Enabled:      $TLS_ENABLED"
    echo "Audit Logging:    $AUDIT_ENABLED"
    echo "Auto Backup:      $BACKUP_ENABLED"
    echo ""
    echo "Installation paths:"
    echo "  Binary:   $INSTALL_DIR/bin/openbao"
    echo "  Config:   $CONFIG_DIR/openbao.hcl"
    echo "  Data:     $DATA_DIR"
    echo "  Logs:     $LOG_DIR"
    echo ""

    read -p "Proceed with installation? [Y/n]: " proceed
    if [[ "$proceed" =~ ^[Nn]$ ]]; then
        log_info "Installation cancelled"
        exit 0
    fi

    create_system_user
    download_openbao
    create_directories
    generate_config

    if [[ "$TLS_ENABLED" == "true" ]]; then
        generate_tls_certs
    fi

    create_systemd_service
    enable_and_start
    configure_ashchan_integration

    print_header "Installation Complete!"

    echo "OpenBao v${OPENBAO_VERSION} has been installed and configured."
    echo ""

    if [[ "$DEPLOYMENT_MODE" == "dev" ]]; then
        echo "Development mode is active. OpenBao is auto-unsealed."
        echo ""
        echo "Next steps:"
        echo "  1. Run: make openbao-secrets-init"
        echo "  2. Run: make openbao-migrate-secrets"
    else
        echo "Production mode is active. OpenBao requires unsealing."
        echo ""
        echo "To unseal OpenBao:"
        echo "  1. Run: make openbao-status"
        echo "  2. Note the unseal key shares (5 keys generated)"
        echo "  3. Run: make openbao-unseal and enter 3 of 5 keys"
        echo ""
        echo "IMPORTANT: Store unseal keys securely!"
        echo "  - Each key holder should store their key separately"
        echo "  - Losing 3+ keys means data loss"
    fi

    echo ""
    echo "Documentation: tools/openbao/README.md"
    echo "Management:    make openbao-*"
    echo ""

    log_success "Installation complete!"
}

main "$@"
