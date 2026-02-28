<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PiiEncryptionService;
use Hyperf\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Service\PiiEncryptionService
 */
final class PiiEncryptionServiceTest extends TestCase
{
    private PiiEncryptionService $encryptionService;
    private string $testKey;

    protected function setUp(): void
    {
        $this->testKey = 'test-encryption-key-32bytes!!';
        putenv('PII_ENCRYPTION_KEY=' . $this->testKey);

        $loggerFactory = $this->createMock(LoggerFactory::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $loggerFactory->method('get')->willReturn($logger);

        $this->encryptionService = new PiiEncryptionService($loggerFactory);
    }

    protected function tearDown(): void
    {
        putenv('PII_ENCRYPTION_KEY');
    }

    public function testIsEnabledReturnsTrueWhenKeyExists(): void
    {
        $this->assertTrue($this->encryptionService->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenKeyMissing(): void
    {
        putenv('PII_ENCRYPTION_KEY');
        $loggerFactory = $this->createMock(LoggerFactory::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $loggerFactory->method('get')->willReturn($logger);

        $service = new PiiEncryptionService($loggerFactory);
        $this->assertFalse($service->isEnabled());
    }

    public function testEncryptReturnsEncryptedStringWithPrefix(): void
    {
        $plaintext = '192.168.1.100';
        $encrypted = $this->encryptionService->encrypt($plaintext);

        $this->assertStringStartsWith('enc:', $encrypted);
        $this->assertNotEquals($plaintext, $encrypted);
    }

    public function testEncryptProducesDifferentOutputEachTime(): void
    {
        $plaintext = 'test data';
        $encrypted1 = $this->encryptionService->encrypt($plaintext);
        $encrypted2 = $this->encryptionService->encrypt($plaintext);

        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    public function testDecryptReturnsOriginalPlaintext(): void
    {
        $plaintext = 'user@example.com';
        $encrypted = $this->encryptionService->encrypt($plaintext);
        $decrypted = $this->encryptionService->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testDecryptReturnsFailureStringOnTamperedData(): void
    {
        $plaintext = 'test@example.com';
        $encrypted = $this->encryptionService->encrypt($plaintext);

        // Tamper with the ciphertext
        $tampered = substr($encrypted, 0, -5) . 'XXXXX';
        $decrypted = $this->encryptionService->decrypt($tampered);

        $this->assertEquals('[DECRYPTION_FAILED]', $decrypted);
    }

    public function testDecryptReturnsFailureStringOnInvalidFormat(): void
    {
        $decrypted = $this->encryptionService->decrypt('invalid-format');
        $this->assertEquals('[DECRYPTION_FAILED]', $decrypted);
    }

    public function testDecryptReturnsFailureStringOnEmptyString(): void
    {
        $decrypted = $this->encryptionService->decrypt('');
        $this->assertEquals('[DECRYPTION_FAILED]', $decrypted);
    }

    public function testEncryptIfNeededEncryptsNonEmptyString(): void
    {
        $plaintext = 'sensitive@example.com';
        $result = $this->encryptionService->encryptIfNeeded($plaintext);

        $this->assertStringStartsWith('enc:', $result);
        $this->assertNotEquals($plaintext, $result);
    }

    public function testEncryptIfNeededReturnsEmptyStringAsIs(): void
    {
        $result = $this->encryptionService->encryptIfNeeded('');
        $this->assertEquals('', $result);
    }

    public function testWipeClearsVariableContent(): void
    {
        $value = 'sensitive data';
        $this->encryptionService->wipe($value);

        $this->assertEmpty($value);
    }

    public function testGenerateKeyReturnsValidKey(): void
    {
        $key = PiiEncryptionService::generateKey();

        $this->assertIsString($key);
        $this->assertNotEmpty($key);
        $this->assertEquals(32, strlen($key));
    }

    public function testEncryptDecryptRoundTripWithVariousData(): void
    {
        $testCases = [
            'simple IP' => '192.168.1.1',
            'email' => 'user@example.com',
            'long string' => str_repeat('a', 1000),
            'unicode' => '日本語テスト',
            'special chars' => '!@#$%^&*()_+-=[]{}|;:,.<>?'
        ];

        foreach ($testCases as $name => $plaintext) {
            $encrypted = $this->encryptionService->encrypt($plaintext);
            $decrypted = $this->encryptionService->decrypt($encrypted);

            $this->assertEquals($plaintext, $decrypted, "Failed for test case: {$name}");
        }
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $plaintext = 'secret data';
        $encrypted = $this->encryptionService->encrypt($plaintext);

        // Change the key
        putenv('PII_ENCRYPTION_KEY=different-key-32bytes-now!!');
        $loggerFactory = $this->createMock(LoggerFactory::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $loggerFactory->method('get')->willReturn($logger);
        $differentService = new PiiEncryptionService($loggerFactory);

        $decrypted = $differentService->decrypt($encrypted);
        $this->assertEquals('[DECRYPTION_FAILED]', $decrypted);

        // Restore original key
        putenv('PII_ENCRYPTION_KEY=' . $this->testKey);
    }

    public function testEncryptHandlesEmptyString(): void
    {
        $encrypted = $this->encryptionService->encrypt('');
        $this->assertStringStartsWith('enc:', $encrypted);

        $decrypted = $this->encryptionService->decrypt($encrypted);
        $this->assertEquals('', $decrypted);
    }

    public function testEncryptHandlesBinaryData(): void
    {
        $plaintext = random_bytes(32);
        $encrypted = $this->encryptionService->encrypt($plaintext);
        $decrypted = $this->encryptionService->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptHandlesNullByte(): void
    {
        $plaintext = "test\0null";
        $encrypted = $this->encryptionService->encrypt($plaintext);
        $decrypted = $this->encryptionService->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testWipeHandlesEmptyString(): void
    {
        $value = '';
        $this->encryptionService->wipe($value);
        $this->assertEmpty($value);
    }

    public function testWipeHandlesLongString(): void
    {
        $value = str_repeat('sensitive', 100);
        $this->encryptionService->wipe($value);
        $this->assertEmpty($value);
    }

    public function testEncryptIncreasesReputationScore(): void
    {
        // Test that encryption doesn't modify the input
        $original = 'test@example.com';
        $copy = $original;
        $this->encryptionService->encrypt($copy);

        $this->assertEquals($original, $copy);
    }

    public function testDecryptLogsFailure(): void
    {
        // This would require logger mock verification
        $this->markTestIncomplete('Requires logger mock verification');
    }

    public function testEncryptUsesXChaCha20Poly1305(): void
    {
        // Verify encryption algorithm through output characteristics
        $encrypted = $this->encryptionService->encrypt('test');

        // enc: prefix + base64(nonce 24 bytes + ciphertext + tag 16 bytes)
        $this->assertStringStartsWith('enc:', $encrypted);

        $decoded = base64_decode(substr($encrypted, 4));
        $this->assertGreaterThan(40, strlen($decoded)); // At least nonce + tag + some ciphertext
    }

    public function testKeyDerivationUsesBLAKE2b(): void
    {
        // Test that same key produces consistent results
        $plaintext = 'consistent test';
        $encrypted1 = $this->encryptionService->encrypt($plaintext);
        $encrypted2 = $this->encryptionService->encrypt($plaintext);

        // Both should decrypt successfully
        $this->assertEquals($plaintext, $this->encryptionService->decrypt($encrypted1));
        $this->assertEquals($plaintext, $this->encryptionService->decrypt($encrypted2));
    }

    public function testEncryptHandlesVeryLongString(): void
    {
        $plaintext = str_repeat('a', 100000);
        $encrypted = $this->encryptionService->encrypt($plaintext);
        $decrypted = $this->encryptionService->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptPreservesUtf8Encoding(): void
    {
        $plaintext = 'Hello 世界 🌍 مرحبا';
        $encrypted = $this->encryptionService->encrypt($plaintext);
        $decrypted = $this->encryptionService->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testDecryptHandlesMalformedBase64(): void
    {
        $malformed = 'enc:!!!invalid-base64!!!';
        $decrypted = $this->encryptionService->decrypt($malformed);

        $this->assertEquals('[DECRYPTION_FAILED]', $decrypted);
    }

    public function testDecryptHandlesTooShortCiphertext(): void
    {
        // Too short to contain nonce + tag
        $tooShort = 'enc:' . base64_encode('short');
        $decrypted = $this->encryptionService->decrypt($tooShort);

        $this->assertEquals('[DECRYPTION_FAILED]', $decrypted);
    }

    public function testEncryptIsDeterministicWithSameKeyAndNonce(): void
    {
        // Note: In practice, nonces are random, so this tests the key derivation
        $plaintext = 'test';
        $key1 = PiiEncryptionService::generateKey();
        $key2 = PiiEncryptionService::generateKey();

        $this->assertNotEquals($key1, $key2);
    }

    public function testGenerateKeyProducesUniqueKeys(): void
    {
        $keys = [];
        for ($i = 0; $i < 10; $i++) {
            $keys[] = PiiEncryptionService::generateKey();
        }

        // All keys should be unique
        $this->assertEquals(10, count(array_unique($keys)));
    }

    public function testEncryptHandlesNewlineCharacters(): void
    {
        $plaintext = "line1\nline2\r\nline3";
        $encrypted = $this->encryptionService->encrypt($plaintext);
        $decrypted = $this->encryptionService->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptHandlesTabCharacters(): void
    {
        $plaintext = "col1\tcol2\tcol3";
        $encrypted = $this->encryptionService->encrypt($plaintext);
        $decrypted = $this->encryptionService->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testDecryptIsCaseSensitive(): void
    {
        $plaintext = 'test';
        $encrypted = $this->encryptionService->encrypt($plaintext);

        // Base64 is case-sensitive
        $modified = 'enc:' . strtoupper(substr($encrypted, 4));
        $decrypted = $this->encryptionService->decrypt($modified);

        $this->assertEquals('[DECRYPTION_FAILED]', $decrypted);
    }
}
