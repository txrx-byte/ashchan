# PHPStan 10 Type Hinting Guide

**Last Updated:** 2026-02-28  
**PHPStan Level:** 10 (Maximum)  
**PHP Version:** 8.2+

## Overview

This guide documents the type hinting conventions and PHPStan 10 compliance patterns used in the Auth/Accounts service. All code must pass PHPStan level 10 analysis.

---

## PHPStan Configuration

```neon
# phpstan.neon
parameters:
    level: 10
    bootstrapFiles:
        - phpstan-bootstrap.php
    paths:
        - app
        - config
    reportUnmatchedIgnoredErrors: false
    ignoreErrors:
        - '#Static call to instance method Hyperf\DbConnection\Db::.*#'
        - '#Static call to instance method Hyperf\HttpServer\Router\Router::.*#'
```

**Ignored Errors:**

- Static calls to `Db::` and `Router::` are framework patterns that cannot be refactored
- All other errors must be fixed, not ignored

---

## Type Declaration Rules

### 1. Always Declare Return Types

Every function and method must have an explicit return type.

```php
// ✅ Good
public function getUser(int $id): ?User
{
    return User::find($id);
}

// ❌ Bad - missing return type
public function getUser(int $id)
{
    return User::find($id);
}
```

### 2. Use Nullable Types Explicitly

Use `?Type` or `Type|null` syntax for nullable values.

```php
// ✅ Good
private function getExpiresAt(): ?string
{
    return $this->ban_expires_at;
}

// ✅ Also good (explicit union)
private function getExpiresAt(): string|null
{
    return $this->ban_expires_at;
}
```

### 3. Use Generic Types for Collections

Always specify array key/value types.

```php
// ✅ Good - array with integer keys, User values
/** @var array<int, User> */
$users = User::query()->get()->toArray();

// ✅ Good - array with string keys, mixed values
/** @var array<string, mixed> */
$data = ['user_id' => 1, 'username' => 'admin'];

// ❌ Bad - untyped array
/** @var array */
$users = User::query()->get()->toArray();
```

### 4. Document Complex Types in PHPDoc

Use `@var`, `@param`, and `@return` for complex types.

```php
/**
 * Validate a session token and return associated user data.
 *
 * @param string $token The raw session token from the client
 * @return array<string, mixed>|null User data or null if invalid/expired/banned
 */
public function validateToken(string $token): ?array
{
    // ...
}
```

---

## Common Type Patterns

### Model Properties

Document model properties with `@property` annotations.

```php
/**
 * Staff user account model.
 *
 * @property int         $id
 * @property string      $username
 * @property string      $password_hash
 * @property string      $email
 * @property string      $role           One of: admin, manager, mod, janitor, user
 * @property bool        $banned
 * @property string|null $ban_reason
 * @property string|null $ban_expires_at ISO 8601 timestamp or null
 * @property string      $created_at
 * @property string      $updated_at
 *
 * @method static User|null find(mixed $id)
 * @method static User findOrFail(mixed $id)
 * @method static \Hyperf\Database\Model\Builder<User> query()
 * @method static User create(array<string, mixed> $attributes)
 */
final class User extends Model
{
    // ...
}
```

### Constructor Property Promotion

Use constructor property promotion with explicit types.

```php
// ✅ Good
final class AuthController
{
    public function __construct(
        private AuthService $authService,
        private PiiEncryptionService $piiEncryption,
        private HttpResponse $response,
        private SiteConfigService $config,
    ) {
        // Properties automatically declared
    }
}
```

### Nullable Parameters with Defaults

```php
// ✅ Good - nullable with null default
public function banUser(int $userId, string $reason, ?string $expiresAt = null): void
{
    // ...
}

// ✅ Good - nullable with string default
public function getConfig(string $key, string $default = ''): string
{
    // ...
}
```

---

## Handling Mixed Types

### When `mixed` is Unavoidable

Some framework methods return `mixed`. Cast explicitly:

