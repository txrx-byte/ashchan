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
 * Automated IP/PII retention and deletion service.
 *
 * Enforces data retention policies by periodically nullifying or deleting
 * PII data that has exceeded its retention period.
 *
 * Retention schedule (from DATA_INVENTORY.md):
 *   - Post IP addresses:      30 days from post creation
 *   - Post emails:            30 days from post creation
 *   - Flood log IPs:          24 hours
 *
 * This service runs in the boards-threads-posts context.
 * Moderation service has its own IpRetentionService for moderation tables.
 */
final class IpRetentionService
{
    private LoggerInterface $logger;

    /** @var array<string, int> Retention periods in days */
    private const RETENTION_DAYS = [
        'post_ip'   => 30,
        'post_email' => 30,
        'flood_log' => 1, // 24 hours, expressed as 1 day
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

        $results['posts_ip'] = $this->purgePostIps();
        $results['posts_email'] = $this->purgePostEmails();
        $results['flood_log'] = $this->purgeFloodLog();

        $total = array_sum($results);
        $this->logger->info("IP retention cleanup completed", [
            'results' => $results,
            'total_rows' => $total,
        ]);

        return $results;
    }

    /**
     * Nullify IP addresses on posts older than retention period.
     */
    public function purgePostIps(): int
    {
        $days = self::RETENTION_DAYS['post_ip'];

        try {
            $affected = Db::update(
                "UPDATE posts SET ip_address = NULL WHERE ip_address IS NOT NULL AND created_at < NOW() - INTERVAL '{$days} days'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} post IP addresses (>{$days} days old)");

                // Log the retention action (without PII)
                $this->logRetentionAction('posts', 'ip_address', $affected, $days);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge post IPs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Nullify email addresses on posts older than retention period.
     */
    public function purgePostEmails(): int
    {
        $days = self::RETENTION_DAYS['post_email'];

        try {
            $affected = Db::update(
                "UPDATE posts SET email = NULL WHERE email IS NOT NULL AND email != '' AND created_at < NOW() - INTERVAL '{$days} days'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} post email addresses (>{$days} days old)");
                $this->logRetentionAction('posts', 'email', $affected, $days);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge post emails: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete flood log entries older than retention period.
     */
    public function purgeFloodLog(): int
    {
        try {
            $affected = Db::delete(
                "DELETE FROM flood_log WHERE created_at < NOW() - INTERVAL '24 hours'"
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} flood log entries (>24h old)");
                $this->logRetentionAction('flood_log', '*', $affected, 1);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge flood log: " . $e->getMessage());
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
            // Don't fail the retention job if audit logging fails
            $this->logger->warning("Failed to log retention action: " . $e->getMessage());
        }
    }
}
