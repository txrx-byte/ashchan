/**
 * Report Queue JavaScript
 * Ported from OpenYotsuba/reports/js/031d60ebf8d41a9f/reportqueue.js
 * 
 * Handles the report queue interface for janitors and mods
 */

var RQ = {
  HIGHLIGHT_THRES: 500,
  GLOBAL_THRES: 1500,
  MODE_REPORTS: 0,
  MODE_CONTEXT: 1,
  
  syncDelay: 15000,
  syncInterval: null,
  syncEnabled: false,
  
  init: function() {
    this.settings = this.getSettings();
    this.applyCustomCSS();
    
    this.board = null;
    this.mode = this.MODE_REPORTS;
    this.cleared_only = false;
    this.extraFetch = null;
    this.showOld = false;
    this.activeFilter = null;
    
    this.xhr = {};
    this.threadCache = {};
    this.templateCache = null;
    
    this.focusedReport = null;
    this.focusedPost = null;
    
    this.clickCommands = {
      'toggle-cleared': RQ.onToggleClearedClick,
      'switch-board': RQ.onBoardLinkClick,
      'refresh': RQ.refreshReports,
      'show-settings': RQ.showSettings,
      'toggle-dt': RQ.onToggleDTClick,
      'clear': RQ.onClearClick,
      'delete': RQ.onDeleteClick,
      'ban-request': RQ.onBanRequestClick,
      'show-ban-requests': RQ.onShowBanRequests
    };
    
    this.panelStack = null;
    this.currentCount = 0;
    this.searchTimeout = null;
    
    this.resolveQuery();
    Tip.init();
    
    $.on(document, 'click', RQ.onClick);
    $.on(document, 'DOMContentLoaded', RQ.run);
    $.on(window, 'hashchange', RQ.onHashChange);
  },
  
  run: function() {
    $.off(document, 'DOMContentLoaded', RQ.run);
    
    if (localStorage.getItem('dark-theme')) {
      $.addClass($.docEl, 'dark-theme');
      $.id('cfg-cb-dt').checked = true;
    }
    
    $.on($.id('filter-form'), 'submit', RQ.onFilterSubmit);
    $.on($.id('filter-form'), 'reset', RQ.onFilterReset);
    $.on($.id('search-box'), 'focus', RQ.onSearchFocus);
    $.on($.id('search-box'), 'keydown', RQ.onSearchKeyDown);
    
    $.on($.id('cfg-btn'), 'focus', RQ.onCfgBtnFocusChange);
    $.on($.id('cfg-btn'), 'blur', RQ.onCfgBtnFocusChange);
    
    RQ.panelStack = $.id('panel-stack');
    
    if (RQ.cleared_only) {
      $.addClass($.id('cleared-btn'), 'active');
    }
    
    RQ.showReports(RQ.board, RQ.cleared_only, RQ.extraFetch);
  },
  
  resolveQuery: function() {
    var hash = window.location.hash;
    if (hash) {
      var params = hash.substr(1).split('&');
      for (var i = 0; i < params.length; i++) {
        var parts = params[i].split('=');
        if (parts[0] === 'board') {
          this.board = parts[1];
        } else if (parts[0] === 'cleared' && parts[1] === '1') {
          this.cleared_only = true;
        }
      }
    }
  },
  
  showReports: function(board, cleared, extra) {
    var self = this;
    var url = RQ_CONFIG.apiBase + '/data?page=1';
    
    if (board) {
      url += '&board=' + encodeURIComponent(board);
    }
    if (cleared) {
      url += '&cleared=1';
    }
    
    if (this.xhr.reports) {
      this.xhr.reports.abort();
    }
    
    this.xhr.reports = ajaxGet(url, function(data) {
      self.renderReports(data);
    });
  },
  
  renderReports: function(data) {
    var container = $.id('items');
    if (!container) return;
    
    container.innerHTML = '';
    
    if (!data.reports || data.reports.length === 0) {
      container.innerHTML = '<div class="no-reports">No reports found</div>';
      return;
    }
    
    for (var i = 0; i < data.reports.length; i++) {
      var report = data.reports[i];
      var el = this.createReportElement(report);
      container.appendChild(el);
    }
    
    this.currentCount = data.total;
  },
  
  createReportElement: function(report) {
    var div = $.el('div');
    div.className = 'report-item';
    div.setAttribute('data-id', report.id);
    
    if (report.weight >= this.HIGHLIGHT_THRES) {
      $.addClass(div, 'highlighted');
    }
    if (report.weight >= this.GLOBAL_THRES) {
      $.addClass(div, 'unlocked');
    }
    
    var isThread = report.is_thread || (report.resto === 0);
    
    div.innerHTML = 
      '<div class="report-header">' +
        '<span class="report-board">/' + report.board + '/</span>' +
        '<span class="report-post">No. <a href="/' + report.board + '/thread/' + report.no + '" target="_blank">' + report.no + '</a></span>' +
        '<span class="report-weight">Weight: ' + report.weight + ' (' + report.count + ' reports)</span>' +
      '</div>' +
      '<div class="report-content">' +
        (report.post ? this.formatPostContent(report.post) : '') +
      '</div>' +
      '<div class="report-meta">' +
        '<span>Category: ' + (report.cats || 'Unknown') + '</span>' +
        ' | <span>Reported: ' + formatDate(report.ts) + '</span>' +
      '</div>' +
      '<div class="report-actions">' +
        '<button class="button button-light" data-cmd="clear" data-id="' + report.id + '">Clear</button>' +
        '<button class="button button-light" data-cmd="delete" data-id="' + report.id + '">Delete</button>' +
        '<button class="button button-light" data-cmd="ban-request" data-id="' + report.id + '">Ban Request</button>' +
      '</div>';
    
    return div;
  },
  
  formatPostContent: function(post) {
    var html = '';
    
    if (post.sub) {
      html += '<div class="report-subject">' + escapeHTML(post.sub) + '</div>';
    }
    
    if (post.com) {
      html += '<div class="report-comment">' + escapeHTML(post.com) + '</div>';
    }
    
    return html;
  },
  
  onToggleClearedClick: function(btn) {
    RQ.cleared_only = !RQ.cleared_only;
    
    if (RQ.cleared_only) {
      $.addClass(btn, 'active');
    } else {
      $.removeClass(btn, 'active');
    }
    
    RQ.showReports(RQ.board, RQ.cleared_only, RQ.extraFetch);
  },
  
  onBoardLinkClick: function(btn) {
    var board = btn.getAttribute('data-slug') || null;
    
    // Update active state
    var items = $.cls('board-slug', $.id('board-menu'));
    for (var i = 0; i < items.length; i++) {
      $.removeClass(items[i], 'active');
    }
    $.addClass(btn, 'active');
    
    RQ.board = board;
    RQ.showReports(board, RQ.cleared_only, RQ.extraFetch);
    
    // Update hash
    var hash = '#';
    if (board) {
      hash += 'board=' + board;
    }
    if (RQ.cleared_only) {
      hash += (hash.length > 1 ? '&' : '') + 'cleared=1';
    }
    window.location.hash = hash;
  },
  
  refreshReports: function() {
    RQ.showReports(RQ.board, RQ.cleared_only, RQ.extraFetch);
  },
  
  onClearClick: function(btn) {
    var id = btn.getAttribute('data-id');
    
    if (!confirm('Clear report #' + id + '?')) {
      return;
    }
    
    ajaxPost(RQ_CONFIG.apiBase + '/' + id + '/clear', {}, function(data) {
      if (data.status === 'success') {
        var el = $.cls('report-item').namedItem('data-id', id);
        if (el) {
          $.addClass(el, 'cleared');
        }
      }
    });
  },
  
  onDeleteClick: function(btn) {
    var id = btn.getAttribute('data-id');
    
    if (!confirm('Delete report #' + id + '? This cannot be undone.')) {
      return;
    }
    
    ajaxPost(RQ_CONFIG.apiBase + '/' + id + '/delete', {}, function(data) {
      if (data.status === 'success') {
        var el = document.querySelector('.report-item[data-id="' + id + '"]');
        if (el) {
          el.remove();
        }
      }
    });
  },
  
  onBanRequestClick: function(btn) {
    var id = btn.getAttribute('data-id');
    var reportEl = btn.closest('.report-item');
    var reportData = this.getReportData(id);
    
    this.showPanel('ban-request', this.getBanRequestForm(reportData), 'Ban Request', {
      'data-report-id': id
    });
  },
  
  onShowBanRequests: function() {
    window.location.href = '/staff/reports/ban-requests';
  },
  
  getBanRequestForm: function(report) {
    return '<div class="panel-content ban-request-form">' +
      '<p>Creating ban request for post No. ' + report.no + '</p>' +
      '<select class="ban-template-select">' +
        '<option value="">Select template...</option>' +
        '<option value="1">Spam</option>' +
        '<option value="2">Harassment</option>' +
        '<option value="3">Illegal Content</option>' +
      '</select>' +
      '<textarea placeholder="Additional reason (optional)"></textarea>' +
      '<button class="button button-approve" data-cmd="submit-ban-request">Submit Request</button>' +
    '</div>';
  },
  
  showSettings: function() {
    var html = '<div class="panel-content" id="settings-panel">' +
      '<div class="settings-row">' +
        '<label><input type="checkbox" data-setting="imageHover"> Image hover preview</label>' +
      '</div>' +
      '<div class="settings-row">' +
        '<label><input type="checkbox" data-setting="enableKeybinds"> Enable keyboard shortcuts</label>' +
      '</div>' +
      '<div class="settings-row">' +
        '<label><input type="checkbox" data-setting="hideThumbnails"> Hide thumbnails</label>' +
      '</div>' +
      '<button class="button button-primary" data-cmd="save-settings">Save Settings</button>' +
    '</div>';
    
    RQ.showPanel('settings', html, 'Settings');
  },
  
  onToggleDTClick: function() {
    if ($.id('cfg-cb-dt').checked) {
      localStorage.setItem('dark-theme', '1');
      $.addClass($.docEl, 'dark-theme');
    } else {
      localStorage.removeItem('dark-theme');
      $.removeClass($.docEl, 'dark-theme');
    }
  },
  
  showPanel: function(id, html, title, attrs) {
    var panel = $.id('panel-stack');
    if (!panel) return;
    
    panel.innerHTML = 
      '<div class="panel-header">' +
        '<span class="panel-title">' + title + '</span>' +
        '<span class="panel-close" data-cmd="shift-panel">&times;</span>' +
      '</div>' +
      '<div class="panel-content" id="panel-' + id + '">' + html + '</div>';
    
    if (attrs) {
      for (var key in attrs) {
        panel.setAttribute(key, attrs[key]);
      }
    }
    
    $.addClass(panel, 'active');
  },
  
  hidePanel: function() {
    var panel = $.id('panel-stack');
    if (panel) {
      $.removeClass(panel, 'active');
    }
  },
  
  shiftPanel: function() {
    RQ.hidePanel();
  },
  
  onClick: function(e) {
    var tgt = e.target;
    var cmd = tgt.getAttribute('data-cmd');
    
    if (cmd && RQ.clickCommands[cmd]) {
      e.preventDefault();
      RQ.clickCommands[cmd](tgt, e);
    }
  },
  
  onFilterSubmit: function(e) {
    e.preventDefault();
    // Implement filter logic
  },
  
  onFilterReset: function(e) {
    RQ.activeFilter = null;
    $.id('reset-btn').classList.add('hidden');
    RQ.refreshReports();
  },
  
  onSearchFocus: function() {
    $.id('reset-btn').classList.remove('hidden');
  },
  
  onSearchKeyDown: function(e) {
    if (e.keyCode === 27) {
      e.target.blur();
      RQ.onFilterReset();
    }
  },
  
  onCfgBtnFocusChange: function(e) {
    // Handle config button focus
  },
  
  onHashChange: function() {
    RQ.resolveQuery();
    RQ.showReports(RQ.board, RQ.cleared_only, RQ.extraFetch);
  },
  
  getSettings: function() {
    return {
      imageHover: localStorageGet('rq_image_hover', true),
      enableKeybinds: localStorageGet('rq_keybinds', false),
      hideThumbnails: localStorageGet('rq_hide_thumbs', false)
    };
  },
  
  applyCustomCSS: function(css) {
    var el = $.id('js-custom-css');
    if (el) {
      el.parentNode.removeChild(el);
    }
  },
  
  getReportData: function(id) {
    // Get report data from cache or DOM
    var el = document.querySelector('.report-item[data-id="' + id + '"]');
    if (!el) return null;
    
    return {
      id: id,
      board: el.querySelector('.report-board')?.textContent || '',
      no: el.querySelector('.report-post a')?.textContent || ''
    };
  },
  
  error: function(msg) {
    alert('Error: ' + msg);
  }
};

// Initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function() {
    RQ.init();
  });
} else {
  RQ.init();
}
