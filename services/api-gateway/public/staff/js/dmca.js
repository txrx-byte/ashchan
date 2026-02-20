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
      'toggle-resolve-form': APP.onResolveFormClick
    };
    
    if (window.Tip) Tip.init();
    
    document.addEventListener('click', APP.onClick);
    
    var form = document.getElementById('dmca-notice-form');
    if (form) {
        form.addEventListener('submit', APP.onNewNoticeSubmit);
    }
  },
  
  onNewNoticeSubmit: function(e) {
    var emailField = document.getElementById('dmca-email-field');
    if (emailField && emailField.value === '') {
      if (!confirm('The E-Mail field is empty.
Are you sure you want to continue?')) {
        e.preventDefault();
        e.stopPropagation();
      }
    }
  },
  
  onResolveFormClick: function(t) {
    var cnt = t.nextElementSibling;
    
    if (cnt.classList.contains('hidden')) {
      t.textContent = 'Hide Form';
      cnt.classList.remove('hidden');
    }
    else {
      t.textContent = 'Show Form';
      cnt.classList.add('hidden');
    }
  },
  
  onClick: function(e) {
    if (e.button != 0 || e.ctrlKey || e.altKey || e.shiftKey || e.metaKey) return;
    
    var t = e.target;
    if (t == document) return;
    
    var cmd = t.getAttribute('data-cmd');
    if (cmd && APP.clickCommands[cmd]) {
      e.preventDefault();
      e.stopPropagation();
      APP.clickCommands[cmd](t, e);
    }
  }
};

APP.init();
