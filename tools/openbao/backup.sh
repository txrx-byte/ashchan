#!/usr/bin/env bash
#
# OpenBao Backup Script for Ashchan
#
# Usage: sudo tools/openbao/backup.sh
#
# License: Apache 2.0
#

set -euo pipefail

readonly BACKUP_DIR="${BACKUP_DIR:-/var/backups/openbao}"
readonly DATE=$(date +%Y%m%d-%H%M%S)
readonly CONFIG_DIR="/etc/openbao"
readonly ASHCHAN_CONFIG_DIR="/etc/ashchan/openbao"
readonly DATA_DIR="/var/lib/openbao"

readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[✓]${NC} $1"; }

print_header() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

print_header "OpenBao Backup"

# Create backup directory
mkdir -p "$BACKUP_DIR"
log_info "Backup directory: $BACKUP_DIR"

# Backup Raft storage (if applicable)
if [[ -d "$DATA_DIR/raft" ]]; then
    log_info "Backing up Raft storage..."
    tar -czf "$BACKUP_DIR/raft-$DATE.tar.gz" -C "$DATA_DIR" raft/
    log_success "Raft storage backed up: $BACKUP_DIR/raft-$DATE.tar.gz"
fi

# Backup data directory (file backend)
if [[ -d "$DATA_DIR/data" ]]; then
    log_info "Backing up data directory..."
    tar -czf "$BACKUP_DIR/data-$DATE.tar.gz" -C "$DATA_DIR" data/
    log_success "Data backed up: $BACKUP_DIR/data-$DATE.tar.gz"
fi

# Backup configuration
log_info "Backing up configuration..."
tar -czf "$BACKUP_DIR/config-$DATE.tar.gz" -C "$CONFIG_DIR" .
log_success "Configuration backed up: $BACKUP_DIR/config-$DATE.tar.gz"

# Backup client certificates
log_info "Backing up client certificates..."
tar -czf "$BACKUP_DIR/certs-$DATE.tar.gz" -C "$ASHCHAN_CONFIG_DIR" .
log_success "Certificates backed up: $BACKUP_DIR/certs-$DATE.tar.gz"

# Create metadata file
cat > "$BACKUP_DIR/backup-$DATE.json" << EOF
{
    "timestamp": "$(date -Iseconds)",
    "backup_dir": "$BACKUP_DIR",
    "files": [
        "raft-$DATE.tar.gz",
        "data-$DATE.tar.gz",
        "config-$DATE.tar.gz",
        "certs-$DATE.tar.gz"
    ],
    "openbao_version": "$(openbao --version 2>&1 | head -1)",
    "hostname": "$(hostname)"
}
EOF

log_success "Metadata saved: $BACKUP_DIR/backup-$DATE.json"

# Cleanup old backups (keep last 30 days)
log_info "Cleaning up backups older than 30 days..."
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +30 -delete 2>/dev/null || true
find "$BACKUP_DIR" -name "*.json" -mtime +30 -delete 2>/dev/null || true

print_header "Backup Complete"

echo "Backup location: $BACKUP_DIR"
echo ""
echo "Files created:"
ls -lh "$BACKUP_DIR"/*"$DATE"* 2>/dev/null || true
echo ""
echo "To restore:"
echo "  sudo tools/openbao/restore-backup.sh $BACKUP_DIR/backup-$DATE.json"
echo ""

log_success "Backup complete!"
