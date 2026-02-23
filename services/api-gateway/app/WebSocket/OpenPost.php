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

namespace App\WebSocket;

/**
 * State of an open (actively being edited) post.
 *
 * An open post is allocated on the server when a user starts typing.
 * It stays open until explicitly closed, timed out (15 min), or the
 * user disconnects without reclaiming it.
 *
 * @see docs/LIVEPOSTING.md §5.6
 */
final class OpenPost
{
    /** Maximum body length in characters. */
    public const MAX_BODY_LENGTH = 2000;

    /** Maximum number of newlines in a post body. */
    public const MAX_LINE_COUNT = 100;

    /** Maximum open post lifetime in seconds (15 minutes). */
    public const MAX_LIFETIME_SECONDS = 900;

    /**
     * @param int    $postId        Database post ID (BIGSERIAL)
     * @param int    $threadId      Parent thread ID
     * @param string $board         Board slug
     * @param string $body          Current post body text
     * @param int    $charCount     Current character count
     * @param int    $lineCount     Current newline count
     * @param int    $createdAt     Unix timestamp of post allocation
     * @param bool   $hasSpoiler    Whether the post has a spoiler image
     * @param string $passwordHash  Bcrypt hash for post reclamation
     */
    public function __construct(
        public readonly int $postId,
        public readonly int $threadId,
        public readonly string $board,
        public string $body = '',
        public int $charCount = 0,
        public int $lineCount = 0,
        public readonly int $createdAt = 0,
        public bool $hasSpoiler = false,
        public string $passwordHash = '',
    ) {
    }

    /**
     * Whether the post body has reached the maximum character limit.
     */
    public function isBodyFull(): bool
    {
        return $this->charCount >= self::MAX_BODY_LENGTH;
    }

    /**
     * Whether the post has too many lines.
     */
    public function hasMaxLines(): bool
    {
        return $this->lineCount >= self::MAX_LINE_COUNT;
    }

    /**
     * Whether the post has exceeded its maximum open lifetime.
     */
    public function isExpired(): bool
    {
        return (time() - $this->createdAt) >= self::MAX_LIFETIME_SECONDS;
    }

    /**
     * Append a character to the body. Returns false if limits exceeded.
     *
     * @param string $char Single UTF-8 character
     * @return bool True if append succeeded, false if body is full
     */
    public function appendChar(string $char): bool
    {
        if ($this->isBodyFull()) {
            return false;
        }

        if ($char === "\n") {
            if ($this->hasMaxLines()) {
                return false;
            }
            $this->lineCount++;
        }

        $this->body .= $char;
        $this->charCount++;
        return true;
    }

    /**
     * Remove the last character from the body. Returns false if body is empty.
     *
     * @return bool True if backspace succeeded, false if body was empty
     */
    public function backspace(): bool
    {
        if ($this->body === '') {
            return false;
        }

        // Get last UTF-8 character
        $lastChar = mb_substr($this->body, -1, 1, 'UTF-8');
        $this->body = mb_substr($this->body, 0, -1, 'UTF-8');
        $this->charCount--;

        if ($lastChar === "\n") {
            $this->lineCount--;
        }

        return true;
    }

    /**
     * Splice the body: remove `$len` characters at `$start`, insert `$text`.
     *
     * @param int    $start Character offset to start splicing
     * @param int    $len   Number of characters to remove
     * @param string $text  Replacement text
     * @return bool True if splice succeeded, false if limits exceeded
     */
    public function splice(int $start, int $len, string $text): bool
    {
        $before = mb_substr($this->body, 0, $start, 'UTF-8');
        $after  = mb_substr($this->body, $start + $len, null, 'UTF-8');
        $newBody = $before . $text . $after;

        $newCharCount = mb_strlen($newBody, 'UTF-8');
        if ($newCharCount > self::MAX_BODY_LENGTH) {
            return false;
        }

        $newLineCount = substr_count($newBody, "\n");
        if ($newLineCount > self::MAX_LINE_COUNT) {
            return false;
        }

        $this->body = $newBody;
        $this->charCount = $newCharCount;
        $this->lineCount = $newLineCount;
        return true;
    }
}
