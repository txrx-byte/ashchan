<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Simple PHP template renderer.
 * Templates are plain PHP files that receive extracted variables.
 */
final class TemplateRenderer
{
    private string $viewPath;

    public function __construct()
    {
        // In Docker container: /app/views
        // In dev source tree: detect based on directory existence
        $appViews = '/app/views';
        $srcViews = dirname(__DIR__, 2) . '/views';

        $this->viewPath = is_dir($appViews) ? $appViews : $srcViews;
    }

    /**
     * Render a PHP template with the given data.
     *
     * @param string $template Template name (without .php extension)
     * @param array<string, mixed>  $data     Variables to extract into the template scope
     * @return string Rendered HTML
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->viewPath . '/' . $template . '.php';
        if (!file_exists($file)) {
            return '<!-- Template not found: ' . htmlspecialchars($template) . ' -->';
        }

        // Add common variables
        $data['current_year'] = date('Y');

        extract($data, EXTR_SKIP);

        ob_start();
        include $file;
        return ob_get_clean() ?: '';
    }
}
