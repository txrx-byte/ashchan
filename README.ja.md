# ashchan

[English](README.md) | [中文](README.zh.md) | **日本語**

[![PHP Composer](https://github.com/txrx-byte/ashchan/actions/workflows/php.yml/badge.svg)](https://github.com/txrx-byte/ashchan/actions/workflows/php.yml)
![enbyware](https://pride-badges.pony.workers.dev/static/v1?label=enbyware&labelColor=%23555&stripeWidth=8&stripeColors=FCF434%2CFFFFFF%2C9C59D1%2C2C2C2C)

Ashchan は **Hyperf/Swoole** 上に構築された高性能でプライバシー重視の画像掲示板です。分散マイクロサービスアーキテクチャを採用しています。コンテナ化に依存せず **PHP-CLI + Swoole** でネイティブ実行され、シンプルなデプロイモデルとプロセスの直接管理を提供します。

## 特徴

- **ゼロパブリック公開**：Cloudflare Tunnel 経由のイングレス — オリジンサーバーにパブリック IP やオープンポートなし
- **エンドツーエンド暗号化**：Cloudflare TLS → トンネル暗号化 → mTLS サービスメッシュ — 100% 暗号化
- **ネイティブ PHP-CLI**：コンテナオーバーヘッドなしの Swoole ベース PHP プロセス
- **mTLS セキュリティ**：相互 TLS 証明書によるサービス間通信の保護
- **多層キャッシュ**：Cloudflare CDN → Varnish HTTP キャッシュ → Redis アプリケーションキャッシュ
- **プライバシー重視**：最小限のデータ保持、IP ハッシュ化、コンプライアンス対応（GDPR/CCPA）
- **水平スケーリング**：トラフィックスパイクと高可用性に対応した設計
- **Systemd 統合**：プロダクション対応のサービス管理

---

## クイックスタート

### 必要条件

- PHP 8.2+ Swoole エクステンション付き
- PostgreSQL 16+
- Redis 7+
- MinIO または S3 互換ストレージ（メディア用）
- OpenSSL（証明書生成用）
- Composer（PHP 依存関係マネージャー）
- Make（ビルドツール）

#### Alpine Linux (apk)

```bash
# PHP 8.4 + 必要なエクステンション
sudo apk add --no-cache \
  php84 php84-openssl php84-pdo php84-pdo_pgsql php84-mbstring \
  php84-curl php84-pcntl php84-phar php84-iconv php84-dom php84-xml \
  php84-xmlwriter php84-tokenizer php84-fileinfo php84-ctype \
  php84-posix php84-session php84-sockets \
  php84-pecl-swoole php84-pecl-redis \
  openssl composer postgresql-client redis make

# php シンボリックリンクがない場合は作成
sudo ln -sf $(which php84) /usr/local/bin/php
```

#### Ubuntu/Debian (apt)

```bash
sudo apt-get install -y \
  php8.2 php8.2-cli php8.2-swoole php8.2-pgsql php8.2-redis \
  php8.2-mbstring php8.2-curl php8.2-xml php8.2-dom \
  openssl composer postgresql-client redis-server make
```

### インストール

```bash
# 1. すべてのサービスの PHP 依存関係をインストール
make install

# 2. mTLS 証明書を生成
make mtls-init && make mtls-certs

# 3. サービスを設定（必要に応じて .env ファイルを編集）
# 各サービスは services/<service-name>/.env に独自の .env ファイルを持ちます

# 4. すべてのサービスを起動
make up

# 5. データベースマイグレーションを実行
make migrate

# 6. データベースのシードデータを投入
make seed
```

### 開発クイックスタート

```bash
# 完全ブートストラップ（依存関係インストール、証明書生成、サービス起動）
make bootstrap

# 開発中の簡易リスタート
make dev-quick
```

### ヘルスチェック

```bash
# すべてのサービスを確認
make health

# 個別サービスを確認
curl http://localhost:9501/health

# 証明書ステータスを確認
make mtls-status
```

---

## ドキュメント

### アーキテクチャと設計
| ドキュメント | 説明 |
|------------|------|
| [docs/architecture.md](docs/architecture.md) | システムアーキテクチャ、サービス境界、ネットワークトポロジー |
| [docs/SERVICEMESH.md](docs/SERVICEMESH.md) | **mTLS アーキテクチャ、証明書管理、セキュリティ** |
| [docs/VARNISH_CACHE.md](docs/VARNISH_CACHE.md) | **Varnish HTTP キャッシュレイヤー、無効化、チューニング** |
| [docs/system-design.md](docs/system-design.md) | リクエストフロー、キャッシュ、障害分離 |
| [docs/security.md](docs/security.md) | セキュリティコントロール、暗号化、監査ログ |
| [docs/FIREWALL_HARDENING.md](docs/FIREWALL_HARDENING.md) | **ファイアウォール、fail2ban、sysctl ハードニング（Linux & FreeBSD）** |

### API とコントラクト
| ドキュメント | 説明 |
|------------|------|
| [docs/FOURCHAN_API.md](docs/FOURCHAN_API.md) | **4chan 互換リードオンリー API（4chan 形式準拠の出力）** |
| [contracts/openapi/README.md](contracts/openapi/README.md) | サービス別 API 仕様 |
| [contracts/events/README.md](contracts/events/README.md) | ドメインイベントスキーマ |

### データベースとマイグレーション
| ドキュメント | 説明 |
|------------|------|
| [db/README.md](db/README.md) | データベースマイグレーションとスキーマ |

### サービス
| サービス | ポート | 説明 |
|---------|--------|------|
| [services/api-gateway](services/api-gateway) | 9501 | API ゲートウェイ、ルーティング、レート制限 |
| [services/auth-accounts](services/auth-accounts) | 9502 | 認証/アカウントサービス |
| [services/boards-threads-posts](services/boards-threads-posts) | 9503 | 板/スレッド/投稿サービス |
| [services/media-uploads](services/media-uploads) | 9504 | メディアアップロードと処理 |
| [services/search-indexing](services/search-indexing) | 9505 | 検索バックエンド |
| [services/moderation-anti-spam](services/moderation-anti-spam) | 9506 | モデレーションとスパム対策 |

---

## アーキテクチャ

```
╔═════════════════════════ パブリックインターネット ═══════════════════╗
║                                                                          ║
║  クライアント ── TLS 1.3 ──▶ Cloudflare エッジ (WAF, DDoS, CDN)         ║
║                              │                                          ║
║                       Cloudflare Tunnel                                 ║
║                       (アウトバウンドのみ、暗号化)                         ║
║                              │                                          ║
╚══════════════════════════════┼═══════════════════════════════╝
                              │
╔══════════════════════════════┼═ オリジン (公開ポートなし) ═════╗
║                              │                                          ║
║                     ┌────────▼───────┐                                   ║
║                     │ cloudflared      │                                   ║
║                     └────────┬───────┘                                   ║
║                              │                                          ║
║                     ┌────────▼───────┐                                   ║
║                     │ nginx (80)       │─── 静的/メディア ──┐             ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼───────┐                   │             ║
║                     │ Anubis (8080)   │  PoW チャレンジ   │             ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼───────┐                   │             ║
║                     │ Varnish (6081)  │  HTTP キャッシュ   │             ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼────────────────────────┘             ║
║                     │        API ゲートウェイ (9501)       │             ║
║                     └─────────┬───────────────────────┘             ║
║                              │ mTLS                                    ║
║      ┌───────┬────────┬────────┼────────┬────────┐                    ║
║      │       │        │        │        │        │                    ║
║   ┌──▼──┐ ┌──▼───┐ ┌──▼───┐ ┌──▼───┐ ┌──▼───┐                    ║
║   │ 認証│ │  板  │ │メディア│ │ 検索 │ │モデレ│                    ║
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
║  │ キャッシュ│  │ 投稿    │  │ 検索      │                             ║
║  │ 無効化    │  │ スコア  │  │ インデクス│                             ║
║  │ +Varnish  │  │ (モデレ)│  │ コンシューマ│                           ║
║  └───────────┘  └─────────┘  └───────────┘                             ║
║                                                                          ║
╚══════════════════════════════════════════════════════════════════════════╝
```

**エンドツーエンド暗号化：** クライアント ↔ Cloudflare (TLS 1.3) → Cloudflare Tunnel (暗号化) → nginx → Anubis (PoW) → Varnish (キャッシュ) → API ゲートウェイ → バックエンドサービス (mTLS)。オリジンサーバーには **パブリック IP なし**、**オープンインバウンドポートなし** — `cloudflared` がアウトバウンド専用トンネルを作成します。

### サービス間通信

サービスは localhost または設定されたホストアドレス上で HTTP/HTTPS を介して通信します。mTLS を使用した本番環境：

| サービス | HTTP ポート | mTLS ポート | アドレス |
|---------|-------------|-------------|---------|
| API ゲートウェイ | 9501 | 8443 | localhost または設定されたホスト |
| 認証/アカウント | 9502 | 8443 | localhost または設定されたホスト |
| 板/スレッド/投稿 | 9503 | 8443 | localhost または設定されたホスト |
| メディア/アップロード | 9504 | 8443 | localhost または設定されたホスト |
| 検索/インデクシング | 9505 | 8443 | localhost または設定されたホスト |
| モデレーション/スパム対策 | 9506 | 8443 | localhost または設定されたホスト |

---

## Makefile コマンド

### 開発
```bash
make install      # すべてのサービスの .env.example を .env にコピー
make up           # すべてのサービスを起動（ネイティブ PHP プロセス）
make down         # すべてのサービスを停止
make logs         # 統合ログを表示
make migrate      # データベースマイグレーションを実行
make seed         # データベースのシードデータを投入
make test         # すべてのサービスのテストを実行
make lint         # すべての PHP コードをリント
make phpstan      # PHPStan 静的解析を実行
```

### ブートストラップとクイックスタート
```bash
make bootstrap    # 完全セットアップ（依存関係、証明書、サービス、マイグレーション、シード）
make dev-quick    # 開発イテレーション用クイックリスタート
```

### mTLS 証明書
```bash
make mtls-init    # サービスメッシュのルート CA を生成
make mtls-certs   # すべてのサービス証明書を生成
make mtls-verify  # mTLS 設定を検証
make mtls-rotate  # すべてのサービス証明書をローテーション
make mtls-status  # 証明書の有効期限ステータスを表示
```

### サービス管理
```bash
make start-<svc>  # 特定のサービスを起動
make stop-<svc>   # 特定のサービスを停止
make restart      # すべてのサービスを再起動
make health       # すべてのサービスのヘルスチェック
make clean        # ランタイムアーティファクトをクリーン
make clean-certs  # 生成されたすべての証明書を削除
```

### 静的バイナリビルド（オプション）

PHP ランタイム依存関係なしのポータブルな自己完結型実行可能ファイルをビルドします。[static-php-cli](https://github.com/crazywhalecc/static-php-cli) を使用して PHP + Swoole + すべてのエクステンションをサービスごとに1つの静的バイナリにコンパイルします。

```bash
make build-static           # すべてのサービスを静的バイナリとしてビルド
make build-static-gateway   # ゲートウェイのみビルド
make build-static-boards    # 板サービスのみビルド
make build-static-php       # 静的 PHP バイナリのみビルド
make build-static-clean     # ビルドアーティファクトを削除
```

出力バイナリは `build/static-php/dist/` に配置されます：
```bash
./build/static-php/dist/ashchan-gateway start     # PHP インストール不要
PORT=9501 ./ashchan-gateway start                  # 環境変数でポートをオーバーライド
```

詳細は [build/static-php/build.sh](build/static-php/build.sh) を参照してください。

---

## 証明書管理

### 証明書の生成

```bash
# ルート CA を生成（有効期限 10 年）
./scripts/mtls/generate-ca.sh

# すべてのサービス証明書を生成（有効期限 1 年）
./scripts/mtls/generate-all-certs.sh

# 単一サービスの証明書を生成
./scripts/mtls/generate-cert.sh gateway localhost
```

### 証明書の検証

```bash
# メッシュ全体を検証
./scripts/mtls/verify-mesh.sh

# 単一証明書を確認
openssl x509 -in certs/services/gateway/gateway.crt -text -noout

# 証明書チェーンを検証
openssl verify -CAfile certs/ca/ca.crt certs/services/gateway/gateway.crt
```

### 証明書の場所

```
certs/
├── ca/
│   ├── ca.crt              # ルート CA 証明書
│   ├── ca.key              # ルート CA 秘密鍵
│   └── ca.cnf              # CA 設定
└── services/
    ├── gateway/
    │   ├── gateway.crt     # ゲートウェイ証明書
    │   └── gateway.key     # ゲートウェイ秘密鍵
    ├── auth/
    ├── boards/
    ├── media/
    ├── search/
    └── moderation/
```

---

## 開発

### 個別サービスの実行

```bash
# 開発用に単一サービスを起動
cd services/api-gateway
composer install
cp .env.example .env
# .env を編集して DB、Redis などを設定
php bin/hyperf.php start
```

### テストの実行

```bash
# すべてのテストを実行
make test

# 単一サービスのテストを実行
cd services/boards-threads-posts
composer test

# カバレッジ付きで実行
composer test -- --coverage-html coverage/
```

### コードスタイル

```bash
# すべてのサービスをリント
make lint

# PHPStan を実行
make phpstan

# コードスタイルを修正（サービスごと）
cd services/api-gateway
composer cs-fix
```

---

## デプロイ

### 本番環境の必要条件

- **PHP 8.2+** エクステンション：swoole、openssl、curl、pdo、pdo_pgsql、redis、mbstring、json、pcntl
- **PostgreSQL 16+** 永続ストレージ用
- **Redis 7+** キャッシュ、レート制限、キュー用
- **MinIO** または S3 互換ストレージ（メディアファイル用）
- **Systemd** プロセス管理用（推奨）

### Systemd サービスの例

```ini
# /etc/systemd/system/ashchan-gateway.service
[Unit]
Description=Ashchan API ゲートウェイ
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

### 本番環境チェックリスト

- [ ] 本番環境用 CA を生成（開発用と分離）
- [ ] サービスポートのファイアウォールルールを設定
- [ ] ログ集約を設定（例：journald → Loki）
- [ ] PostgreSQL のバックアップ戦略を設定
- [ ] モニタリングとアラートを設定（例：Prometheus）
- [ ] 証明書ローテーション手順をテスト
- [ ] 一般的な運用のランブックを作成
- [ ] トラフィック予測に基づくレート制限を設定

---

## トラブルシューティング

### よくある問題

| 問題 | 解決策 |
|------|--------|
| サービスが起動しない | ログを確認：`journalctl -u ashchan-<service>` |
| データベース接続エラー | PostgreSQL が稼働中で `.env` が正しいことを確認 |
| Redis 接続エラー | Redis が稼働中でパスワードが一致することを確認 |
| mTLS ハンドシェイク失敗 | 証明書を再生成：`make mtls-certs` |
| ポートが既に使用中 | 既存プロセスを確認：`lsof -i :<port>` |

### デバッグコマンド

```bash
# サービスステータスを確認
systemctl status ashchan-gateway

# サービスログを表示
journalctl -u ashchan-gateway -f

# mTLS 接続をテスト
curl --cacert certs/ca/ca.crt \
     --cert certs/services/gateway/gateway.crt \
     --key certs/services/gateway/gateway.key \
     https://localhost:8443/health

# PHP エクステンションを確認
php -m | grep -E 'swoole|openssl|pdo|redis'
```

### 参照
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) - 詳細なトラブルシューティングガイド

---

## コントリビューション

ガイドラインは [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) を参照してください。

### コミットメッセージ
Conventional Commits を使用：`feat:`、`fix:`、`docs:`、`refactor:`、`test:`

### コードスタイル
- PSR-12 準拠
- 型ヒント必須（`declare(strict_types=1);`）
- PHPStan Level 10 静的解析

---

## ライセンス

Apache License, Version 2.0 の下でライセンスされています。全文は [LICENSE](LICENSE) を参照してください。

---

## ステータス

✅ mTLS 証明書の生成とローテーションスクリプト
✅ サービススキャフォールディングとマイグレーション
✅ OpenAPI コントラクト
✅ イベントスキーマ
✅ モデレーションシステム（OpenYotsuba から移植）
✅ ネイティブ PHP-CLI デプロイモデル

🚧 ドメインロジックの実装
🚧 イベントのパブリッシュ/コンシューム
🚧 インテグレーションテスト
