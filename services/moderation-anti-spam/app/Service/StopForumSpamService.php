<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Guzzle\ClientFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class StopForumSpamService
{
    private ClientFactory $clientFactory;

    private ConfigInterface $config;

    private LoggerInterface $logger;

    public function __construct(
        ClientFactory $clientFactory,
        ConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->clientFactory = $clientFactory;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function check(string $ip, ?string $email = null, ?string $username = null): bool
    {
        $client = $this->clientFactory->create();
        $params = [
            'json' => '',
            'ip' => $ip,
        ];

        if ($email) {
            $params['email'] = $email;
        }

        if ($username) {
            $params['username'] = $username;
        }

        try {
            $response = $client->get('http://api.stopforumspam.org/api', [
                'query' => $params,
                'timeout' => 2.0, // Fail fast to avoid blocking
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['success']) || !$data['success']) {
                $this->logger->warning('StopForumSpam API request failed or returned error.', ['response' => $data]);
                return false; // Fail open (allow post) if API fails
            }

            // Check if user appears in database
            // The response structure usually contains 'ip', 'email', 'username' keys with 'appears' property.
            // Or if multiple checks, it might return an array.
            
            // Example response for single IP: {"success":1, "ip":{"appears":1,"frequency":255,"lastseen":"2023-10-27 10:00:00","confidence":99.9}}
            
            foreach (['ip', 'email', 'username'] as $type) {
                if (isset($data[$type]) && is_array($data[$type])) {
                    if (isset($data[$type]['appears']) && $data[$type]['appears']) {
                        // Check confidence if available
                        $confidence = $data[$type]['confidence'] ?? 0;
                        $threshold = $this->config->get('stopforumspam.threshold', 80);
                        
                        if ($confidence >= $threshold) {
                            $this->logger->info("Blocked spammer via StopForumSpam: $type", ['data' => $data[$type]]);
                            return true;
                        }
                    }
                }
            }

            return false;

        } catch (\Throwable $e) {
            $this->logger->error('StopForumSpam API error: ' . $e->getMessage());
            return false; // Fail open
        }
    }

    public function report(string $ip, string $email, string $username, string $evidence): void
    {
        $apiKey = $this->config->get('stopforumspam.api_key');

        if (empty($apiKey)) {
            $this->logger->warning('StopForumSpam API key not configured. Skipping report.');
            return;
        }

        $client = $this->clientFactory->create();

        try {
            $client->post('https://www.stopforumspam.com/add.php', [
                'form_params' => [
                    'username' => $username,
                    'ip_addr' => $ip,
                    'evidence' => $evidence,
                    'email' => $email,
                    'api_key' => $apiKey,
                ],
                'timeout' => 5.0,
            ]);
            
            $this->logger->info("Reported spammer to StopForumSpam: $username ($ip)");

        } catch (\Throwable $e) {
            $this->logger->error('StopForumSpam Report API error: ' . $e->getMessage());
        }
    }
}
