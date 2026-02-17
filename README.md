# ashchan

Ashchan is a high-performance, privacy-first imageboard built on Hyperf with a distributed microarchitecture. It is designed for horizontal scalability, strong abuse resistance, and compliance readiness.

## Docs
- [docs/architecture.md](docs/architecture.md)
- [docs/system-design.md](docs/system-design.md)
- [docs/compliance.md](docs/compliance.md)
- [docs/anti-spam.md](docs/anti-spam.md)
- [docs/security.md](docs/security.md)
- [contracts/openapi/README.md](contracts/openapi/README.md)
- [contracts/events/README.md](contracts/events/README.md)
- [db/README.md](db/README.md)
- [k8s/README.md](k8s/README.md)

## Services
- [services/api-gateway/README.md](services/api-gateway/README.md)
- [services/auth-accounts/README.md](services/auth-accounts/README.md)
- [services/boards-threads-posts/README.md](services/boards-threads-posts/README.md)
- [services/media-uploads/README.md](services/media-uploads/README.md)
- [services/search-indexing/README.md](services/search-indexing/README.md)
- [services/moderation-anti-spam/README.md](services/moderation-anti-spam/README.md)

## Local Development
```bash
# Copy env files
for svc in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do
  cp services/$svc/.env.example services/$svc/.env
done

# Start all services
podman-compose up -d

# Check health
curl http://localhost:9501/health
```

## Kubernetes Deployment
```bash
# Dev overlay
kubectl apply -k k8s/overlays/dev

# Check pods
kubectl get pods -n ashchan
```

## Status
Architecture, service scaffolding, contracts, database migrations, Podman Compose, and Kubernetes manifests are complete. Next: implement domain logic, event publishing, and integration tests.
