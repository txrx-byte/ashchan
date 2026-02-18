<?php
declare(strict_types=1);

namespace App\Controller\Staff;

use App\Service\ModerationService;
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
        $query = $this->request->query('q', '');
        $type = $this->request->query('type', 'all');
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
        $ip = $this->request->query('ip', '');
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
        $md5 = $this->request->query('md5', '');
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
        $comment = $this->request->query('comment', '');
        $name = $this->request->query('name', '');
        $subject = $this->request->query('subject', '');
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
        $staff = Db::table('mod_users')
            ->select('username', 'level', 'flags', 'last_login', 'ips')
            ->orderBy('level', 'desc')
            ->orderBy('username')
            ->get();
        
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
        
        $query = Db::table('flood_log')
            ->select('ip', 'board', 'thread_id', 'req_sig', 'created_on')
            ->orderBy('created_on', 'desc')
            ->limit(100);
        
        if ($ip !== '') {
            $query->where('ip', $ip);
        }
        if ($board !== '') {
            $query->where('board', $board);
        }
        
        $logs = $query->get();
        
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
        
        $query = Db::table('event_log')
            ->select('type', 'ip', 'board', 'thread_id', 'post_id', 'arg_str', 'pwd', 'created_on')
            ->orderBy('created_on', 'desc')
            ->limit(100);
        
        if ($username !== '') {
            $query->where('pwd', $username);
        }
        if ($board !== '') {
            $query->where('board', $board);
        }
        if ($action !== '') {
            $query->where('type', $action);
        }
        
        $logs = $query->get();
        
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
        $logs = Db::table('user_actions')
            ->select('action', 'ip', 'board', 'postno', 'time')
            ->where('action', 'delete')
            ->orderBy('time', 'desc')
            ->limit(100)
            ->get();
        
        $html = $this->viewService->render('staff/tools/userdellog', [
            'logs' => $logs,
        ]);
        return $this->response->html($html);
    }

    /**
     * Perform search across tables
     */
    private function performSearch(string $query, string $type): array
    {
        $results = [
            'bans' => [],
            'reports' => [],
            'posts' => [],
        ];
        
        // Search bans
        if ($type === 'all' || $type === 'bans') {
            $results['bans'] = Db::table('banned_users')
                ->select('id', 'board', 'host', 'reason', 'now', 'length', 'admin')
                ->where('host', 'like', "%{$query}%")
                ->orWhere('reason', 'like', "%{$query}%")
                ->limit(20)
                ->get();
        }
        
        // Search reports
        if ($type === 'all' || $type === 'reports') {
            $results['reports'] = Db::table('reports')
                ->select('id', 'board', 'no', 'post_json', 'ts')
                ->where('no', 'like', "%{$query}%")
                ->orWhere('board', $query)
                ->limit(20)
                ->get();
        }
        
        return $results;
    }

    /**
     * Get IP information
     */
    private function getIpInfo(string $ip): array
    {
        // Get ban history for IP
        $bans = Db::table('banned_users')
            ->select('id', 'board', 'reason', 'now', 'length', 'admin', 'active')
            ->where('host', $ip)
            ->orderBy('now', 'desc')
            ->limit(10)
            ->get();
        
        // Get reports from IP
        $reports = Db::table('reports')
            ->select('id', 'board', 'no', 'ts', 'cleared')
            ->where('ip', $ip)
            ->orderBy('ts', 'desc')
            ->limit(10)
            ->get();
        
        // Get user actions
        $actions = Db::table('user_actions')
            ->select('action', 'board', 'postno', 'time')
            ->where('ip', $ip)
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
            'blacklist_reason' => $blacklisted['banreason'] ?? null,
        ];
    }

    /**
     * Test post against filters
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
            
            if ($filter['regex']) {
                $matched = @preg_match('/' . $filter['pattern'] . '/i', $content);
            } else {
                $matched = stripos($content, $filter['pattern']) !== false;
            }
            
            if ($matched) {
                $matches[] = [
                    'id' => $filter['id'],
                    'pattern' => $filter['pattern'],
                    'board' => $filter['board'],
                    'ban_days' => $filter['ban_days'],
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
