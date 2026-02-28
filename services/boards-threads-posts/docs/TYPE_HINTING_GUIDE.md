# Boards/Threads/Posts Service Type Hinting Guide

**Last Updated:** 2026-02-28
**PHPStan Level:** 10 (Maximum)
**PHP Version:** 8.2+

## Overview

This guide documents the type hinting conventions and PHPStan 10 compliance patterns used in the Boards/Threads/Posts service. All code must pass PHPStan level 10 analysis.

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
public function getThread(int $threadId, bool $includeIpHash = false): ?array
{
    // ...
}

// ❌ Bad - missing return type
public function getThread(int $threadId, bool $includeIpHash = false)
{
    // ...
}
```

### 2. Use Nullable Types Explicitly

Use `?Type` or `Type|null` syntax for nullable values.

```php
// ✅ Good
public function getBoard(string $slug): ?Board
{
    return Board::query()->where('slug', $slug)->first();
}

// ✅ Also good (explicit union)
public function getBoard(string $slug): Board|null
{
    return Board::query()->where('slug', $slug)->first();
}
```

### 3. Use Generic Types for Collections

Always specify array key/value types.

```php
// ✅ Good - array with integer keys, Board values
/** @var array<int, Board> */
$boards = Board::query()->get()->toArray();

// ✅ Good - array with string keys, mixed values
/** @var array<string, mixed> */
$data = [
    'name' => 'Anonymous',
    'content' => 'Post content',
    'media_url' => null,
];

// ✅ Good - nested generic types
/** @return array{threads: array<int, array<string, mixed>>, page: int, total_pages: int} */
public function getThreadIndex(Board $board): array { }

// ❌ Bad - untyped array
/** @var array */
$threads = Thread::query()->get()->toArray();
```

### 4. Document Complex Types in PHPDoc

Use `@var`, `@param`, and `@return` for complex types.

```php
/**
 * Create a new thread.
 *
 * @param Board $board The board to create the thread on
 * @param array<string, mixed> $data Thread data including name, content, media
 * @return array<string, int> Created thread and post IDs
 * @throws \RuntimeException If thread creation fails
 */
public function createThread(Board $board, array $data): array
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
 * Board model.
 *
 * @property int         $id
 * @property string      $slug
 * @property string      $title
 * @property string      $subtitle
 * @property string      $category
 * @property bool        $nsfw
 * @property int         $max_threads
 * @property int         $bump_limit
 * @property int         $image_limit
 * @property int         $cooldown_seconds
 * @property bool        $text_only
 * @property bool        $require_subject
 * @property string      $rules
 * @property bool        $archived
 * @property bool        $staff_only
 * @property bool        $user_ids
 * @property bool        $country_flags
 * @property int         $next_post_no
 * @property string      $created_at
 * @property string      $updated_at
 *
 * @method static \Hyperf\Database\Model\Builder<Board> query()
 * @method static Board|null find(mixed $id)
 * @method static Board create(array<string, mixed> $attributes)
 */
