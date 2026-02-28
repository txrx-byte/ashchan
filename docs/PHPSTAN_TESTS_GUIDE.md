# PHPStan Level 10 Guide for PHPUnit Tests

**Last Updated:** February 28, 2026  
**Applies To:** Ashchan Hyperf Microservices Application  
**PHP Version:** 8.2+  
**PHPUnit Version:** 10.0+  
**PHPStan Version:** 2.1+

---

## Table of Contents

1. [PHPStan Configuration for Tests](#1-phpstan-configuration-for-tests)
2. [Test Code Quality](#2-test-code-quality)
3. [Common Issues and Solutions](#3-common-issues-and-solutions)
4. [Best Practices](#4-best-practices)
5. [Bootstrap Considerations](#5-bootstrap-considerations)
6. [Reference Examples](#6-reference-examples)

---

## 1. PHPStan Configuration for Tests

### 1.1 Should You Have Separate PHPStan Config for Tests?

**Yes, absolutely.** Tests have different requirements than production code:

- Tests intentionally access private/protected members via reflection
- Tests use mocks that may not perfectly match interface contracts
- Tests may have unused parameters (required by PHPUnit data provider interface)
- Tests often work with mixed types from fixtures and data providers

### 1.2 Recommended `phpstan.tests.neon` Structure

Create a separate configuration file at `tests/phpstan.neon` in each service:

```neon
# tests/phpstan.neon
includes:
    - ../phpstan.neon  # Extend main config

parameters:
    # Lower level for tests - some patterns are acceptable in test code
    level: 9
    
    # Include test directories
    paths:
        - tests
    
    # Additional bootstrap for test-specific autoloading
    bootstrapFiles:
        - tests/phpstan-test-bootstrap.php
    
    # Relax rules that are overly strict for test code
    ignoreErrors:
        # Unused parameters in test methods (required by PHPUnit interface)
        - '#Method .* has an unused parameter .*#'
        
        # Mixed type from mocks is acceptable in tests
        - '#Call to an undefined method .* on Mockery.*#'
        - '#Access to an undefined property .* on Mockery.*#'
        
        # Data providers returning mixed arrays
        - '#Method .* return type has no value type specified in iterable type array#'
        
        # PHPUnit assertion type inference limitations
        - '#Parameter .* of method PHPUnit.* expects .*#'
        
        # Test helper methods may have unused private methods for future tests
        - '#Unused private method .*#'
        
        # Side effects are intentional in tests
        - '#Side effects of .*#'
```

### 1.3 Root `phpstan.neon` Integration

Update your main `phpstan.neon` to include tests with relaxed rules:

```neon
# phpstan.neon (root or service-level)
parameters:
    level: 10
    paths:
        - app
        - config
    
    # Separate test analysis with different rules
    scanDirectories:
        - tests
    
    # Report unmatched ignored errors in production code only
    reportUnmatchedIgnoredErrors: true
```

### 1.4 Makefile Integration

```makefile
# Makefile
.PHONY: phpstan phpstan-tests phpstan-all

phpstan:
    vendor/bin/phpstan analyse --configuration phpstan.neon

phpstan-tests:
    vendor/bin/phpstan analyse --configuration tests/phpstan.neon

phpstan-all: phpstan phpstan-tests
```

---

## 2. Test Code Quality

### 2.1 Type Hints in Tests

**Always use strict type hints in test code.** PHPStan 10 can infer types better when you're explicit:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UserService;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    // ✅ Good: Explicit property type
    private UserService $service;
    
    // ✅ Good: Explicit nullable type
    private ?string $testToken = null;
    
    // ✅ Good: Generic array type
    /** @var array<int, array<string, mixed>> */
    private array $testData = [];
    
    protected function setUp(): void
    {
        $this->service = new UserService();
        $this->testToken = bin2hex(random_bytes(16));
    }
}
```

### 2.2 Mock Object Typing

Use intersection types for mocks (PHP 8.2+):

```php
<?php
declare(strict_types=1);

use App\Service\EventPublisher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase
{
    // ✅ PHP 8.2+ intersection type for mocks
    private EventPublisher&MockObject $publisher;
    
    protected function setUp(): void
    {
        // ✅ Explicit mock type
        $this->publisher = $this->createMock(EventPublisher::class);
    }
    
    public function testPublishesEvent(): void
    {
        // ✅ Type-safe expectation
        $this->publisher
            ->expects(self::once())
            ->method('publish')
            ->with(self::callback(function (array $event): bool {
                return isset($event['type'], $event['payload']);
            }));
        
        // Test implementation...
    }
}
```

**For Mockery (if used):**

```php
<?php
declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class MediaServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    
    // ✅ Intersection type with Mockery
    private EventPublisher&MockInterface $eventPublisher;
    
    protected function setUp(): void
    {
        $this->eventPublisher = \Mockery::mock(EventPublisher::class);
    }
}
```

### 2.3 Data Providers

Data providers require special attention for PHPStan 10:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ValidationServiceTest extends TestCase
{
    /**
     * @return iterable<array{string, bool, string}>
     */
    public static function emailValidationProvider(): iterable
    {
        // ✅ Explicit return type with generic
        yield 'valid email' => ['user@example.com', true, 'Valid email'];
        yield 'missing @' => ['invalid.email', false, 'Missing @ symbol'];
        yield 'missing domain' => ['user@', false, 'Missing domain'];
        yield 'empty string' => ['', false, 'Empty string'];
    }
    
    #[DataProvider('emailValidationProvider')]
    public function testValidateEmail(
        string $email,
        bool $expectedValid,
        string $description
    ): void {
        // ✅ All parameters have explicit types
        $result = $this->service->validateEmail($email);
        
        self::assertSame($expectedValid, $result->isValid(), $description);
    }
}
```

**For complex data providers:**

```php
<?php
declare(strict_types=1);

/**
 * @return iterable<string, array{
 *     input: array<string, mixed>,
 *     expected: array{valid: bool, errors: list<string>},
 *     description: string
 * }>
 */
public static function complexValidationProvider(): iterable
{
    yield 'valid user data' => [
        'input' => [
            'username' => 'validuser',
            'email' => 'user@example.com',
            'age' => 25,
        ],
        'expected' => [
            'valid' => true,
            'errors' => [],
        ],
        'description' => 'All fields valid',
    ];
    
    yield 'invalid email' => [
        'input' => [
            'username' => 'validuser',
            'email' => 'invalid',
            'age' => 25,
        ],
        'expected' => [
            'valid' => false,
            'errors' => ['Invalid email format'],
        ],
        'description' => 'Email validation fails',
    ];
}
```

### 2.4 Assertion Type Inference

PHPStan cannot always infer types from PHPUnit assertions. Use explicit type narrowing:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function testGetUserReturnsCorrectType(): void
    {
        $result = $this->service->getUser(1);
        
        // ✅ AssertNotNull before accessing properties
        self::assertNotNull($result);
        
        // ✅ PHPStan now knows $result is not null
        self::assertIsString($result->getEmail());
        self::assertGreaterThan(0, $result->getId());
    }
    
    public function testArrayResult(): void
    {
        $result = $this->service->getAllUsers();
        
        // ✅ Explicit type assertion
        self::assertIsArray($result);
        self::assertArrayHasKey('users', $result);
        
        // ✅ Type narrowing for array access
        if (isset($result['users']) && is_array($result['users'])) {
            self::assertCount(3, $result['users']);
        }
    }
    
    public function testInstanceOfAssertion(): void
    {
        $result = $this->service->createUser('test');
        
        // ✅ instanceof narrows type for PHPStan
        self::assertInstanceOf(\App\Model\User::class, $result);
        
        // ✅ PHPStan knows $result is User instance
        self::assertEquals('test', $result->getUsername());
    }
}
```

---

## 3. Common Issues and Solutions

### 3.1 Unused Parameters in Test Methods

**Problem:** PHPUnit requires specific method signatures that may have unused parameters.

```php
// ❌ PHPStan error: Unused parameter $value
public function testDataProvider(string $value): void
{
    $this->service->process();
}
```

**Solutions:**

**Option 1: Use the parameter (preferred)**
```php
public function testDataProvider(string $value): void
{
    // ✅ Use the parameter meaningfully
    $this->service->setValue($value);
    $this->service->process();
}
```

**Option 2: Prefix with underscore (acceptable for test fixtures)**
```php
public function testWithUnusedFixture(string $_value): void
{
    // Test doesn't need the value
    $this->service->process();
}
```

**Option 3: Ignore in test-specific config**
```neon
# tests/phpstan.neon
ignoreErrors:
    - '#Method .*Test::.* has an unused parameter#'
```

### 3.2 Mixed Type Inference with Mocks

**Problem:** Mocks often return `mixed` types that PHPStan can't verify.

```php
// ❌ PHPStan error: Cannot call method on mixed
$config = $this->createMock(SiteConfigService::class);
$config->method('get')->willReturn('value');
$result = $config->get('key');
self::assertIsString($result); // PHPStan still sees mixed
```

**Solutions:**

**Option 1: Explicit type hint in callback**
```php
$config = $this->createMock(SiteConfigService::class);
$config
    ->method('get')
    ->willReturnCallback(function (string $key): string {
        return match ($key) {
            'site_name' => 'Ashchan',
            default => 'default',
        };
    });

// ✅ PHPStan knows return type is string
$result = $config->get('site_name');
```

**Option 2: Use @var annotation for narrowing**
```php
$config = $this->createMock(SiteConfigService::class);
$config->method('get')->willReturn('value');

/** @var string $result */
$result = $config->get('key');
self::assertIsString($result);
```

**Option 3: Create test doubles instead of mocks**
```php
// Create a simple test implementation
final class TestSiteConfigService implements SiteConfigService
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config = []) {}
    
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
    
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->config[$key] ?? $default;
        return (int) $value;
    }
}

