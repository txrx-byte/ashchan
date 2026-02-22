# Firewall & Security Hardening

Production deployment guide covering firewall configuration, intrusion prevention, and
system hardening for Ashchan. Covers **GNU/Linux** (nftables, iptables, firewalld) and
**FreeBSD** (pf, ipfw).

---

## Port Inventory

Before writing rules, know what you are protecting:

| Port | Service | Direction | Exposure |
|------|---------|-----------|----------|
| 80   | nginx HTTP (redirect → HTTPS) | Inbound | **Public** (with nginx) |
| 443  | nginx HTTPS (TLS entry point) | Inbound | **Public** (with nginx) |
| 8080 | Anubis reverse proxy | Inbound | **Public** (without nginx) / Loopback (with nginx) |
| 9501 | API Gateway | Internal | Loopback only |
| 9502 | Auth / Accounts | Internal | Loopback only |
| 9503 | Boards / Threads / Posts | Internal | Loopback only |
| 9504 | Media / Uploads | Internal | Loopback only |
| 9505 | Search / Indexing | Internal | Loopback only |
| 9506 | Moderation / Anti-Spam | Internal | Loopback only |
| 8443 | Gateway mTLS | Internal | Loopback only |
| 8444 | Auth mTLS | Internal | Loopback only |
| 8445 | Boards mTLS | Internal | Loopback only |
| 8446 | Media mTLS | Internal | Loopback only |
| 8447 | Search mTLS | Internal | Loopback only |
| 8448 | Moderation mTLS | Internal | Loopback only |
| 9091 | Anubis Prometheus metrics | Internal | Monitoring only |
| 5432 | PostgreSQL | Internal | Loopback only |
| 6379 | Redis | Internal | Loopback only |
| 9000 | MinIO (S3 API) | Internal | Loopback only |
| 9001 | MinIO Console | Internal | Admin only |
| 22 | SSH | Inbound | Admin only |

> **Principle:** Only the public entry point and SSH should be reachable from the
> internet. With nginx: ports **80, 443, and 22**. Without nginx: ports **8080 and 22**.
> Everything else stays on loopback or a private network.
>
> When using nginx, port 8080 (Anubis) should be moved to loopback-only.
> See [docs/NGINX_HARDENING.md](NGINX_HARDENING.md) for the full nginx deployment guide.

---

## GNU/Linux — nftables (recommended)

nftables is the modern Linux firewall (replaces iptables). Available on all major
distributions since kernel 3.13+.

### /etc/nftables.conf

```nft
#!/usr/sbin/nft -f

flush ruleset

# ── Variables ────────────────────────────────────────────────
define WAN_IF     = eth0
define ASHCHAN_PORTS = { 9501-9506, 8443-8448, 9091 }
define INFRA_PORTS   = { 5432, 6379, 9000, 9001 }

# ── Base tables ──────────────────────────────────────────────
table inet filter {
    # Rate-limit sets
    set ratelimit_ssh {
        type ipv4_addr
        flags dynamic,timeout
        timeout 10m
    }

    set ratelimit_http {
        type ipv4_addr
        flags dynamic,timeout
        timeout 1m
    }

    set blocklist {
        type ipv4_addr
        flags interval
        # Populate from fail2ban or threat feeds:
        # elements = { 192.0.2.0/24, 198.51.100.0/24 }
    }

    chain input {
        type filter hook input priority 0; policy drop;

        # ── Fundamentals ──
        ct state established,related accept
        ct state invalid drop
        iif lo accept

        # ── Drop known-bad IPs ──
        ip saddr @blocklist drop

        # ── ICMP (allow ping, limit) ──
        ip protocol icmp icmp type {
            echo-request, echo-reply,
            destination-unreachable, time-exceeded
        } limit rate 10/second accept

        ip6 nexthdr icmpv6 icmpv6 type {
            echo-request, echo-reply,
            nd-neighbor-solicit, nd-neighbor-advert,
            nd-router-solicit, nd-router-advert
        } limit rate 10/second accept

        # ── SSH (rate-limited) ──
        tcp dport 22 ct state new \
            add @ratelimit_ssh { ip saddr limit rate 4/minute burst 8 packets } \
            accept

        # ── Anubis public endpoint (rate-limited) ──
        tcp dport 8080 ct state new \
            add @ratelimit_http { ip saddr limit rate 60/minute burst 120 packets } \
            accept

        # ── Block external access to internal services ──
        iifname $WAN_IF tcp dport $ASHCHAN_PORTS drop
        iifname $WAN_IF tcp dport $INFRA_PORTS   drop

        # ── Allow internal (loopback) service traffic ──
        iif lo tcp dport $ASHCHAN_PORTS accept
        iif lo tcp dport $INFRA_PORTS   accept

        # ── Log & drop the rest ──
        limit rate 5/minute log prefix "nft-drop: " level warn
        counter drop
    }

    chain forward {
        type filter hook forward priority 0; policy drop;
    }

    chain output {
        type filter hook output priority 0; policy accept;
    }
}
```

