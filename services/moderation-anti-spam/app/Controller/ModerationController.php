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
        $postId  = (int) $request->input('post_id', 0);
        $reason  = (string) $request->input('reason', '');
        $details = (string) $request->input('details', '');
        $ip      = $request->getHeaderLine('X-Forwarded-For') ?: $request->server('remote_addr', '');
        $ipHash  = hash('sha256', $ip);

        if (!$postId || !$reason) {
            return $this->response->json(['error' => 'post_id and reason required'], 400);
        }

        $report = $this->modService->createReport($postId, $reason, $details, $ipHash);
        return $this->response->json(['report' => $report->toArray()], 201);
    }

    /** GET /api/v1/reports?status=pending&page=1 – List reports (mod/admin) */
    #[RequestMapping(path: 'reports', methods: ['GET'])]
    public function listReports(RequestInterface $request): ResponseInterface
    {
        $status = (string) $request->query('status', 'pending');
        $page   = max(1, (int) $request->query('page', '1'));

        $data = $this->modService->listReports($status, $page);
        return $this->response->json($data);
    }

    /** POST /api/v1/reports/{id}/decide – Moderate a report */
    #[RequestMapping(path: 'reports/{id:\d+}/decide', methods: ['POST'])]
    public function decide(RequestInterface $request, int $id): ResponseInterface
    {
        $moderatorId = (int) $request->input('moderator_id', 0);
        $action      = (string) $request->input('action', '');
        $reason      = (string) $request->input('reason', '');

        if (!$moderatorId || !$action) {
            return $this->response->json(['error' => 'moderator_id and action required'], 400);
        }

        try {
            $decision = $this->modService->decide($id, $moderatorId, $action, $reason);
            return $this->response->json(['decision' => $decision->toArray()]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 404);
        }
    }

    /** POST /api/v1/reports/{id}/dismiss – Dismiss a report */
    #[RequestMapping(path: 'reports/{id:\d+}/dismiss', methods: ['POST'])]
    public function dismiss(RequestInterface $request, int $id): ResponseInterface
    {
        $moderatorId = (int) $request->input('moderator_id', 0);
        try {
            $this->modService->dismiss($id, $moderatorId);
            return $this->response->json(['status' => 'dismissed']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()], 404);
        }
    }

    /* ──────────────────────────────────────────────
     * Spam Check
     * ────────────────────────────────────────────── */

    /** POST /api/v1/spam/check – Check content before posting */
    #[RequestMapping(path: 'spam/check', methods: ['POST'])]
    public function spamCheck(RequestInterface $request): ResponseInterface
    {
        $ipHash    = (string) $request->input('ip_hash', '');
        $content   = (string) $request->input('content', '');
        $isThread  = (bool) $request->input('is_thread', false);
        $imageHash = $request->input('image_hash');

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
        $bg = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        // Add noise
        for ($i = 0; $i < 50; $i++) {
            $lineColor = imagecolorallocate($img, random_int(150, 230), random_int(150, 230), random_int(150, 230));
            imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
        }

        // Draw text
        $textColor = imagecolorallocate($img, 0, 0, 0);
        $chars = str_split($captcha['answer']);
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

        return $this->response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('X-Captcha-Token', $captcha['token'])
            ->withHeader('Cache-Control', 'no-store')
            ->raw($imgData);
    }

    /** POST /api/v1/captcha/verify – Verify captcha response */
    #[RequestMapping(path: 'captcha/verify', methods: ['POST'])]
    public function verifyCaptcha(RequestInterface $request): ResponseInterface
    {
        $token    = (string) $request->input('token', '');
        $response = (string) $request->input('response', '');

        if (!$token || !$response) {
            return $this->response->json(['valid' => false, 'error' => 'Missing token or response'], 400);
        }

        $valid = $this->spamService->verifyCaptcha($token, $response);
        return $this->response->json(['valid' => $valid]);
    }
}
