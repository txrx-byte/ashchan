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
 * Multi-layer anti-spam engine.
 *
 * Layer 1: Rate limiting (IP-based sliding window)
 * Layer 2: Content fingerprinting (duplicate detection)
 * Layer 3: Risk scoring (heuristic analysis)
 * Layer 4: Captcha escalation (high-risk IPs)
 * Layer 5: Honeypot detection
 */
final class SpamService
{
    private const RATE_WINDOW   = 60;  // 60 seconds
    private const RATE_LIMIT    = 5;   // 5 posts per window
    private const THREAD_LIMIT  = 1;   // 1 thread per 5 min
    private const THREAD_WINDOW = 300;

    private const RISK_THRESHOLD_HIGH  = 7;
    private const RISK_THRESHOLD_BLOCK = 10;

    public function __construct(
        private Redis $redis,
        private StopForumSpamService $sfsService,
    ) {}

    /**
     * Run all spam checks on a potential post.
     *
     * @return array{allowed: bool, score: int, reasons: string[], captcha_required: bool}
     */
    public function check(string $ipHash, string $content, bool $isThread, ?string $imageHash = null, ?string $realIp = null): array
    {
        $score   = 0;
        /** @var string[] $reasons */
        $reasons = [];

        // Layer 0: StopForumSpam (if real IP provided)
        if ($realIp && $this->sfsService->check($realIp)) {
            $score += 100;
            $reasons[] = 'Blocked by StopForumSpam';
        }

        // Layer 1: Rate limiting
        [$rateLimited, $rateReason] = $this->checkRateLimit($ipHash, $isThread);
        if ($rateLimited) {
            $score += 10;
            $reasons[] = (string) $rateReason;
        }

        // Layer 2: Duplicate content
        if ($this->isDuplicate($content)) {
            $score += 4;
            $reasons[] = 'Duplicate content detected';
        }

        // Layer 3: Content risk scoring
        $contentScore = $this->scoreContent($content);
        $score += $contentScore;
        if ($contentScore > 0) {
            $reasons[] = "Content risk: {$contentScore}";
        }

        // Layer 4: Image hash check
        if ($imageHash && $this->isBannedImage($imageHash)) {
            $score += 10;
            $reasons[] = 'Banned image hash';
        }

        // Layer 5: IP reputation
        $ipScore = $this->getIpReputation($ipHash);
        $score += $ipScore;
        if ($ipScore > 0) {
            $reasons[] = "IP reputation: {$ipScore}";
        }

        $allowed = $score < self::RISK_THRESHOLD_BLOCK;
        $captchaRequired = $score >= self::RISK_THRESHOLD_HIGH;

        // Record this attempt
        $this->recordAttempt($ipHash, $score);

        return [
            'allowed'          => $allowed,
            'score'            => $score,
            'reasons'          => $reasons,
            'captcha_required' => $captchaRequired,
        ];
    }

    /* ──────────────────────────────────────────────
     * Layer 1: Rate Limiting
     * ────────────────────────────────────────────── */

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkRateLimit(string $ipHash, bool $isThread): array
    {
        $now = time();

        // Post rate limit
        $postKey = "ratelimit:post:{$ipHash}";
        $postCount = $this->slidingWindowCount($postKey, $now, self::RATE_WINDOW);
        if ($postCount >= self::RATE_LIMIT) {
            return [true, "Post rate limit exceeded ({$postCount}/" . self::RATE_LIMIT . " per " . self::RATE_WINDOW . "s)"];
        }

        // Thread creation rate limit
        if ($isThread) {
            $threadKey = "ratelimit:thread:{$ipHash}";
            $threadCount = $this->slidingWindowCount($threadKey, $now, self::THREAD_WINDOW);
            if ($threadCount >= self::THREAD_LIMIT) {
                return [true, "Thread creation rate limit exceeded"];
            }
            $this->redis->zAdd($threadKey, $now, (string) $now);
            $this->redis->expire($threadKey, self::THREAD_WINDOW);
        }

        $this->redis->zAdd($postKey, $now, (string) $now);
        $this->redis->expire($postKey, self::RATE_WINDOW);

        return [false, ''];
    }

