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
 * ashchan extension.js
 * Native extension: settings, reply hiding, filters, backlinks, ID coloring,
 * quick reply, keyboard navigation, thread watcher, and more.
 *
 * Settings menu replicates 4chan's native extension settings 1:1.
 * Patterns adapted from OpenYotsuba (BSD 3-clause license).
 */

(function() {
'use strict';

// ---- Icon Data URIs (theme-matched close/expand/collapse) ----
var ICON = {
  // 14x14 "X" close button
  close: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAA4AAAAOCAYAAAAfSC3RAAAAQklEQVQoz2NgoBAw0sSmf///z4Csif3////sDBgA+38GhOb/DGjM/xkIsJkBmyYGrE7Coh+HJozmYDUHu1OxmkMpAACISx3pHKle8AAAAABJRU5ErkJggg==',
  // 15x15 minus (expanded category)
  collapse: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAA8AAAAPCAYAAAA71pVKAAAAMElEQVQoz2P4////fwYKASM2wf8MDAxEaWLAJfjv3z8GojQx4BL89+8fA1GaKAYAstgTD5lMvYIAAAAASUVORK5CYII=',
  // 15x15 plus (collapsed category)
  expand: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAA8AAAAPCAYAAAA71pVKAAAAPUlEQVQoz2P4////fwYKASNRmv79+8dArGYGBuI1M+DT/J8BpyYGfJr//fvHQJRmBnya//37x0CUZooBACvyGOkKh0FwAAAAAElFTkSuQmCC'
};

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
    if ($.hasClass(el, cls)) { $.removeClass(el, cls); }
    else { $.addClass(el, cls); }
  },
  el: function(tag) { return document.createElement(tag); }
};

var UA = {
  hasWebStorage: (function() {
    try { localStorage.setItem('t','t'); localStorage.removeItem('t'); return true; }
    catch(e) { return false; }
  })(),
  isMobileDevice: /Mobile|Android|Opera Mobi|Nintendo/.test(navigator.userAgent)
};

// ---- Main Module ----
var Main = {
  board: null, tid: null, isThread: false,
  init: function() {
    var b = document.body;
    Main.board = b.getAttribute('data-board-slug');
    Main.tid   = b.getAttribute('data-thread-id');
    Main.isThread = $.hasClass(b, 'is_thread');
  }
};

// ============================================================
//  CONFIG — every 4chan native-extension option, 1:1
// ============================================================
var Config = {
  // Quotes & Replying
  quotePreview:   true,
  backlinks:      true,
  inlineQuotes:   false,
  quickReply:     true,
  persistentQR:   false,

  // Monitoring
  threadUpdater:      true,
  alwaysAutoUpdate:   false,
  threadWatcher:      false,
  threadAutoWatcher:  false,
  autoScroll:         false,
  updaterSound:       false,
  fixedThreadWatcher: false,
  threadStats:        true,

  // Filters & Post Hiding
  filter:       false,
  threadHiding: true,
  hideStubs:    false,

  // Navigation
  threadExpansion: true,
  dropDownNav:    false,
  classicNav:     false,
  autoHideNav:    false,
  customMenu:     false,
  alwaysDepage:   false,
  topPageNav:     false,
  stickyNav:      false,
  keyBinds:       false,

  // Images & Media
  imageExpansion:      true,
  fitToScreenExpansion:false,
  imageHover:          false,
  imageHoverBg:        false,
  revealSpoilers:      false,
  unmuteWebm:          false,
  noPictures:          false,
  embedYouTube:        true,
  embedSoundCloud:     false,

  // Miscellaneous
  linkify:         false,
  customCSS:       false,
  IDColor:         true,
  compactThreads:  false,
  centeredThreads: false,
  localTime:       true,
  forceHTTPS:      false,

  // Master kill-switch
  disableAll: false,

  /* persistence */
  load: function() {
    if (!UA.hasWebStorage) return;
    var s = localStorage.getItem('ashchan-settings');
    if (s) { try { var p = JSON.parse(s);
      for (var k in p) if (Config.hasOwnProperty(k) && typeof Config[k]!=='function') Config[k]=p[k];
    } catch(e){} }
  },
  save: function() {
    if (!UA.hasWebStorage) return;
    var o={};
    for (var k in Config) if (Config.hasOwnProperty(k) && typeof Config[k]!=='function') o[k]=Config[k];
    localStorage.setItem('ashchan-settings', JSON.stringify(o));
  },
  toURL: function() {
    var c={};
    c.settings = localStorage.getItem('ashchan-settings');
    var f = localStorage.getItem('ashchan-filters'); if(f) c.filters=f;
    var s = localStorage.getItem('ashchan-css');     if(s) c.css=s;
    return encodeURIComponent(JSON.stringify(c));
  }
};

// ============================================================
//  REPLY HIDING
// ============================================================
var ReplyHiding = {
  hidden: {},
  init: function() {
    if (!UA.hasWebStorage) return;
    var s = localStorage.getItem('ashchan-hidden-replies-' + Main.board);
    if (s) try { ReplyHiding.hidden = JSON.parse(s); } catch(e){}
  },
  save: function() {
    if (!UA.hasWebStorage) return;
    var k = 'ashchan-hidden-replies-' + Main.board;
    Object.keys(ReplyHiding.hidden).length ? localStorage.setItem(k, JSON.stringify(ReplyHiding.hidden)) : localStorage.removeItem(k);
  },
  toggle: function(pid) {
    ReplyHiding.hidden[pid] ? (delete ReplyHiding.hidden[pid], ReplyHiding.show(pid)) : (ReplyHiding.hidden[pid]=Date.now(), ReplyHiding.hide(pid));
    ReplyHiding.save();
  },
  hide: function(pid) {
    var p = $.id('p'+pid); if (!p) return;
    var s = $.el('div'); s.id='stub-'+pid; s.className='stub';
    s.innerHTML='<a href="#" data-cmd="show-reply" data-pid="'+pid+'">[+] Post No.'+pid+' hidden</a>';
    p.style.display='none'; p.parentNode.insertBefore(s,p);
  },
  show: function(pid) {
    var p = $.id('p'+pid), s = $.id('stub-'+pid);
    if (p) p.style.display=''; if (s) s.remove();
  },
  applyAll: function() { for (var pid in ReplyHiding.hidden) ReplyHiding.hide(pid); }
};

// ============================================================
//  THREAD HIDING
// ============================================================
var ThreadHiding = {
  hidden: {},
  init: function() {
    if (!Config.threadHiding || !UA.hasWebStorage) return;
    var s = localStorage.getItem('ashchan-hidden-threads-' + Main.board);
    if (s) try { ThreadHiding.hidden = JSON.parse(s); } catch(e){}
    ThreadHiding.applyAll();
  },
  save: function() {
    if (!UA.hasWebStorage) return;
    var k = 'ashchan-hidden-threads-' + Main.board;
    Object.keys(ThreadHiding.hidden).length ? localStorage.setItem(k, JSON.stringify(ThreadHiding.hidden)) : localStorage.removeItem(k);
  },
  toggle: function(tid) {
    ThreadHiding.hidden[tid] ? (delete ThreadHiding.hidden[tid], ThreadHiding.show(tid)) : (ThreadHiding.hidden[tid]=Date.now(), ThreadHiding.hide(tid));
    ThreadHiding.save();
  },
  hide: function(tid) {
    var t = $.id('t'+tid); if (!t) return;
    if (!Config.hideStubs) {
      var s = $.el('div'); s.id='stub-t'+tid; s.className='stub';
      s.innerHTML='<a href="#" data-cmd="show-thread" data-tid="'+tid+'">[+] Thread No.'+tid+' hidden</a>';
      t.style.display='none'; t.parentNode.insertBefore(s,t);
    } else { t.style.display='none'; }
  },
  show: function(tid) {
    var t = $.id('t'+tid), s = $.id('stub-t'+tid);
    if (t) t.style.display=''; if (s) s.remove();
  },
  applyAll: function() { for (var tid in ThreadHiding.hidden) ThreadHiding.hide(tid); },
  clearHistory: function() {
    var stubs = $.qsa('.stub[id^="stub-t"]');
    for (var i=0;i<stubs.length;i++) { var tid=stubs[i].id.replace('stub-t',''); ThreadHiding.show(tid); }
    ThreadHiding.hidden={}; ThreadHiding.save();
  }
};

