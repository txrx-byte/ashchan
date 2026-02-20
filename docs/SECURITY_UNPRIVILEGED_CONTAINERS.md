# Unprivileged Container Security Configuration

This document describes the security configuration ensuring all containers in the Ashchan project run as unprivileged (non-root) users, following the principle of least privilege and supporting rootless Podman scenarios.

## Overview

All containers in the Ashchan project are configured to run as unprivileged users to:
- Minimize the attack surface in case of container escape
- Follow security best practices
- Support rootless container runtimes (Podman)
- Comply with Kubernetes Pod Security Standards (restricted)

## Application Services

All PHP application services run as user `appuser` (UID 1000, GID 1000):

| Service | Dockerfile USER | UID:GID |
|---------|----------------|---------|
| api-gateway | appuser | 1000:1000 |
| auth-accounts | appuser | 1000:1000 |
| boards-threads-posts | appuser | 1000:1000 |
| media-uploads | appuser | 1000:1000 |
| search-indexing | appuser | 1000:1000 |
| moderation-anti-spam | appuser | 1000:1000 |

### Implementation
Each service Dockerfile includes:
```dockerfile
RUN addgroup -g 1000 appgroup && \
    adduser -u 1000 -G appgroup -s /bin/sh -D appuser && \
    chown -R appuser:appgroup /app /etc/mtls

USER appuser
```

## Infrastructure Services

Infrastructure services use their official image's default unprivileged users:

| Service | User | UID:GID | Notes |
|---------|------|---------|-------|
| postgres | postgres | 70:70 | Official PostgreSQL Alpine image |
| redis | redis | 999:999 | Official Redis Alpine image |
| minio | minio-user | 1000:1000 | Official MinIO image |

### Implementation
The `podman-compose.yml` file explicitly specifies the `user:` directive for each infrastructure service to ensure they run as unprivileged users even in rootless container scenarios.

## Development Container

The devcontainer configuration uses the `codespace` user (non-root) from the Microsoft universal devcontainer image:

```json
{
  "remoteUser": "codespace",
  "containerUser": "codespace"
}
```

## Rootless Podman

This configuration is fully compatible with rootless Podman:
- All containers run as unprivileged users inside the container
- Podman's user namespace mapping allows containers to run without root privileges on the host
- No `privileged: true` or special capabilities required

## Verification

To verify all containers are running as unprivileged users:

### Using Podman Compose
```bash
# Start services
podman-compose up -d

# Check running user for each container
for container in ashchan-postgres-1 ashchan-redis-1 ashchan-minio-1 \
                ashchan-gateway-1 ashchan-auth-1 ashchan-boards-1 \
                ashchan-media-1 ashchan-search-1 ashchan-moderation-1; do
  echo "=== $container ==="
  podman exec $container ps aux | head -2
done
```

### Using Start Script
```bash
# Start services
./start_ashchan_podman.sh

# Check running user for each container
for container in ashchan-postgres ashchan-redis ashchan-minio \
                ashchan-api-gateway ashchan-auth-accounts ashchan-boards-threads-posts \
                ashchan-media-uploads ashchan-search-indexing ashchan-moderation-anti-spam; do
  echo "=== $container ==="
  podman exec $container ps aux | head -2
done
```

Expected output should show non-root users (postgres, redis, appuser, minio-user) running the main processes, NOT root.

## Security Benefits

1. **Container Escape Mitigation**: If an attacker compromises a container, they have limited privileges
2. **File System Protection**: Unprivileged users can't modify system files or escalate privileges
3. **Kubernetes Compatibility**: Meets Pod Security Standards (restricted policy)
4. **Defense in Depth**: Multiple layers of security (SELinux, seccomp, user namespaces)
5. **Compliance**: Meets industry security standards and best practices

## References

- [Podman Rootless Containers](https://github.com/containers/podman/blob/main/docs/tutorials/rootless_tutorial.md)
- [Docker USER Directive](https://docs.docker.com/engine/reference/builder/#user)
- [Kubernetes Pod Security Standards](https://kubernetes.io/docs/concepts/security/pod-security-standards/)
- [CIS Docker Benchmark](https://www.cisecurity.org/benchmark/docker)
