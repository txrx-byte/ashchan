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
 * Automated IP/PII retention and deletion service for moderation tables.
 *
 * Enforces data retention policies by periodically nullifying or deleting
 * PII data that has exceeded its retention period.
 *
 * Retention schedule (from DATA_INVENTORY.md):
 *   - Report IPs:             90 days from report creation
 *   - Ban IPs:                Ban expiry + 30 days
 *   - SFS pending reports:    30 days (processed) or on action
 *   - Report clear log IPs:   90 days
 *   - Moderation decisions:   1 year (then DELETE)
 *   - Audit log staff IPs:    1 year
 */
final class IpRetentionService
{
    private LoggerInterface $logger;

    /** @var array<string, int> Retention periods in days */
    private const RETENTION_DAYS = [
        'report_ip'         => 90,
        'ban_ip'            => 30,   // 30 days after ban expiry
        'sfs_pending'       => 30,
        'report_clear_log'  => 90,
        'moderation_decisions' => 365,
        'audit_log_ip'      => 365,
    ];

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('ip-retention');
    }

    /**
     * Run all retention cleanup jobs.
     *
     * @return array<string, int> Map of table => rows affected
     */
    public function runAll(): array
    {
        $results = [];

        $results['reports_ip'] = $this->purgeReportIps();
        $results['banned_users_ip'] = $this->purgeBanIps();
        $results['sfs_pending'] = $this->purgeSfsPendingReports();
        $results['report_clear_log'] = $this->purgeReportClearLogIps();
        $results['moderation_decisions'] = $this->purgeModerationDecisions();
        $results['audit_log_ip'] = $this->purgeAuditLogIps();

        $total = array_sum($results);
        $this->logger->info("Moderation IP retention cleanup completed", [
            'results' => $results,
            'total_rows' => $total,
        ]);

        return $results;
    }

    /**
     * Nullify IP addresses on reports older than retention period.
     */
    public function purgeReportIps(): int
    {
        $days = self::RETENTION_DAYS['report_ip'];

        try {
            $affected = Db::update(
                "UPDATE reports SET ip = NULL, post_ip = NULL WHERE (ip IS NOT NULL OR post_ip IS NOT NULL) AND created_at < NOW() - INTERVAL '{$days} days'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} report IP addresses (>{$days} days old)");
                $this->logRetentionAction('reports', 'ip,post_ip', $affected, $days);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge report IPs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Nullify IP addresses on expired bans (ban expiry + buffer period).
     */
    public function purgeBanIps(): int
    {
        $bufferDays = self::RETENTION_DAYS['ban_ip'];

        try {
            $affected = Db::update(
                "UPDATE banned_users SET host = NULL, xff = NULL, admin_ip = NULL 
                 WHERE (host IS NOT NULL OR xff IS NOT NULL OR admin_ip IS NOT NULL) 
                 AND active = 0 
                 AND length < NOW() - INTERVAL '{$bufferDays} days'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} expired ban IP addresses");
                $this->logRetentionAction('banned_users', 'host,xff,admin_ip', $affected, $bufferDays);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge ban IPs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete processed SFS pending reports older than retention period.
     */
    public function purgeSfsPendingReports(): int
    {
        $days = self::RETENTION_DAYS['sfs_pending'];

        try {
            $affected = Db::delete(
                "DELETE FROM sfs_pending_reports 
                 WHERE status != 'pending' 
                 AND created_at < NOW() - INTERVAL '{$days} days'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} processed SFS pending reports (>{$days} days old)");
                $this->logRetentionAction('sfs_pending_reports', '*', $affected, $days);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge SFS pending reports: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Nullify IPs in report clear log older than retention period.
     */
    public function purgeReportClearLogIps(): int
    {
        $days = self::RETENTION_DAYS['report_clear_log'];

        try {
            $affected = Db::update(
                "UPDATE report_clear_log SET ip = NULL WHERE ip IS NOT NULL AND created_at < NOW() - INTERVAL '{$days} days'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} report clear log IPs (>{$days} days old)");
                $this->logRetentionAction('report_clear_log', 'ip', $affected, $days);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge report clear log IPs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete moderation decisions older than 1 year.
     */
    public function purgeModerationDecisions(): int
    {
        $days = self::RETENTION_DAYS['moderation_decisions'];

        try {
            $affected = Db::delete(
                "DELETE FROM moderation_decisions WHERE created_at < NOW() - INTERVAL '{$days} days'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} moderation decisions (>{$days} days old)");
                $this->logRetentionAction('moderation_decisions', '*', $affected, $days);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge moderation decisions: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Nullify staff IPs in audit logs older than 1 year.
     */
    public function purgeAuditLogIps(): int
    {
        $days = self::RETENTION_DAYS['audit_log_ip'];

        try {
            $affected = Db::update(
                "UPDATE admin_audit_log SET ip_address = NULL WHERE ip_address IS NOT NULL AND created_at < NOW() - INTERVAL '{$days} days'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} audit log IPs (>{$days} days old)");
                $this->logRetentionAction('admin_audit_log', 'ip_address', $affected, $days);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge audit log IPs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Log a retention action to the audit trail (without PII).
     */
    private function logRetentionAction(string $table, string $column, int $rowsAffected, int $retentionDays): void
    {
        try {
            Db::insert(
                "INSERT INTO pii_retention_log (table_name, column_name, rows_affected, retention_days, executed_at) VALUES (?, ?, ?, ?, NOW())",
                [$table, $column, $rowsAffected, $retentionDays]
            );
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to log retention action: " . $e->getMessage());
        }
    }
}