class Board extends Model
{
    // ...
}
```

### Constructor Property Promotion

Use constructor property promotion with explicit types.

```php
// ✅ Good
final class BoardController
{
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {
        // Properties automatically declared
    }
}
```

### Nullable Parameters with Defaults

```php
// ✅ Good - nullable with null default
public function createThread(
    Board $board,
    array $data,
    ?int $authorId = null,
): array {
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
$pageInput = $request->query('page', '1');
$page = max(1, is_numeric($pageInput) ? (int) $pageInput : 1);

// ✅ Good - type assertion with documentation
/** @var array<string, mixed>|null $body */
$body = json_decode((string) $request->getBody(), true);
if (!is_array($body)) {
    return $this->response->json(['results' => []]);
}
```

### Input from Request

Always validate and cast request input:

```php
// ✅ Good - validate and cast
$input = $request->all();
$name = isset($input['name']) && is_string($input['name']) ? $input['name'] : '';
$email = isset($input['email']) && is_string($input['email']) ? $input['email'] : '';
$subject = isset($input['sub']) && is_string($input['sub']) ? $input['sub'] : '';
$content = isset($input['com']) && is_string($input['com']) ? $input['com'] : '';

// ✅ Good - numeric validation and cast
$afterInput = $request->query('after', '0');
$after = is_numeric($afterInput) ? (int) $afterInput : 0;

// ✅ Good - array validation
$idsInput = $request->input('ids', []);
$ids = is_array($idsInput) ? $idsInput : [];
```

---

## PHPStan Ignore Annotations

Use `@phpstan-ignore-next-line` sparingly and only when:

1. The error is a false positive
2. The code is correct but PHPStan cannot infer it
3. Refactoring is not possible

```php
// ✅ Good - documented false positive (Hyperf magic methods)
// @phpstan-ignore-next-line
$board = Board::create([
    'slug' => $data['slug'],
    'title' => $data['title'],
    // ...
]);
```

**Why This Is Needed:** Hyperf's `Model::create()` uses magic methods that PHPStan cannot fully analyze. The code is correct and tested.

---

## Return Type Patterns

### Returning Model Instances

```php
/**
 * Create a new board.
 *
 * @param array<string, mixed> $data Board data
 * @return Board The created board model
 */
public function createBoard(array $data): Board
{
    $board = new Board();
    $board->slug = (string) $data['slug'];
    $board->title = (string) ($data['title'] ?? '');
    // ...
    $board->save();
    return $board;
}
```

### Returning Optional Models

```php
/**
 * Get a board by slug.
 *
 * @param string $slug Board slug
 * @return Board|null The board or null if not found
 */
public function getBoard(string $slug): ?Board
{
    return Board::query()->where('slug', $slug)->first();
}
```

### Returning Arrays with Structure

```php
/**
 * Get thread index with pagination.
 *
 * @param Board $board The board
 * @param int $page Page number
 * @param int $perPage Threads per page
 * @param bool $includeIpHash Include IP hashes (staff only)
 * @return array{
 *     threads: array<int, array<string, mixed>>,
 *     page: int,
 *     total_pages: int,
 *     total: int
 * }
 */
public function getThreadIndex(Board $board, int $page = 1, int $perPage = 15, bool $includeIpHash = false): array
{
    // ...
}
```

### Returning Void

Always declare `void` for methods that don't return:

```php
/**
 * Invalidate board-related caches.
 */
private function invalidateBoardCaches(): void
{
    try {
        $this->redis->del('boards:all');
        // ...
    } catch (\Throwable $e) {
        // Redis unavailable
    }
}
```

---

## Type Casting Patterns

### Safe Integer Casting

```php
// ✅ Good - validate then cast
$pageInput = $request->query('page', '1');
$page = max(1, is_numeric($pageInput) ? (int) $pageInput : 1);

// ✅ Good - with bounds checking
$limitInput = $request->query('limit', '100');
$limit = min(500, max(1, is_numeric($limitInput) ? (int) $limitInput : 100));
```

### Safe String Casting

```php
// ✅ Good - with null coalescing and type check
$name = isset($input['name']) && is_string($input['name']) ? $input['name'] : '';
$subject = isset($input['sub']) && is_string($input['sub']) ? $input['sub'] : '';

// ✅ Good - with substring for length limit
$rawName = $data['name'] ?? '';
$name = is_string($rawName) ? mb_substr($rawName, 0, 100) : '';
```

### Boolean Casting

```php
// ✅ Good - explicit boolean cast
$spoiler = isset($input['spoiler']) ? (bool) $input['spoiler'] : false;
$imageOnly = (bool) $request->input('image_only', false);

// ✅ Good - from string
$board->nsfw = (bool) ($data['nsfw'] ?? false);
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

final class ThreadController
{
    public function index(RequestInterface $request, string $slug): ResponseInterface
    {
        $pageInput = $request->query('page', '1');
        $page = max(1, is_numeric($pageInput) ? (int) $pageInput : 1);
        // ...
    }
}
```

### Redis Operations

```php
use Hyperf\Redis\Redis;

final class BoardService
{
    public function __construct(private Redis $redis)
    {
    }

    public function listBoards(): array
    {
        try {
            $cached = $this->redis->get('boards:all');
            if (!is_string($cached)) {
                // Cache miss
            }

            /** @var array<int, array<string, mixed>>|false $decoded */
            $decoded = json_decode($cached, true);
            if (!is_array($decoded)) {
                // Invalid cache data
            }

            return $decoded;
        } catch (\Throwable) {
            // Redis unavailable
        }

        // Fall through to database
    }
}
```

### Database Query Results

```php
use Hyperf\DbConnection\Db;

// ✅ Good - typed result from raw query
$nextIdResult = Db::select("SELECT nextval('posts_id_seq') as id");
/** @var object{id: string|int} $row */
$row = $nextIdResult[0];
$nextId = (int) $row->id;

// ✅ Good - typed result from window function
$replyRows = Db::select(
    "SELECT p.* FROM (...)",
    ['{' . implode(',', $threadIds) . '}']
);
// Hydrate into Post models
foreach ($replyRows as $row) {
    $post = new Post();
    $post->forceFill((array) $row);
    $post->exists = true;
}
```

---

## Exception Handling

### Typed Exception Throws

```php
/**
 * Create a new thread.
 *
 * @param Board $board The board
 * @param array<string, mixed> $data Thread data
 * @return array<string, int> Created IDs
 * @throws \RuntimeException If board ID is invalid or ID generation fails
 */
public function createThread(Board $board, array $data): array
{
    if (empty($board->id)) {
        throw new \RuntimeException("Board ID is missing or invalid: " . var_export($board->id, true));
    }

    try {
        $nextIdResult = Db::select("SELECT nextval('posts_id_seq') as id");
        $row = $nextIdResult[0];
        $nextId = (int) $row->id;
    } catch (\Throwable $e) {
        throw new \RuntimeException("Failed to generate ID: " . $e->getMessage());
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
    $cached = $this->redis->get($key);
    if (is_string($cached)) {
        $decoded = json_decode($cached, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
} catch (\Throwable $e) {
    // Redis unavailable, fall through to DB
}
```

---

## Collection and Array Patterns

### Hyperf Collection Type Hints

```php
use Hyperf\Database\Model\Collection;

/** @var Collection<int, Post> $posts */
$posts = Post::query()
    ->where('thread_id', $threadId)
    ->where('deleted', false)
    ->get();

// Type-safe iteration
foreach ($posts as $post) {
    // $post is typed as Post
    echo $post->content;
}
```

### Array Key-By Pattern

```php
// ✅ Good - keyBy with typed result
/** @var Collection<int, Post> $allOps */
$allOps = Post::query()
    ->whereIn('thread_id', $threadIds)
    ->where('is_op', true)
    ->get()
    ->keyBy('thread_id');

// Type-safe lookup
$op = $allOps->get($thread->id);  // Post|null
```

### Chunk Pattern

```php
// ✅ Good - chunk with typed callback
$chunks = $threads->chunk($perPage);
foreach ($chunks as $chunk) {
    // $chunk is Collection<int, Thread>
    foreach ($chunk as $thread) {
        // $thread is Thread
    }
}
```

---

## Common PHPStan Errors and Fixes

### Error: Return type has no value type

**Fix:** Add generic type annotation

```php
// ❌ Before
/** @return array */
public function getBoards(): array { }

// ✅ After
/** @return array<int, array<string, mixed>> */
public function getBoards(): array { }
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
/** @var array<string, mixed>|null $decoded */
$decoded = json_decode($json, true);
if (!is_array($decoded) || !isset($decoded['key'])) {
    throw new \InvalidArgumentException('Missing key');
}
return $decoded['key'];
```

### Error: Strict comparison using === will always evaluate to false

**Fix:** Check types are compatible

```php
// ❌ Before - $count is int, comparing to false
if ($count === false) { }

// ✅ After - proper error handling
if ($count < 0) { }
```

### Error: Offset 'X' does not accept type 'Y'

**Fix:** Ensure array key types match

```php
// ❌ Before
$backlinks = [];
foreach ($posts as $post) {
    $backlinks[$post->id] = [];  // $post->id might be int, array expects string
}

// ✅ After
$backlinks = [];
foreach ($posts as $post) {
    $backlinks[(string) $post->id] = [];  // Explicit cast to string
}
```

---

## Model-Specific Patterns

### Relationship Return Types

```php
/** @return \Hyperf\Database\Model\Relations\HasMany<Thread, $this> */
public function threads(): \Hyperf\Database\Model\Relations\HasMany
{
    return $this->hasMany(Thread::class, 'board_id');
}

/** @return \Hyperf\Database\Model\Relations\BelongsTo<Board, $this> */
public function board(): \Hyperf\Database\Model\Relations\BelongsTo
{
    return $this->belongsTo(Board::class, 'board_id');
}
```

### Accessor Return Types

```php
/**
 * Format media size for display.
 *
 * @return string Human-readable size (e.g., "1.23 MB")
 */
public function getMediaSizeHumanAttribute(): string
{
    $bytes = $this->media_size ?? 0;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return number_format($bytes / 1048576, 2) . ' MB';
}
```

---

## Best Practices Summary

1. **Always declare return types** - Even for `void` methods
2. **Use nullable types explicitly** - `?string` or `string|null`
3. **Document complex types** - Use `@var`, `@param`, `@return`
4. **Validate before casting** - Check `is_string()`, `is_int()`, etc.
5. **Use generic array types** - `array<int, Post>`, `array<string, mixed>`
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
