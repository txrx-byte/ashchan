<?php ob_start(); ?>

<!-- COPPA / Age Gate -->
<noscript>
  <div class="noscriptWarning" style="text-align:center;padding:20px;background:#fca;margin:10px;">
    JavaScript is required for full functionality. Basic browsing works without it.
  </div>
</noscript>

<!-- Post Form Toggle -->
<div id="togglePostFormLink" class="center">
  [<a href="#" id="togglePostForm">Start a New Thread</a>]
</div>

<!-- Post Form -->
<div id="postForm" style="display:none;">
  <form id="mpostform" name="post" action="/api/v1/boards/<?= htmlspecialchars($board_slug) ?>/threads" method="post" enctype="multipart/form-data">
    <table class="postForm">
      <tbody>
        <tr data-type="Name">
          <td class="label">Name</td>
          <td><input name="name" type="text" placeholder="Anonymous" autocomplete="off" maxlength="75"></td>
        </tr>
        <tr data-type="Options">
          <td class="label">Options</td>
          <td><input name="email" type="text" placeholder="sage" autocomplete="off" maxlength="75"></td>
        </tr>
        <tr data-type="Subject">
          <td class="label">Subject</td>
          <td>
            <input name="sub" type="text" autocomplete="off" maxlength="100">
            <input type="submit" value="Post">
          </td>
        </tr>
        <tr data-type="Comment">
          <td class="label">Comment</td>
          <td><textarea name="com" cols="48" rows="4" maxlength="2000" wrap="soft"></textarea></td>
        </tr>
        <tr data-type="File">
          <td class="label">File</td>
          <td>
            <input name="upfile" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
            <label><input type="checkbox" name="spoiler"> Spoiler?</label>
          </td>
        </tr>
        <tr id="captchaRow" data-type="Captcha">
          <td class="label">Captcha</td>
          <td>
            <div id="t-root">
              <div id="captcha-container">
                <img id="captchaImg" src="/api/v1/captcha" alt="captcha" style="cursor:pointer" title="Click to refresh">
                <input name="captcha_response" type="text" placeholder="Type the text above" maxlength="8" autocomplete="off">
              </div>
            </div>
          </td>
        </tr>
        <tr data-type="Rules">
          <td colspan="2" class="rules">
            <ul>
              <li>Supported file types: JPEG, PNG, GIF, WebP (max 4MB)</li>
              <li>Images larger than 250x250 will be thumbnailed.</li>
              <li>Read the <a href="/rules">Rules</a> before posting.</li>
            </ul>
          </td>
        </tr>
      </tbody>
    </table>
  </form>
</div>
<div id="postFormError"></div>

<script>
(function() {
  var toggle = document.getElementById('togglePostForm');
  var form = document.getElementById('postForm');
  if (toggle && form) {
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      form.style.display = form.style.display === 'none' ? '' : 'none';
    });
  }
})();
</script>

<hr class="abovePostForm">

<!-- Board Subtitle / Flags -->
<div class="boardSubtitle">
  <?php if (!empty($board_subtitle)): ?><span><?= htmlspecialchars($board_subtitle) ?></span><?php endif; ?>
</div>

<!-- Sorting Controls -->
<div class="sortControls" style="text-align:right;margin:0 5px 5px 0;font-size:10pt;">
  [<a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a>]
  [<a href="/<?= htmlspecialchars($board_slug) ?>/archive">Archive</a>]
  <span id="ctrl-top">
    [<a href="#bottom">Bottom</a>]
  </span>
</div>

<hr>

