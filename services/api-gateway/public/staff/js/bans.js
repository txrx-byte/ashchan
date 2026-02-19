'use strict';

var APP = {
  init: function() {
    this.clickCommands = {
      'unban': APP.onUnbanClick,
      'toggle': APP.onToggleClick,
      'toggle-all': APP.onToggleAllClick,
      'dismiss-error': APP.hideStatusMessage
    };
    
    if (window.Tip) Tip.init();
    
    document.addEventListener('click', APP.onClick);
  },
  
  showUnbanPrompt: function(ids) {
    APP.hideUnbanPrompt();
    var count = ids.length;
    if (!count) return;
    
    var params = 'data-ids="' + ids.join(',') + '"';
    
    var el = document.createElement('div');
    el.id = 'feedback';
    el.innerHTML = '<span id="unban-prompt" class="feedback">'
      + count + ' entr' + (count == 1 ? 'y' : 'ies') + ' selected: '
      + '<span ' + params + ' data-cmd="unban" class="button btn-accept">Unban</span>'
      + '</span>';
    
    document.body.appendChild(el);
  },
  
  hideUnbanPrompt: function() {
    var el = document.getElementById('feedback');
    if (el) document.body.removeChild(el);
  },
  
  showStatusMessage: function(msg, type) {
    var cnt = document.getElementById('feedback');
    if (!cnt) return;
    
    var el = document.getElementById('unban-prompt');
    if (el) el.classList.add('hidden');
    
    el = document.getElementById('unban-status');
    if (el) cnt.removeChild(el);
    
    el = document.createElement('span');
    el.id = 'unban-status';
    el.className = 'feedback';
    if (type) el.className += ' feedback-' + type;
    el.innerHTML = msg;
    if (type === 'error') {
        el.setAttribute('data-cmd', 'dismiss-error');
    }
    cnt.appendChild(el);
  },
  
  hideStatusMessage: function() {
    var el = document.getElementById('unban-status');
    if (el) el.parentNode.removeChild(el);
    
    el = document.getElementById('unban-prompt');
    if (el) el.classList.remove('hidden');
  },
  
  onClick: function(e) {
    var t = e.target;
    // Walk up if needed or rely on simple check
    
    var cmd = t.getAttribute('data-cmd');
    if (cmd && APP.clickCommands[cmd]) {
        e.preventDefault();
        APP.clickCommands[cmd](t, e);
    }
  },
  
  onToggleClick: function() {
    var nodes = document.getElementsByClassName('range-select');
    var ids = [];
    for (var i=0; i<nodes.length; i++) {
        if (nodes[i].checked) ids.push(nodes[i].getAttribute('data-id'));
    }
    APP.showUnbanPrompt(ids);
  },
  
  onToggleAllClick: function(button) {
    var flag = !document.getElementById('feedback');
    var nodes = document.getElementsByClassName('range-select');
    var ids = [];
    for (var i=0; i<nodes.length; i++) {
        nodes[i].checked = flag;
        if (flag) ids.push(nodes[i].getAttribute('data-id'));
    }
    APP.showUnbanPrompt(ids);
  },
  
  uncheckAll: function() {
    var nodes = document.getElementsByClassName('range-select');
    for (var i=0; i<nodes.length; i++) {
        nodes[i].checked = false;
        nodes[i].disabled = false;
    }
  },
  
  onUnbanClick: function(button) {
    var ids = button.getAttribute('data-ids');
    if (!ids) return;
    
    APP.showStatusMessage('Processing...', 'notify');
    
    fetch('/staff/bans/unban', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: ids })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            APP.hideUnbanPrompt();
            var idList = ids.split(',');
            idList.forEach(function(id) {
                var el = document.getElementById('ban-' + id);
                if (el) {
                    el.style.opacity = '0.5';
                    var cb = el.querySelector('.range-select');
                    if (cb) { cb.checked = false; cb.disabled = true; }
                    var col = el.querySelector('.col-act'); // Assuming this exists or similar
                    if (col) col.textContent = ''; // Clear status if any
                }
            });
            APP.uncheckAll();
        } else {
            APP.showStatusMessage(data.message || 'Error', 'error');
        }
    })
    .catch(function() { APP.showStatusMessage('Network error', 'error'); });
  }
};

APP.init();
