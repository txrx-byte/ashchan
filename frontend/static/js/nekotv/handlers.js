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
 * nekotv/handlers.js
 * Server event handlers: decode incoming NekotV events and dispatch to the player.
 * Port of meguca's client/nekotv/handlers.ts.
 *
 * Depends on: nekotv/protocol.js, nekotv/player.js, nekotv/playlist.js
 */

(function() {
'use strict';

var NTV = window.NekotV = window.NekotV || {};
var E   = NTV.Events;

/** Time sync threshold in seconds (1.6s). */
var SYNC_THRESHOLD = 1.6;

/**
 * Route an incoming event object to the appropriate handler.
 * @param {object} msg - Decoded JSON event with an 'event' key.
 */
function handleMessage(msg) {
    if (!msg || !msg.event) return;

    var player = NTV.Main ? NTV.Main.player : null;
    if (!player) return;

    switch (msg.event) {
        case E.CONNECTED:        handleConnected(msg, player); break;
        case E.ADD_VIDEO:        handleAddVideo(msg, player); break;
        case E.REMOVE_VIDEO:     handleRemoveVideo(msg, player); break;
        case E.SKIP_VIDEO:       handleSkipVideo(msg, player); break;
        case E.PAUSE:            handlePause(msg, player); break;
        case E.PLAY:             handlePlay(msg, player); break;
        case E.TIME_SYNC:        handleTimeSync(msg, player); break;
        case E.SET_TIME:         handleSetTime(msg, player); break;
        case E.SET_RATE:         handleSetRate(msg, player); break;
        case E.REWIND:           handleRewind(msg, player); break;
        case E.PLAY_ITEM:        handlePlayItem(msg, player); break;
        case E.SET_NEXT_ITEM:    handleSetNextItem(msg, player); break;
        case E.UPDATE_PLAYLIST:  handleUpdatePlaylist(msg, player); break;
        case E.TOGGLE_LOCK:      handleToggleLock(msg, player); break;
        case E.CLEAR_PLAYLIST:   handleClearPlaylist(msg, player); break;
        default:
            console.warn('[NekotV] Unknown event:', msg.event);
    }
}

function handleConnected(msg, player) {
    player.setItems(msg.video_list || [], msg.item_pos);
    updatePanel();
    // Apply initial time sync
    if (msg.time !== undefined) {
        handleTimeSyncData(msg, player);
    }
}

function handleAddVideo(msg, player) {
    var item = msg.item;
    if (!item) return;
    player.videoList.addItem(item, msg.at_end !== false);
    if (player.itemsLength() === 1) player.setVideo(0);
    updatePanel();
}

function handleRemoveVideo(msg, player) {
    player.removeItem(msg.url);
    updatePanel();
}

function handleSkipVideo(msg, player) {
    player.skipItem(msg.url);
    updatePanel();
}

function handlePause(msg, player) {
    player.pause();
    if (msg.time !== undefined) player.setTime(msg.time);
}

function handlePlay(msg, player) {
    var newTime = msg.time || 0;
    var curTime = player.getTime();
    if (Math.abs(curTime - newTime) >= SYNC_THRESHOLD) {
        player.setTime(newTime);
    }
    player.play();
}

function handleTimeSync(msg, player) {
    handleTimeSyncData(msg, player);
}

function handleTimeSyncData(msg, player) {
    var paused = msg.paused || false;
    var rate   = msg.rate || 1;
    var newTime = msg.time || 0;
    var curTime = player.getTime();

    if (player.getPlaybackRate() !== rate) {
        player.setPlaybackRate(rate);
    }

    if (!player.isVideoLoaded()) return;

    // If near the end, skip synchronization
    if (player.getDuration() <= newTime + SYNC_THRESHOLD) return;

    if (!paused) {
        player.play();
    } else {
        player.pause();
    }

    if (Math.abs(curTime - newTime) < SYNC_THRESHOLD) return;

    if (!paused) {
        player.setTime(newTime + 0.5);
    } else {
        player.setTime(newTime);
    }
}

function handleSetTime(msg, player) {
    var newTime = msg.time || 0;
    var curTime = player.getTime();
    // Only sync if difference exceeds threshold
    if (Math.abs(curTime - newTime) >= SYNC_THRESHOLD) {
        player.setTime(newTime);
    }
}

function handleSetRate(msg, player) {
    player.setPlaybackRate(msg.rate || 1);
}

function handleRewind(msg, player) {
    player.setTime((msg.time || 0) + 0.5);
}

function handlePlayItem(msg, player) {
    player.setVideo(msg.pos || 0);
    updatePanel();
}

function handleSetNextItem(msg, player) {
    player.setNextItem(msg.pos || 0);
    updatePanel();
}

function handleUpdatePlaylist(msg, player) {
    player.setItems(msg.items || []);
    updatePanel();
}

function handleToggleLock(msg, player) {
    // Lock state is cosmetic for now
    player.videoList.isOpen = msg.is_open;
}

function handleClearPlaylist(msg, player) {
    player.clearItems();
    updatePanel();
}

/** Trigger panel visibility + playlist UI update. */
function updatePanel() {
    if (NTV.Main) NTV.Main.updatePanel();
}

NTV.Handlers = {
    handleMessage: handleMessage
};

})();
