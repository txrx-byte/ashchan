<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Ashchan\EventBus;

/**
 * CloudEvents-compatible envelope value object.
 *
 * Matches the JSON Schema defined in contracts/events/*.json:
 *   {id, type, occurred_at, payload}
 */
final readonly class CloudEvent
{
    /**
     * @param string                $id         UUIDv4
     * @param string                $type       Event type (e.g. "post.created")
     * @param \DateTimeImmutable    $occurredAt When the event happened
     * @param array<string, mixed>  $payload    Event-specific data
     */
    public function __construct(
        public string $id,
        public string $type,
        public \DateTimeImmutable $occurredAt,
        public array $payload,
    ) {}

    /**
     * Serialize to JSON string for Redis stream storage.
     */
    public function toJson(): string
    {
        $json = json_encode([
            'id' => $this->id,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'payload' => $this->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $json;
    }

    /**
     * Deserialize from JSON string stored in Redis stream.
     *
     * @throws \JsonException
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException If occurred_at cannot be parsed as a valid datetime
     */
    public static function fromJson(string $json): self
    {
        /** @var array{id: string, type: string, occurred_at: string, payload: array<string, mixed>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['id'], $data['type'], $data['occurred_at'], $data['payload'])) {
            throw new \InvalidArgumentException('Invalid CloudEvent JSON: missing required fields');
        }

        $occurredAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $data['occurred_at']);
        if ($occurredAt === false) {
            // Fallback to ISO 8601
            try {
                $occurredAt = new \DateTimeImmutable($data['occurred_at']);
            } catch (\Exception $e) {
                throw new \UnexpectedValueException(
                    sprintf('Invalid CloudEvent occurred_at value: "%s"', $data['occurred_at']),
                    0,
                    $e,
                );
            }
        }

        return new self(
            id: $data['id'],
            type: $data['type'],
            occurredAt: $occurredAt,
            payload: $data['payload'],
        );
    }

    /**
     * Create a new CloudEvent with a generated UUID and current timestamp.
     *
     * @param string               $type    Event type constant from EventTypes
     * @param array<string, mixed> $payload Event-specific data
     */
    public static function create(string $type, array $payload): self
    {
        return new self(
            id: self::generateUuid4(),
            type: $type,
            occurredAt: new \DateTimeImmutable(),
            payload: $payload,
        );
    }

    /**
     * Generate a UUID v4 without external dependencies.
     */
    private static function generateUuid4(): string
    {
        $bytes = random_bytes(16);
        // Set version to 4 (0100)
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        // Set variant to RFC 4122 (10xx)
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }
}
