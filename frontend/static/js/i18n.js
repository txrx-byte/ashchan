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
 * i18n.js — Lightweight client-side internationalization for ashchan.
 *
 * Language packs register via window.AshchanLang[code] = { ... }.
 * This module loads the active language from localStorage / navigator,
 * applies translations to DOM elements marked with data-i18n attributes,
 * and exposes a t() function for runtime string lookups.
 *
 * Supported data attributes:
 *   data-i18n="key"                — sets textContent
 *   data-i18n-placeholder="key"    — sets placeholder attribute
 *   data-i18n-title="key"          — sets title attribute
 *   data-i18n-value="key"          — sets value attribute (buttons/inputs)
 *   data-i18n-html="key"           — sets innerHTML (use sparingly)
 *
 * Interpolation:
 *   t('key', { count: 5 }) replaces {{count}} in the string with 5.
 *
 * Language detection order:
 *   1. ?lang=xx URL parameter
 *   2. localStorage 'ashchan_lang'
 *   3. <html lang="xx"> attribute
 *   4. navigator.language prefix
 *   5. 'en' fallback
 *
 * Usage in JS:
 *   var label = Ashchan.i18n.t('nav.settings');
 *   var msg   = Ashchan.i18n.t('board.omitted', { count: 3, images: 1 });
 */

