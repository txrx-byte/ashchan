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
# Generate a service certificate for Ashchan ServiceMesh
# Usage: ./generate-cert.sh <service-name> [dns-name]
#
# Examples:
#   ./generate-cert.sh gateway gateway.ashchan.local
#   ./generate-cert.sh auth auth.ashchan.local
#   ./generate-cert.sh boards boards.ashchan.local
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
CERTS_DIR="${PROJECT_ROOT}/certs"
CA_DIR="${CERTS_DIR}/ca"
SERVICES_DIR="${CERTS_DIR}/services"

# Service name (required)
SERVICE_NAME="${1:-}"
if [[ -z "${SERVICE_NAME}" ]]; then
    echo "Usage: $0 <service-name> [dns-name]"
    echo ""
    echo "Examples:"
    echo "  $0 gateway gateway.ashchan.local"
    echo "  $0 auth auth.ashchan.local"
    echo "  $0 boards boards.ashchan.local"
    echo ""
    echo "Available services: gateway, auth, boards, media, search, moderation"
    exit 1
fi

# DNS name (default to service-name.ashchan.local)
DNS_NAME="${2:-${SERVICE_NAME}.ashchan.local}"

# Create services directory
mkdir -p "${SERVICES_DIR}"

# Check if CA exists
if [[ ! -f "${CA_DIR}/ca.crt" ]] || [[ ! -f "${CA_DIR}/ca.key" ]]; then
    echo "Error: Root CA not found at ${CA_DIR}"
    echo "Run ./scripts/mtls/generate-ca.sh first"
    exit 1
fi

# Check if certificate already exists
if [[ -f "${SERVICES_DIR}/${SERVICE_NAME}.crt" ]] && [[ -f "${SERVICES_DIR}/${SERVICE_NAME}.key" ]]; then
    echo "Certificate already exists for ${SERVICE_NAME}"
    echo "Remove it first if you want to regenerate:"
    echo "  rm ${SERVICES_DIR}/${SERVICE_NAME}.*"
    exit 1
fi

echo "=== Generating Certificate for ${SERVICE_NAME} ==="
echo "DNS Name: ${DNS_NAME}"
echo ""

# Create service directory
SERVICE_DIR="${SERVICES_DIR}/${SERVICE_NAME}"
mkdir -p "${SERVICE_DIR}"

# Generate private key
# NOTE: We use mode 644 (not 600) because containers mount these volumes
# as root but the application runs as appuser (UID 1000). The appuser
# must be able to read the key files for Swoole SSL to work.
# In production, use proper UID mapping or secrets management instead.
echo "1. Generating private key..."
openssl ecparam -genkey -name prime256v1 -noout -out "${SERVICE_DIR}/${SERVICE_NAME}.key"
chmod 644 "${SERVICE_DIR}/${SERVICE_NAME}.key"
echo "   Created: ${SERVICE_DIR}/${SERVICE_NAME}.key (mode 644 for container access)"

# Generate CSR
echo "2. Generating Certificate Signing Request (CSR)..."
openssl req -new \
    -key "${SERVICE_DIR}/${SERVICE_NAME}.key" \
    -out "${SERVICE_DIR}/${SERVICE_NAME}.csr" \
    -subj "/C=US/ST=California/L=San Francisco/O=Ashchan/OU=ServiceMesh/CN=${DNS_NAME}"

echo "   Created: ${SERVICE_DIR}/${SERVICE_NAME}.csr"

# Create certificate extensions config
cat > "${SERVICE_DIR}/${SERVICE_NAME}.ext" << EOF
basicConstraints = CA:FALSE
nsCertType = server, client
nsComment = "OpenSSL Generated Service Certificate for ${DNS_NAME}"
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer:always
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth, clientAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = ${DNS_NAME}
DNS.2 = ${SERVICE_NAME}.ashchan.local
DNS.3 = ${SERVICE_NAME}
DNS.4 = ashchan-${SERVICE_NAME}-1
EOF

# Sign certificate with CA
echo "3. Signing certificate with Root CA (valid for 365 days)..."
openssl x509 -req \
    -in "${SERVICE_DIR}/${SERVICE_NAME}.csr" \
    -CA "${CA_DIR}/ca.crt" \
    -CAkey "${CA_DIR}/ca.key" \
    -CAcreateserial \
    -out "${SERVICE_DIR}/${SERVICE_NAME}.crt" \
    -days 365 \
    -sha256 \
    -extfile "${SERVICE_DIR}/${SERVICE_NAME}.ext"

echo "   Created: ${SERVICE_DIR}/${SERVICE_NAME}.crt"

# Copy to services directory
cp "${SERVICE_DIR}/${SERVICE_NAME}.crt" "${SERVICES_DIR}/${SERVICE_NAME}.crt"
cp "${SERVICE_DIR}/${SERVICE_NAME}.key" "${SERVICES_DIR}/${SERVICE_NAME}.key"

# Verify certificate
echo "4. Verifying certificate..."
if openssl verify -CAfile "${CA_DIR}/ca.crt" "${SERVICE_DIR}/${SERVICE_NAME}.crt" > /dev/null 2>&1; then
    echo "   ✓ Certificate verified successfully"
else
    echo "   ✗ Certificate verification failed"
    exit 1
fi

# Clean up CSR and ext file
rm -f "${SERVICE_DIR}/${SERVICE_NAME}.csr"
rm -f "${SERVICE_DIR}/${SERVICE_NAME}.ext"

echo ""
echo "=== Certificate Generation Complete ==="
echo ""
echo "Certificate: ${SERVICE_DIR}/${SERVICE_NAME}.crt"
echo "Private Key: ${SERVICE_DIR}/${SERVICE_NAME}.key (mode 644 for container access)"
echo "CA Bundle:   ${CA_DIR}/ca.crt"
echo ""
echo "Certificate details:"
openssl x509 -in "${SERVICE_DIR}/${SERVICE_NAME}.crt" -text -noout | grep -A2 "Subject:" | head -3
echo ""
echo "To use this certificate in Podman Compose, mount:"
echo "  - ${SERVICE_DIR}/${SERVICE_NAME}.crt:/etc/mtls/server.crt:ro"
echo "  - ${SERVICE_DIR}/${SERVICE_NAME}.key:/etc/mtls/server.key:ro"
echo "  - ${CA_DIR}/ca.crt:/etc/mtls/ca.crt:ro"
echo ""
