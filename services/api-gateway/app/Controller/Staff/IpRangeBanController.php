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


namespace App\Controller\Staff;

use App\Service\ViewService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/staff/iprangebans')]
final class IpRangeBanController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $rangeBans = Db::table('ip_range_bans')
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
        $rangeBans = \App\Helper\PgArrayParser::parseCollection($rangeBans, 'boards');
        $html = $this->viewService->render('staff/iprangebans/index', ['rangeBans' => $rangeBans]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $html = $this->viewService->render('staff/iprangebans/create', [
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (empty($body['range'])) {
            $errors[] = 'IP range is required';
        } elseif (!$this->validateCidr((string) $body['range'])) {
            $errors[] = 'Invalid CIDR notation (e.g., 192.168.1.0/24)';
        }
        if (empty($body['reason'])) {
            $errors[] = 'Reason is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        [$rangeStart, $rangeEnd] = $this->parseCidr((string) $body['range']);
        $user = \Hyperf\Context\Context::get('staff_user');

        Db::table('ip_range_bans')->insert([
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'reason' => trim((string) ($body['reason'] ?? '')),
            'boards' => \App\Helper\PgArrayParser::toPgArray((array) ($body['boards'] ?? [])),
            'is_active' => isset($body['is_active']),
            'expires_at' => !empty($body['expires_at']) ? $body['expires_at'] : null,
            'created_by' => $user['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/iprangebans']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $rangeBan = Db::table('ip_range_bans')->where('id', $id)->first();
        if (!$rangeBan) {
            return $this->response->json(['error' => 'Not found'], 404);
        }
        $rangeBan->boards = \App\Helper\PgArrayParser::parse($rangeBan->boards ?? null);

        $html = $this->viewService->render('staff/iprangebans/edit', [
            'rangeBan' => $rangeBan,
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        $rangeBan = Db::table('ip_range_bans')->where('id', $id)->first();
        if (!$rangeBan) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        /** @var array<string, mixed> $body */

        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (empty($body['range'])) {
            $errors[] = 'IP range is required';
        } elseif (!$this->validateCidr((string) $body['range'])) {
            $errors[] = 'Invalid CIDR notation (e.g., 192.168.1.0/24)';
        }
        if (empty($body['reason'])) {
            $errors[] = 'Reason is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        [$rangeStart, $rangeEnd] = $this->parseCidr((string) $body['range']);

        Db::table('ip_range_bans')->where('id', $id)->update([
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'reason' => trim((string) ($body['reason'] ?? '')),
            'boards' => \App\Helper\PgArrayParser::toPgArray((array) ($body['boards'] ?? [])),
            'is_active' => isset($body['is_active']),
            'expires_at' => !empty($body['expires_at']) ? $body['expires_at'] : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/iprangebans']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $rangeBan = Db::table('ip_range_bans')->where('id', $id)->first();
        if (!$rangeBan) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        Db::table('ip_range_bans')->where('id', $id)->delete();
        return $this->response->json(['success' => true]);
    }

    #[PostMapping(path: 'test')]
    public function test(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $testIp = (string) ($body['ip'] ?? '');

        if (empty($testIp) || !$this->validateIp($testIp)) {
            return $this->response->json(['valid' => false, 'error' => 'Invalid IP address']);
        }

        $matchingBans = Db::table('ip_range_bans')
            ->where('is_active', true)
            ->whereRaw('?::inet >= range_start AND ?::inet <= range_end', [$testIp, $testIp])
            ->get();

        return $this->response->json([
            'valid' => true,
            'ip' => $testIp,
            'matches' => count($matchingBans),
            'bans' => $matchingBans,
        ]);
    }

    private function validateCidr(string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2) {
            return false;
        }
        $ip = $parts[0];
        $mask = (int)$parts[1];
        
        if (!$this->validateIp($ip)) {
            return false;
        }
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        return true;
    }

    private function validateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return array{string, string}
     */
    private function parseCidr(string $cidr): array
    {
        [$ip, $mask] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return ['0.0.0.0', '0.0.0.0'];
        }
        $maskLong = ~((1 << (32 - (int)$mask)) - 1);
        $start = (string) long2ip($ipLong & $maskLong);
        $end = (string) long2ip($ipLong | ~$maskLong);
        return [$start, $end];
    }
}
