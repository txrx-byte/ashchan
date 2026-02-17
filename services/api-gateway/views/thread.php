<?php ob_start(); ?>

<hr class="abovePostForm">

<!-- Mobile nav links -->
<div class="navLinks mobile">
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a></span>
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a></span>
  <span class="mobileib button"><a href="#bottom">Bottom</a></span>
</div>

<!-- Reply Mode Banner -->
<div class="navLinks desktop">
  [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
  [<a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a>]
  [<a href="#bottom">Bottom</a>]
</div>

<div id="mpostform">
  <a href="#" class="mobilePostFormToggle mobile hidden button">Post a Reply</a>
</div>

<!-- Post Form -->
<form name="post" action="/api/v1/boards/<?= htmlspecialchars($board_slug) ?>/threads/<?= (int)$thread_id ?>/posts" method="post" enctype="multipart/form-data">
  <input type="hidden" name="MAX_FILE_SIZE" value="4194304">
  <input type="hidden" name="mode" value="regist">
  <input type="hidden" name="resto" value="<?= (int)$thread_id ?>">
  <table class="postForm" id="postForm">
    <tbody>
      <tr data-type="Name">
        <td>Name</td>
        <td><input name="name" type="text" tabindex="1" placeholder="Anonymous"></td>
      </tr>
      <tr data-type="Options">
        <td>Options</td>
        <td><input name="email" type="text" tabindex="2"></td>
      </tr>
      <tr data-type="Comment">
        <td>Comment</td>
        <td><textarea name="com" cols="48" rows="4" wrap="soft" tabindex="4"></textarea></td>
      </tr>
      <tr data-type="File">
        <td>File</td>
        <td><input id="postFile" name="upfile" type="file" tabindex="8"></td>
      </tr>
      <tr class="rules">
        <td colspan="2">
          <ul class="rules">
            <li>Read the <a href="/rules">Rules</a> before posting.</li>
            <li>Supported: JPEG, PNG, GIF, WebP. Max file size: 4 MB.</li>
          </ul>
        </td>
      </tr>
    </tbody>
    <tfoot>
      <tr><td colspan="2">
        <input type="submit" value="Post" tabindex="6">
        <div id="postFormError"></div>
      </td></tr>
    </tfoot>
  </table>
</form>

<hr>

<!-- Thread Container -->
<form name="delform" id="delform" action="/api/v1/delete" method="post">
  <div class="board">
    <div class="thread" id="t<?= (int)$thread_id ?>">

      <!-- OP Post -->
      <?php if (!empty($op)): ?>
      <div class="postContainer opContainer" id="pc<?= (int)$op['id'] ?>">
        <div id="p<?= (int)$op['id'] ?>" class="post op">

          <!-- Mobile Post Info -->
          <div class="postInfoM mobile" id="pim<?= (int)$op['id'] ?>">
            <span class="nameBlock">
              <span class="name"><?= htmlspecialchars($op['author_name'] ?? 'Anonymous') ?></span>
              <?php if (!empty($op['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($op['tripcode']) ?></span><?php endif; ?>
              <?php if (!empty($op['capcode'])): ?><strong class="capcode">## <?= htmlspecialchars($op['capcode']) ?></strong><?php endif; ?>
              <?php if (!empty($thread_sticky)): ?><img src="/static/img/sticky.gif" alt="Sticky" title="Sticky" class="stickyIcon"><?php endif; ?>
              <?php if (!empty($thread_locked)): ?><img src="/static/img/closed.gif" alt="Closed" title="Closed" class="closedIcon"><?php endif; ?>
              <br>
              <?php if (!empty($op['subject'])): ?><span class="subject"><?= htmlspecialchars($op['subject']) ?></span><?php endif; ?>
            </span>
            <span class="dateTime postNum" data-utc="<?= htmlspecialchars($op['created_at'] ?? '') ?>">
              <?= htmlspecialchars($op['formatted_time'] ?? $op['created_at'] ?? '') ?>
              <a href="#p<?= (int)$op['id'] ?>" title="Link to this post">No.</a><a href="#q<?= (int)$op['id'] ?>" title="Reply to this post"><?= (int)$op['id'] ?></a>
            </span>
          </div>

          <?php if (!empty($op['media_url'])): ?>
          <div class="file" id="f<?= (int)$op['id'] ?>">
            <div class="fileText" id="fT<?= (int)$op['id'] ?>">
              File: <a href="<?= htmlspecialchars($op['media_url']) ?>" target="_blank"><?= htmlspecialchars($op['media_filename'] ?? 'image') ?></a>
              (<?= htmlspecialchars($op['media_size_human'] ?? '') ?><?php if (!empty($op['media_dimensions'])): ?>, <?= htmlspecialchars($op['media_dimensions']) ?><?php endif; ?>)
            </div>
            <a class="fileThumb" href="<?= htmlspecialchars($op['media_url']) ?>" target="_blank">
              <img src="<?= htmlspecialchars($op['thumb_url'] ?? $op['media_url']) ?>" alt="<?= htmlspecialchars($op['media_size_human'] ?? '') ?>" loading="lazy" style="height:auto;width:auto;max-width:250px;max-height:250px;">
              <div data-tip data-tip-cb="mShowFull" class="mFileInfo mobile"><?= htmlspecialchars($op['media_size_human'] ?? '') ?></div>
            </a>
          </div>
          <?php endif; ?>

          <!-- Desktop Post Info -->
          <div class="postInfo desktop" id="pi<?= (int)$op['id'] ?>">
            <input type="checkbox" name="<?= (int)$op['id'] ?>" value="delete">
            <?php if (!empty($op['subject'])): ?><span class="subject"><?= htmlspecialchars($op['subject']) ?></span><?php endif; ?>
            <span class="nameBlock">
              <span class="name"><?= htmlspecialchars($op['author_name'] ?? 'Anonymous') ?></span>
              <?php if (!empty($op['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($op['tripcode']) ?></span><?php endif; ?>
              <?php if (!empty($op['capcode'])): ?><strong class="capcode">## <?= htmlspecialchars($op['capcode']) ?></strong><?php endif; ?>
            </span>
            <span class="dateTime" data-utc="<?= htmlspecialchars($op['created_at'] ?? '') ?>"><?= htmlspecialchars($op['formatted_time'] ?? $op['created_at'] ?? '') ?></span>
            <span class="postNum desktop">
              <a href="#p<?= (int)$op['id'] ?>" title="Link to this post">No.</a><a href="#q<?= (int)$op['id'] ?>" title="Reply to this post"><?= (int)$op['id'] ?></a>
              <?php if (!empty($thread_sticky)): ?><img src="/static/img/sticky.gif" alt="Sticky" title="Sticky" class="stickyIcon"><?php endif; ?>
              <?php if (!empty($thread_locked)): ?><img src="/static/img/closed.gif" alt="Closed" title="Closed" class="closedIcon"><?php endif; ?>
            </span>
          </div>

          <blockquote class="postMessage" id="m<?= (int)$op['id'] ?>">
            <?= $op['content_html'] ?? htmlspecialchars($op['content'] ?? '') ?>
          </blockquote>
        </div>
      </div>
      <?php endif; ?>

      <!-- Replies -->
      <?php foreach (($replies ?? []) as $reply): ?>
      <div class="postContainer replyContainer" id="pc<?= (int)$reply['id'] ?>">
        <div class="sideArrows" id="sa<?= (int)$reply['id'] ?>">&gt;&gt;</div>
        <div id="p<?= (int)$reply['id'] ?>" class="post reply">

          <!-- Mobile Post Info -->
          <div class="postInfoM mobile" id="pim<?= (int)$reply['id'] ?>">
            <span class="nameBlock">
              <span class="name"><?= htmlspecialchars($reply['author_name'] ?? 'Anonymous') ?></span>
              <?php if (!empty($reply['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($reply['tripcode']) ?></span><?php endif; ?>
              <?php if (!empty($reply['capcode'])): ?><strong class="capcode">## <?= htmlspecialchars($reply['capcode']) ?></strong><?php endif; ?>
              <br>
            </span>
            <span class="dateTime postNum" data-utc="<?= htmlspecialchars($reply['created_at'] ?? '') ?>">
              <?= htmlspecialchars($reply['formatted_time'] ?? $reply['created_at'] ?? '') ?>
              <a href="#p<?= (int)$reply['id'] ?>" title="Link to this post">No.</a><a href="#q<?= (int)$reply['id'] ?>" title="Reply to this post"><?= (int)$reply['id'] ?></a>
            </span>
          </div>

          <!-- Desktop Post Info -->
          <div class="postInfo desktop" id="pi<?= (int)$reply['id'] ?>">
            <input type="checkbox" name="<?= (int)$reply['id'] ?>" value="delete">
            <span class="nameBlock">
              <span class="name"><?= htmlspecialchars($reply['author_name'] ?? 'Anonymous') ?></span>
              <?php if (!empty($reply['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($reply['tripcode']) ?></span><?php endif; ?>
              <?php if (!empty($reply['capcode'])): ?><strong class="capcode">## <?= htmlspecialchars($reply['capcode']) ?></strong><?php endif; ?>
            </span>
            <span class="dateTime" data-utc="<?= htmlspecialchars($reply['created_at'] ?? '') ?>"><?= htmlspecialchars($reply['formatted_time'] ?? $reply['created_at'] ?? '') ?></span>
            <span class="postNum desktop">
              <a href="#p<?= (int)$reply['id'] ?>" title="Link to this post">No.</a><a href="#q<?= (int)$reply['id'] ?>" title="Reply to this post"><?= (int)$reply['id'] ?></a>
            </span>
          </div>

          <?php if (!empty($reply['media_url'])): ?>
          <div class="file" id="f<?= (int)$reply['id'] ?>">
            <div class="fileText" id="fT<?= (int)$reply['id'] ?>">
              File: <a href="<?= htmlspecialchars($reply['media_url']) ?>" target="_blank"><?= htmlspecialchars($reply['media_filename'] ?? 'image') ?></a>
              (<?= htmlspecialchars($reply['media_size_human'] ?? '') ?>)
            </div>
            <a class="fileThumb" href="<?= htmlspecialchars($reply['media_url']) ?>" target="_blank">
              <img src="<?= htmlspecialchars($reply['thumb_url'] ?? $reply['media_url']) ?>" alt="<?= htmlspecialchars($reply['media_size_human'] ?? '') ?>" loading="lazy" style="height:auto;width:auto;max-width:125px;max-height:125px;">
              <div data-tip data-tip-cb="mShowFull" class="mFileInfo mobile"><?= htmlspecialchars($reply['media_size_human'] ?? '') ?></div>
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
  </div>

  <!-- Bottom controls -->
  <div class="bottomCtrl desktop">
    <span class="deleteform">
      <input type="hidden" name="mode" value="usrdel">
      Delete Post: [<input type="checkbox" name="onlyimgdel" value="on">File Only]
      <input type="hidden" id="delPassword" name="pwd">
      <input type="submit" value="Delete">
      <input id="bottomReportBtn" type="button" value="Report">
    </span>
    <span class="stylechanger">
      Style: <select id="styleSelector">
        <option value="yotsuba">Yotsuba</option>
        <option value="yotsuba-b" selected>Yotsuba B</option>
        <option value="futaba">Futaba</option>
        <option value="burichan">Burichan</option>
        <option value="photon">Photon</option>
        <option value="tomorrow">Tomorrow</option>
      </select>
    </span>
  </div>
</form>

<hr>

<!-- Thread Stats -->
<div id="thread-stats" class="ts-8">
  <span class="ts-replies"><?= count($replies ?? []) ?> / <?= (int)($image_count ?? 0) ?></span>
  <?php if (!empty($thread_locked)): ?> / <span class="ts-locked">[Locked]</span><?php endif; ?>
  <?php if (!empty($thread_sticky)): ?> / <span class="ts-sticky">[Sticky]</span><?php endif; ?>
</div>

<!-- Auto-Update -->
<div id="autoUpdateCtrl">
  <label><input type="checkbox" id="autoUpdateCheck" checked> Auto</label>
  <span id="autoUpdateStatus"></span>
  [<a href="javascript:void(0);" id="updateNow">Update</a>]
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

<?php
$is_index = false;
$__content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
