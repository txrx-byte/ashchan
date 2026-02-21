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

namespace App\Controller\Staff;

use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;

/**
 * Trait providing access level enforcement for staff controllers.
 *
 * Requires the using class to have a $response property with html() or json() method.
 */
trait RequiresAccessLevel
{
    private const ACCESS_HIERARCHY = [
        'janitor' => 0,
        'mod' => 1,
        'manager' => 2,
        'admin' => 3,
    ];

    /**
     * Check if current staff user has at least the given access level.
     * Returns null if access is granted, or a 403 response if denied.
     */
    private function requireAccessLevel(string $minLevel): ?ResponseInterface
    {
        $staffInfo = Context::get('staff_info', []);
        $userLevel = $staffInfo['level'] ?? 'janitor';
        $userRank = self::ACCESS_HIERARCHY[$userLevel] ?? 0;
        $requiredRank = self::ACCESS_HIERARCHY[$minLevel] ?? 0;

        if ($userRank < $requiredRank) {
            return $this->response->json([
                'error' => 'Forbidden: requires ' . $minLevel . ' access level or higher',
            ], 403);
        }

        return null;
    }

    /**
     * Get the current staff user from context.
     *
     * @return array<string, mixed>|null
     */
    private function getStaffUser(): ?array
    {
        /** @var array<string, mixed>|null */
        return Context::get('staff_user');
    }

    /**
     * Get the current staff info (with derived flags) from context.
     *
     * @return array{username: string, level: string, boards: array<string>, is_mod: bool, is_manager: bool, is_admin: bool}
     */
    private function getStaffInfo(): array
    {
        /** @var array{username: string, level: string, boards: array<string>, is_mod: bool, is_manager: bool, is_admin: bool} */
        return Context::get('staff_info', [
            'username' => 'system',
            'level' => 'janitor',
            'boards' => [],
            'is_mod' => false,
            'is_manager' => false,
            'is_admin' => false,
        ]);
    }
}
