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
 * English language pack (base/fallback).
 *
 * All keys use flat dot-notation. This file defines the canonical
 * set of keys — all other language packs must provide the same keys.
 */

(function() {
'use strict';

var L = window.AshchanLang = window.AshchanLang || {};

L.en = {
  // ── Navigation ──────────────────────────────────────────
  'nav.settings':           'Settings',
  'nav.search':             'Search',
  'nav.home':               'Home',
  'nav.return':             'Return',
  'nav.catalog':            'Catalog',
  'nav.archive':            'Archive',
  'nav.bottom':             'Bottom',
  'nav.top':                'Top',
  'nav.rules':              'Rules',
  'nav.terms':              'Terms',
  'nav.privacy':            'Privacy',
  'nav.cookies':            'Cookies',
  'nav.contact':            'Contact',

  // ── Home page ───────────────────────────────────────────
  'home.title':             'ashchan',
  'home.subtitle':          'A simple imageboard',
  'home.stats.posts':       'Total posts:',
  'home.stats.threads':     'Active threads:',
  'home.stats.users':       'Active users:',

  // ── Post form ───────────────────────────────────────────
  'form.name':              'Name',
  'form.options':           'Options',
  'form.subject':           'Subject',
  'form.comment':           'Comment',
  'form.file':              'File',
  'form.verification':      'Verification',
  'form.submit':            'Post',
  'form.spoiler':           'Spoiler?',
  'form.newThread':         'Start a New Thread',
  'form.placeholder.name':  'Anonymous',
  'form.placeholder.options': 'sage',
  'form.rules.filetypes':   'Supported file types: JPEG, PNG, GIF, WebP (max 4MB)',
  'form.rules.thumbnail':   'Images larger than 250x250 will be thumbnailed.',
  'form.rules.readRules':   'Read the <a href="/rules">Rules</a> before posting.',
  'form.privacy':           'By posting, you agree to our <a href="/legal/privacy" target="_blank" rel="noopener noreferrer">Privacy Policy</a>. Your IP is encrypted and auto-deleted after 30 days. Spam may be reported to <a href="https://www.stopforumspam.com" target="_blank" rel="noopener noreferrer">SFS</a>.',

  // ── Board page ──────────────────────────────────────────
  'board.reply':            'Reply',
  'board.omitted':          '{{count}} post{{plural}} omitted.',
  'board.omittedImages':    'and {{count}} image reply{{plural}}',
  'board.clickToView':      'Click here',
  'board.toView':           'to view.',

  // ── Thread page ─────────────────────────────────────────
  'thread.watchThread':     'Watch Thread',
  'thread.replies':         '{{count}} replies',
  'thread.images':          '{{count}} images',
  'thread.locked':          '[Locked]',
  'thread.sticky':          '[Sticky]',
  'thread.auto':            'Auto',
  'thread.update':          'Update',

  // ── Delete / Report ─────────────────────────────────────
  'action.deletePost':      'Delete Post',
  'action.fileOnly':        'File Only',
  'action.password':        'Password',
  'action.delete':          'Delete',
  'action.report':          'Report',
  'action.selectPost':      'Select a post first',

  // ── Catalog ─────────────────────────────────────────────
  'catalog.search':         'Search threads...',
  'catalog.sortBy':         'Sort by:',
  'catalog.sort.bump':      'Bump order',
  'catalog.sort.time':      'Creation date',
  'catalog.sort.replies':   'Reply count',
  'catalog.sort.images':    'Image count',
  'catalog.size':           'Size:',
  'catalog.size.small':     'Small',
  'catalog.size.large':     'Large',
  'catalog.hidden':         'Hidden:',
  'catalog.clear':          'Clear',
  'catalog.noImage':        'No image',
  'catalog.replies':        'R:',
  'catalog.images':         'I:',
  'catalog.sticky':         'Sticky',
  'catalog.locked':         'Locked',

  // ── Archive ─────────────────────────────────────────────
  'archive.search':         'Search archived threads...',
  'archive.colNo':          'No.',
  'archive.colExcerpt':     'Excerpt',
  'archive.empty':          'No archived threads.',

  // ── NekotV ──────────────────────────────────────────────
  'nekotv.theaterMode':     'Theater Mode',
  'nekotv.playlist':        'Keep playlist open',
  'nekotv.mute':            'Mute',
  'nekotv.unmute':          'Unmute',
  'nekotv.close':           'Close',
  'nekotv.enabled':         'NekotV: Enabled (click to disable)',
  'nekotv.disabled':        'NekotV: Disabled (click to enable)',

  // ── Post elements ───────────────────────────────────────
  'post.linkPost':          'Link to this post',
  'post.replyPost':         'Reply to this post',
  'post.postMenu':          'Post menu',
  'post.highlightId':       'Highlight posts by this ID',
  'post.ipHistory':         'View post history for this IP hash',

  // ── Blotter ─────────────────────────────────────────────
  'blotter.show':           'Show blotter',
  'blotter.hide':           'Hide blotter',
  'blotter.allNews':        'All news',

  // ── Style switcher ──────────────────────────────────────
  'style.label':            'Style:',

  // ── Footer ──────────────────────────────────────────────
  'footer.allContent':      'All content copyright their respective owners.',
  'footer.copyright':       'ashchan',

  // ── Pagination ──────────────────────────────────────────
  'pagination.prev':        '<< Previous',
  'pagination.next':        'Next >>',

  // ── Noscript ────────────────────────────────────────────
  'noscript.warning':       'JavaScript is required for full functionality. Basic browsing works without it.',

  // ── Language selector ───────────────────────────────────
  'lang.label':             'Language:'
};

})();
