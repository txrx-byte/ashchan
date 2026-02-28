# Auth/Accounts Service API Reference

**Last Updated:** 2026-02-28  
**Base URL:** `/api/v1`  
**Authentication:** Bearer token (session-based)

## Overview

This document describes the RESTful API endpoints provided by the Auth/Accounts service. All endpoints return JSON responses.

## Authentication

Most endpoints require authentication via a Bearer token in the `Authorization` header:

```http
Authorization: Bearer <session-token>
```

Alternatively, session tokens can be passed via the `session_token` cookie.

## Common Response Codes

| Code | Meaning | Description |
|------|---------|-------------|
| `200` | OK | Request succeeded |
| `201` | Created | Resource created successfully |
| `400` | Bad Request | Invalid input parameters |
| `401` | Unauthorized | Missing or invalid authentication |
| `403` | Forbidden | Insufficient permissions |
| `409` | Conflict | Resource already exists |
| `429` | Too Many Requests | Rate limit exceeded |
| `500` | Internal Server Error | Server error |

---

## Health Endpoints

### Health Check

**Endpoint:** `GET /health`

**Authentication:** Not required

Returns a simple health check response for load balancers and service mesh probes.

**Response:**

```json
{
  "status": "ok"
}
```

---

## Authentication Endpoints

### Login

**Endpoint:** `POST /api/v1/auth/login`

**Authentication:** Not required

Authenticates a staff user and issues a session token.

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | Staff username |
| `password` | string | Yes | User password |

**Example Request:**

```json
{
  "username": "admin",
  "password": "securepassword123"
}
```

**Success Response (200):**

