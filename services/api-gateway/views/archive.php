<?php ob_start(); ?>

<hr class="abovePostForm">

<!-- Mobile nav links -->
<div class="navLinks mobile">
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a></span>
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a></span>
  <span class="mobileib button"><a href="#bottom">Bottom</a></span>
</div>

<!-- Desktop nav links -->
<div class="navLinks desktop">
  [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
  [<a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a>]
  [<a href="#bottom">Bottom</a>]
</div>

<hr>

<!-- Archive Controls -->
<div id="arc-ctrl" class="center">
  Search: <input type="text" id="arc-search" placeholder="" autocomplete="off" size="30">
</div>

<hr>

<!-- Archive List -->
<div id="arc-list">
  <table id="arc-list-table">
    <colgroup>
      <col style="width:80px">
      <col>
    </colgroup>
    <thead>
      <tr>
        <th>No.</th>
        <th>Excerpt</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($archived_threads)): ?>
      <?php foreach ($archived_threads as $thread): ?>
      <tr>
        <td><a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>"><?= (int)$thread['id'] ?></a></td>
        <td><?= htmlspecialchars($thread['excerpt'] ?? 'No excerpt') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php else: ?>
      <tr><td colspan="2" style="text-align:center;padding:20px;">No archived threads.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<hr>

<!-- Bottom nav links -->
<div class="navLinks navLinksBot desktop">
  [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
  [<a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a>]
  [<a href="#top">Top</a>]
</div>

<div class="navLinks navLinksBot mobile">
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a></span>
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a></span>
  <span class="mobileib button"><a href="#top">Top</a></span>
</div>

<script>
(function() {
  var search = document.getElementById('arc-search');
  if (!search) return;
  search.addEventListener('input', function() {
    var q = search.value.toLowerCase();
    var rows = document.querySelectorAll('#arc-list-table tbody tr');
    rows.forEach(function(r) {
      var text = r.textContent.toLowerCase();
      r.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
    });
  });
})();
</script>

<?php
$is_index = true;
$__content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
