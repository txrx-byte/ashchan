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
final class MtlsMiddleware implements MiddlewareInterface
{
    private bool $enabled;
    private bool $verifyPeer;
    private string $minTlsVersion;

    public function __construct(ConfigInterface $config)
    {
        $this->enabled = (bool) $config->get('mtls.enabled', true);
        $this->verifyPeer = (bool) $config->get('mtls.verify_peer', true);
        $this->minTlsVersion = (string) $config->get('mtls.min_tls_version', 'TLSv1.3');
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
        $request = $request->withHeader('X-Client-TLS-Version', (string) ($sslContext['version'] ?? 'unknown'));

        return $handler->handle($request);
    }

    /**
     * Get SSL context from Swoole request
     *
     * @return array<string, mixed>|null
     */
    private function getSslContext(ServerRequestInterface $request): ?array
    {
        $serverRequest = $request->getServerParams();

        // Check for Swoole SSL context
        if (isset($serverRequest['ssl'])) {
            /** @var array<string, mixed> */
            return $serverRequest['ssl'];
        }

        // Check for standard HTTPS context
        if (isset($serverRequest['https'])) {
            /** @var array<string, mixed> */
            return $serverRequest['https'];
        }

        return null;
    }

    /**
     * Verify client certificate against CA
     *
     * @param array<string, mixed> $sslContext
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
        $tlsVersion = (string) ($sslContext['version'] ?? '');
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
     *
     * @param array<string, mixed> $sslContext
     */
    private function extractClientIdentity(array $sslContext): ?string
    {
        // Try to get CN from client certificate subject
        if (isset($sslContext['client_cert_dn'])) {
            $dn = (string) $sslContext['client_cert_dn'];

            // Extract CN from DN string
            if (preg_match('/CN=([^,\/]+)/', $dn, $matches)) {
                return trim($matches[1]);
            }
        }

        // Try to get subject alternative name
        if (isset($sslContext['subject_alt_name'])) {
            $san = (string) $sslContext['subject_alt_name'];

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
        ], JSON_PRETTY_PRINT) ?: '{}';

        $response = new \Hyperf\HttpMessage\Server\Response();
        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($body));
    }
}
