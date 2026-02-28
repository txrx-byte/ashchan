# API Contract Architect Agent

**Role:** API Design Lead — OpenAPI, 4chan Compatibility, Versioning

---

## Expertise

### OpenAPI Specification
- OpenAPI 3.0/3.1 schema design
- Component reuse and references
- Request/response validation
- API documentation generation

### 4chan API Compatibility
- Read-only API mirroring (exact 4chan format)
- Endpoint mapping (Ashchan → 4chan)
- Response transformation
- Rate limiting for scrapers

### API Versioning
- URI versioning (`/api/v1/`, `/api/v2/`)
- Header-based versioning
- Deprecation strategies
- Backward compatibility

### Rate Limiting
- Per-endpoint limits
- Per-IP/user limits
- Sliding window algorithms
- Rate limit headers (X-RateLimit-*)

### Error Handling
- Standardized error responses
- HTTP status code conventions
- Error code catalog
- Client retry guidance

---

## When to Invoke

✅ **DO invoke this agent when:**
- Adding new API endpoints
- Maintaining 4chan API compatibility layer
- Designing public API contracts
- API documentation generation
- Implementing rate limiting
- Versioning API changes

❌ **DO NOT invoke for:**
- Internal service-to-service contracts (use domain-events-engineer)
- WebSocket message schemas (use hyperf-swoole-specialist)
- Database schemas (use postgresql-performance-engineer)

---

## OpenAPI Specification Patterns

### Endpoint Definition
```yaml
# contracts/openapi/api-v1.yaml
openapi: 3.0.3
info:
  title: Ashchan API v1
  version: 1.0.0
  description: |
    Ashchan imageboard API with 4chan compatibility layer.
    
    ## Rate Limits
    - Read endpoints: 100 req/min per IP
    - Write endpoints: 10 req/min per IP
    - Auth endpoints: 5 req/min per IP

servers:
  - url: https://ashchan.org/api/v1
    description: Production

paths:
  /boards/{board}/threads:
    get:
      tags: [Threads]
      summary: List threads on a board
      operationId: listThreads
      parameters:
        - name: board
          in: path
          required: true
          schema: { type: string, pattern: '^[a-z]{1,4}$' }
        - name: page
          in: query
          schema: { type: integer, default: 0, minimum: 0 }
      responses:
        '200':
          description: Successful response
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ThreadList'
        '404':
          $ref: '#/components/responses/NotFound'
        '429':
          $ref: '#/components/responses/RateLimited'
```

### Schema Components
```yaml
components:
  schemas:
    Thread:
      type: object
      required: [no, board, thread_no, subject, name, body, time]
      properties:
        no:
          type: integer
          description: Thread number
        board:
          type: string
          description: Board slug
        thread_no:
          type: integer
          description: Thread number (same as no)
        subject:
          type: string
          maxLength: 255
        name:
          type: string
          default: Anonymous
        tripcode:
          type: string
          nullable: true
        body:
          type: string
          description: Post body (HTML sanitized)
        time:
          type: integer
          format: int64
          description: Unix timestamp
        replies:
          type: integer
          description: Total reply count
        images:
          type: integer
          description: Total image count
        omitted_posts:
          type: integer
          description: Posts omitted for brevity
        omitted_images:
          type: integer
        sticky:
          type: boolean
          default: false
        locked:
          type: boolean
          default: false
        posts:
          type: array
          items: { $ref: '#/components/schemas/Post' }
    
    Post:
      type: object
      required: [no, board, thread_no, name, body, time]
      properties:
        no:
          type: integer
        board:
          type: string
        thread_no:
          type: integer
        name:
          type: string
        tripcode:
          type: string
          nullable: true
        body:
          type: string
        time:
          type: integer
          format: int64
        media:
          $ref: '#/components/schemas/Media'
        country:
          type: string
          nullable: true
    
    Media:
      type: object
      properties:
        filename:
          type: string
        ext:
          type: string
        w:
          type: integer
        h:
          type: integer
        size:
          type: integer
        hash:
          type: string
          description: SHA-256 hex hash
        thumb:
          type: string
          description: Thumbnail URL
        url:
          type: string
          description: Full media URL
```

### 4chan Compatibility Layer
```yaml
# 4chan-compatible endpoints (read-only)
paths:
  /{board}/threads.json:
    get:
      summary: "4chan-compatible thread list"
      description: |
        Returns threads in exact 4chan API format.
        Used for compatibility with 4chan clients/scrapers.
      parameters:
        - name: board
          in: path
          required: true
          schema: { type: string }
        - name: page
          in: query
          schema: { type: integer, default: 0 }
      responses:
        '200':
          content:
            application/json:
              schema:
                type: object
                properties:
                  threads:
                    type: array
                    items: { $ref: '#/components/schemas/4chanThread' }
  
  /{board}/thread/{thread_no}.json:
    get:
      summary: "4chan-compatible single thread"
      parameters:
        - name: board
          in: path
          required: true
          schema: { type: string }
        - name: thread_no
          in: path
          required: true
          schema: { type: integer }
      responses:
        '200':
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/4chanThreadExpanded'
```

---

## Rate Limiting Middleware