### Apply

```bash
# Validate syntax
nft -c -f /etc/nftables.conf

# Apply
sudo nft -f /etc/nftables.conf

# Persist across reboots
sudo systemctl enable nftables

# Verify
sudo nft list ruleset
```

---

## GNU/Linux — iptables (legacy)

For older systems without nftables:

```bash
#!/usr/bin/env bash
# ashchan-iptables.sh — Ashchan firewall rules (iptables)
set -euo pipefail

IPT="iptables"
IP6T="ip6tables"
WAN_IF="eth0"

# ── Flush ──
$IPT -F && $IPT -X
$IPT -t nat -F && $IPT -t nat -X
$IPT -t mangle -F && $IPT -t mangle -X

# ── Default policy: drop ──
$IPT -P INPUT DROP
$IPT -P FORWARD DROP
$IPT -P OUTPUT ACCEPT

# ── Loopback ──
$IPT -A INPUT -i lo -j ACCEPT
$IPT -A OUTPUT -o lo -j ACCEPT

# ── Established / related ──
$IPT -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
$IPT -A INPUT -m conntrack --ctstate INVALID -j DROP

# ── ICMP ──
$IPT -A INPUT -p icmp --icmp-type echo-request -m limit --limit 10/s -j ACCEPT

# ── SSH (rate-limited) ──
$IPT -A INPUT -p tcp --dport 22 -m conntrack --ctstate NEW \
     -m recent --set --name SSH
$IPT -A INPUT -p tcp --dport 22 -m conntrack --ctstate NEW \
     -m recent --update --seconds 60 --hitcount 5 --name SSH -j DROP
$IPT -A INPUT -p tcp --dport 22 -m conntrack --ctstate NEW -j ACCEPT

# ── Anubis public (rate-limited via hashlimit) ──
$IPT -A INPUT -p tcp --dport 8080 -m conntrack --ctstate NEW \
     -m hashlimit --hashlimit-upto 60/min --hashlimit-burst 120 \
     --hashlimit-mode srcip --hashlimit-name http_limit \
     -j ACCEPT

# ── Block external access to internal services ──
for port in 9501 9502 9503 9504 9505 9506 \
            8443 8444 8445 8446 8447 8448 \
            9091 5432 6379 9000 9001; do
    $IPT -A INPUT -i "$WAN_IF" -p tcp --dport "$port" -j DROP
done

# ── SYN flood protection ──
$IPT -A INPUT -p tcp --syn -m limit --limit 25/s --limit-burst 50 -j ACCEPT
$IPT -A INPUT -p tcp --syn -j DROP

# ── Log & drop ──
$IPT -A INPUT -m limit --limit 5/min -j LOG --log-prefix "iptables-drop: " --log-level 4
$IPT -A INPUT -j DROP

echo "iptables rules applied"
```

### Persist (Debian/Ubuntu)

```bash
sudo apt-get install -y iptables-persistent
sudo netfilter-persistent save
sudo systemctl enable netfilter-persistent
```

### Persist (RHEL/Fedora)

```bash
sudo iptables-save > /etc/sysconfig/iptables
sudo systemctl enable iptables
```

---

## GNU/Linux — firewalld

For distributions using firewalld (Fedora, RHEL, Rocky, Alma):

