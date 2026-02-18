#!/bin/bash
#
# Rotate certificates for Ashchan ServiceMesh
# This script regenerates all service certificates and triggers a rolling restart
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
CERTS_DIR="${PROJECT_ROOT}/certs"
CA_DIR="${CERTS_DIR}/ca"
SERVICES_DIR="${CERTS_DIR}/services"

echo "=== Ashchan ServiceMesh - Certificate Rotation ==="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Check if CA exists
if [[ ! -f "${CA_DIR}/ca.crt" ]] || [[ ! -f "${CA_DIR}/ca.key" ]]; then
    echo -e "${RED}Error: Root CA not found${NC}"
    echo "Run ./scripts/mtls/generate-ca.sh first"
    exit 1
fi

# Define services
SERVICES=("gateway" "auth" "boards" "media" "search" "moderation")

echo "This script will:"
echo "  1. Backup existing certificates"
echo "  2. Generate new certificates for all services"
echo "  3. Trigger a rolling restart of services"
echo ""
read -p "Continue? (y/N) " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted"
    exit 0
fi

# Backup existing certificates
BACKUP_DIR="${CERTS_DIR}/backup/$(date +%Y%m%d_%H%M%S)"
echo ""
echo "Creating backup at ${BACKUP_DIR}..."
mkdir -p "${BACKUP_DIR}"

for SERVICE in "${SERVICES[@]}"; do
    if [[ -d "${SERVICES_DIR}/${SERVICE}" ]]; then
        cp -r "${SERVICES_DIR}/${SERVICE}" "${BACKUP_DIR}/"
        echo -e "${GREEN}✓ Backed up ${SERVICE}${NC}"
    fi
done

echo ""
echo "Backup complete: ${BACKUP_DIR}"
echo ""

# Regenerate all certificates
echo "Regenerating certificates..."
echo ""

for SERVICE in "${SERVICES[@]}"; do
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    
    # Remove old certificate
    rm -f "${SERVICES_DIR}/${SERVICE}/${SERVICE}.crt"
    rm -f "${SERVICES_DIR}/${SERVICE}/${SERVICE}.key"
    rm -f "${SERVICES_DIR}/${SERVICE}.crt"
    rm -f "${SERVICES_DIR}/${SERVICE}.key"
    
    # Generate new certificate
    DNS_NAME="${SERVICE}.ashchan.local"
    "${SCRIPT_DIR}/generate-cert.sh" "${SERVICE}" "${DNS_NAME}"
    echo ""
done

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo -e "${GREEN}✓ All certificates regenerated${NC}"
echo ""

# Check if services are running and trigger restart
echo "Checking for running services..."
if podman ps --format "{{.Names}}" | grep -q "ashchan-"; then
    echo ""
    echo -e "${YELLOW}Services are running. Rolling restart recommended.${NC}"
    echo ""
    echo "To restart services one by one (zero-downtime):"
    echo "  for svc in gateway auth boards media search moderation; do"
    echo "    podman restart ashchan-\$svc-1"
    echo "    sleep 5"
    echo "  done"
    echo ""
    read -p "Perform rolling restart now? (y/N) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        for SERVICE in "${SERVICES[@]}"; do
            CONTAINER_NAME="ashchan-${SERVICE}-1"
            if podman ps --format "{{.Names}}" | grep -q "^${CONTAINER_NAME}$"; then
                echo "Restarting ${CONTAINER_NAME}..."
                podman restart "${CONTAINER_NAME}"
                sleep 5
                echo -e "${GREEN}✓ ${CONTAINER_NAME} restarted${NC}"
            fi
        done
        echo ""
        echo -e "${GREEN}✓ Rolling restart complete${NC}"
    fi
else
    echo "No services running. Start with: podman-compose up -d"
fi

echo ""
echo "=== Certificate Rotation Complete ==="
echo ""
echo "New certificates valid for 365 days"
echo "Backup stored at: ${BACKUP_DIR}"
echo ""
echo "To restore backup if needed:"
echo "  cp -r ${BACKUP_DIR}/* ${SERVICES_DIR}/"
echo ""
