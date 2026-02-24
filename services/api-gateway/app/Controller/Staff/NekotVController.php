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
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Hyperf\Redis\RedisFactory;

/**
 * NekotV staff controller for playlist lock/unlock.
 *
 * Staff can lock or unlock a thread's NekotV playlist, preventing
 * or allowing users from adding/removing/clearing videos.
 *
 * The lock state is stored in Redis and read by NekotVFeedManager
 * on the WebSocket workers.
 */
final class NekotVController
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly RedisFactory $redisFactory,
    ) {
    }

    /**
     * POST /api/v1/nekotv/{threadId}/lock
     *
     * Lock or unlock the NekotV playlist for a thread.
     * Body: {"locked": true|false}
     */
    public function setPlaylistLock(int $threadId): PsrResponseInterface
    {
        $staffUser = Context::get('staff_user');
        if (!$staffUser) {
            return $this->response->json(['error' => 'Unauthorized'])->withStatus(403);
        }

        $locked = (bool) $this->request->input('locked', true);

        $redis = $this->redisFactory->get('default');
        $key = "nekotv:lock:{$threadId}";

        if ($locked) {
            $redis->setex($key, 86400, '1');
        } else {
            $redis->del($key);
        }

        return $this->response->json([
            'thread_id' => $threadId,
            'locked'    => $locked,
        ]);
    }

    /**
     * GET /api/v1/nekotv/{threadId}/lock
     *
     * Check the lock state of a thread's NekotV playlist.
     */
    public function getPlaylistLock(int $threadId): PsrResponseInterface
    {
        $staffUser = Context::get('staff_user');
        if (!$staffUser) {
            return $this->response->json(['error' => 'Unauthorized'])->withStatus(403);
        }

        $redis = $this->redisFactory->get('default');
        $locked = (bool) $redis->get("nekotv:lock:{$threadId}");

        return $this->response->json([
            'thread_id' => $threadId,
            'locked'    => $locked,
        ]);
    }
}
