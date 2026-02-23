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

/**
 * livepost/protocol.js
 * Binary encode/decode for the ashchan WebSocket wire format.
 *
 * Binary frames: type byte is the LAST byte. Post IDs are float64 LE (8 bytes).
 * Text frames:   2-char zero-padded type prefix + optional JSON payload.
 *
 * Must be loaded before connection.js.
 */

(function() {
'use strict';

var LP = window.Livepost = window.Livepost || {};

var encoder = new TextEncoder();
var decoder = new TextDecoder();

LP.Protocol = {

    // ---- Text message type codes ----
    MSG_INSERT_POST:  1,
    MSG_CLOSE_POST:   5,
    MSG_INSERT_IMAGE: 6,
    MSG_SPOILER:      7,
    MSG_SYNCHRONISE: 30,
    MSG_RECLAIM:     31,
    MSG_POST_ID:     32,
    MSG_CONCAT:      33,
    MSG_NOOP:        34,
    MSG_SYNC_COUNT:  35,
    MSG_SERVER_TIME: 36,
    MSG_REDIRECT:    37,
    MSG_CAPTCHA:     38,
    MSG_CONFIGS:     39,

    // ---- Binary message type bytes ----
    BIN_APPEND:    0x02,
    BIN_BACKSPACE: 0x03,
    BIN_SPLICE:    0x04,

    // ---- Post ID encoding (float64 LE, matches server BinaryProtocol.php) ----

    /**
     * Encode a post ID as 8-byte float64 little-endian.
     * Safe for IDs up to 2^53 (Number.MAX_SAFE_INTEGER).
     *
     * @param {number} id
     * @returns {Uint8Array} 8 bytes
     */
    encodePostId: function(id) {
        var buf = new ArrayBuffer(8);
        new DataView(buf).setFloat64(0, id, true);
        return new Uint8Array(buf);
    },

    /**
     * Decode post ID from the first 8 bytes of a Uint8Array.
     *
     * @param {Uint8Array} data
     * @returns {number}
     */
    decodePostId: function(data) {
        var view = new DataView(data.buffer, data.byteOffset, 8);
        return view.getFloat64(0, true);
    },

    // ---- Client → Server binary encoders ----

    /**
     * Append a single character.
     * C→S: [char:utf8][0x02]
     *
     * @param {string} ch  Single character
     * @returns {ArrayBuffer}
     */
    encodeAppend: function(ch) {
        var encoded = encoder.encode(ch);
        var frame = new Uint8Array(encoded.length + 1);
        frame.set(encoded);
        frame[frame.length - 1] = 0x02;
        return frame.buffer;
    },

    /**
     * Backspace (delete last character).
     * C→S: [0x03]
     *
     * @returns {ArrayBuffer}
     */
    encodeBackspace: function() {
        return new Uint8Array([0x03]).buffer;
    },

    /**
     * Splice: replace `delCount` characters at `start` with `text`.
     * C→S: [start:u16LE][len:u16LE][text:utf8][0x04]
     *
     * @param {number} start    - Character offset
     * @param {number} delCount - Number of characters to delete
     * @param {string} text     - Replacement text
     * @returns {ArrayBuffer}
     */
    encodeSplice: function(start, delCount, text) {
        var encoded = encoder.encode(text);
        var frame = new Uint8Array(4 + encoded.length + 1);
        var view = new DataView(frame.buffer);
        view.setUint16(0, start, true);
        view.setUint16(2, delCount, true);
        frame.set(encoded, 4);
        frame[frame.length - 1] = 0x04;
        return frame.buffer;
    },

    // ---- Text message encoder ----

    /**
     * Encode a text-protocol message.
     *
     * @param {number} type     - Message type code (1-39)
     * @param {*}      [payload] - JSON-serializable payload
     * @returns {string}
     */
    encodeText: function(type, payload) {
        var typeStr = type < 10 ? '0' + type : '' + type;
        if (payload !== undefined && payload !== null) {
            return typeStr + JSON.stringify(payload);
        }
        return typeStr;
    },

    // ---- Server → Client decoders ----

    /**
     * Decode an incoming binary frame.
     * S→C: [postID:f64LE][payload...][typeByte]
     *
     * @param {ArrayBuffer} data
     * @returns {{type: number, postId: number, payload: Uint8Array}}
     */
    decodeBinary: function(data) {
        var bytes = new Uint8Array(data);
        var type = bytes[bytes.length - 1];
        var postId = this.decodePostId(bytes);
        var payload = bytes.slice(8, bytes.length - 1);
        return { type: type, postId: postId, payload: payload };
    },

    /**
     * Decode an incoming text frame.
     *
     * @param {string} data
     * @returns {{type: number, payload: *}}
     */
    decodeText: function(data) {
        var type = parseInt(data.substring(0, 2), 10);
        var payload = data.length > 2 ? JSON.parse(data.substring(2)) : null;
        return { type: type, payload: payload };
    },

    // ---- Splice payload decoder (for S→C binary splice) ----

    /**
     * Decode the payload portion of a splice message (after postId, before type byte).
     *
     * @param {Uint8Array} payload - bytes between postId and type byte
     * @returns {{start: number, delCount: number, text: string}}
     */
    decodeSplicePayload: function(payload) {
        var view = new DataView(payload.buffer, payload.byteOffset, 4);
        var start = view.getUint16(0, true);
        var delCount = view.getUint16(2, true);
        var text = decoder.decode(payload.slice(4));
        return { start: start, delCount: delCount, text: text };
    }
};

})();
