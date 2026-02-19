<?php
declare(strict_types=1);

namespace App\Service;

use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Service to interact with StopForumSpam API.
 */
class StopForumSpamService
{
    private const API_URL = 'http://api.stopforumspam.org/api';
    private const REPORT_URL = 'https://www.stopforumspam.com/add.php';
    private const CONFIDENCE_THRESHOLD = 80;

    private LoggerInterface $logger;
    private ?string $apiKey;

    public function __construct(
        private ClientFactory $clientFactory,
        LoggerFactory $loggerFactory
    ) {
        $this->logger = $loggerFactory->get('sfs');
        $this->apiKey = env('SFS_API_KEY');
    }

    /**
     * Check if an IP, Email, or Username is flagged as spam.
     *
     * @return bool True if flagged as spammer
     */
    public function check(string $ip, ?string $email = null, ?string $username = null): bool
    {
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
            $client = $this->clientFactory->create();
            $response = $client->get(self::API_URL, ['query' => $params, 'timeout' => 2]);
            
            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || !$data['success']) {
                return false;
            }

            foreach (['ip', 'email', 'username'] as $type) {
                if (isset($data[$type]) && is_array($data[$type])) {
                    // Normalize single vs multiple response structure
                    $entries = isset($data[$type]['appears']) ? [$data[$type]] : $data[$type];
                    
                    foreach ($entries as $entry) {
                        if (isset($entry['appears']) && $entry['appears'] && isset($entry['confidence'])) {
                            if ($entry['confidence'] >= self::CONFIDENCE_THRESHOLD) {
                                $this->logger->info("SFS Block: $type matched with confidence {$entry['confidence']}");
                                return true;
                            }
                        }
                    }
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('SFS Check Failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Report a spammer to StopForumSpam.
     */
    public function report(string $ip, string $email, string $username, string $evidence): void
    {
        if (!$this->apiKey) {
            $this->logger->warning('SFS Report Skipped: No API Key configured');
            return;
        }

        $params = [
            'username' => $username,
            'ip_addr' => $ip,
            'evidence' => $evidence,
            'email' => $email,
            'api_key' => $this->apiKey,
        ];

        try {
            $client = $this->clientFactory->create();
            $client->post(self::REPORT_URL, ['form_params' => $params, 'timeout' => 5]);
            $this->logger->info("SFS Reported: IP=$ip");
        } catch (\Throwable $e) {
            $this->logger->error('SFS Report Failed: ' . $e->getMessage());
        }
    }
}