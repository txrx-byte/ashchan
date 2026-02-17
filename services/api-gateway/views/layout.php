<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title ?? 'ashchan') ?></title>
  <link rel="stylesheet" href="/static/css/common.css">
  <link rel="stylesheet" id="themeStylesheet" href="/static/css/yotsuba-b.css">
  <script src="/static/js/core.js" defer></script>
  <meta name="robots" content="noindex,nofollow">
</head>
<body data-board-slug="<?= htmlspecialchars($board_slug ?? '') ?>" data-thread-id="<?= htmlspecialchars((string)($thread_id ?? '')) ?>" data-page="<?= (int)($page_num ?? 1) ?>">

  <!-- Top Navigation -->
  <div id="boardNavDesktop" class="boardBanner">
    <span class="boardList">
      [
      <?php foreach ($boards as $i => $b): ?>
        <a href="/<?= htmlspecialchars($b['slug']) ?>/"><?= htmlspecialchars($b['slug']) ?></a><?php if ($i < count($boards) - 1): ?> / <?php endif; ?>
      <?php endforeach; ?>
      ]
    </span>
    <span class="pageJump">
      [<a href="#" id="settingsBtn">Settings</a>]
      [<a href="/search/">Search</a>]
      [<a href="/">Home</a>]
    </span>
  </div>

  <div id="boardNavMobile" class="boardBanner mobile">
    <select id="boardSelectMobile">
      <?php foreach ($boards as $b): ?>
      <option value="/<?= htmlspecialchars($b['slug']) ?>/"<?php if (($b['slug'] ?? '') === ($board_slug ?? '')): ?> selected<?php endif; ?>>
        /<?= htmlspecialchars($b['slug']) ?>/ - <?= htmlspecialchars($b['title']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Banner -->
  <div id="bannerCnt" class="title">
    <div class="boardBanner">
      <div class="boardTitle">/<?= htmlspecialchars($board_slug ?? '') ?>/ - <?= htmlspecialchars($board_title ?? '') ?></div>
    </div>
  </div>

  <?= $__content ?? '' ?>

  <!-- Bottom Navigation -->
  <div id="boardNavDesktopFoot" class="boardBanner">
    <span class="boardList">
      [
      <?php foreach ($boards as $i => $b): ?>
        <a href="/<?= htmlspecialchars($b['slug']) ?>/"><?= htmlspecialchars($b['slug']) ?></a><?php if ($i < count($boards) - 1): ?> / <?php endif; ?>
      <?php endforeach; ?>
      ]
    </span>
  </div>

  <!-- Footer -->
  <div id="absbot" class="absBotText">
    <span class="absBotLinks">
      [<a href="/legal/terms">Terms</a>]
      [<a href="/legal/privacy">Privacy</a>]
      [<a href="/legal/cookies">Cookies</a>]
      [<a href="/legal/contact">Contact</a>]
    </span>
    <br>
    <small>All content copyright their respective owners. ashchan &copy; <?= date('Y') ?></small>
  </div>

  <!-- Style Selector (Bottom Right) -->
  <div id="styleChanger">
    Style:
    <select id="styleSelector">
      <option value="yotsuba">Yotsuba</option>
      <option value="yotsuba-b" selected>Yotsuba B</option>
      <option value="futaba">Futaba</option>
      <option value="burichan">Burichan</option>
      <option value="photon">Photon</option>
      <option value="tomorrow">Tomorrow</option>
    </select>
  </div>

</body>
</html>
