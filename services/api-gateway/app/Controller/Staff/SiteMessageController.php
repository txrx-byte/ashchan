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

#[Controller(prefix: '/staff/site-messages')]
final class SiteMessageController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $messages = Db::table('site_messages')
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
        $html = $this->viewService->render('staff/site-messages/index', ['messages' => $messages]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $html = $this->viewService->render('staff/site-messages/create', [
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $errors = [];

        if (empty($body['title'])) {
            $errors[] = 'Title is required';
        }
        if (empty($body['message'])) {
            $errors[] = 'Message is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        $user = \Hyperf\Context\Context::get('staff_user');
        Db::table('site_messages')->insert([
            'title' => trim($body['title']),
            'message' => trim($body['message']),
            'is_html' => isset($body['is_html']),
            'boards' => $body['boards'] ?? [],
            'is_active' => isset($body['is_active']),
            'start_at' => !empty($body['start_at']) ? $body['start_at'] : date('Y-m-d H:i:s'),
            'end_at' => !empty($body['end_at']) ? $body['end_at'] : null,
            'created_by' => $user['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/site-messages']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $message = Db::table('site_messages')->where('id', $id)->first();
        if (!$message) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $html = $this->viewService->render('staff/site-messages/edit', [
            'message' => $message,
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        $message = Db::table('site_messages')->where('id', $id)->first();
        if (!$message) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $body = $this->request->getParsedBody();
        $errors = [];

        if (empty($body['title'])) {
            $errors[] = 'Title is required';
        }
        if (empty($body['message'])) {
            $errors[] = 'Message is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        Db::table('site_messages')->where('id', $id)->update([
            'title' => trim($body['title']),
            'message' => trim($body['message']),
            'is_html' => isset($body['is_html']),
            'boards' => $body['boards'] ?? [],
            'is_active' => isset($body['is_active']),
            'start_at' => !empty($body['start_at']) ? $body['start_at'] : $message->start_at,
            'end_at' => !empty($body['end_at']) ? $body['end_at'] : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/site-messages']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $message = Db::table('site_messages')->where('id', $id)->first();
        if (!$message) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        Db::table('site_messages')->where('id', $id)->delete();
        return $this->response->json(['success' => true]);
    }

    #[PostMapping(path: 'preview')]
    public function preview(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $title = $body['title'] ?? '';
        $message = $body['message'] ?? '';
        $isHtml = isset($body['is_html']);

        if ($isHtml) {
            $message = strip_tags($message, '<p><br><strong><em><a><ul><ol><li>');
        } else {
            $message = nl2br(htmlspecialchars($message));
        }

        return $this->response->json([
            'title' => htmlspecialchars($title),
            'preview' => $message,
        ]);
    }
}
