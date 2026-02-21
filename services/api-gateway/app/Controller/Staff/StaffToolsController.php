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

use App\Service\ModerationService;
use App\Service\PiiEncryptionService;
use App\Service\ViewService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\DbConnection\Db;
use Psr\Http\Message\ResponseInterface;

/**
 * StaffToolsController - Admin tools functionality
 */
#[Controller(prefix: '/staff')]
final class StaffToolsController
{
    public function __construct(
        private ModerationService $modService,
        private PiiEncryptionService $piiEncryption,
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
     * IP Lookup tool
     */
    #[GetMapping(path: 'ip-lookup')]
    public function ipLookup(): ResponseInterface
    {
        $ip = (string) $this->request->query('ip', '');
        $info = null;
        
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $info = $this->getIpInfo($ip);
        }
        
        $html = $this->viewService->render('staff/tools/ip-lookup', [
            'ip' => $ip,
            'info' => $info,
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
        ]);
        return $this->response->html($html);
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
    private function performSearch(string $query, string $type): array
    {
        $results = [
            'bans' => [],
            'reports' => [],
            'posts' => [],
        ];
        
        // Search bans — use host_hash for IP lookups (encrypted IPs can't be searched with LIKE)
        if ($type === 'all' || $type === 'bans') {
            $banQuery = Db::table('banned_users')
                ->select('id', 'board', 'host', 'reason', 'now', 'length', 'admin');
            
            // If query looks like an IP, search by hash; otherwise search by reason text
            if (filter_var($query, FILTER_VALIDATE_IP)) {
                $queryHash = hash('sha256', $query);
                $banQuery->where('host_hash', $queryHash);
            } else {
                $banQuery->where('reason', 'like', "%{$query}%");
            }
            
            $bans = $banQuery->limit(20)->get();
            // Decrypt host IPs for staff display
            $results['bans'] = $bans->map(function ($ban) {
                $ban->host = is_string($ban->host) && $ban->host !== ''
                    ? $this->piiEncryption->decrypt($ban->host)
                    : '';
                return $ban;
            });
        }
        
        // Search reports
        if ($type === 'all' || $type === 'reports') {
            $results['reports'] = Db::table('reports')
                ->select('id', 'board', 'no', 'post_json', 'ts')
                ->where(function ($q) use ($query) {
                    $q->where('no', 'like', "%{$query}%")
                      ->orWhere('board', $query);
                })
                ->limit(20)
                ->get();
        }
        
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

        // Get ban history for IP — use host_hash for lookup
        $bans = Db::table('banned_users')
            ->select('id', 'board', 'reason', 'now', 'length', 'admin', 'active')
            ->where('host_hash', $ipHash)
            ->orderBy('now', 'desc')
            ->limit(10)
            ->get();
        
        // Get reports from IP — use ip_hash for lookup
        $reports = Db::table('reports')
            ->select('id', 'board', 'no', 'ts', 'cleared')
            ->where('ip_hash', $ipHash)
            ->orderBy('ts', 'desc')
            ->limit(10)
            ->get();
        
        // Get user actions — use ip_hash for lookup
        $actions = Db::table('user_actions')
            ->select('action', 'board', 'postno', 'time')
            ->where('ip_hash', $ipHash)
            ->orderBy('time', 'desc')
            ->limit(10)
            ->get();
        
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
            'ban_count' => count($bans),
            'report_count' => count($reports),
        ];
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
                $matched = @preg_match('/' . (string) $filter->pattern . '/i', $content);
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
}
