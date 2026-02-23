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
 * livepost/post-view.js
 * DOM rendering for live post updates — insertions, closes, and character
 * mutations (append, backspace, splice) from other users.
 *
 * Post DOM structure follows the existing 4chan-compatible markup:
 *   <div class="postContainer replyContainer" id="pc{id}">
 *     <div id="p{id}" class="post reply editing">
 *       <div class="postInfo desktop" id="pi{id}">...</div>
 *       <blockquote class="postMessage" id="m{id}">...</blockquote>
 *     </div>
 *   </div>
 *
 * Open posts get the `.editing` class and a pulsing live indicator.
 *
 * Depends on: protocol.js, state-machine.js
 */

(function() {
'use strict';

var LP = window.Livepost = window.Livepost || {};

var decoder = new TextDecoder();

LP.PostView = {

    /**
     * Map of currently open (being-edited) posts.
     * @type {Object.<number, {body: string, el: HTMLElement}>}
     */
    openPosts: {},

    // ---- Server events ----

    /**
     * A new post was inserted (InsertPost, type 01).
     * Creates DOM elements and starts tracking the open post body.
     *
     * @param {object} post - Post data from server
     */
    onInsertPost: function(post) {
        if (!post || !post.id) return;

        // Don't duplicate
        if (document.getElementById('p' + post.id)) return;

        var thread = document.querySelector('.thread');
        if (!thread) return;

        // Build and insert DOM
        var html = this.renderReply(post);
        var temp = document.createElement('div');
        temp.innerHTML = html;
        var container = temp.firstElementChild;
        if (!container) return;
        thread.appendChild(container);

        // Track as open post
        var msgEl = document.getElementById('m' + post.id);
        this.openPosts[post.id] = {
            body: post.body || '',
            el: msgEl
        };

        // If there's already content, render it
        if (post.body && msgEl) {
            this.renderBody(msgEl, post.body);
        }

        // Scroll the new post smoothly into view
        container.scrollIntoView({ behavior: 'smooth', block: 'end' });

        // Update reply count in thread stats
        this.updateReplyCount(1);
    },

    /**
     * A post was closed (ClosePost, type 05).
     * Removes editing state and replaces body with final rendered HTML.
     *
     * @param {object} data - {id, content_html, ...}
     */
    onClosePost: function(data) {
        if (!data || !data.id) return;

        var postEl = document.getElementById('p' + data.id);
        if (postEl) {
            postEl.classList.remove('editing');
            // Remove the live indicator
            var indicator = postEl.querySelector('.lp-indicator');
            if (indicator) indicator.remove();
        }

        // Replace body with final server-rendered HTML
        var msgEl = document.getElementById('m' + data.id);
        if (msgEl && data.content_html) {
            msgEl.innerHTML = data.content_html;
        }

        // Stop tracking
        delete this.openPosts[data.id];

        // If this was OUR post, tell the FSM
        if (LP.FSM && LP.FSM.postId === data.id) {
            LP.FSM.onCloseConfirmed(data.id);
        }
    },

    /**
     * A character was appended to an open post (binary type 0x02).
     *
     * @param {number}     postId  - Post ID
     * @param {Uint8Array} payload - UTF-8 encoded character (without type byte)
     */
    onAppend: function(postId, payload) {
        var entry = this.openPosts[postId];
        if (!entry) return;

        // Skip if this is our own post — we already have the text locally
        if (LP.FSM && LP.FSM.postId === postId) return;

        var ch = decoder.decode(payload);
        entry.body += ch;

        // Fast path: append directly to the DOM
        if (entry.el) {
            if (ch === '\n') {
                entry.el.appendChild(document.createElement('br'));
            } else {
                // Append to last text node or create new one
                var last = entry.el.lastChild;
                if (last && last.nodeType === Node.TEXT_NODE &&
                    !last.parentElement.classList.contains('quote')) {
                    last.textContent += ch;
                } else {
                    entry.el.appendChild(document.createTextNode(ch));
                }
            }
        }
    },

    /**
     * A backspace was applied to an open post (binary type 0x03).
     *
     * @param {number} postId
     */
    onBackspace: function(postId) {
        var entry = this.openPosts[postId];
        if (!entry) return;
        if (LP.FSM && LP.FSM.postId === postId) return;

        entry.body = entry.body.slice(0, -1);

        // Remove from DOM
        if (entry.el && entry.el.lastChild) {
            var last = entry.el.lastChild;
            if (last.nodeType === Node.TEXT_NODE) {
                if (last.textContent.length > 1) {
                    last.textContent = last.textContent.slice(0, -1);
                } else {
                    entry.el.removeChild(last);
                }
            } else {
                // Could be a <br> or <span class="quote">
                entry.el.removeChild(last);
            }
        }
    },

    /**
     * A splice was applied to an open post (binary type 0x04).
     *
     * @param {number}     postId
     * @param {Uint8Array} payload - [start:u16LE][len:u16LE][text:utf8]
     */
    onSplice: function(postId, payload) {
        var entry = this.openPosts[postId];
        if (!entry) return;
        if (LP.FSM && LP.FSM.postId === postId) return;

        var splice = LP.Protocol.decodeSplicePayload(payload);
        entry.body = entry.body.substring(0, splice.start) +
                     splice.text +
                     entry.body.substring(splice.start + splice.delCount);

        // Slow path: re-render the entire body
        if (entry.el) {
            this.renderBody(entry.el, entry.body);
        }
    },

    // ---- Rendering helpers ----

    /**
     * Render raw body text into a blockquote element.
     * For open posts we show raw text with basic greentext highlighting.
     *
     * @param {HTMLElement} el   - The <blockquote> element
     * @param {string}      body - Raw post body text
     */
    renderBody: function(el, body) {
        // Clear existing content
        el.textContent = '';

        var lines = body.split('\n');
        for (var i = 0; i < lines.length; i++) {
            if (i > 0) el.appendChild(document.createElement('br'));

            var line = lines[i];
            if (line.length === 0) continue;

            // Greentext: lines starting with > (but not >> which is a quote link)
            if (line.charAt(0) === '>' && line.charAt(1) !== '>') {
                var span = document.createElement('span');
                span.className = 'quote';
                span.textContent = line;
                el.appendChild(span);
            } else {
                el.appendChild(document.createTextNode(line));
            }
        }
    },

    /**
     * Render a new reply post container as an HTML string.
     * Matches the existing thread.html markup structure.
     *
     * @param {object} post - {id, name, board_post_no, time, body}
     * @returns {string} HTML
     */
    renderReply: function(post) {
        var id = post.id;
        var name = this.escapeHtml(post.name || 'Anonymous');
        var time = post.time || new Date().toLocaleString();
        var boardPostNo = post.board_post_no || id;

        return '' +
            '<div class="postContainer replyContainer" id="pc' + id + '">' +
                '<div class="sideArrows" id="sa' + id + '">&gt;&gt;</div>' +
                '<div id="p' + id + '" class="post reply editing">' +
                    '<div class="postInfo desktop" id="pi' + id + '">' +
                        '<input type="checkbox" name="' + id + '" value="delete"> ' +
                        '<span class="nameBlock">' +
                            '<span class="name">' + name + '</span>' +
                        '</span> ' +
                        '<span class="dateTime" data-utc="">' + time + '</span> ' +
                        '<span class="postNum desktop">' +
                            '<a href="#p' + id + '" title="Link to this post">No.</a>' +
                            '<a href="#q' + id + '" title="Reply to this post">' + boardPostNo + '</a>' +
                        '</span> ' +
                        '<span class="lp-indicator" title="Post is being typed live">\u25CF</span>' +
                    '</div>' +
                    '<blockquote class="postMessage" id="m' + id + '"></blockquote>' +
                '</div>' +
            '</div>';
    },

    /**
     * Escape HTML entities to prevent XSS.
     *
     * @param {string} text
     * @returns {string}
     */
    escapeHtml: function(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    },

    /**
     * Increment or decrement the reply count display.
     *
     * @param {number} delta
     */
    updateReplyCount: function(delta) {
        var el = document.querySelector('.ts-replies');
        if (!el) return;
        var match = el.textContent.match(/(\d+)/);
        if (match) {
            var count = parseInt(match[1], 10) + delta;
            el.textContent = count + ' replies';
        }
    }
};

})();
