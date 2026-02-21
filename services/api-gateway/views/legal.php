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
  <title>ashchan - Legal</title>
  <link rel="shortcut icon" href="/static/img/favicon.ico">
  <link rel="stylesheet" href="/static/css/common.css">
  <style>
    body { background: #FFFFEE; font-family: arial, helvetica, sans-serif; font-size: 10pt; margin: 0; padding: 0; }
    a { color: #00E; text-decoration: none; }
    a:hover { color: #DD0000; }
    .top-nav { background: #FEDCBA; padding: 3px 5px; font-size: 9pt; border-bottom: 1px solid #D9BFB7; text-align: center; }
    .top-nav a { margin: 0 2px; }
    .header { background: #800000; text-align: center; padding: 15px 0 12px; }
    .header h1 { color: #FFF; font-family: 'Tahoma', sans-serif; font-size: 28px; margin: 0; letter-spacing: -1px; }
    .header h1 a { color: #FFF; text-decoration: none; }
    .header h1 a:hover { color: #FED; }
    .sub-nav { background: #800000; text-align: center; padding: 0 0 10px; font-size: 10pt; }
    .sub-nav a { color: #FEC; margin: 0 6px; text-decoration: none; }
    .sub-nav a:hover { color: #FFF; text-decoration: underline; }
    .legal-content { max-width: 750px; margin: 0 auto; padding: 20px 20px 40px; }
    .legal-hub { margin: 20px 0; }
    .legal-card { display: block; background: #D6DAF0; border: 1px solid #B7C5D9; padding: 16px 20px; margin-bottom: 12px; text-decoration: none; color: #000; transition: background 0.15s; }
    .legal-card:hover { background: #C6CAE0; color: #000; }
    .legal-card h3 { margin: 0 0 4px; font-size: 14px; color: #34345C; }
    .legal-card p { margin: 0; font-size: 10pt; color: #555; line-height: 1.5; }
    .legal-card .arrow { float: right; font-size: 18px; color: #800000; margin-top: 8px; }
    .legal-intro { background: #D6DAF0; border: 1px solid #B7C5D9; padding: 14px 18px; margin-bottom: 24px; line-height: 1.6; }
    .legal-intro p { margin: 6px 0; }
    .policy-version { font-size: 9pt; color: #888; margin: 8px 0 20px; }
    .home-footer { background: #FEDCBA; padding: 6px 5px; font-size: 9pt; text-align: center; border-top: 1px solid #D9BFB7; margin-top: 30px; }
    .home-footer a { color: #800000; }
  </style>
  <script src="/static/js/core.js" defer></script>
</head>
<body>

<div class="top-nav">
  [<?php foreach (($boards ?? []) as $i => $b): ?><a href="/<?= htmlspecialchars((string) $b['slug']) ?>/" title="<?= htmlspecialchars((string) $b['title']) ?>"><?= htmlspecialchars((string) $b['slug']) ?></a><?php if ($i < count($boards ?? []) - 1): ?> / <?php endif; ?><?php endforeach; ?>]
</div>

<div class="header">
  <h1><a href="/">ashchan</a></h1>
</div>
<div class="sub-nav">
  <a href="/">Home</a>
  <a href="/about">About</a>
  <a href="/rules">Rules</a>
  <a href="/feedback">Feedback</a>
  <a href="/legal">Legal</a>
  <a href="/legal/contact">Contact</a>
</div>

<div class="legal-content">
  <div class="legal-intro">
    <p><strong>ashchan is committed to transparency.</strong> We believe you have a right to understand exactly what data we collect, how we protect it, and what controls you have over it. These pages lay it all out — no legalese buried in footnotes, no dark patterns.</p>
    <p>Policy Version: 1.0.0 &bull; Last Updated: <?= date('F j, Y') ?></p>
  </div>

  <div class="legal-hub">
    <a href="/legal/privacy" class="legal-card">
      <span class="arrow">&rsaquo;</span>
      <h3>Privacy Policy</h3>
      <p>Exactly what data we collect, how it&rsquo;s encrypted, how long we keep it, and who can access it. Full data inventory included — nothing hidden.</p>
    </a>

    <a href="/legal/terms" class="legal-card">
      <span class="arrow">&rsaquo;</span>
      <h3>Terms of Service</h3>
      <p>The agreement governing your use of ashchan. Covers user conduct, content policies, liability, and third-party data sharing disclosures.</p>
    </a>

    <a href="/legal/cookies" class="legal-card">
      <span class="arrow">&rsaquo;</span>
      <h3>Cookie Policy</h3>
      <p>What cookies and local storage we use (spoiler: only essential ones), what they do, and how to manage them.</p>
    </a>

    <a href="/legal/rights" class="legal-card">
      <span class="arrow">&rsaquo;</span>
      <h3>Your Privacy Rights (GDPR/CCPA)</h3>
      <p>Exercise your data rights here — access, deletion, portability, objection. One place to manage everything, with clear timelines and no runaround.</p>
    </a>

    <a href="/legal/contact" class="legal-card">
      <span class="arrow">&rsaquo;</span>
      <h3>Contact</h3>
      <p>How to reach the site administrators for legal inquiries, takedown requests, law enforcement, and general questions.</p>
    </a>
  </div>
</div>

<div class="home-footer">
  <a href="/about">About</a> &bull;
  <a href="/rules">Rules</a> &bull;
  <a href="/feedback">Feedback</a> &bull;
  <a href="/legal">Legal</a> &bull;
  <a href="/legal/contact">Contact</a>
  <br>
  <small>All trademarks and copyrights on this page are owned by their respective parties.</small>
</div>

</body>
</html>
