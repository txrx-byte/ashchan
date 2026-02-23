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
 * livepost/open-post.js
 * FormModel — tracks the textarea value, computes minimal diffs against
 * the last-sent state, and sends binary mutations (append/backspace/splice).
 *
 * Input diffing algorithm matches meguca's FormModel.parseInput():
 *   +1 char at end  → Append  (0x02)
 *   -1 char at end  → Backspace (0x03)
 *   anything else   → Splice  (0x04)
 *
 * Depends on: protocol.js, connection.js, state-machine.js
 */

(function() {
'use strict';

var LP = window.Livepost = window.Livepost || {};

var MAX_CHARS = 2000;
var MAX_LINES = 100;

LP.OpenPost = {
    /** Last body state sent to the server */
    inputBody: '',

    /** Bound textarea element */
    textarea: null,

    /** Running character count */
    charCount: 0,

    /** Running line count */
    lineCount: 0,

    /**
     * Bind to a textarea element and start listening for input events.
     *
     * @param {HTMLTextAreaElement} textarea
     */
    init: function(textarea) {
        this.textarea = textarea;
        this.inputBody = '';
        this.charCount = 0;
        this.lineCount = 0;

        var self = this;
        textarea.addEventListener('input', function() { self.onInput(); });
    },

    /**
     * Called on every `input` event from the textarea.
     * If the FSM is in 'ready' state, the first keystroke allocates a new post.
     * If in 'alloc' state, we compute and send the diff.
     */
    onInput: function() {
        if (LP.FSM.state === 'ready') {
            // First keystroke → allocate the post
            var nameInput = document.querySelector('#livepostForm input[name="name"]');
            var optInput  = document.querySelector('#livepostForm input[name="email"]');
            LP.FSM.startPost(
                nameInput ? nameInput.value : '',
                optInput  ? optInput.value  : ''
            );
            // The diff will be sent after we receive PostID and transition to 'alloc'
            return;
        }

        if (LP.FSM.state !== 'alloc') return;

        var current = this.textarea.value;

        // Enforce max characters
        if (current.length > MAX_CHARS) {
            current = current.substring(0, MAX_CHARS);
            this.textarea.value = current;
        }

        // Enforce max lines
        var nlCount = (current.match(/\n/g) || []).length;
        if (nlCount > MAX_LINES) {
            var lines = current.split('\n');
            current = lines.slice(0, MAX_LINES + 1).join('\n');
            this.textarea.value = current;
        }

        this.diff(current);
    },

    /**
     * Compute the minimal mutation between the last-sent body and `current`,
     * then send the appropriate binary message.
     *
     * @param {string} current - Current textarea value
     */
    diff: function(current) {
        var prev = this.inputBody;
        if (current === prev) return;

        var P = LP.Protocol;

        // Case 1: single character appended at end
        if (current.length === prev.length + 1 && current.substring(0, prev.length) === prev) {
            var ch = current[current.length - 1];
            LP.Connection.sendBinary(P.encodeAppend(ch));
            this.inputBody = current;
            this.updateCounts(current);
            return;
        }

        // Case 2: single character removed from end (backspace)
        if (current.length === prev.length - 1 && prev.substring(0, current.length) === current) {
            LP.Connection.sendBinary(P.encodeBackspace());
            this.inputBody = current;
            this.updateCounts(current);
            return;
        }

        // Case 3: general splice — find divergence point
        var start = 0;
        var minLen = Math.min(prev.length, current.length);
        while (start < minLen && prev[start] === current[start]) {
            start++;
        }

        // Find matching suffix
        var endPrev = prev.length;
        var endCurr = current.length;
        while (endPrev > start && endCurr > start && prev[endPrev - 1] === current[endCurr - 1]) {
            endPrev--;
            endCurr--;
        }

        var delCount = endPrev - start;
        var insertText = current.substring(start, endCurr);

        LP.Connection.sendBinary(P.encodeSplice(start, delCount, insertText));
        this.inputBody = current;
        this.updateCounts(current);
    },

    /**
     * Update character/line counters and the counter display.
     *
     * @param {string} text
     */
    updateCounts: function(text) {
        this.charCount = text.length;
        this.lineCount = (text.match(/\n/g) || []).length;

        var counter = document.getElementById('livepostCharCount');
        if (counter) {
            counter.textContent = this.charCount + '/' + MAX_CHARS;
            if (this.charCount > MAX_CHARS * 0.9) {
                counter.className = 'lp-char-count lp-char-warning';
            } else {
                counter.className = 'lp-char-count';
            }
        }
    },

    /**
     * Restore body from a reclaim response. Sets the textarea value and
     * updates the internal state to match.
     *
     * @param {string} body
     */
    restoreBody: function(body) {
        this.inputBody = body;
        if (this.textarea) {
            this.textarea.value = body;
        }
        this.updateCounts(body);
    },

    /**
     * Called by the FSM on state transitions. Updates the form UI to
     * reflect the current authoring state.
     *
     * @param {string} oldState
     * @param {string} newState
     */
    onStateChange: function(oldState, newState) {
        var status  = document.getElementById('livepostStatus');
        var doneBtn = document.getElementById('livepostDone');

        switch (newState) {
            case 'allocating':
                if (status) status.textContent = 'Allocating post\u2026';
                if (doneBtn) doneBtn.disabled = true;
                break;

            case 'alloc':
                if (status) status.textContent = 'Live';
                if (doneBtn) doneBtn.disabled = false;
                // If the user typed while we were allocating, send the diff now
                if (oldState === 'allocating' && this.textarea && this.textarea.value) {
                    this.diff(this.textarea.value);
                }
                break;

            case 'closing':
                if (status) status.textContent = 'Closing\u2026';
                if (doneBtn) doneBtn.disabled = true;
                break;

            case 'ready':
                if (status) status.textContent = '';
                this.reset();
                if (LP.UI) LP.UI.resetForm();
                break;
        }
    },

    /**
     * Reset internal state (does not send any messages).
     */
    reset: function() {
        this.inputBody = '';
        this.charCount = 0;
        this.lineCount = 0;
        if (this.textarea) {
            this.textarea.value = '';
        }
        var counter = document.getElementById('livepostCharCount');
        if (counter) {
            counter.textContent = '0/' + MAX_CHARS;
            counter.className = 'lp-char-count';
        }
    }
};

})();
