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
      'preview': APP.onPreviewClick
    };
    
    document.addEventListener('click', APP.onClick);
  },
  
  onClick: function(e) {
    var t = e.target;
    var cmd = t.getAttribute('data-cmd');
    if (cmd && APP.clickCommands[cmd]) {
        e.preventDefault();
        APP.clickCommands[cmd](t);
    }
  },
  
  onPreviewClick: function(btn) {
    var msg = document.getElementById('field-message').value;
    var isHtml = document.getElementById('field-html').checked;
    
    fetch('/staff/site-messages/preview', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: msg, is_html: isHtml })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var p = document.getElementById('preview-content');
        if (p) {
            p.innerHTML = data.preview;
            p.parentNode.classList.remove('hidden');
        } else {
            alert('Preview: ' + data.preview);
        }
    });
  }
};

APP.init();