// In test:
$config = new TestSiteConfigService(['key' => 'value']);
```

### 3.3 Final Class Mocking

**Problem:** PHPStan may flag issues when mocking final classes, even with DG\BypassFinals.

```php
// ❌ Without proper setup, this fails at runtime
$redis = $this->createMock(\Hyperf\Redis\Redis::class);
```

**Solutions:**

**Option 1: Ensure DG\BypassFinals is loaded early**
```php
// tests/bootstrap.php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ✅ Enable before any class loading
DG\BypassFinals::enable();
```

**Option 2: Use addMethods() for final classes**
```php
$redis = $this->getMockBuilder(\Hyperf\Redis\Redis::class)
    ->disableOriginalConstructor()
    ->onlyMethods(['get', 'set', 'del']) // ✅ Specify methods to mock
    ->getMock();
```

**Option 3: Create wrapper interfaces**
```php
// Define an interface for Redis operations
interface RedisClientInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): bool;
    public function del(string $key): int;
}

// Wrap Hyperf Redis
final class HyperfRedisWrapper implements RedisClientInterface
{
    public function __construct(private \Hyperf\Redis\Redis $redis) {}
    
    public function get(string $key): mixed { return $this->redis->get($key); }
    public function set(string $key, mixed $value): bool { return $this->redis->set($key, $value); }
    public function del(string $key): int { return $this->redis->del($key); }
}

