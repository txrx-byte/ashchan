# OpenBao Secrets Management for Ashchan

**Last Updated:** 2026-02-28  
**License:** MPL 2.0 (Open Source)  
**Governance:** Linux Foundation / OpenSSF

---

## Overview

This directory contains the OpenBao integration for Ashchan, providing:

- **Centralized secrets management** - No more `.env` files with plaintext secrets
- **Dynamic database credentials** - Auto-generated, short-lived PostgreSQL users
- **Automatic secret rotation** - Configurable rotation policies
- **Encryption as a Service** - Transit encryption for PII without managing keys
- **Audit logging** - Immutable logs of all secret access
- **Multi-tenancy** - Namespaces for dev/staging/production isolation

---

## Why OpenBao (Not HashiCorp Vault)?

| Feature | OpenBao | HashiCorp Vault |
|---------|---------|-----------------|
| License | MPL 2.0 (Open Source) | BSL 1.1 (Restricted) |
| Commercial Use | ✅ Unrestricted | ⚠️ Limited |
| SaaS Offering | ✅ Allowed | ❌ Prohibited |
| Governance | Linux Foundation (Community) | HashiCorp (Corporate) |
| Namespaces | ✅ Included | ❌ Enterprise Only |
| HA Read Scaling | ✅ Included | ❌ Enterprise Only |
| API Compatibility | ✅ Vault-compatible | N/A |

**Bottom line:** OpenBao is the truly open source choice with no licensing gotchas.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Ashchan Services                          │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐        │
│  │ Gateway  │  │   Auth   │  │  Boards  │  │  Media   │        │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └────┬─────┘        │
│       │             │             │             │               │
│       └─────────────┴─────────────┴─────────────┘               │
│                           │                                      │
│                    mTLS + Client Cert                            │
│                           │                                      │
└───────────────────────────┼──────────────────────────────────────┘
                            │
                    ┌───────▼────────┐
                    │   OpenBao      │
                    │   (Port 8200)  │
                    └───────┬────────┘
                            │
            ┌───────────────┼───────────────┐
            │               │               │
    ┌───────▼───────┐ ┌─────▼──────┐ ┌─────▼──────┐
    │   PostgreSQL  │ │   Redis    │ │   Audit    │
    │   (Dynamic    │ │ (Password  │ │   Logs     │
    │    Creds)     │ │  Storage)  │ │ (File/Syslog)│
    └───────────────┘ └────────────┘ └────────────┘
```

---

## Quick Start

### Prerequisites

- Linux (Ubuntu 22.04+, Debian 12+, Alpine 3.18+, or RHEL 9+)
- `systemd` (for service management)
- `curl`, `jq`, `openssl`
- Root or sudo access

### Installation

```bash
# Run the interactive installer
sudo make openbao-install

# Or run directly
sudo tools/openbao/install.sh
```

The installer will:
1. Download and verify OpenBao binary
2. Create system user and directories
3. Generate configuration based on your answers
4. Initialize and unseal OpenBao
5. Configure PostgreSQL dynamic credentials
6. Migrate existing secrets from `.env` files

### Post-Installation

```bash
# Check OpenBao status
make openbao-status

# View audit logs
make openbao-audit

# Backup secrets
make openbao-backup

# Rotate encryption key
make openbao-rotate
```

---

## Configuration

### Environment Modes

The installer supports three modes:

| Mode | Description | Unseal Keys | Use Case |
|------|-------------|-------------|----------|
| `dev` | In-memory, auto-unsealed | N/A | Local development |
| `standalone` | Single server, file-backed | 3 of 5 | Small deployments |
| `ha` | High availability (Raft) | 3 of 5 | Production clusters |

### Storage Backends

| Backend | Pros | Cons | Best For |
|---------|------|------|----------|
| `file` | Simple, no dependencies | Single point of failure | Dev/Testing |
| `raft` (built-in) | HA, no external deps | Requires 3+ nodes | Production |
| `postgresql` | Familiar, backup-friendly | External dependency | Existing PG clusters |
| `redis` | Fast, in-memory | Data loss on restart | Caching layer |

---

## Secrets Paths

| Path | Type | Description |
|------|------|-------------|
| `secret/ashchan/global` | KV v2 | Global secrets (JWT secret, encryption keys) |
| `secret/ashchan/services/{name}` | KV v2 | Per-service secrets |
| `database/ashchan/postgresql` | Database | Dynamic PostgreSQL credentials |
| `transit/ashchan/pii` | Transit | PII encryption keys |
| `pki/ashchan` | PKI | Internal certificate authority |

---

## Migration from .env Files

The installer includes a migration tool:

```bash
# Dry run (show what would be migrated)
sudo tools/openbao/migrate-secrets.sh --dry-run

