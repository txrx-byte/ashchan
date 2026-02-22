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
 * SpamService - Spam checking and captcha verification backed by Redis.
 * All thresholds and rate limits are configured via site_settings (admin panel).
 */
final class SpamService
{
    private int $captchaTtl;
    private int $rateWindow;
    private int $rateLimit;
    private int $threadLimit;
    private int $threadWindow;
    private int $riskThresholdBlock;
    private int $duplicateFingerprintTtl;
    private int $minFingerprintLength;
    private int $urlCountThreshold;
    private int $repeatedCharThreshold;
    private float $capsRatioThreshold;
    private int $ipReputationTtl;
    private float $reputationEscalationThreshold;

    public function __construct(
        private Redis $redis,
        SiteConfigService $config,
    ) {
        $this->captchaTtl = $config->getInt('captcha_ttl', 300);
        $this->rateWindow = $config->getInt('post_rate_window', 60);
        $this->rateLimit = $config->getInt('post_rate_limit', 5);
        $this->threadLimit = $config->getInt('thread_rate_limit', 1);
        $this->threadWindow = $config->getInt('thread_rate_window', 300);
        $this->riskThresholdBlock = $config->getInt('risk_threshold_block', 10);
        $this->duplicateFingerprintTtl = $config->getInt('duplicate_fingerprint_ttl', 3600);
        $this->minFingerprintLength = $config->getInt('min_fingerprint_length', 10);
        $this->urlCountThreshold = $config->getInt('url_count_threshold', 3);
        $this->repeatedCharThreshold = $config->getInt('repeated_char_threshold', 9);
        $this->capsRatioThreshold = $config->getFloat('caps_ratio_threshold', 0.7);
        $this->ipReputationTtl = $config->getInt('ip_reputation_ttl', 86400);
        $this->reputationEscalationThreshold = $config->getFloat('reputation_escalation_threshold', 7.0);
    }

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
        $this->redis->zRemRangeByScore($postKey, '-inf', (string) ($now - $this->rateWindow));
        $postCount = (int) $this->redis->zCard($postKey);
        if ($postCount >= $this->rateLimit) {
            $score += 10.0;
            $reasons[] = 'Post rate limit exceeded';
        }
        $this->redis->zAdd($postKey, $now, (string) $now . ':' . bin2hex(random_bytes(4)));
        $this->redis->expire($postKey, $this->rateWindow);

        if ($isThread) {
            $threadKey = "ratelimit:thread:{$ipHash}";
            $this->redis->zRemRangeByScore($threadKey, '-inf', (string) ($now - $this->threadWindow));
            $threadCount = (int) $this->redis->zCard($threadKey);
            if ($threadCount >= $this->threadLimit) {
                $score += 10.0;
                $reasons[] = 'Thread creation rate limit exceeded';
            }
            $this->redis->zAdd($threadKey, $now, (string) $now);
            $this->redis->expire($threadKey, $this->threadWindow);
        }

        // Layer 2: Duplicate content detection
        if (mb_strlen($content) >= $this->minFingerprintLength) {
            $sanitized = preg_replace('/\s+/', ' ', trim($content));
            $fingerprint = hash('sha256', mb_strtolower(is_string($sanitized) ? $sanitized : ''));
            $fpKey = "fingerprint:{$fingerprint}";
            if ($this->redis->exists($fpKey)) {
                $score += 4.0;
                $reasons[] = 'Duplicate content detected';
            }
            $this->redis->setex($fpKey, $this->duplicateFingerprintTtl, '1');
        }

        // Layer 3: Content heuristics
        $lower = mb_strtolower($content);
        $urlCount = preg_match_all('/https?:\/\//', $lower);
        if ($urlCount > $this->urlCountThreshold) {
            $score += 3.0;
            $reasons[] = 'Excessive URLs';
        }
        $repThreshold = $this->repeatedCharThreshold;
        if (preg_match('/(.)\1{' . $repThreshold . ',}/', $content)) {
            $score += 3.0;
            $reasons[] = 'Repeated characters';
        }
        $alphaOnly = preg_replace('/[^a-zA-Z]/', '', $content);
        $alphaLen = mb_strlen(is_string($alphaOnly) ? $alphaOnly : '');
        if ($alphaLen > 20) {
            $capsOnly = preg_replace('/[^A-Z]/', '', $content);
            $capsLen = mb_strlen(is_string($capsOnly) ? $capsOnly : '');
            if ($capsLen / $alphaLen > $this->capsRatioThreshold) {
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
        if ($score >= $this->reputationEscalationThreshold) {
            $this->redis->incr("ip_reputation:{$ipHash}");
            $this->redis->expire("ip_reputation:{$ipHash}", $this->ipReputationTtl);
        }

        $isSpam = $score >= $this->riskThresholdBlock;

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
            $this->captchaTtl,
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
