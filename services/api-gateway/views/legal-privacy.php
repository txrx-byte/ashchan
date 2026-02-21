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
  <title>ashchan - Privacy Policy</title>
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

    .privacy-content { max-width: 780px; margin: 0 auto; padding: 20px 20px 40px; }
    .privacy-content h2 { color: #800000; font-size: 16px; border-bottom: 1px solid #D9BFB7; padding-bottom: 4px; margin: 24px 0 10px; }
    .privacy-content h3 { color: #800000; font-size: 13px; margin: 18px 0 6px; }
    .privacy-content p { margin: 6px 0; line-height: 1.6; }
    .privacy-content ul, .privacy-content ol { margin: 6px 0 6px 20px; padding: 0; line-height: 1.7; }
    .privacy-content li { margin-bottom: 4px; }

    .policy-meta { background: #D6DAF0; border: 1px solid #B7C5D9; padding: 12px 16px; margin-bottom: 20px; font-size: 10pt; line-height: 1.5; }
    .policy-meta p { margin: 3px 0; }

    .toc { background: #D6DAF0; border: 1px solid #B7C5D9; padding: 10px 15px; margin-bottom: 20px; }
    .toc h3 { margin: 0 0 6px; font-size: 12px; color: #34345C; }
    .toc ul { margin: 0; padding: 0 0 0 18px; font-size: 10pt; line-height: 1.6; }
    .toc a { color: #34345C; }
    .toc a:hover { color: #DD0000; }

    .data-table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; font-size: 9pt; }
    .data-table th { background: #800000; color: #FFF; padding: 6px 8px; text-align: left; font-weight: bold; font-size: 9pt; }
    .data-table td { padding: 5px 8px; border-bottom: 1px solid #D9BFB7; vertical-align: top; }
    .data-table tr:nth-child(even) td { background: #F5F5E0; }
    .data-table .pii-critical { color: #C00; font-weight: bold; }
    .data-table .encrypted { color: #228B22; font-weight: bold; }

    .highlight-box { background: #F0FFF0; border: 1px solid #B7D9B7; padding: 10px 14px; margin: 12px 0; font-size: 10pt; line-height: 1.6; }
    .highlight-box strong { color: #228B22; }
    .warning-box { background: #FFF3CD; border: 1px solid #FFECB5; padding: 10px 14px; margin: 12px 0; font-size: 10pt; line-height: 1.5; }
    .warning-box strong { color: #856404; }

    .home-footer { background: #FEDCBA; padding: 6px 5px; font-size: 9pt; text-align: center; border-top: 1px solid #D9BFB7; margin-top: 30px; }
    .home-footer a { color: #800000; }

    @media (max-width: 600px) {
      .data-table { font-size: 8pt; }
      .data-table th, .data-table td { padding: 4px 5px; }
    }
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

<div class="privacy-content">

  <div class="policy-meta">
    <p><strong>Privacy Policy</strong></p>
    <p>Policy Version: 1.0.0 &bull; Last Updated: <?= date('F j, Y') ?> &bull; <a href="/legal">&laquo; Back to Legal</a></p>
    <p>ashchan is committed to full transparency about your data. This policy tells you <strong>exactly</strong> what we collect, why, how we protect it, and what you can do about it.</p>
  </div>

  <div class="toc">
    <h3>Table of Contents</h3>
    <ul>
      <li><a href="#collect">1. What We Collect</a></li>
      <li><a href="#not-collect">2. What We Do NOT Collect</a></li>
      <li><a href="#encryption">3. How Your Data Is Protected</a></li>
      <li><a href="#inventory">4. Complete Data Inventory</a></li>
      <li><a href="#retention">5. Data Retention &amp; Automatic Deletion</a></li>
      <li><a href="#legal-basis">6. Legal Basis for Processing (GDPR)</a></li>
      <li><a href="#third-party">7. Third-Party Data Sharing</a></li>
      <li><a href="#rights">8. Your Privacy Rights</a></li>
      <li><a href="#breach">9. Data Breach Notification</a></li>
      <li><a href="#children">10. Children&rsquo;s Privacy</a></li>
      <li><a href="#changes">11. Changes to This Policy</a></li>
    </ul>
  </div>

  <!-- 1. What We Collect -->
  <h2 id="collect">1. What We Collect</h2>

  <p>When you use ashchan, we collect the minimum data necessary for the site to function, for moderation, and for legal compliance. Here is every piece of data we collect, with no exceptions:</p>

  <h3>IP Address</h3>
  <ul>
    <li>Collected <strong>only</strong> when you create a post, thread, or report.</li>
    <li>Your IP is <strong>NOT</strong> recorded in HTTP access logs or any server-level logging.</li>
    <li>Encrypted immediately upon capture using XChaCha20-Poly1305 authenticated encryption.</li>
    <li>Can only be decrypted by authorized administrators for moderation or legal compliance.</li>
    <li>Automatically and <strong>irreversibly deleted after 30 days</strong>.</li>
  </ul>

  <h3>Options Field (sage/noko)</h3>
  <ul>
    <li>The post form includes an &ldquo;Options&rdquo; field (historically labelled &ldquo;Email&rdquo;) that supports <code>sage</code> (reply without bumping) and <code>noko</code> (stay on thread after posting) commands.</li>
    <li>This field does <strong>not</strong> collect email addresses. Any value entered is treated as a command string, not as contact information.</li>
    <li>Stored as plaintext. Automatically deleted after 30 days.</li>
    <li>We do <strong>not</strong> collect email addresses from regular users. Only staff accounts have email addresses on file.</li>
  </ul>

  <h3>Author Name &amp; Tripcode (optional)</h3>
  <ul>
    <li>Only collected if you voluntarily enter a name or tripcode.</li>
    <li>Displayed publicly on your post. Tripcodes are one-way hashed.</li>
  </ul>

  <h3>Post Content &amp; Media</h3>
  <ul>
    <li>The text and images you post are stored for the lifetime of the post.</li>
    <li>Posts are ephemeral — older threads are pruned as new ones are created.</li>
  </ul>

  <h3>Browser Metadata</h3>
  <ul>
    <li>User-Agent string may be collected for rate limiting and abuse detection.</li>
    <li>Retained for a maximum of <strong>24 hours</strong>, then permanently deleted.</li>
  </ul>

  <h3>Country Code</h3>
  <ul>
    <li>Derived from your IP address via GeoIP lookup at post creation time.</li>
    <li>May be displayed on posts (board-dependent). Not considered PII on its own.</li>
  </ul>

  <h3>Deletion Password</h3>
  <ul>
    <li>If you set a post deletion password, it is stored as a one-way hash (not reversible).</li>
  </ul>

  <!-- 2. What We Do NOT Collect -->
  <h2 id="not-collect">2. What We Do NOT Collect</h2>

  <div class="highlight-box">
    <strong>We believe in data minimization.</strong> The following is a non-exhaustive list of things we explicitly do <strong>not</strong> collect or use:
  </div>

  <ul>
    <li>We do <strong>not</strong> use tracking cookies, analytics, or advertising pixels.</li>
    <li>We do <strong>not</strong> use Google Analytics, Facebook Pixel, or any third-party tracking.</li>
    <li>We do <strong>not</strong> create user profiles or behavioral models.</li>
    <li>We do <strong>not</strong> sell or share your data for advertising purposes.</li>
    <li>We do <strong>not</strong> fingerprint your browser or device.</li>
    <li>We do <strong>not</strong> log your IP address at the HTTP/server level — only at the application level during specific actions (posting, reporting).</li>
    <li>We do <strong>not</strong> use any form of cross-site tracking.</li>
    <li>We do <strong>not</strong> require registration, accounts, or any form of identification to use the site.</li>
  </ul>

  <!-- 3. Encryption -->
  <h2 id="encryption">3. How Your Data Is Protected</h2>

  <p>All personally identifiable information (PII) is encrypted <strong>before</strong> being stored in our database. This means that even in the event of a complete database breach, your personal data cannot be read without the encryption keys.</p>

  <h3>Encryption Standard</h3>
  <ul>
    <li><strong>Algorithm:</strong> XChaCha20-Poly1305 (IETF AEAD) — a modern authenticated encryption cipher recommended by the security community.</li>
    <li><strong>Key Derivation:</strong> Data Encryption Keys derived from server-side secrets via BLAKE2b.</li>
    <li><strong>Nonce:</strong> Unique 24-byte random nonce per encrypted value, preventing pattern analysis.</li>
    <li><strong>Authentication:</strong> Poly1305 MAC ensures data integrity — any tampering is detected and rejected.</li>
  </ul>

  <h3>IP Address Architecture</h3>
  <ol>
    <li><strong>Capture:</strong> IP addresses are captured only during post creation, report submission, and staff login — never at the HTTP/access-log level.</li>
    <li><strong>Encryption:</strong> Immediately encrypted via <code>PiiEncryptionService</code> before any database write.</li>
    <li><strong>Lookup:</strong> A deterministic SHA-256 hash is stored alongside for efficient abuse-filtering queries. This hash is not reversible.</li>
    <li><strong>Decryption:</strong> Only authorized administrators can decrypt, and every decryption is logged in an immutable audit trail.</li>
    <li><strong>Deletion:</strong> Automated cron job permanently nullifies encrypted IP data after the retention period.</li>
  </ol>

  <h3>Key Rotation</h3>
  <ul>
    <li>Data encryption keys are rotated every 90 days.</li>
    <li>Old keys are retained (read-only) until all data encrypted with them has been deleted per the retention schedule.</li>
  </ul>

  <!-- 4. Data Inventory -->
  <h2 id="inventory">4. Complete Data Inventory</h2>

  <p>In the interest of full transparency, here is <strong>every piece of data</strong> we store, organized by service. This is the same inventory our compliance team uses internally — we are not hiding anything from you.</p>

  <h3>4.1 Posts &amp; Threads</h3>
  <table class="data-table">
    <thead>
      <tr><th>Data</th><th>Classification</th><th>Purpose</th><th>Retention</th><th>Protected</th></tr>
    </thead>
    <tbody>
      <tr><td>IP Address</td><td class="pii-critical">PII-Critical</td><td>Moderation, abuse prevention</td><td>30 days</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>Options field (sage/noko)</td><td>Operational</td><td>Sage/noko post commands</td><td>30 days</td><td>Plaintext (not PII)</td></tr>
      <tr><td>Author Name</td><td>PII-Sensitive</td><td>User-provided attribution</td><td>Post lifetime</td><td>Plaintext (user-provided)</td></tr>
      <tr><td>Tripcode</td><td>PII-Sensitive</td><td>Identity verification</td><td>Post lifetime</td><td>One-way hash</td></tr>
      <tr><td>Country Code</td><td>PII-Sensitive</td><td>Geographic context</td><td>Post lifetime</td><td>Plaintext</td></tr>
      <tr><td>Delete Password</td><td>Operational</td><td>User self-deletion</td><td>Post lifetime</td><td>One-way hash</td></tr>
      <tr><td>Post Content</td><td>Operational</td><td>User expression</td><td>Post lifetime</td><td>Plaintext</td></tr>
      <tr><td>Media Files</td><td>Operational</td><td>Media display</td><td>Post lifetime</td><td>Plaintext</td></tr>
    </tbody>
  </table>

  <h3>4.2 Reports &amp; Moderation</h3>
  <table class="data-table">
    <thead>
      <tr><th>Data</th><th>Classification</th><th>Purpose</th><th>Retention</th><th>Protected</th></tr>
    </thead>
    <tbody>
      <tr><td>Reporter IP</td><td class="pii-critical">PII-Critical</td><td>Abuse prevention</td><td>90 days</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>Reported Post IP</td><td class="pii-critical">PII-Critical</td><td>Moderation action</td><td>90 days</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>Ban IP</td><td class="pii-critical">PII-Critical</td><td>Ban enforcement</td><td>Ban duration + 30 days</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>Ban XFF Header</td><td class="pii-critical">PII-Critical</td><td>Proxy detection</td><td>Ban duration + 30 days</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>Admin Action IP</td><td>Staff-Internal</td><td>Staff audit trail</td><td>1 year</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>SFS Pending IP</td><td class="pii-critical">PII-Critical</td><td>Anti-spam reporting</td><td>30 days or until processed</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>Moderation Decisions</td><td>Operational</td><td>Audit trail</td><td>1 year</td><td>Plaintext</td></tr>
    </tbody>
  </table>

  <h3>4.3 Media Uploads</h3>
  <table class="data-table">
    <thead>
      <tr><th>Data</th><th>Classification</th><th>Purpose</th><th>Retention</th><th>Protected</th></tr>
    </thead>
    <tbody>
      <tr><td>Uploader IP</td><td class="pii-critical">PII-Critical</td><td>Abuse tracing</td><td>30 days</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>Media Metadata</td><td>Operational</td><td>Media management</td><td>Media lifetime</td><td>Plaintext</td></tr>
    </tbody>
  </table>

  <h3>4.4 Rate Limiting &amp; Infrastructure</h3>
  <table class="data-table">
    <thead>
      <tr><th>Data</th><th>Classification</th><th>Purpose</th><th>Retention</th><th>Protected</th></tr>
    </thead>
    <tbody>
      <tr><td>Flood Log IP</td><td class="pii-critical">PII-Critical</td><td>Rate limiting, DDoS prevention</td><td>24 hours</td><td class="encrypted">Encrypted (XChaCha20)</td></tr>
      <tr><td>Session Tokens</td><td>Operational</td><td>Staff session management</td><td>7 days (TTL)</td><td>Hashed (SHA-256)</td></tr>
      <tr><td>HTTP Access Logs</td><td>Operational</td><td>Debugging</td><td>7 days</td><td>No IPs stored</td></tr>
    </tbody>
  </table>

  <!-- 5. Retention -->
  <h2 id="retention">5. Data Retention &amp; Automatic Deletion</h2>

  <p>We retain personal data only for as long as necessary. Deletion is <strong>automated</strong> — no manual intervention required, and no human reviews data before deletion.</p>

  <table class="data-table">
    <thead>
      <tr><th>Data Type</th><th>Retention Period</th><th>Deletion Method</th><th>What Happens</th></tr>
    </thead>
    <tbody>
      <tr><td>Post IP addresses</td><td>30 days</td><td>Automated cron (nullify)</td><td>Permanently and irreversibly deleted</td></tr>
      <tr><td>Options field values</td><td>30 days</td><td>Automated cron (nullify)</td><td>Permanently and irreversibly deleted</td></tr>
      <tr><td>Report IPs</td><td>90 days</td><td>Automated cron (nullify)</td><td>Permanently and irreversibly deleted</td></tr>
      <tr><td>Ban IPs</td><td>Ban duration + 30 days</td><td>Automated cron (nullify)</td><td>IP data permanently deleted</td></tr>
      <tr><td>Rate limiting logs</td><td>24 hours</td><td>Automated cron (DELETE)</td><td>Entire record permanently deleted</td></tr>
      <tr><td>SFS pending reports</td><td>30 days</td><td>Automated cron (DELETE)</td><td>Entire record permanently deleted</td></tr>
      <tr><td>Uploader IPs</td><td>30 days</td><td>Automated cron (nullify)</td><td>Permanently and irreversibly deleted</td></tr>
      <tr><td>Audit log staff IPs</td><td>1 year</td><td>Automated cron (nullify)</td><td>IP data permanently deleted</td></tr>
      <tr><td>Moderation decisions</td><td>1 year</td><td>Automated cron (DELETE)</td><td>Entire record permanently deleted</td></tr>
      <tr><td>HTTP access logs</td><td>7 days</td><td>logrotate</td><td>Rotated and overwritten (no IPs stored)</td></tr>
      <tr><td>Consent records</td><td>Indefinite</td><td>N/A</td><td>Required for legal compliance</td></tr>
    </tbody>
  </table>

  <div class="highlight-box">
    <strong>How deletion works:</strong> The <code>IpRetentionService</code> runs as a scheduled cron job across all services. It sets PII columns to <code>NULL</code> (irreversible) or deletes entire records. Every deletion is logged to an immutable audit trail (without PII). You don&rsquo;t need to request deletion — it happens automatically.
  </div>

  <!-- 6. Legal Basis -->
  <h2 id="legal-basis">6. Legal Basis for Processing (GDPR)</h2>

  <p>For users in the European Economic Area (EEA), UK, and other GDPR-covered jurisdictions, we process your data based on the following legal bases:</p>

  <table class="data-table">
    <thead>
      <tr><th>Data</th><th>Legal Basis</th><th>Justification</th></tr>
    </thead>
    <tbody>
      <tr><td>IP addresses (posts)</td><td>Legitimate interest (Art. 6(1)(f))</td><td>Abuse prevention, spam mitigation, legal compliance</td></tr>
      <tr><td>IP addresses (bans)</td><td>Legitimate interest (Art. 6(1)(f))</td><td>Enforcing community rules, preventing ban evasion</td></tr>
      <tr><td>Options field (sage/noko)</td><td>Contract performance</td><td>Post command functionality (not PII — does not contain email addresses)</td></tr>
      <tr><td>Post content &amp; media</td><td>Contract performance</td><td>Core service functionality</td></tr>
      <tr><td>SFS lookup &amp; reporting</td><td>Consent (by posting)</td><td>User consents to anti-spam checks and potential SFS reporting by submitting a post</td></tr>
      <tr><td>Consent records</td><td>Legal obligation (Art. 6(1)(c))</td><td>GDPR/CCPA record-keeping requirement</td></tr>
      <tr><td>Staff account data</td><td>Contract performance</td><td>Volunteer/staff relationship</td></tr>
      <tr><td>Moderation logs</td><td>Legitimate interest (Art. 6(1)(f))</td><td>Platform safety, legal defense</td></tr>
    </tbody>
  </table>

  <!-- 7. Third-Party -->
  <h2 id="third-party">7. Third-Party Data Sharing</h2>

  <h3>7.1 StopForumSpam (SFS)</h3>

  <p>To protect the community from spam and abuse, ashchan integrates with <a href="https://www.stopforumspam.com" target="_blank" rel="noopener">StopForumSpam</a>, a publicly accessible anti-spam database.</p>

  <h3>How it works:</h3>
  <ol>
    <li><strong>Lookup:</strong> When you create a post, your IP address may be checked against the SFS database to determine if it has been previously reported as a spam source. This check sends your IP address to SFS over HTTPS.</li>
    <li><strong>Reporting (manual only):</strong> If your post is identified as spam by a moderator, a site administrator <strong>may</strong> submit a report to StopForumSpam including:
      <ul>
        <li>Your IP address (decrypted from encrypted storage specifically for this purpose)</li>
        <li>Your username (as displayed on the post, typically &ldquo;Anonymous&rdquo;)</li>
        <li>The content of your post (as evidence)</li>
      </ul>
    </li>
    <li><strong>Consequences:</strong> Once submitted, this data becomes part of a public anti-spam database subject to <a href="https://www.stopforumspam.com/privacy" target="_blank" rel="noopener">SFS&rsquo;s own privacy policy</a>.</li>
  </ol>

  <div class="warning-box">
    <strong>Important:</strong> SFS submission is <strong>never automatic</strong>. It requires explicit, manual approval by a site administrator. Your encrypted IP must be actively decrypted for this specific purpose. An audit trail is maintained for all decryption and submission actions. You may object to SFS reporting — see <a href="/legal/rights">Your Privacy Rights</a>.
  </div>

  <div class="highlight-box">
    <strong>Consent by posting:</strong> By submitting a post on ashchan, you acknowledge and agree that your IP address may be checked against the SFS database during post submission, and that if your post is confirmed as spam by a site administrator, your IP address and post content may be reported to SFS. This consent is noted on all post forms across the site. If you do not agree, please do not post.
  </div>

  <h3>7.2 Law Enforcement</h3>
  <p>Data may be disclosed in response to valid legal process (subpoena, court order, etc.) as required by applicable law. We will notify affected users where legally permitted to do so.</p>

  <h3>7.3 No Other Sharing</h3>
  <p>We do not share, sell, rent, or trade your personal data with any other third parties, advertisers, data brokers, or anyone else. Period.</p>

  <!-- 8. Rights -->
  <h2 id="rights">8. Your Privacy Rights</h2>

  <p>Depending on your jurisdiction, you have the following rights. You can exercise all of them from our <a href="/legal/rights"><strong>Privacy Rights Center</strong></a>.</p>

  <table class="data-table">
    <thead>
      <tr><th>Right</th><th>Description</th><th>Response Time</th></tr>
    </thead>
    <tbody>
      <tr><td><strong>Right to Access</strong></td><td>Request a copy of all data we hold about you</td><td>30 days (GDPR) / 45 days (CCPA)</td></tr>
      <tr><td><strong>Right to Erasure</strong></td><td>Request deletion of your data (&ldquo;Right to be Forgotten&rdquo;). Note: IP data is already auto-deleted per retention schedule.</td><td>30 days (GDPR) / 45 days (CCPA)</td></tr>
      <tr><td><strong>Right to Rectification</strong></td><td>Request correction of inaccurate personal data</td><td>Immediate where possible</td></tr>
      <tr><td><strong>Right to Data Portability</strong></td><td>Request your data in a structured, machine-readable format (JSON)</td><td>30 days</td></tr>
      <tr><td><strong>Right to Object</strong></td><td>Object to processing for specific purposes, including SFS reporting</td><td>Immediate</td></tr>
      <tr><td><strong>Right to Restrict Processing</strong></td><td>Request that we limit how we process your data</td><td>Immediate</td></tr>
      <tr><td><strong>Right to Not Be Sold</strong> (CCPA)</td><td>We do NOT sell personal data. This right is satisfied by default.</td><td>N/A</td></tr>
      <tr><td><strong>Non-Discrimination</strong> (CCPA)</td><td>We will not discriminate against you for exercising your privacy rights</td><td>N/A</td></tr>
    </tbody>
  </table>

  <p><strong>To exercise your rights:</strong> Visit our <a href="/legal/rights">Privacy Rights Center</a> or contact us at <a href="/legal/contact">our contact page</a>. We will respond within the applicable timeframe and will never charge a fee for exercising your rights.</p>

  <h3>Automated Decision-Making</h3>
  <p>We use automated spam scoring during post creation. If your post is blocked by automated anti-spam measures, you may contact the site administrator to request manual review. You have the right not to be subject to decisions based solely on automated processing that significantly affect you.</p>

  <!-- 9. Breach -->
  <h2 id="breach">9. Data Breach Notification</h2>

  <p>In the event of a data breach affecting your personal data, we will:</p>
  <ul>
    <li>Notify the relevant supervisory authority within <strong>72 hours</strong> (GDPR requirement)</li>
    <li>Notify affected users if the breach poses a high risk to their rights and freedoms</li>
    <li>Publish a notice on the platform</li>
    <li>Take immediate steps to contain and remediate the breach</li>
  </ul>

  <div class="highlight-box">
    <strong>Encryption matters:</strong> Due to our encryption-at-rest policy, a database breach alone would <strong>not</strong> expose your personal data in readable form. The encryption keys are stored separately from the database.
  </div>

  <!-- 10. Children -->
  <h2 id="children">10. Children&rsquo;s Privacy</h2>

  <p>ashchan is not directed at children under the age of 13 (or the applicable age of digital consent in your jurisdiction). We do not knowingly collect personal data from children. If you are a parent or guardian and believe your child has provided personal data through this site, please <a href="/legal/contact">contact us</a> and we will take steps to delete that information.</p>

  <!-- 11. Changes -->
  <h2 id="changes">11. Changes to This Policy</h2>

  <p>We may update this policy from time to time. When we do:</p>
  <ul>
    <li>The policy version number and &ldquo;Last Updated&rdquo; date at the top will be updated.</li>
    <li>Material changes will be announced via a site-wide notice (blotter message).</li>
    <li>Where required by law, we will obtain re-consent for material changes.</li>
    <li>Previous versions of this policy will be archived and available upon request.</li>
  </ul>

  <p style="margin-top: 20px; font-size: 9pt; color: #888;"><em>If you have questions about this privacy policy, please <a href="/legal/contact">contact us</a>. For exercising your privacy rights, visit the <a href="/legal/rights">Privacy Rights Center</a>.</em></p>

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
