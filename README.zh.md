# ashchan

[English](README.md) | **中文** | [日本語](README.ja.md)

[![PHP Composer](https://github.com/txrx-byte/ashchan/actions/workflows/php.yml/badge.svg)](https://github.com/txrx-byte/ashchan/actions/workflows/php.yml)
![enbyware](https://pride-badges.pony.workers.dev/static/v1?label=enbyware&labelColor=%23555&stripeWidth=8&stripeColors=FCF434%2CFFFFFF%2C9C59D1%2C2C2C2C)

Ashchan 是一个基于 **Hyperf/Swoole** 构建的高性能、隐私优先的图像版，采用分布式微服务架构。它通过 **PHP-CLI 及 Swoole** 原生运行，无需容器化依赖，提供更简洁的部署模型和直接的进程管理。

## 特性

- **零公网暴露**：通过 Cloudflare Tunnel 接入 —— 源服务器无公网 IP，无开放端口
- **端到端加密**：Cloudflare TLS → 隧道加密 → mTLS 服务网格 —— 100% 加密
- **原生 PHP-CLI**：直接基于 Swoole 的 PHP 进程，无容器开销
- **mTLS 安全**：服务间通信通过双向 TLS 证书保护
- **多层缓存**：Cloudflare CDN → Varnish HTTP 缓存 → Redis 应用缓存
- **隐私优先**：最少数据留存，IP 哈希处理，合规就绪（GDPR/CCPA）
- **水平扩展**：为流量高峰和高可用性而设计
- **Systemd 集成**：生产级服务管理

---

## 快速开始

### 环境要求

- PHP 8.2+ 及 Swoole 扩展
- PostgreSQL 16+
- Redis 7+
- MinIO 或 S3 兼容存储（用于媒体文件）
- OpenSSL（用于证书生成）
- Composer（PHP 依赖管理器）
- Make（构建工具）

#### Alpine Linux (apk)

```bash
# PHP 8.4 + 必需扩展
sudo apk add --no-cache \
  php84 php84-openssl php84-pdo php84-pdo_pgsql php84-mbstring \
  php84-curl php84-pcntl php84-phar php84-iconv php84-dom php84-xml \
  php84-xmlwriter php84-tokenizer php84-fileinfo php84-ctype \
  php84-posix php84-session php84-sockets \
  php84-pecl-swoole php84-pecl-redis \
  openssl composer postgresql-client redis make

# 如果 php 符号链接不存在则创建
sudo ln -sf $(which php84) /usr/local/bin/php
```

#### Ubuntu/Debian (apt)

```bash
sudo apt-get install -y \
  php8.2 php8.2-cli php8.2-swoole php8.2-pgsql php8.2-redis \
  php8.2-mbstring php8.2-curl php8.2-xml php8.2-dom \
  openssl composer postgresql-client redis-server make
```

### 安装

```bash
# 1. 为所有服务安装 PHP 依赖
make install

# 2. 生成 mTLS 证书
make mtls-init && make mtls-certs

# 3. 配置服务（根据需要编辑 .env 文件）
# 每个服务在 services/<service-name>/.env 中有自己的 .env 文件

# 4. 启动所有服务
make up

# 5. 运行数据库迁移
make migrate

# 6. 填充数据库种子数据
make seed
```

### 快速开发启动

```bash
# 完整引导（安装依赖、生成证书、启动服务）
make bootstrap

# 或者在开发过程中快速重启
make dev-quick
```

### 验证健康状态

```bash
# 检查所有服务
make health

# 检查单个服务
curl http://localhost:9501/health

# 检查证书状态
make mtls-status
```

---

## 文档

### 架构与设计
| 文档 | 描述 |
|------|------|
| [docs/architecture.md](docs/architecture.md) | 系统架构、服务边界、网络拓扑 |
| [docs/SERVICEMESH.md](docs/SERVICEMESH.md) | **mTLS 架构、证书管理、安全性** |
| [docs/VARNISH_CACHE.md](docs/VARNISH_CACHE.md) | **Varnish HTTP 缓存层、失效、调优** |
| [docs/system-design.md](docs/system-design.md) | 请求流程、缓存、故障隔离 |
| [docs/security.md](docs/security.md) | 安全控制、加密、审计日志 |
| [docs/FIREWALL_HARDENING.md](docs/FIREWALL_HARDENING.md) | **防火墙、fail2ban、sysctl 加固（Linux 和 FreeBSD）** |

### API 与契约
| 文档 | 描述 |
|------|------|
| [docs/FOURCHAN_API.md](docs/FOURCHAN_API.md) | **4chan 兼容只读 API（精确 4chan 格式输出）** |
| [contracts/openapi/README.md](contracts/openapi/README.md) | 各服务 API 规范 |
| [contracts/events/README.md](contracts/events/README.md) | 领域事件模式 |

### 数据库与迁移
| 文档 | 描述 |
|------|------|
| [db/README.md](db/README.md) | 数据库迁移与模式 |

### 服务
| 服务 | 端口 | 描述 |
|------|------|------|
| [services/api-gateway](services/api-gateway) | 9501 | API 网关、路由、限流 |
| [services/auth-accounts](services/auth-accounts) | 9502 | 认证/账户服务 |
| [services/boards-threads-posts](services/boards-threads-posts) | 9503 | 版块/帖子/回复服务 |
| [services/media-uploads](services/media-uploads) | 9504 | 媒体上传及处理 |
| [services/search-indexing](services/search-indexing) | 9505 | 搜索后端 |
| [services/moderation-anti-spam](services/moderation-anti-spam) | 9506 | 审核与反垃圾 |

---

## 架构

```
╔═════════════════════════ 公共互联网 ═══════════════════════════╗
║                                                                          ║
║  客户端 ── TLS 1.3 ──▶ Cloudflare 边缘节点 (WAF, DDoS, CDN)              ║
║                              │                                          ║
║                       Cloudflare Tunnel                                 ║
║                       (仅出站、加密)                                      ║
║                              │                                          ║
╚══════════════════════════════┼═══════════════════════════════╝
                              │
╔══════════════════════════════┼═ 源站 (无公开端口) ══════════╗
║                              │                                          ║
║                     ┌────────▼───────┐                                   ║
║                     │ cloudflared      │                                   ║
║                     └────────┬───────┘                                   ║
║                              │                                          ║
║                     ┌────────▼───────┐                                   ║
║                     │ nginx (80)       │─── 静态/媒体 ──┐                ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼───────┐                   │             ║
║                     │ Anubis (8080)   │  PoW 验证        │             ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼───────┐                   │             ║
║                     │ Varnish (6081)  │  HTTP 缓存       │             ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼────────────────────────┘             ║
║                     │        API 网关 (9501)             │             ║
║                     └─────────┬───────────────────────┘             ║
║                              │ mTLS                                    ║
║      ┌───────┬────────┬────────┼────────┬────────┐                    ║
║      │       │        │        │        │        │                    ║
║   ┌──▼──┐ ┌──▼───┐ ┌──▼───┐ ┌──▼───┐ ┌──▼───┐                    ║
║   │ 认证│ │ 版块 │ │ 媒体 │ │ 搜索 │ │ 审核 │                    ║
║   │ 9502│ │ 9503  │ │ 9504  │ │ 9505  │ │ 9506  │                    ║
║   └──┬──┘ └──┬───┘ └──┬───┘ └──┬───┘ └──┬───┘                    ║
║      │       │        │        │        │                           ║
║      └───────┴────────┴────────┴────────┘                           ║
║                     │                                              ║
║      ┌─────────────┼──────────────────┐                           ║
║      │              │                  │                           ║
║  ┌───▼───────┐  ┌──▼────────┐  ┌──▼───────┐                      ║
║  │ PostgreSQL │  │  Redis     │  │ MinIO     │                      ║
║  │   5432     │  │  6379      │  │ 9000/9001 │                      ║
║  └───────────┘  └────┬───────┘  └──────────┘                      ║
║                       │                                              ║
║              Redis Streams (DB 6)                                     ║
║              ashchan:events                                           ║
║       ┌────────────┼────────────┐                                    ║
║       │            │            │                                    ║
║  ┌────▼─────┐  ┌──▼──────┐  ┌─▼────────┐                             ║
║  │ 缓存      │  │ 帖子    │  │ 搜索      │                             ║
║  │ 失效      │  │ 评分    │  │ 索引      │                             ║
║  │ +Varnish  │  │ (审核)  │  │ 消费者    │                             ║
║  └───────────┘  └─────────┘  └───────────┘                             ║
║                                                                          ║
╚══════════════════════════════════════════════════════════════════════════╝
```

**端到端加密：** 客户端 ↔ Cloudflare (TLS 1.3) → Cloudflare Tunnel (加密) → nginx → Anubis (PoW) → Varnish (缓存) → API 网关 → 后端服务 (mTLS)。源服务器 **无公网 IP**，**无开放入站端口** —— `cloudflared` 创建仅出站隧道。

### 服务通信

服务通过 HTTP/HTTPS 在 localhost 或配置的主机地址上进行通信。生产环境 mTLS 部署：

| 服务 | HTTP 端口 | mTLS 端口 | 地址 |
|------|-----------|-----------|------|
| API 网关 | 9501 | 8443 | localhost 或配置的主机 |
| 认证/账户 | 9502 | 8443 | localhost 或配置的主机 |
| 版块/帖子/回复 | 9503 | 8443 | localhost 或配置的主机 |
| 媒体/上传 | 9504 | 8443 | localhost 或配置的主机 |
| 搜索/索引 | 9505 | 8443 | localhost 或配置的主机 |
| 审核/反垃圾 | 9506 | 8443 | localhost 或配置的主机 |

---

## Makefile 命令

### 开发
```bash
make install      # 为所有服务复制 .env.example 到 .env
make up           # 启动所有服务（原生 PHP 进程）
make down         # 停止所有服务
make logs         # 查看合并日志
make migrate      # 运行数据库迁移
make seed         # 填充数据库种子数据
make test         # 运行所有服务测试
make lint         # 检查所有 PHP 代码
make phpstan      # 运行 PHPStan 静态分析
```

### 引导与快速启动
```bash
make bootstrap    # 完整设置（依赖、证书、服务、迁移、种子数据）
make dev-quick    # 开发迭代快速重启
```

### mTLS 证书
```bash
make mtls-init    # 生成服务网格根 CA
make mtls-certs   # 生成所有服务证书
make mtls-verify  # 验证 mTLS 配置
make mtls-rotate  # 轮换所有服务证书
make mtls-status  # 显示证书过期状态
```

### 服务管理
```bash
make start-<svc>  # 启动特定服务
make stop-<svc>   # 停止特定服务
make restart      # 重启所有服务
make health       # 检查所有服务健康状态
make clean        # 清理运行时产物
make clean-certs  # 删除所有生成的证书
```

### 静态二进制构建（可选）

构建无需 PHP 运行时依赖的便携式独立可执行文件。使用 [static-php-cli](https://github.com/crazywhalecc/static-php-cli) 将 PHP + Swoole + 所有扩展编译为每个服务一个静态二进制文件。

```bash
make build-static           # 将所有服务构建为静态二进制
make build-static-gateway   # 仅构建网关
make build-static-boards    # 仅构建版块服务
make build-static-php       # 仅构建静态 PHP 二进制
make build-static-clean     # 删除构建产物
```

输出二进制文件位于 `build/static-php/dist/`：
```bash
./build/static-php/dist/ashchan-gateway start     # 无需安装 PHP
PORT=9501 ./ashchan-gateway start                  # 通过环境变量覆盖端口
```

详见 [build/static-php/build.sh](build/static-php/build.sh) 获取完整选项和环境变量。

---

## 证书管理

### 生成证书

```bash
# 生成根 CA（有效期 10 年）
./scripts/mtls/generate-ca.sh

# 生成所有服务证书（有效期 1 年）
./scripts/mtls/generate-all-certs.sh

# 生成单个服务证书
./scripts/mtls/generate-cert.sh gateway localhost
```

### 验证证书

```bash
# 验证整个网格
./scripts/mtls/verify-mesh.sh

# 检查单个证书
openssl x509 -in certs/services/gateway/gateway.crt -text -noout

# 验证证书链
openssl verify -CAfile certs/ca/ca.crt certs/services/gateway/gateway.crt
```

### 证书位置

```
certs/
├── ca/
│   ├── ca.crt              # 根 CA 证书
│   ├── ca.key              # 根 CA 私钥
│   └── ca.cnf              # CA 配置
└── services/
    ├── gateway/
    │   ├── gateway.crt     # 网关证书
    │   └── gateway.key     # 网关私钥
    ├── auth/
    ├── boards/
    ├── media/
    ├── search/
    └── moderation/
```

---

## 开发

### 运行单个服务

```bash
# 启动单个服务进行开发
cd services/api-gateway
composer install
cp .env.example .env
# 编辑 .env 以配置数据库、Redis 等
php bin/hyperf.php start
```

### 运行测试

```bash
# 运行所有测试
make test

# 运行单个服务测试
cd services/boards-threads-posts
composer test

# 运行覆盖率测试
composer test -- --coverage-html coverage/
```

### 代码风格

```bash
# 检查所有服务
make lint

# 运行 PHPStan
make phpstan

# 修复代码风格（按服务）
cd services/api-gateway
composer cs-fix
```

---

## 部署

### 生产环境要求

- **PHP 8.2+** 及扩展：swoole、openssl、curl、pdo、pdo_pgsql、redis、mbstring、json、pcntl
- **PostgreSQL 16+** 用于持久化存储
- **Redis 7+** 用于缓存、限流和队列
- **MinIO** 或 S3 兼容存储用于媒体文件
- **Systemd** 用于进程管理（推荐）

### Systemd 服务示例

```ini
# /etc/systemd/system/ashchan-gateway.service
[Unit]
Description=Ashchan API 网关
After=network.target postgresql.service redis.service

[Service]
Type=simple
User=ashchan
Group=ashchan
WorkingDirectory=/opt/ashchan/services/api-gateway
Environment=APP_ENV=production
ExecStart=/usr/bin/php bin/hyperf.php start
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### 生产环境检查清单

- [ ] 生成生产环境 CA（与开发环境分离）
- [ ] 配置服务端口防火墙规则
- [ ] 设置日志聚合（例如 journald → Loki）
- [ ] 配置 PostgreSQL 备份策略
- [ ] 建立监控和告警（例如 Prometheus）
- [ ] 测试证书轮换流程
- [ ] 编写常见操作运维手册
- [ ] 根据流量预期配置限流

---

## 故障排查

### 常见问题

| 问题 | 解决方案 |
|------|----------|
| 服务无法启动 | 查看日志：`journalctl -u ashchan-<service>` |
| 数据库连接错误 | 验证 PostgreSQL 正在运行且 `.env` 配置正确 |
| Redis 连接错误 | 验证 Redis 正在运行且密码匹配 |
| mTLS 握手失败 | 重新生成证书：`make mtls-certs` |
| 端口已被占用 | 检查现有进程：`lsof -i :<port>` |

### 调试命令

```bash
# 检查服务状态
systemctl status ashchan-gateway

# 查看服务日志
journalctl -u ashchan-gateway -f

# 测试 mTLS 连接
curl --cacert certs/ca/ca.crt \
     --cert certs/services/gateway/gateway.crt \
     --key certs/services/gateway/gateway.key \
     https://localhost:8443/health

# 检查 PHP 扩展
php -m | grep -E 'swoole|openssl|pdo|redis'
```

### 另请参阅
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) - 详细故障排查指南

---

## 贡献

参见 [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) 了解指南。

### 提交信息
使用约定式提交：`feat:`、`fix:`、`docs:`、`refactor:`、`test:`

### 代码风格
- 符合 PSR-12
- 必须使用类型提示（`declare(strict_types=1);`）
- PHPStan Level 10 静态分析

---

## 许可证

基于 Apache License, Version 2.0 授权。完整文本请参见 [LICENSE](LICENSE)。

---

## 状态

✅ mTLS 证书生成和轮换脚本
✅ 服务脚手架和迁移
✅ OpenAPI 契约
✅ 事件模式
✅ 审核系统（从 OpenYotsuba 移植）
✅ 原生 PHP-CLI 部署模型

🚧 领域逻辑实现
🚧 事件发布/消费
🚧 集成测试
