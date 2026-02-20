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


namespace App\Controller;

use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

abstract class AbstractController
{
    /**
     * Get staff info from context
     * @return array{username: string, level: string, boards: array<string>, is_mod: bool, is_manager: bool, is_admin: bool}
     */
    protected function getStaffInfo(): array
    {
        /** @var array{username: string, level: string, boards: array<string>, is_mod: bool, is_manager: bool, is_admin: bool} $info */
        $info = Context::get('staff_info', [
            'username' => 'system',
            'level' => 'janitor',
            'boards' => [],
            'is_mod' => false,
            'is_manager' => false,
            'is_admin' => false,
        ]);
        return $info;
    }

    /**
     * Get board list
     * @return array<string>
     */
    protected function getBoardList(): array
    {
        // In production, fetch from boards service
        return ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'];
    }

    /**
     * Create a HTML response
     */
    protected function html(ResponseInterface $response, string $body, int $status = 200): PsrResponseInterface
    {
        /** @var PsrResponseInterface $base */
        $base = $response; // @phpstan-ignore varTag.nativeType
        return $base->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody(new SwooleStream($body));
    }
}