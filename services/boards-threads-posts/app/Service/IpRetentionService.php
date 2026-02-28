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
 *
 * @see docs/SECURITY.md §Data Retention
 */
final class IpRetentionService
{
    /**
     * @var LoggerInterface Logger for retention operations
     */
    private LoggerInterface $logger;

    /**
     * @var int Retention period for post IP addresses (days)
     */
    private int $postIpRetentionDays;

    /**
     * @var int Retention period for post email addresses (days)
     */
    private int $postEmailRetentionDays;

    /**
     * @var int Retention period for flood log entries (days)
     */
    private int $floodLogRetentionDays;

    /**
     * @param LoggerFactory $loggerFactory Logger factory
     * @param SiteConfigService $config Site configuration service
     */
    public function __construct(LoggerFactory $loggerFactory, SiteConfigService $config)
    {
        $this->logger = $loggerFactory->get('ip-retention');
        $this->postIpRetentionDays    = $config->getInt('retention_post_ip', 30);
        $this->postEmailRetentionDays = $config->getInt('retention_post_email', 30);
        $this->floodLogRetentionDays  = $config->getInt('retention_flood_log', 1);
    }

    /**
     * Run all retention cleanup jobs.
     *
     * Executes all PII retention cleanup operations in sequence.
     * Results are logged for audit purposes.
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
     *
     * Uses PostgreSQL's make_interval() for date arithmetic.
     * Encrypted IPs are set to NULL (not decrypted first).
     *
     * @return int Number of rows affected
     */
    public function purgePostIps(): int
    {
        $days = $this->postIpRetentionDays;

        try {
            $affected = Db::update(
                'UPDATE posts SET ip_address = NULL WHERE ip_address IS NOT NULL AND created_at < NOW() - make_interval(days => ?)',
                [$days]
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
     *
     * Empty strings are also cleared to maintain data consistency.
     *
     * @return int Number of rows affected
     */
    public function purgePostEmails(): int
    {
        $days = $this->postEmailRetentionDays;

        try {
            $affected = Db::update(
                'UPDATE posts SET email = NULL WHERE email IS NOT NULL AND email != \'\'  AND created_at < NOW() - make_interval(days => ?)',
                [$days]
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
     *
     * Flood logs contain IP addresses and are fully deleted (not nullified)
     * after the retention period.
     *
     * @return int Number of rows deleted
     */
    public function purgeFloodLog(): int
    {
        $days = $this->floodLogRetentionDays;

        try {
            $affected = Db::delete(
                'DELETE FROM flood_log WHERE created_at < NOW() - make_interval(days => ?)',
                [$days]
            );

            if ($affected > 0) {
                $this->logger->info("Purged {$affected} flood log entries (>{$days} day(s) old)");
                $this->logRetentionAction('flood_log', '*', $affected, $days);
            }

            return $affected;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to purge flood log: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Log a retention action to the audit trail (without PII).
     *
     * Records retention operations in pii_retention_log table for
     * compliance and auditing purposes.
     *
     * @param string $table Table name
     * @param string $column Column name (or '*' for full row deletion)
     * @param int $rowsAffected Number of rows affected
     * @param int $retentionDays Retention period in days
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
