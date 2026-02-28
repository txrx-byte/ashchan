# ═══════════════════════════════════════════════════════════════
# Ashchan SELinux Policy Build System
# 
# Author: Ashchan Security Architecture Team
# Version: 1.0.0
# Date: 28 February 2026
# 
# This Makefile automates the build, installation, and management
# of Ashchan SELinux policy modules.
# 
# Requirements:
#   - selinux-policy-devel package
#   - policycoreutils-python-utils package
#   - Root/sudo access for installation
# 
# Usage:
#   make              - Build all policy modules
#   make install      - Install policies and apply contexts
#   make uninstall    - Remove all Ashchan policies
#   make verify       - Verify policy installation
#   make clean        - Remove build artifacts
# ═══════════════════════════════════════════════════════════════

SHELL := /bin/bash

# Policy module names
POLICIES := ashchan cloudflared anubis varnish

# Build directory
BUILDDIR := build

# Installation directories
PREFIX := /usr/share/selinux
CONTEXTDIR := /etc/selinux/targeted/contexts/files

# Colors for output
RED := \033[0;31m
GREEN := \033[0;32m
YELLOW := \033[0;33m
BLUE := \033[0;34m
NC := \033[0m  # No Color

# Check for required tools
CHECKPOLICY := $(shell which checkmodule 2>/dev/null)
SEMODULE_PACKAGE := $(shell which semodule_package 2>/dev/null)
SEMODULE := $(shell which semodule 2>/dev/null)
RESTORECON := $(shell which restorecon 2>/dev/null)
SEMANAGE := $(shell which semanage 2>/dev/null)

# Default target
.PHONY: all
all: check-tools $(addsuffix .pp,$(POLICIES))
	@echo -e "$(GREEN)[✓]$(NC) All policy modules built successfully"

# ═══════════════════════════════════════════════════════════════
# Tool Checks
# ═══════════════════════════════════════════════════════════════

.PHONY: check-tools
check-tools:
	@if [ -z "$(CHECKPOLICY)" ]; then \
		echo -e "$(RED)[ERROR]$(NC) checkmodule not found"; \
		echo "Install selinux-policy-devel:"; \
		echo "  RHEL/CentOS/Fedora: sudo dnf install -y selinux-policy-devel"; \
		echo "  Ubuntu/Debian: sudo apt-get install -y selinux-policy-dev"; \
		exit 1; \
	fi
	@if [ -z "$(SEMODULE_PACKAGE)" ]; then \
		echo -e "$(RED)[ERROR]$(NC) semodule_package not found"; \
		echo "Install policycoreutils-python-utils:"; \
		echo "  RHEL/CentOS/Fedora: sudo dnf install -y policycoreutils-python-utils"; \
		echo "  Ubuntu/Debian: sudo apt-get install -y policycoreutils"; \
		exit 1; \
	fi
	@echo -e "$(GREEN)[✓]$(NC) Required tools found"

# ═══════════════════════════════════════════════════════════════
# Policy Module Build Rules
# ═══════════════════════════════════════════════════════════════

# Create build directory
$(BUILDDIR):
	@mkdir -p $(BUILDDIR)

# Generic rule for building .pp from .te
%.pp: %.te | $(BUILDDIR)
	@echo -e "$(BLUE)[*]$(NC) Building $*.pp..."
	@checkmodule -M -m -o $(BUILDDIR)/$*.mod $<
	@if [ -f "$*.fc" ]; then \
		semodule_package -o $(BUILDDIR)/$*.pp -m $(BUILDDIR)/$*.mod -f $*.fc; \
	else \
		semodule_package -o $(BUILDDIR)/$*.pp -m $(BUILDDIR)/$*.mod; \
	fi
	@echo -e "$(GREEN)[✓]$(NC) Built $(BUILDDIR)/$*.pp"

# ashchan requires .fc file
ashchan.pp: ashchan.te ashchan.fc | $(BUILDDIR)
	@echo -e "$(BLUE)[*]$(NC) Building ashchan.pp..."
	@checkmodule -M -m -o $(BUILDDIR)/ashchan.mod ashchan.te
	@semodule_package -o $(BUILDDIR)/ashchan.pp -m $(BUILDDIR)/ashchan.mod -f ashchan.fc
	@echo -e "$(GREEN)[✓]$(NC) Built $(BUILDDIR)/ashchan.pp"

# Individual policy targets
.PHONY: ashchan cloudflared anubis varnish
ashchan: ashchan.pp
cloudflared: cloudflared.pp
anubis: anubis.pp
varnish: varnish.pp

# Build all policies
.PHONY: build
build: all

# ═══════════════════════════════════════════════════════════════
# Installation
# ═══════════════════════════════════════════════════════════════

.PHONY: install
install: all check-root
	@echo -e "$(BLUE)[*]$(NC) Installing Ashchan SELinux policy modules..."
	@for policy in $(POLICIES); do \
		if [ -f "$(BUILDDIR)/$$policy.pp" ]; then \
			echo "  Installing $$policy..."; \
			semodule -i $(BUILDDIR)/$$policy.pp 2>/dev/null || \
			semodule -X 300 -i $(BUILDDIR)/$$policy.pp; \
			echo -e "$(GREEN)  [✓]$(NC) Installed $$policy"; \
		fi; \
	done
	@echo -e "$(GREEN)[✓]$(NC) All policy modules installed"
	@echo ""
	@echo -e "$(YELLOW)[*]$(NC) Run 'make setbooleans' to enable required SELinux booleans"
	@echo -e "$(YELLOW)[*]$(NC) Run 'make restorecon' to apply file contexts"

.PHONY: install-ashchan
install-ashchan: ashchan.pp check-root
	@echo -e "$(BLUE)[*]$(NC) Installing ashchan policy module..."
	@semodule -i $(BUILDDIR)/ashchan.pp 2>/dev/null || semodule -X 300 -i $(BUILDDIR)/ashchan.pp
	@echo -e "$(GREEN)[✓]$(NC) Ashchan policy installed"

# ═══════════════════════════════════════════════════════════════
# File Contexts
# ═══════════════════════════════════════════════════════════════

.PHONY: restorecon
restorecon: check-root
	@echo -e "$(BLUE)[*]$(NC) Applying SELinux file contexts to /opt/ashchan..."
	@restorecon -Rv /opt/ashchan 2>/dev/null || echo -e "$(YELLOW)[WARN]$(NC) /opt/ashchan not found"
	@echo -e "$(GREEN)[✓]$(NC) File contexts applied"

.PHONY: setcontexts
setcontexts: check-root
	@echo -e "$(BLUE)[*]$(NC) Configuring SELinux file context rules..."
	@# Application root
	@semanage fcontext -a -t ashchan_app_t "/opt/ashchan(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_app_t "/opt/ashchan(/.*)?"
	@# Service directories
	@semanage fcontext -a -t ashchan_gateway_app_t "/opt/ashchan/services/api-gateway(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_gateway_app_t "/opt/ashchan/services/api-gateway(/.*)?"
	@semanage fcontext -a -t ashchan_auth_app_t "/opt/ashchan/services/auth-accounts(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_auth_app_t "/opt/ashchan/services/auth-accounts(/.*)?"
	@semanage fcontext -a -t ashchan_boards_app_t "/opt/ashchan/services/boards-threads-posts(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_boards_app_t "/opt/ashchan/services/boards-threads-posts(/.*)?"
	@semanage fcontext -a -t ashchan_media_app_t "/opt/ashchan/services/media-uploads(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_media_app_t "/opt/ashchan/services/media-uploads(/.*)?"
	@semanage fcontext -a -t ashchan_search_app_t "/opt/ashchan/services/search-indexing(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_search_app_t "/opt/ashchan/services/search-indexing(/.*)?"
	@semanage fcontext -a -t ashchan_moderation_app_t "/opt/ashchan/services/moderation-anti-spam(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_moderation_app_t "/opt/ashchan/services/moderation-anti-spam(/.*)?"
	@# Runtime directories
	@semanage fcontext -a -t ashchan_runtime_t "/opt/ashchan/runtime(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_runtime_t "/opt/ashchan/runtime(/.*)?"
	@semanage fcontext -a -t ashchan_log_t "/opt/ashchan/runtime/logs(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_log_t "/opt/ashchan/runtime/logs(/.*)?"
	@semanage fcontext -a -t ashchan_runtime_t "/tmp/ashchan(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_runtime_t "/tmp/ashchan(/.*)?"
	@# Certificates
	@semanage fcontext -a -t ashchan_certs_t "/opt/ashchan/certs(/.*)?" 2>/dev/null || \
		semanage fcontext -m -t ashchan_certs_t "/opt/ashchan/certs(/.*)?"
	@semanage fcontext -a -t ashchan_ca_key_t "/opt/ashchan/certs/ca/ca\.key" 2>/dev/null || \
		semanage fcontext -m -t ashchan_ca_key_t "/opt/ashchan/certs/ca/ca\.key"
	@semanage fcontext -a -t ashchan_service_key_t "/opt/ashchan/certs/services/[^/]+/[^/]+\.key" 2>/dev/null || \
		semanage fcontext -m -t ashchan_service_key_t "/opt/ashchan/certs/services/[^/]+/[^/]+\.key"
	@echo -e "$(GREEN)[✓]$(NC) File context rules configured"

# ═══════════════════════════════════════════════════════════════
# Port Contexts
# ═══════════════════════════════════════════════════════════════

.PHONY: setports
setports: check-root
	@echo -e "$(BLUE)[*]$(NC) Configuring SELinux port contexts..."
	@# Ashchan service ports
	@semanage port -a -t ashchan_port_t -p tcp 9501 2>/dev/null || semanage port -m -t ashchan_port_t -p tcp 9501
	@semanage port -a -t ashchan_port_t -p tcp 9502 2>/dev/null || semanage port -m -t ashchan_port_t -p tcp 9502
	@semanage port -a -t ashchan_port_t -p tcp 9503 2>/dev/null || semanage port -m -t ashchan_port_t -p tcp 9503
	@semanage port -a -t ashchan_port_t -p tcp 9504 2>/dev/null || semanage port -m -t ashchan_port_t -p tcp 9504
	@semanage port -a -t ashchan_port_t -p tcp 9505 2>/dev/null || semanage port -m -t ashchan_port_t -p tcp 9505
	@semanage port -a -t ashchan_port_t -p tcp 9506 2>/dev/null || semanage port -m -t ashchan_port_t -p tcp 9506
	@# mTLS ports
	@semanage port -a -t ashchan_mtls_port_t -p tcp 8443 2>/dev/null || semanage port -m -t ashchan_mtls_port_t -p tcp 8443
	@semanage port -a -t ashchan_mtls_port_t -p tcp 8444 2>/dev/null || semanage port -m -t ashchan_mtls_port_t -p tcp 8444
	@semanage port -a -t ashchan_mtls_port_t -p tcp 8445 2>/dev/null || semanage port -m -t ashchan_mtls_port_t -p tcp 8445
	@semanage port -a -t ashchan_mtls_port_t -p tcp 8446 2>/dev/null || semanage port -m -t ashchan_mtls_port_t -p tcp 8446
	@semanage port -a -t ashchan_mtls_port_t -p tcp 8447 2>/dev/null || semanage port -m -t ashchan_mtls_port_t -p tcp 8447
	@semanage port -a -t ashchan_mtls_port_t -p tcp 8448 2>/dev/null || semanage port -m -t ashchan_mtls_port_t -p tcp 8448
	@# Infrastructure ports
	@semanage port -a -t anubis_port_t -p tcp 8080 2>/dev/null || semanage port -m -t anubis_port_t -p tcp 8080
	@semanage port -a -t varnish_port_t -p tcp 6081 2>/dev/null || semanage port -m -t varnish_port_t -p tcp 6081
	@semanage port -a -t varnish_admin_port_t -p tcp 6082 2>/dev/null || semanage port -m -t varnish_admin_port_t -p tcp 6082
	@semanage port -a -t anubis_metrics_port_t -p tcp 9091 2>/dev/null || semanage port -m -t anubis_metrics_port_t -p tcp 9091
	@echo -e "$(GREEN)[✓]$(NC) Port contexts configured"

# ═══════════════════════════════════════════════════════════════
# SELinux Booleans
# ═══════════════════════════════════════════════════════════════

.PHONY: setbooleans
setbooleans: check-root
	@echo -e "$(BLUE)[*]$(NC) Configuring SELinux booleans..."
	@# Standard booleans
	@setsebool -P httpd_can_network_connect 1 2>/dev/null || true
	@setsebool -P httpd_can_network_connect_db 1 2>/dev/null || true
	@setsebool -P httpd_can_network_connect_redis 1 2>/dev/null || true
	@setsebool -P httpd_execmem 1 2>/dev/null || true
	@# Ashchan custom booleans
	@setsebool -P ashchan_external_network 1 2>/dev/null || true
	@setsebool -P ashchan_connect_postgresql 1 2>/dev/null || true
	@setsebool -P ashchan_connect_redis 1 2>/dev/null || true
	@setsebool -P ashchan_connect_minio 1 2>/dev/null || true
	@setsebool -P ashchan_use_pcntl 1 2>/dev/null || true
	@setsebool -P ashchan_create_sockets 1 2>/dev/null || true
	@setsebool -P ashchan_use_tmp 1 2>/dev/null || true
	@setsebool -P ashchan_access_certs 1 2>/dev/null || true
	@setsebool -P ashchan_syslog 1 2>/dev/null || true
	@# Cloudflared booleans
	@setsebool -P cloudflared_external_network 1 2>/dev/null || true
	@setsebool -P cloudflared_syslog 1 2>/dev/null || true
	@# Anubis booleans
	@setsebool -P anubis_connect_redis 1 2>/dev/null || true
	@setsebool -P anubis_syslog 1 2>/dev/null || true
	@echo -e "$(GREEN)[✓]$(NC) SELinux booleans configured"

# ═══════════════════════════════════════════════════════════════
# Verification
# ═══════════════════════════════════════════════════════════════

.PHONY: verify
verify:
	@echo -e "$(BLUE)[*]$(NC) Verifying Ashchan SELinux policy installation..."
	@echo ""
	@echo "Policy Modules:"
	@echo "───────────────────────────────────────────────────────"
	@for policy in $(POLICIES); do \
		if semodule -l 2>/dev/null | grep -q "^$$policy"; then \
			echo -e "$(GREEN)  [✓]$(NC) $$policy installed"; \
		else \
			echo -e "$(RED)  [✗]$(NC) $$policy NOT installed"; \
		fi; \
	done
	@echo ""
	@echo "File Contexts:"
	@echo "───────────────────────────────────────────────────────"
	@if [ -d "/opt/ashchan" ]; then \
		ls -Z /opt/ashchan 2>/dev/null | head -5; \
	else \
		echo -e "$(YELLOW)[WARN]$(NC) /opt/ashchan not found"; \
	fi
	@echo ""
	@echo "Port Contexts:"
	@echo "───────────────────────────────────────────────────────"
	@semanage port -l 2>/dev/null | grep -E "ashchan|anubis|varnish" | head -10 || \
		echo -e "$(YELLOW)[WARN]$(NC) No custom ports found"
	@echo ""
	@echo "SELinux Booleans:"
	@echo "───────────────────────────────────────────────────────"
	@getsebool -a 2>/dev/null | grep -E "ashchan|cloudflared|anubis" | head -15 || \
		echo -e "$(YELLOW)[WARN]$(NC) Custom booleans not found"
	@echo ""
	@echo "SELinux Mode:"
	@echo "───────────────────────────────────────────────────────"
	@getenforce 2>/dev/null || echo -e "$(YELLOW)[WARN]$(NC) SELinux not available"

# ═══════════════════════════════════════════════════════════════
# Uninstallation
# ═══════════════════════════════════════════════════════════════

.PHONY: uninstall
uninstall: check-root
	@echo -e "$(YELLOW)[!]$(NC) Removing Ashchan SELinux policy modules..."
	@for policy in $(POLICIES); do \
		if semodule -l 2>/dev/null | grep -q "^$$policy"; then \
			echo "  Removing $$policy..."; \
			semodule -r $$policy 2>/dev/null && \
			echo -e "$(GREEN)  [✓]$(NC) Removed $$policy"; \
		fi; \
	done
	@echo -e "$(GREEN)[✓]$(NC) Policy modules removed"
	@echo ""
	@echo -e "$(YELLOW)[*]$(NC) Run 'make restorecon' to reset file contexts if needed"

# ═══════════════════════════════════════════════════════════════
# Permissive Mode (for testing)
# ═══════════════════════════════════════════════════════════════

.PHONY: permissive
permissive: check-root
	@echo -e "$(BLUE)[*]$(NC) Setting Ashchan domains to permissive mode..."
	@semanage permissive -a ashchan_gateway_t 2>/dev/null || semanage permissive -m ashchan_gateway_t
	@semanage permissive -a ashchan_auth_t 2>/dev/null || semanage permissive -m ashchan_auth_t
	@semanage permissive -a ashchan_boards_t 2>/dev/null || semanage permissive -m ashchan_boards_t
	@semanage permissive -a ashchan_media_t 2>/dev/null || semanage permissive -m ashchan_media_t
	@semanage permissive -a ashchan_search_t 2>/dev/null || semanage permissive -m ashchan_search_t
	@semanage permissive -a ashchan_moderation_t 2>/dev/null || semanage permissive -m ashchan_moderation_t
	@echo -e "$(GREEN)[✓]$(NC) Ashchan domains set to permissive mode"
	@echo ""
	@echo -e "$(YELLOW)[*]$(NC) Monitor denials with: sudo ausearch -m avc -ts recent"

.PHONY: enforcing
enforcing: check-root
	@echo -e "$(BLUE)[*]$(NC) Setting Ashchan domains to enforcing mode..."
	@semanage permissive -d ashchan_gateway_t 2>/dev/null || true
	@semanage permissive -d ashchan_auth_t 2>/dev/null || true
	@semanage permissive -d ashchan_boards_t 2>/dev/null || true
	@semanage permissive -d ashchan_media_t 2>/dev/null || true
	@semanage permissive -d ashchan_search_t 2>/dev/null || true
	@semanage permissive -d ashchan_moderation_t 2>/dev/null || true
	@echo -e "$(GREEN)[✓]$(NC) Ashchan domains set to enforcing mode"

# ═══════════════════════════════════════════════════════════════
# Cleanup
# ═══════════════════════════════════════════════════════════════

.PHONY: clean
clean:
	@echo -e "$(BLUE)[*]$(NC) Removing build artifacts..."
	@rm -rf $(BUILDDIR)
	@rm -f *.pp *.mod
	@echo -e "$(GREEN)[✓]$(NC) Clean complete"

# ═══════════════════════════════════════════════════════════════
# Helper Targets
# ═══════════════════════════════════════════════════════════════

.PHONY: check-root
check-root:
	@if [ "$$(id -u)" != "0" ]; then \
		echo -e "$(RED)[ERROR]$(NC) This target requires root access"; \
		echo "Run with: sudo make $$(MAKECMDGOALS)"; \
		exit 1; \
	fi

.PHONY: help
help:
	@echo ""
	@echo "Ashchan SELinux Policy Build System"
	@echo "═══════════════════════════════════════════════════════"
	@echo ""
	@echo "Build Targets:"
	@echo "  all          - Build all policy modules (default)"
	@echo "  ashchan      - Build ashchan policy only"
	@echo "  cloudflared  - Build cloudflared policy only"
	@echo "  anubis       - Build anubis policy only"
	@echo "  varnish      - Build varnish policy only"
	@echo ""
	@echo "Installation Targets:"
	@echo "  install      - Install all policies and configure system"
	@echo "  setcontexts  - Configure file context rules"
	@echo "  setports     - Configure port context rules"
	@echo "  setbooleans  - Enable required SELinux booleans"
	@echo "  restorecon   - Apply file contexts to /opt/ashchan"
	@echo ""
	@echo "Testing Targets:"
	@echo "  permissive   - Set Ashchan domains to permissive mode"
	@echo "  enforcing    - Set Ashchan domains to enforcing mode"
	@echo "  verify       - Verify policy installation"
	@echo ""
	@echo "Cleanup Targets:"
	@echo "  uninstall    - Remove all Ashchan policies"
	@echo "  clean        - Remove build artifacts"
	@echo ""
	@echo "Quick Start:"
	@echo "  sudo make install      # Install and configure everything"
	@echo "  sudo make verify       # Verify installation"
	@echo ""
