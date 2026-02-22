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


namespace App\Controller;

use App\Model\BanTemplate;
use App\Model\Report;
use App\Model\ReportCategory;
use App\Service\ModerationService;
use App\Service\SpamService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * ModerationController - ported from OpenYotsuba reports and admin systems.
 *
 * Handles report submission, queue management, ban requests, and moderation actions.
 */
#[Controller(prefix: '/api/v1')]
final class ModerationController extends AbstractController
{
    public function __construct(
        private ModerationService $modService,
        private SpamService $spamService,
        private \App\Service\PiiEncryptionService $piiEncryption,
        private HttpResponse $response,
    ) {}

    /* ──────────────────────────────────────────────
     * Report Categories (for report form)
     * ────────────────────────────────────────────── */

    /**
     * GET /api/v1/report-categories - Get available report categories
     */
    #[GetMapping(path: 'report-categories')]
    public function getReportCategories(RequestInterface $request): ResponseInterface
    {
        $board = $request->query('board', '');
        $isWorksafe = (bool) $request->query('ws', false);

        if (!is_string($board)) {
            $board = '';
        }

        $categories = ReportCategory::getForReportForm($board, $isWorksafe);

        return $this->response->json([
            'categories' => $categories,
        ]);
    }

    /* ──────────────────────────────────────────────
     * Reports - Public Submission
     * ────────────────────────────────────────────── */

