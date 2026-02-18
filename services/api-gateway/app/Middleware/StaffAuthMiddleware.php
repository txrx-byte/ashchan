<?php
declare(strict_types=1);

namespace App\Middleware;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * StaffAuthMiddleware - Authentication for staff interface (/staff)
 * 
 * Verifies that the user has valid staff credentials (janitor/mod/manager/admin)
 * before allowing access to staff-only pages.
 */
class StaffAuthMiddleware implements MiddlewareInterface
{
    /**
     * Process the request and verify staff authentication
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $container = \Hyperf\Context\Context::getContainer();
        
        // Get response interface
        $response = $container->get(ResponseInterface::class);
        
        // Check for staff authentication
        // In production, this would verify JWT tokens or session cookies
        $isStaff = $this->verifyStaffAuth($request);
        
        if (!$isStaff) {
            // Redirect to login or return 403
            return $response->redirect('/staff/login');
        }
        
        // Add staff info to request context
        $staffInfo = $this->getStaffInfo($request);
        \Hyperf\Context\Context::set('staff_info', $staffInfo);
        
        return $handler->handle($request);
    }
    
    /**
     * Verify staff authentication
     * This is a placeholder - in production, verify against auth service
     */
    private function verifyStaffAuth(ServerRequestInterface $request): bool
    {
        // For development: check for staff cookie
        // In production: verify JWT token from auth service
        
        // Check cookies (mimics OpenYotsuba's 4chan_auser/4chan_apass)
        $cookies = $request->getCookieParams();
        
        if (isset($cookies['staff_user']) && isset($cookies['staff_token'])) {
            // Validate token (placeholder - would verify against database/auth service)
            return true;
        }
        
        // Check Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            // Validate token (placeholder)
            return true;
        }
        
        return false;
    }
    
    /**
     * Get staff information from authentication
     * @return array{username: string, level: string, boards: array, flags: array}
     */
    private function getStaffInfo(ServerRequestInterface $request): array
    {
        $cookies = $request->getCookieParams();
        
        return [
            'username' => $cookies['staff_user'] ?? 'system',
            'level' => $cookies['staff_level'] ?? 'janitor',
            'boards' => explode(',', $cookies['staff_boards'] ?? ''),
            'flags' => [],
            'is_janitor' => true,
            'is_mod' => in_array($cookies['staff_level'] ?? '', ['mod', 'manager', 'admin']),
            'is_manager' => in_array($cookies['staff_level'] ?? '', ['manager', 'admin']),
            'is_admin' => ($cookies['staff_level'] ?? '') === 'admin',
        ];
    }
}
