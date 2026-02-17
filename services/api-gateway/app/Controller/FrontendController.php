<?php
declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Serves the frontend static files and templates.
 * In production, Nginx/Caddy would serve static files directly.
 */
final class FrontendController
{
    public function __construct(
        private HttpResponse $response,
    ) {}

    /** GET / – Homepage */
    public function home(): ResponseInterface
    {
        return $this->response->redirect('/boards/');
    }

    /** Serve static files (development only). */
    public function staticFile(string $path): ResponseInterface
    {
        $basePath = dirname(__DIR__, 3) . '/../frontend/static/';
        $filePath = realpath($basePath . $path);

        if (!$filePath || !str_starts_with($filePath, realpath($basePath))) {
            return $this->response->raw('Not found')->withStatus(404);
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
        ];

        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        $content = file_get_contents($filePath);

        return $this->response->raw($content)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
