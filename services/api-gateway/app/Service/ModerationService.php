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

use App\Model\BanRequest;
use App\Model\BanTemplate;
use App\Model\BannedUser;
use App\Model\ModerationDecision;
use App\Model\Report;
use App\Model\ReportCategory;
use App\Model\ReportClearLog;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;

/**
 * ModerationService - ported from OpenYotsuba ReportQueue and bans system.
 *
 * Handles report submission, queue management, ban requests, and moderation decisions.
 * Thresholds are configured via site_settings (admin panel).
 */
final class ModerationService
{
    #[Inject]
    private LoggerInterface $logger;

    #[Inject]
    private PiiEncryptionService $piiEncryption;

    // Admin-configurable moderation queue settings (loaded from site_settings)
    private int $globalThreshold;
    private int $highlightThreshold;
    private float $threadWeightBoost;
    private int $abuseClearDays;
    private int $abuseClearCount;
    private int $abuseClearBanInterval;
    private int $reportAbuseTemplateId;

    public function __construct(SiteConfigService $config)
    {
        $this->globalThreshold = $config->getInt('report_global_threshold', 1500);
        $this->highlightThreshold = $config->getInt('report_highlight_threshold', 500);
        $this->threadWeightBoost = $config->getFloat('thread_weight_boost', 1.25);
        $this->abuseClearDays = $config->getInt('abuse_clear_days', 3);
        $this->abuseClearCount = $config->getInt('abuse_clear_count', 50);
        $this->abuseClearBanInterval = $config->getInt('abuse_clear_ban_interval', 5);
        $this->reportAbuseTemplateId = $config->getInt('report_abuse_template_id', 190);
    }

    /**
     * Create a new report (port of report_submit from OpenYotsuba)
     *
     * @param array<string, mixed> $postData Post data snapshot
     * @param string $reporterIp Encrypted IP (admin-decryptable)
     * @param string $reporterIpHash SHA-256 hash of raw IP (for deterministic lookups)
     * @return Report
     */
    public function createReport(
        int $postId,
        string $board,
        int $categoryId,
        array $postData,
        string $reporterIp,
        string $reporterIpHash,
        ?string $reporterPwd = null,
        ?string $passId = null,
        ?string $reqSig = null
    ): Report {
        // Get category
        $category = ReportCategory::find($categoryId);
        if (!$category) {
            throw new \InvalidArgumentException('Invalid report category');
        }

        // Determine category type
        $catType = $categoryId === 31 ? Report::CAT_ILLEGAL : Report::CAT_RULE;

        // Calculate weight (may be reduced for abusive reporters)
        $weight = (float) $category->getAttribute('weight');

        // Check if reporter should be filtered (reduced weight)
        $ignoreReason = $this->checkReportFilter($categoryId, $reporterIpHash, $passId, $reporterPwd);
        if ($ignoreReason > 0) {
            $weight = 0.5;
        }

        // Check if post was previously reported and cleared
        $existingReport = Report::query()
            ->where('board', $board)
            ->where('no', $postId)
            ->where('cleared', 1)
            ->first();

        $isCleared = $existingReport ? 1 : 0;
        $clearedBy = $existingReport ? $existingReport->getAttribute('cleared_by') : '';

        // Log cleared reporter if re-reporting
        if ($isCleared) {
            $this->logClearedReporter($reporterIpHash, $reporterPwd, $passId, $categoryId, $weight);
        }

        // Determine if worksafe
        $isWorksafe = $this->isWorksafeBoard($board) ? 1 : 0;

        // Create report — ip is encrypted (admin-decryptable), ip_hash for lookups
        $report = Report::create([
            'ip' => $reporterIp,
            'ip_hash' => $reporterIpHash,
            'pwd' => $reporterPwd,
            'pass_id' => $passId,
            'req_sig' => $reqSig,
            'board' => $board,
            'no' => $postId,
            'resto' => (int) ($postData['resto'] ?? 0),
            'cat' => $catType,
            'weight' => $weight,
            'report_category' => $categoryId,
            'post_ip' => $postData['host'] ?? '',
            'post_json' => json_encode($postData),
            'cleared' => $isCleared,
            'cleared_by' => $clearedBy,
            'ws' => $isWorksafe,
            'ts' => now(),
        ]);

        // Log action
        $this->logger->info('Report created', [
            'report_id' => $report->getAttribute('id'),
            'board' => $board,
            'post_id' => $postId,
            'category' => $categoryId,
        ]);

        return $report;
    }

