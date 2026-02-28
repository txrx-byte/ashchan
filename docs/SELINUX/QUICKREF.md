# Ashchan SELinux Quick Reference

**Version:** 1.0.0  
**Date:** 28 February 2026

---

## Quick Start

```bash
# Install everything
cd docs/SELINUX/policy
sudo make install

# Or use the installation script
sudo ./scripts/install.sh

# Verify installation
sudo ./scripts/verify.sh
```

---

## Command Reference

### Build Commands

```bash
make              # Build all policy modules
make ashchan      # Build ashchan policy only
make cloudflared  # Build cloudflared policy only
make anubis       # Build anubis policy only
make varnish      # Build varnish policy only
make clean        # Remove build artifacts
```

### Installation Commands

```bash
sudo make install         # Install all policies
sudo make setcontexts     # Configure file context rules
sudo make setports        # Configure port context rules
sudo make setbooleans     # Enable required booleans
sudo make restorecon      # Apply file contexts
```

### Testing Commands

```bash
sudo make permissive      # Set domains to permissive mode
sudo make enforcing       # Set domains to enforcing mode
sudo make verify          # Verify installation
```

### Uninstall Commands

```bash
sudo make uninstall       # Remove all policies
```

---

## Service Domains

| Service | Domain Type | Port |
|---------|-------------|------|
| API Gateway | `ashchan_gateway_t` | 9501 |
| Auth/Accounts | `ashchan_auth_t` | 9502 |
| Boards/Threads/Posts | `ashchan_boards_t` | 9503 |
| Media/Uploads | `ashchan_media_t` | 9504 |
| Search/Indexing | `ashchan_search_t` | 9505 |
| Moderation/Anti-Spam | `ashchan_moderation_t` | 9506 |

---

## Port Contexts

### HTTP Ports
```bash
ashchan_port_t: 9501, 9502, 9503, 9504, 9505, 9506
```

### mTLS Ports
```bash
ashchan_mtls_port_t: 8443, 8444, 8445, 8446, 8447, 8448
```

### Infrastructure Ports
```bash
anubis_port_t:        8080
varnish_port_t:       6081
varnish_admin_port_t: 6082
anubis_metrics_port_t: 9091
```

---

## SELinux Booleans

### Required Booleans

```bash
# Standard
httpd_can_network_connect      = on
httpd_can_network_connect_db   = on
httpd_can_network_connect_redis = on
httpd_execmem                  = on

# Ashchan custom
ashchan_external_network       = on
ashchan_connect_postgresql     = on
ashchan_connect_redis          = on
ashchan_connect_minio          = on
ashchan_use_pcntl              = on
ashchan_create_sockets         = on
ashchan_use_tmp                = on
ashchan_access_certs           = on
ashchan_syslog                 = on
```

### Boolean Commands

```bash
# Check boolean status
getsebool ashchan_external_network

# Enable boolean
sudo setsebool -P ashchan_external_network 1

# List all Ashchan booleans
getsebool -a | grep ashchan
```

---

## File Contexts

### Application Code

| Path | Context |
|------|---------|
| `/opt/ashchan/` | `ashchan_app_t` |
| `/opt/ashchan/services/api-gateway/` | `ashchan_gateway_app_t` |
| `/opt/ashchan/services/auth-accounts/` | `ashchan_auth_app_t` |
| `/opt/ashchan/services/boards-threads-posts/` | `ashchan_boards_app_t` |
| `/opt/ashchan/services/media-uploads/` | `ashchan_media_app_t` |
| `/opt/ashchan/services/search-indexing/` | `ashchan_search_app_t` |
| `/opt/ashchan/services/moderation-anti-spam/` | `ashchan_moderation_app_t` |

### Sensitive Files

| Path | Context | Sensitivity |
|------|---------|-------------|
| `/opt/ashchan/certs/ca/ca.key` | `ashchan_ca_key_t` | **CRITICAL** |
| `/opt/ashchan/certs/services/*/*.key` | `ashchan_service_key_t` | **CRITICAL** |
| `/opt/ashchan/services/*/.env` | `ashchan_config_secret_t` | **CRITICAL** |

### Runtime and Logs

| Path | Context |
|------|---------|
| `/opt/ashchan/runtime/` | `ashchan_runtime_t` |
| `/opt/ashchan/runtime/logs/` | `ashchan_log_t` |
| `/opt/ashchan/runtime/pid/` | `ashchan_var_run_t` |
| `/tmp/ashchan/` | `ashchan_runtime_t` |

---

## Diagnostic Commands

### Quick Diagnostics

```bash
# Check SELinux mode
getenforce

# Check policy modules
semodule -l | grep ashchan

# Check file contexts
ls -Z /opt/ashchan

# Check port contexts
sudo semanage port -l | grep ashchan

# Check booleans
getsebool -a | grep ashchan
```

