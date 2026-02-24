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
 * nekotv/players.js
 * Video player implementations: YouTube, Twitch, Raw (MP4/WebM), IFrame, TikTok.
 * Port of meguca's client/nekotv/players/*.ts.
 *
 * Each player implements the IPlayer interface:
 *   loadVideo(item), removeVideo(), isVideoLoaded(),
 *   play(), pause(), getTime(), setTime(t),
 *   getPlaybackRate(), setPlaybackRate(r), setMuted(m)
 *
 * Depends on: nekotv/protocol.js
 */

(function() {
'use strict';

var NTV = window.NekotV = window.NekotV || {};

/** @enum {number} */
var PlayerState = {
    UNINITIALIZED: 0,
    SCRIPT_LOADING: 1,
    SCRIPT_LOADED: 2,
    PLAYER_ADDED: 3
};

// ─────────────────────────────────────────────────────────────
// YouTube Player
// ─────────────────────────────────────────────────────────────

function YoutubePlayer() {
    this.state = PlayerState.UNINITIALIZED;
    this.ytPlayer = null;
    this.playerEl = null;
    this.videoToLoad = null;
}

YoutubePlayer.prototype._extractVideoId = function(url) {
    var patterns = [
        /youtube\.com.*v=([A-Za-z0-9_-]+)/,
        /youtu\.be\/([A-Za-z0-9_-]+)/,
        /youtube\.com\/shorts\/([A-Za-z0-9_-]+)/,
        /youtube\.com\/embed\/([A-Za-z0-9_-]+)/
    ];
    for (var i = 0; i < patterns.length; i++) {
        var m = url.match(patterns[i]);
        if (m) return m[1];
    }
    return '';
};

YoutubePlayer.prototype.loadVideo = function(item) {
    var self = this;
    switch (this.state) {
        case PlayerState.UNINITIALIZED:
            this._initMediaPlayer();
            this.videoToLoad = item;
            break;
        case PlayerState.SCRIPT_LOADING:
            this.videoToLoad = item;
            break;
        case PlayerState.PLAYER_ADDED:
            this.ytPlayer.loadVideoById(this._extractVideoId(item.url));
            break;
        default: // SCRIPT_LOADED
            this._addPlayer(item);
    }
};

YoutubePlayer.prototype._initMediaPlayer = function() {
    if (this.state !== PlayerState.UNINITIALIZED) return;
    var script = document.createElement('script');
    script.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(script);
    this.state = PlayerState.SCRIPT_LOADING;
    var self = this;
    window.onYouTubeIframeAPIReady = function() {
        self.state = PlayerState.SCRIPT_LOADED;
        if (self.videoToLoad) {
            self.loadVideo(self.videoToLoad);
            self.videoToLoad = null;
        }
    };
};

YoutubePlayer.prototype._addPlayer = function(item) {
    var container = document.getElementById('watch-video');
    this.playerEl = document.createElement('div');
    this.playerEl.id = 'youtube-player';
    container.appendChild(this.playerEl);

    var self = this;
    var isMuted = NTV.Main ? NTV.Main.isMuted() : false;

    this.ytPlayer = new YT.Player('youtube-player', {
        videoId: this._extractVideoId(item.url),
        playerVars: {
            autoplay: 1,
            playsinline: 1,
            modestbranding: 1,
            rel: 0,
            showinfo: 0
        },
        events: {
            onReady: function() {
                self.state = PlayerState.PLAYER_ADDED;
                if (isMuted) {
                    self.ytPlayer.mute();
                } else {
                    self.ytPlayer.unMute();
                }
                self.ytPlayer.setVolume(100);
                self.ytPlayer.playVideo();
                // Retry autoplay if browser blocked it
                setTimeout(function() {
                    if (self.ytPlayer && self.ytPlayer.getPlayerState &&
                        self.ytPlayer.getPlayerState() === -1) {
                        self.ytPlayer.playVideo();
                    }
                }, 2000);
            }
        }
    });
};

YoutubePlayer.prototype.removeVideo = function() {
    if (this.state === PlayerState.PLAYER_ADDED && this.ytPlayer) {
        this.ytPlayer.destroy();
        this.ytPlayer = null;
        if (this.playerEl) {
            this.playerEl.remove();
            this.playerEl = null;
        }
        this.state = PlayerState.SCRIPT_LOADED;
    } else {
        this.videoToLoad = null;
    }
};

YoutubePlayer.prototype.isVideoLoaded = function() {
    return this.state === PlayerState.PLAYER_ADDED;
};

YoutubePlayer.prototype.play = function() {
    if (this.ytPlayer) this.ytPlayer.playVideo();
};

YoutubePlayer.prototype.pause = function() {
    if (this.ytPlayer) this.ytPlayer.pauseVideo();
};

YoutubePlayer.prototype.getTime = function() {
    return this.ytPlayer ? this.ytPlayer.getCurrentTime() : 0;
};

YoutubePlayer.prototype.setTime = function(t) {
    if (this.ytPlayer) this.ytPlayer.seekTo(t, true);
};

YoutubePlayer.prototype.getPlaybackRate = function() {
    return this.ytPlayer ? this.ytPlayer.getPlaybackRate() : 1;
};

YoutubePlayer.prototype.setPlaybackRate = function(r) {
    if (this.ytPlayer) this.ytPlayer.setPlaybackRate(r);
};

YoutubePlayer.prototype.setMuted = function(m) {
    if (this.ytPlayer) {
        m ? this.ytPlayer.mute() : this.ytPlayer.unMute();
    }
};


// ─────────────────────────────────────────────────────────────
// Raw Player (HTML5 <video> for MP4/WebM)
// ─────────────────────────────────────────────────────────────

function RawPlayer() {
    this.videoElement = null;
    this.loaded = false;
}

RawPlayer.prototype._ensureElement = function() {
    if (this.videoElement) return;
    var container = document.getElementById('watch-video');
    this.videoElement = document.createElement('video');
    this.videoElement.id = 'raw-player';
    this.loaded = false;
    var self = this;
    this.videoElement.addEventListener('loadeddata', function() {
        self.loaded = true;
    });
    container.appendChild(this.videoElement);
};

RawPlayer.prototype.loadVideo = function(item) {
    this.loaded = false;
    this._ensureElement();
    this.videoElement.src = item.url;
};

RawPlayer.prototype.removeVideo = function() {
    this.loaded = false;
    if (this.videoElement) {
        this.videoElement.remove();
        this.videoElement = null;
    }
};

RawPlayer.prototype.isVideoLoaded = function() { return this.loaded; };
RawPlayer.prototype.play = function() { if (this.videoElement) this.videoElement.play(); };
RawPlayer.prototype.pause = function() { if (this.videoElement) this.videoElement.pause(); };
RawPlayer.prototype.getTime = function() { return this.videoElement ? this.videoElement.currentTime : 0; };
RawPlayer.prototype.setTime = function(t) { if (this.videoElement) this.videoElement.currentTime = t; };
RawPlayer.prototype.getPlaybackRate = function() { return this.videoElement ? this.videoElement.playbackRate : 1; };
RawPlayer.prototype.setPlaybackRate = function(r) { if (this.videoElement) this.videoElement.playbackRate = r; };
RawPlayer.prototype.setMuted = function(m) { if (this.videoElement) this.videoElement.muted = m; };


// ─────────────────────────────────────────────────────────────
// IFrame Player (YouTube Live / Kick)
// ─────────────────────────────────────────────────────────────

function IFramePlayer() {
    this.serverTime = 0;
    this.currentIframe = null;
    this.loaded = false;
}

IFramePlayer.prototype.loadVideo = function(item) {
    var container = document.getElementById('watch-video');
    if (!this.currentIframe) {
        this.currentIframe = document.createElement('iframe');
        this.currentIframe.id = 'iframe-player';
        this.currentIframe.className = 'iframe-player';
        this.currentIframe.frameBorder = '0';
        this.currentIframe.allowFullscreen = true;
        this.currentIframe.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share';
        this.currentIframe.referrerPolicy = 'strict-origin-when-cross-origin';
        this.currentIframe.src = item.id || item.url;
        this.currentIframe.title = item.title || '';
        var self = this;
        this.currentIframe.onload = function() { self.loaded = true; };
        container.appendChild(this.currentIframe);
    } else {
        this.currentIframe.src = item.id || item.url;
        this.currentIframe.title = item.title || '';
    }
};

IFramePlayer.prototype.removeVideo = function() {
    if (this.currentIframe) {
        this.currentIframe.remove();
        this.currentIframe = null;
    }
    this.loaded = false;
};

IFramePlayer.prototype.isVideoLoaded = function() { return this.loaded; };
IFramePlayer.prototype.play = function() {};
IFramePlayer.prototype.pause = function() {};
IFramePlayer.prototype.getPlaybackRate = function() { return 1; };
IFramePlayer.prototype.setPlaybackRate = function() {};
IFramePlayer.prototype.setMuted = function() {};

IFramePlayer.prototype.getTime = function() {
    return this.serverTime === 0 ? 0 : (Date.now() / 1000 - this.serverTime);
};

IFramePlayer.prototype.setTime = function(t) {
    this.serverTime = Date.now() / 1000 - t;
};


// ─────────────────────────────────────────────────────────────
// Twitch Player
// ─────────────────────────────────────────────────────────────

var TWITCH_REGEX = /(?:https?:\/\/)?(?:www\.)?twitch\.tv\/(\w+)(?:\/)?/;

function TwitchPlayer() {
    this.state = PlayerState.UNINITIALIZED;
    this.twitchPlayer = null;
    this.videoToLoad = null;
    this.serverTime = 0;
}

TwitchPlayer.prototype._extractChannelName = function(url) {
    var m = url.match(TWITCH_REGEX);
    return m ? m[1] : null;
};

TwitchPlayer.prototype.loadVideo = function(item) {
    switch (this.state) {
        case PlayerState.UNINITIALIZED:
            this._initMediaPlayer();
            this.videoToLoad = item;
            break;
        case PlayerState.SCRIPT_LOADING:
            this.videoToLoad = item;
            break;
        case PlayerState.SCRIPT_LOADED:
            this._addPlayer(item);
            break;
        case PlayerState.PLAYER_ADDED:
            this.twitchPlayer.setChannel(this._extractChannelName(item.url));
            break;
    }
};

TwitchPlayer.prototype._initMediaPlayer = function() {
    if (this.state !== PlayerState.UNINITIALIZED) return;
    var script = document.createElement('script');
    script.src = 'https://player.twitch.tv/js/embed/v1.js';
    document.head.appendChild(script);
    this.state = PlayerState.SCRIPT_LOADING;
    var self = this;
    script.onload = function() {
        self.state = PlayerState.SCRIPT_LOADED;
        if (self.videoToLoad) {
            self.loadVideo(self.videoToLoad);
            self.videoToLoad = null;
        }
    };
};

TwitchPlayer.prototype._addPlayer = function(item) {
    var channel = this._extractChannelName(item.url);
    var isMuted = NTV.Main ? NTV.Main.isMuted() : false;
    var self = this;
    this.twitchPlayer = new Twitch.Player('watch-video', {
        channel: channel,
        autoplay: true
    });
    this.twitchPlayer.addEventListener(Twitch.Player.READY, function() {
        self.state = PlayerState.PLAYER_ADDED;
        self.twitchPlayer.setMuted(isMuted);
        self.twitchPlayer.play();
    });
};

TwitchPlayer.prototype.removeVideo = function() {
    if (!this.twitchPlayer) return;
    this.twitchPlayer = null;
    var container = document.getElementById('watch-video');
    var iframe = container.querySelector('iframe[title="Twitch"]');
    if (iframe) iframe.remove();
    this.state = PlayerState.SCRIPT_LOADED;
};

TwitchPlayer.prototype.isVideoLoaded = function() {
    return this.state === PlayerState.PLAYER_ADDED;
};

TwitchPlayer.prototype.play = function() {
    if (this.twitchPlayer) this.twitchPlayer.play();
};

TwitchPlayer.prototype.pause = function() {
    if (this.twitchPlayer) this.twitchPlayer.pause();
};

TwitchPlayer.prototype.getTime = function() {
    return this.serverTime === 0 ? 0 : (Date.now() / 1000 - this.serverTime);
};

TwitchPlayer.prototype.setTime = function(t) {
    this.serverTime = Date.now() / 1000 - t;
};

TwitchPlayer.prototype.getPlaybackRate = function() { return 1; };
TwitchPlayer.prototype.setPlaybackRate = function() {};

TwitchPlayer.prototype.setMuted = function(m) {
    if (this.twitchPlayer) this.twitchPlayer.setMuted(m);
};


// ─────────────────────────────────────────────────────────────
// TikTok Player (extends RawPlayer)
// ─────────────────────────────────────────────────────────────

function TikTokPlayer() {
    RawPlayer.call(this);
}

TikTokPlayer.prototype = Object.create(RawPlayer.prototype);
TikTokPlayer.prototype.constructor = TikTokPlayer;

TikTokPlayer.prototype.loadVideo = function(item) {
    this.loaded = false;
    this._ensureElement();
    this.videoElement.id = 'tiktok-player';
    // Use tikwm CDN proxy for TikTok videos
    this.videoElement.src = 'https://tikwm.com/video/media/hdplay/' + item.id + '.mp4';
};


// ─────────────────────────────────────────────────────────────
// Export all players
// ─────────────────────────────────────────────────────────────

NTV.Players = {
    YoutubePlayer: YoutubePlayer,
    RawPlayer: RawPlayer,
    IFramePlayer: IFramePlayer,
    TwitchPlayer: TwitchPlayer,
    TikTokPlayer: TikTokPlayer,
    PlayerState: PlayerState
};

})();
