<?php
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
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noarchive">
  <meta http-equiv="pragma" content="no-cache">
  <meta http-equiv="expires" content="-1">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars((string) $page_title ?? 'ashchan') ?></title>
  <link rel="shortcut icon" href="/static/img/favicon.ico">
  <link rel="stylesheet" title="switch" href="/static/css/yotsuba-b.css">
  <link rel="alternate stylesheet" href="/static/css/yotsuba.css" title="Yotsuba">
  <link rel="alternate stylesheet" href="/static/css/yotsuba-b.css" title="Yotsuba B">
  <link rel="alternate stylesheet" href="/static/css/futaba.css" title="Futaba">
  <link rel="alternate stylesheet" href="/static/css/burichan.css" title="Burichan">
  <link rel="alternate stylesheet" href="/static/css/photon.css" title="Photon">
  <link rel="alternate stylesheet" href="/static/css/tomorrow.css" title="Tomorrow">
  <link rel="stylesheet" href="/static/css/mobile.css">
  <link rel="stylesheet" href="/static/css/common.css">
  <?= $extra_css ?? '' ?>
  <script src="/static/js/core.js" defer></script>
  <script src="/static/js/altcha.min.js" defer></script>
  <script src="/static/js/extension.js" defer></script>
<?php if (!empty($is_staff)): ?>
<?php
  $__staff_level = $staff_level ?? '';
  $__staff_js = in_array($__staff_level, ['admin', 'manager', 'mod'], true) ? 'mod.js' : 'janitor.js';
?>
  <script src="/static/js/<?= $__staff_js ?>" defer></script>
<?php endif; ?>
</head>
<body class="<?= !empty($is_index) ? 'is_index' : 'is_thread' ?> board_<?= htmlspecialchars((string) $board_slug ?? '') ?>"
      data-board-slug="<?= htmlspecialchars((string) $board_slug ?? '') ?>"
      data-thread-id="<?= htmlspecialchars((string)($thread_id ?? '')) ?>"
      data-page="<?= (int)($page_num ?? 1) ?>"
<?php if (!empty($is_staff)): ?>
      data-is-staff="true"
      data-staff-level="<?= htmlspecialchars($staff_level ?? '') ?>"
<?php endif; ?>>

<span id="id_css"></span>

<!-- Desktop Board Navigation -->
<div id="boardNavDesktop" class="desktop">
  <span class="boardList">
    [
    <?php foreach (($nav_groups ?? []) as $gi => $group): ?>
      <?php foreach ($group['boards'] as $bi => $b): ?>
        <a href="/<?= htmlspecialchars((string) $b['slug']) ?>/" title="<?= htmlspecialchars((string) $b['title']) ?>"><?= htmlspecialchars((string) $b['slug']) ?></a>
        <?php if ($bi < count($group['boards']) - 1): ?> / <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($gi < count($nav_groups) - 1): ?> ] [ <?php endif; ?>
    <?php endforeach; ?>
    ]
  </span>
  <span id="navtopright">[<a href="javascript:void(0);" id="settingsWindowLink">Settings</a>] [<a href="/" target="_top">Home</a>]</span>
</div>

<!-- Mobile Board Navigation -->
<div id="boardNavMobile" class="mobile">
  <div class="boardSelect">
    <strong>Board</strong>
    <select id="boardSelectMobile">
      <option value="/">Home</option>
      <?php foreach (($nav_groups ?? []) as $group): ?>
        <optgroup label="<?= htmlspecialchars((string) $group['name']) ?>">
          <?php foreach ($group['boards'] as $b): ?>
            <option value="/<?= htmlspecialchars((string) $b['slug']) ?>/"<?php if (($b['slug'] ?? '') === ($board_slug ?? '')): ?> selected<?php endif; ?>>/<?= htmlspecialchars((string) $b['slug']) ?>/ - <?= htmlspecialchars((string) $b['title']) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="pageJump">
    <a href="#bottom">&#9660;</a>
    <a href="javascript:void(0);" id="settingsWindowLinkMobile">Settings</a>
    <a href="/" target="_top">Home</a>
  </div>
</div>

<?php if (!empty($board_slug)): ?>
<!-- Board Banner -->
<div class="boardBanner">
  <img id="bannerImg" src="/static/img/banner.png" alt="ashchan" width="300" height="100">
  <div class="boardTitle">/<?= htmlspecialchars((string) $board_slug) ?>/ - <?= htmlspecialchars((string) $board_title ?? '') ?></div>
</div>
<?php endif; ?>

<?= $__content ?? '' ?>

<!-- Bottom Board Navigation -->
<div id="boardNavDesktopFoot" class="boardNav desktop">
  <span class="boardList">
    [
    <?php foreach (($nav_groups ?? []) as $gi => $group): ?>
      <?php foreach ($group['boards'] as $bi => $b): ?>
        <a href="/<?= htmlspecialchars((string) $b['slug']) ?>/" title="<?= htmlspecialchars((string) $b['title']) ?>"><?= htmlspecialchars((string) $b['slug']) ?></a>
        <?php if ($bi < count($group['boards']) - 1): ?> / <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($gi < count($nav_groups) - 1): ?> ] [ <?php endif; ?>
    <?php endforeach; ?>
    ]
  </span>
  <span id="navbotright">[<a href="javascript:void(0);" id="settingsWindowLinkBot">Settings</a>] [<a href="/" target="_top">Home</a>]</span>
</div>

<!-- Footer -->
<div id="absbot" class="absBotText">
  <div class="mobile">
    <span id="disable-mobile">[<a href="javascript:void(0);">Disable Mobile View / Use Desktop Site</a>]<br><br></span>
  </div>
  <span class="absBotDisclaimer">All trademarks and copyrights on this page are owned by their respective parties. Images uploaded are the responsibility of the Poster. Comments are owned by the Poster.</span>
  <div id="footer-links">
    <a href="/about">About</a> &bull;
    <a href="/feedback">Feedback</a> &bull;
    <a href="/legal">Legal</a> &bull;
    <a href="/contact">Contact</a>
  </div>
</div>

<div id="bottom"></div>

</body>
</html>
