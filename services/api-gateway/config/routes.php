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


use Hyperf\HttpServer\Router\Router;
use App\Controller\HealthController;
use App\Controller\GatewayController;
use App\Controller\FrontendController;
use App\Controller\Staff\StaffController;
use App\Controller\Staff\StaffReportController;
use App\Controller\Staff\StaffBanTemplateController;
use App\Controller\Staff\StaffReportCategoryController;
use App\Controller\Staff\StaffToolsController;
use App\Controller\Staff\CapcodeController;
use App\Controller\Staff\IpRangeBanController;
use App\Controller\Staff\AutopurgeController;
use App\Controller\Staff\DmcaController;
use App\Controller\Staff\BlotterController;
use App\Controller\Staff\SiteMessageController;
use App\Controller\ReportController;
use App\Controller\Staff\BoardConfigController;

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
Router::post('/staff/logout', [\App\Controller\Staff\StaffController::class, 'logout']);

// Staff admin dashboard (main admin panel)
Router::get('/staff', [\App\Controller\Staff\StaffController::class, 'index']);
Router::get('/staff/admin', [\App\Controller\Staff\StaffController::class, 'admin']);
Router::get('/staff/dashboard', [\App\Controller\Staff\StaffController::class, 'dashboard']);
Router::get('/staff/dashboard/stats', [\App\Controller\Staff\StaffController::class, 'dashboardStats']);
Router::get('/staff/bans', [\App\Controller\Staff\StaffController::class, 'bans']);
Router::post('/staff/bans/unban', [\App\Controller\Staff\StaffController::class, 'unban']);
// Reports and ban requests handled by StaffReportController via annotations

// Staff Tools
Router::get('/staff/search', [\App\Controller\Staff\StaffToolsController::class, 'search']);
Router::get('/staff/ip-lookup', [\App\Controller\Staff\StaffToolsController::class, 'ipLookup']);
Router::get('/staff/check-md5', [\App\Controller\Staff\StaffToolsController::class, 'checkMd5']);
Router::get('/staff/check-filter', [\App\Controller\Staff\StaffToolsController::class, 'checkFilter']);
Router::get('/staff/staff-roster', [\App\Controller\Staff\StaffToolsController::class, 'staffRoster']);
Router::post('/staff/staff-roster/create', [\App\Controller\Staff\StaffToolsController::class, 'staffRosterCreate']);
Router::post('/staff/staff-roster/{id:\d+}/update-level', [\App\Controller\Staff\StaffToolsController::class, 'staffRosterUpdateLevel']);
Router::post('/staff/staff-roster/{id:\d+}/toggle-active', [\App\Controller\Staff\StaffToolsController::class, 'staffRosterToggleActive']);
Router::post('/staff/staff-roster/{id:\d+}/delete', [\App\Controller\Staff\StaffToolsController::class, 'staffRosterDelete']);
Router::get('/staff/floodlog', [\App\Controller\Staff\StaffToolsController::class, 'floodLog']);
Router::get('/staff/stafflog', [\App\Controller\Staff\StaffToolsController::class, 'staffLog']);
Router::get('/staff/userdellog', [\App\Controller\Staff\StaffToolsController::class, 'userDelLog']);

// Staff Reports (All staff)
Router::get('/staff/reports', [\App\Controller\Staff\StaffReportController::class, 'index']);
Router::get('/staff/reports/data', [\App\Controller\Staff\StaffReportController::class, 'data']);
Router::post('/staff/reports/{id:\d+}/clear', [\App\Controller\Staff\StaffReportController::class, 'clear']);
Router::post('/staff/reports/{id:\d+}/delete', [\App\Controller\Staff\StaffReportController::class, 'delete']);
Router::get('/staff/reports/ban-requests', [\App\Controller\Staff\StaffReportController::class, 'banRequests']);
Router::post('/staff/reports/ban-requests', [\App\Controller\Staff\StaffReportController::class, 'createBanRequest']);
Router::post('/staff/reports/ban-requests/{id:\d+}/approve', [\App\Controller\Staff\StaffReportController::class, 'approveBanRequest']);
Router::post('/staff/reports/ban-requests/{id:\d+}/deny', [\App\Controller\Staff\StaffReportController::class, 'denyBanRequest']);

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

