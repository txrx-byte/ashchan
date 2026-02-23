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
 * livepost/state-machine.js
 * Post authoring finite state machine.
 *
 * States:
 *   ready      → User can start a new post
 *   allocating → InsertPost sent, waiting for PostID response
 *   alloc      → Post allocated, user is typing (mutations sent live)
 *   closing    → ClosePost sent, waiting for server close confirmation
 *   (ready)    → Post closed, cycle complete
 *
 * Depends on: protocol.js, connection.js
 */

(function() {
'use strict';

var LP = window.Livepost = window.Livepost || {};

LP.FSM = {
    /** @type {'ready'|'allocating'|'alloc'|'closing'} */
    state: 'ready',

    /** @type {number|null} Server-assigned post ID */
    postId: null,

    /** @type {string|null} Random hex password for reclaim */
    reclaimPassword: null,

    // ---- State transitions ----

    /**
     * Transition to a new state and notify the form model.
     *
     * @param {string} newState
     */
    transition: function(newState) {
        var old = this.state;
        this.state = newState;
        if (LP.OpenPost) LP.OpenPost.onStateChange(old, newState);
    },

    // ---- User actions ----

    /**
     * User begins a new post. Sends InsertPost (type 01) to the server.
     *
     * @param {string} name    - Author name (or empty for Anonymous)
     * @param {string} options - Email/sage field
     */
    startPost: function(name, options) {
        if (this.state !== 'ready') return;
        this.transition('allocating');

        this.reclaimPassword = this.generatePassword();

        LP.Connection.sendText(LP.Protocol.MSG_INSERT_POST, {
            name: name || '',
            email: options || '',
            password: this.reclaimPassword
        });
    },

    /**
     * Server responds with the allocated post ID (type 32).
     *
     * @param {number} postId
     */
    onPostId: function(postId) {
        if (this.state !== 'allocating') return;
        this.postId = postId;
        this.transition('alloc');

        // Persist for reclaim across reconnects
        try {
            sessionStorage.setItem('lp_pass_' + postId, this.reclaimPassword);
            sessionStorage.setItem('lp_open', String(postId));
        } catch (e) { /* storage unavailable */ }
    },

    /**
     * User clicks "Done" — send ClosePost (type 05).
     */
    closePost: function() {
        if (this.state !== 'alloc') return;
        this.transition('closing');
        LP.Connection.sendText(LP.Protocol.MSG_CLOSE_POST);
    },

    /**
     * Server confirms the post is closed (received via PostView.onClosePost
     * when our own post ID matches).
     *
     * @param {number} postId
     */
    onCloseConfirmed: function(postId) {
        this.postId = null;
        this.reclaimPassword = null;
        this.transition('ready');

        try {
            sessionStorage.removeItem('lp_pass_' + postId);
            sessionStorage.removeItem('lp_open');
        } catch (e) { /* ignore */ }
    },

    // ---- Reclaim after disconnect ----

    /**
     * Attempt to reclaim an open post from a previous session.
     * Called automatically after sync completes.
     *
     * @returns {boolean} true if a reclaim attempt was sent
     */
    tryReclaim: function() {
        var openId;
        try { openId = sessionStorage.getItem('lp_open'); } catch (e) { return false; }
        if (!openId) return false;

        var password;
        try { password = sessionStorage.getItem('lp_pass_' + openId); } catch (e) { return false; }
        if (!password) return false;

        LP.Connection.sendText(LP.Protocol.MSG_RECLAIM, {
            id: parseInt(openId, 10),
            password: password
        });
        this.transition('allocating');
        return true;
    },

    /**
     * Server responds to reclaim attempt.
     *
     * @param {object|null} result - {post_id, thread_id, body} on success, null/error on failure
     */
    onReclaim: function(result) {
        if (result && result.post_id) {
            this.postId = result.post_id;
            this.transition('alloc');
            if (LP.OpenPost) LP.OpenPost.restoreBody(result.body || '');
        } else {
            // Reclaim failed — clear stale data
            this.transition('ready');
            try { sessionStorage.removeItem('lp_open'); } catch (e) { /* ignore */ }
        }
    },

    // ---- Helpers ----

    /**
     * Generate a cryptographically random hex password for reclaim.
     *
     * @returns {string} 32-char hex string
     */
    generatePassword: function() {
        var arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        return Array.from(arr, function(b) {
            return b.toString(16).padStart(2, '0');
        }).join('');
    },

    /**
     * Hard reset — clears all state without sending messages.
     */
    reset: function() {
        this.state = 'ready';
        this.postId = null;
        this.reclaimPassword = null;
    }
};

})();
