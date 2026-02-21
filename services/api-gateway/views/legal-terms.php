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
  <title>ashchan - Terms of Service</title>
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

    .tos-content { max-width: 780px; margin: 0 auto; padding: 20px 20px 40px; }
    .tos-content h2 { color: #800000; font-size: 16px; border-bottom: 1px solid #D9BFB7; padding-bottom: 4px; margin: 24px 0 10px; }
    .tos-content h3 { color: #800000; font-size: 13px; margin: 18px 0 6px; }
    .tos-content p { margin: 6px 0; line-height: 1.6; }
    .tos-content ul, .tos-content ol { margin: 6px 0 6px 20px; padding: 0; line-height: 1.7; }
    .tos-content li { margin-bottom: 4px; }

    .policy-meta { background: #D6DAF0; border: 1px solid #B7C5D9; padding: 12px 16px; margin-bottom: 20px; font-size: 10pt; line-height: 1.5; }
    .policy-meta p { margin: 3px 0; }

    .toc { background: #D6DAF0; border: 1px solid #B7C5D9; padding: 10px 15px; margin-bottom: 20px; }
    .toc h3 { margin: 0 0 6px; font-size: 12px; color: #34345C; }
    .toc ul { margin: 0; padding: 0 0 0 18px; font-size: 10pt; line-height: 1.6; }
    .toc a { color: #34345C; }
    .toc a:hover { color: #DD0000; }

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

<div class="tos-content">

  <div class="policy-meta">
    <p><strong>Terms of Service</strong></p>
    <p>Policy Version: 1.0.0 &bull; Last Updated: <?= date('F j, Y') ?> &bull; <a href="/legal">&laquo; Back to Legal</a></p>
    <p>By accessing or using ashchan, you agree to be bound by these Terms of Service. If you do not agree, do not use the site.</p>
  </div>

  <div class="toc">
    <h3>Table of Contents</h3>
    <ul>
      <li><a href="#acceptance">1. Acceptance of Terms</a></li>
      <li><a href="#eligibility">2. Eligibility</a></li>
      <li><a href="#description">3. Service Description</a></li>
      <li><a href="#conduct">4. User Conduct</a></li>
      <li><a href="#content">5. User Content</a></li>
      <li><a href="#data">6. Data Collection &amp; Privacy</a></li>
      <li><a href="#ip">7. Intellectual Property</a></li>
      <li><a href="#moderation">8. Moderation &amp; Enforcement</a></li>
      <li><a href="#dmca">9. DMCA &amp; Copyright</a></li>
      <li><a href="#liability">10. Limitation of Liability</a></li>
      <li><a href="#indemnification">11. Indemnification</a></li>
      <li><a href="#disclaimers">12. Disclaimers</a></li>
      <li><a href="#governing">13. Governing Law</a></li>
      <li><a href="#modifications">14. Modifications</a></li>
      <li><a href="#severability">15. Severability</a></li>
      <li><a href="#contact-tos">16. Contact</a></li>
    </ul>
  </div>

  <h2 id="acceptance">1. Acceptance of Terms</h2>
  <p>By accessing, browsing, or using ashchan (the &ldquo;Service&rdquo;), you acknowledge that you have read, understood, and agree to be bound by these Terms of Service (&ldquo;Terms&rdquo;) and our <a href="/legal/privacy">Privacy Policy</a>. These Terms constitute a legally binding agreement between you and the operators of ashchan.</p>
  <p>Your continued use of the Service after any modifications to these Terms constitutes acceptance of the revised Terms.</p>

  <h2 id="eligibility">2. Eligibility</h2>
  <p>You must be at least 18 years of age to use this Service. By using ashchan, you represent and warrant that you are at least 18 years old and have the legal capacity to enter into this agreement. If you are accessing the Service from a jurisdiction where the age of majority is higher than 18, you must meet that jurisdiction&rsquo;s requirements.</p>

  <h2 id="description">3. Service Description</h2>
  <p>ashchan is an anonymous imageboard platform that allows users to post text and images across topic-specific boards. The Service is provided on an &ldquo;as is&rdquo; and &ldquo;as available&rdquo; basis. Key characteristics:</p>
  <ul>
    <li>No registration or account is required to post.</li>
    <li>All posts are anonymous by default.</li>
    <li>Content is ephemeral — older threads are pruned as new ones are created.</li>
    <li>The Service is provided free of charge.</li>
  </ul>

  <h2 id="conduct">4. User Conduct</h2>
  <p>You agree to comply with all applicable laws and the <a href="/rules">site rules</a>. You will not:</p>
  <ul>
    <li>Post content that violates United States law or the laws of your jurisdiction.</li>
    <li>Upload, post, or link to child sexual abuse material (CSAM) or any content exploiting minors.</li>
    <li>Post personal information (&ldquo;dox&rdquo;) of other individuals.</li>
    <li>Organize or participate in raids, brigading, or harassment campaigns.</li>
    <li>Spam, flood, or otherwise disrupt the Service.</li>
    <li>Post malware, viruses, phishing links, or other malicious content.</li>
    <li>Attempt to circumvent bans, spam filters, or other security measures.</li>
    <li>Impersonate site staff or forge capcodes.</li>
    <li>Use the Service for commercial advertising without authorization.</li>
    <li>Attempt to gain unauthorized access to the Service&rsquo;s infrastructure.</li>
  </ul>

  <h2 id="content">5. User Content</h2>
  <p>You retain ownership of the content you post. By posting content on ashchan, you grant the Service a non-exclusive, royalty-free, worldwide license to display, distribute, and store your content as necessary to operate the Service.</p>
  <p>You are solely responsible for the content you post. The Service does not pre-screen content and makes no representations about the accuracy, reliability, or quality of user-submitted content.</p>
  <p>Content may be removed at the discretion of site staff for any reason, including but not limited to rule violations. You may delete your own posts using the deletion password set at the time of posting.</p>

  <h2 id="data">6. Data Collection &amp; Privacy</h2>
  <p>Your use of the Service is also governed by our <a href="/legal/privacy">Privacy Policy</a>, which details:</p>
  <ul>
    <li>What data we collect (IP addresses, post content, options field commands)</li>
    <li>How it is encrypted and protected (XChaCha20-Poly1305 authenticated encryption)</li>
    <li>How long it is retained (30 days for post IPs, auto-deleted)</li>
    <li>Third-party sharing (StopForumSpam — manual, admin-approved only)</li>
    <li>Your privacy rights under GDPR and CCPA</li>
  </ul>
  <p><strong>Key disclosure:</strong> By posting on ashchan, you acknowledge and consent to the possibility that your IP address and post data may be reported to <a href="https://www.stopforumspam.com" target="_blank" rel="noopener">StopForumSpam</a> if your activity is determined to constitute spam or abuse. This process is never automated — it requires explicit administrator approval with full audit logging. See our <a href="/legal/privacy#third-party">Privacy Policy</a> for full details.</p>

  <h2 id="ip">7. Intellectual Property</h2>
  <p>The ashchan software, design, and original content are protected by copyright. User-submitted content remains the property of its respective creators.</p>
  <p>The site name, logo, and branding are the property of the ashchan operators. You may not use them in a manner that suggests endorsement or affiliation without permission.</p>

  <h2 id="moderation">8. Moderation &amp; Enforcement</h2>
  <p>The Service is moderated by volunteer staff. Moderation actions may include:</p>
  <ul>
    <li>Warning messages attached to posts</li>
    <li>Post or thread deletion</li>
    <li>Temporary or permanent bans (IP-based)</li>
    <li>Range bans for persistent abuse</li>
  </ul>
  <p>Moderation decisions are made at the discretion of site staff. While we strive for fairness and consistency, the Service reserves the right to remove any content or restrict any user&rsquo;s access for any reason.</p>
  <p>If you believe a moderation action was taken in error, you may appeal via the ban page or through the <a href="/feedback">Feedback</a> form.</p>

  <h2 id="dmca">9. DMCA &amp; Copyright</h2>
  <p>ashchan respects the intellectual property rights of others. If you believe your copyrighted work has been posted on the Service without authorization, you may submit a DMCA takedown notice to the site administrators via our <a href="/legal/contact">contact page</a>.</p>
  <p>Your notice must include:</p>
  <ol>
    <li>Identification of the copyrighted work claimed to be infringed.</li>
    <li>Identification of the material to be removed, with sufficient information to locate it.</li>
    <li>Your contact information (name, address, email, phone number).</li>
    <li>A statement that you have a good faith belief that the use is not authorized.</li>
    <li>A statement, under penalty of perjury, that the information is accurate and you are authorized to act on behalf of the copyright owner.</li>
    <li>Your physical or electronic signature.</li>
  </ol>
  <p>We will process valid DMCA requests promptly and remove infringing content.</p>

  <h2 id="liability">10. Limitation of Liability</h2>
  <p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, THE SERVICE AND ITS OPERATORS SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES ARISING OUT OF OR IN CONNECTION WITH YOUR USE OF THE SERVICE, REGARDLESS OF THE CAUSE OF ACTION.</p>
  <p>The Service&rsquo;s total liability for any claim shall not exceed the amount you paid to use the Service (which is $0, as the Service is free).</p>

  <h2 id="indemnification">11. Indemnification</h2>
  <p>You agree to indemnify and hold harmless the Service operators, staff, and volunteers from any claims, damages, losses, or expenses (including legal fees) arising from:</p>
  <ul>
    <li>Your use of the Service</li>
    <li>Content you post on the Service</li>
    <li>Your violation of these Terms</li>
    <li>Your violation of any third-party rights</li>
  </ul>

  <h2 id="disclaimers">12. Disclaimers</h2>
  <p>THE SERVICE IS PROVIDED &ldquo;AS IS&rdquo; AND &ldquo;AS AVAILABLE&rdquo; WITHOUT WARRANTIES OF ANY KIND, EXPRESS OR IMPLIED. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE.</p>
  <p>We make no representations about the accuracy, reliability, or completeness of any user-generated content. You acknowledge that any reliance on such content is at your own risk.</p>

  <h2 id="governing">13. Governing Law</h2>
  <p>These Terms shall be governed by and construed in accordance with the laws of the United States. Any disputes arising from these Terms shall be subject to the exclusive jurisdiction of the courts of the United States.</p>

  <h2 id="modifications">14. Modifications</h2>
  <p>We reserve the right to modify these Terms at any time. Changes will be indicated by updating the &ldquo;Last Updated&rdquo; date and policy version at the top of this page. Material changes will be announced via a site-wide notice. Your continued use of the Service after modifications constitutes acceptance of the revised Terms.</p>

  <h2 id="severability">15. Severability</h2>
  <p>If any provision of these Terms is found to be unenforceable or invalid, that provision shall be limited or eliminated to the minimum extent necessary, and the remaining provisions shall remain in full force and effect.</p>

  <h2 id="contact-tos">16. Contact</h2>
  <p>For questions about these Terms of Service, please visit our <a href="/legal/contact">contact page</a> or submit feedback through the <a href="/feedback">Feedback</a> form.</p>

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
