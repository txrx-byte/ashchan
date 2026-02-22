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


namespace App\Service;

use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Service to interact with Spur Context API for IP intelligence.
 *
 * Spur detects VPNs, residential proxies, and bots by providing
 * real-time IP context data including risk signals, tunnel info,
 * infrastructure classification, and client behavior analysis.
 *
 * @see https://docs.spur.us/context-api
 */
class SpurService
{
    private const API_BASE_URL = 'https://api.spur.us';
    private const CONTEXT_ENDPOINT = '/v2/context/';

    private int $defaultTimeout;

    /**
     * Risk factors that indicate high-risk anonymous traffic.
     * These risks, when present, contribute to spam scoring.
     */
    private const HIGH_RISK_FACTORS = [
        'CALLBACK_PROXY',
        'TUNNEL',
        'GEO_MISMATCH',
        'WEB_SCRAPING',
        'BOTNET',
        'MALWARE',
    ];

    /**
     * Risk factors that warrant an immediate block.
     */
    private const BLOCK_RISK_FACTORS = [
        'BOTNET',
        'MALWARE',
    ];

    /**
     * Infrastructure types considered higher risk for anonymous abuse.
     */
    private const DATACENTER_INFRA = [
        'DATACENTER',
    ];

    /**
     * Tunnel types that indicate anonymous proxying.
     */
    private const ANONYMOUS_TUNNEL_TYPES = [
        'VPN',
        'PROXY',
        'TOR',
    ];

    private LoggerInterface $logger;
    private ?string $apiToken;

    public function __construct(
        private ClientFactory $clientFactory,
        private SiteSettingsService $settingsService,
        LoggerFactory $loggerFactory,
        SiteConfigService $config,
    ) {
        $this->logger = $loggerFactory->get('spur');
        $this->apiToken = $config->get('spur_api_token', '') ?: null;
        $this->defaultTimeout = $config->getInt('spur_timeout', 3);
    }

    /**
     * Check whether spur.us integration is available and enabled.
     */
    public function isEnabled(): bool
    {
        if (empty($this->apiToken)) {
            return false;
        }

        return $this->settingsService->isFeatureEnabled('spur_enabled', false);
    }

