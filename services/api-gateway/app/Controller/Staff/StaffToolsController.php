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

use App\Service\AuthenticationService;
use App\Service\ModerationService;
use App\Service\PiiEncryptionService;
use App\Service\ProxyClient;
use App\Service\ViewService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\DbConnection\Db;
use Psr\Http\Message\ResponseInterface;

use function Hyperf\Coroutine\parallel;

/**
 * StaffToolsController - Admin tools functionality
 */
#[Controller(prefix: '/staff')]
final class StaffToolsController
{
    use RequiresAccessLevel;

    public function __construct(
        private AuthenticationService $authService,
        private ModerationService $modService,
        private PiiEncryptionService $piiEncryption,
        private ProxyClient $proxyClient,
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    /**
     * Search bans, IPs, posts, etc.
     */
    #[GetMapping(path: 'search')]
    public function search(): ResponseInterface
    {
        $query = (string) $this->request->query('q', '');
        $type = (string) $this->request->query('type', 'all');
        $results = null;
        
        if ($query !== '') {
            $results = $this->performSearch($query, $type);
        }
        
        $html = $this->viewService->render('staff/tools/search', [
            'query' => $query,
            'type' => $type,
            'results' => $results,
        ]);
        return $this->response->html($html);
    }

    /**
     * IP Lookup tool - supports both raw IP and IP hash lookup
     */
    #[GetMapping(path: 'ip-lookup')]
    public function ipLookup(): ResponseInterface
    {
        $ip = (string) $this->request->query('ip', '');
        $hash = (string) $this->request->query('hash', '');
        $info = null;
        $hashPosts = null;

        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $info = $this->getIpInfo($ip);
        }

        // IP hash lookup: query the boards backend for post history
        if ($hash !== '' && preg_match('/^[0-9a-f]{16}$/', $hash)) {
            $hashPosts = $this->getPostsByIpHash($hash);
        }
        
        $html = $this->viewService->render('staff/tools/ip-lookup', [
            'ip' => $ip,
            'info' => $info,
            'hash' => $hash,
            'hash_posts' => $hashPosts,
        ]);
        return $this->response->html($html);
    }

    /**
     * Check MD5 hash
     */
    #[GetMapping(path: 'check-md5')]
    public function checkMd5(): ResponseInterface
    {
        $md5 = (string) $this->request->query('md5', '');
        $results = null;
        
        if ($md5 !== '') {
            $results = $this->checkMd5Hash($md5);
        }
        
        $html = $this->viewService->render('staff/tools/check-md5', [
            'md5' => $md5,
            'results' => $results,
        ]);
        return $this->response->html($html);
    }

    /**
     * Check post filter
     */
    #[GetMapping(path: 'check-filter')]
    public function checkFilter(): ResponseInterface
    {
        $comment = (string) $this->request->query('comment', '');
        $name = (string) $this->request->query('name', '');
        $subject = (string) $this->request->query('subject', '');
        $result = null;
        
        if ($comment !== '' || $name !== '' || $subject !== '') {
            $result = $this->testPostFilter($comment, $name, $subject);
        }
        
        $html = $this->viewService->render('staff/tools/check-filter', [
            'comment' => $comment,
            'name' => $name,
            'subject' => $subject,
            'result' => $result,
        ]);
        return $this->response->html($html);
    }

    /**
     * Staff roster
     */
    #[GetMapping(path: 'staff-roster')]
    public function staffRoster(): ResponseInterface
    {
        $user = $this->getStaffUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $staffInfo = $this->getStaffInfo();

        try {
            $staff = Db::table('staff_users')
                ->select('id', 'username', 'email', 'access_level', 'is_active', 'last_login_at', 'created_at')
                ->orderBy('access_level', 'desc')
                ->orderBy('username')
                ->get();
        } catch (\Throwable $e) {
            $staff = [];
        }

        $html = $this->viewService->render('staff/tools/staff-roster', [
            'staff' => $staff,
            'username' => $user['username'],
            'level' => $user['access_level'],
            'isManager' => $staffInfo['is_manager'],
            'isAdmin' => $staffInfo['is_admin'],
            'access_levels' => ['janitor' => 'Janitor', 'mod' => 'Moderator', 'manager' => 'Manager', 'admin' => 'Admin'],
        ]);
        return $this->response->html($html);
    }

