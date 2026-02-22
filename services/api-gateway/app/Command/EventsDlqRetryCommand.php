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

use Ashchan\EventBus\EventPublisher;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Redis\RedisFactory;
use Psr\Container\ContainerInterface;
use function Hyperf\Support\env;

/**
 * Replay dead-lettered events back to the main stream.
 *
 * Usage:
 *   php bin/hyperf.php events:dlq:retry           # Retry all
 *   php bin/hyperf.php events:dlq:retry --id=1234  # Retry specific entry
 */
#[Command]
final class EventsDlqRetryCommand extends HyperfCommand
{
    protected ?string $name = 'events:dlq:retry';

    protected string $description = 'Replay dead-lettered events back to the main stream';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('id', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Specific DLQ entry ID to retry');
    }

    public function handle(): void
    {
        $redis = $this->container->get(RedisFactory::class)->get('events');
        $stream = (string) env('EVENTS_STREAM_NAME', 'ashchan:events');
        $dlqStream = (string) env('EVENTS_DLQ_STREAM', 'ashchan:events:dlq');
        $maxlen = (int) env('EVENTS_MAXLEN', 100000);
        /** @var string|null $specificId */
        $specificId = $this->input->getOption('id');

        $this->line('');

        if ($specificId !== null && $specificId !== '') {
            // Retry a specific entry
            $this->retryEntry($redis, $stream, $dlqStream, $maxlen, $specificId);
        } else {
            // Retry all entries
            $this->retryAll($redis, $stream, $dlqStream, $maxlen);
        }

        $this->line('');
    }

    private function retryEntry(object $redis, string $stream, string $dlqStream, int $maxlen, string $entryId): void
    {
        /** @var array<string, array<string, string>>|false $entries */
        $entries = $redis->xRange($dlqStream, $entryId, $entryId);

        if (!is_array($entries) || $entries === []) {
            $this->error("DLQ entry '{$entryId}' not found.");
            return;
        }

        $data = $entries[$entryId] ?? [];
        if (!isset($data['event'])) {
            $this->error("DLQ entry '{$entryId}' has no event payload.");
            return;
        }

        // Re-publish to main stream
        $result = $redis->xAdd($stream, '*', ['event' => $data['event']], $maxlen, true);

        if ($result !== false) {
            // Remove from DLQ
            $redis->xDel($dlqStream, [$entryId]);
            $this->info("Replayed entry {$entryId} → {$result}");
        } else {
            $this->error("Failed to replay entry {$entryId}");
        }
    }

    private function retryAll(object $redis, string $stream, string $dlqStream, int $maxlen): void
    {
        /** @var array<string, array<string, string>>|false $entries */
        $entries = $redis->xRange($dlqStream, '-', '+');

        if (!is_array($entries) || $entries === []) {
            $this->info("DLQ is empty — nothing to retry.");
            return;
        }

        $total = count($entries);
        $success = 0;
        $failed = 0;

        $this->info("Replaying {$total} dead-lettered events...");

        foreach ($entries as $id => $data) {
            if (!isset($data['event'])) {
                $failed++;
                $this->warn("  Skipped {$id} (no event payload)");
                continue;
            }

            $result = $redis->xAdd($stream, '*', ['event' => $data['event']], $maxlen, true);

            if ($result !== false) {
                $redis->xDel($dlqStream, [$id]);
                $success++;
            } else {
                $failed++;
                $this->warn("  Failed to replay {$id}");
            }
        }

        $this->info("Done: {$success} replayed, {$failed} failed.");
    }
}
