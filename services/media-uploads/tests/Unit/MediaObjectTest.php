<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Model\MediaObject;
use Hyperf\Context\ApplicationContext;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for MediaObject model.
 *
 * Covers: fillable fields, casts configuration, table name.
 */
final class MediaObjectTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MediaObject $model;

    protected function setUp(): void
    {
        parent::setUp();

        // Hyperf models need a container with an EventDispatcher
        $container = Mockery::mock(ContainerInterface::class);
        $dispatcher = Mockery::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')->andReturnNull();
        $container->shouldReceive('has')
            ->with(EventDispatcherInterface::class)
            ->andReturn(true);
        $container->shouldReceive('get')
            ->with(EventDispatcherInterface::class)
            ->andReturn($dispatcher);

        ApplicationContext::setContainer($container);

        $this->model = new MediaObject();
    }

    public function testTableNameIsCorrect(): void
    {
        $this->assertSame('media_objects', $this->model->getTable());
    }

    public function testTimestampsAreDisabled(): void
    {
        $this->assertFalse($this->model->timestamps);
    }

    public function testFillableContainsExpectedFields(): void
    {
        $expected = [
            'hash_sha256', 'mime_type', 'file_size', 'width', 'height',
            'storage_key', 'thumb_key', 'original_filename', 'phash',
            'nsfw_flagged', 'banned',
        ];

        $fillable = $this->model->getFillable();
        foreach ($expected as $field) {
            $this->assertContains($field, $fillable, "Missing fillable field: {$field}");
        }
    }

    public function testCastsContainsExpectedTypes(): void
    {
        $casts = $this->model->getCasts();

        $this->assertSame('integer', $casts['id']);
        $this->assertSame('integer', $casts['file_size']);
        $this->assertSame('integer', $casts['width']);
        $this->assertSame('integer', $casts['height']);
        $this->assertSame('boolean', $casts['nsfw_flagged']);
        $this->assertSame('boolean', $casts['banned']);
    }

    public function testIdFieldIsNotFillable(): void
    {
        $this->assertNotContains('id', $this->model->getFillable());
    }

    public function testCreatedAtFieldIsNotFillable(): void
    {
        $this->assertNotContains('created_at', $this->model->getFillable());
    }
}
