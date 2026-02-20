<?php
declare(strict_types=1);

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