#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# Ashchan SELinux Policy Verification Script
# 
# Author: Ashchan Security Architecture Team
# Version: 1.0.0
# Date: 28 February 2026
# 
# This script verifies the installation and configuration of
# Ashchan SELinux policy modules.
# 
# Usage:
#   sudo ./scripts/verify.sh [--verbose]
# ═══════════════════════════════════════════════════════════════

set -euo pipefail

# ═══════════════════════════════════════════════════════════════
# Configuration
# ═══════════════════════════════════════════════════════════════

ASHCHAN_ROOT="/opt/ashchan"
VERBOSE=false

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ═══════════════════════════════════════════════════════════════
# Parse Arguments
# ═══════════════════════════════════════════════════════════════

while [[ $# -gt 0 ]]; do
    case $1 in
        --verbose|-v)
            VERBOSE=true
            shift
            ;;
        -h|--help)
            echo "Usage: sudo $0 [--verbose]"
            echo ""
            echo "Options:"
            echo "  --verbose, -v   Show detailed output"
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
    echo -e "${RED}[✗]${NC} $1"
}

section_header() {
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo "  $1"
    echo "═══════════════════════════════════════════════════════"
}

# ═══════════════════════════════════════════════════════════════
# Verification Functions
# ═══════════════════════════════════════════════════════════════

verify_selinux_status() {
    section_header "SELinux Status"
    
    if command -v getenforce &> /dev/null; then
        local mode
        mode=$(getenforce 2>/dev/null || echo "Unknown")
        
        case $mode in
            Enforcing)
                log_success "SELinux is in enforcing mode"
                ;;
            Permissive)
                log_warning "SELinux is in permissive mode (logging only)"
                ;;
            Disabled)
                log_error "SELinux is disabled"
                ;;
            *)
                log_warning "Unknown SELinux mode: $mode"
                ;;
        esac
    else
        log_error "SELinux tools not found"
        return 1
    fi
    
    if [[ "$VERBOSE" == true ]]; then
        echo ""
        sestatus 2>/dev/null || log_warning "Could not get detailed SELinux status"
    fi
}

verify_policy_modules() {
    section_header "Policy Modules"
    
    local policies=("ashchan" "cloudflared" "anubis" "varnish")
    local all_installed=true
    
    for policy in "${policies[@]}"; do
        if semodule -l 2>/dev/null | grep -q "^${policy}"; then
            local version
            version=$(semodule -l 2>/dev/null | grep "^${policy}" | awk '{print $2}' || echo "unknown")
            log_success "${policy} installed (version: ${version})"
        else
            log_error "${policy} NOT installed"
            all_installed=false
        fi
    done
    
    if [[ "$all_installed" == false ]]; then
        echo ""
        log_warning "Some policy modules are missing"
        echo "Run: sudo make install (in docs/SELINUX/policy/)"
        return 1
    fi
    
    if [[ "$VERBOSE" == true ]]; then
        echo ""
        log_info "All loaded SELinux modules:"
        semodule -l 2>/dev/null | grep -E "ashchan|cloudflared|anubis|varnish" || true
    fi
}

verify_file_contexts() {
    section_header "File Contexts"
    
    if [[ ! -d "$ASHCHAN_ROOT" ]]; then
        log_warning "$ASHCHAN_ROOT does not exist"
        return 0
    fi
    
    # Check key directories
    local check_paths=(
        "$ASHCHAN_ROOT:ashchan_app_t"
        "$ASHCHAN_ROOT/services:ashchan_gateway_app_t"
        "$ASHCHAN_ROOT/runtime:ashchan_runtime_t"
        "$ASHCHAN_ROOT/certs:ashchan_certs_t"
    )
    
    local all_correct=true
    
    for check in "${check_paths[@]}"; do
        local path="${check%%:*}"
        local expected="${check##*:}"
        
        if [[ -e "$path" ]]; then
            local actual
            actual=$(ls -ldZ "$path" 2>/dev/null | awk '{print $1}' | cut -d: -f3)
            
            if [[ "$actual" == *"$expected"* ]]; then
                log_success "${path}: ${actual}"
            else
                log_warning "${path}: expected ${expected}, got ${actual}"
                all_correct=false
            fi
        fi
    done
    
    if [[ "$all_correct" == false ]]; then
        echo ""
        log_warning "Some file contexts are incorrect"
        echo "Run: sudo restorecon -Rv $ASHCHAN_ROOT"
    fi
    
    if [[ "$VERBOSE" == true ]]; then
        echo ""
        log_info "File contexts in $ASHCHAN_ROOT:"
        ls -laZ "$ASHCHAN_ROOT" 2>/dev/null | head -15 || true
    fi
}

