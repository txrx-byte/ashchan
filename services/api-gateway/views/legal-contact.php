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
  <title>ashchan - Contact</title>
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

    .contact-content { max-width: 700px; margin: 0 auto; padding: 20px 20px 40px; }
    .contact-content h2 { color: #800000; font-size: 16px; border-bottom: 1px solid #D9BFB7; padding-bottom: 4px; margin: 24px 0 10px; }
    .contact-content p { margin: 6px 0; line-height: 1.6; }
    .contact-content ul { margin: 6px 0 6px 20px; padding: 0; line-height: 1.7; }
    .contact-content li { margin-bottom: 4px; }

    .contact-intro { background: #D6DAF0; border: 1px solid #B7C5D9; padding: 14px 18px; margin-bottom: 20px; line-height: 1.6; }
    .contact-intro p { margin: 6px 0; }

    .contact-card { background: #F5F5E0; border: 1px solid #D9BFB7; padding: 14px 18px; margin-bottom: 14px; }
    .contact-card h3 { color: #800000; font-size: 13px; margin: 0 0 6px; }
    .contact-card p { margin: 4px 0; line-height: 1.5; }
    .contact-card .method { font-weight: bold; color: #34345C; }

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

<div class="contact-content">

  <div class="contact-intro">
    <p><strong>Need to reach us?</strong> Choose the most appropriate channel below. Using the right contact method helps us respond faster.</p>
    <p><a href="/legal">&laquo; Back to Legal</a></p>
  </div>

  <div class="contact-card">
    <h3>General Feedback &amp; Suggestions</h3>
    <p>For feature requests, bug reports, suggestions, and general site feedback:</p>
    <p class="method"><a href="/feedback">Use the Feedback Form &rsaquo;</a></p>
    <p style="font-size: 9pt; color: #888;">This is the fastest way to reach us for non-urgent matters.</p>
  </div>

  <div class="contact-card">
    <h3>Privacy &amp; Data Rights Requests</h3>
    <p>To exercise your GDPR/CCPA rights (data access, deletion, portability, objection):</p>
    <p class="method"><a href="/legal/rights">Visit the Privacy Rights Center &rsaquo;</a></p>
    <p style="font-size: 9pt; color: #888;">We respond to all data rights requests within 30 days (GDPR) or 45 days (CCPA).</p>
  </div>

  <div class="contact-card">
    <h3>DMCA &amp; Copyright Takedown Requests</h3>
    <p>If you believe your copyrighted work has been posted without authorization, submit a DMCA takedown notice.</p>
    <p class="method">Email: <strong>dmca@ashchan.example.com</strong></p>
    <p style="font-size: 9pt; color: #888;">Please include all information required by the DMCA (see <a href="/legal/terms#dmca">Terms of Service &sect;9</a>).</p>
  </div>

  <div class="contact-card">
    <h3>Legal Inquiries &amp; Law Enforcement</h3>
    <p>For legal process, subpoenas, court orders, and law enforcement requests:</p>
    <p class="method">Email: <strong>legal@ashchan.example.com</strong></p>
    <p style="font-size: 9pt; color: #888;">Please include valid legal documentation. We respond to valid legal process in accordance with applicable law.</p>
  </div>

  <div class="contact-card">
    <h3>Security Vulnerabilities</h3>
    <p>If you&rsquo;ve discovered a security vulnerability, please report it responsibly:</p>
    <p class="method">Email: <strong>security@ashchan.example.com</strong></p>
    <p style="font-size: 9pt; color: #888;">Please do not publicly disclose vulnerabilities before we&rsquo;ve had a chance to address them. We appreciate responsible disclosure.</p>
  </div>

  <div class="contact-card">
    <h3>Abuse Reports</h3>
    <p>To report illegal content, CSAM, or other urgent abuse:</p>
    <p class="method">Email: <strong>abuse@ashchan.example.com</strong></p>
    <p style="font-size: 9pt; color: #888;">For reporting rule violations on specific posts, please use the report button on the post itself.</p>
  </div>

  <h2>Response Times</h2>
  <ul>
    <li><strong>General feedback:</strong> Within 7 business days</li>
    <li><strong>Privacy/data rights requests:</strong> Within 30 days (GDPR) / 45 days (CCPA)</li>
    <li><strong>DMCA takedowns:</strong> Within 48 hours for valid notices</li>
    <li><strong>Security vulnerabilities:</strong> Acknowledgment within 24 hours</li>
    <li><strong>Abuse reports (illegal content):</strong> As soon as possible, typically within hours</li>
    <li><strong>Legal process:</strong> Per applicable legal requirements</li>
  </ul>

  <p style="margin-top: 20px; font-size: 9pt; color: #888;"><em>Note: Email addresses listed above are placeholders. Replace with actual contact addresses before deployment.</em></p>

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
