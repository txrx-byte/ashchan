# Ashchan mTLS ServiceMesh Architecture

## Overview

Ashchan uses a **DNS-based mTLS ServiceMesh** for secure service-to-service communication. This architecture provides:

- **Mutual TLS (mTLS)** authentication between all services
- **DNS-based service discovery** via rootless Podman networking
- **Zero-trust security model** with certificate-based identity
- **No Kubernetes dependency** - runs entirely on Podman Compose

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
│ mtls:8443       │ │ mtls:8443       │ │ (Port 9504) │ │ (Port 9505) │ │ (Port 9506)   │
│ http:9502       │ │ http:9502       │ │ mtls:8443   │ │ mtls:8443   │ │ mtls:8443     │
└────────┬────────┘ └────────┬────────┘ └──────┬──────┘ └──────┬──────┘ └───────┬───────┘
         │                   │                 │               │                 │
         └───────────────────┴─────────────────┴───────────────┴─────────────────┘
                                    │
                        ┌───────────┴───────────┐
                        │   ServiceMesh Network │
                        │    10.90.0.0/24       │
                        │   DNS: ashchan.local  │
                        └───────────┬───────────┘
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

Each service has a unique identity based on DNS names:

| Service | DNS Name | Certificate CN | Internal URL |
|---------|----------|----------------|--------------|
| API Gateway | `gateway.ashchan.local` | `gateway.ashchan.local` | `https://gateway.ashchan.local:8443` |
| Auth/Accounts | `auth.ashchan.local` | `auth.ashchan.local` | `https://auth.ashchan.local:8443` |
| Boards/Threads/Posts | `boards.ashchan.local` | `boards.ashchan.local` | `https://boards.ashchan.local:8443` |
| Media/Uploads | `media.ashchan.local` | `media.ashchan.local` | `https://media.ashchan.local:8443` |
| Search/Indexing | `search.ashchan.local` | `search.ashchan.local` | `https://search.ashchan.local:8443` |
| Moderation/Anti-Spam | `moderation.ashchan.local` | `moderation.ashchan.local` | `https://moderation.ashchan.local:8443` |

### Infrastructure Services (No mTLS)

| Service | DNS Name | Internal URL |
|---------|----------|--------------|
| PostgreSQL | `postgres.ashchan.local` | `postgres.ashchan.local:5432` |
| Redis | `redis.ashchan.local` | `redis.ashchan.local:6379` |
| MinIO | `minio.ashchan.local` | `https://minio.ashchan.local:9000` |

---

## Certificate Authority Structure

```
ashchan-ca (Root CA)
├── gateway.ashchan.local (Server Certificate)
├── auth.ashchan.local (Server Certificate)
├── boards.ashchan.local (Server Certificate)
├── media.ashchan.local (Server Certificate)
├── search.ashchan.local (Server Certificate)
├── moderation.ashchan.local (Server Certificate)
└── ashchan.local (Client CA - signs client certs)
```

### Certificate Properties

- **Root CA**: Valid for 10 years, self-signed
- **Server Certificates**: Valid for 1 year, auto-renewable
- **Key Algorithm**: ECDSA P-256 (or RSA 2048)
- **Signature Algorithm**: SHA-256
- **Key Usage**: Digital Signature, Key Encipherment
- **Extended Key Usage**: TLS Web Server Authentication, TLS Web Client Authentication

---

## Network Topology

### Podman Network Configuration

```yaml
networks:
  ashchan-mesh:
    driver: bridge
    ipam:
      driver: host-local
      config:
        - subnet: 10.90.0.0/24
          gateway: 10.90.0.1
    options:
      com.podman.network.bridge.name: ashchan-mesh
```

### DNS Resolution

Podman's internal DNS resolver provides automatic service discovery:

- Container hostname → Container IP
- Service name → Container IP
- Custom domain: `*.ashchan.local`

**DNS Configuration in Containers:**
```bash
# Inside any container
getent hosts auth.ashchan.local
# Returns: 10.90.0.21  auth.ashchan.local
```

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
- **SAN Validation**: Subject Alternative Names must match DNS

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

# Generate service certificates
./scripts/mtls/generate-cert.sh gateway.ashchan.local
./scripts/mtls/generate-cert.sh auth.ashchan.local
# ... etc
```

### Generation (Production)
```bash
# Use Vault or external CA
vault write pki/issue/ashchan-services \
    common_name=gateway.ashchan.local \
    ttl=8760h \
    ip_sans=10.90.0.10
