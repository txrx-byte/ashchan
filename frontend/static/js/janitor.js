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
 * Janitor Extension (Ported for Ashchan)
 */

(function() {
'use strict';

var J = {
  nextChunkIndex: 0,
  nextChunk: null,
  chunkSize: 100,
  
  // Ashchan config
  apiRoot: '/api/v1',
  board: document.body.dataset.boardSlug,
  tid: document.body.dataset.threadId,
  isStaff: document.body.dataset.isStaff === 'true',
  
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
  autoRefreshTimeout: null
};

AdminTools.initVisibilityAPI = function() {
  this.hidden = 'hidden';
  this.visibilitychange = 'visibilitychange';
  
  if (typeof document.hidden === 'undefined') {
    if ('mozHidden' in document) {
      this.hidden = 'mozHidden';
      this.visibilitychange = 'mozvisibilitychange';
    }
    else if ('webkitHidden' in document) {
      this.hidden = 'webkitHidden';
      this.visibilitychange = 'webkitvisibilitychange';
    }
  }
  
  document.addEventListener(this.visibilitychange, this.onVisibilityChange.bind(this), false);
};

AdminTools.init = function() {
  var cnt, html;
  
  AdminTools.initVisibilityAPI();
  
  cnt = document.createElement('div');
  cnt.className = 'extPanel reply';
  cnt.id = 'adminToolbox';
  cnt.style.right = '10px';
  cnt.style.top = '60px';
  cnt.style.position = 'fixed';
  cnt.style.zIndex = '9000';

  html = '<div class="drag" id="atHeader" style="cursor:move; padding: 5px; background: #ddd; border-bottom: 1px solid #aaa;">Janitor Tools'
    + '<img alt="Refresh" title="Refresh" src="' + J.icons.refresh
    + '" id="atRefresh" data-cmd="at-refresh" class="pointer right" style="float:right; cursor:pointer;"></div>'
    + '<div style="padding: 5px;">'
    + '<h4><a href="/staff/reports" target="_blank">Reports</a>: '
    + '<span title="Total" id="at-total">?</span></h4>'
    + '<h4 id="at-msg-cnt"><a data-cmd="at-msg" href="/staff/site-messages" target="_blank">Messages</a>: <span id="at-msg">?</span></h4>'
    + '</div>';

  cnt.innerHTML = html;
  document.body.appendChild(cnt);
  AdminTools.refreshReportCount();

  // Simple drag implementation
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
      cnt.style.right = 'auto'; // Disable right anchoring
  });
};

AdminTools.onVisibilityChange = function() {
  var self = AdminTools;
  
  if (document[self.hidden]) {
    clearInterval(self.autoRefreshTimeout);
    self.autoRefreshTimeout = null;
  }
  else {
    self.refreshReportCount();
    self.autoRefreshTimeout = setInterval(self.refreshReportCount.bind(self), self.autoRefreshDelay);
  }
};

AdminTools.refreshReportCount = function(force) {
  var cache;
  
  if (force !== true && (cache = localStorage.getItem('ashchan-cache-rc'))) {
    cache = JSON.parse(cache);
    
    if (cache.ts > Date.now() - AdminTools.cacheTTL) {
      document.getElementById('at-total').textContent = cache.data.total;
      return;
    }
  }
  
  fetch(J.apiRoot + '/reports/count')
    .then(r => r.json())
    .then(data => {
        document.getElementById('at-total').textContent = data.total;
        
        var cache = {
            ts: Date.now(),
            data: { total: data.total }
        };
        localStorage.setItem('ashchan-cache-rc', JSON.stringify(cache));
    })
    .catch(e => console.error('Report count error:', e));
};

J.openDeletePrompt = function(id) {
  var html, cnt, btn;

  if (typeof id === 'object' && id.getAttribute) {
      btn = id;
      id = btn.getAttribute('data-id');
  }

  // Check if already open
  if (document.getElementById('delete-prompt')) return;

  html = '<div class="extPanel reply" style="border: 1px solid #000; padding: 5px; background: #eee;"><div class="panelHeader">Delete Post No.' + id
  + '<span class="panelCtrl" style="float:right"><img alt="Close" title="Close" class="pointer" data-cmd="close-delete-prompt" src="'
  + J.icons.cross + '" style="cursor:pointer"></span></div><div id="delete-prompt-inner" style="padding: 10px; text-align: center;">'
    + '<input type="button" value="Delete Post" tabindex="-1" data-cmd="delete-post" data-id="' + id + '"> '
    + '<input type="button" value="Delete Image Only" data-cmd="delete-image" data-id="' + id + '">'
    + '</div></div>';

  cnt = document.createElement('div');
  cnt.className = 'UIPanel';
  cnt.id = 'delete-prompt';
  cnt.style.position = 'fixed';
  cnt.style.top = '50%';
  cnt.style.left = '50%';
  cnt.style.transform = 'translate(-50%, -50%)';
  cnt.style.zIndex = '9001';

  cnt.innerHTML = html;
  cnt.addEventListener('click', J.onClick, false);
  document.body.appendChild(cnt);
};

