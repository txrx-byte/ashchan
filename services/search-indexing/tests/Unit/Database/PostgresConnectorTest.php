<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\PostgresConnector;
use Mockery;
use Mockery\MockInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgresConnectorTest extends TestCase
{
    private PostgresConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new PostgresConnector();
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    // --- getDsn() tests ---

    public function testGetDsnBuildsBasicConnectionString(): void
    {
        $method = new \ReflectionMethod($this->connector, 'getDsn');
        $method->setAccessible(true);

        $dsn = $method->invoke($this->connector, [
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'ashchan',
        ]);

        $this->assertSame('pgsql:host=localhost;port=5432;dbname=ashchan', $dsn);
    }

    public function testGetDsnIncludesSslMode(): void
    {
        $method = new \ReflectionMethod($this->connector, 'getDsn');
        $method->setAccessible(true);

        $dsn = $method->invoke($this->connector, [
            'host' => 'db.example.com',
            'port' => '5432',
            'database' => 'ashchan',
            'sslmode' => 'require',
        ]);

        $this->assertSame('pgsql:host=db.example.com;port=5432;dbname=ashchan;sslmode=require', $dsn);
    }

    public function testGetDsnOmitsSslModeWhenNotSet(): void
    {
        $method = new \ReflectionMethod($this->connector, 'getDsn');
        $method->setAccessible(true);

        $dsn = $method->invoke($this->connector, [
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'testdb',
        ]);

        $this->assertStringNotContainsString('sslmode', $dsn);
    }

    // --- configureEncoding() tests ---

    public function testConfigureEncodingAcceptsValidCharset(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('exec')
            ->once()
            ->with("SET NAMES 'utf8'")
            ->andReturn(0);

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, ['charset' => 'utf8']);
    }

    public function testConfigureEncodingRejectsInvalidCharset(): void
    {
        $mockPdo = Mockery::mock(PDO::class);

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported charset');

        $method->invoke($this->connector, $mockPdo, ['charset' => 'malicious; DROP TABLE users']);
    }

    public function testConfigureEncodingSkipsWhenNoCharset(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, []);
    }

    public function testConfigureEncodingNormalizesToLowercase(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('exec')
            ->once()
            ->with("SET NAMES 'utf8'")
            ->andReturn(0);

        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, ['charset' => 'UTF8']);
    }

    // --- configureTimezone() tests ---

    public function testConfigureTimezoneAcceptsValidTimezone(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('quote')
            ->with('UTC')
            ->andReturn("'UTC'");
        $mockPdo->shouldReceive('exec')
            ->once()
            ->with("SET timezone TO 'UTC'")
            ->andReturn(0);

        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, ['timezone' => 'UTC']);
    }

    public function testConfigureTimezoneAcceptsRegionFormat(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('quote')
            ->with('America/New_York')
            ->andReturn("'America/New_York'");
        $mockPdo->shouldReceive('exec')
            ->once()
            ->with("SET timezone TO 'America/New_York'")
            ->andReturn(0);

        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, ['timezone' => 'America/New_York']);
    }

    public function testConfigureTimezoneRejectsInvalidTimezone(): void
    {
        $mockPdo = Mockery::mock(PDO::class);

        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone');

        $method->invoke($this->connector, $mockPdo, ['timezone' => "'; DROP TABLE users; --"]);
    }

    public function testConfigureTimezoneSkipsWhenNotSet(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, []);
    }

    // --- configureSearchPath() tests ---

    public function testConfigureSearchPathAcceptsValidSchema(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('exec')
            ->once()
            ->with('SET search_path TO "public"')
            ->andReturn(0);

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, ['schema' => 'public']);
    }

    public function testConfigureSearchPathAcceptsArrayOfSchemas(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('exec')
            ->once()
            ->with('SET search_path TO "public", "app"')
            ->andReturn(0);

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, ['schema' => ['public', 'app']]);
    }

    public function testConfigureSearchPathRejectsInvalidSchema(): void
    {
        $mockPdo = Mockery::mock(PDO::class);

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid schema name');

        $method->invoke($this->connector, $mockPdo, ['schema' => '; DROP TABLE users']);
    }

    public function testConfigureSearchPathSkipsWhenNotSet(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, []);
    }

    // --- configureApplicationName() tests ---

    public function testConfigureApplicationNameAcceptsValidName(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('quote')
            ->with('search-indexing')
            ->andReturn("'search-indexing'");
        $mockPdo->shouldReceive('exec')
            ->once()
            ->with("SET application_name TO 'search-indexing'")
            ->andReturn(0);

        $method = new \ReflectionMethod($this->connector, 'configureApplicationName');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, ['application_name' => 'search-indexing']);
    }

    public function testConfigureApplicationNameRejectsInvalidName(): void
    {
        $mockPdo = Mockery::mock(PDO::class);

        $method = new \ReflectionMethod($this->connector, 'configureApplicationName');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid application_name');

        $method->invoke($this->connector, $mockPdo, ['application_name' => "'; DROP TABLE users; --"]);
    }

    public function testConfigureApplicationNameSkipsWhenNotSet(): void
    {
        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldNotReceive('exec');

        $method = new \ReflectionMethod($this->connector, 'configureApplicationName');
        $method->setAccessible(true);
        $method->invoke($this->connector, $mockPdo, []);
    }
}
