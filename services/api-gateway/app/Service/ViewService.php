<?php
declare(strict_types=1);

namespace App\Service;

/**
 * ViewService - Simple template renderer
 */
final class ViewService
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = __DIR__ . '/../../views/';
    }

    /**
     * Render a template with variables
     */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->basePath . $template . '.html';
        
        if (!file_exists($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($vars);
        
        ob_start();
        include $file;
        return ob_get_clean();
    }
}
