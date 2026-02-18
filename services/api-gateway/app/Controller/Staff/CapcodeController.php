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

#[Controller(prefix: '/staff/capcodes')]
final class CapcodeController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $capcodes = Db::table('capcodes')
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get();
        $html = $this->viewService->render('staff/capcodes/index', ['capcodes' => $capcodes]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $html = $this->viewService->render('staff/capcodes/create', [
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $errors = [];

        if (strlen($body['name'] ?? '') < 2) {
            $errors[] = 'Name must be at least 2 characters';
        }
        if (empty($body['label'])) {
            $errors[] = 'Label is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        // Generate secure tripcode
        $tripcode = '!!' . bin2hex(random_bytes(16));

        $user = \Hyperf\Context\Context::get('staff_user');
        Db::table('capcodes')->insert([
            'name' => trim($body['name']),
            'tripcode' => $tripcode,
            'label' => trim($body['label']),
            'color' => $body['color'] ?? '#0000FF',
            'boards' => $body['boards'] ?? [],
            'is_active' => isset($body['is_active']),
            'created_by' => $user['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/capcodes']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $capcode = Db::table('capcodes')->where('id', $id)->first();
        if (!$capcode) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $html = $this->viewService->render('staff/capcodes/edit', [
            'capcode' => $capcode,
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        $capcode = Db::table('capcodes')->where('id', $id)->first();
        if (!$capcode) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $body = $this->request->getParsedBody();
        $errors = [];

        if (strlen($body['name'] ?? '') < 2) {
            $errors[] = 'Name must be at least 2 characters';
        }
        if (empty($body['label'])) {
            $errors[] = 'Label is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        Db::table('capcodes')->where('id', $id)->update([
            'name' => trim($body['name']),
            'label' => trim($body['label']),
            'color' => $body['color'] ?? '#0000FF',
            'boards' => $body['boards'] ?? [],
            'is_active' => isset($body['is_active']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/capcodes']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $capcode = Db::table('capcodes')->where('id', $id)->first();
        if (!$capcode) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        Db::table('capcodes')->where('id', $id)->delete();
        return $this->response->json(['success' => true]);
    }

    #[PostMapping(path: 'test')]
    public function test(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $tripcode = $body['tripcode'] ?? '';

        // Check if tripcode exists
        $capcode = Db::table('capcodes')
            ->where('tripcode', $tripcode)
            ->where('is_active', true)
            ->first();

        if ($capcode) {
            return $this->response->json([
                'valid' => true,
                'name' => $capcode->name,
                'label' => $capcode->label,
                'color' => $capcode->color,
            ]);
        }

        return $this->response->json(['valid' => false]);
    }
}
