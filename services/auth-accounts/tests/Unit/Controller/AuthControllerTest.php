<?php
declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AuthController;
use App\Model\DeletionRequest;
use App\Model\User;
use App\Service\AuthService;
use App\Service\PiiEncryptionService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \App\Controller\AuthController
 */
final class AuthControllerTest extends TestCase
{
    private AuthService&MockObject $authService;
    private PiiEncryptionService&MockObject $piiEncryption;
    private HttpResponse&MockObject $httpResponse;
    private RequestInterface&MockObject $request;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthService::class);
        $this->piiEncryption = $this->createMock(PiiEncryptionService::class);

        // Initialize the typed property so __destruct() doesn't crash
        $ref = new \ReflectionProperty(PiiEncryptionService::class, 'encryptionKey');
        $ref->setAccessible(true);
        $ref->setValue($this->piiEncryption, '');

        $this->httpResponse = $this->createMock(HttpResponse::class);
        $this->request = $this->createMock(RequestInterface::class);

        $this->controller = new AuthController(
            $this->authService,
            $this->piiEncryption,
            $this->httpResponse,
        );
    }

    /**
     * Helper: configure httpResponse->json() to return a mock PSR-7 response.
     *
     * @param array<string, mixed> $expectedPayload Exact JSON array expected
     * @param int                  $expectedStatus  HTTP status code
     * @return ResponseInterface&MockObject
     */
    private function expectJson(array $expectedPayload, int $expectedStatus = 200): ResponseInterface&MockObject
    {
        $mockResponse = $this->createMock(ResponseInterface::class);

        if ($expectedStatus !== 200) {
            $statusResponse = $this->createMock(ResponseInterface::class);
            $mockResponse->expects($this->once())
                ->method('withStatus')
                ->with($expectedStatus)
                ->willReturn($statusResponse);

            $this->httpResponse->expects($this->once())
                ->method('json')
                ->with($expectedPayload)
                ->willReturn($mockResponse);

            return $statusResponse;
        }

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($expectedPayload)
            ->willReturn($mockResponse);

        return $mockResponse;
    }

    /**
     * Helper: create a mock Redis that supports eval (magic method via __call).
     */
    private function createRedisMock(): MockObject
    {
        return $this->getMockBuilder(\Hyperf\Redis\Redis::class)
            ->disableOriginalConstructor()
            ->addMethods(['eval'])
            ->getMock();
    }

    /* ──────────────────────────────────────
     * login()
     * ────────────────────────────────────── */

    public function testLoginMissingUsername(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', ''],
            ['password', '', 'pass123'],
        ]);

        $expected = $this->expectJson(['error' => 'Username and password required'], 400);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginMissingPassword(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', 'admin'],
            ['password', '', ''],
        ]);

        $expected = $this->expectJson(['error' => 'Username and password required'], 400);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginUsernameTooLong(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', str_repeat('a', 65)],
            ['password', '', 'password123'],
        ]);

        $expected = $this->expectJson(['error' => 'Username too long'], 400);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginPasswordTooLong(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', 'admin'],
            ['password', '', str_repeat('p', 257)],
        ]);

        $expected = $this->expectJson(['error' => 'Password too long'], 400);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginInvalidCredentials(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', 'admin'],
            ['password', '', 'wrongpass'],
        ]);
        $this->request->method('server')->willReturn('127.0.0.1');
        $this->request->method('getHeaderLine')->willReturn('TestAgent/1.0');

        // Bypass rate limiting by returning a mock Redis that allows the request
        $mockRedis = $this->createRedisMock();
        $mockRedis->method('eval')->willReturn(0);
        $this->authService->method('getRedis')->willReturn($mockRedis);
        $this->authService->method('login')->willReturn(null);

        $expected = $this->expectJson(['error' => 'Invalid credentials'], 401);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginSuccessful(): void
    {
        $loginResult = [
            'token' => 'abc123',
            'expires_in' => 604800,
            'user' => ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'],
        ];

        $this->request->method('input')->willReturnMap([
            ['username', '', 'admin'],
            ['password', '', 'correct-password'],
        ]);
        $this->request->method('server')->willReturn('127.0.0.1');
        $this->request->method('getHeaderLine')->willReturn('TestAgent/1.0');

        $mockRedis = $this->createRedisMock();
        $mockRedis->method('eval')->willReturn(0);
        $this->authService->method('getRedis')->willReturn($mockRedis);
        $this->authService->method('login')->willReturn($loginResult);

        $expected = $this->expectJson($loginResult, 200);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginBannedUserReturnsGenericError(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', 'banneduser'],
            ['password', '', 'password123'],
        ]);
        $this->request->method('server')->willReturn('10.0.0.1');
        $this->request->method('getHeaderLine')->willReturn('');

        $mockRedis = $this->createRedisMock();
        $mockRedis->method('eval')->willReturn(0);
        $this->authService->method('getRedis')->willReturn($mockRedis);
        $this->authService->method('login')
            ->willThrowException(new \RuntimeException('Account is banned'));

        $expected = $this->expectJson(['error' => 'Invalid credentials'], 401);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginNonStringUsernameReturns400(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', 123],    // non-string
            ['password', '', 'pass'],
        ]);

        $expected = $this->expectJson(['error' => 'Username and password required'], 400);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * logout()
     * ────────────────────────────────────── */

    public function testLogoutWithBearerToken(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer test-token-123');

        $this->authService->expects($this->once())
            ->method('logout')
            ->with('test-token-123');

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->logout($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLogoutWithoutTokenStillReturnsOk(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');
        $this->request->method('getCookieParams')
            ->willReturn([]);

        $this->authService->expects($this->never())->method('logout');

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->logout($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLogoutWithCookieToken(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');
        $this->request->method('getCookieParams')
            ->willReturn(['session_token' => 'cookie-token-abc']);

        $this->authService->expects($this->once())
            ->method('logout')
            ->with('cookie-token-abc');

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->logout($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * validate()
     * ────────────────────────────────────── */

    public function testValidateWithNoToken(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');
        $this->request->method('getCookieParams')
            ->willReturn([]);

        $expected = $this->expectJson(['error' => 'No token'], 401);
        $result = $this->controller->validate($this->request);
        $this->assertSame($expected, $result);
    }

    public function testValidateWithInvalidToken(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer invalid-token');
        $this->authService->method('validateToken')->willReturn(null);

        $expected = $this->expectJson(['error' => 'Invalid or expired token'], 401);
        $result = $this->controller->validate($this->request);
        $this->assertSame($expected, $result);
    }

    public function testValidateWithValidToken(): void
    {
        $userData = ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];

        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer valid-token');
        $this->authService->method('validateToken')
            ->with('valid-token')
            ->willReturn($userData);

        $expected = $this->expectJson(['user' => $userData], 200);
        $result = $this->controller->validate($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * register()
     * ────────────────────────────────────── */

    public function testRegisterWithoutTokenReturns401(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');
        $this->request->method('getCookieParams')
            ->willReturn([]);

        $expected = $this->expectJson(['error' => 'Authentication required'], 401);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterNonAdminReturns403(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer mod-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 2, 'username' => 'mod', 'role' => 'mod']);

        $expected = $this->expectJson(['error' => 'Admin only'], 403);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterInvalidTokenReturns403(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer expired-token');
        $this->authService->method('validateToken')
            ->willReturn(null);

        $expected = $this->expectJson(['error' => 'Admin only'], 403);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterMissingUsernameReturns400(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['username', '', ''],
            ['password', '', 'strongpassword1'],
            ['email', '', ''],
            ['role', 'user', 'user'],
        ]);

        $expected = $this->expectJson(['error' => 'Username and password required'], 400);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterUsernameTooLongReturns400(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['username', '', str_repeat('a', 65)],
            ['password', '', 'strongpassword1'],
            ['email', '', ''],
            ['role', 'user', 'user'],
        ]);

        $expected = $this->expectJson(['error' => 'Username too long'], 400);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterInvalidUsernameCharsReturns400(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['username', '', 'invalid user!'],
            ['password', '', 'strongpassword1'],
            ['email', '', ''],
            ['role', 'user', 'user'],
        ]);

        $expected = $this->expectJson(
            ['error' => 'Username may only contain letters, numbers, hyphens, and underscores'],
            400
        );
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterPasswordTooShortReturns400(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['username', '', 'newuser'],
            ['password', '', 'short'],
            ['email', '', ''],
            ['role', 'user', 'user'],
        ]);

        $expected = $this->expectJson(['error' => 'Password must be at least 12 characters'], 400);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterPasswordTooLongReturns400(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['username', '', 'newuser'],
            ['password', '', str_repeat('p', 257)],
            ['email', '', ''],
            ['role', 'user', 'user'],
        ]);

        $expected = $this->expectJson(['error' => 'Password too long'], 400);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterInvalidEmailReturns400(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['username', '', 'newuser'],
            ['password', '', 'strongpassword1'],
            ['email', '', 'not-an-email'],
            ['role', 'user', 'user'],
        ]);

        $expected = $this->expectJson(['error' => 'Invalid email address'], 400);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterInvalidRoleReturns400(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['username', '', 'newuser'],
            ['password', '', 'strongpassword1'],
            ['email', '', ''],
            ['role', 'user', 'superadmin'],
        ]);

        $expected = $this->expectJson(['error' => 'Invalid role'], 400);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    public function testRegisterDuplicateUsernameReturns409(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['username', '', 'existing_user'],
            ['password', '', 'strongpassword1'],
            ['email', '', ''],
            ['role', 'user', 'user'],
        ]);

        $this->authService->method('register')
            ->willThrowException(new \RuntimeException('Username already taken'));

        $expected = $this->expectJson(['error' => 'Username already taken'], 409);
        $result = $this->controller->register($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * ban()
     * ────────────────────────────────────── */

    public function testBanWithoutTokenReturns401(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');
        $this->request->method('getCookieParams')
            ->willReturn([]);

        $expected = $this->expectJson(['error' => 'Authentication required'], 401);
        $result = $this->controller->ban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testBanNonStaffReturns403(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer user-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 5, 'username' => 'regular', 'role' => 'user']);

        $expected = $this->expectJson(['error' => 'Insufficient privileges'], 403);
        $result = $this->controller->ban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testBanNoTargetReturns400(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['user_id', 0, 0],
            ['reason', '', ''],
            ['expires_at', null, null],
            ['ip_hash', '', ''],
            ['duration', 86400, 86400],
        ]);

        $expected = $this->expectJson(['error' => 'Must specify user_id or ip_hash'], 400);
        $result = $this->controller->ban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testBanUserById(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer mod-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 2, 'username' => 'mod', 'role' => 'mod']);

        $this->request->method('input')->willReturnMap([
            ['user_id', 0, 42],
            ['reason', '', 'Spamming'],
            ['expires_at', null, '2026-12-31T23:59:59Z'],
            ['ip_hash', '', ''],
            ['duration', 86400, 86400],
        ]);

        $this->authService->expects($this->once())
            ->method('banUser')
            ->with(42, 'Spamming', '2026-12-31T23:59:59Z');

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->ban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testBanByIpHash(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['user_id', 0, 0],
            ['reason', '', 'Abuse'],
            ['expires_at', null, null],
            ['ip_hash', '', 'abc123hash'],
            ['duration', 86400, 3600],
        ]);

        $this->authService->expects($this->once())
            ->method('banIp')
            ->with('abc123hash', 'Abuse', 3600);

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->ban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testBanBothUserAndIp(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')->willReturnMap([
            ['user_id', 0, 42],
            ['reason', '', 'Both'],
            ['expires_at', null, null],
            ['ip_hash', '', 'hash456'],
            ['duration', 86400, 86400],
        ]);

        $this->authService->expects($this->once())->method('banUser');
        $this->authService->expects($this->once())->method('banIp');

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->ban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testBanManagerRoleIsAllowed(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer manager-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 3, 'username' => 'manager', 'role' => 'manager']);

        $this->request->method('input')->willReturnMap([
            ['user_id', 0, 10],
            ['reason', '', 'test'],
            ['expires_at', null, null],
            ['ip_hash', '', ''],
            ['duration', 86400, 86400],
        ]);

        $this->authService->expects($this->once())->method('banUser');

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->ban($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * unban()
     * ────────────────────────────────────── */

    public function testUnbanWithoutTokenReturns401(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');
        $this->request->method('getCookieParams')
            ->willReturn([]);

        $expected = $this->expectJson(['error' => 'Authentication required'], 401);
        $result = $this->controller->unban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testUnbanNonStaffReturns403(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer user-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 5, 'username' => 'user', 'role' => 'janitor']);

        $expected = $this->expectJson(['error' => 'Insufficient privileges'], 403);
        $result = $this->controller->unban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testUnbanInvalidUserId(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')
            ->with('user_id')
            ->willReturn(0);

        $expected = $this->expectJson(['error' => 'Invalid user ID'], 400);
        $result = $this->controller->unban($this->request);
        $this->assertSame($expected, $result);
    }

    public function testUnbanSuccess(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer admin-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $this->request->method('input')
            ->with('user_id')
            ->willReturn(42);

        $this->authService->expects($this->once())
            ->method('unbanUser')
            ->with(42);

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->unban($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * recordConsent()
     * ────────────────────────────────────── */

    public function testRecordConsentSuccess(): void
    {
        $this->request->method('server')
            ->with('remote_addr', '')
            ->willReturn('192.168.1.1');
        $this->request->method('input')->willReturnMap([
            ['consented', false, true],
            ['policy_version', '1.0', '2.0'],
        ]);

        $this->piiEncryption->method('encrypt')
            ->with('192.168.1.1')
            ->willReturn('enc:encrypted-ip');

        $this->authService->method('hashIp')
            ->with('192.168.1.1')
            ->willReturn('hashed-ip');

        // Should record consent twice: privacy_policy + age_verification
        $this->authService->expects($this->exactly(2))
            ->method('recordConsent')
            ->willReturnCallback(function (string $ipHash, string $ipEnc, ?int $userId, string $type, string $version, bool $consented) {
                $this->assertSame('hashed-ip', $ipHash);
                $this->assertSame('enc:encrypted-ip', $ipEnc);
                $this->assertNull($userId);
                $this->assertSame('2.0', $version);
                $this->assertTrue($consented);
                $this->assertContains($type, ['privacy_policy', 'age_verification']);
                return $this->createMock(\App\Model\Consent::class);
            });

        $expected = $this->expectJson(['status' => 'ok'], 200);
        $result = $this->controller->recordConsent($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * dataRequest()
     * ────────────────────────────────────── */

    public function testDataRequestWithoutTokenReturns401(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');
        $this->request->method('getCookieParams')
            ->willReturn([]);

        $expected = $this->expectJson(['error' => 'Authentication required'], 401);
        $result = $this->controller->dataRequest($this->request);
        $this->assertSame($expected, $result);
    }

    public function testDataRequestInvalidTokenReturns401(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer bad-token');
        $this->authService->method('validateToken')->willReturn(null);

        $expected = $this->expectJson(['error' => 'Invalid token'], 401);
        $result = $this->controller->dataRequest($this->request);
        $this->assertSame($expected, $result);
    }

    public function testDataExportRequest(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer user-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 10, 'username' => 'user', 'role' => 'user']);

        $this->request->method('input')
            ->with('type', 'data_export')
            ->willReturn('data_export');

        $mockReq = $this->createMock(DeletionRequest::class);
        $mockReq->method('toArray')
            ->willReturn(['id' => 1, 'user_id' => 10, 'status' => 'pending', 'request_type' => 'data_export']);

        $this->authService->expects($this->once())
            ->method('requestDataExport')
            ->with(10)
            ->willReturn($mockReq);

        $expected = $this->expectJson([
            'request' => ['id' => 1, 'user_id' => 10, 'status' => 'pending', 'request_type' => 'data_export'],
        ], 200);

        $result = $this->controller->dataRequest($this->request);
        $this->assertSame($expected, $result);
    }

    public function testDataDeletionRequest(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer user-token');
        $this->authService->method('validateToken')
            ->willReturn(['user_id' => 10, 'username' => 'user', 'role' => 'user']);

        $this->request->method('input')
            ->with('type', 'data_export')
            ->willReturn('data_deletion');

        $mockReq = $this->createMock(DeletionRequest::class);
        $mockReq->method('toArray')
            ->willReturn(['id' => 2, 'user_id' => 10, 'status' => 'pending', 'request_type' => 'data_deletion']);

        $this->authService->expects($this->once())
            ->method('requestDataDeletion')
            ->with(10)
            ->willReturn($mockReq);

        $expected = $this->expectJson([
            'request' => ['id' => 2, 'user_id' => 10, 'status' => 'pending', 'request_type' => 'data_deletion'],
        ], 200);

        $result = $this->controller->dataRequest($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * Token extraction edge cases
     * ────────────────────────────────────── */

    public function testBearerTokenWithTrailingSpacesIsTrimmed(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer   my-token   ');
        $this->authService->method('validateToken')
            ->with('my-token')
            ->willReturn(['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);

        $expected = $this->expectJson(['user' => ['user_id' => 1, 'username' => 'admin', 'role' => 'admin']], 200);
        $result = $this->controller->validate($this->request);
        $this->assertSame($expected, $result);
    }

    public function testEmptyBearerTokenTreatedAsNoToken(): void
    {
        $this->request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer ');
        $this->request->method('getCookieParams')
            ->willReturn([]);

        $expected = $this->expectJson(['error' => 'No token'], 401);
        $result = $this->controller->validate($this->request);
        $this->assertSame($expected, $result);
    }

    /* ──────────────────────────────────────
     * Rate limiting edge case
     * ────────────────────────────────────── */

    public function testLoginWithEmptyIpBypassesRateLimiting(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', 'admin'],
            ['password', '', 'pass123456'],
        ]);
        // Empty string IP
        $this->request->method('server')
            ->with('remote_addr', '')
            ->willReturn('');
        $this->request->method('getHeaderLine')
            ->with('User-Agent')
            ->willReturn('agent');

        $this->authService->method('login')->willReturn(null);

        // Rate limiting should be skipped for empty IPs
        $expected = $this->expectJson(['error' => 'Invalid credentials'], 401);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginRateLimitedReturns429(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', 'admin'],
            ['password', '', 'pass123456'],
        ]);
        $this->request->method('server')
            ->with('remote_addr', '')
            ->willReturn('10.0.0.1');

        $mockRedis = $this->createRedisMock();
        $mockRedis->method('eval')->willReturn(1); // Rate limited
        $this->authService->method('getRedis')->willReturn($mockRedis);

        $expected = $this->expectJson(['error' => 'Too many login attempts, try again later'], 429);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }

    public function testLoginRedisFailFailsOpen(): void
    {
        $this->request->method('input')->willReturnMap([
            ['username', '', 'admin'],
            ['password', '', 'pass123456'],
        ]);
        $this->request->method('server')
            ->with('remote_addr', '')
            ->willReturn('10.0.0.1');
        $this->request->method('getHeaderLine')
            ->with('User-Agent')
            ->willReturn('agent');

        $mockRedis = $this->createRedisMock();
        $mockRedis->method('eval')
            ->willThrowException(new \RuntimeException('Redis down'));
        $this->authService->method('getRedis')->willReturn($mockRedis);

        // Login should proceed (fail-open) when Redis is down
        $this->authService->method('login')->willReturn(null);

        $expected = $this->expectJson(['error' => 'Invalid credentials'], 401);
        $result = $this->controller->login($this->request);
        $this->assertSame($expected, $result);
    }
}
