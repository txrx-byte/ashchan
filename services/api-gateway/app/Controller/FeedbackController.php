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

use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Handles public feedback submissions from the /feedback page.
 */
final class FeedbackController
{
    private const VALID_CATEGORIES = [
        'bug_report',
        'feature_request',
        'ui_ux',
        'board_suggestion',
        'moderation',
        'performance',
        'security',
        'accessibility',
        'praise',
        'other',
    ];

    private const VALID_PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /** Rate limit: max submissions per IP per hour */
    private const RATE_LIMIT = 5;

    public function __construct(
        private RequestInterface $request,
        private HttpResponse $response,
    ) {}

    /** POST /api/v1/feedback – Submit feedback */
    public function submit(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        // Validate required fields
        $category = trim((string) ($body['category'] ?? ''));
        $subject  = trim((string) ($body['subject'] ?? ''));
        $message  = trim((string) ($body['message'] ?? ''));

        if ($category === '' || $subject === '' || $message === '') {
            return $this->json(['error' => 'Category, subject, and message are required.'], 422);
        }

        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            return $this->json(['error' => 'Invalid category.'], 422);
        }

        if (mb_strlen($subject) > 150) {
            return $this->json(['error' => 'Subject must be 150 characters or fewer.'], 422);
        }

        if (mb_strlen($message) < 10) {
            return $this->json(['error' => 'Message must be at least 10 characters.'], 422);
        }

        if (mb_strlen($message) > 5000) {
            return $this->json(['error' => 'Message must be 5000 characters or fewer.'], 422);
        }

        // Validate optional fields
        $priority = trim((string) ($body['priority'] ?? 'normal'));
        if (!in_array($priority, self::VALID_PRIORITIES, true)) {
            $priority = 'normal';
        }

        $board   = $this->sanitizeOptional($body['board'] ?? null, 20);
        $url     = $this->sanitizeOptional($body['url'] ?? null, 500);
        $browser = $this->sanitizeOptional($body['browser'] ?? null, 500);
        $email   = $this->sanitizeOptional($body['email'] ?? null, 200);
        $name    = $this->sanitizeOptional($body['name'] ?? null, 100);

        // Validate email format if provided
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email address format.'], 422);
        }

        // Get client IP
        $ip = $this->getClientIp();

        // Simple rate limiting
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $recentCount = Db::table('feedback')
            ->where('ip_address', $ip)
            ->where('created_at', '>', $oneHourAgo)
            ->count();

        if ($recentCount >= self::RATE_LIMIT) {
            return $this->json([
                'error' => 'You have submitted too many feedback entries recently. Please try again later.',
            ], 429);
        }

        // Insert
        $now = date('Y-m-d H:i:s');
        $id = Db::table('feedback')->insertGetId([
            'category'   => $category,
            'subject'    => $subject,
            'message'    => $message,
            'board'      => $board,
            'url'        => $url,
            'browser'    => $browser,
            'priority'   => $priority,
            'email'      => $email,
            'name'       => $name,
            'ip_address' => $ip,
            'status'     => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->json([
            'id'      => $id,
            'message' => 'Feedback submitted successfully. Thank you!',
        ], 201);
    }

    /**
     * Sanitize an optional string field: trim, enforce max length, return null if empty.
     */
    private function sanitizeOptional(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }
        return mb_substr($str, 0, $maxLength);
    }

    /** Extract client IP from request headers / server params. */
    private function getClientIp(): string
    {
        $headers = $this->request->getHeaders();
        // Check proxy headers
        foreach (['x-forwarded-for', 'x-real-ip'] as $header) {
            if (!empty($headers[$header][0])) {
                $ips = explode(',', $headers[$header][0]);
                return trim($ips[0]);
            }
        }
        $serverParams = $this->request->getServerParams();
        return (string) ($serverParams['remote_addr'] ?? '127.0.0.1');
    }

    /**
     * Return a JSON response.
     *
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->json($data)->withStatus($status);
    }
}
