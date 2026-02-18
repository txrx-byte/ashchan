<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

/**
 * mTLS HTTP Client Factory
 *
 * Creates HTTP clients configured for mTLS service-to-service communication.
 * All outbound requests use client certificates for mutual authentication.
 */
class MtlsHttpClientFactory
{
    public function __construct(
        private ContainerInterface $container,
        private ConfigInterface $config
    ) {
    }

    /**
     * Create an mTLS-enabled HTTP client for a specific service
     *
     * @param string $serviceUrl The base URL of the target service
     * @param array $options Additional Guzzle options
     */
    public function create(string $serviceUrl, array $options = []): ClientInterface
    {
        $defaultOptions = [
            'base_uri' => $serviceUrl,
            'timeout' => 30.0,
            'connect_timeout' => 10.0,

            // mTLS Configuration
            'verify' => $this->config->get('mtls.ca_file', '/etc/mtls/ca/ca.crt'),
            'cert' => $this->config->get('mtls.client_cert_file', '/etc/mtls/client/client.crt'),
            'ssl_key' => $this->config->get('mtls.client_key_file', '/etc/mtls/client/client.key'),

            // HTTP/2 support (optional, for performance)
            'version' => '1.1',

            // Headers
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'ashchan-service-mesh/1.0',
            ],
        ];

        $mergedOptions = array_merge_recursive($defaultOptions, $options);

        return new Client($mergedOptions);
    }

    /**
     * Create a client for a specific service by name
     *
     * @param string $serviceName Service name (e.g., 'auth', 'boards', 'media')
     */
    public function createForService(string $serviceName): ClientInterface
    {
        $serviceUrl = $this->config->get(sprintf('services.%s.url', $serviceName));

        if (! $serviceUrl) {
            throw new \InvalidArgumentException("Service URL not configured for: {$serviceName}");
        }

        return $this->create($serviceUrl);
    }

    /**
     * Get the configured CA file path
     */
    public function getCaFile(): string
    {
        return $this->config->get('mtls.ca_file', '/etc/mtls/ca/ca.crt');
    }

    /**
     * Get the configured client certificate path
     */
    public function getClientCertFile(): string
    {
        return $this->config->get('mtls.client_cert_file', '/etc/mtls/client/client.crt');
    }

    /**
     * Get the configured client key path
     */
    public function getClientKeyFile(): string
    {
        return $this->config->get('mtls.client_key_file', '/etc/mtls/client/client.key');
    }

    /**
     * Test mTLS connectivity to a service
     *
     * @param string $serviceUrl The service URL to test
     * @param string $endpoint The health check endpoint
     */
    public function testConnection(string $serviceUrl, string $endpoint = '/health'): array
    {
        $client = $this->create($serviceUrl);

        try {
            $response = $client->get($endpoint);

            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }
}
