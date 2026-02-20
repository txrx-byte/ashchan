<?php
declare(strict_types=1);

namespace App\Controller\Staff;

use App\Service\ViewService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/staff/blotter')]
final class BlotterController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $messages = Db::table('blotter_messages')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
        $html = $this->viewService->render('staff/blotter/index', ['messages' => $messages]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $html = $this->viewService->render('staff/blotter/create');
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (empty($body['message'])) {
            $errors[] = 'Message is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        $user = \Hyperf\Context\Context::get('staff_user');
        Db::table('blotter_messages')->insert([
            'message' => trim((string) ($body['message'] ?? '')),
            'is_html' => isset($body['is_html']),
            'is_active' => isset($body['is_active']),
            'priority' => (int)($body['priority'] ?? 0),
            'created_by' => $user['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/blotter']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $message = Db::table('blotter_messages')->where('id', $id)->first();
        if (!$message) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $html = $this->viewService->render('staff/blotter/edit', ['message' => $message]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        $message = Db::table('blotter_messages')->where('id', $id)->first();
        if (!$message) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        /** @var array<string, mixed> $body */

        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (empty($body['message'])) {
            $errors[] = 'Message is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        Db::table('blotter_messages')->where('id', $id)->update([
            'message' => trim((string) ($body['message'] ?? '')),
            'is_html' => isset($body['is_html']),
            'is_active' => isset($body['is_active']),
            'priority' => (int)($body['priority'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/blotter']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $message = Db::table('blotter_messages')->where('id', $id)->first();
        if (!$message) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        Db::table('blotter_messages')->where('id', $id)->delete();
        return $this->response->json(['success' => true]);
    }

    #[PostMapping(path: 'preview')]
    public function preview(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $message = (string) ($body['message'] ?? '');
        $isHtml = isset($body['is_html']);

        if ($isHtml) {
            // Sanitize HTML - allow only safe tags
            $message = strip_tags($message, '<p><br><strong><em><a><ul><ol><li>');
        } else {
            $message = nl2br(htmlspecialchars($message));
        }

        return $this->response->json(['preview' => $message]);
    }
}
