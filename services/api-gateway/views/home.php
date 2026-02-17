<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ashchan</title>
  <link rel="stylesheet" href="/static/css/common.css">
  <link rel="stylesheet" id="themeStylesheet" href="/static/css/yotsuba-b.css">
  <script src="/static/js/core.js" defer></script>
  <style>
    .home-banner { text-align: center; margin: 30px 0 20px; }
    .home-banner h1 { font-size: 28pt; margin: 0; }
    .home-banner p { font-size: 10pt; color: #666; }
    .board-list-container { max-width: 700px; margin: 0 auto; padding: 0 15px; }
    .board-category { margin-bottom: 20px; }
    .board-category h2 { font-size: 13pt; border-bottom: 1px solid #ccc; padding-bottom: 3px; margin-bottom: 8px; }
    .board-category ul { list-style: none; padding: 0; margin: 0; }
    .board-category li { padding: 2px 0; font-size: 10pt; }
    .board-category li a { text-decoration: none; font-weight: bold; }
    .board-category li .board-desc { color: #666; margin-left: 5px; }
    .home-footer { text-align: center; margin: 40px 0 20px; font-size: 9pt; color: #888; }
    .home-stats { text-align: center; margin: 20px 0; font-size: 9pt; background: #eee; padding: 8px; border-radius: 3px; }
  </style>
</head>
<body>

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
    </span>
  </div>

  <div class="home-banner">
    <h1>ashchan</h1>
    <p>A simple imageboard</p>
  </div>

  <div class="board-list-container">
    <?php foreach ($categories as $category): ?>
    <div class="board-category">
      <h2><?= htmlspecialchars($category['name']) ?></h2>
      <ul>
        <?php foreach ($category['boards'] as $b): ?>
        <li>
          <a href="/<?= htmlspecialchars($b['slug']) ?>/">/<?= htmlspecialchars($b['slug']) ?>/</a>
          <span class="board-desc">- <?= htmlspecialchars($b['title']) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="home-stats">
    <strong>Total posts:</strong> <?= (int)($total_posts ?? 0) ?> |
    <strong>Active threads:</strong> <?= (int)($active_threads ?? 0) ?> |
    <strong>Active users:</strong> <?= (int)($active_users ?? 0) ?>
  </div>

  <div class="home-footer">
    <a href="/legal/terms">Terms</a> |
    <a href="/legal/privacy">Privacy</a> |
    <a href="/legal/cookies">Cookies</a> |
    <a href="/legal/contact">Contact</a>
    <br>
    ashchan &copy; <?= date('Y') ?>
  </div>

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
