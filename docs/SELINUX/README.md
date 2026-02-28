# Ashchan SELinux Security Architecture

**Version:** 1.0  
**Date:** 28 February 2026  
**Classification:** Enterprise Security Architecture

---

## Overview

This directory contains the complete SELinux (Security-Enhanced Linux) policy modules and documentation for the Ashchan microservices imageboard platform. SELinux provides **mandatory access control (MAC)** that confines each service to its minimum required permissions, protecting against privilege escalation, lateral movement, and data exfiltration attacks.

### Why SELinux?

Ashchan uses **native PHP-CLI deployment** without containerization. This means:

| Without SELinux | With SELinux |
|-----------------|--------------|
| Processes run with full user privileges | Processes confined to minimum permissions |
| A compromised service can access all user files | Access limited to designated file contexts |
| Network access is unrestricted | Network connections controlled by policy |
| No protection against privilege escalation | Multiple layers of enforcement |

**SELinux is the primary confinement mechanism** for Ashchan's native PHP-CLI deployment model.

---

## Architecture

### Service Domains

Each Ashchan service runs in its own SELinux domain (type):

```
┌─────────────────────────────────────────────────────────────┐
│                    SELinux Domain Isolation                  │
├─────────────────────────────────────────────────────────────┤
│  Service              │  Domain Type                        │
├─────────────────────────────────────────────────────────────┤
│  API Gateway          │  ashchan_gateway_t                  │
│  Auth/Accounts        │  ashchan_auth_t                     │
│  Boards/Threads/Posts │  ashchan_boards_t                   │
│  Media/Uploads        │  ashchan_media_t                    │
│  Search/Indexing      │  ashchan_search_t                   │
│  Moderation/Anti-Spam │  ashchan_moderation_t               │
└─────────────────────────────────────────────────────────────┘
```

### Supporting Daemons

Additional domains for infrastructure components:

| Component | Domain Type | Purpose |
|-----------|-------------|---------|
| Cloudflare Tunnel | `cloudflared_t` | Outbound-only encrypted tunnel |
| Anubis PoW | `anubis_t` | AI firewall with proof-of-work challenges |
| Varnish Cache | `varnishd_t` | HTTP reverse proxy cache |

### Trust Boundaries

```
                    TRUST BOUNDARY ANALYSIS

┌──────────────────────────────────────────────────────────────┐
│  UNTRUSTED ZONE (Internet)                                    │
│  ─────────────────────                                        │
│  Any external traffic                                         │
└────────────────────────┬─────────────────────────────────────┘
                         │ Cloudflare Tunnel (encrypted)
                         ▼
┌──────────────────────────────────────────────────────────────┐
│  DMZ EQUIVALENT (nginx + Anubis)                              │
│  ──────────────────────                                       │
│  nginx_t (httpd_t)                                            │
│  anubis_t (custom domain)                                     │
│  SELinux: Enforce mode                                        │
└────────────────────────┬─────────────────────────────────────┘
                         │ Varnish cache
                         ▼
┌──────────────────────────────────────────────────────────────┐
│  APPLICATION ZONE (API Gateway + Services)                    │
│  ─────────────────────                                        │
│  ashchan_gateway_t                                            │
│  ashchan_auth_t, ashchan_boards_t, etc.                       │
│  SELinux: Enforce mode (PRIMARY CONFINEMENT)                  │
└────────────────────────┬─────────────────────────────────────┘
                         │ mTLS + network controls
                         ▼
┌──────────────────────────────────────────────────────────────┐
│  DATA ZONE (PostgreSQL, Redis, MinIO)                         │
│  ─────────────                                                │
│  postgresql_t, redis_t, minio_t                               │
│  SELinux: Enforce mode                                        │
└──────────────────────────────────────────────────────────────┘
```

---

## Quick Start

### Prerequisites

```bash
# Verify SELinux is available and in enforcing mode
sestatus

# Expected output:
#   SELinux status:                 enabled
#   Current mode:                   enforcing
#   Policy from config file:        targeted

# Install SELinux policy development tools
sudo dnf install -y selinux-policy-devel policycoreutils-python-utils
```

### Installation

```bash
# Navigate to SELinux policy directory
cd docs/SELINUX/policy

# Build all policy modules
make

# Install policy modules
sudo make install

# Apply file contexts to Ashchan installation
sudo make restorecon

# Enable required SELinux booleans
sudo make setbooleans

# Verify installation
sudo semodule -l | grep ashchan
```

### Verification

```bash
# Check policy modules are loaded
semodule -l | grep -E 'ashchan|cloudflared|anubis|varnish'

# Check file contexts
ls -Z /opt/ashchan

# Check port contexts
sudo semanage port -l | grep ashchan

# Check boolean status
getsebool -a | grep ashchan
```

