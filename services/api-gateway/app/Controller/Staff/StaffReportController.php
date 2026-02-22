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

use App\Controller\AbstractController;
use App\Service\ModerationService;
use App\Service\ProxyClient;
use App\Service\ViewService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * StaffReportController - Report queue interface for staff
 * 
 * Ported from OpenYotsuba/reports/ReportQueue.php
 * Provides the web interface for janitors/mods to manage reports
 */
#[Controller(prefix: '/staff/reports')]
class StaffReportController extends AbstractController
{
    public function __construct(
        private ModerationService $modService,
        private HttpResponse $response,
        private ViewService $viewService,
        private ProxyClient $proxyClient,
    ) {}

    /**
     * GET /staff/reports - Main report queue page
     * Ported from OpenYotsuba/reports/views/reportqueue.tpl.php
     */
    #[GetMapping(path: '')]
    public function index(RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        // Get query parameters
        $board = $request->query('board', '');
        $cleared = (bool) $request->query('cleared', false);
        
        // Get board list
        $boardlist = $this->getBoardList();
        
        // Determine access level
        $isMod = $staffInfo['is_mod'];
        $isManager = $staffInfo['is_manager'];
        $isAdmin = $staffInfo['is_admin'];
        
        $html = $this->viewService->render('staff/reports/index', [
            'boardlist' => $boardlist,
            'access' => [
                'board' => $staffInfo['boards'],
                'is_mod' => $isMod,
                'is_manager' => $isManager,
                'is_admin' => $isAdmin,
            ],
            'isMod' => $isMod,
            'isManager' => $isManager,
            'isAdmin' => $isAdmin,
            'currentBoard' => $board,
            'cleared_only' => $cleared,
            'username' => $staffInfo['username'],
        ]);

        return $this->html($this->response, $html);
    }

    /**
     * GET /staff/reports/data - Fetch report data for AJAX
     */
    #[GetMapping(path: 'data')]
    public function data(RequestInterface $request): ResponseInterface
    {
        $board = $request->query('board');
        $cleared = (bool) $request->query('cleared', false);
        $page = max(1, (int) $request->query('page', 1));
        
        $data = $this->modService->getReportQueue(
            is_string($board) && $board !== '' ? $board : null,
            $cleared,
            $page,
            25
        );

        // Enrich reports with post media data from boards service
        /** @var array<int, array<string, mixed>> $reports */
        $reports = $data['reports'] ?? [];
        $data['reports'] = $this->enrichReportsWithMedia($reports);
        
        return $this->response->json($data);
    }

    /**
     * Fetch real post media (thumb_url, subject, comment) from the boards service
     * and merge it into the report data so the catalog UI can show thumbnails.
     *
     * @param array<int, array<string, mixed>> $reports
     * @return array<int, array<string, mixed>>
     */
    private function enrichReportsWithMedia(array $reports): array
    {
        if (count($reports) === 0) {
            return $reports;
        }

        // Collect unique board:no pairs
        $lookups = [];
        foreach ($reports as $r) {
            $key = (string) ($r['board'] ?? '') . ':' . (int) ($r['no'] ?? 0);
            if (!isset($lookups[$key])) {
                $lookups[$key] = ['board' => $r['board'], 'no' => (int) $r['no']];
            }
        }

        // Call the boards service bulk lookup
        $body = json_encode(['posts' => array_values($lookups)]);
        if ($body === false) {
            return $reports;
        }

        try {
            $resp = $this->proxyClient->forward(
                'boards',
                'POST',
                '/api/v1/posts/lookup',
                ['Content-Type' => 'application/json'],
                $body,
            );

            if ($resp['status'] !== 200) {
                return $reports;
            }

            $payload = json_decode((string) ($resp['body']), true);
            $results = is_array($payload) && isset($payload['results']) && is_array($payload['results'])
                ? $payload['results']
                : [];
        } catch (\Throwable) {
            return $reports;
        }

        // Merge media data into each report's post object
        foreach ($reports as &$report) {
            $key = (string) ($report['board'] ?? '') . ':' . (int) ($report['no'] ?? 0);
            if (!isset($results[$key])) {
                continue;
            }
            $media = $results[$key];
            $post = is_array($report['post'] ?? null) ? $report['post'] : [];
            $post['thumb_url'] = $media['thumb_url'] ?? null;
            $post['media_url'] = $media['media_url'] ?? null;
            $post['media_filename'] = $media['media_filename'] ?? null;
            $post['media_dimensions'] = $media['media_dimensions'] ?? null;
            $post['spoiler_image'] = $media['spoiler_image'] ?? false;

            // Use real subject/comment if the stored post_json stub was empty
            if (empty($post['sub']) && !empty($media['sub'])) {
                $post['sub'] = $media['sub'];
            }
            if (empty($post['com']) && !empty($media['com'])) {
                $post['com'] = $media['com'];
            }

            $report['post'] = $post;
        }
        unset($report);

        return $reports;
    }

    /**
     * POST /staff/reports/{id}/clear - Clear a report
     */
    #[PostMapping(path: '{id:\d+}/clear')]
    public function clear(int $id, RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        try {
            $this->modService->clearReport($id, $staffInfo['username']);
            return $this->response->json(['status' => 'success']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'], 500);
        }
    }

    /**
     * POST /staff/reports/{id}/delete - Delete a report
     */
    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        try {
            $this->modService->deleteReport($id);
            return $this->response->json(['status' => 'success']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'], 500);
        }
    }

    /**
     * GET /staff/reports/ban-requests - Ban requests page
     */
    #[GetMapping(path: 'ban-requests')]
    public function banRequests(RequestInterface $request): ResponseInterface
    {
        $board = $request->query('board');
        $data = $this->modService->getBanRequests(
            is_string($board) && $board !== '' ? $board : null
        );
        
        $html = $this->viewService->render('staff/reports/ban-requests', [
            'requests' => $data['requests'],
            'count' => $data['count'],
            'boardlist' => $this->getBoardList(),
            'isMod' => (bool) ($this->getStaffInfo()['is_mod']),
        ]);

        return $this->html($this->response, $html);
    }

    /**
     * POST /staff/reports/ban-requests/{id}/approve - Approve ban request
     */
    #[PostMapping(path: 'ban-requests/{id:\d+}/approve')]
    public function approveBanRequest(int $id, RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_mod']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        try {
            $ban = $this->modService->approveBanRequest($id, $staffInfo['username']);
            return $this->response->json([
                'status' => 'success',
                'ban' => $ban->getSummary(),
            ]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'], 500);
        }
    }

    /**
     * POST /staff/reports/ban-requests/{id}/deny - Deny ban request
     */
    #[PostMapping(path: 'ban-requests/{id:\d+}/deny')]
    public function denyBanRequest(int $id, RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_mod']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        try {
            $this->modService->denyBanRequest($id, $staffInfo['username']);
            return $this->response->json(['status' => 'success']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'], 500);
        }
    }
}