```php
// ✅ Good - explicit cast after validation
$userId = $user['user_id'] ?? 0;
if (!is_int($userId) || $userId <= 0) {
    return $this->response->json(['error' => 'Invalid user'])->withStatus(400);
}

// ✅ Good - type assertion with documentation
/** @var array<string, mixed> $decoded */
$decoded = json_decode($cached, true);
if (!is_array($decoded)) {
    return null;
}
```

### Input from Request

Always validate and cast request input:

```php
// ✅ Good - validate and cast
$username = $request->input('username', '');
$password = $request->input('password', '');

if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
    return $this->response->json(['error' => 'Username and password required'])->withStatus(400);
}

// ✅ Good - numeric validation and cast
$userId = $request->input('user_id');
if (!is_numeric($userId) || (int) $userId <= 0) {
    return $this->response->json(['error' => 'Invalid user ID'])->withStatus(400);
}
$userId = (int) $userId;
```

---

## PHPStan Ignore Annotations

Use `@phpstan-ignore-next-line` sparingly and only when:

1. The error is a false positive
2. The code is correct but PHPStan cannot infer it
3. Refactoring is not possible

```php
// ✅ Good - documented false positive
// @phpstan-ignore-next-line
return User::create([
    'username'      => $username,
    'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
    'email'         => $email,
    'role'          => $role,
]);
```

**Why This Is Needed:** Hyperf's `Model::create()` uses magic methods that PHPStan cannot fully analyze. The code is correct and tested.

---

## Return Type Patterns

### Returning Model Instances

```php
/**
 * Register a new staff user.
 *
 * @param string $username Unique username
 * @param string $password Plaintext password
 * @param string $email    Optional email address
 * @param string $role     User role
 * @return User The created user model
 * @throws \RuntimeException If the username is already taken
 */
public function register(string $username, string $password, string $email, string $role = 'user'): User
{
    if (User::query()->where('username', $username)->exists()) {
        throw new \RuntimeException('Username already taken');
    }

    return User::create([/* ... */]);
}
```

### Returning Optional Models

```php
/**
 * Find a user by ID.
 *
 * @param int $id User ID
 * @return User|null The user or null if not found
 */
public function findUser(int $id): ?User
{
    return User::find($id);
}
```

### Returning Arrays

```php
/**
 * Get all data requests for a user.
 *
 * @param int $userId The user's ID
 * @return array<int, array<string, mixed>> Array of request records
 */
public function getDataRequests(int $userId): array
{
    /** @var array<int, array<string, mixed>> $data */
    $data = DeletionRequest::query()
        ->where('user_id', $userId)
        ->orderByDesc('requested_at')
        ->get()
        ->toArray();
    return $data;
}
```

### Returning Void

Always declare `void` for methods that don't return:

```php
/**
 * Destroy a session by its raw token.
 *
 * @param string $token The raw session token
 */
public function logout(string $token): void
{
    $tokenHash = hash('sha256', $token);
    $this->redis->del("session:{$tokenHash}");
    Session::query()->where('token', $tokenHash)->delete();
}
```

---

## Type Casting Patterns

### Safe Integer Casting

```php
// ✅ Good - validate then cast
$duration = $request->input('duration', 86400);
$durationInt = is_numeric($duration) ? (int) $duration : 86400;

// ✅ Good - with bounds checking
$durationInt = max($this->minBanDuration, min($this->maxBanDuration, $durationInt));
```

### Safe String Casting

```php
// ✅ Good - with null coalescing and type check
$remoteAddr = $request->server('remote_addr', '');
$ip = is_string($remoteAddr) ? $remoteAddr : '';

// ✅ Good - with substring for length limit
$reasonStr = is_string($reason) ? mb_substr($reason, 0, 500) : '';
```

### Boolean Casting

```php
// ✅ Good - explicit boolean cast
$consented = (bool) $request->input('consented', false);

// ✅ Good - string to boolean conversion
public function getBool(string $key, bool $default = false): bool
{
    $value = $this->get($key, $default ? 'true' : 'false');
    return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
}
```

---