    /**
     * Create new staff account
     */
    #[PostMapping(path: 'staff-roster/create')]
    public function staffRosterCreate(): ResponseInterface
    {
        $denied = $this->requireAccessLevel('admin');
        if ($denied) return $denied;

        $body = (array) $this->request->getParsedBody();
        $errors = [];
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $accessLevel = (string) ($body['access_level'] ?? 'janitor');

        if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
        if (!in_array($accessLevel, ['janitor', 'mod', 'manager', 'admin'])) $errors[] = 'Invalid access level';
        if (Db::table('staff_users')->where('username', $username)->first()) $errors[] = 'Username already exists';

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        Db::table('staff_users')->insert([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'access_level' => $accessLevel,
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logStaffAction('create_account', 'Created staff account: ' . $username . ' (' . $accessLevel . ')');

        return $this->response->json(['success' => true]);
    }

    /**
     * Promote/demote staff member (change access level)
     */
    #[PostMapping(path: 'staff-roster/{id:\d+}/update-level')]
    public function staffRosterUpdateLevel(int $id): ResponseInterface
    {
        $denied = $this->requireAccessLevel('admin');
        if ($denied) return $denied;

        $current = $this->getStaffUser();
        if ($current && (int) $current['id'] === $id) {
            return $this->response->json(['error' => 'Cannot change your own access level'], 400);
        }

        $user = Db::table('staff_users')->where('id', $id)->first();
        if (!$user) {
            return $this->response->json(['error' => 'User not found'], 404);
        }

        $body = (array) $this->request->getParsedBody();
        $newLevel = (string) ($body['access_level'] ?? '');
        if (!in_array($newLevel, ['janitor', 'mod', 'manager', 'admin'])) {
            return $this->response->json(['error' => 'Invalid access level'], 400);
        }

        Db::table('staff_users')->where('id', $id)->update([
            'access_level' => $newLevel,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logStaffAction('update_level', 'Changed ' . (string) $user->username . ' level: ' . (string) $user->access_level . ' -> ' . $newLevel);

        return $this->response->json(['success' => true]);
    }

    /**
     * Toggle staff active status
     */
    #[PostMapping(path: 'staff-roster/{id:\d+}/toggle-active')]
    public function staffRosterToggleActive(int $id): ResponseInterface
    {
        $denied = $this->requireAccessLevel('admin');
        if ($denied) return $denied;

        $current = $this->getStaffUser();
        if ($current && (int) $current['id'] === $id) {
            return $this->response->json(['error' => 'Cannot deactivate yourself'], 400);
        }

        $user = Db::table('staff_users')->where('id', $id)->first();
        if (!$user) {
            return $this->response->json(['error' => 'User not found'], 404);
        }

        Db::table('staff_users')->where('id', $id)->update([
            'is_active' => !$user->is_active,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $newStatus = !$user->is_active ? 'activated' : 'deactivated';
        $this->logStaffAction('toggle_active', $newStatus . ' staff account: ' . (string) $user->username);

        return $this->response->json(['success' => true, 'is_active' => !$user->is_active]);
    }

    /**
     * Delete staff account
     */
    #[PostMapping(path: 'staff-roster/{id:\d+}/delete')]
    public function staffRosterDelete(int $id): ResponseInterface
    {
        $denied = $this->requireAccessLevel('admin');
        if ($denied) return $denied;

        $current = $this->getStaffUser();
        if ($current && (int) $current['id'] === $id) {
            return $this->response->json(['error' => 'Cannot delete yourself'], 400);
        }

        $user = Db::table('staff_users')->where('id', $id)->first();
        if (!$user) {
            return $this->response->json(['error' => 'User not found'], 404);
        }

        Db::table('staff_users')->where('id', $id)->delete();

        $this->logStaffAction('delete_account', 'Deleted staff account: ' . (string) $user->username);

        return $this->response->json(['success' => true]);
    }

    /**
     * Flood log
     */
    #[GetMapping(path: 'floodlog')]
    public function floodLog(): ResponseInterface
    {
        $ip = $this->request->query('ip', '');
        $board = $this->request->query('board', '');
        
        try {
            $query = Db::table('flood_log')
                ->select('ip', 'board', 'thread_id', 'req_sig', 'created_on')
                ->orderBy('created_on', 'desc')
                ->limit(100);
            
            if ($ip !== '' && is_string($ip)) {
                // Look up by hash if the ip column is encrypted, or by ip_hash column
                $query->where('ip_hash', hash('sha256', $ip));
            }
            if ($board !== '' && is_string($board)) {
                $query->where('board', $board);
            }
            
            $logs = $query->get();

            // Decrypt IPs for staff display
            $logs = $logs->map(function ($log) {
                $log->ip = is_string($log->ip) && $log->ip !== ''
                    ? $this->piiEncryption->decrypt($log->ip)
                    : '';
                return $log;
            });
        } catch (\Throwable $e) {
            $logs = [];
        }
        
        $html = $this->viewService->render('staff/tools/floodlog', [
            'logs' => $logs,
            'filterIp' => $ip,
            'filterBoard' => $board,
        ]);
        return $this->response->html($html);
    }

    /**
     * Staff action log
     */
    #[GetMapping(path: 'stafflog')]
    public function staffLog(): ResponseInterface
    {
        $username = $this->request->query('username', '');
        $board = $this->request->query('board', '');
        $action = $this->request->query('action', '');
        
        try {
            $query = Db::table('admin_audit_log')
                ->select('action_type', 'ip_address as ip', 'board', 'resource_id as post_id', 'description as arg_str', 'username', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(100);
            
            if ($username !== '') {
                $query->where('username', $username);
            }
            if ($board !== '') {
                $query->where('board', $board);
            }
            if ($action !== '') {
                $query->where('action_type', $action);
            }
            
            $logs = $query->get();

            // Decrypt staff IPs for display
            $logs = $logs->map(function ($log) {
                $log->ip = is_string($log->ip) && $log->ip !== ''
                    ? $this->piiEncryption->decrypt($log->ip)
                    : '';
                return $log;
            });
        } catch (\Throwable $e) {
            $logs = [];
        }
        
        $html = $this->viewService->render('staff/tools/stafflog', [
            'logs' => $logs,
            'filterUsername' => $username,
            'filterBoard' => $board,
            'filterAction' => $action,
        ]);
        return $this->response->html($html);
    }

    /**
     * User deletion log
     */
    #[GetMapping(path: 'userdellog')]
    public function userDelLog(): ResponseInterface
    {
        try {
            $logs = Db::table('admin_audit_log')
                ->select('action_type', 'ip_address as ip', 'board', 'resource_id as postno', 'created_at as time')
                ->where('action_type', 'delete')
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();
        } catch (\Throwable $e) {
            $logs = [];
        }
        
        $html = $this->viewService->render('staff/tools/userdellog', [
            'logs' => $logs,
        ]);
        return $this->response->html($html);
    }

    /**
     * Perform search across tables
     *
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function performSearch(string $query, string $type): array
    {
        /** @var array<string, mixed> $results */
        $results = [
            'bans' => [],
            'reports' => [],
            'posts' => [],
        ];

        // Build parallel closures for independent searches
        $tasks = [];
        
        // Search bans — use host_hash for IP lookups (encrypted IPs can't be searched with LIKE)
        if ($type === 'all' || $type === 'bans') {
            $tasks['bans'] = function () use ($query) {
                $banQuery = Db::table('banned_users')
                    ->select('id', 'board', 'host', 'reason', 'now', 'length', 'admin');
                
                // If query looks like an IP, search by hash; otherwise search by reason text
                if (filter_var($query, FILTER_VALIDATE_IP)) {
                    $queryHash = hash('sha256', $query);
                    $banQuery->where('host_hash', $queryHash);
                } else {
                    $escapedQuery = str_replace(['%', '_'], ['\%', '\_'], $query);
                    $banQuery->where('reason', 'like', "%{$escapedQuery}%");
                }
                
                $bans = $banQuery->limit(20)->get();
                return $bans->map(function ($ban) {
                    $ban->host = is_string($ban->host) && $ban->host !== ''
                        ? $this->piiEncryption->decrypt($ban->host)
                        : '';
                    return $ban;
                });
            };
        }
        
        // Search reports
        if ($type === 'all' || $type === 'reports') {
            $tasks['reports'] = static function () use ($query) {
                return Db::table('reports')
                    ->select('id', 'board', 'no', 'post_json', 'ts')
                    ->where(function ($q) use ($query) {
                        $q->where('no', (int) $query)
                          ->orWhere('board', $query);
                    })
                    ->limit(20)
                    ->get();
            };
        }

        // Run all tasks in parallel when there are multiple
        if (count($tasks) > 1) {
            $parallelResults = parallel($tasks);
            foreach ($parallelResults as $key => $value) {
                $results[$key] = $value;
            }
        } else {
            foreach ($tasks as $key => $task) {
                $results[$key] = $task();
            }
        }
        
        /** @var array<string, mixed> $results */
        return $results;
    }

    /**
     * Get IP information
     *
     * @return array<string, mixed>
     */
    private function getIpInfo(string $ip): array
    {
        $ipHash = hash('sha256', $ip);

        // Execute independent DB queries in parallel using Swoole coroutines
        [$bans, $reports, $actions] = parallel([
            static fn () => Db::table('banned_users')
                ->select('id', 'board', 'reason', 'now', 'length', 'admin', 'active')
                ->where('host_hash', $ipHash)
                ->orderBy('now', 'desc')
                ->limit(10)
                ->get(),
            static fn () => Db::table('reports')
                ->select('id', 'board', 'no', 'ts', 'cleared')
                ->where('ip_hash', $ipHash)
                ->orderBy('ts', 'desc')
                ->limit(10)
                ->get(),
            static fn () => Db::table('user_actions')
                ->select('action', 'board', 'postno', 'time')
                ->where('ip_hash', $ipHash)
                ->orderBy('time', 'desc')
                ->limit(10)
                ->get(),
        ]);

        // Basic geolocation (would use GeoIP2 in production)
        $geoInfo = [
            'ip' => $ip,
            'country' => 'Unknown',
            'region' => 'Unknown',
            'city' => 'Unknown',
            'isp' => 'Unknown',
        ];
        
        return [
            'ip' => $ip,
            'bans' => $bans,
            'reports' => $reports,
            'actions' => $actions,
            'geo' => $geoInfo,
            'ban_count' => is_countable($bans) ? count($bans) : 0,
            'report_count' => is_countable($reports) ? count($reports) : 0,
        ];
    }

    /**
     * Fetch post history by IP hash from the boards backend.
     *
     * @return array<string, mixed>|null
     */
    private function getPostsByIpHash(string $hash): ?array
    {
        $staffInfo = \Hyperf\Context\Context::get('staff_info', []);
        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($staffInfo['level'])) {
            $headers['X-Staff-Level'] = (string) $staffInfo['level'];
        }

        $result = $this->proxyClient->forward(
            'boards',
            'GET',
            '/api/v1/posts/by-ip-hash/' . urlencode($hash),
            $headers
        );

        if ($result['status'] >= 400) {
            return null;
        }

        $body = $result['body'];
        if (!is_string($body)) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Check MD5 hash against database
     *
     * @return array<string, mixed>
     */
    private function checkMd5Hash(string $md5): array
    {
        // Search for MD5 in posts
        $posts = Db::table('posts')
            ->select('board', 'no', 'resto', 'md5', 'filename')
            ->where('md5', $md5)
            ->limit(20)
            ->get();
        
        // Check blacklist
        $blacklisted = Db::table('blacklist')
            ->select('id', 'field', 'contents', 'banreason', 'active')
            ->where('field', 'md5')
            ->where('contents', $md5)
            ->first();
        
        return [
            'md5' => $md5,
            'posts' => $posts,
            'post_count' => count($posts),
            'blacklisted' => $blacklisted !== null,
            'blacklist_reason' => $blacklisted ? ($blacklisted->banreason ?? null) : null,
        ];
    }

    /**
     * Test post against filters
     *
     * @return array<string, mixed>
     */
    private function testPostFilter(string $comment, string $name, string $subject): array
    {
        $filters = Db::table('postfilter')
            ->select('id', 'pattern', 'regex', 'board', 'active', 'ban_days')
            ->where('active', 1)
            ->get();
        
        $matches = [];
        $content = $comment . ' ' . $name . ' ' . $subject;
        
        foreach ($filters as $filter) {
            $matched = false;
            
            if ($filter->regex) {
                // Prevent ReDoS: use SOH delimiter to avoid injection,
                // and enforce a strict backtrack limit
                $safePattern = str_replace('\0', '', (string) $filter->pattern);
                $delimiter = chr(1);
                $oldLimit = (int) ini_get('pcre.backtrack_limit');
                ini_set('pcre.backtrack_limit', '10000');
                $matched = @preg_match($delimiter . $safePattern . $delimiter . 'iu', $content);
                ini_set('pcre.backtrack_limit', (string) $oldLimit);
                if ($matched === false) {
                    // Invalid regex pattern — skip this filter
                    continue;
                }
            } else {
                $matched = stripos($content, (string) $filter->pattern) !== false;
            }
            
            if ($matched) {
                $matches[] = [
                    'id' => $filter->id,
                    'pattern' => $filter->pattern,
                    'board' => $filter->board,
                    'ban_days' => $filter->ban_days,
                ];
            }
        }
        
        return [
            'comment' => $comment,
            'name' => $name,
            'subject' => $subject,
            'matches' => $matches,
            'match_count' => count($matches),
            'would_be_banned' => count($matches) > 0,
        ];
    }

    /**
     * Log a staff action to the audit log
     */
    private function logStaffAction(string $actionType, string $description, ?string $board = null, ?int $resourceId = null): void
    {
        $user = $this->getStaffUser();
        if (!$user) return;

        try {
            $serverParams = $this->request->getServerParams();
            $this->authService->logAuditAction(
                (int) $user['id'],
                (string) $user['username'],
                $actionType,
                'staff_management',
                null,
                $resourceId,
                $description,
                (string) ($serverParams['remote_addr'] ?? ''),
                $this->request->getHeaderLine('User-Agent'),
                null,
                null,
                (string) $this->request->getUri(),
                $board
            );
        } catch (\Throwable $e) {
            // Logging failure should not break the operation
        }
    }
}
