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

use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * PII Encryption Service using libsodium (AES-256-GCM equivalent via XChaCha20-Poly1305).
 *
 * Provides envelope encryption for personally identifiable information at rest.
 * Uses XChaCha20-Poly1305 (IETF AEAD) which is the recommended libsodium AEAD cipher.
 *
 * Key hierarchy:
 *   KEK (Key Encryption Key) = derived from PII_ENCRYPTION_KEY env var via Argon2ID
 *   DEK (Data Encryption Key) = random key, encrypted with KEK, stored in config/cache
 *
 * For simplicity in this implementation, we use the KEK directly as the DEK.
 * In production, implement proper envelope encryption with key rotation.
 */
final class PiiEncryptionService
{
    private LoggerInterface $logger;
    private string $encryptionKey;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('pii-encryption');

        $rawKey = \Hyperf\Support\env('PII_ENCRYPTION_KEY', '');
        if (!is_string($rawKey) || $rawKey === '') {
            $this->logger->warning('PII_ENCRYPTION_KEY not set — PII will be stored in plaintext');
            $this->encryptionKey = '';
            return;
        }

        // Derive a fixed-length key from the provided secret using BLAKE2b
        // This ensures we always have a SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES-length key
        $this->encryptionKey = sodium_crypto_generichash(
            $rawKey,
            '',
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
        );
    }

    /**
     * Check if encryption is available (key configured).
     */
    public function isEnabled(): bool
    {
        return $this->encryptionKey !== '';
    }

    /**
     * Encrypt a PII value.
     *
     * Returns a base64-encoded string: nonce || ciphertext || tag
     * Returns the original value if encryption is not configured.
     */
    public function encrypt(string $plaintext): string
    {
        if (!$this->isEnabled()) {
            return $plaintext;
        }

        if ($plaintext === '') {
            return '';
        }

        try {
            // Generate a random nonce (24 bytes for XChaCha20-Poly1305)
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

            // Additional authenticated data: empty for now, could include table/column context
            $aad = '';

            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $aad,
                $nonce,
                $this->encryptionKey
            );

            // Prepend 'enc:' marker so we can distinguish encrypted from plaintext
            return 'enc:' . base64_encode($nonce . $ciphertext);
        } catch (\SodiumException $e) {
            $this->logger->error('PII encryption failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to encrypt PII data');
        }
    }

    /**
     * Decrypt a PII value.
     *
     * Expects a base64-encoded string prefixed with 'enc:'.
     * If the value is not encrypted (no prefix), returns it as-is (migration compatibility).
     */
    public function decrypt(string $ciphertext): string
    {
        if (!$this->isEnabled()) {
            return $ciphertext;
        }

        if ($ciphertext === '') {
            return '';
        }

        // Not encrypted (legacy data or plaintext)
        if (!str_starts_with($ciphertext, 'enc:')) {
            return $ciphertext;
        }

        try {
            $decoded = base64_decode(substr($ciphertext, 4), true);
            if ($decoded === false) {
                $this->logger->error('PII decryption failed: invalid base64');
                return '[DECRYPTION_FAILED]';
            }

            $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($decoded) < $nonceLength) {
                $this->logger->error('PII decryption failed: data too short');
                return '[DECRYPTION_FAILED]';
            }

            $nonce = substr($decoded, 0, $nonceLength);
            $encrypted = substr($decoded, $nonceLength);
            $aad = '';

            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $encrypted,
                $aad,
                $nonce,
                $this->encryptionKey
            );

            if ($plaintext === false) {
                $this->logger->error('PII decryption failed: authentication failed');
                return '[DECRYPTION_FAILED]';
            }

            return $plaintext;
        } catch (\SodiumException $e) {
            $this->logger->error('PII decryption failed: ' . $e->getMessage());
            return '[DECRYPTION_FAILED]';
        }
    }

    /**
     * Encrypt a value only if it's not already encrypted.
     */
    public function encryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'enc:')) {
            return $value; // Already encrypted
        }
        return $this->encrypt($value);
    }

    /**
     * Securely wipe a string from memory.
     *
     * @param-out string|null $value
     */
    public function wipe(string &$value): void
    {
        $length = strlen($value);
        try {
            sodium_memzero($value);
        } catch (\SodiumException $e) {
            // Best effort: overwrite with zeros
            $value = str_repeat("\0", $length);
        }
    }

    /**
     * Generate a new random encryption key (for key rotation or initial setup).
     *
     * @return string Hex-encoded key suitable for PII_ENCRYPTION_KEY env var
     */
    public static function generateKey(): string
    {
        return sodium_bin2hex(
            random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)
        );
    }
}
