# Ashchan mTLS Security Architecture

## Overview

Ashchan uses **mTLS (Mutual TLS)** for secure service-to-service communication. This architecture provides:

- **Mutual TLS authentication** between all services
- **Certificate-based identity** for zero-trust security
- **Native PHP-CLI deployment** without container dependencies
- **Systemd integration** for production process management

---

## Architecture Diagram

```
                                    ┌─────────────────┐
                                    │   Public Internet
                                    └────────┬────────┘
                                             │
                                    ┌────────▼────────┐
                                    │  API Gateway    │
                                    │  (Port 9501)    │
                                    │  TLS Termination│
                                    └────────┬────────┘
                                             │ mTLS (Port 8443)
         ┌───────────────────┬───────────────┼───────────────┬───────────────────┐
         │                   │               │               │                   │
┌────────▼────────┐ ┌────────▼────────┐ ┌───▼─────────┐ ┌───▼─────────┐ ┌───────▼───────┐
│ Auth/Accounts   │ │Boards/Threads   │ │ Media/      │ │ Search/     │ │ Moderation/   │
│ (Port 9502)     │ │ (Port 9503)     │ │ Uploads     │ │ Indexing    │ │ Anti-Spam     │
│ mTLS:8443       │ │ mTLS:8443       │ │ (Port 9504) │ │ (Port 9505) │ │ (Port 9506)   │
└────────┬────────┘ └────────┬────────┘ └──────┬──────┘ └──────┬──────┘ └───────┬───────┘
         │                   │                 │               │                 │
         └───────────────────┴─────────────────┴───────────────┴─────────────────┘
                                    │
              ┌─────────────────────┼─────────────────────┐
              │                     │                     │
     ┌────────▼────────┐  ┌────────▼────────┐  ┌────────▼────────┐
     │   PostgreSQL    │  │     Redis       │  │     MinIO       │
     │   (Port 5432)   │  │   (Port 6379)   │  │ (Port 9000/9001)│
     └─────────────────┘  └─────────────────┘  └─────────────────┘
```

---

## Service Identity

Each service has a unique identity based on X.509 certificates:

| Service | Certificate CN | HTTP Port | mTLS Port |
|---------|----------------|-----------|-----------|
| API Gateway | `gateway` | 9501 | 8443 |
| Auth/Accounts | `auth` | 9502 | 8443 |
| Boards/Threads/Posts | `boards` | 9503 | 8443 |
| Media/Uploads | `media` | 9504 | 8443 |
| Search/Indexing | `search` | 9505 | 8443 |
| Moderation/Anti-Spam | `moderation` | 9506 | 8443 |

### Infrastructure Services (No mTLS)

| Service | Port | Notes |
|---------|------|-------|
| PostgreSQL | 5432 | Use SSL if connecting over network |
| Redis | 6379 | Use TLS if connecting over network |
| MinIO | 9000 | Use HTTPS for production |

---

## Certificate Authority Structure

```
ashchan-ca (Root CA)
├── gateway.crt (Server Certificate)
├── auth.crt (Server Certificate)
├── boards.crt (Server Certificate)
├── media.crt (Server Certificate)
├── search.crt (Server Certificate)
└── moderation.crt (Server Certificate)
```

### Certificate Properties

- **Root CA**: Valid for 10 years, self-signed
- **Server Certificates**: Valid for 1 year, auto-renewable
- **Key Algorithm**: ECDSA P-256 (or RSA 2048)
- **Signature Algorithm**: SHA-256
- **Key Usage**: Digital Signature, Key Encipherment
- **Extended Key Usage**: TLS Web Server Authentication, TLS Web Client Authentication

---

## mTLS Handshake Flow

```
┌─────────────┐                              ┌─────────────┐
│   Client    │                              │   Server    │
│  Service    │                              │  Service    │
└──────┬──────┘                              └──────┬──────┘
       │                                            │
       │           Client Hello                     │
       │           (no cert yet)                    │
       │───────────────────────────────────────────>│
       │                                            │
       │           Server Hello +                   │
       │           Server Certificate               │
       │           Certificate Request              │
       │<───────────────────────────────────────────│
       │                                            │
       │           Client Certificate               │
       │           Certificate Verify               │
       │───────────────────────────────────────────>│
       │                                            │
       │           Verify client cert               │
       │           Verify certificate chain         │
       │           Check SAN/CN                     │
       │                                            │
       │           Encrypted Session                │
       │<═══════════════════════════════════════════│
       │                                            │
```

---

## Security Properties