```bash
# Set default zone to drop
sudo firewall-cmd --set-default-zone=drop

# Allow SSH (rate limiting handled by fail2ban)
sudo firewall-cmd --permanent --add-service=ssh

# Allow Anubis public port
sudo firewall-cmd --permanent --add-port=8080/tcp

# Allow loopback-only service ports (trusted zone)
sudo firewall-cmd --permanent --zone=trusted --add-interface=lo

# Add internal ports to trusted zone (loopback only)
for port in 9501 9502 9503 9504 9505 9506 \
            8443 8444 8445 8446 8447 8448 \
            9091 5432 6379 9000 9001; do
    sudo firewall-cmd --permanent --zone=trusted --add-port=${port}/tcp
done

# Enable logging of dropped packets
sudo firewall-cmd --permanent --set-log-denied=unicast

# Reload
sudo firewall-cmd --reload
sudo firewall-cmd --list-all
```

---

## FreeBSD — pf (recommended)

### /etc/pf.conf

```
# ── Ashchan pf.conf ─────────────────────────────────────────
# FreeBSD Packet Filter configuration

# ── Macros ──
ext_if = "vtnet0"
ashchan_ports = "{ 9501:9506, 8443:8448, 9091 }"
infra_ports   = "{ 5432, 6379, 9000, 9001 }"

# ── Tables ──
table <bruteforce>  persist
table <blocklist>   persist file "/etc/pf.blocklist"

# ── Options ──
set skip on lo0
set block-policy drop
set loginterface $ext_if
set optimization aggressive
set limit { states 100000, frags 25000 }

# ── Normalisation ──
scrub in all fragment reassemble no-df max-mss 1440

# ── Queueing (optional ALTQ) ──
# altq on $ext_if bandwidth 1Gb hfsc queue { q_pri, q_def }
# queue q_pri priority 7 hfsc(realtime 30%)
# queue q_def priority 1 hfsc(default)

# ── Filter rules ──

# Block everything by default
block log all

# Drop known-bad IPs
block drop in  quick on $ext_if from <blocklist>
block drop in  quick on $ext_if from <bruteforce>

# Allow outbound
pass out quick on $ext_if keep state

# Allow ICMP (rate-limited)
pass in on $ext_if inet proto icmp icmp-type { echoreq, unreach, timex } \
    keep state (max-src-conn-rate 10/10)

# SSH — brute-force protection via overload table
pass in on $ext_if proto tcp to port 22 keep state \
    (max-src-conn 10, max-src-conn-rate 4/60, \
     overload <bruteforce> flush global)

# Anubis — public HTTP with connection limits
pass in on $ext_if proto tcp to port 8080 keep state \
    (max-src-conn 100, max-src-conn-rate 60/60, \
     overload <bruteforce> flush)

# Block external access to all internal service ports
block drop in on $ext_if proto tcp to port $ashchan_ports
block drop in on $ext_if proto tcp to port $infra_ports

# Allow all loopback
pass quick on lo0

# ── Expiry for brute-force table ──
# Add to cron: pfctl -t bruteforce -T expire 86400
```

### Apply

```bash
# Check syntax
pfctl -nf /etc/pf.conf

# Load rules
pfctl -f /etc/pf.conf

# Enable pf
sysrc pf_enable=YES
service pf start

# View active rules
pfctl -sr

# View blocked IPs
pfctl -t bruteforce -T show
```

### Cron for table expiry

```bash
# /etc/cron.d/pf-expire
# Flush bruteforce entries older than 24 hours
0 * * * * root /sbin/pfctl -t bruteforce -T expire 86400
```

---

## FreeBSD — ipfw

```bash
#!/bin/sh
# ashchan-ipfw.sh — Ashchan firewall rules (IPFW)

# Flush existing rules
ipfw -q flush

# Allow loopback
ipfw -q add 100 allow all from any to any via lo0

# Allow established connections
ipfw -q add 200 allow tcp from any to any established

# SSH (rate-limited: 4 connections per 60 seconds per source)
ipfw -q add 300 allow tcp from any to me 22 setup limit src-addr 4

# Anubis public HTTP
ipfw -q add 400 allow tcp from any to me 8080 setup limit src-addr 100

# Block external access to internal service ports
for port in 9501 9502 9503 9504 9505 9506 \
            8443 8444 8445 8446 8447 8448 \
            9091 5432 6379 9000 9001; do
    ipfw -q add 500 deny tcp from any to me ${port} in recv vtnet0
done

# Allow ICMP
ipfw -q add 600 allow icmp from any to any icmptypes 0,3,8,11

# Allow outbound
ipfw -q add 700 allow all from me to any out

# Default deny
ipfw -q add 65534 deny log all from any to any

echo "ipfw rules applied"
```

