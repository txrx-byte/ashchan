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
  <title>ashchan - Rules</title>
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

    .rules-content {
      max-width: 750px;
      margin: 0 auto;
      padding: 20px 20px 40px;
    }

    .rules-preamble {
      background: #D6DAF0;
      border: 1px solid #B7C5D9;
      padding: 12px 16px;
      margin-bottom: 24px;
      line-height: 1.6;
      font-size: 10pt;
    }
    .rules-preamble p { margin: 6px 0; }

    .rules-section {
      margin-bottom: 28px;
    }

    .rules-section h2 {
      color: #800000;
      font-size: 16px;
      border-bottom: 1px solid #D9BFB7;
      padding-bottom: 4px;
      margin: 20px 0 10px;
    }

    .rules-section h3 {
      color: #800000;
      font-size: 13px;
      margin: 15px 0 5px;
    }

    .rules-section p {
      margin: 6px 0;
      line-height: 1.6;
    }

    .rules-section ol, .rules-section ul {
      margin: 6px 0 6px 20px;
      padding: 0;
      line-height: 1.7;
    }

    .rules-section ol li, .rules-section ul li {
      margin-bottom: 6px;
    }

    .rules-section ol li strong {
      color: #800000;
    }

    .rule-number {
      background: #800000;
      color: #FFF;
      font-size: 9px;
      font-weight: bold;
      padding: 1px 5px;
      margin-right: 4px;
      border-radius: 2px;
    }

    .rules-toc {
      background: #D6DAF0;
      border: 1px solid #B7C5D9;
      padding: 10px 15px;
      margin-bottom: 20px;
    }
    .rules-toc h3 {
      margin: 0 0 6px;
      font-size: 12px;
      color: #34345C;
    }
    .rules-toc ul {
      margin: 0;
      padding: 0 0 0 18px;
      font-size: 10pt;
      line-height: 1.6;
    }
    .rules-toc a { color: #34345C; }
    .rules-toc a:hover { color: #DD0000; }

    .severity-note {
      background: #FFF3CD;
      border: 1px solid #FFECB5;
      padding: 8px 12px;
      margin: 12px 0;
      font-size: 9pt;
      line-height: 1.5;
    }
    .severity-note strong { color: #856404; }

    .tldr {
      background: #F0FFF0;
      border: 1px solid #B7D9B7;
      padding: 10px 14px;
      margin: 12px 0;
      font-size: 10pt;
      line-height: 1.6;
    }
    .tldr strong { color: #228B22; }

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
<div class="rules-content">

  <!-- Preamble -->
  <div class="rules-preamble">
    <p><strong>ashchan is committed to providing an open platform for anonymous discussion.</strong> With that freedom comes responsibility. These rules exist to keep the boards usable, the discussion genuine, and the community alive. They are inspired by long-standing community standards — adapted for imageboard culture, where anonymity is a feature, not a bug.</p>
    <p>By posting on ashchan, you agree to follow these rules. Ignorance of the rules does not excuse violations.</p>
  </div>

  <!-- Table of Contents -->
  <div class="rules-toc">
    <h3>Table of Contents</h3>
    <ul>
      <li><a href="#tldr">TL;DR &mdash; The Short Version</a></li>
      <li><a href="#global">Global Rules</a></li>
      <li><a href="#conduct">Community Standards</a></li>
      <li><a href="#posting">Posting Etiquette</a></li>
      <li><a href="#content">Content Guidelines</a></li>
      <li><a href="#moderation">Moderation &amp; Enforcement</a></li>
      <li><a href="#board-specific">Board-Specific Rules</a></li>
      <li><a href="#pledge">Our Pledge</a></li>
    </ul>
  </div>

  <!-- TL;DR -->
  <div class="rules-section" id="tldr">
    <h2>TL;DR &mdash; The Short Version</h2>
    <div class="tldr">
      <strong>Be a human.</strong> Don&rsquo;t post illegal content. Don&rsquo;t spam. Don&rsquo;t dox people. Don&rsquo;t be a bot (unless you&rsquo;re a really good one). Contribute something — even if that something is a quality shitpost. Lurk before you post. Respect the culture of each board. If you get banned, you probably deserved it.
    </div>
  </div>

  <!-- Global Rules -->
  <div class="rules-section" id="global">
    <h2>Global Rules</h2>
    <p>These rules apply to <strong>all boards</strong> without exception. Violations may result in immediate and permanent bans.</p>
    <ol>
      <li><strong>No illegal content.</strong> You will not upload, post, discuss, request, or link to anything that violates United States law or the laws of your jurisdiction. This includes but is not limited to child sexual abuse material (CSAM), credible threats of violence, and content that facilitates terrorism.</li>
      <li><strong>No doxxing.</strong> You will not post or solicit personal information about any individual — including but not limited to real names, addresses, phone numbers, email addresses, social media accounts, workplaces, or photographs taken without consent — with the intent to harass, intimidate, or enable others to do so.</li>
      <li><strong>No raids or brigading.</strong> You will not organize, coordinate, or encourage invasions, harassment campaigns, or targeted attacks against other users, communities, or websites.</li>
      <li><strong>No spam or flooding.</strong> You will not spam, flood, or otherwise disrupt the normal flow of discussion. This includes commercial spam, copypasta floods, duplicate threads, and automated posting. Do not intentionally evade spam or post filters.</li>
      <li><strong>No malicious content.</strong> You will not post or link to viruses, trojans, malware, phishing pages, cryptominers, IP loggers, or any other content designed to harm users or their devices.</li>
      <li><strong>No advertising.</strong> Unsolicited advertising, referral links, affiliate marketing, cryptocurrency shilling, and any form of commercial promotion are not permitted.</li>
      <li><strong>No ban evasion.</strong> If you are banned, do not attempt to circumvent the ban through VPNs, proxies, new devices, or any other means. Ban evasion will result in extended bans.</li>
      <li><strong>No impersonation of staff.</strong> Do not falsely claim to be an administrator, moderator, or janitor. Do not forge capcodes or imitate staff tripcodes.</li>
    </ol>
  </div>

  <!-- Community Standards -->
  <div class="rules-section" id="conduct">
    <h2>Community Standards</h2>
    <p>ashchan is an anonymous community. Anonymity means your ideas stand on their own — it does not mean &ldquo;anything goes.&rdquo; We expect participants to engage in good faith, even when disagreeing vigorously.</p>

    <h3>Do</h3>
    <ul>
      <li><strong>Lurk before you post.</strong> Every board has its own culture, humor, and norms. Spend time reading before contributing. This is imageboard tradition for a reason.</li>
      <li><strong>Contribute meaningfully.</strong> Start interesting threads. Post thoughtful replies. Share original content. Even banter and shitposting can be done well — low effort is not the same as no effort.</li>
      <li><strong>Stay on topic.</strong> Respect what each board is for. <code>/a/</code> is for anime, <code>/g/</code> is for technology, and so on. Off-topic threads will be moved or deleted.</li>
      <li><strong>Engage with ideas, not identities.</strong> Anonymous boards work best when you respond to what someone says, not who you think they are. No one cares about your post count, reputation, or followers here — because those things don&rsquo;t exist.</li>
      <li><strong>Accept disagreement.</strong> People will disagree with you. Sometimes loudly. Sometimes with creative profanity. That&rsquo;s fine. Respond with better arguments, not reports.</li>
      <li><strong>Use sage appropriately.</strong> If your reply doesn&rsquo;t contribute enough to warrant bumping a thread, sage it. It&rsquo;s polite.</li>
      <li><strong>Report rule-breaking content.</strong> Use the report system for genuine violations. It helps the moderation team keep the boards clean.</li>
    </ul>

    <h3>Don&rsquo;t</h3>
    <ul>
      <li><strong>Don&rsquo;t derail threads.</strong> Keep discussions on track. If a conversation naturally evolves, that&rsquo;s fine. Intentionally steering a thread off-topic or into flamewars is not.</li>
      <li><strong>Don&rsquo;t abuse the report system.</strong> Reports are for rule violations, not for opinions you disagree with. Frivolous reports waste moderator time and may result in action against the reporter.</li>
      <li><strong>Don&rsquo;t be a troll for its own sake.</strong> There&rsquo;s a difference between clever bait and just being obnoxious. If your only goal is to make people angry without any humor or substance, find a different hobby.</li>
      <li><strong>Don&rsquo;t post low-quality bait.</strong> &ldquo;[X] btfo&rdquo; threads, rage-bait copypasta, and manufactured outrage add nothing. If you&rsquo;re going to bait, at least make it interesting.</li>
      <li><strong>Don&rsquo;t backseat moderate.</strong> If someone is breaking the rules, report them. Don&rsquo;t reply telling them what the rules are &mdash; that just bumps the thread and derails discussion further.</li>
    </ul>
  </div>

  <!-- Posting Etiquette -->
  <div class="rules-section" id="posting">
    <h2>Posting Etiquette</h2>
    <p>Good etiquette makes the boards better for everyone. These are strong recommendations — while not all are enforced with bans, consistently ignoring them will earn you one.</p>
    <ul>
      <li><strong>Check the catalog before posting a new thread.</strong> Your topic probably already has an active thread. Duplicate threads fragment discussion and will be merged or deleted.</li>
      <li><strong>Write descriptive thread subjects.</strong> &ldquo;Hey guys&rdquo; and &ldquo;ITT&rdquo; tell nobody anything. A good subject line helps people find your thread and decides whether it gets replies or ignored.</li>
      <li><strong>Quote properly.</strong> Use <code>&gt;&gt;</code> to link to posts you&rsquo;re replying to. Use <code>&gt;</code> for greentext quoting. This makes conversations readable.</li>
      <li><strong>Don&rsquo;t reply to obvious bait.</strong> Bait threads die when starved of (You)s. Replying just to say &ldquo;bait&rdquo; still bumps the thread. Report and move on.</li>
      <li><strong>Spoiler tag appropriately.</strong> When discussing plot points, endings, or surprises — spoiler tag them. Not everyone has seen/read/played it.</li>
      <li><strong>Keep images relevant.</strong> The opening post of a thread should have an image that relates to the topic. Reaction images in replies are fine in moderation.</li>
      <li><strong>Respect file size and format limits.</strong> Supported formats: JPEG, PNG, GIF, WebP (max 4MB). Images larger than 250&times;250 pixels will be thumbnailed.</li>
      <li><strong>Don&rsquo;t necrobump ancient threads</strong> unless you have something genuinely new to add. Let dead threads rest.</li>
    </ul>
  </div>

  <!-- Content Guidelines -->
  <div class="rules-section" id="content">
    <h2>Content Guidelines</h2>
    <p>ashchan supports free expression within legal bounds. The following content standards ensure the platform remains accessible and usable.</p>
    <ul>
      <li><strong>NSFW content</strong> is only permitted on boards explicitly marked as NSFW. Posting explicit content on SFW boards will result in deletion and a ban.</li>
      <li><strong>Gore, shock content, and extreme violence</strong> are restricted to boards where such content is explicitly permitted, if any. Gratuitous shock images posted to derail or distress are prohibited everywhere.</li>
      <li><strong>Copyrighted material:</strong> Do not use ashchan to distribute pirated software, media, or other copyrighted content. Brief excerpts and transformative use (memes, edits, commentary) are generally acceptable under fair use principles.</li>
      <li><strong>AI-generated content</strong> should be labeled as such when posted, especially AI-generated images. Undisclosed AI art passed off as original work is dishonest and may be removed.</li>
      <li><strong>Threatening language:</strong> Vague expressions of frustration are part of imageboard culture. Specific, credible threats against identifiable individuals or groups are taken seriously and may be reported to law enforcement.</li>
    </ul>
  </div>

  <!-- Moderation -->
  <div class="rules-section" id="moderation">
    <h2>Moderation &amp; Enforcement</h2>
    <p>Moderation on ashchan is carried out by volunteers who donate their time to keep the boards functional.</p>

    <h3>How Enforcement Works</h3>
    <ul>
      <li><strong>Warnings:</strong> For minor or first-time violations, you may receive a public warning attached to your post.</li>
      <li><strong>Post deletion:</strong> Rule-breaking posts may be deleted without notice.</li>
      <li><strong>Temporary bans:</strong> Ranging from hours to weeks, depending on severity. You&rsquo;ll see a ban message explaining the reason and duration.</li>
      <li><strong>Permanent bans:</strong> Reserved for severe violations, repeated offenders, and those who demonstrate an unwillingness to follow the rules.</li>
      <li><strong>Range bans:</strong> In cases of persistent abuse from specific IP ranges, broader bans may be applied. We try to minimize collateral damage.</li>
    </ul>

    <h3>Staff Standards</h3>
    <p>We hold our moderation team to high standards. Moderators and janitors pledge to:</p>
    <ul>
      <li>Enforce the rules consistently, fairly, and without personal bias.</li>
      <li>Not use moderation tools for personal vendettas or to silence opinions they disagree with.</li>
      <li>Respect user privacy and handle personal data in accordance with our <a href="/legal/privacy">Privacy Policy</a>.</li>
      <li>Be transparent about moderation actions where appropriate (public bans include reasons).</li>
      <li>Recuse themselves from moderation actions involving personal disputes.</li>
      <li>Accept community feedback on moderation practices with an open mind.</li>
    </ul>

    <h3>Appeals</h3>
    <p>If you believe you were banned in error, you may appeal via the ban page shown when you attempt to post. Provide a clear, reasonable explanation. Appeals are reviewed by a different staff member than the one who issued the ban. Abusive or frivolous appeals will be ignored.</p>

    <div class="severity-note">
      <strong>Note:</strong> All moderation decisions are made at the discretion of ashchan staff. We are not required to justify every decision publicly, but we strive for consistency and fairness. If you think moderation is consistently unfair, use the <a href="/feedback">Feedback</a> form to let us know.
    </div>
  </div>

  <!-- Board-specific rules -->
  <div class="rules-section" id="board-specific">
    <h2>Board-Specific Rules</h2>
    <p>Individual boards may have additional rules beyond these global ones. Board-specific rules are displayed at the top of each board and in the posting form. These rules are set by the administrators and enforced by the moderation team assigned to that board.</p>
    <p>Examples of board-specific rules may include:</p>
    <ul>
      <li>Spoiler requirements for ongoing series or recent releases</li>
      <li>Restrictions on certain recurring thread types</li>
      <li>Quality standards for opening posts (e.g., minimum image resolution)</li>
      <li>Topic restrictions to keep boards focused</li>
    </ul>
    <p>When a board-specific rule conflicts with these global rules, the <strong>stricter</strong> rule applies.</p>
  </div>

  <!-- Our Pledge -->
  <div class="rules-section" id="pledge">
    <h2>Our Pledge</h2>
    <p>The ashchan community — users and staff alike — is committed to maintaining a space where anyone can participate in discussion regardless of background, experience level, or identity. We value:</p>
    <ul>
      <li><strong>Openness:</strong> Anyone can post, anyone can lurk, anyone can contribute. The barrier to entry is zero and we intend to keep it that way.</li>
      <li><strong>Anonymity:</strong> Your posts are judged on their content, not your identity. We protect user privacy by design — IP addresses are encrypted and auto-deleted after 30 days.</li>
      <li><strong>Quality over quantity:</strong> We would rather have one good thread than fifty garbage ones. Good-faith effort to contribute makes the community better.</li>
      <li><strong>Honest moderation:</strong> We moderate to keep the boards functional and legal, not to curate a safe space or enforce ideological conformity. Disagreement is welcome. Disruption is not.</li>
      <li><strong>Accountability:</strong> Staff are held to the same rules as users, plus additional standards. Abuse of moderation tools is not tolerated.</li>
    </ul>
    <p>This is your community as much as ours. Take care of it.</p>
    <p style="margin-top: 16px; font-size: 9pt; color: #888;"><em>These rules are adapted from community standards including <a href="https://www.reddithelp.com/hc/en-us/articles/205926439" target="_blank" rel="noopener">Reddiquette</a> and the <a href="https://www.contributor-covenant.org/" target="_blank" rel="noopener">Contributor Covenant</a>, rewritten for imageboard culture. Last updated: <?= date('F Y') ?>.</em></p>
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
