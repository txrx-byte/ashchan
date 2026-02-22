<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\HealthController;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit tests for HealthController.
 */
final class HealthControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testCheckReturnsOkJson(): void
    {
        $httpResponse = Mockery::mock(HttpResponse::class);
        $response = Mockery::mock(ResponseInterface::class);

        $httpResponse->shouldReceive('json')
            ->with(['status' => 'ok'])
            ->once()
            ->andReturn($response);

        $controller = new HealthController($httpResponse);
        $result = $controller->check();

        $this->assertSame($response, $result);
    }
}
