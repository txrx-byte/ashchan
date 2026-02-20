#!/bin/sh

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

# start-service.sh - Unified entrypoint for all Ashchan services
#
# Runs inside the ashchan-runtime container.  Receives a short service
# alias and maps it to the actual service directory + Hyperf port.
#
# Usage:
#   /app/scripts/start-service.sh <service-alias>
#
# Aliases:
#   gateway     -> services/api-gateway       (port 9501)
#   auth        -> services/auth-accounts     (port 9502)
#   boards      -> services/boards-threads-posts (port 9503)
#   media       -> services/media-uploads     (port 9504)
#   search      -> services/search-indexing   (port 9505)
#   moderation  -> services/moderation-anti-spam (port 9506)

set -eu

SERVICE_ALIAS="${1:-}"

if [ -z "$SERVICE_ALIAS" ]; then
    echo "ERROR: No service alias provided."
    echo "Usage: $0 <gateway|auth|boards|media|search|moderation>"
    exit 1
fi

# Map alias -> directory name
case "$SERVICE_ALIAS" in
    gateway)
        SERVICE_DIR="api-gateway"
        DEFAULT_PORT=9501
        ;;
    auth)
        SERVICE_DIR="auth-accounts"
        DEFAULT_PORT=9502
        ;;
    boards)
        SERVICE_DIR="boards-threads-posts"
        DEFAULT_PORT=9503
        ;;
    media)
        SERVICE_DIR="media-uploads"
        DEFAULT_PORT=9504
        ;;
    search)
        SERVICE_DIR="search-indexing"
        DEFAULT_PORT=9505
        ;;
    moderation)
        SERVICE_DIR="moderation-anti-spam"
        DEFAULT_PORT=9506
        ;;
    *)
        echo "ERROR: Unknown service alias '${SERVICE_ALIAS}'."
        echo "Valid aliases: gateway, auth, boards, media, search, moderation"
        exit 1
        ;;
esac

APP_PATH="/app/services/${SERVICE_DIR}"
HYPERF_ENTRY="${APP_PATH}/bin/hyperf.php"

if [ ! -d "$APP_PATH" ]; then
    echo "ERROR: Service directory not found: ${APP_PATH}"
    exit 1
fi

if [ ! -f "$HYPERF_ENTRY" ]; then
    echo "ERROR: Hyperf entry point not found: ${HYPERF_ENTRY}"
    exit 1
fi

echo "──────────────────────────────────────────────────"
echo "  Ashchan ServiceMesh - ${SERVICE_ALIAS}"
echo "  Directory : ${APP_PATH}"
echo "  Binary    : $(which swoole-cli 2>/dev/null || echo '/usr/local/bin/swoole-cli')"
echo "  Port      : ${DEFAULT_PORT}"
echo "  PID       : $$"
echo "──────────────────────────────────────────────────"

# Use exec so swoole-cli becomes PID 1 (or direct child of tini)
# and receives signals correctly.
exec /usr/local/bin/swoole-cli "${HYPERF_ENTRY}" start
