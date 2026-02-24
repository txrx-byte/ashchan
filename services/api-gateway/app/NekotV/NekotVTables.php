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
 * Cross-worker shared-memory Swoole Tables for NekotV state.
 *
 * Created once before workers fork (in NekotVTableListener) so all Swoole
 * workers read/write the same physical memory. Timer state uses fixed FLOAT
 * columns for lock-free atomic reads. Playlist state uses a JSON-serialized
 * STRING column (64 KB max, supports ~50 items comfortably).
 *
 * @see App\Listener\NekotVTableListener
 */
final class NekotVTables
{
    /** Maximum concurrent thread timers (Swoole Table row count). */
    private const TABLE_SIZE = 1024;

    /** Maximum serialized playlist size in bytes (64 KB). */
    private const PLAYLIST_DATA_SIZE = 65536;

    private static ?Table $timerTable = null;
    private static ?Table $playlistTable = null;
    private static bool $initialized = false;

    /**
     * Initialize shared tables. Must be called exactly once, before $server->start().
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // ── Timer table ──────────────────────────────────────────
        // One row per thread. Each field is a fixed-size FLOAT or INT
        // for lock-free atomic reads/writes via Swoole Table.
        self::$timerTable = new Table(self::TABLE_SIZE);
        self::$timerTable->column('start_time', Table::TYPE_FLOAT, 8);
        self::$timerTable->column('pause_start', Table::TYPE_FLOAT, 8);
        self::$timerTable->column('rate_start', Table::TYPE_FLOAT, 8);
        self::$timerTable->column('rate', Table::TYPE_FLOAT, 8);
        self::$timerTable->column('is_started', Table::TYPE_INT, 1);
        self::$timerTable->column('rebake_at', Table::TYPE_FLOAT, 8);
        self::$timerTable->create();

        // ── Playlist table ───────────────────────────────────────
        // One row per thread. Items stored as JSON blob in `data`.
        // `pos`, `is_open`, `length` are separate columns for cheap reads
        // without deserializing the full blob on every sync tick.
        self::$playlistTable = new Table(self::TABLE_SIZE);
        self::$playlistTable->column('data', Table::TYPE_STRING, self::PLAYLIST_DATA_SIZE);
        self::$playlistTable->column('pos', Table::TYPE_INT, 4);
        self::$playlistTable->column('is_open', Table::TYPE_INT, 1);
        self::$playlistTable->column('length', Table::TYPE_INT, 4);
        self::$playlistTable->create();

        self::$initialized = true;
    }

    /**
     * Get the shared timer table.
     *
     * @throws \RuntimeException if tables are not initialized
     */
    public static function timerTable(): Table
    {
        return self::$timerTable ?? throw new \RuntimeException(
            'NekotV tables not initialized — call NekotVTables::init() before server start',
        );
    }

    /**
     * Get the shared playlist table.
     *
     * @throws \RuntimeException if tables are not initialized
     */
    public static function playlistTable(): Table
    {
        return self::$playlistTable ?? throw new \RuntimeException(
            'NekotV tables not initialized — call NekotVTables::init() before server start',
        );
    }

    /**
     * Whether tables have been initialized.
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Delete all state for a thread (timer + playlist).
     */
    public static function deleteThread(int $threadId): void
    {
        $key = (string) $threadId;
        self::$timerTable?->del($key);
        self::$playlistTable?->del($key);
    }
}
