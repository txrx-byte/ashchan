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
 * Moderator Extension (Ported for Ashchan)
 */

(function() {
'use strict';

var J = {
  // Ashchan config
  apiRoot: '/api/v1',
  board: document.body.dataset.boardSlug,
  tid: document.body.dataset.threadId,
  isStaff: document.body.dataset.isStaff === 'true',
  staffLevel: document.body.dataset.staffLevel || '',
  
  icons: {
      refresh: 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>')
  }
};

/**
 * Admin tools
 */
var AdminTools = {
  cacheTTL: 60000,
  autoRefreshDelay: 120000,
  autoRefreshTimeout: null,
  
  init: function() {
    // Similar to Janitor but with more links
    var cnt = document.createElement('div');
    cnt.className = 'extPanel reply';
    cnt.id = 'adminToolbox';
    cnt.style.right = '10px';
    cnt.style.top = '60px';
    cnt.style.position = 'fixed';
    cnt.style.zIndex = '9000';
    cnt.style.background = '#f0f0f0';
    cnt.style.border = '1px solid #ccc';

    var html = '<div class="drag" id="atHeader" style="cursor:move; padding: 5px; background: #ddd; border-bottom: 1px solid #aaa;">Mod Tools'
      + '<img alt="Refresh" title="Refresh" src="' + J.icons.refresh
      + '" id="atRefresh" data-cmd="at-refresh" class="pointer right" style="float:right; cursor:pointer;"></div>'
      + '<div style="padding: 5px;">'
      + '<h4><a href="/staff/reports" target="_blank">Reports</a>: '
      + '<span title="Total" id="at-total">?</span></h4>'
      + '<h4><a href="/staff/reports/ban-requests" target="_blank">Ban Req</a>: '
      + '<span id="at-banreqs">?</span></h4>'
      + '</div>';

    cnt.innerHTML = html;
    document.body.appendChild(cnt);
    AdminTools.refreshReportCount();
    
    // Drag logic
    var drag = document.getElementById('atHeader');
    var isDragging = false;
    var offset = {x: 0, y: 0};
    
    drag.addEventListener('mousedown', function(e) {
        isDragging = true;
        offset.x = e.offsetX;
        offset.y = e.offsetY;
    });
    
    document.addEventListener('mouseup', function() { isDragging = false; });
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        cnt.style.left = (e.clientX - offset.x) + 'px';
        cnt.style.top = (e.clientY - offset.y) + 'px';
    });
  },
  
  refreshReportCount: function() {
    fetch(J.apiRoot + '/reports/count')
      .then(r => r.json())
      .then(data => {
          document.getElementById('at-total').textContent = data.total;
          // Ideally fetch ban req count too
          document.getElementById('at-banreqs').textContent = '?'; 
      });
  }
};

J.deletePost = function(id, imageOnly, banIp) {
    if (!confirm('Are you sure you want to delete post No.' + id + '?')) return;

    var url = J.apiRoot + '/boards/' + J.board + '/posts/' + id;
    var params = new URLSearchParams();
    if (imageOnly) params.append('file_only', 'true');
    if (banIp) params.append('ban_ip', 'true'); // If backend supports it directly

    fetch(url + '?' + params.toString(), { method: 'DELETE' })
        .then(r => {
            if (r.ok) {
                var post = document.getElementById('p' + id);
                if (post) {
                    post.style.opacity = '0.5';
                    post.innerHTML += ' <span style="color:red">[DELETED]</span>';
                }
            } else {
                alert('Error deleting post');
            }
        });
};

J.toggleThreadOption = function(id, option) {
    fetch(J.apiRoot + '/boards/' + J.board + '/threads/' + id + '/options', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ option: option })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('Thread ' + option + ' toggled.');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
};

J.banUser = function(id) {
    var reason = prompt("Ban reason for No." + id + ":", "Spam");
    if (!reason) return;

    fetch(J.apiRoot + '/bans', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            board: J.board,
            post_no: id,
            template_id: 1,
            reason: reason,
            admin_username: 'current_user'
        })
    }).then(r => r.json()).then(data => {
        if (data.error) alert('Error: ' + data.error);
        else alert('User banned.');
    });
};

