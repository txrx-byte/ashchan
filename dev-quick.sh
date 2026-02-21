#!/usr/bin/env bash

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

#
# Ashchan Quick Development Script
# 
# Lightweight version of bootstrap.sh for rapid development iterations.
# Assumes mTLS certs and .env files are already configured.
#
# Usage: ./dev-quick.sh
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
NC='\033[0m'

info() { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
step() { echo -e "${CYAN}[STEP]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

echo
echo "🚀 Ashchan Quick Development Restart"
echo "====================================="

# Step 1: Stop existing services
step "1/3 Stopping existing services..."
make down 2>/dev/null || true
success "Services stopped"

# Step 2: Start services
step "2/3 Starting services..."
make up
success "Services started"

# Step 3: Quick health check
step "3/3 Checking health..."
sleep 3

HEALTHY=0
TOTAL=6

for pair in gateway:9501 auth:9502 boards:9503 media:9504 search:9505 moderation:9506; do
    svc=${pair%%:*}
    PORT=${pair##*:}
    if curl -s --max-time 2 "http://localhost:$PORT/health" > /dev/null 2>&1; then
        ((HEALTHY++))
    fi
done

echo
if [ $HEALTHY -eq $TOTAL ]; then
    success "✓ All services healthy ($HEALTHY/$TOTAL)"
else
    warn "⚠ Some services may still be starting ($HEALTHY/$TOTAL healthy)"
fi

echo
success "🎯 Quick restart complete!"
echo "====================================="
echo "API Gateway: http://localhost:9501"
echo "Logs:        make logs"
echo "Health:      make health"
echo
