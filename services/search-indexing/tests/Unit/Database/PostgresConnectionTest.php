<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\PostgresConnection;
use Mockery;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PostgresConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    public function testBindValuesBindsIntegerAsParamInt(): void
    {
        $mockStatement = Mockery::mock(PDOStatement::class);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(1, 42, PDO::PARAM_INT);

        $connection = $this->createConnection();
        $connection->bindValues($mockStatement, [42]);
    }

    public function testBindValuesBindsBoolAsParamBool(): void
    {
        $mockStatement = Mockery::mock(PDOStatement::class);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(1, true, PDO::PARAM_BOOL);

        $connection = $this->createConnection();
        $connection->bindValues($mockStatement, [true]);
    }

    public function testBindValuesBindsNullAsParamNull(): void
    {
        $mockStatement = Mockery::mock(PDOStatement::class);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(1, null, PDO::PARAM_NULL);

        $connection = $this->createConnection();
        $connection->bindValues($mockStatement, [null]);
    }

    public function testBindValuesBindsStringAsParamStr(): void
    {
        $mockStatement = Mockery::mock(PDOStatement::class);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(1, 'hello', PDO::PARAM_STR);

        $connection = $this->createConnection();
        $connection->bindValues($mockStatement, ['hello']);
    }

    public function testBindValuesUsesStringKeyAsIs(): void
    {
        $mockStatement = Mockery::mock(PDOStatement::class);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(':name', 'value', PDO::PARAM_STR);

        $connection = $this->createConnection();
        $connection->bindValues($mockStatement, [':name' => 'value']);
    }

    public function testBindValuesUsesOneBasedIndexForNumericKeys(): void
    {
        $mockStatement = Mockery::mock(PDOStatement::class);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(1, 'first', PDO::PARAM_STR);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(2, 'second', PDO::PARAM_STR);

        $connection = $this->createConnection();
        $connection->bindValues($mockStatement, ['first', 'second']);
    }

    public function testBindValuesHandlesMixedTypes(): void
    {
        $mockStatement = Mockery::mock(PDOStatement::class);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(1, 'text', PDO::PARAM_STR);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(2, 42, PDO::PARAM_INT);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(3, true, PDO::PARAM_BOOL);
        $mockStatement->shouldReceive('bindValue')
            ->once()
            ->with(4, null, PDO::PARAM_NULL);

        $connection = $this->createConnection();
        $connection->bindValues($mockStatement, ['text', 42, true, null]);
    }

    private function createConnection(): PostgresConnection
    {
        $mockPdo = Mockery::mock(PDO::class);

        // PostgresConnection extends Connection which needs a PDO, database name, prefix, and config
        return new PostgresConnection($mockPdo, 'test_db', '', []);
    }
}
