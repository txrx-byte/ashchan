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
      'toggle': APP.onToggleClick,
      'delete': APP.onDeleteClick,
      'toggle-active': APP.onToggleActiveClick,
      'match': APP.onMatchClick,
      'search': APP.onSearchClick,
      'toggle-all': APP.onToggleAllClick,
      'dismiss-error': APP.hideStatusMessage,
      'reset-filter': APP.resetFilter,
      'search-more': APP.onSearchMoreClick,
      'use-calc-res': APP.useCalcResult,
    };
    
    if (window.Tip) Tip.init();
    
    document.addEventListener('click', APP.onClick);
    document.addEventListener('DOMContentLoaded', APP.run);
  },
  
  run: function() {
    var el;
    
    if (el = document.getElementById('filter-ip')) {
      el.addEventListener('keydown', APP.onFilterKeyDown);
      document.getElementById('filter-desc').addEventListener('keydown', APP.onFilterKeyDown);
    }
    
    if (el = document.getElementById('js-update-desc')) {
      APP.onUpdateDescChanged();
      el.addEventListener('change', APP.onUpdateDescChanged);
    }
    
    if (el = document.getElementById('js-calc-cidr-form')) {
      el.addEventListener('submit', APP.onCalcCIDRSubmit);
      document.getElementById('js-calc-ip-form').addEventListener('submit', APP.onCalcIPSubmit);
    }
  },
  
  useCalcResult: function() {
    var el = document.getElementById('js-calc-res');
    if (!el) return;
    
    var data = el.getAttribute('data-cidr') || el.textContent;
    document.getElementById('js-ranges-field').value = data;
  },
  
  resetCalcResCnt: function(el) {
    el.textContent = '';
    el.removeAttribute('data-cidr');
    el.parentNode.classList.add('hidden');
  },
  
  onCalcCIDRSubmit: function(e) {
    e.preventDefault();
    var el = document.getElementById('js-calc-res');
    APP.resetCalcResCnt(el);
    
    var cidr = document.getElementById('js-calc-cidr').value;
    var match = cidr.match(/^([.0-9]+)\/([0-9]{1,2})$/);
    
    if (!match) return;
    
    var ip = match[1];
    var pfx = parseInt(match[2], 10);
    
    if (!ip || !pfx || pfx < 1 || pfx > 32) return;
    
    var res = IpSubnetCalculator.calculateSubnetMask(ip, pfx);
    if (!res) return;
    
    el.setAttribute('data-cidr', cidr);
    el.innerHTML = 'Start IP: ' + res.ipLowStr + '
End IP: ' + res.ipHighStr;
    el.parentNode.classList.remove('hidden');
  },
  
  onCalcIPSubmit: function(e) {
    e.preventDefault();
    var el = document.getElementById('js-calc-res');
    APP.resetCalcResCnt(el);
    
    var ipStart = document.getElementById('js-calc-ip-s').value;
    var ipEnd = document.getElementById('js-calc-ip-e').value;
    
    var res = IpSubnetCalculator.calculate(ipStart, ipEnd);
    if (!res) return;
    
    el.innerHTML = res.map(function(x) { return x.ipLowStr + '/' + x.prefixSize; }).join("
");
    el.parentNode.classList.remove('hidden');
  },
  
  onSearchMoreClick: function(btn, e) {
    var el = btn.parentNode.querySelector('.js-desc');
    if (!el || el.textContent === '') {
      e.preventDefault();
      return;
    }
    btn.setAttribute('href', '?action=search&mode=desc&q=' + encodeURIComponent(el.textContent));
  },
  
  onUpdateDescChanged: function(e) {
    var el = e ? this : document.getElementById('js-update-desc');
    document.getElementById('field-desc').disabled = !el.checked;
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
  
  onFilterKeyDown: function(e) {
    if (e.keyCode == 13) {
      APP.search(e.target.value, e.target.id.split('-')[1]);
    }
  },
  
  search: function(value, mode, opts) {
    if (value === '' && !opts) return;
    
    var url = new URL(window.location.href);
    url.searchParams.set('q', value);
    // url.searchParams.set('mode', mode); // Mode logic might differ in Ashchan
    window.location.href = url.toString();
  },
  
  resetFilter: function() {
    var el = document.getElementById('filter-desc');
    if (el) el.value = '';
    
    var nodes = document.getElementsByClassName('js-search-opt');
    for (var i = 0; i < nodes.length; i++) {
        if (nodes[i].type === 'checkbox') nodes[i].checked = false;
        else if (nodes[i].type === 'text') nodes[i].value = '';
    }
  },
  
  onMatchClick: function() {
    APP.search(document.getElementById('filter-ip').value, 'ip');
  },
  
  onSearchClick: function() {
    APP.search(document.getElementById('filter-desc').value, 'desc');
  },
  
  onToggleClick: function() {
    var nodes = document.getElementsByClassName('range-select');
    var ids = [];
    for (var i = 0; i < nodes.length; i++) {
        if (nodes[i].checked) ids.push(nodes[i].getAttribute('data-id'));
    }
    APP.showMessage(ids);
  },
  
  onToggleAllClick: function() {
    var flag = !document.getElementById('feedback');
    var nodes = document.getElementsByClassName('range-select');
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
    var id = button.getAttribute('data-id') || button.getAttribute('data-ids'); // Single edit only usually
    if (id && id.indexOf(',') === -1) {
        window.location.href = '/staff/iprangebans/' + id + '/edit';
    } else {
        alert('Bulk edit not supported');
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
        return fetch('/staff/iprangebans/' + id + '/update', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ is_active: active })
        });
    });

    Promise.all(promises).then(function() {
        APP.hideMessage();
        APP.uncheckAll();
        idList.forEach(function(id) {
            var el = document.getElementById('range-' + id);
            if (el) {
                var col = el.querySelector('.col-act');
                if (col) col.innerHTML = active ? '&#x2713;' : '';
            }
        });
    }).catch(function() {
        APP.showStatusMessage('Error updating bans', 'error');
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
        return fetch('/staff/iprangebans/' + id + '/delete', { method: 'POST' });
    });

    Promise.all(promises).then(function() {
        APP.hideMessage();
        APP.uncheckAll();
        idList.forEach(function(id) {
            var el = document.getElementById('range-' + id);
            if (el) {
                el.style.opacity = '0.5';
                var cb = el.querySelector('.range-select');
                if (cb) { cb.checked = false; cb.disabled = true; }
            }
        });
    });
  }
};

APP.init();