J.toggleSpoiler = function(id) {
    fetch(J.apiRoot + '/boards/' + J.board + '/posts/' + id + '/spoiler', {
        method: 'POST'
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('Spoiler toggled.');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
};

J.onClick = function(e) {
    var t = e.target;
    var cmd = t.getAttribute('data-cmd') || t.parentNode.getAttribute('data-cmd');
    var id = t.getAttribute('data-id') || t.parentNode.getAttribute('data-id');

    if (cmd) {
        e.preventDefault();
        switch(cmd) {
            case 'mod-delete':
                J.deletePost(id, false);
                break;
            case 'mod-delete-img':
                J.deletePost(id, true);
                break;
            case 'mod-ban':
                J.banUser(id);
                break;
            case 'mod-sticky':
                J.toggleThreadOption(id, 'sticky');
                break;
            case 'mod-lock':
                J.toggleThreadOption(id, 'lock');
                break;
            case 'mod-spoiler':
                J.toggleSpoiler(id);
                break;
            case 'mod-sfs':
                J.sfsCheck(id);
                break;
            case 'at-refresh':
                AdminTools.refreshReportCount();
                break;
        }
    }
};

J.onPostMenuReady = function(e) {
    var menu = e.detail.menu;
    var pid = e.detail.pid;
    var list = menu.querySelector('ul');

    var add = function(label, cmd) {
        var li = document.createElement('li');
        li.className = 'dd-admin';
        li.innerHTML = '<a href="#" data-cmd="' + cmd + '" data-id="' + pid + '">' + label + '</a>';
        list.appendChild(li);
    };

    add('Delete Post', 'mod-delete');
    add('Delete File', 'mod-delete-img');
    add('Ban User', 'mod-ban');
    add('Toggle Spoiler', 'mod-spoiler');
    
    // Check if OP for thread options
    if (document.getElementById('pc' + pid) && document.getElementById('pc' + pid).classList.contains('opContainer')) {
        add('Toggle Sticky', 'mod-sticky');
        add('Toggle Lock', 'mod-lock');
    }
    
    add('SFS Check', 'mod-sfs');
};

J.sfsCheck = function(pid) {
    // ideally fetch from backend, but for now prompt
    var ip = prompt("Enter IP to check against StopForumSpam (Post IP not exposed to frontend):");
    if (ip) {
        fetch('/api/v1/spam/check', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ip: ip })
        })
        .then(r => r.json())
        .then(data => {
            if (data.is_spam) alert('POSITIVE: ' + ip + ' is listed on StopForumSpam.');
            else alert('Negative: ' + ip + ' is NOT listed.');
        });
    }
};

J.loadSamePosters = function() {
    fetch(J.apiRoot + '/boards/' + J.board + '/threads/' + J.tid + '/ips')
      .then(r => r.json())
      .then(data => {
          // data is map of post_id => hash
          for (var pid in data) {
              var hash = data[pid];
              var post = document.getElementById('pi' + pid);
              if (post) {
                  var span = document.createElement('span');
                  span.className = 'posteruid id_' + hash;
                  span.innerHTML = ' (ID: <span class="hand" title="Highlight posts by this ID" style="background-color: #' + hash.substr(0,6) + '; color: white; padding: 0 2px;">' + hash + '</span>)';
                  var nameBlock = post.querySelector('.nameBlock');
                  if (nameBlock) nameBlock.appendChild(span);
              }
          }
      });
};

J.init = function() {
    if (!J.isStaff) return;
    
    AdminTools.init();
    document.addEventListener('click', J.onClick);
    document.addEventListener('ashchanPostMenuReady', J.onPostMenuReady);
    
    // Add "Same Poster ID" link if in thread view
    if (J.tid) {
        var nav = document.querySelector('.thread-controls span');
        if (nav) {
            var a = document.createElement('a');
            a.href = '#';
            a.textContent = 'Same Poster ID';
            a.onclick = function(e) { e.preventDefault(); J.loadSamePosters(); };
            nav.appendChild(document.createTextNode(' ['));
            nav.appendChild(a);
            nav.appendChild(document.createTextNode(']'));
        }
    }
    
    console.log('Ashchan Mod Tools initialized');
};

J.init();

})();
