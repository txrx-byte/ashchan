# Spur.us IP Intelligence Integration

## Overview

[Spur](https://spur.us) provides IP intelligence data for detecting VPNs, residential proxies, and bots. The integration uses the **Spur Context API** (`/v2/context/:ip`) to enrich the spam scoring pipeline with real-time IP context signals.

This integration is **optional** and can be toggled on/off at runtime by admins via the site settings API.

## Architecture

```
┌─────────────┐     ┌────────────────┐     ┌──────────────────┐
│  Incoming    │────▶│  SpamService   │────▶│  SpurService     │
│  Post/Thread │     │  (Layer 0b)    │     │  (evaluate)      │
└─────────────┘     └────────────────┘     └────────┬─────────┘
                                                     │
                                           ┌─────────▼─────────┐
                                           │  Spur Context API  │
                                           │  api.spur.us       │
                                           │  GET /v2/context/  │
                                           └───────────────────┘
```

The `SpurService` sits alongside `StopForumSpamService` as **Layer 0b** in the multi-layer anti-spam engine:

| Layer | Name | Description |
|-------|------|-------------|
| 0a | StopForumSpam | IP/email/username reputation checks |
| **0b** | **Spur IP Intelligence** | **VPN/proxy/bot detection via Context API** |
| 1 | Rate Limiting | IP-based sliding window |
| 2 | Content Fingerprinting | Duplicate detection |
| 3 | Risk Scoring | Heuristic analysis |
| 4 | Image Hash Bans | Banned image detection |
| 5 | IP Reputation | Redis-based reputation tracking |

## Spur Context API

### Endpoint

```
GET https://api.spur.us/v2/context/{ip_address}
```

### Authentication

All requests use a `Token` header:

```
Token: YOUR_SPUR_API_TOKEN
```

### Response Structure

```json
{
  "as": {
    "number": 49981,
    "organization": "WorldStream"
  },
  "client": {
    "behaviors": ["FILE_SHARING", "TOR_PROXY_USER"],
    "concentration": {
      "city": "Amsterdam",
      "country": "NL",
      "density": 0.2675,
      "geohash": "tsn",
      "skew": 6762,
      "state": "North Holland"
    },
    "count": 4,
    "countries": 2,
    "proxies": ["ABCPROXY_PROXY", "NETNUT_PROXY"],
    "spread": 4724209,
    "types": ["MOBILE", "DESKTOP"]
  },
  "infrastructure": "DATACENTER",
  "ip": "89.39.106.191",
  "location": {
    "city": "Amsterdam",
    "country": "NL",
    "state": "North Holland"
  },
  "organization": "WorldStream B.V.",
  "risks": ["CALLBACK_PROXY", "TUNNEL", "GEO_MISMATCH"],
  "services": ["OPENVPN"],
  "tunnels": [
    {
      "anonymous": true,
      "entries": ["89.39.106.82"],
      "operator": "PROTON_VPN",
      "type": "VPN"
    }
  ]
}
```

### Response Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 400 | Bad input — IP must be a public IPv4 or IPv6 address |
| 401 | Access denied — invalid token |
| 403 | Token valid but no Context API access |
| 429 | Insufficient query balance |
| 500 | Server error |

### Response Headers

| Header | Description |
|--------|-------------|
| `x-balance-remaining` | Queries remaining in this billing cycle |
| `x-result-dt` | Date for which this response is sourced (YYYYmmdd) |

## Risk Scoring

The `SpurService::evaluate()` method translates Spur context data into a numeric risk score:

### Block-Level Risks (score += 100)

These risks trigger an immediate block:

- `BOTNET`
- `MALWARE`

### High Risks (score += 15 each)

- `CALLBACK_PROXY`
- `TUNNEL`
- `GEO_MISMATCH`
- `WEB_SCRAPING`
- `BOTNET`
- `MALWARE`

### Anonymous Tunnels (score += 10 each, max 30)

Anonymous VPN, Proxy, or TOR tunnels detected by Spur. Includes the tunnel operator name (e.g., PROTON_VPN).

### Infrastructure (score += 5)

Datacenter-originating traffic receives a minor penalty.

### Proxy Associations (score += 3 each, max 15)

IPs associated with known proxy services.

### Client Concentration (variable)

- 100+ clients behind the IP: +10
- 20+ clients: +5

### Geographic Dispersion (score += 8)

Clients from more than 5 countries sharing the IP.

### Behavior Flags (score += 5 each)

Client behaviors containing `TOR` or `PROXY` identifiers.

## Configuration

### Environment Variables

Set in `services/moderation-anti-spam/.env`:

```bash
# Required: API token from https://app.spur.us
SPUR_API_TOKEN=your_token_here

# Optional: request timeout in seconds (default: 3)
SPUR_TIMEOUT=3
```

### Runtime Toggle (Admin)

Spur integration can be toggled at runtime via the site settings API. Both the API token **and** the admin toggle must be active for Spur to operate.

**Enable Spur:**

```bash
curl -X PUT http://moderation-anti-spam:9506/api/v1/admin/settings/spur_enabled \
  -H 'Content-Type: application/json' \
  -d '{"value": "true", "reason": "Enabling Spur for VPN/proxy detection"}'
```

**Disable Spur:**

```bash
curl -X PUT http://moderation-anti-spam:9506/api/v1/admin/settings/spur_enabled \
  -H 'Content-Type: application/json' \
  -d '{"value": "false", "reason": "Disabling Spur - high false positive rate"}'
```

**Check status:**

```bash
curl http://moderation-anti-spam:9506/api/v1/spam/spur-status
# {"enabled": true}
```

## API Endpoints

### Spur Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v1/spam/spur-lookup` | Full IP context lookup |
| POST | `/api/v1/spam/spur-evaluate` | Risk score evaluation |
| GET | `/api/v1/spam/spur-status` | Check if Spur is enabled |

### Internal Endpoints (Service Mesh)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/internal/spur/lookup` | Full IP context lookup |
| POST | `/internal/spur/evaluate` | Risk score evaluation |
| GET | `/internal/spur/status` | Check if Spur is enabled |

### Site Settings Admin Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/admin/settings` | List all settings |
| GET | `/api/v1/admin/settings/{key}` | Get a setting |
| PUT | `/api/v1/admin/settings/{key}` | Update a setting |
| GET | `/api/v1/admin/settings/{key}/audit` | View setting change history |

## Files

| File | Description |
|------|-------------|
| `services/moderation-anti-spam/app/Service/SpurService.php` | Core Spur Context API client and risk evaluator |
| `services/moderation-anti-spam/app/Controller/SpurController.php` | HTTP endpoints for Spur lookups |
| `services/moderation-anti-spam/app/Service/SiteSettingsService.php` | Database-backed feature toggle service |
| `services/moderation-anti-spam/app/Controller/SiteSettingsController.php` | Admin settings management endpoints |
| `services/moderation-anti-spam/config/autoload/spur.php` | Spur configuration |
| `db/migrations/20260220000002_create_site_settings.sql` | Site settings and audit log tables |
| `contracts/openapi/moderation-anti-spam.yaml` | OpenAPI specification |

## Database

### Migration: `20260220000002_create_site_settings.sql`

Creates two tables:

**`site_settings`** — Key-value store for feature toggles:

| Column | Type | Description |
|--------|------|-------------|
| `key` | VARCHAR(255) PK | Setting identifier |
| `value` | TEXT | Current value |
| `description` | TEXT | Human-readable description |
| `updated_by` | BIGINT FK | Staff user who last changed it |
| `created_at` | TIMESTAMP | Creation time |
| `updated_at` | TIMESTAMP | Last update time |

**`site_settings_audit_log`** — Change history:

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGSERIAL PK | Auto-increment ID |
| `setting_key` | VARCHAR(255) | Which setting changed |
| `old_value` | TEXT | Previous value |
| `new_value` | TEXT | New value |
| `changed_by` | BIGINT FK | Staff user who made the change |
| `changed_at` | TIMESTAMP | When the change occurred |
| `reason` | TEXT | Optional reason for the change |

### Default Settings

| Key | Default | Description |
|-----|---------|-------------|
| `spur_enabled` | `false` | Spur.us IP intelligence (disabled by default) |
| `sfs_enabled` | `true` | StopForumSpam integration (enabled by default) |

## Deployment

1. **Set environment variable:**
   ```bash
   SPUR_API_TOKEN=your_spur_token
   ```

2. **Run the migration:**
   ```bash
   psql -U ashchan -d ashchan -f db/migrations/20260220000002_create_site_settings.sql
   ```

3. **Enable via admin API:**
   ```bash
   curl -X PUT http://moderation-anti-spam:9506/api/v1/admin/settings/spur_enabled \
     -H 'Content-Type: application/json' \
     -d '{"value": "true", "reason": "Initial Spur activation"}'
   ```

4. **Verify:**
   ```bash
   curl http://moderation-anti-spam:9506/api/v1/spam/spur-status
   ```

## Graceful Degradation

The Spur integration is designed to fail silently:

- If the API token is not set → integration is disabled
- If the admin toggle is off → integration is disabled
- If the Spur API times out (default 3s) → returns score 0, no penalty
- If the Spur API returns non-200 → logged and skipped
- If the API rate limit is hit (429) → logged and skipped
- If the IP is private/reserved → skipped without API call
- If the balance drops below 100 queries → warning logged

The broader spam scoring pipeline continues normally regardless of Spur availability.

## Billing and Quotas

Spur is a paid API service. Each `lookup()` call consumes one query from your billing cycle. The `x-balance-remaining` response header is monitored:

- **< 100 remaining**: Warning logged automatically
- **Rate limited (429)**: Request skipped, warning logged

Monitor your balance at [app.spur.us](https://app.spur.us).

## Privacy Considerations

- The Spur lookup sends the **raw IP address** to an external API (`api.spur.us`)
- Use the admin toggle to disable Spur if IP sharing with third parties is a concern
- Spur results are **not persisted** to the database — they are used for real-time scoring only
- No PII is stored in Spur-related logs (only hashed IPs and score data)
- The `raw` response field from Spur is stripped before any HTTP responses from our API