verify_port_contexts() {
    section_header "Port Contexts"
    
    # Check Ashchan ports
    local ashchan_ports=(9501 9502 9503 9504 9505 9506)
    local mtls_ports=(8443 8444 8445 8446 8447 8448)
    local infra_ports=("8080:anubis" "6081:varnish" "6082:varnish_admin" "9091:anubis_metrics")
    
    local all_correct=true
    
    # Check HTTP ports
    for port in "${ashchan_ports[@]}"; do
        if semanage port -l 2>/dev/null | grep -q "ashchan_port_t.*${port}"; then
            log_success "Port ${port}: ashchan_port_t"
        else
            log_warning "Port ${port}: NOT labeled as ashchan_port_t"
            all_correct=false
        fi
    done
    
    # Check mTLS ports
    for port in "${mtls_ports[@]}"; do
        if semanage port -l 2>/dev/null | grep -q "ashchan_mtls_port_t.*${port}"; then
            log_success "Port ${port}: ashchan_mtls_port_t"
        else
            log_warning "Port ${port}: NOT labeled as ashchan_mtls_port_t"
            all_correct=false
        fi
    done
    
    # Check infrastructure ports
    for check in "${infra_ports[@]}"; do
        local port="${check%%:*}"
        local expected="${check##*:}"
        
        if semanage port -l 2>/dev/null | grep -q "${expected}_port_t.*${port}"; then
            log_success "Port ${port}: ${expected}_port_t"
        else
            log_warning "Port ${port}: NOT labeled correctly"
            all_correct=false
        fi
    done
    
    if [[ "$all_correct" == false ]]; then
        echo ""
        log_warning "Some port contexts are missing"
        echo "Run: sudo make setports"
    fi
    
    if [[ "$VERBOSE" == true ]]; then
        echo ""
        log_info "All Ashchan-related port contexts:"
        semanage port -l 2>/dev/null | grep -E "ashchan|anubis|varnish" || true
    fi
}

verify_booleans() {
    section_header "SELinux Booleans"
    
    local required_booleans=(
        "ashchan_external_network"
        "ashchan_connect_postgresql"
        "ashchan_connect_redis"
        "ashchan_use_pcntl"
        "ashchan_create_sockets"
        "ashchan_access_certs"
        "httpd_can_network_connect"
        "httpd_can_network_connect_db"
        "httpd_can_network_connect_redis"
        "httpd_execmem"
    )
    
    local all_enabled=true
    
    for bool in "${required_booleans[@]}"; do
        local value
        value=$(getsebool "$bool" 2>/dev/null | awk '{print $3}' || echo "off")
        
        if [[ "$value" == "on" ]]; then
            log_success "${bool}: on"
        else
            log_warning "${bool}: ${value} (should be on)"
            all_enabled=false
        fi
    done
    
    if [[ "$all_enabled" == false ]]; then
        echo ""
        log_warning "Some booleans are not enabled"
        echo "Run: sudo make setbooleans"
    fi
    
    if [[ "$VERBOSE" == true ]]; then
        echo ""
        log_info "All Ashchan-related booleans:"
        getsebool -a 2>/dev/null | grep -E "ashchan|cloudflared|anubis" || true
    fi
}

