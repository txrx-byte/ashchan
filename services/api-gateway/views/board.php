<?php ob_start(); ?>

<hr class="abovePostForm">

<!-- Mobile nav links -->
<div class="navLinks mobile">
  <span class="mobileib button"><a href="#bottom">Bottom</a></span>
  <span class="mobileib button"><a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a></span>
  <span class="mobileib button"><a href="#" id="refresh_top">Refresh</a></span>
</div>

<!-- Post Form Toggle -->
<div id="togglePostFormLink" class="desktop">
  [<a href="javascript:void(0);" onclick="var f=document.getElementById('postForm');f.style.display=f.style.display==='none'?'table':'none';">Start a New Thread</a>]
</div>

<!-- Post Form -->
<form name="post" action="/api/v1/boards/<?= htmlspecialchars($board_slug) ?>/threads" method="post" enctype="multipart/form-data">
  <input type="hidden" name="MAX_FILE_SIZE" value="4194304">
  <input type="hidden" name="mode" value="regist">
  <table class="postForm hideMobile" id="postForm" style="display:none;">
    <tbody>
      <tr data-type="Name">
        <td>Name</td>
        <td><input name="name" type="text" tabindex="1" placeholder="Anonymous"></td>
      </tr>
      <tr data-type="Options">
        <td>Options</td>
        <td><input name="email" type="text" tabindex="2"></td>
      </tr>
      <tr data-type="Subject">
        <td>Subject</td>
        <td><input name="sub" type="text" tabindex="3"><input type="submit" value="Post" tabindex="10"></td>
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
      <tr><td colspan="2"><div id="postFormError"></div></td></tr>
    </tfoot>
  </table>
</form>

<hr class="aboveMidAd">

<!-- Board controls -->
<div id="ctrl-top" class="desktop">
  <hr>
  <input type="text" id="search-box" placeholder="Search OPs&hellip;">
  [<a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a>]
  [<a href="/<?= htmlspecialchars($board_slug) ?>/archive">Archive</a>]
</div>

<hr>

