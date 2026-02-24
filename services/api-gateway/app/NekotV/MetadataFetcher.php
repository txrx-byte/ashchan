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

namespace App\NekotV;

use Psr\Log\LoggerInterface;

/**
 * Fetches video metadata from supported platforms.
 *
 * Supports YouTube (via Data API v3), Twitch (via yt-dlp), Kick (regex),
 * TikTok, and raw MP4/WebM (via ffprobe) from whitelisted domains.
 *
 * Port of meguca's websockets/feeds/nekotv/yt_api.go and friends.
 * Uses cURL for HTTP requests and Swoole\Coroutine\System::exec()
 * for subprocess calls (yt-dlp, ffprobe) to avoid blocking the event loop.
 *
 * @see meguca/websockets/feeds/nekotv/yt_api.go
 * @see meguca/websockets/feeds/nekotv/ytdlp.go
 * @see meguca/websockets/feeds/nekotv/tiktok.go
 */
final class MetadataFetcher
{
    /** YouTube video ID patterns. */
    private const YOUTUBE_PATTERNS = [
        '/youtube\.com.*v=([A-Za-z0-9_-]+)/',
        '/youtu\.be\/([A-Za-z0-9_-]+)/',
        '/youtube\.com\/shorts\/([A-Za-z0-9_-]+)/',
        '/youtube\.com\/embed\/([A-Za-z0-9_-]+)/',
    ];

    /** Twitch stream URL pattern. */
    private const TWITCH_PATTERN = '/(?:https?:\/\/)?(?:www\.)?twitch\.tv\/(\w+)(?:\/)?/';

    /** Kick stream URL pattern. */
    private const KICK_PATTERN = '/(?:https?:\/\/)?(?:www\.)?kick\.com\/(\w+)(?:\/)?/';

    /** TikTok video URL pattern. */
    private const TIKTOK_PATTERN = '/tiktok\.com\/@[\w.]+\/video\/(\d+)/';

    /** YouTube Data API v3 base URL. */
    private const YOUTUBE_API_URL = 'https://www.googleapis.com/youtube/v3/videos';

    /** ISO 8601 duration component patterns. */
    private const DURATION_HOURS_PATTERN   = '/(\d+)H/';
    private const DURATION_MINUTES_PATTERN = '/(\d+)M/';
    private const DURATION_SECONDS_PATTERN = '/(\d+)S/';

    /** Configured YouTube API key (null = disabled). */
    private readonly ?string $youtubeApiKey;

    /** Whitelisted URL prefixes for raw video playback. */
    private readonly array $mp4Whitelist;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $key = getenv('YOUTUBE_API_KEY');
        $this->youtubeApiKey = ($key !== false && $key !== '') ? $key : null;

