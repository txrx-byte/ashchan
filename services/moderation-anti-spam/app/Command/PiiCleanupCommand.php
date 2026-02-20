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

namespace App\Command;

use App\Service\IpRetentionService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Cron command to run moderation PII data retention cleanup.
 *
 * Should be scheduled to run daily via system cron or Hyperf crontab:
 *   php bin/hyperf.php pii:cleanup
 */
#[Command]
class PiiCleanupCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('pii:cleanup');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('Run moderation PII data retention cleanup (automated IP deletion)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without actually deleting');
        $this->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Only clean a specific table');
    }

    public function handle(): void
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $table = $this->input->getOption('table');

        if ($dryRun) {
            $this->info('DRY RUN: No data will be modified.');
            $this->info('Retention policy:');
            $this->info('  - Report IPs: 90 days');
            $this->info('  - Ban IPs: ban expiry + 30 days');
            $this->info('  - SFS pending reports: 30 days (processed)');
            $this->info('  - Report clear log IPs: 90 days');
            $this->info('  - Moderation decisions: 1 year');
            $this->info('  - Audit log staff IPs: 1 year');
            return;
        }

        $this->info('Starting moderation PII retention cleanup...');

        /** @var IpRetentionService $retentionService */
        $retentionService = $this->container->get(IpRetentionService::class);

        if (is_string($table) && $table !== '') {
            $this->info("Cleaning specific table: {$table}");
            $method = match ($table) {
                'reports_ip' => 'purgeReportIps',
                'banned_users_ip' => 'purgeBanIps',
                'sfs_pending' => 'purgeSfsPendingReports',
                'report_clear_log' => 'purgeReportClearLogIps',
                'moderation_decisions' => 'purgeModerationDecisions',
                'audit_log_ip' => 'purgeAuditLogIps',
                default => null,
            };

            if ($method === null) {
                $this->error("Unknown table: {$table}. Valid: reports_ip, banned_users_ip, sfs_pending, report_clear_log, moderation_decisions, audit_log_ip");
                return;
            }

            $affected = $retentionService->{$method}();
            $this->info("Cleaned {$affected} rows from {$table}.");
            return;
        }

        $results = $retentionService->runAll();

        $this->info('Cleanup complete:');
        foreach ($results as $key => $count) {
            $this->info("  {$key}: {$count} rows cleaned");
        }
        $this->info('Total: ' . array_sum($results) . ' rows');
    }
}
