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


var APP = {};

APP.init = function() {
  if (window.Tip) Tip.init();
  
  document.addEventListener('DOMContentLoaded', APP.run);
};

APP.run = function() {
  var form = document.getElementById('filter-form');
  if (form) form.addEventListener('submit', APP.onApplyFilter);
  
  var btn = document.getElementById('filter-apply');
  if (btn) btn.addEventListener('click', APP.onApplyFilter);
};

/**
 * Notifications
 */
APP.messageTimeout = null;

APP.showMessage = function(msg, type, timeout) {
  var el;
  
  APP.hideMessage();
  
  el = document.createElement('div');
  el.id = 'feedback';
  el.title = 'Dismiss';
  el.innerHTML = '<span class="feedback-' + type + '">' + msg + '</span>';
  
  el.addEventListener('click', APP.hideMessage);
  
  document.body.appendChild(el);
  
  if (timeout) {
    APP.messageTimeout = setTimeout(APP.hideMessage, timeout);
  }
};

APP.hideMessage = function() {
  var el = document.getElementById('feedback');
  
  if (el) {
    if (APP.messageTimeout) {
      clearTimeout(APP.messageTimeout);
      APP.messageTimeout = null;
    }
    
    el.removeEventListener('click', APP.hideMessage);
    document.body.removeChild(el);
  }
};

APP.error = function(msg) {
  APP.showMessage(msg || 'Something went wrong', 'error', 5000);
};

APP.notify = function(msg) {
  APP.showMessage(msg, 'notify', 3000);
};

APP.validateFilter = function(filter) {
  if (!filter.board && filter.post) {
    APP.error('You need to select a board to search by post ID');
    return false;
  }
  return true;
};

APP.onApplyFilter = function(e) {
  if (e) e.preventDefault();
  
  var filter = {};
  var field;
  
  field = document.getElementById('filter-type');
  if (field && field.selectedIndex) {
    filter.type = field.options[field.selectedIndex].value;
  }
  
  field = document.getElementById('filter-user');
  if (field && field.selectedIndex) {
    filter.user = field.options[field.selectedIndex].textContent;
  }
  
  field = document.getElementById('filter-board');
  if (field && field.selectedIndex) {
    filter.board = field.options[field.selectedIndex].textContent;
  }
  
  field = document.getElementById('filter-date');
  if (field && field.value) {
    filter.date = field.value;
  }
  
  field = document.getElementById('filter-post');
  if (field && field.value) {
    filter.post = field.value;
  }
  
  field = document.getElementById('filter-ops');
  if (field && field.checked) {
    filter.ops = 1;
  }
  
  field = document.getElementById('filter-manual');
  if (field && field.checked) {
    filter.manual = 1;
  }
  
  if (!APP.validateFilter(filter)) {
    return;
  }
  
  var hash = [];
  for (var key in filter) {
    hash.push(key + '=' + encodeURIComponent(filter[key]));
  }
  
  location.search = '?' + hash.join('&');
};

APP.init();