## Handling Framework Types

### Hyperf Response Interface

```php
use Psr\Http\Message\ResponseInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;

final class HealthController
{
    public function __construct(private HttpResponse $response)
    {
    }

    public function check(): ResponseInterface
    {
        return $this->response->json(['status' => 'ok']);
    }
}
```

### Hyperf Request Interface

```php
use Hyperf\HttpServer\Contract\RequestInterface;

public function login(RequestInterface $request): ResponseInterface
{
    $username = $request->input('username', '');
    // ...
}
```

### Redis Operations

```php
use Hyperf\Redis\Redis;

public function __construct(private Redis $redis)
{
}

public function getSession(string $tokenHash): ?array
{
    try {
        $cached = $this->redis->get("session:{$tokenHash}");
        if (!is_string($cached) || $cached === '') {
            return null;
        }
        
        /** @var array<string, mixed>|false $decoded */
        $decoded = json_decode($cached, true);
        if (!is_array($decoded)) {
            return null;
        }
        
        return $decoded;
    } catch (\Throwable) {
        return null;
    }
}
```

---

## Exception Handling

### Typed Exception Throws

```php
/**
 * @throws \RuntimeException If the username is already taken
 */
public function register(string $username, string $password, string $email, string $role): User
{
    if (User::query()->where('username', $username)->exists()) {
        throw new \RuntimeException('Username already taken');
    }
    // ...
}
```

### Catching Exceptions

```php
// ✅ Good - catch specific exceptions when possible
try {
    $result = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(/* ... */);
    if ($result === false) {
        throw new \SodiumException('Authentication failed');
    }
    return $result;
} catch (\SodiumException $e) {
    $this->logger->error('Decryption failed: ' . $e->getMessage());
    return '[DECRYPTION_FAILED]';
}

// ✅ Good - catch Throwable for framework operations
try {
    $this->redis->setex("session:{$tokenHash}", $this->sessionTtl, $json);
} catch (\Throwable) {
    // Redis failure is non-fatal — DB fallback will handle validation
}
```

---

## Common PHPStan Errors and Fixes

### Error: Return type has no value type

**Fix:** Add generic type annotation

```php
// ❌ Before
/** @return array */
public function getData(): array { }

// ✅ After
/** @return array<string, mixed> */
public function getData(): array { }
```

### Error: Parameter type is too wide

**Fix:** Narrow the type

```php
// ❌ Before
public function process($data) { }

// ✅ After
public function process(array $data): void { }
```

### Error: Cannot access offset on mixed

**Fix:** Add type assertion

```php
// ❌ Before
$data = json_decode($json, true);
return $data['key'];

// ✅ After
/** @var array<string, mixed> $data */
$data = json_decode($json, true);
if (!isset($data['key'])) {
    throw new \InvalidArgumentException('Missing key');
}
return $data['key'];
```

### Error: Strict comparison using === will always evaluate to false

**Fix:** Check types are compatible

```php
// ❌ Before - $count is int, comparing to false
if ($count === false) { }

// ✅ After - proper error handling
if ($count < 0) { }
```

---

## Best Practices Summary

1. **Always declare return types** - Even for `void` methods
2. **Use nullable types explicitly** - `?string` or `string|null`
3. **Document complex types** - Use `@var`, `@param`, `@return`
4. **Validate before casting** - Check `is_string()`, `is_int()`, etc.
5. **Use generic array types** - `array<int, User>`, `array<string, mixed>`
6. **Document model properties** - Use `@property` annotations
7. **Handle mixed explicitly** - Cast and validate framework returns
8. **Minimize ignore annotations** - Only for verified false positives
9. **Document exceptions** - Use `@throws` for declared exceptions
10. **Run PHPStan frequently** - Catch errors early in development

---

## Related Documentation

- [Architecture](ARCHITECTURE.md) - System architecture
- [Security Model](SECURITY.md) - Security considerations
- [API Reference](API.md) - API documentation
- [Troubleshooting](TROUBLESHOOTING.md) - Common issues
