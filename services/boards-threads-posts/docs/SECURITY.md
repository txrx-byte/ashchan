# Boards/Threads/Posts Service Security Model

**Last Updated:** 2026-02-28
**Classification:** Internal Documentation

## Overview

This document describes the security architecture, threat model, and defensive measures implemented in the Boards/Threads/Posts service.

---

## Threat Model

### Assets Protected

| Asset | Sensitivity | Protection Method |
|-------|-------------|-------------------|
| Post IP addresses | High | XChaCha20-Poly1305 encryption |
| Post emails | Medium | PII encryption at rest |
| Delete passwords | Medium | bcrypt hashing |
| Edit passwords (liveposting) | Medium | bcrypt hashing |
| User-generated content | Low | XSS sanitization, input validation |

### Threat Actors

1. **Malicious Users:** XSS attempts, flood attacks, spam
2. **External Attackers:** SQL injection, data exfiltration
3. **Compromised Infrastructure:** Database/Redis access
4. **Insider Threats:** Administrators with elevated access

---

## Input Validation

### Controller-Level Validation

All user input is validated at the controller layer before reaching services:

```php
// ThreadController::create()
$name = isset($input['name']) && is_string($input['name']) ? $input['name'] : '';
$email = isset($input['email']) && is_string($input['email']) ? $input['email'] : '';
$subject = isset($input['sub']) && is_string($input['sub']) ? $input['sub'] : '';
$content = isset($input['com']) && is_string($input['com']) ? $input['com'] : '';

// Length validation
if (mb_strlen($name) > 100) {
    return $this->response->json(['error' => 'Name must not exceed 100 characters'])->withStatus(400);
}
if (mb_strlen($content) > 20000) {
    return $this->response->json(['error' => 'Comment must not exceed 20000 characters'])->withStatus(400);
}

// Content validation (whitespace-only counts as blank)
$trimmedContent = trim($data['content']);
if ($board->text_only && $trimmedContent === '') {
    return $this->response->json(['error' => 'A comment is required'])->withStatus(400);
}
```

### Validation Rules

| Field | Type | Max Length | Validation |
|-------|------|------------|------------|
| `name` | string | 100 | Optional, string type |
| `email` | string | - | Optional, string type |
| `subject` | string | 100 | Optional, string type |
| `content` | string | 20000 | Required (non-blank) |
| `password` | string | - | Optional, string type |
| `slug` | string | 32 | `^[a-z0-9]{1,32}$` |

### Type Coercion Prevention

```php
// Explicit type checking, no implicit coercion
$pageInput = $request->query('page', '1');
$page = max(1, is_numeric($pageInput) ? (int) $pageInput : 1);

// Array input validation
$idsInput = $request->input('ids', []);
$ids = is_array($idsInput) ? $idsInput : [];
```

---

## SQL Injection Prevention

### Parameterized Queries

All database queries use parameterized statements:

```php
// ✅ Good - parameterized
$board = Board::query()->where('slug', $slug)->first();

// ✅ Good - parameterized with whereIn
$posts = Post::query()
    ->whereIn('posts.id', $postIds)
    ->where('boards.slug', $boardSlug)
    ->get();

// ❌ Bad - string interpolation (never done)
// $posts = Db::select("SELECT * FROM posts WHERE id = $postId");
```

### Raw Query Parameter Binding

```php
// Window function for latest replies - parameters bound safely
$replyRows = Db::select(
    "SELECT p.* FROM (
        SELECT p2.*, ROW_NUMBER() OVER (PARTITION BY p2.thread_id ORDER BY p2.id DESC) AS rn
        FROM posts p2
        WHERE p2.thread_id = ANY(?)
        AND p2.is_op = false
        AND p2.deleted = false
    ) p WHERE p.rn <= 5",
    ['{' . implode(',', $threadIds) . '}']
);
```

### IP Address Validation

