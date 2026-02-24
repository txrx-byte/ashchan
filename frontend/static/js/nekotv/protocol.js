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
 * nekotv/protocol.js
 * NekotV wire protocol: encode/decode binary frames with type byte 0x10.
 *
 * Binary format:
 *   S→C: [JSON payload (UTF-8)][0x10]
 *   C→S: [action byte: 1=subscribe, 0=unsubscribe][0x10]
 *
 * Must be loaded before nekotv/connection.js.
 */

(function() {
'use strict';

var NTV = window.NekotV = window.NekotV || {};

var decoder = new TextDecoder();

/** Wire type byte for NekotV messages (matches server NekotVFeed::MESSAGE_TYPE). */
NTV.MESSAGE_TYPE = 0x10;

/** Video type enum (matches server VideoType.php). */
NTV.VideoType = {
    RAW:         0,
    YOUTUBE:     1,
    TWITCH:      2,
    IFRAME:      3,
    TIKTOK:      4,
    TIKTOK_LIVE: 5
};

/** Event name constants (matches server NekotVEvent.php). */
NTV.Events = {
    CONNECTED:        'connected',
    ADD_VIDEO:        'add_video',
    REMOVE_VIDEO:     'remove_video',
    SKIP_VIDEO:       'skip_video',
    PAUSE:            'pause',
    PLAY:             'play',
    TIME_SYNC:        'time_sync',
    SET_TIME:         'set_time',
    SET_RATE:         'set_rate',
    REWIND:           'rewind',
    PLAY_ITEM:        'play_item',
    SET_NEXT_ITEM:    'set_next_item',
    UPDATE_PLAYLIST:  'update_playlist',
    TOGGLE_LOCK:      'toggle_lock',
    CLEAR_PLAYLIST:   'clear_playlist'
};

NTV.Protocol = {
    /**
     * Encode a subscribe message (C→S).
     * @returns {ArrayBuffer}
     */
    encodeSubscribe: function() {
        return new Uint8Array([1, NTV.MESSAGE_TYPE]).buffer;
    },

    /**
     * Encode an unsubscribe message (C→S).
     * @returns {ArrayBuffer}
     */
    encodeUnsubscribe: function() {
        return new Uint8Array([0, NTV.MESSAGE_TYPE]).buffer;
    },

    /**
     * Decode a binary NekotV frame from the server.
     * Last byte is the type byte (0x10), preceding bytes are UTF-8 JSON.
     *
     * @param {Uint8Array} bytes - Full binary frame
     * @returns {object|null} Parsed JSON event, or null if invalid.
     */
    decode: function(bytes) {
        if (bytes.length < 2) return null;
        if (bytes[bytes.length - 1] !== NTV.MESSAGE_TYPE) return null;
        var json = decoder.decode(bytes.slice(0, bytes.length - 1));
        try {
            return JSON.parse(json);
        } catch (e) {
            console.error('[NekotV] Failed to parse event:', e);
            return null;
        }
    }
};

})();
