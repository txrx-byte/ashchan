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


use App\Model\ReportCategory;
use App\Service\ViewService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * StaffReportCategoryController - Report categories management for managers
 * 
 * Ported from OpenYotsuba/admin/manager/report_categories.php
 */
#[Controller(prefix: '/staff/report-categories')]
class StaffReportCategoryController
{
    public function __construct(
        private ViewService $viewService,
        private HttpResponse $response,
    ) {}

    /**
     * GET /staff/report-categories - List all report categories
     */
    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->redirect('/staff/login');
        }
        
        $categories = ReportCategory::query()
            ->orderBy('board')
            ->orderBy('weight', 'desc')
            ->get();
        
        $html = $this->viewService->render('staff/report-categories/index', [
            'categories' => $categories->toArray(),
            'isManager' => true,
        ]);
        return $this->response->html($html);
    }

    /**
     * GET /staff/report-categories/create - Create category form
     */
    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->redirect('/staff/login');
        }
        
        $html = $this->viewService->render('staff/report-categories/update', [
            'category' => null,
            'action' => 'create',
            'boardList' => $this->getBoardList(true),
        ]);
        return $this->response->html($html);
    }

    /**
     * POST /staff/report-categories - Create new category
     */
    #[PostMapping(path: '')]
    public function store(RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        /** @var array<string, mixed> $data */
        
        $data = $request->all();
        
        // Required fields
        if (empty($data['title'])) {
            return $this->response->json(['error' => 'Title is required'], 400);
        }
        
        if (!isset($data['weight']) || (float) $data['weight'] <= 0) {
            return $this->response->json(['error' => 'Weight must be greater than 0'], 400);
        }
        
        try {
            ReportCategory::create([
                'board' => $data['board'] ?? '',
                'title' => $data['title'],
                'weight' => (float) $data['weight'],
                'exclude_boards' => $data['exclude_boards'] ?? '',
                'filtered' => isset($data['filtered']) ? (int) $data['filtered'] : 0,
                'op_only' => isset($data['op_only']) ? 1 : 0,
                'reply_only' => isset($data['reply_only']) ? 1 : 0,
                'image_only' => isset($data['image_only']) ? 1 : 0,
            ]);
            
            return $this->response->redirect('/staff/report-categories');
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'], 500);
        }
    }

    /**
     * GET /staff/report-categories/{id}/edit - Edit category form
     */
    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->redirect('/staff/login');
        }
        
        $category = ReportCategory::find($id);
        if (!$category) {
            return $this->response->redirect('/staff/report-categories');
        }
        
        $html = $this->viewService->render('staff/report-categories/update', [
            'category' => $category->toArray(),
            'action' => 'edit',
            'boardList' => $this->getBoardList(true),
        ]);
        return $this->response->html($html);
    }

    /**
     * POST /staff/report-categories/{id} - Update category
     */
    #[PostMapping(path: '{id:\d+}')]
    public function update(int $id, RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        $category = ReportCategory::find($id);
        if (!$category) {
            return $this->response->json(['error' => 'Category not found'], 404);
        }
        
        /** @var array<string, mixed> $data */
        
        $data = $request->all();
        
        if (empty($data['title'])) {
            return $this->response->json(['error' => 'Title is required'], 400);
        }
        
        try {
            $category->update([
                'board' => $data['board'] ?? $category->getAttribute('board'),
                'title' => $data['title'],
                'weight' => isset($data['weight']) ? (float) $data['weight'] : $category->getAttribute('weight'),
                'exclude_boards' => $data['exclude_boards'] ?? $category->getAttribute('exclude_boards'),
                'filtered' => isset($data['filtered']) ? (int) $data['filtered'] : $category->getAttribute('filtered'),
                'op_only' => isset($data['op_only']) ? 1 : 0,
                'reply_only' => isset($data['reply_only']) ? 1 : 0,
                'image_only' => isset($data['image_only']) ? 1 : 0,
            ]);
            
            return $this->response->redirect('/staff/report-categories');
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'], 500);
        }
    }

    /**
     * POST /staff/report-categories/{id}/delete - Delete category
     */
    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        $category = ReportCategory::find($id);
        if (!$category) {
            return $this->response->redirect('/staff/report-categories');
        }
        
        // Don't allow deleting the illegal category (ID 31)
        if ($id === 31) {
            return $this->response->redirect('/staff/report-categories');
        }
        
        try {
            $category->delete();
            return $this->response->redirect('/staff/report-categories');
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getStaffInfo(): array
    {
        /** @var array<string, mixed> */
        return \Hyperf\Context\Context::get('staff_info', [
            'username' => 'system',
            'level' => 'janitor',
            'is_manager' => false,
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    private function getBoardList(bool $allowSpecial = false): array
    {
        try {
            $slugs = Db::table('boards')
                ->where('archived', false)
                ->orderBy('slug')
                ->pluck('slug')
                ->toArray();
        } catch (\Throwable) {
            $slugs = [];
        }

        /** @var array<int|string, string> $boards */
        $boards = [];
        
        if ($allowSpecial) {
            $boards[''] = 'All Boards';
            $boards['_ws_'] = 'All Worksafe';
            $boards['_nws_'] = 'All NSFW';
        }
        
        foreach ($slugs as $slug) {
            $boards[(string) $slug] = '/' . $slug . '/';
        }
        
        return $boards;
    }
}
