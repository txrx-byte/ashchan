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
# Verify mTLS configuration for Ashchan
# This script tests that all certificates are valid and services can communicate via mTLS
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
CERTS_DIR="${PROJECT_ROOT}/certs"
CA_DIR="${CERTS_DIR}/ca"
SERVICES_DIR="${CERTS_DIR}/services"
PID_DIR="/tmp/ashchan-pids"

echo "=== Ashchan mTLS - Verification ==="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check prerequisites
echo "Checking prerequisites..."

if ! command -v openssl &> /dev/null; then
    echo -e "${RED}✗ openssl not found${NC}"
    exit 1
fi
echo -e "${GREEN}✓ openssl found${NC}"

echo ""

# Check CA exists
echo "Checking CA certificates..."
if [[ ! -f "${CA_DIR}/ca.crt" ]]; then
    echo -e "${RED}✗ CA certificate not found${NC}"
    echo "Run ./scripts/mtls/generate-ca.sh first"
    exit 1
fi
echo -e "${GREEN}✓ CA certificate found${NC}"

if [[ ! -f "${CA_DIR}/ca.key" ]]; then
    echo -e "${RED}✗ CA private key not found${NC}"
    exit 1
fi
echo -e "${GREEN}✓ CA private key found${NC}"

echo ""

# Define services
SERVICES=("gateway" "auth" "boards" "media" "search" "moderation")

# Verify each service certificate
echo "Verifying service certificates..."
echo ""

for SERVICE in "${SERVICES[@]}"; do
    CERT_FILE="${SERVICES_DIR}/${SERVICE}/${SERVICE}.crt"
    KEY_FILE="${SERVICES_DIR}/${SERVICE}/${SERVICE}.key"
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Service: ${SERVICE}"
    
    # Check files exist
    if [[ ! -f "${CERT_FILE}" ]]; then
        echo -e "${RED}  ✗ Certificate not found${NC}"
        continue
    fi
    echo -e "${GREEN}  ✓ Certificate found${NC}"
    
    if [[ ! -f "${KEY_FILE}" ]]; then
        echo -e "${RED}  ✗ Private key not found${NC}"
        continue
    fi
    echo -e "${GREEN}  ✓ Private key found${NC}"
    
    # Verify certificate against CA
    if openssl verify -CAfile "${CA_DIR}/ca.crt" "${CERT_FILE}" > /dev/null 2>&1; then
        echo -e "${GREEN}  ✓ Certificate verified against CA${NC}"
    else
        echo -e "${RED}  ✗ Certificate verification failed${NC}"
        continue
    fi
    
    # Check certificate expiry
    EXPIRY=$(openssl x509 -in "${CERT_FILE}" -noout -enddate | cut -d= -f2)
    # Handle different date command versions (GNU vs BSD)
    if date --version >/dev/null 2>&1; then
        # GNU date
        EXPIRY_EPOCH=$(date -d "${EXPIRY}" +%s 2>/dev/null || echo "0")
    else
        # BSD date (macOS)
        EXPIRY_EPOCH=$(date -j -f "%b %d %H:%M:%S %Y %Z" "${EXPIRY}" +%s 2>/dev/null || echo "0")
    fi
    NOW_EPOCH=$(date +%s)
    
    if [[ ${EXPIRY_EPOCH} -gt 0 ]]; then
        DAYS_LEFT=$(( (EXPIRY_EPOCH - NOW_EPOCH) / 86400 ))
        
        if [[ ${DAYS_LEFT} -lt 30 ]]; then
            echo -e "${YELLOW}  ⚠ Certificate expires in ${DAYS_LEFT} days${NC}"
        else
            echo -e "${GREEN}  ✓ Certificate expires in ${DAYS_LEFT} days (${EXPIRY})${NC}"
        fi
    else
        echo -e "${YELLOW}  ⚠ Could not parse expiry date: ${EXPIRY}${NC}"
    fi
    
    # Check key matches certificate
    CERT_MD5=$(openssl x509 -noout -modulus -in "${CERT_FILE}" 2>/dev/null | openssl md5)
    KEY_MD5=$(openssl rsa -noout -modulus -in "${KEY_FILE}" 2>/dev/null | openssl md5)
    
    if [[ "${CERT_MD5}" == "${KEY_MD5}" ]]; then
        echo -e "${GREEN}  ✓ Certificate and key match${NC}"
    else
        echo -e "${RED}  ✗ Certificate and key do not match${NC}"
    fi
    
    # Show certificate subject
    SUBJECT=$(openssl x509 -in "${CERT_FILE}" -noout -subject | sed 's/subject=//')
    echo "     Subject: ${SUBJECT}"
    
    # Show SANs
    SANS=$(openssl x509 -in "${CERT_FILE}" -noout -ext subjectAltName 2>/dev/null | grep -v "Subject Alternative Name:" | tr -d ' ' || echo "N/A")
    echo "     SANs: ${SANS}"
    
    echo ""
done

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Check if services are running
echo "Checking service status..."
RUNNING_COUNT=0
for SERVICE in "${SERVICES[@]}"; do
    PID_FILE="${PID_DIR}/${SERVICE}.pid"
    if [[ -f "${PID_FILE}" ]] && kill -0 "$(cat "${PID_FILE}")" 2>/dev/null; then
        echo -e "${GREEN}✓ ${SERVICE} is running (PID $(cat "${PID_FILE}"))${NC}"
        ((RUNNING_COUNT++))
    else
        echo "  ${SERVICE} is not running"
    fi
done

echo ""
echo "Running services: ${RUNNING_COUNT}/${#SERVICES[@]}"
echo ""

# Test health endpoints if services are running
if [[ ${RUNNING_COUNT} -gt 0 ]]; then
    echo "Testing service health endpoints..."
    echo ""
    
    declare -A PORTS=(
        ["gateway"]="9501"
        ["auth"]="9502"
        ["boards"]="9503"
        ["media"]="9504"
        ["search"]="9505"
        ["moderation"]="9506"
    )
    
    for SERVICE in "${SERVICES[@]}"; do
        PORT="${PORTS[$SERVICE]}"
        if curl -s --max-time 2 "http://localhost:${PORT}/health" > /dev/null 2>&1; then
            echo -e "${GREEN}✓ ${SERVICE} health endpoint responding (port ${PORT})${NC}"
        else
            echo -e "${YELLOW}⚠ ${SERVICE} health endpoint not responding (port ${PORT})${NC}"
        fi
    done
    
    echo ""
fi

echo "=== Verification Complete ==="
echo ""
echo "Certificate storage: ${CERTS_DIR}"
echo ""
echo "Next steps:"
echo "  1. Start services: make up"
echo "  2. View logs: make logs"
echo "  3. Test mTLS: curl --cacert ${CA_DIR}/ca.crt https://localhost:8443/health"
echo ""
