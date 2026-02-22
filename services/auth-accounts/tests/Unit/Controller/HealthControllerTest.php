<?php
declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \App\Controller\HealthController
 */
final class HealthControllerTest extends TestCase
{
    public function testCheckReturnsOkStatus(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);

        $httpResponse = $this->createMock(HttpResponse::class);
        $httpResponse->expects($this->once())
            ->method('json')
            ->with(['status' => 'ok'])
            ->willReturn($mockResponse);

        $controller = new HealthController($httpResponse);
        $result = $controller->check();

        $this->assertSame($mockResponse, $result);
    }
}
