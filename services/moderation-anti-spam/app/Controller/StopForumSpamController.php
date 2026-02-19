<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\StopForumSpamService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Internal API for SFS checks and reporting.
 */
#[Controller(prefix: '/internal/spam')]
final class StopForumSpamController
{
    public function __construct(
        private StopForumSpamService $sfsService,
        private HttpResponse $response
    ) {}

    /**
     * Check if an entity is spam.
     */
    #[PostMapping(path: 'check')]
    public function check(RequestInterface $request): ResponseInterface
    {
        $ip = $request->input('ip', '');
        $email = $request->input('email');
        $username = $request->input('username');

        if (empty($ip)) {
            return $this->response->json(['is_spam' => false]);
        }

        $isSpam = $this->sfsService->check($ip, $email, $username);

        return $this->response->json(['is_spam' => $isSpam]);
    }

    /**
     * Report a spammer.
     */
    #[PostMapping(path: 'report')]
    public function report(RequestInterface $request): ResponseInterface
    {
        $ip = $request->input('ip', '');
        $email = $request->input('email', '');
        $username = $request->input('username', 'Anonymous');
        $evidence = $request->input('evidence', '');

        if (empty($ip)) {
            return $this->response->json(['error' => 'IP required'], 400);
        }

        // Ideally queue this, but for now run synchronously with short timeout in service
        $this->sfsService->report($ip, $email, $username, $evidence);

        return $this->response->json(['status' => 'reported']);
    }
}