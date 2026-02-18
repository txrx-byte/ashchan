<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ModerationService;
use App\Service\SpamService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api/v1')]
final class ModerationController
{
    public function __construct(
        private ModerationService $modService,
        private SpamService $spamService,
        private HttpResponse $response,
    ) {}

    /* ──────────────────────────────────────────────
     * Reports
     * ────────────────────────────────────────────── */

    /** POST /api/v1/reports – Submit a report */
    #[RequestMapping(path: 'reports', methods: ['POST'])]
    public function createReport(RequestInterface $request): ResponseInterface
    {
        $postId  = $request->input('post_id');
        $reason  = $request->input('reason');
        $details = $request->input('details', '');
        $remoteAddr = $request->server('remote_addr', '');
        $ip = $request->getHeaderLine('X-Forwarded-For') ?: (is_string($remoteAddr) ? $remoteAddr : '');
        $ipHash = hash('sha256', $ip);

        if (!is_numeric($postId) || !is_string($reason) || (int)$postId === 0 || $reason === '') {
            return $this->response->json(['error' => 'post_id and reason required']);
        }

        $report = $this->modService->createReport((int)$postId, $reason, is_string($details) ? $details : '', $ipHash);
        return $this->response->json(['report' => $report->toArray()]);
    }

    /** GET /api/v1/reports?status=pending&page=1 – List reports (mod/admin) */
    #[RequestMapping(path: 'reports', methods: ['GET'])]
    public function listReports(RequestInterface $request): ResponseInterface
    {
        $status = $request->query('status', 'pending');
        $page   = $request->query('page', '1');

        $data = $this->modService->listReports(is_string($status) ? $status : 'pending', max(1, is_numeric($page) ? (int)$page : 1));
        return $this->response->json($data);
    }

    /** POST /api/v1/reports/{id}/decide – Moderate a report */
    #[RequestMapping(path: 'reports/{id:\d+}/decide', methods: ['POST'])]
    public function decide(RequestInterface $request, int $id): ResponseInterface
    {
        $moderatorId = $request->input('moderator_id');
        $action      = $request->input('action');
        $reason      = $request->input('reason', '');

        if (!is_numeric($moderatorId) || !is_string($action) || (int)$moderatorId === 0 || $action === '') {
            return $this->response->json(['error' => 'moderator_id and action required']);
        }

        try {
            $decision = $this->modService->decide($id, (int)$moderatorId, $action, is_string($reason) ? $reason : '');
            return $this->response->json(['decision' => $decision->toArray()]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()]);
        }
    }

    /** POST /api/v1/reports/{id}/dismiss – Dismiss a report */
    #[RequestMapping(path: 'reports/{id:\d+}/dismiss', methods: ['POST'])]
    public function dismiss(RequestInterface $request, int $id): ResponseInterface
    {
        $moderatorId = $request->input('moderator_id');
        if (!is_numeric($moderatorId)) {
            return $this->response->json(['error' => 'Invalid moderator ID']);
        }
        try {
            $this->modService->dismiss($id, (int)$moderatorId);
            return $this->response->json(['status' => 'dismissed']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()]);
        }
    }

    /* ──────────────────────────────────────────────
     * Spam Check
     * ────────────────────────────────────────────── */

    /** POST /api/v1/spam/check – Check content before posting */
    #[RequestMapping(path: 'spam/check', methods: ['POST'])]
    public function spamCheck(RequestInterface $request): ResponseInterface
    {
        $ipHash    = $request->input('ip_hash');
        $content   = $request->input('content');
        $isThread  = (bool) $request->input('is_thread', false);
        $imageHash = $request->input('image_hash');

        if (!is_string($ipHash) || !is_string($content) || (!is_null($imageHash) && !is_string($imageHash))) {
            return $this->response->json(['error' => 'Invalid input']);
        }

        $result = $this->spamService->check($ipHash, $content, $isThread, $imageHash);
        return $this->response->json($result);
    }

    /* ──────────────────────────────────────────────
     * Captcha
     * ────────────────────────────────────────────── */

    /** GET /api/v1/captcha – Generate a new captcha */
    #[RequestMapping(path: 'captcha', methods: ['GET'])]
    public function captcha(): ResponseInterface
    {
        $captcha = $this->spamService->generateCaptcha();

        // Generate captcha image
        $width  = 200;
        $height = 70;
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
             return $this->response->json(['error' => 'Failed to create image']);
        }
        $bg = imagecolorallocate($img, 255, 255, 255);
        if ($bg === false) {
            imagedestroy($img);
            return $this->response->json(['error' => 'Failed to allocate color']);
        }
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        // Add noise
        for ($i = 0; $i < 50; $i++) {
            $lineColor = imagecolorallocate($img, random_int(150, 230), random_int(150, 230), random_int(150, 230));
            if ($lineColor !== false) {
                imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
            }
        }

        // Draw text
        $textColor = imagecolorallocate($img, 0, 0, 0);
        if ($textColor === false) {
            imagedestroy($img);
            return $this->response->json(['error' => 'Failed to allocate color']);
        }
        $chars = str_split((string) $captcha['answer']);
        $x = 20;
        foreach ($chars as $char) {
            $y = random_int(25, 45);
            $fontSize = random_int(4, 5);
            imagestring($img, $fontSize, $x, $y, $char, $textColor);
            $x += random_int(25, 32);
        }

        ob_start();
        imagepng($img);
        $imgData = ob_get_clean();
        imagedestroy($img);

        return $this->response->raw($imgData ?: '')
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('X-Captcha-Token', (string) $captcha['token'])
            ->withHeader('Cache-Control', 'no-store');
    }

    /** POST /api/v1/captcha/verify – Verify captcha response */
    #[RequestMapping(path: 'captcha/verify', methods: ['POST'])]
    public function verifyCaptcha(RequestInterface $request): ResponseInterface
    {
        $token    = $request->input('token');
        $response = $request->input('response');

        if (!is_string($token) || !is_string($response) || !$token || !$response) {
            return $this->response->json(['valid' => false, 'error' => 'Missing token or response']);
        }

        $valid = $this->spamService->verifyCaptcha($token, $response);
        return $this->response->json(['valid' => $valid]);
    }
}
