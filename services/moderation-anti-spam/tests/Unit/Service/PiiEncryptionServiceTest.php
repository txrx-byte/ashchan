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
        $plaintext = 'sensitive@example.com';
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
        $plaintext = 'user@example.com';
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
}
