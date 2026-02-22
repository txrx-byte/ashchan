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

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Redis\RedisFactory;
use Psr\Container\ContainerInterface;
use function Hyperf\Support\env;

/**
 * List dead-lettered events for inspection.
 *
 * Usage: php bin/hyperf.php events:dlq:list [--limit=20]
 */
#[Command]
final class EventsDlqListCommand extends HyperfCommand
{
    protected ?string $name = 'events:dlq:list';

    protected string $description = 'List dead-lettered events';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('limit', 'l', \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Max entries to show', '20');
    }

    public function handle(): void
    {
        $redis = $this->container->get(RedisFactory::class)->get('events');
        $dlqStream = (string) env('EVENTS_DLQ_STREAM', 'ashchan:events:dlq');
        $limit = (int) ($this->input->getOption('limit') ?? 20);

        $this->line('');
        $this->info("Dead-Letter Queue: {$dlqStream}");
        $this->line(str_repeat('─', 60));

        try {
            /** @var int|false $length */
            $length = $redis->xLen($dlqStream);
            if ($length === false || $length === 0) {
                $this->info("  No dead-lettered events. Queue is clean.");
                $this->line('');
                return;
            }

            $this->line(sprintf("  Total entries: %d (showing last %d)", $length, min($limit, $length)));
            $this->line('');

            // Read the last N entries
            /** @var array<string, array<string, string>>|false $entries */
            $entries = $redis->xRevRange($dlqStream, '+', '-', $limit);

            if (!is_array($entries) || $entries === []) {
                $this->warn("  Could not read entries.");
                return;
            }

            foreach ($entries as $id => $data) {
                $this->line(sprintf("  [%s]", $id));
                $this->line(sprintf("    Original ID: %s", $data['original_id'] ?? 'N/A'));
                $this->line(sprintf("    Group:       %s", $data['group'] ?? 'N/A'));
                $this->line(sprintf("    Consumer:    %s", $data['consumer'] ?? 'N/A'));
                $this->line(sprintf("    Failed at:   %s", $data['failed_at'] ?? 'N/A'));
                $this->line(sprintf("    Error:       %s", $data['error'] ?? 'N/A'));

                // Try to decode the event for type info
                if (isset($data['event'])) {
                    try {
                        /** @var array<string, mixed> $event */
                        $event = json_decode($data['event'], true, 512, JSON_THROW_ON_ERROR);
                        $this->line(sprintf("    Event type:  %s", $event['type'] ?? 'unknown'));
                        $this->line(sprintf("    Event ID:    %s", $event['id'] ?? 'unknown'));
                    } catch (\Throwable) {
                        $this->line("    Event:       (unparseable)");
                    }
                }
                $this->line('');
            }
        } catch (\Throwable $e) {
            $this->error("DLQ stream does not exist: " . $e->getMessage());
        }

        $this->line('');
    }
}