// In tests, mock the interface
$redis = $this->createMock(RedisClientInterface::class);
```

### 3.4 Void Return Types

**Problem:** Test methods must return void, but PHPStan may flag issues with early returns or exceptions.

```php
// ❌ Potential issue with early return
public function testWithEarlyReturn(): void
{
    if (!$condition) {
        return; // PHPStan may flag this
    }
    // ...
}
```

**Solutions:**

**Option 1: Use assertions instead of early returns**
```php
public function testWithCondition(): void
{
    self::assertTrue($condition, 'Condition must be met');
    // Continue with test...
}
```

**Option 2: Structure test to avoid early returns**
```php
public function testNormalCase(): void
{
    // Test the normal path
}

public function testEdgeCase(): void
{
    // Test the edge case separately
}
```

**Option 3: Use assertThrows for exception testing**
```php
public function testThrowsException(): void
{
    // ✅ PHPUnit 10 style
    $this->expectException(\InvalidArgumentException::class);
    $this->service->invalidOperation();
}

// Or with PHPUnit 10 attributes
#[Test]
#[ExpectedException(\InvalidArgumentException::class)]
public function testThrowsException(): void
{
    $this->service->invalidOperation();
}
```

### 3.5 Property Access on Mixed

**Problem:** Hyperf models return `mixed` from `getAttribute()`.

```php
// ❌ PHPStan error: Access to property on mixed
$user = new User();
$name = $user->getAttribute('name');
self::assertIsString($name);
```

**Solutions:**

**Option 1: Explicit type assertion**
```php
$user = new User();
$name = $user->getAttribute('name');