(function() {
'use strict';

// ── Language pack registry ──────────────────────────────────

/** @type {Object<string, Object<string, string>>} */
var packs = window.AshchanLang = window.AshchanLang || {};

// ── State ───────────────────────────────────────────────────

var currentLang = 'en';
var fallbackLang = 'en';

// ── Supported languages (code → native name) ───────────────

var SUPPORTED = {
  en: 'English',
  zh: '中文',
  ja: '日本語'
};

// ── Core API ────────────────────────────────────────────────

/**
 * Translate a key, with optional interpolation.
 *
 * @param {string} key        Dot-notated translation key
 * @param {Object} [params]   Interpolation values: {{name}} → params.name
 * @returns {string}          Translated string, or the key itself if missing
 */
function t(key, params) {
  var str = lookup(key, currentLang) || lookup(key, fallbackLang) || key;
  if (params) {
    Object.keys(params).forEach(function(k) {
      str = str.replace(new RegExp('\\{\\{' + k + '\\}}', 'g'), String(params[k]));
    });
  }
  return str;
}

/**
 * Look up a key in a specific language pack.
 * Supports dot notation: 'nav.settings' → pack['nav.settings'] || pack.nav.settings
 *
 * @param {string} key
 * @param {string} lang
 * @returns {string|undefined}
 */
function lookup(key, lang) {
  var pack = packs[lang];
  if (!pack) return undefined;

  // Direct flat key lookup (preferred — all packs use flat keys)
  if (typeof pack[key] === 'string') return pack[key];

  // Nested dot-notation fallback
  var parts = key.split('.');
  var obj = pack;
  for (var i = 0; i < parts.length; i++) {
    if (obj == null || typeof obj !== 'object') return undefined;
    obj = obj[parts[i]];
  }
  return typeof obj === 'string' ? obj : undefined;
}

/**
 * Detect the preferred language.
 *
 * @returns {string} Language code ('en', 'zh', 'ja', etc.)
 */
function detectLanguage() {
  // 1. URL parameter ?lang=xx
  var url = new URL(window.location.href);
  var langParam = url.searchParams.get('lang');
  if (langParam && SUPPORTED[langParam]) return langParam;

  // 2. localStorage
  var saved = localStorage.getItem('ashchan_lang');
  if (saved && SUPPORTED[saved]) return saved;

  // 3. <html lang="xx">
  var htmlLang = document.documentElement.lang;
  if (htmlLang) {
    var prefix = htmlLang.split('-')[0].toLowerCase();
    if (SUPPORTED[prefix]) return prefix;
  }

  // 4. navigator.language
  if (navigator.language) {
    var navPrefix = navigator.language.split('-')[0].toLowerCase();
    if (SUPPORTED[navPrefix]) return navPrefix;
  }

  // 5. Fallback
  return 'en';
}

/**
 * Set the active language and re-apply translations.
 *
 * @param {string} lang  Language code
 */
function setLanguage(lang) {
  if (!SUPPORTED[lang]) return;
  currentLang = lang;
  localStorage.setItem('ashchan_lang', lang);
  document.documentElement.lang = lang;
  applyTranslations();
  updateLanguageSelector();
}

/**
 * Get the current language code.
 *
 * @returns {string}
 */
function getLanguage() {
  return currentLang;
}

/**
 * Get all supported languages.
 *
 * @returns {Object<string, string>} code → native name
 */
function getSupportedLanguages() {
  return SUPPORTED;
}

// ── DOM translation ─────────────────────────────────────────

/**
 * Apply translations to all data-i18n-annotated elements in the DOM.
 *
 * @param {Element} [root=document]  Scope to translate within
 */
function applyTranslations(root) {
  root = root || document;

  // data-i18n → textContent
  var els = root.querySelectorAll('[data-i18n]');
  for (var i = 0; i < els.length; i++) {
    var key = els[i].getAttribute('data-i18n');
    var translated = t(key);
    if (translated !== key) {
      els[i].textContent = translated;
    }
  }

  // data-i18n-html → innerHTML
  var htmlEls = root.querySelectorAll('[data-i18n-html]');
  for (var h = 0; h < htmlEls.length; h++) {
    var hKey = htmlEls[h].getAttribute('data-i18n-html');
    var hTranslated = t(hKey);
    if (hTranslated !== hKey) {
      htmlEls[h].innerHTML = hTranslated;
    }
  }

  // data-i18n-placeholder → placeholder
  var phEls = root.querySelectorAll('[data-i18n-placeholder]');
  for (var p = 0; p < phEls.length; p++) {
    var pKey = phEls[p].getAttribute('data-i18n-placeholder');
    var pTranslated = t(pKey);
    if (pTranslated !== pKey) {
      phEls[p].placeholder = pTranslated;
    }
  }

  // data-i18n-title → title
  var titleEls = root.querySelectorAll('[data-i18n-title]');
  for (var ti = 0; ti < titleEls.length; ti++) {
    var tKey = titleEls[ti].getAttribute('data-i18n-title');
    var tTranslated = t(tKey);
    if (tTranslated !== tKey) {
      titleEls[ti].title = tTranslated;
    }
  }

  // data-i18n-value → value (for submit buttons, etc.)
  var valEls = root.querySelectorAll('[data-i18n-value]');
  for (var v = 0; v < valEls.length; v++) {
    var vKey = valEls[v].getAttribute('data-i18n-value');
    var vTranslated = t(vKey);
    if (vTranslated !== vKey) {
      valEls[v].value = vTranslated;
    }
  }
}

/**
 * Sync the language selector UI to the current language.
 */
function updateLanguageSelector() {
  var selectors = document.querySelectorAll('.lang-selector select, #langSelector');
  for (var i = 0; i < selectors.length; i++) {
    selectors[i].value = currentLang;
  }
}

/**
 * Build and inject language selector UI into existing containers.
 */
function initLanguageSelector() {
  var containers = document.querySelectorAll('.lang-selector');
  for (var i = 0; i < containers.length; i++) {
    var container = containers[i];
    // Don't rebuild if already populated
    if (container.querySelector('select')) continue;

    var select = document.createElement('select');
    select.id = 'langSelector';
    select.setAttribute('aria-label', 'Language');

    var codes = Object.keys(SUPPORTED);
    for (var j = 0; j < codes.length; j++) {
      var opt = document.createElement('option');
      opt.value = codes[j];
      opt.textContent = SUPPORTED[codes[j]];
      if (codes[j] === currentLang) opt.selected = true;
      select.appendChild(opt);
    }

    select.addEventListener('change', function() {
      setLanguage(this.value);
    });

    container.appendChild(select);
  }
}

// ── Initialization ──────────────────────────────────────────

function init() {
  currentLang = detectLanguage();
  document.documentElement.lang = currentLang;

  // Only apply if not English (English is the template default)
  if (currentLang !== 'en' || packs[currentLang]) {
    applyTranslations();
  }

  initLanguageSelector();
}

// Boot
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

// ── Public API ──────────────────────────────────────────────

var Ashchan = window.Ashchan = window.Ashchan || {};
Ashchan.i18n = {
  t: t,
  setLanguage: setLanguage,
  getLanguage: getLanguage,
  getSupportedLanguages: getSupportedLanguages,
  applyTranslations: applyTranslations,
  SUPPORTED: SUPPORTED
};

})();
