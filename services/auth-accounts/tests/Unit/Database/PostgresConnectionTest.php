<?php
declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Database\PostgresConnection;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Database\PostgresConnection
 *
 * @requires extension pdo
 */
final class PostgresConnectionTest extends TestCase
{
    /**
     * Create a PostgresConnection instance without a real PDO connection.
     */
    private function makeConnection(): PostgresConnection
    {
        $pdo = $this->createMock(\PDO::class);
        // Use reflection to construct without calling the parent constructor's
        // full setup which needs a resolver / connection string
        $ref = new \ReflectionClass(PostgresConnection::class);
        $conn = $ref->newInstanceWithoutConstructor();

        return $conn;
    }

    /* ──────────────────────────────────────
     * bindValues() — PDO type mapping
     * ────────────────────────────────────── */

    public function testBindValuesIntegerUsesParamInt(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('bindValue')
            ->with(1, 42, PDO::PARAM_INT);

        $conn = $this->makeConnection();
        $conn->bindValues($stmt, [42]);
    }

    public function testBindValuesBooleanUsesParamBool(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('bindValue')
            ->with(1, true, PDO::PARAM_BOOL);

        $conn = $this->makeConnection();
        $conn->bindValues($stmt, [true]);
    }

    public function testBindValuesFalseBooleanUsesParamBool(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('bindValue')
            ->with(1, false, PDO::PARAM_BOOL);

        $conn = $this->makeConnection();
        $conn->bindValues($stmt, [false]);
    }

    public function testBindValuesNullUsesParamNull(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('bindValue')
            ->with(1, null, PDO::PARAM_NULL);

        $conn = $this->makeConnection();
        $conn->bindValues($stmt, [null]);
    }

    public function testBindValuesStringUsesParamStr(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('bindValue')
            ->with(1, 'hello', PDO::PARAM_STR);

        $conn = $this->makeConnection();
        $conn->bindValues($stmt, ['hello']);
    }

    public function testBindValuesFloatUsesParamStr(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('bindValue')
            ->with(1, 3.14, PDO::PARAM_STR);

        $conn = $this->makeConnection();
        $conn->bindValues($stmt, [3.14]);
    }

    public function testBindValuesNamedKeys(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $calls = [];
        $stmt->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnCallback(function ($key, $val, $type) use (&$calls): bool {
                $calls[] = [$key, $val, $type];
                return true;
            });

        $this->makeConnection()->bindValues($stmt, [':name' => 'test', ':id' => 10]);

        $this->assertSame(':name', $calls[0][0]);
        $this->assertSame(PDO::PARAM_STR, $calls[0][2]);
        $this->assertSame(':id', $calls[1][0]);
        $this->assertSame(PDO::PARAM_INT, $calls[1][2]);
    }

    public function testBindValuesNumericKeysAreOneBased(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $calls = [];
        $stmt->expects($this->exactly(3))
            ->method('bindValue')
            ->willReturnCallback(function ($key, $val, $type) use (&$calls): bool {
                $calls[] = $key;
                return true;
            });

        $this->makeConnection()->bindValues($stmt, ['a', 'b', 'c']);
        $this->assertSame([1, 2, 3], $calls);
    }

    public function testBindValuesMixedTypes(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $types = [];
        $stmt->expects($this->exactly(5))
            ->method('bindValue')
            ->willReturnCallback(function ($key, $val, $type) use (&$types): bool {
                $types[] = $type;
                return true;
            });

        $this->makeConnection()->bindValues($stmt, ['text', 42, true, null, 3.14]);

        $this->assertSame(PDO::PARAM_STR, $types[0]);
        $this->assertSame(PDO::PARAM_INT, $types[1]);
        $this->assertSame(PDO::PARAM_BOOL, $types[2]);
        $this->assertSame(PDO::PARAM_NULL, $types[3]);
        $this->assertSame(PDO::PARAM_STR, $types[4]);
    }

    public function testBindValuesEmptyArrayDoesNothing(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->never())->method('bindValue');
        $this->makeConnection()->bindValues($stmt, []);
    }

    /* ──────────────────────────────────────
     * isUniqueConstraintError()
     * ────────────────────────────────────── */

    private function callIsUniqueConstraintError(\Exception $e): bool
    {
        $conn = $this->makeConnection();
        $method = new \ReflectionMethod($conn, 'isUniqueConstraintError');
        $method->setAccessible(true);
        return $method->invoke($conn, $e);
    }

    public function testDetectsDuplicateKeyViolation(): void
    {
        $this->assertTrue($this->callIsUniqueConstraintError(
            new \Exception('ERROR: duplicate key value violates unique constraint "users_username_key"')
        ));
    }

    public function testDetectsUniqueViolation(): void
    {
        $this->assertTrue($this->callIsUniqueConstraintError(
            new \Exception('unique_violation')
        ));
    }

    public function testReturnsFalseForOtherErrors(): void
    {
        $this->assertFalse($this->callIsUniqueConstraintError(
            new \Exception('Connection refused')
        ));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertTrue($this->callIsUniqueConstraintError(
            new \Exception('DUPLICATE KEY value violates UNIQUE constraint')
        ));
    }
}
