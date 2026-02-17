<?php ob_start(); ?>

<div class="board-controls">
  <span id="ctrl-top">
    [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
    [<a href="#bottom">Bottom</a>]
  </span>
</div>

<hr>

<!-- Catalog Controls -->
<div id="catalog-controls">
  <div class="catalog-search">
    <input type="text" id="catalog-search-input" placeholder="Search threads..." autocomplete="off">
  </div>
  <div class="catalog-sort">
    Sort by:
    <select id="catalog-sort-select">
      <option value="bump" selected>Bump order</option>
      <option value="time">Creation date</option>
      <option value="replies">Reply count</option>
      <option value="images">Image count</option>
    </select>
  </div>
  <div class="catalog-size">
    Size:
    <select id="catalog-size-select">
      <option value="small">Small</option>
      <option value="large" selected>Large</option>
    </select>
  </div>
</div>

<hr>

<!-- Catalog Grid -->
<div id="threads" class="catalog-board">
  <?php if (empty($threads)): ?>
    <div style="text-align:center;padding:40px;color:#666;">No threads yet.</div>
  <?php endif; ?>

  <?php foreach (($threads ?? []) as $thread): ?>
  <?php $top = $thread['op'] ?? $thread; ?>
  <div class="catalog-thread" id="thread-<?= (int)$thread['id'] ?>"
       data-replies="<?= (int)($thread['reply_count'] ?? 0) ?>"
       data-images="<?= (int)($thread['image_count'] ?? 0) ?>"
       data-bumped="<?= htmlspecialchars($thread['bumped_at'] ?? '') ?>"
       data-created="<?= htmlspecialchars($thread['created_at'] ?? '') ?>">

    <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>">
      <?php if (!empty($top['thumb_url'])): ?>
      <img src="<?= htmlspecialchars($top['thumb_url']) ?>" alt="" class="catalog-thumb" loading="lazy">
      <?php else: ?>
      <div class="catalog-thumb no-image">No image</div>
      <?php endif; ?>
    </a>

    <div class="catalog-stats">
      <b>R: <?= (int)($thread['reply_count'] ?? 0) ?></b> / <b>I: <?= (int)($thread['image_count'] ?? 0) ?></b>
      <?php if (!empty($thread['sticky'])): ?> / <b title="Sticky">📌</b><?php endif; ?>
      <?php if (!empty($thread['locked'])): ?> / <b title="Locked">🔒</b><?php endif; ?>
    </div>

    <div class="catalog-excerpt">
      <?php if (!empty($top['subject'])): ?>
        <b><?= htmlspecialchars($top['subject']) ?></b>:
      <?php endif; ?>
      <?= htmlspecialchars($top['content_preview'] ?? substr(strip_tags($top['content'] ?? ''), 0, 150)) ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div style="clear:both"></div>

<hr>

<div class="board-controls">
  <span>
    [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
    [<a href="#top">Top</a>]
  </span>
</div>

<a id="bottom"></a>

<script>
(function() {
  var sortSelect = document.getElementById('catalog-sort-select');
  if (!sortSelect) return;
  sortSelect.addEventListener('change', function() {
    var threads = Array.from(document.querySelectorAll('.catalog-thread'));
    var container = document.getElementById('threads');
    var key = sortSelect.value;
    threads.sort(function(a, b) {
      if (key === 'replies') return parseInt(b.dataset.replies) - parseInt(a.dataset.replies);
      if (key === 'images') return parseInt(b.dataset.images) - parseInt(a.dataset.images);
      if (key === 'time') return parseInt(b.dataset.created) - parseInt(a.dataset.created);
      return parseInt(b.dataset.bumped) - parseInt(a.dataset.bumped);
    });
    threads.forEach(function(t) { container.appendChild(t); });
  });
})();
</script>

<?php
$__content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
