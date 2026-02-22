<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\PostgresConnection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PDOStatement;

/**
 * Unit tests for PostgresConnection.
 *
 * Covers: bindValues() type mapping and isUniqueConstraintError() pattern matching.
 */
final class PostgresConnectionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    // ── bindValues() ─────────────────────────────────────────────────

    public function testBindValuesBindsIntegerAsPdoInt(): void
    {
        $conn = $this->createConnection();
        $stmt = Mockery::mock(PDOStatement::class);

        $stmt->shouldReceive('bindValue')
            ->with(1, 42, \PDO::PARAM_INT)
            ->once();

        $conn->bindValues($stmt, [42]);
    }

    public function testBindValuesBindsBooleanAsPdoBool(): void
    {
        $conn = $this->createConnection();
        $stmt = Mockery::mock(PDOStatement::class);

        $stmt->shouldReceive('bindValue')
            ->with(1, true, \PDO::PARAM_BOOL)
            ->once();

        $conn->bindValues($stmt, [true]);
    }

    public function testBindValuesBindsNullAsPdoNull(): void
    {
        $conn = $this->createConnection();
        $stmt = Mockery::mock(PDOStatement::class);

        $stmt->shouldReceive('bindValue')
            ->with(1, null, \PDO::PARAM_NULL)
            ->once();

        $conn->bindValues($stmt, [null]);
    }

    public function testBindValuesBindsStringAsPdoStr(): void
    {
        $conn = $this->createConnection();
        $stmt = Mockery::mock(PDOStatement::class);

        $stmt->shouldReceive('bindValue')
            ->with(1, 'hello', \PDO::PARAM_STR)
            ->once();

        $conn->bindValues($stmt, ['hello']);
    }

    public function testBindValuesHandlesNamedParameters(): void
    {
        $conn = $this->createConnection();
        $stmt = Mockery::mock(PDOStatement::class);

        $stmt->shouldReceive('bindValue')
            ->with('name', 'John', \PDO::PARAM_STR)
            ->once();
        $stmt->shouldReceive('bindValue')
            ->with('age', 30, \PDO::PARAM_INT)
            ->once();

        $conn->bindValues($stmt, ['name' => 'John', 'age' => 30]);
    }

    public function testBindValuesHandsMixedTypes(): void
    {
        $conn = $this->createConnection();
        $stmt = Mockery::mock(PDOStatement::class);

        $stmt->shouldReceive('bindValue')
            ->with(1, 'text', \PDO::PARAM_STR)
            ->once();
        $stmt->shouldReceive('bindValue')
            ->with(2, 42, \PDO::PARAM_INT)
            ->once();
        $stmt->shouldReceive('bindValue')
            ->with(3, null, \PDO::PARAM_NULL)
            ->once();
        $stmt->shouldReceive('bindValue')
            ->with(4, false, \PDO::PARAM_BOOL)
            ->once();

        $conn->bindValues($stmt, ['text', 42, null, false]);
    }

    // ── isUniqueConstraintError() ────────────────────────────────────

    /**
     * @dataProvider uniqueConstraintMessages
     */
    public function testIsUniqueConstraintErrorDetectsViolations(string $message, bool $expected): void
    {
        $conn = $this->createConnection();
        $method = new \ReflectionMethod($conn, 'isUniqueConstraintError');
        $method->setAccessible(true);

        $exception = new \Exception($message);
        $this->assertSame($expected, $method->invoke($conn, $exception));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function uniqueConstraintMessages(): array
    {
        return [
            'postgres unique violation' => [
                'ERROR: duplicate key value violates unique constraint "media_objects_hash_sha256_key"',
                true,
            ],
            'duplicate key' => [
                'duplicate key value violates unique constraint',
                true,
            ],
            'unique violation' => [
                'UNIQUE constraint violation on column hash_sha256',
                true,
            ],
            'unrelated error' => [
                'Connection refused',
                false,
            ],
            'syntax error' => [
                'ERROR: syntax error at or near "SELECT"',
                false,
            ],
            'empty message' => [
                '',
                false,
            ],
        ];
    }

    // ── Helper ───────────────────────────────────────────────────────

    /**
     * Create a PostgresConnection using a mock PDO (bypassing real DB).
     */
    private function createConnection(): PostgresConnection
    {
        // PostgresConnection extends Connection which needs a PDO callable,
        // but we only test methods that don't require an active DB connection.
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('getAttribute')->andReturn('');

        return new PostgresConnection(
            static fn() => $pdo,
            '',
        );
    }
}
