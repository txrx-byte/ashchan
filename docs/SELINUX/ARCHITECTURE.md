# Ashchan SELinux Security Architecture

**Version:** 1.0.0  
**Date:** 28 February 2026  
**Classification:** Enterprise Security Architecture

---

## 1. Executive Summary

### 1.1 Purpose

This document describes the SELinux (Security-Enhanced Linux) security architecture for the Ashchan microservices imageboard platform. SELinux provides **mandatory access control (MAC)** that confines each service to its minimum required permissions.

### 1.2 Security Model

Ashchan uses **native PHP-CLI deployment** without containerization. SELinux serves as the **primary confinement mechanism**:

| Without SELinux | With SELinux |
|-----------------|--------------|
| Processes run with full user privileges | Processes confined to minimum permissions |
| Compromised service can access all files | Access limited to designated contexts |
| Network access is unrestricted | Network connections controlled by policy |
| No protection against privilege escalation | Multiple enforcement layers |

### 1.3 Key Design Decisions

1. **Per-Service Domains**: Each microservice runs in its own SELinux domain
2. **Least Privilege**: Services only receive permissions required for their function
3. **Defense in Depth**: SELinux complements other security controls (mTLS, firewall)
4. **Enforce Mode**: Production deployment requires enforcing mode

---

## 2. Threat Model

### 2.1 Assets Protected

| Asset | Threat | SELinux Control |
|-------|--------|-----------------|
| Application code | Unauthorized modification | File type enforcement |
| mTLS private keys | Theft, unauthorized access | Restricted file contexts |
| Database credentials | Exfiltration | Secret file contexts |
| PostgreSQL data | Unauthorized access | Network port controls |
| Redis cache | Cache poisoning | Network port controls |
| User uploads | Unauthorized access | Media service isolation |
| Log files | Tampering, injection | Log file contexts |
| Service ports | Hijacking | Port type enforcement |

### 2.2 Attack Vectors Mitigated

```
Attack Vector                    SELinux Control
─────────────────────────────────────────────────────────
PHP RCE exploitation     →       Domain confinement
Privilege escalation     →       Type enforcement
Lateral movement         →       Service isolation
Data exfiltration        →       Network controls
Configuration tampering  →       File contexts
Log manipulation         →       Log file contexts
Port hijacking           →       Port type enforcement
```

### 2.3 Trust Boundaries

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

## 3. Policy Architecture

### 3.1 Policy Modules

| Module | Purpose | Services |
|--------|---------|----------|
| `ashchan` | Main service policies | Gateway, Auth, Boards, Media, Search, Moderation |
| `cloudflared` | Cloudflare Tunnel | Outbound tunnel daemon |
| `anubis` | PoW Firewall | AI challenge service |
| `varnish` | HTTP Cache | Varnish cache daemon |

### 3.2 Service Domains

```
ashchan_gateway_t     ──┐
ashchan_auth_t      ────┤
ashchan_boards_t    ────┤
ashchan_media_t     ────┤  Isolated domains
ashchan_search_t    ────┤  with controlled
ashchan_moderation_t ───┘  communication
```

### 3.3 Domain Isolation

```selinux
# Each service has its own domain type
type ashchan_gateway_t, domain;
type ashchan_auth_t, domain;
type ashchan_boards_t, domain;
type ashchan_media_t, domain;
type ashchan_search_t, domain;
type ashchan_moderation_t, domain;

# Services cannot access each other's memory
# Services cannot signal each other
# Services can only communicate via approved network ports
```

---

## 4. File Context Architecture

### 4.1 Context Hierarchy

```
ashchan_app_t (application root)
    │
    ├── ashchan_gateway_app_t
    ├── ashchan_auth_app_t
    ├── ashchan_boards_app_t
    ├── ashchan_media_app_t
    ├── ashchan_search_app_t
    └── ashchan_moderation_app_t

ashchan_certs_t (certificate root)
    │
    ├── ashchan_ca_certs_t (CA certificates)
    │   └── ashchan_ca_key_t (CA private key - CRITICAL)
    │
    └── ashchan_service_certs_t (service certificates)
        └── ashchan_service_key_t (service private keys - CRITICAL)
```

### 4.2 Sensitivity Levels

| Level | Context Types | Access Control |
|-------|---------------|----------------|
| **CRITICAL** | `ashchan_ca_key_t`, `ashchan_service_key_t`, `ashchan_config_secret_t` | Read-only, service-specific |
| **High** | `ashchan_ca_certs_t`, `ashchan_service_certs_t`, `ashchan_auth_app_t`, `ashchan_moderation_app_t` | Read-only for certs, isolated for apps |
| **Medium** | `ashchan_gateway_app_t`, `ashchan_boards_app_t`, `ashchan_media_app_t`, `ashchan_search_app_t`, `ashchan_log_t` | Service-specific access |
| **Low** | `ashchan_app_t`, `ashchan_runtime_t`, `ashchan_lib_t` | Read access for all services |

### 4.3 File Access Rules

