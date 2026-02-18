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

#[Controller(prefix: '/staff/autopurge')]
final class AutopurgeController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $rules = Db::table('autopurge_rules')
            ->orderBy('is_active', 'desc')
            ->orderBy('hit_count', 'desc')
            ->get();
        $html = $this->viewService->render('staff/autopurge/index', ['rules' => $rules]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $html = $this->viewService->render('staff/autopurge/create', [
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $errors = [];

        if (empty($body['pattern'])) {
            $errors[] = 'Pattern is required';
        }
        if ($body['ban_length_days'] < 0) {
            $errors[] = 'Ban length must be 0 or greater (0 = no ban)';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        $user = \Hyperf\Context\Context::get('staff_user');
        Db::table('autopurge_rules')->insert([
            'pattern' => trim($body['pattern']),
            'is_regex' => isset($body['is_regex']),
            'boards' => $body['boards'] ?? [],
            'purge_threads' => isset($body['purge_threads']),
            'purge_replies' => isset($body['purge_replies']),
            'ban_length_days' => (int)($body['ban_length_days'] ?? 0),
            'ban_reason' => trim($body['ban_reason'] ?? ''),
            'is_active' => isset($body['is_active']),
            'created_by' => $user['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/autopurge']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $rule = Db::table('autopurge_rules')->where('id', $id)->first();
        if (!$rule) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $html = $this->viewService->render('staff/autopurge/edit', [
            'rule' => $rule,
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        $rule = Db::table('autopurge_rules')->where('id', $id)->first();
        if (!$rule) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $body = $this->request->getParsedBody();
        $errors = [];

        if (empty($body['pattern'])) {
            $errors[] = 'Pattern is required';
        }
        if ($body['ban_length_days'] < 0) {
            $errors[] = 'Ban length must be 0 or greater (0 = no ban)';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        Db::table('autopurge_rules')->where('id', $id)->update([
            'pattern' => trim($body['pattern']),
            'is_regex' => isset($body['is_regex']),
            'boards' => $body['boards'] ?? [],
            'purge_threads' => isset($body['purge_threads']),
            'purge_replies' => isset($body['purge_replies']),
            'ban_length_days' => (int)($body['ban_length_days'] ?? 0),
            'ban_reason' => trim($body['ban_reason'] ?? ''),
            'is_active' => isset($body['is_active']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/autopurge']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $rule = Db::table('autopurge_rules')->where('id', $id)->first();
        if (!$rule) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        Db::table('autopurge_rules')->where('id', $id)->delete();
        return $this->response->json(['success' => true]);
    }

    #[PostMapping(path: 'test')]
    public function test(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $pattern = $body['pattern'] ?? '';
        $sampleText = $body['sample_text'] ?? '';
        $isRegex = isset($body['is_regex']);

        if (empty($pattern)) {
            return $this->response->json(['error' => 'Pattern is required']);
        }

        $matched = false;
        if ($isRegex) {
            $matched = @preg_match('/' . $pattern . '/i', $sampleText);
            if ($matched === false) {
                return $this->response->json(['error' => 'Invalid regex pattern']);
            }
        } else {
            $matched = stripos($sampleText, $pattern) !== false;
        }

        return $this->response->json([
            'matched' => (bool)$matched,
            'pattern' => $pattern,
            'is_regex' => $isRegex,
        ]);
    }
}
