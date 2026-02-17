# Kubernetes Manifests

Manifests use Kustomize for base + overlays (dev, staging, prod).

## Usage
```bash
kubectl apply -k k8s/overlays/dev
```
