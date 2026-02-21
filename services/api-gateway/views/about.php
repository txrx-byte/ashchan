<?php
/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ashchan - About</title>
  <link rel="shortcut icon" href="/static/img/favicon.ico">
  <link rel="stylesheet" href="/static/css/common.css">
  <style>
    body {
      background: #FFFFEE;
      font-family: arial, helvetica, sans-serif;
      font-size: 10pt;
      margin: 0;
      padding: 0;
    }
    a { color: #00E; text-decoration: none; }
    a:hover { color: #DD0000; }

    .top-nav {
      background: #FEDCBA;
      padding: 3px 5px;
      font-size: 9pt;
      border-bottom: 1px solid #D9BFB7;
      text-align: center;
    }
    .top-nav a { margin: 0 2px; }

    .header {
      background: #800000;
      text-align: center;
      padding: 15px 0 12px;
      margin-bottom: 0;
    }
    .header h1 {
      color: #FFF;
      font-family: 'Tahoma', sans-serif;
      font-size: 28px;
      margin: 0;
      letter-spacing: -1px;
    }
    .header h1 a { color: #FFF; text-decoration: none; }
    .header h1 a:hover { color: #FED; }

    .sub-nav {
      background: #800000;
      text-align: center;
      padding: 0 0 10px;
      font-size: 10pt;
    }
    .sub-nav a {
      color: #FEC;
      margin: 0 6px;
      text-decoration: none;
    }
    .sub-nav a:hover { color: #FFF; text-decoration: underline; }

    .faq-content {
      max-width: 700px;
      margin: 0 auto;
      padding: 20px 20px 40px;
    }

    .faq-section {
      margin-bottom: 30px;
    }

    .faq-section h2 {
      color: #800000;
      font-size: 16px;
      border-bottom: 1px solid #D9BFB7;
      padding-bottom: 4px;
      margin: 20px 0 10px;
    }

    .faq-section h3 {
      color: #800000;
      font-size: 13px;
      margin: 15px 0 5px;
    }

    .faq-section h3 a {
      color: #800000;
      text-decoration: none;
    }

    .faq-section p {
      margin: 6px 0;
      line-height: 1.5;
    }

    .faq-section ul {
      margin: 6px 0 6px 20px;
      padding: 0;
      line-height: 1.6;
    }

    .faq-toc {
      background: #D6DAF0;
      border: 1px solid #B7C5D9;
      padding: 10px 15px;
      margin-bottom: 20px;
    }
    .faq-toc h3 {
      margin: 0 0 6px;
      font-size: 12px;
      color: #34345C;
    }
    .faq-toc ul {
      margin: 0;
      padding: 0 0 0 18px;
      font-size: 10pt;
      line-height: 1.6;
    }
    .faq-toc a { color: #34345C; }
    .faq-toc a:hover { color: #DD0000; }

    .quote { color: #789922; }

    .home-footer {
      background: #FEDCBA;
      padding: 6px 5px;
      font-size: 9pt;
      text-align: center;
      border-top: 1px solid #D9BFB7;
      margin-top: 30px;
    }
    .home-footer a { color: #800000; }
  </style>
  <script src="/static/js/core.js" defer></script>
</head>
<body>

<!-- Top board nav -->
<div class="top-nav">
  [<?php foreach (($boards ?? []) as $i => $b): ?><a href="/<?= htmlspecialchars((string) $b['slug']) ?>/" title="<?= htmlspecialchars((string) $b['title']) ?>"><?= htmlspecialchars((string) $b['slug']) ?></a><?php if ($i < count($boards ?? []) - 1): ?> / <?php endif; ?><?php endforeach; ?>]
</div>

<!-- Header -->
<div class="header">
  <h1><a href="/">ashchan</a></h1>
</div>
<div class="sub-nav">
  <a href="/">Home</a>
  <a href="/about">About</a>
  <a href="/rules">Rules</a>
  <a href="/feedback">Feedback</a>
  <a href="/legal">Legal</a>
  <a href="/contact">Contact</a>
</div>

<!-- Content -->
<div class="faq-content">

  <!-- Table of Contents -->
  <div class="faq-toc">
    <h3>Table of Contents</h3>
    <ul>
      <li><a href="#what">What is ashchan?</a></li>
      <li><a href="#boards">What boards are available?</a></li>
      <li><a href="#posting">How do I post?</a></li>
      <li><a href="#formatting">How does text formatting work?</a></li>
      <li><a href="#tripcodes">What are tripcodes?</a></li>
      <li><a href="#capcodes">What are capcodes?</a></li>
      <li><a href="#deleting">Can I delete my own posts?</a></li>
      <li><a href="#images">What image types are supported?</a></li>
      <li><a href="#catalog">What is the catalog?</a></li>
      <li><a href="#sage">What does &ldquo;sage&rdquo; do?</a></li>
      <li><a href="#bumplimit">What is a bump limit?</a></li>
      <li><a href="#rules">What are the rules?</a></li>
      <li><a href="#staff">Who runs ashchan?</a></li>
      <li><a href="#software">What software does ashchan run on?</a></li>
    </ul>
  </div>

  <!-- About -->
  <div class="faq-section" id="what">
    <h2>About</h2>
    <h3><a href="#what">What is ashchan?</a></h3>
    <p>ashchan is a simple, anonymous imageboard where anyone can post comments and share images on a variety of topics. Unlike most online communities, ashchan does not require registration or an account to participate. Every post is anonymous, fostering discussion based on the content of what is said rather than who says it.</p>
    <p>ashchan is organized into boards, each dedicated to a specific topic — from anime and video games to technology, creative arts, and general discussion. Users create threads by uploading an image and writing a comment, and other users can reply to continue the conversation.</p>
    <p>The site follows the traditional imageboard format originating from Japanese boards like Futaba Channel. Content is ephemeral: older threads are pushed off the board as new ones are created, and there is no permanent archive. This encourages fresh discussion and keeps the community active.</p>
  </div>

  <!-- Boards -->
  <div class="faq-section" id="boards">
    <h3><a href="#boards">What boards are available?</a></h3>
    <p>ashchan hosts a wide variety of boards spanning many interests. You can view the complete list of boards on the <a href="/">home page</a>. Boards are organized into categories including:</p>
    <ul>
      <li><strong>Japanese Culture</strong> — Anime, manga, and related topics</li>
      <li><strong>Video Games</strong> — Video game discussion</li>
      <li><strong>Interests</strong> — Technology, science, sports, and more</li>
      <li><strong>Creative</strong> — Art, music, literature, and DIY</li>
      <li><strong>Other</strong> — General discussion and random topics</li>
      <li><strong>Misc.</strong> — Miscellaneous boards (some NSFW)</li>
    </ul>
    <p>Each board has its own subject and culture. Please read any board-specific rules before posting.</p>
  </div>

  <!-- Posting -->
  <div class="faq-section" id="posting">
    <h3><a href="#posting">How do I post?</a></h3>
    <p>To create a new thread, navigate to a board and use the posting form at the top of the page. New threads require an image upload. Fill in the <strong>Comment</strong> field and attach a file, then click &ldquo;Post&rdquo;.</p>
    <p>To reply to an existing thread, click on the thread to open it and use the reply form. Replies do not require an image, but you may still upload one.</p>
    <p>The <strong>Name</strong> field is optional. If left blank, your post will appear as &ldquo;Anonymous&rdquo;. You may enter a name, or a name with a <a href="#tripcodes">tripcode</a>.</p>
    <p>The <strong>Options/Email</strong> field can be used for special functions like <a href="#sage">sage</a>.</p>
  </div>

  <!-- Formatting -->
  <div class="faq-section" id="formatting">
    <h3><a href="#formatting">How does text formatting work?</a></h3>
    <p>ashchan supports several text formatting options:</p>
    <ul>
      <li><span class="quote">&gt;quoted text</span> — Lines beginning with <code>&gt;</code> are displayed as green &ldquo;greentext&rdquo; quotes</li>
      <li><strong>&gt;&gt;123</strong> — Quote links to other posts in the thread by their post number</li>
      <li><strong>&gt;&gt;&gt;/g/</strong> — Cross-board links to other boards</li>
      <li><strong>**bold**</strong> — Bold text</li>
      <li>URLs are automatically made clickable</li>
    </ul>
  </div>

  <!-- Tripcodes -->
  <div class="faq-section" id="tripcodes">
    <h3><a href="#tripcodes">What are tripcodes?</a></h3>
    <p>A tripcode is a hashed password that creates a unique identifier next to your name. To use a tripcode, enter your name followed by a <code>#</code> and a password in the Name field — for example, <code>User#password</code>. The password will be hashed into a short code displayed after your name, allowing others to verify your identity across posts without needing an account.</p>
    <p>Tripcodes are useful when you need to prove that multiple posts were made by the same person, such as in <span class="quote">&gt;original content</span> threads or follow-up discussions.</p>
  </div>

  <!-- Capcodes -->
  <div class="faq-section" id="capcodes">
    <h3><a href="#capcodes">What are capcodes?</a></h3>
    <p>Capcodes are special identifiers displayed next to staff members&rsquo; names when posting in an official capacity. They indicate a user&rsquo;s role on the site:</p>
    <ul>
      <li><strong style="color:#FF0000;">## Admin</strong> — Site administrator</li>
      <li><strong style="color:#800080;">## Mod</strong> — Board moderator</li>
      <li><strong style="color:#117743;">## Janitor</strong> — Board janitor</li>
    </ul>
    <p>Capcodes cannot be faked. They are applied server-side and are only available to authenticated staff members.</p>
  </div>

  <!-- Deleting -->
  <div class="faq-section" id="deleting">
    <h3><a href="#deleting">Can I delete my own posts?</a></h3>
    <p>Yes. When you create a post, you can set a deletion password in the <strong>Password</strong> field at the bottom of the form. To delete a post later, check the box next to it, enter your password, and click &ldquo;Delete&rdquo;. You can also choose to delete only the file while keeping the post text intact.</p>
    <p>If you did not set a password, you will not be able to delete the post yourself. Staff may remove posts that violate the rules.</p>
  </div>

  <!-- Images -->
  <div class="faq-section" id="images">
    <h3><a href="#images">What image types are supported?</a></h3>
    <p>ashchan supports the following file types for uploads:</p>
    <ul>
      <li>JPEG (.jpg, .jpeg)</li>
      <li>PNG (.png)</li>
      <li>GIF (.gif)</li>
      <li>WebP (.webp)</li>
    </ul>
    <p>Uploaded files are subject to size limits. The maximum file size is set per board, typically between 2 MB and 8 MB. Thumbnails are generated automatically.</p>
  </div>

  <!-- Catalog -->
  <div class="faq-section" id="catalog">
    <h3><a href="#catalog">What is the catalog?</a></h3>
    <p>The catalog is an overview of all active threads on a board, displayed as a grid of thumbnails with short previews. You can access a board&rsquo;s catalog by clicking the &ldquo;Catalog&rdquo; link next to the board name, or by navigating to <code>/&lt;board&gt;/catalog</code>.</p>
    <p>The catalog is useful for quickly scanning all current threads to see if a topic has already been discussed before creating a new thread.</p>
  </div>

  <!-- Sage -->
  <div class="faq-section" id="sage">
    <h3><a href="#sage">What does &ldquo;sage&rdquo; do?</a></h3>
    <p>Entering <code>sage</code> (下げ, meaning &ldquo;to lower&rdquo;) in the Options/Email field allows you to reply to a thread without bumping it to the top of the board. This is useful when you want to contribute to a discussion without drawing extra attention to the thread.</p>
    <p>Sage does not hide your post or make it invisible — it simply prevents the thread from being bumped.</p>
  </div>

  <!-- Bump Limit -->
  <div class="faq-section" id="bumplimit">
    <h3><a href="#bumplimit">What is a bump limit?</a></h3>
    <p>Each board has a bump limit (typically 300 replies). Once a thread has reached its bump limit, new replies will no longer bump the thread to the top of the board. The thread remains open for replies but will gradually fall off the board as newer threads push it away.</p>
    <p>Similarly, each board has a maximum number of active threads. When a new thread is created and the board is at capacity, the oldest thread is pruned (deleted).</p>
  </div>

  <!-- Rules -->
  <div class="faq-section" id="rules">
    <h3><a href="#rules">What are the rules?</a></h3>
    <p>ashchan has site-wide rules that apply to all boards:</p>
    <ul>
      <li>You will not upload, post, discuss, request, or link to anything that violates local or United States law.</li>
      <li>You will not post or request personal information (&ldquo;dox&rdquo;) or calls to invasion (&ldquo;raids&rdquo;).</li>
      <li>No spamming or flooding of any kind. No intentionally evading spam/post filters.</li>
      <li>No malicious content — viruses, trojans, worms, etc.</li>
      <li>Advertising (of any form) is not welcome. This includes any type of referral linking or profit-related posting.</li>
      <li>Do not try to circumvent bans.</li>
    </ul>
    <p>Individual boards may have their own additional rules listed at the top of the board. Violating rules may result in a warning, post deletion, or a temporary or permanent ban.</p>
  </div>

  <!-- Staff -->
  <div class="faq-section" id="staff">
    <h3><a href="#staff">Who runs ashchan?</a></h3>
    <p>ashchan is maintained by a small team of volunteers:</p>
    <ul>
      <li><strong>Administrators</strong> manage the site infrastructure, make policy decisions, and oversee all staff.</li>
      <li><strong>Moderators</strong> enforce the rules across multiple boards. They can delete posts, issue bans, and manage threads.</li>
      <li><strong>Janitors</strong> are volunteer helpers who can report rule-breaking posts and request deletions. They have limited moderation powers on specific boards assigned to them.</li>
    </ul>
    <p>If you are interested in helping moderate ashchan, applications may be announced periodically.</p>
  </div>

  <!-- Software -->
  <div class="faq-section" id="software">
    <h3><a href="#software">What software does ashchan run on?</a></h3>
    <p>ashchan is built as a modern microservices application using PHP (Hyperf framework) with PostgreSQL and Redis. The architecture includes separate services for authentication, board management, media handling, moderation, and search indexing — communicating over mTLS-secured internal connections.</p>
    <p>The frontend design is inspired by the classic Futaba-style imageboard layout, with multiple stylesheet options including Yotsuba, Yotsuba B, Futaba, Burichan, Photon, and Tomorrow.</p>
  </div>

</div>

<!-- Footer -->
<div class="home-footer">
  <a href="/about">About</a> &bull;
  <a href="/rules">Rules</a> &bull;
  <a href="/feedback">Feedback</a> &bull;
  <a href="/legal">Legal</a> &bull;
  <a href="/contact">Contact</a>
  <br>
  <small>All trademarks and copyrights on this page are owned by their respective parties.</small>
</div>

</body>
</html>
