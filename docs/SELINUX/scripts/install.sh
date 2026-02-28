#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# Ashchan SELinux Policy Installation Script
# 
# Author: Ashchan Security Architecture Team
# Version: 1.0.0
# Date: 28 February 2026
# 
# This script automates the complete installation of Ashchan
# SELinux policy modules including all dependencies and
# configuration.
# 
# Usage:
#   sudo ./scripts/install.sh [--skip-verify] [--permissive]
# ═══════════════════════════════════════════════════════════════

set -euo pipefail

# ═══════════════════════════════════════════════════════════════
# Configuration
# ═══════════════════════════════════════════════════════════════

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POLICY_DIR="$(dirname "$SCRIPT_DIR")/policy"
ASHCHAN_ROOT="/opt/ashchan"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Options
SKIP_VERIFY=false
PERMISSIVE_MODE=false

# ═══════════════════════════════════════════════════════════════
# Parse Arguments
# ═══════════════════════════════════════════════════════════════

while [[ $# -gt 0 ]]; do
    case $1 in
        --skip-verify)
            SKIP_VERIFY=true
            shift
            ;;
        --permissive)
            PERMISSIVE_MODE=true
            shift
            ;;
        -h|--help)
            echo "Usage: sudo $0 [--skip-verify] [--permissive]"
            echo ""
            echo "Options:"
            echo "  --skip-verify   Skip verification after installation"
            echo "  --permissive    Install in permissive mode for testing"
            echo "  -h, --help      Show this help message"
            exit 0
            ;;
        *)
            echo -e "${RED}[ERROR]${NC} Unknown option: $1"
            exit 1
            ;;
    esac
done

# ═══════════════════════════════════════════════════════════════
# Helper Functions
# ═══════════════════════════════════════════════════════════════

log_info() {
    echo -e "${BLUE}[*]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[✓]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_error "This script must be run as root"
        exit 1
    fi
}

check_command() {
    if ! command -v "$1" &> /dev/null; then
        log_error "Required command not found: $1"
        return 1
    fi
    return 0
}

# ═══════════════════════════════════════════════════════════════
# Pre-flight Checks
# ═══════════════════════════════════════════════════════════════

