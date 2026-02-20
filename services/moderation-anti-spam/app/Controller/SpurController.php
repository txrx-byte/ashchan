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

use App\Service\SpurService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Internal API for Spur IP intelligence lookups.
 *
 * Exposes endpoints for checking IP context and evaluating
 * risk scores using the Spur Context API.
 */
#[Controller(prefix: '/internal/spur')]
final class SpurController
{
    public function __construct(
        private SpurService $spurService,
        private HttpResponse $response
    ) {}

    /**
     * Look up full IP context from Spur.
     *
     * POST /internal/spur/lookup
     * Body: { "ip": "1.2.3.4" }
     */
    #[PostMapping(path: 'lookup')]
    public function lookup(RequestInterface $request): ResponseInterface
    {
        $ip = (string) $request->input('ip', '');

        if (empty($ip)) {
            return $this->response->json(['error' => 'IP address required'], 400);
        }

        if (!$this->spurService->isEnabled()) {
            return $this->response->json(['error' => 'Spur integration is not enabled'], 503);
        }

        $context = $this->spurService->lookup($ip);

        if ($context === null) {
            return $this->response->json(['error' => 'Lookup failed or IP is not public'], 422);
        }

        // Strip the raw field for external responses
        unset($context['raw']);

        return $this->response->json([
            'ip' => $context['ip'],
            'risks' => $context['risks'],
            'tunnels' => $context['tunnels'],
            'infrastructure' => $context['infrastructure'],
            'location' => $context['location'],
            'organization' => $context['organization'],
            'as' => $context['as'],
            'client' => $context['client'],
        ]);
    }

    /**
     * Evaluate an IP's risk score using Spur data.
     *
     * POST /internal/spur/evaluate
     * Body: { "ip": "1.2.3.4" }
     */
    #[PostMapping(path: 'evaluate')]
    public function evaluate(RequestInterface $request): ResponseInterface
    {
        $ip = (string) $request->input('ip', '');

        if (empty($ip)) {
            return $this->response->json(['error' => 'IP address required'], 400);
        }

        if (!$this->spurService->isEnabled()) {
            return $this->response->json(['error' => 'Spur integration is not enabled'], 503);
        }

        $evaluation = $this->spurService->evaluate($ip);

        return $this->response->json([
            'score' => $evaluation['score'],
            'reasons' => $evaluation['reasons'],
            'block' => $evaluation['block'],
        ]);
    }

    /**
     * Check if Spur integration is currently enabled.
     *
     * GET /internal/spur/status
     */
    #[GetMapping(path: 'status')]
    public function status(): ResponseInterface
    {
        return $this->response->json([
            'enabled' => $this->spurService->isEnabled(),
        ]);
    }
}
