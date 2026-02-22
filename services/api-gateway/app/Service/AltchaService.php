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
use Psr\Log\LoggerInterface;
use Hyperf\Logger\LoggerFactory;

/**
 * Self-hosted ALTCHA proof-of-work captcha service.
 *
 * Generates SHA-256 challenges and verifies solutions entirely on the server.
 * No external API calls or third-party dependencies required.
 *
 * Algorithm:
 * 1. Generate random salt + secret number
 * 2. challenge = SHA-256(salt + secretNumber)
 * 3. signature = HMAC-SHA-256(challenge, hmacKey)
 * 4. Client brute-forces to find the number that produces the challenge hash
 * 5. Server re-derives challenge from payload and verifies signature + hash match
 *
 * Anti-replay: used challenges are stored in Redis with TTL.
 */
final class AltchaService
{
    private const ALGORITHM = 'SHA-256';
    private const MAX_NUMBER = 50000;
    private const CHALLENGE_TTL = 300; // 5 minutes
    private const REDIS_PREFIX = 'altcha:used:';

    private LoggerInterface $logger;
    private string $hmacKey;

    public function __construct(
        LoggerFactory $loggerFactory,
        private Redis $redis,
        private SiteConfigService $config,
    ) {
        $this->logger = $loggerFactory->get('altcha');
        $this->hmacKey = '';
    }

    /**
     * Check if ALTCHA captcha is enabled site-wide.
     */
    public function isEnabled(): bool
    {
        return $this->config->getBool('altcha_enabled', true);
    }

    /**
     * Get the HMAC key, falling back to env var then a generated default.
     */
    private function getHmacKey(): string
    {
        if ($this->hmacKey !== '') {
            return $this->hmacKey;
        }

        // Try site_settings first, then env var
        $key = $this->config->get('altcha_hmac_key', '');
        if ($key === '') {
            $key = (string) ($_ENV['ALTCHA_HMAC_KEY'] ?? $_SERVER['ALTCHA_HMAC_KEY'] ?? '');
        }
        if ($key === '') {
            // Generate a deterministic fallback from PII key (still secure, just auto-derived)
            $piiKey = (string) ($_ENV['PII_ENCRYPTION_KEY'] ?? $_SERVER['PII_ENCRYPTION_KEY'] ?? 'ashchan-default-key');
            $key = hash('sha256', 'altcha-hmac:' . $piiKey);
        }

        $this->hmacKey = $key;
        return $key;
    }

    /**
     * Create a new challenge for the client to solve.
     *
     * @return array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}
     */
    public function createChallenge(): array
    {
        $salt = bin2hex(random_bytes(12)); // 24-char hex
        $secretNumber = random_int(0, self::MAX_NUMBER);
        $expires = time() + self::CHALLENGE_TTL;

        // Append expiration to salt as query params (prevents salt tampering)
        $saltWithParams = $salt . '?expires=' . $expires . '&';

        // challenge = SHA-256(salt + secretNumber)
        $challenge = hash('sha256', $saltWithParams . $secretNumber);

        // signature = HMAC-SHA-256(challenge, hmacKey)
        $signature = hash_hmac('sha256', $challenge, $this->getHmacKey());

        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'maxnumber' => self::MAX_NUMBER,
            'salt' => $saltWithParams,
            'signature' => $signature,
        ];
    }

    /**
     * Verify a solution payload from the client.
     *
     * The payload is a Base64-encoded JSON string containing:
     * {algorithm, challenge, number, salt, signature}
     */
    public function verifySolution(string $payload): bool
    {
        if ($payload === '') {
            $this->logger->debug('ALTCHA verification failed: empty payload');
            return false;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            $this->logger->debug('ALTCHA verification failed: invalid base64');
            return false;
        }

        /** @var array{algorithm?: string, challenge?: string, number?: int|string, salt?: string, signature?: string}|null $data */
        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            $this->logger->debug('ALTCHA verification failed: invalid JSON');
            return false;
        }

        $algorithm = $data['algorithm'] ?? '';
        $challenge = $data['challenge'] ?? '';
        $number = $data['number'] ?? null;
        $salt = $data['salt'] ?? '';
        $signature = $data['signature'] ?? '';

        // Check algorithm
        if ($algorithm !== self::ALGORITHM) {
            $this->logger->debug('ALTCHA verification failed: wrong algorithm', ['algorithm' => $algorithm]);
            return false;
        }

        // Check required fields
        if ($challenge === '' || $number === null || $salt === '' || $signature === '') {
            $this->logger->debug('ALTCHA verification failed: missing fields');
            return false;
        }

        // Check expiration from salt params
        if (str_contains($salt, '?expires=')) {
            if (preg_match('/\?expires=(\d+)/', $salt, $m)) {
                $expires = (int) $m[1];
                if (time() > $expires) {
                    $this->logger->debug('ALTCHA verification failed: challenge expired');
                    return false;
                }
            }
        }

        // Verify challenge: SHA-256(salt + number) === challenge
        $expectedChallenge = hash('sha256', $salt . (string) $number);
        if (!hash_equals($expectedChallenge, $challenge)) {
            $this->logger->debug('ALTCHA verification failed: challenge mismatch');
            return false;
        }

        // Verify signature: HMAC-SHA-256(challenge, hmacKey) === signature
        $expectedSignature = hash_hmac('sha256', $challenge, $this->getHmacKey());
        if (!hash_equals($expectedSignature, $signature)) {
            $this->logger->debug('ALTCHA verification failed: signature mismatch');
            return false;
        }

        // Anti-replay: check if this challenge was already used
        $replayKey = self::REDIS_PREFIX . $challenge;
        try {
            $alreadyUsed = $this->redis->get($replayKey);
            if ($alreadyUsed) {
                $this->logger->debug('ALTCHA verification failed: replay detected');
                return false;
            }
            // Mark as used with TTL
            $this->redis->setex($replayKey, self::CHALLENGE_TTL * 2, '1');
        } catch (\Throwable $e) {
            // Redis unavailable — skip replay check but log warning
            $this->logger->warning('ALTCHA anti-replay check skipped: Redis unavailable', [
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }
}