// ✅ Assert type before using
self::assertIsString($name);
self::assertEquals('expected', $name);
```

**Option 2: Use @var annotation**
```php
$user = new User();

/** @var string $name */
$name = $user->getAttribute('name');
self::assertEquals('expected', $name);
```

**Option 3: Create typed accessor methods in model**
```php
// In your Model class
public function getName(): string
{
    /** @var string */
    return $this->getAttribute('name');
}

// In test - now type-safe
self::assertEquals('expected', $user->getName());
```

---

## 4. Best Practices

### 4.1 Maintain Strict Types While Keeping Tests Readable

**Use descriptive variable names:**
```php
// ❌ Unclear
$data = ['id' => 1, 'name' => 'test'];
$result = $service->process($data);

// ✅ Clear intent
$userData = ['id' => 1, 'name' => 'test'];
$processedUser = $service->process($userData);
```

**Group related assertions:**
```php
public function testCreatesUserCorrectly(): void
{
    $user = $this->service->createUser('test', 'test@example.com');
    
    // Identity assertions
    self::assertNotNull($user->getId());
    self::assertIsInt($user->getId());
    
    // Attribute assertions
    self::assertSame('test', $user->getUsername());
    self::assertSame('test@example.com', $user->getEmail());
    
    // State assertions
    self::assertTrue($user->isActive());
    self::assertNull($user->getDeletedAt());
}
```

**Use helper methods for complex setup:**
```php
private function createValidUser(array $overrides = []): User
{
    $defaults = [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'secure-password-123',
    ];
    
    return new User(array_merge($defaults, $overrides));
}

public function testUserValidation(): void
{
    $user = $this->createValidUser(['username' => 'newuser']);
    // ...
}
```

### 4.2 Document Test Intent

Use PHPDoc to explain WHY, not WHAT:

```php
/**
 * Tests that spam detection triggers when URL count exceeds threshold.
 *
 * The SpamService counts URLs in content and flags posts with more than
 * 5 URLs as potential spam. This test verifies the threshold is enforced
 * at exactly 6 URLs (boundary condition).
 */
public function testDetectsSpamWhenUrlCountExceedsThreshold(): void
{
    // Test implementation...
}
```

### 4.3 Use PHPUnit 10 Features

**Attributes over annotations:**
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\App\Service\UserService::class)]
#[Group('unit')]
final class UserServiceTest extends TestCase
{
    #[Test]
    #[DataProvider('userValidationProvider')]
    public function testValidateUser(array $data, bool $expectedValid): void
    {
        // ...
    }
}
```

**Type-safe test doubles:**
```php
use PHPUnit\Framework\MockObject\Generator;
use PHPUnit\Framework\MockObject\MockObject;

private function createConfigMock(array $values = []): SiteConfigService&MockObject
{
    $mock = $this->createMock(SiteConfigService::class);
    
    foreach ($values as $key => $value) {
        $mock->method('get')
            ->with($key)
            ->willReturn($value);
    }
    
    return $mock;
}
```

