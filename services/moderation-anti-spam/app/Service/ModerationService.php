<?php
declare(strict_types=1);

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
 */
final class ModerationService
{
    #[Inject]
    private LoggerInterface $logger;

    /**
     * Weight thresholds (from OpenYotsuba ReportQueue)
     */
    private const GLOBAL_THRES = 1500;       // Weight after which report is globally unlocked
    private const HIGHLIGHT_THRES = 500;     // Weight for highlighting
    private const THREAD_WEIGHT_BOOST = 1.25; // Weight multiplier for threads
    private const ABUSE_CLEAR_DAYS = 3;      // Days to check for abuse
    private const ABUSE_CLEAR_COUNT = 50;    // Cleared reports threshold for auto-ban
    private const ABUSE_CLEAR_BAN_INTERVAL = 5; // Min days between auto-bans
    private const REP_ABUSE_TPL = 190;       // Report abuse template ID

    /**
     * Create a new report (port of report_submit from OpenYotsuba)
     *
     * @param array<string, mixed> $postData Post data snapshot
     * @return Report
     */
    public function createReport(
        int $postId,
        string $board,
        int $categoryId,
        array $postData,
        string $reporterIp,
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
        /** @var mixed $rawWeight */
        $rawWeight = $category->getAttribute('weight');
        $weight = (float) $rawWeight;

        // Check if reporter should be filtered (reduced weight)
        $ignoreReason = $this->checkReportFilter($categoryId, $reporterIp, $passId, $reporterPwd);
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
        /** @var mixed $clearedByRaw */
        $clearedByRaw = $existingReport ? $existingReport->getAttribute('cleared_by') : '';
        $clearedBy = is_string($clearedByRaw) ? $clearedByRaw : '';

        // Log cleared reporter if re-reporting
        if ($isCleared) {
            $this->logClearedReporter($reporterIp, $reporterPwd, $passId, $categoryId, $weight);
        }

        // Determine if worksafe
        $isWorksafe = $this->isWorksafeBoard($board) ? 1 : 0;

        // Create report
        $report = Report::create([
            'ip' => $reporterIp,
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
            'post_json' => (string) json_encode($postData),
            'cleared' => $isCleared,
            'cleared_by' => $clearedBy,
            'ws' => $isWorksafe,
            'ts' => \Carbon\Carbon::now(),
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
                GROUP_CONCAT(DISTINCT report_category) as cats,
                MAX(ts) as time,
                ANY_VALUE(id) as id,
                ANY_VALUE(post_json) as post_json,
                ANY_VALUE(resto) as resto,
                ANY_VALUE(post_ip) as post_ip,
                ANY_VALUE(ws) as ws,
                ANY_VALUE(cleared_by) as cleared_by
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

        $total = $query->count();
        $reports = $query
            ->orderByDesc('total_weight')
            ->orderByDesc('time')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // Format reports
        $formatted = [];
        foreach ($reports as $report) {
            /** @var array<string, mixed> $reportArr */
            $reportArr = (array) $report;
            $formatted[] = $this->formatReport($reportArr);
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
        $request = BanRequest::findOrFail($requestId);
        /** @var mixed $banTemplateId */
        $banTemplateId = $request->getAttribute('ban_template');
        $template = BanTemplate::find($banTemplateId);

        if (!$template instanceof BanTemplate) {
            throw new \RuntimeException('Ban template not found');
        }

        /** @var mixed $reqBoard */
        $reqBoard = $request->getAttribute('board');
        /** @var mixed $reqPostNo */
        $reqPostNo = $request->getAttribute('post_no');
        /** @var mixed $reqReason */
        $reqReason = $request->getAttribute('reason');
        /** @var mixed $reqJanitor */
        $reqJanitor = $request->getAttribute('janitor');
        /** @var mixed $reqBanTemplate */
        $reqBanTemplate = $request->getAttribute('ban_template');
        /** @var mixed $tplId */
        $tplId = $template->getAttribute('id');

        // Create ban
        $ban = $this->createBanFromTemplate(
            $template,
            is_string($reqBoard) ? $reqBoard : '',
            (int) $reqPostNo,
            $approverUsername,
            is_string($reqReason) ? $reqReason : ''
        );

        // Delete request
        $request->delete();

        // Update janitor stats
        $this->updateJanitorStats(
            is_string($reqJanitor) ? $reqJanitor : '',
            1, // accepted
            is_string($reqBoard) ? $reqBoard : '',
            (int) $reqPostNo,
            (int) $reqBanTemplate,
            (int) $tplId,
            $approverUsername
        );

        return $ban;
    }

    /**
     * Deny a ban request
     */
    public function denyBanRequest(int $requestId, string $denierUsername): bool
    {
        $request = BanRequest::findOrFail($requestId);

        /** @var mixed $reqJanitor */
        $reqJanitor = $request->getAttribute('janitor');
        /** @var mixed $reqBoard */
        $reqBoard = $request->getAttribute('board');
        /** @var mixed $reqPostNo */
        $reqPostNo = $request->getAttribute('post_no');
        /** @var mixed $reqBanTemplate */
        $reqBanTemplate = $request->getAttribute('ban_template');

        // Update janitor stats
        $this->updateJanitorStats(
            is_string($reqJanitor) ? $reqJanitor : '',
            0, // denied
            is_string($reqBoard) ? $reqBoard : '',
            (int) $reqPostNo,
            (int) $reqBanTemplate,
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
        /** @var mixed $banTypeRaw */
        $banTypeRaw = $template->getAttribute('ban_type');
        $banType = is_string($banTypeRaw) ? $banTypeRaw : 'local';
        /** @var mixed $banDaysRaw */
        $banDaysRaw = $template->getAttribute('ban_days');
        $banDays = (int) $banDaysRaw;

        // Calculate ban length
        if ($banDays === -1) {
            $length = null; // Permanent
        } elseif ($banDays === 0) {
            $length = \Carbon\Carbon::now()->addSeconds(10); // Warning
        } else {
            $length = \Carbon\Carbon::now()->addDays($banDays);
        }

        $ban = BannedUser::create([
            'board' => $banType === 'global' ? '' : $board,
            'global' => $banType === 'global' ? 1 : 0,
            'zonly' => $banType === 'zonly' ? 1 : 0,
            'name' => 'Anonymous',
            'host' => $targetIp ?? '',
            'reason' => $customReason ?: $template->getAttribute('public_reason'),
            'length' => $length,
            'now' => \Carbon\Carbon::now(),
            'admin' => $adminUsername,
            'post_num' => $postNo,
            'rule' => $template->getAttribute('rule'),
            'template_id' => $template->getAttribute('id'),
            'pass_id' => $passId ?? '',
            'post_json' => $postData ? (string) json_encode($postData) : '',
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
     * @return array{banned: bool, reason?: string, expires_at?: string|null}
     */
    public function checkBan(string $board, string $ip, ?string $passId = null): array
    {
        $query = BannedUser::query()
            ->where('active', 1)
            ->where(function (\Hyperf\Database\Model\Builder $q) use ($board): void {
                $q->where('global', 1)
                  ->orWhere('board', $board);
            })
            ->where(function (\Hyperf\Database\Model\Builder $q) use ($ip, $passId): void {
                $q->where('host', $ip);
                if ($passId !== null) {
                    $q->orWhere('pass_id', $passId);
                }
            })
            ->where(function (\Hyperf\Database\Model\Builder $q): void {
                $q->where('length', '>', \Carbon\Carbon::now())
                  ->orWhereNull('length'); // Permanent bans
            });

        $ban = $query->first();

        if ($ban instanceof BannedUser) {
            /** @var mixed $banReason */
            $banReason = $ban->getAttribute('reason');
            /** @var \Carbon\Carbon|null $banLength */
            $banLength = $ban->getAttribute('length');
            return [
                'banned' => true,
                'reason' => is_string($banReason) ? $banReason : '',
                'expires_at' => $banLength instanceof \Carbon\Carbon ? $banLength->toIso8601String() : null,
                'is_permanent' => $banLength === null,
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
            ->selectRaw('board, SUM(weight) as total_weight')
            ->groupBy('board')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($results as $row) {
            /** @var Report $row */
            /** @var mixed $rawBoard */
            $rawBoard = $row->getAttribute('board');
            /** @var mixed $rawWeight */
            $rawWeight = $row->getAttribute('total_weight');
            $board = is_string($rawBoard) ? $rawBoard : '';
            $weight = (float) $rawWeight;

            // Apply thread weight boost if needed
            if ($weight < self::GLOBAL_THRES) {
                continue;
            }

            $counts[$board] = (int) ceil($weight / self::GLOBAL_THRES);
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

        /** @var mixed $rawFiltered */
        $rawFiltered = $category->getAttribute('filtered');
        $filterThreshold = (int) $rawFiltered;
        if ($filterThreshold < 1) {
            return 0;
        }

        // Check cleared reports in past X days
        $clearCount = ReportClearLog::query()
            ->where(function (\Hyperf\Database\Model\Builder $q) use ($ip, $passId, $pwd): void {
                $q->where('ip', $ip);
                if ($passId !== null) {
                    $q->orWhere('pass_id', $passId);
                }
                if ($pwd !== null) {
                    $q->orWhere('pwd', $pwd);
                }
            })
            ->where('created_at', '>', \Carbon\Carbon::now()->subDays(2))
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
        string $ip,
        ?string $pwd,
        ?string $passId,
        int $categoryId,
        float $weight
    ): void {
        ReportClearLog::create([
            'ip' => $ip,
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
        // This would typically insert into janitor_stats table
        // For now, just log
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
        $id = (int) ($report['id'] ?? 0);
        $board = is_string($report['board'] ?? null) ? (string) $report['board'] : '';
        $no = (int) ($report['no'] ?? 0);
        $cnt = (int) ($report['cnt'] ?? 0);
        $totalWeight = (float) ($report['total_weight'] ?? 0);
        $time = is_string($report['time'] ?? null) ? strtotime((string) $report['time']) : 0;
        $postJson = is_string($report['post_json'] ?? null) ? (string) $report['post_json'] : '{}';
        $resto = (int) ($report['resto'] ?? 0);

        return [
            'id'            => $id,
            'board'         => $board,
            'no'            => $no,
            'count'         => $cnt,
            'weight'        => $totalWeight,
            'ts'            => $time,
            'post'          => json_decode($postJson, true),
            'resto'         => $resto,
            'is_thread'     => $resto === 0,
            'is_highlighted' => $totalWeight >= self::HIGHLIGHT_THRES,
            'is_unlocked'   => $totalWeight >= self::GLOBAL_THRES,
        ];
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
