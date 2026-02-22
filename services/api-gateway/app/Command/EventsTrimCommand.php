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
 * Manually trim the event stream to a specific maximum length.
 *
 * Usage: php bin/hyperf.php events:trim [--maxlen=100000]
 */
#[Command]
final class EventsTrimCommand extends HyperfCommand
{
    protected ?string $name = 'events:trim';

    protected string $description = 'Manually trim the event stream';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('maxlen', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Maximum stream length after trimming');
    }

    public function handle(): void
    {
        $redis = $this->container->get(RedisFactory::class)->get('events');
        $stream = (string) env('EVENTS_STREAM_NAME', 'ashchan:events');
        /** @var string|null $maxlenOpt */
        $maxlenOpt = $this->input->getOption('maxlen');
        $maxlen = $maxlenOpt !== null ? (int) $maxlenOpt : (int) env('EVENTS_MAXLEN', 100000);

        $this->line('');

        try {
            /** @var int|false $beforeLen */
            $beforeLen = $redis->xLen($stream);
            $before = $beforeLen !== false ? $beforeLen : 0;

            if ($before === 0) {
                $this->info("Stream '{$stream}' is empty — nothing to trim.");
                $this->line('');
                return;
            }

            $this->line(sprintf("Stream:        %s", $stream));
            $this->line(sprintf("Current size:  %s entries", number_format($before)));
            $this->line(sprintf("Target size:   %s entries", number_format($maxlen)));

            // XTRIM with MAXLEN
            /** @var int|false $trimmed */
            $trimmed = $redis->xTrim($stream, $maxlen, true);

            /** @var int|false $afterLen */
            $afterLen = $redis->xLen($stream);
            $after = $afterLen !== false ? $afterLen : 0;

            $this->line(sprintf("After trim:    %s entries", number_format($after)));
            $this->line(sprintf("Removed:       %s entries", number_format($before - $after)));
            $this->info("Trim complete.");
        } catch (\Throwable $e) {
            $this->error("Failed to trim stream: " . $e->getMessage());
        }

        $this->line('');
    }
}
