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
Router::get('/staff/login', [StaffController::class, 'login']);
Router::post('/staff/login', [StaffController::class, 'loginPost']);
Router::get('/staff/logout', [StaffController::class, 'logout']);

// Staff admin dashboard (main admin panel)
Router::get('/staff', [StaffController::class, 'index']);
Router::get('/staff/admin', [StaffController::class, 'admin']);
Router::get('/staff/dashboard', [StaffController::class, 'dashboard']);
Router::get('/staff/bans', [StaffController::class, 'bans']);
Router::get('/staff/reports', [StaffController::class, 'reports']);
Router::get('/staff/reports/ban-requests', [StaffController::class, 'banRequests']);

// Staff Tools
Router::get('/staff/search', [StaffToolsController::class, 'search']);
Router::get('/staff/ip-lookup', [StaffToolsController::class, 'ipLookup']);
Router::get('/staff/check-md5', [StaffToolsController::class, 'checkMd5']);
Router::get('/staff/check-filter', [StaffToolsController::class, 'checkFilter']);
Router::get('/staff/staff-roster', [StaffToolsController::class, 'staffRoster']);
Router::get('/staff/floodlog', [StaffToolsController::class, 'floodLog']);
Router::get('/staff/stafflog', [StaffToolsController::class, 'staffLog']);
Router::get('/staff/userdellog', [StaffToolsController::class, 'userDelLog']);

// Staff ban templates (Manager+)
Router::get('/staff/ban-templates', [StaffBanTemplateController::class, 'index']);
Router::get('/staff/ban-templates/create', [StaffBanTemplateController::class, 'create']);
Router::post('/staff/ban-templates', [StaffBanTemplateController::class, 'store']);
Router::get('/staff/ban-templates/{id:\d+}/edit', [StaffBanTemplateController::class, 'edit']);
Router::post('/staff/ban-templates/{id:\d+}', [StaffBanTemplateController::class, 'update']);
Router::post('/staff/ban-templates/{id:\d+}/delete', [StaffBanTemplateController::class, 'delete']);

// Staff report categories (Manager+)
Router::get('/staff/report-categories', [StaffReportCategoryController::class, 'index']);
Router::get('/staff/report-categories/create', [StaffReportCategoryController::class, 'create']);
Router::post('/staff/report-categories', [StaffReportCategoryController::class, 'store']);
Router::get('/staff/report-categories/{id:\d+}/edit', [StaffReportCategoryController::class, 'edit']);
Router::post('/staff/report-categories/{id:\d+}', [StaffReportCategoryController::class, 'update']);
Router::post('/staff/report-categories/{id:\d+}/delete', [StaffReportCategoryController::class, 'delete']);

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
