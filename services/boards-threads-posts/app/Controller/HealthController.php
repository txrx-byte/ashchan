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
 * Health check controller for service monitoring and load balancer probes.
 *
 * Provides a simple endpoint that returns HTTP 200 with a JSON status response
 * when the service is running and able to handle requests.
 *
 * This endpoint is used by:
 * - Kubernetes liveness/readiness probes
 * - Load balancer health checks
 * - Monitoring systems (Prometheus, Datadog, etc.)
 * - Manual service verification
 *
 * @see docs/ARCHITECTURE.md §Monitoring
 */
final class HealthController
{
    /**
     * @param HttpResponse $response HTTP response builder
     */
    public function __construct(private HttpResponse $response)
    {
    }

    /**
     * Perform a basic health check.
     *
     * Returns a simple status response indicating the service is running.
     * This endpoint does not perform deep health checks (database connectivity,
     * cache availability, etc.) - it only confirms the HTTP server is responsive.
     *
     * For comprehensive health checks, use the /health/deep endpoint (if available)
     * which checks database and cache connectivity.
     *
     * @return ResponseInterface JSON response with status
     *
     * @example GET /health
     * @example Response: {"status": "ok"}
     *
     * @see GET /health
     */
    public function check(): ResponseInterface
    {
        return $this->response->json(['status' => 'ok']);
    }
}
