# PHPStan Level 10 Quick Reference for Tests

**Last Updated:** February 28, 2026  
**For:** Ashchan Hyperf Microservices

---

## Common Errors & Solutions

### 1. Unused Parameter in Test Method

**Error:**
```
Method App\Tests\Unit\ServiceTest::testSomething() has an unused parameter $value.
```

**Solutions:**

```php
// ✅ Use the parameter
public function testSomething(string $value): void
{
    $this->service->process($value);
}

// ✅ Prefix with underscore (if truly unused)
public function testSomething(string $_value): void
{
    // Test doesn't need the value
}

// ✅ Ignore in tests/phpstan.neon
ignoreErrors:
    - '#Method .*Test::.* has an unused parameter#'
```

---

### 2. Mock Return Type is Mixed

**Error:**
```
Call to method getConfig() on mixed.
```

**Solutions:**

```php
// ✅ Use return type in callback
$config->method('get')->willReturnCallback(function (string $key): string {
    return match ($key) {
        'key' => 'value',
        default => 'default',
    };
});

// ✅ Use @var annotation
/** @var string $result */
$result = $config->get('key');

// ✅ Assert type before use
$result = $config->get('key');
self::assertIsString($result);
```

---

### 3. Cannot Access Property on Mixed

**Error:**
```
Cannot access property $name on mixed.
```

**Solutions:**

```php
// ✅ AssertNotNull first
$user = $this->service->getUser(1);
self::assertNotNull($user);
self::assertEquals('test', $user->name);

// ✅ Use instanceof
if ($user instanceof User) {
    self::assertEquals('test', $user->name);
}

// ✅ @var annotation
/** @var User $user */
$user = $this->service->getUser(1);
```

---

### 4. Data Provider Return Type

**Error:**
```
Method dataProvider() return type has no value type specified in iterable type array.
```

**Solutions:**

```php
// ✅ Explicit return type
/**
 * @return iterable<array{string, bool, string}>
 */
public static function validationProvider(): iterable
{
    yield 'valid' => ['test@example.com', true, 'Valid email'];
    yield 'invalid' => ['invalid', false, 'Invalid email'];
}

// ✅ For complex structures
/**
 * @return iterable<string, array{
 *     input: array<string, mixed>,
 *     expected: array{valid: bool, errors: list<string>},
 *     description: string
 * }>
 */
public static function complexProvider(): iterable
{
    yield 'case' => [
        'input' => ['key' => 'value'],
        'expected' => ['valid' => true, 'errors' => []],
        'description' => 'Test description',
    ];
}
```

---

### 5. Final Class Cannot Be Mocked

**Error:**
```
Class Hyperf\Redis\Redis is final and cannot be mocked.
```

**Solutions:**

```php
// ✅ Ensure DG\BypassFinals is enabled (tests/bootstrap.php)
DG\BypassFinals::enable();

// ✅ Use addMethods() for final classes
$redis = $this->getMockBuilder(Redis::class)
    ->disableOriginalConstructor()
    ->onlyMethods(['get', 'set', 'del'])
    ->getMock();

// ✅ Create wrapper interface (best for production code)
interface RedisClientInterface {
    public function get(string $key): mixed;
}
```

---

### 6. Void Return Type Issues

**Error:**
```
Method testSomething() has invalid return type void.
```

**Solutions:**

```php
// ✅ Always return void explicitly
public function testSomething(): void
{
    // No return statement needed
}

// ✅ Use expectException instead of try-catch
public function testThrowsException(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->service->invalidOperation();
}

// ✅ Avoid early returns
public function testWithCondition(): void
{
    self::assertTrue($condition);
    // Continue test...
}
```

---

### 7. Array Key Access on Mixed

**Error:**
```
Cannot access offset 'key' on mixed.
```

**Solutions:**

```php
// ✅ Assert array type first
$result = $this->service->getData();
self::assertIsArray($result);
self::assertArrayHasKey('key', $result);
self::assertEquals('value', $result['key']);

// ✅ Use isset check
if (isset($result['key']) && is_string($result['key'])) {
    self::assertEquals('value', $result['key']);
}

// ✅ @var annotation
/** @var array{key: string} $result */
$result = $this->service->getData();
```

---

### 8. Assertion Type Narrowing

**Error:**
```
Parameter #1 of method assertEquals() expects string, mixed given.
```

**Solutions:**

```php
// ✅ Assert type before assertion
$value = $model->getAttribute('name');
self::assertIsString($value);
self::assertEquals('expected', $value);

// ✅ Use typed accessor methods
self::assertEquals('expected', $model->getName());

// ✅ @var annotation
/** @var string $value */
$value = $model->getAttribute('name');
```

