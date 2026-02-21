# Data Inventory & Retention Policy

This document catalogs all personally identifiable information (PII) stored by Ashchan,
the legal basis for collection, retention periods, and encryption requirements.

**Last Updated:** 2026-02-20
**Policy Version:** 1.0.0

---

## 1. Data Classification

| Classification | Description | Examples |
|---|---|---|
| **PII-Critical** | Directly identifies a natural person | IP address, email address |
| **PII-Sensitive** | Could identify when combined with other data | Browser fingerprint, country code, user-agent |
| **Operational** | Required for system function, not identifying | Post content, thread metadata, board settings |
| **Staff-Internal** | Staff account data, audit trails | Staff usernames, admin IPs, audit logs |

---

## 2. Data Inventory by Service

### 2.1 Auth/Accounts (`auth-accounts`)

| Table | Column | Classification | Purpose | Retention | Encrypted |
|---|---|---|---|---|---|
| `users` | `id` | Operational | User identity | Account lifetime | No |
| `users` | `username` | PII-Sensitive | Account identification | Account lifetime | No |
| `users` | `password_hash` | PII-Critical | Authentication | Account lifetime | Hashed (Argon2ID) |
| `users` | `ban_status` | Operational | Access control | Account lifetime | No |
| `sessions` | `token_hash` | Operational | Session management | 7 days (TTL) | Hashed (SHA-256) |
| `consents` | `policy_version` | Operational | GDPR/CCPA compliance | Indefinite (legal) | No |
| `consents` | `metadata` | PII-Sensitive | Consent context | Indefinite (legal) | No |
| `deletion_requests` | `*` | Operational | DSR tracking | 3 years (legal defense) | No |

### 2.2 Boards/Threads/Posts (`boards-threads-posts`)

| Table | Column | Classification | Purpose | Retention | Encrypted |
|---|---|---|---|---|---|
| `posts` | `ip_address` | **PII-Critical** | Moderation, abuse prevention, SFS reporting | **30 days**, then auto-deleted | **Yes (XChaCha20-Poly1305)** |
| `posts` | `email` | Operational | Sage/noko commands (not actual email — does not store user email addresses) | 30 days (auto-nullified) | No (plaintext) |
| `posts` | `author_name` | PII-Sensitive | Attribution (user-provided) | Post lifetime | No |
| `posts` | `tripcode` | PII-Sensitive | Identity verification | Post lifetime | No |
| `posts` | `country_code` | PII-Sensitive | Geographic context | Post lifetime | No |
| `posts` | `delete_password_hash` | Operational | User self-deletion | Post lifetime | Hashed |
| `posts` | `content` | Operational | User expression | Post lifetime | No |
| `posts` | `media_*` | Operational | Media display | Post lifetime | No |
| `threads` | `*` | Operational | Thread structure | Thread lifetime | No |
| `boards` | `*` | Operational | Board configuration | Indefinite | No |

### 2.3 Moderation/Anti-Spam (`moderation-anti-spam`)

| Table | Column | Classification | Purpose | Retention | Encrypted |
|---|---|---|---|---|---|
| `reports` | `ip` | **PII-Critical** | Reporter identification, abuse prevention | **90 days** | **Yes (XChaCha20-Poly1305)** |
| `reports` | `ip_hash` | **PII-Critical** | Deterministic lookups for abuse filtering | **90 days** | Hashed (SHA-256) |
| `reports` | `post_ip` | **PII-Critical** | Reported user's IP for moderation | **90 days** | **Yes (XChaCha20-Poly1305)** |
| `banned_users` | `host` | **PII-Critical** | IP ban enforcement | Ban duration + 30 days | **Yes (XChaCha20-Poly1305)** |
| `banned_users` | `xff` | **PII-Critical** | Proxy detection | Ban duration + 30 days | **Yes (XChaCha20-Poly1305)** |
| `banned_users` | `admin_ip` | **Staff-Internal** | Staff audit trail | 1 year | **Yes (XChaCha20-Poly1305)** |
| `banned_users` | `password` | PII-Sensitive | Pass-based banning | Ban duration | Hashed |
| `banned_users` | `pass_id` | PII-Sensitive | Pass identification | Ban duration | No |
| `banned_users` | `post_json` | PII-Sensitive | Evidence snapshot | Ban duration + 30 days | No |
| `sfs_pending_reports` | `ip_address` | **PII-Critical** | SFS submission (requires decryption) | **30 days or until processed** | **Yes (XChaCha20-Poly1305)** |
| `sfs_pending_reports` | `evidence_snapshot` | PII-Sensitive | SFS evidence | 30 days or until processed | No |
| `risk_scores` | `*` | Operational | Spam scoring | 30 days | No |
| `moderation_decisions` | `*` | Operational | Audit trail | 1 year | No |
| `report_clear_log` | `ip_hash` | **PII-Critical** | Staff audit — abuse filtering | **90 days** | Hashed (SHA-256) |

