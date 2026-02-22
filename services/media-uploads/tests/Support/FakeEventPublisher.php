<?php

declare(strict_types=1);

namespace Tests\Support;

use Ashchan\EventBus\CloudEvent;

/**
 * Test double for EventPublisher (which is final and cannot be mocked by Mockery).
 *
 * Records all published events for assertion in tests.
 */
final class FakeEventPublisher
{
    /** @var CloudEvent[] */
    public array $publishedEvents = [];

    public function publish(CloudEvent $event): string|false
    {
        $this->publishedEvents[] = $event;
        return 'fake-stream-id';
    }

    public function streamLength(): int
    {
        return count($this->publishedEvents);
    }

    /**
     * @return array<string, mixed>
     */
    public function streamInfo(): array
    {
        return ['length' => count($this->publishedEvents)];
    }

    /**
     * Assert that an event of the given type was published.
     */
    public function assertPublished(string $eventType): CloudEvent
    {
        foreach ($this->publishedEvents as $event) {
            if ($event->type === $eventType) {
                return $event;
            }
        }

        throw new \RuntimeException("No event of type '{$eventType}' was published. " .
            'Published types: ' . implode(', ', array_map(fn(CloudEvent $e) => $e->type, $this->publishedEvents)));
    }

    /**
     * Assert that no events were published.
     */
    public function assertNothingPublished(): void
    {
        if (count($this->publishedEvents) > 0) {
            throw new \RuntimeException('Expected no events, but ' . count($this->publishedEvents) . ' were published.');
        }
    }
}
