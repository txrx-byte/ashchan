#!/usr/bin/env bash
#
# Rotate OpenBao client certificates for Ashchan
#
# Usage: sudo tools/openbao/rotate-certs.sh
#
# License: Apache 2.0
#

set -euo pipefail

readonly ASHCHAN_OPENBAO_DIR="/etc/ashchan/openbao"
readonly CLIENT_CERT_DIR="$ASHCHAN_OPENBAO_DIR/client"
readonly CONFIG_DIR="/etc/openbao"
readonly CA_CERT="$ASHCHAN_OPENBAO_DIR/ca.crt"
readonly CA_KEY="$CONFIG_DIR/tls/ca.key"

readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[✓]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

print_header() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

print_header "Rotating OpenBao Client Certificates"

log_warn "This will regenerate all client certificates"
log_warn "Services may need to be restarted to pick up new certs"
echo ""

read -p "Continue? [Y/n]: " confirm
if [[ "$confirm" =~ ^[Nn]$ ]]; then
    log_info "Rotation cancelled"
    exit 0
fi

# Backup existing certs
if [[ -d "$CLIENT_CERT_DIR" ]] && [[ -n "$(ls -A "$CLIENT_CERT_DIR" 2>/dev/null)" ]]; then
    BACKUP_DIR="$ASHCHAN_OPENBAO_DIR/client-backup-$(date +%Y%m%d-%H%M%S)"
    log_info "Backing up existing certs to: $BACKUP_DIR"
    mkdir -p "$BACKUP_DIR"
    cp -r "$CLIENT_CERT_DIR"/* "$BACKUP_DIR/" 2>/dev/null || true
fi

# Generate new client certificate
log_info "Generating new client certificate..."

mkdir -p "$CLIENT_CERT_DIR"

# Generate key
openssl genrsa -out "$CLIENT_CERT_DIR/client.key" 2048

# Generate CSR
openssl req -new -key "$CLIENT_CERT_DIR/client.key" \
    -out "$CLIENT_CERT_DIR/client.csr" \
    -subj "/CN=ashchan-services/O=Ashchan/C=US"

# Sign with CA
openssl x509 -req -in "$CLIENT_CERT_DIR/client.csr" \
    -CA "$CA_CERT" -CAkey "$CA_KEY" \
    -CAcreateserial -out "$CLIENT_CERT_DIR/client.crt" \
    -days 365 -sha256

# Set permissions
chmod 600 "$CLIENT_CERT_DIR/client.key"
chmod 644 "$CLIENT_CERT_DIR/client.crt" "$CLIENT_CERT_DIR/client.csr"

log_success "New client certificate generated"

# Verify certificate
log_info "Verifying certificate..."
if openssl verify -CAfile "$CA_CERT" "$CLIENT_CERT_DIR/client.crt" > /dev/null 2>&1; then
    log_success "Certificate verification passed"
else
    log_error "Certificate verification failed"
    exit 1
fi

# Show certificate info
echo ""
log_info "New certificate details:"
openssl x509 -in "$CLIENT_CERT_DIR/client.crt" -text -noout | grep -E "Subject:|Issuer:|Not Before:|Not After :" | sed 's/^/  /'

print_header "Certificate Rotation Complete"

echo "New client certificate: $CLIENT_CERT_DIR/client.crt"
echo "New client key:         $CLIENT_CERT_DIR/client.key"
echo "CA certificate:         $CA_CERT"
echo ""
echo "Certificate validity: 365 days"
echo ""
echo "Next steps:"
echo ""
echo "  1. Restart all Ashchan services to pick up new certs"
echo "     make restart"
echo ""
echo "  2. Verify services can connect"
echo "     make openbao-audit"
echo ""
echo "  3. (Optional) Remove backup after verification"
echo "     sudo rm -rf $BACKUP_DIR"
echo ""

log_success "Certificate rotation complete!"