### 2.4 Media/Uploads (`media-uploads`)

| Table | Column | Classification | Purpose | Retention | Encrypted |
|---|---|---|---|---|---|
| `media_objects` | `uploader_ip` | **PII-Critical** | Abuse tracing | **30 days** | **Yes (XChaCha20-Poly1305)** |
| `media_objects` | `*` (other) | Operational | Media management | Media lifetime | No |

### 2.5 API Gateway Logs

| Data | Classification | Purpose | Retention | Encrypted |
|---|---|---|---|---|
| `flood_log.ip` | **PII-Critical** | Rate limiting, DDoS prevention | **24 hours** | **Yes (XChaCha20-Poly1305)** |
| `admin_audit_log.ip_address` | **Staff-Internal** | Staff accountability | 1 year | **Yes (XChaCha20-Poly1305)** |

### 2.6 Infrastructure

| Data | Classification | Purpose | Retention | Encrypted |
|---|---|---|---|---|
| Access logs (nginx/Swoole) | Operational | Debugging, security | **7 days** (logrotate) | At rest (filesystem) |
| Redis session cache | Operational | Performance | 7-day TTL | In-memory only |

> **IP Address Logging Policy:** HTTP-level access logs (nginx, Swoole) **MUST NOT**
> contain raw IP addresses. Configure `access_log off;` or redact IPs from the log format.
> IP addresses are captured **only at post/report creation time** within the application
> layer and encrypted immediately via `PiiEncryptionService` before database storage.
> This ensures IPs are never persisted in plaintext anywhere in the system.

---

## 3. Retention Schedule Summary

| Data Category | Retention Period | Deletion Method |
|---|---|---|
| Post IP addresses | 30 days from post creation | Automated cron job (nullify) |
| Flood log IPs | 24 hours | Automated cron job (DELETE) |
| Report IPs | 90 days from report | Automated cron job (nullify) |
| Ban IPs | Ban expiry + 30 days | Automated cron job (nullify) |
| SFS pending report IPs | 30 days or on processing | Automated cron/on-action |
| Media uploader IPs | 30 days from upload | Automated cron job (nullify) |
| Audit log staff IPs | 1 year | Automated cron job (nullify) |
| Access logs | 7 days | logrotate (no IPs — redacted) |
| Sessions | 7 days | TTL expiry (Redis) + DB cleanup |
| Consent records | Indefinite | Required for legal compliance |
| User accounts | Until deletion request | DSR workflow |
| Moderation decisions | 1 year | Automated cron job (DELETE) |

---

## 4. Legal Basis for Processing (GDPR Art. 6)

| Data | Legal Basis | Justification |
|---|---|---|
| IP addresses (posts) | Legitimate interest (Art. 6(1)(f)) | Abuse prevention, spam mitigation, legal compliance |
| IP addresses (bans) | Legitimate interest | Enforcing community rules, preventing ban evasion |
| Options field (sage/noko) | Contract performance | Post command functionality (not PII) |
| Content/media | Contract performance | Core service functionality |
| Consent records | Legal obligation (Art. 6(1)(c)) | GDPR/CCPA record-keeping requirement |
| Staff account data | Contract performance | Employment/volunteer relationship |
| Moderation logs | Legitimate interest | Platform safety, legal defense |

---

## 5. Third-Party Data Sharing

### 5.1 StopForumSpam (SFS)

