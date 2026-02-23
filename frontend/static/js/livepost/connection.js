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
 * livepost/connection.js
 * WebSocket lifecycle, reconnection with exponential backoff, keep-alive.
 *
 * Depends on: protocol.js (window.Livepost.Protocol)
 */

(function() {
'use strict';

var RECONNECT_BASE_MS   = 1000;
var RECONNECT_MAX_MS    = 30000;
var RECONNECT_MULTIPLIER = 1.5;
var KEEPALIVE_INTERVAL   = 25000; // 25s NOOP ping

var LP = window.Livepost = window.Livepost || {};

/**
 * Manages a single WebSocket connection to /api/socket.
 * Handles connect, synchronise, reconnect, and message dispatch.
 */
LP.Connection = {
    ws: null,
    board: null,
    threadId: null,
    reconnectAttempts: 0,
    reconnectTimer: null,
    keepaliveTimer: null,
    connected: false,
    synced: false,

    /**
     * Open or re-open the WebSocket connection.
     *
     * @param {string} board  - Board slug (e.g. "g")
     * @param {string|number} threadId - Thread ID
     */
    init: function(board, threadId) {
        this.board = board;
        this.threadId = threadId;
        this.connect();
    },

    connect: function() {
        if (this.ws) {
            try { this.ws.close(); } catch (e) { /* ignore */ }
        }

        var proto = location.protocol === 'https:' ? 'wss' : 'ws';
        this.ws = new WebSocket(proto + '://' + location.host + '/api/socket');
        this.ws.binaryType = 'arraybuffer';

        var self = this;

        this.ws.onopen = function() {
            self.connected = true;
            self.reconnectAttempts = 0;
            self.startKeepalive();
            self.synchronise();
            if (LP.UI) LP.UI.updateConnectionStatus(true);
        };

        this.ws.onmessage = function(e) {
            self.onMessage(e.data);
        };

        this.ws.onclose = function(e) {
            self.connected = false;
            self.synced = false;
            self.stopKeepalive();
            if (LP.UI) LP.UI.updateConnectionStatus(false);
            // Reconnect unless the user or server closed cleanly
            if (e.code !== 1000) {
                self.scheduleReconnect();
            }
        };

        this.ws.onerror = function() {
            // onclose will fire immediately after — no action needed here
        };
    },

    /**
     * Send the synchronise message (type 30) to subscribe to the thread feed.
     */
    synchronise: function() {
        var P = LP.Protocol;
        this.sendText(P.MSG_SYNCHRONISE, {
            board: this.board,
            thread: parseInt(this.threadId, 10)
        });
    },

    // ---- Message dispatch ----

    onMessage: function(data) {
        var P = LP.Protocol;

        if (data instanceof ArrayBuffer) {
            var decoded = P.decodeBinary(data);
            this.dispatchBinary(decoded);
        } else {
            var msg = P.decodeText(data);
            if (msg.type === P.MSG_CONCAT) {
                var self = this;
                msg.payload.forEach(function(sub) { self.onMessage(sub); });
            } else {
                this.dispatchText(msg);
            }
        }
    },

    dispatchBinary: function(msg) {
        var P = LP.Protocol;
        switch (msg.type) {
            case P.BIN_APPEND:
                if (LP.PostView) LP.PostView.onAppend(msg.postId, msg.payload);
                break;
            case P.BIN_BACKSPACE:
                if (LP.PostView) LP.PostView.onBackspace(msg.postId);
                break;
            case P.BIN_SPLICE:
                if (LP.PostView) LP.PostView.onSplice(msg.postId, msg.payload);
                break;
        }
    },

    dispatchText: function(msg) {
        var P = LP.Protocol;
        switch (msg.type) {
            case P.MSG_INSERT_POST:
                if (LP.PostView) LP.PostView.onInsertPost(msg.payload);
                break;
            case P.MSG_CLOSE_POST:
                if (LP.PostView) LP.PostView.onClosePost(msg.payload);
                break;
            case P.MSG_POST_ID:
                if (LP.FSM) LP.FSM.onPostId(msg.payload);
                break;
            case P.MSG_SYNCHRONISE:
                if (LP.Sync) LP.Sync.onSync(msg.payload);
                break;
            case P.MSG_SYNC_COUNT:
                if (LP.Sync) LP.Sync.onSyncCount(msg.payload);
                break;
            case P.MSG_SERVER_TIME:
                if (LP.Sync) LP.Sync.onServerTime(msg.payload);
                break;
            case P.MSG_RECLAIM:
                if (LP.FSM) LP.FSM.onReclaim(msg.payload);
                break;
        }
    },

    // ---- Reconnection ----

    scheduleReconnect: function() {
        var delay = Math.min(
            RECONNECT_BASE_MS * Math.pow(RECONNECT_MULTIPLIER, this.reconnectAttempts),
            RECONNECT_MAX_MS
        );
        this.reconnectAttempts++;
        var self = this;
        this.reconnectTimer = setTimeout(function() {
            self.connect();
        }, delay);
    },

    // ---- Keep-alive ----

    startKeepalive: function() {
        var self = this;
        var P = LP.Protocol;
        this.keepaliveTimer = setInterval(function() {
            self.sendText(P.MSG_NOOP);
        }, KEEPALIVE_INTERVAL);
    },

    stopKeepalive: function() {
        if (this.keepaliveTimer) {
            clearInterval(this.keepaliveTimer);
            this.keepaliveTimer = null;
        }
    },

    // ---- Send helpers ----

    sendText: function(type, payload) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(LP.Protocol.encodeText(type, payload));
        }
    },

    sendBinary: function(data) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(data);
        }
    },

    // ---- Teardown ----

    close: function() {
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        this.stopKeepalive();
        if (this.ws) {
            this.ws.close(1000);
            this.ws = null;
        }
    }
};

})();