verify_permissive_domains() {
    section_header "Permissive Domains"
    
    local ashchan_domains=(
        "ashchan_gateway_t"
        "ashchan_auth_t"
        "ashchan_boards_t"
        "ashchan_media_t"
        "ashchan_search_t"
        "ashchan_moderation_t"
    )
    
    local any_permissive=false
    
    for domain in "${ashchan_domains[@]}"; do
        if semanage permissive -l 2>/dev/null | grep -q "$domain"; then
            log_warning "${domain}: PERMISSIVE MODE"
            any_permissive=true
        else
            log_success "${domain}: enforcing"
        fi
    done
    
    if [[ "$any_permissive" == true ]]; then
        echo ""
        log_warning "Some domains are in permissive mode"
        echo "This is normal during testing, but switch to enforcing for production"
        echo ""
        echo "To switch to enforcing mode:"
        for domain in "${ashchan_domains[@]}"; do
            if semanage permissive -l 2>/dev/null | grep -q "$domain"; then
                echo "  sudo semanage permissive -d ${domain}"
            fi
        done
    fi
}

check_recent_denials() {
    section_header "Recent AVC Denials (Last Hour)"
    
    if command -v ausearch &> /dev/null; then
        local denials
        denials=$(ausearch -m avc -ts recent 2>/dev/null | grep -E "ashchan|cloudflared|anubis|varnish" | wc -l || echo "0")
        
        if [[ "$denials" -gt 0 ]]; then
            log_warning "Found ${denials} recent AVC denials"
            echo ""
            
            if [[ "$VERBOSE" == true ]]; then
                log_info "Recent denials:"
                ausearch -m avc -ts recent 2>/dev/null | grep -E "ashchan|cloudflared|anubis|varnish" | tail -10 || true
                echo ""
                log_info "Denial analysis:"
                ausearch -m avc -ts recent 2>/dev/null | grep -E "ashchan|cloudflared|anubis|varnish" | audit2why || true
            else
                echo "Run with --verbose to see denial details"
            fi
            
            echo ""
            echo "To fix denials:"
            echo "  1. Review: sudo ausearch -m avc -ts recent | audit2why"
            echo "  2. Generate fix: sudo ausearch -m avc -ts recent | audit2allow -M ashchan_local"
            echo "  3. Install fix: sudo semodule -i ashchan_local.pp"
        else
            log_success "No recent AVC denials"
        fi
    else
        log_info "ausearch not available - skipping denial check"
    fi
}

generate_report() {
    section_header "Verification Summary"
    
    local score=0
    local total=6
    
    # Policy modules
    if semodule -l 2>/dev/null | grep -q "ashchan"; then
        ((score++))
    fi
    
    # File contexts
    if [[ -d "$ASHCHAN_ROOT" ]] && ls -Z "$ASHCHAN_ROOT" 2>/dev/null | grep -q "ashchan"; then
        ((score++))
    fi
    
    # Port contexts
    if semanage port -l 2>/dev/null | grep -q "ashchan_port_t"; then
        ((score++))
    fi
    
    # Booleans
    if getsebool ashchan_external_network 2>/dev/null | grep -q "on"; then
        ((score++))
    fi
    
    # SELinux enforcing
    if getenforce 2>/dev/null | grep -q "Enforcing"; then
        ((score++))
    fi
    
    # No denials
    if command -v ausearch &> /dev/null; then
        local denials
        denials=$(ausearch -m avc -ts recent 2>/dev/null | grep -c "ashchan" || echo "0")
        if [[ "$denials" -eq 0 ]]; then
            ((score++))
        fi
    else
        ((score++))  # Assume OK if we can't check
    fi
    
    echo ""
    echo "  Score: ${score}/${total}"
    echo ""
    
    if [[ $score -eq $total ]]; then
        log_success "All checks passed! SELinux is properly configured."
    elif [[ $score -ge $((total - 1)) ]]; then
        log_success "Most checks passed. Review warnings above."
    elif [[ $score -ge $((total / 2)) ]]; then
        log_warning "Partial configuration. Review and fix issues above."
    else
        log_error "Incomplete configuration. Run installation script."
    fi
}

# ═══════════════════════════════════════════════════════════════
# Main
# ═══════════════════════════════════════════════════════════════

main() {
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo "  Ashchan SELinux Policy Verification"
    echo "  Version 1.0.0"
    echo "═══════════════════════════════════════════════════════"
    
    verify_selinux_status
    verify_policy_modules
    verify_file_contexts
    verify_port_contexts
    verify_booleans
    verify_permissive_domains
    check_recent_denials
    generate_report
    
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo "  Verification Complete"
    echo "═══════════════════════════════════════════════════════"
    echo ""
}

# Run main function
main "$@"
