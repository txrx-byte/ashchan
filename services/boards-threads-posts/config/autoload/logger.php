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


use function Hyperf\Support\env;

$isProduction = env('APP_ENV', 'production') === 'production';

return [
    'default' => 'default',
    'channels' => [
        'default' => [
            'handler' => [
                'class' => Monolog\Handler\StreamHandler::class,
                'constructor' => [
                    'stream' => 'php://stdout',
                    'level' => $isProduction ? Monolog\Level::Info : Monolog\Level::Debug,
                ],
            ],
            'formatter' => [
                'class' => Monolog\Formatter\LineFormatter::class,
                'constructor' => [
                    'format' => null,
                    'dateFormat' => 'Y-m-d H:i:s',
                    'allowInlineLineBreaks' => true,
                ],
            ],
        ],
    ],
];
