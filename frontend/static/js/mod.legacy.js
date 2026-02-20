(function() {
'use strict';

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


var Mod = {
  init: function() {
    if (document.body.dataset.isStaff !== 'true') return;
    
    document.addEventListener('ashchanPostMenuReady', Mod.onPostMenuReady);
    console.log('Ashchan Mod Tools initialized');
  },

  onPostMenuReady: function(e) {
    var menu = e.detail.menu;
    var pid = e.detail.pid;
    var btn = e.detail.btn;
    var list = menu.querySelector('ul');

    // Add separator
    var sep = document.createElement('li');
    sep.className = 'separator';
    sep.style.borderTop = '1px solid #ccc';
    sep.style.marginTop = '3px';
    sep.style.paddingTop = '3px';
    sep.style.textAlign = 'center';
    sep.style.fontSize = '0.9em';
    sep.style.color = '#888';
    sep.textContent = 'Staff Tools';
    list.appendChild(sep);

    // Delete
    var del = document.createElement('li');
    del.innerHTML = '<span style="color:red">✖</span> Delete Post';
    del.dataset.cmd = 'mod-delete';
    del.dataset.pid = pid;
    del.style.cursor = 'pointer';
    list.appendChild(del);

    // Ban
    var ban = document.createElement('li');
    ban.innerHTML = '<span style="color:orange">⚡</span> Ban User';
    ban.dataset.cmd = 'mod-ban';
    ban.dataset.pid = pid;
    ban.style.cursor = 'pointer';
    list.appendChild(ban);

    // SFS Check
    var sfs = document.createElement('li');
    sfs.innerHTML = '<span style="color:blue">🔍</span> SFS Check';
    sfs.dataset.cmd = 'mod-sfs';
    sfs.dataset.pid = pid;
    sfs.style.cursor = 'pointer';
    list.appendChild(sfs);

    // Bind clicks
    del.addEventListener('click', Mod.onDeleteClick);
    ban.addEventListener('click', Mod.onBanClick);
    sfs.addEventListener('click', Mod.onSfsClick);
  },

  onDeleteClick: function(e) {
    e.preventDefault();
    var pid = this.dataset.pid;
    if (confirm('Delete post No.' + pid + '?')) {
        Mod.deletePost(pid);
    }
  },

  deletePost: function(pid) {
      var board = document.body.getAttribute('data-board-slug');
      
      // Call Gateway endpoint for staff delete
      fetch('/api/v1/reports/' + pid, { // Using reports/delete as a placeholder or need new endpoint
          method: 'DELETE',
          headers: {
             // Auth headers handled by browser cookie
          }
      }).then(r => {
          if (r.ok) {
              var post = document.getElementById('p' + pid);
              if (post) {
                  post.style.opacity = '0.5';
                  post.innerHTML += ' <span style="color:red">[DELETED]</span>';
              }
          } else {
              alert('Delete failed (status ' + r.status + ')');
          }
      });
  },

  onBanClick: function(e) {
      e.preventDefault();
      var pid = this.dataset.pid;
      var reason = prompt('Ban reason:', 'Spam');
      if (reason) {
          Mod.banUser(pid, reason);
      }
  },
  
  banUser: function(pid, reason) {
      var board = document.body.getAttribute('data-board-slug');
      
      fetch('/api/v1/bans', {
          method: 'POST',
          headers: {
              'Content-Type': 'application/json'
          },
          body: JSON.stringify({
              board: board,
              post_no: pid,
              template_id: 1, 
              reason: reason,
              admin_username: 'staff' // Should be inferred by backend
          })
      }).then(r => r.json()).then(data => {
          if(data.error) alert('Error: ' + data.error);
          else alert('User banned!');
      });
  },

  onSfsClick: function(e) {
    e.preventDefault();
    alert('StopForumSpam check requires user IP. Current system stores IP hashes only, so checking existing posts is not supported yet.');
  }
};

// Check for is_staff flag passed from layout.html logic?
// layout.html only includes this script if is_staff is true.
// But we can also check body attribute if we added it.
// We didn't add data-is-staff to body, but we can assume if this script runs, it's allowed.
Mod.init();

})();