    /**
     * Look up IP context from Spur Context API.
     *
     * @return array{
     *     risks: string[],
     *     tunnels: array<int, array{anonymous: bool, operator: string, type: string}>,
     *     infrastructure: string,
     *     client: array{proxies: string[], behaviors: string[], count: int, countries: int, types: string[]},
     *     location: array{city: string, country: string, state: string},
     *     as: array{number: int, organization: string},
     *     organization: string,
     *     ip: string,
     *     raw: array<string, mixed>
     * }|null Returns null on failure or if service is disabled
     */
    public function lookup(string $ip): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->isValidPublicIp($ip)) {
            $this->logger->warning('Spur lookup skipped: invalid or private IP', ['ip_hash' => hash('sha256', $ip)]);
            return null;
        }

        try {
            $client = $this->clientFactory->create();
            $response = $client->get(self::API_BASE_URL . self::CONTEXT_ENDPOINT . urlencode($ip), [
                'headers' => [
                    'Token' => $this->apiToken,
                    'Accept' => 'application/json',
                ],
                'timeout' => $this->defaultTimeout,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 429) {
                $this->logger->warning('Spur rate limit reached', [
                    'remaining' => $response->getHeaderLine('x-balance-remaining'),
                ]);
                return null;
            }

            if ($statusCode !== 200) {
                $this->logger->error('Spur API returned non-200 status', [
                    'status' => $statusCode,
                ]);
                return null;
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($response->getBody()->getContents(), true);

            if (!is_array($data)) {
                $this->logger->error('Spur API returned invalid JSON');
                return null;
            }

            $remaining = $response->getHeaderLine('x-balance-remaining');
            if (is_numeric($remaining) && (int) $remaining < 100) {
                $this->logger->warning('Spur API balance running low', [
                    'remaining' => $remaining,
                ]);
            }

            return $this->normalizeResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('Spur lookup failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Evaluate an IP for spam/abuse risk using Spur data.
     *
     * @return array{score: int, reasons: string[], block: bool, context: array<string, mixed>|null}
     */
    public function evaluate(string $ip): array
    {
        $result = [
            'score' => 0,
            'reasons' => [],
            'block' => false,
            'context' => null,
        ];

        $context = $this->lookup($ip);
        if ($context === null) {
            return $result;
        }

        $result['context'] = $context;

        // Check for blocking risk factors
        foreach ($context['risks'] as $risk) {
            if (in_array($risk, self::BLOCK_RISK_FACTORS, true)) {
                $result['block'] = true;
                $result['score'] += 100;
                $result['reasons'][] = "Spur: critical risk factor ({$risk})";
            } elseif (in_array($risk, self::HIGH_RISK_FACTORS, true)) {
                $result['score'] += 15;
                $result['reasons'][] = "Spur: high risk factor ({$risk})";
            }
        }

        // Check for anonymous tunnels (VPN/Proxy/TOR)
        $anonymousTunnels = 0;
        foreach ($context['tunnels'] as $tunnel) {
            if (!empty($tunnel['anonymous']) && in_array($tunnel['type'], self::ANONYMOUS_TUNNEL_TYPES, true)) {
                $anonymousTunnels++;
                $operator = $tunnel['operator'];
                $result['reasons'][] = "Spur: anonymous {$tunnel['type']} detected ({$operator})";
            }
        }
        if ($anonymousTunnels > 0) {
            $result['score'] += min($anonymousTunnels * 10, 30);
        }

        // Check infrastructure type
        if (in_array($context['infrastructure'], self::DATACENTER_INFRA, true)) {
            $result['score'] += 5;
            $result['reasons'][] = 'Spur: datacenter infrastructure';
        }

        // Check client proxy associations
        $proxyCount = count($context['client']['proxies']);
        if ($proxyCount > 0) {
            $result['score'] += min($proxyCount * 3, 15);
            $result['reasons'][] = "Spur: associated with {$proxyCount} proxy service(s)";
        }

        // Check high client count (many users behind same IP = shared/proxy)
        $clientCount = $context['client']['count'];
        if ($clientCount > 100) {
            $result['score'] += 10;
            $result['reasons'][] = "Spur: high client concentration ({$clientCount} clients)";
        } elseif ($clientCount > 20) {
            $result['score'] += 5;
            $result['reasons'][] = "Spur: moderate client concentration ({$clientCount} clients)";
        }

        // Check multi-country usage (geographic dispersion)
        $countries = $context['client']['countries'];
        if ($countries > 5) {
            $result['score'] += 8;
            $result['reasons'][] = "Spur: clients from {$countries} countries";
        }

        // Check suspicious behaviors
        $behaviors = $context['client']['behaviors'];
        foreach ($behaviors as $behavior) {
            if (str_contains($behavior, 'TOR') || str_contains($behavior, 'PROXY')) {
                $result['score'] += 5;
                $result['reasons'][] = "Spur: behavior flag ({$behavior})";
            }
        }

        $this->logger->info('Spur evaluation complete', [
            'score' => $result['score'],
            'block' => $result['block'],
            'reason_count' => count($result['reasons']),
        ]);

        return $result;
    }

    /**
     * Normalize the raw API response into a consistent structure.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     risks: string[],
     *     tunnels: array<int, array{anonymous: bool, operator: string, type: string}>,
     *     infrastructure: string,
     *     client: array{proxies: string[], behaviors: string[], count: int, countries: int, types: string[]},
     *     location: array{city: string, country: string, state: string},
     *     as: array{number: int, organization: string},
     *     organization: string,
     *     ip: string,
     *     raw: array<string, mixed>
     * }
     */
    private function normalizeResponse(array $data): array
    {
        $client = is_array($data['client'] ?? null) ? $data['client'] : [];
        $location = is_array($data['location'] ?? null) ? $data['location'] : [];
        $as = is_array($data['as'] ?? null) ? $data['as'] : [];

        $tunnels = [];
        if (is_array($data['tunnels'] ?? null)) {
            foreach ($data['tunnels'] as $tunnel) {
                if (is_array($tunnel)) {
                    $tunnels[] = [
                        'anonymous' => (bool) ($tunnel['anonymous'] ?? false),
                        'operator' => (string) ($tunnel['operator'] ?? ''),
                        'type' => (string) ($tunnel['type'] ?? ''),
                    ];
                }
            }
        }

        return [
            'risks' => is_array($data['risks'] ?? null)
                ? array_map(static fn(mixed $v): string => (string) $v, $data['risks'])
                : [],
            'tunnels' => $tunnels,
            'infrastructure' => (string) ($data['infrastructure'] ?? ''),
            'client' => [
                'proxies' => is_array($client['proxies'] ?? null)
                    ? array_map(static fn(mixed $v): string => (string) $v, $client['proxies'])
                    : [],
                'behaviors' => is_array($client['behaviors'] ?? null)
                    ? array_map(static fn(mixed $v): string => (string) $v, $client['behaviors'])
                    : [],
                'count' => (int) ($client['count'] ?? 0),
                'countries' => (int) ($client['countries'] ?? 0),
                'types' => is_array($client['types'] ?? null)
                    ? array_map(static fn(mixed $v): string => (string) $v, $client['types'])
                    : [],
            ],
            'location' => [
                'city' => (string) ($location['city'] ?? ''),
                'country' => (string) ($location['country'] ?? ''),
                'state' => (string) ($location['state'] ?? ''),
            ],
            'as' => [
                'number' => (int) ($as['number'] ?? 0),
                'organization' => (string) ($as['organization'] ?? ''),
            ],
            'organization' => (string) ($data['organization'] ?? ''),
            'ip' => (string) ($data['ip'] ?? ''),
            'raw' => $data,
        ];
    }

    /**
     * Validate that the given string is a public (non-private) IP address.
     */
    private function isValidPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
