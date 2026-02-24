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
 * 中文（简体）语言包
 * Chinese (Simplified) language pack for ashchan.
 */

(function() {
'use strict';

var L = window.AshchanLang = window.AshchanLang || {};

L.zh = {
  // ── 导航 ────────────────────────────────────────────────
  'nav.settings':           '设置',
  'nav.search':             '搜索',
  'nav.home':               '首页',
  'nav.return':             '返回',
  'nav.catalog':            '目录',
  'nav.archive':            '归档',
  'nav.bottom':             '底部',
  'nav.top':                '顶部',
  'nav.rules':              '规则',
  'nav.terms':              '条款',
  'nav.privacy':            '隐私',
  'nav.cookies':            'Cookies',
  'nav.contact':            '联系我们',

  // ── 首页 ────────────────────────────────────────────────
  'home.title':             'ashchan',
  'home.subtitle':          '一个简洁的图版',
  'home.stats.posts':       '总帖数：',
  'home.stats.threads':     '活跃主题：',
  'home.stats.users':       '活跃用户：',

  // ── 发帖表单 ────────────────────────────────────────────
  'form.name':              '名称',
  'form.options':           '选项',
  'form.subject':           '主题',
  'form.comment':           '内容',
  'form.file':              '文件',
  'form.verification':      '验证',
  'form.submit':            '发布',
  'form.spoiler':           '剧透？',
  'form.newThread':         '发起新主题',
  'form.placeholder.name':  '匿名',
  'form.placeholder.options': 'sage',
  'form.rules.filetypes':   '支持的文件类型：JPEG、PNG、GIF、WebP（最大 4MB）',
  'form.rules.thumbnail':   '大于 250x250 的图片将生成缩略图。',
  'form.rules.readRules':   '发帖前请阅读<a href="/rules">规则</a>。',
  'form.privacy':           '发帖即表示您同意我们的<a href="/legal/privacy" target="_blank" rel="noopener noreferrer">隐私政策</a>。您的 IP 地址经过加密，30 天后自动删除。垃圾信息可能会被举报至 <a href="https://www.stopforumspam.com" target="_blank" rel="noopener noreferrer">SFS</a>。',

  // ── 版块页面 ────────────────────────────────────────────
  'board.reply':            '回复',
  'board.omitted':          '省略了 {{count}} 条帖子。',
  'board.omittedImages':    '和 {{count}} 张图片回复',
  'board.clickToView':      '点击此处',
  'board.toView':           '查看。',

  // ── 主题页面 ────────────────────────────────────────────
  'thread.watchThread':     '关注主题',
  'thread.replies':         '{{count}} 条回复',
  'thread.images':          '{{count}} 张图片',
  'thread.locked':          '[已锁定]',
  'thread.sticky':          '[已置顶]',
  'thread.auto':            '自动',
  'thread.update':          '更新',

  // ── 删除 / 举报 ────────────────────────────────────────
  'action.deletePost':      '删除帖子',
  'action.fileOnly':        '仅文件',
  'action.password':        '密码',
  'action.delete':          '删除',
  'action.report':          '举报',
  'action.selectPost':      '请先选择一条帖子',

  // ── 目录 ────────────────────────────────────────────────
  'catalog.search':         '搜索主题…',
  'catalog.sortBy':         '排序：',
  'catalog.sort.bump':      '回复排序',
  'catalog.sort.time':      '创建时间',
  'catalog.sort.replies':   '回复数',
  'catalog.sort.images':    '图片数',
  'catalog.size':           '大小：',
  'catalog.size.small':     '小',
  'catalog.size.large':     '大',
  'catalog.hidden':         '已隐藏：',
  'catalog.clear':          '清除',
  'catalog.noImage':        '无图片',
  'catalog.replies':        '回：',
  'catalog.images':         '图：',
  'catalog.sticky':         '置顶',
  'catalog.locked':         '锁定',

  // ── 归档 ────────────────────────────────────────────────
  'archive.search':         '搜索已归档主题…',
  'archive.colNo':          '编号',
  'archive.colExcerpt':     '摘要',
  'archive.empty':          '没有已归档的主题。',

  // ── NekotV ──────────────────────────────────────────────
  'nekotv.theaterMode':     '影院模式',
  'nekotv.playlist':        '保持播放列表打开',
  'nekotv.mute':            '静音',
  'nekotv.unmute':          '取消静音',
  'nekotv.close':           '关闭',
  'nekotv.enabled':         'NekotV：已启用（点击禁用）',
  'nekotv.disabled':        'NekotV：已禁用（点击启用）',

  // ── 帖子元素 ────────────────────────────────────────────
  'post.linkPost':          '链接到此帖子',
  'post.replyPost':         '回复此帖子',
  'post.postMenu':          '帖子菜单',
  'post.highlightId':       '高亮该 ID 的帖子',
  'post.ipHistory':         '查看此 IP 哈希的发帖历史',

  // ── 公告栏 ──────────────────────────────────────────────
  'blotter.show':           '显示公告',
  'blotter.hide':           '隐藏公告',
  'blotter.allNews':        '所有新闻',

  // ── 主题切换 ────────────────────────────────────────────
  'style.label':            '样式：',

  // ── 页脚 ────────────────────────────────────────────────
  'footer.allContent':      '所有内容版权归各自所有者所有。',
  'footer.copyright':       'ashchan',

  // ── 分页 ────────────────────────────────────────────────
  'pagination.prev':        '<< 上一页',
  'pagination.next':        '下一页 >>',

  // ── 无脚本 ──────────────────────────────────────────────
  'noscript.warning':       '完整功能需要 JavaScript。基本浏览无需 JavaScript。',

  // ── 语言选择器 ──────────────────────────────────────────
  'lang.label':             '语言：'
};

})();
