#!/bin/bash
# ashchan-deploy-gcp.sh
# Deployment script for Ashchan on Google Cloud Platform (Cloud Shell / Compute Engine)

set -e

echo "=== Ashchan GCP Deployment ==="

# 1. Environment Check
if ! command -v podman &> /dev/null; then
    echo "Installing podman..."
    # sudo apt-get update && sudo apt-get install -y podman
    echo "Please install podman manually."
    exit 1
fi

if ! command -v podman-compose &> /dev/null; then
    echo "Installing podman-compose..."
    # sudo apt-get install -y podman-compose || pip3 install podman-compose
    echo "Please install podman-compose manually."
    exit 1
fi

# 2. Setup mTLS Certificates
echo "--> Initializing mTLS infrastructure..."
chmod +x scripts/mtls/*.sh

if [ ! -f "certs/ca/ca.crt" ]; then
    make mtls-init
else
    echo "CA certificates already exist. Skipping initialization."
fi

if [ ! -f "certs/services/gateway/gateway.crt" ]; then
    make mtls-certs
else
    echo "Service certificates already exist. Skipping generation."
fi

# 3. Environment Configuration
echo "--> Configuring environment..."
make install

# 4. Clean up previous deployments (avoid IPAM conflicts)
echo "--> Cleaning up previous containers..."
podman-compose down -v || true
podman rm -f $(podman ps -aq) || true
podman network prune -f || true

# 5. Build & Start Services
echo "--> Building and starting services..."
make up

# 6. Waiting for Database
echo "--> Waiting for Postgres to be ready..."
until podman exec ashchan-postgres-1 pg_isready -U ashchan; do
    echo "Waiting for postgres..."
    sleep 2
done

# 6. Database Migrations
echo "--> Running Database Migrations..."
make migrate

# 7. Deployment Status
echo "=== Deployment Complete ==="
echo "Services are running:"
podman-compose ps
echo ""
echo "Access the application at:"
echo "http://localhost:9501 (Web Interface)"
echo ""
echo "To expose to the internet via Cloud Shell, click the 'Web Preview' button and select port 9501."