### Persist on FreeBSD

```bash
# /etc/rc.conf
firewall_enable="YES"
firewall_type="/etc/ipfw.rules"
firewall_logging="YES"
```

---

## fail2ban

### Installation

```bash
# Debian/Ubuntu
sudo apt-get install -y fail2ban

# RHEL/Fedora
sudo dnf install -y fail2ban

# Alpine Linux
sudo apk add fail2ban

# FreeBSD
sudo pkg install py39-fail2ban
```

### /etc/fail2ban/jail.local

```ini
[DEFAULT]
# Ban for 1 hour, increase on repeat offenders
bantime  = 3600
# 5 failures within 10 minutes triggers a ban
findtime = 600
maxretry = 5
# Use nftables on Linux (change to pf on FreeBSD)
banaction = nftables-multiport
# Email alerts (optional)
# destemail = admin@ashchan.local
# action = %(action_mwl)s

# ── SSH brute-force ─────────────────────────────────────────
[sshd]
enabled  = true
port     = ssh
filter   = sshd
logpath  = /var/log/auth.log
maxretry = 3
bantime  = 7200
findtime = 300

# Progressive ban: repeat offenders get longer bans
[sshd-progressive]
enabled  = true
port     = ssh
filter   = sshd
logpath  = /var/log/auth.log
maxretry = 2
bantime  = 86400
findtime = 86400

# ── Anubis / HTTP abuse ────────────────────────────────────
[ashchan-http-flood]
enabled  = true
port     = 8080
filter   = ashchan-http-flood
logpath  = /tmp/ashchan-gateway.log
maxretry = 120
findtime = 60
bantime  = 3600

[ashchan-http-4xx]
enabled  = true
port     = 8080
filter   = ashchan-http-4xx
logpath  = /tmp/ashchan-gateway.log
maxretry = 30
findtime = 300
bantime  = 1800

[ashchan-http-auth]
enabled  = true
port     = 8080
filter   = ashchan-http-auth
logpath  = /tmp/ashchan-auth.log
maxretry = 5
findtime = 300
bantime  = 7200

# ── Spam / rapid posting ───────────────────────────────────
[ashchan-post-flood]
enabled  = true
port     = 8080
filter   = ashchan-post-flood
logpath  = /tmp/ashchan-boards.log
maxretry = 10
findtime = 60
bantime  = 3600

# ── PostgreSQL brute-force ──────────────────────────────────
[postgresql]
enabled  = true
port     = 5432
filter   = ashchan-postgresql
logpath  = /var/log/postgresql/postgresql-*-main.log
maxretry = 3
bantime  = 7200

# ── Redis brute-force ──────────────────────────────────────
[redis]
enabled  = true
port     = 6379
filter   = ashchan-redis
logpath  = /var/log/redis/redis-server.log
maxretry = 3
bantime  = 7200

# ── Recidive (ban repeat offenders across all jails) ────────
[recidive]
enabled  = true
filter   = recidive
logpath  = /var/log/fail2ban.log
maxretry = 3
findtime = 86400
bantime  = 604800
action   = nftables-allports[name=recidive]
```

### Custom fail2ban Filters

#### /etc/fail2ban/filter.d/ashchan-http-flood.conf

```ini
[Definition]
# Match any HTTP request log line from the API Gateway
# Adjust the regex to match your actual Swoole/Hyperf log format
failregex = ^\[.*\]\s+<HOST>\s+.*\s+(GET|POST|PUT|DELETE|HEAD|OPTIONS)\s+
ignoreregex =
datepattern = {^LN-BEG}
```

#### /etc/fail2ban/filter.d/ashchan-http-4xx.conf

```ini
[Definition]
# Match 4xx client errors (scanners, bots probing paths)
failregex = ^\[.*\]\s+<HOST>\s+.*\s+HTTP/\d\.\d"\s+4\d\d\s+
ignoreregex =
datepattern = {^LN-BEG}
```

#### /etc/fail2ban/filter.d/ashchan-http-auth.conf

