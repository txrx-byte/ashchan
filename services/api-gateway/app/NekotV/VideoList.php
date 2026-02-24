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

use Swoole\Table;

/**
 * Ordered playlist of VideoItem entries backed by Swoole Table.
 *
 * All state lives in cross-worker shared memory (NekotVTables::playlistTable()).
 * Items are stored as a JSON blob; pos, is_open, and length have dedicated
 * columns for cheap reads without deserializing the full blob.
 *
 * Each mutation reads the current JSON, modifies in-memory, then writes back.
 * At typical playlist sizes (≤ 50 items) the JSON round-trip is negligible.
 *
 * Port of meguca's websockets/feeds/nekotv/video_list.go VideoList.
 *
 * @see meguca/websockets/feeds/nekotv/video_list.go
 */
final class VideoList
{
    /** Swoole Table row key (string cast of threadId). */
    private readonly string $key;

    /**
     * Maximum playlist size (configurable via env).
     */
    private readonly int $maxSize;

    public function __construct(
        private readonly int $threadId,
    ) {
        $this->key = (string) $threadId;
        $this->maxSize = (int) (getenv('NEKOTV_MAX_PLAYLIST_SIZE') ?: 50);
    }

    // ─────────────────────────────────────────────────────────────
    //  Table helpers
    // ─────────────────────────────────────────────────────────────

    private function table(): Table
    {
        return NekotVTables::playlistTable();
    }