    /**
     * Get reports for queue display (port of fetch_reports from OpenYotsuba)
     *
     * @return array<string, mixed>
     */
    public function getReportQueue(
        ?string $board = null,
        bool $clearedOnly = false,
        int $page = 1,
        int $perPage = 25
    ): array {
        $query = Report::query()
            ->selectRaw('
                no,
                board,
                SUM(weight) as total_weight,
                COUNT(*) as cnt,
                STRING_AGG(DISTINCT report_category::text, \',\') as cats,
                MAX(ts) as time,
                MIN(id) as id,
                MIN(post_json) as post_json,
                MIN(resto) as resto,
                MIN(post_ip) as post_ip,
                MIN(ws) as ws,
                MIN(cleared_by) as cleared_by
            ')
            ->groupBy('no', 'board');

        if ($clearedOnly) {
            $query->where('cleared', 1);
        } else {
            $query->where('cleared', 0);
        }

        if ($board !== null) {
            $query->where('board', $board);
        }

        // Count distinct (no, board) groups for pagination
        $countBase = Report::query();
        if ($clearedOnly) {
            $countBase->where('cleared', 1);
        } else {
            $countBase->where('cleared', 0);
        }
        if ($board !== null) {
            $countBase->where('board', $board);
        }
        $total = (int) $countBase->selectRaw('COUNT(DISTINCT (no, board)) as cnt')->value('cnt');

        $reports = $query
            ->orderByDesc('total_weight')
            ->orderByDesc('time')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // Format reports
        $formatted = [];
        foreach ($reports as $report) {
            /** @var array<string, mixed> $reportArray */
            $reportArray = $report->toArray();
            $formatted[] = $this->formatReport($reportArray);
        }

        return [
            'reports' => $formatted,
            'total' => $total,
            'page' => $page,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Clear a report (mark as handled)
     */
    public function clearReport(int $reportId, string $staffUsername): bool
    {
        $report = Report::findOrFail($reportId);

        $report->update([
            'cleared' => 1,
            'cleared_by' => $staffUsername,
        ]);

        $this->logger->info('Report cleared', [
            'report_id' => $reportId,
            'cleared_by' => $staffUsername,
        ]);

        return true;
    }

    /**
     * Hard delete a report (remove from database)
     */
    public function deleteReport(int $reportId): bool
    {
        $report = Report::findOrFail($reportId);
        $report->delete();

        $this->logger->info('Report deleted', ['report_id' => $reportId]);

        return true;
    }

    /**
     * Create a ban request (for janitor approval)
     *
     * @param array<string, mixed> $postData
     */
    public function createBanRequest(
        string $board,
        int $postNo,
        string $janitorUsername,
        int $templateId,
        array $postData,
        string $reason
    ): BanRequest {
        $template = BanTemplate::find($templateId);
        if (!$template) {
            throw new \InvalidArgumentException('Invalid ban template');
        }

        $banRequest = BanRequest::create([
            'board' => $board,
            'post_no' => $postNo,
            'janitor' => $janitorUsername,
            'ban_template' => $templateId,
            'post_json' => json_encode($postData),
            'reason' => $reason,
            'length' => $template->getAttribute('ban_days'),
        ]);

        $this->logger->info('Ban request created', [
            'request_id' => $banRequest->getAttribute('id'),
            'board' => $board,
            'janitor' => $janitorUsername,
        ]);

        return $banRequest;
    }

    /**
     * Get pending ban requests
     *
     * @return array<string, mixed>
     */
    public function getBanRequests(?string $board = null): array
    {
        $query = BanRequest::query()->orderByDesc('created_at');

        if ($board !== null) {
            $query->where('board', $board);
        }

        $requests = $query->get();

        return [
            'requests' => $requests->toArray(),
            'count' => count($requests),
        ];
    }

    /**
     * Approve a ban request
     */
    public function approveBanRequest(int $requestId, string $approverUsername): BannedUser
    {
        /** @var BanRequest $request */
        $request = BanRequest::findOrFail($requestId);
        $template = BanTemplate::find($request->getAttribute('ban_template'));

        if (!$template) {
            throw new \RuntimeException('Ban template not found');
        }

        // Create ban
        $ban = $this->createBanFromTemplate(
            $template,
            (string) $request->getAttribute('board'),
            (int) $request->getAttribute('post_no'),
            $approverUsername,
            (string) $request->getAttribute('reason')
        );

        // Delete request
        $request->delete();

        // Update janitor stats
        $this->updateJanitorStats(
            (string) $request->getAttribute('janitor'),
            1, // accepted
            (string) $request->getAttribute('board'),
            (int) $request->getAttribute('post_no'),
            (int) $request->getAttribute('ban_template'),
            (int) $template->getAttribute('id'),
            $approverUsername
        );

        return $ban;
    }

    /**
     * Deny a ban request
     */
    public function denyBanRequest(int $requestId, string $denierUsername): bool
    {
        /** @var BanRequest $request */
        $request = BanRequest::findOrFail($requestId);

        // Update janitor stats
        $this->updateJanitorStats(
            (string) $request->getAttribute('janitor'),
            0, // denied
            (string) $request->getAttribute('board'),
            (int) $request->getAttribute('post_no'),
            (int) $request->getAttribute('ban_template'),
            0,
            $denierUsername
        );

        $request->delete();

        return true;
    }

    /**
     * Create a ban from template
     *
     * @param array<string, mixed> $postData
     */
    public function createBanFromTemplate(
        BanTemplate $template,
        string $board,
        int $postNo,
        string $adminUsername,
        string $customReason = '',
        array $postData = [],
        ?string $targetIp = null,
        ?string $passId = null
    ): BannedUser {
        $banType = $template->getAttribute('ban_type');
        $banDays = (int) $template->getAttribute('ban_days');

        // Calculate ban length
        if ($banDays === -1) {
            $length = null; // Permanent
        } elseif ($banDays === 0) {
            $length = now()->addSeconds(10); // Warning
        } else {
            $length = now()->addDays($banDays);
        }

        $ban = BannedUser::create([
            'board' => $banType === 'global' ? '' : $board,
            'global' => $banType === 'global' ? 1 : 0,
            'zonly' => $banType === 'zonly' ? 1 : 0,
            'name' => 'Anonymous',
            'host' => $targetIp !== null ? $this->piiEncryption->encrypt($targetIp) : '',
            'host_hash' => $targetIp !== null ? hash('sha256', $targetIp) : '',
            'reason' => $customReason ?: $template->getAttribute('public_reason'),
            'length' => $length,
            'now' => now(),
            'admin' => $adminUsername,
            'post_num' => $postNo,
            'rule' => $template->getAttribute('rule'),
            'template_id' => $template->getAttribute('id'),
            'pass_id' => $passId ?? '',
            'post_json' => $postData ? json_encode($postData) : '',
            'appealable' => $template->getAttribute('appealable'),
            'active' => 1,
        ]);

        $this->logger->info('Ban created', [
            'ban_id' => $ban->getAttribute('id'),
            'type' => $banType,
            'board' => $board,
        ]);

        return $ban;
    }

    /**
     * Check if user is banned
     *
     * @return array{banned: bool, reason?: string, expires_at?: string|null, is_permanent?: bool}
     */
    public function checkBan(string $board, string $ip, ?string $passId = null): array
    {
        // Use hash for deterministic lookup against host_hash column
        $ipHash = hash('sha256', $ip);

        $query = BannedUser::query()
            ->where('active', 1)
            ->where(function ($q) use ($board) {
                $q->where('global', 1)
                  ->orWhere('board', $board);
            })
            ->where(function ($q) use ($ipHash, $passId) {
                $q->where('host_hash', $ipHash);
                if ($passId !== null) {
                    $q->orWhere('pass_id', $passId);
                }
            })
            ->where(function ($q) {
                $q->where('length', '>', now())
                  ->orWhereNull('length'); // Permanent bans
            });

        $ban = $query->first();

        if ($ban) {
            return [
                'banned' => true,
                'reason' => (string) $ban->getAttribute('reason'),
                'expires_at' => $ban->getAttribute('length') !== null ? (string) $ban->getAttribute('length')->toIso8601String() : null,
                'is_permanent' => $ban->getAttribute('length') === null,
            ];
        }

        return ['banned' => false];
    }

    /**
     * Get report count by board
     *
     * @return array<string, int>
     */
    public function countReportsByBoard(): array
    {
        $results = Report::query()
            ->where('cleared', 0)
            ->selectRaw('board, COUNT(*) as report_count, SUM(weight) as total_weight')
            ->groupBy('board')
            ->get();

        $counts = [];
        foreach ($results as $row) {
            $board = $row->getAttribute('board');
            $count = (int) $row->getAttribute('report_count');
            $counts[(string) $board] = $count;
        }

        return $counts;
    }

    /**
     * Check if report should be filtered (reduced weight)
     */
    private function checkReportFilter(
        int $categoryId,
        string $ip,
        ?string $passId = null,
        ?string $pwd = null
    ): int {
        $category = ReportCategory::find($categoryId);
        if (!$category) {
            return 0;
        }

        $filterThreshold = (int) $category->getAttribute('filtered');
        if ($filterThreshold < 1) {
            return 0;
        }

        // Check cleared reports in past X days — use ip_hash for deterministic lookup
        $clearCount = ReportClearLog::query()
            ->where(function ($q) use ($ip, $passId, $pwd) {
                $q->where('ip_hash', $ip);
                if ($passId !== null) {
                    $q->orWhere('pass_id', $passId);
                }
                if ($pwd !== null) {
                    $q->orWhere('pwd', $pwd);
                }
            })
            ->where('created_at', '>', now()->subDays(2))
            ->count();

        if ($clearCount >= $filterThreshold) {
            return 1; // Filter due to excessive clears
        }

        return 0;
    }

    /**
     * Log cleared reporter for abuse tracking
     */
    private function logClearedReporter(
        string $ipHash,
        ?string $pwd,
        ?string $passId,
        int $categoryId,
        float $weight
    ): void {
        ReportClearLog::create([
            'ip_hash' => $ipHash,
            'pwd' => $pwd,
            'pass_id' => $passId,
            'category' => $categoryId,
            'weight' => $weight,
        ]);
    }

    /**
     * Update janitor statistics
     */
    private function updateJanitorStats(
        string $janitorUsername,
        int $action, // 0=denied, 1=accepted
        string $board,
        int $postId,
        int $requestedTpl,
        int $acceptedTpl,
        string $modUsername
    ): void {
        try {
            \Hyperf\DbConnection\Db::table('janitor_stats')->insert([
                'janitor_username' => $janitorUsername,
                'action' => $action,
                'board' => $board,
                'post_id' => $postId,
                'requested_template' => $requestedTpl,
                'accepted_template' => $acceptedTpl,
                'mod_username' => $modUsername,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Table may not exist yet — log and continue
            $this->logger->warning('Failed to insert janitor stats', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Janitor stats updated', [
            'janitor' => $janitorUsername,
            'action' => $action === 1 ? 'accepted' : 'denied',
            'board' => $board,
            'post_id' => $postId,
            'moderator' => $modUsername,
        ]);
    }

    /**
     * Format report for API response
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function formatReport(array $report): array
    {
        return [
            'id' => (int) $report['id'],
            'board' => $report['board'],
            'no' => (int) $report['no'],
            'count' => (int) $report['cnt'],
            'weight' => (float) $report['total_weight'],
            'ts' => isset($report['time']) ? strtotime((string) $report['time']) : 0,
            'post' => json_decode((string) ($report['post_json'] ?? '{}'), true),
            'resto' => (int) $report['resto'],
            'is_thread' => (int) $report['resto'] === 0,
            'is_highlighted' => (float) $report['total_weight'] >= $this->highlightThreshold,
            'is_unlocked' => (float) $report['total_weight'] >= $this->globalThreshold,
        ];
    }

    /**
     * Unban a user by ban ID
     */
    public function unbanUser(int $banId, string $staffUsername): bool
    {
        $ban = BannedUser::find($banId);
        if (!$ban) {
            return false;
        }

        $ban->setAttribute('active', 0);
        $ban->setAttribute('unbannedon', now());
        $ban->setAttribute('admin', $staffUsername);
        $ban->save();

        return true;
    }

    /**
     * Check if board is worksafe
     */
    private function isWorksafeBoard(string $board): bool
    {
        // Common worksafe boards - customize based on your board list
        $worksafe = ['g', 'prog', 'fit', 'sci', 'biz', 'diy', 'ck', 'gd', 'ic', 'lit'];
        return in_array($board, $worksafe, true);
    }
}
