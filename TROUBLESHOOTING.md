# Ashchan Troubleshooting Guide

Common issues and solutions for running Ashchan with Podman.

---

## DNS Resolution Errors in Containers

### Symptom

You see errors like this in your service logs:

```
RedisException: DNS Lookup resolve failed in /app/vendor/hyperf/redis/src/RedisConnection.php:318
```

Or similar errors for PostgreSQL, other services, or inter-service communication.

### Root Cause

**Podman Compose v2 network configuration issue**: When using `podman-compose` with version `'2.4'`, containers may not automatically connect to a shared network that enables DNS resolution between services.

The issue occurs because:

1. Podman creates containers on isolated networks by default
2. Without an explicit shared network definition, each container cannot resolve hostnames like `redis`, `postgres`, or service names
3. The Hyperf framework tries to connect to `REDIS_HOST=redis` but DNS lookup fails

### Solution

Add an explicit network configuration to `podman-compose.yml`:

```yaml
version: '2.4'

# Add this networks block at the top
networks:
  ashchan:
    driver: bridge
    ipam:
      driver: host-local
      config:
        - subnet: 10.89.0.0/24

services:
  postgres:
    # ... your config ...
    networks:
      - ashchan  # Add this to EVERY service

  redis:
    # ... your config ...
    networks:
      - ashchan  # Add this to EVERY service

  # ... all other services need the networks: - ashchan line ...
```

### Steps to Fix

1. **Stop all running containers:**
   ```bash
   ./ashchan.sh down
   ```

2. **Update `podman-compose.yml`** with the network configuration (see above)

3. **Remove old networks** (if they exist):
   ```bash
   podman network rm ashchan-network ashchan_default 2>/dev/null || true
   ```

4. **Restart services:**
   ```bash
   ./ashchan.sh up
   ```

5. **Verify DNS resolution works:**
   ```bash
   podman exec ashchan-api-gateway ping -c 2 redis
   # Should resolve and ping successfully
   ```

### Verification

After applying the fix, test connectivity:

```bash
# Check all containers are on the same network
podman network inspect ashchan

# Test DNS resolution from inside a container
podman exec ashchan-api-gateway getent hosts redis
podman exec ashchan-api-gateway getent hosts postgres

# Check service health
./ashchan.sh health
```

---

## Other Common Issues

### Container Won't Start

**Check logs:**
```bash
./ashchan.sh logs <service-name>
```

**Common causes:**
- Missing `.env` file - run `./ashchan.sh setup`
- Database not ready - wait for health check to pass
- Port already in use - check with `podman ps`

### Database Connection Errors

**Wait for PostgreSQL to be ready:**
```bash
podman exec ashchan-postgres pg_isready -U ashchan
```

**Check database exists:**
```bash
podman exec -it ashchan-postgres psql -U ashchan -c '\l'
```

### MinIO Connection Issues

**Check MinIO is healthy:**
```bash
curl http://localhost:9000/minio/health/live
```

**Access MinIO Console:**
```
http://localhost:9001
Username: ashchan
Password: ashchan123
```

### Permission Denied on Volumes

**Fix volume permissions:**
```bash
podman unshare chown -R 1000:1000 ~/.local/share/containers/storage/volumes/
```

---

## Management Commands

Use the `./ashchan.sh` script for common operations:

| Command | Description |
|---------|-------------|
| `./ashchan.sh setup` | Initial setup (creates .env files, starts services) |
| `./ashchan.sh up` | Start all services |
| `./ashchan.sh down` | Stop and remove everything (containers, networks, volumes) |
| `./ashchan.sh stop` | Stop containers (keeps data) |
| `./ashchan.sh restart` | Restart all containers |
| `./ashchan.sh status` | Show container status |
| `./ashchan.sh logs [svc]` | Tail logs (optionally for specific service) |
| `./ashchan.sh health` | Check health of all services |
| `./ashchan.sh rebuild [svc]` | Rebuild service images |
| `./ashchan.sh migrate` | Run database migrations |
| `./ashchan.sh clean` | Remove everything including build cache |
| `./ashchan.sh help` | Show all commands |

---

## Getting Help

If you encounter issues not covered here:

1. Check service logs: `./ashchan.sh logs`
2. Verify container status: `./ashchan.sh status`
3. Check network configuration: `./ashchan.sh network`
4. Review Podman logs: `podman logs <container-name>`
