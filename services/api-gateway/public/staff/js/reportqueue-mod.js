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
 * Report Queue Mod JavaScript
 * Extended functionality for moderators (vs janitors)
 * Ported from OpenYotsuba/reports/js/d8d9b0cdc33f3418/reportqueue-mod.js
 */

var RQMod = {
  init: function() {
    // Add mod-specific click commands
    RQ.clickCommands['show-q-ban'] = RQMod.onShowQuickBanClick;
    RQ.clickCommands['quick-ban'] = RQMod.onQuickBanClick;
    RQ.clickCommands['pick-rid'] = RQMod.onPickReport;
    
    // Add mod-specific UI elements
    this.addModButtons();
  },
  
  addModButtons: function() {
    var menu = $.id('menu');
    if (!menu) return;
    
    // Quick ban button (already in OpenYotsuba template)
    // This is handled in the template
  },
  
  onShowQuickBanClick: function(btn) {
    var reportId = btn.getAttribute('data-id');
    var reportData = RQ.getReportData(reportId);
    
    RQ.showPanel('quick-ban', RQMod.getQuickBanForm(reportData), 'Quick Ban', {
      'data-report-id': reportId
    });
  },
  
  onQuickBanClick: function(btn) {
    // Handle quick ban submission
    var panel = $.id('panel-stack');
    var templateSelect = panel.querySelector('.ban-template-select');
    var reasonField = panel.querySelector('.ban-reason-field');
    
    if (!templateSelect || !templateSelect.value) {
      RQ.error('Please select a ban template');
      return;
    }
    
    var data = {
      template_id: templateSelect.value,
      reason: reasonField ? reasonField.value : '',
      report_id: panel.getAttribute('data-report-id')
    };
    
    ajaxJSON('/staff/reports/ban-requests', data, function(response) {
      if (response.status === 'success') {
        RQ.hidePanel();
        alert('Ban request submitted');
      } else {
        RQ.error(response.error || 'Failed to create ban request');
      }
    });
  },
  
  getQuickBanForm: function(report) {
    return '<div class="panel-content" id="quick-ban-panel">' +
      '<p>Post No. ' + report.no + ' (/' + report.board + '/)</p>' +
      '<select class="ban-template-select">' +
        '<option value="">Select ban template...</option>' +
        '<option value="5">Spam (1 day)</option>' +
        '<option value="6">Advertising (1 day)</option>' +
        '<option value="7">Ban Evasion (Permanent)</option>' +
      '</select>' +
      '<textarea class="ban-reason-field" placeholder="Additional reason (optional)"></textarea>' +
      '<button class="button button-approve" data-cmd="quick-ban">Submit Ban Request</button>' +
    '</div>';
  },
  
  onPickReport: function(btn) {
    // Handle report selection for bulk actions
    var reportId = btn.getAttribute('data-id');
    var reportEl = document.querySelector('.report-item[data-id="' + reportId + '"]');
    
    if (reportEl) {
      reportEl.classList.toggle('selected');
    }
  },
  
  // Bulk action handlers
  bulkClear: function() {
    var selected = document.querySelectorAll('.report-item.selected');
    if (selected.length === 0) {
      RQ.error('No reports selected');
      return;
    }
    
    if (!confirm('Clear ' + selected.length + ' selected report(s)?')) {
      return;
    }
    
    selected.forEach(function(el) {
      var id = el.getAttribute('data-id');
      ajaxPost('/staff/reports/' + id + '/clear', {});
      el.classList.remove('selected');
      el.classList.add('cleared');
    });
  },
  
  bulkDelete: function() {
    var selected = document.querySelectorAll('.report-item.selected');
    if (selected.length === 0) {
      RQ.error('No reports selected');
      return;
    }
    
    if (!confirm('Delete ' + selected.length + ' selected report(s)? This cannot be undone.')) {
      return;
    }
    
    selected.forEach(function(el) {
      var id = el.getAttribute('data-id');
      ajaxPost('/staff/reports/' + id + '/delete', {});
      el.remove();
    });
  },
  
  // IP lookup for mods
  lookupIP: function(ip) {
    ajaxGet('/staff/api/ip-lookup?ip=' + encodeURIComponent(ip), function(data) {
      // Show IP info in panel
      RQ.showPanel('ip-info', RQMod.formatIPInfo(data), 'IP Information');
    });
  },
  
  formatIPInfo: function(data) {
    return '<div class="panel-content">' +
      '<dl>' +
        '<dt>IP Address</dt><dd>' + (data.ip || 'N/A') + '</dd>' +
        '<dt>Location</dt><dd>' + (data.location || 'N/A') + '</dd>' +
        '<dt>ISP</dt><dd>' + (data.isp || 'N/A') + '</dd>' +
        '<dt>ASN</dt><dd>' + (data.asn || 'N/A') + '</dd>' +
      '</dl>' +
    '</div>';
  }
};

// Initialize mod extensions when RQ is ready
if (typeof RQ !== 'undefined') {
  var originalRun = RQ.run;
  RQ.run = function() {
    originalRun.apply(this, arguments);
    RQMod.init();
  };
}
