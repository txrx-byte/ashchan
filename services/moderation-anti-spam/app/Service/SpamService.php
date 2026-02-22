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
 * Layer 0a: StopForumSpam check (IP/email/username reputation)
 * Layer 0b: Spur IP intelligence (VPN/proxy/bot detection)
 * Layer 1: Rate limiting (IP-based sliding window)
 * Layer 2: Content fingerprinting (duplicate detection)
 * Layer 3: Risk scoring (heuristic analysis)
 * Layer 4: Captcha escalation (high-risk IPs)
 * Layer 5: Honeypot detection
 */
final class SpamService
{
    private int $rateWindow;
    private int $rateLimit;
    private int $threadLimit;
    private int $threadWindow;
    private int $riskThresholdHigh;
    private int $riskThresholdBlock;
    private int $duplicateFingerprintTtl;
    private int $captchaTtl;
    private int $captchaLength;
    private int $ipReputationTtl;
    private int $urlCountThreshold;
    private float $capsRatioThreshold;
    private int $excessiveLengthThreshold;

    public function __construct(
        private Redis $redis,
        private StopForumSpamService $sfsService,
        private SpurService $spurService,
        SiteConfigService $config,
    ) {
        $this->rateWindow               = $config->getInt('post_rate_window', 60);
        $this->rateLimit                = $config->getInt('post_rate_limit', 5);
        $this->threadLimit              = $config->getInt('thread_rate_limit', 1);
        $this->threadWindow             = $config->getInt('thread_rate_window', 300);
        $this->riskThresholdHigh        = $config->getInt('risk_threshold_high', 7);
        $this->riskThresholdBlock       = $config->getInt('risk_threshold_block', 10);
        $this->duplicateFingerprintTtl  = $config->getInt('duplicate_fingerprint_ttl', 3600);
        $this->captchaTtl               = $config->getInt('captcha_ttl', 300);
        $this->captchaLength            = $config->getInt('captcha_length', 6);
        $this->ipReputationTtl          = $config->getInt('ip_reputation_ttl', 86400);
        $this->urlCountThreshold        = $config->getInt('url_count_threshold', 3);
        $this->capsRatioThreshold       = $config->getFloat('caps_ratio_threshold', 0.7);
        $this->excessiveLengthThreshold = $config->getInt('excessive_length_threshold', 1500);
    }

    /**
     * Run all spam checks on a potential post.
     *
     * $ipHash is used for rate-limiting keys in Redis (ephemeral).
     * $realIp is the raw IP for external intelligence checks (SFS, Spur).
     * Raw IPs are never logged or persisted by this service.
     *
     * @return array{allowed: bool, score: int, reasons: string[], captcha_required: bool}
     */
    public function check(string $ipHash, string $content, bool $isThread, ?string $imageHash = null, ?string $realIp = null): array
    {
        $score   = 0;
        /** @var string[] $reasons */
        $reasons = [];

        // Layer 0a: StopForumSpam (if real IP provided)
        if ($realIp && $this->sfsService->check($realIp)) {
            $score += 100;
            $reasons[] = 'Blocked by StopForumSpam';
        }

        // Layer 0b: Spur IP Intelligence (if real IP provided and enabled)
        if ($realIp) {
            $spurResult = $this->spurService->evaluate($realIp);
            if ($spurResult['block']) {
                $score += $spurResult['score'];
                $reasons = array_merge($reasons, $spurResult['reasons']);
            } elseif ($spurResult['score'] > 0) {
                $score += $spurResult['score'];
                $reasons = array_merge($reasons, $spurResult['reasons']);
            }
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

        $allowed = $score < $this->riskThresholdBlock;
        $captchaRequired = $score >= $this->riskThresholdHigh;

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
        $postCount = $this->slidingWindowCount($postKey, $now, $this->rateWindow);
        if ($postCount >= $this->rateLimit) {
            return [true, "Post rate limit exceeded ({$postCount}/" . $this->rateLimit . " per " . $this->rateWindow . "s)"];
        }

        // Thread creation rate limit
        if ($isThread) {
            $threadKey = "ratelimit:thread:{$ipHash}";
            $threadCount = $this->slidingWindowCount($threadKey, $now, $this->threadWindow);
            if ($threadCount >= $this->threadLimit) {
                return [true, "Thread creation rate limit exceeded"];
            }
            $this->redis->zAdd($threadKey, $now, (string) $now);
            $this->redis->expire($threadKey, $this->threadWindow);
        }

        $this->redis->zAdd($postKey, $now, (string) $now);
        $this->redis->expire($postKey, $this->rateWindow);

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

        $this->redis->setex($key, $this->duplicateFingerprintTtl, '1');
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
        if ($urlCount > $this->urlCountThreshold) $score += 3;
        elseif ($urlCount > 1) $score += 1;

        // Excessive caps
        $alphaOnly = preg_replace('/[^a-zA-Z]/', '', $content);
        $alphaLen = mb_strlen(is_string($alphaOnly) ? $alphaOnly : '');
        if ($alphaLen > 20) {
            $capsOnly = preg_replace('/[^A-Z]/', '', $content);
            $capsLen = mb_strlen(is_string($capsOnly) ? $capsOnly : '');
            if ($capsLen / $alphaLen > $this->capsRatioThreshold) $score += 2;
        }

        // Excessive length
        if (mb_strlen($content) > $this->excessiveLengthThreshold) $score += 1;

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
        if ($score >= $this->riskThresholdHigh) {
            $this->redis->incr("ip_reputation:{$ipHash}");
            $this->redis->expire("ip_reputation:{$ipHash}", $this->ipReputationTtl);
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
        for ($i = 0; $i < $this->captchaLength; $i++) {
            $answer .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $token = bin2hex(random_bytes(16));
        $this->redis->setex("captcha:{$token}", $this->captchaTtl, $answer);

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
