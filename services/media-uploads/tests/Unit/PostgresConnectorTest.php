<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\PostgresConnector;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Unit tests for PostgresConnector.
 *
 * Covers: DSN building, charset validation, timezone validation,
 * search_path validation, application_name validation, and security
 * rejection of invalid inputs.
 */
final class PostgresConnectorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private PostgresConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new PostgresConnector();
    }

    // ── getDsn() ─────────────────────────────────────────────────────

    public function testGetDsnBuildsBasicDsn(): void
    {
        $method = new \ReflectionMethod($this->connector, 'getDsn');
        $method->setAccessible(true);

        $result = $method->invoke($this->connector, [
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'ashchan',
        ]);

        $this->assertSame('pgsql:host=localhost;port=5432;dbname=ashchan', $result);
    }

    public function testGetDsnIncludesSslmode(): void
    {
        $method = new \ReflectionMethod($this->connector, 'getDsn');
        $method->setAccessible(true);

        $result = $method->invoke($this->connector, [
            'host' => 'db.example.com',
            'port' => '5433',
            'database' => 'mydb',
            'sslmode' => 'require',
        ]);

        $this->assertSame('pgsql:host=db.example.com;port=5433;dbname=mydb;sslmode=require', $result);
    }

    public function testGetDsnOmitsSslmodeWhenNotSet(): void
    {
        $method = new \ReflectionMethod($this->connector, 'getDsn');
        $method->setAccessible(true);

        $result = $method->invoke($this->connector, [
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'test',
        ]);

        $this->assertStringNotContainsString('sslmode', $result);
    }

    // ── configureEncoding() ──────────────────────────────────────────

    public function testConfigureEncodingAcceptsValidCharset(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')
            ->with("SET NAMES 'utf8'")
            ->once();

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['charset' => 'utf8']);
    }

    public function testConfigureEncodingAcceptsUppercaseCharset(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')
            ->with("SET NAMES 'utf-8'")
            ->once();

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['charset' => 'UTF-8']);
    }

    public function testConfigureEncodingRejectsInvalidCharset(): void
    {
        $pdo = Mockery::mock(PDO::class);

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported charset');
        $method->invoke($this->connector, $pdo, ['charset' => 'evil; DROP TABLE users;--']);
    }

    public function testConfigureEncodingSkipsWhenNotSet(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, []);
    }

    public function testConfigureEncodingSkipsEmptyString(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['charset' => '']);
    }

    // ── configureTimezone() ──────────────────────────────────────────

    public function testConfigureTimezoneAcceptsValidTimezone(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('quote')
            ->with('UTC')
            ->once()
            ->andReturn("'UTC'");
        $pdo->shouldReceive('exec')
            ->with("SET timezone TO 'UTC'")
            ->once();

        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['timezone' => 'UTC']);
    }

    public function testConfigureTimezoneAcceptsRegionCity(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('quote')
            ->with('America/New_York')
            ->once()
            ->andReturn("'America/New_York'");
        $pdo->shouldReceive('exec')
            ->with("SET timezone TO 'America/New_York'")
            ->once();

        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['timezone' => 'America/New_York']);
    }

    public function testConfigureTimezoneRejectsInvalidTimezone(): void
    {
        $pdo = Mockery::mock(PDO::class);

        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone identifier');
        $method->invoke($this->connector, $pdo, ['timezone' => "UTC'; DROP TABLE users;--"]);
    }

    public function testConfigureTimezoneSkipsWhenNotSet(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, []);
    }

    // ── configureSearchPath() ────────────────────────────────────────

    public function testConfigureSearchPathAcceptsSingleSchema(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')
            ->with('SET search_path TO "public"')
            ->once();

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['schema' => 'public']);
    }

    public function testConfigureSearchPathAcceptsMultipleSchemas(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')
            ->with('SET search_path TO "public", "audit"')
            ->once();

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['schema' => ['public', 'audit']]);
    }

    public function testConfigureSearchPathRejectsInvalidSchemaName(): void
    {
        $pdo = Mockery::mock(PDO::class);

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid schema name');
        $method->invoke($this->connector, $pdo, ['schema' => "public; DROP TABLE users;--"]);
    }

    public function testConfigureSearchPathSkipsWhenNotSet(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, []);
    }

    public function testConfigureSearchPathSkipsEmptyArray(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['schema' => []]);
    }

    // ── configureApplicationName() ───────────────────────────────────

    public function testConfigureApplicationNameAcceptsValidName(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('quote')
            ->with('media-uploads')
            ->once()
            ->andReturn("'media-uploads'");
        $pdo->shouldReceive('exec')
            ->with("SET application_name TO 'media-uploads'")
            ->once();

        $method = new \ReflectionMethod($this->connector, 'configureApplicationName');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['application_name' => 'media-uploads']);
    }

    public function testConfigureApplicationNameRejectsInvalidName(): void
    {
        $pdo = Mockery::mock(PDO::class);

        $method = new \ReflectionMethod($this->connector, 'configureApplicationName');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid application_name');
        $method->invoke($this->connector, $pdo, ['application_name' => "app'; DROP TABLE users;--"]);
    }

    public function testConfigureApplicationNameSkipsWhenEmpty(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureApplicationName');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, ['application_name' => '']);
    }

    // ── formatSchema() ───────────────────────────────────────────────

    public function testFormatSchemaDoubleQuotesEach(): void
    {
        $method = new \ReflectionMethod($this->connector, 'formatSchema');
        $method->setAccessible(true);

        $result = $method->invoke($this->connector, ['public', 'my_schema']);
        $this->assertSame('"public", "my_schema"', $result);
    }

    public function testFormatSchemaEscapesDoubleQuotesInName(): void
    {
        $method = new \ReflectionMethod($this->connector, 'formatSchema');
        $method->setAccessible(true);

        $result = $method->invoke($this->connector, ['sche"ma']);
        $this->assertSame('"sche""ma"', $result);
    }
}
