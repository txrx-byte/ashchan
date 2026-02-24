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
 * nekotv/index.js
 * Entry point for NekotV shared video player.
 * Initializes player, wires up controls, integrates with
 * livepost WebSocket via Livepost.Connection.
 *
 * Port of meguca's client/nekotv/nekotv.ts.
 *
 * Load order (all defer, preserves order):
 *   1. nekotv/protocol.js
 *   2. nekotv/videolist.js
 *   3. nekotv/players.js
 *   4. nekotv/player.js
 *   5. nekotv/playlist.js
 *   6. nekotv/theater.js
 *   7. nekotv/handlers.js
 *   8. nekotv/index.js  (this file)
 *
 * Depends on: livepost/connection.js (window.Livepost.Connection)
 */

(function() {
'use strict';

var NTV = window.NekotV = window.NekotV || {};
var LP  = window.Livepost;

// ── State ──

var player = null;
var panelVisible = false;
var _isMuted = false;
var _isEnabled = true;

// ── DOM references ──

var watchPanel   = null;
var watchDiv     = null;
var playerDiv    = null;
var bannerIcon   = null;
var muteBtn      = null;
var playlistBtn  = null;
var theaterBtn   = null;
var closeBtn     = null;

// ── Public API (NTV.Main) ──

NTV.Main = {
    player: null,

    isMuted: function() { return _isMuted; },
    isEnabled: function() { return _isEnabled; },

    /**
     * Update panel visibility based on player state.
     */
    updatePanel: function() {
        if (!player) return;
        if (player.isListEmpty() || !_isEnabled) {
            NTV.Theater.setTheaterMode(false);
            setPanelVisible(false);
            player.stop();
            NTV.Playlist.stopPlayerTimeInterval();
        } else {
            setPanelVisible(true);
            NTV.Playlist.updatePlaylist();
            NTV.Playlist.startPlayerTimeInterval();
        }
    }
};

// ── Initialization ──

function init() {
    // Bail if the NekotV DOM isn't present (non-thread pages)
    watchPanel = document.getElementById('watch-panel');
    if (!watchPanel) return;

    watchDiv    = watchPanel;
    playerDiv   = document.getElementById('watch-player');
    bannerIcon  = document.getElementById('banner-nekotv');
    muteBtn     = document.getElementById('watch-mute-button');
    playlistBtn = document.getElementById('watch-playlist-button');
    theaterBtn  = document.getElementById('watch-theater-button');
    closeBtn    = document.getElementById('watch-close-button');

    // Create player
    player = new NTV.Player();
    NTV.Main.player = player;

    // Init sub-modules
    NTV.Playlist.init();

    // Restore persisted state
    var savedEnabled = localStorage.getItem('nekotv-enabled');
    _isEnabled = savedEnabled !== null ? savedEnabled === 't' : true;

    var savedMuted = localStorage.getItem('nekotv-muted');
    _isMuted = savedMuted === 't';

    updateMuteButton();
    updateBannerIcon();

    // ── Event bindings ──

    // Banner icon toggles NekotV on/off
    if (bannerIcon) {
        bannerIcon.addEventListener('click', function() {
            setEnabled(!_isEnabled);
        });
    }

    // Close button
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            setEnabled(false);
        });
    }

    // Mute button
    if (muteBtn) {
        muteBtn.addEventListener('click', function() {
            _isMuted = !_isMuted;
            localStorage.setItem('nekotv-muted', _isMuted ? 't' : 'f');
            player.setMuted(_isMuted);
            updateMuteButton();
        });
    }

    // Playlist toggle
    if (playlistBtn) {
        playlistBtn.addEventListener('click', function() {
            NTV.Playlist.togglePlaylist();
        });
    }

    // Player div click toggles playlist (desktop only)
    if (playerDiv) {
        playerDiv.addEventListener('click', function() {
            if (!matchMedia('(pointer:coarse)').matches) {
                NTV.Playlist.togglePlaylist();
            }
        });
    }

    // Theater mode button
    if (theaterBtn) {
        theaterBtn.addEventListener('click', function() {
            NTV.Theater.setTheaterMode(!NTV.Theater.getTheaterMode());
        });
    }

    // Hook into WebSocket connection for NekotV binary messages.
    // We extend the livepost connection's dispatchBinary to also handle 0x10.
    hookWebSocket();

    // Subscribe when connection syncs
    if (LP && LP.Connection) {
        // Store original onopen to chain
        var origSync = LP.Connection.synchronise;
        LP.Connection.synchronise = function() {
            origSync.call(LP.Connection);
            // Subscribe to NekotV feed after sync
            subscribeToFeed();
        };
    }
}