<!-- Threads -->
<form name="delform" id="delform" action="/api/v1/delete" method="post">
  <div class="board">
    <?php if (empty($threads)): ?>
    <div class="board" style="text-align:center;padding:40px;color:#666;font-size:14px;">
      No threads yet. Be the first to start a thread!
    </div>
    <?php endif; ?>

    <?php foreach (($threads ?? []) as $thread): ?>
    <div class="thread" id="t<?= (int)$thread['id'] ?>">
      <?php $op = $thread['op'] ?? []; ?>
      <?php if (!empty($op)): ?>
      <div class="postContainer opContainer" id="pc<?= (int)($op['id'] ?? 0) ?>">
        <div id="p<?= (int)($op['id'] ?? 0) ?>" class="post op">

          <?php if (!empty($op['media_url'])): ?>
          <div class="file" id="f<?= (int)($op['id'] ?? 0) ?>">
            <div class="fileText" id="fT<?= (int)($op['id'] ?? 0) ?>">
              File: <a href="<?= htmlspecialchars($op['media_url']) ?>" target="_blank"><?= htmlspecialchars($op['media_filename'] ?? 'image') ?></a>
              (<?= htmlspecialchars($op['media_size_human'] ?? '') ?><?php if (!empty($op['media_dimensions'])): ?>, <?= htmlspecialchars($op['media_dimensions']) ?><?php endif; ?>)
            </div>
            <a class="fileThumb" href="<?= htmlspecialchars($op['media_url']) ?>" target="_blank">
              <img src="<?= htmlspecialchars($op['thumb_url'] ?? $op['media_url']) ?>" alt="<?= htmlspecialchars($op['media_size_human'] ?? '') ?>" loading="lazy" style="height:auto;width:auto;max-width:250px;max-height:250px;">
            </a>
          </div>
          <?php endif; ?>

          <div class="postInfo desktop" id="pi<?= (int)($op['id'] ?? 0) ?>">
            <input type="checkbox" name="<?= (int)($op['id'] ?? 0) ?>" value="delete">
            <?php if (!empty($op['subject'])): ?>
            <span class="subject"><?= htmlspecialchars($op['subject']) ?></span>
            <?php endif; ?>
            <span class="nameBlock">
              <span class="name"><?= htmlspecialchars($op['author_name'] ?? 'Anonymous') ?></span>
              <?php if (!empty($op['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($op['tripcode']) ?></span><?php endif; ?>
            </span>
            <span class="dateTime" data-utc="<?= htmlspecialchars($op['created_at'] ?? '') ?>"><?= htmlspecialchars($op['formatted_time'] ?? $op['created_at'] ?? '') ?></span>
            <span class="postNum desktop">
              <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>#p<?= (int)($op['id'] ?? 0) ?>" title="Link to this post">No.</a><a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>#q<?= (int)($op['id'] ?? 0) ?>" title="Reply to this post"><?= (int)($op['id'] ?? 0) ?></a>
              <?php if (!empty($thread['sticky'])): ?><img src="/static/img/sticky.gif" alt="Sticky" title="Sticky" class="stickyIcon"><?php endif; ?>
              <?php if (!empty($thread['locked'])): ?><img src="/static/img/closed.gif" alt="Closed" title="Closed" class="closedIcon"><?php endif; ?>
              &nbsp; <span>[<a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>" class="replylink">Reply</a>]</span>
            </span>
          </div>

          <blockquote class="postMessage" id="m<?= (int)($op['id'] ?? 0) ?>">
            <?= $op['content_html'] ?? htmlspecialchars($op['content'] ?? '') ?>
          </blockquote>
        </div>

        <!-- Mobile post link -->
        <div class="postLink mobile">
          <span class="info"><?= (int)($thread['reply_count'] ?? 0) ?> Replies / <?= (int)($thread['image_count'] ?? 0) ?> Images</span>
          <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>" class="button">View Thread</a>
        </div>
      </div>
      <?php endif; ?>

      <?php if (($thread['omitted_posts'] ?? 0) > 0): ?>
      <span class="summary desktop">
        <?= (int)$thread['omitted_posts'] ?> repl<?= $thread['omitted_posts'] > 1 ? 'ies' : 'y' ?>
        <?php if (($thread['omitted_images'] ?? 0) > 0): ?>and <?= (int)$thread['omitted_images'] ?> image<?= $thread['omitted_images'] > 1 ? 's' : '' ?><?php endif; ?>
        omitted. <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>" class="replylink">Click here</a> to view.
      </span>
      <?php endif; ?>

      <?php foreach (($thread['latest_replies'] ?? []) as $reply): ?>
      <div class="postContainer replyContainer" id="pc<?= (int)$reply['id'] ?>">
        <div class="sideArrows" id="sa<?= (int)$reply['id'] ?>">&gt;&gt;</div>
        <div id="p<?= (int)$reply['id'] ?>" class="post reply">
          <div class="postInfo desktop" id="pi<?= (int)$reply['id'] ?>">
            <input type="checkbox" name="<?= (int)$reply['id'] ?>" value="delete">
            <span class="nameBlock">
              <span class="name"><?= htmlspecialchars($reply['author_name'] ?? 'Anonymous') ?></span>
              <?php if (!empty($reply['tripcode'])): ?><span class="postertrip"><?= htmlspecialchars($reply['tripcode']) ?></span><?php endif; ?>
            </span>
            <span class="dateTime" data-utc="<?= htmlspecialchars($reply['created_at'] ?? '') ?>"><?= htmlspecialchars($reply['formatted_time'] ?? $reply['created_at'] ?? '') ?></span>
            <span class="postNum desktop">
              <a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>#p<?= (int)$reply['id'] ?>" title="Link to this post">No.</a><a href="/<?= htmlspecialchars($board_slug) ?>/thread/<?= (int)$thread['id'] ?>#q<?= (int)$reply['id'] ?>" title="Reply to this post"><?= (int)$reply['id'] ?></a>
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

<!-- Desktop Pagination -->
<div class="pagelist desktop">
  <?php $total_pages = $total_pages ?? 1; ?>
  <div class="prev">
    <?php if ($page_num > 1): ?>
    <form class="pageSwitcherForm" action="/<?= htmlspecialchars($board_slug) ?>/?page=<?= $page_num - 1 ?>">
      <input type="submit" value="Previous">
    </form>
    <?php else: ?>
    <span>Previous</span>
    <?php endif; ?>
  </div>
  <div class="pages">
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
    [<?php if ($p === $page_num): ?><strong><a href="/<?= htmlspecialchars($board_slug) ?>/?page=<?= $p ?>"><?= $p ?></a></strong><?php else: ?><a href="/<?= htmlspecialchars($board_slug) ?>/?page=<?= $p ?>"><?= $p ?></a><?php endif; ?>]
    <?php endfor; ?>
  </div>
  <div class="next">
    <?php if ($page_num < $total_pages): ?>
    <form class="pageSwitcherForm" action="/<?= htmlspecialchars($board_slug) ?>/?page=<?= $page_num + 1 ?>">
      <input type="submit" value="Next" accesskey="x">
    </form>
    <?php else: ?>
    <span>Next</span>
    <?php endif; ?>
  </div>
  <div class="pages cataloglink"><a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a></div>
  <div class="pages cataloglink"><a href="/<?= htmlspecialchars($board_slug) ?>/archive">Archive</a></div>
</div>

<!-- Mobile Pagination -->
<div class="mPagelist mobile">
  <div class="pages">
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
    <span>[<?php if ($p === $page_num): ?><strong><a href="/<?= htmlspecialchars($board_slug) ?>/?page=<?= $p ?>"><?= $p ?></a></strong><?php else: ?><a href="/<?= htmlspecialchars($board_slug) ?>/?page=<?= $p ?>"><?= $p ?></a><?php endif; ?>]</span>
    <?php endfor; ?>
    <div class="mobileCatalogLink">[<a href="/<?= htmlspecialchars($board_slug) ?>/catalog">Catalog</a>]</div>
  </div>
  <?php if ($page_num < $total_pages): ?>
  <div class="next"><a href="/<?= htmlspecialchars($board_slug) ?>/?page=<?= $page_num + 1 ?>" class="button">Next</a></div>
  <?php endif; ?>
</div>

<?php
$is_index = true;
$__content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
