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

namespace App\Service;

use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * SFS (StopForumSpam) Submission Service with on-the-fly PII decryption.
 *
 * Workflow:
 * 1. Staff member flags a post for SFS reporting → entry created in sfs_pending_reports
 * 2. Admin reviews the pending queue (encrypted IPs shown as masked)
 * 3. Admin approves submission → this service decrypts IP in-memory, sends to SFS
 * 4. Plaintext IP is NEVER written to disk or logs
 * 5. Audit trail records: who approved, when, report ID (without the decrypted IP)
 *
 * This process is disclosed in the Terms of Service.
 */
final class SfsSubmissionService
{
    private LoggerInterface $logger;

    public function __construct(
        private PiiEncryptionService $encryption,
        private StopForumSpamService $sfsService,
        LoggerFactory $loggerFactory
    ) {
        $this->logger = $loggerFactory->get('sfs-submission');
    }

    /**
     * List pending SFS reports for admin review.
     *
     * IPs are shown masked (only last octet hidden) for context.
     * Full decryption only happens on approval.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPendingReports(int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;

        $reports = Db::select(
            "SELECT id, post_id, board_slug, ip_address, post_content, 
                    evidence_snapshot, reporter_id, status, created_at
             FROM sfs_pending_reports 
             WHERE status = 'pending'
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );

        $result = [];
        foreach ($reports as $report) {
            /** @var object $report */
            $ipRaw = is_string($report->ip_address) ? $report->ip_address : '';

            // Decrypt IP for masking (show partial for admin context)
            $decryptedIp = $this->encryption->decrypt($ipRaw);
            $maskedIp = $this->maskIp($decryptedIp);

            // Wipe the decrypted IP from memory
            $this->encryption->wipe($decryptedIp);

            $evidence = null;
            if (is_string($report->evidence_snapshot)) {
                $evidence = json_decode($report->evidence_snapshot, true);
            }