### AVC Denial Analysis

```bash
# View recent denials
sudo ausearch -m avc -ts recent

# Get explanation
sudo ausearch -m avc -ts recent | audit2why

# Generate fix policy
sudo ausearch -m avc -ts recent | audit2allow -M ashchan_fix

# Install fix
sudo semodule -i ashchan_fix.pp
```

---

## Common Fixes

### Port Binding Failed

```bash
# Add port to ashchan_port_t
sudo semanage port -a -t ashchan_port_t -p tcp 9501
```

### Database Connection Failed

```bash
# Enable PostgreSQL boolean
sudo setsebool -P ashchan_connect_postgresql 1
```

### Redis Connection Failed

```bash
# Enable Redis boolean
sudo setsebool -P ashchan_connect_redis 1
```

### Certificate Access Denied

```bash
# Apply correct context
sudo semanage fcontext -a -t ashchan_service_key_t \
    "/opt/ashchan/certs/services/gateway/gateway\.key"
sudo restorecon -v /opt/ashchan/certs/services/gateway/gateway.key
```

### Log Write Failed

```bash
# Apply log context
sudo semanage fcontext -a -t ashchan_log_t \
    "/opt/ashchan/runtime/logs(/.*)?"
sudo restorecon -Rv /opt/ashchan/runtime/logs
```

---

## Emergency Procedures

### Immediate Permissive Mode

```bash
# Switch to permissive (immediate)
sudo setenforce 0

# Verify
getenforce  # Should return: Permissive
```

### Restore Enforcing Mode

```bash
# Switch to enforcing
sudo setenforce 1

# Verify
getenforce  # Should return: Enforcing
```

### Remove All Policies

```bash
# Remove policy modules
sudo semodule -r ashchan cloudflared anubis varnish

# Reset file contexts
sudo restorecon -Rv /opt/ashchan
```

---

## Installation Checklist

```bash
# 1. Install dependencies
sudo dnf install -y selinux-policy-devel policycoreutils-python-utils

# 2. Build policies
cd docs/SELINUX/policy
make

# 3. Install policies
sudo make install

# 4. Configure contexts
sudo make setcontexts
sudo make setports
sudo make setbooleans

# 5. Apply file contexts
sudo make restorecon

# 6. Verify installation
sudo make verify

# 7. Test in permissive mode (optional)
sudo make permissive

# 8. Switch to enforcing after testing
sudo make enforcing
```

---

## Service Management

```bash
# Start all services
sudo systemctl start ashchan-gateway
sudo systemctl start ashchan-auth
sudo systemctl start ashchan-boards
sudo systemctl start ashchan-media
sudo systemctl start ashchan-search
sudo systemctl start ashchan-moderation

# Check status
sudo systemctl status ashchan-gateway

# View logs
journalctl -u ashchan-gateway -f

# Restart service
sudo systemctl restart ashchan-gateway
```

---

## Troubleshooting Flow

```
Service not working?
    │
    ├─→ Check SELinux mode: getenforce
    │   └─→ If Disabled: Enable SELinux
    │
    ├─→ Check for denials: sudo ausearch -m avc -ts recent
    │   └─→ If denials found:
    │       ├─→ Analyze: audit2why
    │       ├─→ Fix boolean: setsebool -P <bool> 1
    │       ├─→ Fix port: semanage port -a -t <type> -p tcp <port>
    │       ├─→ Fix context: restorecon -Rv <path>
    │       └─→ Generate fix: audit2allow -M fix && semodule -i fix.pp
    │
    ├─→ Check policy installed: semodule -l | grep ashchan
    │   └─→ If not installed: sudo make install
    │
    └─→ Check file contexts: ls -Z /opt/ashchan
        └─→ If wrong: sudo restorecon -Rv /opt/ashchan
```

---

## Key Files

| File | Purpose |
|------|---------|
| `policy/ashchan.te` | Main policy type enforcement rules |
| `policy/ashchan.fc` | File context definitions |
| `policy/ashchan.if` | Reusable interfaces |
| `policy/cloudflared.te` | Cloudflare Tunnel policy |
| `policy/anubis.te` | Anubis PoW firewall policy |
| `policy/varnish.te` | Varnish cache policy |
| `policy/Makefile` | Build automation |
| `scripts/install.sh` | Installation script |
| `scripts/verify.sh` | Verification script |

---

## Related Documentation

| Document | Purpose |
|----------|---------|
| [README.md](README.md) | Overview and quick start |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Security architecture details |
| [INSTALLATION.md](INSTALLATION.md) | Detailed installation guide |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Troubleshooting guide |

---

## Support

For issues:
1. Check [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
2. Run diagnostics: `sudo ausearch -m avc -ts recent | audit2why`
3. Contact security team with denial analysis
