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
  <title>ashchan - Cookie Policy</title>
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

    .cookie-content { max-width: 780px; margin: 0 auto; padding: 20px 20px 40px; }
    .cookie-content h2 { color: #800000; font-size: 16px; border-bottom: 1px solid #D9BFB7; padding-bottom: 4px; margin: 24px 0 10px; }
    .cookie-content h3 { color: #800000; font-size: 13px; margin: 18px 0 6px; }
    .cookie-content p { margin: 6px 0; line-height: 1.6; }
    .cookie-content ul { margin: 6px 0 6px 20px; padding: 0; line-height: 1.7; }
    .cookie-content li { margin-bottom: 4px; }

    .policy-meta { background: #D6DAF0; border: 1px solid #B7C5D9; padding: 12px 16px; margin-bottom: 20px; font-size: 10pt; line-height: 1.5; }
    .policy-meta p { margin: 3px 0; }

    .cookie-table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; font-size: 9pt; }
    .cookie-table th { background: #800000; color: #FFF; padding: 6px 8px; text-align: left; font-weight: bold; font-size: 9pt; }
    .cookie-table td { padding: 5px 8px; border-bottom: 1px solid #D9BFB7; vertical-align: top; }
    .cookie-table tr:nth-child(even) td { background: #F5F5E0; }
    .cookie-table code { background: #EEE; padding: 1px 4px; font-size: 9pt; }

    .highlight-box { background: #F0FFF0; border: 1px solid #B7D9B7; padding: 10px 14px; margin: 12px 0; font-size: 10pt; line-height: 1.6; }
    .highlight-box strong { color: #228B22; }

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

<div class="cookie-content">

  <div class="policy-meta">
    <p><strong>Cookie Policy</strong></p>
    <p>Policy Version: 1.0.0 &bull; Last Updated: <?= date('F j, Y') ?> &bull; <a href="/legal">&laquo; Back to Legal</a></p>
  </div>

  <div class="highlight-box">
    <strong>Short version:</strong> ashchan does not use tracking cookies, analytics, or advertising cookies. We only use essential cookies and localStorage for site functionality (like remembering your preferred stylesheet). No third-party cookies are set.
  </div>

  <h2>What Are Cookies?</h2>
  <p>Cookies are small text files stored on your device by your web browser. They are used to remember preferences and maintain session state. <strong>localStorage</strong> is a similar browser feature that stores data locally on your device.</p>

  <h2>Cookies &amp; Local Storage We Use</h2>
  <p>Here is a <strong>complete and exhaustive list</strong> of every cookie and localStorage item used by ashchan:</p>

  <h3>Cookies</h3>
  <table class="cookie-table">
    <thead>
      <tr><th>Name</th><th>Purpose</th><th>Type</th><th>Duration</th><th>Set By</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><code>staff_session</code></td>
        <td>Staff authentication session (only set for logged-in staff members)</td>
        <td>Essential</td>
        <td>Session / 7 days</td>
        <td>ashchan</td>
      </tr>
      <tr>
        <td><code>consent_version</code></td>
        <td>Records the version of the privacy policy you&rsquo;ve acknowledged</td>
        <td>Essential</td>
        <td>1 year</td>
        <td>ashchan</td>
      </tr>
    </tbody>
  </table>

  <h3>localStorage Items</h3>
  <table class="cookie-table">
    <thead>
      <tr><th>Key</th><th>Purpose</th><th>Type</th><th>Duration</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><code>selectedStyle</code></td>
        <td>Remembers your preferred stylesheet (Yotsuba, Yotsuba B, Futaba, etc.)</td>
        <td>Preference</td>
        <td>Until you clear browser data</td>
      </tr>
      <tr>
        <td><code>postPassword</code></td>
        <td>Remembers your default post deletion password</td>
        <td>Preference</td>
        <td>Until you clear browser data</td>
      </tr>
      <tr>
        <td><code>expandedImages</code></td>
        <td>Tracks which images you&rsquo;ve expanded in the current session</td>
        <td>Functional</td>
        <td>Session</td>
      </tr>
      <tr>
        <td><code>hiddenThreads</code></td>
        <td>Tracks threads you&rsquo;ve hidden from your view</td>
        <td>Preference</td>
        <td>Until you clear browser data</td>
      </tr>
    </tbody>
  </table>

  <h2>What We Do NOT Use</h2>
  <ul>
    <li><strong>No tracking cookies</strong> — we do not track your browsing behavior.</li>
    <li><strong>No analytics cookies</strong> — no Google Analytics, Matomo, or any other analytics.</li>
    <li><strong>No advertising cookies</strong> — we do not serve ads or use ad networks.</li>
    <li><strong>No third-party cookies</strong> — no external services set cookies through our site.</li>
    <li><strong>No social media tracking</strong> — no Facebook, Twitter, or other social media widgets.</li>
    <li><strong>No fingerprinting</strong> — we do not use canvas fingerprinting, WebGL fingerprinting, or any other browser fingerprinting technique.</li>
  </ul>

  <h2>Managing Cookies</h2>
  <p>Since we only use essential cookies, there is nothing to opt out of. However, you have full control:</p>
  <ul>
    <li><strong>Browser settings:</strong> You can configure your browser to block or delete cookies at any time. This may affect staff login functionality but will not affect normal browsing or posting.</li>
    <li><strong>localStorage:</strong> You can clear localStorage through your browser&rsquo;s developer tools (F12 &rarr; Application &rarr; Local Storage) or by clearing all site data in your browser settings. This will reset your style preference.</li>
    <li><strong>Private/Incognito mode:</strong> Using private browsing will prevent any persistent storage. Cookies and localStorage will be cleared when you close the window.</li>
  </ul>

  <h2>Changes to This Policy</h2>
  <p>If we ever add new cookies or local storage items, we will update this page and the policy version number. We are committed to keeping our cookie usage minimal and transparent.</p>

  <p style="margin-top: 20px; font-size: 9pt; color: #888;"><em>Questions about cookies? <a href="/legal/contact">Contact us</a>.</em></p>

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