```

### Rotation
- **Automatic**: Certificates renewed at 70% lifetime
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
SERVICE_DNS_NAME=auth.ashchan.local

# mTLS configuration
MTLS_ENABLED=true
MTLS_PORT=8443
MTLS_CERT_FILE=/etc/mtls/server.crt
MTLS_KEY_FILE=/etc/mtls/server.key
MTLS_CA_FILE=/etc/mtls/ca.crt

# Client certificate (for outbound calls)
MTLS_CLIENT_CERT_FILE=/etc/mtls/client.crt
MTLS_CLIENT_KEY_FILE=/etc/mtls/client.key

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
                'ssl_cert_file' => '/etc/mtls/server.crt',
                'ssl_key_file' => '/etc/mtls/server.key',
                'ssl_verify_peer' => true,
                'ssl_verify_depth' => 3,
                'ssl_ca_file' => '/etc/mtls/ca.crt',
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
            'verify' => '/etc/mtls/ca.crt',
            'cert' => '/etc/mtls/client.crt',
            'key' => '/etc/mtls/client.key',
            'ssl_key' => '/etc/mtls/client.key',
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
   POST https://auth.ashchan.local:8443/api/v1/verify
   Client Cert: gateway.ashchan.local
   Server Cert: auth.ashchan.local
   
3. Gateway → Boards Service (mTLS)
   POST https://boards.ashchan.local:8443/api/v1/posts
   Client Cert: gateway.ashchan.local
   Server Cert: boards.ashchan.local
   
4. Boards Service → Media Service (mTLS)
   GET https://media.ashchan.local:8443/api/v1/media/abc123
   Client Cert: boards.ashchan.local
   Server Cert: media.ashchan.local
   
5. Boards Service → Moderation Service (mTLS)
   POST https://moderation.ashchan.local:8443/api/v1/score
   Client Cert: boards.ashchan.local
   Server Cert: moderation.ashchan.local
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

### DNS Resolution Failure
- **Symptom**: Connection timeout, "host not found"
- **Detection**: Health check failures
- **Recovery**: Restart Podman network, check DNS configuration

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
  "timestamp": "2025-02-18T12:00:00Z",
  "level": "info",
  "service": "api-gateway",
  "event": "mtls_handshake_complete",
  "client_cn": "boards.ashchan.local",
  "server_cn": "gateway.ashchan.local",
  "tls_version": "TLSv1.3",
  "cipher_suite": "TLS_AES_256_GCM_SHA384"
}
```

### Health Checks

```bash
# Test mTLS connection
openssl s_client -connect auth.ashchan.local:8443 \
  -cert /etc/mtls/client.crt \
  -key /etc/mtls/client.key \
  -CAfile /etc/mtls/ca.crt

# Verify certificate chain
openssl verify -CAfile /etc/mtls/ca.crt /etc/mtls/server.crt
```

---

## Deployment

### Development (Podman Compose)

```bash
# Generate certificates
./scripts/mtls/generate-ca.sh
./scripts/mtls/generate-all-certs.sh

# Start services
podman-compose up -d

# Verify mTLS
./scripts/mtls/verify-mesh.sh
```

### Production (Rootless Podman on multiple hosts)

```bash
# On each host:
# 1. Install certificates
# 2. Configure Podman network
# 3. Deploy services with systemd

# For multi-host:
# - Use WireGuard/VXLAN for host-to-host networking
# - Centralized CA (Vault)
# - DNS forwarding between hosts
```

---

## Troubleshooting

### Common Issues

| Issue | Symptom | Solution |
|-------|---------|----------|
| Certificate mismatch | "unable to verify certificate" | Regenerate certs with correct CN |
| DNS resolution fails | "host not found" | Check Podman network config |
| TLS version mismatch | "unsupported protocol" | Ensure TLS 1.3 on all services |
| Permission denied | "cannot read certificate" | Fix file permissions (644 for certs, 600 for keys) |

### Debug Commands

```bash
# Check certificate details
openssl x509 -in /etc/mtls/server.crt -text -noout

# Test connection without client cert (should fail)
curl -k https://auth.ashchan.local:8443/health

# Test connection with client cert (should succeed)
curl --cacert /etc/mtls/ca.crt \
     --cert /etc/mtls/client.crt \
     --key /etc/mtls/client.key \
     https://auth.ashchan.local:8443/health

# Check Podman DNS
podman exec ashchan-gateway-1 getent hosts auth.ashchan.local
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

## Migration from Static IPs

### Before (Static IPs)
```bash
AUTH_SERVICE_URL=http://10.90.0.21:9502
BOARDS_SERVICE_URL=http://10.90.0.22:9503
```

### After (DNS + mTLS)
```bash
AUTH_SERVICE_URL=https://auth.ashchan.local:8443
BOARDS_SERVICE_URL=https://boards.ashchan.local:8443
```

### Migration Steps
1. Deploy mTLS infrastructure (CA, certificates)
2. Update service configurations for mTLS
3. Update environment variables (HTTP → HTTPS, IP → DNS)
4. Test each service pair individually
5. Enable mTLS verification in production
6. Disable non-mTLS ports (9502-9506)

---

## Future Enhancements

- **Automatic certificate rotation** via cron job
- **Service mesh dashboard** for certificate status
- **mTLS middleware** for automatic client cert injection
- **OCSP stapling** for faster revocation checks
- **SPIFFE/SPIRE integration** for workload identity
- **Envoy sidecar** proxy for advanced traffic management
