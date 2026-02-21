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
# Generate Root CA for Ashchan ServiceMesh
# This script creates the Certificate Authority that signs all service certificates
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
CERTS_DIR="${PROJECT_ROOT}/certs"
CA_DIR="${CERTS_DIR}/ca"

echo "=== Ashchan Root CA Generator ==="
echo ""

# Create directories
mkdir -p "${CA_DIR}"
mkdir -p "${CERTS_DIR}/services"

# Check if CA already exists
if [[ -f "${CA_DIR}/ca.crt" ]] && [[ -f "${CA_DIR}/ca.key" ]]; then
    echo "CA certificates already exist at ${CA_DIR}"
    echo "Remove them first if you want to regenerate:"
    echo "  rm -rf ${CA_DIR}"
    exit 1
fi

echo "Generating Root CA..."
echo ""

# Generate CA private key (ECDSA P-256)
# NOTE: We use mode 644 (not 600) because container volumes mount as root
# but the application runs as appuser (UID 1000). Swoole needs to read
# the CA cert/key for mTLS verification.
# In production, use proper UID mapping or secrets management instead.
echo "1. Generating CA private key (ECDSA P-256)..."
openssl ecparam -genkey -name prime256v1 -noout -out "${CA_DIR}/ca.key"
chmod 644 "${CA_DIR}/ca.key"
echo "   Created: ${CA_DIR}/ca.key"

# Generate CA certificate
echo "2. Generating CA certificate (valid for 10 years)..."
openssl req -new -x509 -sha256 -days 3650 \
    -key "${CA_DIR}/ca.key" \
    -out "${CA_DIR}/ca.crt" \
    -subj "/C=US/ST=California/L=San Francisco/O=Ashchan/OU=ServiceMesh/CN=ashchan-ca" \
    -addext "basicConstraints=critical,CA:TRUE" \
    -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=hash"

echo "   Created: ${CA_DIR}/ca.crt"

# Create CA configuration file
echo "3. Creating CA configuration file..."
cat > "${CA_DIR}/ca.cnf" << 'EOF'
# Ashchan Root CA Configuration

[ ca ]
default_ca = CA_default

[ CA_default ]
dir               = /etc/ashchan/ca
certs             = $dir/certs
crl_dir           = $dir/crl
new_certs_dir     = $dir/newcerts
database          = $dir/index.txt
serial            = $dir/serial
RANDFILE          = $dir/.rand

private_key       = $dir/ca.key
certificate       = $dir/ca.crt

crlnumber         = $dir/crlnumber
crl               = $dir/crl.pem
crl_extensions    = crl_ext
default_crl_days  = 30

default_md        = sha256
name_opt          = ca_default
cert_opt          = ca_default
default_days      = 365
preserve          = no
policy            = policy_loose

[ policy_loose ]
countryName             = optional
stateOrProvinceName     = optional
localityName            = optional
organizationName        = optional
organizationalUnitName  = optional
commonName              = supplied
emailAddress            = optional

[ req ]
default_bits        = 2048
distinguished_name  = req_distinguished_name
string_mask         = utf8only
default_md          = sha256
x509_extensions     = v3_ca

[ req_distinguished_name ]
countryName                     = Country Name (2 letter code)
stateOrProvinceName             = State or Province Name
localityName                    = Locality Name
0.organizationName              = Organization Name
organizationalUnitName          = Organizational Unit Name
commonName                      = Common Name
emailAddress                    = Email Address

countryName_default             = US
stateOrProvinceName_default     = California
localityName_default            = San Francisco
0.organizationName_default      = Ashchan
organizationalUnitName_default  = ServiceMesh
emailAddress_default            = admin@ashchan.local

[ v3_ca ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true
keyUsage = critical, digitalSignature, cRLSign, keyCertSign

[ v3_intermediate_ca ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true, pathlen:0
keyUsage = critical, digitalSignature, cRLSign, keyCertSign

[ server_cert ]
basicConstraints = CA:FALSE
nsCertType = server
nsComment = "OpenSSL Generated Server Certificate"
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer:always
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth, clientAuth

[ client_cert ]
basicConstraints = CA:FALSE
nsCertType = client
nsComment = "OpenSSL Generated Client Certificate"
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer:always
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = clientAuth, serverAuth

[ crl_ext ]
authorityKeyIdentifier=keyid:always
EOF

echo "   Created: ${CA_DIR}/ca.cnf"

# Create serial and index files for CA
echo "4. Initializing CA database..."
touch "${CA_DIR}/index.txt"
echo "1000" > "${CA_DIR}/serial"
echo "1000" > "${CA_DIR}/crlnumber"
echo "   Created: ${CA_DIR}/index.txt, ${CA_DIR}/serial, ${CA_DIR}/crlnumber"

# Copy CA cert to services directory for easy access
cp "${CA_DIR}/ca.crt" "${CERTS_DIR}/services/ca.crt"

echo ""
echo "=== Root CA Generation Complete ==="
echo ""
echo "CA Certificate: ${CA_DIR}/ca.crt"
echo "CA Private Key: ${CA_DIR}/ca.key"
echo ""
echo "Next steps:"
echo "  1. Generate service certificates: ./scripts/mtls/generate-cert.sh <service-name>"
echo "  2. Or generate all at once: ./scripts/mtls/generate-all-certs.sh"
echo ""
echo "Verify CA certificate:"
echo "  openssl x509 -in ${CA_DIR}/ca.crt -text -noout"
echo ""
