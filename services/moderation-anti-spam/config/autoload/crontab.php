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

use Hyperf\Crontab\Crontab;

return [
    'enable' => true,
    'crontab' => [
        // Moderation PII Retention Cleanup - runs daily at 03:15 UTC
        // Nullifies report IPs (90d), ban IPs (expiry+30d), SFS pending (30d),
        // report clear log IPs (90d), moderation decisions (1yr), audit log IPs (1yr).
        (new Crontab())
            ->setName('moderation-pii-retention-cleanup')
            ->setRule('15 3 * * *')
            ->setCallback([\App\Service\IpRetentionService::class, 'runAll'])
            ->setMemo('Automated moderation PII retention cleanup')
            ->setOnOneServer(true),
    ],
];
