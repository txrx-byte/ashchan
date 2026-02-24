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
 * nekotv/videolist.js
 * Client-side ordered playlist with position tracking.
 * Port of meguca's client/nekotv/videolist.ts.
 *
 * Depends on: nekotv/protocol.js
 */

(function() {
'use strict';

var NTV = window.NekotV = window.NekotV || {};

/**
 * @constructor
 */
function VideoList() {
    this.items = [];
    this.pos = 0;
    this._isOpen = true;
}

Object.defineProperty(VideoList.prototype, 'length', {
    get: function() { return this.items.length; }
});

Object.defineProperty(VideoList.prototype, 'currentItem', {
    get: function() { return this.items[this.pos] || null; }
});

Object.defineProperty(VideoList.prototype, 'isOpen', {
    get: function() { return this._isOpen; },
    set: function(v) { this._isOpen = v; }
});

VideoList.prototype.getItem = function(i) {
    return this.items[i] || null;
};

VideoList.prototype.getItems = function() {
    return this.items;
};

VideoList.prototype.setPos = function(i) {
    if (i < 0 || i > this.items.length - 1) i = 0;
    this.pos = i;
};

VideoList.prototype.exists = function(fn) {
    return this.items.some(fn);
};

VideoList.prototype.findIndex = function(fn) {
    for (var i = 0; i < this.items.length; i++) {
        if (fn(this.items[i])) return i;
    }
    return -1;
};

/**
 * Add a video item to the playlist.
 * @param {object} item  - VideoItem {url, title, author, duration, id, type}
 * @param {boolean} atEnd - If true, append; otherwise insert after current.
 */
VideoList.prototype.addItem = function(item, atEnd) {
    if (atEnd) {
        this.items.push(item);
    } else {
        this.items.splice(this.pos + 1, 0, item);
    }
};

/**
 * Move item at nextPos to immediately after current position.
 */
VideoList.prototype.setNextItem = function(nextPos) {
    var next = this.items[nextPos];
    this.items.splice(nextPos, 1);
    if (nextPos < this.pos) this.pos--;
    this.items.splice(this.pos + 1, 0, next);
};

VideoList.prototype.removeItem = function(index) {
    if (index < this.pos) this.pos--;
    this.items.splice(index, 1);
    if (this.pos >= this.items.length) this.pos = 0;
};

/**
 * Remove the current item and advance.
 * @returns {boolean} True if playlist is now empty.
 */
VideoList.prototype.skipItem = function() {
    this.items.splice(this.pos, 1);
    if (this.pos >= this.items.length) {
        this.pos = 0;
        return true;
    }
    return false;
};

VideoList.prototype.clear = function() {
    this.items = [];
    this.pos = 0;
};

NTV.VideoList = VideoList;

})();
