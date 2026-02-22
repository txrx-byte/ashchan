<?php

declare(strict_types=1);

namespace Tests\Unit\Process;

use App\Process\EventConsumerProcess;
use App\Service\SearchService;
use Ashchan\EventBus\CloudEvent;
use Ashchan\EventBus\EventTypes;
use Hyperf\Redis\RedisFactory;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class EventConsumerProcessTest extends TestCase
{
    private EventConsumerProcess $process;
    private SearchService&MockInterface $mockSearchService;
    private LoggerInterface&MockInterface $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockSearchService = Mockery::mock(SearchService::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldReceive('info')->byDefault();
        $this->mockLogger->shouldReceive('debug')->byDefault();
        $this->mockLogger->shouldReceive('warning')->byDefault();
        $this->mockLogger->shouldReceive('error')->byDefault();

        $mockRedis = Mockery::mock(\Hyperf\Redis\RedisProxy::class);
        $mockRedisFactory = Mockery::mock(RedisFactory::class);
        $mockRedisFactory->shouldReceive('get')->with('events')->andReturn($mockRedis);

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(SearchService::class)->andReturn($this->mockSearchService);
        $container->shouldReceive('get')->with(RedisFactory::class)->andReturn($mockRedisFactory);
        $container->shouldReceive('get')->with(LoggerInterface::class)->andReturn($this->mockLogger);
        $container->shouldReceive('has')->andReturn(false);

        $this->process = new EventConsumerProcess($container);
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    // --- supports() tests ---

    public function testSupportsPostCreatedEvent(): void
    {
        $method = new \ReflectionMethod($this->process, 'supports');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->process, EventTypes::POST_CREATED));
    }

    public function testSupportsThreadCreatedEvent(): void
    {
        $method = new \ReflectionMethod($this->process, 'supports');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->process, EventTypes::THREAD_CREATED));
    }

    public function testSupportsModerationDecisionEvent(): void
    {
        $method = new \ReflectionMethod($this->process, 'supports');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->process, EventTypes::MODERATION_DECISION));
    }

    public function testDoesNotSupportMediaIngestedEvent(): void
    {
        $method = new \ReflectionMethod($this->process, 'supports');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->process, EventTypes::MEDIA_INGESTED));
    }

    public function testDoesNotSupportUnknownEvent(): void
    {
        $method = new \ReflectionMethod($this->process, 'supports');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->process, 'unknown.event'));
    }

    // --- processEvent() tests ---

    public function testProcessEventIndexesPostOnPostCreated(): void
    {
        $event = new CloudEvent(
            id: 'evt-001',
            type: EventTypes::POST_CREATED,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'board_id' => 'tech',
                'thread_id' => 1,
                'post_id' => 42,
                'content' => 'Hello world',
            ],
        );

        $this->mockSearchService->shouldReceive('indexPost')
            ->once()
            ->with('tech', 1, 42, 'Hello world');

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }

    public function testProcessEventSkipsPostWithMissingBoardId(): void
    {
        $event = new CloudEvent(
            id: 'evt-002',
            type: EventTypes::POST_CREATED,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'thread_id' => 1,
                'post_id' => 42,
                'content' => 'Hello world',
            ],
        );

        $this->mockSearchService->shouldNotReceive('indexPost');

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }

    public function testProcessEventSkipsPostWithZeroPostId(): void
    {
        $event = new CloudEvent(
            id: 'evt-003',
            type: EventTypes::POST_CREATED,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'board_id' => 'tech',
                'thread_id' => 1,
                'post_id' => 0,
                'content' => 'Hello world',
            ],
        );

        $this->mockSearchService->shouldNotReceive('indexPost');

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }

    public function testProcessEventHandlesThreadCreated(): void
    {
        $event = new CloudEvent(
            id: 'evt-004',
            type: EventTypes::THREAD_CREATED,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'thread_id' => 100,
                'board_id' => 'random',
            ],
        );

        // Thread created only logs, doesn't index
        $this->mockSearchService->shouldNotReceive('indexPost');

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }

    public function testProcessEventRemovesPostOnModerationBan(): void
    {
        $event = new CloudEvent(
            id: 'evt-005',
            type: EventTypes::MODERATION_DECISION,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'action' => 'ban',
                'target_type' => 'post',
                'target_id' => 42,
                'board_id' => 'tech',
            ],
        );

        $this->mockSearchService->shouldReceive('removePost')
            ->once()
            ->with('tech', 42);

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }

    public function testProcessEventRemovesPostOnModerationDelete(): void
    {
        $event = new CloudEvent(
            id: 'evt-006',
            type: EventTypes::MODERATION_DECISION,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'action' => 'delete',
                'target_type' => 'post',
                'target_id' => 99,
                'board_id' => 'random',
            ],
        );

        $this->mockSearchService->shouldReceive('removePost')
            ->once()
            ->with('random', 99);

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }

    public function testProcessEventIgnoresNonBanDeleteModerationActions(): void
    {
        $event = new CloudEvent(
            id: 'evt-007',
            type: EventTypes::MODERATION_DECISION,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'action' => 'warn',
                'target_type' => 'post',
                'target_id' => 42,
                'board_id' => 'tech',
            ],
        );

        $this->mockSearchService->shouldNotReceive('removePost');

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }

    public function testProcessEventIgnoresModerationForNonPostTargets(): void
    {
        $event = new CloudEvent(
            id: 'evt-008',
            type: EventTypes::MODERATION_DECISION,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'action' => 'ban',
                'target_type' => 'thread',
                'target_id' => 42,
                'board_id' => 'tech',
            ],
        );

        $this->mockSearchService->shouldNotReceive('removePost');

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }

    public function testProcessEventLogWarningWhenModerationMissingBoardId(): void
    {
        $event = new CloudEvent(
            id: 'evt-009',
            type: EventTypes::MODERATION_DECISION,
            occurredAt: new \DateTimeImmutable(),
            payload: [
                'action' => 'delete',
                'target_type' => 'post',
                'target_id' => 42,
                // Missing board_id
            ],
        );

        $this->mockSearchService->shouldNotReceive('removePost');

        $this->mockLogger->shouldReceive('warning')
            ->once()
            ->with(
                '[SearchConsumer] Cannot remove post from index: missing board_id in moderation event payload',
                Mockery::on(fn(array $ctx) => $ctx['post_id'] === 42 && $ctx['action'] === 'delete'),
            );

        $method = new \ReflectionMethod($this->process, 'processEvent');
        $method->setAccessible(true);
        $method->invoke($this->process, $event);
    }
}