<!-- Threads -->
<form id="delform" name="delform" class="deleteform">
  <?php if (empty($threads)): ?>
    <div style="text-align:center;padding:40px;color:#666;font-size:14px;">
      No threads yet. Be the first to start a thread!
    </div>
  <?php endif; ?>

  <?php foreach (($threads ?? []) as $thread): ?>
  <div class="thread" id="t<?= (int)$thread['id'] ?>">

    <!-- OP Post -->
    <?php $op = $thread['op'] ?? []; ?>
    <?php if (!empty($op)): ?>
    <div class="postContainer opContainer" id="pc<?= (int)($op['id'] ?? 0) ?>">
      <div id="p<?= (int)($op['id'] ?? 0) ?>" class="post op">
        <div class="postInfo desktop" id="pi<?= (int)($op['id'] ?? 0) ?>">
          <input type="checkbox" name="<?= (int)($op['id'] ?? 0) ?>" value="delete">
          <?php if (!empty($op['subject'])): ?>
            <span class="subject"><?= htmlspecialchars($op['subject']) ?></span>
          <?php endif; ?>
          <span class="nameBlock">
            <span class="name"><?= htmlspecialchars($op['author_name'] ?? 'Anonymous') ?></span>
            <?php if (!empty($op['tripcode'])): ?>
              <span class="postertrip"><?= htmlspecialchars($op['tripcode']) ?></span>
            <?php endif; ?>
          </span>
          <span class="dateTime" data-utc="<?= htmlspecialchars($op['created_at'] ?? '') ?>"><?= htmlspecialchars($op['formatted_time'] ?? $op['created_at'] ?? '') ?></span>
          <span class="postNum desktop">
            <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>#p<?= (int)($op['id'] ?? 0) ?>" title="Link to this post">No.</a>
            <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>#q<?= (int)($op['id'] ?? 0) ?>" title="Reply to this post"><?= (int)($op['id'] ?? 0) ?></a>
          </span>
          <span class="postMenuBtn" title="Post menu">▶</span>
          [<a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>" class="replylink">Reply</a>]
        </div>

        <?php if (!empty($op['media_url'])): ?>
        <div class="file" id="f<?= (int)($op['id'] ?? 0) ?>">
          <div class="fileText" id="fT<?= (int)($op['id'] ?? 0) ?>">
            File: <a href="<?= htmlspecialchars($op['media_url']) ?>" target="_blank"><?= htmlspecialchars($op['media_filename'] ?? 'image') ?></a>
            (<?= htmlspecialchars($op['media_size_human'] ?? '') ?>, <?= htmlspecialchars($op['media_dimensions'] ?? '') ?>)
          </div>
          <a class="fileThumb" href="<?= htmlspecialchars($op['media_url']) ?>" target="_blank">
            <img src="<?= htmlspecialchars($op['thumb_url'] ?? $op['media_url']) ?>" alt="<?= htmlspecialchars($op['media_size_human'] ?? '') ?>" loading="lazy"
                 style="max-width:250px;max-height:250px;">
          </a>
        </div>
        <?php endif; ?>

        <blockquote class="postMessage" id="m<?= (int)($op['id'] ?? 0) ?>">
          <?= $op['content_html'] ?? htmlspecialchars($op['content'] ?? '') ?>
        </blockquote>
      </div>
    </div>
    <?php endif; ?>

    <!-- Omitted info -->
    <?php if (($thread['omitted_posts'] ?? 0) > 0): ?>
    <span class="summary desktop">
      <span class="info"><?= (int)$thread['omitted_posts'] ?> post<?= $thread['omitted_posts'] > 1 ? 's' : '' ?>
      <?php if (($thread['omitted_images'] ?? 0) > 0): ?>and <?= (int)$thread['omitted_images'] ?> image repl<?= $thread['omitted_images'] > 1 ? 'ies' : 'y' ?><?php endif; ?>
      omitted. <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>">Click here</a> to view.
      </span>
    </span>
    <?php endif; ?>

    <!-- Latest Replies -->
    <?php foreach (($thread['latest_replies'] ?? []) as $reply): ?>
    <div class="postContainer replyContainer" id="pc<?= (int)$reply['id'] ?>">
      <div class="sideArrows" id="sa<?= (int)$reply['id'] ?>">&gt;&gt;</div>
      <div id="p<?= (int)$reply['id'] ?>" class="post reply">
        <div class="postInfo desktop" id="pi<?= (int)$reply['id'] ?>">
          <input type="checkbox" name="<?= (int)$reply['id'] ?>" value="delete">
          <span class="nameBlock">
            <span class="name"><?= htmlspecialchars($reply['author_name'] ?? 'Anonymous') ?></span>
            <?php if (!empty($reply['tripcode'])): ?>
              <span class="postertrip"><?= htmlspecialchars($reply['tripcode']) ?></span>
            <?php endif; ?>
          </span>
          <span class="dateTime" data-utc="<?= htmlspecialchars($reply['created_at'] ?? '') ?>"><?= htmlspecialchars($reply['formatted_time'] ?? $reply['created_at'] ?? '') ?></span>
          <span class="postNum desktop">
            <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>#p<?= (int)$reply['id'] ?>" title="Link to this post">No.</a>
            <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>#q<?= (int)$reply['id'] ?>" title="Reply to this post"><?= (int)$reply['id'] ?></a>
          </span>
          <span class="postMenuBtn" title="Post menu">▶</span>
        </div>

        <?php if (!empty($reply['media_url'])): ?>
        <div class="file" id="f<?= (int)$reply['id'] ?>">
          <div class="fileText" id="fT<?= (int)$reply['id'] ?>">
            File: <a href="<?= htmlspecialchars($reply['media_url']) ?>" target="_blank"><?= htmlspecialchars($reply['media_filename'] ?? 'image') ?></a>
            (<?= htmlspecialchars($reply['media_size_human'] ?? '') ?>)
          </div>
          <a class="fileThumb" href="<?= htmlspecialchars($reply['media_url']) ?>" target="_blank">
            <img src="<?= htmlspecialchars($reply['thumb_url'] ?? $reply['media_url']) ?>" alt="<?= htmlspecialchars($reply['media_size_human'] ?? '') ?>" loading="lazy"
                 style="max-width:125px;max-height:125px;">
          </a>
        </div>
        <?php endif; ?>

        <blockquote class="postMessage" id="m<?= (int)$reply['id'] ?>">
          <?= $reply['content_html'] ?? htmlspecialchars($reply['content'] ?? '') ?>
        </blockquote>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
  <hr>
  <?php endforeach; ?>

  <!-- Delete / Report Controls -->
  <div class="deleteform-controls" id="ctrl-bottom">
    <span class="deleteBtn">
      Delete Post
      [<label><input type="checkbox" name="onlyimgdel"> File Only</label>]
      Password <input type="password" name="pwd" id="delPassword" maxlength="8" size="8">
      <input type="submit" value="Delete">
    </span>
    [<input type="button" value="Report" onclick="alert('Select a post first')">]
  </div>
</form>

<hr>

<!-- Pagination -->
<div class="pagelist desktop" id="pagelist">
  <div class="pages">
    <?php $total_pages = $total_pages ?? 1; ?>
    <?php if ($page_num > 1): ?>
      <a href="/<?= htmlspecialchars($board_slug) ?>/<?= $page_num - 1 ?>" class="prev">&lt;&lt; Previous</a>
    <?php endif; ?>
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
      <?php if ($p === $page_num): ?>
        <strong>[<?= $p ?>]</strong>
      <?php else: ?>
        <a href="/<?= htmlspecialchars($board_slug) ?>/<?= $p ?>">[<?= $p ?>]</a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page_num < $total_pages): ?>
      <a href="/<?= htmlspecialchars($board_slug) ?>/<?= $page_num + 1 ?>" class="next">Next &gt;&gt;</a>
    <?php endif; ?>
  </div>
</div>

<a id="bottom"></a>

<?php
$__content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
