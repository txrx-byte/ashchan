<?php
declare(strict_types=1);

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
        if (!str_starts_with($path, '/staff/')) {
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
        $tokenHash = hash('sha256', $sessionToken);
        
        // Validate session
        $validation = $this->authService->validateSession($tokenHash);
        
        if (!$validation['valid']) {
            return $this->redirectToLogin($request, 'Invalid or expired session. Please login again.');
        }
        
        $user = $validation['user'];
        
        // Store user info in context for controllers
        \Hyperf\Context\Context::set('staff_user', $user);
        \Hyperf\Context\Context::set('staff_user_id', $user['id']);
        \Hyperf\Context\Context::set('staff_access_level', $user['access_level']);
        
        // Check if this is a state-changing request requiring CSRF validation
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $csrfToken = $request->getHeaderLine('X-CSRF-Token') 
                ?? $request->parsedBody()['_csrf_token'] 
                ?? null;
            
            if (!$csrfToken) {
                return $this->response->json([
                    'error' => 'CSRF token missing',
                ], 403);
            }
            
            if (!$this->authService->validateCsrfToken($user['id'], $csrfToken)) {
                $this->logSecurityEvent($user, 'csrf_failure', 'Invalid CSRF token', $request);
                return $this->response->json([
                    'error' => 'Invalid CSRF token. Please refresh the page and try again.',
                ], 403);
            }
        }
        
        // Check access level for certain paths
        $requiredLevel = $this->getRequiredAccessLevel($path);
        if ($requiredLevel && !$this->authService->hasAccessLevel($user['id'], $requiredLevel)) {
            $this->logSecurityEvent($user, 'access_denied', "Insufficient access level for {$path}", $request);
            return $this->response->json([
                'error' => 'Access denied. Insufficient privileges.',
            ], 403);
        }
        
        // Check board access for board-specific paths
        if (preg_match('#/staff/(?:reports|bans)/([a-z0-9]+)#', $path, $matches)) {
            $board = $matches[1];
            if (!$this->authService->canAccessBoard($user['id'], $board)) {
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
     * Get required access level for path
     */
    private function getRequiredAccessLevel(string $path): ?string
    {
        // Manager+ paths
        if (preg_match('#^/staff/(?:staff-roster|add-account|capcodes|iprangebans|autopurge|dmca|maintenance|floodlog|stafflog|userdellog)#', $path)) {
            return 'manager';
        }
        
        // Admin+ paths
        if (preg_match('#^/staff/(?:blotter|globalmsg|phpmyadmin)#', $path)) {
            return 'admin';
        }
        
        return null;
    }
    
    /**
     * Log security event
     */
    private function logSecurityEvent(array $user, string $eventType, string $message, ServerRequestInterface $request): void
    {
        $this->authService->logAuditAction(
            $user['id'],
            $user['username'],
            $eventType,
            'security',
            null,
            null,
            $message,
            $request->getServerParams()['remote_addr'] ?? '',
            $request->getHeaderLine('User-Agent')
        );
    }
}
