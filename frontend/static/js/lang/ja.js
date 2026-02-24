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
 * 日本語言語パック
 * Japanese language pack for ashchan.
 */

(function() {
'use strict';

var L = window.AshchanLang = window.AshchanLang || {};

L.ja = {
  // ── ナビゲーション ──────────────────────────────────────
  'nav.settings':           '設定',
  'nav.search':             '検索',
  'nav.home':               'ホーム',
  'nav.return':             '戻る',
  'nav.catalog':            'カタログ',
  'nav.archive':            'アーカイブ',
  'nav.bottom':             '下へ',
  'nav.top':                '上へ',
  'nav.rules':              'ルール',
  'nav.terms':              '利用規約',
  'nav.privacy':            'プライバシー',
  'nav.cookies':            'Cookie',
  'nav.contact':            'お問い合わせ',

  // ── ホームページ ────────────────────────────────────────
  'home.title':             'ashchan',
  'home.subtitle':          'シンプルな画像掲示板',
  'home.stats.posts':       '総投稿数：',
  'home.stats.threads':     'アクティブスレッド：',
  'home.stats.users':       'アクティブユーザー：',

  // ── 投稿フォーム ────────────────────────────────────────
  'form.name':              '名前',
  'form.options':           'オプション',
  'form.subject':           '件名',
  'form.comment':           'コメント',
  'form.file':              'ファイル',
  'form.verification':      '認証',
  'form.submit':            '投稿',
  'form.spoiler':           'ネタバレ？',
  'form.newThread':         '新規スレッドを立てる',
  'form.placeholder.name':  '名無しさん',
  'form.placeholder.options': 'sage',
  'form.rules.filetypes':   '対応ファイル形式：JPEG、PNG、GIF、WebP（最大 4MB）',
  'form.rules.thumbnail':   '250x250 より大きい画像はサムネイル化されます。',
  'form.rules.readRules':   '投稿前に<a href="/rules">ルール</a>をお読みください。',
  'form.privacy':           '投稿することで、<a href="/legal/privacy" target="_blank" rel="noopener noreferrer">プライバシーポリシー</a>に同意したものとみなされます。IPアドレスは暗号化され、30日後に自動削除されます。スパムは <a href="https://www.stopforumspam.com" target="_blank" rel="noopener noreferrer">SFS</a> に報告される場合があります。',

  // ── 板ページ ────────────────────────────────────────────
  'board.reply':            '返信',
  'board.omitted':          '{{count}} 件の投稿が省略されています。',
  'board.omittedImages':    '{{count}} 件の画像返信を含む',
  'board.clickToView':      'こちらをクリック',
  'board.toView':           'して表示。',

  // ── スレッドページ ──────────────────────────────────────
  'thread.watchThread':     'スレッドをウォッチ',
  'thread.replies':         '{{count}} 件の返信',
  'thread.images':          '{{count}} 枚の画像',
  'thread.locked':          '[ロック済み]',
  'thread.sticky':          '[固定済み]',
  'thread.auto':            '自動',
  'thread.update':          '更新',

  // ── 削除 / 通報 ────────────────────────────────────────
  'action.deletePost':      '投稿を削除',
  'action.fileOnly':        'ファイルのみ',
  'action.password':        'パスワード',
  'action.delete':          '削除',
  'action.report':          '通報',
  'action.selectPost':      '先に投稿を選択してください',

  // ── カタログ ────────────────────────────────────────────
  'catalog.search':         'スレッドを検索…',
  'catalog.sortBy':         '並び替え：',
  'catalog.sort.bump':      'バンプ順',
  'catalog.sort.time':      '作成日時',
  'catalog.sort.replies':   '返信数',
  'catalog.sort.images':    '画像数',
  'catalog.size':           'サイズ：',
  'catalog.size.small':     '小',
  'catalog.size.large':     '大',
  'catalog.hidden':         '非表示：',
  'catalog.clear':          'クリア',
  'catalog.noImage':        '画像なし',
  'catalog.replies':        '返：',
  'catalog.images':         '画：',
  'catalog.sticky':         '固定',
  'catalog.locked':         'ロック',

  // ── アーカイブ ──────────────────────────────────────────
  'archive.search':         'アーカイブ済みスレッドを検索…',
  'archive.colNo':          'No.',
  'archive.colExcerpt':     '抜粋',
  'archive.empty':          'アーカイブ済みスレッドはありません。',

  // ── NekotV ──────────────────────────────────────────────
  'nekotv.theaterMode':     'シアターモード',
  'nekotv.playlist':        'プレイリストを開いたままにする',
  'nekotv.mute':            'ミュート',
  'nekotv.unmute':          'ミュート解除',
  'nekotv.close':           '閉じる',
  'nekotv.enabled':         'NekotV：有効（クリックで無効化）',
  'nekotv.disabled':        'NekotV：無効（クリックで有効化）',

  // ── 投稿要素 ────────────────────────────────────────────
  'post.linkPost':          'この投稿へのリンク',
  'post.replyPost':         'この投稿に返信',
  'post.postMenu':          '投稿メニュー',
  'post.highlightId':       'このIDの投稿をハイライト',
  'post.ipHistory':         'このIPハッシュの投稿履歴を表示',

  // ── お知らせ ────────────────────────────────────────────
  'blotter.show':           'お知らせを表示',
  'blotter.hide':           'お知らせを非表示',
  'blotter.allNews':        'すべてのニュース',

  // ── テーマ切替 ──────────────────────────────────────────
  'style.label':            'スタイル：',

  // ── フッター ────────────────────────────────────────────
  'footer.allContent':      'すべてのコンテンツの著作権は各所有者に帰属します。',
  'footer.copyright':       'ashchan',

  // ── ページネーション ────────────────────────────────────
  'pagination.prev':        '<< 前へ',
  'pagination.next':        '次へ >>',

  // ── NoScript ────────────────────────────────────────────
  'noscript.warning':       '完全な機能にはJavaScriptが必要です。基本的な閲覧はJavaScriptなしでも可能です。',

  // ── 言語選択 ────────────────────────────────────────────
  'lang.label':             '言語：'
};

})();
