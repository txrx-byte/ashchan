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

use App\Service\SfsSubmissionService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Admin-only controller for the SFS pending reports queue.
 *
 * All endpoints require admin-level authentication.
 * Decryption of PII only occurs when an admin explicitly approves a report.
 */
#[Controller(prefix: '/api/v1/admin/sfs')]
final class SfsQueueController
{
    public function __construct(
        private SfsSubmissionService $sfsSubmission,
        private HttpResponse $response,
    ) {}

    /**
     * GET /api/v1/admin/sfs/queue - List pending SFS reports (IPs masked).
     */
    #[GetMapping(path: 'queue')]
    public function listQueue(RequestInterface $request): ResponseInterface
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $reports = $this->sfsSubmission->listPendingReports($page, $perPage);

        return $this->response->json([
            'status' => 'success',
            'data' => $reports,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * POST /api/v1/admin/sfs/queue/{id}/approve - Decrypt IP and submit to SFS.
     *
     * This is the critical endpoint where encrypted PII is decrypted in-memory
     * and sent to StopForumSpam. Requires admin role.
     */
    #[PostMapping(path: 'queue/{id:\d+}/approve')]
    public function approveReport(RequestInterface $request, int $id): ResponseInterface
    {
        // Extract admin identity from auth context
        $adminUserId = $request->input('admin_user_id', '');
        if (!is_string($adminUserId) || $adminUserId === '') {
            return $this->response->json(['error' => 'Admin identity required'], 401);
        }

        $result = $this->sfsSubmission->approveAndSubmit($id, $adminUserId);

        $statusCode = $result['success'] ? 200 : 400;
        return $this->response->json($result, $statusCode);
    }

    /**
     * POST /api/v1/admin/sfs/queue/{id}/reject - Reject an SFS report.
     */
    #[PostMapping(path: 'queue/{id:\d+}/reject')]
    public function rejectReport(RequestInterface $request, int $id): ResponseInterface
    {
        $adminUserId = $request->input('admin_user_id', '');
        if (!is_string($adminUserId) || $adminUserId === '') {
            return $this->response->json(['error' => 'Admin identity required'], 401);
        }

        $reason = $request->input('reason', '');
        if (!is_string($reason)) {
            $reason = '';
        }

        $result = $this->sfsSubmission->rejectReport($id, $adminUserId, $reason);

        $statusCode = $result['success'] ? 200 : 400;
        return $this->response->json($result, $statusCode);
    }

    /**
     * POST /api/v1/admin/sfs/queue - Queue a post for SFS review.
     *
     * Called by mod tools when flagging a post for SFS submission.
     */
    #[PostMapping(path: 'queue')]
    public function queueReport(RequestInterface $request): ResponseInterface
    {
        $postId = $request->input('post_id');
        $boardSlug = $request->input('board_slug');
        $ipAddress = $request->input('ip_address');
        $postContent = $request->input('post_content', '');
        $reporterId = $request->input('reporter_id', '');

        if (!is_numeric($postId) || (int) $postId === 0) {
            return $this->response->json(['error' => 'Invalid post_id'], 400);
        }
        if (!is_string($boardSlug) || $boardSlug === '') {
            return $this->response->json(['error' => 'Board slug required'], 400);
        }
        if (!is_string($ipAddress) || $ipAddress === '') {
            return $this->response->json(['error' => 'IP address required'], 400);
        }
        if (!is_string($postContent)) {
            $postContent = '';
        }
        if (!is_string($reporterId)) {
            $reporterId = 'unknown';
        }

        // Collect evidence metadata
        $evidence = [
            'user_agent' => $request->header('user-agent', ''),
            'reported_at' => date('c'),
        ];

        $reportId = $this->sfsSubmission->queueForReview(
            (int) $postId,
            $boardSlug,
            $ipAddress,
            $postContent,
            $reporterId,
            $evidence
        );

        return $this->response->json([
            'status' => 'success',
            'report_id' => $reportId,
            'message' => 'Post queued for SFS review',
        ], 201);
    }
}
