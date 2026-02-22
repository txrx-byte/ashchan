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

use App\Service\AuthenticationService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;

/**
 * Deterministic cleanup of expired/used CSRF tokens.
 *
 * Schedule via system crontab every 5 minutes:
 *   *\/5 * * * * cd /path/to/api-gateway && php bin/hyperf.php csrf:cleanup
 */
#[Command]
final class CleanCsrfTokensCommand extends HyperfCommand
{
    protected ?string $name = 'csrf:cleanup';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('Delete expired and used CSRF tokens');
    }

    public function handle(): void
    {
        /** @var AuthenticationService $authService */
        $authService = $this->container->get(AuthenticationService::class);
        $deleted = $authService->cleanupExpiredCsrfTokens();
        $this->info("Cleaned up {$deleted} expired/used CSRF token(s).");
    }
}