---

## Directory Structure

```
docs/SELINUX/
├── README.md                    # This file - overview and quick start
├── ARCHITECTURE.md              # Detailed security architecture documentation
├── INSTALLATION.md              # Step-by-step installation guide
├── TROUBLESHOOTING.md           # Common issues and resolutions
├── QUICKREF.md                  # Quick reference card for administrators
│
├── policy/                      # SELinux policy modules
│   ├── Makefile                 # Build automation
│   ├── ashchan.te               # Main Ashchan services policy
│   ├── ashchan.fc               # File context definitions
│   ├── ashchan.if               # Reusable interfaces
│   ├── cloudflared.te           # Cloudflare Tunnel policy
│   ├── anubis.te                # Anubis PoW firewall policy
│   └── varnish.te               # Varnish cache policy
│
└── scripts/                     # Management scripts
    ├── install.sh               # Automated installation script
    ├── verify.sh                # Verification and health check
    ├── backup.sh                # Backup current SELinux configuration
    ├── rollback.sh              # Emergency rollback procedure
    └── audit-monitor.sh         # Real-time AVC denial monitoring
```

---

## Policy Modules

### ashchan.te (Main Policy)

The main Ashchan policy module defines:

- **6 service domains** (gateway, auth, boards, media, search, moderation)
- **File access rules** for application code, runtime directories, logs, certificates
- **Network access rules** for port binding and inter-service communication
- **Boolean controls** for optional functionality

### cloudflared.te

Policy for the Cloudflare Tunnel daemon:

- **Outbound-only network access** (no inbound connections allowed)
- **Configuration file access** for tunnel credentials
- **Runtime directory access** for state management

### anubis.te

Policy for the Anubis proof-of-work firewall:

- **Port binding** for challenge endpoint (8080)
- **Redis connectivity** for challenge state storage
- **Process control** for worker management

### varnish.te

Policy for Varnish HTTP cache:

- **Port binding** for cache (6081) and admin (6082)
- **Backend connectivity** to API Gateway
- **VCL configuration access**

---

## File Contexts

### Application Code

| Context Type | Path Pattern | Purpose |
|--------------|--------------|---------|
| `ashchan_app_t` | `/opt/ashchan/` | Application root |
| `ashchan_gateway_app_t` | `services/api-gateway/` | Gateway code |
| `ashchan_auth_app_t` | `services/auth-accounts/` | Auth code |
| `ashchan_boards_app_t` | `services/boards-threads-posts/` | Boards code |
| `ashchan_media_app_t` | `services/media-uploads/` | Media code |
| `ashchan_search_app_t` | `services/search-indexing/` | Search code |
| `ashchan_moderation_app_t` | `services/moderation-anti-spam/` | Moderation code |

### Sensitive Files

| Context Type | Path Pattern | Sensitivity |
|--------------|--------------|-------------|
| `ashchan_ca_key_t` | `certs/ca/ca.key` | **CRITICAL** |
| `ashchan_service_key_t` | `certs/services/*/*.key` | **CRITICAL** |
| `ashchan_config_secret_t` | `services/*/.env` | **CRITICAL** |

### Runtime and Logs

| Context Type | Path Pattern | Purpose |
|--------------|--------------|---------|
| `ashchan_runtime_t` | `/opt/ashchan/runtime/`, `/tmp/ashchan/` | Runtime data |
| `ashchan_log_t` | `/opt/ashchan/runtime/logs/` | Log files |
| `ashchan_var_run_t` | `/opt/ashchan/runtime/pid/` | PID files |

---

## Port Contexts

### Service Ports

```bash
# HTTP ports (public-facing)
semanage port -l | grep ashchan_port_t
#   ashchan_port_t  tcp  9501, 9502, 9503, 9504, 9505, 9506

# mTLS ports (service-to-service)
semanage port -l | grep ashchan_mtls_port_t
#   ashchan_mtls_port_t  tcp  8443, 8444, 8445, 8446, 8447, 8448
```

### Infrastructure Ports

| Port Type | Ports | Component |
|-----------|-------|-----------|
| `anubis_port_t` | 8080 | Anubis PoW firewall |
| `varnish_port_t` | 6081 | Varnish HTTP cache |
| `varnish_admin_port_t` | 6082 | Varnish admin interface |
| `anubis_metrics_port_t` | 9091 | Anubis Prometheus metrics |

---

## SELinux Booleans

### Standard Booleans

```bash
# PHP/Swoole network access
setsebool -P httpd_can_network_connect 1
setsebool -P httpd_can_network_connect_db 1
setsebool -P httpd_can_network_connect_redis 1
setsebool -P httpd_execmem 1  # Required for JIT compilation
```

