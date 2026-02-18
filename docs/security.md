# Security

## Principles
- **Least privilege**: minimal service-to-service permissions.
- **Defense in depth**: layered controls at edge, gateway, and services.
- **Fail securely**: default-deny policies.
- **Zero trust**: never trust, always verify (mTLS for all internal traffic).

---

## mTLS ServiceMesh

### Overview

All service-to-service communication uses **mutual TLS (mTLS)** for authentication and encryption. This provides:

- **Server authentication**: Clients verify server identity
- **Client authentication**: Servers verify client identity
- **Encryption**: All traffic encrypted in transit
- **Non-repudiation**: Certificate-based identity

### Certificate Authority

```
ashchan-ca (Root CA)
├── gateway.ashchan.local
├── auth.ashchan.local
├── boards.ashchan.local
├── media.ashchan.local
├── search.ashchan.local
└── moderation.ashchan.local
```

### Certificate Properties

| Property | Value |
|----------|-------|
| Root CA validity | 10 years |
| Service certificate validity | 1 year |
| Key algorithm | ECDSA P-256 |
| Signature algorithm | SHA-256 |
| Minimum TLS version | TLS 1.3 |
| Cipher suites | AES-256-GCM, ChaCha20-Poly1305 |

### mTLS Configuration

Each service requires:

```bash
# Server certificate
MTLS_CERT_FILE=/etc/mtls/<service>/<service>.crt
MTLS_KEY_FILE=/etc/mtls/<service>/<service>.key

# CA certificate for verification
MTLS_CA_FILE=/etc/mtls/ca/ca.crt

# Client certificate (for outbound calls)
MTLS_CLIENT_CERT_FILE=/etc/mtls/<service>/<service>.crt
MTLS_CLIENT_KEY_FILE=/etc/mtls/<service>/<service>.key
```

### Service Identity

Services are identified by certificate CN and SAN:

| Service | DNS Name | Certificate Path |
|---------|----------|------------------|
| API Gateway | `gateway.ashchan.local` | `/etc/mtls/gateway/` |
| Auth/Accounts | `auth.ashchan.local` | `/etc/mtls/auth/` |
| Boards/Threads/Posts | `boards.ashchan.local` | `/etc/mtls/boards/` |
| Media/Uploads | `media.ashchan.local` | `/etc/mtls/media/` |
| Search/Indexing | `search.ashchan.local` | `/etc/mtls/search/` |
| Moderation/Anti-Spam | `moderation.ashchan.local` | `/etc/mtls/moderation/` |

### mTLS Handshake

```
Client                          Server
  │                               │
  │────Client Hello──────────────>│
  │                               │
  │<───Server Hello + Cert───────│
  │<───Certificate Request────────│
  │                               │
  │────Client Cert───────────────>│
  │                               │
  │         [Verify certs]        │
  │         [Check chain]         │
  │                               │
  │<───Encrypted Session─────────│
```

### Certificate Management

```bash
# Generate Root CA
make mtls-init

# Generate all service certificates
make mtls-certs

# Verify mTLS configuration
make mtls-verify

# Rotate certificates (before expiry)
make mtls-rotate

# Check certificate status
make mtls-status
```

### See Also
- [docs/SERVICEMESH.md](docs/SERVICEMESH.md) - Complete mTLS architecture
- `scripts/mtls/` - Certificate management scripts

---

## Secrets Management

### Environment Variables (Development)
- Secrets stored in `.env` files per service.
- Never commit `.env` files to version control.
- Use `.env.example` as template.

### Production Secrets
- Use secrets manager (Vault, AWS Secrets Manager).
- Inject via systemd service files or Podman secrets.
- Rotate credentials on schedule.

### Secret Rotation Schedule

| Secret Type | Rotation Period |
|-------------|-----------------|
| Service certificates | 1 year (auto at 30 days) |
| Database passwords | 90 days |
| API keys | 90 days |
| JWT secrets | 30 days |

---

## Input Validation

### Schema Validation
- All API requests validated against OpenAPI schemas.
- Strict type checking (declare(strict_types=1)).
- Request size limits enforced.

### Content Sanitization
- HTML stripped from post content.
- File uploads validated by MIME type and magic bytes.
- URLs sanitized (no javascript:, data: schemes).

