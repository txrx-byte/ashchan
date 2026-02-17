<?php ob_start(); ?>

<!-- Post Form (Thread Reply) -->
<div id="postForm">
  <form id="mpostform" name="post" action="/api/v1/boards/<?= htmlspecialchars($board_slug) ?>/threads/<?= (int)$thread_id ?>/posts" method="post" enctype="multipart/form-data">
    <table class="postForm replyMode">
      <tbody>
        <tr data-type="Name">
          <td class="label">Name</td>
          <td><input name="name" type="text" placeholder="Anonymous" autocomplete="off" maxlength="75"></td>
        </tr>
        <tr data-type="Options">
          <td class="label">Options</td>
          <td><input name="email" type="text" placeholder="sage" autocomplete="off" maxlength="75"></td>
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
              <img id="captchaImg" src="/api/v1/captcha" alt="captcha" style="cursor:pointer" title="Click to refresh">
              <input name="captcha_response" type="text" placeholder="Type the text above" maxlength="8" autocomplete="off">
            </div>
          </td>
        </tr>
        <tr>
          <td></td>
          <td><input type="submit" value="Post"></td>
        </tr>
      </tbody>
    </table>
  </form>
</div>
<div id="postFormError"></div>

<hr>

<!-- Thread Controls -->
<div class="thread-controls">
  <span id="ctrl-top">
    [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
    [<a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a>]
    [<a href="#bottom">Bottom</a>]
    [<a href="#" class="watchThread">Watch Thread</a>]
  </span>
</div>

<hr>

<!-- Thread Container -->
<form id="delform" name="delform" class="deleteform">
  <div class="thread" id="t<?= (int)$thread_id ?>">

    <!-- OP Post -->
    <?php if (!empty($op)): ?>
    <div class="postContainer opContainer" id="pc<?= (int)$op['id'] ?>">
      <div id="p<?= (int)$op['id'] ?>" class="post op">
        <div class="postInfoM mobile" id="pim<?= (int)$op['id'] ?>">
          <span class="nameBlock">
            <span class="name"><?= htmlspecialchars($op['author_name'] ?? 'Anonymous') ?></span>
            <?php if (!empty($op['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($op['tripcode']) ?></span><?php endif; ?>
          </span><br>
          <span class="dateTime" data-utc="<?= htmlspecialchars($op['created_at'] ?? '') ?>"><?= htmlspecialchars($op['formatted_time'] ?? $op['created_at'] ?? '') ?></span>
          <span class="postNum mobile">
            <a href="#p<?= (int)$op['id'] ?>">No.</a>
            <a href="#q<?= (int)$op['id'] ?>"><?= (int)$op['id'] ?></a>
          </span>
        </div>
        <div class="postInfo desktop" id="pi<?= (int)$op['id'] ?>">
          <input type="checkbox" name="<?= (int)$op['id'] ?>" value="delete">
          <?php if (!empty($op['subject'])): ?><span class="subject"><?= htmlspecialchars($op['subject']) ?></span><?php endif; ?>
          <span class="nameBlock">
            <span class="name"><?= htmlspecialchars($op['author_name'] ?? 'Anonymous') ?></span>
            <?php if (!empty($op['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($op['tripcode']) ?></span><?php endif; ?>
            <?php if (!empty($op['capcode'])): ?><span class="capcode">## <?= htmlspecialchars($op['capcode']) ?></span><?php endif; ?>
          </span>
          <span class="dateTime" data-utc="<?= htmlspecialchars($op['created_at'] ?? '') ?>"><?= htmlspecialchars($op['formatted_time'] ?? $op['created_at'] ?? '') ?></span>
          <span class="postNum desktop">
            <a href="#p<?= (int)$op['id'] ?>" title="Link to this post">No.</a>
            <a href="#q<?= (int)$op['id'] ?>" title="Reply to this post"><?= (int)$op['id'] ?></a>
          </span>
          <span class="postMenuBtn" title="Post menu">▶</span>
          <?php if (!empty($op['backlinks'])): ?>
          <span class="backlink">
            <?php foreach ($op['backlinks'] as $bl): ?>
              <a href="#p<?= (int)$bl ?>" class="quotelink">&gt;&gt;<?= (int)$bl ?></a>
            <?php endforeach; ?>
          </span>
          <?php endif; ?>
        </div>

        <?php if (!empty($op['media_url'])): ?>
        <div class="file" id="f<?= (int)$op['id'] ?>">
          <div class="fileText" id="fT<?= (int)$op['id'] ?>">
            File: <a href="<?= htmlspecialchars($op['media_url']) ?>" target="_blank"><?= htmlspecialchars($op['media_filename'] ?? 'image') ?></a>
            (<?= htmlspecialchars($op['media_size_human'] ?? '') ?>, <?= htmlspecialchars($op['media_dimensions'] ?? '') ?>)
          </div>
          <a class="fileThumb" href="<?= htmlspecialchars($op['media_url']) ?>" target="_blank">
            <img src="<?= htmlspecialchars($op['thumb_url'] ?? $op['media_url']) ?>" alt="<?= htmlspecialchars($op['media_size_human'] ?? '') ?>" loading="lazy"
                 style="max-width:250px;max-height:250px;">
          </a>
        </div>
        <?php endif; ?>

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
        <div class="postInfoM mobile" id="pim<?= (int)$reply['id'] ?>">
          <span class="nameBlock">
            <span class="name"><?= htmlspecialchars($reply['author_name'] ?? 'Anonymous') ?></span>
            <?php if (!empty($reply['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($reply['tripcode']) ?></span><?php endif; ?>
          </span><br>
          <span class="dateTime" data-utc="<?= htmlspecialchars($reply['created_at'] ?? '') ?>"><?= htmlspecialchars($reply['formatted_time'] ?? $reply['created_at'] ?? '') ?></span>
          <span class="postNum mobile">
            <a href="#p<?= (int)$reply['id'] ?>">No.</a>
            <a href="#q<?= (int)$reply['id'] ?>"><?= (int)$reply['id'] ?></a>
          </span>
        </div>
        <div class="postInfo desktop" id="pi<?= (int)$reply['id'] ?>">
          <input type="checkbox" name="<?= (int)$reply['id'] ?>" value="delete">
          <span class="nameBlock">
            <span class="name"><?= htmlspecialchars($reply['author_name'] ?? 'Anonymous') ?></span>
            <?php if (!empty($reply['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($reply['tripcode']) ?></span><?php endif; ?>
            <?php if (!empty($reply['capcode'])): ?><span class="capcode">## <?= htmlspecialchars($reply['capcode']) ?></span><?php endif; ?>
          </span>
          <span class="dateTime" data-utc="<?= htmlspecialchars($reply['created_at'] ?? '') ?>"><?= htmlspecialchars($reply['formatted_time'] ?? $reply['created_at'] ?? '') ?></span>
          <span class="postNum desktop">
            <a href="#p<?= (int)$reply['id'] ?>" title="Link to this post">No.</a>
            <a href="#q<?= (int)$reply['id'] ?>" title="Reply to this post"><?= (int)$reply['id'] ?></a>
          </span>
          <span class="postMenuBtn" title="Post menu">▶</span>
          <?php if (!empty($reply['backlinks'])): ?>
          <span class="backlink">
            <?php foreach ($reply['backlinks'] as $bl): ?>
              <a href="#p<?= (int)$bl ?>" class="quotelink">&gt;&gt;<?= (int)$bl ?></a>
            <?php endforeach; ?>
          </span>
          <?php endif; ?>
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

<!-- Thread Stats + Auto-Update -->
<div class="thread-stats">
  <span class="ts-replies"><?= count($replies ?? []) ?> replies</span>
  <span class="ts-images"><?= (int)($image_count ?? 0) ?> images</span>
  <?php if (!empty($thread_locked)): ?><span class="ts-locked">[Locked]</span><?php endif; ?>
  <?php if (!empty($thread_sticky)): ?><span class="ts-sticky">[Sticky]</span><?php endif; ?>
</div>

<div id="autoUpdateCtrl" class="auto-update-ctrl">
  <label>
    <input type="checkbox" checked> Auto
  </label>
  <span id="autoUpdateStatus"></span>
  [<a href="#" id="updateNow">Update</a>]
</div>

<hr>

<!-- Bottom Controls -->
<div class="thread-controls">
  <span>
    [<a href="/<?= htmlspecialchars($board_slug) ?>/">Return</a>]
    [<a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a>]
    [<a href="#top">Top</a>]
  </span>
</div>

<a id="bottom"></a>

<?php
$__content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
