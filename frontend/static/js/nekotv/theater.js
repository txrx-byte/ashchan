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
 * nekotv/theater.js
 * Theater mode: full-viewport split view with video on left, thread on right.
 * Port of meguca's client/nekotv/theaterMode.ts.
 *
 * Depends on: nekotv/protocol.js, nekotv/player.js
 */

(function() {
'use strict';

var NTV = window.NekotV = window.NekotV || {};

var isTheaterMode = false;
var rightDiv = null;

function getTheaterMode() {
    return isTheaterMode;
}

function setTheaterMode(value) {
    if (isTheaterMode === value) return;
    isTheaterMode = value;
    value ? activateTheaterMode() : deactivateTheaterMode();
}

function isAtBottom() {
    var el = rightDiv || document.documentElement;
    return Math.abs(el.scrollHeight - el.scrollTop - el.clientHeight) < 2;
}

function scrollToBottom() {
    var el = rightDiv || document.documentElement;
    el.scrollTop = el.scrollHeight;
}

function activateTheaterMode() {
    var articles = document.getElementsByTagName('article');
    var atBottom = isAtBottom();

    // Find last fully visible article for scroll restoration
    var articleShown = null;
    for (var i = articles.length - 1; i >= 0; i--) {
        var rect = articles[i].getBoundingClientRect();
        if (rect.top >= 0 && rect.left >= 0 &&
            rect.bottom <= window.innerHeight &&
            rect.right <= window.innerWidth) {
            articleShown = articles[i];
            break;
        }
    }

    // Move all body children into a right-content div
    rightDiv = document.createElement('div');
    rightDiv.id = 'right-content';
    var children = document.body.children;
    while (children.length > 0) {
        rightDiv.appendChild(children[0]);
    }
    document.body.appendChild(rightDiv);

    // Move watch panel to the front
    var watchPanel = document.getElementById('watch-panel');
    if (watchPanel) {
        document.body.insertBefore(watchPanel, document.body.firstChild);
    }

    document.body.classList.add('nekotv-theater');

    // Restore scroll position
    if (atBottom) {
        rightDiv.scrollTo(0, rightDiv.scrollHeight);
    } else if (articleShown) {
        articleShown.scrollIntoView({ behavior: 'instant', block: 'end' });
    }
    rightDiv.scrollLeft = 0;

    // Reload player to adapt to new container size
    if (NTV.Main && NTV.Main.player) NTV.Main.player.reload();
}

function deactivateTheaterMode() {
    var watchPanel = document.getElementById('watch-panel');
    var articles = document.getElementsByTagName('article');
    var atBottom = isAtBottom();

    var articleShown = null;
    for (var i = articles.length - 1; i >= 0; i--) {
        var rect = articles[i].getBoundingClientRect();
        if (rect.top >= 0 && rect.left >= 0 &&
            rect.bottom <= window.innerHeight &&
            rect.right <= window.innerWidth) {
            articleShown = articles[i];
            break;
        }
    }

    // Move watch panel back to its original anchor
    var anchor = document.getElementById('nekotv-anchor');
    if (anchor && watchPanel) {
        anchor.after(watchPanel);
    }

    // Move all children back to body
    if (rightDiv) {
        while (rightDiv.firstChild) {
            document.body.appendChild(rightDiv.firstChild);
        }
        rightDiv.remove();
        rightDiv = null;
    }

    document.body.classList.remove('nekotv-theater');

    if (articleShown) {
        articleShown.scrollIntoView({ behavior: 'instant', block: 'end' });
    }
    if (atBottom) {
        scrollToBottom();
    }

    if (NTV.Main && NTV.Main.player) NTV.Main.player.reload();
}

NTV.Theater = {
    getTheaterMode: getTheaterMode,
    setTheaterMode: setTheaterMode
};

})();
