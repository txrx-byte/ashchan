<?php
declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Database\PostgresConnector;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Database\PostgresConnector
 *
 * Tests that exercise validation logic via reflection (private methods)
 * without requiring a real PostgreSQL connection.
 *
 * @requires extension pdo
 */
final class PostgresConnectorTest extends TestCase
{
    private PostgresConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new PostgresConnector();
    }

    /* ──────────────────────────────────────
     * getDsn() — basic DSN construction
     * ────────────────────────────────────── */

    private function callGetDsn(array $config): string
    {
        $method = new \ReflectionMethod($this->connector, 'getDsn');
        $method->setAccessible(true);
        return $method->invoke($this->connector, $config);
    }

    public function testGetDsnBasic(): void
    {
        $dsn = $this->callGetDsn([
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'ashchan',
        ]);
        $this->assertSame('pgsql:host=localhost;port=5432;dbname=ashchan', $dsn);
    }

    public function testGetDsnWithSslMode(): void
    {
        $dsn = $this->callGetDsn([
            'host' => 'db.example.com',
            'port' => '5432',
            'database' => 'mydb',
            'sslmode' => 'require',
        ]);
        $this->assertStringContainsString('sslmode=require', $dsn);
    }

    public function testGetDsnWithVerifyFullSslMode(): void
    {
        $dsn = $this->callGetDsn([
            'host' => 'db.example.com',
            'port' => '5432',
            'database' => 'mydb',
            'sslmode' => 'verify-full',
        ]);
        $this->assertStringContainsString('sslmode=verify-full', $dsn);
    }

    /**
     * @dataProvider validSslModesProvider
     */
    public function testGetDsnAcceptsAllValidSslModes(string $mode): void
    {
        $dsn = $this->callGetDsn([
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'db',
            'sslmode' => $mode,
        ]);
        $this->assertStringContainsString("sslmode={$mode}", $dsn);
    }

    /** @return array<string, array{string}> */
    public static function validSslModesProvider(): array
    {
        return [
            'disable' => ['disable'],
            'allow' => ['allow'],
            'prefer' => ['prefer'],
            'require' => ['require'],
            'verify-ca' => ['verify-ca'],
            'verify-full' => ['verify-full'],
        ];
    }

    public function testGetDsnRejectsInvalidSslMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sslmode');
        $this->callGetDsn([
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'db',
            'sslmode' => 'bogus',
        ]);
    }

    public function testGetDsnAcceptsIpv6Host(): void
    {
        $dsn = $this->callGetDsn([
            'host' => '[::1]',
            'port' => '5432',
            'database' => 'testdb',
        ]);
        $this->assertStringContainsString('host=[::1]', $dsn);
    }

    public function testGetDsnAcceptsHyphenatedDatabaseName(): void
    {
        $dsn = $this->callGetDsn([
            'host' => 'localhost',
            'port' => '5432',
            'database' => 'my-database',
        ]);
        $this->assertStringContainsString('dbname=my-database', $dsn);
    }

    /* ──────────────────────────────────────
     * getDsn() — validation rejections
     * ────────────────────────────────────── */

    public function testGetDsnRejectsEmptyHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database host');
        $this->callGetDsn(['host' => '', 'port' => '5432', 'database' => 'db']);
    }

    public function testGetDsnRejectsInvalidHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->callGetDsn(['host' => 'host;DROP TABLE', 'port' => '5432', 'database' => 'db']);
    }

    public function testGetDsnRejectsInvalidPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database port');
        $this->callGetDsn(['host' => 'localhost', 'port' => '99999', 'database' => 'db']);
    }

    public function testGetDsnRejectsNonNumericPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->callGetDsn(['host' => 'localhost', 'port' => 'abc', 'database' => 'db']);
    }

    public function testGetDsnRejectsPortZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->callGetDsn(['host' => 'localhost', 'port' => '0', 'database' => 'db']);
    }

    public function testGetDsnRejectsEmptyDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');
        $this->callGetDsn(['host' => 'localhost', 'port' => '5432', 'database' => '']);
    }

    public function testGetDsnRejectsDatabaseWithSpecialChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->callGetDsn(['host' => 'localhost', 'port' => '5432', 'database' => 'db;DROP']);
    }

    /* ──────────────────────────────────────
     * configureEncoding() — charset validation
     * ────────────────────────────────────── */

    private function callConfigureEncoding(PDO $pdo, array $config): void
    {
        $method = new \ReflectionMethod($this->connector, 'configureEncoding');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, $config);
    }

    public function testConfigureEncodingUtf8(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('exec')
            ->with("SET NAMES 'utf8'");
        $this->callConfigureEncoding($pdo, ['charset' => 'utf8']);
    }

    public function testConfigureEncodingSkipsWhenNotSet(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $this->callConfigureEncoding($pdo, []);
    }

    public function testConfigureEncodingSkipsEmptyCharset(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $this->callConfigureEncoding($pdo, ['charset' => '']);
    }

    public function testConfigureEncodingRejectsInvalidCharset(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported charset');
        $this->callConfigureEncoding($pdo, ['charset' => 'evil_charset']);
    }

    /**
     * @dataProvider allowedCharsetsProvider
     */
    public function testConfigureEncodingAcceptsAllowedCharsets(string $charset): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('exec');
        $this->callConfigureEncoding($pdo, ['charset' => $charset]);
    }

    /** @return array<string, array{string}> */
    public static function allowedCharsetsProvider(): array
    {
        return [
            'utf8' => ['utf8'],
            'utf-8' => ['utf-8'],
            'latin1' => ['latin1'],
            'latin2' => ['latin2'],
            'latin9' => ['latin9'],
            'sql_ascii' => ['sql_ascii'],
            'win1250' => ['win1250'],
            'win1251' => ['win1251'],
            'win1252' => ['win1252'],
        ];
    }

    public function testConfigureEncodingIsCaseInsensitive(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('exec')
            ->with("SET NAMES 'utf8'");
        $this->callConfigureEncoding($pdo, ['charset' => 'UTF8']);
    }

    /* ──────────────────────────────────────
     * configureTimezone()
     * ────────────────────────────────────── */

    private function callConfigureTimezone(PDO $pdo, array $config): void
    {
        $method = new \ReflectionMethod($this->connector, 'configureTimezone');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, $config);
    }

    public function testConfigureTimezoneWithUtc(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('quote')->willReturnCallback(fn ($v) => "'{$v}'");
        $pdo->expects($this->once())
            ->method('exec')
            ->with("SET timezone TO 'UTC'");
        $this->callConfigureTimezone($pdo, ['timezone' => 'UTC']);
    }

    public function testConfigureTimezoneSkipsIfNotSet(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $this->callConfigureTimezone($pdo, []);
    }

    public function testConfigureTimezoneRejectsInvalid(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->callConfigureTimezone($pdo, ['timezone' => "'; DROP TABLE users; --"]);
    }

    public function testConfigureTimezoneAcceptsRegionCity(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('quote')->willReturnCallback(fn ($v) => "'{$v}'");
        $pdo->expects($this->once())->method('exec');
        $this->callConfigureTimezone($pdo, ['timezone' => 'America/New_York']);
    }

    /* ──────────────────────────────────────
     * configureSearchPath()
     * ────────────────────────────────────── */

    private function callConfigureSearchPath(PDO $pdo, array $config): void
    {
        $method = new \ReflectionMethod($this->connector, 'configureSearchPath');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, $config);
    }

    public function testConfigureSearchPathSingleSchema(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('exec')
            ->with('SET search_path TO "public"');
        $this->callConfigureSearchPath($pdo, ['schema' => 'public']);
    }

    public function testConfigureSearchPathMultipleSchemas(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('exec')
            ->with('SET search_path TO "public", "extensions"');
        $this->callConfigureSearchPath($pdo, ['schema' => ['public', 'extensions']]);
    }

    public function testConfigureSearchPathSkipsIfNull(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $this->callConfigureSearchPath($pdo, []);
    }

    public function testConfigureSearchPathRejectsInvalidSchemaName(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid schema name');
        $this->callConfigureSearchPath($pdo, ['schema' => "'; DROP TABLE users; --"]);
    }

    /* ──────────────────────────────────────
     * configureApplicationName()
     * ────────────────────────────────────── */

    private function callConfigureApplicationName(PDO $pdo, array $config): void
    {
        $method = new \ReflectionMethod($this->connector, 'configureApplicationName');
        $method->setAccessible(true);
        $method->invoke($this->connector, $pdo, $config);
    }

    public function testConfigureApplicationName(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('quote')->willReturnCallback(fn ($v) => "'{$v}'");
        $pdo->expects($this->once())
            ->method('exec')
            ->with("SET application_name TO 'ashchan-auth'");
        $this->callConfigureApplicationName($pdo, ['application_name' => 'ashchan-auth']);
    }

    public function testConfigureApplicationNameSkipsIfNotSet(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $this->callConfigureApplicationName($pdo, []);
    }

    public function testConfigureApplicationNameRejectsInvalid(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->callConfigureApplicationName($pdo, ['application_name' => "'; DROP TABLE users; --"]);
    }

    /* ──────────────────────────────────────
     * formatSchema()
     * ────────────────────────────────────── */

    private function callFormatSchema(array $schemas): string
    {
        $method = new \ReflectionMethod($this->connector, 'formatSchema');
        $method->setAccessible(true);
        return $method->invoke($this->connector, $schemas);
    }

    public function testFormatSchemaSingle(): void
    {
        $this->assertSame('"public"', $this->callFormatSchema(['public']));
    }

    public function testFormatSchemaMultiple(): void
    {
        $this->assertSame('"public", "extensions"', $this->callFormatSchema(['public', 'extensions']));
    }

    public function testFormatSchemaEscapesQuotes(): void
    {
        $result = $this->callFormatSchema(['a"b']);
        $this->assertSame('"a""b"', $result);
    }
}
