<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ashchan</title>
  <link rel="stylesheet" href="/static/css/common.css">
  <style>
    body { background: #FFFFEE; font-family: arial, helvetica, sans-serif; font-size: 10pt; margin: 0; padding: 0; }
    a { color: #00E; text-decoration: none; }
    a:hover { color: #DD0000; }

    .top-nav { background: #FEDCBA; padding: 3px 5px; font-size: 9pt; border-bottom: 1px solid #D9BFB7; text-align: center; }
    .top-nav a { margin: 0 2px; }

    .home-logo { text-align: center; margin: 20px 0 5px; }
    .home-logo img, .home-logo h1 { margin: 0; }
    .home-logo h1 { font-size: 36px; color: #800000; font-family: 'Tahoma', sans-serif; letter-spacing: -1px; margin: 10px 0 0; }
    .home-tagline { text-align: center; font-size: 12px; color: #800000; margin: 2px 0 15px; font-style: italic; }

    .home-content { max-width: 820px; margin: 0 auto; padding: 0 20px; }

    .boards-box { border: 1px solid #B7C5D9; margin-bottom: 12px; }
    .boards-box .box-title { background: #98E; padding: 4px 8px; color: #FFF; font-weight: bold; font-size: 11pt; }
    .boards-box .box-content { background: #D6DAF0; padding: 8px 12px; }

    .board-row { margin: 2px 0; font-size: 10pt; }
    .board-row a { font-weight: bold; color: #34345C; }
    .board-row a:hover { color: #DD0000; }
    .board-row .board-title { color: #000; margin-left: 3px; }

    .home-info { text-align: center; margin: 20px 0; font-size: 9pt; color: #89A; }

    .home-footer { background: #FEDCBA; padding: 6px 5px; font-size: 9pt; text-align: center; border-top: 1px solid #D9BFB7; margin-top: 30px; }
    .home-footer a { color: #800000; }
  </style>
  <script src="/static/js/core.js" defer></script>
</head>
<body>

<!-- Top board nav -->
<div class="top-nav">
  [<?php foreach (($boards ?? []) as $i => $b): ?><a href="/<?= htmlspecialchars($b['slug']) ?>/" title="<?= htmlspecialchars($b['title']) ?>"><?= htmlspecialchars($b['slug']) ?></a><?php if ($i < count($boards ?? []) - 1): ?> / <?php endif; ?><?php endforeach; ?>]
</div>

<!-- Logo -->
<div class="home-logo">
  <h1>ashchan</h1>
</div>
<div class="home-tagline">a simple imageboard</div>

<!-- Board listing -->
<div class="home-content">
  <?php foreach (($categories ?? []) as $category): ?>
  <div class="boards-box">
    <div class="box-title"><?= htmlspecialchars($category['name']) ?></div>
    <div class="box-content">
      <?php foreach ($category['boards'] as $b): ?>
      <div class="board-row">
        <a href="/<?= htmlspecialchars($b['slug']) ?>/">/<?= htmlspecialchars($b['slug']) ?>/</a>
        <span class="board-title">- <?= htmlspecialchars($b['title']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="home-info">
    Total posts: <?= number_format((int)($total_posts ?? 0)) ?> &bull;
    Active content: <?= (int)($active_threads ?? 0) ?> thread<?= ($active_threads ?? 0) != 1 ? 's' : '' ?>
  </div>
</div>

<!-- Footer -->
<div class="home-footer">
  <a href="/about">About</a> &bull;
  <a href="/feedback">Feedback</a> &bull;
  <a href="/legal">Legal</a> &bull;
  <a href="/contact">Contact</a>
  <br>
  <small>All trademarks and copyrights on this page are owned by their respective parties.</small>
</div>

</body>
</html>
