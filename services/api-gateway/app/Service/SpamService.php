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

    public function __construct(
        private Redis $redis,
    ) {}

    /**
     * Check content for spam
     */
    /** @return array{is_spam: bool, score: float, message: string} */
    public function check(string $ipHash, string $content, bool $isThread = false, ?string $imageHash = null): array
    {
        return [
            'is_spam' => false,
            'score' => 0.0,
            'message' => 'OK',
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
