# Ashchan Troubleshooting Guide

Common issues and solutions for running Ashchan with native PHP-CLI.

---

## PHP Environment Issues

### Symptom: Swoole extension not loaded

```
Fatal error: Class 'Swoole\Server' not found
```

**Solution:**

1. **Install Swoole extension:**
   ```bash
   # Ubuntu/Debian
   sudo apt-get install php-swoole

   # Or via PECL
   pecl install swoole
   
   # Enable in php.ini
   echo "extension=swoole.so" | sudo tee /etc/php/8.2/cli/conf.d/20-swoole.ini
   ```

2. **Verify installation:**
   ```bash
   php -m | grep swoole
   php -r "echo Swoole\Constant::VERSION;"
   ```

### Symptom: Missing PHP extensions

```
Class 'PDO' not found
Class 'Redis' not found
```

**Solution:**

```bash
# Install required extensions
sudo apt-get install php-pdo php-pgsql php-redis php-mbstring php-curl php-xml

# Verify
php -m | grep -E 'pdo|pgsql|redis|mbstring|curl'
```

---

## Service Startup Issues

### Symptom: Service won't start

**Check 1: View logs**
```bash
# View service log
cat /tmp/ashchan-gateway.log

# Tail logs for all services
make logs
```

**Check 2: Verify PHP syntax**
```bash
cd services/api-gateway
php -l bin/hyperf.php
```

**Check 3: Check port availability**
```bash
# Check if port is in use
lsof -i :9501

# Kill process using the port (if safe to do so)
# Get PID from lsof output and: kill <PID>
```

**Check 4: Verify .env file exists**
```bash
ls -la services/api-gateway/.env
# If missing, copy from example
cp services/api-gateway/.env.example services/api-gateway/.env
```

### Symptom: Permission denied errors

```
Error: Cannot read /path/to/certs/...
```

**Solution:**

```bash
# Fix certificate permissions
chmod 644 certs/ca/ca.crt
chmod 644 certs/services/*//*.crt
chmod 600 certs/services/*/*.key

# Fix ownership (if needed)
chown -R $(whoami):$(whoami) certs/
```

---

## Database Connection Issues

### Symptom: PostgreSQL connection refused

```
SQLSTATE[08006] Connection refused
```

**Solutions:**

1. **Verify PostgreSQL is running:**
   ```bash
   # Systemd
   sudo systemctl status postgresql
   
   # Check if listening
   pg_isready -h localhost -p 5432
   ```

2. **Check connection parameters in .env:**
   ```bash
   cat services/api-gateway/.env | grep DB_
   # Verify DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
   ```

3. **Test connection manually:**
   ```bash
   psql -h localhost -U ashchan -d ashchan -c "SELECT 1"
   ```

4. **Check pg_hba.conf for local connections:**
   ```bash
   sudo cat /etc/postgresql/16/main/pg_hba.conf | grep -v "^#"
   ```

### Symptom: Redis connection refused

```
RedisException: Connection refused
```

**Solutions:**

1. **Verify Redis is running:**
   ```bash
   sudo systemctl status redis
   redis-cli ping
   ```

2. **Check Redis password in .env:**
   ```bash
   cat services/api-gateway/.env | grep REDIS_
   # Verify REDIS_HOST, REDIS_PORT, REDIS_AUTH (password)
   ```

3. **Test connection manually:**
   ```bash
   redis-cli -h localhost -a "your_password" ping
   ```

---

## mTLS Certificate Issues

### Symptom: Certificate verification failed

```
SSL: certificate verify failed
```

**Solutions:**

1. **Verify certificate chain:**
   ```bash
   openssl verify -CAfile certs/ca/ca.crt certs/services/gateway/gateway.crt
   ```

2. **Check certificate expiration:**
   ```bash
   make mtls-status
   # Or manually:
   openssl x509 -in certs/services/gateway/gateway.crt -noout -dates
   ```

3. **Regenerate certificates:**
   ```bash
   make clean-certs
   make mtls-init
   make mtls-certs
   ```

### Symptom: mTLS handshake fails

```
SSL_ERROR_SYSCALL
SSL routines::tlsv13 alert certificate required
```

**Solutions:**