J.closeDeletePrompt = function() {
  var prompt = document.getElementById('delete-prompt');
  if (prompt) {
      document.body.removeChild(prompt);
  }
};

J.deletePost = function(btn, imageOnly) {
  var id = btn.getAttribute('data-id');
  var delMsg = document.getElementById('delete-prompt-inner');
  
  delMsg.textContent = 'Deleting...';

  var url = J.apiRoot + '/boards/' + J.board + '/posts/' + id;
  if (imageOnly) url += '?file_only=true';

  fetch(url, { method: 'DELETE' })
      .then(r => {
          if (r.ok) {
              var post = document.getElementById('p' + id);
              if (post) {
                  if (imageOnly) {
                      var file = post.querySelector('.file');
                      if (file) file.innerHTML = '<span class="fileThumb">File Deleted</span>';
                  } else {
                      post.style.opacity = '0.5';
                      post.innerHTML += ' <span style="color:red">[DELETED]</span>';
                  }
              }
              J.closeDeletePrompt();
          } else {
              delMsg.textContent = 'Error: ' + r.statusText;
          }
      })
      .catch(e => {
          delMsg.textContent = 'Error: ' + e.message;
      });
};

J.openBanReqFrame = function(btn) {
  var id = btn.getAttribute('data-id');
  var reason = prompt("Ban Request Reason for No." + id + ":");
  
  if (!reason) return;

  fetch(J.apiRoot + '/ban-requests', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
          board: J.board,
          post_no: id,
          template_id: 1, // Default to generic for now
          reason: reason,
          janitor_username: 'current_user' // Backend should infer this
      })
  }).then(r => r.json()).then(data => {
      if (data.error) alert('Error: ' + data.error);
      else alert('Ban request submitted.');
  });
};

/**
 * Click handler
 */
J.onClick = function(e) {
  var t = e.target;
  var cmd = t.getAttribute('data-cmd');
  
  if (!cmd && t.parentNode) {
      t = t.parentNode;
      cmd = t.getAttribute('data-cmd');
  }

  if (cmd) {
    switch (cmd) {
      case 'at-refresh':
        AdminTools.refreshReportCount(true);
        break;
      case 'delete-post':
        J.deletePost(t, false);
        break;
      case 'delete-image':
        J.deletePost(t, true);
        break;
      case 'open-delete-prompt':
        J.openDeletePrompt(t);
        break;
      case 'close-delete-prompt':
        J.closeDeletePrompt();
        break;
      case 'open-banreq-prompt':
        J.openBanReqFrame(t);
        break;
    }
  }
};

J.onPostMenuReady = function(e) {
  var menu = e.detail.menu || e.target; // Handle both event types if needed
  var pid = e.detail.pid || e.detail.postId;
  
  if (!menu) return;

  // Add items to menu
  var list = menu.querySelector('ul') || menu; // If it's the ul itself

  var li = document.createElement('li');
  li.className = 'dd-admin';
  li.innerHTML = '<a href="#" data-cmd="open-delete-prompt" data-id="' + pid + '">Delete Post</a>';
  list.appendChild(li);
  
  li = document.createElement('li');
  li.className = 'dd-admin';
  li.innerHTML = '<a href="#" data-cmd="open-banreq-prompt" data-id="' + pid + '">Ban Request</a>';
  list.appendChild(li);
};

J.init = function() {
  if (document.body.dataset.isStaff !== 'true') return;

  AdminTools.init();
  document.addEventListener('click', J.onClick, false);
  document.addEventListener('ashchanPostMenuReady', J.onPostMenuReady);
  
  // Inject CSS
  var style = document.createElement('style');
  style.textContent = `
    .extPanel { background: #f0f0f0; border: 1px solid #ccc; font-size: 11px; }
    .UIPanel { background: rgba(0,0,0,0.5); width: 100%; height: 100%; position: fixed; top: 0; left: 0; }
  `;
  document.head.appendChild(style);
};

J.init();

})();
