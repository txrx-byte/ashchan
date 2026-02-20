# Podman Compose Networking Guide

## Overview

This document explains the networking configuration for ashchan's local development environment using Podman Compose.

## Decision: DNS Names Over Static IPs

**We use DNS-based service discovery (container names) instead of static IP addresses.**

### Why DNS Names?

1. **Podman Compose handles DNS automatically** - Containers on the same bridge network can resolve each other by service name
2. **No IP conflicts** - Static IPs can cause allocation errors when containers are recreated
3. **Simpler configuration** - No need to manage IPAM subnets or track IP assignments
4. **Standard practice** - This is how podman-compose is designed to work

### What Went Wrong with Static IPs

Attempting to use static IPs caused the following issues:

```
Error: unable to start container: IPAM error: requested ip address X.X.X.X 
is already allocated to container ID YYY
```

**Root cause:** Podman's IPAM (IP Address Management) retains IP allocations for "Created" state containers. When containers are stopped but not fully removed, their IPs remain reserved, preventing new containers from claiming those addresses.

**Workaround attempts that failed:**
- Changing subnet (10.89.10.0/24 → 10.90.0.0/24) - Same issue persisted
- Stopping containers - IPs still held in "Created" state
- Full compose down --volumes - Required removing all containers manually

### The DNS Solution

Services communicate using container names as hostnames:

| Service | Hostname | Port |
|---------|----------|------|
| PostgreSQL | `postgres` | 5432 |
| Redis | `redis` | 6379 |
| MinIO | `minio` | 9000 |
| API Gateway | `api-gateway` | 9501 |
| Auth Service | `auth-accounts` | 9502 |
| Boards Service | `boards-threads-posts` | 9503 |
| Media Service | `media-uploads` | 9504 |
| Search Service | `search-indexing` | 9505 |
| Moderation Service | `moderation-anti-spam` | 9506 |

## Configuration

### podman-compose.yml

The network configuration is simple - just a bridge network:

```yaml
networks:
  ashchan:
    driver: bridge
```

Each service joins the network without specifying an IP:

```yaml
services:
  postgres:
    networks:
      - ashchan
```

### Service Environment Variables

Services reference each other by name in their `.env` files:

```bash
# Database
DB_HOST=postgres
DB_DSN=pgsql:host=postgres;port=5432;dbname=ashchan

# Redis
REDIS_HOST=redis

# MinIO
OBJECT_STORAGE_ENDPOINT=http://minio:9000

# Inter-service communication
AUTH_SERVICE_URL=http://auth-accounts:9502
BOARDS_SERVICE_URL=http://boards-threads-posts:9503
MEDIA_SERVICE_URL=http://media-uploads:9504
SEARCH_SERVICE_URL=http://search-indexing:9505
MODERATION_SERVICE_URL=http://moderation-anti-spam:9506
```

## Troubleshooting

### Container won't start - IPAM error

If you see errors like:
```
IPAM error: requested ip address X.X.X.X is already allocated
```

**Solution:** Remove all containers and recreate:

```bash
cd /home/abrookstgz/ashchan
podman-compose down --remove-orphans --volumes
podman-compose up -d --build
```

### Services can't connect to database

Check that containers are on the same network:

```bash
podman network inspect ashchan_ashchan
```

Verify DNS resolution from inside a container:

```bash
podman exec ashchan_api-gateway_1 ping -c 3 postgres
```

### Check container status

```bash
# All containers
podman ps -a

# Only running
podman ps

# With ports
podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

### View network details

```bash
# List networks
podman network ls

# Inspect network
podman network inspect ashchan_ashchan
```

## Quick Reference

### Start all services
```bash
podman-compose up -d
```

### Stop all services
```bash
podman-compose down
```

### View logs
```bash
podman-compose logs -f
podman-compose logs -f api-gateway
```

### Health check
```bash
curl http://localhost:9501/health
```

## Network Topology

```
                    ┌─────────────────┐
                    │   Host Network  │
                    │   127.0.0.1     │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │   Port Mapping  │
                    │ 9501-9506, etc. │
                    └────────┬────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
        ▼                    ▼                    ▼
┌───────────────┐  ┌─────────────────┐  ┌───────────────┐
│ api-gateway   │  │ auth-accounts   │  │ postgres      │
│ :9501         │  │ :9502           │  │ :5432         │
└───────┬───────┘  └────────┬────────┘  └───────┬───────┘
        │                   │                   │
        └───────────────────┼───────────────────┘
                            │
                    ┌───────▼────────┐
                    │  ashchan       │
                    │  bridge network│
                    │  (DNS enabled) │
                    └────────────────┘
```

All containers can reach each other by service name on the internal bridge network.
