/**
 * ashchan - core.js
 * Core imageboard JavaScript functionality.
 * Handles: theme switching, post quoting, inline expansion,
 * quick reply, thread watcher, settings, image expansion,
 * quote preview, auto-update, keyboard shortcuts, post menus.
 */
(function() {
  'use strict';

  /* ============================
   * Configuration
   * ============================ */
  const Config = {
    siteName: 'ashchan',
    apiBase: '/api/v1',
    autoUpdateInterval: 30000,
    maxFileSize: 4 * 1024 * 1024,
    allowedFileTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    themes: ['yotsuba', 'yotsuba-b', 'futaba', 'burichan', 'tomorrow', 'photon'],
    defaultTheme: 'yotsuba-b',
    maxCommentLength: 2000,
    thumbMaxWidth: 250,
    thumbMaxHeight: 250
  };

  /* ============================
   * Utility
   * ============================ */
  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
  const on = (el, ev, fn, opts) => el && el.addEventListener(ev, fn, opts);
  const off = (el, ev, fn) => el && el.removeEventListener(ev, fn);
  const create = (tag, attrs, html) => {
    const el = document.createElement(tag);
    if (attrs) Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
    if (html) el.innerHTML = html;
    return el;
  };

  function formatTimestamp(utc) {
    const d = new Date(utc * 1000);
    const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const pad = n => String(n).padStart(2, '0');
    return `${pad(d.getMonth()+1)}/${pad(d.getDate())}/${d.getFullYear().toString().slice(2)}` +
           `(${days[d.getDay()]})${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  }

  function escapeHtml(s) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(s));
    return div.innerHTML;
  }

  function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
  }

  /* ============================
   * Local Storage Helpers
   * ============================ */
  const Storage = {
    get(key, fallback) {
      try { const v = localStorage.getItem('ashchan_' + key); return v !== null ? JSON.parse(v) : fallback; }
      catch { return fallback; }
    },
    set(key, val) {
      try { localStorage.setItem('ashchan_' + key, JSON.stringify(val)); } catch {}
    }
  };

  /* ============================
   * Theme Manager
   * ============================ */
  const ThemeManager = {
    current: null,
    init() {
      this.current = Storage.get('theme', Config.defaultTheme);
      this.apply(this.current);
      const sel = $('#styleSelector');
      if (sel) {
        sel.value = this.current;
        on(sel, 'change', () => this.apply(sel.value));
      }
    },
    apply(name) {
      this.current = name;
      Storage.set('theme', name);
      const link = $('#themeStylesheet');
      if (link) link.href = `/static/css/${name}.css`;
      const sel = $('#styleSelector');
      if (sel) sel.value = name;
    }
  };

  /* ============================
   * Post Quoting
   * ============================ */
  const PostQuoting = {
    init() {
      on(document, 'click', (e) => {
        const link = e.target.closest('.postNum a[title="Reply to this post"]');
        if (!link) return;
        e.preventDefault();
        const num = link.textContent;
        this.insertQuote(num);
      });
    },
    insertQuote(num) {
      const ta = $('#qrComment') || $('textarea[name="com"]');
      if (!ta) return;
      const quote = `>>${num}\n`;
      const start = ta.selectionStart;
      ta.value = ta.value.slice(0, start) + quote + ta.value.slice(ta.selectionEnd);
      ta.selectionStart = ta.selectionEnd = start + quote.length;
      ta.focus();
      QuickReply.show();
    }
  };

  /* ============================
   * Image Expansion
   * ============================ */
  const ImageExpansion = {
    init() {
      on(document, 'click', (e) => {
        const thumb = e.target.closest('.fileThumb');
        if (!thumb) return;
        e.preventDefault();
        this.toggle(thumb);
      });
    },
    toggle(thumb) {
      const img = $('img', thumb);
      if (!img) return;
      if (thumb.classList.contains('expanded')) {
        img.src = img.dataset.thumbSrc || img.src;
        img.style.maxWidth = Config.thumbMaxWidth + 'px';
        img.style.maxHeight = Config.thumbMaxHeight + 'px';
        img.style.width = '';
        img.style.height = '';
        thumb.classList.remove('expanded');
      } else {
        img.dataset.thumbSrc = img.dataset.thumbSrc || img.src;
        img.src = thumb.href;
        img.style.maxWidth = 'none';
        img.style.maxHeight = 'none';
        img.style.width = 'auto';
        img.style.height = 'auto';
        thumb.classList.add('expanded');
      }
    }
  };

  /* ============================
   * Quote Preview (Hover)
   * ============================ */
  const QuotePreview = {
    el: null,
    init() {
      this.el = create('div', { id: 'quote-preview' });
      this.el.style.display = 'none';
      document.body.appendChild(this.el);
      on(document, 'mouseover', (e) => this.handleOver(e));
      on(document, 'mouseout', (e) => this.handleOut(e));
    },
    handleOver(e) {
      const link = e.target.closest('.quotelink, .backlink a');
      if (!link) return;
      const href = link.getAttribute('href') || '';
      const match = href.match(/#p(\d+)/);
      if (!match) return;
      const postId = match[1];
      const post = $(`#p${postId}`);
      if (!post) return;
      this.el.innerHTML = post.outerHTML;
      this.el.style.display = 'block';
      const rect = link.getBoundingClientRect();
      this.el.style.left = Math.min(rect.left, window.innerWidth - 620) + 'px';
      this.el.style.top = (rect.bottom + window.scrollY + 5) + 'px';
    },
    handleOut(e) {
      const link = e.target.closest('.quotelink, .backlink a');
      if (!link) return;
      this.el.style.display = 'none';
    }
  };

  /* ============================
   * Quick Reply (Floating Post Form)
   * ============================ */
  const QuickReply = {
    el: null,
    visible: false,
    dragState: null,
    init() {
      this.buildUI();
      on(document, 'click', (e) => {
        if (e.target.closest('.postNum a[title="Reply to this post"]')) {
          this.show();
        }
      });
    },
    buildUI() {
      this.el = create('div', { id: 'quickReply' });
      this.el.style.display = 'none';
      this.el.style.right = '10px';
      this.el.style.bottom = '10px';
      this.el.innerHTML = `
        <div class="qrHeader">
          <span>Reply</span>
          <span class="qrClose">&times;</span>
        </div>
        <form id="qrForm" method="post" enctype="multipart/form-data">
          <table>
            <tr><td><input type="text" name="name" placeholder="Name" autocomplete="off"></td></tr>
            <tr><td><input type="text" name="email" placeholder="Options" autocomplete="off"></td></tr>
            <tr><td><input type="text" name="sub" placeholder="Subject" autocomplete="off"></td></tr>
            <tr><td><textarea id="qrComment" name="com" placeholder="Comment" maxlength="${Config.maxCommentLength}"></textarea></td></tr>
            <tr><td><input type="file" name="upfile" accept="${Config.allowedFileTypes.join(',')}"></td></tr>
            <tr><td><div id="qrCaptcha"></div></td></tr>
            <tr><td><input type="submit" value="Post"></td></tr>
          </table>
        </form>`;
      document.body.appendChild(this.el);

      on($('.qrClose', this.el), 'click', () => this.hide());
      on($('.qrHeader', this.el), 'mousedown', (e) => this.startDrag(e));
      on($('#qrForm', this.el), 'submit', (e) => this.submit(e));
    },
    show() {
      this.el.style.display = 'block';
      this.visible = true;
    },
    hide() {
      this.el.style.display = 'none';
      this.visible = false;
    },
    startDrag(e) {
      if (e.target.classList.contains('qrClose')) return;
      e.preventDefault();
      const rect = this.el.getBoundingClientRect();
      this.dragState = { x: e.clientX - rect.left, y: e.clientY - rect.top };
      const move = (me) => {
        this.el.style.left = (me.clientX - this.dragState.x) + 'px';
        this.el.style.top = (me.clientY - this.dragState.y) + 'px';
        this.el.style.right = 'auto';
        this.el.style.bottom = 'auto';
      };
      const up = () => { off(document, 'mousemove', move); off(document, 'mouseup', up); };
      on(document, 'mousemove', move);
      on(document, 'mouseup', up);
    },
    async submit(e) {
      e.preventDefault();
      const form = e.target;
      const data = new FormData(form);
      const threadId = document.body.dataset.threadId;
      const boardSlug = document.body.dataset.boardSlug;
      if (!boardSlug) return;

      try {
        const res = await fetch(`${Config.apiBase}/boards/${boardSlug}/threads/${threadId || ''}/posts`, {
          method: 'POST',
          body: data,
          credentials: 'same-origin'
        });
        if (!res.ok) {
          const err = await res.json();
          alert(err.message || 'Post failed');
          return;
        }
        form.reset();
        if (threadId) AutoUpdate.check();
        else window.location.reload();
      } catch (ex) {
        alert('Network error');
      }
    }
  };

  /* ============================
   * Auto-Update (Thread View)
   * ============================ */
  const AutoUpdate = {
    timer: null,
    enabled: false,
    init() {
      if (!document.body.dataset.threadId) return;
      this.enabled = Storage.get('autoUpdate', true);
      this.setupUI();
      if (this.enabled) this.start();
    },
    setupUI() {
      const footer = $('#autoUpdateCtrl');
      if (!footer) return;
      const cb = $('input', footer);
      if (cb) {
        cb.checked = this.enabled;
        on(cb, 'change', () => {
          this.enabled = cb.checked;
          Storage.set('autoUpdate', this.enabled);
          this.enabled ? this.start() : this.stop();
        });
      }
    },
    start() {
      this.stop();
      this.timer = setInterval(() => this.check(), Config.autoUpdateInterval);
    },
    stop() {
      if (this.timer) { clearInterval(this.timer); this.timer = null; }
    },
    async check() {
      const threadId = document.body.dataset.threadId;
      const boardSlug = document.body.dataset.boardSlug;
      if (!threadId || !boardSlug) return;
      try {
        const lastPost = $$('.postContainer.replyContainer').pop();
        const after = lastPost ? lastPost.id.replace('pc', '') : '0';
        const res = await fetch(`${Config.apiBase}/boards/${boardSlug}/threads/${threadId}/posts?after=${after}`);
        if (!res.ok) return;
        const data = await res.json();
        if (data.posts && data.posts.length > 0) {
          data.posts.forEach(p => this.appendPost(p));
        }
      } catch {}
    },
    appendPost(post) {
      const thread = $('.thread');
      if (!thread || $(`#pc${post.id}`, thread)) return;
      const html = PostRenderer.render(post, false);
      thread.insertAdjacentHTML('beforeend', html);
    }
  };

  /* ============================
   * Post Renderer
   * ============================ */
  const PostRenderer = {
    render(post, isOp) {
      const cls = isOp ? 'opContainer' : 'replyContainer';
      const postCls = isOp ? 'op' : 'reply';
      const sideArrows = isOp ? '' : `<div class="sideArrows" id="sa${post.id}">&gt;&gt;</div>`;
      const checkbox = `<input type="checkbox" name="${post.id}" value="delete">`;
      const name = escapeHtml(post.author_name || 'Anonymous');
      const trip = post.tripcode ? `<span class="postertrip">${escapeHtml(post.tripcode)}</span>` : '';
      const subject = post.subject ? `<span class="subject">${escapeHtml(post.subject)}</span> ` : '';
      const time = formatTimestamp(post.created_at);

      let fileHtml = '';
      if (post.media_url) {
        const fname = escapeHtml(post.media_filename || 'file');
        const fsize = formatFileSize(post.media_size || 0);
        fileHtml = `
          <div class="file" id="f${post.id}">
            <div class="fileText" id="fT${post.id}">File: <a href="${post.media_url}" target="_blank">${fname}</a> (${fsize})</div>
            <a class="fileThumb" href="${post.media_url}" target="_blank">
              <img src="${post.thumb_url || post.media_url}" alt="${fsize}" loading="lazy"
                   style="max-width:${Config.thumbMaxWidth}px;max-height:${Config.thumbMaxHeight}px;">
            </a>
          </div>`;
      }
      const content = this.formatContent(post.content || '');
      const backlinks = post.backlinks ? post.backlinks.map(b =>
        `<a href="#p${b}" class="quotelink">&gt;&gt;${b}</a>`
      ).join(' ') : '';
      const backlinkHtml = backlinks ? `<span class="backlink">${backlinks}</span>` : '';

      return `
        <div class="postContainer ${cls}" id="pc${post.id}">
          ${sideArrows}
          <div id="p${post.id}" class="post ${postCls}">
            <div class="postInfo desktop" id="pi${post.id}">
              ${checkbox}
              ${subject}
              <span class="nameBlock">
                <span class="name">${name}</span>${trip}
              </span>
              <span class="dateTime" data-utc="${post.created_at}">${time}</span>
              <span class="postNum desktop">
                <a href="#p${post.id}" title="Link to this post">No.</a>
                <a href="#q${post.id}" title="Reply to this post">${post.id}</a>
              </span>
              <span class="postMenuBtn" title="Post menu">▶</span>
              ${backlinkHtml}
            </div>
            ${fileHtml}
            <blockquote class="postMessage" id="m${post.id}">${content}</blockquote>
          </div>
        </div>`;
    },
    formatContent(text) {
      let html = escapeHtml(text);
      // Greentext
      html = html.replace(/^(&gt;[^&\n].*)$/gm, '<span class="quote">$1</span>');
      // Quote links
      html = html.replace(/&gt;&gt;(\d+)/g, '<a href="#p$1" class="quotelink">&gt;&gt;$1</a>');
      // Cross-board links
      html = html.replace(/&gt;&gt;&gt;\/(\w+)\//g, '<a href="/$1/" class="quotelink">&gt;&gt;&gt;/$1/</a>');
      // Spoilers
      html = html.replace(/\[spoiler\](.*?)\[\/spoiler\]/gs, '<s>$1</s>');
      // Line breaks
      html = html.replace(/\n/g, '<br>');
      return html;
    }
  };

  /* ============================
   * Thread Watcher
   * ============================ */
  const ThreadWatcher = {
    data: {},
    el: null,
    init() {
      this.data = Storage.get('watchedThreads', {});
      this.buildUI();
      this.renderList();
      on(document, 'click', (e) => {
        if (e.target.closest('.watchThread')) {
          e.preventDefault();
          this.toggleWatch();
        }
      });
    },
    buildUI() {
      this.el = create('div', { id: 'threadWatcher' });
      this.el.innerHTML = '<div class="twHeader">Watched Threads [<a href="#" id="twClose">X</a>]</div><div id="twList"></div>';
      this.el.style.display = Object.keys(this.data).length ? 'block' : 'none';
      document.body.appendChild(this.el);
      on($('#twClose', this.el), 'click', (e) => { e.preventDefault(); this.el.style.display = 'none'; });
    },
    toggleWatch() {
      const threadId = document.body.dataset.threadId;
      const boardSlug = document.body.dataset.boardSlug;
      if (!threadId) return;
      const key = `${boardSlug}/${threadId}`;
      if (this.data[key]) {
        delete this.data[key];
      } else {
        const title = $('.subject')?.textContent || `Thread #${threadId}`;
        this.data[key] = { title: title.slice(0, 50), board: boardSlug, thread: threadId };
      }
      Storage.set('watchedThreads', this.data);
      this.renderList();
    },
    renderList() {
      const list = $('#twList', this.el);
      if (!list) return;
      const entries = Object.entries(this.data);
      if (!entries.length) { list.innerHTML = '<div class="twEntry">No watched threads</div>'; return; }
      list.innerHTML = entries.map(([key, val]) =>
        `<div class="twEntry"><a href="/${val.board}/thread/${val.thread}">${escapeHtml(val.title)}</a></div>`
      ).join('');
      this.el.style.display = 'block';
    }
  };

  /* ============================
   * Post Menu
   * ============================ */
  const PostMenu = {
    init() {
      on(document, 'click', (e) => {
        const btn = e.target.closest('.postMenuBtn');
        if (btn) { e.stopPropagation(); this.show(btn); return; }
        this.hide();
      });
    },
    show(btn) {
      this.hide();
      const postEl = btn.closest('.post');
      const postId = postEl?.id?.replace('p', '');
      if (!postId) return;
      const menu = create('ul', { class: 'postMenu' });
      menu.innerHTML = `
        <li data-action="report" data-id="${postId}">Report</li>
        <li data-action="hide" data-id="${postId}">Hide post</li>
        <li data-action="filter-name" data-id="${postId}">Filter name</li>
        <li data-action="highlight" data-id="${postId}">Highlight</li>`;
      const rect = btn.getBoundingClientRect();
      menu.style.left = rect.left + 'px';
      menu.style.top = (rect.bottom + window.scrollY) + 'px';
      document.body.appendChild(menu);
      on(menu, 'click', (me) => {
        const li = me.target.closest('li');
        if (!li) return;
        const action = li.dataset.action;
        const id = li.dataset.id;
        if (action === 'report') ReportDialog.show(id);
        else if (action === 'hide') PostHiding.hide(id);
        else if (action === 'highlight') PostHighlighting.highlight(id);
        this.hide();
      });
    },
    hide() {
      $$('.postMenu').forEach(m => m.remove());
    }
  };

  /* ============================
   * Post Hiding
   * ============================ */
  const PostHiding = {
    hidden: new Set(),
    init() {
      this.hidden = new Set(Storage.get('hiddenPosts', []));
      this.applyAll();
    },
    hide(postId) {
      this.hidden.add(postId);
      Storage.set('hiddenPosts', [...this.hidden]);
      this.apply(postId);
    },
    apply(postId) {
      const el = $(`#pc${postId}`);
      if (el) el.style.display = 'none';
    },
    applyAll() {
      this.hidden.forEach(id => this.apply(id));
    }
  };

  /* ============================
   * Post Highlighting
   * ============================ */
  const PostHighlighting = {
    highlight(postId) {
      const el = $(`#p${postId}`);
      if (el) {
        el.classList.add('highlight');
        setTimeout(() => el.classList.remove('highlight'), 3000);
      }
    }
  };

  /* ============================
   * Report Dialog
   * ============================ */
  const ReportDialog = {
    show(postId) {
      this.hide();
      const overlay = create('div', { id: 'reportOverlay' });
      overlay.innerHTML = `
        <div id="reportForm">
          <h3>Report Post No.${postId}</h3>
          <form>
            <label><input type="radio" name="reason" value="rule_violation" checked> Rule violation</label>
            <label><input type="radio" name="reason" value="spam"> Spam</label>
            <label><input type="radio" name="reason" value="illegal"> Illegal content</label>
            <label><input type="radio" name="reason" value="other"> Other</label>
            <textarea name="details" placeholder="Additional details (optional)"></textarea>
            <div class="reportBtns">
              <button type="button" id="reportCancel">Cancel</button>
              <button type="submit">Submit</button>
            </div>
          </form>
        </div>`;
      document.body.appendChild(overlay);
      on($('#reportCancel', overlay), 'click', () => this.hide());
      on($('form', overlay), 'submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        try {
          await fetch(`${Config.apiBase}/reports`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId, reason: fd.get('reason'), details: fd.get('details') })
          });
          alert('Report submitted');
        } catch { alert('Failed to submit report'); }
        this.hide();
      });
    },
    hide() {
      const el = $('#reportOverlay');
      if (el) el.remove();
    }
  };

  /* ============================
   * Settings Panel
   * ============================ */
  const SettingsPanel = {
    init() {
      on(document, 'click', (e) => {
        if (e.target.closest('#settingsBtn')) {
          e.preventDefault();
          this.toggle();
        }
      });
    },
    toggle() {
      const existing = $('#settingsOverlay');
      if (existing) { existing.remove(); $('#settingsPanel')?.remove(); return; }
      const overlay = create('div', { id: 'settingsOverlay' });
      const panel = create('div', { id: 'settingsPanel' });
      panel.innerHTML = `
        <h3>Settings</h3>
        <label><input type="checkbox" id="setAutoUpdate" ${Storage.get('autoUpdate', true) ? 'checked' : ''}> Auto-update threads</label>
        <label><input type="checkbox" id="setRelativeTime" ${Storage.get('relativeTime', false) ? 'checked' : ''}> Relative timestamps</label>
        <label><input type="checkbox" id="setNsfwSpoiler" ${Storage.get('nsfwSpoiler', false) ? 'checked' : ''}> Spoiler NSFW images</label>
        <label><input type="checkbox" id="setInlineQuotes" ${Storage.get('inlineQuotes', true) ? 'checked' : ''}> Inline quote previews</label>
        <br>
        <button id="settingsClose">Close</button>`;
      document.body.appendChild(overlay);
      document.body.appendChild(panel);
      on(overlay, 'click', () => this.toggle());
      on($('#settingsClose', panel), 'click', () => this.toggle());
      on($('#setAutoUpdate', panel), 'change', (e) => Storage.set('autoUpdate', e.target.checked));
      on($('#setRelativeTime', panel), 'change', (e) => Storage.set('relativeTime', e.target.checked));
      on($('#setNsfwSpoiler', panel), 'change', (e) => Storage.set('nsfwSpoiler', e.target.checked));
      on($('#setInlineQuotes', panel), 'change', (e) => Storage.set('inlineQuotes', e.target.checked));
    }
  };

  /* ============================
   * Keyboard Shortcuts
   * ============================ */
  const Shortcuts = {
    init() {
      on(document, 'keydown', (e) => {
        if (e.target.matches('input, textarea, select')) return;
        switch (e.key) {
          case 'q': QuickReply.show(); e.preventDefault(); break;
          case 'Escape': QuickReply.hide(); break;
        }
      });
    }
  };

  /* ============================
   * Catalog
   * ============================ */
  const Catalog = {
    init() {
      const search = $('#catalog-search-input');
      if (!search) return;
      on(search, 'input', () => {
        const q = search.value.toLowerCase();
        $$('.catalog-thread').forEach(t => {
          const text = (t.textContent || '').toLowerCase();
          t.style.display = text.includes(q) ? '' : 'none';
        });
      });
    }
  };

  /* ============================
   * Consent / GDPR / COPPA Banner
   * ============================ */
  const ConsentBanner = {
    init() {
      if (Storage.get('consentGiven', false)) return;
      const banner = create('div', { id: 'consentBanner' });
      banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#333;color:#fff;padding:12px 20px;z-index:99999;text-align:center;font-size:11pt;';
      banner.innerHTML = `
        This site uses cookies for essential functionality. By using this site you confirm that you are 13 years or older and agree to our
        <a href="/legal/privacy" style="color:#81A2BE">Privacy Policy</a> and
        <a href="/legal/terms" style="color:#81A2BE">Terms of Service</a>.
        <button id="consentAccept" style="margin-left:15px;padding:5px 15px;cursor:pointer;">I Accept</button>
        <button id="consentDecline" style="margin-left:5px;padding:5px 15px;cursor:pointer;">Decline</button>`;
      document.body.appendChild(banner);
      on($('#consentAccept', banner), 'click', () => {
        Storage.set('consentGiven', true);
        banner.remove();
        fetch(`${Config.apiBase}/consent`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ consented: true, policy_version: '1.0' })
        }).catch(() => {});
      });
      on($('#consentDecline', banner), 'click', () => {
        banner.innerHTML = '<p>You must accept to use this site. <a href="/" style="color:#81A2BE">Return home</a></p>';
      });
    }
  };

  /* ============================
   * Delete Post Form
   * ============================ */
  const DeleteForm = {
    init() {
      const form = $('form.deleteform, #delform');
      if (!form) return;
      on(form, 'submit', async (e) => {
        e.preventDefault();
        const checked = $$('input[type="checkbox"][name]:checked', form);
        if (!checked.length) { alert('Select posts to delete'); return; }
        const pwd = $('#delPassword')?.value || '';
        const onlyImg = $('input[name="onlyimgdel"]', form)?.checked || false;
        const ids = checked.map(cb => cb.name);
        try {
          await fetch(`${Config.apiBase}/posts/delete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids, password: pwd, image_only: onlyImg })
          });
          window.location.reload();
        } catch { alert('Delete failed'); }
      });
    }
  };

  /* ============================
   * Hash Navigation
   * ============================ */
  const HashNav = {
    init() {
      if (location.hash) this.scrollTo(location.hash);
      on(window, 'hashchange', () => this.scrollTo(location.hash));
    },
    scrollTo(hash) {
      if (!hash) return;
      const el = $(hash);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        PostHighlighting.highlight(hash.replace('#p', '').replace('#pc', ''));
      }
    }
  };

  /* ============================
   * Boot
   * ============================ */
  function init() {
    ThemeManager.init();
    PostQuoting.init();
    ImageExpansion.init();
    QuotePreview.init();
    QuickReply.init();
    AutoUpdate.init();
    ThreadWatcher.init();
    PostMenu.init();
    PostHiding.init();
    SettingsPanel.init();
    Shortcuts.init();
    Catalog.init();
    ConsentBanner.init();
    DeleteForm.init();
    HashNav.init();
  }

  if (document.readyState === 'loading') {
    on(document, 'DOMContentLoaded', init);
  } else {
    init();
  }
})();