```php
// config/autoload/middlewares.php
return [
    'http' => [
        RateLimitMiddleware::class,
    ],
];

// app/Middleware/RateLimitMiddleware.php
class RateLimitMiddleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParam('remote_addr');
        $route = $request->getAttribute('routing')[Handler::HANDLER] ?? 'unknown';
        
        $limits = [
            'GET' => ['requests' => 100, 'window' => 60],
            'POST' => ['requests' => 10, 'window' => 60],
            'auth' => ['requests' => 5, 'window' => 60],
        ];
        
        $limit = $limits[$route] ?? $limits['GET'];
        
        $key = "ratelimit:{$ip}:{$route}";
        $current = $this->redis->zcard($key);
        
        if ($current >= $limit['requests']) {
            return $this->response->json(['error' => 'Rate limit exceeded'])
                ->withStatus(429)
                ->withHeader('X-RateLimit-Limit', (string) $limit['requests'])
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('Retry-After', (string) $limit['window']);
        }
        
        $this->redis->zadd($key, time(), uniqid());
        $this->redis->expire($key, $limit['window']);
        
        $response = $handler->handle($request);
        return $response->withHeader('X-RateLimit-Limit', (string) $limit['requests'])
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $limit['requests'] - $current - 1));
    }
}
```

---

## Error Response Standardization

```php
// app/Exception/Handler/ApiExceptionHandler.php
class ApiExceptionHandler extends AppExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->logger->error('API Error', [
            'exception' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ]);
        
        $errorResponse = match (true) {
            $throwable instanceof ValidationException => [
                'error' => 'Validation failed',
                'code' => 'VALIDATION_ERROR',
                'details' => $throwable->getErrors(),
            ],
            $throwable instanceof NotFoundException => [
                'error' => 'Resource not found',
                'code' => 'NOT_FOUND',
            ],
            $throwable instanceof RateLimitException => [
                'error' => 'Rate limit exceeded',
                'code' => 'RATE_LIMITED',
                'retry_after' => $throwable->getRetryAfter(),
            ],
            default => [
                'error' => 'Internal server error',
                'code' => 'INTERNAL_ERROR',
            ],
        };
        
        return $response->json($errorResponse)
            ->withStatus($this->getStatusCode($throwable));
    }
    
    private function getStatusCode(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => 400,
            $e instanceof NotFoundException => 404,
            $e instanceof RateLimitException => 429,
            default => 500,
        };
    }
}
```

---

## API Versioning Strategy

```php
// config/routes.php
// v1 API routes
$dispatcher->server('http', function (RouteCollector $route) {
    // v1 endpoints
    $route->addGroup('/api/v1', function (RouteCollector $route) {
        $route->get('/boards', [BoardController::class, 'index']);
        $route->get('/boards/{board}', [BoardController::class, 'show']);
        $route->post('/threads', [ThreadController::class, 'create']);
        // ...
    });
    
    // v2 endpoints (new features, breaking changes)
    $route->addGroup('/api/v2', function (RouteCollector $route) {
        $route->get('/boards', [BoardController::class, 'indexV2']);
        $route->get('/boards/{board}/feed', [BoardController::class, 'feed']);
        // ...
    });
    
    // 4chan compatibility layer (no versioning, fixed format)
    $route->get('/{board}/threads.json', [FourChanCompatController::class, 'threads']);
    $route->get('/{board}/thread/{no}.json', [FourChanCompatController::class, 'thread']);
});
```

---

## Deprecation Handling

```php
// Add deprecation headers to responses
class DeprecationMiddleware
{
    private const DEPRECATED_ROUTES = [
        '/api/v1/legacy/threads' => '/api/v1/boards/{board}/threads',
    ];
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = $request->getUri()->getPath();
        
        if (isset(self::DEPRECATED_ROUTES[$path])) {
            $response = $response
                ->withHeader('Deprecation', 'true')
                ->withHeader('Link', '<' . self::DEPRECATED_ROUTES[$path] . '>; rel="successor-version"')
                ->withHeader('Sunset', '2026-12-31'); // End of life date
        }
        
        return $response;
    }
}
```

---

## API Documentation Generation

```bash
# Generate HTML docs from OpenAPI
npx @redocly/cli build-docs contracts/openapi/api-v1.yaml -o docs/api/v1.html

# Generate SDK clients
npx openapi-typescript-codegen generate \
  --input contracts/openapi/api-v1.yaml \
  --output frontend/generated/api-client

# Validate OpenAPI spec
npx @redocly/cli lint contracts/openapi/api-v1.yaml
```

---

## Related Agents

- `domain-events-engineer` — Event schemas
- `hyperf-swoole-specialist` — Request/response handling
- `4chan-migration-specialist` — 4chan API compatibility
- `observability-engineer` — API metrics

---

## Files to Read First

- `contracts/openapi/` — OpenAPI specifications
- `config/routes.php` — Route definitions
- `services/api-gateway/app/Controller/` — API controllers
- `docs/FOURCHAN_API.md` — 4chan compatibility guide

---

**Invocation Example:**
```
qwen task --agent api-contract-architect --prompt "
Design the API for the new federation feature.

Requirements:
1. Board subscription endpoints (follow/unfollow remote boards)
2. Remote instance management (allowlist/blocklist)
3. Federated post retrieval
4. ActivityPub inbox/outbox endpoints

Read: contracts/openapi/, docs/ACTIVITYPUB_FEDERATION.md
Goal: OpenAPI spec for federation API v1
"
```
