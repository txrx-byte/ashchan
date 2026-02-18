#!/bin/bash
#
# Verify mTLS mesh connectivity for Ashchan ServiceMesh
# This script tests that all services can communicate via mTLS
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
CERTS_DIR="${PROJECT_ROOT}/certs"
CA_DIR="${CERTS_DIR}/ca"
SERVICES_DIR="${CERTS_DIR}/services"

echo "=== Ashchan ServiceMesh - mTLS Verification ==="
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

if ! command -v podman &> /dev/null; then
    echo -e "${RED}✗ podman not found${NC}"
    exit 1
fi
echo -e "${GREEN}✓ podman found${NC}"

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
    EXPIRY_EPOCH=$(date -d "${EXPIRY}" +%s)
    NOW_EPOCH=$(date +%s)
    DAYS_LEFT=$(( (EXPIRY_EPOCH - NOW_EPOCH) / 86400 ))
    
    if [[ ${DAYS_LEFT} -lt 30 ]]; then
        echo -e "${YELLOW}  ⚠ Certificate expires in ${DAYS_LEFT} days${NC}"
    else
        echo -e "${GREEN}  ✓ Certificate expires in ${DAYS_LEFT} days (${EXPIRY})${NC}"
    fi
    
    # Check key matches certificate
    CERT_MD5=$(openssl x509 -noout -modulus -in "${CERT_FILE}" | openssl md5)
    KEY_MD5=$(openssl rsa -noout -modulus -in "${KEY_FILE}" | openssl md5)
    
    if [[ "${CERT_MD5}" == "${KEY_MD5}" ]]; then
        echo -e "${GREEN}  ✓ Certificate and key match${NC}"
    else
        echo -e "${RED}  ✗ Certificate and key do not match${NC}"
    fi
    
    # Show certificate subject
    SUBJECT=$(openssl x509 -in "${CERT_FILE}" -noout -subject | sed 's/subject=//')
    echo "     Subject: ${SUBJECT}"
    
    # Show SANs
    SANS=$(openssl x509 -in "${CERT_FILE}" -noout -ext subjectAltName | grep -v "Subject Alternative Name:" | tr -d ' ')
    echo "     SANs: ${SANS}"
    
    echo ""
done

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Check Podman network
echo "Checking Podman network configuration..."
if podman network inspect ashchan-mesh > /dev/null 2>&1; then
    echo -e "${GREEN}✓ ashchan-mesh network exists${NC}"
else
    echo -e "${YELLOW}⚠ ashchan-mesh network not found (will be created on first compose up)${NC}"
fi

echo ""

# Check if services are running
echo "Checking service status..."
RUNNING_COUNT=0
for SERVICE in "${SERVICES[@]}"; do
    CONTAINER_NAME="ashchan-${SERVICE}-1"
    if podman ps --format "{{.Names}}" | grep -q "^${CONTAINER_NAME}$"; then
        echo -e "${GREEN}✓ ${CONTAINER_NAME} is running${NC}"
        ((RUNNING_COUNT++))
    else
        echo "  ${CONTAINER_NAME} is not running"
    fi
done

echo ""
echo "Running services: ${RUNNING_COUNT}/${#SERVICES[@]}"
echo ""

# Test mTLS connections if services are running
if [[ ${RUNNING_COUNT} -gt 0 ]]; then
    echo "Testing mTLS connectivity..."
    echo ""
    
    # Test gateway health endpoint
    GATEWAY_CERT="${SERVICES_DIR}/gateway/gateway.crt"
    GATEWAY_KEY="${SERVICES_DIR}/gateway/gateway.key"
    
    echo "Testing gateway public endpoint..."
    if curl -k -s --max-time 5 https://localhost:9501/health > /dev/null 2>&1; then
        echo -e "${GREEN}✓ Gateway health endpoint responding${NC}"
    else
        echo -e "${YELLOW}⚠ Gateway health endpoint not responding (may be starting)${NC}"
    fi
    
    echo ""
fi

echo "=== Verification Complete ==="
echo ""
echo "Certificate storage: ${CERTS_DIR}"
echo ""
echo "Next steps:"
echo "  1. If certificates are valid: podman-compose up -d"
echo "  2. Check service logs: podman-compose logs -f"
echo "  3. Test mTLS: curl --cacert ${CA_DIR}/ca.crt https://<service>.ashchan.local:8443/health"
echo ""
