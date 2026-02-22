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

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertStringStartsWith('enc:', $encrypted);

        $decrypted = $this->service->decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', $this->service->encrypt(''));
    }

    public function testDecryptEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', $this->service->decrypt(''));
    }

    public function testEncryptProducesUniqueOutputs(): void
    {
        $plaintext = 'test-data';
        $enc1 = $this->service->encrypt($plaintext);
        $enc2 = $this->service->encrypt($plaintext);

        // Different nonces → different ciphertext
        $this->assertNotSame($enc1, $enc2);

        // Both decrypt to the same value
        $this->assertSame($plaintext, $this->service->decrypt($enc1));
        $this->assertSame($plaintext, $this->service->decrypt($enc2));
    }

    public function testDecryptReturnsPlaintextWhenNoPrefix(): void
    {
        // Legacy data without 'enc:' prefix should be returned as-is
        $this->assertSame('192.168.1.1', $this->service->decrypt('192.168.1.1'));
    }

    public function testDecryptInvalidBase64(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('invalid base64'));

        $result = $this->service->decrypt('enc:!!!not-valid-base64!!!');
        $this->assertSame('[DECRYPTION_FAILED]', $result);
    }

    public function testDecryptTruncatedData(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('data too short'));

        $result = $this->service->decrypt('enc:' . base64_encode('short'));
        $this->assertSame('[DECRYPTION_FAILED]', $result);
    }

    /* ──────────────────────────────────────
     * encryptIfNeeded()
     * ────────────────────────────────────── */

    public function testEncryptIfNeededSkipsAlreadyEncrypted(): void
    {
        $encrypted = $this->service->encrypt('test');
        $result = $this->service->encryptIfNeeded($encrypted);
        $this->assertSame($encrypted, $result);
    }

    public function testEncryptIfNeededEncryptsPlaintext(): void
    {
        $result = $this->service->encryptIfNeeded('plaintext-data');
        $this->assertStringStartsWith('enc:', $result);
    }

    /* ──────────────────────────────────────
     * Disabled encryption (no key)
     * ────────────────────────────────────── */

    public function testEncryptReturnsPlaintextWhenDisabled(): void
    {
        putenv('PII_ENCRYPTION_KEY=');
        $service = new PiiEncryptionService($this->loggerFactory);

        $this->assertSame('192.168.1.1', $service->encrypt('192.168.1.1'));
    }

    public function testDecryptReturnsPlaintextWhenDisabled(): void
    {
        putenv('PII_ENCRYPTION_KEY=');
        $service = new PiiEncryptionService($this->loggerFactory);

        $this->assertSame('some-data', $service->decrypt('some-data'));
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
        $this->assertNotSame($key1, $key2);
    }

    /* ──────────────────────────────────────
     * wipe()
     * ────────────────────────────────────── */

    public function testWipeZeroesString(): void
    {
        $secret = 'sensitive-data-12345';
        $originalLen = strlen($secret);
        $this->service->wipe($secret);

        // After wipe, the string should be zeroed or empty
        $this->assertNotSame('sensitive-data-12345', $secret);
        // sodium_memzero sets the string to empty or zero bytes
    }
}
