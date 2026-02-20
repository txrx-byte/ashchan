#!/bin/bash

# Copyright 2026 txrx-byte
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.


# This script starts the Ashchan microservices using direct Podman commands.
# It creates volumes, pulls/builds images, and runs containers.
# Ensure your registries.conf is correctly set up as per previous steps.
# Run this script as the user who runs Podman.

# Set CONTAINERS_CONF environment variable for Podman
export CONTAINERS_CONF="$HOME/.config/containers/registries.conf"

echo "Starting Ashchan services using Podman..."

# --- Cleanup stale containers ---
echo "Cleaning up any existing containers..."
for name in ashchan-postgres ashchan-redis ashchan-minio ashchan-api-gateway ashchan-auth-accounts ashchan-boards-threads-posts ashchan-media-uploads ashchan-search-indexing ashchan-moderation-anti-spam; do
  podman rm -f "$name" 2>/dev/null || true
done

# --- Create Podman Network ---
echo "Creating Podman network: ashchan-network..."
podman network create ashchan-network || true

# --- Create Volumes (ignore if already exists) ---
echo "Creating Podman volumes..."
podman volume create postgres_data || true
podman volume create redis_data || true
podman volume create minio_data || true

# --- Start Infrastructure Services (Postgres, Redis, Minio) ---

# Postgres
echo "Starting Postgres container..."
podman run -d --name ashchan-postgres \
  --network ashchan-network \
  -e POSTGRES_USER=ashchan \
  -e POSTGRES_PASSWORD=ashchan \
  -e POSTGRES_DB=ashchan \
  -p 5432:5432 \
  -v postgres_data:/var/lib/postgresql/data \
  --health-cmd "pg_isready -U ashchan" \
  --health-interval 5s \
  --health-timeout 5s \
  --health-retries 5 \
  postgres:16-alpine

# Wait for Postgres to be ready (more generous sleep as --health is not supported)
echo "Waiting for Postgres to become ready (60 seconds)..."
sleep 60

# Redis
echo "Starting Redis container..."
podman run -d --name ashchan-redis \
  --network ashchan-network \
  -p 6379:6379 \
  -v redis_data:/data \
  --health-cmd "redis-cli ping" \
  --health-interval 5s \
  --health-timeout 3s \
  --health-retries 5 \
  redis:7-alpine

# Wait for Redis to be ready (30 seconds)
echo "Waiting for Redis to become ready (30 seconds)..."
sleep 30

# Minio
echo "Starting Minio container..."
podman run -d --name ashchan-minio \
  --network ashchan-network \
  -e MINIO_ROOT_USER=ashchan \
  -e MINIO_ROOT_PASSWORD=ashchan123 \
  -p 9000:9000 -p 9001:9001 \
  -v minio_data:/data \
  --health-cmd "curl -f http://ashchan-minio:9000/minio/health/live" \
  --health-interval 10s \
  --health-timeout 5s \
  --health-retries 3 \
  minio/minio:latest \
  server /data --console-address ":9001"

# Wait for Minio to be ready (30 seconds)
echo "Waiting for Minio to become ready (30 seconds)..."
sleep 30

# --- Build and Start Microservices ---

SERVICES=(
  "api-gateway:9501:./services/api-gateway:$PWD/frontend/static:/app/frontend/static:ro"
  "auth-accounts:9502:./services/auth-accounts"
  "boards-threads-posts:9503:./services/boards-threads-posts"
  "media-uploads:9504:./services/media-uploads"
  "search-indexing:9505:./services/search-indexing"
  "moderation-anti-spam:9506:./services/moderation-anti-spam"
)

for service_info in "${SERVICES[@]}"; do
  IFS=':' read -r name port_map context_path volume_src volume_dest volume_mode <<< "$service_info"

  service_name="ashchan-${name}"
  echo "Processing service: ${service_name}"

  # Build the image
  echo "Building image for ${service_name}..."
  podman build -t "localhost/${service_name}" "${context_path}"

  if [ $? -ne 0 ]; then
    echo "Error building image for ${service_name}. Exiting."
    exit 1
  fi

  # Get environment variables from .env file
  ENV_ARGS=""
  if [ -f "${context_path}/.env" ]; then
    echo "Loading environment variables from ${context_path}/.env"
    while IFS='= ' read -r key value; do
      if [[ ! -z "$key" && ! "$key" =~ ^# ]]; then # Skip empty lines and comments
        ENV_ARGS+=" -e ${key}=${value}"
      fi
    done < "${context_path}/.env"
  fi

  # Construct volume arguments
  VOLUME_ARGS=""
  if [[ ! -z "$volume_src" && ! -z "$volume_dest" ]]; then
    VOLUME_ARGS+=" -v ${volume_src}:${volume_dest}:${volume_mode}"
  fi

  # Run the container
  echo "Starting container ${service_name}..."
  # Note: Modified port mapping for api-gateway
  if [ "$name" == "api-gateway" ]; then
    podman run -d --name "${service_name}" \
      --network ashchan-network \
      -p 9501:9501 \
      ${ENV_ARGS} \
      ${VOLUME_ARGS} \
      --restart=unless-stopped \
      "localhost/${service_name}"
  else
    podman run -d --name "${service_name}" \
      --network ashchan-network \
      -p "${port_map}:${port_map}" \
      ${ENV_ARGS} \
      ${VOLUME_ARGS} \
      --restart=unless-stopped \
      "localhost/${service_name}"
  fi

  if [ $? -ne 0 ]; then
    echo "Error starting container ${service_name}. Exiting."
    exit 1
  fi

  echo "Container ${service_name} started."
  sleep 5 # Small delay before next service
done

echo "All services initiated. Check 'podman ps' for status."
