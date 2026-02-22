#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────
# Ashchan Static PHP Binary Builder
#
# Builds each microservice as a single self-contained static
# binary using static-php-cli (https://github.com/crazywhalecc/static-php-cli).
#
# The output is one portable executable per service that bundles:
#   - A statically-linked PHP runtime (musl + all extensions)
#   - The service application code + vendor dependencies
#   - Swoole/Hyperf configuration
#
# Usage:
#   ./build/static-php/build.sh              # Build all services
#   ./build/static-php/build.sh gateway      # Build one service
#   ./build/static-php/build.sh --php-only   # Build PHP binary only
#   ./build/static-php/build.sh --clean      # Remove build artifacts
#
# Environment:
#   SPC_VERSION     static-php-cli version  (default: 2.4.2)
#   PHP_VERSION     PHP version to build    (default: 8.4)
#   BUILD_DIR       Build output directory  (default: ./build/static-php/dist)
#   PARALLEL_JOBS   Compile parallelism     (default: nproc)
# ──────────────────────────────────────────────────────────────
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
BUILD_DIR="${BUILD_DIR:-${ROOT_DIR}/build/static-php/dist}"
SPC_DIR="${ROOT_DIR}/build/static-php/.spc"
SPC_BIN="${SPC_DIR}/bin/spc"
SPC_VERSION="${SPC_VERSION:-2.4.2}"
PHP_VERSION="${PHP_VERSION:-8.4}"
PARALLEL_JOBS="${PARALLEL_JOBS:-$(nproc 2>/dev/null || echo 2)}"

# ── Service registry ────────────────────────────────────────
declare -A SERVICES=(
    [gateway]="api-gateway"
    [auth]="auth-accounts"
    [boards]="boards-threads-posts"
    [media]="media-uploads"
    [search]="search-indexing"
    [moderation]="moderation-anti-spam"
)

# Ordered mTLS ports matching config/autoload/server.php defaults
declare -A PORTS=(
    [gateway]=9501
    [auth]=9502
    [boards]=9503
    [media]=9504
    [search]=9505
    [moderation]=9506
)

# ── Extensions ──────────────────────────────────────────────
# Minimal set required by all Hyperf/Swoole services.
# Sorted for reproducibility.
EXTENSIONS=(
    bcmath
    brotli
    ctype
    curl
    dom
    fileinfo
    filter
    gd
    iconv
    igbinary
    mbstring
    msgpack
    openssl
    pcntl
    pdo
    pdo_pgsql
    phar
    posix
    readline
    redis
    session
    simplexml
    sockets
    swoole
    tokenizer
    xml
    xmlwriter
    zip
    zlib
)

# ── Libraries needed by the extensions above ────────────────
LIBRARIES=(
    brotli
    bzip2
    curl
    freetype
    gd
    gmp
    icu
    imagemagick
    libavif
    libjpeg
    libpng
    libwebp
    libxml2
    libzip
    nghttp2
    openssl
    pgsql
    readline
    sqlite
    xz
    zlib
    zstd
)

# ── Colours ─────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; NC='\033[0m'

info()  { echo -e "${BLUE}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
err()   { echo -e "${RED}[ERROR]${NC} $*" >&2; }
die()   { err "$@"; exit 1; }

# ── Helpers ─────────────────────────────────────────────────

ensure_spc() {
    if [[ -x "$SPC_BIN" ]]; then
        info "static-php-cli already present at ${SPC_BIN}"
        return
    fi

    info "Downloading static-php-cli v${SPC_VERSION}..."
    mkdir -p "$SPC_DIR"

    local arch
    arch="$(uname -m)"
    case "$arch" in
        x86_64)  arch="x86_64" ;;
        aarch64|arm64) arch="aarch64" ;;
        *) die "Unsupported architecture: $arch" ;;
    esac

    local os
    os="$(uname -s | tr '[:upper:]' '[:lower:]')"
    [[ "$os" == "linux" ]] || die "Static builds only supported on Linux (got $os)"

    local url="https://github.com/crazywhalecc/static-php-cli/releases/download/${SPC_VERSION}/spc-linux-${arch}.tar.gz"
    curl -fsSL "$url" | tar -xz -C "$SPC_DIR"
    chmod +x "$SPC_BIN"

    ok "static-php-cli v${SPC_VERSION} installed"
}