```selinux
# Services can read application code
allow ashchan_gateway_t ashchan_app_t:file { read getattr open };

# Services can read/write their own runtime files
allow ashchan_gateway_t ashchan_runtime_t:file { create read write unlink };

# Services can write to their own log files
allow ashchan_gateway_t ashchan_log_t:file { create append write };

# Services can read CA cert and own cert/key
allow ashchan_gateway_t ashchan_ca_certs_t:file { read getattr };
allow ashchan_gateway_t ashchan_service_key_t:file { read getattr };
```

---

## 5. Network Access Control

### 5.1 Port Type Enforcement

```selinux
# Service HTTP ports
type ashchan_port_t, port_type;      # 9501-9506

# Service mTLS ports
type ashchan_mtls_port_t, port_type; # 8443-8448

# Infrastructure ports
type anubis_port_t, port_type;       # 8080
type varnish_port_t, port_type;      # 6081
type varnish_admin_port_t, port_type; # 6082
```

### 5.2 Network Flow

```
                    Network Access Control Flow

Internet ──▶ cloudflared_t (outbound only)
                 │
                 ▼
             anubis_t (port 8080)
                 │
                 ▼
            varnishd_t (port 6081)
                 │
                 ▼
         ashchan_gateway_t (port 9501/8443)
                 │
         ┌───────┼───────┬───────┬───────┐
         ▼       ▼       ▼       ▼       ▼
    ashchan  ashchan  ashchan  ashchan  ashchan
     _auth   _boards  _media  _search  _moderation
```

### 5.3 Connection Rules

```selinux
# Gateway can connect to all backend services
corenet_tcp_connect_generic_port(ashchan_gateway_t)

# Backend services can connect to PostgreSQL
if (ashchan_connect_postgresql) {
    corenet_tcp_connect_postgresql_port(ashchan_gateway_t)
    corenet_tcp_connect_postgresql_port(ashchan_auth_t)
    # ... all services
}

# Backend services can connect to Redis
if (ashchan_connect_redis) {
    corenet_tcp_connect_redis_port(ashchan_gateway_t)
    # ... all services
}
```

---

## 6. Boolean Controls

### 6.1 Standard Booleans

| Boolean | Purpose | Default |
|---------|---------|---------|
| `httpd_can_network_connect` | Allow network connections | on |
| `httpd_can_network_connect_db` | Allow database connections | on |
| `httpd_can_network_connect_redis` | Allow Redis connections | on |
| `httpd_execmem` | Allow executable memory (JIT) | on |

### 6.2 Custom Ashchan Booleans

| Boolean | Purpose | Default |
|---------|---------|---------|
| `ashchan_external_network` | Outbound network access | on |
| `ashchan_connect_postgresql` | PostgreSQL connectivity | on |
| `ashchan_connect_redis` | Redis connectivity | on |
| `ashchan_connect_minio` | MinIO/S3 connectivity | on |
| `ashchan_use_pcntl` | Process control (Swoole) | on |
| `ashchan_create_sockets` | Socket creation | on |
| `ashchan_use_tmp` | Temporary file access | on |
| `ashchan_access_certs` | Certificate access | on |
| `ashchan_syslog` | System logging | on |
| `ashchan_bind_privileged_ports` | Bind to ports < 1024 | off |

### 6.3 Boolean Usage

```bash
# Check boolean status
getsebool ashchan_connect_postgresql

# Enable boolean
sudo setsebool -P ashchan_connect_postgresql 1

# Disable boolean
sudo setsebool -P ashchan_connect_postgresql 0
```

---

## 7. Process Control

### 7.1 Swoole Process Management

Ashchan uses Swoole's coroutine runtime which requires process control:

```selinux
# Allow process signaling for worker management
allow ashchan_gateway_t self:process { signal sigchld signull };

# Allow execution of PHP binaries
allow ashchan_gateway_t ashchan_exec_t:file { execute execute_no_trans };
```

### 7.2 Capabilities

```selinux
# Typically NOT needed for Swoole (runs as user)
# allow ashchan_gateway_t self:capability { setuid setgid };

# Only if binding to privileged ports (< 1024)
if (ashchan_bind_privileged_ports) {
    allow ashchan_gateway_t self:capability { net_bind_service };
}
```

---

## 8. Systemd Integration

### 8.1 Service Units

```ini
# /etc/systemd/system/ashchan-gateway.service
[Unit]
Description=Ashchan API Gateway
After=network.target

[Service]
Type=notify
User=ashchan
Group=ashchan
WorkingDirectory=/opt/ashchan/services/api-gateway
ExecStart=/usr/bin/php bin/hyperf.php start
Restart=always

# SELinux context (optional, policy handles this)
# SELinuxContext=system_u:system_r:ashchan_gateway_t:s0

[Install]
WantedBy=multi-user.target
```

### 8.2 Domain Transition

```selinux
# Transition from init to service domain via systemd
init_daemon_domain(ashchan_gateway_t, ashchan_exec_t)

# Allow systemd to manage the service
systemd_dbus_chat_systemd(ashchan_gateway_t)
```