// Staff Account Management (Manager+)
Router::get('/staff/accounts', [\App\Controller\Staff\AccountManagementController::class, 'index']);
Router::get('/staff/accounts/create', [\App\Controller\Staff\AccountManagementController::class, 'create']);
Router::post('/staff/accounts/store', [\App\Controller\Staff\AccountManagementController::class, 'store']);
Router::get('/staff/accounts/{id:\d+}/edit', [\App\Controller\Staff\AccountManagementController::class, 'edit']);
Router::post('/staff/accounts/{id:\d+}/update', [\App\Controller\Staff\AccountManagementController::class, 'update']);
Router::post('/staff/accounts/{id:\d+}/delete', [\App\Controller\Staff\AccountManagementController::class, 'delete']);
Router::post('/staff/accounts/{id:\d+}/reset-password', [\App\Controller\Staff\AccountManagementController::class, 'resetPassword']);
Router::post('/staff/accounts/{id:\d+}/unlock', [\App\Controller\Staff\AccountManagementController::class, 'unlock']);

// Staff Capcodes Management (Manager+)
Router::get('/staff/capcodes', [\App\Controller\Staff\CapcodeController::class, 'index']);
Router::get('/staff/capcodes/create', [\App\Controller\Staff\CapcodeController::class, 'create']);
Router::post('/staff/capcodes/store', [\App\Controller\Staff\CapcodeController::class, 'store']);
Router::get('/staff/capcodes/{id:\d+}/edit', [\App\Controller\Staff\CapcodeController::class, 'edit']);
Router::post('/staff/capcodes/{id:\d+}/update', [\App\Controller\Staff\CapcodeController::class, 'update']);
Router::post('/staff/capcodes/{id:\d+}/delete', [\App\Controller\Staff\CapcodeController::class, 'delete']);
Router::post('/staff/capcodes/test', [\App\Controller\Staff\CapcodeController::class, 'test']);

// Staff IP Range Bans (Manager+)
Router::get('/staff/iprangebans', [\App\Controller\Staff\IpRangeBanController::class, 'index']);
Router::get('/staff/iprangebans/create', [\App\Controller\Staff\IpRangeBanController::class, 'create']);
Router::post('/staff/iprangebans/store', [\App\Controller\Staff\IpRangeBanController::class, 'store']);
Router::get('/staff/iprangebans/{id:\d+}/edit', [\App\Controller\Staff\IpRangeBanController::class, 'edit']);
Router::post('/staff/iprangebans/{id:\d+}/update', [\App\Controller\Staff\IpRangeBanController::class, 'update']);
Router::post('/staff/iprangebans/{id:\d+}/delete', [\App\Controller\Staff\IpRangeBanController::class, 'delete']);
Router::post('/staff/iprangebans/test', [\App\Controller\Staff\IpRangeBanController::class, 'test']);

// Staff Autopurge Rules (Manager+)
Router::get('/staff/autopurge', [\App\Controller\Staff\AutopurgeController::class, 'index']);
Router::get('/staff/autopurge/create', [\App\Controller\Staff\AutopurgeController::class, 'create']);
Router::post('/staff/autopurge/store', [\App\Controller\Staff\AutopurgeController::class, 'store']);
Router::get('/staff/autopurge/{id:\d+}/edit', [\App\Controller\Staff\AutopurgeController::class, 'edit']);
Router::post('/staff/autopurge/{id:\d+}/update', [\App\Controller\Staff\AutopurgeController::class, 'update']);
Router::post('/staff/autopurge/{id:\d+}/delete', [\App\Controller\Staff\AutopurgeController::class, 'delete']);
Router::post('/staff/autopurge/test', [\App\Controller\Staff\AutopurgeController::class, 'test']);

// Staff DMCA Management (Manager+)
Router::get('/staff/dmca', [\App\Controller\Staff\DmcaController::class, 'index']);
Router::get('/staff/dmca/create', [\App\Controller\Staff\DmcaController::class, 'create']);
Router::post('/staff/dmca/store', [\App\Controller\Staff\DmcaController::class, 'store']);
Router::get('/staff/dmca/{id:\d+}', [\App\Controller\Staff\DmcaController::class, 'view']);
Router::post('/staff/dmca/{id:\d+}/process', [\App\Controller\Staff\DmcaController::class, 'process']);
Router::post('/staff/dmca/{id:\d+}/status', [\App\Controller\Staff\DmcaController::class, 'updateStatus']);

// Staff Blotter Messages (Manager+)
Router::get('/staff/blotter', [\App\Controller\Staff\BlotterController::class, 'index']);
Router::get('/staff/blotter/create', [\App\Controller\Staff\BlotterController::class, 'create']);
Router::post('/staff/blotter/store', [\App\Controller\Staff\BlotterController::class, 'store']);
Router::get('/staff/blotter/{id:\d+}/edit', [\App\Controller\Staff\BlotterController::class, 'edit']);
Router::post('/staff/blotter/{id:\d+}/update', [\App\Controller\Staff\BlotterController::class, 'update']);
Router::post('/staff/blotter/{id:\d+}/delete', [\App\Controller\Staff\BlotterController::class, 'delete']);
Router::post('/staff/blotter/preview', [\App\Controller\Staff\BlotterController::class, 'preview']);

