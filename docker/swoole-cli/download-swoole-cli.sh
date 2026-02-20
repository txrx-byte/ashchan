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

# download-swoole-cli.sh
#
# Downloads a pre-built swoole-cli binary for local development/testing.
# For production, use the Dockerfile.build or GitHub Actions workflow
# to build from source with the exact extensions you need.
#
# Usage:
#   ./docker/swoole-cli/download-swoole-cli.sh [version] [arch]
#
# Examples:
#   ./docker/swoole-cli/download-swoole-cli.sh          # latest, auto-detect arch
#   ./docker/swoole-cli/download-swoole-cli.sh v6.0.0 x86_64

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VERSION="${1:-v6.0.0}"
ARCH="${2:-$(uname -m)}"
OUTPUT="${SCRIPT_DIR}/swoole-cli"

# Normalise architecture names
case "$ARCH" in
    x86_64|amd64)  ARCH="x86_64" ;;
    aarch64|arm64) ARCH="aarch64" ;;
    *)
        echo "ERROR: Unsupported architecture: ${ARCH}"
        echo "Supported: x86_64, aarch64"
        exit 1
        ;;
esac

echo "──────────────────────────────────────────────────"
echo "  swoole-cli Downloader"
echo "  Version : ${VERSION}"
echo "  Arch    : ${ARCH}"
echo "  Output  : ${OUTPUT}"
echo "──────────────────────────────────────────────────"

# GitHub release URL pattern
# swoole-cli releases binaries as:
#   swoole-cli-v6.0.0-linux-x86_64.tar.xz
#   swoole-cli-v6.0.0-linux-aarch64.tar.xz
BASE_URL="https://github.com/swoole/swoole-cli/releases/download/${VERSION}"
FILENAME="swoole-cli-${VERSION}-linux-${ARCH}.tar.xz"
URL="${BASE_URL}/${FILENAME}"

echo ""
echo "Downloading from: ${URL}"
echo ""

TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

if command -v curl &>/dev/null; then
    curl -fSL -o "${TMPDIR}/${FILENAME}" "${URL}" || {
        echo ""
        echo "Download failed. The release URL may have changed."
        echo "Check releases at: https://github.com/swoole/swoole-cli/releases"
        echo ""
        echo "Alternative: build from source using:"
        echo "  docker build -f docker/swoole-cli/Dockerfile.build -t swoole-builder docker/swoole-cli/"
        echo "  docker create --name tmp swoole-builder"
        echo "  docker cp tmp:/output/swoole-cli docker/swoole-cli/swoole-cli"
        echo "  docker rm tmp"
        exit 1
    }
elif command -v wget &>/dev/null; then
    wget -O "${TMPDIR}/${FILENAME}" "${URL}" || {
        echo "Download failed. See above for alternatives."
        exit 1
    }
else
    echo "ERROR: Neither curl nor wget found. Install one and retry."
    exit 1
fi

echo "Extracting..."
cd "$TMPDIR"

if [[ "$FILENAME" == *.tar.xz ]]; then
    tar -xJf "$FILENAME"
elif [[ "$FILENAME" == *.tar.gz ]]; then
    tar -xzf "$FILENAME"
fi

# Find the swoole-cli binary in the extracted contents
BINARY=$(find . -name 'swoole-cli' -type f | head -1)

if [ -z "$BINARY" ]; then
    echo "ERROR: swoole-cli binary not found in archive."
    echo "Archive contents:"
    ls -laR .
    exit 1
fi

cp "$BINARY" "$OUTPUT"
chmod +x "$OUTPUT"

echo ""
echo "✓ swoole-cli downloaded to: ${OUTPUT}"
echo ""

# Verify
echo "Binary info:"
file "$OUTPUT"
ls -lh "$OUTPUT"
echo ""

if "$OUTPUT" --version 2>/dev/null; then
    echo ""
    echo "✓ Binary is functional."
else
    echo ""
    echo "⚠ Binary may not run on this system (arch mismatch or missing deps)."
    echo "  This is expected if you downloaded for a different target architecture."
fi
