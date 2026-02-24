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
 * Contract for publishing CloudEvents to a message stream.
 */
interface EventPublisherInterface
{
    /**
     * Publish a CloudEvent to the stream.
     *
     * @return string|false The stream entry ID on success, false on failure
     */
    public function publish(CloudEvent $event): string|false;

    /**
     * Get current stream length.
     */
    public function streamLength(): int;

    /**
     * Get stream info for health checks.
     *
     * @return array<string, mixed>
     */
    public function streamInfo(): array;
}
