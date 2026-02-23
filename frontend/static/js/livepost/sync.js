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
 * livepost/sync.js
 * Thread synchronisation on connect — reconciles server-side post state
 * with the client DOM.
 *
 * When the server sends a sync response (type 30), this module:
 *   1. Inserts any posts present on server but missing from the DOM.
 *   2. Marks open posts with the `.editing` class and tracks their body state.
 *   3. Updates closed-post bodies if they differ from what the DOM shows.
 *   4. Attempts to reclaim any open post from a previous session.
 *
 * Depends on: protocol.js, state-machine.js, post-view.js
 */

(function() {
'use strict';

var LP = window.Livepost = window.Livepost || {};

LP.Sync = {

    /** Clock drift between client and server (ms) */
    serverTimeDelta: 0,

    /**
     * Handle the sync response from the server (type 30 S→C).
     *
     * @param {object} data - {recent: [{id, body, is_editing, ...}], ...}
     */
    onSync: function(data) {
        if (!data) return;
        LP.Connection.synced = true;

        // Reconcile each post in the sync payload against the DOM
        if (data.recent && Array.isArray(data.recent)) {
            for (var i = 0; i < data.recent.length; i++) {
                var post = data.recent[i];
                this.reconcilePost(post);
            }
        }

        // Try to reclaim an open post from a previous session
        if (LP.FSM) LP.FSM.tryReclaim();

        // Notify the main module
        if (LP.Main) LP.Main.onSynced();
    },

    /**
     * Reconcile a single post from the sync payload with the DOM.
     *
     * @param {object} post
     */
    reconcilePost: function(post) {
        if (!post || !post.id) return;

        var existing = document.getElementById('p' + post.id);

        if (!existing) {
            // Post exists on server but not in DOM → insert it
            if (LP.PostView) LP.PostView.onInsertPost(post);
            return;
        }

        if (post.is_editing) {
            // Post is still open on the server → mark as editing and track
            existing.classList.add('editing');

            // Add live indicator if not present
            var piEl = document.getElementById('pi' + post.id);
            if (piEl && !piEl.querySelector('.lp-indicator')) {
                var indicator = document.createElement('span');
                indicator.className = 'lp-indicator';
                indicator.title = 'Post is being typed live';
                indicator.textContent = '\u25CF';
                piEl.appendChild(indicator);
            }

            var msgEl = document.getElementById('m' + post.id);
            LP.PostView.openPosts[post.id] = {
                body: post.body || '',
                el: msgEl
            };

            // Update the body if server has content
            if (msgEl && post.body) {
                LP.PostView.renderBody(msgEl, post.body);
            }
        } else {
            // Post is closed — ensure it's not marked as editing
            existing.classList.remove('editing');
            var closedIndicator = existing.querySelector('.lp-indicator');
            if (closedIndicator) closedIndicator.remove();
        }
    },

    /**
     * Handle sync count update (type 35).
     * Shows the number of active viewers.
     *
     * @param {object} data - {active: number, total: number}
     */
    onSyncCount: function(data) {
        if (!data) return;
        var el = document.getElementById('livepostViewers');
        if (el) {
            el.textContent = data.active + ' watching';
        }
    },

    /**
     * Handle server time message (type 36).
     * Calculates clock drift for latency-aware rendering.
     *
     * @param {number} timestamp - Unix timestamp (seconds)
     */
    onServerTime: function(timestamp) {
        this.serverTimeDelta = Date.now() - (timestamp * 1000);
    }
};

})();
