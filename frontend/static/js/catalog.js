/**
 * ashchan catalog.js
 * Catalog-specific features: search/filter, sort, size toggle, thread hiding,
 * thread pinning, keyboard shortcuts, tooltips, thread watcher.
 *
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
  hasClass: function(el, cls) { return el && el.classList.contains(cls); },
  toggleClass: function(el, cls) {
    if ($.hasClass(el, cls)) {
      $.removeClass(el, cls);
    } else {
      $.addClass(el, cls);
    }
  }
};

var UA = {
  hasWebStorage: (function() {
    try {
      localStorage.setItem('test', 'test');
      localStorage.removeItem('test');
      return true;
    } catch (e) {
      return false;
    }
  })(),
  isMobileDevice: /Mobile|Android|Opera Mobi|Nintendo/.test(navigator.userAgent)
};

// ---- Catalog Module ----
var Catalog = {
  boardSlug: null,
  hiddenThreads: {},
  hiddenThreadsCount: 0,
  pinnedThreads: {},
  quickFilterPattern: false,
  settings: {
    orderby: 'bump',
    size: 'large'
  },
  tooltipTimeout: null,
  hasTooltip: false,

  init: function() {
    var body = document.body;
    Catalog.boardSlug = body.getAttribute('data-board-slug');
    
    // Load settings and thread states from localStorage
    Catalog.loadSettings();
    Catalog.loadStorage();
    
    // Initialize UI controls
    var searchInput = $.id('catalog-search-input');
    var sortSelect = $.id('catalog-sort-select');
    var sizeSelect = $.id('catalog-size-select');
    var threadsContainer = $.id('threads');

    if (searchInput) {
      $.on(searchInput, 'keyup', Catalog.debounce(Catalog.filterThreads, 250));
      $.on(searchInput, 'keydown', function(e) {
        if (e.keyCode === 27) { // Escape
          searchInput.value = '';
          Catalog.clearFilter();
        }
      });
    }

    if (sortSelect) {
      $.on(sortSelect, 'change', Catalog.sortThreads);
      sortSelect.value = Catalog.settings.orderby;
    }

    if (sizeSelect) {
      $.on(sizeSelect, 'change', Catalog.resizeThreads);
      sizeSelect.value = Catalog.settings.size;
      if (threadsContainer) {
        threadsContainer.className = 'catalog-board catalog-' + Catalog.settings.size;
      }
    }

    // Thread interactions (click delegation)
    if (threadsContainer) {
      $.on(threadsContainer, 'click', Catalog.onThreadClick);
      $.on(threadsContainer, 'mouseover', Catalog.onThreadMouseOver);
      $.on(threadsContainer, 'mouseout', Catalog.onThreadMouseOut);
    }

    // Keyboard shortcuts
    $.on(document, 'keyup', Catalog.onKeyUp);

    // Apply initial states (hidden/pinned)
    Catalog.applyThreadStates();

    // Initialize thread watcher
    ThreadWatcher.init();
  },

  // ---- Settings & Storage ----
  loadSettings: function() {
    if (!UA.hasWebStorage) return;
    var saved = localStorage.getItem('ashchan-catalog-settings');
    if (saved) {
      try {
        var parsed = JSON.parse(saved);
        Catalog.settings = Object.assign(Catalog.settings, parsed);
      } catch (e) {}
    }
  },

  saveSettings: function() {
    if (!UA.hasWebStorage) return;
    localStorage.setItem('ashchan-catalog-settings', JSON.stringify(Catalog.settings));
  },

  loadStorage: function() {
    if (!UA.hasWebStorage || !Catalog.boardSlug) return;
    
    var hidden = localStorage.getItem('ashchan-hide-' + Catalog.boardSlug);
    if (hidden) {
      try {
        Catalog.hiddenThreads = JSON.parse(hidden);
      } catch (e) {
        Catalog.hiddenThreads = {};
      }
    }

    var pinned = localStorage.getItem('ashchan-pin-' + Catalog.boardSlug);
    if (pinned) {
      try {
        Catalog.pinnedThreads = JSON.parse(pinned);
      } catch (e) {
        Catalog.pinnedThreads = {};
      }
    }
  },

  saveHiddenThreads: function() {
    if (!UA.hasWebStorage || !Catalog.boardSlug) return;
    if (Object.keys(Catalog.hiddenThreads).length > 0) {
      localStorage.setItem('ashchan-hide-' + Catalog.boardSlug, JSON.stringify(Catalog.hiddenThreads));
    } else {
      localStorage.removeItem('ashchan-hide-' + Catalog.boardSlug);
    }
  },

  savePinnedThreads: function() {
    if (!UA.hasWebStorage || !Catalog.boardSlug) return;
    if (Object.keys(Catalog.pinnedThreads).length > 0) {
      localStorage.setItem('ashchan-pin-' + Catalog.boardSlug, JSON.stringify(Catalog.pinnedThreads));
    } else {
      localStorage.removeItem('ashchan-pin-' + Catalog.boardSlug);
    }
  },

  // ---- Thread Filtering/Search ----
  filterThreads: function() {
    var searchInput = $.id('catalog-search-input');
    var filter = (searchInput.value || '').toLowerCase().trim();
    var threads = $.qsa('.catalog-thread');

    if (!filter) {
      Catalog.clearFilter();
      return;
    }

    Catalog.quickFilterPattern = new RegExp(filter.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');

    for (var i = 0; i < threads.length; i++) {
      var thread = threads[i];
      var excerpt = (thread.querySelector('.catalog-excerpt') || {}).textContent || '';
      
      if (Catalog.quickFilterPattern.test(excerpt)) {
        thread.style.display = '';
      } else {
        thread.style.display = 'none';
      }
    }

    Catalog.updateFilterLabel(filter);
  },

  clearFilter: function() {
    Catalog.quickFilterPattern = false;
    var threads = $.qsa('.catalog-thread');
    for (var i = 0; i < threads.length; i++) {
      var tid = threads[i].id.replace('thread-', '');
      if (Catalog.hiddenThreads[tid]) {
        threads[i].style.display = 'none';
      } else {
        threads[i].style.display = '';
      }
    }
    Catalog.updateFilterLabel('');
  },

  updateFilterLabel: function(term) {
    var label = $.id('search-label');
    var termEl = $.id('search-term');
    if (label && termEl) {
      if (term) {
        termEl.textContent = term;
        label.style.display = 'inline';
      } else {
        label.style.display = 'none';
      }
    }
  },

  // ---- Sorting ----
  sortThreads: function() {
    var sortSelect = $.id('catalog-sort-select');
    var key = sortSelect.value;
    var threadsContainer = $.id('threads');
    var threads = Array.from($.qsa('.catalog-thread'));

    Catalog.settings.orderby = key;
    Catalog.saveSettings();

    // First, separate pinned threads
    var pinned = [];
    var unpinned = [];

    threads.forEach(function(thread) {
      var tid = thread.id.replace('thread-', '');
      if (Catalog.pinnedThreads[tid]) {
        pinned.push(thread);
      } else {
        unpinned.push(thread);
      }
    });

    // Sort unpinned threads
    unpinned.sort(function(a, b) {
      var valA, valB;
      switch (key) {
        case 'replies':
          valA = parseInt(a.dataset.replies, 10) || 0;
          valB = parseInt(b.dataset.replies, 10) || 0;
          return valB - valA;
        case 'images':
          valA = parseInt(a.dataset.images, 10) || 0;
          valB = parseInt(b.dataset.images, 10) || 0;
          return valB - valA;
        case 'time':
          valA = parseInt(a.dataset.created, 10) || 0;
          valB = parseInt(b.dataset.created, 10) || 0;
          return valB - valA;
        case 'bump':
        default:
          valA = parseInt(a.dataset.bumped, 10) || 0;
          valB = parseInt(b.dataset.bumped, 10) || 0;
          return valB - valA;
      }
    });

    // Re-append: pinned first, then sorted unpinned
    pinned.forEach(function(thread) {
      threadsContainer.appendChild(thread);
    });
    unpinned.forEach(function(thread) {
      threadsContainer.appendChild(thread);
    });
  },

  // ---- Size Toggle ----
  resizeThreads: function() {
    var sizeSelect = $.id('catalog-size-select');
    var threadsContainer = $.id('threads');
    var size = sizeSelect.value;
    
    threadsContainer.className = 'catalog-board catalog-' + size;
    Catalog.settings.size = size;
    Catalog.saveSettings();
  },

  // ---- Thread States (Hide/Pin) ----
  applyThreadStates: function() {
    var threads = $.qsa('.catalog-thread');
    Catalog.hiddenThreadsCount = 0;

    for (var i = 0; i < threads.length; i++) {
      var thread = threads[i];
      var tid = thread.id.replace('thread-', '');

      if (Catalog.hiddenThreads[tid]) {
        thread.style.display = 'none';
        Catalog.hiddenThreadsCount++;
      }

      if (Catalog.pinnedThreads[tid]) {
        $.addClass(thread, 'pinned');
      }
    }

    Catalog.updateHiddenCount();
  },

  toggleThreadHide: function(tid) {
    var thread = $.id('thread-' + tid);
    if (!thread) return;

    if (Catalog.hiddenThreads[tid]) {
      delete Catalog.hiddenThreads[tid];
      thread.style.display = '';
      Catalog.hiddenThreadsCount--;
    } else {
      Catalog.hiddenThreads[tid] = true;
      thread.style.display = 'none';
      Catalog.hiddenThreadsCount++;
    }

    Catalog.saveHiddenThreads();
    Catalog.updateHiddenCount();
  },

  toggleThreadPin: function(tid) {
    var thread = $.id('thread-' + tid);
    if (!thread) return;

    if (Catalog.pinnedThreads[tid]) {
      delete Catalog.pinnedThreads[tid];
      $.removeClass(thread, 'pinned');
    } else {
      Catalog.pinnedThreads[tid] = parseInt(thread.dataset.replies, 10) || 0;
      $.addClass(thread, 'pinned');
    }

    Catalog.savePinnedThreads();
    Catalog.sortThreads(); // Re-sort to move pinned to top
  },

  updateHiddenCount: function() {
    var label = $.id('hidden-label');
    var count = $.id('hidden-count');
    if (label && count) {
      if (Catalog.hiddenThreadsCount > 0) {
        count.textContent = Catalog.hiddenThreadsCount;
        label.style.display = 'inline';
      } else {
        label.style.display = 'none';
      }
    }
  },

  clearHiddenThreads: function() {
    Catalog.hiddenThreads = {};
    Catalog.hiddenThreadsCount = 0;
    Catalog.saveHiddenThreads();
    
    var threads = $.qsa('.catalog-thread');
    for (var i = 0; i < threads.length; i++) {
      if (!Catalog.quickFilterPattern || Catalog.quickFilterPattern.test(threads[i].textContent)) {
        threads[i].style.display = '';
      }
    }
    Catalog.updateHiddenCount();
  },

  clearPinnedThreads: function() {
    Catalog.pinnedThreads = {};
    Catalog.savePinnedThreads();
    
    var threads = $.qsa('.catalog-thread.pinned');
    for (var i = 0; i < threads.length; i++) {
      $.removeClass(threads[i], 'pinned');
    }
    Catalog.sortThreads();
  },

  // ---- Event Handlers ----
  onThreadClick: function(e) {
    var target = e.target;
    
    // Handle hide button
    if (target.hasAttribute('data-hide')) {
      e.preventDefault();
      Catalog.toggleThreadHide(target.getAttribute('data-hide'));
      return;
    }

    // Handle pin button
    if (target.hasAttribute('data-pin')) {
      e.preventDefault();
      Catalog.toggleThreadPin(target.getAttribute('data-pin'));
      return;
    }

    // Handle watch button
    if (target.hasAttribute('data-watch')) {
      e.preventDefault();
      var tid = target.getAttribute('data-watch');
      var thread = $.id('thread-' + tid);
      var subject = '';
      var excerpt = '';
      if (thread) {
        var excerptEl = thread.querySelector('.catalog-excerpt');
        if (excerptEl) {
          var subEl = excerptEl.querySelector('b');
          subject = subEl ? subEl.textContent : '';
          excerpt = excerptEl.textContent;
        }
      }
      ThreadWatcher.toggle(tid, Catalog.boardSlug, subject, excerpt);
      return;
    }

    // Handle menu button
    if (target.hasAttribute('data-menu')) {
      e.preventDefault();
      PostMenu.open(target, target.getAttribute('data-menu'));
      return;
    }

    // Shift+click to hide
    if (e.shiftKey && $.hasClass(target, 'catalog-thumb')) {
      e.preventDefault();
      var thread = target.closest('.catalog-thread');
      if (thread) {
        var tid = thread.id.replace('thread-', '');
        Catalog.toggleThreadHide(tid);
      }
      return;
    }

    // Alt+click to pin
    if (e.altKey && $.hasClass(target, 'catalog-thumb')) {
      e.preventDefault();
      var thread = target.closest('.catalog-thread');
      if (thread) {
        var tid = thread.id.replace('thread-', '');
        Catalog.toggleThreadPin(tid);
      }
      return;
    }
  },

  onThreadMouseOver: function(e) {
    var target = e.target;
    if ($.hasClass(target, 'catalog-thumb')) {
      clearTimeout(Catalog.tooltipTimeout);
      Catalog.tooltipTimeout = setTimeout(function() {
        Catalog.showTooltip(target);
      }, 250);
    }
  },

  onThreadMouseOut: function(e) {
    clearTimeout(Catalog.tooltipTimeout);
    if (Catalog.hasTooltip) {
      Catalog.hideTooltip();
    }
  },

  // ---- Tooltips ----
  showTooltip: function(thumb) {
    var thread = thumb.closest('.catalog-thread');
    if (!thread) return;

    var tid = thread.id.replace('thread-', '');
    var replies = thread.dataset.replies || 0;
    var images = thread.dataset.images || 0;
    var created = thread.dataset.created;
    var bumped = thread.dataset.bumped;

    var excerpt = thread.querySelector('.catalog-excerpt');
    var text = excerpt ? excerpt.textContent : '';

    var html = '<div class="catalog-tooltip">';
    html += '<div class="tooltip-stats">R: ' + replies + ' / I: ' + images + '</div>';
    
    if (created) {
      var date = new Date(parseInt(created, 10) * 1000);
      html += '<div class="tooltip-date">Created: ' + date.toLocaleString() + '</div>';
    }
    
    if (text) {
      html += '<div class="tooltip-excerpt">' + text.substring(0, 200) + (text.length > 200 ? '...' : '') + '</div>';
    }
    
    html += '</div>';

    var tooltip = document.createElement('div');
    tooltip.id = 'catalog-tooltip';
    tooltip.innerHTML = html;
    document.body.appendChild(tooltip);

    var rect = thumb.getBoundingClientRect();
    var docWidth = document.documentElement.clientWidth;
    
    var left = rect.right + 10 + window.pageXOffset;
    if (left + tooltip.offsetWidth > docWidth) {
      left = rect.left - tooltip.offsetWidth - 10 + window.pageXOffset;
    }

    tooltip.style.left = left + 'px';
    tooltip.style.top = (rect.top + window.pageYOffset) + 'px';

    Catalog.hasTooltip = true;
  },

  hideTooltip: function() {
    var tooltip = $.id('catalog-tooltip');
    if (tooltip) {
      tooltip.parentNode.removeChild(tooltip);
    }
    Catalog.hasTooltip = false;
  },

  // ---- Keyboard Shortcuts ----
  onKeyUp: function(e) {
    var target = e.target;
    if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
      return;
    }

    switch (e.keyCode) {
      case 83: // S - Focus search
        var searchInput = $.id('catalog-search-input');
        if (searchInput) {
          searchInput.focus();
        }
        break;
      case 82: // R - Refresh
        if (!e.shiftKey) {
          location.reload();
        }
        break;
      case 88: // X - Cycle sort order
        Catalog.cycleSortOrder();
        break;
    }
  },

  cycleSortOrder: function() {
    var sortSelect = $.id('catalog-sort-select');
    if (!sortSelect) return;

    var orders = ['bump', 'time', 'replies', 'images'];
    var current = orders.indexOf(sortSelect.value);
    var next = (current + 1) % orders.length;
    
    sortSelect.value = orders[next];
    Catalog.sortThreads();
  },

  // ---- Utilities ----
  debounce: function(func, wait, immediate) {
    var timeout;
    return function() {
      var context = this, args = arguments;
      var later = function() {
        timeout = null;
        if (!immediate) func.apply(context, args);
      };
      var callNow = immediate && !timeout;
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
      if (callNow) func.apply(context, args);
    };
  }
};

// ---- Thread Watcher Module ----
var ThreadWatcher = {
  watched: {},
  listNode: null,
  charLimit: 45,

  init: function() {
    if (!UA.hasWebStorage) return;

    ThreadWatcher.load();
    ThreadWatcher.buildUI();
    ThreadWatcher.updateThreadIcons();

    window.addEventListener('storage', ThreadWatcher.syncStorage, false);
  },

  load: function() {
    var storage = localStorage.getItem('ashchan-watch');
    if (storage) {
      try {
        ThreadWatcher.watched = JSON.parse(storage);
      } catch (e) {
        ThreadWatcher.watched = {};
      }
    }
  },

  save: function() {
    localStorage.setItem('ashchan-watch', JSON.stringify(ThreadWatcher.watched));
  },

  syncStorage: function(e) {
    if (e.key === 'ashchan-watch' && e.newValue !== e.oldValue) {
      ThreadWatcher.load();
      ThreadWatcher.build();
      ThreadWatcher.updateThreadIcons();
    }
  },

  buildUI: function() {
    var cnt = document.createElement('div');
    cnt.id = 'threadWatcher';
    cnt.innerHTML = '<div class="tw-header" id="twHeader">Thread Watcher' +
      '<span id="twClose" class="tw-close">&times;</span>' +
      '<span id="twRefresh" class="tw-refresh" title="Refresh">&#x21bb;</span></div>';

    ThreadWatcher.listNode = document.createElement('ul');
    ThreadWatcher.listNode.id = 'watchList';
    cnt.appendChild(ThreadWatcher.listNode);

    document.body.appendChild(cnt);

    $.on(cnt, 'click', ThreadWatcher.onClick);
    $.on($.id('twHeader'), 'mousedown', Draggable.startDrag);

    // Position from saved settings
    var pos = localStorage.getItem('ashchan-tw-position');
    if (pos) {
      try {
        var parsed = JSON.parse(pos);
        cnt.style.left = parsed.left;
        cnt.style.top = parsed.top;
      } catch (e) {}
    }

    ThreadWatcher.build();
  },

  build: function() {
    var html = '';

    for (var key in ThreadWatcher.watched) {
      var parts = key.split('-');
      var tid = parts[0];
      var board = parts[1];
      var data = ThreadWatcher.watched[key];
      var label = data[0] || ('No.' + tid);
      var newReplies = data[2] || 0;

      html += '<li id="watch-' + key + '">' +
        '<span class="tw-unwatch" data-cmd="unwatch" data-id="' + tid + '" data-board="' + board + '">&times;</span> ' +
        '<a href="/' + board + '/thread/' + tid + '"' +
        (newReplies > 0 ? ' class="hasNewReplies">(' + newReplies + ') ' : '>') +
        '/' + board + '/ - ' + label + '</a></li>';
    }

    if (!html) {
      html = '<li class="tw-empty">No watched threads</li>';
    }

    ThreadWatcher.listNode.innerHTML = html;
  },

  onClick: function(e) {
    var target = e.target;

    if (target.hasAttribute('data-cmd')) {
      var cmd = target.getAttribute('data-cmd');
      if (cmd === 'unwatch') {
        var tid = target.getAttribute('data-id');
        var board = target.getAttribute('data-board');
        ThreadWatcher.remove(tid, board);
      }
    } else if (target.id === 'twClose') {
      $.id('threadWatcher').style.display = 'none';
    } else if (target.id === 'twRefresh') {
      ThreadWatcher.refresh();
    }
  },

  toggle: function(tid, board, subject, excerpt) {
    var key = tid + '-' + board;

    if (ThreadWatcher.watched[key]) {
      delete ThreadWatcher.watched[key];
    } else {
      var label = ThreadWatcher.generateLabel(subject, excerpt, tid);
      ThreadWatcher.watched[key] = [label, tid, 0];
    }

    ThreadWatcher.save();
    ThreadWatcher.build();
    ThreadWatcher.updateThreadIcons();
  },

  remove: function(tid, board) {
    var key = tid + '-' + board;
    delete ThreadWatcher.watched[key];
    ThreadWatcher.save();
    ThreadWatcher.build();
    ThreadWatcher.updateThreadIcons();
  },

  generateLabel: function(subject, excerpt, tid) {
    var label = subject || excerpt || '';
    if (label.length > ThreadWatcher.charLimit) {
      label = label.substring(0, ThreadWatcher.charLimit) + '...';
    }
    return label || ('No.' + tid);
  },

  updateThreadIcons: function() {
    var threads = $.qsa('.catalog-thread');
    for (var i = 0; i < threads.length; i++) {
      var thread = threads[i];
      var tid = thread.id.replace('thread-', '');
      var key = tid + '-' + Catalog.boardSlug;
      var icon = thread.querySelector('.watch-icon');
      
      if (icon) {
        if (ThreadWatcher.watched[key]) {
          $.addClass(icon, 'watched');
          icon.title = 'Unwatch thread';
        } else {
          $.removeClass(icon, 'watched');
          icon.title = 'Watch thread';
        }
      }
    }
  },

  refresh: function() {
    // Fetch updates for watched threads
    var icon = $.id('twRefresh');
    if (icon) {
      $.addClass(icon, 'rotating');
    }

    var count = Object.keys(ThreadWatcher.watched).length;
    var done = 0;

    for (var key in ThreadWatcher.watched) {
      (function(k) {
        var parts = k.split('-');
        var tid = parts[0];
        var board = parts[1];

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/api/v1/boards/' + board + '/threads/' + tid);
        xhr.onload = function() {
          done++;
          if (this.status === 200) {
            try {
              var data = JSON.parse(this.responseText);
              // Update reply count if available
              if (data.reply_count !== undefined) {
                var lastCount = ThreadWatcher.watched[k][2] || 0;
                var newCount = data.reply_count - parseInt(ThreadWatcher.watched[k][1], 10);
                if (newCount > lastCount) {
                  ThreadWatcher.watched[k][2] = newCount;
                }
              }
            } catch (e) {}
          } else if (this.status === 404) {
            // Thread is dead
            ThreadWatcher.watched[k][1] = -1;
          }
          if (done === count) {
            ThreadWatcher.onRefreshDone(icon);
          }
        };
        xhr.onerror = function() {
          done++;
          if (done === count) {
            ThreadWatcher.onRefreshDone(icon);
          }
        };
        xhr.send();
      })(key);
    }

    if (count === 0 && icon) {
      $.removeClass(icon, 'rotating');
    }
  },

  onRefreshDone: function(icon) {
    if (icon) {
      $.removeClass(icon, 'rotating');
    }
    ThreadWatcher.save();
    ThreadWatcher.build();
  }
};

// ---- Post Menu Module ----
var PostMenu = {
  activeBtn: null,
  activeMenu: null,

  open: function(btn, tid) {
    if (PostMenu.activeBtn === btn) {
      PostMenu.close();
      return;
    }

    PostMenu.close();

    var thread = $.id('thread-' + tid);
    var isHidden = Catalog.hiddenThreads[tid];
    var isPinned = Catalog.pinnedThreads[tid];
    var isWatched = ThreadWatcher.watched[tid + '-' + Catalog.boardSlug];

    var html = '<ul class="post-menu-list">' +
      '<li data-hide="' + tid + '">' + (isHidden ? 'Unhide' : 'Hide') + ' thread</li>' +
      '<li data-pin="' + tid + '">' + (isPinned ? 'Unpin' : 'Pin') + ' thread</li>' +
      '<li data-watch="' + tid + '">' + (isWatched ? 'Unwatch' : 'Watch') + ' thread</li>' +
      '</ul>';

    var menu = document.createElement('div');
    menu.id = 'post-menu';
    menu.className = 'post-menu';
    menu.innerHTML = html;

    var rect = btn.getBoundingClientRect();
    menu.style.top = (rect.bottom + 3 + window.pageYOffset) + 'px';
    menu.style.left = (rect.left + window.pageXOffset) + 'px';

    document.body.appendChild(menu);
    document.addEventListener('click', PostMenu.onDocumentClick, false);

    $.addClass(btn, 'menu-open');
    PostMenu.activeBtn = btn;
    PostMenu.activeMenu = menu;
  },

  close: function() {
    if (PostMenu.activeMenu) {
      PostMenu.activeMenu.parentNode.removeChild(PostMenu.activeMenu);
      PostMenu.activeMenu = null;
    }
    if (PostMenu.activeBtn) {
      $.removeClass(PostMenu.activeBtn, 'menu-open');
      PostMenu.activeBtn = null;
    }
    document.removeEventListener('click', PostMenu.onDocumentClick, false);
  },

  onDocumentClick: function(e) {
    var target = e.target;
    
    if (target.hasAttribute('data-hide')) {
      Catalog.toggleThreadHide(target.getAttribute('data-hide'));
    } else if (target.hasAttribute('data-pin')) {
      Catalog.toggleThreadPin(target.getAttribute('data-pin'));
    } else if (target.hasAttribute('data-watch')) {
      var tid = target.getAttribute('data-watch');
      var thread = $.id('thread-' + tid);
      var subject = '', excerpt = '';
      if (thread) {
        var excerptEl = thread.querySelector('.catalog-excerpt');
        if (excerptEl) {
          var subEl = excerptEl.querySelector('b');
          subject = subEl ? subEl.textContent : '';
          excerpt = excerptEl.textContent;
        }
      }
      ThreadWatcher.toggle(tid, Catalog.boardSlug, subject, excerpt);
    }
    
    PostMenu.close();
  }
};

// ---- Draggable Module ----
var Draggable = {
  el: null,
  dx: 0,
  dy: 0,

  startDrag: function(e) {
    e.preventDefault();

    var el = this.parentNode;
    Draggable.el = el;

    var rect = el.getBoundingClientRect();
    Draggable.dx = e.clientX - rect.left;
    Draggable.dy = e.clientY - rect.top;

    document.addEventListener('mousemove', Draggable.onDrag, false);
    document.addEventListener('mouseup', Draggable.endDrag, false);
  },

  onDrag: function(e) {
    if (!Draggable.el) return;

    var left = e.clientX - Draggable.dx;
    var top = e.clientY - Draggable.dy;

    var docWidth = document.documentElement.clientWidth;
    var docHeight = document.documentElement.clientHeight;
    var elWidth = Draggable.el.offsetWidth;
    var elHeight = Draggable.el.offsetHeight;

    // Constrain to viewport
    if (left < 0) left = 0;
    if (top < 0) top = 0;
    if (left + elWidth > docWidth) left = docWidth - elWidth;
    if (top + elHeight > docHeight) top = docHeight - elHeight;

    Draggable.el.style.left = left + 'px';
    Draggable.el.style.top = top + 'px';
    Draggable.el.style.right = 'auto';
    Draggable.el.style.bottom = 'auto';
  },

  endDrag: function() {
    if (Draggable.el && Draggable.el.id === 'threadWatcher') {
      localStorage.setItem('ashchan-tw-position', JSON.stringify({
        left: Draggable.el.style.left,
        top: Draggable.el.style.top
      }));
    }

    Draggable.el = null;
    document.removeEventListener('mousemove', Draggable.onDrag, false);
    document.removeEventListener('mouseup', Draggable.endDrag, false);
  }
};

// ---- Initialize ----
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', Catalog.init);
} else {
  Catalog.init();
}

// Expose for external use
window.Catalog = Catalog;
window.ThreadWatcher = ThreadWatcher;

})();

