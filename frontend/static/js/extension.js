/**
 * ashchan extension.js
 * Thread page features: reply hiding, filters, backlinks, ID coloring,
 * file info tooltips, quick reply, keyboard navigation, and more.
 *
 * Patterns adapted from OpenYotsuba (BSD 3-clause license).
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
  },
  el: function(tag) { return document.createElement(tag); }
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

// ---- Main Module ----
var Main = {
  board: null,
  tid: null,
  isThread: false,

  init: function() {
    var body = document.body;
    Main.board = body.getAttribute('data-board-slug');
    Main.tid = body.getAttribute('data-thread-id');
    Main.isThread = $.hasClass(body, 'is_thread');
  }
};

// ---- Config (Settings) ----
var Config = {
  // Quotes & Replying
  quotePreview: true,
  backlinks: true,
  inlineQuotes: false,
  
  // Monitoring
  threadWatcher: false,
  threadAutoWatcher: false,
  
  // Filters & Post Hiding
  filter: false,
  replyHiding: true,
  threadHiding: true,
  hideStubs: false,
  
  // Images & Media
  imageHover: false,
  imageExpansion: true,
  fitToScreenExpansion: false,
  revealSpoilers: false,
  noPictures: false,
  embedYouTube: true,
  embedSoundCloud: false,
  
  // Miscellaneous
  linkify: false,
  IDColor: true,
  localTime: true,
  compactThreads: false,
  centeredThreads: false,
  keyBinds: false,
  customCSS: false,
  
  // Disable all
  disableAll: false,

  load: function() {
    if (!UA.hasWebStorage) return;
    var saved = localStorage.getItem('ashchan-settings');
    if (saved) {
      try {
        var parsed = JSON.parse(saved);
        // Merge saved settings with defaults
        for (var key in parsed) {
          if (Config.hasOwnProperty(key) && typeof Config[key] !== 'function') {
            Config[key] = parsed[key];
          }
        }
      } catch (e) {
        console.error('Failed to load settings:', e);
      }
    }
  },

  save: function() {
    if (!UA.hasWebStorage) return;
    var settings = {};
    for (var key in Config) {
      if (Config.hasOwnProperty(key) && typeof Config[key] !== 'function') {
        settings[key] = Config[key];
      }
    }
    localStorage.setItem('ashchan-settings', JSON.stringify(settings));
  },
  
  toURL: function() {
    var cfg = {};
    cfg.settings = localStorage.getItem('ashchan-settings');
    if (localStorage.getItem('ashchan-filters')) {
      cfg.filters = localStorage.getItem('ashchan-filters');
    }
    if (localStorage.getItem('ashchan-css')) {
      cfg.css = localStorage.getItem('ashchan-css');
    }
    return encodeURIComponent(JSON.stringify(cfg));
  }
};

// ---- Reply Hiding ----
var ReplyHiding = {
  hidden: {},

  init: function() {
    if (!Config.replyHiding || !UA.hasWebStorage) return;
    
    var key = 'ashchan-hidden-replies-' + Main.board;
    var saved = localStorage.getItem(key);
    if (saved) {
      try {
        ReplyHiding.hidden = JSON.parse(saved);
      } catch (e) {}
    }
  },

  save: function() {
    if (!UA.hasWebStorage) return;
    var key = 'ashchan-hidden-replies-' + Main.board;
    if (Object.keys(ReplyHiding.hidden).length > 0) {
      localStorage.setItem(key, JSON.stringify(ReplyHiding.hidden));
    } else {
      localStorage.removeItem(key);
    }
  },

  toggle: function(pid) {
    if (ReplyHiding.hidden[pid]) {
      delete ReplyHiding.hidden[pid];
      ReplyHiding.show(pid);
    } else {
      ReplyHiding.hidden[pid] = Date.now();
      ReplyHiding.hide(pid);
    }
    ReplyHiding.save();
  },

  hide: function(pid) {
    var post = $.id('p' + pid);
    if (!post) return;

    var stub = $.el('div');
    stub.id = 'stub-' + pid;
    stub.className = 'stub';
    stub.innerHTML = '<a href="#" data-cmd="show-reply" data-pid="' + pid + '">' +
      '[+] Post No.' + pid + ' hidden</a>';
    
    post.style.display = 'none';
    post.parentNode.insertBefore(stub, post);
  },

  show: function(pid) {
    var post = $.id('p' + pid);
    var stub = $.id('stub-' + pid);
    
    if (post) post.style.display = '';
    if (stub) stub.remove();
  },

  applyAll: function() {
    for (var pid in ReplyHiding.hidden) {
      ReplyHiding.hide(pid);
    }
  }
};

// ---- ID Color ----
var IDColor = {
  enabled: true,
  cache: {},

  init: function() {
    IDColor.enabled = Config.IDColor;
  },

  apply: function(el) {
    if (!IDColor.enabled || !el) return;

    var id = el.textContent;
    if (!id || id === 'Heaven') return;

    if (!IDColor.cache[id]) {
      IDColor.cache[id] = IDColor.generate(id);
    }

    var colors = IDColor.cache[id];
    el.style.backgroundColor = colors.bg;
    el.style.color = colors.fg;
    el.style.padding = '0 3px';
    el.style.borderRadius = '3px';
  },

  generate: function(id) {
    var hash = 0;
    for (var i = 0; i < id.length; i++) {
      hash = ((hash << 5) - hash) + id.charCodeAt(i);
      hash = hash & hash;
    }

    // Generate HSL color from hash
    var h = Math.abs(hash) % 360;
    var s = 50 + (Math.abs(hash >> 8) % 30);
    var l = 60 + (Math.abs(hash >> 16) % 20);

    var bg = 'hsl(' + h + ',' + s + '%,' + l + '%)';
    var fg = l > 70 ? '#000' : '#fff';

    return { bg: bg, fg: fg };
  }
};

// ---- Backlinks ----
var Backlinks = {
  init: function() {
    if (!Config.backlinks || !Main.isThread) return;
    
    var posts = $.qsa('.post');
    for (var i = 0; i < posts.length; i++) {
      Backlinks.parse(posts[i]);
    }
  },

  parse: function(post) {
    var pid = post.id.replace('p', '');
    var msg = $.qs('.postMessage', post);
    if (!msg) return;

    var quotelinks = $.qsa('.quotelink', msg);
    for (var i = 0; i < quotelinks.length; i++) {
      var link = quotelinks[i];
      var href = link.getAttribute('href') || '';
      var match = href.match(/#p(\d+)/);
      
      if (!match) continue;
      
      var targetPid = match[1];
      var targetPost = $.id('p' + targetPid);
      
      if (!targetPost) continue;
      
      // Add OP marker
      if (targetPid === Main.tid) {
        if (link.textContent.indexOf('(OP)') === -1) {
          link.textContent += ' (OP)';
        }
      }

      // Create or get backlinks container
      var blContainer = $.id('bl_' + targetPid);
      if (!blContainer) {
        blContainer = $.el('div');
        blContainer.id = 'bl_' + targetPid;
        blContainer.className = 'backlink';
        targetPost.appendChild(blContainer);
      }

      // Check if backlink already exists
      if ($.qs('a[href="#p' + pid + '"]', blContainer)) continue;

      // Add backlink
      var bl = $.el('span');
      bl.innerHTML = '<a href="#p' + pid + '" class="quotelink">&gt;&gt;' + pid + '</a> ';
      blContainer.appendChild(bl);
    }
  }
};

// ---- Quote Preview ----
var QuotePreview = {
  previewEl: null,
  timeout: null,

  init: function() {
    if (!Config.quotePreview) return;

    $.on(document, 'mouseover', QuotePreview.onMouseOver);
    $.on(document, 'mouseout', QuotePreview.onMouseOut);
  },

  onMouseOver: function(e) {
    var target = e.target;
    if (!$.hasClass(target, 'quotelink') && !$.hasClass(target, 'quoteLink')) return;

    clearTimeout(QuotePreview.timeout);
    QuotePreview.timeout = setTimeout(function() {
      QuotePreview.show(target);
    }, 100);
  },

  onMouseOut: function(e) {
    var target = e.target;
    if (!$.hasClass(target, 'quotelink') && !$.hasClass(target, 'quoteLink')) return;

    clearTimeout(QuotePreview.timeout);
    QuotePreview.hide();
  },

  show: function(link) {
    var href = link.getAttribute('href') || '';
    var match = href.match(/#p(\d+)/);
    if (!match) return;

    var postId = match[1];
    var post = $.id('p' + postId);
    if (!post) return;

    QuotePreview.hide();

    var preview = $.el('div');
    preview.className = 'preview posthover';
    preview.innerHTML = post.innerHTML;
    preview.id = 'quote-preview';

    var rect = link.getBoundingClientRect();
    preview.style.position = 'absolute';
    preview.style.left = (rect.right + window.scrollX + 5) + 'px';
    preview.style.top = (rect.top + window.scrollY) + 'px';
    preview.style.zIndex = '100';

    document.body.appendChild(preview);
    QuotePreview.previewEl = preview;

    // Adjust if off-screen
    var previewRect = preview.getBoundingClientRect();
    if (previewRect.right > window.innerWidth) {
      preview.style.left = (rect.left + window.scrollX - previewRect.width - 5) + 'px';
    }
    if (previewRect.bottom > window.innerHeight) {
      preview.style.top = (window.innerHeight + window.scrollY - previewRect.height - 10) + 'px';
    }
  },

  hide: function() {
    if (QuotePreview.previewEl) {
      QuotePreview.previewEl.remove();
      QuotePreview.previewEl = null;
    }
  }
};

// ---- Image Hover ----
var ImageHover = {
  previewEl: null,

  init: function() {
    if (!Config.imageHover) return;

    $.on(document, 'mouseover', ImageHover.onMouseOver);
    $.on(document, 'mouseout', ImageHover.onMouseOut);
    $.on(document, 'mousemove', ImageHover.onMouseMove);
  },

  onMouseOver: function(e) {
    var target = e.target;
    if (target.tagName !== 'IMG') return;
    
    var thumb = target.closest('.fileThumb');
    if (!thumb) return;

    var fullUrl = thumb.href;
    if (!fullUrl) return;

    // Don't hover on webm/mp4
    if (/\.(webm|mp4)$/i.test(fullUrl)) return;

    ImageHover.show(fullUrl, e);
  },

  onMouseOut: function(e) {
    var target = e.target;
    if (target.tagName !== 'IMG') return;

    ImageHover.hide();
  },

  onMouseMove: function(e) {
    if (!ImageHover.previewEl) return;

    var x = e.clientX + 10;
    var y = e.clientY + 10;

    // Keep on screen
    var maxX = window.innerWidth - ImageHover.previewEl.offsetWidth - 10;
    var maxY = window.innerHeight - ImageHover.previewEl.offsetHeight - 10;

    if (x > maxX) x = e.clientX - ImageHover.previewEl.offsetWidth - 10;
    if (y > maxY) y = e.clientY - ImageHover.previewEl.offsetHeight - 10;

    ImageHover.previewEl.style.left = x + 'px';
    ImageHover.previewEl.style.top = y + 'px';
  },

  show: function(url, e) {
    ImageHover.hide();

    var img = $.el('img');
    img.id = 'image-hover';
    img.src = url;
    img.style.position = 'fixed';
    img.style.maxWidth = '80vw';
    img.style.maxHeight = '80vh';
    img.style.zIndex = '9999';
    img.style.pointerEvents = 'none';
    img.style.boxShadow = '0 0 10px rgba(0,0,0,0.5)';

    document.body.appendChild(img);
    ImageHover.previewEl = img;
    ImageHover.onMouseMove(e);
  },

  hide: function() {
    if (ImageHover.previewEl) {
      ImageHover.previewEl.remove();
      ImageHover.previewEl = null;
    }
  }
};

// ---- Linkify ----
var Linkify = {
  urlPattern: /\b(https?:\/\/[^\s<>\[\]"]+)/gi,

  init: function() {
    if (!Config.linkify) return;

    var posts = $.qsa('.postMessage');
    for (var i = 0; i < posts.length; i++) {
      Linkify.exec(posts[i]);
    }
  },

  exec: function(msg) {
    if (!msg) return;

    var walker = document.createTreeWalker(msg, NodeFilter.SHOW_TEXT, null, false);
    var textNodes = [];
    
    while (walker.nextNode()) {
      // Skip nodes that are already inside links
      if (walker.currentNode.parentNode.tagName === 'A') continue;
      textNodes.push(walker.currentNode);
    }

    for (var i = 0; i < textNodes.length; i++) {
      var node = textNodes[i];
      var text = node.textContent;
      
      if (!Linkify.urlPattern.test(text)) continue;
      Linkify.urlPattern.lastIndex = 0;

      var frag = document.createDocumentFragment();
      var lastIndex = 0;
      var match;

      while ((match = Linkify.urlPattern.exec(text)) !== null) {
        // Add text before match
        if (match.index > lastIndex) {
          frag.appendChild(document.createTextNode(text.substring(lastIndex, match.index)));
        }

        // Add link
        var link = $.el('a');
        link.href = match[1];
        link.textContent = match[1];
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        frag.appendChild(link);

        lastIndex = Linkify.urlPattern.lastIndex;
      }

      // Add remaining text
      if (lastIndex < text.length) {
        frag.appendChild(document.createTextNode(text.substring(lastIndex)));
      }

      node.parentNode.replaceChild(frag, node);
    }
  }
};

// ---- Local Time ----
var LocalTime = {
  weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],

  init: function() {
    if (!Config.localTime) return;

    var dates = $.qsa('.dateTime');
    for (var i = 0; i < dates.length; i++) {
      LocalTime.convert(dates[i]);
    }
  },

  convert: function(el) {
    var utc = el.getAttribute('data-utc');
    if (!utc) return;

    var date = new Date(parseInt(utc, 10) * 1000);
    var formatted = LocalTime.format(date);
    
    el.textContent = formatted;
    el.title = 'UTC: ' + new Date(parseInt(utc, 10) * 1000).toUTCString();
  },

  format: function(date) {
    var pad = function(n) { return n < 10 ? '0' + n : n; };
    
    return pad(date.getMonth() + 1) + '/' +
      pad(date.getDate()) + '/' +
      ('' + date.getFullYear()).slice(-2) + '(' +
      LocalTime.weekdays[date.getDay()] + ')' +
      pad(date.getHours()) + ':' +
      pad(date.getMinutes()) + ':' +
      pad(date.getSeconds());
  }
};

// ---- Settings Menu ----
var SettingsMenu = {
  // [Name, Description, available on mobile?]
  options: {
    'Quotes &amp; Replying': {
      quotePreview: ['Quote preview', 'Show post when mousing over post links', true],
      backlinks: ['Backlinks', 'Show who has replied to a post', true],
      inlineQuotes: ['Inline quote links', 'Clicking quote links will inline expand the quoted post', false]
    },
    'Monitoring': {
      threadWatcher: ['Thread Watcher', 'Keep track of threads you\'re watching and see when they receive new posts', true],
      threadAutoWatcher: ['Auto-watch threads you create', '', true]
    },
    'Filters &amp; Post Hiding': {
      filter: ['Filter and highlight specific threads/posts', 'Enable pattern-based filters', false],
      replyHiding: ['Reply hiding', 'Hide individual replies by clicking the minus button', true],
      threadHiding: ['Thread hiding', 'Hide entire threads by clicking the minus button', true],
      hideStubs: ['Hide thread stubs', 'Don\'t display stubs of hidden threads', false]
    },
    'Images &amp; Media': {
      imageExpansion: ['Image expansion', 'Enable inline image expansion, limited to browser width', true],
      fitToScreenExpansion: ['Fit expanded images to screen', 'Limit expanded images to both browser width and height', false],
      imageHover: ['Image hover', 'Mouse over images to view full size, limited to browser size', false],
      revealSpoilers: ['Don\'t spoiler images', 'Show image thumbnail instead of spoiler placeholders', true],
      noPictures: ['Hide thumbnails', 'Don\'t display thumbnails while browsing', true],
      embedYouTube: ['Embed YouTube links', 'Embed YouTube player into replies', false],
      embedSoundCloud: ['Embed SoundCloud links', 'Embed SoundCloud player into replies', false]
    },
    'Miscellaneous': {
      linkify: ['Linkify URLs', 'Make user-posted links clickable', true],
      IDColor: ['Color user IDs', 'Assign unique colors to user IDs on boards that use them', true],
      localTime: ['Convert dates to local time', 'Convert server time to your local time', true],
      compactThreads: ['Force long posts to wrap', 'Long posts will wrap at 75% browser width', false],
      centeredThreads: ['Center threads', 'Align threads to the center of page', false],
      keyBinds: ['Use keyboard shortcuts', 'Enable handy keyboard shortcuts for common actions', false]
    }
  },

  toggle: function() {
    if ($.id('settingsMenu')) {
      SettingsMenu.close();
    } else {
      SettingsMenu.open();
    }
  },

  open: function() {
    var cnt, html, cat, opts, key, categories;

    cnt = document.createElement('div');
    cnt.id = 'settingsMenu';
    cnt.className = 'UIPanel';

    html = '<div class="extPanel reply"><div class="panelHeader">Settings'
      + '<span class="panelCtrl"><a href="#" class="pointer" data-cmd="settings-close" title="Close">[X]</a>'
      + '</span></div>';

    html += '<ul class="settings-list">';

    categories = SettingsMenu.options;

    for (cat in categories) {
      opts = categories[cat];
      html += '<li class="settings-cat-lbl"><strong>' + cat.replace(/&amp;/g, '&') + '</strong></li>';
      html += '<ul class="settings-cat">';
      
      for (key in opts) {
        html += '<li><label><input type="checkbox" class="menuOption" data-option="'
          + key + '"' + (Config[key] ? ' checked="checked"' : '')
          + '> ' + opts[key][0] + '</label>';
        if (opts[key][1]) {
          html += '<div class="settings-tip">' + opts[key][1] + '</div>';
        }
        html += '</li>';
      }
      html += '</ul>';
    }

    // Disable all option
    html += '</ul><ul class="settings-list"><li class="settings-off">'
      + '<label title="Completely disable the native extension (overrides any checked boxes)">'
      + '<input type="checkbox" class="menuOption" data-option="disableAll"'
      + (Config.disableAll ? ' checked="checked"' : '')
      + '> Disable the native extension</label></li></ul>';

    html += '<div class="center" style="padding: 10px;">'
      + '<button data-cmd="settings-export">Export Settings</button> '
      + '<button data-cmd="settings-save">Save Settings</button></div>';
    html += '</div>';

    cnt.innerHTML = html;
    cnt.addEventListener('click', SettingsMenu.onClick, false);
    document.body.appendChild(cnt);

    // Focus first option
    var firstOpt = $.qs('.menuOption', cnt);
    if (firstOpt) firstOpt.focus();
  },

  close: function() {
    var el = $.id('settingsMenu');
    if (el) {
      el.removeEventListener('click', SettingsMenu.onClick, false);
      document.body.removeChild(el);
    }
  },

  save: function() {
    var options = $.id('settingsMenu').getElementsByClassName('menuOption');
    
    for (var i = 0; i < options.length; i++) {
      var el = options[i];
      var key = el.getAttribute('data-option');
      Config[key] = el.checked;
    }

    Config.save();
    SettingsMenu.close();
    
    // Reload to apply settings
    location.reload();
  },
  
  showExport: function() {
    var cnt, str, el;
    
    if ($.id('exportSettings')) {
      return;
    }
    
    str = location.href.replace(location.hash, '').replace(/^http:/, 'https:') + '#cfg=' + Config.toURL();
    
    cnt = document.createElement('div');
    cnt.id = 'exportSettings';
    cnt.className = 'UIPanel';
    cnt.setAttribute('data-cmd', 'export-close');
    cnt.innerHTML = '<div class="extPanel reply"><div class="panelHeader">Export Settings'
      + '<span class="panelCtrl"><a href="#" data-cmd="export-close" class="pointer" title="Close">[X]</a></span></div>'
      + '<p class="center">Copy and save the URL below, and visit it from another '
      + 'browser or computer to restore your extension settings.</p>'
      + '<p class="center"><input class="export-field" type="text" readonly="readonly" value="' + str + '"></p>'
      + '<p style="margin-top:15px" class="center">Alternatively, you can drag the link below into your '
      + 'bookmarks bar and click it to restore.</p>'
      + '<p class="center">[<a target="_blank" href="' + str + '">Restore ashchan Settings</a>]</p></div>';

    document.body.appendChild(cnt);
    cnt.addEventListener('click', SettingsMenu.onExportClick, false);
    el = $.qs('.export-field', cnt);
    if (el) {
      el.focus();
      el.select();
    }
  },
  
  closeExport: function() {
    var cnt = $.id('exportSettings');
    if (cnt) {
      cnt.removeEventListener('click', SettingsMenu.onExportClick, false);
      document.body.removeChild(cnt);
    }
  },
  
  onExportClick: function(e) {
    var cmd = e.target.getAttribute('data-cmd');
    if (cmd === 'export-close' || e.target.id === 'exportSettings') {
      e.preventDefault();
      SettingsMenu.closeExport();
    }
  },

  onClick: function(e) {
    var t = e.target;
    var cmd = t.getAttribute('data-cmd');

    if (cmd === 'settings-close') {
      e.preventDefault();
      SettingsMenu.close();
    } else if (cmd === 'settings-save') {
      e.preventDefault();
      SettingsMenu.save();
    } else if (cmd === 'settings-export') {
      e.preventDefault();
      SettingsMenu.showExport();
    } else if (t.id === 'settingsMenu') {
      // Clicked on overlay background
      e.preventDefault();
      SettingsMenu.close();
    }
  }
};

// ---- Post Menu ----
var PostMenu = {
  activeBtn: null,
  activeMenu: null,

  init: function() {
    $.on(document, 'click', PostMenu.onClick);
  },

  onClick: function(e) {
    var target = e.target;

    // Close menu on outside click
    if (PostMenu.activeMenu && !PostMenu.activeMenu.contains(target)) {
      PostMenu.close();
    }

    // Handle menu button click
    if ($.hasClass(target, 'postMenuBtn')) {
      e.preventDefault();
      var pid = target.closest('.post').id.replace('p', '');
      PostMenu.open(target, pid);
      return;
    }

    // Handle menu item clicks
    if (target.hasAttribute('data-cmd')) {
      e.preventDefault();
      var cmd = target.getAttribute('data-cmd');
      var pid = target.getAttribute('data-pid');

      switch (cmd) {
        case 'hide-reply':
          ReplyHiding.toggle(pid);
          break;
        case 'show-reply':
          ReplyHiding.toggle(pid);
          break;
        case 'highlight-id':
          IDHighlight.toggle(target.getAttribute('data-id'));
          break;
        case 'filter-id':
          Filter.addID(target.getAttribute('data-id'));
          break;
      }

      PostMenu.close();
    }
  },

  open: function(btn, pid) {
    if (PostMenu.activeBtn === btn) {
      PostMenu.close();
      return;
    }

    PostMenu.close();

    var post = $.id('p' + pid);
    var isHidden = ReplyHiding.hidden[pid];
    
    // Get user ID if present
    var uidEl = $.qs('.posteruid .hand', post);
    var uid = uidEl ? uidEl.textContent : null;

    var html = '<ul class="post-menu-list">';
    html += '<li data-cmd="' + (isHidden ? 'show' : 'hide') + '-reply" data-pid="' + pid + '">' +
      (isHidden ? 'Show' : 'Hide') + ' reply</li>';
    
    if (uid) {
      html += '<li data-cmd="highlight-id" data-id="' + uid + '">Highlight ID</li>';
      html += '<li data-cmd="filter-id" data-id="' + uid + '">Filter ID</li>';
    }
    
    html += '</ul>';

    var menu = $.el('div');
    menu.id = 'post-menu';
    menu.className = 'post-menu';
    menu.innerHTML = html;

    var rect = btn.getBoundingClientRect();
    menu.style.top = (rect.bottom + 3 + window.pageYOffset) + 'px';
    menu.style.left = (rect.left + window.pageXOffset) + 'px';

    document.body.appendChild(menu);

    $.addClass(btn, 'menuOpen');
    PostMenu.activeBtn = btn;
    PostMenu.activeMenu = menu;
  },

  close: function() {
    if (PostMenu.activeMenu) {
      PostMenu.activeMenu.remove();
      PostMenu.activeMenu = null;
    }
    if (PostMenu.activeBtn) {
      $.removeClass(PostMenu.activeBtn, 'menuOpen');
      PostMenu.activeBtn = null;
    }
  }
};

// ---- ID Highlight ----
var IDHighlight = {
  highlighted: {},

  toggle: function(id) {
    if (IDHighlight.highlighted[id]) {
      IDHighlight.unhighlight(id);
      delete IDHighlight.highlighted[id];
    } else {
      IDHighlight.highlight(id);
      IDHighlight.highlighted[id] = true;
    }
  },

  highlight: function(id) {
    var posts = $.qsa('.posteruid .hand');
    for (var i = 0; i < posts.length; i++) {
      if (posts[i].textContent === id) {
        var post = posts[i].closest('.post');
        if (post) $.addClass(post, 'highlight');
      }
    }
  },

  unhighlight: function(id) {
    var posts = $.qsa('.posteruid .hand');
    for (var i = 0; i < posts.length; i++) {
      if (posts[i].textContent === id) {
        var post = posts[i].closest('.post');
        if (post) $.removeClass(post, 'highlight');
      }
    }
  }
};

// ---- Filter ----
var Filter = {
  filters: [],

  init: function() {
    if (!Config.filter || !UA.hasWebStorage) return;

    var saved = localStorage.getItem('ashchan-filters');
    if (saved) {
      try {
        Filter.filters = JSON.parse(saved);
      } catch (e) {}
    }

    Filter.apply();
  },

  save: function() {
    localStorage.setItem('ashchan-filters', JSON.stringify(Filter.filters));
  },

  addID: function(id) {
    Filter.filters.push({
      type: 'id',
      pattern: id,
      active: true,
      hide: true
    });
    Filter.save();
    Filter.apply();
  },

  apply: function() {
    var posts = $.qsa('.post');
    for (var i = 0; i < posts.length; i++) {
      var post = posts[i];
      
      for (var j = 0; j < Filter.filters.length; j++) {
        var filter = Filter.filters[j];
        if (!filter.active) continue;

        if (filter.type === 'id') {
          var uidEl = $.qs('.posteruid .hand', post);
          if (uidEl && uidEl.textContent === filter.pattern) {
            if (filter.hide) {
              post.style.display = 'none';
            }
          }
        }
      }
    }
  }
};

// ---- Parser (Post Processing) ----
var Parser = {
  init: function() {
    var posts = $.qsa('.post');
    for (var i = 0; i < posts.length; i++) {
      Parser.parsePost(posts[i]);
    }
  },

  parsePost: function(post) {
    // Add post menu button
    var postInfo = $.qs('.postInfo', post);
    if (postInfo && !$.qs('.postMenuBtn', postInfo)) {
      var btn = $.el('a');
      btn.href = '#';
      btn.className = 'postMenuBtn';
      btn.title = 'Post menu';
      btn.textContent = '▶';
      postInfo.appendChild(btn);
    }

    // Apply ID colors
    var uidEl = $.qs('.posteruid .hand', post);
    if (uidEl) {
      IDColor.apply(uidEl);
    }
  }
};

// ---- Keyboard Navigation ----
var KeyBinds = {
  init: function() {
    $.on(document, 'keyup', KeyBinds.onKeyUp);
  },

  onKeyUp: function(e) {
    // Skip if typing in input
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    switch (e.keyCode) {
      case 82: // R - Update thread
        if (!e.shiftKey && Main.isThread) {
          location.reload();
        }
        break;
      case 81: // Q - Open quick reply
        // Quick reply implementation would go here
        break;
    }
  }
};

// ---- Initialize ----
function init() {
  Main.init();
  Config.load();
  
  // Settings button - multiple possible IDs from layout
  var settingsLinks = [
    $.id('settingsWindowLink'),
    $.id('settingsWindowLinkBot'),
    $.id('settingsWindowLinkMobile'),
    $.id('settingsBtn')
  ];
  
  settingsLinks.forEach(function(el) {
    if (el) {
      $.on(el, 'click', function(e) {
        e.preventDefault();
        SettingsMenu.toggle();
      });
    }
  });
  
  // Global click handler for commands
  $.on(document, 'click', function(e) {
    var cmd = e.target.getAttribute('data-cmd');
    if (cmd === 'settings-toggle') {
      e.preventDefault();
      SettingsMenu.toggle();
    }
  });
  
  // Features that work on all pages
  if (Config.IDColor) {
    IDColor.init();
  }
  
  if (Config.linkify) {
    Linkify.init();
  }
  
  if (Config.localTime) {
    LocalTime.init();
  }
  
  // Thread-specific features
  if (Main.isThread) {
    ReplyHiding.init();
    ReplyHiding.applyAll();
    Backlinks.init();
    
    if (Config.quotePreview) {
      QuotePreview.init();
    }
    
    if (Config.imageHover) {
      ImageHover.init();
    }
    
    if (Config.filter) {
      Filter.init();
    }
    
    Parser.init();
    PostMenu.init();
    KeyBinds.init();
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

// Expose for external use
window.ReplyHiding = ReplyHiding;
window.IDColor = IDColor;
window.Filter = Filter;
window.Config = Config;
window.SettingsMenu = SettingsMenu;

})();
