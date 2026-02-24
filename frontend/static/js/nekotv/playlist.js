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
 * nekotv/playlist.js
 * Playlist UI rendering and player-time interval.
 * Port of meguca's client/nekotv/playlist.ts.
 *
 * Depends on: nekotv/protocol.js, nekotv/player.js
 */

(function() {
'use strict';

var NTV = window.NekotV = window.NekotV || {};

var playlistOl = null;       // #watch-playlist-entries
var playlistDiv = null;      // #watch-playlist
var playerTimeInterval = null;
var isPlaylistVisible = false;

/**
 * Format seconds as M:SS or H:MM:SS.
 */
function secondsToTimeExact(totalSeconds) {
    totalSeconds = Math.floor(totalSeconds);
    var hours   = Math.floor(totalSeconds / 3600);
    var minutes = Math.floor((totalSeconds - hours * 3600) / 60);
    var seconds = Math.round(totalSeconds - hours * 3600 - minutes * 60);
    var pad = function(v) { return v < 10 ? '0' + v : '' + v; };

    if (hours) return hours + ':' + pad(minutes) + ':' + pad(seconds);
    if (minutes) return minutes + ':' + pad(seconds);
    return '0:' + pad(seconds);
}

/**
 * Escape HTML entities in a string for safe DOM insertion.
 */
function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

/**
 * Rebuild the playlist DOM from the player's current video list.
 */
function updatePlaylist() {
    if (!playlistOl || !NTV.Main || !NTV.Main.player) return;

    var player = NTV.Main.player;
    var items  = player.videoList.items;
    var curPos = player.getItemPos();

    // Build all LI elements
    var fragment = document.createDocumentFragment();
    for (var i = 0; i < items.length; i++) {
        var video = items[i];
        var li = document.createElement('li');
        li.className = 'watch-playlist-entry';
        if (i === curPos) li.className += ' selected';

        var videoTerm = '';
        if (video.url && video.url.indexOf('https') !== 0) {
            videoTerm = escapeHtml(video.url);
        }

        var durationStr, moreClasses = '';
        if (video.duration === Infinity || video.duration < 0) {
            durationStr = '\u221E'; // ∞
            moreClasses = ' infinite';
        } else {
            durationStr = secondsToTimeExact(video.duration);
        }

        li.innerHTML =
            '<span class="watch-video-term">' + videoTerm + '</span>' +
            '<a class="watch-video-title" target="_blank" href="' + escapeHtml(video.url) + '" ' +
                'title="' + escapeHtml(video.title) + '">' +
                escapeHtml(video.title) +
            '</a>' +
            '<span class="watch-video-time">' +
                '<span class="watch-player-time"></span>' +
                '<span class="watch-player-dur' + moreClasses + '">' + durationStr + '</span>' +
            '</span>';
        fragment.appendChild(li);
    }

    // Replace all children
    while (playlistOl.firstChild) playlistOl.removeChild(playlistOl.firstChild);
    playlistOl.appendChild(fragment);
}

function updatePlayerTime() {
    if (!playlistOl || !playlistOl.firstElementChild) return;
    var player = NTV.Main.player;
    var time = player.getTime();
    if (time === undefined || time === null) return;
    var pos = player.getItemPos();
    var entry = playlistOl.children[pos];
    if (!entry) return;
    var el = entry.querySelector('.watch-player-time');
    if (el) el.textContent = secondsToTimeExact(time) + ' / ';
}

function startPlayerTimeInterval() {
    if (!playerTimeInterval) {
        updatePlayerTime();
        playerTimeInterval = setInterval(updatePlayerTime, 1000);
    }
}

function stopPlayerTimeInterval() {
    if (playerTimeInterval) {
        clearInterval(playerTimeInterval);
        playerTimeInterval = null;
    }
}

function showPlaylist() {
    if (playlistDiv) playlistDiv.style.display = 'block';
}

function hidePlaylist() {
    if (playlistDiv) playlistDiv.style.display = '';
}

function togglePlaylist() {
    isPlaylistVisible = !isPlaylistVisible;
    isPlaylistVisible ? showPlaylist() : hidePlaylist();
}

/** Initialize DOM references (called from index.js). */
function init() {
    playlistOl  = document.getElementById('watch-playlist-entries');
    playlistDiv = document.getElementById('watch-playlist');
}

// Export
NTV.Playlist = {
    init: init,
    updatePlaylist: updatePlaylist,
    startPlayerTimeInterval: startPlayerTimeInterval,
    stopPlayerTimeInterval: stopPlayerTimeInterval,
    togglePlaylist: togglePlaylist,
    secondsToTimeExact: secondsToTimeExact
};

})();
