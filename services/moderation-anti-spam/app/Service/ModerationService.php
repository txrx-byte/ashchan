<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\ModerationDecision;
use App\Model\Report;

final class ModerationService
{
    /**
     * Create a new report.
     */
    public function createReport(int $postId, string $reason, string $details, string $ipHash): Report
    {
        return Report::create([
            'post_id'           => $postId,
            'reason'            => $reason,
            'details'           => $details,
            'reporter_ip_hash'  => $ipHash,
            'status'            => 'pending',
        ]);
    }

    /**
     * List reports with optional filtering.
     * @return array<string, mixed>
     */
    public function listReports(string $status = 'pending', int $page = 1, int $perPage = 50): array
    {
        $query = Report::query();
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $total = $query->count();
        $reports = $query
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->toArray();

        return [
            'reports'     => $reports,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Take action on a report.
     */
    public function decide(int $reportId, int $moderatorId, string $action, string $reason): ModerationDecision
    {
        $report = Report::findOrFail($reportId);

        $decision = ModerationDecision::create([
            'report_id'    => $reportId,
            'moderator_id' => $moderatorId,
            'action'       => $action,
            'reason'       => $reason,
        ]);

        $report->update([
            'status'      => 'resolved',
            'reviewed_by' => $moderatorId,
        ]);

        /** @var ModerationDecision $decision */
        return $decision;
    }

    /**
     * Dismiss a report.
     */
    public function dismiss(int $reportId, int $moderatorId): void
    {
        $report = Report::findOrFail($reportId);
        $report->update([
            'status'      => 'dismissed',
            'reviewed_by' => $moderatorId,
        ]);
    }

    /**
     * Get moderation history for a post.
     * @return array<int, array<string, mixed>>
     */
    public function getPostHistory(int $postId): array
    {
        /** @var array<int, array<string, mixed>> $data */
        $data = Report::query()
            ->where('post_id', $postId)
            ->with('decisions')
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
        return $data;
    }
}
