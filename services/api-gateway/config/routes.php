<?php
declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;
use App\Controller\HealthController;
use App\Controller\GatewayController;
use App\Controller\FrontendController;
use App\Controller\Staff\StaffController;
use App\Controller\Staff\StaffReportController;
use App\Controller\Staff\StaffBanTemplateController;
use App\Controller\Staff\StaffReportCategoryController;
use App\Controller\Staff\StaffToolsController;

// Health check
Router::get('/health', [HealthController::class, 'check']);

// Static files (dev mode)
Router::get('/static/{path:.+}', [FrontendController::class, 'staticFile']);
Router::get('/staff/css/{path:.+}', [FrontendController::class, 'staffCss']);
Router::get('/staff/js/{path:.+}', [FrontendController::class, 'staffJs']);
Router::get('/staff/favicon.ico', [FrontendController::class, 'staffFavicon']);

// Media proxy
Router::get('/media/{path:.*}', [GatewayController::class, 'proxyMedia']);

// ============== Staff Interface Routes ==============

// Staff login/logout
Router::get('/staff/login', [\App\Controller\Staff\StaffController::class, 'login']);
Router::post('/staff/login', [\App\Controller\Staff\StaffController::class, 'loginPost']);
Router::get('/staff/logout', [\App\Controller\Staff\StaffController::class, 'logout']);

// Staff admin dashboard (main admin panel)
Router::get('/staff', [\App\Controller\Staff\StaffController::class, 'index']);
Router::get('/staff/admin', [\App\Controller\Staff\StaffController::class, 'admin']);
Router::get('/staff/dashboard', [\App\Controller\Staff\StaffController::class, 'dashboard']);
Router::get('/staff/bans', [\App\Controller\Staff\StaffController::class, 'bans']);
Router::get('/staff/reports', [\App\Controller\Staff\StaffController::class, 'reports']);
Router::get('/staff/reports/ban-requests', [\App\Controller\Staff\StaffController::class, 'banRequests']);

// Staff Tools
Router::get('/staff/search', [\App\Controller\Staff\StaffToolsController::class, 'search']);
Router::get('/staff/ip-lookup', [\App\Controller\Staff\StaffToolsController::class, 'ipLookup']);
Router::get('/staff/check-md5', [\App\Controller\Staff\StaffToolsController::class, 'checkMd5']);
Router::get('/staff/check-filter', [\App\Controller\Staff\StaffToolsController::class, 'checkFilter']);
Router::get('/staff/staff-roster', [\App\Controller\Staff\StaffToolsController::class, 'staffRoster']);
Router::get('/staff/floodlog', [\App\Controller\Staff\StaffToolsController::class, 'floodLog']);
Router::get('/staff/stafflog', [\App\Controller\Staff\StaffToolsController::class, 'staffLog']);
Router::get('/staff/userdellog', [\App\Controller\Staff\StaffToolsController::class, 'userDelLog']);

// Staff ban templates (Manager+)
Router::get('/staff/ban-templates', [\App\Controller\Staff\StaffBanTemplateController::class, 'index']);
Router::get('/staff/ban-templates/create', [\App\Controller\Staff\StaffBanTemplateController::class, 'create']);
Router::post('/staff/ban-templates', [\App\Controller\Staff\StaffBanTemplateController::class, 'store']);
Router::get('/staff/ban-templates/{id:\d+}/edit', [\App\Controller\Staff\StaffBanTemplateController::class, 'edit']);
Router::post('/staff/ban-templates/{id:\d+}', [\App\Controller\Staff\StaffBanTemplateController::class, 'update']);
Router::post('/staff/ban-templates/{id:\d+}/delete', [\App\Controller\Staff\StaffBanTemplateController::class, 'delete']);

// Staff report categories (Manager+)
Router::get('/staff/report-categories', [\App\Controller\Staff\StaffReportCategoryController::class, 'index']);
Router::get('/staff/report-categories/create', [\App\Controller\Staff\StaffReportCategoryController::class, 'create']);
Router::post('/staff/report-categories', [\App\Controller\Staff\StaffReportCategoryController::class, 'store']);
Router::get('/staff/report-categories/{id:\d+}/edit', [\App\Controller\Staff\StaffReportCategoryController::class, 'edit']);
Router::post('/staff/report-categories/{id:\d+}', [\App\Controller\Staff\StaffReportCategoryController::class, 'update']);
Router::post('/staff/report-categories/{id:\d+}/delete', [\App\Controller\Staff\StaffReportCategoryController::class, 'delete']);

// ============== Moderation API Routes ==============

// Public report submission
Router::post('/api/v1/reports', [App\Controller\ModerationController::class, 'createReport']);
Router::get('/api/v1/report-categories', [App\Controller\ModerationController::class, 'getReportCategories']);

// Staff API endpoints
Router::get('/api/v1/reports', [App\Controller\ModerationController::class, 'listReports']);
Router::get('/api/v1/reports/count', [App\Controller\ModerationController::class, 'countReports']);
Router::post('/api/v1/reports/{id:\d+}/clear', [App\Controller\ModerationController::class, 'clearReport']);
Router::delete('/api/v1/reports/{id:\d+}', [App\Controller\ModerationController::class, 'deleteReport']);
Router::post('/api/v1/ban-requests', [App\Controller\ModerationController::class, 'createBanRequest']);
Router::get('/api/v1/ban-requests', [App\Controller\ModerationController::class, 'getBanRequests']);
Router::post('/api/v1/ban-requests/{id:\d+}/approve', [App\Controller\ModerationController::class, 'approveBanRequest']);
Router::post('/api/v1/ban-requests/{id:\d+}/deny', [App\Controller\ModerationController::class, 'denyBanRequest']);
Router::get('/api/v1/ban-templates', [App\Controller\ModerationController::class, 'getBanTemplates']);
Router::post('/api/v1/ban-templates', [App\Controller\ModerationController::class, 'createBanTemplate']);
Router::put('/api/v1/ban-templates/{id:\d+}', [App\Controller\ModerationController::class, 'updateBanTemplate']);
Router::post('/api/v1/bans/check', [App\Controller\ModerationController::class, 'checkBan']);
Router::post('/api/v1/bans', [App\Controller\ModerationController::class, 'createBan']);

// Spam check and captcha
Router::post('/api/v1/spam/check', [App\Controller\ModerationController::class, 'spamCheck']);
Router::get('/api/v1/captcha', [App\Controller\ModerationController::class, 'captcha']);
Router::post('/api/v1/captcha/verify', [App\Controller\ModerationController::class, 'verifyCaptcha']);

// ============== Frontend Routes ==============

// Homepage
Router::get('/', [FrontendController::class, 'home']);

// Board pages (must be before API catch-all)
Router::get('/{slug}/catalog', [FrontendController::class, 'catalog']);
Router::get('/{slug}/archive', [FrontendController::class, 'archive']);
Router::get('/{slug}/thread/{id:\d+}', [FrontendController::class, 'thread']);
Router::get('/{slug}/', [FrontendController::class, 'board']);
Router::get('/{slug}', [FrontendController::class, 'board']);

// Frontend form submissions (redirect handlers)
Router::post('/{slug}/threads', [FrontendController::class, 'createThread']);
Router::post('/{slug}/thread/{id:\d+}/posts', [FrontendController::class, 'createPost']);

// API proxy – catch-all (must be last)
Router::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    '/api/v1/{path:.*}', [GatewayController::class, 'proxy']);
