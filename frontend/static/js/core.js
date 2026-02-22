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
 * ashchan core.js
 * Style switching, post form toggle, post quoting, image expansion,
 * quote preview, thread auto-update, board navigation.
 *
 * Patterns adapted from 4chan-JS (BSD 3-clause license).
 * Copyright (c) 2012-2025, 4chan community support LLC
 * See: https://github.com/4chan/4chan-JS
 */

(function() {
'use strict';

// ---- Utility ----
var $ = {
  id: function(id) { return document.getElementById(id); },
  cls: function(cls, root) { return (root || document).getElementsByClassName(cls); },
  tag: function(tag, root) { return (root || document).getElementsByTagName(tag); },
  qs: function(sel, root) { return (root || document).querySelector(sel); },
  qsa: function(sel, root) { return (root || document).querySelectorAll(sel); },
  on: function(el, ev, fn) { el && el.addEventListener(ev, fn, false); },
  off: function(el, ev, fn) { el && el.removeEventListener(ev, fn, false); },
  addClass: function(el, cls) { el && el.classList.add(cls); },
  removeClass: function(el, cls) { el && el.classList.remove(cls); },
  hasClass: function(el, cls) { return el && el.classList.contains(cls); }
};

// ---- Style Switcher ----
var StyleSwitcher = {
  styles: ['Yotsuba', 'Yotsuba B', 'Futaba', 'Burichan', 'Photon', 'Tomorrow'],
  
  init: function() {
    var saved = localStorage.getItem('ashchan_style');
    if (saved) {
      StyleSwitcher.set(saved);
    }
    
    // Bind style changer selects
    var selects = $.qsa('.stylechanger select, select[data-cmd="style"], #styleSelector');
    for (var i = 0; i < selects.length; i++) {
      $.on(selects[i], 'change', function() {
        StyleSwitcher.set(this.value);
        // Sync all selects
        var allSelects = $.qsa('.stylechanger select, select[data-cmd="style"], #styleSelector');
        for (var j = 0; j < allSelects.length; j++) {
          allSelects[j].value = this.value;
        }
      });
      if (saved) {
        selects[i].value = saved;
      }
    }
  },
  
  set: function(name) {
    var link = $.id('themeStylesheet');
    if (link) {
      var map = {
        'Yotsuba': '/static/css/yotsuba.css',
        'Yotsuba B': '/static/css/yotsuba-b.css',
        'Futaba': '/static/css/futaba.css',
        'Burichan': '/static/css/burichan.css',
        'Photon': '/static/css/photon.css',
        'Tomorrow': '/static/css/tomorrow.css'
      };
      if (map[name]) {
        link.href = map[name];
      }
    }
    localStorage.setItem('ashchan_style', name);
  }
};

// ---- Post Form Toggle ----
var PostForm = {
  init: function() {
    var link = $.id('togglePostFormLink');
    var form = $.id('postForm');
    if (link && form) {
      $.on(link, 'click', function(e) {
        e.preventDefault();
        if (form.style.display === 'table') {
          form.style.display = '';
          link.textContent = 'Start a New Thread';
        } else {
          form.style.display = 'table';
          link.textContent = 'Close Post Form';
          var name = form.querySelector('input[name="name"]');
          if (name) name.focus();
        }
      });
    }
  }
};

// ---- Post Quoting ----
var PostQuoting = {
  init: function() {
    $.on(document, 'click', function(e) {
      var target = e.target;
      // Handle quote number click (>>No.)
      if (target.tagName === 'A' && target.getAttribute('href')) {
        var href = target.getAttribute('href');
        // Check if it's a quote link like #pNNNN
        if (/^#p\d+$/.test(href)) {
          // Scroll to post
          return;
        }
      }
      
      // Handle "No." click to quote in post form
      var postNum = target.closest ? target.closest('.postNum') : null;
      if (postNum) {
        var quoteLink = postNum.querySelector('a[title="Quote this post"]');
        if (quoteLink && target === quoteLink) {
          e.preventDefault();
          var id = quoteLink.getAttribute('href').replace('#q', '');
          PostQuoting.quote(id);
        }
      }
    });
  },
  
  quote: function(id) {
    var form = $.id('postForm');
    var ta = form ? form.querySelector('textarea[name="com"]') : null;
    if (!ta) return;
    
    // Show form if hidden
    if (form.style.display !== 'table') {
      form.style.display = 'table';
      var toggle = $.id('togglePostFormLink');
      if (toggle) toggle.textContent = 'Close Post Form';
    }
    
    var text = '>>' + id + '\n';
    var start = ta.selectionStart;
    ta.value = ta.value.substring(0, start) + text + ta.value.substring(ta.selectionEnd);
    ta.selectionStart = ta.selectionEnd = start + text.length;
    ta.focus();
  }
};

// ---- Image Expansion ----
var ImageExpansion = {
  init: function() {
    $.on(document, 'click', function(e) {
      var target = e.target;
      
      // Click on thumbnail image
      if (target.tagName === 'IMG' && $.hasClass(target.parentNode, 'fileThumb')) {
        e.preventDefault();
        ImageExpansion.toggle(target);
      }
      
      // Click on fileThumb link
      if (target.tagName === 'A' && $.hasClass(target, 'fileThumb')) {
        e.preventDefault();
        var img = target.querySelector('img');
        if (img) ImageExpansion.toggle(img);
      }
    });
  },
  
  toggle: function(thumb) {
    var link = thumb.parentNode;
    if (thumb.tagName !== 'IMG' || !link || link.tagName !== 'A') return;
    
    if ($.hasClass(thumb, 'expandedImg')) {
      // Collapse
      thumb.src = thumb.getAttribute('data-thumb-src');
      thumb.style.maxWidth = '';
      thumb.style.maxHeight = '';
      thumb.style.width = '';
      thumb.style.height = '';
      $.removeClass(thumb, 'expandedImg');
    } else {
      // Expand
      thumb.setAttribute('data-thumb-src', thumb.src);
      thumb.src = link.href;
      thumb.style.maxWidth = 'none';
      thumb.style.maxHeight = 'none';
      $.addClass(thumb, 'expandedImg');
    }
  }
};

// ---- Quote Preview ----
// QuotePreview is provided by extension.js with debouncing support.
// Removed duplicate implementation from core.js.

// ---- Thread Auto-Updater ----
var ThreadUpdater = {
  interval: 30,
  timer: null,
  enabled: false,
  countdown: 0,
  
  init: function() {
    if (!document.body.getAttribute('data-thread-id')) return;
    
    var ctrl = $.id('autoUpdateCtrl');
    if (!ctrl) return;
    
    var checkbox = ctrl.querySelector('input[type="checkbox"]');
    var updateLink = $.id('updateLink');
    var statusEl = $.id('autoUpdateStatus');
    
    if (checkbox) {
      $.on(checkbox, 'change', function() {
        if (checkbox.checked) {
          ThreadUpdater.start();
        } else {
          ThreadUpdater.stop();
        }
      });
    }
    
    if (updateLink) {
      $.on(updateLink, 'click', function(e) {
        e.preventDefault();
        ThreadUpdater.update();
      });
    }
  },
  
  start: function() {
    ThreadUpdater.enabled = true;
    ThreadUpdater.countdown = ThreadUpdater.interval;
    ThreadUpdater.tick();
  },
  
  stop: function() {
    ThreadUpdater.enabled = false;
    if (ThreadUpdater.timer) {
      clearTimeout(ThreadUpdater.timer);
      ThreadUpdater.timer = null;
    }
    var statusEl = $.id('autoUpdateStatus');
    if (statusEl) statusEl.textContent = '';
  },
  
  tick: function() {
    if (!ThreadUpdater.enabled) return;
    
    ThreadUpdater.countdown--;
    var statusEl = $.id('autoUpdateStatus');
    
    if (ThreadUpdater.countdown <= 0) {
      if (statusEl) statusEl.textContent = 'Updating...';
      ThreadUpdater.update();
      ThreadUpdater.countdown = ThreadUpdater.interval;
    } else {
      if (statusEl) statusEl.textContent = ThreadUpdater.countdown + 's';
    }
    
    ThreadUpdater.timer = setTimeout(ThreadUpdater.tick, 1000);
  },
  
  update: function() {
    var slug = document.body.getAttribute('data-board-slug');
    var threadId = document.body.getAttribute('data-thread-id');
    if (!slug || !threadId) return;
    
    // Fetch updated thread data
    fetch('/api/v1/boards/' + slug + '/threads/' + threadId)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var statusEl = $.id('autoUpdateStatus');
        if (statusEl) statusEl.textContent = 'Updated';
        setTimeout(function() {
          if (statusEl && statusEl.textContent === 'Updated')
            statusEl.textContent = '';
        }, 2000);
      })
      .catch(function() {
        var statusEl = $.id('autoUpdateStatus');
        if (statusEl) statusEl.textContent = 'Error';
      });
  }
};

