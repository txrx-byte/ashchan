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
  <title>ashchan - Privacy Rights Center</title>
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

    .rights-content { max-width: 780px; margin: 0 auto; padding: 20px 20px 40px; }
    .rights-content h2 { color: #800000; font-size: 16px; border-bottom: 1px solid #D9BFB7; padding-bottom: 4px; margin: 28px 0 10px; }
    .rights-content h3 { color: #34345C; font-size: 13px; margin: 18px 0 6px; }
    .rights-content p { margin: 6px 0; line-height: 1.6; }
    .rights-content ul { margin: 6px 0 6px 20px; padding: 0; line-height: 1.7; }
    .rights-content li { margin-bottom: 4px; }

    .rights-intro { background: #D6DAF0; border: 1px solid #B7C5D9; padding: 14px 18px; margin-bottom: 20px; line-height: 1.6; }
    .rights-intro p { margin: 6px 0; }

    .rights-table { border-collapse: collapse; width: 100%; margin: 10px 0 16px; font-size: 10pt; }
    .rights-table th { background: #800000; color: #FFF; padding: 6px 10px; text-align: left; font-weight: bold; font-size: 10pt; }
    .rights-table td { border: 1px solid #D9BFB7; padding: 6px 10px; vertical-align: top; }
    .rights-table tr:nth-child(even) { background: #F5F5E0; }

    .right-card { background: #F5F5E0; border: 1px solid #D9BFB7; padding: 14px 18px; margin-bottom: 14px; }
    .right-card h3 { color: #800000; font-size: 13px; margin: 0 0 6px; }
    .right-card p { margin: 4px 0; line-height: 1.6; font-size: 10pt; }
    .right-card .applies { font-size: 9pt; color: #888; margin-top: 6px; }

    .request-form { background: #F0E0D6; border: 1px solid #D9BFB7; padding: 18px 22px; margin-top: 16px; }
    .request-form h3 { color: #800000; font-size: 14px; margin: 0 0 12px; }
    .form-group { margin-bottom: 12px; }
    .form-group label { display: block; font-weight: bold; color: #34345C; margin-bottom: 3px; font-size: 10pt; }
    .form-group label .required { color: #DD0000; }
    .form-group input[type="text"],
    .form-group input[type="email"],
    .form-group textarea,
    .form-group select { width: 100%; max-width: 500px; padding: 6px 8px; border: 1px solid #B7C5D9; background: #FFFFEE; font-size: 10pt; font-family: arial, helvetica, sans-serif; box-sizing: border-box; }
    .form-group textarea { height: 100px; resize: vertical; }
    .form-group select { width: auto; min-width: 200px; }
    .form-group .help-text { font-size: 9pt; color: #888; margin-top: 2px; }
    .form-actions { margin-top: 16px; }
    .form-actions button { background: #800000; color: #FFF; border: 1px solid #600; padding: 8px 24px; font-size: 10pt; cursor: pointer; font-family: arial, helvetica, sans-serif; }
    .form-actions button:hover { background: #A00000; }

    .info-box { background: #E8EAFA; border: 1px solid #B7C5D9; padding: 12px 16px; margin: 14px 0; font-size: 10pt; line-height: 1.6; }
    .warning-box { background: #FFFACD; border: 1px solid #DAA520; padding: 12px 16px; margin: 14px 0; font-size: 10pt; line-height: 1.6; }

    .process-step { display: flex; align-items: flex-start; margin-bottom: 10px; }
    .step-number { background: #800000; color: #FFF; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; flex-shrink: 0; margin-right: 10px; margin-top: 2px; }
    .step-text { line-height: 1.6; }

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

<div class="rights-content">

  <div class="rights-intro">
    <p><strong>Privacy Rights Center</strong></p>
    <p>We believe you have the right to understand and control your data. This page lets you exercise your privacy rights under the <strong>EU General Data Protection Regulation (GDPR)</strong>, the <strong>California Consumer Privacy Act (CCPA/CPRA)</strong>, and other applicable privacy laws &mdash; all in one place.</p>
    <p><a href="/legal">&laquo; Back to Legal</a> &bull; <a href="/legal/privacy">Full Privacy Policy</a></p>
  </div>

  <!-- ===== YOUR RIGHTS ===== -->
  <h2>Your Rights at a Glance</h2>

  <table class="rights-table">
    <thead>
      <tr>
        <th>Right</th>
        <th>Description</th>
        <th>Response Time</th>
        <th>Law</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Right to Access</strong></td>
        <td>Request a copy of all personal data we hold about you, exported as JSON.</td>
        <td>30 days</td>
        <td>GDPR Art. 15 / CCPA &sect;1798.100</td>
      </tr>
      <tr>
        <td><strong>Right to Erasure</strong></td>
        <td>Request deletion of your personal data. Soft-deleted immediately, fully purged within SLA.</td>
        <td>45 days</td>
        <td>GDPR Art. 17 / CCPA &sect;1798.105</td>
      </tr>
      <tr>
        <td><strong>Right to Rectification</strong></td>
        <td>Correct inaccurate personal data. Available immediately via account settings.</td>
        <td>Immediate</td>
        <td>GDPR Art. 16</td>
      </tr>
      <tr>
        <td><strong>Right to Data Portability</strong></td>
        <td>Receive your personal data in a structured, machine-readable JSON format.</td>
        <td>30 days</td>
        <td>GDPR Art. 20 / CCPA &sect;1798.100</td>
      </tr>
      <tr>
        <td><strong>Right to Object</strong></td>
        <td>Object to specific processing. Opt out of SFS reporting via account settings.</td>
        <td>Immediate</td>
        <td>GDPR Art. 21</td>
      </tr>
      <tr>
        <td><strong>Right to Restrict Processing</strong></td>
        <td>Limit how we process your data while a complaint or rectification is pending.</td>
        <td>72 hours</td>
        <td>GDPR Art. 18</td>
      </tr>
      <tr>
        <td><strong>Right to Know</strong></td>
        <td>Know what personal information we collect, how we use it, and who we share it with.</td>
        <td>45 days</td>
        <td>CCPA &sect;1798.100</td>
      </tr>
      <tr>
        <td><strong>Right to Non-Discrimination</strong></td>
        <td>We will never discriminate against you for exercising your privacy rights.</td>
        <td>&mdash;</td>
        <td>CCPA &sect;1798.125</td>
      </tr>
    </tbody>
  </table>

  <!-- ===== DETAILED RIGHTS ===== -->
  <h2>Detailed Rights Explanations</h2>

  <div class="right-card">
    <h3>Right to Access / Right to Know</h3>
    <p>You can request a complete copy of all personal data we hold about you. Your export will include:</p>
    <ul>
      <li><strong>Account data:</strong> Username, email (if provided), password hash, role, registration IP (encrypted), registration date</li>
      <li><strong>Post data:</strong> All posts attributed to your account, including any associated IP hashes</li>
      <li><strong>Report data:</strong> Any reports you have filed</li>
      <li><strong>Moderation data:</strong> Any bans or warnings issued to your account</li>
    </ul>
    <p>Data is exported as a machine-readable JSON file. IP addresses stored in our system are encrypted with XChaCha20-Poly1305 and will be provided in their original form in your export.</p>
    <p class="applies">Applies under: GDPR Article 15, CCPA &sect;1798.100</p>
  </div>

  <div class="right-card">
    <h3>Right to Erasure (&ldquo;Right to Be Forgotten&rdquo;)</h3>
    <p>You can request that we delete your personal data. Our deletion process:</p>
    <ul>
      <li><strong>Step 1:</strong> Your account and associated PII are soft-deleted immediately upon approval</li>
      <li><strong>Step 2:</strong> All encrypted IP addresses linked to your posts are set to NULL</li>
      <li><strong>Step 3:</strong> Your data is fully purged from all systems within 45 days</li>
    </ul>
    <p><strong>What remains after erasure:</strong> Post content itself is retained (imageboards are public forums), but all identifying information is permanently removed. Posts become truly anonymous.</p>
    <div class="warning-box">
      <strong>Important:</strong> Erasure may be limited when data is required for legal obligations, defense of legal claims, or compliance with law enforcement requests. We will inform you of any such limitations.
    </div>
    <p class="applies">Applies under: GDPR Article 17, CCPA &sect;1798.105</p>
  </div>

  <div class="right-card">
    <h3>Right to Rectification</h3>
    <p>You can correct inaccurate personal data at any time through your account settings. If you need to correct data that isn&rsquo;t directly editable, submit a request below.</p>
    <p class="applies">Applies under: GDPR Article 16</p>
  </div>

  <div class="right-card">
    <h3>Right to Data Portability</h3>
    <p>You can receive all your personal data in a structured, commonly used, machine-readable format (JSON). This export is identical to an access request and can be used to transfer your data to another service.</p>
    <p class="applies">Applies under: GDPR Article 20, CCPA &sect;1798.100</p>
  </div>

  <div class="right-card">
    <h3>Right to Object / Opt-Out</h3>
    <p>You can object to specific types of data processing:</p>
    <ul>
      <li><strong>Stop Forum Spam (SFS) reporting:</strong> If your IP or email is submitted to SFS as part of our anti-spam measures, you can opt out via your account settings. This takes effect immediately.</li>
      <li><strong>Sale of personal information:</strong> We do <strong>not</strong> sell your personal information and never have. There is nothing to opt out of.</li>
    </ul>
    <p class="applies">Applies under: GDPR Article 21, CCPA &sect;1798.120</p>
  </div>

  <div class="right-card">
    <h3>Right to Restrict Processing</h3>
    <p>You can request that we limit how we process your data. This is typically used when:</p>
    <ul>
      <li>You contest the accuracy of your data (processing paused while we verify)</li>
      <li>Processing is unlawful but you prefer restriction over erasure</li>
      <li>We no longer need the data but you need it for legal claims</li>
    </ul>
    <p class="applies">Applies under: GDPR Article 18</p>
  </div>

  <!-- ===== WHAT WE ACTUALLY STORE ===== -->
  <h2>What We Store About You</h2>

  <div class="info-box">
    <strong>Transparency note:</strong> We are an imageboard. We collect the <em>minimum data required</em> to operate the service and comply with the law. We do <strong>not</strong> track you across websites, build advertising profiles, or sell any data. For the complete data inventory, see our <a href="/legal/privacy">Privacy Policy</a>.
  </div>

  <table class="rights-table">
    <thead>
      <tr>
        <th>Data Category</th>
        <th>What</th>
        <th>Protection</th>
        <th>Auto-Deleted After</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Post IP addresses</td>
        <td>Your IP when you create a post</td>
        <td>XChaCha20-Poly1305 encrypted + SHA-256 hash</td>
        <td>30 days</td>
      </tr>
      <tr>
        <td>Report IP addresses</td>
        <td>Your IP when you file a report</td>
        <td>XChaCha20-Poly1305 encrypted</td>
        <td>90 days</td>
      </tr>
      <tr>
        <td>Ban records</td>
        <td>IP at time of ban</td>
        <td>XChaCha20-Poly1305 encrypted</td>
        <td>Ban expiry + 30 days</td>
      </tr>
      <tr>
        <td>Flood prevention logs</td>
        <td>IP hash for rate limiting</td>
        <td>SHA-256 hash only (no raw IP)</td>
        <td>24 hours</td>
      </tr>
      <tr>
        <td>Post content</td>
        <td>Your post text, subject, name</td>
        <td>Stored as-is (public content)</td>
        <td>Board-specific archive/prune rules</td>
      </tr>
      <tr>
        <td>Uploaded media</td>
        <td>Images/files you upload</td>
        <td>SHA-256 deduplicated, stored in MinIO</td>
        <td>When last referencing post is pruned</td>
      </tr>
      <tr>
        <td>Cookies</td>
        <td>Session token (staff only), consent version</td>
        <td>HttpOnly, Secure, SameSite=Strict</td>
        <td>Session end / 1 year</td>
      </tr>
      <tr>
        <td>localStorage</td>
        <td>Style preference, post password, UI state</td>
        <td>Client-side only, never sent to server</td>
        <td>Until you clear browser data</td>
      </tr>
    </tbody>
  </table>

  <div class="info-box">
    <strong>What we do NOT store:</strong> We do not collect email addresses (unless you provide one voluntarily), real names, phone numbers, social media profiles, location data, device fingerprints, advertising identifiers, or browsing history. We use <strong>zero</strong> analytics, tracking pixels, or third-party scripts.
  </div>

  <!-- ===== HOW DELETION WORKS ===== -->
  <h2>How Automatic Deletion Works</h2>

  <p>Our <code>IpRetentionService</code> runs as a scheduled cron job and <strong>automatically</strong> purges data according to this schedule &mdash; no action required from you:</p>

  <div class="process-step">
    <div class="step-number">1</div>
    <div class="step-text"><strong>Post IPs:</strong> Set to NULL after 30 days. Your posts remain, but the link to your IP is permanently destroyed.</div>
  </div>
  <div class="process-step">
    <div class="step-number">2</div>
    <div class="step-text"><strong>Flood logs:</strong> Deleted entirely after 24 hours.</div>
  </div>
  <div class="process-step">
    <div class="step-number">3</div>
    <div class="step-text"><strong>Report IPs:</strong> Set to NULL after 90 days.</div>
  </div>
  <div class="process-step">
    <div class="step-number">4</div>
    <div class="step-text"><strong>Ban records:</strong> IP data nullified 30 days after ban expiry.</div>
  </div>
  <div class="process-step">
    <div class="step-number">5</div>
    <div class="step-text"><strong>SFS queue:</strong> Processed entries deleted after 30 days.</div>
  </div>
  <div class="process-step">
    <div class="step-number">6</div>
    <div class="step-text"><strong>Audit logs:</strong> IP addresses nullified after 1 year. Audit records themselves are retained.</div>
  </div>

  <p>All automated deletions are logged to an immutable audit trail (without PII) for compliance verification.</p>

  <!-- ===== REQUEST PROCESS ===== -->
  <h2>How to Exercise Your Rights</h2>

  <h3>Option 1: Self-Service (Immediate)</h3>
  <p>Some rights can be exercised immediately without submitting a request:</p>
  <ul>
    <li><strong>Rectification:</strong> Update your account information directly in your account settings</li>
    <li><strong>Object to SFS reporting:</strong> Toggle the opt-out in your account settings</li>
    <li><strong>Delete your posts:</strong> Use the delete function on individual posts (requires your post password)</li>
  </ul>

  <h3>Option 2: Submit a Formal Request</h3>
  <p>For access, erasure, portability, restriction, or any right not available via self-service, submit the form below.</p>

  <div class="request-form" id="rights-request-form">
    <h3>Privacy Rights Request Form</h3>

    <div class="form-group">
      <label for="request-type">Request Type <span class="required">*</span></label>
      <select id="request-type" name="request_type" required>
        <option value="">-- Select a right to exercise --</option>
        <option value="access">Right to Access / Data Export (GDPR Art. 15 / CCPA)</option>
        <option value="erasure">Right to Erasure / Deletion (GDPR Art. 17 / CCPA)</option>
        <option value="portability">Right to Data Portability (GDPR Art. 20)</option>
        <option value="rectification">Right to Rectification (GDPR Art. 16)</option>
        <option value="restriction">Right to Restrict Processing (GDPR Art. 18)</option>
        <option value="objection">Right to Object (GDPR Art. 21)</option>
        <option value="know">Right to Know (CCPA)</option>
        <option value="optout">Do Not Sell My Information (CCPA)</option>
      </select>
    </div>

    <div class="form-group">
      <label for="identity-method">Identity Verification Method <span class="required">*</span></label>
      <select id="identity-method" name="identity_method" required>
        <option value="">-- How can we verify your identity? --</option>
        <option value="account">I have an account (provide username below)</option>
        <option value="post_password">I have a post password for specific posts</option>
        <option value="ip_range">I can verify my IP address range</option>
        <option value="other">Other (describe below)</option>
      </select>
      <div class="help-text">We must verify your identity before processing your request. We will never ask for more information than necessary.</div>
    </div>

    <div class="form-group">
      <label for="identifier">Username or Identifier</label>
      <input type="text" id="identifier" name="identifier" placeholder="Your username, tripcode, or other identifier">
      <div class="help-text">Provide any identifier that helps us locate your data.</div>
    </div>

    <div class="form-group">
      <label for="email">Email Address (for response delivery)</label>
      <input type="email" id="email" name="email" placeholder="your@email.com">
      <div class="help-text">Optional. If you don&rsquo;t provide an email, we&rsquo;ll deliver the response through an alternative secure method.</div>
    </div>

    <div class="form-group">
      <label for="details">Additional Details <span class="required">*</span></label>
      <textarea id="details" name="details" placeholder="Please describe your request in detail. Include any relevant post numbers, board names, date ranges, or other information that helps us locate your data." required></textarea>
    </div>

    <div class="form-group">
      <label for="jurisdiction">Your Jurisdiction</label>
      <select id="jurisdiction" name="jurisdiction">
        <option value="">-- Optional: select your jurisdiction --</option>
        <option value="eu">European Union / EEA (GDPR)</option>
        <option value="uk">United Kingdom (UK GDPR)</option>
        <option value="california">California, USA (CCPA/CPRA)</option>
        <option value="other_us">Other US State</option>
        <option value="canada">Canada (PIPEDA)</option>
        <option value="brazil">Brazil (LGPD)</option>
        <option value="other">Other</option>
      </select>
      <div class="help-text">Helps us apply the correct legal framework, but we honor all rights requests regardless of location.</div>
    </div>

    <div class="info-box" style="margin-top: 10px;">
      <strong>What happens next:</strong>
      <ol style="margin: 6px 0 0 16px; padding: 0;">
        <li>We receive your request and acknowledge it within <strong>72 hours</strong></li>
        <li>We verify your identity using the method you selected</li>
        <li>We process your request within the applicable SLA (30&ndash;45 days)</li>
        <li>We deliver the result to you securely</li>
      </ol>
    </div>

    <div class="form-actions">
      <button type="button" onclick="submitRightsRequest()">Submit Privacy Request</button>
    </div>
  </div>

  <!-- ===== CCPA-SPECIFIC ===== -->
  <h2>California Privacy Rights (CCPA/CPRA)</h2>

  <p>If you are a California resident, you have additional rights under the California Consumer Privacy Act (CCPA) as amended by the California Privacy Rights Act (CPRA):</p>

  <div class="right-card">
    <h3>Do Not Sell or Share My Personal Information</h3>
    <p>We do <strong>not</strong> sell your personal information. We do <strong>not</strong> share your personal information for cross-context behavioral advertising. There is no &ldquo;sale&rdquo; or &ldquo;sharing&rdquo; as defined by the CCPA to opt out of.</p>
    <p>The only third-party data transmission we perform is to <strong>Stop Forum Spam (SFS)</strong> for anti-spam purposes, which involves sending an IP address and/or email of confirmed spammers. This is not a &ldquo;sale&rdquo; under CCPA. Regardless, you can opt out of SFS reporting via your account settings.</p>
  </div>

  <div class="right-card">
    <h3>Categories of Personal Information Collected</h3>
    <p>In the past 12 months, we have collected the following categories (per CCPA &sect;1798.140):</p>
    <ul>
      <li><strong>Identifiers:</strong> IP addresses (encrypted), usernames, tripcodes, post passwords</li>
      <li><strong>Internet activity:</strong> Posts created, reports filed, pages visited (server logs only, no tracking)</li>
    </ul>
    <p>We have <strong>NOT</strong> collected: real names, physical addresses, phone numbers, SSN, payment info, geolocation, biometric data, professional information, education information, or protected characteristics.</p>
  </div>

  <!-- ===== GDPR-SPECIFIC ===== -->
  <h2>EU/EEA Privacy Rights (GDPR)</h2>

  <p>If you are in the European Union or European Economic Area, the GDPR provides you with specific rights listed in the table above. Additional information:</p>

  <div class="right-card">
    <h3>Legal Basis for Processing</h3>
    <ul>
      <li><strong>Legitimate interest (Art. 6(1)(f)):</strong> Processing post data, IP collection for anti-abuse</li>
      <li><strong>Consent (Art. 6(1)(a)):</strong> Cookie storage, optional account registration</li>
      <li><strong>Legal obligation (Art. 6(1)(c)):</strong> Retention of data for law enforcement compliance</li>
    </ul>
  </div>

  <div class="right-card">
    <h3>Data Protection Officer</h3>
    <p>For GDPR-related inquiries, you may contact our data protection representative at: <strong>dpo@ashchan.example.com</strong></p>
    <p style="font-size: 9pt; color: #888;">(Placeholder email &mdash; replace before deployment)</p>
  </div>

  <div class="right-card">
    <h3>Right to Lodge a Complaint</h3>
    <p>If you believe we have not adequately addressed your privacy concerns, you have the right to lodge a complaint with your local Data Protection Authority (DPA). A list of EU DPAs is available at <strong>edpb.europa.eu</strong>.</p>
  </div>

  <!-- ===== ENCRYPTION TRANSPARENCY ===== -->
  <h2>How We Protect Your Data</h2>

  <div class="info-box">
    <strong>Encryption architecture:</strong> All personally identifiable information (PII) is encrypted at rest using <strong>XChaCha20-Poly1305</strong> (IETF AEAD) with envelope encryption. This is the same family of algorithms used by modern secure messaging protocols.
  </div>

  <ul>
    <li><strong>Encryption at rest:</strong> Every IP address is encrypted with a unique 24-byte random nonce before storage</li>
    <li><strong>Hashing for lookups:</strong> A separate SHA-256 hash enables abuse lookups without exposing the raw IP</li>
    <li><strong>No plaintext IPs in logs:</strong> Application logs never contain raw IP addresses</li>
    <li><strong>Key rotation:</strong> Encryption keys are rotated every 90 days</li>
    <li><strong>mTLS:</strong> All inter-service communication is encrypted with mutual TLS</li>
    <li><strong>Admin access:</strong> PII decryption is logged to an immutable audit trail (who decrypted, when, why)</li>
  </ul>

  <!-- ===== VERIFICATION ===== -->
  <h2>Identity Verification</h2>

  <p>To protect your privacy, we must verify your identity before processing data rights requests. Our verification methods are designed to be <strong>proportional</strong> &mdash; we will never ask for more information than necessary:</p>

  <ul>
    <li><strong>Account holders:</strong> Verified through your authenticated session or account credentials</li>
    <li><strong>Anonymous posters:</strong> Verified through post passwords, IP range confirmation, or knowledge of specific post content</li>
    <li><strong>Authorized agents:</strong> If you designate an agent to act on your behalf (CCPA right), we require written authorization and agent identity verification</li>
  </ul>

  <div class="warning-box">
    <strong>We will never ask for:</strong> Government-issued ID, Social Security numbers, payment information, or any sensitive documents to verify your identity. If someone claiming to be us asks for these, it is not legitimate.
  </div>

  <!-- ===== CONTACT ===== -->
  <h2>Questions About Your Rights?</h2>

  <p>If you have questions about exercising your privacy rights, need help with the form, or want to discuss your request before submitting:</p>
  <ul>
    <li><strong>Privacy questions:</strong> <a href="/legal/contact">Contact Us</a></li>
    <li><strong>Full data details:</strong> <a href="/legal/privacy">Privacy Policy</a></li>
    <li><strong>Cookie information:</strong> <a href="/legal/cookies">Cookie Policy</a></li>
    <li><strong>Terms of use:</strong> <a href="/legal/terms">Terms of Service</a></li>
  </ul>

  <p style="margin-top: 18px; font-size: 9pt; color: #888;"><em>Last updated: <?= date('F j, Y') ?></em></p>

</div>

<script>
function submitRightsRequest() {
  var form = document.getElementById('rights-request-form');
  var type = document.getElementById('request-type').value;
  var method = document.getElementById('identity-method').value;
  var details = document.getElementById('details').value;

  if (!type) { alert('Please select a request type.'); return; }
  if (!method) { alert('Please select an identity verification method.'); return; }
  if (!details.trim()) { alert('Please provide additional details about your request.'); return; }

  // In production, this would submit via AJAX to a backend endpoint.
  // For now, show confirmation.
  alert(
    'Privacy rights request received.\n\n' +
    'Type: ' + type + '\n' +
    'Verification: ' + method + '\n\n' +
    'We will acknowledge your request within 72 hours and process it within the applicable SLA.\n\n' +
    'Note: This form is not yet connected to a backend. Please email your request to privacy@ashchan.example.com in the meantime.'
  );
}
</script>

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