### Authentication
- **Server Authentication**: Client verifies server certificate
- **Client Authentication**: Server verifies client certificate
- **Certificate Chain**: All certs signed by `ashchan-ca`
- **SAN Validation**: Subject Alternative Names must match expected identity

### Authorization
- **Service Identity**: Derived from certificate CN
- **Policy Enforcement**: Gateway enforces service-to-service policies
- **Least Privilege**: Services only access required resources

### Encryption
- **TLS 1.3**: Minimum TLS version
- **Cipher Suites**: Strong ciphers only (AES-256-GCM, ChaCha20-Poly1305)
- **Perfect Forward Secrecy**: Ephemeral key exchange

---

## Certificate Lifecycle

### Generation (Development)
```bash
# Generate Root CA
./scripts/mtls/generate-ca.sh

# Generate all service certificates
./scripts/mtls/generate-all-certs.sh

# Generate single service certificate
./scripts/mtls/generate-cert.sh gateway localhost
```

### Generation (Production)
```bash
# Use Vault or external CA
vault write pki/issue/ashchan-services \
    common_name=gateway \
    ttl=8760h \
    alt_names=gateway,localhost
```

### Rotation
- **Automatic**: Certificates renewed at 70% lifetime via cron
- **Manual**: `./scripts/mtls/rotate-certs.sh`
- **Emergency**: Revoke and reissue immediately

### Revocation
- **CRL**: Certificate Revocation List maintained
- **OCSP**: Optional OCSP responder for production

---

## Service Configuration

### Environment Variables

Each service requires these mTLS-related variables:

```bash
# Service identity
SERVICE_NAME=auth-accounts

# mTLS configuration
MTLS_ENABLED=true
MTLS_PORT=8443
MTLS_CERT_FILE=/path/to/certs/services/auth/auth.crt
MTLS_KEY_FILE=/path/to/certs/services/auth/auth.key
MTLS_CA_FILE=/path/to/certs/ca/ca.crt

# Client certificate (for outbound calls)
MTLS_CLIENT_CERT_FILE=/path/to/certs/services/auth/auth.crt
MTLS_CLIENT_KEY_FILE=/path/to/certs/services/auth/auth.key

# Peer verification
MTLS_VERIFY_CLIENT=true
MTLS_MIN_TLS_VERSION=TLSv1.3
```

### Hyperf Server Configuration

```php
// config/autoload/server.php
return [
    'servers' => [
        [
            'name' => 'mtls',
            'type' => Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => 8443,
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                SwooleEvent::ON_REQUEST => [App\Controller\IndexController::class, 'index'],
            ],
            'options' => [
                'open_ssl' => true,
                'ssl_cert_file' => env('MTLS_CERT_FILE'),
                'ssl_key_file' => env('MTLS_KEY_FILE'),
                'ssl_verify_peer' => true,
                'ssl_verify_depth' => 3,
                'ssl_ca_file' => env('MTLS_CA_FILE'),
            ],
        ],
    ],
];
```

### HTTP Client Configuration (Outbound mTLS)

```php
// config/autoload/mtls_client.php
return [
    'http_client' => [
        'default_options' => [
            'verify' => env('MTLS_CA_FILE'),
            'cert' => env('MTLS_CLIENT_CERT_FILE'),
            'key' => env('MTLS_CLIENT_KEY_FILE'),
        ],
    ],
];
```

---

## Request Flow Example

### Creating a Post (with mTLS)

```
1. Client → Gateway (HTTPS)
   POST /api/v1/boards/g/threads/12345/posts
   Authorization: Bearer <token>

2. Gateway → Auth Service (mTLS)
   POST https://localhost:8443/api/v1/verify
   Client Cert: gateway
   Server Cert: auth
   
3. Gateway → Boards Service (mTLS)
   POST https://localhost:8443/api/v1/posts
   Client Cert: gateway
   Server Cert: boards
   
4. Boards Service → Media Service (mTLS)
   GET https://localhost:8443/api/v1/media/abc123
   Client Cert: boards
   Server Cert: media
   
5. Boards Service → Moderation Service (mTLS)
   POST https://localhost:8443/api/v1/score
   Client Cert: boards
   Server Cert: moderation
```

---

## Failure Modes

### Certificate Expiration
- **Symptom**: TLS handshake fails with "certificate expired"
- **Detection**: Monitoring alerts at 30 days before expiration
- **Recovery**: Automatic renewal or manual rotation