// ---- Board Navigation (Mobile) ----
var BoardNav = {
  init: function() {
    var select = $.id('boardSelectMobile');
    if (select) {
      $.on(select, 'change', function() {
        if (select.value) {
          window.location.href = select.value;
        }
      });
    }
  }
};

// ---- Highlight Post on Hash ----
var PostHighlight = {
  init: function() {
    PostHighlight.highlight();
    $.on(window, 'hashchange', PostHighlight.highlight);
  },
  
  highlight: function() {
    // Remove existing highlights
    var highlighted = $.qsa('.highlight');
    for (var i = 0; i < highlighted.length; i++) {
      $.removeClass(highlighted[i], 'highlight');
    }
    
    var hash = window.location.hash;
    if (/^#p\d+$/.test(hash)) {
      var post = $.id(hash.substring(1));
      if (post) {
        $.addClass(post, 'highlight');
      }
    }
  }
};

// ---- Post Menu ----
var PostMenu = {
  activeMenu: null,
  
  init: function() {
    $.on(document, 'click', function(e) {
      if (PostMenu.activeMenu) {
        PostMenu.activeMenu.remove();
        PostMenu.activeMenu = null;
      }
    });
  }
};

// ---- Blotter Toggle ----
var Blotter = {
  init: function() {
    var showBtn = $.id('showBlotter');
    var hideBtn = $.id('hideBlotter');
    var msgs = $.id('blotter-msgs');
    if (!showBtn || !hideBtn || !msgs) return;

    $.on(showBtn, 'click', function() {
      msgs.style.display = '';
      showBtn.style.display = 'none';
      hideBtn.style.display = '';
    });
    $.on(hideBtn, 'click', function() {
      msgs.style.display = 'none';
      hideBtn.style.display = 'none';
      showBtn.style.display = '';
    });
  }
};

// ---- Main Init ----
function init() {
  StyleSwitcher.init();
  PostForm.init();
  PostQuoting.init();
  ImageExpansion.init();
  ThreadUpdater.init();
  BoardNav.init();
  PostHighlight.init();
  PostMenu.init();
  Blotter.init();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

})();