```json
{
  "token": "abc123...",
  "expires_in": 604800,
  "user": {
    "user_id": 1,
    "username": "admin",
    "role": "admin"
  }
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `400` | `{ "error": "Username and password required" }` | Missing credentials |
| `400` | `{ "error": "Username too long" }` | Username exceeds max length |
| `400` | `{ "error": "Password too long" }` | Password exceeds max length |
| `401` | `{ "error": "Invalid credentials" }` | Wrong username or password |
| `401` | `{ "error": "Invalid credentials" }` | Account is banned |
| `429` | `{ "error": "Too many login attempts, try again later" }` | Rate limited |

**Security Notes:**

- Generic error messages prevent user enumeration
- Per-IP rate limiting (default: 10 attempts per 5 minutes)
- Constant-time password verification (dummy hash on user not found)
- Banned accounts return generic "Invalid credentials" error

---

### Logout

**Endpoint:** `POST /api/v1/auth/logout`

**Authentication:** Required (Bearer token)

Invalidates the current session token.

**Headers:**

```http
Authorization: Bearer <session-token>
```

**Success Response (200):**

```json
{
  "status": "ok"
}
```

**Notes:**

- Always returns success, even if token was already invalid
- Removes session from both Redis cache and database

---

### Validate Token

**Endpoint:** `GET /api/v1/auth/validate`

**Authentication:** Required (Bearer token)

Validates a session token and returns associated user information.

**Headers:**

```http
Authorization: Bearer <session-token>
```

**Success Response (200):**

```json
{
  "user": {
    "user_id": 1,
    "username": "admin",
    "role": "admin"
  }
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `401` | `{ "error": "No token" }` | Missing Authorization header |
| `401` | `{ "error": "Invalid or expired token" }` | Token not found or expired |
| `401` | `{ "error": "Invalid or expired token" }` | User is banned |

**Performance:**

- O(1) Redis lookup on cache hit
- O(1) database lookup on cache miss
- Always checks current ban status (not cached value alone)

---

### Register User

**Endpoint:** `POST /api/v1/auth/register`

**Authentication:** Required (admin only)

Creates a new staff user account.

**Headers:**

```http
Authorization: Bearer <admin-token>
```

**Request Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `username` | string | Yes | - | Unique username |
| `password` | string | Yes | - | Password (min 12 chars) |
| `email` | string | No | `""` | Email address |
| `role` | string | No | `"user"` | One of: admin, manager, mod, janitor, user |

**Example Request:**

```json
{
  "username": "newmod",
  "password": "verysecurepassword123",
  "email": "mod@example.com",
  "role": "mod"
}
```

**Success Response (201):**

```json
{
  "user": {
    "id": 42,
    "username": "newmod",
    "role": "mod"
  }
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `400` | `{ "error": "Username and password required" }` | Missing required fields |
| `400` | `{ "error": "Username too long" }` | Exceeds max_username_length |
| `400` | `{ "error": "Username may only contain letters, numbers, hyphens, and underscores" }` | Invalid characters |
| `400` | `{ "error": "Password must be at least 12 characters" }` | Password too short |
| `400` | `{ "error": "Password too long" }` | Exceeds max_password_length |
| `400` | `{ "error": "Invalid email address" }` | Malformed email |
| `400` | `{ "error": "Invalid role" }` | Role not in allowed list |
| `401` | `{ "error": "Authentication required" }` | Missing token |
| `403` | `{ "error": "Admin only" }` | Caller is not admin |
| `409` | `{ "error": "Username already taken" }` | Duplicate username |

**Validation Rules:**

- Username: alphanumeric, hyphens, underscores only
- Password: minimum 12 characters
- Email: RFC 5322 format (optional)
- Role: must be one of allowed staff roles

---

### Ban User

**Endpoint:** `POST /api/v1/auth/ban`

**Authentication:** Required (staff: admin, manager, mod)

Bans a user account and/or IP address.

**Headers:**

```http
Authorization: Bearer <staff-token>
```

**Request Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `user_id` | int | No* | - | User ID to ban |
| `ip_hash` | string | No* | - | HMAC-SHA256 IP hash to ban |
| `reason` | string | No | `""` | Ban reason (max 500 chars) |
| `expires_at` | string | No | - | ISO 8601 expiry timestamp |
| `duration` | int | No | `86400` | Ban duration in seconds (for IP bans) |

*At least one of `user_id` or `ip_hash` is required.

**Example Request (User Ban):**

```json
{
  "user_id": 123,
  "reason": "Violation of community guidelines",
  "expires_at": "2026-03-01T00:00:00Z"
}
```

**Example Request (IP Ban):**

```json
{
  "ip_hash": "abc123...",
  "reason": "Spam bot",
  "duration": 86400
}
```

**Success Response (200):**

```json
{
  "status": "ok"
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `400` | `{ "error": "Must specify user_id or ip_hash" }` | No ban target specified |
| `400` | `{ "error": "Invalid user ID" }` | Malformed user_id |
| `401` | `{ "error": "Authentication required" }` | Missing token |
| `403` | `{ "error": "Insufficient privileges" }` | Caller role not allowed |

**Notes:**

- User bans: invalidates all sessions immediately
- IP bans: stored in Redis with TTL
- Ban status cache invalidated immediately
- Expired bans auto-cleared on next access

**Authorized Roles:**

- `admin`
- `manager`
- `mod`

---

### Unban User

**Endpoint:** `POST /api/v1/auth/unban`

**Authentication:** Required (staff: admin, manager, mod)

Removes a ban from a user account.

**Headers:**

```http
Authorization: Bearer <staff-token>
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | User ID to unban |

**Example Request:**

```json
{
  "user_id": 123
}
```

**Success Response (200):**

```json
{
  "status": "ok"
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `400` | `{ "error": "Invalid user ID" }` | Malformed user_id |
| `401` | `{ "error": "Authentication required" }` | Missing token |
| `403` | `{ "error": "Insufficient privileges" }` | Caller role not allowed |

**Authorized Roles:**

- `admin`
- `manager`
- `mod`

---

## Consent Endpoints

### Record Consent

**Endpoint:** `POST /api/v1/consent`

**Authentication:** Not required

Records user consent for privacy/age policies (GDPR/COPPA/CCPA compliance).

**Request Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `consented` | bool | Yes | - | Whether consent was granted |
| `policy_version` | string | No | `"1.0"` | Policy version string |

**Example Request:**

```json
{
  "consented": true,
  "policy_version": "2.1"
}
```

**Success Response (200):**

```json
{
  "status": "ok"
}
```

**Notes:**

- Consent records are append-only (new row per decision)
- IP address hashed with HMAC-SHA256 for lookups
- IP address encrypted for admin recovery
- Records both `privacy_policy` and `age_verification` consent types

**Security:**

- IP hashing prevents rainbow table attacks
- Encrypted IP allows admin decryption if needed
- No user enumeration via consent endpoints

---

## Data Rights Endpoints

### Request Data Export or Deletion

**Endpoint:** `POST /api/v1/auth/data-request`

**Authentication:** Required (Bearer token)

Creates a data export or deletion request (GDPR/CCPA data rights).

**Headers:**

```http
Authorization: Bearer <session-token>
```

**Request Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `type` | string | No | `"data_export"` | One of: `data_export`, `data_deletion` |

**Example Request (Export):**

```json
{
  "type": "data_export"
}
```

**Example Request (Deletion):**

```json
{
  "type": "data_deletion"
}
```

**Success Response (200):**

```json
{
  "request": {
    "id": 42,
    "user_id": 1,
    "status": "pending",
    "request_type": "data_export",
    "requested_at": "2026-02-28T12:00:00Z",
    "completed_at": null
  }
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `400` | `{ "error": "Invalid user" }` | User ID extraction failed |
| `401` | `{ "error": "Authentication required" }` | Missing token |
| `401` | `{ "error": "Invalid token" }` | Token invalid/expired |

**Request Statuses:**

| Status | Description |
|--------|-------------|
| `pending` | Request received, awaiting processing |
| `processing` | Request is being fulfilled |
| `completed` | Request fulfilled |
| `denied` | Request denied (e.g., fraudulent) |

**Notes:**

- Users can only request their own data
- Deletion requests are irreversible (right to be forgotten)
- Export includes all user data (profile, sessions, consents)

---

## User Roles

| Role | Permissions |
|------|-------------|
| `admin` | Full system access, user management, ban management |
| `manager` | Moderation management, limited admin functions |
| `mod` | Ban/unban users, content moderation |
| `janitor` | Board cleanup, basic moderation |
| `user` | Standard authenticated user |

---

## Rate Limiting

### Login Rate Limiting

- **Mechanism:** Redis sorted-set sliding window
- **Default:** 10 attempts per 5 minutes per IP
- **Response:** HTTP 429 with error message
- **Bypass:** None (fail-open if Redis unavailable)

**Lua Script Implementation:**

The rate limiter uses an atomic Lua script to prevent race conditions:

```lua
local key = KEYS[1]
local now = tonumber(ARGV[1])
local window_start = tonumber(ARGV[2])
local max_reqs = tonumber(ARGV[3])
local member = ARGV[4]
local window = tonumber(ARGV[5])

-- Remove expired entries
redis.call('ZREMRANGEBYSCORE', key, '-inf', window_start)

-- Check count
local count = redis.call('ZCARD', key)
if count >= max_reqs then
    return 1  -- Rate limited
end

-- Add new entry
redis.call('ZADD', key, now, member)
redis.call('EXPIRE', key, window)

return 0  -- OK
```

---

## Error Handling

All errors return JSON with an `error` field:

```json
{
  "error": "Human-readable error message"
}
```

### Internal Server Errors

Unhandled exceptions are caught by `AppExceptionHandler`:

- Full stack trace logged to STDERR
- Client receives generic `{ "error": "Internal server error" }`
- No internal details exposed

---

## Related Documentation

- [Architecture](ARCHITECTURE.md) - System architecture overview
- [Security Model](SECURITY.md) - Security considerations
- [Troubleshooting](TROUBLESHOOTING.md) - Common issues