### Certificate Revocation
- **Symptom**: TLS handshake fails with "certificate revoked"
- **Detection**: CRL/OCSP check failure
- **Recovery**: Issue new certificate, investigate compromise

### mTLS Handshake Failure
- **Symptom**: SSL_ERROR alerts in logs
- **Causes**:
  - Certificate chain mismatch
  - Unsupported TLS version
  - Cipher suite incompatibility
- **Recovery**: Verify certificate configuration, check TLS settings

---

## Monitoring and Observability

### Metrics to Track

| Metric | Description | Alert Threshold |
|--------|-------------|-----------------|
| `mtls_handshake_total` | Total mTLS handshakes | - |
| `mtls_handshake_failures` | Failed handshakes | > 1% of total |
| `mtls_cert_expiry_days` | Days until cert expires | < 30 days |
| `mtls_revoked_connections` | Connections with revoked certs | > 0 |

### Logging

```json
{
  "timestamp": "2026-02-18T12:00:00Z",
  "level": "info",
  "service": "api-gateway",
  "event": "mtls_handshake_complete",
  "client_cn": "boards",
  "server_cn": "gateway",
  "tls_version": "TLSv1.3",
  "cipher_suite": "TLS_AES_256_GCM_SHA384"
}
```

### Health Checks

```bash
# Test mTLS connection
openssl s_client -connect localhost:8443 \
  -cert certs/services/gateway/gateway.crt \
  -key certs/services/gateway/gateway.key \
  -CAfile certs/ca/ca.crt

# Verify certificate chain
openssl verify -CAfile certs/ca/ca.crt certs/services/auth/auth.crt
```

---

## Deployment

### Development

```bash
# Generate certificates
./scripts/mtls/generate-ca.sh
./scripts/mtls/generate-all-certs.sh

# Start services
make up

# Verify mTLS
./scripts/mtls/verify-mesh.sh
```

### Production (Systemd)

```ini
# /etc/systemd/system/ashchan-gateway.service
[Unit]
Description=Ashchan API Gateway
After=network.target postgresql.service redis.service

[Service]
Type=simple
User=ashchan
Group=ashchan
WorkingDirectory=/opt/ashchan/services/api-gateway
Environment=APP_ENV=production
Environment=MTLS_CERT_FILE=/etc/ashchan/certs/services/gateway/gateway.crt
Environment=MTLS_KEY_FILE=/etc/ashchan/certs/services/gateway/gateway.key
Environment=MTLS_CA_FILE=/etc/ashchan/certs/ca/ca.crt
ExecStart=/usr/bin/php bin/hyperf.php start
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Multi-Host Deployment

For multi-host deployments:

1. **Centralized CA**: Use Vault or HSM for certificate management
2. **Certificate Distribution**: Automate cert deployment via Ansible/Chef
3. **Service URLs**: Configure environment variables with actual hostnames
4. **Network Security**: Use firewall rules to restrict mTLS ports

---

## Troubleshooting

### Common Issues

| Issue | Symptom | Solution |
|-------|---------|----------|
| Certificate mismatch | "unable to verify certificate" | Regenerate certs with correct CN |
| TLS version mismatch | "unsupported protocol" | Ensure TLS 1.3 on all services |
| Permission denied | "cannot read certificate" | Fix file permissions (644 for certs, 600 for keys) |
| Port already in use | "address already in use" | Stop existing process or use different port |

### Debug Commands

```bash
# Check certificate details
openssl x509 -in certs/services/gateway/gateway.crt -text -noout

# Test connection without client cert (should fail)
curl -k https://localhost:8443/health

# Test connection with client cert (should succeed)
curl --cacert certs/ca/ca.crt \
     --cert certs/services/gateway/gateway.crt \
     --key certs/services/gateway/gateway.key \
     https://localhost:8443/health

# Check PHP Swoole SSL support
php -r "var_dump(extension_loaded('swoole'), defined('SWOOLE_SSL'));"
```

---

## Security Best Practices

1. **Never commit certificates** to version control
2. **Rotate certificates** at least annually
3. **Use separate CAs** for development and production
4. **Monitor certificate expiration** with alerts
5. **Restrict certificate access** (600 for keys, 644 for certs)
6. **Audit mTLS connections** in logs
7. **Use hardware security modules** (HSM) for production CA
8. **Implement certificate pinning** for critical services

---

## Future Enhancements

- **Automatic certificate rotation** via cron job
- **Service mesh dashboard** for certificate status
- **mTLS middleware** for automatic client cert injection
- **OCSP stapling** for faster revocation checks
- **SPIFFE/SPIRE integration** for workload identity