```ini
[Definition]
# Match failed authentication attempts
failregex = ^\[.*\]\s+.*"ip":\s*"<HOST>".*"event":\s*"auth_failed"
            ^\[.*\]\s+.*<HOST>.*401\s+
ignoreregex =
datepattern = {^LN-BEG}
```

#### /etc/fail2ban/filter.d/ashchan-post-flood.conf

```ini
[Definition]
# Match rapid POST requests to thread/post creation endpoints
failregex = ^\[.*\]\s+<HOST>\s+.*POST\s+/api/v1/boards/.*/threads
            ^\[.*\]\s+<HOST>\s+.*POST\s+/api/v1/boards/.*/posts
ignoreregex =
datepattern = {^LN-BEG}
```

#### /etc/fail2ban/filter.d/ashchan-postgresql.conf

```ini
[Definition]
failregex = FATAL:\s+password authentication failed for user.*client\s+<HOST>
            FATAL:\s+no pg_hba.conf entry for host\s+"<HOST>"
ignoreregex =
datepattern = {^LN-BEG}
```

#### /etc/fail2ban/filter.d/ashchan-redis.conf

```ini
[Definition]
failregex = Client <HOST>:\d+ failed auth
ignoreregex =
```

### FreeBSD fail2ban (pf backend)

```ini
# In /etc/fail2ban/jail.local on FreeBSD, override banaction:
[DEFAULT]
banaction = pf
```

### Verify fail2ban

```bash
# Check jail status
sudo fail2ban-client status

# Check a specific jail
sudo fail2ban-client status ashchan-http-flood

# Test a filter against a log file
sudo fail2ban-regex /tmp/ashchan-gateway.log /etc/fail2ban/filter.d/ashchan-http-flood.conf

# Unban an IP
sudo fail2ban-client set ashchan-http-flood unbanip 192.0.2.1

# View banned IPs (nftables)
sudo nft list set inet filter f2b-ashchan-http-flood
```

---

## sysctl Hardening

### GNU/Linux — /etc/sysctl.d/99-ashchan.conf

```ini
# ── Network stack hardening ──────────────────────────────────

# Ignore ICMP redirects (prevent MITM)
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv6.conf.default.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0

# Ignore source-routed packets
net.ipv4.conf.all.accept_source_route = 0
net.ipv6.conf.all.accept_source_route = 0

# Enable reverse path filtering (anti-spoofing)
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1

# SYN flood protection
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 4096
net.ipv4.tcp_synack_retries = 2

# Disable IP forwarding (unless acting as router)
net.ipv4.ip_forward = 0
net.ipv6.conf.all.forwarding = 0

# Ignore ICMP broadcasts (smurf protection)
net.ipv4.icmp_echo_ignore_broadcasts = 1

# Log martians (spoofed/impossible addresses)
net.ipv4.conf.all.log_martians = 1
net.ipv4.conf.default.log_martians = 1

# ── Performance tuning for high-connection servers ───────────

# Increase connection tracking table
net.netfilter.nf_conntrack_max = 1048576
net.netfilter.nf_conntrack_tcp_timeout_established = 3600

# Increase local port range
net.ipv4.ip_local_port_range = 10000 65535

# Reuse TIME_WAIT sockets
net.ipv4.tcp_tw_reuse = 1

# Faster TCP keepalives (detect dead connections)
net.ipv4.tcp_keepalive_time = 600
net.ipv4.tcp_keepalive_intvl = 30
net.ipv4.tcp_keepalive_probes = 5

# Increase socket buffer sizes (Swoole benefits)
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.core.rmem_default = 1048576
net.core.wmem_default = 1048576
net.ipv4.tcp_rmem = 4096 1048576 16777216
net.ipv4.tcp_wmem = 4096 1048576 16777216

# Increase somaxconn for Swoole listeners
net.core.somaxconn = 65535
net.core.netdev_max_backlog = 65535

# ── File descriptor limits ───────────────────────────────────
fs.file-max = 2097152
```

Apply:

```bash
sudo sysctl -p /etc/sysctl.d/99-ashchan.conf
```

### FreeBSD — /etc/sysctl.conf

