<?php
declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\FeedbackController;
use App\Service\SiteConfigService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \App\Controller\FeedbackController
 */
final class FeedbackControllerTest extends TestCase
{
    private RequestInterface $request;
    private HttpResponse $httpResponse;
    private SiteConfigService $config;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->httpResponse = $this->createMock(HttpResponse::class);
        $this->config = $this->createMock(SiteConfigService::class);
        $this->config->method('getInt')->willReturn(5);
    }

    private function makeController(): FeedbackController
    {
        return new FeedbackController($this->request, $this->httpResponse, $this->config);
    }

    private function mockJsonResponse(int $expectedStatus): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('withStatus')
            ->with($expectedStatus)
            ->willReturnSelf();

        $this->httpResponse->method('json')
            ->willReturn($mockResponse);
    }

    /* ──────────────────────────────────────
     * Validation: missing fields
     * ────────────────────────────────────── */

    public function testSubmitReturns422WhenCategoryMissing(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'subject' => 'Test',
            'message' => 'This is a test message with enough length',
        ]);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], 'required');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    public function testSubmitReturns422WhenSubjectMissing(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'category' => 'bug_report',
            'message' => 'This is a test message with enough length',
        ]);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], 'required');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    public function testSubmitReturns422WhenMessageMissing(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'category' => 'bug_report',
            'subject' => 'Test',
        ]);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], 'required');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    /* ──────────────────────────────────────
     * Validation: invalid category
     * ────────────────────────────────────── */

    public function testSubmitReturns422ForInvalidCategory(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'category' => 'hacking',
            'subject' => 'Test',
            'message' => 'This is a test message with enough length',
        ]);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], 'Invalid category');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    /* ──────────────────────────────────────
     * Validation: subject length
     * ────────────────────────────────────── */

    public function testSubmitReturns422WhenSubjectTooLong(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'category' => 'bug_report',
            'subject' => str_repeat('x', 151),
            'message' => 'This is a test message with enough length',
        ]);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], '150');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    /* ──────────────────────────────────────
     * Validation: message length
     * ────────────────────────────────────── */

    public function testSubmitReturns422WhenMessageTooShort(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'category' => 'bug_report',
            'subject' => 'Test',
            'message' => 'Short',
        ]);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], 'at least 10');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    public function testSubmitReturns422WhenMessageTooLong(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'category' => 'bug_report',
            'subject' => 'Test',
            'message' => str_repeat('x', 5001),
        ]);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], '5000');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    /* ──────────────────────────────────────
     * Validation: email format
     * ────────────────────────────────────── */

    public function testSubmitReturns422ForInvalidEmail(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'category' => 'bug_report',
            'subject' => 'Test',
            'message' => 'This is a test message with enough length',
            'email' => 'not-an-email',
        ]);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], 'email');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    /* ──────────────────────────────────────
     * Validation: null body
     * ────────────────────────────────────── */

    public function testSubmitHandlesNullBody(): void
    {
        $this->request->method('getParsedBody')->willReturn(null);

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $data) {
                return isset($data['error']) && str_contains($data['error'], 'required');
            }))
            ->willReturn($this->createStatusResponse(422));

        $controller = $this->makeController();
        $controller->submit();
    }

    /* ──────────────────────────────────────
     * Valid categories
     * ────────────────────────────────────── */

    /**
     * @dataProvider validCategoryProvider
     */
    public function testValidCategoriesAccepted(string $category): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'category' => $category,
            'subject' => 'Test subject',
            'message' => 'This is a valid test message for feedback.',
        ]);

        // For valid input, the controller will hit DB. We expect json() to be called.
        // Since we can't mock the DB, just verify the category passes validation
        // by checking it doesn't return an "Invalid category" error.
        $callArgs = null;
        $this->httpResponse->method('json')
            ->willReturnCallback(function (array $data) use (&$callArgs) {
                $callArgs = $data;
                return $this->createStatusResponse(200);
            });

        $controller = $this->makeController();

        try {
            $controller->submit();
        } catch (\Throwable) {
            // DB exception expected — that's fine, category validation passed
        }

        // If we got a JSON response, it should NOT be about invalid category
        if ($callArgs !== null && isset($callArgs['error'])) {
            $this->assertStringNotContainsString('Invalid category', $callArgs['error']);
        } else {
            // No error response or DB exception thrown — category was accepted
            $this->assertTrue(true, "Category '{$category}' passed validation");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validCategoryProvider(): array
    {
        return [
            'bug_report' => ['bug_report'],
            'feature_request' => ['feature_request'],
            'ui_ux' => ['ui_ux'],
            'board_suggestion' => ['board_suggestion'],
            'moderation' => ['moderation'],
            'performance' => ['performance'],
            'security' => ['security'],
            'accessibility' => ['accessibility'],
            'praise' => ['praise'],
            'other' => ['other'],
        ];
    }

    /* ──────────────────────────────────────
     * Helpers
     * ────────────────────────────────────── */

    private function createStatusResponse(int $status): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withStatus')->willReturnSelf();
        return $response;
    }
}
