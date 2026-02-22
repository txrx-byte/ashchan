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
 * PII Encryption Service using libsodium (XChaCha20-Poly1305 AEAD).
 *
 * Provides authenticated encryption for personally identifiable information at rest.
 * Uses XChaCha20-Poly1305 (IETF AEAD) — the recommended libsodium AEAD cipher.
 *
 * Key hierarchy:
 *   KEK (Key Encryption Key) = derived from PII_ENCRYPTION_KEY env var via BLAKE2b
 *                               with a fixed application-specific salt to prevent
 *                               key reuse across different contexts.
 *   DEK (Data Encryption Key) = random per-value nonce provides per-ciphertext uniqueness.
 *
 * Wire format: "enc:" || base64(nonce || ciphertext || tag)
 *   - nonce:      24 bytes (XCHACHA20_NPUB)
 *   - ciphertext: len(plaintext) bytes
 *   - tag:        16 bytes (Poly1305 MAC)
 *
 * Security notes:
 *   - Each encrypt() call generates a fresh random nonce (no nonce reuse)
 *   - Decryption authenticates before decrypting (AEAD)
 *   - Key material is wiped from memory on object destruction
 *   - For production key rotation, implement envelope encryption with versioned DEKs
 */
final class PiiEncryptionService
{
    private LoggerInterface $logger;
    private string $encryptionKey;

    /**
     * Application-specific salt for key derivation.
     *
     * This ensures the same PII_ENCRYPTION_KEY produces different derived keys
     * when used in different application contexts, preventing cross-context
     * key reuse. The salt is not secret — its purpose is domain separation.
     */
    private const KEY_DERIVATION_SALT = 'ashchan-pii-encryption-v1';

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('pii-encryption');

        $rawKey = \Hyperf\Support\env('PII_ENCRYPTION_KEY', '');
        if (!is_string($rawKey) || $rawKey === '') {
            $this->logger->warning('PII_ENCRYPTION_KEY not set — PII will be stored in plaintext');
            $this->encryptionKey = '';
            return;
        }

        // Derive a fixed-length key using BLAKE2b with an application-specific salt.
        // The salt provides domain separation so the same master key produces
        // different derived keys for different purposes.
        $this->encryptionKey = sodium_crypto_generichash(
            $rawKey,
            self::KEY_DERIVATION_SALT,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
        );

        // Wipe the raw key from local scope immediately.
        // sodium_memzero sets the variable to null/empty; we've already
        // derived our key so the raw value is no longer needed.
        try {
            sodium_memzero($rawKey);
        } catch (\SodiumException) {
            // Best-effort wipe
        }
    }

    /**
     * Securely wipe the derived key from memory on destruction.
     *
     * Important in long-lived Swoole worker processes where objects may persist
     * across many requests. Ensures key material doesn't linger in memory
     * after the service is no longer needed.
     */
    public function __destruct()
    {
        if ($this->encryptionKey !== '') {
            // Use str_repeat to overwrite with zeros instead of sodium_memzero,
            // because sodium_memzero sets the variable to null which conflicts
            // with the string type declaration on this property.
            $length = strlen($this->encryptionKey);
            $this->encryptionKey = str_repeat("\0", $length);
        }
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
     * Returns a base64-encoded string prefixed with 'enc:': nonce || ciphertext || tag.
     * Returns the original value if encryption is not configured.
     *
     * @param string $plaintext The sensitive data to encrypt
     * @return string Encrypted string in "enc:<base64>" format, or raw value if encryption disabled
     * @throws \RuntimeException If encryption fails (e.g., libsodium error)
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
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $aad = '';

            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $aad,
                $nonce,
                $this->encryptionKey
            );

            $result = 'enc:' . base64_encode($nonce . $ciphertext);

            // Wipe intermediate sensitive values
            sodium_memzero($nonce);

            return $result;
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
     *
     * @param string $ciphertext The encrypted string (or raw string for migration compat)
     * @return string The decrypted plaintext, or '[DECRYPTION_FAILED]' on error
     */
    public function decrypt(string $ciphertext): string
    {
        if (!$this->isEnabled()) {
            return $ciphertext;
        }

        if ($ciphertext === '') {
            return '';
        }

        // Migration compatibility: unencrypted values lack the 'enc:' prefix
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
            $minLength = $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
            if (strlen($decoded) < $minLength) {
                $this->logger->error('PII decryption failed: data too short (expected at least ' . $minLength . ' bytes, got ' . strlen($decoded) . ')');
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
                $this->logger->error('PII decryption failed: authentication failed (tampered or wrong key)');
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
     *
     * Useful during data migration to avoid double-encrypting existing values.
     *
     * @param string $value The value to conditionally encrypt
     * @return string The encrypted value
     */
    public function encryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'enc:')) {
            return $value;
        }
        return $this->encrypt($value);
    }

    /**
     * Securely wipe a string from memory.
     *
     * Uses libsodium's sodium_memzero() which overwrites memory with zeros.
     * Falls back to manual zeroing if sodium fails.
     *
     * @param string $value The string to wipe (passed by reference, will be zeroed)
     * @param-out string|null $value
     */
    public function wipe(string &$value): void
    {
        $length = strlen($value);
        try {
            sodium_memzero($value);
        } catch (\SodiumException) {
            $value = str_repeat("\0", $length);
        }
    }

    /**
     * Generate a new random encryption key for initial setup or key rotation.
     *
     * @return string Hex-encoded key suitable for the PII_ENCRYPTION_KEY env var
     */
    public static function generateKey(): string
    {
        return sodium_bin2hex(
            random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)
        );
    }
}
