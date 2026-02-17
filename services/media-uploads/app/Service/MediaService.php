<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\MediaObject;

/**
 * Media processing pipeline:
 * 1. Validate file type/size
 * 2. Compute SHA-256 hash for dedup
 * 3. Check banned hashes
 * 4. Generate thumbnail
 * 5. Upload to object storage (MinIO/S3)
 * 6. Return media metadata
 */
final class MediaService
{
    private string $storageBucket;
    private string $storageEndpoint;
    private int $maxFileSize;

    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    ];

    private const THUMB_MAX_WIDTH  = 250;
    private const THUMB_MAX_HEIGHT = 250;

    public function __construct()
    {
        $this->storageBucket   = getenv('OBJECT_STORAGE_BUCKET') ?: 'ashchan-media';
        $this->storageEndpoint = getenv('OBJECT_STORAGE_ENDPOINT') ?: 'http://minio:9000';
        $this->maxFileSize     = (int) (getenv('MAX_FILE_SIZE') ?: 4194304); // 4MB
    }

    /**
     * Process an uploaded file.
     *
     * @param string $tmpPath     Temporary file path
     * @param string $origName    Original filename
     * @param string $mimeType    Declared MIME type
     * @param int    $fileSize    File size in bytes
     * @return array Media metadata for post creation
     * @throws \RuntimeException on validation failure
     */
    public function processUpload(string $tmpPath, string $origName, string $mimeType, int $fileSize): array
    {
        // Validate
        $this->validate($tmpPath, $mimeType, $fileSize);

        // Hash for dedup
        $hash = hash_file('sha256', $tmpPath);

        // Check for existing (dedup)
        $existing = MediaObject::query()->where('hash_sha256', $hash)->first();
        if ($existing) {
            if ($existing->banned) {
                throw new \RuntimeException('This file has been banned');
            }
            return $this->toMetadata($existing);
        }

        // Get dimensions
        [$width, $height] = $this->getImageDimensions($tmpPath);

        // Generate thumbnail
        $thumbPath = $this->generateThumbnail($tmpPath, $mimeType);

        // Upload to object storage
        $ext = pathinfo($origName, PATHINFO_EXTENSION) ?: 'jpg';
        $storageKey = date('Y/m/d/') . $hash . '.' . $ext;
        $thumbKey   = date('Y/m/d/') . $hash . '_thumb.' . $ext;

        $this->uploadToStorage($tmpPath, $storageKey, $mimeType);
        if ($thumbPath) {
            $this->uploadToStorage($thumbPath, $thumbKey, $mimeType);
            @unlink($thumbPath);
        }

        // Persist metadata
        $media = MediaObject::create([
            'hash_sha256'      => $hash,
            'mime_type'        => $mimeType,
            'file_size'        => $fileSize,
            'width'            => $width,
            'height'           => $height,
            'storage_key'      => $storageKey,
            'thumb_key'        => $thumbKey,
            'original_filename'=> $origName,
        ]);

        return $this->toMetadata($media);
    }

    /**
     * Delete media by hash (moderation).
     */
    public function banHash(string $hash): void
    {
        MediaObject::query()
            ->where('hash_sha256', $hash)
            ->update(['banned' => true]);
    }

    /* ──────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────── */

    private function validate(string $path, string $mime, int $size): void
    {
        if ($size > $this->maxFileSize) {
            throw new \RuntimeException('File too large (max ' . ($this->maxFileSize / 1048576) . 'MB)');
        }

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \RuntimeException('File type not allowed');
        }

        // Double-check actual MIME using fileinfo
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $actualMime = $finfo->file($path);
        if (!in_array($actualMime, self::ALLOWED_MIMES, true)) {
            throw new \RuntimeException('File type mismatch');
        }
    }

    private function getImageDimensions(string $path): array
    {
        $info = @getimagesize($path);
        return $info ? [(int) $info[0], (int) $info[1]] : [0, 0];
    }

    private function generateThumbnail(string $srcPath, string $mime): ?string
    {
        $info = @getimagesize($srcPath);
        if (!$info) return null;

        [$origW, $origH] = $info;
        if ($origW <= self::THUMB_MAX_WIDTH && $origH <= self::THUMB_MAX_HEIGHT) {
            // Small enough, use original as thumb
            return null;
        }

        $ratio = min(self::THUMB_MAX_WIDTH / $origW, self::THUMB_MAX_HEIGHT / $origH);
        $thumbW = (int) ($origW * $ratio);
        $thumbH = (int) ($origH * $ratio);

        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($srcPath),
            'image/png'  => imagecreatefrompng($srcPath),
            'image/gif'  => imagecreatefromgif($srcPath),
            'image/webp' => imagecreatefromwebp($srcPath),
            default      => null,
        };

        if (!$src) return null;

        $thumb = imagecreatetruecolor($thumbW, $thumbH);

        // Preserve transparency for PNG/GIF/WebP
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $thumbW, $thumbH, $transparent);
        }

        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $thumbW, $thumbH, $origW, $origH);

        $thumbPath = tempnam(sys_get_temp_dir(), 'thumb_');

        match ($mime) {
            'image/jpeg' => imagejpeg($thumb, $thumbPath, 85),
            'image/png'  => imagepng($thumb, $thumbPath, 6),
            'image/gif'  => imagegif($thumb, $thumbPath),
            'image/webp' => imagewebp($thumb, $thumbPath, 85),
        };

        imagedestroy($src);
        imagedestroy($thumb);

        return $thumbPath;
    }

    /**
     * Upload a file to MinIO/S3 using pre-signed URL or direct PUT.
     */
    private function uploadToStorage(string $localPath, string $key, string $mime): void
    {
        $accessKey = getenv('OBJECT_STORAGE_ACCESS_KEY') ?: 'minioadmin';
        $secretKey = getenv('OBJECT_STORAGE_SECRET_KEY') ?: 'minioadmin';
        $bucket    = $this->storageBucket;
        $endpoint  = $this->storageEndpoint;

        $url = "{$endpoint}/{$bucket}/{$key}";
        $date = gmdate('D, d M Y H:i:s T');
        $contentType = $mime;

        // Simple S3v2 auth for MinIO
        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n/{$bucket}/{$key}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => fopen($localPath, 'r'),
            CURLOPT_INFILESIZE     => filesize($localPath),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Date: {$date}",
                "Content-Type: {$contentType}",
                "Authorization: AWS {$accessKey}:{$signature}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 300) {
            throw new \RuntimeException("Failed to upload to storage: HTTP {$httpCode}");
        }
    }

    private function toMetadata(MediaObject $media): array
    {
        $endpoint = rtrim($this->storageEndpoint, '/');
        $bucket   = $this->storageBucket;

        return [
            'media_id'         => $media->id,
            'media_url'        => "{$endpoint}/{$bucket}/{$media->storage_key}",
            'thumb_url'        => $media->thumb_key ? "{$endpoint}/{$bucket}/{$media->thumb_key}" : null,
            'media_filename'   => $media->original_filename,
            'media_size'       => $media->file_size,
            'media_dimensions' => "{$media->width}x{$media->height}",
            'media_hash'       => $media->hash_sha256,
        ];
    }
}
