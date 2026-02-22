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


namespace App\Controller;

use App\Model\ReportCategory;
use App\Service\AltchaService;
use App\Service\ModerationService;
use App\Service\PiiEncryptionService;
use App\Service\TemplateRenderer;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * ReportController – serves the popup report form and handles submission.
 *
 * GET  /report/{board}/{no} – Show report popup form
 * POST /report/{board}/{no} – Submit report
 */
final class ReportController
{
    public function __construct(
        private HttpResponse $response,
        private TemplateRenderer $renderer,
        private ModerationService $modService,
        private PiiEncryptionService $piiEncryption,
        private AltchaService $altcha,
    ) {}

    /**
     * GET /report/{board}/{no} – Render the report popup form
     */
    public function show(RequestInterface $request, string $board, int $no): ResponseInterface
    {
        $board = preg_replace('/[^a-z0-9]/', '', strtolower($board)) ?: '';
        if ($board === '' || $no < 1) {
            return $this->html($this->renderer->render('report', [
                'error' => 'Invalid board or post number.',
            ]));
        }

        // Determine worksafe status (default to true for /g/-style boards)
        $worksafe = $this->isWorksafeBoard($board);
        $categories = ReportCategory::getForReportForm($board, $worksafe);

        return $this->html($this->renderer->render('report', [
            'board' => $board,
            'post_no' => $no,
            'categories' => $categories,
        ]));
    }

    /**
     * POST /report/{board}/{no} – Process report submission
     */
    public function submit(RequestInterface $request, string $board, int $no): ResponseInterface
    {
        $board = preg_replace('/[^a-z0-9]/', '', strtolower($board)) ?: '';
        if ($board === '' || $no < 1) {
            return $this->html($this->renderer->render('report', [
                'error' => 'Invalid board or post number.',
            ]));
        }

        // Verify ALTCHA captcha
        if ($this->altcha->isEnabled()) {
            $altchaPayload = $request->input('altcha');
            $altchaPayload = is_string($altchaPayload) ? $altchaPayload : '';
            if (!$this->altcha->verifySolution($altchaPayload)) {
                return $this->html($this->renderer->render('report', [
                    'error' => 'Captcha verification failed. Please try again.',
                ]));
            }
        }

        // Determine category
        $catType = $request->input('cat', 'rule');
        $categoryId = (int) $request->input('cat_id', 0);

        if ($catType === 'illegal') {
            // Use the illegal category (ID 31 by convention)
            $categoryId = 31;
        }

        if ($categoryId < 1) {
            return $this->html($this->renderer->render('report', [
                'error' => 'Please select a report category.',
            ]));
        }

        // Get reporter info
        $remoteAddr = $request->server('remote_addr', '');
        $ip = is_string($remoteAddr) ? $remoteAddr : '';
        $encryptedIp = $this->piiEncryption->encrypt($ip);
        $ipHash = hash('sha256', $ip);

        // Build request signature
        $reqSig = md5(($request->header('user-agent', '') ?: '') . $ip);

        $postData = [
            'no' => $no,
            'resto' => 0,
            'com' => '',
            'host' => '',
        ];

        try {
            $this->modService->createReport(
                $no,
                $board,
                $categoryId,
                $postData,
                $encryptedIp,
                $ipHash,
                null,
                null,
                $reqSig,
            );

            return $this->html($this->renderer->render('report', [
                'success' => true,
                'board' => $board,
                'post_no' => $no,
            ]));
        } catch (\InvalidArgumentException $e) {
            return $this->html($this->renderer->render('report', [
                'error' => $e->getMessage(),
            ]));
        } catch (\Throwable $e) {
            error_log('ReportController error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->html($this->renderer->render('report', [
                'error' => 'Failed to submit report. Please try again.',
            ]));
        }
    }

    /**
     * Check if a board is worksafe (mirrors ReportCategory logic)
     */
    private function isWorksafeBoard(string $board): bool
    {
        $worksafe = ['g', 'prog', 'fit', 'sci', 'biz', 'diy', 'ck', 'gd', 'ic', 'lit'];
        return in_array($board, $worksafe, true);
    }

    /**
     * Send HTML response
     */
    private function html(string $html): ResponseInterface
    {
        return $this->response->html($html);
    }
}
