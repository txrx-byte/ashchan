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

use App\Service\AltchaService;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * ALTCHA proof-of-work captcha challenge endpoint.
 *
 * GET /api/v1/altcha/challenge — returns a challenge JSON for the widget.
 */
final class AltchaController
{
    public function __construct(
        private HttpResponse $response,
        private AltchaService $altcha,
    ) {}

    /**
     * Generate a new ALTCHA challenge.
     *
     * Returns JSON: {algorithm, challenge, maxnumber, salt, signature}
     */
    public function challenge(): ResponseInterface
    {
        if (!$this->altcha->isEnabled()) {
            return $this->response->json(['error' => 'ALTCHA captcha is disabled'])->withStatus(404);
        }

        $challenge = $this->altcha->createChallenge();

        return $this->response->json($challenge)
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }
}