    /**
     * POST /api/v1/reports - Submit a new report
     */
    #[PostMapping(path: 'reports')]
    public function createReport(RequestInterface $request): ResponseInterface
    {
        $postId = $request->input('post_id');
        $board = $request->input('board');
        $categoryId = $request->input('category_id');
        $captchaToken = $request->input('captcha_token');

        // Validation
        if (!is_numeric($postId) || (int) $postId === 0) {
            return $this->response->json(['error' => 'Invalid post_id']);
        }
        if (!is_string($board) || $board === '') {
            return $this->response->json(['error' => 'Invalid board']);
        }
        if (!is_numeric($categoryId) || (int) $categoryId === 0) {
            return $this->response->json(['error' => 'Invalid category_id']);
        }

        // Verify captcha (if required)
        if ($captchaToken !== null) {
            // Captcha verification would go here
        }

        // Get post data (would fetch from boards service in production)
        $postData = [
            'no' => (int) $postId,
            'resto' => 0,
            'com' => '',
            'host' => '',
        ];

        // Get reporter info — encrypt IP for storage, hash for lookups
        $remoteAddr = $request->server('remote_addr', '');
        $ip = is_string($remoteAddr) ? $remoteAddr : '';
        $encryptedIp = $this->piiEncryption->encrypt($ip);
        $ipHash = hash('sha256', $ip);

        // Get request signature for spam filtering
        $reqSig = $this->buildRequestSignature($request);

        try {
            $report = $this->modService->createReport(
                (int) $postId,
                $board,
                (int) $categoryId,
                $postData,
                $encryptedIp,
                $ipHash,
                null, // pwd
                null, // pass_id
                $reqSig
            );

            return $this->response->json([
                'status' => 'success',
                'report' => [
                    'id' => $report->getAttribute('id'),
                    'post_id' => $report->getAttribute('no'),
                    'board' => $report->getAttribute('board'),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->response->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Failed to submit report'], 500);
        }
    }

    /* ──────────────────────────────────────────────
     * Report Queue - Staff Only
     * ────────────────────────────────────────────── */

    /**
     * GET /api/v1/reports - Get report queue (staff only)
     */
    #[GetMapping(path: 'reports')]
    public function listReports(RequestInterface $request): ResponseInterface
    {
        // In production, verify staff authentication here
        $board = $request->query('board');
        $cleared = (bool) $request->query('cleared', false);
        $page = max(1, (int) $request->query('page', 1));

        $data = $this->modService->getReportQueue(
            is_string($board) ? $board : null,
            $cleared,
            $page
        );

        return $this->response->json($data);
    }

    /**
     * GET /api/v1/reports/count - Get report counts by board
     */
    #[GetMapping(path: 'reports/count')]
    public function countReports(): ResponseInterface
    {
        $counts = $this->modService->countReportsByBoard();

        return $this->response->json([
            'counts' => $counts,
            'total' => array_sum($counts),
        ]);
    }

    /**
     * POST /api/v1/reports/{id}/clear - Clear a report (staff only)
     */
    #[PostMapping(path: 'reports/{id:\d+}/clear')]
    public function clearReport(RequestInterface $request, int $id): ResponseInterface
    {
        // Use authenticated staff username from session context
        $staffUsername = $this->getAuthenticatedStaffUsername();
        if ($staffUsername === null) {
            return $this->response->json(['error' => 'Staff authentication required'], 401);
        }

        try {
            $this->modService->clearReport($id, $staffUsername);
            return $this->response->json(['status' => 'cleared']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/v1/reports/{id} - Delete a report (staff only)
     */
    #[\Hyperf\HttpServer\Annotation\DeleteMapping(path: 'reports/{id:\d+}')]
    public function deleteReport(int $id): ResponseInterface
    {
        try {
            $this->modService->deleteReport($id);
            return $this->response->json(['status' => 'deleted']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ──────────────────────────────────────────────
     * Ban Requests - Staff Only
     * ────────────────────────────────────────────── */

    /**
     * POST /api/v1/ban-requests - Create a ban request (janitor)
     */
    #[PostMapping(path: 'ban-requests')]
    public function createBanRequest(RequestInterface $request): ResponseInterface
    {
        // Use authenticated staff username from session context
        $janitorUsername = $this->getAuthenticatedStaffUsername();
        if ($janitorUsername === null) {
            return $this->response->json(['error' => 'Staff authentication required'], 401);
        }

        $board = $request->input('board');
        $postNo = $request->input('post_no');
        $templateId = $request->input('template_id');
        $reason = $request->input('reason');

        // Validation
        if (!is_string($board) || $board === '') {
            return $this->response->json(['error' => 'Invalid board']);
        }
        if (!is_numeric($postNo) || (int) $postNo === 0) {
            return $this->response->json(['error' => 'Invalid post_no']);
        }
        if (!is_numeric($templateId) || (int) $templateId === 0) {
            return $this->response->json(['error' => 'Invalid template_id']);
        }

        // Get post data
        $postData = $request->input('post_data', []);
        if (!is_array($postData)) {
            $postData = [];
        }
        /** @var array<string, mixed> $postData */

        try {
            $banRequest = $this->modService->createBanRequest(
                $board,
                (int) $postNo,
                $janitorUsername,
                (int) $templateId,
                $postData,
                is_string($reason) ? $reason : ''
            );

            return $this->response->json([
                'status' => 'success',
                'request' => $banRequest->toArray(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->response->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Failed to create ban request'], 500);
        }
    }

    /**
     * GET /api/v1/ban-requests - Get pending ban requests (staff only)
     */
    #[GetMapping(path: 'ban-requests')]
    public function getBanRequests(RequestInterface $request): ResponseInterface
    {
        $board = $request->query('board');

        $data = $this->modService->getBanRequests(
            is_string($board) ? $board : null
        );

        return $this->response->json($data);
    }

    /**
     * POST /api/v1/ban-requests/{id}/approve - Approve ban request (mod+)
     */
    #[PostMapping(path: 'ban-requests/{id:\d+}/approve')]
    public function approveBanRequest(RequestInterface $request, int $id): ResponseInterface
    {
        // Use authenticated staff username from session context
        $approverUsername = $this->getAuthenticatedStaffUsername();
        if ($approverUsername === null) {
            return $this->response->json(['error' => 'Staff authentication required'], 401);
        }

        try {
            $ban = $this->modService->approveBanRequest($id, $approverUsername);
            return $this->response->json([
                'status' => 'approved',
                'ban' => $ban->getSummary(),
            ]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/ban-requests/{id}/deny - Deny ban request (mod+)
     */
    #[PostMapping(path: 'ban-requests/{id:\d+}/deny')]
    public function denyBanRequest(RequestInterface $request, int $id): ResponseInterface
    {
        // Use authenticated staff username from session context
        $denierUsername = $this->getAuthenticatedStaffUsername();
        if ($denierUsername === null) {
            return $this->response->json(['error' => 'Staff authentication required'], 401);
        }

        try {
            $this->modService->denyBanRequest($id, $denierUsername);
            return $this->response->json(['status' => 'denied']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ──────────────────────────────────────────────
     * Ban Templates - Manager Only
     * ────────────────────────────────────────────── */

    /**
     * GET /api/v1/ban-templates - Get ban templates
     */
    #[GetMapping(path: 'ban-templates')]
    public function getBanTemplates(RequestInterface $request): ResponseInterface
    {
        $activeOnly = (bool) $request->query('active', true);
        $board = $request->query('board');

        $query = BanTemplate::query();

        if ($activeOnly) {
            $query->where('active', 1);
        }

        if (is_string($board) && $board !== '') {
            // Escape LIKE wildcards (backslashes first, then % and _)
            $escapedBoard = addcslashes($board, '\\%_');
            $query->where(function ($q) use ($escapedBoard) {
                $q->where('boards', '')
                  ->orWhere('boards', 'like', "%{$escapedBoard}%");
            });
        }

        $templates = $query->orderBy('rule')->orderBy('name')->get();

        return $this->response->json([
            'templates' => $templates->toArray(),
        ]);
    }

    /**
     * POST /api/v1/ban-templates - Create ban template (manager only)
     */
    #[PostMapping(path: 'ban-templates')]
    public function createBanTemplate(RequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();

        // Required fields
        $required = ['rule', 'name', 'ban_type', 'ban_days', 'public_reason'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return $this->response->json(['error' => "Missing field: {$field}"], 400);
            }
        }

        try {
            $template = BanTemplate::create($data);
            return $this->response->json([
                'status' => 'created',
                'template' => $template->toArray(),
            ], 201);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Failed to create template'], 500);
        }
    }

    /**
     * PUT /api/v1/ban-templates/{id} - Update ban template (manager only)
     */
    #[\Hyperf\HttpServer\Annotation\PutMapping(path: 'ban-templates/{id:\d+}')]
    public function updateBanTemplate(RequestInterface $request, int $id): ResponseInterface
    {
        $template = BanTemplate::find($id);
        if (!$template) {
            return $this->response->json(['error' => 'Template not found'], 404);
        }

        $data = $request->all();

        try {
            $template->update($data);
            $fresh = $template->fresh();
            return $this->response->json([
                'status' => 'updated',
                'template' => $fresh ? $fresh->toArray() : $template->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Failed to update template'], 500);
        }
    }

    /* ──────────────────────────────────────────────
     * Bans - Check & Management
     * ────────────────────────────────────────────── */

    /**
     * POST /api/v1/bans/check - Check if IP/pass is banned
     */
    #[PostMapping(path: 'bans/check')]
    public function checkBan(RequestInterface $request): ResponseInterface
    {
        $board = $request->input('board');
        $ip = $request->input('ip');
        $passId = $request->input('pass_id');

        if (!is_string($board) || !is_string($ip)) {
            return $this->response->json(['error' => 'board and ip required']);
        }

        $result = $this->modService->checkBan(
            $board,
            $ip,
            is_string($passId) ? $passId : null
        );

        return $this->response->json($result);
    }

    /**
     * POST /api/v1/bans - Create ban (staff only)
     */
    #[PostMapping(path: 'bans')]
    public function createBan(RequestInterface $request): ResponseInterface
    {
        // Use authenticated staff username from session context
        $adminUsername = $this->getAuthenticatedStaffUsername();
        if ($adminUsername === null) {
            return $this->response->json(['error' => 'Staff authentication required'], 401);
        }

        $templateId = $request->input('template_id');
        $board = $request->input('board');
        $postNo = $request->input('post_no');
        $targetIp = $request->input('ip');
        $passId = $request->input('pass_id');
        $customReason = $request->input('reason');

        // Validation
        if (!is_numeric($templateId) || (int) $templateId === 0) {
            return $this->response->json(['error' => 'Invalid template_id']);
        }
        if (!is_string($board)) {
            return $this->response->json(['error' => 'Invalid board']);
        }
        if (!is_numeric($postNo)) {
            return $this->response->json(['error' => 'Invalid post_no']);
        }

        $template = BanTemplate::find((int) $templateId);
        if (!$template) {
            return $this->response->json(['error' => 'Template not found'], 404);
        }

        try {
            $ban = $this->modService->createBanFromTemplate(
                $template,
                $board,
                (int) $postNo,
                $adminUsername,
                is_string($customReason) ? $customReason : '',
                [], // postData
                is_string($targetIp) ? $targetIp : null,
                is_string($passId) ? $passId : null
            );

            return $this->response->json([
                'status' => 'created',
                'ban' => $ban->getSummary(),
            ], 201);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/bans/{id}/unban - Unban a user (staff only)
     */
    #[PostMapping(path: 'bans/{id:\d+}/unban')]
    public function unban(RequestInterface $request, int $id): ResponseInterface
    {
        // Use authenticated staff username from session context
        $staffUsername = $this->getAuthenticatedStaffUsername();
        if ($staffUsername === null) {
            return $this->response->json(['error' => 'Staff authentication required'], 401);
        }

        $result = $this->modService->unbanUser($id, $staffUsername);

        if ($result) {
            return $this->response->json(['status' => 'unbanned']);
        }

        return $this->response->json(['error' => 'Ban not found'], 404);
    }

    /* ──────────────────────────────────────────────
     * Spam Check & Captcha
     * ────────────────────────────────────────────── */

    /**
     * POST /api/v1/spam/check - Check content for spam
     *
     * Accepts raw IP — hashes internally for rate-limiting keys.
     * Falls back to ip_hash for backward compatibility.
     */
    #[PostMapping(path: 'spam/check')]
    public function spamCheck(RequestInterface $request): ResponseInterface
    {
        $content = $request->input('content', '');
        $rawIp = $request->input('ip', '');
        $ipHash = $request->input('ip_hash', '');
        $isThread = (bool) $request->input('is_thread', false);
        $imageHash = $request->input('image_hash');

        if (!is_string($content)) {
            $content = '';
        }

        // Prefer raw IP (hash internally), fall back to pre-hashed for backward compat
        if (is_string($rawIp) && $rawIp !== '') {
            $ipHash = hash('sha256', $rawIp);
        } elseif (!is_string($ipHash) || $ipHash === '') {
            return $this->response->json(['error' => 'ip or ip_hash is required'], 400);
        }

        $result = $this->spamService->check(
            $ipHash,
            $content,
            $isThread,
            is_string($imageHash) ? $imageHash : null
        );

        return $this->response->json($result);
    }

    /**
     * GET /api/v1/captcha - Generate a captcha challenge
     */
    #[GetMapping(path: 'captcha')]
    public function captcha(): ResponseInterface
    {
        $captcha = $this->spamService->generateCaptcha();

        return $this->response->json([
            'token' => $captcha['token'],
            'question' => $captcha['question'],
        ]);
    }

    /**
     * POST /api/v1/captcha/verify - Verify a captcha response
     */
    #[PostMapping(path: 'captcha/verify')]
    public function verifyCaptcha(RequestInterface $request): ResponseInterface
    {
        $token = $request->input('token');
        $answer = $request->input('answer');

        if (!is_string($token) || $token === '' || !is_string($answer) || $answer === '') {
            return $this->response->json(['error' => 'token and answer required'], 400);
        }

        $valid = $this->spamService->verifyCaptcha($token, $answer);

        return $this->response->json([
            'valid' => $valid,
        ]);
    }

    /**
     * Build request signature for spam filtering
     * @return string
     */
    private function buildRequestSignature(RequestInterface $request): string
    {
        $headers = [
            'user-agent' => $request->getHeaderLine('User-Agent'),
            'accept-language' => $request->getHeaderLine('Accept-Language'),
        ];

        return hash('sha256', json_encode($headers) ?: '');
    }

    /**
     * Get the authenticated staff username from the session context.
     * Returns null if not authenticated.
     */
    private function getAuthenticatedStaffUsername(): ?string
    {
        /** @var array<string, mixed> $staffInfo */
        $staffInfo = \Hyperf\Context\Context::get('staff_info', []);
        $username = $staffInfo['username'] ?? null;
        return is_string($username) && $username !== '' ? $username : null;
    }
}
