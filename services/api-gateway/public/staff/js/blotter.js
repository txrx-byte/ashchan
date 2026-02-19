'use strict';

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
    
    fetch('/staff/blotter/preview', {
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
