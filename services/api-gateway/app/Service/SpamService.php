<?php
declare(strict_types=1);

namespace App\Service;

/**
 * SpamService - Stub for spam checking and captcha
 */
final class SpamService
{
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
     * Generate captcha
     */
    /** @return array{token: string, answer: int} */
    public function generateCaptcha(): array
    {
        return [
            'token' => bin2hex(random_bytes(16)),
            'answer' => random_int(1, 10),
        ];
    }

    /**
     * Verify captcha
     */
    public function verifyCaptcha(string $token, string $response): bool
    {
        // In production, verify against stored captcha
        return true;
    }
}
