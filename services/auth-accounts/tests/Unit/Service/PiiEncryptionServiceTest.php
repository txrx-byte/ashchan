<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PiiEncryptionService;
use Hyperf\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \App\Service\PiiEncryptionService
 */
final class PiiEncryptionServiceTest extends TestCase
{
    private PiiEncryptionService $service;
    private LoggerInterface $logger;
    private LoggerFactory $loggerFactory;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->loggerFactory = $this->createMock(LoggerFactory::class);
        $this->loggerFactory->method('get')
            ->with('pii-encryption')
            ->willReturn($this->logger);

        // Set the env var before constructing the service
        putenv('PII_ENCRYPTION_KEY=test-key-must-be-long-enough-for-derivation');
        $this->service = new PiiEncryptionService($this->loggerFactory);
    }

    protected function tearDown(): void
    {
        putenv('PII_ENCRYPTION_KEY');
    }

    /* ──────────────────────────────────────
     * isEnabled()
     * ────────────────────────────────────── */

    public function testIsEnabledReturnsTrueWhenKeySet(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenNoKey(): void
    {
        putenv('PII_ENCRYPTION_KEY=');
        $service = new PiiEncryptionService($this->loggerFactory);
        $this->assertFalse($service->isEnabled());
    }

    /* ──────────────────────────────────────
     * encrypt() / decrypt() roundtrip
     * ────────────────────────────────────── */

    public function testEncryptDecryptRoundtrip(): void
    {
        $plaintext = '192.168.1.100';
        $encrypted = $this->service->encrypt($plaintext);

        $this->assertStringStartsWith('enc:', $encrypted);
        $this->assertNotEquals($plaintext, $encrypted);

        $decrypted = $this->service->decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $plaintext = 'test-data-for-nonce-uniqueness';
        $encrypted1 = $this->service->encrypt($plaintext);
        $encrypted2 = $this->service->encrypt($plaintext);

        // Different nonces → different ciphertext (probabilistic encryption)
        $this->assertNotEquals($encrypted1, $encrypted2);

        // Both should decrypt to the same value
        $this->assertSame($plaintext, $this->service->decrypt($encrypted1));
        $this->assertSame($plaintext, $this->service->decrypt($encrypted2));
    }

    public function testEncryptEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', $this->service->encrypt(''));
    }

    public function testDecryptEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', $this->service->decrypt(''));
    }

    public function testEncryptLongPayload(): void
    {
        $plaintext = str_repeat('A', 10000);
        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptUnicodeContent(): void
    {
        $plaintext = '🎉 Unicode test: こんにちは世界 — émojis & spëcial chars';
        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    /* ──────────────────────────────────────
     * decrypt() edge cases and failure modes
     * ────────────────────────────────────── */

    public function testDecryptNonEncryptedValueReturnsAsIs(): void
    {
        // Values without enc: prefix are returned unchanged (migration compat)
        $raw = 'plain-ip-address';
        $this->assertSame($raw, $this->service->decrypt($raw));
    }

    public function testDecryptInvalidBase64ReturnsFailureMarker(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('invalid base64'));

        $result = $this->service->decrypt('enc:!!!not-base64!!!');
        $this->assertSame('[DECRYPTION_FAILED]', $result);
    }

    public function testDecryptTruncatedDataReturnsFailureMarker(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('data too short'));

        // Valid base64 but too short to contain nonce + tag
        $result = $this->service->decrypt('enc:' . base64_encode('short'));
        $this->assertSame('[DECRYPTION_FAILED]', $result);
    }

    public function testDecryptTamperedCiphertextReturnsFailureMarker(): void
    {
        $encrypted = $this->service->encrypt('secret data');

        // Tamper with the ciphertext by flipping a byte
        $decoded = base64_decode(substr($encrypted, 4), true);
        $this->assertNotFalse($decoded);
        $tampered = $decoded;
        // Flip the last byte (in ciphertext area, after the nonce)
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);
        $tamperedEncrypted = 'enc:' . base64_encode($tampered);

        $result = $this->service->decrypt($tamperedEncrypted);
        $this->assertSame('[DECRYPTION_FAILED]', $result);
    }

    public function testDecryptWithWrongKeyReturnsFailureMarker(): void
    {
        $encrypted = $this->service->encrypt('secret data');

        // Create a service with a different key
        putenv('PII_ENCRYPTION_KEY=completely-different-key-for-testing');
        $otherService = new PiiEncryptionService($this->loggerFactory);

        $result = $otherService->decrypt($encrypted);
        $this->assertSame('[DECRYPTION_FAILED]', $result);
    }

    /* ──────────────────────────────────────
     * Disabled encryption (no key)
     * ────────────────────────────────────── */

    public function testEncryptWithoutKeyReturnsPlaintext(): void
    {
        putenv('PII_ENCRYPTION_KEY=');
        $service = new PiiEncryptionService($this->loggerFactory);

        $plaintext = '10.0.0.1';
        $this->assertSame($plaintext, $service->encrypt($plaintext));
    }

    public function testDecryptWithoutKeyReturnsInput(): void
    {
        putenv('PII_ENCRYPTION_KEY=');
        $service = new PiiEncryptionService($this->loggerFactory);

        $input = 'enc:somebase64data';
        $this->assertSame($input, $service->decrypt($input));
    }

    /* ──────────────────────────────────────
     * encryptIfNeeded()
     * ────────────────────────────────────── */

    public function testEncryptIfNeededEncryptsPlainValue(): void
    {
        $result = $this->service->encryptIfNeeded('192.168.1.1');
        $this->assertStringStartsWith('enc:', $result);
    }

    public function testEncryptIfNeededSkipsAlreadyEncrypted(): void
    {
        $encrypted = $this->service->encrypt('192.168.1.1');
        $result = $this->service->encryptIfNeeded($encrypted);
        // Should return the same value untouched (not double-encrypt)
        $this->assertSame($encrypted, $result);
    }

    /* ──────────────────────────────────────
     * wipe()
     * ────────────────────────────────────── */

    public function testWipeZeroesOutString(): void
    {
        $sensitive = 'super-secret-password';
        $this->service->wipe($sensitive);
        // After wipe, the value should be zeroed or nulled
        $this->assertTrue($sensitive === '' || $sensitive === null || $sensitive === str_repeat("\0", 21));
    }

    /* ──────────────────────────────────────
     * generateKey()
     * ────────────────────────────────────── */

    public function testGenerateKeyReturnsHexString(): void
    {
        $key = PiiEncryptionService::generateKey();
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $key);
        // XChaCha20-Poly1305 key is 32 bytes = 64 hex chars
        $this->assertSame(64, strlen($key));
    }

    public function testGenerateKeyProducesUniqueKeys(): void
    {
        $key1 = PiiEncryptionService::generateKey();
        $key2 = PiiEncryptionService::generateKey();
        $this->assertNotEquals($key1, $key2);
    }

    /* ──────────────────────────────────────
     * Destructor (key zeroing)
     * ────────────────────────────────────── */

    public function testDestructorDoesNotThrow(): void
    {
        $service = new PiiEncryptionService($this->loggerFactory);
        // Explicit destruction should not throw
        unset($service);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }
}
