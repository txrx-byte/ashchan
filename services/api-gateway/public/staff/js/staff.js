/**
 * Staff Interface JavaScript
 * Common functionality for the Ashchan staff interface
 */

document.addEventListener('DOMContentLoaded', function() {
  // Auto-hide flash messages
  var flashMessages = document.querySelectorAll('.flash-message');
  flashMessages.forEach(function(msg) {
    setTimeout(function() {
      msg.style.opacity = '0';
      msg.style.transition = 'opacity 0.5s';
      setTimeout(function() {
        msg.remove();
      }, 500);
    }, 5000);
  });
  
  // Confirm destructive actions
  document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
      var message = this.getAttribute('data-confirm');
      if (!confirm(message)) {
        e.preventDefault();
      }
    });
  });
  
  // Auto-submit forms on select change
  document.querySelectorAll('[data-autosubmit]').forEach(function(el) {
    el.addEventListener('change', function() {
      var form = this.closest('form');
      if (form) {
        form.submit();
      }
    });
  });
  
  // Toggle all checkboxes
  var toggleAll = document.getElementById('toggle-all');
  if (toggleAll) {
    toggleAll.addEventListener('change', function() {
      var checkboxes = document.querySelectorAll('.range-select');
      checkboxes.forEach(function(cb) {
        cb.checked = toggleAll.checked;
      });
    });
  }
  
  // Quick search form
  var quickSearch = document.getElementById('front-form-qs');
  if (quickSearch) {
    var input = document.getElementById('front-field-qs');
    if (input) {
      var timeout;
      input.addEventListener('input', function() {
        clearTimeout(timeout);
        timeout = setTimeout(function() {
          quickSearch.submit();
        }, 500);
      });
    }
  }
});

/**
 * Ban request actions
 */
function approveBanRequest(id) {
  if (!confirm('Approve ban request #' + id + '?')) {
    return;
  }
  
  fetch('/staff/reports/ban-requests/' + id + '/approve', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'}
  })
  .then(r => r.json())
  .then(data => {
    if (data.status === 'success') {
      document.getElementById('request-' + id)?.remove();
      alert('Ban request approved');
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => {
    alert('Error: ' + err.message);
  });
}

function denyBanRequest(id) {
  if (!confirm('Deny ban request #' + id + '?')) {
    return;
  }
  
  fetch('/staff/reports/ban-requests/' + id + '/deny', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'}
  })
  .then(r => r.json())
  .then(data => {
    if (data.status === 'success') {
      document.getElementById('request-' + id)?.remove();
      alert('Ban request denied');
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => {
    alert('Error: ' + err.message);
  });
}

/**
 * Clear report
 */
function clearReport(id) {
  if (!confirm('Clear report #' + id + '?')) {
    return;
  }
  
  fetch('/staff/reports/' + id + '/clear', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'}
  })
  .then(r => r.json())
  .then(data => {
    if (data.status === 'success') {
      var el = document.querySelector('.report-item[data-id="' + id + '"]');
      if (el) {
        el.classList.add('cleared');
      }
      alert('Report cleared');
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => {
    alert('Error: ' + err.message);
  });
}

/**
 * Delete report
 */
function deleteReport(id) {
  if (!confirm('Delete report #' + id + '? This cannot be undone.')) {
    return;
  }
  
  fetch('/staff/reports/' + id + '/delete', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'}
  })
  .then(r => r.json())
  .then(data => {
    if (data.status === 'success') {
      var el = document.querySelector('.report-item[data-id="' + id + '"]');
      if (el) {
        el.remove();
      }
      alert('Report deleted');
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => {
    alert('Error: ' + err.message);
  });
}

/**
 * Toggle dark theme
 */
function toggleDarkTheme() {
  var checkbox = document.getElementById('cfg-cb-dt');
  if (checkbox.checked) {
    localStorage.setItem('dark-theme', '1');
    document.documentElement.classList.add('dark-theme');
  } else {
    localStorage.removeItem('dark-theme');
    document.documentElement.classList.remove('dark-theme');
  }
}
