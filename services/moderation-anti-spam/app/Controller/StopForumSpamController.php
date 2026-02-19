<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\StopForumSpamService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

#[Controller(prefix: '/api/v1/spam')]
class StopForumSpamController
{
    public function __construct(
        private StopForumSpamService $service,
        private ResponseInterface $response
    ) {
    }

    #[PostMapping(path: 'sfs-check')]
    public function check(RequestInterface $request): ResponseInterface
    {
        $ip = $request->input('ip');
        $email = $request->input('email');
        $username = $request->input('username');

        if (empty($ip)) {
            return $this->response->json(['error' => 'IP address is required'])->withStatus(400);
        }

        $isSpam = $this->service->check($ip, $email, $username);

        return $this->response->json(['is_spam' => $isSpam]);
    }

    #[PostMapping(path: 'sfs-report')]
    public function report(RequestInterface $request): ResponseInterface
    {
        $ip = $request->input('ip');
        $email = $request->input('email');
        $username = $request->input('username');
        $evidence = $request->input('evidence');

        if (empty($ip) || empty($email) || empty($username) || empty($evidence)) {
            return $this->response->json(['error' => 'Missing required fields (ip, email, username, evidence)'])->withStatus(400);
        }

        // Run in background ideally, but for now synchronous
        // Or dispatch a job here.
        // Since we are inside a microservice, maybe just call the service.
        // The service logs errors, so it shouldn't crash.
        
        $this->service->report($ip, $email, $username, $evidence);

        return $this->response->json(['status' => 'reported']);
    }
}