    /**
     * Load items from the Swoole Table.
     *
     * @return list<VideoItem>
     */
    private function loadItems(): array
    {
        $row = $this->table()->get($this->key);
        if ($row === false || $row['data'] === '') {
            return [];
        }

        $decoded = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $itemData) {
            if (is_array($itemData)) {
                $items[] = VideoItem::fromArray($itemData);
            }
        }
        return $items;
    }

    /**
     * Save items + metadata to the Swoole Table.
     *
     * @param list<VideoItem> $items
     */
    private function saveItems(array $items, int $pos, ?bool $isOpen = null): void
    {
        $isOpen ??= $this->isOpen();
        $this->table()->set($this->key, [
            'data'    => json_encode(
                array_map(fn(VideoItem $v) => $v->jsonSerialize(), $items),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            'pos'     => $pos,
            'is_open' => $isOpen ? 1 : 0,
            'length'  => count($items),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  Read accessors
    // ─────────────────────────────────────────────────────────────

    /**
     * Number of items in the playlist.
     */
    public function length(): int
    {
        $row = $this->table()->get($this->key);
        if ($row === false) {
            return 0;
        }
        return (int) $row['length'];
    }

    /**
     * Current playback position index.
     */
    public function getPos(): int
    {
        $row = $this->table()->get($this->key);
        if ($row === false) {
            return 0;
        }
        return (int) $row['pos'];
    }

    /**
     * Whether the playlist is open (unlocked for user modifications).
     */
    public function isOpen(): bool
    {
        $row = $this->table()->get($this->key);
        if ($row === false) {
            return true; // Default open
        }
        return (bool) $row['is_open'];
    }

    /**
     * Set playlist open/locked state.
     */
    public function setOpen(bool $isOpen): void
    {
        // Only update the is_open column (other columns untouched).
        $row = $this->table()->get($this->key);
        if ($row !== false) {
            $this->table()->set($this->key, ['is_open' => $isOpen ? 1 : 0]);
        }
    }

    /**
     * Get the currently playing video.
     *
     * @throws \RuntimeException if playlist is empty or position is invalid
     */
    public function currentItem(): VideoItem
    {
        $items = $this->loadItems();
        $pos = $this->getPos();

        if ($pos < 0 || $pos >= count($items)) {
            throw new \RuntimeException('Invalid playlist position');
        }
        return $items[$pos];
    }

    /**
     * Get a video at a specific index.
     *
     * @throws \OutOfRangeException if index is invalid
     */
    public function getItem(int $index): VideoItem
    {
        $items = $this->loadItems();
        if ($index < 0 || $index >= count($items)) {
            throw new \OutOfRangeException("Invalid playlist index: {$index}");
        }
        return $items[$index];
    }

    /**
     * Get all items as an array.
     *
     * @return list<VideoItem>
     */
    public function getItems(): array
    {
        return $this->loadItems();
    }

    // ─────────────────────────────────────────────────────────────
    //  Mutations (read → modify → write)
    // ─────────────────────────────────────────────────────────────

    /**
     * Replace all items (used for state restoration).
     *
     * @param list<VideoItem> $items
     */
    public function setItems(array $items): void
    {
        $this->saveItems(array_values($items), 0);
    }

    /**
     * Set the playback position.
     */
    public function setPos(int $pos): void
    {
        $length = $this->length();
        if ($pos < 0 || ($length > 0 && $pos >= $length)) {
            $pos = 0;
        }
        $this->table()->set($this->key, ['pos' => $pos]);
    }

    /**
     * Check if a video exists in the playlist by predicate.
     *
     * @param callable(VideoItem): bool $predicate
     */
    public function exists(callable $predicate): bool
    {
        foreach ($this->loadItems() as $item) {
            if ($predicate($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the index of a video matching the predicate.
     *
     * @param callable(VideoItem): bool $predicate
     * @return int Index or -1 if not found
     */
    public function findIndex(callable $predicate): int
    {
        foreach ($this->loadItems() as $i => $item) {
            if ($predicate($item)) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * Add a video to the playlist.
     *
     * @param bool $atEnd If true, append at end; otherwise insert after current position
     * @return bool False if playlist is full
     */
    public function addItem(VideoItem $item, bool $atEnd = true): bool
    {
        $items = $this->loadItems();
        $pos = $this->getPos();

        if (count($items) >= $this->maxSize) {
            return false;
        }

        if ($atEnd) {
            $items[] = $item;
        } else {
            $insertPos = $pos + 1;
            array_splice($items, $insertPos, 0, [$item]);
        }

        $this->saveItems($items, $pos);
        return true;
    }

    /**
     * Move a video to play next (after current position).
     *
     * @throws \OutOfRangeException if position is invalid
     */
    public function setNextItem(int $nextPos): void
    {
        $items = $this->loadItems();
        $pos = $this->getPos();

        if ($nextPos < 0 || $nextPos >= count($items)) {
            throw new \OutOfRangeException("Invalid position for setNext: {$nextPos}");
        }

        $item = $items[$nextPos];
        array_splice($items, $nextPos, 1);

        if ($nextPos < $pos) {
            $pos--;
        }

        $insertPos = $pos + 1;
        array_splice($items, $insertPos, 0, [$item]);

        $this->saveItems($items, $pos);
    }

    /**
     * Remove a video at a specific index.
     *
     * Adjusts the current position if the removed item is before or at
     * the current position.
     */
    public function removeItem(int $index): void
    {
        $items = $this->loadItems();
        $pos = $this->getPos();

        if ($index < 0 || $index >= count($items)) {
            return;
        }

        if ($index < $pos) {
            $pos--;
        }

        array_splice($items, $index, 1);

        if ($pos >= count($items) && count($items) > 0) {
            $pos = 0;
        }

        $this->saveItems($items, $pos);
    }

    /**
     * Skip the current video (remove it and advance).
     *
     * @return bool True if the playlist is now empty or wrapped around
     */
    public function skipItem(): bool
    {
        $items = $this->loadItems();
        $pos = $this->getPos();

        array_splice($items, $pos, 1);

        $wrapped = false;
        if ($pos >= count($items)) {
            $pos = 0;
            $wrapped = true;
        }

        $this->saveItems($items, $pos);
        return $wrapped;
    }

    /**
     * Clear all items from the playlist.
     */
    public function clear(): void
    {
        $this->saveItems([], 0);
    }

    /**
     * Shuffle the playlist (keeping the current item in place).
     */
    public function shuffle(): void
    {
        $items = $this->loadItems();
        $pos = $this->getPos();

        if (count($items) <= 1) {
            return;
        }

        $current = $items[$pos];
        array_splice($items, $pos, 1);
        shuffle($items);
        array_unshift($items, $current);

        $this->saveItems($items, 0);
    }

    // ─────────────────────────────────────────────────────────────
    //  Persistence (Redis serialization)
    // ─────────────────────────────────────────────────────────────

    /**
     * Serialize playlist state for persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items'   => array_map(fn(VideoItem $item) => $item->jsonSerialize(), $this->loadItems()),
            'pos'     => $this->getPos(),
            'is_open' => $this->isOpen(),
        ];
    }

    /**
     * Restore playlist state from persistence.
     *
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): void
    {
        $isOpen = (bool) ($data['is_open'] ?? true);
        $pos = (int) ($data['pos'] ?? 0);

        $items = [];
        $rawItems = $data['items'] ?? [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $itemData) {
                if (is_array($itemData)) {
                    $items[] = VideoItem::fromArray($itemData);
                }
            }
        }

        // Clamp position
        if ($pos >= count($items)) {
            $pos = 0;
        }

        $this->saveItems($items, $pos, $isOpen);
    }

    /**
     * Delete this playlist's state from the Swoole Table.
     */
    public function delete(): void
    {
        $this->table()->del($this->key);
    }
}