preflight_checks() {
    log_info "Running pre-flight checks..."
    
    # Check root
    check_root
    
    # Check required commands
    local required_cmds=("checkmodule" "semodule_package" "semodule" "restorecon" "semanage")
    local missing_cmds=()
    
    for cmd in "${required_cmds[@]}"; do
        if ! check_command "$cmd"; then
            missing_cmds+=("$cmd")
        fi
    done
    
    if [[ ${#missing_cmds[@]} -gt 0 ]]; then
        log_error "Missing required commands: ${missing_cmds[*]}"
        echo ""
        echo "Install required packages:"
        echo "  RHEL/CentOS/Fedora:"
        echo "    sudo dnf install -y selinux-policy-devel policycoreutils-python-utils"
        echo "  Ubuntu/Debian:"
        echo "    sudo apt-get install -y selinux-policy-dev policycoreutils"
        exit 1
    fi
    
    # Check SELinux status
    if ! command -v getenforce &> /dev/null; then
        log_warning "SELinux does not appear to be installed"
        read -p "Continue anyway? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    else
        local selinux_mode
        selinux_mode=$(getenforce 2>/dev/null || echo "Unknown")
        log_info "SELinux mode: $selinux_mode"
    fi
    
    # Check policy directory
    if [[ ! -d "$POLICY_DIR" ]]; then
        log_error "Policy directory not found: $POLICY_DIR"
        exit 1
    fi
    
    log_success "Pre-flight checks passed"
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Install Dependencies
# ═══════════════════════════════════════════════════════════════

install_dependencies() {
    log_info "Checking for required packages..."
    
    # Detect package manager
    local pkg_mgr=""
    if command -v dnf &> /dev/null; then
        pkg_mgr="dnf"
    elif command -v yum &> /dev/null; then
        pkg_mgr="yum"
    elif command -v apt-get &> /dev/null; then
        pkg_mgr="apt-get"
    elif command -v apt &> /dev/null; then
        pkg_mgr="apt"
    fi
    
    if [[ -n "$pkg_mgr" ]]; then
        log_info "Detected package manager: $pkg_mgr"
        
        case $pkg_mgr in
            dnf|yum)
                # Check if packages are installed
                if ! rpm -q selinux-policy-devel &> /dev/null; then
                    log_info "Installing selinux-policy-devel..."
                    sudo $pkg_mgr install -y selinux-policy-devel
                fi
                if ! rpm -q policycoreutils-python-utils &> /dev/null; then
                    log_info "Installing policycoreutils-python-utils..."
                    sudo $pkg_mgr install -y policycoreutils-python-utils
                fi
                ;;
            apt-get|apt)
                # Check if packages are installed
                if ! dpkg -l | grep -q selinux-policy-dev; then
                    log_info "Installing selinux-policy-dev..."
                    sudo $pkg_mgr install -y selinux-policy-dev
                fi
                if ! dpkg -l | grep -q policycoreutils; then
                    log_info "Installing policycoreutils..."
                    sudo $pkg_mgr install -y policycoreutils
                fi
                ;;
        esac
        
        log_success "Dependencies installed"
    else
        log_warning "Could not detect package manager - skipping dependency installation"
    fi
    
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Build Policies
# ═══════════════════════════════════════════════════════════════

build_policies() {
    log_info "Building SELinux policy modules..."
    
    cd "$POLICY_DIR"
    
    # Build each policy
    local policies=("ashchan" "cloudflared" "anubis" "varnish")
    
    for policy in "${policies[@]}"; do
        if [[ -f "${policy}.te" ]]; then
            log_info "Building ${policy}.pp..."
            
            if ! make "$policy" 2>/dev/null; then
                log_warning "Failed to build ${policy}.pp - continuing"
            else
                log_success "Built ${policy}.pp"
            fi
        fi
    done
    
    cd - > /dev/null
    
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Install Policies
# ═══════════════════════════════════════════════════════════════

install_policies() {
    log_info "Installing SELinux policy modules..."
    
    local policies=("ashchan" "cloudflared" "anubis" "varnish")
    
    for policy in "${policies[@]}"; do
        if [[ -f "${POLICY_DIR}/build/${policy}.pp" ]]; then
            log_info "Installing ${policy}..."
            
            if semodule -i "${POLICY_DIR}/build/${policy}.pp" 2>/dev/null; then
                log_success "Installed ${policy}"
            elif semodule -X 300 -i "${POLICY_DIR}/build/${policy}.pp" 2>/dev/null; then
                log_success "Installed ${policy} (with priority override)"
            else
                log_warning "Failed to install ${policy}"
            fi
        else
            log_warning "Policy file not found: ${POLICY_DIR}/build/${policy}.pp"
        fi
    done
    
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Configure File Contexts
# ═══════════════════════════════════════════════════════════════

configure_file_contexts() {
    log_info "Configuring SELinux file contexts..."
    
    # Application root
    semanage fcontext -a -t ashchan_app_t "${ASHCHAN_ROOT}(/.*)?" 2>/dev/null || \
    semanage fcontext -m -t ashchan_app_t "${ASHCHAN_ROOT}(/.*)?" 2>/dev/null || true
    
    # Service directories
    for service in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do
        local context_type="ashchan_$(echo $service | tr '-' '_')_app_t"
        semanage fcontext -a -t "$context_type" "${ASHCHAN_ROOT}/services/${service}(/.*)?" 2>/dev/null || \
        semanage fcontext -m -t "$context_type" "${ASHCHAN_ROOT}/services/${service}(/.*)?" 2>/dev/null || true
    done
    
    # Runtime directories
    semanage fcontext -a -t ashchan_runtime_t "${ASHCHAN_ROOT}/runtime(/.*)?" 2>/dev/null || true
    semanage fcontext -a -t ashchan_log_t "${ASHCHAN_ROOT}/runtime/logs(/.*)?" 2>/dev/null || true
    semanage fcontext -a -t ashchan_runtime_t "/tmp/ashchan(/.*)?" 2>/dev/null || true
    
    # Certificate directories
    semanage fcontext -a -t ashchan_certs_t "${ASHCHAN_ROOT}/certs(/.*)?" 2>/dev/null || true
    semanage fcontext -a -t ashchan_ca_key_t "${ASHCHAN_ROOT}/certs/ca/ca\.key" 2>/dev/null || true
    semanage fcontext -a -t ashchan_service_key_t "${ASHCHAN_ROOT}/certs/services/[^/]+/[^/]+\.key" 2>/dev/null || true
    
    log_success "File contexts configured"
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Configure Port Contexts
# ═══════════════════════════════════════════════════════════════

configure_port_contexts() {
    log_info "Configuring SELinux port contexts..."
    
    # Ashchan service ports
    for port in 9501 9502 9503 9504 9505 9506; do
        semanage port -a -t ashchan_port_t -p tcp "$port" 2>/dev/null || \
        semanage port -m -t ashchan_port_t -p tcp "$port" 2>/dev/null || true
    done
    
    # mTLS ports
    for port in 8443 8444 8445 8446 8447 8448; do
        semanage port -a -t ashchan_mtls_port_t -p tcp "$port" 2>/dev/null || \
        semanage port -m -t ashchan_mtls_port_t -p tcp "$port" 2>/dev/null || true
    done
    
    # Infrastructure ports
    semanage port -a -t anubis_port_t -p tcp 8080 2>/dev/null || true
    semanage port -a -t varnish_port_t -p tcp 6081 2>/dev/null || true
    semanage port -a -t varnish_admin_port_t -p tcp 6082 2>/dev/null || true
    semanage port -a -t anubis_metrics_port_t -p tcp 9091 2>/dev/null || true
    
    log_success "Port contexts configured"
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Configure Booleans
# ═══════════════════════════════════════════════════════════════

configure_booleans() {
    log_info "Configuring SELinux booleans..."
    
    # Standard booleans
    setsebool -P httpd_can_network_connect 1 2>/dev/null || true
    setsebool -P httpd_can_network_connect_db 1 2>/dev/null || true
    setsebool -P httpd_can_network_connect_redis 1 2>/dev/null || true
    setsebool -P httpd_execmem 1 2>/dev/null || true
    
    # Ashchan custom booleans
    local booleans=(
        "ashchan_external_network"
        "ashchan_connect_postgresql"
        "ashchan_connect_redis"
        "ashchan_connect_minio"
        "ashchan_use_pcntl"
        "ashchan_create_sockets"
        "ashchan_use_tmp"
        "ashchan_access_certs"
        "ashchan_syslog"
        "cloudflared_external_network"
        "cloudflared_syslog"
        "anubis_connect_redis"
        "anubis_syslog"
    )
    
    for bool in "${booleans[@]}"; do
        setsebool -P "$bool" 1 2>/dev/null || true
    done
    
    log_success "SELinux booleans configured"
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Apply File Contexts
# ═══════════════════════════════════════════════════════════════

apply_file_contexts() {
    log_info "Applying file contexts..."
    
    if [[ -d "$ASHCHAN_ROOT" ]]; then
        restorecon -Rv "$ASHCHAN_ROOT" 2>/dev/null || log_warning "Failed to apply contexts to $ASHCHAN_ROOT"
        log_success "File contexts applied to $ASHCHAN_ROOT"
    else
        log_warning "$ASHCHAN_ROOT does not exist - skipping restorecon"
    fi
    
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Set Permissive Mode (Optional)
# ═══════════════════════════════════════════════════════════════

set_permissive_mode() {
    if [[ "$PERMISSIVE_MODE" == true ]]; then
        log_info "Setting Ashchan domains to permissive mode for testing..."
        
        local domains=(
            "ashchan_gateway_t"
            "ashchan_auth_t"
            "ashchan_boards_t"
            "ashchan_media_t"
            "ashchan_search_t"
            "ashchan_moderation_t"
        )
        
        for domain in "${domains[@]}"; do
            semanage permissive -a "$domain" 2>/dev/null || \
            semanage permissive -m "$domain" 2>/dev/null || true
        done
        
        log_success "Permissive mode enabled for Ashchan domains"
        echo ""
        log_warning "Remember to switch to enforcing mode after testing:"
        echo "  sudo semanage permissive -d ashchan_gateway_t"
        echo "  sudo semanage permissive -d ashchan_auth_t"
        echo "  ..."
        echo ""
    fi
}

# ═══════════════════════════════════════════════════════════════
# Verification
# ═══════════════════════════════════════════════════════════════

verify_installation() {
    if [[ "$SKIP_VERIFY" == true ]]; then
        log_info "Skipping verification (requested by user)"
        return
    fi
    
    log_info "Verifying installation..."
    echo ""
    
    # Check policy modules
    echo "Policy Modules:"
    echo "───────────────────────────────────────────────────────"
    local policies=("ashchan" "cloudflared" "anubis" "varnish")
    local all_installed=true
    
    for policy in "${policies[@]}"; do
        if semodule -l 2>/dev/null | grep -q "^${policy}"; then
            echo -e "  ${GREEN}[✓]${NC} ${policy}"
        else
            echo -e "  ${RED}[✗]${NC} ${policy} NOT installed"
            all_installed=false
        fi
    done
    
    echo ""
    
    if [[ "$all_installed" == false ]]; then
        log_warning "Some policy modules are not installed"
    else
        log_success "All policy modules installed"
    fi
    
    # Check file contexts
    echo ""
    echo "File Contexts:"
    echo "───────────────────────────────────────────────────────"
    if [[ -d "$ASHCHAN_ROOT" ]]; then
        ls -Z "$ASHCHAN_ROOT" 2>/dev/null | head -5 || log_warning "Could not list file contexts"
    else
        log_warning "$ASHCHAN_ROOT does not exist"
    fi
    
    # Check port contexts
    echo ""
    echo "Port Contexts:"
    echo "───────────────────────────────────────────────────────"
    semanage port -l 2>/dev/null | grep -E "ashchan|anubis|varnish" | head -10 || \
        log_warning "No custom ports found"
    
    # Check booleans
    echo ""
    echo "SELinux Booleans:"
    echo "───────────────────────────────────────────────────────"
    getsebool -a 2>/dev/null | grep -E "ashchan|cloudflared|anubis" | head -10 || \
        log_warning "Custom booleans not found"
    
    # SELinux mode
    echo ""
    echo "SELinux Mode:"
    echo "───────────────────────────────────────────────────────"
    getenforce 2>/dev/null || log_warning "SELinux not available"
    
    echo ""
}

# ═══════════════════════════════════════════════════════════════
# Main
# ═══════════════════════════════════════════════════════════════

main() {
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo "  Ashchan SELinux Policy Installation"
    echo "  Version 1.0.0"
    echo "═══════════════════════════════════════════════════════"
    echo ""
    
    preflight_checks
    install_dependencies
    build_policies
    install_policies
    configure_file_contexts
    configure_port_contexts
    configure_booleans
    apply_file_contexts
    set_permissive_mode
    verify_installation
    
    echo ""
    echo "═══════════════════════════════════════════════════════"
    log_success "Installation complete!"
    echo ""
    echo "Next steps:"
    echo "  1. Review the verification output above"
    echo "  2. Start Ashchan services: sudo systemctl start ashchan-gateway"
    echo "  3. Monitor for AVC denials: sudo ausearch -m avc -ts recent"
    echo "  4. If using permissive mode, switch to enforcing after testing"
    echo ""
    echo "Documentation: docs/SELINUX/README.md"
    echo "═══════════════════════════════════════════════════════"
    echo ""
}

# Run main function
main "$@"
