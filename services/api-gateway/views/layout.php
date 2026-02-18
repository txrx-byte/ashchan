<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noarchive">
  <meta http-equiv="pragma" content="no-cache">
  <meta http-equiv="expires" content="-1">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title ?? 'ashchan') ?></title>
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
  <script src="/static/js/extension.js" defer></script>
</head>
<body class="<?= !empty($is_index) ? 'is_index' : 'is_thread' ?> board_<?= htmlspecialchars($board_slug ?? '') ?>"
      data-board-slug="<?= htmlspecialchars($board_slug ?? '') ?>"
      data-thread-id="<?= htmlspecialchars((string)($thread_id ?? '')) ?>"
      data-page="<?= (int)($page_num ?? 1) ?>">

<span id="id_css"></span>

<!-- Desktop Board Navigation -->
<div id="boardNavDesktop" class="desktop">
  <span class="boardList">[<?php foreach (($boards ?? []) as $i => $b): ?><a href="/<?= htmlspecialchars($b['slug']) ?>/" title="<?= htmlspecialchars($b['title']) ?>"><?= htmlspecialchars($b['slug']) ?></a><?php if ($i < count($boards ?? []) - 1): ?> / <?php endif; ?><?php endforeach; ?>]</span>
  <span id="navtopright">[<a href="javascript:void(0);" id="settingsWindowLink">Settings</a>] [<a href="/" target="_top">Home</a>]</span>
</div>

<!-- Mobile Board Navigation -->
<div id="boardNavMobile" class="mobile">
  <div class="boardSelect">
    <strong>Board</strong>
    <select id="boardSelectMobile">
      <?php foreach (($boards ?? []) as $b): ?>
      <option value="/<?= htmlspecialchars($b['slug']) ?>/"<?php if (($b['slug'] ?? '') === ($board_slug ?? '')): ?> selected<?php endif; ?>>/<?= htmlspecialchars($b['slug']) ?>/ - <?= htmlspecialchars($b['title']) ?></option>
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
  <div class="boardTitle">/<?= htmlspecialchars($board_slug) ?>/ - <?= htmlspecialchars($board_title ?? '') ?></div>
</div>
<?php endif; ?>

<?= $__content ?? '' ?>

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
