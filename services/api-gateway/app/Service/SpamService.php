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


namespace App\Service;

use Hyperf\Redis\Redis;

/**
 * SpamService - Spam checking and captcha verification backed by Redis
 */
final class SpamService
{
    private const CAPTCHA_TTL = 300; // 5 minutes
    private const RATE_WINDOW = 60;   // 60 seconds
    private const RATE_LIMIT = 5;     // 5 posts per window
    private const THREAD_LIMIT = 1;   // 1 thread per 5 min
    private const THREAD_WINDOW = 300;
    private const RISK_THRESHOLD_BLOCK = 10;

    public function __construct(
        private Redis $redis,
    ) {}

    /**
     * Check content for spam
     */
    /** @return array{is_spam: bool, score: float, message: string} */
    public function check(string $ipHash, string $content, bool $isThread = false, ?string $imageHash = null): array
    {
        $score = 0.0;
        $reasons = [];

        // Layer 1: Rate limiting
        $postKey = "ratelimit:post:{$ipHash}";
        $now = time();
        $this->redis->zRemRangeByScore($postKey, '-inf', (string) ($now - self::RATE_WINDOW));
        $postCount = (int) $this->redis->zCard($postKey);
        if ($postCount >= self::RATE_LIMIT) {
            $score += 10.0;
            $reasons[] = 'Post rate limit exceeded';
        }
        $this->redis->zAdd($postKey, $now, (string) $now . ':' . bin2hex(random_bytes(4)));
        $this->redis->expire($postKey, self::RATE_WINDOW);

        if ($isThread) {
            $threadKey = "ratelimit:thread:{$ipHash}";
            $this->redis->zRemRangeByScore($threadKey, '-inf', (string) ($now - self::THREAD_WINDOW));
            $threadCount = (int) $this->redis->zCard($threadKey);
            if ($threadCount >= self::THREAD_LIMIT) {
                $score += 10.0;
                $reasons[] = 'Thread creation rate limit exceeded';
            }
            $this->redis->zAdd($threadKey, $now, (string) $now);
            $this->redis->expire($threadKey, self::THREAD_WINDOW);
        }

        // Layer 2: Duplicate content detection
        if (mb_strlen($content) >= 10) {
            $sanitized = preg_replace('/\s+/', ' ', trim($content));
            $fingerprint = hash('sha256', mb_strtolower(is_string($sanitized) ? $sanitized : ''));
            $fpKey = "fingerprint:{$fingerprint}";
            if ($this->redis->exists($fpKey)) {
                $score += 4.0;
                $reasons[] = 'Duplicate content detected';
            }
            $this->redis->setex($fpKey, 3600, '1');
        }

        // Layer 3: Content heuristics
        $lower = mb_strtolower($content);
        $urlCount = preg_match_all('/https?:\/\//', $lower);
        if ($urlCount > 3) {
            $score += 3.0;
            $reasons[] = 'Excessive URLs';
        }
        if (preg_match('/(.)\1{9,}/', $content)) {
            $score += 3.0;
            $reasons[] = 'Repeated characters';
        }
        $alphaOnly = preg_replace('/[^a-zA-Z]/', '', $content);
        $alphaLen = mb_strlen(is_string($alphaOnly) ? $alphaOnly : '');
        if ($alphaLen > 20) {
            $capsOnly = preg_replace('/[^A-Z]/', '', $content);
            $capsLen = mb_strlen(is_string($capsOnly) ? $capsOnly : '');
            if ($capsLen / $alphaLen > 0.7) {
                $score += 2.0;
                $reasons[] = 'Excessive caps';
            }
        }

        // Layer 4: Banned image hash
        if ($imageHash !== null && $this->redis->sIsMember('banned_images', $imageHash)) {
            $score += 10.0;
            $reasons[] = 'Banned image hash';
        }

        // Layer 5: IP reputation
        $repScore = $this->redis->get("ip_reputation:{$ipHash}");
        if (is_numeric($repScore) && (int) $repScore > 0) {
            $score += (float) $repScore;
            $reasons[] = 'IP reputation penalty';
        }

        // Record negative reputation
        if ($score >= 7.0) {
            $this->redis->incr("ip_reputation:{$ipHash}");
            $this->redis->expire("ip_reputation:{$ipHash}", 86400);
        }

        $isSpam = $score >= self::RISK_THRESHOLD_BLOCK;

        return [
            'is_spam' => $isSpam,
            'score' => $score,
            'message' => $isSpam ? implode('; ', $reasons) : 'OK',
        ];
    }

    /**
     * Generate captcha - stores answer in Redis with TTL
     */
    /** @return array{token: string, question: string} */
    public function generateCaptcha(): array
    {
        $a = random_int(1, 20);
        $b = random_int(1, 20);
        $answer = $a + $b;
        $token = bin2hex(random_bytes(16));

        $this->redis->setex(
            "captcha:{$token}",
            self::CAPTCHA_TTL,
            (string) $answer
        );

        return [
            'token' => $token,
            'question' => "{$a} + {$b} = ?",
        ];
    }

    /**
     * Verify captcha - checks answer against Redis-stored value, single-use
     */
    public function verifyCaptcha(string $token, string $response): bool
    {
        if ($token === '' || $response === '') {
            return false;
        }

        $key = "captcha:{$token}";
        $storedAnswer = $this->redis->get($key);

        if (!is_string($storedAnswer)) {
            return false; // Expired or invalid token
        }

        // Delete immediately to prevent replay
        $this->redis->del($key);

        return $response === $storedAnswer;
    }
}