// Staff Site Messages (Manager+)
Router::get('/staff/site-messages', [\App\Controller\Staff\SiteMessageController::class, 'index']);
Router::get('/staff/site-messages/create', [\App\Controller\Staff\SiteMessageController::class, 'create']);
Router::post('/staff/site-messages/store', [\App\Controller\Staff\SiteMessageController::class, 'store']);
Router::get('/staff/site-messages/{id:\d+}/edit', [\App\Controller\Staff\SiteMessageController::class, 'edit']);
Router::post('/staff/site-messages/{id:\d+}/update', [\App\Controller\Staff\SiteMessageController::class, 'update']);
Router::post('/staff/site-messages/{id:\d+}/delete', [\App\Controller\Staff\SiteMessageController::class, 'delete']);
Router::post('/staff/site-messages/preview', [\App\Controller\Staff\SiteMessageController::class, 'preview']);

// Board Configuration (Manager+)
Router::get('/staff/boards', [\App\Controller\Staff\BoardConfigController::class, 'index']);
Router::get('/staff/boards/create', [\App\Controller\Staff\BoardConfigController::class, 'create']);
Router::post('/staff/boards/store', [\App\Controller\Staff\BoardConfigController::class, 'store']);
Router::get('/staff/boards/{slug}/edit', [\App\Controller\Staff\BoardConfigController::class, 'edit']);
Router::post('/staff/boards/{slug}/update', [\App\Controller\Staff\BoardConfigController::class, 'update']);
Router::post('/staff/boards/{slug}/delete', [\App\Controller\Staff\BoardConfigController::class, 'delete']);

// Site Settings (Admin only)
Router::get('/staff/site-settings', [\App\Controller\Staff\SiteSettingsController::class, 'index']);
Router::post('/staff/site-settings/update', [\App\Controller\Staff\SiteSettingsController::class, 'update']);
Router::get('/staff/site-settings/{key}/audit', [\App\Controller\Staff\SiteSettingsController::class, 'audit']);

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
Router::post('/api/v1/bans/{id:\d+}/unban', [App\Controller\ModerationController::class, 'unban']);

// Spam check and captcha
Router::post('/api/v1/spam/check', [App\Controller\ModerationController::class, 'spamCheck']);
Router::get('/api/v1/captcha', [App\Controller\ModerationController::class, 'captcha']);
Router::post('/api/v1/captcha/verify', [App\Controller\ModerationController::class, 'verifyCaptcha']);

// ALTCHA proof-of-work captcha
Router::get('/api/v1/altcha/challenge', [App\Controller\AltchaController::class, 'challenge']);

// ============== Frontend Routes ==============

// Report popup (must be before board catch-all)
Router::get('/report/{board}/{no:\d+}', [ReportController::class, 'show']);
Router::post('/report/{board}/{no:\d+}', [ReportController::class, 'submit']);

// Homepage
Router::get('/', [FrontendController::class, 'home']);

// Static pages
Router::get('/about', [FrontendController::class, 'about']);
Router::get('/rules', [FrontendController::class, 'rules']);
Router::get('/feedback', [FrontendController::class, 'feedback']);

// Legal pages
Router::get('/legal', [FrontendController::class, 'legal']);
Router::get('/legal/privacy', [FrontendController::class, 'legalPrivacy']);
Router::get('/legal/terms', [FrontendController::class, 'legalTerms']);
Router::get('/legal/cookies', [FrontendController::class, 'legalCookies']);
Router::get('/legal/rights', [FrontendController::class, 'legalRights']);
Router::get('/legal/contact', [FrontendController::class, 'legalContact']);
Router::get('/contact', [FrontendController::class, 'legalContact']);

// Feedback API
Router::post('/api/v1/feedback', [App\Controller\FeedbackController::class, 'submit']);

// Board pages (must be before API catch-all)
Router::get('/{slug}/catalog', [FrontendController::class, 'catalog']);
Router::get('/{slug}/archive', [FrontendController::class, 'archive']);
Router::get('/{slug}/thread/{id:\d+}', [FrontendController::class, 'thread']);
Router::get('/{slug}/', [FrontendController::class, 'board']);
Router::get('/{slug}', [FrontendController::class, 'board']);

// Frontend form submissions (redirect handlers)
Router::post('/{slug}/threads', [FrontendController::class, 'createThread']);
Router::post('/{slug}/thread/{id:\d+}/posts', [FrontendController::class, 'createPost']);
Router::post('/{slug}/delete', [FrontendController::class, 'deletePosts']);

// API proxy – catch-all (must be last)
Router::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    '/api/v1/{path:.*}', [GatewayController::class, 'proxy']);
