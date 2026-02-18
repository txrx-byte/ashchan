/**
 * Helpers JavaScript
 * Ported from OpenYotsuba/js/helpers.js
 * 
 * Common utility functions for staff interface
 */

/**
 * CSRF helper
 */
function csrf_tag() {
  var token = getCookie('_tkn');
  if (!token) {
    token = generateToken();
    setCookie('_tkn', token, 30);
  }
  return '<input type="hidden" name="_tkn" value="' + token + '">';
}

function csrf_attr() {
  var token = getCookie('_tkn');
  if (!token) {
    token = generateToken();
    setCookie('_tkn', token, 30);
  }
  return 'data-csrf="' + token + '"';
}

function getCookie(name) {
  var value = '; ' + document.cookie;
  var parts = value.split('; ' + name + '=');
  if (parts.length == 2) {
    return parts.pop().split(';').shift();
  }
  return null;
}

function setCookie(name, value, days) {
  var expires = '';
  if (days) {
    var date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    expires = '; expires=' + date.toUTCString();
  }
  document.cookie = name + '=' + value + expires + '; path=/; domain=' + window.location.hostname;
}

function generateToken() {
  var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  var token = '';
  for (var i = 0; i < 32; i++) {
    token += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return token;
}

/**
 * AJAX helper
 */
function ajax(url, options) {
  options = options || {};
  
  var xhr = new XMLHttpRequest();
  xhr.open(options.method || 'GET', url, true);
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  
  if (options.headers) {
    for (var key in options.headers) {
      xhr.setRequestHeader(key, options.headers[key]);
    }
  }
  
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      if (xhr.status >= 200 && xhr.status < 300) {
        if (options.success) {
          try {
            var data = JSON.parse(xhr.responseText);
            options.success(data, xhr);
          } catch (e) {
            options.success(xhr.responseText, xhr);
          }
        }
      } else {
        if (options.error) {
          options.error(xhr);
        }
      }
    }
  };
  
  if (options.data) {
    xhr.send(options.data);
  } else {
    xhr.send(null);
  }
  
  return xhr;
}

function ajaxGet(url, callback) {
  return ajax(url, {
    success: callback
  });
}

function ajaxPost(url, data, callback) {
  var formData;
  if (typeof data === 'string') {
    formData = data;
  } else {
    formData = new URLSearchParams();
    for (var key in data) {
      formData.append(key, data[key]);
    }
  }
  
  return ajax(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    data: formData,
    success: callback
  });
}

function ajaxJSON(url, data, callback) {
  return ajax(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    data: JSON.stringify(data),
    success: callback
  });
}

/**
 * DOM helpers
 */
function escapeHTML(str) {
  var div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function stripHTML(str) {
  var div = document.createElement('div');
  div.innerHTML = str;
  return div.textContent || div.innerText || '';
}

function formatNumber(num) {
  return num.toString().replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
}

function formatDate(timestamp, format) {
  var date = new Date(timestamp * 1000);
  
  if (format === 'short') {
    var month = date.getMonth() + 1;
    var day = date.getDate();
    var year = date.getFullYear() % 100;
    return (month < 10 ? '0' : '') + month + '/' + 
           (day < 10 ? '0' : '') + day + '/' + 
           (year < 10 ? '0' : '') + year;
  }
  
  return date.toLocaleString();
}

/**
 * Image helpers
 */
function imageHoverInit() {
  $.on(document, 'mouseover', function(e) {
    var tgt = e.target;
    if (tgt.tagName === 'IMG' && tgt.hasAttribute('data-hover')) {
      // Show enlarged image on hover
    }
  });
}

/**
 * Form helpers
 */
function serializeForm(form) {
  var data = new FormData(form);
  var params = [];
  
  for (var [key, value] of data.entries()) {
    params.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
  }
  
  return params.join('&');
}

function validateEmail(email) {
  var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return re.test(email);
}

/**
 * Storage helpers
 */
function localStorageGet(key, defaultValue) {
  try {
    var value = localStorage.getItem(key);
    return value !== null ? JSON.parse(value) : defaultValue;
  } catch (e) {
    return defaultValue;
  }
}

function localStorageSet(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
    return true;
  } catch (e) {
    return false;
  }
}

/**
 * Debounce helper
 */
function debounce(func, wait) {
  var timeout;
  return function() {
    var context = this, args = arguments;
    clearTimeout(timeout);
    timeout = setTimeout(function() {
      func.apply(context, args);
    }, wait);
  };
}

/**
 * Pretty duration formatter
 */
function prettyDuration(seconds) {
  if (seconds < 60) {
    return seconds + ' second' + (seconds !== 1 ? 's' : '');
  }
  
  var minutes = Math.floor(seconds / 60);
  if (minutes < 60) {
    return minutes + ' minute' + (minutes !== 1 ? 's' : '');
  }
  
  var hours = Math.floor(minutes / 60);
  if (hours < 24) {
    return hours + ' hour' + (hours !== 1 ? 's' : '');
  }
  
  var days = Math.floor(hours / 24);
  return days + ' day' + (days !== 1 ? 's' : '');
}

// Export helpers
window.csrf_tag = csrf_tag;
window.csrf_attr = csrf_attr;
window.getCookie = getCookie;
window.setCookie = setCookie;
window.ajax = ajax;
window.ajaxGet = ajaxGet;
window.ajaxPost = ajaxPost;
window.ajaxJSON = ajaxJSON;
window.escapeHTML = escapeHTML;
window.stripHTML = stripHTML;
window.formatNumber = formatNumber;
window.formatDate = formatDate;
window.serializeForm = serializeForm;
window.validateEmail = validateEmail;
window.localStorageGet = localStorageGet;
window.localStorageSet = localStorageSet;
window.debounce = debounce;
window.prettyDuration = prettyDuration;
