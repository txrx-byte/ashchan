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

use App\Service\AuthenticationService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * StaffAuthMiddleware - Production authentication for staff interface
 * 
 * Security features:
 * - Session token validation
 * - Account status verification
 * - Permission checking
 * - CSRF validation for state-changing requests
 * - Comprehensive audit logging
 */
class StaffAuthMiddleware implements MiddlewareInterface
{
    private AuthenticationService $authService;
    private HttpResponse $response;
    
    public function __construct(AuthenticationService $authService, HttpResponse $response)
    {
        $this->authService = $authService;
        $this->response = $response;
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        // Only protect /staff/* routes - all other routes are public
        if ($path !== '/staff' && !str_starts_with($path, '/staff/')) {
            return $handler->handle($request);
        }

        // Allow login/logout pages without auth
        if (in_array($path, ['/staff/login', '/staff/logout'])) {
            return $handler->handle($request);
        }

        // Get session token from cookie
        $cookies = $request->getCookieParams();
        $sessionToken = $cookies['staff_session'] ?? null;

        if (!$sessionToken) {
            return $this->redirectToLogin($request, 'Session expired. Please login again.');
        }
        
        // Hash token for lookup
        $tokenHash = hash('sha256', (string) $sessionToken);
        
        // Validate session
        $validation = $this->authService->validateSession($tokenHash);
        
        if (!$validation['valid'] || !isset($validation['user'])) {
            return $this->redirectToLogin($request, 'Invalid or expired session. Please login again.');
        }
        
        $user = $validation['user'];
        
        // Store user info in context for controllers
        \Hyperf\Context\Context::set('staff_user', $user);
        \Hyperf\Context\Context::set('staff_user_id', $user['id']);
        \Hyperf\Context\Context::set('staff_access_level', $user['access_level']);
        
        // Derive staff_info for controllers that expect the legacy shape
        $level = $user['access_level'] ?? 'janitor';
        $accessFlags = $user['access_flags'] ?? [];
        $boardAccess = $user['board_access'] ?? [];
        \Hyperf\Context\Context::set('staff_info', [
            'username' => $user['username'] ?? 'system',
            'level' => $level,
            'boards' => is_array($boardAccess) ? $boardAccess : (is_string($boardAccess) ? json_decode($boardAccess, true) ?? [] : []),
            'is_mod' => in_array($level, ['mod', 'manager', 'admin'], true),
            'is_manager' => in_array($level, ['manager', 'admin'], true),
            'is_admin' => $level === 'admin',
        ]);
        
        // Enforce access level requirements per path prefix
        $accessDenied = $this->checkPathAccessLevel($path, $level);
        if ($accessDenied !== null) {
            return $accessDenied;
        }
        
        // Generate CSRF token for GET requests and make it available to views
        if ($request->getMethod() === 'GET') {
            $csrfToken = $this->authService->generateCsrfToken((int) $user['id']);
            \Hyperf\Context\Context::set('csrf_token', $csrfToken);
        }
        
        // Check if this is a state-changing request requiring CSRF validation
        // Exempt logout from CSRF (session is being destroyed anyway)
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH']) && $path !== '/staff/logout') {
            $headerToken = $request->getHeaderLine('X-CSRF-Token');
            $parsedBody = $request->getParsedBody();
            $bodyToken = is_array($parsedBody) ? ($parsedBody['_csrf_token'] ?? null) : null;
            $csrfToken = ($headerToken !== '' ? $headerToken : null)
                ?? (is_string($bodyToken) && $bodyToken !== '' ? $bodyToken : null);
            
            if (!$csrfToken) {
                return $this->response->json([
                    'error' => 'CSRF token missing',
                ], 403);
            }
            
            if (!$this->authService->validateCsrfToken((int) $user['id'], $csrfToken)) {
                $this->logSecurityEvent($user, 'csrf_failure', 'Invalid CSRF token', $request);
                return $this->response->json([
                    'error' => 'Invalid CSRF token. Please refresh the page and try again.',
                ], 403);
            }
        }
        
        // Check access level for certain paths
        $requiredLevel = $this->getRequiredAccessLevel($path);
        if ($requiredLevel && !$this->authService->hasAccessLevel((int) $user['id'], $requiredLevel)) {
            $this->logSecurityEvent($user, 'access_denied', "Insufficient access level for {$path}", $request);
            return $this->response->json([
                'error' => 'Access denied. Insufficient privileges.',
            ], 403);
        }
        
        // Check board access for board-specific paths
        if (preg_match('#/staff/(?:reports|bans)/([a-z0-9]+)#', $path, $matches)) {
            $board = $matches[1];
            if (!$this->authService->canAccessBoard((int) $user['id'], $board)) {
                $this->logSecurityEvent($user, 'board_access_denied', "No access to board /{$board}/", $request);
                return $this->response->json([
                    'error' => 'Access denied. You do not have access to this board.',
                ], 403);
            }
        }
        
        return $handler->handle($request);
    }
    
    /**
     * Redirect to login page
     */
    private function redirectToLogin(ServerRequestInterface $request, string $message): PsrResponseInterface
    {
        // For API requests, return JSON
        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            return $this->response->json([
                'error' => $message,
                'redirect' => '/staff/login',
            ], 401);
        }
        
        // For web requests, redirect to login
        return $this->response->redirect('/staff/login?error=' . urlencode($message));
    }
    
    /**
     * Check path-based access level enforcement
     * Returns a 403 response if the user's level is insufficient, or null if access is allowed.
     */
    private function checkPathAccessLevel(string $path, string $userLevel): ?PsrResponseInterface
    {
        $requiredLevel = $this->getRequiredAccessLevel($path);
        if ($requiredLevel === null) {
            return null; // no restriction on this path
        }

        $hierarchy = ['janitor' => 0, 'mod' => 1, 'manager' => 2, 'admin' => 3];
        $userRank = $hierarchy[$userLevel] ?? 0;
        $requiredRank = $hierarchy[$requiredLevel] ?? 0;

        if ($userRank < $requiredRank) {
            return $this->response->json([
                'error' => 'Forbidden: requires ' . $requiredLevel . ' access level or higher',
            ], 403);
        }

        return null;
    }

    /**
     * Get required access level for path
     */
    private function getRequiredAccessLevel(string $path): ?string
    {
        // Admin-only paths
        if (preg_match('#^/staff/(?:accounts|capcodes|site-messages)(?:/|$)#', $path)) {
            return 'admin';
        }
        
        // Manager+ paths
        if (preg_match('#^/staff/(?:blotter|dmca|staff-roster|stafflog|userdellog|ban-templates|report-categories)(?:/|$)#', $path)) {
            return 'manager';
        }

        // Mod+ paths
        if (preg_match('#^/staff/(?:iprangebans|autopurge|ip-lookup|check-md5|check-filter|floodlog)(?:/|$)#', $path)) {
            return 'mod';
        }
        
        // All other /staff paths (dashboard, reports, bans, search) are accessible to all authenticated staff
        return null;
    }
    
    /**
     * Log security event
     *
     * @param array<string, mixed> $user
     */
    private function logSecurityEvent(array $user, string $eventType, string $message, ServerRequestInterface $request): void
    {
        $this->authService->logAuditAction(
            (int) $user['id'],
            (string) $user['username'],
            $eventType,
            'security',
            null,
            null,
            $message,
            (string) ($request->getServerParams()['remote_addr'] ?? ''),
            $request->getHeaderLine('User-Agent')
        );
    }
}