### Custom Ashchan Booleans

| Boolean | Default | Purpose |
|---------|---------|---------|
| `ashchan_external_network` | on | Cloudflare Tunnel outbound |
| `ashchan_connect_postgresql` | on | Database connectivity |
| `ashchan_connect_redis` | on | Cache/event bus |
| `ashchan_connect_minio` | on | Object storage |
| `ashchan_use_pcntl` | on | Swoole process management |
| `ashchan_create_sockets` | on | Network communication |
| `ashchan_access_certs` | on | mTLS certificate access |
| `ashchan_syslog` | on | System logging |

---

## Testing and Validation

### Permissive Mode Testing

Before enforcing policies, test in permissive mode:

```bash
# Add service domains to permissive mode
sudo semanage permissive -a ashchan_gateway_t
sudo semanage permissive -a ashchan_auth_t
sudo semanage permissive -a ashchan_boards_t
sudo semanage permissive -a ashchan_media_t
sudo semanage permissive -a ashchan_search_t
sudo semanage permissive -a ashchan_moderation_t

# Start services and monitor for AVC denials
sudo ausearch -m avc -ts recent | audit2why

# Generate policy updates from denials
sudo ausearch -m avc -ts today | audit2allow -M ashchan_local
```

### Functionality Tests

```bash
# Test HTTP endpoints
curl http://localhost:9501/health
curl http://localhost:9502/health

# Test mTLS communication
curl --cacert /opt/ashchan/certs/ca/ca.crt \
     --cert /opt/ashchan/certs/services/gateway/gateway.crt \
     --key /opt/ashchan/certs/services/gateway/gateway.key \
     https://localhost:8443/health

# Test database connectivity
sudo -u ashchan php -r "new PDO('pgsql:host=localhost;port=5432;dbname=ashchan');"

# Test Redis connectivity
sudo -u ashchan php -r "new Redis(); Redis->connect('localhost', 6379);"
```

### Enforcing Mode

After successful permissive testing:

```bash
# Remove permissive mode
sudo semanage permissive -d ashchan_gateway_t
sudo semanage permissive -d ashchan_auth_t
sudo semanage permissive -d ashchan_boards_t
sudo semanage permissive -d ashchan_media_t
sudo semanage permissive -d ashchan_search_t
sudo semanage permissive -d ashchan_moderation_t

# Verify enforcing mode
getenforce  # Should return: Enforcing
```

---

## Monitoring and Alerting

### Real-time AVC Monitoring

```bash
# Watch for Ashchan-related denials
sudo tail -f /var/log/audit/audit.log | grep -E 'avc.*denied.*ashchan'

# Use the monitoring script
./scripts/audit-monitor.sh
```

### Daily Audit Report

```bash
# Generate daily denial report
sudo ausearch -m avc -ts today | audit2why > /var/log/selinux-ashchan-daily.log

# Count denials by type
sudo ausearch -m avc -ts today | grep denied | awk -F'}' '{print $NF}' | sort | uniq -c
```

---

## Emergency Procedures

### Immediate Permissive Mode

If services fail due to SELinux policy issues:

```bash
# Switch to permissive mode immediately
sudo setenforce 0

# Verify mode change
getenforce  # Should return: Permissive
```

### Rollback Procedure

To completely remove Ashchan SELinux policies:

```bash
# Run the rollback script
sudo ./scripts/rollback.sh

# Or manually:
sudo semodule -r ashchan cloudflared anubis varnish
sudo restorecon -Rv /opt/ashchan
```

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for detailed rollback procedures.

---

## Related Documentation

| Document | Description |
|----------|-------------|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Detailed security architecture and threat model |
| [INSTALLATION.md](INSTALLATION.md) | Step-by-step installation guide |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Common issues and resolutions |
| [QUICKREF.md](QUICKREF.md) | Quick reference card for administrators |
| [../security.md](../security.md) | Overall Ashchan security documentation |
| [../SERVICEMESH.md](../SERVICEMESH.md) | mTLS service mesh architecture |
| [../FIREWALL_HARDENING.md](../FIREWALL_HARDENING.md) | Firewall and system hardening |

---

## Support

For SELinux-related issues:

1. Check [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues
2. Run `sudo ausearch -m avc -ts recent | audit2why` for denial analysis
3. Use the monitoring script: `./scripts/audit-monitor.sh`
4. Consult the Ashchan security team for policy updates

---

## License

SELinux policy modules are licensed under the Apache License, Version 2.0, consistent with the Ashchan project.

See [LICENSE](../../LICENSE) for the full license text.
