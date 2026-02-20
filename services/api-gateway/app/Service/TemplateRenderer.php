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
