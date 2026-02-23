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
 * livepost/index.js
 * Main entry point — creates the live reply form UI, initialises modules,
 * and connects to the WebSocket feed on thread pages.
 *
 * Load order (all via <script defer>):
 *   1. protocol.js    — no dependencies
 *   2. connection.js  — depends on protocol
 *   3. state-machine.js — depends on protocol, connection
 *   4. open-post.js   — depends on protocol, connection, state-machine
 *   5. post-view.js   — depends on protocol, state-machine
 *   6. sync.js        — depends on protocol, state-machine, post-view
 *   7. index.js       — depends on all of the above (this file)
 */

(function() {
'use strict';

var LP = window.Livepost = window.Livepost || {};

// ---- UI: Live Reply Form ----

LP.UI = {
    formEl: null,

    /**
     * Create the livepost reply form and insert it after the existing post form.
     * The form provides a textarea for live typed content, a character counter,
     * a "Done" button, and status indicators.
     */
    createForm: function() {
        var existing = document.getElementById('postForm');
        if (!existing) return;

        var form = document.createElement('div');
        form.id = 'livepostForm';
        form.className = 'lp-form';

        form.innerHTML =
            '<div class="lp-header">' +
                '<span class="lp-title">Live Reply</span>' +
                '<span id="livepostStatus" class="lp-status"></span>' +
                '<span id="livepostViewers" class="lp-viewers"></span>' +
                '<span id="livepostConnection" class="lp-conn"></span>' +
            '</div>' +
            '<table class="postForm replyMode">' +
                '<tbody>' +
                    '<tr data-type="Name">' +
                        '<td class="label">Name</td>' +
                        '<td>' +
                            '<input name="name" type="text" placeholder="Anonymous" ' +
                                'autocomplete="off" maxlength="75">' +
                        '</td>' +
                    '</tr>' +
                    '<tr data-type="Options">' +
                        '<td class="label">Options</td>' +
                        '<td>' +
                            '<input name="email" type="text" placeholder="sage" ' +
                                'autocomplete="off" maxlength="75">' +
                        '</td>' +
                    '</tr>' +
                    '<tr data-type="Comment">' +
                        '<td class="label">Comment</td>' +
                        '<td>' +
                            '<textarea name="com" id="livepostTextarea" cols="48" rows="4" ' +
                                'maxlength="2000" wrap="soft" ' +
                                'placeholder="Start typing to begin a live post\u2026"></textarea>' +
                            '<div class="lp-controls">' +
                                '<span id="livepostCharCount" class="lp-char-count">0/2000</span>' +
                                '<button type="button" id="livepostDone" ' +
                                    'class="lp-done" disabled>Done</button>' +
                            '</div>' +
                        '</td>' +
                    '</tr>' +
                '</tbody>' +
            '</table>';

        // Insert right after the existing form
        existing.parentNode.insertBefore(form, existing.nextSibling);
        this.formEl = form;
    },

    /**
     * Bind event handlers for the livepost form controls.
     */
    bindEvents: function() {
        var textarea = document.getElementById('livepostTextarea');
        var doneBtn  = document.getElementById('livepostDone');

        if (textarea && LP.OpenPost) {
            LP.OpenPost.init(textarea);
        }

        if (doneBtn) {
            doneBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (LP.FSM) LP.FSM.closePost();
            });
        }

        // Ctrl+Enter / Cmd+Enter to close post
        if (textarea) {
            textarea.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    if (LP.FSM && LP.FSM.state === 'alloc') {
                        LP.FSM.closePost();
                    }
                }
            });
        }
    },

    /**
     * Reset the form to its initial empty state after a post is closed.
     */
    resetForm: function() {
        var textarea = document.getElementById('livepostTextarea');
        if (textarea) {
            textarea.value = '';
            textarea.focus();
        }

        var doneBtn = document.getElementById('livepostDone');
        if (doneBtn) doneBtn.disabled = true;

        var status = document.getElementById('livepostStatus');
        if (status) status.textContent = '';
    },

    /**
     * Update the connection status indicator.
     *
     * @param {boolean} connected
     */
    updateConnectionStatus: function(connected) {
        var el = document.getElementById('livepostConnection');
        if (!el) return;
        if (connected) {
            el.textContent = '\u25CF Connected';
            el.className = 'lp-conn lp-connected';
        } else {
            el.textContent = '\u25CB Reconnecting\u2026';
            el.className = 'lp-conn lp-disconnected';
        }
    }
};

// ---- Main Module ----

LP.Main = {
    enabled: false,

    /**
     * Initialise liveposting on the current page.
     * Only activates on thread pages (body must have data-board-slug and data-thread-id).
     */
    init: function() {
        var body = document.body;
        var board    = body.getAttribute('data-board-slug');
        var threadId = body.getAttribute('data-thread-id');

        // Only enable on thread pages
        if (!board || !threadId) return;

        this.enabled = true;

        // Detect dark theme for CSS overrides
        this.detectTheme();

        // Build the live reply form
        LP.UI.createForm();
        LP.UI.bindEvents();

        // Connect to the WebSocket feed
        LP.Connection.init(board, threadId);
    },

    /**
     * Detect the active theme and add a CSS class for dark-theme overrides.
     * Also watches for theme changes via the style switcher.
     */
    detectTheme: function() {
        var link = document.getElementById('themeStylesheet');
        if (!link) return;

        var apply = function() {
            if (link.href && link.href.indexOf('tomorrow') !== -1) {
                document.body.classList.add('lp-dark');
            } else {
                document.body.classList.remove('lp-dark');
            }
        };

        apply();

        // Re-check when stylesheet changes (style switcher)
        var observer = new MutationObserver(apply);
        observer.observe(link, { attributes: true, attributeFilter: ['href'] });
    },

    /**
     * Called when the sync message has been processed.
     */
    onSynced: function() {
        var status = document.getElementById('livepostStatus');
        if (status && LP.FSM && LP.FSM.state === 'ready') {
            status.textContent = 'Ready';
            setTimeout(function() {
                if (status.textContent === 'Ready') {
                    status.textContent = '';
                }
            }, 2000);
        }
    },

    /**
     * Tear down the livepost system (page unload).
     */
    destroy: function() {
        LP.Connection.close();
        if (LP.FSM) LP.FSM.reset();
        this.enabled = false;
    }
};

// ---- Auto-initialise ----

function boot() {
    LP.Main.init();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

// Clean up on page unload
window.addEventListener('beforeunload', function() {
    if (LP.Main.enabled) {
        LP.Main.destroy();
    }
});

})();
