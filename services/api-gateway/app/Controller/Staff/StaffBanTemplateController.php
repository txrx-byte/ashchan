<?php
declare(strict_types=1);

namespace App\Controller\Staff;

use App\Controller\AbstractController;
use App\Model\BanTemplate;
use App\Service\ModerationService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * StaffBanTemplateController - Ban templates management for managers
 * 
 * Ported from OpenYotsuba/admin/manager/ban_templates.php
 */
#[Controller(prefix: '/staff/ban-templates')]
class StaffBanTemplateController extends AbstractController
{
    public function __construct(
        private ModerationService $modService,
        private HttpResponse $response,
    ) {}

    /**
     * GET /staff/ban-templates - List all ban templates
     */
    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        $templates = BanTemplate::query()
            ->orderBy('rule')
            ->orderBy('name')
            ->get();
        
        return $this->response->view('staff/ban-templates/index', [
            'templates' => $templates->toArray(),
            'isManager' => true,
            'isAdmin' => $staffInfo['is_admin'],
        ]);
    }

    /**
     * GET /staff/ban-templates/create - Create template form
     */
    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        return $this->response->view('staff/ban-templates/update', [
            'template' => null,
            'action' => 'create',
            'banTypes' => ['local' => 'Local', 'global' => 'Global', 'zonly' => 'Unappealable'],
            'accessLevels' => ['janitor' => 'Janitor', 'mod' => 'Moderator', 'manager' => 'Manager', 'admin' => 'Admin'],
        ]);
    }

    /**
     * POST /staff/ban-templates - Create new template
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
        $required = ['name', 'rule', 'ban_type', 'ban_days', 'public_reason'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->response->json(['error' => "Field '{$field}' is required"], 400);
            }
        }
        
        try {
            $template = BanTemplate::create([
                'name' => $data['name'],
                'rule' => $data['rule'],
                'ban_type' => $data['ban_type'],
                'ban_days' => (int) $data['ban_days'],
                'banlen' => $data['ban_days'] == -1 ? 'indefinite' : '',
                'public_reason' => $data['public_reason'],
                'private_reason' => $data['private_reason'] ?? '',
                'publicban' => isset($data['publicban']) ? 1 : 0,
                'is_public' => isset($data['is_public']) ? 1 : 0,
                'can_warn' => isset($data['can_warn']) ? 1 : 0,
                'action' => $data['action'] ?? '',
                'save_type' => $data['save_type'] ?? '',
                'blacklist_image' => isset($data['blacklist_image']) ? 1 : 0,
                'access' => $data['access'] ?? 'janitor',
                'boards' => '',
                'exclude' => $data['exclude'] ?? '',
                'appealable' => isset($data['appealable']) ? 1 : 0,
                'active' => 1,
            ]);
            
            return $this->response->redirect('/staff/ban-templates');
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /staff/ban-templates/{id}/edit - Edit template form
     */
    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        $template = BanTemplate::find($id);
        if (!$template) {
            return $this->response->json(['error' => 'Template not found'], 404);
        }
        
        return $this->response->view('staff/ban-templates/update', [
            'template' => $template->toArray(),
            'action' => 'edit',
            'banTypes' => ['local' => 'Local', 'global' => 'Global', 'zonly' => 'Unappealable'],
            'accessLevels' => ['janitor' => 'Janitor', 'mod' => 'Moderator', 'manager' => 'Manager', 'admin' => 'Admin'],
        ]);
    }

    /**
     * POST /staff/ban-templates/{id} - Update template
     */
    #[PostMapping(path: '{id:\d+}')]
    public function update(int $id, RequestInterface $request): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        $template = BanTemplate::find($id);
        if (!$template) {
            return $this->response->json(['error' => 'Template not found'], 404);
        }
        
        $data = $request->all();
        
        try {
            $template->update([
                'name' => $data['name'] ?? $template->getAttribute('name'),
                'rule' => $data['rule'] ?? $template->getAttribute('rule'),
                'ban_type' => $data['ban_type'] ?? $template->getAttribute('ban_type'),
                'ban_days' => isset($data['ban_days']) ? (int) $data['ban_days'] : $template->getAttribute('ban_days'),
                'public_reason' => $data['public_reason'] ?? $template->getAttribute('public_reason'),
                'private_reason' => $data['private_reason'] ?? $template->getAttribute('private_reason'),
                'publicban' => isset($data['publicban']) ? 1 : 0,
                'is_public' => isset($data['is_public']) ? 1 : 0,
                'can_warn' => isset($data['can_warn']) ? 1 : 0,
                'action' => $data['action'] ?? $template->getAttribute('action'),
                'save_type' => $data['save_type'] ?? $template->getAttribute('save_type'),
                'blacklist_image' => isset($data['blacklist_image']) ? 1 : 0,
                'access' => $data['access'] ?? $template->getAttribute('access'),
                'exclude' => $data['exclude'] ?? $template->getAttribute('exclude'),
                'appealable' => isset($data['appealable']) ? 1 : 0,
                'active' => isset($data['active']) ? 1 : 0,
            ]);
            
            return $this->response->redirect('/staff/ban-templates');
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /staff/ban-templates/{id}/delete - Delete template
     */
    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $staffInfo = $this->getStaffInfo();
        
        if (!$staffInfo['is_manager']) {
            return $this->response->json(['error' => 'Permission denied'], 403);
        }
        
        $template = BanTemplate::find($id);
        if (!$template) {
            return $this->response->json(['error' => 'Template not found'], 404);
        }
        
        try {
            $template->delete();
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
            'is_mod' => false,
            'is_manager' => false,
            'is_admin' => false,
        ]);
    }
}
