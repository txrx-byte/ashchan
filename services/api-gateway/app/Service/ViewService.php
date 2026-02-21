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

use Hyperf\Context\Context;

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

        // Auto-inject CSRF token and staff info from context
        if (!isset($vars['csrf_token'])) {
            $vars['csrf_token'] = Context::get('csrf_token', '');
        }
        if (!isset($vars['staff_info'])) {
            $vars['staff_info'] = Context::get('staff_info', []);
        }

        extract($vars);
        
        ob_start();
        include $file;
        $html = (string) ob_get_clean();

        // Auto-inject CSRF meta tag + fetch interceptor for staff pages
        if (isset($vars['csrf_token']) && $vars['csrf_token'] !== '' && str_contains($template, 'staff/')) {
            $token = htmlspecialchars($vars['csrf_token'], ENT_QUOTES, 'UTF-8');
            $csrfSnippet = <<<HTML
<meta name="csrf-token" content="{$token}">
<script>
(function(){
    var t='{$token}';
    // Intercept fetch for AJAX calls
    var origFetch=window.fetch;
    window.fetch=function(url,opts){
        opts=opts||{};
        var m=(opts.method||'GET').toUpperCase();
        if(['POST','PUT','DELETE','PATCH'].indexOf(m)>=0){
            opts.headers=opts.headers||{};
            if(opts.headers instanceof Headers){opts.headers.set('X-CSRF-Token',t);}
            else{opts.headers['X-CSRF-Token']=t;}
        }
        return origFetch.call(this,url,opts);
    };
    // Inject hidden field into standard forms
    document.addEventListener('DOMContentLoaded',function(){
        document.querySelectorAll('form[method="POST"],form[method="post"]').forEach(function(f){
            if(!f.querySelector('[name="_csrf_token"]')){
                var i=document.createElement('input');
                i.type='hidden';i.name='_csrf_token';i.value=t;
                f.appendChild(i);
            }
        });
    });
})();
</script>
HTML;
            // Inject after <head> or at the start
            if (str_contains($html, '</head>')) {
                $html = str_replace('</head>', $csrfSnippet . "\n</head>", $html);
            } else {
                $html = $csrfSnippet . "\n" . $html;
            }
        }

        return $html;
    }
}