```ini
# ── Network hardening ──
net.inet.tcp.blackhole=2
net.inet.udp.blackhole=1
net.inet.icmp.icmplim=50
net.inet.tcp.syncookies=1
net.inet.tcp.always_keepalive=1
net.inet.tcp.rfc1323=1
net.inet.tcp.path_mtu_discovery=1
net.inet.ip.redirect=0
net.inet.ip.sourceroute=0
net.inet.ip.accept_sourceroute=0

# ── Performance ──
kern.ipc.somaxconn=65535
kern.ipc.maxsockets=204800
net.inet.tcp.sendspace=65536
net.inet.tcp.recvspace=65536
kern.maxfiles=204800
kern.maxfilesperproc=102400
```

---

## Additional Security Tools

### CrowdSec (modern fail2ban alternative)

[CrowdSec](https://crowdsec.net/) is a collaborative IPS that shares threat intelligence
across the community. It detects attacks locally and can pull ban lists from a shared
reputation database.

```bash
# Install (Debian/Ubuntu)
curl -s https://packagecloud.io/install/repositories/crowdsec/crowdsec/script.deb.sh | sudo bash
sudo apt-get install -y crowdsec crowdsec-firewall-bouncer-nftables

# Install (FreeBSD)
sudo pkg install crowdsec

# Enroll and start
sudo cscli hub update
sudo cscli collections install crowdsecurity/linux
sudo cscli collections install crowdsecurity/http-cve
sudo cscli collections install crowdsecurity/nginx  # closest to Swoole HTTP logs
sudo systemctl enable --now crowdsec
sudo systemctl enable --now crowdsec-firewall-bouncer-nftables

# Check decisions
sudo cscli decisions list
```

### abuseipdb-cli (IP reputation lookup)

Check IPs against the [AbuseIPDB](https://www.abuseipdb.com/) database before banning
or when investigating attacks:

```bash
# Install
pip3 install abuseipdb-cli

# Check an IP
abuseipdb check 192.0.2.1

# Report an IP from fail2ban (add to jail action)
abuseipdb report <ip> -c 18 -m "HTTP flood against ashchan"
```

### Suricata (network IDS/IPS)

[Suricata](https://suricata.io/) inspects traffic at the network level and can detect
exploit attempts, C2 traffic, and protocol anomalies.

```bash
# Install
sudo apt-get install -y suricata

# Or on FreeBSD
sudo pkg install suricata

# Update rules
sudo suricata-update

# Run in IDS mode (monitor only)
sudo suricata -c /etc/suricata/suricata.yaml -i eth0

# Run in IPS mode (inline blocking — requires nfqueue or netmap)
sudo suricata -c /etc/suricata/suricata.yaml --af-packet -D
```

### rkhunter & chkrootkit (rootkit detection)

```bash
# Install
sudo apt-get install -y rkhunter chkrootkit

# Scan
sudo rkhunter --check --sk
sudo chkrootkit

# Cron — daily scan
echo '0 3 * * * root /usr/bin/rkhunter --check --sk --report-warnings-only | mail -s "rkhunter $(hostname)" admin@ashchan.local' \
    | sudo tee /etc/cron.d/rkhunter
```

### OSSEC / Wazuh (host intrusion detection)

[Wazuh](https://wazuh.com/) (OSSEC fork) monitors file integrity, log analysis, and
rootkit detection in real time.

```bash
# Install agent
curl -s https://packages.wazuh.com/4.x/apt/KEY.GPG | sudo apt-key add -
echo "deb https://packages.wazuh.com/4.x/apt/ stable main" | \
    sudo tee /etc/apt/sources.list.d/wazuh.list
sudo apt-get update && sudo apt-get install -y wazuh-agent
```

### Lynis (security audit)

[Lynis](https://cisofy.com/lynis/) runs a comprehensive security audit of the system:

```bash
# Install
sudo apt-get install -y lynis   # Debian/Ubuntu
sudo pkg install lynis          # FreeBSD

# Run full audit
sudo lynis audit system

# Run specific test group
sudo lynis audit system --tests-from-group "firewalls"
```

---

## Process Isolation

### systemd hardening (Linux)

When running services via systemd, restrict their capabilities:

```ini
# Add to each ashchan-*.service or ashchan-static@.service

[Service]
# Run as unprivileged user
User=ashchan
Group=ashchan

# Filesystem restrictions
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/tmp /opt/ashchan/runtime
PrivateTmp=yes

# Network restrictions (keep only TCP)
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX

# Capability restrictions
CapabilityBoundingSet=
NoNewPrivileges=yes

# System call filtering
SystemCallFilter=@system-service
SystemCallArchitectures=native

# Memory protection
MemoryDenyWriteExecute=yes
```

### FreeBSD jails

On FreeBSD, run Ashchan inside a jail for additional isolation:

```bash
# Create thin jail
sudo bastille create ashchan 14.0-RELEASE 10.90.0.10

# Install PHP inside jail
sudo bastille pkg ashchan install php84 php84-swoole php84-pdo_pgsql php84-redis

# Copy service files
sudo bastille cp ashchan /opt/ashchan/ services/

# Start service inside jail
sudo bastille cmd ashchan /opt/ashchan/ashchan start
```

---

## Monitoring & Alerting

### Prometheus metrics

Anubis exports metrics on port 9091. Scrape them with Prometheus and set up alerts:

```yaml
# /etc/prometheus/prometheus.yml (snippet)
scrape_configs:
  - job_name: 'ashchan-anubis'
    static_configs:
      - targets: ['localhost:9091']
    scrape_interval: 15s

  - job_name: 'ashchan-services'
    static_configs:
      - targets:
        - 'localhost:9501'
        - 'localhost:9502'
        - 'localhost:9503'
        - 'localhost:9504'
        - 'localhost:9505'
        - 'localhost:9506'
    metrics_path: /health
    scrape_interval: 30s
```

### Alert rules (Prometheus)

```yaml
# /etc/prometheus/rules/ashchan.yml
groups:
  - name: ashchan
    rules:
      - alert: AshchanServiceDown
        expr: up{job="ashchan-services"} == 0
        for: 2m
        labels:
          severity: critical
        annotations:
          summary: "Service {{ $labels.instance }} is down"

      - alert: AshchanHighErrorRate
        expr: rate(http_requests_total{status=~"5.."}[5m]) > 0.1
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High 5xx rate on {{ $labels.instance }}"

      - alert: AshchanHighLatency
        expr: histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m])) > 2
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "P95 latency above 2s on {{ $labels.instance }}"
```

---

## Quick Reference

### Day-one checklist

```
[ ] Apply firewall rules (nftables/pf)
[ ] Enable and persist firewall on boot
[ ] Install and configure fail2ban
[ ] Apply sysctl hardening
[ ] Create unprivileged ashchan user
[ ] Enable systemd hardening directives
[ ] Set up SSH key-only authentication
[ ] Disable root SSH login
[ ] Install Lynis, run first audit
[ ] Set up monitoring (Prometheus + Alertmanager)
[ ] Test: port scan from external host (nmap)
[ ] Test: fail2ban triggers correctly
```

### Verify external exposure

```bash
# From another machine, scan the server — only 22 and 8080 should be open
nmap -Pn -sT -p 22,8080,5432,6379,9000,9501-9506,8443-8448,9091 <server-ip>

# Expected:
#   22/tcp   open   ssh
#   8080/tcp open   http-proxy
#   (all others: closed or filtered)
```

### Emergency: block an IP immediately

```bash
# nftables (Linux)
sudo nft add element inet filter blocklist '{ 192.0.2.1 }'

# iptables (Linux)
sudo iptables -I INPUT -s 192.0.2.1 -j DROP

# pf (FreeBSD)
sudo pfctl -t blocklist -T add 192.0.2.1

# ipfw (FreeBSD)
sudo ipfw add 1 deny all from 192.0.2.1 to any

# fail2ban (any OS)
sudo fail2ban-client set ashchan-http-flood banip 192.0.2.1
```

---

## See Also

- [docs/NGINX_HARDENING.md](NGINX_HARDENING.md) — nginx reverse proxy (TLS, rate limiting, bot blocking)
- [docs/security.md](security.md) — mTLS, encryption, audit logging, compliance
- [docs/anti-spam.md](anti-spam.md) — Application-level anti-spam (Stop Forum Spam, heuristics)
- [docs/SFS_ESCALATION_PLAYBOOK.md](SFS_ESCALATION_PLAYBOOK.md) — Spam escalation procedures
- [docs/TROUBLESHOOTING.md](TROUBLESHOOTING.md) — Debugging service issues
