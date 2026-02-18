/**
 * Admin Core JavaScript
 * Ported from OpenYotsuba/admin/js/admincore.js
 * 
 * Provides common functionality for staff interface
 */

var AdminCore = {
  autoload: function() {
    if (document.body.hasAttribute('data-tips')) {
      Tip.init();
    }
  },

  onToggleCatClick: function(btn) {
    var tgt, cat;

    if (cat = btn.getAttribute('data-cat')) {
      tgt = $.id('js-' + cat);

      if (btn.hasAttribute('data-open')) {
        btn.removeAttribute('data-open');
        btn.textContent = 'Show';
      }
      else {
        btn.setAttribute('data-open', 1);
        btn.textContent = 'Hide';
      }
    }
    else {
      btn.classList.add('hidden');
      tgt = btn.previousElementSibling;
    }

    tgt.classList.toggle('hidden');
  }
};

document.addEventListener('DOMContentLoaded', AdminCore.autoload, false);

/**
 * Keybinds
 */
var Keybinds = {};

Keybinds.init = function(main) {
  this.main = main;
  this.map = {};
  this.labels = {};
  main.clickCommands['prompt-key'] = Keybinds.showPrompt;
};

Keybinds.add = function(map) {
  var label, code;

  for (code in map) {
    this.map[code] = map[code][0];
    if (label = map[code][1]) {
      this.labels[code] = label;
    }
  }
};

Keybinds.enable = function() {
  $.on(document, 'keydown', Keybinds.handler);
};

Keybinds.disable = function() {
  $.off(document, 'keydown', Keybinds.handler);
};

Keybinds.handler = function(e) {
  var code = e.keyCode;
  
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
    return;
  }

  if (Keybinds.map[code]) {
    e.preventDefault();
    Keybinds.map[code]();
  }
};

Keybinds.showPrompt = function(button) {
  var html;

  Keybinds.disable();

  html = '<div class="panel-content center">Valid keys are ' +
    '<kbd>Esc</kbd>, <kbd>&#8592;</kbd>, <kbd>&#8594;</kbd>, <kbd>A-Z</kbd>' +
    '</div>';

  Keybinds.main.showPanel('key-prompt', html, 'Press a key',
    {
      'data-id': button.getAttribute('data-id'),
      'data-close-cb': 'KeyPrompt'
    }
  );

  $.on(document, 'keydown', Keybinds.resolvePrompt);
};

Keybinds.codeToKey = function(code) {
  var key = null;

  if (code == 8) {
    return null;
  }

  if (code == 27) {
    key = 'Esc';
  }
  else if (code == 37) {
    key = '&#8592;';
  }
  else if (code == 39) {
    key = '&#8594;';
  }
  else if (code >= 65 && code <= 90) {
    key = String.fromCharCode(code).toUpperCase();
  }

  return key;
};

Keybinds.resolvePrompt = function(e) {
  var key, panel, id, funk, map, labels, kbd;

  key = Keybinds.codeToKey(e.keyCode);

  if (!key) {
    return;
  }

  panel = $.id('panel-key-prompt');
  id = +$.tag('div', panel)[0].getAttribute('data-id');
  funk = Keybinds.main.clickCommands['save-key'];

  map = Keybinds.map;
  labels = Keybinds.labels;

  for (var code in labels) {
    if (labels[code][0] == key) {
      Keybinds.main.error('Key already bound');
      return;
    }
  }

  $.off(document, 'keydown', Keybinds.resolvePrompt);

  Keybinds.main.hidePanel();

  if (funk) {
    funk(id, e.keyCode);
  }

  Keybinds.enable();
};

/**
 * Tips (Tooltips)
 */
var Tip = {};

Tip.init = function() {
  this.el = null;
  this.timeout = null;
  this.delay = 500;

  $.on(document, 'mouseover', Tip.onMouseOver);
  $.on(document, 'mouseout', Tip.onMouseOut);
};

Tip.onMouseOver = function(e) {
  var tgt = e.target;
  var tip = tgt.getAttribute('data-tip');

  if (!tip) {
    return;
  }

  var delay = +tgt.getAttribute('data-tip-delay') || Tip.delay;

  Tip.timeout = setTimeout(function() {
    Tip.show(tip, tgt);
  }, delay);
};

Tip.onMouseOut = function(e) {
  if (Tip.timeout) {
    clearTimeout(Tip.timeout);
    Tip.timeout = null;
  }

  Tip.hide();
};

Tip.show = function(content, target) {
  if (Tip.el) {
    Tip.hide();
  }

  Tip.el = $.el('div');
  Tip.el.id = 'tooltip';
  Tip.el.innerHTML = content;

  document.body.appendChild(Tip.el);

  Tip.position(target);
};

Tip.hide = function() {
  if (Tip.el) {
    document.body.removeChild(Tip.el);
    Tip.el = null;
  }
};

Tip.position = function(target) {
  var rect = target.getBoundingClientRect();
  var tipRect = Tip.el.getBoundingClientRect();

  var top = rect.bottom + 5;
  var left = rect.left + (rect.width / 2) - (tipRect.width / 2);

  Tip.el.style.top = top + 'px';
  Tip.el.style.left = left + 'px';
};

/**
 * Utility functions (ported from OpenYotsuba/js/helpers.js)
 */
var $ = {
  id: function(id) {
    return document.getElementById(id);
  },

  cls: function(cls, ctx) {
    return (ctx || document).getElementsByClassName(cls);
  },

  tag: function(tag, ctx) {
    return (ctx || document).getElementsByTagName(tag);
  },

  el: function(tag) {
    return document.createElement(tag);
  },

  on: function(el, ev, fn) {
    el.addEventListener(ev, fn, false);
  },

  off: function(el, ev, fn) {
    el.removeEventListener(ev, fn, false);
  },

  addClass: function(el, cls) {
    el.classList.add(cls);
  },

  removeClass: function(el, cls) {
    el.classList.remove(cls);
  },

  hasClass: function(el, cls) {
    return el.classList.contains(cls);
  },

  toggleClass: function(el, cls) {
    el.classList.toggle(cls);
  },

  prevent: function(e) {
    if (e) {
      e.preventDefault();
    }
    return false;
  },

  docEl: document.documentElement,

  visibilitychange: (function() {
    if ('hidden' in document) {
      return 'visibilitychange';
    }
    return null;
  })()
};

// Export for use in other scripts
window.AdminCore = AdminCore;
window.Keybinds = Keybinds;
window.Tip = Tip;
window.$ = $;
