<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\DeletionRequest;
use App\Tests\TestBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\DeletionRequest
 */
final class DeletionRequestTest extends TestCase
{
    protected function setUp(): void
    {
        TestBootstrap::init();
    }

    public function testTableName(): void
    {
        $this->assertSame('deletion_requests', (new DeletionRequest())->getTable());
    }

    public function testTimestampsDisabled(): void
    {
        $this->assertFalse((new DeletionRequest())->timestamps);
    }

    public function testFillableColumns(): void
    {
        $fillable = (new DeletionRequest())->getFillable();
        foreach (['user_id', 'status', 'request_type', 'requested_at'] as $col) {
            $this->assertContains($col, $fillable);
        }
    }

    public function testCastsConfiguration(): void
    {
        $casts = (new DeletionRequest())->getCasts();
        $this->assertSame('integer', $casts['id']);
        $this->assertSame('integer', $casts['user_id']);
    }

    public function testDataExportType(): void
    {
        $req = new DeletionRequest();
        $req->setAttribute('request_type', 'data_export');
        $req->setAttribute('status', 'pending');
        $req->setAttribute('user_id', 42);

        $this->assertSame('data_export', $req->getAttribute('request_type'));
        $this->assertSame('pending', $req->getAttribute('status'));
        $this->assertSame(42, $req->getAttribute('user_id'));
    }

    public function testDataDeletionType(): void
    {
        $req = new DeletionRequest();
        $req->setAttribute('request_type', 'data_deletion');
        $this->assertSame('data_deletion', $req->getAttribute('request_type'));
    }

    public function testStatusTransitions(): void
    {
        $req = new DeletionRequest();
        foreach (['pending', 'processing', 'completed', 'denied'] as $status) {
            $req->setAttribute('status', $status);
            $this->assertSame($status, $req->getAttribute('status'));
        }
    }

    public function testIdNotFillable(): void
    {
        $this->assertNotContains('id', (new DeletionRequest())->getFillable());
    }

    public function testCompletedAtNotFillable(): void
    {
        $this->assertNotContains('completed_at', (new DeletionRequest())->getFillable());
    }
}
