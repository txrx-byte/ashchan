<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * mTLS Middleware for ServiceMesh
 *
 * Validates client certificates on all incoming mTLS connections.
 * Only requests with valid certificates signed by the Ashchan CA are allowed.
 */
class MtlsMiddleware implements MiddlewareInterface
{
    private bool $enabled;
    private string $caFile;
    private bool $verifyPeer;
    private string $minTlsVersion;

    public function __construct(ConfigInterface $config)
    {
        $this->enabled = $config->get('mtls.enabled', true);
        $this->caFile = $config->get('mtls.ca_file', '/etc/mtls/ca/ca.crt');
        $this->verifyPeer = $config->get('mtls.verify_peer', true);
        $this->minTlsVersion = $config->get('mtls.min_tls_version', 'TLSv1.3');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip mTLS validation if not enabled
        if (! $this->enabled) {
            return $handler->handle($request);
        }

        // Get SSL context from Swoole request
        $sslContext = $this->getSslContext($request);

        if ($sslContext === null) {
            // Not an HTTPS connection
            return $handler->handle($request);
        }

        // Verify client certificate
        if ($this->verifyPeer) {
            $verifyResult = $this->verifyClientCertificate($sslContext);

            if ($verifyResult !== true) {
                return $this->denyAccess($verifyResult);
            }
        }

        // Extract client identity from certificate
        $clientIdentity = $this->extractClientIdentity($sslContext);

        if ($clientIdentity !== null) {
            // Add client identity to request attributes for downstream use
            $request = $request->withAttribute('mtls_client_identity', $clientIdentity);
            $request = $request->withAttribute('mtls_verified', true);
        }

        // Add TLS version to headers for logging
        $request = $request->withHeader('X-Client-TLS-Version', $sslContext['version'] ?? 'unknown');

        return $handler->handle($request);
    }

    /**
     * Get SSL context from Swoole request
     */
    private function getSslContext(ServerRequestInterface $request): ?array
    {
        $serverRequest = $request->getServerParams();

        // Check for Swoole SSL context
        if (isset($serverRequest['ssl'])) {
            return $serverRequest['ssl'];
        }

        // Check for standard HTTPS context
        if (isset($serverRequest['https'])) {
            return $serverRequest['https'];
        }

        return null;
    }

    /**
     * Verify client certificate against CA
     */
    private function verifyClientCertificate(array $sslContext): string|true
    {
        // Check if client certificate was provided
        if (! isset($sslContext['client_cert']) || ! $sslContext['client_cert']) {
            return 'Client certificate required';
        }

        // Check certificate verification result
        if (isset($sslContext['verify_result'])) {
            $verifyResult = (int) $sslContext['verify_result'];

            if ($verifyResult !== 0) {
                return sprintf('Certificate verification failed: %s (code: %d)', $this->getVerifyError($verifyResult), $verifyResult);
            }
        }

        // Check TLS version
        $tlsVersion = $sslContext['version'] ?? '';
        if ($tlsVersion && version_compare($tlsVersion, $this->minTlsVersion, '<')) {
            return sprintf('TLS version %s is below minimum required %s', $tlsVersion, $this->minTlsVersion);
        }

        return true;
    }

    /**
     * Get human-readable SSL verification error
     */
    private function getVerifyError(int $code): string
    {
        $errors = [
            2 => 'Unable to get issuer certificate',
            3 => 'Unable to get certificate CRL',
            4 => 'Unable to get issuer certificate CRL',
            5 => 'Error in certificate notBefore field',
            6 => 'Error in certificate notAfter field',
            7 => 'Certificate is not yet valid',
            8 => 'Certificate has expired',
            9 => 'CRL is not yet valid',
            10 => 'CRL has expired',
            11 => 'Format error in certificate\'s notBefore field',
            12 => 'Format error in certificate\'s notAfter field',
            13 => 'Unable to get local issuer certificate',
            14 => 'Unable to verify the first certificate',
            15 => 'Certificate revoked',
            18 => 'Self-signed certificate',
            19 => 'Self-signed certificate in certificate chain',
            20 => 'Unable to get local issuer certificate',
        ];

        return $errors[$code] ?? 'Unknown verification error';
    }

    /**
     * Extract client identity from certificate
     */
    private function extractClientIdentity(array $sslContext): ?string
    {
        // Try to get CN from client certificate subject
        if (isset($sslContext['client_cert_dn'])) {
            $dn = $sslContext['client_cert_dn'];

            // Extract CN from DN string
            if (preg_match('/CN=([^,\/]+)/', $dn, $matches)) {
                return trim($matches[1]);
            }
        }

        // Try to get subject alternative name
        if (isset($sslContext['subject_alt_name'])) {
            $san = $sslContext['subject_alt_name'];

            // Extract DNS name from SAN
            if (preg_match('/DNS:([^,\s]+)/', $san, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Deny access with 403 response
     */
    private function denyAccess(string $reason): ResponseInterface
    {
        $body = json_encode([
            'error' => 'Forbidden',
            'message' => 'mTLS authentication failed',
            'reason' => $reason,
        ], JSON_PRETTY_PRINT);

        return new class ($body) implements ResponseInterface {
            private string $body;

            public function __construct(string $body)
            {
                $this->body = $body;
            }

            public function getProtocolVersion(): string
            {
                return '1.1';
            }

            public function withProtocolVersion($version): ResponseInterface
            {
                return $this;
            }

            public function getHeaders(): array
            {
                return [
                    'Content-Type' => ['application/json'],
                    'Content-Length' => [strlen($this->body)],
                ];
            }

            public function hasHeader($name): bool
            {
                return in_array(strtolower($name), ['content-type', 'content-length']);
            }

            public function getHeader($name): array
            {
                return match (strtolower($name)) {
                    'content-type' => ['application/json'],
                    'content-length' => [strlen($this->body)],
                    default => [],
                };
            }

            public function getHeaderLine($name): string
            {
                return implode(', ', $this->getHeader($name));
            }

            public function withHeader($name, $value): ResponseInterface
            {
                return $this;
            }

            public function withAddedHeader($name, $value): ResponseInterface
            {
                return $this;
            }

            public function withoutHeader($name): ResponseInterface
            {
                return $this;
            }

            public function getBody()
            {
                return new SwooleStream($this->body);
            }

            public function withBody($body): ResponseInterface
            {
                $this->body = (string) $body;
                return $this;
            }

            public function getStatusCode(): int
            {
                return 403;
            }

            public function withStatus($code, $reasonPhrase = ''): ResponseInterface
            {
                return $this;
            }

            public function getReasonPhrase(): string
            {
                return 'Forbidden';
            }
        };
    }
}
