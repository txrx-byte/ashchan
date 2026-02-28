#!/usr/bin/env bash
#
# Restore OpenBao from backup
#
# Usage: sudo tools/openbao/restore-backup.sh <backup.json|backup-dir>
#
# License: Apache 2.0
#

set -euo pipefail

readonly CONFIG_DIR="/etc/openbao"
readonly DATA_DIR="/var/lib/openbao"
readonly ASHCHAN_CONFIG_DIR="/etc/ashchan/openbao"

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

if [[ $# -lt 1 ]]; then
    log_error "Usage: $0 <backup.json|backup-dir>"
    exit 1
fi

BACKUP_INPUT="$1"

# Determine backup directory
if [[ -f "$BACKUP_INPUT" ]]; then
    # JSON metadata file provided
    BACKUP_DIR=$(dirname "$BACKUP_INPUT")
elif [[ -d "$BACKUP_INPUT" ]]; then
    BACKUP_DIR="$BACKUP_INPUT"
else
    log_error "Invalid backup path: $BACKUP_INPUT"
    exit 1
fi

print_header "OpenBao Restore"

log_info "Backup directory: $BACKUP_DIR"
echo ""

# List available backups
log_info "Available backups in $BACKUP_DIR:"
ls -lh "$BACKUP_DIR"/*.tar.gz 2>/dev/null | head -10 || echo "  No backups found"
echo ""

# Find most recent backup if not specified
if [[ -f "$BACKUP_INPUT" ]]; then
    BACKUP_DATE=$(jq -r '.timestamp' "$BACKUP_INPUT" 2>/dev/null | cut -d'T' -f1)
    log_info "Restoring from backup: $BACKUP_DATE"
else
    # Find most recent
    LATEST=$(ls -t "$BACKUP_DIR"/config-*.tar.gz 2>/dev/null | head -1)
    if [[ -z "$LATEST" ]]; then
        log_error "No backup found in $BACKUP_DIR"
        exit 1
    fi
    BACKUP_DATE=$(basename "$LATEST" | sed 's/config-\(.*\)\.tar\.gz/\1/')
    log_info "Restoring from most recent backup: $BACKUP_DATE"
fi

echo ""
log_warn "This will STOP OpenBao and OVERWRITE current data"
read -p "Continue? [y/N]: " confirm
if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    log_info "Restore cancelled"
    exit 0
fi

# Stop OpenBao
print_step "Stopping OpenBao..."
systemctl stop openbao 2>/dev/null || true
sleep 2

# Backup current state (just in case)
CURRENT_BACKUP="/var/backups/openbao/pre-restore-$(date +%Y%m%d-%H%M%S)"
log_info "Creating backup of current state: $CURRENT_BACKUP"
mkdir -p "$CURRENT_BACKUP"
cp -r "$CONFIG_DIR" "$CURRENT_BACKUP/" 2>/dev/null || true
cp -r "$DATA_DIR" "$CURRENT_BACKUP/" 2>/dev/null || true
cp -r "$ASHCHAN_CONFIG_DIR" "$CURRENT_BACKUP/" 2>/dev/null || true

# Restore configuration
if [[ -f "$BACKUP_DIR/config-${BACKUP_DATE}.tar.gz" ]]; then
    print_step "Restoring configuration..."
    rm -rf "$CONFIG_DIR"/*
    tar -xzf "$BACKUP_DIR/config-${BACKUP_DATE}.tar.gz" -C "$CONFIG_DIR"
    log_success "Configuration restored"
fi

# Restore data (Raft)
if [[ -f "$BACKUP_DIR/raft-${BACKUP_DATE}.tar.gz" ]]; then
    print_step "Restoring Raft data..."
    rm -rf "$DATA_DIR/raft"
    tar -xzf "$BACKUP_DIR/raft-${BACKUP_DATE}.tar.gz" -C "$DATA_DIR"
    log_success "Raft data restored"
fi

# Restore data (file backend)
if [[ -f "$BACKUP_DIR/data-${BACKUP_DATE}.tar.gz" ]]; then
    print_step "Restoring data..."
    rm -rf "$DATA_DIR/data"
    tar -xzf "$BACKUP_DIR/data-${BACKUP_DATE}.tar.gz" -C "$DATA_DIR"
    log_success "Data restored"
fi

# Restore certificates
if [[ -f "$BACKUP_DIR/certs-${BACKUP_DATE}.tar.gz" ]]; then
    print_step "Restoring certificates..."
    rm -rf "$ASHCHAN_CONFIG_DIR"/*
    tar -xzf "$BACKUP_DIR/certs-${BACKUP_DATE}.tar.gz" -C "$ASHCHAN_CONFIG_DIR"
    log_success "Certificates restored"
fi

# Fix permissions
log_info "Fixing permissions..."
chown -R openbao:openbao "$DATA_DIR" 2>/dev/null || true
chmod 700 "$DATA_DIR" 2>/dev/null || true

# Start OpenBao
print_step "Starting OpenBao..."
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

# Check seal status
print_step "Checking seal status..."
SEALED=$(curl -sf http://localhost:8200/v1/sys/seal-status 2>/dev/null | jq -r '.sealed' || echo "unknown")

if [[ "$SEALED" == "true" ]]; then
    log_warn "OpenBao is SEALED"
    echo ""
    echo "Run unseal procedure:"
    echo "  make openbao-unseal"
    echo ""
    echo "You will need 3 of 5 unseal keys from the original initialization."
else
    log_success "OpenBao is unsealed and ready"
fi

print_header "Restore Complete"

echo "Restore completed from: $BACKUP_DIR"
echo "Backup of pre-restore state: $CURRENT_BACKUP"
echo ""

if [[ "$SEALED" == "true" ]]; then
    log_warn "Remember to unseal OpenBao before using"
fi

log_success "Restore complete!"
