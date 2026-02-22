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
 * Show event stream statistics: length, consumer groups, pending counts, lag.
 *
 * Usage: php bin/hyperf.php events:stats
 */
#[Command]
final class EventsStatsCommand extends HyperfCommand
{
    protected ?string $name = 'events:stats';

    protected string $description = 'Show event stream statistics';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $redis = $this->container->get(RedisFactory::class)->get('events');
        $stream = (string) env('EVENTS_STREAM_NAME', 'ashchan:events');

        $this->line('');
        $this->info("Event Stream Statistics");
        $this->line(str_repeat('─', 60));

        // Stream info
        try {
            /** @var int|false $length */
            $length = $redis->xLen($stream);
            $this->line(sprintf("  Stream:    %s", $stream));
            $this->line(sprintf("  Length:    %s entries", $length !== false ? number_format($length) : 'N/A'));
        } catch (\Throwable $e) {
            $this->error("Stream '{$stream}' does not exist or is not accessible.");
            $this->error($e->getMessage());
            return;
        }

        // Stream info details
        try {
            /** @var array<string, mixed>|false $info */
            $info = $redis->xInfo('STREAM', $stream);
            if (is_array($info)) {
                $this->line(sprintf("  First ID:  %s", $info['first-entry'][0] ?? 'N/A'));
                $this->line(sprintf("  Last ID:   %s", $info['last-entry'][0] ?? 'N/A'));
            }
        } catch (\Throwable) {
            // Stream may be empty
        }

        $this->line('');

        // Consumer groups
        try {
            /** @var array<int, array<string, mixed>>|false $groups */
            $groups = $redis->xInfo('GROUPS', $stream);
            if (!is_array($groups) || $groups === []) {
                $this->warn("  No consumer groups registered.");
            } else {
                $this->info("Consumer Groups:");
                $this->line(str_repeat('─', 60));

                foreach ($groups as $group) {
                    $groupName = (string) ($group['name'] ?? 'unknown');
                    $consumers = (int) ($group['consumers'] ?? 0);
                    $pending = (int) ($group['pending'] ?? 0);
                    $lastDelivered = (string) ($group['last-delivered-id'] ?? '0-0');
                    $lag = $group['lag'] ?? 'N/A';

                    $this->line(sprintf("  Group:        %s", $groupName));
                    $this->line(sprintf("  Consumers:    %d", $consumers));
                    $this->line(sprintf("  Pending:      %s", number_format($pending)));
                    $this->line(sprintf("  Last ID:      %s", $lastDelivered));
                    $this->line(sprintf("  Lag:          %s", is_int($lag) ? number_format($lag) : (string) $lag));
                    $this->line('');
                }
            }
        } catch (\Throwable $e) {
            $this->warn("Could not retrieve consumer groups: " . $e->getMessage());
        }

        // DLQ info
        $dlqStream = (string) env('EVENTS_DLQ_STREAM', 'ashchan:events:dlq');
        try {
            /** @var int|false $dlqLen */
            $dlqLen = $redis->xLen($dlqStream);
            if ($dlqLen !== false && $dlqLen > 0) {
                $this->warn(sprintf("  DLQ:       %s entries in %s", number_format($dlqLen), $dlqStream));
            } else {
                $this->info(sprintf("  DLQ:       Empty (%s)", $dlqStream));
            }
        } catch (\Throwable) {
            $this->line(sprintf("  DLQ:       Stream '%s' does not exist yet", $dlqStream));
        }

        $this->line('');
    }
}
