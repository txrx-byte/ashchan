<?php

declare(strict_types=1);
 ob_start(); ?>

<hr class="abovePostForm">

<!-- Mobile nav links -->
<div class="navLinks mobile">
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a></span>
  <span class="mobileib button"><a href="#bottom">Bottom</a></span>
</div>

<!-- Desktop nav links -->
<div class="navLinks desktop">
  [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
  [<a href="#bottom">Bottom</a>]
</div>

<hr>

<!-- Catalog Controls -->
<div id="ctrl" class="center">
  <div id="catalog-controls">
    Search: <input type="text" id="catalog-search" placeholder="" autocomplete="off" size="20">
    &nbsp;
    Order: <select id="catalog-sort">
      <option value="bump" selected>Bump order</option>
      <option value="time">Creation date</option>
      <option value="replies">Reply count</option>
      <option value="images">Image count</option>
    </select>
    &nbsp;
    Image Size: <select id="catalog-size">
      <option value="small">Small</option>
      <option value="large" selected>Large</option>
    </select>
  </div>
</div>

<hr>

<!-- Catalog Grid -->
<div id="threads" class="extended-small">
  <?php if (empty($threads)): ?>
  <div style="text-align:center;padding:40px;color:#89A;">No threads.</div>
  <?php endif; ?>

  <?php foreach (($threads ?? []) as $thread): ?>
  <?php $top = $thread['op'] ?? $thread; ?>
  <div class="thread" id="thread-<?= htmlspecialchars($thread['id']) ?>"
       data-replies="<?= (int)($thread['reply_count'] ?? 0) ?>"
       data-images="<?= (int)($thread['image_count'] ?? 0) ?>"
       data-bumped="<?= htmlspecialchars($thread['bumped_at'] ?? '') ?>"
       data-created="<?= htmlspecialchars($thread['created_at'] ?? '') ?>">
    <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= htmlspecialchars($thread['id']) ?>">
      <?php if (!empty($top['thumb_url'])): ?>
      <img class="thumb" src="<?= htmlspecialchars($top['thumb_url']) ?>" alt="" loading="lazy">
      <?php else: ?>
      <img class="thumb" src="/static/img/no-image.png" alt="No image" loading="lazy">
      <?php endif; ?>
    </a>
    <div class="meta">
      <b>R: <strong><?= (int)($thread['reply_count'] ?? 0) ?></strong> / I: <strong><?= (int)($thread['image_count'] ?? 0) ?></strong></b>
      <?php if (!empty($thread['sticky'])): ?> <img src="/static/img/sticky.gif" alt="Sticky" title="Sticky" class="stickyIcon"><?php endif; ?>
      <?php if (!empty($thread['locked'])): ?> <img src="/static/img/closed.gif" alt="Closed" title="Closed" class="closedIcon"><?php endif; ?>
    </div>
    <div class="teaser">
      <?php if (!empty($top['subject'])): ?><b><?= htmlspecialchars($top['subject']) ?></b>: <?php endif; ?>
      <?= htmlspecialchars($top['content_preview'] ?? substr(strip_tags($top['content'] ?? ''), 0, 150)) ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div style="clear:both"></div>
</div>

<hr>

<!-- Bottom nav links -->
<div class="navLinks navLinksBot desktop">
  [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
  [<a href="#top">Top</a>]
</div>

<div class="navLinks navLinksBot mobile">
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a></span>
  <span class="mobileib button"><a href="#top">Top</a></span>
</div>

<script>
(function() {
  var sortSelect = document.getElementById('catalog-sort');
  var sizeSelect = document.getElementById('catalog-size');
  var searchInput = document.getElementById('catalog-search');
  var container = document.getElementById('threads');

  if (sortSelect) {
    sortSelect.addEventListener('change', function() {
      var threads = Array.from(document.querySelectorAll('#threads > .thread'));
      var key = sortSelect.value;
      threads.sort(function(a, b) {
        if (key === 'replies') return parseInt(b.dataset.replies) - parseInt(a.dataset.replies);
        if (key === 'images') return parseInt(b.dataset.images) - parseInt(a.dataset.images);
        if (key === 'time') return (b.dataset.created || '').localeCompare(a.dataset.created || '');
        return (b.dataset.bumped || '').localeCompare(a.dataset.bumped || '');
      });
      threads.forEach(function(t) { container.appendChild(t); });
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', function() {
      var q = searchInput.value.toLowerCase();
      var threads = document.querySelectorAll('#threads > .thread');
      threads.forEach(function(t) {
        var text = t.textContent.toLowerCase();
        t.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  if (sizeSelect) {
    sizeSelect.addEventListener('change', function() {
      container.className = sizeSelect.value === 'small' ? 'extended-small' : 'extended-large';
    });
  }
})();
</script>

<?php
$is_index = true;
$extra_css = '<link rel="stylesheet" href="/static/css/catalog_mobile.css">';
$__content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
