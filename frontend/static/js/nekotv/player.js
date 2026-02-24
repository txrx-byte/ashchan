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
 * nekotv/player.js
 * Main Player controller — delegates to the correct player implementation
 * based on video type and manages the playlist.
 * Port of meguca's client/nekotv/player.ts.
 *
 * Depends on: nekotv/protocol.js, nekotv/videolist.js, nekotv/players.js
 */

(function() {
'use strict';

var NTV = window.NekotV = window.NekotV || {};

/**
 * @constructor
 */
function Player() {
    this.player = null;
    this.videoList = new NTV.VideoList();
    this.players = {};
    this.players[NTV.VideoType.YOUTUBE] = new NTV.Players.YoutubePlayer();
    this.players[NTV.VideoType.RAW]     = new NTV.Players.RawPlayer();
    this.players[NTV.VideoType.IFRAME]  = new NTV.Players.IFramePlayer();
    this.players[NTV.VideoType.TWITCH]  = new NTV.Players.TwitchPlayer();
    this.players[NTV.VideoType.TIKTOK]  = new NTV.Players.TikTokPlayer();
}

/**
 * Switch to playing a specific playlist position.
 */
Player.prototype.setVideo = function(i) {
    var item = this.videoList.getItem(i);
    if (!item) return;
    this.videoList.setPos(i);
    var matchedPlayer = this.players[item.type];
    if (matchedPlayer !== this.player) {
        if (this.player) this.player.removeVideo();
        this.player = matchedPlayer;
    }
    this.player.loadVideo(item);
};

Player.prototype.setNextItem = function(pos) {
    this.videoList.setNextItem(pos);
};

Player.prototype.addVideoItem = function(item, atEnd) {
    this.videoList.addItem(item, atEnd);
};

Player.prototype.removeItem = function(url) {
    var index = this.videoList.findIndex(function(item) { return item.url === url; });
    if (index === -1) return;
    var isCurrent = this.videoList.currentItem && this.videoList.currentItem.url === url;
    this.videoList.removeItem(index);
    if (isCurrent && this.videoList.length > 0) {
        this.setVideo(this.videoList.pos);
    }
};

Player.prototype.skipItem = function(url) {
    var pos = this.videoList.findIndex(function(item) { return item.url === url; });
    if (pos === -1) return;
    this.videoList.setPos(pos);
    this.videoList.skipItem();
    if (this.videoList.length === 0) return;
    this.setVideo(this.videoList.pos);
};

Player.prototype.getItems = function() {
    return this.videoList.getItems();
};

/**
 * Replace the entire playlist, optionally setting position.
 * Only reloads video if the current URL changed.
 */
Player.prototype.setItems = function(list, pos) {
    var currentUrl = this.videoList.pos < this.videoList.length
        ? (this.videoList.currentItem ? this.videoList.currentItem.url : '')
        : '';
    this.clearItems();
    if (!list || list.length === 0) return;
    for (var i = 0; i < list.length; i++) {
        this.addVideoItem(list[i], true);
    }
    if (pos !== undefined) {
        this.videoList.setPos(pos);
    }
    if (currentUrl !== (this.videoList.currentItem ? this.videoList.currentItem.url : '') || !this.player) {
        this.setVideo(this.videoList.pos);
    }
};

Player.prototype.clearItems = function() {
    this.videoList.clear();
};

Player.prototype.isListEmpty = function() {
    return this.videoList.length === 0;
};

Player.prototype.itemsLength = function() {
    return this.videoList.length;
};

Player.prototype.getItemPos = function() {
    return this.videoList.pos;
};

Player.prototype.hasVideo = function() {
    return this.player !== null;
};

Player.prototype.getDuration = function() {
    if (this.videoList.pos >= this.videoList.length) return 0;
    var item = this.videoList.currentItem;
    return item ? (item.duration || 0) : 0;
};

Player.prototype.isVideoLoaded = function() {
    return this.player ? this.player.isVideoLoaded() : false;
};

Player.prototype.play = function() {
    if (!this.player || !this.player.isVideoLoaded()) return;
    this.player.play();
};

Player.prototype.pause = function() {
    if (!this.player || !this.player.isVideoLoaded()) return;
    this.player.pause();
};

Player.prototype.getTime = function() {
    if (!this.player || !this.player.isVideoLoaded()) return 0;
    return this.player.getTime();
};

Player.prototype.setTime = function(time) {
    if (!this.player || !this.player.isVideoLoaded()) return;
    this.player.setTime(time);
};

Player.prototype.getPlaybackRate = function() {
    if (!this.player || !this.player.isVideoLoaded()) return 1;
    return this.player.getPlaybackRate();
};

Player.prototype.setPlaybackRate = function(rate) {
    if (!this.player || !this.player.isVideoLoaded()) return;
    this.player.setPlaybackRate(rate);
};

Player.prototype.setMuted = function(m) {
    if (this.player) this.player.setMuted(m);
};

Player.prototype.stop = function() {
    if (this.player) {
        this.player.removeVideo();
        this.player = null;
    }
    this.clearItems();
};

Player.prototype.reload = function() {
    if (this.player) this.player.removeVideo();
    if (this.videoList.length > 0) {
        this.player.loadVideo(this.videoList.currentItem);
    }
};

NTV.Player = Player;

})();