        $whitelist = getenv('NEKOTV_MP4_WHITELIST');
        $this->mp4Whitelist = ($whitelist !== false && $whitelist !== '')
            ? array_map('trim', explode(',', $whitelist))
            : [];
    }

    /**
     * Fetch video metadata from a URL.
     *
     * Dispatches to the appropriate platform fetcher based on URL pattern.
     * Returns null if the URL is not recognized or metadata cannot be retrieved.
     */
    public function fetch(string $url): ?VideoItem
    {
        // Try TikTok
        $item = $this->fetchTiktok($url);
        if ($item !== null) {
            return $item;
        }

        // Try Twitch
        $item = $this->fetchTwitch($url);
        if ($item !== null) {
            return $item;
        }

        // Try Kick
        $item = $this->fetchKick($url);
        if ($item !== null) {
            return $item;
        }

        // Try YouTube
        $videoId = $this->extractYoutubeId($url);
        if ($videoId !== null) {
            return $this->fetchYoutube($videoId, $url);
        }

        // Try raw video from whitelisted domain
        return $this->fetchRawVideo($url);
    }

    /**
     * Fetch YouTube video metadata via Data API v3.
     */
    private function fetchYoutube(string $videoId, string $originalUrl): ?VideoItem
    {
        if ($this->youtubeApiKey === null) {
            $this->logger->warning('YouTube API key not configured');
            return null;
        }

        $apiUrl = sprintf(
            '%s?part=snippet,contentDetails&fields=items(snippet/title,contentDetails/duration)&id=%s&key=%s',
            self::YOUTUBE_API_URL,
            urlencode($videoId),
            urlencode($this->youtubeApiKey),
        );

        $response = $this->httpGet($apiUrl);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || isset($data['error'])) {
            $errorMsg = $data['error']['message'] ?? 'Unknown YouTube API error';
            $this->logger->error('YouTube API error', ['error' => $errorMsg]);
            return null;
        }

        $items = $data['items'] ?? [];
        if (empty($items)) {
            $this->logger->warning('YouTube video not found', ['id' => $videoId]);
            return null;
        }

        $item = $items[0];
        $title = $item['snippet']['title'] ?? 'Unknown';
        $durationStr = $item['contentDetails']['duration'] ?? 'PT0S';
        $duration = $this->parseIsoDuration($durationStr);

        // Duration 0 = live stream → use iframe embed
        if ($duration === 0.0) {
            return new VideoItem(
                url: "https://www.youtube.com/watch?v={$videoId}",
                title: $title,
                author: '',
                duration: VideoItem::INFINITE_DURATION,
                id: "https://www.youtube.com/embed/{$videoId}",
                type: VideoType::IFRAME,
            );
        }

        return new VideoItem(
            url: $originalUrl,
            title: $title,
            author: '',
            duration: $duration,
            id: $videoId,
            type: VideoType::YOUTUBE,
        );
    }

    /**
     * Fetch Twitch stream metadata via yt-dlp.
     */
    private function fetchTwitch(string $url): ?VideoItem
    {
        if (!preg_match(self::TWITCH_PATTERN, $url, $matches)) {
            return null;
        }

        $channel = $matches[1];
        $twitchUrl = "https://www.twitch.tv/{$channel}";

        $result = $this->execCommand('yt-dlp', ['--dump-json', $twitchUrl]);
        if ($result === null) {
            return null;
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            return null;
        }

        $streamer = $data['display_id'] ?? $channel;
        $streamTitle = $data['description'] ?? '';
        $webpageUrl = $data['webpage_url'] ?? $twitchUrl;
        $title = "{$streamer} - {$streamTitle}";

        return new VideoItem(
            url: $webpageUrl,
            title: $title,
            author: $streamer,
            duration: VideoItem::INFINITE_DURATION,
            id: '',
            type: VideoType::TWITCH,
        );
    }

    /**
     * Fetch Kick stream metadata (regex-based, no API call).
     */
    private function fetchKick(string $url): ?VideoItem
    {
        if (!preg_match(self::KICK_PATTERN, $url, $matches)) {
            return null;
        }

        $username = $matches[1];
        $kickUrl = "https://kick.com/{$username}";

        return new VideoItem(
            url: $kickUrl,
            title: $kickUrl,
            author: $username,
            duration: VideoItem::INFINITE_DURATION,
            id: "https://player.kick.com/{$username}",
            type: VideoType::IFRAME,
        );
    }

    /**
     * Fetch TikTok video metadata.
     */
    private function fetchTiktok(string $url): ?VideoItem
    {
        if (!preg_match(self::TIKTOK_PATTERN, $url, $matches)) {
            return null;
        }

        $videoId = $matches[1];

        // Use yt-dlp for TikTok metadata as well
        $result = $this->execCommand('yt-dlp', ['--dump-json', '--no-download', $url]);
        if ($result === null) {
            // Fallback to basic info
            return new VideoItem(
                url: $url,
                title: "TikTok {$videoId}",
                author: '',
                duration: 60.0, // Default duration estimate
                id: $videoId,
                type: VideoType::TIKTOK,
            );
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            return null;
        }

        $author = $data['uploader'] ?? $data['creator'] ?? '';
        $title = $data['title'] ?? $data['description'] ?? '';
        $duration = (float) ($data['duration'] ?? 60);

        if ($title === '') {
            $title = "@{$author} - {$videoId}";
        } else {
            $title = "@{$author} - {$title}";
        }

        return new VideoItem(
            url: $url,
            title: $title,
            author: $author,
            duration: $duration + 1.0, // +1s buffer like meguca
            id: $videoId,
            type: VideoType::TIKTOK,
        );
    }

    /**
     * Fetch raw video metadata via ffprobe (whitelisted domains only).
     */
    private function fetchRawVideo(string $url): ?VideoItem
    {
        if (!$this->isWhitelistedDomain($url)) {
            return null;
        }

        $lowerUrl = strtolower($url);
        if (!str_ends_with($lowerUrl, '.mp4') && !str_ends_with($lowerUrl, '.webm')) {
            return null;
        }

        // Use ffprobe to get duration
        $result = $this->execCommand('ffprobe', [
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            $url,
        ]);

        if ($result === null) {
            return null;
        }

        $data = json_decode($result, true);
        $duration = (float) ($data['format']['duration'] ?? 0);

        if ($duration <= 0) {
            $this->logger->warning('ffprobe returned zero duration', ['url' => $url]);
            return null;
        }

        return new VideoItem(
            url: $url,
            title: $url,
            author: '',
            duration: $duration,
            id: '',
            type: VideoType::RAW,
        );
    }

    /**
     * Extract a YouTube video ID from a URL.
     */
    private function extractYoutubeId(string $url): ?string
    {
        foreach (self::YOUTUBE_PATTERNS as $pattern) {
            if (preg_match($pattern, $url, $matches) && isset($matches[1])) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Parse an ISO 8601 duration string (PT1H2M3S) to seconds.
     */
    private function parseIsoDuration(string $duration): float
    {
        $total = 0.0;

        if (preg_match(self::DURATION_HOURS_PATTERN, $duration, $m)) {
            $total += (int) $m[1] * 3600;
        }
        if (preg_match(self::DURATION_MINUTES_PATTERN, $duration, $m)) {
            $total += (int) $m[1] * 60;
        }
        if (preg_match(self::DURATION_SECONDS_PATTERN, $duration, $m)) {
            $total += (int) $m[1];
        }

        return $total;
    }

    /**
     * Check if a URL is from a whitelisted domain.
     */
    private function isWhitelistedDomain(string $url): bool
    {
        foreach ($this->mp4Whitelist as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Execute an HTTP GET request using cURL.
     *
     * Uses Swoole's cURL hook for coroutine compatibility.
     */
    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'ashchan-nekotv/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $this->logger->warning('HTTP GET failed', [
                'url'       => $url,
                'http_code' => $httpCode,
                'error'     => $error,
            ]);
            return null;
        }

        return (string) $response;
    }

    /**
     * Execute a command using Swoole's coroutine-safe exec.
     *
     * @param string $command Command name
     * @param list<string> $args Command arguments
     */
    private function execCommand(string $command, array $args): ?string
    {
        $escapedArgs = array_map('escapeshellarg', $args);
        $fullCommand = escapeshellcmd($command) . ' ' . implode(' ', $escapedArgs);

        if (class_exists(\Swoole\Coroutine\System::class) && \Swoole\Coroutine::getCid() > 0) {
            // Inside a coroutine — use non-blocking exec
            $result = \Swoole\Coroutine\System::exec($fullCommand);
            if ($result === false || $result['code'] !== 0) {
                $this->logger->warning('Command execution failed', [
                    'command' => $command,
                    'code'    => $result['code'] ?? -1,
                ]);
                return null;
            }
            return $result['output'] ?? null;
        }

        // Fallback for non-coroutine context
        $output = [];
        $returnVar = 0;
        exec($fullCommand, $output, $returnVar);

        if ($returnVar !== 0) {
            return null;
        }

        return implode("\n", $output);
    }
}
