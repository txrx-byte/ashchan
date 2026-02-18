#!/bin/bash

# This script configures Podman's registries.conf for rootless mode.
# It creates the necessary directory and writes the configuration file.
# Run this script as the user who runs Podman.

CONFIG_DIR="$HOME/.config/containers"
CONFIG_FILE="$CONFIG_DIR/registries.conf"

echo "Creating directory: $CONFIG_DIR"
mkdir -p "$CONFIG_DIR"

if [ $? -ne 0 ]; then
  echo "Error: Failed to create directory $CONFIG_DIR"
  exit 1
fi

echo "Writing corrected configuration to: $CONFIG_FILE"
cat << EOF > "$CONFIG_FILE"
[registries.search]
registries = ["docker.io", "registry.redhat.io", "quay.io"]

[registries.insecure]
registries = []

[registries.block]
registries = []

[registries.mirrors."docker.io"]
mirror = ["https://mirror.gcr.io", "https://registry-1.docker.io"]
EOF

if [ $? -eq 0 ]; then
  echo "Successfully updated $CONFIG_FILE"
  echo ""
  echo "Next steps:"
  echo "1. You can now try to run 'podman pull' commands with the CONTAINERS_CONF variable set:"
  echo "   CONTAINERS_CONF=$HOME/.config/containers/registries.conf podman pull postgres:16-alpine redis:7-alpine minio/minio:latest"
  echo ""
  echo "2. If the pulls are successful, you can then try running your containers using podman-compose:"
  echo "   CONTAINERS_CONF=$HOME/.config/containers/registries.conf podman-compose up -d"
  echo "   (If podman-compose still has issues, you may need to resort to individual 'podman run' commands after pulling images)."
else
  echo "Error: Failed to write to $CONFIG_FILE"
  exit 1
fi

exit 0