---

### 9. Mockery Intersection Types

**Error:**
```
Property $mock type is invalid.
```

**Solutions:**

```php
// ✅ PHP 8.2+ intersection type
private EventPublisher&\Mockery\MockInterface $publisher;

// ✅ PHPUnit MockObject
private EventPublisher&\PHPUnit\Framework\MockObject\MockObject $publisher;

// ✅ Initialize in setUp
protected function setUp(): void
{
    $this->publisher = \Mockery::mock(EventPublisher::class);
}
```

---

### 10. Side Effects in Tests

**Error:**
```
Side effects of method setUp() are not allowed.
```

**Solutions:**

```php
// ✅ Ignore in tests/phpstan.neon
ignoreErrors:
    - '#Side effects of .*#'

// ✅ Or use @phpstan-ignore-line sparingly
protected function setUp(): void
{
    // @phpstan-ignore-line - Test setup requires side effects
    parent::setUp();
}
```

---

## Type Hinting Cheat Sheet

### Property Declarations

```php
// Service under test
private UserService $service;

// Mock with intersection type
private Redis&MockObject $redis;
private EventPublisher&MockInterface $publisher;

// Nullable property
private ?string $token = null;

// Generic arrays
/** @var array<int, User> */
private array $users = [];

/** @var array<string, mixed> */
private array $config = [];

/** @var list<string> */
private array $errors = [];
```

### Method Return Types

```php
// Test methods always return void
public function testSomething(): void {}

// Data providers return iterable
/** @return iterable<array{string, bool}> */
public static function provider(): iterable {}

// Helper methods with explicit types
private function createUser(string $name): User {}
private function getConfig(): array {}
```

### Parameter Types

```php
// Named parameters for clarity
$result = $this->service->check(
    ipHash: 'hash123',
    content: 'test content',
    isThread: false,
);

// Nullable parameters
public function process(?string $value = null): void {}

// Generic array parameters
/** @param array<string, mixed> $data */
public function fill(array $data): void {}
```

---

## PHPUnit 10 Attributes

```php
use PHPUnit\Framework\Attributes\{
    CoversClass,
    CoversMethod,
    DataProvider,
    Depends,
    Group,
    Test,
    TestDox,
};

#[CoversClass(UserService::class)]
#[Group('unit')]
final class UserServiceTest extends TestCase
{
    #[Test]
    #[DataProvider('userProvider')]
    public function testCreateUser(array $data, bool $expected): void {}
    
    #[Test]
    #[Depends('testCreateUser')]
    public function testGetUser(): void {}
    
    #[Test]
    #[TestDox('Creates a user with valid data')]
    public function testCreateValidUser(): void {}
}
```

---

## PHPStan Configuration Quick Copy

### tests/phpstan.neon

```neon
includes:
    - ../phpstan.neon

parameters:
    level: 9
    paths:
        - tests
    bootstrapFiles:
        - tests/phpstan-test-bootstrap.php
    stubFiles:
        - tests/stubs/phpunit.stub
        - tests/stubs/hyperf.stub
        - tests/stubs/mockery.stub
    ignoreErrors:
        - '#Unused parameter#'
        - '#Side effects#'
        - '#Mockery.*MockInterface#'
```

### tests/bootstrap.php

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
DG\BypassFinals::enable();
```

---

## Makefile Commands

```bash
# Run all tests
make test

# Run PHPStan on production code
make phpstan

# Run PHPStan on test code
make phpstan-tests

# Run PHPStan on everything
make phpstan-all

# Generate baseline
make phpstan-baseline

# Run with coverage
make test-coverage
```

---

## Best Practices Summary

| Do | Don't |
|----|-------|
| Use explicit type hints | Rely on PHPStan to infer types |
| Narrow types with assertions | Access properties on mixed |
| Use intersection types for mocks | Suppress errors without justification |
| Document WHY in PHPDoc | Document WHAT (code shows that) |
| Group related assertions | Have scattered assertions |
| Clean up in tearDown() | Leave test artifacts |
| Use PHPUnit 10 attributes | Use old @annotation syntax |
| Create test helpers | Repeat setup code |

---

## Related Documentation

- [PHPSTAN_TESTS_GUIDE.md](./PHPSTAN_TESTS_GUIDE.md) - Comprehensive guide
- [TYPE_HINTING_GUIDE.md](./TYPE_HINTING_GUIDE.md) - Type hinting standards
- [BEST_PRACTICES.md](./BEST_PRACTICES.md) - PHPStan 10 patterns

---

**Quick Help:** `make help | grep -E 'phpstan|test'`
