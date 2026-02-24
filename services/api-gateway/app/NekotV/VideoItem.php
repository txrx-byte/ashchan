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

namespace App\NekotV;

/**
 * Immutable value object representing a video in the NekotV playlist.
 *
 * Port of meguca's pb/nekotv.proto VideoItem message. Uses plain PHP
 * instead of protobuf for consistency with ashchan's JSON-based wire format.
 *
 * @see meguca/pb/nekotv.proto
 */
final readonly class VideoItem implements \JsonSerializable
{
    /**
     * Sentinel value for live streams (infinite duration).
     * IEEE 754 infinity — matches meguca's common.Float32Infinite.
     */
    public const INFINITE_DURATION = INF;

    public function __construct(
        /** Video URL (canonical, used as unique key). */
        public string $url,

        /** Display title. */
        public string $title,

        /** Author/channel name. */
        public string $author,

        /** Duration in seconds (INF for live streams). */
        public float $duration,

        /** Platform-specific embed ID (e.g. YouTube embed URL, TikTok ID). */
        public string $id,

        /** Video source type. */
        public VideoType $type,
    ) {
    }

    /**
     * Whether this is a live stream (infinite duration).
     */
    public function isLive(): bool
    {
        return is_infinite($this->duration);
    }

    /**
     * Create a VideoItem from an associative array (e.g. from JSON decode).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) ($data['url'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            author: (string) ($data['author'] ?? ''),
            duration: (float) ($data['duration'] ?? 0.0),
            id: (string) ($data['id'] ?? ''),
            type: VideoType::from((int) ($data['type'] ?? 0)),
        );
    }

    /**
     * Serialize to JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'url'      => $this->url,
            'title'    => $this->title,
            'author'   => $this->author,
            'duration' => $this->isLive() ? -1.0 : $this->duration,
            'id'       => $this->id,
            'type'     => $this->type->value,
        ];
    }
}