```php
private function getClientIp(RequestInterface $request): string
{
    $ip = $request->getHeaderLine('X-Forwarded-For')
        ?: $request->getHeaderLine('X-Real-IP')
        ?: $request->server('remote_addr', '127.0.0.1');

    // X-Forwarded-For may contain comma-separated list; take leftmost
    if (str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip, 2)[0]);
    }

    // Validate IP address format
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '127.0.0.1';
}
```

---

## XSS Sanitization

### Content Formatting Pipeline

User content passes through multiple sanitization layers:

```php
// ContentFormatter::format()
public function format(string $raw): string
{
    // Layer 1: HTML entity encoding (prevents script injection)
    $html = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Layer 2: Allowed markup processing
    // Code blocks: [code]...[/code]
    $html = preg_replace('/\[code\](.*?)\[\/code\]/s', '<pre class="prettyprint">$1</pre>', $html) ?? $html;

    // Spoilers: [spoiler]...[/spoiler]
    $html = preg_replace('/\[spoiler\](.*?)\[\/spoiler\]/s', '<s>$1</s>', $html) ?? $html;

    // Bold: **text**
    $html = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $html) ?? $html;

    // Greentext: lines starting with >
    $html = preg_replace('/^(&gt;(?!&gt;).*)$/m', '<span class="quote">$1</span>', $html) ?? $html;

    // Quote links: >>12345
    $html = preg_replace('/&gt;&gt;([a-f0-9\-]{36}|\d+)/i', '<a href="#p$1" class="quotelink">&gt;&gt;$1</a>', $html) ?? $html;

    // Auto-link bare URLs (after quote patterns to avoid conflicts)
    $html = preg_replace(
        '/(https?:\/\/[^\s<>\[\]"\']+)/i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $html
    ) ?? $html;

    // Layer 3: Line breaks
    $html = nl2br($html);

    return $html;
}
```

### Security Properties

| Property | Implementation |
|----------|----------------|
| Script prevention | `htmlspecialchars()` encodes `<`, `>`, `&`, `"`, `'` |
| Event handler prevention | All user content encoded before HTML construction |
| URL scheme restriction | Only `http:` and `https:` URLs auto-linked |
| Quote link validation | Regex ensures only valid post IDs linked |
| Cross-board link validation | Only alphanumeric board slugs allowed |

### Allowed Markup

| Markup | Output | Security Notes |
|--------|--------|----------------|
| `[code]...[/code]` | `<pre class="prettyprint">` | Content still HTML-encoded |
| `[spoiler]...[/spoiler]` | `<s>...</s>` | Content still HTML-encoded |
| `**bold**` | `<b>...</b>` | Content still HTML-encoded |
| `*italic*` | `<i>...</i>` | Content still HTML-encoded |
| `__underline__` | `<u>...</u>` | Content still HTML-encoded |
| `~~strikethrough~~` | `<s>...</s>` | Content still HTML-encoded |
| `>greentext` | `<span class="quote">` | Line-level, content encoded |
| `>>12345` | `<a href="#p12345">` | ID validated by regex |
| `>>>/b/` | `<a href="/b/">` | Board slug validated |

---

## PII Encryption

### IP Address Encryption

IP addresses are encrypted at rest using XChaCha20-Poly1305:

```php
// PiiEncryptionService::encrypt()
public function encrypt(string $plaintext): string
{
    if (!$this->isEnabled()) {
        return $plaintext;
    }

    // Generate random nonce (24 bytes for XChaCha20-Poly1305)
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
        $plaintext,
        '',  // AAD
        $nonce,
        $this->encryptionKey
    );

    // Format: enc:<base64(nonce || ciphertext || tag)>
    return 'enc:' . base64_encode($nonce . $ciphertext);
}
```

### Key Derivation