---

## 9. Certificate Security

### 9.1 Certificate Hierarchy

```
ashchan-ca (Root CA)
├── gateway.ashchan.local
├── auth.ashchan.local
├── boards.ashchan.local
├── media.ashchan.local
├── search.ashchan.local
└── moderation.ashchan.local
```

### 9.2 Certificate Access Control

```selinux
# All services can read CA certificate
allow ashchan_gateway_t ashchan_ca_certs_t:file { read getattr };

# Each service can read its own certificate and key
allow ashchan_gateway_t ashchan_service_certs_t:file { read getattr };
allow ashchan_gateway_t ashchan_service_key_t:file { read getattr };

# Services CANNOT read other services' keys
# (enforced by file path, not SELinux type)
```

### 9.3 Private Key Protection

| Key | Context | Access |
|-----|---------|--------|
| CA private key | `ashchan_ca_key_t` | Certificate authority only |
| Gateway private key | `ashchan_service_key_t` | Gateway service only |
| Auth private key | `ashchan_service_key_t` | Auth service only |
| Boards private key | `ashchan_service_key_t` | Boards service only |
| Media private key | `ashchan_service_key_t` | Media service only |
| Search private key | `ashchan_service_key_t` | Search service only |
| Moderation private key | `ashchan_service_key_t` | Moderation service only |

---

## 10. Logging and Audit

### 10.1 Audit Configuration

```bash
# Enable SELinux audit logging
sudo auditctl -w /opt/ashchan -p rwxa -k ashchan_access

# Monitor certificate access
sudo auditctl -w /opt/ashchan/certs -p r -k ashchan_cert_read
```

### 10.2 Log Analysis

```bash
# View Ashchan-related denials
sudo ausearch -m avc -ts recent | grep ashchan

# Generate daily report
sudo ausearch -m avc -ts today | audit2why > /var/log/selinux-ashchan.log
```

---

## 11. Testing Strategy

### 11.1 Permissive Mode Testing

```bash
# Set domains to permissive mode
sudo semanage permissive -a ashchan_gateway_t
sudo semanage permissive -a ashchan_auth_t
# ... all domains

# Monitor for denials
sudo ausearch -m avc -ts recent

# Generate fixes
sudo ausearch -m avc -ts recent | audit2allow -M ashchan_local
```

### 11.2 Functionality Tests

| Test | Command | Expected |
|------|---------|----------|
| HTTP health | `curl http://localhost:9501/health` | 200 OK |
| mTLS health | `curl --cacert ... https://localhost:8443/health` | 200 OK |
| Database | PHP PDO connection | Success |
| Redis | PHP Redis connection | Success |

### 11.3 Enforcement Criteria

Before switching to enforcing mode:

- [ ] No AVC denials in permissive mode
- [ ] All health checks pass
- [ ] All functionality tests pass
- [ ] Log analysis shows no issues
- [ ] Performance baseline established

---

## 12. Deployment Checklist

### 12.1 Pre-Installation

- [ ] SELinux enabled and in enforcing mode
- [ ] Required packages installed
- [ ] Backup current SELinux configuration
- [ ] Review policy modules

### 12.2 Installation

- [ ] Build policy modules
- [ ] Install policy modules
- [ ] Configure file contexts
- [ ] Configure port contexts
- [ ] Enable required booleans
- [ ] Apply file contexts

### 12.3 Testing

- [ ] Set domains to permissive mode
- [ ] Start all services
- [ ] Run functionality tests
- [ ] Monitor for AVC denials
- [ ] Fix any issues

### 12.4 Production

- [ ] Switch to enforcing mode
- [ ] Verify all services running
- [ ] Set up monitoring
- [ ] Document configuration
- [ ] Schedule policy review

---

## 13. Maintenance

### 13.1 Policy Updates

```bash
# Check for policy updates
semodule -l | grep ashchan

# Update policy
cd docs/SELINUX/policy
make clean
make
sudo make install
sudo make restorecon
```

### 13.2 Quarterly Review

- [ ] Review AVC denials
- [ ] Update policy if needed
- [ ] Verify boolean settings
- [ ] Test backup/restore procedure
- [ ] Update documentation

---

## 14. References

### 14.1 Related Documents

- [README.md](README.md) - Overview and quick start
- [INSTALLATION.md](INSTALLATION.md) - Installation guide
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - Troubleshooting
- [QUICKREF.md](QUICKREF.md) - Quick reference

### 14.2 External Resources

- [SELinux Project](https://selinuxproject.org/)
- [Red Hat SELinux Guide](https://access.redhat.com/documentation/en-us/red_hat_enterprise_linux/8/html/security_hardening/using-selinux)
- [NSA SELinux Guide](https://www.nsa.gov/resources/everyone/securing-hosts/)

---

**Document End**

*This document is part of the Ashchan Security Architecture. See [../security.md](../security.md) for overall security documentation.*