### Rate Limiting
- Per-IP rate limits at gateway.
- Per-user rate limits after authentication.
- Per-service rate limits for internal calls.

---

## Audit Logging

### Log Format
```json
{
  "timestamp": "2025-02-18T12:00:00Z",
  "level": "info",
  "service": "api-gateway",
  "event": "mtls_handshake_complete",
  "client_cn": "boards.ashchan.local",
  "tls_version": "TLSv1.3",
  "correlation_id": "abc123"
}
```

### Audit Events
- Authentication attempts (success/failure)
- Authorization decisions
- mTLS handshake events
- Moderation actions
- Configuration changes

### Log Retention
- Hot storage: 30 days
- Cold storage: 1 year
- Compliance logs: 7 years

---

## Data Encryption

### In Transit
- Public traffic: TLS 1.2+ (HTTPS)
- Internal traffic: TLS 1.3+ (mTLS)
- No plaintext internal communication

### At Rest
- PostgreSQL: TDE or filesystem encryption
- Redis: AUTH + protected network
- MinIO: Server-side encryption (SSE-S3)

### Sensitive Fields
- IP addresses: hashed with salt
- Email addresses: encrypted (if stored)
- Consents: encrypted with KMS key

---

## Network Security

### Network Segmentation

```
┌─────────────────────────────────────┐
│         ashchan-public              │
│       (10.90.1.0/24)                │
│  ┌─────────────┐                    │
│  │   Gateway   │                    │
│  │  10.90.1.10 │                    │
│  └──────┬──────┘                    │
└─────────┼───────────────────────────┘
          │
┌─────────┼───────────────────────────┐
│         ▼      ashchan-mesh         │
│       (10.90.0.0/24)                │
│  ┌──────┴──────┐                    │
│  │  Services   │ mTLS only          │
│  │ 10.90.0.20+ │                    │
│  └──────┬──────┘                    │
│  ┌──────┴──────┐                    │
│  │ Infra (DB)  │                    │
│  │ 10.90.0.10+ │                    │
│  └─────────────┘                    │
└─────────────────────────────────────┘
```

### Firewall Rules

**API Gateway:**
- Inbound: 9501 (HTTP), 9443 (HTTPS) from anywhere
- Outbound: 8443 to services (mTLS only)

**Application Services:**
- Inbound: 8443 from gateway only (mTLS)
- Outbound: 5432 (PostgreSQL), 6379 (Redis)

**Infrastructure:**
- PostgreSQL: 5432 from services only
- Redis: 6379 from services only
- MinIO: 9000 from media service only

---

## DDoS Protection

### Edge Protection
- Cloudflare or AWS Shield for production.
- Rate limiting at edge.
- Geographic blocking if needed.

### Gateway Protection
- Request rate limiting per IP.
- Connection rate limiting.
- Request size limits.

### Service Protection
- Circuit breakers for downstream calls.
- Bulkhead isolation per service.
- Timeout enforcement.

---

## Incident Response

### Kill Switches
- Per-board disable capability.
- Posting disable (read-only mode).
- Media upload disable.

### Runbooks
- DDoS response procedure.
- Spam wave response.
- Certificate compromise response.
- Data breach response.

### Contact
- Security team: security@ashchan.local
- PGP key: [link to key]

---

## Compliance

### Data Retention
- Posts: indefinite (until deleted)
- IP logs: 30 days (hashed)
- Audit logs: 7 years
- Media: indefinite (until deleted)

### User Rights (GDPR/CCPA)
- Right to access: export all user data
- Right to deletion: anonymize posts
- Right to correction: edit user info

---

## Security Checklist

### Development
- [ ] mTLS enabled for all services
- [ ] Certificates valid and not expiring soon
- [ ] Secrets not hardcoded
- [ ] Input validation on all endpoints
- [ ] Rate limiting configured

### Deployment
- [ ] Firewall rules applied
- [ ] Network segmentation verified
- [ ] Certificates rotated if needed
- [ ] Audit logging enabled
- [ ] Backups encrypted

### Operations
- [ ] Certificate expiration monitored
- [ ] Logs reviewed for anomalies
- [ ] Rate limit alerts configured
- [ ] Incident response plan tested
- [ ] Security patches applied
