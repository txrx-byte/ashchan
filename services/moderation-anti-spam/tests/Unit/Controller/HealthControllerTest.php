<?php
declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use Hyperf\HttpServer\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Controller\HealthController
 */
final class HealthControllerTest extends TestCase
{
    private HealthController $controller;

    protected function setUp(): void
    {
        $this->controller = new HealthController();
    }

    public function testCheckReturnsOkStatus(): void
    {
        $response = $this->controller->check();

        $this->assertInstanceOf(Response::class);
        
        $body = $response->getBody();
        $this->assertIsString($body);
        
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('ok', $data['status']);
    }

    public function testCheckReturnsJsonContentType(): void
    {
        $response = $this->controller->check();
        
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('content-type', $headers);
        $this->assertStringContainsString('application/json', $headers['content-type'][0]);
    }

    public function testCheckReturns200StatusCode(): void
    {
        $response = $this->controller->check();
        $this->assertEquals(200, $response->getStatusCode());
    }
}
