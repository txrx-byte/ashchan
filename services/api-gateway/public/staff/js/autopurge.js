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


var APP = {
  init: function() {
    this.clickCommands = {
      'edit': APP.onEditClick,
      'delete': APP.onDeleteClick,
      'toggle-active': APP.onToggleActiveClick,
      'toggle-all': APP.onToggleAllClick,
      'dismiss-error': APP.hideStatusMessage
    };
    
    if (window.Tip) Tip.init();
    
    document.addEventListener('click', APP.onClick);
  },
  
  showMessage: function(ids) {
    APP.hideMessage();
    var count = ids.length;
    if (!count) return;
    
    var params = count > 1 ? 'data-ids="' + ids.join(',') + '"' : 'data-id="' + ids[0] + '"';
    
    var el = document.createElement('div');
    el.id = 'feedback';
    el.innerHTML = '<span id="select-prompt" class="feedback">'
      + count + ' entr' + (count == 1 ? 'y' : 'ies') + ' selected: '
      + '<span ' + params + ' data-cmd="edit" class="button">Edit</span>'
      + '<span ' + params + ' data-cmd="delete" class="button">Delete</span>'
      + '<span ' + params + ' data-enable data-cmd="toggle-active" class="button">Enable</span>'
      + '<span ' + params + ' data-cmd="toggle-active" class="button">Disable</span>'
      + '<span> | </span>'
      + '<span ' + params + ' data-cmd="toggle-all" class="button">Deselect</span>'
      + '</span>';
    
    document.body.appendChild(el);
  },
  
  hideMessage: function() {
    var el = document.getElementById('feedback');
    if (el) document.body.removeChild(el);
  },
  
  showStatusMessage: function(msg, type) {
    var cnt = document.getElementById('feedback');
    if (!cnt) return;
    
    var el = document.getElementById('select-prompt');
    if (el) el.classList.add('hidden');
    
    el = document.getElementById('select-status');
    if (el) cnt.removeChild(el);
    
    el = document.createElement('span');
    el.id = 'select-status';
    el.className = 'feedback';
    if (type) el.className += ' feedback-' + type;
    
    el.innerHTML = msg;
    if (type === 'error') {
        el.setAttribute('data-cmd', 'dismiss-error');
        el.title = 'Dismiss';
    }
    cnt.appendChild(el);
  },
  
  hideStatusMessage: function() {
    var el = document.getElementById('select-status');
    if (el) el.parentNode.removeChild(el);
    
    el = document.getElementById('select-prompt');
    if (el) el.classList.remove('hidden');
  },
  
  onClick: function(e) {
    if (e.button == 2 || e.ctrlKey || e.altKey || e.shiftKey || e.metaKey) return;
    
    var t = e.target;
    if (t == document) return;
    
    var cmd = t.getAttribute('data-cmd');
    if (cmd && APP.clickCommands[cmd]) {
      e.stopPropagation();
      APP.clickCommands[cmd](t, e);
    }
  },
  
  onToggleAllClick: function() {
    var flag = !document.getElementById('feedback');
    var nodes = document.getElementsByClassName('range-select'); // Assuming checkbox class
    var ids = [];
    for (var i = 0; i < nodes.length; i++) {
        nodes[i].checked = flag;
        if (flag) ids.push(nodes[i].getAttribute('data-id'));
    }
    APP.showMessage(ids);
  },
  
  uncheckAll: function() {
    var nodes = document.getElementsByClassName('range-select');
    for (var i = 0; i < nodes.length; i++) {
        nodes[i].checked = false;
        nodes[i].disabled = false;
    }
  },
  
  onEditClick: function(button, e) {
    e.preventDefault();
    var id = button.getAttribute('data-id');
    if (id) {
        window.location.href = '/staff/autopurge/' + id + '/edit';
    }
  },
  
  onToggleActiveClick: function(button, e) {
    e.preventDefault();
    var ids = button.getAttribute('data-ids') || button.getAttribute('data-id');
    if (!ids) return;
    
    var active = button.hasAttribute('data-enable');
    var idList = ids.split(',');
    
    APP.showStatusMessage('Processing...', 'notify');

    var promises = idList.map(function(id) {
        return fetch('/staff/autopurge/' + id + '/update', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ is_active: active })
        });
    });

    Promise.all(promises).then(function() {
        APP.hideMessage();
        APP.uncheckAll();
        // Reload to show status changes or update DOM
        location.reload();
    }).catch(function() {
        APP.showStatusMessage('Error updating rules', 'error');
    });
  },
  
  onDeleteClick: function(button, e) {
    e.preventDefault();
    if (!confirm('Are you sure?')) return;
    
    var ids = button.getAttribute('data-ids') || button.getAttribute('data-id');
    if (!ids) return;
    var idList = ids.split(',');

    APP.showStatusMessage('Processing...', 'notify');

    var promises = idList.map(function(id) {
        return fetch('/staff/autopurge/' + id + '/delete', { method: 'POST' });
    });

    Promise.all(promises).then(function() {
        APP.hideMessage();
        APP.uncheckAll();
        idList.forEach(function(id) {
            var el = document.getElementById('item-' + id);
            if (el) el.style.opacity = '0.5';
        });
    });
  }
};

APP.init();