build_php_binary() {
    local ext_csv
    ext_csv="$(IFS=,; echo "${EXTENSIONS[*]}")"
    local lib_csv
    lib_csv="$(IFS=,; echo "${LIBRARIES[*]}")"

    if [[ -f "${SPC_DIR}/buildroot/bin/micro.sfx" ]]; then
        info "Static PHP micro.sfx already built — skipping (delete ${SPC_DIR}/buildroot to force rebuild)"
        return
    fi

    info "Downloading PHP ${PHP_VERSION} source and extension sources..."
    (cd "$SPC_DIR" && "$SPC_BIN" download \
        --with-php="${PHP_VERSION}" \
        --for-extensions="${ext_csv}" \
        --prefer-pre-built 2>&1 | tail -5)

    info "Building static PHP cli + micro SAPI (this takes a while)..."
    (cd "$SPC_DIR" && "$SPC_BIN" build \
        --build-cli --build-micro \
        --with-extensions="${ext_csv}" \
        --with-libs="${lib_csv}" \
        -j "${PARALLEL_JOBS}" 2>&1 | tail -20)

    [[ -f "${SPC_DIR}/buildroot/bin/micro.sfx" ]] || die "Build failed — micro.sfx not found"
    ok "Static PHP built: $(${SPC_DIR}/buildroot/bin/php -v | head -1)"
}

pack_service() {
    local name="$1"
    local svc_dir="${SERVICES[$name]}"
    local src="${ROOT_DIR}/services/${svc_dir}"
    local out="${BUILD_DIR}/${name}"
    local phar="${BUILD_DIR}/${name}.phar"
    local binary="${BUILD_DIR}/ashchan-${name}"

    [[ -d "$src" ]] || die "Service directory not found: $src"

    info "Packing service: ${name} (${svc_dir})..."

    # 1. Create a clean staging area
    local stage="${BUILD_DIR}/.stage-${name}"
    rm -rf "$stage"
    mkdir -p "$stage"

    # Copy app code (excluding dev files, tests, caches)
    rsync -a --delete \
        --exclude='.git' \
        --exclude='tests/' \
        --exclude='phpstan*' \
        --exclude='phpunit*' \
        --exclude='runtime/logs/*' \
        --exclude='runtime/container/*' \
        --exclude='.env' \
        --exclude='.env.*' \
        "$src/" "$stage/"

    # Install production-only composer deps (no dev)
    if [[ -f "$stage/composer.json" ]]; then
        (cd "$stage" && composer install \
            --no-dev --prefer-dist --optimize-autoloader \
            --no-progress --no-interaction --quiet 2>/dev/null) || \
        warn "composer install failed for ${name} — using existing vendor/"
    fi

    # 2. Build PHAR archive
    info "  Creating PHAR archive..."
    rm -f "$phar"

    "${SPC_DIR}/buildroot/bin/php" -d phar.readonly=0 -r '
        $src = $argv[1];
        $phar_path = $argv[2];
        $entry = $argv[3];

        $phar = new Phar($phar_path, 0, basename($phar_path));
        $phar->startBuffering();

        // Add all files from the staged service directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $rel = substr($file->getPathname(), strlen($src) + 1);
                // Skip unnecessary files
                if (preg_match("#(\.git|tests?/|phpstan|phpunit|\.md$)#i", $rel)) continue;
                $phar->addFile($file->getPathname(), $rel);
                $count++;
            }
        }

        // Create stub that mirrors bin/hyperf.php
        $stub = <<<STUB
#!/usr/bin/env php
<?php
Phar::mapPhar();
ini_set("display_errors", "on");
ini_set("display_startup_errors", "on");
error_reporting(E_ALL);
define("BASE_PATH", "phar://" . __FILE__);
require BASE_PATH . "/vendor/autoload.php";
if (file_exists(getcwd() . "/.env")) {
    (Dotenv\Dotenv::createUnsafeMutable(getcwd()))->safeLoad();
}
\$container = require BASE_PATH . "/config/container.php";
\$application = \$container->get(Hyperf\Contract\ApplicationInterface::class);
\$application->run();
__HALT_COMPILER();
STUB;
        $phar->setStub($stub);
        $phar->compressFiles(Phar::GZ);
        $phar->stopBuffering();

        echo "  Packed {$count} files into " . basename($phar_path) . " (" . round(filesize($phar_path) / 1024 / 1024, 1) . " MB)\n";
    ' "$stage" "$phar" "bin/hyperf.php"

    [[ -f "$phar" ]] || die "PHAR creation failed for ${name}"

    # 3. Combine micro.sfx + phar → single binary
    info "  Fusing micro.sfx + PHAR → ashchan-${name}..."
    cat "${SPC_DIR}/buildroot/bin/micro.sfx" "$phar" > "$binary"
    chmod +x "$binary"

    # 4. Report
    local size
    size=$(du -sh "$binary" | cut -f1)
    ok "  Built: ${binary} (${size})"

    # Cleanup staging
    rm -rf "$stage"
}

