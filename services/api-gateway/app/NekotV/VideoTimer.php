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
 * Server-authoritative video playback timer backed by Swoole Table.
 *
 * All state lives in cross-worker shared memory (NekotVTables::timerTable())
 * so every Swoole worker reads the same canonical time. Reads are lock-free;
 * mutations perform atomic row-level set() calls.
 *
 * Port of meguca's websockets/feeds/nekotv/timer.go VideoTimer.
 *
 * Time calculation:
 *   elapsed = timeSince(startTime) - rateTime + rateTime*rate - pauseTime
 *
 * Where:
 *   rateTime = timeSince(rateStartTime) - pauseTime
 *   pauseTime = timeSince(pauseStartTime)  (if currently paused)
 *
 * Float drift protection: call reBake() periodically (e.g. hourly) to
 * collapse accumulated arithmetic back into startTime, resetting offsets.
 *
 * @see meguca/websockets/feeds/nekotv/timer.go
 */
final class VideoTimer
{
    /** Maximum allowed playback rate. */
    private const MAX_RATE = 16.0;

    /** Minimum allowed playback rate. */
    private const MIN_RATE = 0.0625;

    /** Re-bake interval in seconds (1 hour). */
    public const REBAKE_INTERVAL = 3600.0;

    /** Swoole Table row key (string cast of threadId). */
    private readonly string $key;

    public function __construct(
        private readonly int $threadId,
    ) {
        $this->key = (string) $threadId;
    }

    // ─────────────────────────────────────────────────────────────
    //  Table accessors
    // ─────────────────────────────────────────────────────────────

    private function table(): Table
    {
        return NekotVTables::timerTable();
    }

    /**
     * Read the full row from Swoole Table.
     *
     * @return array{start_time: float, pause_start: float, rate_start: float, rate: float, is_started: int, rebake_at: float}
     */
    private function read(): array
    {
        $row = $this->table()->get($this->key);
        if ($row === false) {
            return [
                'start_time'  => 0.0,
                'pause_start' => 0.0,
                'rate_start'  => 0.0,
                'rate'        => 1.0,
                'is_started'  => 0,
                'rebake_at'   => 0.0,
            ];
        }
        return $row;
    }

    /**
     * Write columns to the Swoole Table row.
     *
     * @param array<string, float|int> $columns
     */
    private function write(array $columns): void
    {
        $this->table()->set($this->key, $columns);
    }

    // ─────────────────────────────────────────────────────────────
    //  Timer operations
    // ─────────────────────────────────────────────────────────────

    /**
     * Start (or restart) the timer from the beginning.
     */
    public function start(): void
    {
        $now = microtime(true);
        $this->write([
            'is_started'  => 1,
            'start_time'  => $now,
            'pause_start' => 0.0,
            'rate_start'  => $now,
            'rate'        => $this->read()['rate'] ?: 1.0,
            'rebake_at'   => $now + self::REBAKE_INTERVAL,
        ]);
    }

    /**
     * Stop the timer completely (reset all state).
     */
    public function stop(): void
    {
        $this->write([
            'is_started'  => 0,
            'start_time'  => 0.0,
            'pause_start' => 0.0,
            'rate_start'  => 0.0,
            'rebake_at'   => 0.0,
        ]);
    }

    /**
     * Pause playback.
     *
     * Adjusts the start time to account for the current rate, then
     * records the pause start time.
     */
    public function pause(): void
    {
        $row = $this->read();
        $rateTime = $this->computeRateTime($row);
        $rate = $row['rate'] ?: 1.0;

        $this->write([
            'start_time'  => $row['start_time'] + $rateTime - $rateTime / $rate,
            'pause_start' => microtime(true),
            'rate_start'  => 0.0,
        ]);
    }

    /**
     * Resume playback after a pause.
     *
     * If not started, starts from the beginning. Otherwise adjusts
     * the start time to account for the pause duration.
     */
    public function play(): void
    {
        $row = $this->read();

        if ($row['is_started'] === 0) {
            $this->start();
            return;
        }

        $pauseTime = $this->computePauseTime($row);

        $this->write([
            'start_time'  => $row['start_time'] + $pauseTime,
            'pause_start' => 0.0,
            'rate_start'  => microtime(true),
        ]);
    }

    /**
     * Get the current playback time in seconds.
     *
     * Pure read — no mutations, any worker can call at any time.
     */
    public function getTime(): float
    {
        $row = $this->read();

        if ($row['start_time'] === 0.0) {
            return 0.0;
        }

        $now = microtime(true);
        $elapsed = $now - $row['start_time'];
        $rateTime = $this->computeRateTime($row, $now);
        $pauseTime = $this->computePauseTime($row, $now);
        $rate = $row['rate'] ?: 1.0;

        return $elapsed - $rateTime + $rateTime * $rate - $pauseTime;
    }

    /**
     * Seek to a specific time in seconds.
     *
     * @param float $seconds Target time (must be >= 0)
     * @throws \InvalidArgumentException if seconds is negative
     */
    public function setTime(float $seconds): void
    {
        if ($seconds < 0.0) {
            throw new \InvalidArgumentException(
                "Time must be non-negative, got {$seconds}",
            );
        }

        $now = microtime(true);
        $row = $this->read();
        $isPaused = $row['is_started'] === 0 || $row['pause_start'] > 0.0;

        if ($isPaused) {
            // When paused: set startTime so getTime() returns $seconds,
            // then re-establish the pause.
            $rate = $row['rate'] ?: 1.0;
            $this->write([
                'start_time'  => $now - $seconds,
                'rate_start'  => 0.0,
                'pause_start' => $now,
            ]);
        } else {
            $this->write([
                'start_time'  => $now - $seconds,
                'rate_start'  => $now,
                'pause_start' => 0.0,
            ]);
        }
    }