```php
// Key derived from environment variable using BLAKE2b
$rawKey = \Hyperf\Support\env('PII_ENCRYPTION_KEY', '');
$this->encryptionKey = sodium_crypto_generichash(
    $rawKey,
    '',
    SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
);
```

### Decryption with Fallback

```php
public function decrypt(string $ciphertext): string
{
    if (!$this->isEnabled()) {
        return $ciphertext;
    }

    // Not encrypted (legacy data or plaintext)
    if (!str_starts_with($ciphertext, 'enc:')) {
        return $ciphertext;
    }

    try {
        $decoded = base64_decode(substr($ciphertext, 4), true);
        $nonce = substr($decoded, 0, 24);
        $encrypted = substr($decoded, 24);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $encrypted,
            '',  // AAD
            $nonce,
            $this->encryptionKey
        );

        return $plaintext === false ? '[DECRYPTION_FAILED]' : $plaintext;
    } catch (\SodiumException $e) {
        return '[DECRYPTION_FAILED]';
    }
}
```

### Poster ID Generation

Poster IDs are deterministic hashes (per IP + thread + day):

```php
// In BoardService::createThread() / createPost()
$posterId = substr(hash('sha256', $ip . $thread->id . date('Y-m-d')), 0, 8);
```

**Why Deterministic:**
- Same IP in same thread on same day gets same poster ID
- Enables "unique IPs" count without storing raw IPs
- Cannot be reversed to obtain IP

---

## Rate Limiting

### Flood Prevention

Rate limiting is enforced at the API Gateway layer. The service implements additional flood prevention:

```php
// ThreadController validates content before processing
if (mb_strlen($content) > 20000) {
    return $this->response->json(['error' => 'Comment must not exceed 20000 characters'])->withStatus(400);
}

// Board-level cooldowns enforced in BoardService
if ($thread->reply_count >= ($board->bump_limit ?: 300)) {
    throw new \RuntimeException('Thread has reached bump limit');
}
if ($thread->image_count >= ($board->image_limit ?: 150)) {
    throw new \RuntimeException('Thread has reached image limit');
}
```

### IP-Based Flood Log

Flood events are logged to `flood_log` table with automatic retention:

| Column | Type | Retention |
|--------|------|-----------|
| `ip_address` | encrypted | 24 hours |
| `action` | string | 24 hours |
| `created_at` | timestamp | 24 hours |

---

## Data Retention

### Automated PII Deletion

The `IpRetentionService` automatically purges PII based on retention schedule:

```php
// Retention schedule from DATA_INVENTORY.md
private int $postIpRetentionDays    = 30;   // Post IP addresses
private int $postEmailRetentionDays = 30;   // Post emails
private int $floodLogRetentionDays  = 1;    // Flood log entries
```

### Retention Execution

```php
public function purgePostIps(): int
{
    $days = $this->postIpRetentionDays;

    // Nullify IP addresses older than retention period
    $affected = Db::update(
        'UPDATE posts SET ip_address = NULL WHERE ip_address IS NOT NULL AND created_at < NOW() - make_interval(days => ?)',
        [$days]
    );

    // Log retention action to audit trail
    $this->logRetentionAction('posts', 'ip_address', $affected, $days);

    return $affected;
}
```

### Audit Trail

Retention actions are logged to `pii_retention_log`:

| Column | Type | Description |
|--------|------|-------------|
| `table_name` | string | Table affected |
| `column_name` | string | Column affected |
| `rows_affected` | int | Number of rows |
| `retention_days` | int | Retention period |
| `executed_at` | timestamp | Execution time |

---

## Access Control

### Staff Level Detection

```php
private function isStaffMod(RequestInterface $request): bool
{
    $level = $request->getHeaderLine('X-Staff-Level');
    if ($level === '') {
        return false;
    }

    // Accept both numeric and named levels
    $numericLevel = match (strtolower($level)) {
        'admin'    => 3,
        'manager'  => 2,
        'mod', 'moderator' => 1,
        'janitor'  => 0,
        default    => is_numeric($level) ? (int) $level : -1,
    };

    return $numericLevel >= 1;  // mod+ can see IP hashes
}
```

