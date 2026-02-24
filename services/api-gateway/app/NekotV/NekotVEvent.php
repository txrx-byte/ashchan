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
 * NekotV WebSocket event types.
 *
 * Events are JSON-encoded and sent as binary WebSocket frames with
 * the NekotV type byte (0x10) appended. The client strips the last byte
 * and deserializes the JSON payload.
 *
 * Port of meguca's pb/nekotv.proto WebSocketMessage oneof.
 *
 * @see meguca/pb/nekotv.proto
 */
final class NekotVEvent
{
    /** Client just connected — full state snapshot. */
    public const CONNECTED = 'connected';

    /** A video was added to the playlist. */
    public const ADD_VIDEO = 'addVideo';

    /** A video was removed from the playlist. */
    public const REMOVE_VIDEO = 'removeVideo';

    /** Current video was skipped. */
    public const SKIP_VIDEO = 'skip';

    /** Playback was paused. */
    public const PAUSE = 'pause';

    /** Playback was resumed. */
    public const PLAY = 'play';

    /** Periodic time synchronization (1/sec). */
    public const TIME_SYNC = 'timeSync';

    /** Seek to a specific time. */
    public const SET_TIME = 'setTime';

    /** Playback rate changed. */
    public const SET_RATE = 'setRate';

    /** Rewind to a time. */
    public const REWIND = 'rewind';

    /** Jump to specific playlist position. */
    public const PLAY_ITEM = 'playItem';

    /** Reorder playlist: move item to play next. */
    public const SET_NEXT_ITEM = 'setNextItem';

    /** Full playlist update. */
    public const UPDATE_PLAYLIST = 'updatePlaylist';

    /** Playlist lock/unlock toggled. */
    public const TOGGLE_LOCK = 'toggleLock';

    /** Playlist cleared. */
    public const CLEAR_PLAYLIST = 'clearPlaylist';

    /**
     * Build a connected event payload.
     *
     * @param list<VideoItem> $videoList
     * @param array{time: float, paused: bool, rate: float} $timeData
     * @return array<string, mixed>
     */
    public static function connected(array $videoList, int $itemPos, bool $isPlaylistOpen, array $timeData): array
    {
        return [
            'event'      => self::CONNECTED,
            'videoList'  => array_map(fn(VideoItem $v) => $v->jsonSerialize(), $videoList),
            'itemPos'    => $itemPos,
            'isOpen'     => $isPlaylistOpen,
            'time'       => $timeData,
        ];
    }

    /**
     * Build an add video event payload.
     *
     * @return array<string, mixed>
     */
    public static function addVideo(VideoItem $item, bool $atEnd): array
    {
        return [
            'event' => self::ADD_VIDEO,
            'item'  => $item->jsonSerialize(),
            'atEnd' => $atEnd,
        ];
    }

    /**
     * Build a remove video event payload.
     *
     * @return array<string, mixed>
     */
    public static function removeVideo(string $url): array
    {
        return [
            'event' => self::REMOVE_VIDEO,
            'url'   => $url,
        ];
    }

    /**
     * Build a skip video event payload.
     *
     * @return array<string, mixed>
     */
    public static function skipVideo(string $url): array
    {
        return [
            'event' => self::SKIP_VIDEO,
            'url'   => $url,
        ];
    }

    /**
     * Build a pause event payload.
     *
     * @return array<string, mixed>
     */
    public static function pause(float $time): array
    {
        return [
            'event' => self::PAUSE,
            'time'  => $time,
        ];
    }

    /**
     * Build a play event payload.
     *
     * @return array<string, mixed>
     */
    public static function play(float $time): array
    {
        return [
            'event' => self::PLAY,
            'time'  => $time,
        ];
    }

    /**
     * Build a time sync event payload.
     *
     * @return array<string, mixed>
     */
    public static function timeSync(float $time, bool $paused, float $rate): array
    {
        return [
            'event'  => self::TIME_SYNC,
            'time'   => $time,
            'paused' => $paused,
            'rate'   => $rate,
        ];
    }

    /**
     * Build a set time (seek) event payload.
     *
     * @return array<string, mixed>
     */
    public static function setTime(float $time): array
    {
        return [
            'event' => self::SET_TIME,
            'time'  => $time,
        ];
    }

    /**
     * Build a set rate event payload.
     *
     * @return array<string, mixed>
     */
    public static function setRate(float $rate): array
    {
        return [
            'event' => self::SET_RATE,
            'rate'  => $rate,
        ];
    }

    /**
     * Build a clear playlist event payload.
     *
     * @return array<string, mixed>
     */
    public static function clearPlaylist(): array
    {
        return [
            'event' => self::CLEAR_PLAYLIST,
        ];
    }

    /**
     * Build a toggle playlist lock event payload.
     *
     * @return array<string, mixed>
     */
    public static function toggleLock(bool $isOpen): array
    {
        return [
            'event'  => self::TOGGLE_LOCK,
            'isOpen' => $isOpen,
        ];
    }

    /**
     * Build an update playlist event payload.
     *
     * @param list<VideoItem> $items
     * @return array<string, mixed>
     */
    public static function updatePlaylist(array $items): array
    {
        return [
            'event'     => self::UPDATE_PLAYLIST,
            'videoList' => array_map(fn(VideoItem $v) => $v->jsonSerialize(), $items),
        ];
    }
}
