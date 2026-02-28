# Ashchan SELinux Troubleshooting Guide

**Version:** 1.0.0  
**Date:** 28 February 2026

---

## Quick Troubleshooting

### Something's Not Working - Start Here

```bash
# 1. Check for SELinux denials
sudo ausearch -m avc -ts recent

# 2. Get explanation for denials
sudo ausearch -m avc -ts recent | audit2why

# 3. If denials are found, temporarily switch to permissive
sudo setenforce 0

# 4. Test your application

# 5. Generate a local policy to fix denials
sudo ausearch -m avc -ts recent | audit2allow -M ashchan_local

# 6. Install the local policy
sudo semodule -i ashchan_local.pp

# 7. Switch back to enforcing
sudo setenforce 1
```

---

## Table of Contents

1. [Common Issues](#common-issues)
2. [AVC Denial Analysis](#avc-denial-analysis)
3. [Service Won't Start](#service-wont-start)
4. [Database Connection Failed](#database-connection-failed)
5. [Certificate Access Denied](#certificate-access-denied)
6. [Port Binding Failed](#port-binding-failed)
7. [Network Connectivity Issues](#network-connectivity-issues)
8. [Emergency Procedures](#emergency-procedures)

---

## Common Issues

### Issue 1: Service Won't Start

**Symptoms:**
```
systemctl start ashchan-gateway
Job for ashchan-gateway.service failed because the control process exited with error code.
```

**Diagnosis:**
```bash
# Check journal for errors
journalctl -u ashchan-gateway -n 50

# Check for AVC denials
sudo ausearch -m avc -ts recent | grep ashchan
```

**Common Causes:**

| Cause | Solution |
|-------|----------|
| Port not labeled | `sudo semanage port -a -t ashchan_port_t -p tcp 9501` |
| File context wrong | `sudo restorecon -Rv /opt/ashchan` |
| Boolean disabled | `sudo setsebool -P ashchan_use_pcntl 1` |
| Policy not installed | `sudo make install` (in policy directory) |

---

### Issue 2: Database Connection Failed

**Symptoms:**
```
PDOException: SQLSTATE[08006] [7] connection to server at "localhost" (127.0.0.1), port 5432 failed
```

**AVC Denial:**
```
type=AVC msg=audit(...): avc: denied { name_connect } for pid=12345 comm="php" 
dest=5432 scontext=system_u:system_r:ashchan_gateway_t:s0 
tcontext=system_u:object_r:postgresql_port_t:s0 tclass=tcp_socket
```

**Solution:**
```bash
# Enable PostgreSQL boolean
sudo setsebool -P ashchan_connect_postgresql 1

# Verify
getsebool ashchan_connect_postgresql
# Should show: ashchan_connect_postgresql --> on

# Restart service
sudo systemctl restart ashchan-gateway
```

---

### Issue 3: Redis Connection Failed

**Symptoms:**
```
RedisException: Connection refused
```

**AVC Denial:**
```
type=AVC msg=audit(...): avc: denied { name_connect } for pid=12345 comm="php" 
dest=6379 scontext=system_u:system_r:ashchan_gateway_t:s0 
tcontext=system_u:object_r:redis_port_t:s0 tclass=tcp_socket
```

**Solution:**
```bash
# Enable Redis boolean
sudo setsebool -P ashchan_connect_redis 1

# Restart service
sudo systemctl restart ashchan-gateway
```

---

### Issue 4: Certificate Access Denied

**Symptoms:**
```
RuntimeException: Failed to load private key /opt/ashchan/certs/services/gateway/gateway.key
```

**AVC Denial:**
```
type=AVC msg=audit(...): avc: denied { read } for pid=12345 comm="php" 
name="gateway.key" scontext=system_u:system_r:ashchan_gateway_t:s0 
tcontext=system_u:object_r:cert_t:s0 tclass=file
```

**Solution:**
```bash
# Check current file context
ls -Z /opt/ashchan/certs/services/gateway/gateway.key

# Apply correct context
sudo semanage fcontext -a -t ashchan_service_key_t \
    "/opt/ashchan/certs/services/gateway/gateway\.key"
sudo restorecon -v /opt/ashchan/certs/services/gateway/gateway.key

# Verify
ls -Z /opt/ashchan/certs/services/gateway/gateway.key
# Should show: ... ashchan_service_key_t

# Restart service
sudo systemctl restart ashchan-gateway
```

---

### Issue 5: Port Binding Failed

**Symptoms:**
```
RuntimeException: Failed to bind to port 9501
```

**AVC Denial:**
```
type=AVC msg=audit(...): avc: denied { name_bind } for pid=12345 comm="php" 
src=9501 scontext=system_u:system_r:ashchan_gateway_t:s0 
tcontext=system_u:object_r:unreserved_port_t:s0 tclass=tcp_socket
```

**Solution:**
```bash
# Add port to ashchan_port_t type
sudo semanage port -a -t ashchan_port_t -p tcp 9501

# Verify
semanage port -l | grep ashchan_port_t

# Restart service
sudo systemctl restart ashchan-gateway
```

---

### Issue 6: Log File Write Failed

**Symptoms:**
```
RuntimeException: Failed to write to log file /opt/ashchan/runtime/logs/gateway.log
```

**AVC Denial:**
```
type=AVC msg=audit(...): avc: denied { write } for pid=12345 comm="php" 
name="gateway.log" scontext=system_u:system_r:ashchan_gateway_t:s0 
tcontext=system_u:object_r:tmp_t:s0 tclass=file
```

**Solution:**
```bash
# Apply runtime context
sudo semanage fcontext -a -t ashchan_log_t \
    "/opt/ashchan/runtime/logs(/.*)?"
sudo restorecon -Rv /opt/ashchan/runtime/logs

# Verify
ls -Z /opt/ashchan/runtime/logs
# Should show: ... ashchan_log_t

# Restart service
sudo systemctl restart ashchan-gateway
```

---

### Issue 7: Process Signaling Failed (Swoole)

**Symptoms:**
```
Swoole\ExitException: Swoole exited with code 0
```

**AVC Denial:**
```
type=AVC msg=audit(...): avc: denied { signal } for pid=12345 comm="php" 
scontext=system_u:system_r:ashchan_gateway_t:s0 
tcontext=system_u:system_r:ashchan_gateway_t:s0 tclass=process
```

**Solution:**
```bash
# Enable pcntl boolean
sudo setsebool -P ashchan_use_pcntl 1

# Restart service
sudo systemctl restart ashchan-gateway
```

---

## AVC Denial Analysis

### Understanding AVC Denials

AVC (Access Vector Cache) denials are logged when SELinux blocks an action. Here's how to read them:

```
type=AVC msg=audit(1709164800.000:1001): 
  avc: denied { read }                    # Action denied
  for pid=12345 comm="php"                # Process info
  name="gateway.key"                      # Target file/object
  scontext=system_u:system_r:ashchan_gateway_t:s0  # Source context (process)
  tcontext=system_u:object_r:cert_t:s0    # Target context (file)
  tclass=file                             # Object class
  permissive=0                            # 0=enforcing, 1=permissive
```

### Analysis Commands

```bash
# View recent denials
sudo ausearch -m avc -ts recent

# View denials with explanation
sudo ausearch -m avc -ts recent | audit2why

# Generate policy to fix denials
sudo ausearch -m avc -ts recent | audit2allow -M ashchan_fix

# Install the fix
sudo semodule -i ashchan_fix.pp
```

### audit2why Output

```
#============= ashchan_gateway_t ==============

#!!!! This rule can be set with:
#!!!! setsebool -P ashchan_connect_postgresql 1
allow ashchan_gateway_t postgresql_port_t:tcp_socket name_connect;

#!!!! This rule can be set with:
#!!!! semanage port -a -t ashchan_port_t -p tcp 9501
allow ashchan_gateway_t ashchan_port_t:tcp_socket name_bind;
```

---

## Service Won't Start

### Diagnostic Checklist

```bash
# 1. Check service status
sudo systemctl status ashchan-gateway

# 2. Check journal logs
journalctl -u ashchan-gateway -n 100 --no-pager

# 3. Check for AVC denials
sudo ausearch -m avc -ts recent | grep ashchan

# 4. Verify policy is installed
semodule -l | grep ashchan

# 5. Verify file contexts
ls -Z /opt/ashchan

# 6. Verify port contexts
sudo semanage port -l | grep ashchan

# 7. Verify booleans
getsebool -a | grep ashchan
```

### Common Startup Failures

| Error | Cause | Solution |
|-------|-------|----------|
| `Address already in use` | Port not labeled | `sudo semanage port -a -t ashchan_port_t -p tcp 9501` |
| `Permission denied` | File context wrong | `sudo restorecon -Rv /opt/ashchan` |
| `Connection refused` | Boolean disabled | `sudo setsebool -P ashchan_connect_postgresql 1` |
| `Failed to load certificate` | Cert context wrong | `sudo restorecon -Rv /opt/ashchan/certs` |

---

## Database Connection Failed

### PostgreSQL

```bash
# Check if boolean is enabled
getsebool ashchan_connect_postgresql

# Enable if needed
sudo setsebool -P ashchan_connect_postgresql 1

# Verify PostgreSQL port is labeled
sudo semanage port -l | grep postgresql_port_t

# Test connection
sudo -u ashchan php -r "new PDO('pgsql:host=localhost;port=5432;dbname=ashchan');"
```

### Redis

```bash
# Check if boolean is enabled
getsebool ashchan_connect_redis

# Enable if needed
sudo setsebool -P ashchan_connect_redis 1

# Test connection
sudo -u ashchan php -r "new Redis(); Redis->connect('localhost', 6379);"
```

---

## Certificate Access Denied

### Check File Contexts

```bash
# List certificate directory
ls -laZ /opt/ashchan/certs/

# Check CA key (should be ashchan_ca_key_t)
ls -Z /opt/ashchan/certs/ca/ca.key

# Check service keys (should be ashchan_service_key_t)
ls -Z /opt/ashchan/certs/services/*/
```

### Fix File Contexts

```bash
# Apply contexts to CA
sudo semanage fcontext -a -t ashchan_ca_key_t \
    "/opt/ashchan/certs/ca/ca\.key"
sudo restorecon -v /opt/ashchan/certs/ca/ca.key

# Apply contexts to service keys
sudo semanage fcontext -a -t ashchan_service_key_t \
    "/opt/ashchan/certs/services/[^/]+/[^/]+\.key"
sudo restorecon -Rv /opt/ashchan/certs/services/
```

---

## Port Binding Failed

### Check Port Contexts

```bash
# List all Ashchan ports
sudo semanage port -l | grep ashchan

# Check specific port
sudo semanage port -l | grep 9501
```

### Add Missing Port

```bash
# HTTP ports
sudo semanage port -a -t ashchan_port_t -p tcp 9501
sudo semanage port -a -t ashchan_port_t -p tcp 9502
sudo semanage port -a -t ashchan_port_t -p tcp 9503
sudo semanage port -a -t ashchan_port_t -p tcp 9504
sudo semanage port -a -t ashchan_port_t -p tcp 9505
sudo semanage port -a -t ashchan_port_t -p tcp 9506

# mTLS ports
sudo semanage port -a -t ashchan_mtls_port_t -p tcp 8443
sudo semanage port -a -t ashchan_mtls_port_t -p tcp 8444
sudo semanage port -a -t ashchan_mtls_port_t -p tcp 8445
sudo semanage port -a -t ashchan_mtls_port_t -p tcp 8446
sudo semanage port -a -t ashchan_mtls_port_t -p tcp 8447
sudo semanage port -a -t ashchan_mtls_port_t -p tcp 8448
```

---

## Network Connectivity Issues

### Service-to-Service Communication

```bash
# Check if gateway can reach backend services
curl http://localhost:9502/health
curl http://localhost:9503/health

# Check mTLS communication
curl --cacert /opt/ashchan/certs/ca/ca.crt \
     --cert /opt/ashchan/certs/services/gateway/gateway.crt \
     --key /opt/ashchan/certs/services/gateway/gateway.key \
     https://localhost:8443/health
```

### External Network Access

```bash
# Check if external network boolean is enabled
getsebool ashchan_external_network

# Enable if needed (for Cloudflare Tunnel)
sudo setsebool -P ashchan_external_network 1
```

---

## Emergency Procedures

### Immediate Permissive Mode

If services are failing and you need immediate relief:

```bash
# Switch SELinux to permissive mode (immediate)
sudo setenforce 0

# Verify
getenforce  # Should return: Permissive

# Restart services
sudo systemctl restart ashchan-gateway
sudo systemctl restart ashchan-auth
# ... restart all services
```

**Warning:** Permissive mode logs but does not enforce. This is a temporary measure for troubleshooting.

### Complete Rollback

To completely remove Ashchan SELinux policies:

```bash
# Remove policy modules
sudo semodule -r ashchan
sudo semodule -r cloudflared
sudo semodule -r anubis
sudo semodule -r varnish

# Remove file context rules
sudo semanage fcontext -d "/opt/ashchan(/.*)?"
sudo semanage fcontext -d "/opt/ashchan/services/[^/]+(/.*)?"
sudo semanage fcontext -d "/opt/ashchan/runtime(/.*)?"
sudo semanage fcontext -d "/opt/ashchan/certs(/.*)?"
sudo semanage fcontext -d "/tmp/ashchan(/.*)?"

# Reset file contexts
sudo restorecon -Rv /opt/ashchan

# Remove port rules
sudo semanage port -d -t ashchan_port_t -p tcp 9501
sudo semanage port -d -t ashchan_port_t -p tcp 9502
sudo semanage port -d -t ashchan_port_t -p tcp 9503
sudo semanage port -d -t ashchan_port_t -p tcp 9504
sudo semanage port -d -t ashchan_port_t -p tcp 9505
sudo semanage port -d -t ashchan_port_t -p tcp 9506
sudo semanage port -d -t ashchan_mtls_port_t -p tcp 8443
sudo semanage port -d -t ashchan_mtls_port_t -p tcp 8444
sudo semanage port -d -t ashchan_mtls_port_t -p tcp 8445
sudo semanage port -d -t ashchan_mtls_port_t -p tcp 8446
sudo semanage port -d -t ashchan_mtls_port_t -p tcp 8447
sudo semanage port -d -t ashchan_mtls_port_t -p tcp 8448

# Reset booleans
sudo setsebool -P ashchan_external_network 0
sudo setsebool -P ashchan_connect_postgresql 0
sudo setsebool -P ashchan_connect_redis 0
# ... reset all ashchan booleans
```

### Restore from Backup

```bash
# If you backed up SELinux configuration
sudo semodule -b backup-$(date +%Y%m%d).pp

# Or restore specific backup
sudo semodule -i /path/to/backup/ashchan.pp
```

---

## Diagnostic Commands Reference

### Quick Diagnostics

```bash
# Check SELinux status
getenforce
sestatus

# Check policy modules
semodule -l | grep -E "ashchan|cloudflared|anubis|varnish"

# Check file contexts
ls -Z /opt/ashchan

# Check port contexts
sudo semanage port -l | grep ashchan

# Check booleans
getsebool -a | grep ashchan

# Check recent denials
sudo ausearch -m avc -ts recent
```

### Detailed Diagnostics

```bash
# Detailed SELinux status
sestatus -v

# All file contexts for Ashchan
sudo semanage fcontext -l | grep ashchan

# All port contexts
sudo semanage port -l | grep -E "ashchan|anubis|varnish"

# All booleans with values
getsebool -a | grep -E "ashchan|cloudflared|anubis"

# Denials with analysis
sudo ausearch -m avc -ts today | audit2why

# Generate fix policy
sudo ausearch -m avc -ts today | audit2allow -M ashchan_local
```

---

## Getting Help

### Resources

1. [SELinux Documentation](https://selinuxproject.org/)
2. [Red Hat SELinux Guide](https://access.redhat.com/documentation/en-us/red_hat_enterprise_linux/8/html/security_hardening/using-selinux)
3. [Arch Wiki SELinux](https://wiki.archlinux.org/title/SELinux)

### Ashchan-Specific

- Review [README.md](README.md) for installation
- Check [QUICKREF.md](QUICKREF.md) for quick reference
- See [ARCHITECTURE.md](ARCHITECTURE.md) for security design

### Contact

For Ashchan-specific SELinux issues, contact the security team with:

1. Output of `sudo ausearch -m avc -ts recent | audit2why`
2. Service logs: `journalctl -u ashchan-* -n 100`
3. SELinux status: `sestatus`
