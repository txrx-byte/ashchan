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
# Generate all service certificates for Ashchan ServiceMesh
# This script generates certificates for all services at once
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

echo "=== Ashchan ServiceMesh - Generate All Certificates ==="
echo ""

# Check if CA exists
CA_DIR="${PROJECT_ROOT}/certs/ca"
if [[ ! -f "${CA_DIR}/ca.crt" ]] || [[ ! -f "${CA_DIR}/ca.key" ]]; then
    echo "Root CA not found. Generating CA first..."
    echo ""
    "${SCRIPT_DIR}/generate-ca.sh"
    echo ""
fi

# Define all services
declare -A SERVICES=(
    ["gateway"]="gateway.ashchan.local"
    ["auth"]="auth.ashchan.local"
    ["boards"]="boards.ashchan.local"
    ["media"]="media.ashchan.local"
    ["search"]="search.ashchan.local"
    ["moderation"]="moderation.ashchan.local"
)

echo "Generating certificates for ${#SERVICES[@]} services..."
echo ""

for SERVICE_NAME in "${!SERVICES[@]}"; do
    DNS_NAME="${SERVICES[$SERVICE_NAME]}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    "${SCRIPT_DIR}/generate-cert.sh" "${SERVICE_NAME}" "${DNS_NAME}"
    echo ""
done

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "=== All Certificates Generated ==="
echo ""
echo "Certificate bundle location: ${PROJECT_ROOT}/certs/services/"
echo ""
echo "Services:"
for SERVICE_NAME in "${!SERVICES[@]}"; do
    echo "  ✓ ${SERVICE_NAME} (${SERVICES[$SERVICE_NAME]})"
done
echo ""
echo "Next steps:"
echo "  1. Review certificates: ls -la ${PROJECT_ROOT}/certs/services/"
echo "  2. Update podman-compose.yml to mount certificates"
echo "  3. Update service .env files with mTLS URLs"
echo "  4. Start services: podman-compose up -d"
echo ""
echo "To verify a certificate:"
echo "  openssl x509 -in ${PROJECT_ROOT}/certs/services/gateway/gateway.crt -text -noout"
echo ""
