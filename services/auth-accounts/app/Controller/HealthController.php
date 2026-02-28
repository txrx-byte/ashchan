<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace App\Controller;

use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Lightweight health check endpoint for load balancer and service mesh probes.
 *
 * Returns a simple JSON response to indicate the service is running.
 * This endpoint should remain unauthenticated and fast.
 *
 * @see https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/
 *      For Kubernetes health probe patterns
 */
final class HealthController
{
    /**
     * @param HttpResponse $response HTTP response interface for building JSON responses
     */
    public function __construct(private HttpResponse $response)
    {
    }

    /**
     * GET /health — Returns 200 if the service is accepting requests.
     *
     * This endpoint performs no external dependency checks. It only confirms
     * that the PHP process is running and can handle HTTP requests.
     *
     * For comprehensive health checks including database and Redis connectivity,
     * implement a separate /health/live endpoint.
     *
     * @return ResponseInterface JSON response with status: 'ok'
     */
    public function check(): ResponseInterface
    {
        return $this->response->json(['status' => 'ok']);
    }
}