    private function slidingWindowCount(string $key, int $now, int $window): int
    {
        $this->redis->zRemRangeByScore($key, '-inf', (string) ($now - $window));
        return (int) $this->redis->zCard($key);
    }

    /* ──────────────────────────────────────────────
     * Layer 2: Duplicate Detection
     * ────────────────────────────────────────────── */

    private function isDuplicate(string $content): bool
    {
        if (mb_strlen($content) < 10) return false;

        $sanitized = preg_replace('/\s+/', ' ', trim($content));
        $fingerprint = hash('sha256', mb_strtolower(is_string($sanitized) ? $sanitized : ''));
        $key = "fingerprint:{$fingerprint}";

        if ($this->redis->exists($key)) {
            return true;
        }

        $this->redis->setex($key, 3600, '1'); // 1-hour window
        return false;
    }

    /* ──────────────────────────────────────────────
     * Layer 3: Content Risk Scoring
     * ────────────────────────────────────────────── */

    private function scoreContent(string $content): int
    {
        $score = 0;
        $lower = mb_strtolower($content);

        // Too many URLs
        $urlCount = preg_match_all('/https?:\/\//', $lower);
        if ($urlCount > 3) $score += 3;
        elseif ($urlCount > 1) $score += 1;

        // Excessive caps
        $alphaOnly = preg_replace('/[^a-zA-Z]/', '', $content);
        $alphaLen = mb_strlen(is_string($alphaOnly) ? $alphaOnly : '');
        if ($alphaLen > 20) {
            $capsOnly = preg_replace('/[^A-Z]/', '', $content);
            $capsLen = mb_strlen(is_string($capsOnly) ? $capsOnly : '');
            if ($capsLen / $alphaLen > 0.7) $score += 2;
        }

        // Excessive length
        if (mb_strlen($content) > 1500) $score += 1;

        // Repeated characters
        if (preg_match('/(.)\1{9,}/', $content)) $score += 3;

        // Empty/tiny content with no image context
        if (mb_strlen(trim($content)) < 3) $score += 1;

        return $score;
    }

    /* ──────────────────────────────────────────────
     * Layer 4: Image Bans
     * ────────────────────────────────────────────── */

    private function isBannedImage(string $hash): bool
    {
        return (bool) $this->redis->sIsMember('banned_images', $hash);
    }

    public function banImage(string $hash): void
    {
        $this->redis->sAdd('banned_images', $hash);
    }

    /* ──────────────────────────────────────────────
     * Layer 5: IP Reputation
     * ────────────────────────────────────────────── */

    private function getIpReputation(string $ipHash): int
    {
        $res = $this->redis->get("ip_reputation:{$ipHash}");
        $score = is_numeric($res) ? (int) $res : 0;
        return $score;
    }

    private function recordAttempt(string $ipHash, int $score): void
    {
        if ($score >= self::RISK_THRESHOLD_HIGH) {
            $this->redis->incr("ip_reputation:{$ipHash}");
            $this->redis->expire("ip_reputation:{$ipHash}", 86400);
        }
    }

    /* ──────────────────────────────────────────────
     * Captcha Verification
     * ────────────────────────────────────────────── */

    /**
     * @return array{token: string, answer: string}
     */
    public function generateCaptcha(): array
    {
        $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $answer = '';
        for ($i = 0; $i < 6; $i++) {
            $answer .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $token = bin2hex(random_bytes(16));
        $this->redis->setex("captcha:{$token}", 300, $answer); // 5-min expiry

        return [
            'token'  => $token,
            'answer' => $answer, // Used to render the captcha image server-side
        ];
    }

    public function verifyCaptcha(string $token, string $response): bool
    {
        $expected = $this->redis->get("captcha:{$token}");
        if (!$expected) return false;

        $this->redis->del("captcha:{$token}"); // One-time use
        return strtoupper(trim($response)) === $expected;
    }
}