do_clean() {
    info "Cleaning build artifacts..."
    rm -rf "$BUILD_DIR"
    ok "Clean complete"
}

show_help() {
    cat <<'EOF'
Ashchan Static PHP Binary Builder

Usage:
  ./build/static-php/build.sh [OPTIONS] [SERVICES...]

Services:
  gateway     API Gateway (default port 9501)
  auth        Auth & Accounts (default port 9502)
  boards      Boards, Threads, Posts (default port 9503)
  media       Media Uploads (default port 9504)
  search      Search Indexing (default port 9505)
  moderation  Moderation & Anti-Spam (default port 9506)

Options:
  --php-only   Build the static PHP binary only (no service packing)
  --clean      Remove all build artifacts
  --help       Show this help

Environment Variables:
  SPC_VERSION     static-php-cli version to use (default: 2.4.2)
  PHP_VERSION     PHP version to build (default: 8.4)
  BUILD_DIR       Output directory (default: ./build/static-php/dist)
  PARALLEL_JOBS   Number of compile jobs (default: nproc)

Examples:
  # Build all services as static binaries
  ./build/static-php/build.sh

  # Build only the gateway and boards services
  ./build/static-php/build.sh gateway boards

  # Just build the PHP binary (for custom use)
  ./build/static-php/build.sh --php-only

  # Use PHP 8.3 instead of 8.4
  PHP_VERSION=8.3 ./build/static-php/build.sh

Output:
  build/static-php/dist/ashchan-gateway      Single binary, run directly
  build/static-php/dist/ashchan-boards       No PHP install required
  build/static-php/dist/ashchan-*            Portable across Linux (same arch)

Running a static binary:
  ./ashchan-gateway start                    Same as: php bin/hyperf.php start
  PORT=9501 ./ashchan-gateway start          Override port via env
EOF
}

# ── Main ────────────────────────────────────────────────────

main() {
    local php_only=false
    local targets=()

    for arg in "$@"; do
        case "$arg" in
            --php-only) php_only=true ;;
            --clean)    do_clean; exit 0 ;;
            --help|-h)  show_help; exit 0 ;;
            *)
                if [[ -v "SERVICES[$arg]" ]]; then
                    targets+=("$arg")
                else
                    die "Unknown service: $arg  (valid: ${!SERVICES[*]})"
                fi
                ;;
        esac
    done

    # Default to all services
    if [[ ${#targets[@]} -eq 0 ]] && ! $php_only; then
        targets=("${!SERVICES[@]}")
    fi

    mkdir -p "$BUILD_DIR"

    echo ""
    echo "╔══════════════════════════════════════════════════════════╗"
    echo "║        Ashchan Static PHP Binary Builder                ║"
    echo "╠══════════════════════════════════════════════════════════╣"
    echo "║  PHP Version:    ${PHP_VERSION}                                  ║"
    echo "║  SPC Version:    ${SPC_VERSION}                                ║"
    echo "║  Architecture:   $(uname -m)                              ║"
    printf "║  Services:       %-39s║\n" "${targets[*]:-<php-only>}"
    echo "╚══════════════════════════════════════════════════════════╝"
    echo ""

    # Step 1: Ensure static-php-cli is available
    ensure_spc

    # Step 2: Build the static PHP binary (cli + micro)
    build_php_binary

    if $php_only; then
        ok "Static PHP binary built at ${SPC_DIR}/buildroot/bin/php"
        echo ""
        echo "  CLI binary:  ${SPC_DIR}/buildroot/bin/php"
        echo "  Micro SFX:   ${SPC_DIR}/buildroot/bin/micro.sfx"
        echo ""
        exit 0
    fi

    # Step 3: Pack each service
    local failed=0
    for svc in "${targets[@]}"; do
        pack_service "$svc" || { warn "Failed to pack ${svc}"; ((failed++)); }
    done

    echo ""
    echo "════════════════════════════════════════════════════════════"
    if [[ $failed -eq 0 ]]; then
        ok "All ${#targets[@]} service(s) built successfully!"
    else
        warn "${failed} of ${#targets[@]} service(s) failed"
    fi
    echo ""
    echo "Output directory: ${BUILD_DIR}"
    echo ""
    ls -lh "${BUILD_DIR}"/ashchan-* 2>/dev/null || true
    echo ""
    echo "Run a service:    ./build/static-php/dist/ashchan-gateway start"
    echo "════════════════════════════════════════════════════════════"
}

main "$@"
