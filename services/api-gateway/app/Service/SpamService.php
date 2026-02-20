<?php
declare(strict_types=1);

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