1. **Verify both client and server certificates:**
   ```bash
   # Test with openssl
   openssl s_client -connect localhost:8443 \
     -cert certs/services/gateway/gateway.crt \
     -key certs/services/gateway/gateway.key \
     -CAfile certs/ca/ca.crt
   ```

2. **Check certificate Common Name (CN):**
   ```bash
   openssl x509 -in certs/services/auth/auth.crt -noout -subject
   ```

3. **Ensure MTLS environment variables are set:**
   ```bash
   cat services/auth-accounts/.env | grep MTLS_
   ```

---

## Service Communication Issues

### Symptom: Service-to-service calls timeout

**Solutions:**

1. **Verify target service is running:**
   ```bash
   make health
   curl -s http://localhost:9502/health
   ```

2. **Check service URL in .env:**
   ```bash
   cat services/api-gateway/.env | grep SERVICE_URL
   ```

3. **Test connection with curl:**
   ```bash
   # HTTP (development)
   curl -v http://localhost:9502/health
   
   # mTLS (production)
   curl --cacert certs/ca/ca.crt \
        --cert certs/services/gateway/gateway.crt \
        --key certs/services/gateway/gateway.key \
        https://localhost:8443/health
   ```

### Symptom: Health check returns 503

**Check service logs for the underlying error:**
```bash
tail -100 /tmp/ashchan-auth.log
```

Common causes:
- Database connection failed
- Redis connection failed
- Dependency service not available

---

## Performance Issues

### Symptom: High memory usage

```bash
# Check PHP process memory
ps aux | grep hyperf

# Check Swoole worker configuration
cat services/api-gateway/config/autoload/server.php | grep worker_num
```

**Solution:** Adjust worker count in `config/autoload/server.php`:
```php
'settings' => [
    'worker_num' => 4, // Reduce for limited memory
    'max_request' => 1000, // Recycle workers after N requests
],
```

### Symptom: Slow response times

**Check 1: Database queries**
```bash
# Enable slow query log in PostgreSQL
# Check pg_stat_statements for slow queries
```

**Check 2: Redis connection pooling**
```bash
# Verify Redis connection pool settings in .env
REDIS_POOL_MAX_CONNECTIONS=10
```

**Check 3: Swoole coroutine settings**
```php
// config/autoload/server.php
'settings' => [
    'enable_coroutine' => true,
    'max_coroutine' => 100000,
],
```

---

## Process Management

### Starting Services

```bash
# Start all services
make up

# Start specific service
make start-gateway
make start-auth
make start-boards
make start-media
make start-search
make start-moderation
```

### Stopping Services

```bash
# Stop all services
make down

# Stop specific service
make stop-gateway
```

### Checking Service Status

```bash
# Quick health check
make health

# Check running processes
ps aux | grep hyperf

# Check PID files
ls -la /tmp/ashchan-pids/
```

---

## Log Files

| Service | Log Location |
|---------|--------------|
| API Gateway | `/tmp/ashchan-gateway.log` |
| Auth | `/tmp/ashchan-auth.log` |
| Boards | `/tmp/ashchan-boards.log` |
| Media | `/tmp/ashchan-media.log` |
| Search | `/tmp/ashchan-search.log` |
| Moderation | `/tmp/ashchan-moderation.log` |

### Viewing Logs

```bash
# All logs combined
make logs

# Specific service
tail -f /tmp/ashchan-gateway.log

# Search for errors
grep -i error /tmp/ashchan-*.log
```

---

## Cleanup and Reset

### Full Reset

```bash
# Stop all services
make down

# Clean runtime artifacts
make clean

# Clean certificates
make clean-certs

# Fresh bootstrap
make bootstrap
```

### Reset Single Service

```bash
# Stop service
make stop-gateway

# Clear runtime cache
rm -rf services/api-gateway/runtime/*

# Start service
make start-gateway
```

---

## Getting Help

If you encounter issues not covered here:

1. **Check service logs:** `make logs`
2. **Check service health:** `make health`
3. **Verify PHP extensions:** `php -m`
4. **Check certificate status:** `make mtls-status`
5. **Review environment variables:** `cat services/<service>/.env`
6. **File an issue:** Include logs, environment details, and steps to reproduce
