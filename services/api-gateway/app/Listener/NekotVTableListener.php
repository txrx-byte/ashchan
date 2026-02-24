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

namespace App\Listener;

use App\NekotV\NekotVTables;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;

/**
 * Creates shared Swoole Tables for NekotV before workers fork.
 *
 * Swoole Tables live in shared memory: they must be created before
 * $server->start() (which forks workers). All workers then read/write
 * the same physical memory for timer & playlist state.
 *
 * Only initializes when NEKOTV_ENABLED=true.
 */
#[Listener]
final class NekotVTableListener implements ListenerInterface
{
    public function listen(): array
    {
        return [BeforeMainServerStart::class];
    }

    /**
     * @param BeforeMainServerStart $event
     */
    public function process(object $event): void
    {
        if (!filter_var(getenv('NEKOTV_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        NekotVTables::init();
    }
}
