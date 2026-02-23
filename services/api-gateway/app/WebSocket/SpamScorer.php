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

namespace App\WebSocket;

/**
 * Tracks per-IP spam scores for liveposting rate limiting.
 *
 * Following meguca's model, each action has a cost in spam points. Scores
 * decay over time (1 point/second). When the score exceeds the threshold,
 * the client is required to solve a captcha before further post creation.
 *
 * Action Costs:
 *   Post creation:  50 points
 *   Char append:     1 point
 *   Splice:          2 points per character
 *   Image attach:   30 points
 *
 * Design:
 *   - Worker-local arrays (no Swoole Tables) — same as Phase 1 IP counting.
 *   - Scores are stored as {score: int, lastDecay: int (unix timestamp)}.
 *   - Lazy decay: computed on read, not via timers.
 *   - Periodic cleanup of stale entries (>10 min since last activity).
 *
 * @see docs/LIVEPOSTING.md §10.3
 */
final class SpamScorer
{
    // ---- Action costs ----
    public const COST_POST_CREATION = 50;
    public const COST_CHAR_APPEND   = 1;
    public const COST_SPLICE        = 2;
    public const COST_IMAGE_ATTACH  = 30;

    /** Default threshold: 500 points → require captcha. */
    private readonly int $captchaThreshold;

    /** Decay rate: 1 point per second. */
    private const DECAY_RATE = 1;

    /** Stale entry cleanup: remove entries inactive for >10 min. */
    private const STALE_THRESHOLD_SECONDS = 600;

    /**
     * Worker-local spam score registry.
     * ipHash → {score: int, lastDecay: int}
     *
     * @var array<string, array{score: int, lastDecay: int}>
     */
    private array $scores = [];

    /** Cleanup timer ID. */
    private int $cleanupTimerId = 0;

    public function __construct(int $captchaThreshold = 500)
    {
        $this->captchaThreshold = $captchaThreshold;
    }

    /**
     * Start the periodic stale-entry cleanup timer.
     */
    public function start(): void
    {
        // Clean up every 60 seconds
        $this->cleanupTimerId = \Swoole\Timer::tick(60_000, function (): void {
            $this->cleanup();
        });
    }

    /**
     * Stop the cleanup timer.
     */
    public function stop(): void
    {
        if ($this->cleanupTimerId > 0) {
            \Swoole\Timer::clear($this->cleanupTimerId);
            $this->cleanupTimerId = 0;
        }
    }

    /**
     * Record a spam cost for an IP.
     *
     * @param string $ipHash  Hash of the client IP
     * @param int    $cost    Points to add
     * @return int   The new (decayed) score after adding cost
     */
    public function record(string $ipHash, int $cost): int
    {
        $now = time();

        if (!isset($this->scores[$ipHash])) {
            $this->scores[$ipHash] = ['score' => $cost, 'lastDecay' => $now];
            return $cost;
        }

        // Apply decay first
        $entry = $this->scores[$ipHash];
        $elapsed = $now - $entry['lastDecay'];
        $decayed = max(0, $entry['score'] - ($elapsed * self::DECAY_RATE));

        // Add new cost
        $newScore = $decayed + $cost;
        $this->scores[$ipHash] = ['score' => $newScore, 'lastDecay' => $now];

        return $newScore;
    }

    /**
     * Get the current (decayed) score for an IP.
     *
     * @param string $ipHash
     * @return int
     */
    public function getScore(string $ipHash): int
    {
        if (!isset($this->scores[$ipHash])) {
            return 0;
        }

        $entry = $this->scores[$ipHash];
        $elapsed = time() - $entry['lastDecay'];
        return max(0, $entry['score'] - ($elapsed * self::DECAY_RATE));
    }

    /**
     * Check whether an IP has exceeded the captcha threshold.
     *
     * @param string $ipHash
     * @return bool  true if captcha should be required
     */
    public function requiresCaptcha(string $ipHash): bool
    {
        return $this->getScore($ipHash) >= $this->captchaThreshold;
    }

    /**
     * Reset the score for an IP (e.g., after captcha is solved).
     *
     * @param string $ipHash
     */
    public function resetScore(string $ipHash): void
    {
        unset($this->scores[$ipHash]);
    }

    /**
     * Get the captcha threshold.
     *
     * @return int
     */
    public function getThreshold(): int
    {
        return $this->captchaThreshold;
    }

    /**
     * Get current entry count for metrics.
     *
     * @return int
     */
    public function getTrackedIpCount(): int
    {
        return count($this->scores);
    }

    /**
     * Remove stale entries that have fully decayed and been inactive.
     */
    private function cleanup(): void
    {
        $now = time();
        $removed = 0;

        foreach ($this->scores as $ipHash => $entry) {
            $elapsed = $now - $entry['lastDecay'];
            if ($elapsed >= self::STALE_THRESHOLD_SECONDS) {
                unset($this->scores[$ipHash]);
                $removed++;
            }
        }
    }
}