### 4.4 Avoid Error Suppression

```php
// ❌ Don't suppress errors
@$this->service->riskyOperation();

// ✅ Handle expected exceptions
$this->expectException(\RuntimeException::class);
$this->service->riskyOperation();

// ✅ Or use try-catch for complex scenarios
try {
    $this->service->riskyOperation();
    self::fail('Expected exception was not thrown');
} catch (\RuntimeException $e) {
    self::assertEquals('Expected message', $e->getMessage());
}
```

### 4.5 Clean Up Resources

```php
protected function setUp(): void
{
    parent::setUp();
    
    // Set up test environment
    $this->testDir = sys_get_temp_dir() . '/test-' . uniqid();
    mkdir($this->testDir, 0755, true);
}

protected function tearDown(): void
{
    // Clean up test files
    if (isset($this->testDir) && is_dir($this->testDir)) {
        $this->removeDirectory($this->testDir);
    }
    
    // Reset environment
    putenv('TEST_ENV_VAR');
    
    parent::tearDown();
}

private function removeDirectory(string $path): void
{
    $files = glob($path . '/*') ?: [];
    foreach ($files as $file) {
        is_dir($file) ? $this->removeDirectory($file) : unlink($file);
    }
    rmdir($path);
}
```

---

## 5. Bootstrap Considerations

### 5.1 DG\BypassFinals Configuration

**Current Setup (Your Bootstrap):**
```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

DG\BypassFinals::enable();
```

**PHPStan Implications:**

1. **PHPStan doesn't execute bootstrap by default for tests** - You need to configure it explicitly
2. **BypassFinals affects runtime, not static analysis** - PHPStan still sees classes as final

**Recommended PHPStan Bootstrap for Tests:**

```php
<?php
// tests/phpstan-test-bootstrap.php
declare(strict_types=1);

/**
 * PHPStan bootstrap for tests.
 *
 * This file is loaded by PHPStan before analysis, not during test execution.
 * DG\BypassFinals is NOT needed here - PHPStan analyzes code statically.
 */

// Load test helpers and stubs
require_once __DIR__ . '/../vendor/autoload.php';

// Register test-specific class aliases if needed
// class_alias(TestImplementation::class, ProductionInterface::class);

// Define constants that tests might use
if (!defined('TEST_ENV')) {
    define('TEST_ENV', true);
}
```

### 5.2 PHPStan Configuration with Bootstrap

```neon
# tests/phpstan.neon
parameters:
    level: 9
    paths:
        - tests
    
    # Bootstrap loaded by PHPStan (not PHPUnit)
    bootstrapFiles:
        - tests/phpstan-test-bootstrap.php
    
    # PHPUnit stub file for better type inference
    stubFiles:
        - tests/phpunit-stubs.stub
    
    ignoreErrors:
        # Mock-related errors are acceptable in tests
        - '#Call to an undefined method .* on Mockery.*#'
        - '#Access to an undefined property .* on Mockery.*#'
        
        # Unused parameters in test methods
        - '#Method .*Test::.* has an unused parameter#'
        
        # Mixed from fixtures
        - '#Parameter .* of method .* expects .* mixed given#'
```

### 5.3 PHPUnit Stubs for Better Type Inference

Create `tests/phpunit-stubs.stub` for complex types:

```php
// tests/phpunit-stubs.stub

/**
 * @template T of object
 */
interface MockObject extends \PHPUnit\Framework\MockObject\Stub
{
    /**
     * @param class-string<T> $originalClassName
     * @return T&static
     */
    public static function createMock(string $originalClassName): static;
}

/**
 * @template TValue
 */
interface DataProviderInterface
{
    /**
     * @return iterable<array-key, array<int, TValue>>
     */
    public function getData(): iterable;
}
```

### 5.4 Hyperf-Specific Considerations

For Hyperf framework tests, add framework stubs:

```php
// tests/hyperf-stubs.stub

namespace Hyperf\DbConnection {
    class Db
    {
        /**
         * @template T of \Hyperf\Database\Model\Model
         * @param class-string<T> $model
         * @return \Hyperf\Database\Model\Builder<T>
         */
        public static function table(string $model): \Hyperf\Database\Model\Builder {}
    }
}

namespace Hyperf\Redis {
    /**
     * @final Mocked in tests via DG\BypassFinals
     */
    class Redis
    {
        public function get(string $key): mixed {}
        public function set(string $key, mixed $value): bool {}
        public function del(string $key): int {}
    }
}
```

---

## 6. Reference Examples

### 6.1 Complete Test File Example

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SpamService;
use App\Service\SiteConfigService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SpamService.
 *
 * @covers \App\Service\SpamService
 */
#[CoversClass(SpamService::class)]
final class SpamServiceTest extends TestCase
{
    private SpamService $service;
    private SiteConfigService&MockObject $config;
    private RedisClientInterface&MockObject $redis;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->config = $this->createMock(SiteConfigService::class);
        $this->redis = $this->createMock(RedisClientInterface::class);
        
        // Configure default behavior
        $this->config
            ->method('getInt')
            ->willReturnCallback(function (string $key, int $default): int {
                return match ($key) {
                    'spam_url_limit' => 5,
                    'spam_rate_limit' => 10,
                    default => $default,
                };
            });
        
        $this->service = new SpamService($this->redis, $this->config);
    }
    
    #[Test]
    public function generateCaptchaReturnsTokenAndQuestion(): void
    {
        $result = $this->service->generateCaptcha();
        
        self::assertArrayHasKey('token', $result);
        self::assertArrayHasKey('question', $result);
        self::assertIsString($result['token']);
        self::assertIsString($result['question']);
        self::assertNotEmpty($result['token']);
        self::assertStringContainsString(' + ', $result['question']);
    }
    
    #[Test]
    #[DataProvider('captchaVerificationProvider')]
    public function verifyCaptchaWithVariousInputs(
        ?string $token,
        ?string $answer,
        ?string $storedAnswer,
        bool $expectedValid
    ): void {
        // Arrange
        if ($storedAnswer !== null && $token !== null) {
            $this->redis
                ->expects(self::once())
                ->method('get')
                ->with('captcha:' . $token)
                ->willReturn($storedAnswer);
            
            if ($expectedValid) {
                $this->redis
                    ->expects(self::once())
                    ->method('del')
                    ->with('captcha:' . $token);
            }
        }
        
        // Act
        $result = $this->service->verifyCaptcha($token ?? '', $answer ?? '');
        
        // Assert
        self::assertSame($expectedValid, $result);
    }
    
    /**
     * @return iterable<string, array{
     *     token: string|null,
     *     answer: string|null,
     *     storedAnswer: string|null,
     *     expectedValid: bool
     * }>
     */
    public static function captchaVerificationProvider(): iterable
    {
        yield 'valid captcha' => [
            'token' => 'abc123',
            'answer' => '42',
            'storedAnswer' => '42',
            'expectedValid' => true,
        ];
        
        yield 'wrong answer' => [
            'token' => 'abc123',
            'answer' => '99',
            'storedAnswer' => '42',
            'expectedValid' => false,
        ];
        
        yield 'expired token' => [
            'token' => 'expired',
            'answer' => '42',
            'storedAnswer' => null,
            'expectedValid' => false,
        ];
        
        yield 'empty token' => [
            'token' => null,
            'answer' => '42',
            'storedAnswer' => null,
            'expectedValid' => false,
        ];
    }
    
    #[Test]
    public function checkCleanContentPasses(): void
    {
        $result = $this->service->check(
            ipHash: 'test-ip-hash',
            content: 'This is a normal post.',
            isThread: false,
            imageHash: null
        );
        
        self::assertFalse($result['is_spam']);
        self::assertSame('OK', $result['message']);
        self::assertEquals(0.0, $result['score']);
    }
    
    #[Test]
    public function checkExcessiveUrlsFlagged(): void
    {
        $content = 'Check out http://a.com http://b.com http://c.com http://d.com http://e.com http://f.com';
        
        $result = $this->service->check('ip-hash', $content);
        
        self::assertArrayHasKey('is_spam', $result);
        self::assertArrayHasKey('score', $result);
        self::assertGreaterThan(0, $result['score']);
    }
}
```

### 6.2 Test Helper Class Example

```php
<?php
declare(strict_types=1);

