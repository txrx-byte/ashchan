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
 * Binary WebSocket protocol encoder/decoder.
 *
 * Wire format follows meguca's binary protocol:
 * - Post IDs: float64 little-endian (8 bytes) for fast JS DataView.getFloat64()
 * - Message type: last byte of the frame (allows variable-length payloads)
 *
 * Binary message types (hot path — character streaming):
 *   0x02 = Append   [postID:f64LE][char:utf8][0x02]
 *   0x03 = Backspace [postID:f64LE][0x03]
 *   0x04 = Splice   [postID:f64LE][start:u16LE][len:u16LE][text:utf8][0x04]
 *
 * @see docs/LIVEPOSTING.md §4.2, §5.8
 */
final class BinaryProtocol
{
    public const TYPE_APPEND    = 0x02;
    public const TYPE_BACKSPACE = 0x03;
    public const TYPE_SPLICE    = 0x04;

    /**
     * IEEE 754 double-precision safe integer limit (2^53).
     * Post IDs beyond this value lose precision in float64 encoding.
     */
    public const SAFE_INTEGER_MAX = 9007199254740992; // 2^53

    /**
     * Encode a post ID as float64 little-endian (8 bytes).
     *
     * CAVEAT: IEEE 754 double-precision has 53 bits of integer mantissa.
     * Post IDs above 2^53 (9,007,199,254,740,992) will lose precision.
     * At 1,000 posts/second this takes ~285,000 years to reach.
     *
     * @throws \InvalidArgumentException if post ID exceeds safe range
     */
    public static function encodePostId(int $postId): string
    {
        if ($postId < 0 || $postId > self::SAFE_INTEGER_MAX) {
            throw new \InvalidArgumentException(
                "Post ID {$postId} exceeds float64 safe integer range [0, 2^53]"
            );
        }
        return pack('e', (float) $postId); // 'e' = little-endian double
    }

    /**
     * Decode a post ID from the first 8 bytes of binary data.
     *
     * @throws \InvalidArgumentException if data is too short or precision is lost
     */
    public static function decodePostId(string $data): int
    {
        if (strlen($data) < 8) {
            throw new \InvalidArgumentException(
                'Binary data too short for float64 post ID (need 8 bytes, got ' . strlen($data) . ')'
            );
        }

        /** @var array{1: float} $unpacked */
        $unpacked = unpack('e', substr($data, 0, 8));
        $float = $unpacked[1];
        $int = (int) $float;

        if (abs($float - (float) $int) >= 0.5) {
            throw new \InvalidArgumentException(
                "Post ID lost precision in float64 decode (float={$float}, int={$int})"
            );
        }

        return $int;
    }

    /**
     * Encode an Append broadcast frame: [postID:f64LE][char:utf8][0x02]
     */
    public static function encodeAppend(int $postId, string $charUtf8): string
    {
        return self::encodePostId($postId) . $charUtf8 . chr(self::TYPE_APPEND);
    }

    /**
     * Encode a Backspace broadcast frame: [postID:f64LE][0x03]
     */
    public static function encodeBackspace(int $postId): string
    {
        return self::encodePostId($postId) . chr(self::TYPE_BACKSPACE);
    }

    /**
     * Encode a Splice broadcast frame: [postID:f64LE][start:u16LE][len:u16LE][text:utf8][0x04]
     *
     * @param int $start  Splice start position (character offset)
     * @param int $len    Number of characters to remove
     * @param string $text Replacement text (UTF-8)
     */
    public static function encodeSplice(int $postId, int $start, int $len, string $text): string
    {
        return self::encodePostId($postId)
            . pack('v', $start)  // uint16 LE
            . pack('v', $len)    // uint16 LE
            . $text
            . chr(self::TYPE_SPLICE);
    }

    /**
     * Decode a client-sent binary frame.
     *
     * Client frames do NOT include a post ID (server knows which post the client owns).
     * Format: [...payload...][type:u8] where type is the last byte.
     *
     * @return array{type: int, payload: string}
     * @throws \InvalidArgumentException if frame is empty
     */
    public static function decodeClientFrame(string $data): array
    {
        $len = strlen($data);
        if ($len < 1) {
            throw new \InvalidArgumentException('Empty binary frame');
        }

        $type = ord($data[$len - 1]);
        $payload = substr($data, 0, $len - 1);

        return ['type' => $type, 'payload' => $payload];
    }

    /**
     * Decode a client-sent Splice payload (without type byte).
     *
     * Format: [start:u16LE][len:u16LE][text:utf8]
     *
     * @return array{start: int, len: int, text: string}
     * @throws \InvalidArgumentException if payload is too short
     */
    public static function decodeSplicePayload(string $payload): array
    {
        if (strlen($payload) < 4) {
            throw new \InvalidArgumentException(
                'Splice payload too short (need >= 4 bytes for start+len, got ' . strlen($payload) . ')'
            );
        }

        /** @var array{1: int} $startUnpack */
        $startUnpack = unpack('v', substr($payload, 0, 2));
        /** @var array{1: int} $lenUnpack */
        $lenUnpack = unpack('v', substr($payload, 2, 2));

        return [
            'start' => $startUnpack[1],
            'len'   => $lenUnpack[1],
            'text'  => substr($payload, 4),
        ];
    }

    /**
     * Encode a MessageConcat text frame (type 33).
     *
     * Batches multiple text messages into a single WebSocket frame.
     * Format: "33" + JSON array of message strings.
     *
     * @param array<string> $messages
     */
    public static function encodeConcat(array $messages): string
    {
        return '33' . json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Encode a text message with a two-character type prefix.
     *
     * @param int $type Message type code (0-99)
     * @param mixed $payload JSON-encodable payload (null for no payload)
     */
    public static function encodeTextMessage(int $type, mixed $payload = null): string
    {
        $prefix = str_pad((string) $type, 2, '0', STR_PAD_LEFT);
        if ($payload === null) {
            return $prefix;
        }
        return $prefix . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode a text message: first 2 chars are the type, rest is JSON payload.
     *
     * @return array{type: int, payload: mixed}
     */
    public static function decodeTextMessage(string $data): array
    {
        if (strlen($data) < 2) {
            throw new \InvalidArgumentException('Text message too short (need >= 2 chars)');
        }

        $type = (int) substr($data, 0, 2);
        $jsonStr = substr($data, 2);
        $payload = $jsonStr !== '' && $jsonStr !== false
            ? json_decode($jsonStr, true, 32, JSON_THROW_ON_ERROR)
            : null;

        return ['type' => $type, 'payload' => $payload];
    }
}