    /**
     * Whether playback is currently paused.
     */
    public function isPaused(): bool
    {
        $row = $this->read();
        return $row['is_started'] === 0 || $row['pause_start'] > 0.0;
    }

    /**
     * Get the current playback rate.
     */
    public function getRate(): float
    {
        return $this->read()['rate'] ?: 1.0;
    }

    /**
     * Set the playback rate.
     *
     * @param float $rate Playback speed multiplier (0.0625 – 16.0)
     * @throws \InvalidArgumentException if rate is out of range
     */
    public function setRate(float $rate): void
    {
        if ($rate < self::MIN_RATE || $rate > self::MAX_RATE) {
            throw new \InvalidArgumentException(
                sprintf('Rate must be between %.4f and %.1f, got %f', self::MIN_RATE, self::MAX_RATE, $rate),
            );
        }

        $row = $this->read();
        $isPaused = $row['is_started'] === 0 || $row['pause_start'] > 0.0;

        if (!$isPaused) {
            $rateTime = $this->computeRateTime($row);
            $oldRate = $row['rate'] ?: 1.0;
            $this->write([
                'start_time' => $row['start_time'] + $rateTime - $rateTime / $oldRate,
                'rate_start' => microtime(true),
                'rate'       => $rate,
            ]);
        } else {
            $this->write(['rate' => $rate]);
        }
    }

    /**
     * Build a time sync data array for broadcasting.
     *
     * @return array{time: float, paused: bool, rate: float}
     */
    public function getTimeData(): array
    {
        $row = $this->read();
        $isPaused = $row['is_started'] === 0 || $row['pause_start'] > 0.0;

        return [
            'time'   => $this->getTime(),
            'paused' => $isPaused,
            'rate'   => $row['rate'] ?: 1.0,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    //  Float drift protection
    // ─────────────────────────────────────────────────────────────

    /**
     * Re-bake accumulated time back into startTime to prevent float drift.
     *
     * Should be called periodically (e.g. hourly). Collapses all intermediate
     * arithmetic (rate offsets, pause offsets) into a single startTime value
     * so that getTime() = now - startTime with minimal accumulated error.
     */
    public function reBake(): void
    {
        $currentTime = $this->getTime();
        $row = $this->read();

        if ($row['is_started'] === 0) {
            return; // Nothing to re-bake
        }

        $now = microtime(true);
        $isPaused = $row['pause_start'] > 0.0;

        $this->write([
            'start_time'  => $now - $currentTime,
            'rate_start'  => $isPaused ? 0.0 : $now,
            'pause_start' => $isPaused ? $now : 0.0,
            'rebake_at'   => $now + self::REBAKE_INTERVAL,
        ]);
    }

    /**
     * Whether a re-bake is due (based on the stored rebake_at timestamp).
     */
    public function needsReBake(): bool
    {
        $rebakeAt = $this->read()['rebake_at'];
        return $rebakeAt > 0.0 && microtime(true) >= $rebakeAt;
    }

    // ─────────────────────────────────────────────────────────────
    //  Persistence (Redis serialization)
    // ─────────────────────────────────────────────────────────────

    /**
     * Serialize full timer state for persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = $this->read();
        return [
            'is_started'       => (bool) $row['is_started'],
            'start_time'       => $row['start_time'],
            'pause_start_time' => $row['pause_start'],
            'rate_start_time'  => $row['rate_start'],
            'rate'             => $row['rate'] ?: 1.0,
        ];
    }

    /**
     * Restore timer state from persistence.
     *
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): void
    {
        $this->write([
            'is_started'  => ((bool) ($data['is_started'] ?? false)) ? 1 : 0,
            'start_time'  => (float) ($data['start_time'] ?? 0.0),
            'pause_start' => (float) ($data['pause_start_time'] ?? 0.0),
            'rate_start'  => (float) ($data['rate_start_time'] ?? 0.0),
            'rate'        => (float) ($data['rate'] ?? 1.0),
            'rebake_at'   => microtime(true) + self::REBAKE_INTERVAL,
        ]);
    }

    /**
     * Delete this timer's state from the Swoole Table.
     */
    public function delete(): void
    {
        $this->table()->del($this->key);
    }

    // ─────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Calculate accumulated pause time from a table row snapshot.
     *
     * @param array{pause_start: float} $row
     */
    private function computePauseTime(array $row, ?float $now = null): float
    {
        if ($row['pause_start'] === 0.0) {
            return 0.0;
        }
        return ($now ?? microtime(true)) - $row['pause_start'];
    }

    /**
     * Calculate rate-adjusted time since last rate change, from a table row snapshot.
     *
     * @param array{rate_start: float, pause_start: float} $row
     */
    private function computeRateTime(array $row, ?float $now = null): float
    {
        if ($row['rate_start'] === 0.0) {
            return 0.0;
        }
        $now ??= microtime(true);
        return ($now - $row['rate_start']) - $this->computePauseTime($row, $now);
    }
}