// ── WebSocket integration ──

function hookWebSocket() {
    if (!LP || !LP.Connection) return;

    // Extend dispatchBinary to handle NekotV type byte
    var origDispatchBinary = LP.Connection.dispatchBinary;
    LP.Connection.dispatchBinary = function(msg) {
        // Check if the last byte is the NekotV type byte
        // We need to re-read from raw data — check if this is NekotV
        // The decodeBinary already extracted the type byte
        if (msg.type === NTV.MESSAGE_TYPE) {
            // For NekotV, the frame is: [JSON payload][0x10] (no postId prefix)
            // Re-decode from raw data — but we only have the decoded msg.
            // Actually, we need to hook at a lower level.
            return;
        }
        origDispatchBinary.call(LP.Connection, msg);
    };

    // Hook onMessage to intercept raw binary before standard decode
    var origOnMessage = LP.Connection.onMessage;
    LP.Connection.onMessage = function(data) {
        if (data instanceof ArrayBuffer) {
            var bytes = new Uint8Array(data);
            if (bytes.length >= 2 && bytes[bytes.length - 1] === NTV.MESSAGE_TYPE) {
                // This is a NekotV frame — decode as JSON + type byte
                var event = NTV.Protocol.decode(bytes);
                if (event) {
                    NTV.Handlers.handleMessage(event);
                }
                return;
            }
        }
        origOnMessage.call(LP.Connection, data);
    };
}

function subscribeToFeed() {
    if (!_isEnabled) return;
    if (!LP || !LP.Connection) return;
    LP.Connection.sendBinary(NTV.Protocol.encodeSubscribe());
}

function unsubscribeFromFeed() {
    if (!LP || !LP.Connection) return;
    LP.Connection.sendBinary(NTV.Protocol.encodeUnsubscribe());
}

// ── State management ──

function setEnabled(value) {
    if (_isEnabled === value) return;
    _isEnabled = value;
    localStorage.setItem('nekotv-enabled', _isEnabled ? 't' : 'f');
    updateBannerIcon();
    NTV.Main.updatePanel();
    if (_isEnabled) {
        subscribeToFeed();
    } else {
        unsubscribeFromFeed();
    }
}

function setPanelVisible(visible) {
    if (panelVisible === visible) return;
    panelVisible = visible;
    if (visible) {
        watchDiv.style.display = 'flex';
        watchDiv.classList.remove('hide-watch-panel');
    } else {
        watchDiv.classList.add('hide-watch-panel');
        watchDiv.style.display = 'none';
    }
}

function updateMuteButton() {
    if (!muteBtn) return;
    if (_isMuted) {
        muteBtn.textContent = '\uD83D\uDD07'; // 🔇
        muteBtn.title = 'Unmute';
    } else {
        muteBtn.textContent = '\uD83D\uDD0A'; // 🔊
        muteBtn.title = 'Mute';
    }
}

function updateBannerIcon() {
    if (!bannerIcon) return;
    if (_isEnabled) {
        bannerIcon.textContent = '\uD83D\uDCFA'; // 📺
        bannerIcon.title = 'NekotV: Enabled (click to disable)';
    } else {
        bannerIcon.textContent = '\uD83D\uDCFA'; // 📺
        bannerIcon.title = 'NekotV: Disabled (click to enable)';
        bannerIcon.style.opacity = '0.5';
    }
}

// ── Boot ──

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

})();