// ============================================================
//  ID COLOR
// ============================================================
var IDColor = {
  enabled:true, cache:{},
  init: function() { IDColor.enabled = Config.IDColor; },
  apply: function(el) {
    if (!IDColor.enabled || !el) return;
    var id = el.textContent; if (!id || id==='Heaven') return;
    if (!IDColor.cache[id]) IDColor.cache[id] = IDColor.generate(id);
    var c = IDColor.cache[id];
    el.style.backgroundColor=c.bg; el.style.color=c.fg;
    el.style.padding='0 3px'; el.style.borderRadius='3px';
  },
  generate: function(id) {
    var h=0; for (var i=0;i<id.length;i++) { h=((h<<5)-h)+id.charCodeAt(i); h=h&h; }
    var hue=Math.abs(h)%360, sat=50+(Math.abs(h>>8)%30), lit=60+(Math.abs(h>>16)%20);
    var bg='hsl('+hue+','+sat+'%,'+lit+'%)';
    return { bg:bg, fg: lit>70?'#000':'#fff' };
  }
};

// ============================================================
//  BACKLINKS
// ============================================================
var Backlinks = {
  init: function() {
    if (!Config.backlinks || !Main.isThread) return;
    var posts = $.qsa('.post'); for (var i=0;i<posts.length;i++) Backlinks.parse(posts[i]);
  },
  parse: function(post) {
    var pid = post.id.replace('p',''), msg = $.qs('.postMessage', post);
    if (!msg) return;
    var qls = $.qsa('.quotelink', msg);
    for (var i=0;i<qls.length;i++) {
      var href = qls[i].getAttribute('href')||'', m = href.match(/#p(\d+)/);
      if (!m) continue;
      var tp = m[1], target = $.id('p'+tp); if (!target) continue;
      if (tp===Main.tid && qls[i].textContent.indexOf('(OP)')===-1) qls[i].textContent+=' (OP)';
      var bl = $.id('bl_'+tp);
      if (!bl) { bl=$.el('div'); bl.id='bl_'+tp; bl.className='backlink'; target.appendChild(bl); }
      if ($.qs('a[href="#p'+pid+'"]', bl)) continue;
      var s=$.el('span'); s.innerHTML='<a href="#p'+pid+'" class="quotelink">&gt;&gt;'+pid+'</a> ';
      bl.appendChild(s);
    }
  }
};

// ============================================================
//  QUOTE PREVIEW
// ============================================================
var QuotePreview = {
  previewEl:null, timeout:null,
  init: function() {
    if (!Config.quotePreview) return;
    $.on(document,'mouseover',QuotePreview.onOver);
    $.on(document,'mouseout',QuotePreview.onOut);
  },
  onOver: function(e) {
    var t=e.target;
    if (!$.hasClass(t,'quotelink') && !$.hasClass(t,'quoteLink')) return;
    clearTimeout(QuotePreview.timeout);
    QuotePreview.timeout = setTimeout(function(){ QuotePreview.show(t); }, 100);
  },
  onOut: function(e) {
    var t=e.target;
    if (!$.hasClass(t,'quotelink') && !$.hasClass(t,'quoteLink')) return;
    clearTimeout(QuotePreview.timeout); QuotePreview.hide();
  },
  show: function(link) {
    var href=link.getAttribute('href')||'', m=href.match(/#p(\d+)/);
    if (!m) return;
    var post = $.id('p'+m[1]); if (!post) return;
    QuotePreview.hide();
    var pv = $.el('div'); pv.className='preview posthover'; pv.innerHTML=post.innerHTML; pv.id='quote-preview';
    var r = link.getBoundingClientRect();
    pv.style.position='absolute'; pv.style.left=(r.right+window.scrollX+5)+'px'; pv.style.top=(r.top+window.scrollY)+'px'; pv.style.zIndex='100';
    document.body.appendChild(pv); QuotePreview.previewEl=pv;
    var pr = pv.getBoundingClientRect();
    if (pr.right>window.innerWidth) pv.style.left=(r.left+window.scrollX-pr.width-5)+'px';
    if (pr.bottom>window.innerHeight) pv.style.top=(window.innerHeight+window.scrollY-pr.height-10)+'px';
  },
  hide: function() { if (QuotePreview.previewEl) { QuotePreview.previewEl.remove(); QuotePreview.previewEl=null; } }
};

// ============================================================
//  INLINE QUOTES
// ============================================================
var InlineQuotes = {
  init: function() { if (!Config.inlineQuotes) return; $.on(document,'click',InlineQuotes.onClick); },
  onClick: function(e) {
    var t=e.target; if (!$.hasClass(t,'quotelink')) return;
    if (e.shiftKey) return;
    var href=t.getAttribute('href')||'', m=href.match(/#p(\d+)/); if (!m) return;
    var post = $.id('p'+m[1]); if (!post) return;
    e.preventDefault();
    var existing = t.nextElementSibling;
    if (existing && $.hasClass(existing,'inlined')) { existing.remove(); $.removeClass(t,'inlined-link'); return; }
    var w = $.el('div'); w.className='inlined';
    w.style.cssText='border-left:2px solid #789922;padding-left:5px;margin:2px 0';
    w.innerHTML=post.innerHTML;
    t.parentNode.insertBefore(w, t.nextSibling); $.addClass(t,'inlined-link');
  }
};

// ============================================================
//  IMAGE HOVER
// ============================================================
var ImageHover = {
  previewEl:null,
  init: function() {
    if (!Config.imageHover) return;
    $.on(document,'mouseover',ImageHover.onOver);
    $.on(document,'mouseout',ImageHover.onOut);
    $.on(document,'mousemove',ImageHover.onMove);
  },
  onOver: function(e) {
    var t=e.target; if (t.tagName!=='IMG') return;
    var th = t.closest('.fileThumb'); if (!th) return;
    var url=th.href; if (!url || /\.(webm|mp4)$/i.test(url)) return;
    ImageHover.show(url,e);
  },
  onOut: function(e) { if (e.target.tagName==='IMG') ImageHover.hide(); },
  onMove: function(e) {
    if (!ImageHover.previewEl) return;
    var x=e.clientX+10, y=e.clientY+10;
    var mx=window.innerWidth-ImageHover.previewEl.offsetWidth-10;
    var my=window.innerHeight-ImageHover.previewEl.offsetHeight-10;
    if (x>mx) x=e.clientX-ImageHover.previewEl.offsetWidth-10;
    if (y>my) y=e.clientY-ImageHover.previewEl.offsetHeight-10;
    ImageHover.previewEl.style.left=x+'px'; ImageHover.previewEl.style.top=y+'px';
  },
  show: function(url,e) {
    ImageHover.hide();
    var img=$.el('img'); img.id='image-hover'; img.src=url;
    img.style.cssText='position:fixed;max-width:80vw;max-height:80vh;z-index:9999;pointer-events:none;box-shadow:0 0 10px rgba(0,0,0,.5)';
    if (Config.imageHoverBg) img.style.backgroundColor='#fff';
    document.body.appendChild(img); ImageHover.previewEl=img; ImageHover.onMove(e);
  },
  hide: function() { if (ImageHover.previewEl) { ImageHover.previewEl.remove(); ImageHover.previewEl=null; } }
};

// ============================================================
//  LINKIFY
// ============================================================
var Linkify = {
  re: /\b(https?:\/\/[^\s<>\[\]"]+)/gi,
  init: function() {
    if (!Config.linkify) return;
    var msgs = $.qsa('.postMessage'); for (var i=0;i<msgs.length;i++) Linkify.exec(msgs[i]);
  },
  exec: function(msg) {
    if (!msg) return;
    var w = document.createTreeWalker(msg, NodeFilter.SHOW_TEXT, null, false), nodes=[];
    while (w.nextNode()) { if (w.currentNode.parentNode.tagName!=='A') nodes.push(w.currentNode); }
    for (var i=0;i<nodes.length;i++) {
      var n=nodes[i], t=n.textContent; if (!Linkify.re.test(t)) continue; Linkify.re.lastIndex=0;
      var f=document.createDocumentFragment(), last=0, m;
      while ((m=Linkify.re.exec(t))!==null) {
        if (m.index>last) f.appendChild(document.createTextNode(t.substring(last,m.index)));
        var a=$.el('a'); a.href=m[1]; a.textContent=m[1]; a.target='_blank'; a.rel='noopener noreferrer';
        f.appendChild(a); last=Linkify.re.lastIndex;
      }
      if (last<t.length) f.appendChild(document.createTextNode(t.substring(last)));
      n.parentNode.replaceChild(f,n);
    }
  }
};

// ============================================================
//  LOCAL TIME
// ============================================================
var LocalTime = {
  days: ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
  init: function() {
    if (!Config.localTime) return;
    var ds = $.qsa('.dateTime'); for (var i=0;i<ds.length;i++) LocalTime.convert(ds[i]);
  },
  convert: function(el) {
    var u = el.getAttribute('data-utc'); if (!u) return;
    var d = new Date(parseInt(u,10)*1000);
    el.textContent = LocalTime.fmt(d);
    el.title = 'UTC: ' + d.toUTCString();
  },
  fmt: function(d) {
    var p = function(n){ return n<10?'0'+n:n; };
    return p(d.getMonth()+1)+'/'+p(d.getDate())+'/'+(d.getFullYear()+'').slice(-2)+'('+
      LocalTime.days[d.getDay()]+')'+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());
  }
};

// ============================================================
//  QUICK REPLY — matches 4chan structure exactly
// ============================================================
var QuickReply = {
  el:null, persist:false, btn:null, comField:null,
  init: function() { if (!Config.quickReply) return; QuickReply.persist=Config.persistentQR; },
  open: function(pid) {
    if (QuickReply.el) {
      var ta = $.qs('textarea',QuickReply.el);
      if (ta && pid) { QuickReply.addQuote(pid); }
      return;
    }
    var slug=Main.board, tid=Main.tid, cnt=$.el('div');
    cnt.id='quickReply'; cnt.className='extPanel reply';
    cnt.setAttribute('data-trackpos','QR-position');
    cnt.style.right='0px'; cnt.style.top='10%';

    // Header: postblock style, draggable
    var h = '<div id="qrHeader" class="drag postblock">'
      + 'Reply to Thread No.<span id="qrTid">'+tid+'</span>'
      + '<img alt="X" src="'+ICON.close+'" id="qrClose" class="extButton" title="Close Window">'
      + '</div>';

    // Form
    h += '<form name="qrPost" action="/api/v1/boards/'+slug+'/threads/'+tid+'/replies" method="post" enctype="multipart/form-data">';
    h += '<input type="hidden" value="'+tid+'" name="resto">';
    h += '<div id="qrForm">';

    // Name
    h += '<div><input type="text" name="name" placeholder="Name" tabindex="" autocomplete="off"></div>';
    // Options (email/sage)
    h += '<div><input type="text" name="email" id="qrEmail" placeholder="Options" tabindex="" autocomplete="off"></div>';
    // Comment
    h += '<div><textarea name="com" cols="48" rows="4" maxlength="2000" tabindex=""></textarea></div>';
    // ALTCHA Captcha
    h += '<div id="qrCaptchaContainer"><altcha-widget id="qrAltcha" challengeurl="/api/v1/altcha/challenge" name="altcha" hidefooter hidelogo auto="onfocus"></altcha-widget></div>';
    // File + Spoiler + Submit button
    h += '<div><input type="file" name="upfile" id="qrFile" size="19" accept="image/jpeg,image/png,image/gif,image/webp" tabindex="">'
       + '<span id="qrSpoiler"><label>[<input type="checkbox" value="on" name="spoiler">Spoiler?]</label></span>'
       + '<input type="submit" value="Post" tabindex=""></div>';

    h += '</div></form>';
    // Error
    h += '<div id="qrError"></div>';

    cnt.innerHTML=h;
    document.body.appendChild(cnt); QuickReply.el=cnt;

    QuickReply.comField = $.qs('textarea[name="com"]',cnt);
    QuickReply.btn = $.qs('input[type="submit"]',cnt);

    if (pid) QuickReply.addQuote(pid);

    // Draggable header
    QuickReply.makeDraggable(cnt);

    // Close button
    $.on($.id('qrClose'),'click',function(){ QuickReply.close(); });

    // Submit
    var form = $.qs('form[name="qrPost"]',cnt);
    if(form) $.on(form,'submit',function(e){e.preventDefault();QuickReply.submit(form);});

    // File shift-click to clear
    var fi=$.id('qrFile');
    if(fi) $.on(fi,'click',function(e){ if(e.shiftKey){e.preventDefault();QuickReply.resetFile();} });

    // Keyboard: Ctrl+S for spoiler, Esc to close
    if(QuickReply.comField) $.on(QuickReply.comField,'keydown',QuickReply.onKeyDown);
  },
  addQuote: function(pid) {
    var ta = QuickReply.comField; if(!ta) return;
    var a=ta.selectionStart, sel=window.getSelection().toString(), t='>>'+pid+'\n';
    if (sel) t += '>'+sel.trim().replace(/[\r\n]+/g,'\n>')+'\n';
    ta.value ? (ta.value=ta.value.slice(0,a)+t+ta.value.slice(ta.selectionEnd)) : (ta.value=t);
    ta.selectionStart=ta.selectionEnd=a+t.length;
    if (ta.selectionStart==ta.value.length) ta.scrollTop=ta.scrollHeight;
    ta.focus();
  },
  onKeyDown: function(e) {
    if (e.ctrlKey && e.keyCode===83) {
      e.stopPropagation(); e.preventDefault();
      var t=e.target, a=t.selectionStart, i=t.selectionEnd;
      var n='[spoiler]'+t.value.slice(a,i)+'[/spoiler]';
      t.value=t.value.slice(0,a)+n+t.value.slice(i);
      t.setSelectionRange(i+19,i+19);
    } else if (e.keyCode===27 && !e.ctrlKey && !e.altKey && !e.shiftKey) {
      QuickReply.close();
    }
  },
  close: function() {
    if(QuickReply.el){
      QuickReply.comField=null; QuickReply.btn=null;
      QuickReply.el.remove(); QuickReply.el=null;
    }
  },
  resetFile: function() {
    var old=$.id('qrFile'); if(!old) return;
    var nf=$.el('input'); nf.id='qrFile'; nf.type='file'; nf.size='19'; nf.name='upfile';
    nf.accept='image/jpeg,image/png,image/gif,image/webp';
    old.parentNode.replaceChild(nf,old);
    $.on(nf,'click',function(e){ if(e.shiftKey){e.preventDefault();QuickReply.resetFile();} });
  },
  showPostError: function(msg) {
    var el=$.id('qrError'); if(!el) return;
    if(msg){el.innerHTML=msg;el.style.display='block';}else{el.removeAttribute('style');}
  },
  submit: function(form) {
    // Check if ALTCHA widget has been verified
    var altchaWidget = $.id('qrAltcha');
    var altchaInput = $.qs('input[name="altcha"]', form);
    if (altchaWidget && (!altchaInput || !altchaInput.value)) {
      QuickReply.showPostError('Please wait for verification to complete.');
      return;
    }
    var fd=new FormData(form);
    QuickReply.showPostError('');
    if(QuickReply.btn) QuickReply.btn.value='Sending';
    var xhr=new XMLHttpRequest();
    xhr.open('POST',form.action,true);
    xhr.setRequestHeader('Accept','application/json');
    xhr.upload.onprogress=function(e){
      if(e.loaded>=e.total){if(QuickReply.btn)QuickReply.btn.value='100%';}
      else{if(QuickReply.btn)QuickReply.btn.value=(0|e.loaded/e.total*100)+'%';}
    };
    xhr.onerror=function(){if(QuickReply.btn)QuickReply.btn.value='Post';QuickReply.showPostError('Connection error.');};
    xhr.onload=function(){
      if(QuickReply.btn) QuickReply.btn.value='Post';
      if(xhr.status===200||xhr.status===201){
        try{var d=JSON.parse(xhr.responseText);
          if(d.error){QuickReply.showPostError(d.error);return;}
        }catch(e){}
        if(QuickReply.persist){
          var ta=$.qs('textarea[name="com"]',form);if(ta)ta.value='';
          var sp=$.qs('input[name="spoiler"]',form);if(sp)sp.checked=false;
          QuickReply.resetFile();
          // Reset ALTCHA widget for next post
          var aw=$.id('qrAltcha');if(aw&&aw.reset)aw.reset();
        }else{QuickReply.close();}
        location.reload();
      }else{QuickReply.showPostError('Error: '+xhr.status+' '+xhr.statusText);}
    };
    xhr.send(fd);
  },
  makeDraggable: function(el) {
    var hdr=$.id('qrHeader'); if(!hdr) return;
    var ox,oy,drag=false;
    $.on(hdr,'mousedown',function(e){if(e.target.id==='qrClose')return;drag=true;ox=e.clientX-el.getBoundingClientRect().left;oy=e.clientY-el.getBoundingClientRect().top;e.preventDefault();});
    $.on(document,'mousemove',function(e){if(!drag)return;el.style.left=(e.clientX-ox)+'px';el.style.top=(e.clientY-oy)+'px';el.style.right='auto';el.style.bottom='auto';});
    $.on(document,'mouseup',function(){drag=false;});
  }
};

// ============================================================
//  THREAD STATS (fixed, bottom-right)
// ============================================================
var ThreadStats = {
  init: function() {
    if (!Config.threadStats || !Main.isThread) return;
    var posts=$.qsa('.post'), images=$.qsa('.file');
    var el=$.el('div'); el.id='threadStats';
    el.innerHTML='Replies: <span id="ts-replies">'+(posts.length-1)+'</span> / Images: <span id="ts-images">'+images.length+'</span>';
    document.body.appendChild(el);
  },
  update: function() {
    var r=$.id('ts-replies'),im=$.id('ts-images'); if(!r||!im) return;
    r.textContent=$.qsa('.post').length-1; im.textContent=$.qsa('.file').length;
  }
};

// ============================================================
//  UPDATER SOUND
// ============================================================
var UpdaterSound = {
  audio:null,
  init: function() {
    if (!Config.updaterSound) return;
    UpdaterSound.audio=$.el('audio');
    // A short beep encoded as a tiny WAV
    UpdaterSound.audio.src='data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQ==';
    UpdaterSound.audio.volume=0.5;
  },
  play: function() { if(UpdaterSound.audio) try{UpdaterSound.audio.play();}catch(e){} }
};

// ============================================================
//  DROP-DOWN NAVIGATION
// ============================================================
var DropDownNav = {
  el:null, lastY:0,
  init: function() {
    if (!Config.dropDownNav) return;
    var boards = $.qsa('#boardNavDesktop .boardList a'); if (!boards.length) return;
    var nav=$.el('div'); nav.id='dropDownNav'; nav.className='reply';
    if (Config.classicNav) {
      var h='[ '; for(var i=0;i<boards.length;i++){if(i)h+=' / ';h+='<a href="'+boards[i].href+'">'+boards[i].textContent+'</a>';} h+=' ]'; nav.innerHTML=h;
    } else { nav.innerHTML='<a href="/">Home</a> | <a href="/search/">Search</a> | <a href="#" data-cmd="settings-toggle">Settings</a>'; }
    document.body.insertBefore(nav, document.body.firstChild); DropDownNav.el=nav;
    if (Config.autoHideNav) { DropDownNav.lastY=window.scrollY; $.on(window,'scroll',DropDownNav.onScroll); }
  },
  onScroll: function() {
    if (!DropDownNav.el) return;
    var y=window.scrollY;
    (y>DropDownNav.lastY && y>50) ? $.addClass(DropDownNav.el,'hidden') : $.removeClass(DropDownNav.el,'hidden');
    DropDownNav.lastY=y;
  }
};

// ============================================================
//  NAV ARROWS / TOP PAGE NAV
// ============================================================
var NavArrows = {
  init: function() {
    if (!Config.stickyNav) return;
    var el=$.el('div'); el.id='navArrows';
    el.innerHTML='<a href="#top" title="Top">&#x25B2;</a><a href="#bottom" title="Bottom">&#x25BC;</a>';
    document.body.appendChild(el);
  }
};
var TopPageNav = {
  init: function() {
    if (!Config.topPageNav || Main.isThread) return;
    var pl=$.qs('.pagelist'); if (!pl) return;
    var c=pl.cloneNode(true); c.id='topPageNav';
    var df=$.id('delform'); if (df) df.parentNode.insertBefore(c,df);
  }
};

// ============================================================
//  EMBED YOUTUBE / SOUNDCLOUD
// ============================================================
var EmbedYouTube = {
  re: /(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/gi,
  init: function() {
    if (!Config.embedYouTube) return;
    var msgs=$.qsa('.postMessage'); for(var i=0;i<msgs.length;i++) EmbedYouTube.exec(msgs[i]);
  },
  exec: function(msg) {
    var links=$.qsa('a',msg);
    for(var i=0;i<links.length;i++) {
      var href=links[i].href||''; EmbedYouTube.re.lastIndex=0;
      var m=EmbedYouTube.re.exec(href); if(!m) continue;
      if (links[i].nextElementSibling && $.hasClass(links[i].nextElementSibling,'yt-embed-toggle')) continue;
      var s=$.el('span'); s.className='yt-embed-toggle';
      s.innerHTML=' [<a href="javascript:;" data-cmd="yt-embed" data-vid="'+m[1]+'">Embed</a>]';
      links[i].parentNode.insertBefore(s,links[i].nextSibling);
    }
  }
};
var EmbedSoundCloud = {
  re: /https?:\/\/soundcloud\.com\/[^\s<]+/gi,
  init: function() {
    if (!Config.embedSoundCloud) return;
    var msgs=$.qsa('.postMessage'); for(var i=0;i<msgs.length;i++) EmbedSoundCloud.exec(msgs[i]);
  },
  exec: function(msg) {
    var links=$.qsa('a',msg);
    for(var i=0;i<links.length;i++) {
      var href=links[i].href||''; EmbedSoundCloud.re.lastIndex=0;
      if (!EmbedSoundCloud.re.test(href)) continue;
      if (links[i].nextElementSibling && $.hasClass(links[i].nextElementSibling,'sc-embed-toggle')) continue;
      var s=$.el('span'); s.className='sc-embed-toggle';
      s.innerHTML=' [<a href="javascript:;" data-cmd="sc-embed" data-url="'+href+'">Embed</a>]';
      links[i].parentNode.insertBefore(s,links[i].nextSibling);
    }
  }
};

// ============================================================
//  MISC FEATURES
// ============================================================
var ForceHTTPS = { init: function() { if (Config.forceHTTPS && location.protocol==='http:') location.href=location.href.replace('http:','https:'); } };

var CustomCSS = {
  init: function() {
    if (!Config.customCSS || !UA.hasWebStorage) return;
    var css=localStorage.getItem('ashchan-css'); if(!css) return;
    var s=$.el('style'); s.id='customCSSTag'; s.textContent=css; document.head.appendChild(s);
  },
  save: function(css) {
    if (!UA.hasWebStorage) return;
    localStorage.setItem('ashchan-css', css);
    var ex=$.id('customCSSTag');
    if(ex){ex.textContent=css;} else {var s=$.el('style');s.id='customCSSTag';s.textContent=css;document.head.appendChild(s);}
  }
};

var LayoutOptions = {
  init: function() {
    if(Config.compactThreads) $.addClass(document.body,'compactThreads');
    if(Config.centeredThreads) $.addClass(document.body,'centeredThreads');
  }
};

var RevealSpoilers = {
  init: function() {
    if (!Config.revealSpoilers) return;
    var imgs=$.qsa('img[src*="spoiler"]');
    for(var i=0;i<imgs.length;i++) {
      var th=imgs[i].closest('.fileThumb');
      if(th&&th.href) { var ft=$.qs('.fileText a',imgs[i].closest('.file')); if(ft) imgs[i].title=ft.textContent; }
    }
  }
};

var NoPictures = {
  init: function() {
    if (!Config.noPictures) return;
    var t=$.qsa('.fileThumb img'); for(var i=0;i<t.length;i++) t[i].style.display='none';
  }
};

var CustomBoardMenu = {
  init: function() { if(Config.customMenu) CustomBoardMenu.apply(); },
  apply: function() {
    var s=localStorage.getItem('ashchan-custom-menu'); if(!s) return;
    var boards=s.split(',').map(function(b){return b.trim().toLowerCase();}); if(!boards.length) return;
    var lists=$.qsa('.boardList');
    for(var i=0;i<lists.length;i++) {
      var links=$.qsa('a',lists[i]);
      for(var j=0;j<links.length;j++) {
        var sl=links[j].textContent.trim().toLowerCase();
        if(boards.indexOf(sl)===-1) { links[j].style.display='none'; var pv=links[j].previousSibling; if(pv&&pv.nodeType===3&&pv.textContent.trim()==='/') pv.textContent=''; }
      }
    }
  }
};

var InfiniteScroll = {
  loading:false, page:1, total:1,
  init: function() {
    if (!Config.alwaysDepage || Main.isThread) return;
    InfiniteScroll.page = parseInt(document.body.getAttribute('data-page'),10)||1;
    var pls=$.qsa('.pagelist a');
    for(var i=0;i<pls.length;i++){var m=pls[i].href.match(/\/(\d+)$/);if(m){var p=parseInt(m[1],10);if(p>InfiniteScroll.total)InfiniteScroll.total=p;}}
    $.on(window,'scroll',InfiniteScroll.onScroll);
  },
  onScroll: function() {
    if(InfiniteScroll.loading||InfiniteScroll.page>=InfiniteScroll.total) return;
    if(window.scrollY+window.innerHeight>=document.documentElement.scrollHeight-500) InfiniteScroll.loadNext();
  },
  loadNext: function() {
    InfiniteScroll.loading=true; InfiniteScroll.page++;
    fetch('/'+Main.board+'/'+InfiniteScroll.page).then(function(r){return r.text();}).then(function(html){
      var doc=(new DOMParser()).parseFromString(html,'text/html'), ts=doc.querySelectorAll('.thread'), df=$.id('delform');
      if(df&&ts.length) for(var i=0;i<ts.length;i++){df.appendChild($.el('hr'));df.appendChild(ts[i].cloneNode(true));}
      InfiniteScroll.loading=false;
    }).catch(function(){InfiniteScroll.loading=false;});
  }
};

// ============================================================
//  SETTINGS MENU — exact 4chan structure
// ============================================================
var SettingsMenu = {
  // Category definitions: array of {key, label, tip, sub?, action?{text,cmd}}
  categories: {
    'Quotes &amp; Replying': [
      {key:'quotePreview',  label:'Quote preview',         tip:'Show post when mousing over post links'},
      {key:'backlinks',     label:'Backlinks',             tip:'Show who has replied to a post'},
      {key:'inlineQuotes',  label:'Inline quote links',    tip:'Clicking quote links will inline expand the quoted post, Shift-click to bypass inlining'},
      {key:'quickReply',    label:'Quick Reply',           tip:'Quickly respond to a post by clicking its post number'},
      {key:'persistentQR',  label:'Persistent Quick Reply', tip:'Keep Quick Reply window open after posting'}
    ],
    'Monitoring': [
      {key:'threadUpdater',     label:'Thread updater',                          tip:'Append new posts to bottom of thread without refreshing the page'},
      {key:'alwaysAutoUpdate',  label:'Auto-update by default',                  tip:'Always auto-update threads'},
      {key:'threadWatcher',     label:'Thread Watcher',                          tip:'Keep track of threads you\'re watching and see when they receive new posts'},
      {key:'threadAutoWatcher', label:'Automatically watch threads you create',  tip:'',  sub:true},
      {key:'autoScroll',        label:'Auto-scroll with auto-updated posts',     tip:'Automatically scroll the page as new posts are added'},
      {key:'updaterSound',      label:'Sound notification',                      tip:'Play a sound when somebody replies to your post(s)'},
      {key:'fixedThreadWatcher',label:'Pin Thread Watcher to the page',          tip:'Thread Watcher will scroll with you'},
      {key:'threadStats',       label:'Thread statistics',                       tip:'Display post and image counts on the right of the page, <em>italics</em> signify bump/image limit has been met'}
    ],
    'Filters &amp; Post Hiding': [
      {key:'filter',       label:'Filter and highlight specific threads/posts',  tip:'Enable pattern-based filters', action:{text:'Edit',cmd:'filters-open'}},
      {key:'threadHiding', label:'Thread hiding',                                tip:'Hide entire threads by clicking the minus button', action:{text:'Clear History',cmd:'thread-hiding-clear'}},
      {key:'hideStubs',    label:'Hide thread stubs',                            tip:'Don\'t display stubs of hidden threads'}
    ],
    'Navigation': [
      {key:'threadExpansion', label:'Thread expansion',                     tip:'Expand threads inline on board indexes'},
      {key:'dropDownNav',    label:'Use persistent drop-down navigation bar', tip:''},
      {key:'classicNav',     label:'Use traditional board list',            tip:'',  sub:true},
      {key:'autoHideNav',    label:'Auto-hide on scroll',                   tip:'',  sub:true},
      {key:'customMenu',     label:'Custom board list',                     tip:'Only show selected boards in top and bottom board lists', action:{text:'Edit',cmd:'custom-menu-edit'}},
      {key:'alwaysDepage',   label:'Always use infinite scroll',            tip:'Enable infinite scroll by default, so reaching the bottom of the board index will load subsequent pages'},
      {key:'topPageNav',     label:'Page navigation at top of page',        tip:'Show the page switcher at the top of the page, hold Shift and drag to move'},
      {key:'stickyNav',      label:'Navigation arrows',                     tip:'Show top and bottom navigation arrows, hold Shift and drag to move'},
      {key:'keyBinds',       label:'Use keyboard shortcuts',                tip:'Enable handy keyboard shortcuts for common actions', action:{text:'Show',cmd:'keybinds-open'}}
    ],
    'Images &amp; Media': [
      {key:'imageExpansion',      label:'Image expansion',                                tip:'Enable inline image expansion, limited to browser width'},
      {key:'fitToScreenExpansion',label:'Fit expanded images to screen',                  tip:'Limit expanded images to both browser width and height'},
      {key:'imageHover',          label:'Image hover',                                    tip:'Mouse over images to view full size, limited to browser size'},
      {key:'imageHoverBg',        label:'Set a background color for transparent images',  tip:'',sub:true},
      {key:'revealSpoilers',      label:'Don\'t spoiler images',                          tip:'Show image thumbnail and original filename instead of spoiler placeholders'},
      {key:'unmuteWebm',          label:'Un-mute WebM audio',                             tip:'Un-mute sound automatically for WebM playback'},
      {key:'noPictures',          label:'Hide thumbnails',                                tip:'Don\'t display thumbnails while browsing'},
      {key:'embedYouTube',        label:'Embed YouTube links',                            tip:'Embed YouTube player into replies'},
      {key:'embedSoundCloud',     label:'Embed SoundCloud links',                         tip:'Embed SoundCloud player into replies'}
    ],
    'Miscellaneous': [
      {key:'linkify',         label:'Linkify URLs',                tip:'Make user-posted links clickable'},
      {key:'customCSS',       label:'Custom CSS',                  tip:'Include your own CSS rules', action:{text:'Edit',cmd:'css-open'}},
      {key:'IDColor',         label:'Color user IDs',              tip:'Assign unique colors to user IDs on boards that use them'},
      {key:'compactThreads',  label:'Force long posts to wrap',    tip:'Long posts will wrap at 75% browser width'},
      {key:'centeredThreads', label:'Center threads',              tip:'Align threads to the center of page'},
      {key:'localTime',       label:'Convert dates to local time', tip:'Convert server time to your local time'},
      {key:'forceHTTPS',      label:'Always use HTTPS',            tip:'Rewrite URLs to always use HTTPS'}
    ]
  },

  toggle: function() {
    $.qs('.extPanel[data-panel="settings"]') ? SettingsMenu.close() : SettingsMenu.open();
  },

  /* ---- BUILD PANEL (exact 4chan HTML structure) ---- */
  open: function() {
    var cnt = $.el('div');
    cnt.className = 'extPanel reply';
    cnt.setAttribute('data-panel', 'settings');

    var h = '<div class="panelHeader">Settings'
      + '<span class="panelCtrl"><img alt="Close" title="Close" class="pointer" data-cmd="settings-toggle" src="'+ICON.close+'"></span></div>';

    h += '<ul>';

    // [Expand All Settings]
    h += '<ul><li id="settings-exp-all">[<a href="#" data-cmd="settings-exp-all">Expand All Settings</a>]</li></ul>';

    var cats = SettingsMenu.categories;
    for (var cat in cats) {
      if (!cats.hasOwnProperty(cat)) continue;
      var opts = cats[cat];

      h += '<ul>';
      h += '<li class="settings-cat-lbl">'
         + '<img alt="" class="settings-expand" src="'+ICON.expand+'">'
         + '<span class="settings-expand pointer">'+cat+'</span></li>';
      h += '<ul class="settings-cat" style="display: none;">';

      for (var i=0;i<opts.length;i++) {
        var o = opts[i];
        var liCls = o.sub ? ' class="settings-sub"' : '';
        var act   = o.action ? ' [<a href="javascript:;" data-cmd="'+o.action.cmd+'">'+o.action.text+'</a>]' : '';

        h += '<li'+liCls+'><label><input type="checkbox" class="menuOption" data-option="'
           + o.key+'"'+(Config[o.key]?' checked="checked"':'')+'>'+o.label+act+'</label></li>';

        if (o.tip) {
          h += '<li class="'+(o.sub?'settings-tip settings-sub':'settings-tip')+'">'+o.tip+'</li>';
        }
      }
      h += '</ul></ul>';
    }

    h += '</ul>';

    // Disable all
    h += '<ul><li class="settings-off">'
       + '<label title="Completely disable the native extension (overrides any checked boxes)">'
       + '<input type="checkbox" class="menuOption" data-option="disableAll"'
       + (Config.disableAll?' checked="checked"':'')
       + '>Disable the native extension</label></li></ul>';

    // Buttons
    h += '<div class="center"><button data-cmd="settings-export">Export Settings</button>'
       + '<button data-cmd="settings-save">Save Settings</button></div>';

    cnt.innerHTML = h;
    cnt.addEventListener('click', SettingsMenu.onClick, false);
    document.body.appendChild(cnt);
  },

  close: function() {
    var el = $.qs('.extPanel[data-panel="settings"]');
    if (el) { el.removeEventListener('click', SettingsMenu.onClick, false); el.remove(); }
  },

  save: function() {
    var panel = $.qs('.extPanel[data-panel="settings"]'); if (!panel) return;
    var opts = panel.getElementsByClassName('menuOption');
    for (var i=0;i<opts.length;i++) { var k=opts[i].getAttribute('data-option'); Config[k]=opts[i].checked; }
    Config.save(); SettingsMenu.close(); location.reload();
  },

  expandAll: function() {
    var panel = $.qs('.extPanel[data-panel="settings"]'); if (!panel) return;
    var cats=$.qsa('.settings-cat',panel), icons=$.qsa('.settings-cat-lbl img.settings-expand',panel);
    for(var i=0;i<cats.length;i++) cats[i].style.display='block';
    for(var j=0;j<icons.length;j++) icons[j].src=ICON.collapse;
  },

  toggleCategory: function(target) {
    var lbl = target.closest('.settings-cat-lbl'); if (!lbl) return;
    var cat = lbl.nextElementSibling;
    if (!cat || !$.hasClass(cat,'settings-cat')) return;
    var icon = $.qs('img.settings-expand', lbl);
    if (cat.style.display==='none') { cat.style.display='block'; if(icon) icon.src=ICON.collapse; }
    else { cat.style.display='none'; if(icon) icon.src=ICON.expand; }
  },

  /* ---- EXPORT ---- */
  showExport: function() {
    if ($.qs('.extPanel[data-panel="export"]')) return;
    var url = location.href.replace(location.hash,'').replace(/^http:/,'https:')+'#cfg='+Config.toURL();
    var cnt=$.el('div'); cnt.className='extPanel reply'; cnt.setAttribute('data-panel','export');
    cnt.innerHTML='<div class="panelHeader">Export Settings<span class="panelCtrl"><img alt="Close" title="Close" class="pointer" data-cmd="export-close" src="'+ICON.close+'"></span></div>'
      +'<p class="center">Copy and save the URL below, and visit it from another browser or computer to restore your extension settings.</p>'
      +'<p class="center"><input class="export-field" type="text" readonly="readonly" value="'+url+'"></p>'
      +'<p style="margin-top:15px" class="center">Alternatively, drag the link below into your bookmarks bar and click it to restore.</p>'
      +'<p class="center">[<a target="_blank" href="'+url+'">Restore ashchan Settings</a>]</p>';
    document.body.appendChild(cnt);
    var f=$.qs('.export-field',cnt); if(f){f.focus();f.select();}
  },
  closeExport: function() { var c=$.qs('.extPanel[data-panel="export"]'); if(c) c.remove(); },

  /* ---- FILTERS EDITOR ---- */
  openFilters: function() {
    if ($.qs('.extPanel[data-panel="filters"]')) return;
    var saved=localStorage.getItem('ashchan-filters')||'[]';
    var cnt=$.el('div'); cnt.className='extPanel reply'; cnt.setAttribute('data-panel','filters');
    cnt.style.minWidth='500px';
    cnt.innerHTML='<div class="panelHeader">Filters<span class="panelCtrl"><img alt="Close" title="Close" class="pointer" data-cmd="filters-close" src="'+ICON.close+'"></span></div>'
      +'<p style="padding:5px 10px;font-size:11px;">Enter filter patterns, one per line. Format: <code>type:pattern</code> (types: id, name, trip, sub, com, file, flag)<br>Prefix with <code>/</code> for regex. Suffix with <code>;hide</code> or <code>;highlight</code>.</p>'
      +'<textarea id="filterTextarea">'+SettingsMenu.fmtFilters(saved)+'</textarea>'
      +'<div class="center"><button data-cmd="filters-save">Save Filters</button></div>';
    document.body.appendChild(cnt);
  },
  fmtFilters: function(json) {
    try{var a=JSON.parse(json);return a.map(function(f){return f.type+':'+f.pattern+(f.hide?';hide':'')+(f.highlight?';highlight':'');}).join('\n');}catch(e){return '';}
  },
  saveFilters: function() {
    var ta=$.id('filterTextarea'); if(!ta) return;
    var lines=ta.value.split('\n').filter(function(l){return l.trim();}), filters=[];
    for(var i=0;i<lines.length;i++){var l=lines[i].trim(),pts=l.split(':');if(pts.length<2)continue;var type=pts[0],rest=pts.slice(1).join(':'),opts=rest.split(';');
      filters.push({type:type,pattern:opts[0],active:true,hide:opts.indexOf('hide')!==-1,highlight:opts.indexOf('highlight')!==-1});}
    localStorage.setItem('ashchan-filters',JSON.stringify(filters));
    var c=$.qs('.extPanel[data-panel="filters"]'); if(c) c.remove();
  },
  closeFilters: function() { var c=$.qs('.extPanel[data-panel="filters"]'); if(c) c.remove(); },

  /* ---- CUSTOM CSS EDITOR ---- */
  openCSS: function() {
    if ($.qs('.extPanel[data-panel="css"]')) return;
    var saved=localStorage.getItem('ashchan-css')||'';
    var cnt=$.el('div'); cnt.className='extPanel reply'; cnt.setAttribute('data-panel','css');
    cnt.style.minWidth='500px';
    cnt.innerHTML='<div class="panelHeader">Custom CSS<span class="panelCtrl"><img alt="Close" title="Close" class="pointer" data-cmd="css-close" src="'+ICON.close+'"></span></div>'
      +'<textarea id="cssTextarea">'+saved.replace(/</g,'&lt;')+'</textarea>'
      +'<div class="center"><button data-cmd="css-save">Save CSS</button></div>';
    document.body.appendChild(cnt);
  },
  saveCSS: function() {
    var ta=$.id('cssTextarea'); if(!ta) return;
    CustomCSS.save(ta.value); Config.customCSS=true; Config.save();
    var c=$.qs('.extPanel[data-panel="css"]'); if(c) c.remove();
  },
  closeCSS: function() { var c=$.qs('.extPanel[data-panel="css"]'); if(c) c.remove(); },

  /* ---- KEYBOARD SHORTCUTS INFO ---- */
  openKeybinds: function() {
    if ($.qs('.extPanel[data-panel="keybinds"]')) return;
    var cnt=$.el('div'); cnt.className='extPanel reply'; cnt.setAttribute('data-panel','keybinds');
    cnt.style.minWidth='350px';
    cnt.innerHTML='<div class="panelHeader">Keyboard Shortcuts<span class="panelCtrl"><img alt="Close" title="Close" class="pointer" data-cmd="keybinds-close" src="'+ICON.close+'"></span></div>'
      +'<table style="margin:5px;width:calc(100% - 10px)"><tr><td>R</td><td>Update thread</td></tr>'
      +'<tr><td>Q</td><td>Open Quick Reply</td></tr><tr><td>F</td><td>Open Search (catalog)</td></tr>'
      +'<tr><td>Esc</td><td>Close panels / Quick Reply</td></tr><tr><td>Left</td><td>Previous page</td></tr>'
      +'<tr><td>Right</td><td>Next page</td></tr><tr><td>J</td><td>Next post</td></tr>'
      +'<tr><td>K</td><td>Previous post</td></tr><tr><td>H</td><td>Hide/show thread</td></tr>'
      +'<tr><td>E</td><td>Expand all images</td></tr><tr><td>W</td><td>Watch/unwatch thread</td></tr></table>';
    document.body.appendChild(cnt);
  },
  closeKeybinds: function() { var c=$.qs('.extPanel[data-panel="keybinds"]'); if(c) c.remove(); },

  /* ---- CUSTOM BOARD MENU EDITOR ---- */
  openCustomMenu: function() {
    if ($.qs('.extPanel[data-panel="custommenu"]')) return;
    var saved=localStorage.getItem('ashchan-custom-menu')||'';
    var cnt=$.el('div'); cnt.className='extPanel reply'; cnt.setAttribute('data-panel','custommenu');
    cnt.style.minWidth='400px';
    cnt.innerHTML='<div class="panelHeader">Custom Board List<span class="panelCtrl"><img alt="Close" title="Close" class="pointer" data-cmd="custom-menu-close" src="'+ICON.close+'"></span></div>'
      +'<p style="padding:5px 10px;font-size:11px;">Enter board slugs separated by commas (e.g., g, a, v, pol):</p>'
      +'<input type="text" id="customMenuInput" value="'+saved+'" style="width:90%;margin:5px auto;display:block;padding:3px">'
      +'<div class="center"><button data-cmd="custom-menu-save">Save</button></div>';
    document.body.appendChild(cnt);
  },
  saveCustomMenu: function() {
    var inp=$.id('customMenuInput'); if(!inp) return;
    localStorage.setItem('ashchan-custom-menu',inp.value.trim());
    Config.customMenu=true; Config.save();
    var c=$.qs('.extPanel[data-panel="custommenu"]'); if(c) c.remove();
    CustomBoardMenu.apply();
  },
  closeCustomMenu: function() { var c=$.qs('.extPanel[data-panel="custommenu"]'); if(c) c.remove(); },

  /* ---- CLICK HANDLER ---- */
  onClick: function(e) {
    var t = e.target, cmd = t.getAttribute('data-cmd');
    if (!cmd && t.tagName==='IMG') cmd = t.getAttribute('data-cmd');

    switch (cmd) {
      case 'settings-toggle':   e.preventDefault(); SettingsMenu.close(); break;
      case 'settings-save':     e.preventDefault(); SettingsMenu.save(); break;
      case 'settings-export':   e.preventDefault(); SettingsMenu.showExport(); break;
      case 'settings-exp-all':  e.preventDefault(); SettingsMenu.expandAll(); break;
      case 'filters-open':      e.preventDefault(); SettingsMenu.openFilters(); break;
      case 'thread-hiding-clear': e.preventDefault(); ThreadHiding.clearHistory(); break;
      case 'custom-menu-edit':  e.preventDefault(); SettingsMenu.openCustomMenu(); break;
      case 'keybinds-open':     e.preventDefault(); SettingsMenu.openKeybinds(); break;
      case 'css-open':          e.preventDefault(); SettingsMenu.openCSS(); break;
      default:
        if ($.hasClass(t,'settings-expand') || (t.tagName==='IMG' && $.hasClass(t,'settings-expand'))) {
          e.preventDefault(); SettingsMenu.toggleCategory(t);
        }
    }
  }
};

// ============================================================
//  POST MENU
// ============================================================
var PostMenu = {
  activeBtn:null, activeMenu:null,
  init: function() { $.on(document,'click',PostMenu.onClick); },
  onClick: function(e) {
    var t=e.target;
    if (PostMenu.activeMenu && !PostMenu.activeMenu.contains(t)) PostMenu.close();
    if ($.hasClass(t,'postMenuBtn')) { e.preventDefault(); var pid=t.closest('.post').id.replace('p',''); PostMenu.open(t,pid); return; }
    if (t.hasAttribute('data-cmd')) {
      var cmd=t.getAttribute('data-cmd'), pid=t.getAttribute('data-pid');
      switch(cmd){
        case 'hide-reply': case 'show-reply': e.preventDefault(); ReplyHiding.toggle(pid); break;
        case 'highlight-id': e.preventDefault(); IDHighlight.toggle(t.getAttribute('data-id')); break;
        case 'filter-id': e.preventDefault(); Filter.addID(t.getAttribute('data-id')); break;
      }
      PostMenu.close();
    }
  },
  open: function(btn,pid) {
    if(PostMenu.activeBtn===btn){PostMenu.close();return;} PostMenu.close();
    var post=$.id('p'+pid), isHidden=ReplyHiding.hidden[pid];
    var uidEl=$.qs('.posteruid .hand',post), uid=uidEl?uidEl.textContent:null;
    var h='<ul class="post-menu-list"><li data-cmd="'+(isHidden?'show':'hide')+'-reply" data-pid="'+pid+'">'+(isHidden?'Show':'Hide')+' reply</li>';
    if(uid){h+='<li data-cmd="highlight-id" data-id="'+uid+'">Highlight ID</li><li data-cmd="filter-id" data-id="'+uid+'">Filter ID</li>';}
    h+='</ul>';
    var menu=$.el('div'); menu.id='post-menu'; menu.className='post-menu'; menu.innerHTML=h;
    document.dispatchEvent(new CustomEvent('ashchanPostMenuReady',{detail:{menu:menu,pid:pid,btn:btn}}));
    var r=btn.getBoundingClientRect();
    menu.style.top=(r.bottom+3+window.pageYOffset)+'px'; menu.style.left=(r.left+window.pageXOffset)+'px';
    document.body.appendChild(menu); $.addClass(btn,'menuOpen'); PostMenu.activeBtn=btn; PostMenu.activeMenu=menu;
  },
  close: function() {
    if(PostMenu.activeMenu){PostMenu.activeMenu.remove();PostMenu.activeMenu=null;}
    if(PostMenu.activeBtn){$.removeClass(PostMenu.activeBtn,'menuOpen');PostMenu.activeBtn=null;}
  }
};

// ============================================================
//  ID HIGHLIGHT
// ============================================================
var IDHighlight = {
  highlighted: {},
  toggle: function(id) { IDHighlight.highlighted[id]?(IDHighlight.unhighlight(id),delete IDHighlight.highlighted[id]):(IDHighlight.highlight(id),IDHighlight.highlighted[id]=true); },
  highlight: function(id) {
    var ps=$.qsa('.posteruid .hand'); for(var i=0;i<ps.length;i++) if(ps[i].textContent===id){var p=ps[i].closest('.post');if(p)$.addClass(p,'highlight');}
  },
  unhighlight: function(id) {
    var ps=$.qsa('.posteruid .hand'); for(var i=0;i<ps.length;i++) if(ps[i].textContent===id){var p=ps[i].closest('.post');if(p)$.removeClass(p,'highlight');}
  }
};

// ============================================================
//  FILTER
// ============================================================
var Filter = {
  filters:[],
  init: function() {
    if(!Config.filter||!UA.hasWebStorage) return;
    var s=localStorage.getItem('ashchan-filters'); if(s) try{Filter.filters=JSON.parse(s);}catch(e){}
    Filter.apply();
  },
  save: function() { localStorage.setItem('ashchan-filters',JSON.stringify(Filter.filters)); },
  addID: function(id) { Filter.filters.push({type:'id',pattern:id,active:true,hide:true}); Filter.save(); Filter.apply(); },
  apply: function() {
    var posts=$.qsa('.post');
    for(var i=0;i<posts.length;i++) { var post=posts[i];
      for(var j=0;j<Filter.filters.length;j++) { var f=Filter.filters[j]; if(!f.active) continue;
        var match=false;
        if(f.type==='id'){var u=$.qs('.posteruid .hand',post);if(u&&u.textContent===f.pattern)match=true;}
        if(f.type==='name'){var n=$.qs('.name',post);if(n&&n.textContent===f.pattern)match=true;}
        if(f.type==='trip'){var tr=$.qs('.postertrip',post);if(tr&&tr.textContent===f.pattern)match=true;}
        if(f.type==='sub'){var sb=$.qs('.subject',post);if(sb&&sb.textContent.indexOf(f.pattern)!==-1)match=true;}
        if(f.type==='com'){var mg=$.qs('.postMessage',post);if(mg&&mg.textContent.indexOf(f.pattern)!==-1)match=true;}
        if(match){if(f.hide)post.style.display='none';if(f.highlight)$.addClass(post,'highlight');}
      }
    }
  }
};

// ============================================================
//  PARSER (post processing)
// ============================================================
var Parser = {
  init: function() { var posts=$.qsa('.post'); for(var i=0;i<posts.length;i++) Parser.parsePost(posts[i]); },
  parsePost: function(post) {
    var pi=$.qs('.postInfo',post);
    if(pi&&!$.qs('.postMenuBtn',pi)){var b=$.el('a');b.href='#';b.className='postMenuBtn';b.title='Post menu';b.textContent='▶';pi.appendChild(b);}
    var uid=$.qs('.posteruid .hand',post); if(uid) IDColor.apply(uid);
  }
};

// ============================================================
//  KEYBOARD NAVIGATION
// ============================================================
var KeyBinds = {
  init: function() { if(!Config.keyBinds) return; $.on(document,'keyup',KeyBinds.onKey); },
  onKey: function(e) {
    if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA'||e.target.tagName==='SELECT') return;
    switch(e.keyCode){
      case 27: SettingsMenu.close();QuickReply.close();SettingsMenu.closeFilters();SettingsMenu.closeCSS();SettingsMenu.closeKeybinds();SettingsMenu.closeCustomMenu();SettingsMenu.closeExport(); break;
      case 82: if(!e.shiftKey&&Main.isThread) location.reload(); break;
      case 81: if(Main.isThread&&Config.quickReply){e.preventDefault();QuickReply.open();} break;
      case 69: var ts=$.qsa('.fileThumb img:not(.expandedImg)');for(var i=0;i<ts.length;i++)ts[i].click(); break;
      case 74: KeyBinds.navPost(1); break;
      case 75: KeyBinds.navPost(-1); break;
      case 37: if(!Main.isThread){var pv=$.qs('.pagelist .prev');if(pv)pv.click();} break;
      case 39: if(!Main.isThread){var nx=$.qs('.pagelist .next');if(nx)nx.click();} break;
    }
  },
  navPost: function(dir) {
    var posts=$.qsa('.post'); if(!posts.length) return;
    var cur=$.qs('.post.highlight'), idx=-1;
    if(cur){for(var i=0;i<posts.length;i++)if(posts[i]===cur){idx=i;break;} $.removeClass(cur,'highlight');}
    idx+=dir; if(idx<0)idx=0; if(idx>=posts.length)idx=posts.length-1;
    $.addClass(posts[idx],'highlight'); posts[idx].scrollIntoView({behavior:'smooth',block:'center'});
  }
};

// ============================================================
//  GLOBAL COMMAND HANDLER
// ============================================================
function handleGlobalCmd(e) {
  var t=e.target, cmd=t.getAttribute('data-cmd');
  if(!cmd && t.tagName==='IMG') cmd=t.getAttribute('data-cmd');
  if(!cmd) return;
  switch(cmd){
    case 'settings-toggle': e.preventDefault(); SettingsMenu.toggle(); break;
    case 'export-close': e.preventDefault(); SettingsMenu.closeExport(); break;
    case 'filters-close': e.preventDefault(); SettingsMenu.closeFilters(); break;
    case 'filters-save': e.preventDefault(); SettingsMenu.saveFilters(); break;
    case 'css-close': e.preventDefault(); SettingsMenu.closeCSS(); break;
    case 'css-save': e.preventDefault(); SettingsMenu.saveCSS(); break;
    case 'keybinds-close': e.preventDefault(); SettingsMenu.closeKeybinds(); break;
    case 'custom-menu-close': e.preventDefault(); SettingsMenu.closeCustomMenu(); break;
    case 'custom-menu-save': e.preventDefault(); SettingsMenu.saveCustomMenu(); break;
    case 'qr-close': e.preventDefault(); QuickReply.close(); break;
    case 'show-thread': e.preventDefault(); var tid=t.getAttribute('data-tid'); if(tid) ThreadHiding.toggle(tid); break;
    case 'show-reply': e.preventDefault(); var pid=t.getAttribute('data-pid'); if(pid) ReplyHiding.toggle(pid); break;
    case 'yt-embed': e.preventDefault();
      var vid=t.getAttribute('data-vid'); if(vid){var w=t.closest('.yt-embed-toggle');if(w){var em=$.el('div');em.className='yt-embed';em.style.marginTop='5px';em.innerHTML='<iframe width="640" height="360" src="https://www.youtube.com/embed/'+vid+'" frameborder="0" allowfullscreen></iframe>';w.parentNode.insertBefore(em,w.nextSibling);w.remove();}} break;
    case 'sc-embed': e.preventDefault();
      var scUrl=t.getAttribute('data-url');if(scUrl){var sw=t.closest('.sc-embed-toggle');if(sw){var se=$.el('div');se.className='sc-embed';se.style.marginTop='5px';se.innerHTML='<iframe width="100%" height="166" scrolling="no" frameborder="no" src="https://w.soundcloud.com/player/?url='+encodeURIComponent(scUrl)+'&color=ff5500&auto_play=false&hide_related=true&show_comments=false&show_user=true&show_reposts=false"></iframe>';sw.parentNode.insertBefore(se,sw.nextSibling);sw.remove();}} break;
  }
}

// ============================================================
//  INIT
// ============================================================
function init() {
  Main.init();
  Config.load();

  // Settings button bindings
  var sLinks = [$.id('settingsWindowLink'),$.id('settingsWindowLinkBot'),$.id('settingsWindowLinkMobile'),$.id('settingsBtn')];
  sLinks.forEach(function(el){if(el) $.on(el,'click',function(e){e.preventDefault();SettingsMenu.toggle();});});

  // Global command handler
  $.on(document, 'click', handleGlobalCmd);

  // If disabled, stop here
  if (Config.disableAll) return;

  // Quick Reply via post number clicks
  if (Main.isThread && Config.quickReply) {
    $.on(document,'click',function(e){
      var t=e.target;
      if(t.tagName==='A'&&t.title==='Reply to this post'){e.preventDefault();var id=t.getAttribute('href').replace('#q','');QuickReply.open(id);}
    });
  }

  // Force HTTPS
  ForceHTTPS.init();

  // Layout
  LayoutOptions.init();
  CustomCSS.init();

  // Global features
  if(Config.IDColor) IDColor.init();
  if(Config.linkify) Linkify.init();
  if(Config.localTime) LocalTime.init();

  // Navigation
  DropDownNav.init();
  NavArrows.init();
  TopPageNav.init();
  CustomBoardMenu.init();
  InfiniteScroll.init();

  // Embeds
  if(Config.embedYouTube) EmbedYouTube.init();
  if(Config.embedSoundCloud) EmbedSoundCloud.init();

  // Images
  if(Config.revealSpoilers) RevealSpoilers.init();
  if(Config.noPictures) NoPictures.init();

  // Thread-specific
  if (Main.isThread) {
    ReplyHiding.init();
    ReplyHiding.applyAll();
    Backlinks.init();
    QuickReply.init();
    ThreadStats.init();
    UpdaterSound.init();
    if(Config.quotePreview) QuotePreview.init();
    if(Config.inlineQuotes) InlineQuotes.init();
    if(Config.imageHover)   ImageHover.init();
    if(Config.filter)       Filter.init();
    Parser.init();
    PostMenu.init();
  }

  // Board index
  if (!Main.isThread) {
    ThreadHiding.init();
    if(Config.filter) Filter.init();
  }

  // Keyboard
  KeyBinds.init();
}

if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
else init();

// Expose for external use
window.ReplyHiding   = ReplyHiding;
window.IDColor       = IDColor;
window.Filter        = Filter;
window.Config        = Config;
window.SettingsMenu  = SettingsMenu;
window.QuickReply    = QuickReply;
window.ThreadHiding  = ThreadHiding;

})();