| What is shared | When | Legal basis | User notification |
|---|---|---|---|
| IP address (decrypted) | Admin-approved SFS report | Legitimate interest (anti-spam) | Terms of Service disclosure |
| Username | Admin-approved SFS report | Legitimate interest | Terms of Service disclosure |
| ~~Email~~ *(not collected from users)* | N/A | N/A | N/A |
| Post content (evidence) | Admin-approved SFS report | Legitimate interest | Terms of Service disclosure |

**Process:** Data is encrypted at rest. An administrator must explicitly decrypt using their
private key and approve submission. See [SFS Decryption Workflow](#sfs-decryption-workflow).

### 5.2 Law Enforcement

Data may be disclosed in response to valid legal process (subpoena, court order, etc.)
as required by applicable law.

---

## 6. Encryption Architecture

### 6.1 Encryption at Rest

All PII-Critical data is encrypted using **XChaCha20-Poly1305** (IETF AEAD) with envelope encryption:

- **Data Encryption Key (DEK):** Derived from server-side secret (`PII_ENCRYPTION_KEY` env var) via BLAKE2b.
- **Nonce:** Unique 24-byte random nonce per encrypted value, prepended to ciphertext.
- **Format:** `enc:<base64(nonce || ciphertext || tag)>` — the `enc:` prefix distinguishes
  encrypted values from plaintext (migration compatibility).
- **Admin Decryption:** Authorized administrators can decrypt PII values via the
  `PiiEncryptionService::decrypt()` method for moderation, SFS reporting, and legal compliance.

#### IP Address Handling Architecture

1. **In Transit (API layer):** Raw IP addresses are passed between services via mTLS-protected
   internal APIs. No IP addresses appear in HTTP-level access logs.
2. **At Storage (DB layer):** IPs are encrypted via `PiiEncryptionService::encrypt()` before
   being written to any database column. The encrypted value is admin-decryptable.
3. **For Lookups:** A deterministic SHA-256 hash of the raw IP (`ip_hash` column) is stored
   alongside the encrypted IP for efficient database queries (e.g., abuse filtering).
   The hash is not reversible but enables `WHERE ip_hash = ?` lookups.
4. **In Logs:** Application logs **never** contain raw IP addresses. Only the SHA-256 hash
   may appear in log entries for correlation purposes.
5. **Capture Point:** IPs are only captured at the application level during post creation,
   report submission, and staff login — never at the HTTP/access-log level.

### 6.2 SFS Decryption Workflow

For SFS report submission, an additional layer exists:

1. PII is encrypted at rest with the service DEK (automatic).
2. When an admin approves an SFS submission, the system decrypts the IP in-memory.
3. The decrypted IP is sent to SFS over HTTPS.
4. The plaintext IP is **never written to disk or logs** during this process.
5. An audit log entry records: who decrypted, when, for what purpose (without logging the IP).

### 6.3 Key Rotation

- DEKs are rotated every 90 days.
- Old DEKs are retained (read-only) until all data encrypted with them has been deleted per retention policy.
- KEK rotation requires re-encrypting all active DEKs.

---

## 7. Data Subject Rights

| Right | Implementation | SLA |
|---|---|---|
| Right to access | Data export via auth-accounts API | 30 days |
| Right to erasure | Deletion request → soft delete → purge | 45 days |
| Right to rectification | Account settings update | Immediate |
| Right to data portability | JSON export of user data | 30 days |
| Right to object | Opt-out of SFS reporting via account settings | Immediate |

---

## 8. Automated Deletion System

The `IpRetentionService` runs as a scheduled cron job and performs:

1. **Posts:** SET `ip_address = NULL` WHERE `created_at < NOW() - INTERVAL '30 days'`
2. **Flood logs:** DELETE WHERE `created_at < NOW() - INTERVAL '24 hours'`
3. **Reports:** SET `ip = NULL, post_ip = NULL` WHERE `created_at < NOW() - INTERVAL '90 days'`
4. **Bans:** SET `host = NULL, xff = NULL, admin_ip = NULL` WHERE ban expired + 30 days
5. **SFS queue:** DELETE WHERE `created_at < NOW() - INTERVAL '30 days'` AND `status != 'pending'`
6. **Audit logs:** SET `ip_address = NULL` WHERE `created_at < NOW() - INTERVAL '1 year'`

All deletions are logged to an immutable audit trail (without PII).
