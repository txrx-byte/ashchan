#!/bin/bash

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
# Rotate certificates for Ashchan mTLS
# This script regenerates all service certificates and triggers a rolling restart
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
CERTS_DIR="${PROJECT_ROOT}/certs"
CA_DIR="${CERTS_DIR}/ca"
SERVICES_DIR="${CERTS_DIR}/services"
PID_DIR="/tmp/ashchan-pids"

echo "=== Ashchan mTLS - Certificate Rotation ==="
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
echo "  3. Trigger a rolling restart of services (if running)"
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
    DNS_NAME="localhost"
    "${SCRIPT_DIR}/generate-cert.sh" "${SERVICE}" "${DNS_NAME}"
    echo ""
done

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo -e "${GREEN}✓ All certificates regenerated${NC}"
echo ""

# Check if services are running and trigger restart
echo "Checking for running services..."
RUNNING_SERVICES=0

for SERVICE in "${SERVICES[@]}"; do
    PID_FILE="${PID_DIR}/${SERVICE}.pid"
    if [[ -f "${PID_FILE}" ]] && kill -0 "$(cat "${PID_FILE}")" 2>/dev/null; then
        ((RUNNING_SERVICES++))
    fi
done

if [[ ${RUNNING_SERVICES} -gt 0 ]]; then
    echo ""
    echo -e "${YELLOW}${RUNNING_SERVICES} service(s) running. Rolling restart recommended.${NC}"
    echo ""
    read -p "Perform rolling restart now? (y/N) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        cd "${PROJECT_ROOT}"
        for SERVICE in "${SERVICES[@]}"; do
            PID_FILE="${PID_DIR}/${SERVICE}.pid"
            if [[ -f "${PID_FILE}" ]] && kill -0 "$(cat "${PID_FILE}")" 2>/dev/null; then
                echo "Restarting ${SERVICE}..."
                make stop-${SERVICE} 2>/dev/null || true
                sleep 1
                make start-${SERVICE} 2>/dev/null || true
                sleep 3
                echo -e "${GREEN}✓ ${SERVICE} restarted${NC}"
            fi
        done
        echo ""
        echo -e "${GREEN}✓ Rolling restart complete${NC}"
    fi
else
    echo "No services running. Start with: make up"
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
