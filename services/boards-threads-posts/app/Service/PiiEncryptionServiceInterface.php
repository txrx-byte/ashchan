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

/**
 * Interface for PII encryption services.
 * 
 * Allows for test doubles and alternative implementations.
 */
interface PiiEncryptionServiceInterface
{
    /**
     * Check if encryption is available (key configured).
     */
    public function isEnabled(): bool;

    /**
     * Encrypt a PII value.
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypt a PII value.
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Encrypt a value only if it's not already encrypted.
     */
    public function encryptIfNeeded(string $value): string;

    /**
     * Securely wipe a string from memory.
     */
    public function wipe(string &$value): void;
}
