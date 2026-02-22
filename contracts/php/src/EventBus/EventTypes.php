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
 * Constants for all domain event type strings.
 *
 * These correspond to the CloudEvents `type` field and match
 * the JSON Schemas defined in contracts/events/*.json.
 */
final class EventTypes
{
    public const POST_CREATED = 'post.created';
    public const THREAD_CREATED = 'thread.created';
    public const MEDIA_INGESTED = 'media.ingested';
    public const MODERATION_DECISION = 'moderation.decision';

    /**
     * All known event types.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::POST_CREATED,
            self::THREAD_CREATED,
            self::MEDIA_INGESTED,
            self::MODERATION_DECISION,
        ];
    }
}
