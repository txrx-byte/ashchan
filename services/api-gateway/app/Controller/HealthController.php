<?php
declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

final class HealthController
{
    public function __construct(private HttpResponse $response)
    {
    }

    public function check(): ResponseInterface
    {
        return $this->response->json(['status' => 'ok']);
    }
}