# Actual migration
sudo tools/openbao/migrate-secrets.sh

# Rollback (restore .env files from OpenBao)
sudo tools/openbao/migrate-secrets.sh --rollback
```

After migration, services read secrets from OpenBao at startup:

```bash
# Services fetch secrets on startup
curl --cacert /etc/ashchan/openbao/ca.crt \
     --cert /etc/ashchan/openbao/client.crt \
     --key /etc/ashchan/openbao/client.key \
     https://localhost:8200/v1/secret/ashchan/services/auth

# Secrets are injected as environment variables
# via systemd service wrapper
```

---

## Security Model

### Authentication

| Method | Description | Enabled For |
|--------|-------------|-------------|
| `cert` | mTLS client certificates | Services |
| `jwt` | JWT tokens (Kubernetes) | Future |
| `token` | Static tokens (CLI) | Operators |

### Authorization

Policies follow least privilege:

```hcl
# Policy: auth-service
path "secret/ashchan/services/auth" {
  capabilities = ["read"]
}

path "database/creds/ashchan" {
  capabilities = ["read"]
}

path "transit/encrypt/pii" {
  capabilities = ["update"]
}

path "transit/decrypt/pii" {
  capabilities = ["update"]
}
```

### Audit Logging

All operations are logged:

```json
{
  "time": "2026-02-28T12:34:56.789Z",
  "type": "request",
  "auth": {
    "client_token": "hvs.CAESI...",
    "display_name": "cert-auth-service"
  },
  "request": {
    "operation": "read",
    "path": "secret/ashchan/services/auth",
    "data": {
      "keys": ["db_password", "jwt_secret"]
    }
  },
  "response": {
    "status_code": 200
  }
}
```

---

## Operations

### Daily Tasks

```bash
# Check health
make openbao-status

# View recent audit logs
make openbao-audit

# Check seal status
make openbao-unseal-status

# List pending rotations
make openbao-rotation-schedule
```

### Weekly Tasks

```bash
# Backup secrets
make openbao-backup

# Test restore procedure
make openbao-restore-test

# Review audit logs for anomalies
make openbao-audit-review
```

### Monthly Tasks

```bash
# Rotate encryption key
make openbao-rotate-key

# Rotate client certificates
make openbao-rotate-certs

# Review and update policies
make openbao-policy-review
```

---

## Troubleshooting

### Common Issues

| Issue | Symptom | Solution |
|-------|---------|----------|
| Sealed | Services can't connect | Run `make openbao-unseal` |
| Expired cert | mTLS handshake fails | Run `make openbao-rotate-certs` |
| Policy denied | 403 Forbidden | Check policy with `openbao policy read <name>` |
| Storage full | Write failures | Run `make openbao-storage-cleanup` |

### Emergency Procedures

```bash
# Emergency unseal (requires 3 key holders)
make openbao-emergency-unseal

# Emergency seal (lock down immediately)
make openbao-emergency-seal

# Restore from backup
make openbao-restore-backup BACKUP_FILE=/path/to/backup
```

---

## Related Documentation

- [OpenBao Official Docs](https://openbao.org/docs/)
- [Ashchan Security Model](../../docs/security.md)
- [Ashchan mTLS ServiceMesh](../../docs/SERVICEMESH.md)

---

## License

OpenBao is licensed under **MPL 2.0**.

Ashchan integration scripts are licensed under **Apache 2.0** (matching Ashchan).