### Staff Endpoint Protection

| Endpoint | Minimum Level | Enhancement |
|----------|---------------|-------------|
| `GET /threads` | mod+ | Include `ip_hash` in posts |
| `GET /posts/by-ip-hash/{hash}` | mod+ | Return IP hash history |
| `DELETE /posts/{id}` | janitor+ | Staff delete |
| `POST /threads/{id}/options` | mod+ | Sticky/lock/permasage |
| `POST /posts/{id}/spoiler` | janitor+ | Toggle spoiler |
| `GET /threads/{id}/ips` | mod+ | Full IP lookup |
| `POST /posts/lookup` | mod+ | Bulk media lookup |

---

## Delete Password Security

### Password Hashing

Delete passwords are hashed with bcrypt:

```php
// In BoardService::createPost()
'delete_password_hash' => $password !== ''
    ? password_hash($password, PASSWORD_BCRYPT)
    : null,
```

### Password Verification

```php
// In BoardService::deletePost()
if (!password_verify($password, $post->delete_password_hash)) {
    return false;
}
```

### Liveposting Edit Passwords

Edit passwords for liveposting are also bcrypt-hashed:

```php
'edit_password_hash' => password_hash($reclaimPassword, PASSWORD_BCRYPT),
'edit_expires_at' => Carbon::now()->addMinutes(30),
```

---

## Error Handling

### Generic Error Responses

```php
try {
    $result = $this->boardService->createThread($board, $data);
    return $this->response->json($result)->withStatus(201);
} catch (\Throwable $e) {
    // Log full details internally
    error_log($e->getMessage());
    // Return generic error to client
    return $this->response->json(['error' => 'An internal error occurred'])->withStatus(500);
}
```

### Specific vs Generic Errors

| Error Type | Client Response | Internal Log |
|------------|-----------------|--------------|
| Validation error | Specific message | Validation details |
| Business logic error | Specific message | Full exception |
| System error | Generic message | Full stack trace |

---

## Security Checklist

### Deployment

- [ ] `PII_ENCRYPTION_KEY` set to random 32-byte hex value
- [ ] `IP_HASH_SALT` set to random secret string
- [ ] Database credentials rotated from defaults
- [ ] Redis authentication enabled (if exposed)
- [ ] mTLS enabled for service-to-service communication
- [ ] Firewall rules restrict database/Redis access

### Operations

- [ ] Retention jobs running daily
- [ ] Failed requests monitored
- [ ] Staff actions audited
- [ ] Encryption key rotation planned

### Development

- [ ] PHPStan level 10 passing
- [ ] No hardcoded secrets in code
- [ ] Input validation on all user input
- [ ] Prepared statements for all queries
- [ ] XSS sanitization tested

---

## Compliance Features

### GDPR (General Data Protection Regulation)

| Requirement | Implementation |
|-------------|----------------|
| Data Minimization | PII encrypted at rest, auto-deleted after 30 days |
| Purpose Limitation | IP addresses only used for flood prevention and moderation |
| Right to Erasure | Post deletion removes user content |

### Data Inventory

| Data Type | Retention | Legal Basis |
|-----------|-----------|-------------|
| Post content | Indefinite | Legitimate interest (forum archive) |
| IP addresses | 30 days | Legitimate interest (flood prevention) |
| Post emails | 30 days | Consent (optional field) |
| Flood logs | 24 hours | Legitimate interest (abuse prevention) |

---

## Related Documentation

- [Architecture](ARCHITECTURE.md) - System architecture
- [API Reference](API.md) - API documentation
- [Type Hinting Guide](TYPE_HINTING_GUIDE.md) - PHPStan 10 compliance
- [Troubleshooting](TROUBLESHOOTING.md) - Common issues