namespace App\Tests\Support;

use App\Model\User;
use App\Service\PiiEncryptionService;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Test helpers for creating test data.
 *
 * These helpers ensure consistent test data creation across test files.
 */
final class TestDataFactory
{
    private const DEFAULT_PASSWORD = 'test-password-123';
    private const TEST_ENCRYPTION_KEY = '32-byte-test-encryption-key!!';
    
    /**
     * Create a valid user for testing.
     *
     * @param array<string, mixed> $overrides Attributes to override
     */
    public static function createUser(array $overrides = []): User
    {
        $defaults = [
            'username' => 'testuser_' . uniqid(),
            'email' => 'test_' . uniqid() . '@example.com',
            'password' => self::DEFAULT_PASSWORD,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        return new User(array_merge($defaults, $overrides));
    }
    
    /**
     * Create a PII encryption service for testing.
     */
    public static function createPiiEncryptionService(): PiiEncryptionService
    {
        putenv('PII_ENCRYPTION_KEY=' . self::TEST_ENCRYPTION_KEY);
        
        $logger = self::createMockLogger();
        $loggerFactory = new class($logger) extends LoggerFactory {
            public function __construct(private LoggerInterface $logger) {}
            
            public function get(string $group = 'app'): LoggerInterface
            {
                return $this->logger;
            }
        };
        
        return new PiiEncryptionService($loggerFactory);
    }
    
    /**
     * Create a mock logger that discards all messages.
     */
    public static function createMockLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            public function emergency(string|\Stringable $message, array $context = []): void {}
            public function alert(string|\Stringable $message, array $context = []): void {}
            public function critical(string|\Stringable $message, array $context = []): void {}
            public function error(string|\Stringable $message, array $context = []): void {}
            public function warning(string|\Stringable $message, array $context = []): void {}
            public function notice(string|\Stringable $message, array $context = []): void {}
            public function info(string|\Stringable $message, array $context = []): void {}
            public function debug(string|\Stringable $message, array $context = []): void {}
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };
    }
}
```

---

## Quick Reference Checklist

### Before Running PHPStan on Tests

- [ ] Separate `tests/phpstan.neon` created with relaxed rules
- [ ] Test bootstrap configured in PHPStan
- [ ] DG\BypassFinals enabled in PHPUnit bootstrap (not PHPStan)
- [ ] Stub files created for framework classes

### Test Code Quality

- [ ] All methods have return type hints (usually `void`)
- [ ] All properties have type hints
- [ ] Mock types use intersection types (`Class&MockObject`)
- [ ] Data providers have explicit `@return` types
- [ ] Assertions narrow types before property access

### Common Patterns

- [ ] Use `self::` for assertions (not `static::`)
- [ ] Use `#[Test]` attributes (not `@test` annotations)
- [ ] Use named parameters for clarity in assertions
- [ ] Group related assertions together
- [ ] Clean up resources in `tearDown()`

### PHPStan Compliance

- [ ] No `@phpstan-ignore-line` without justification
- [ ] All `mixed` types are narrowed before use
- [ ] No unused parameters (or prefixed with `_`)
- [ ] No suppressed errors without test-specific config

---

## Related Documentation

- [TYPE_HINTING_GUIDE.md](./TYPE_HINTING_GUIDE.md) - General type hinting standards
- [BEST_PRACTICES.md](./BEST_PRACTICES.md) - PHPStan 10 compliant coding patterns
- [TESTING_PLAN.md](./TESTING_PLAN.md) - Test coverage roadmap

---

**Version:** 1.0.0  
**Maintained By:** Documentation Team  
**Review Schedule:** Quarterly
