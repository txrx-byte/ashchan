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
    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->basePath . $template . '.html';
        
        if (!file_exists($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($vars);
        
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
