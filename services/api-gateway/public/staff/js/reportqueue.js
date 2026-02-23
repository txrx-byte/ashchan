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
 * Report Queue JavaScript — Catalog-style grid layout
 * Ported from OpenYotsuba/reports/js/reportqueue.js
 * 
 * Renders reports as image-board catalog cards instead of a list.
 */

var RQ = {
  HIGHLIGHT_THRES: 500,
  GLOBAL_THRES: 1500,
  MODE_REPORTS: 0,
  MODE_CONTEXT: 1,
  MAX_EXCERPT: 160,
  
  syncDelay: 15000,
  syncInterval: null,
  syncEnabled: false,
  
  /** Pool of report objects keyed by id (for action lookups) */
  reportPool: {},
  
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
      'submit-ban-request': RQ.onSubmitBanRequest,
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
    
    var container = $.id('items');
    if (container) container.innerHTML = '<div class="no-reports">Loading&hellip;</div>';
    
    this.xhr.reports = ajaxGet(url, function(data) {
      self.renderReports(data);
    });
  },
  
  renderReports: function(data) {
    var container = $.id('items');
    if (!container) return;
    
    container.innerHTML = '';
    this.reportPool = {};
    
    if (!data.reports || data.reports.length === 0) {
      container.innerHTML = '<div class="no-reports">No reports found</div>';
      this.updateCounter(0, 0);
      return;
    }
    
    for (var i = 0; i < data.reports.length; i++) {
      var report = data.reports[i];
      this.reportPool[report.id] = report;
      container.appendChild(this.createReportCard(report));
    }
    
    this.currentCount = data.total;
    this.updateCounter(data.reports.length, data.total);
  },
  
  updateCounter: function(shown, total) {
    var title = $.id('title');
    if (title) {
      title.textContent = 'Reports' + (total > 0 ? ' (' + total + ')' : '');
    }
  },

  /* ─────────────────────────────────────────────
   * Catalog card builder
   * ───────────────────────────────────────────── */
  createReportCard: function(report) {
    var card = $.el('div');
    card.className = 'report-card';
    card.setAttribute('data-id', report.id);
    
    var isHighlighted = report.weight >= this.HIGHLIGHT_THRES;
    var isCritical = report.weight >= this.GLOBAL_THRES;
    
    if (isHighlighted) $.addClass(card, 'highlighted');
    if (isCritical) $.addClass(card, 'unlocked');
    
    var post = report.post || {};
    var threadNo = report.is_thread ? report.no : (report.resto || report.no);
    var postUrl = '/' + report.board + '/thread/' + threadNo + (report.is_thread ? '' : '#p' + report.no);
    
    // ── Weight badge ──
    var badgeClass = 'report-badge';
    if (isCritical) badgeClass += ' badge-critical';
    else if (isHighlighted) badgeClass += ' badge-high';
    
    var html = '<span class="' + badgeClass + '">' + report.count + '</span>';
    
    // ── Thumbnail or placeholder ──
    html += '<a href="' + postUrl + '" target="_blank">';
    if (post.thumb_url) {
      html += '<img class="report-thumb" src="' + escapeHTML(post.thumb_url) + '" alt="" loading="lazy"';
      if (post.media_url) {
        html += ' data-full="' + escapeHTML(post.media_url) + '"';
      }
      html += '>';
    } else {
      html += '<div class="report-thumb-placeholder">' +
        (report.is_thread ? 'Thread' : 'Reply') +
        '</div>';
    }
    html += '</a>';
    
    // ── Stats line ──
    html += '<div class="report-stats">' +
      '<span class="report-board-tag">/' + escapeHTML(report.board) + '/</span> ' +
      '<b>W:' + report.weight + '</b>' +
    '</div>';
    
    // ── Post number ──
    html += '<div class="report-post-link">' +
      'No. <a href="' + postUrl + '" target="_blank">' + report.no + '</a>' +
    '</div>';
    
    // ── Excerpt ──
    html += '<div class="report-excerpt">';
    html += this.formatExcerpt(post);
    html += '</div>';
    
    // ── Compact action buttons ──
    html += '<div class="report-card-actions">' +
      '<button class="act-clear" data-cmd="clear" data-id="' + report.id + '" title="Clear report">&#10003;</button>' +
      '<button class="act-delete" data-cmd="delete" data-id="' + report.id + '" title="Delete post">&#10007;</button>' +
      '<button class="act-ban" data-cmd="ban-request" data-id="' + report.id + '" title="Ban request">B</button>' +
    '</div>';
    
    // ── Hover preview (full comment) ──
    html += this.buildHoverPreview(report);
    
    card.innerHTML = html;
    return card;
  },
  
  /**
   * Truncated excerpt for card display
   */
  formatExcerpt: function(post) {
    var html = '';
    if (post.sub) {
      html += '<span class="report-subject">' + escapeHTML(post.sub) + '</span>: ';
    }
    if (post.com) {
      var text = post.com.replace(/<[^>]*>/g, ' ').replace(/&[^;]+;/g, ' ').replace(/\s+/g, ' ').trim();
      if (text.length > RQ.MAX_EXCERPT) {
        text = text.substring(0, RQ.MAX_EXCERPT) + '\u2026';
      }
      html += escapeHTML(text);
    }
    if (!html) {
      html = '<span style="color:#aaa">No text</span>';
    }
    return html;
  },
  
  /**
   * Full-content hover preview tooltip
   */
  buildHoverPreview: function(report) {
    var post = report.post || {};
    var html = '<div class="report-hover-preview">';
    
    if (post.sub) {
      html += '<b>' + escapeHTML(post.sub) + '</b><br>';
    }
    if (post.com) {
      var full = post.com.replace(/<[^>]*>/g, ' ').replace(/&[^;]+;/g, ' ').replace(/\s+/g, ' ').trim();
      html += escapeHTML(full);
    } else {
      html += '<i>No text content</i>';
    }
    
    html += '<div class="preview-meta">' +
      '/' + escapeHTML(report.board) + '/ &middot; ' +
      'No. ' + report.no + ' &middot; ' +
      'Weight: ' + report.weight + ' (' + report.count + ' reports) &middot; ' +
      formatDate(report.ts) +
    '</div>';
    
    html += '</div>';
    return html;
  },
  
  /* ─────────────────────────────────────────────
   * Actions
   * ───────────────────────────────────────────── */
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
    
    var items = $.cls('board-slug', $.id('board-menu'));
    for (var i = 0; i < items.length; i++) {
      $.removeClass(items[i], 'active');
    }
    $.addClass(btn, 'active');
    
    RQ.board = board;
    RQ.showReports(board, RQ.cleared_only, RQ.extraFetch);
    
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
        var el = document.querySelector('.report-card[data-id="' + id + '"]');
        if (el) {
          $.addClass(el, 'cleared');
        }
      }
    });
  },
  
  onDeleteClick: function(btn) {
    var id = btn.getAttribute('data-id');
    
    if (!confirm('Delete post for report #' + id + '? This cannot be undone.')) {
      return;
    }
    
    ajaxPost(RQ_CONFIG.apiBase + '/' + id + '/delete', {}, function(data) {
      if (data.status === 'success') {
        var el = document.querySelector('.report-card[data-id="' + id + '"]');
        if (el) {
          el.remove();
        }
      }
    });
  },
  
  onBanRequestClick: function(btn) {
    var id = btn.getAttribute('data-id');
    var reportData = RQ.reportPool[id] || RQ.getReportData(id);
    
    RQ.showPanel('ban-request', RQ.getBanRequestForm(reportData), 'Ban Request', {
      'data-report-id': id
    });
  },
  
  onShowBanRequests: function() {
    window.location.href = '/staff/reports/ban-requests';
  },
  
  onSubmitBanRequest: function(btn) {
    var panel = $.id('panel-stack');
    if (!panel) return;
    
    var reportId = panel.getAttribute('data-report-id');
    var report = RQ.reportPool[reportId];
    if (!report) {
      alert('Report data not found. Please refresh and try again.');
      return;
    }
    
    var select = panel.querySelector('.ban-template-select');
    var textarea = panel.querySelector('.ban-reason-text');
    var templateId = select ? select.value : '';
    var reason = textarea ? textarea.value.trim() : '';
    
    if (!templateId) {
      alert('Please select a ban template.');
      return;
    }
    
    btn.disabled = true;
    btn.textContent = 'Submitting...';
    
    var postData = report.post || {};
    
    ajaxJSON(RQ_CONFIG.apiBase + '/ban-requests', {
      board: report.board,
      post_no: report.no,
      template_id: parseInt(templateId, 10),
      reason: reason,
      post_data: postData
    }, function(data) {
      if (data.status === 'success') {
        RQ.hidePanel();
        var el = document.querySelector('.report-card[data-id="' + reportId + '"]');
        if (el) {
          $.addClass(el, 'cleared');
        }
        // Auto-clear the report after submitting ban request
        ajaxPost(RQ_CONFIG.apiBase + '/' + reportId + '/clear', {}, function() {});
      } else {
        btn.disabled = false;
        btn.textContent = 'Submit Request';
        alert(data.error || 'Failed to submit ban request');
      }
    }, function(xhr) {
      btn.disabled = false;
      btn.textContent = 'Submit Request';
      var msg = 'Failed to submit ban request';
      try { msg = JSON.parse(xhr.responseText).error || msg; } catch(e) {}
      alert(msg);
    });
  },
  
  getBanRequestForm: function(report) {
    var no = report ? (report.no || '') : '';
    var board = report ? (report.board || '') : '';
    return '<div class="panel-content ban-request-form">' +
      '<p>Creating ban request for <b>/' + escapeHTML(board) + '/</b> post No. ' + escapeHTML(String(no)) + '</p>' +
      '<select class="ban-template-select">' +
        '<option value="">Select template...</option>' +
        '<option value="1">Spam</option>' +
        '<option value="2">Harassment</option>' +
        '<option value="3">Illegal Content</option>' +
        '<option value="4">Off-topic</option>' +
        '<option value="5">NSFW on SFW Board</option>' +
      '</select>' +
      '<textarea class="ban-reason-text" placeholder="Additional reason (optional)"></textarea>' +
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
    var q = ($.id('search-box').value || '').trim().toLowerCase();
    if (!q) {
      RQ.onFilterReset();
      return;
    }
    RQ.activeFilter = q;
    var cards = $.cls('report-card');
    for (var i = 0; i < cards.length; i++) {
      var text = cards[i].textContent.toLowerCase();
      cards[i].style.display = text.indexOf(q) !== -1 ? '' : 'none';
    }
    $.id('reset-btn').classList.remove('hidden');
  },
  
  onFilterReset: function(e) {
    RQ.activeFilter = null;
    $.id('search-box').value = '';
    $.id('reset-btn').classList.add('hidden');
    var cards = $.cls('report-card');
    for (var i = 0; i < cards.length; i++) {
      cards[i].style.display = '';
    }
  },
  
  onSearchFocus: function() {
    $.id('reset-btn').classList.remove('hidden');
  },
  
  onSearchKeyDown: function(e) {
    if (e.keyCode === 27) {
      e.target.blur();
      RQ.onFilterReset();
    }
    if (e.keyCode === 13) {
      e.preventDefault();
      RQ.onFilterSubmit(e);
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
    if (RQ.reportPool[id]) return RQ.reportPool[id];
    
    var el = document.querySelector('.report-card[data-id="' + id + '"]');
    if (!el) return null;
    
    return {
      id: id,
      board: (el.querySelector('.report-board-tag') || {}).textContent || '',
      no: (el.querySelector('.report-post-link a') || {}).textContent || ''
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
  // DOM already loaded — init and run immediately
  RQ.init();
  RQ.run();
}