            $result[] = [
                'id'               => $report->id,
                'post_id'          => $report->post_id,
                'board_slug'       => $report->board_slug,
                'ip_masked'        => $maskedIp,
                'post_content'     => $report->post_content,
                'evidence_snapshot' => $evidence,
                'reporter_id'      => $report->reporter_id,
                'status'           => $report->status,
                'created_at'       => $report->created_at,
            ];
        }

        return $result;
    }

    /**
     * Approve an SFS report: decrypt PII and submit to StopForumSpam.
     *
     * This is the critical path where encrypted PII is decrypted in-memory
     * and sent over HTTPS to the SFS API. The plaintext is never persisted.
     *
     * @param int    $reportId     SFS pending report ID
     * @param string $adminUserId  Staff member approving the submission
     * @return array{success: bool, message: string}
     */
    public function approveAndSubmit(int $reportId, string $adminUserId): array
    {
        // Fetch the pending report
        $reports = Db::select(
            "SELECT * FROM sfs_pending_reports WHERE id = ? AND status = 'pending'",
            [$reportId]
        );

        if (empty($reports)) {
            return ['success' => false, 'message' => 'Report not found or already processed'];
        }

        /** @var object $report */
        $report = $reports[0];

        // Decrypt the IP address in memory
        $encryptedIp = is_string($report->ip_address) ? $report->ip_address : '';
        $decryptedIp = $this->encryption->decrypt($encryptedIp);

        if ($decryptedIp === '[DECRYPTION_FAILED]' || $decryptedIp === '') {
            $this->logSfsAction($reportId, $adminUserId, 'decrypt_failed');
            return ['success' => false, 'message' => 'Failed to decrypt IP address'];
        }

        try {
            // Extract evidence for SFS submission
            $postContent = is_string($report->post_content) ? $report->post_content : '';
            $username = 'Anonymous'; // Default for imageboards

            // Try to extract username from evidence snapshot
            if (is_string($report->evidence_snapshot)) {
                $evidence = json_decode($report->evidence_snapshot, true);
                if (is_array($evidence) && isset($evidence['author_name']) && is_string($evidence['author_name'])) {
                    $username = $evidence['author_name'];
                }
            }

            // Submit to SFS — IP is in plaintext only in-memory at this point
            $this->sfsService->report(
                $decryptedIp,
                '', // Email - not typically available on imageboards
                $username,
                $postContent
            );

            // Immediately wipe the decrypted IP from memory
            $this->encryption->wipe($decryptedIp);

            // Mark report as approved
            Db::update(
                "UPDATE sfs_pending_reports SET status = 'approved', updated_at = NOW() WHERE id = ?",
                [$reportId]
            );

            // Audit trail (WITHOUT the IP)
            $this->logSfsAction($reportId, $adminUserId, 'approved_and_submitted');

            $this->logger->info("SFS report #{$reportId} approved and submitted by {$adminUserId}");

            return ['success' => true, 'message' => 'Report submitted to StopForumSpam'];
        } catch (\Throwable $e) {
            // Wipe decrypted IP even on failure
            $this->encryption->wipe($decryptedIp);

            $this->logSfsAction($reportId, $adminUserId, 'submission_failed');
            $this->logger->error("SFS submission failed for report #{$reportId}: " . $e->getMessage());

            return ['success' => false, 'message' => 'Failed to submit to StopForumSpam'];
        }
    }

    /**
     * Reject an SFS report (admin decides not to submit to SFS).
     *
     * @param int    $reportId     SFS pending report ID
     * @param string $adminUserId  Staff member rejecting
     * @param string $reason       Reason for rejection
     * @return array{success: bool, message: string}
     */
    public function rejectReport(int $reportId, string $adminUserId, string $reason = ''): array
    {
        $affected = Db::update(
            "UPDATE sfs_pending_reports SET status = 'rejected', updated_at = NOW() WHERE id = ? AND status = 'pending'",
            [$reportId]
        );

        if ($affected === 0) {
            return ['success' => false, 'message' => 'Report not found or already processed'];
        }

        $this->logSfsAction($reportId, $adminUserId, 'rejected', $reason);
        $this->logger->info("SFS report #{$reportId} rejected by {$adminUserId}: {$reason}");

        return ['success' => true, 'message' => 'Report rejected'];
    }

    /**
     * Queue a post for SFS review.
     *
     * Called by mod tools when flagging a post for SFS submission.
     * Stores the IP encrypted — it will only be decrypted on admin approval.
     *
     * @param int    $postId       Post being reported
     * @param string $boardSlug    Board slug
     * @param string $ipAddress    Raw IP (will be encrypted before storage)
     * @param string $postContent  Content of the flagged post
     * @param string $reporterId   Staff member flagging
     * @param array<string, mixed> $evidence  Additional evidence (headers, UA, etc.)
     * @return int Report ID
     */
    public function queueForReview(
        int $postId,
        string $boardSlug,
        string $ipAddress,
        string $postContent,
        string $reporterId,
        array $evidence = []
    ): int {
        // Encrypt the IP before storage
        $encryptedIp = $this->encryption->encrypt($ipAddress);

        // Wipe the plaintext IP from the parameter
        $this->encryption->wipe($ipAddress);

        Db::insert(
            "INSERT INTO sfs_pending_reports (post_id, board_slug, ip_address, post_content, evidence_snapshot, reporter_id, status, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [
                $postId,
                $boardSlug,
                $encryptedIp,
                $postContent,
                json_encode($evidence),
                $reporterId,
            ]
        );

        $result = Db::select("SELECT lastval() as id");
        /** @var object{id: int} $row */
        $row = $result[0];
        $id = (int) $row->id;

        $this->logSfsAction($id, $reporterId, 'queued');
        $this->logger->info("Post #{$postId} queued for SFS review (report #{$id})");

        return $id;
    }

    /**
     * Mask an IP address for display purposes.
     *
     * Shows enough context for admin review but not the full IP.
     * IPv4: 192.168.1.xxx
     * IPv6: 2001:db8:85a3::xxxx
     */
    private function maskIp(string $ip): string
    {
        if ($ip === '' || $ip === '[DECRYPTION_FAILED]') {
            return '[encrypted]';
        }

        if (str_contains($ip, ':')) {
            // IPv6 — mask last group
            $parts = explode(':', $ip);
            $parts[count($parts) - 1] = 'xxxx';
            return implode(':', $parts);
        }

        // IPv4 — mask last octet
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = 'xxx';
            return implode('.', $parts);
        }

        return '[masked]';
    }

    /**
     * Log an SFS workflow action to the audit trail.
     * NEVER logs the actual IP address.
     */
    private function logSfsAction(int $reportId, string $adminUserId, string $action, string $reason = ''): void
    {
        try {
            Db::insert(
                "INSERT INTO sfs_audit_log (report_id, admin_user_id, action, reason, created_at) VALUES (?, ?, ?, ?, NOW())",
                [$reportId, $adminUserId, $action, $reason]
            );
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to log SFS action: " . $e->getMessage());
        }
    }
}
