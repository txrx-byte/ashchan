<?php
declare(strict_types=1);

namespace App\Controller\Staff;

use App\Controller\AbstractController;
use App\Model\ReportCategory;
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
class StaffReportCategoryController extends AbstractController
{
    public function __construct(
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
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        $categories = ReportCategory::query()
            ->orderBy('board')
            ->orderBy('weight', 'desc')
            ->get();
        
        return $this->response->view('staff/report-categories/index', [
            'categories' => $categories->toArray(),
            'isManager' => true,
        ]);
    }

    /**
     * GET /staff/report-categories/create - Create category form
     */
    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        return $this->response->view('staff/report-categories/update', [
            'category' => null,
            'action' => 'create',
            'boardList' => $this->getBoardList(true),
        ]);
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
            return $this->response->json(['error' => $e->getMessage()], 500);
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
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        $category = ReportCategory::find($id);
        if (!$category) {
            return $this->response->json(['error' => 'Category not found'], 404);
        }
        
        return $this->response->view('staff/report-categories/update', [
            'category' => $category->toArray(),
            'action' => 'edit',
            'boardList' => $this->getBoardList(true),
        ]);
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
            return $this->response->json(['error' => $e->getMessage()], 500);
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
            return $this->response->json(['error' => 'Category not found'], 404);
        }
        
        // Don't allow deleting the illegal category (ID 31)
        if ($id === 31) {
            return $this->response->json(['error' => 'Cannot delete the illegal content category'], 400);
        }
        
        try {
            $category->delete();
            return $this->response->json(['status' => 'success']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    private function getStaffInfo(): array
    {
        return \Hyperf\Context\Context::get('staff_info', [
            'username' => 'system',
            'level' => 'janitor',
            'is_manager' => false,
        ]);
    }

    private function getBoardList(bool $allowSpecial = false): array
    {
        $boards = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'];
        
        if ($allowSpecial) {
            $boards = ['' => 'All Boards'] + $boards;
            $boards['_ws_'] = 'All Worksafe';
            $boards['_nws_'] = 'All NSFW';
        }
        
        return $boards;
    }
}
