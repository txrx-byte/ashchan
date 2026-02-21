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
  <title>ashchan - Feedback</title>
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

    .feedback-content {
      max-width: 780px;
      margin: 0 auto;
      padding: 20px 20px 40px;
    }

    .feedback-intro {
      background: #D6DAF0;
      border: 1px solid #B7C5D9;
      padding: 14px 18px;
      margin-bottom: 20px;
      line-height: 1.6;
    }
    .feedback-intro h2 {
      margin: 0 0 8px;
      font-size: 16px;
      color: #34345C;
    }
    .feedback-intro p {
      margin: 6px 0;
      color: #333;
    }

    .feedback-guidelines {
      background: #FFF9E5;
      border: 1px solid #E5D6A3;
      padding: 12px 18px;
      margin-bottom: 24px;
      line-height: 1.6;
    }
    .feedback-guidelines h3 {
      margin: 0 0 6px;
      font-size: 13px;
      color: #7A6A2B;
    }
    .feedback-guidelines ul {
      margin: 4px 0 4px 20px;
      padding: 0;
    }
    .feedback-guidelines li {
      margin: 3px 0;
      color: #555;
    }

    .form-section {
      margin-bottom: 28px;
    }
    .form-section h2 {
      color: #800000;
      font-size: 16px;
      border-bottom: 1px solid #D9BFB7;
      padding-bottom: 4px;
      margin: 0 0 14px;
    }

    .form-row {
      margin-bottom: 14px;
    }
    .form-row label {
      display: block;
      font-weight: bold;
      margin-bottom: 4px;
      color: #333;
      font-size: 10pt;
    }
    .form-row label .required {
      color: #DD0000;
      margin-left: 2px;
    }
    .form-row .hint {
      display: block;
      font-size: 9pt;
      color: #777;
      margin-bottom: 4px;
      font-weight: normal;
    }

    .form-row input[type="text"],
    .form-row input[type="email"],
    .form-row textarea,
    .form-row select {
      width: 100%;
      max-width: 560px;
      padding: 6px 8px;
      border: 1px solid #AAA;
      background: #FFF;
      font-family: arial, helvetica, sans-serif;
      font-size: 10pt;
      box-sizing: border-box;
    }
    .form-row input[type="text"]:focus,
    .form-row input[type="email"]:focus,
    .form-row textarea:focus,
    .form-row select:focus {
      border-color: #800000;
      outline: none;
      box-shadow: 0 0 3px rgba(128, 0, 0, 0.2);
    }

    .form-row textarea {
      min-height: 140px;
      resize: vertical;
    }

    .category-cards {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 6px;
    }
    .category-card {
      flex: 1 1 170px;
      max-width: 260px;
      border: 2px solid #D9BFB7;
      border-radius: 4px;
      padding: 10px 12px;
      cursor: pointer;
      background: #FFF;
      transition: all 0.15s;
    }
    .category-card:hover {
      border-color: #800000;
      background: #FFF8F0;
    }
    .category-card.selected {
      border-color: #800000;
      background: #FEEBD6;
      box-shadow: 0 0 0 1px #800000;
    }
    .category-card input[type="radio"] {
      display: none;
    }
    .category-card .cat-icon {
      font-size: 18px;
      margin-bottom: 4px;
      display: block;
    }
    .category-card .cat-name {
      font-weight: bold;
      font-size: 10pt;
      color: #800000;
      display: block;
      margin-bottom: 2px;
    }
    .category-card .cat-desc {
      font-size: 9pt;
      color: #666;
      line-height: 1.4;
    }

    .priority-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
    .priority-option {
      display: flex;
      align-items: center;
      gap: 4px;
      cursor: pointer;
      padding: 4px 8px;
      border: 1px solid #CCC;
      border-radius: 3px;
      font-size: 10pt;
    }
    .priority-option:hover {
      background: #F0F0F0;
    }
    .priority-option.selected {
      background: #FEEBD6;
      border-color: #800000;
    }

    .submit-row {
      margin-top: 20px;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .submit-btn {
      padding: 8px 28px;
      background: #800000;
      color: #FFF;
      border: none;
      cursor: pointer;
      font-size: 11pt;
      font-weight: bold;
      letter-spacing: 0.5px;
    }
    .submit-btn:hover {
      background: #A00000;
    }
    .submit-btn:disabled {
      background: #999;
      cursor: not-allowed;
    }
    .submit-note {
      font-size: 9pt;
      color: #777;
    }

    .success-msg {
      background: #E6FFE6;
      border: 1px solid #6C6;
      padding: 12px 16px;
      margin-bottom: 20px;
      color: #260;
      display: none;
    }
    .error-msg {
      background: #FFE6E6;
      border: 1px solid #C66;
      padding: 12px 16px;
      margin-bottom: 20px;
      color: #600;
      display: none;
    }

    .board-select-wrap {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }
    .board-select-wrap select {
      width: auto;
      min-width: 160px;
    }

    .char-count {
      font-size: 9pt;
      color: #999;
      text-align: right;
      max-width: 560px;
      margin-top: 2px;
    }
    .char-count.warning { color: #C60; }
    .char-count.over { color: #D00; }

    .privacy-note {
      background: #F0F0F0;
      border: 1px solid #DDD;
      padding: 10px 14px;
      margin-top: 20px;
      font-size: 9pt;
      color: #555;
      line-height: 1.5;
    }
    .privacy-note strong { color: #333; }

    .home-footer {
      background: #FEDCBA;
      padding: 6px 5px;
      font-size: 9pt;
      text-align: center;
      border-top: 1px solid #D9BFB7;
      margin-top: 30px;
    }
    .home-footer a { color: #800000; }

    @media (max-width: 600px) {
      .category-card {
        flex: 1 1 100%;
        max-width: none;
      }
      .form-row input[type="text"],
      .form-row input[type="email"],
      .form-row textarea,
      .form-row select {
        max-width: 100%;
      }
      .char-count { max-width: 100%; }
    }
  </style>
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
<div class="feedback-content">

  <!-- Welcome / Intro -->
  <div class="feedback-intro">
    <h2>We Want to Hear From You</h2>
    <p>ashchan is built <strong>for its community, by its community</strong>. Your feedback is essential to making this site better for everyone. Whether you&rsquo;re a longtime user or visiting for the first time, whether you have a small suggestion or a major concern — we genuinely want to hear it.</p>
    <p>We welcome feedback from <strong>all people without discrimination</strong>. Regardless of your background, identity, experience level, or how frequently you use the site, your perspective matters equally. Every voice contributes to building a better community.</p>
    <p>All feedback is reviewed by the site team. If you provide an email address, we may follow up to discuss your suggestion or to let you know when your issue has been addressed.</p>
  </div>

  <!-- Guidelines -->
  <div class="feedback-guidelines">
    <h3>Before You Submit</h3>
    <ul>
      <li><strong>Be specific.</strong> The more detail you provide, the better we can understand and act on your feedback.</li>
      <li><strong>One topic per submission.</strong> If you have multiple issues, please submit them separately so each gets proper attention.</li>
      <li><strong>Check the <a href="/about">FAQ</a> first.</strong> Your question may already have an answer in the About page.</li>
      <li><strong>This is not for reporting rule violations.</strong> To report a specific post, use the report button on that post instead.</li>
      <li><strong>No personal information.</strong> Do not include other people&rsquo;s private information in your feedback. You may optionally include your own email for follow-up.</li>
      <li><strong>Constructive feedback is most helpful.</strong> We appreciate both praise and criticism — what matters is that it helps us improve.</li>
    </ul>
  </div>

  <!-- Status messages -->
  <div class="success-msg" id="successMsg"></div>
  <div class="error-msg" id="errorMsg"></div>

  <!-- Feedback Form -->
  <form id="feedbackForm" method="post" action="/api/v1/feedback" onsubmit="return submitFeedback(event)">

    <!-- Category Selection -->
    <div class="form-section">
      <h2>What is your feedback about?</h2>
      <p style="margin: 0 0 12px; color: #555; font-size: 10pt;">Select the category that best describes your feedback. This helps our team route it to the right people.</p>

      <div class="category-cards">
        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="bug_report">
          <span class="cat-icon">&#128027;</span>
          <span class="cat-name">Bug Report</span>
          <span class="cat-desc">Something isn&rsquo;t working correctly — broken pages, errors, display issues, or unexpected behavior.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="feature_request">
          <span class="cat-icon">&#128161;</span>
          <span class="cat-name">Feature Request</span>
          <span class="cat-desc">An idea for a new feature, tool, or board that you&rsquo;d like to see added to ashchan.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="ui_ux">
          <span class="cat-icon">&#127912;</span>
          <span class="cat-name">Design &amp; Usability</span>
          <span class="cat-desc">Feedback on the site&rsquo;s look, feel, layout, or ease of use. Accessibility suggestions welcome.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="board_suggestion">
          <span class="cat-icon">&#128203;</span>
          <span class="cat-name">Board Suggestion</span>
          <span class="cat-desc">Suggest a new board topic, or feedback about existing board categories, rules, or organization.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="moderation">
          <span class="cat-icon">&#9878;</span>
          <span class="cat-name">Moderation Feedback</span>
          <span class="cat-desc">Concerns about moderation policies, ban appeals, or suggestions for improving rule enforcement.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="performance">
          <span class="cat-icon">&#9889;</span>
          <span class="cat-name">Performance</span>
          <span class="cat-desc">Slow pages, timeouts, connectivity issues, or anything related to site speed and reliability.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="security">
          <span class="cat-icon">&#128274;</span>
          <span class="cat-name">Security &amp; Privacy</span>
          <span class="cat-desc">Report a security concern or privacy issue. For urgent vulnerabilities, please also use our <a href="/contact">contact page</a>.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="accessibility">
          <span class="cat-icon">&#9855;</span>
          <span class="cat-name">Accessibility</span>
          <span class="cat-desc">Feedback on screen reader support, keyboard navigation, color contrast, or any accessibility barrier.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="praise">
          <span class="cat-icon">&#11088;</span>
          <span class="cat-name">Praise &amp; Thanks</span>
          <span class="cat-desc">Something you love about ashchan? Let us know! Positive feedback helps us keep doing what works.</span>
        </label>

        <label class="category-card" onclick="selectCategory(this)">
          <input type="radio" name="category" value="other">
          <span class="cat-icon">&#128172;</span>
          <span class="cat-name">Other / General</span>
          <span class="cat-desc">Anything that doesn&rsquo;t fit the other categories — questions, comments, or general thoughts.</span>
        </label>
      </div>
    </div>

    <!-- Details -->
    <div class="form-section">
      <h2>Details</h2>

      <div class="form-row">
        <label for="subject">Subject <span class="required">*</span></label>
        <span class="hint">A short summary of your feedback (max 150 characters).</span>
        <input type="text" id="subject" name="subject" maxlength="150" required placeholder="e.g. &ldquo;Catalog page doesn&rsquo;t load on mobile&rdquo;">
      </div>

      <div class="form-row">
        <label for="message">Message <span class="required">*</span></label>
        <span class="hint">Describe your feedback in detail. Include steps to reproduce bugs, or explain your suggestion clearly.</span>
        <textarea id="message" name="message" maxlength="5000" required placeholder="Please provide as much detail as possible. For bugs: what browser/device are you using? What did you expect to happen? What actually happened?"></textarea>
        <div class="char-count" id="charCount">0 / 5000</div>
      </div>

      <div class="form-row">
        <label for="board">Related Board</label>
        <span class="hint">If your feedback is about a specific board, select it below. Leave blank for general/site-wide feedback.</span>
        <div class="board-select-wrap">
          <select id="board" name="board">
            <option value="">— None / Site-wide —</option>
            <?php foreach (($boards ?? []) as $b): ?>
            <option value="<?= htmlspecialchars((string) $b['slug']) ?>">/<?= htmlspecialchars((string) $b['slug']) ?>/ - <?= htmlspecialchars((string) $b['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <label for="url">Page URL</label>
        <span class="hint">If your feedback relates to a specific page or thread, paste the URL here.</span>
        <input type="text" id="url" name="url" maxlength="500" placeholder="e.g. https://ashchan.org/a/thread/12345">
      </div>

      <div class="form-row">
        <label for="browser">Browser &amp; Device</label>
        <span class="hint">Especially helpful for bug reports. Auto-detected below, but feel free to edit.</span>
        <input type="text" id="browser" name="browser" maxlength="200">
      </div>
    </div>

    <!-- Priority -->
    <div class="form-section">
      <h2>How urgent is this?</h2>
      <p style="margin: 0 0 10px; color: #555; font-size: 10pt;">This helps us prioritize. All feedback is read regardless of priority level.</p>
      <div class="priority-row">
        <label class="priority-option" onclick="selectPriority(this)">
          <input type="radio" name="priority" value="low"> &#128994; Low &mdash; minor suggestion
        </label>
        <label class="priority-option selected" onclick="selectPriority(this)">
          <input type="radio" name="priority" value="normal" checked> &#128992; Normal &mdash; standard feedback
        </label>
        <label class="priority-option" onclick="selectPriority(this)">
          <input type="radio" name="priority" value="high"> &#128308; High &mdash; significant issue
        </label>
        <label class="priority-option" onclick="selectPriority(this)">
          <input type="radio" name="priority" value="critical"> &#128681; Critical &mdash; site-breaking problem
        </label>
      </div>
    </div>

    <!-- Contact (Optional) -->
    <div class="form-section">
      <h2>Contact Information (Optional)</h2>
      <p style="margin: 0 0 12px; color: #555; font-size: 10pt;">You are <strong>not required</strong> to provide any personal information. All feedback is anonymous by default. However, if you&rsquo;d like us to follow up with you, you may provide your email address.</p>

      <div class="form-row">
        <label for="email">Email Address</label>
        <span class="hint">We will only use this to respond to your feedback. We will never share it or use it for marketing.</span>
        <input type="email" id="email" name="email" maxlength="200" placeholder="optional">
      </div>

      <div class="form-row">
        <label for="name">Name or Alias</label>
        <span class="hint">How you&rsquo;d like to be addressed in our response (if applicable).</span>
        <input type="text" id="name" name="name" maxlength="100" placeholder="optional — Anonymous by default">
      </div>
    </div>

    <!-- Privacy + Submit -->
    <div class="privacy-note">
      <strong>Privacy Notice:</strong> Your feedback is submitted securely and stored internally for review by the ashchan team. We collect only the information you provide on this form. Your IP address is recorded solely for anti-spam purposes and is automatically purged after 7 days. We do not share feedback data with third parties. If you include your email, it will be used only to respond to your submission. See our <a href="/legal">privacy policy</a> for more information.
    </div>

    <div class="submit-row">
      <button type="submit" class="submit-btn" id="submitBtn">Submit Feedback</button>
      <span class="submit-note">All fields marked with <span style="color:#DD0000;">*</span> are required.</span>
    </div>

  </form>

  <!-- Additional help -->
  <div style="margin-top: 28px; padding: 12px 0; border-top: 1px solid #D9BFB7; font-size: 10pt; color: #555; line-height: 1.6;">
    <strong>Other ways to reach us:</strong>
    <ul style="margin: 6px 0 0 20px; padding: 0;">
      <li>For <strong>rule violations on specific posts</strong>, use the [Report] button on that post.</li>
      <li>For <strong>ban appeals</strong>, visit the <a href="/banned">ban appeal page</a> (shown when you receive a ban).</li>
      <li>For <strong>legal inquiries</strong> (DMCA, law enforcement), see our <a href="/contact">contact page</a>.</li>
      <li>For <strong>security vulnerabilities</strong>, please also email the contact address listed on the <a href="/contact">contact page</a> with &ldquo;SECURITY&rdquo; in the subject line.</li>
    </ul>
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

<script>
// Auto-detect browser
(function() {
  var b = document.getElementById('browser');
  if (b) b.value = navigator.userAgent.substring(0, 200);
})();

// Category card selection
function selectCategory(el) {
  document.querySelectorAll('.category-card').forEach(function(c) {
    c.classList.remove('selected');
  });
  el.classList.add('selected');
  var radio = el.querySelector('input[type="radio"]');
  if (radio) radio.checked = true;
}

// Priority selection
function selectPriority(el) {
  document.querySelectorAll('.priority-option').forEach(function(p) {
    p.classList.remove('selected');
  });
  el.classList.add('selected');
  var radio = el.querySelector('input[type="radio"]');
  if (radio) radio.checked = true;
}

// Character counter
(function() {
  var msg = document.getElementById('message');
  var counter = document.getElementById('charCount');
  if (msg && counter) {
    msg.addEventListener('input', function() {
      var len = msg.value.length;
      counter.textContent = len + ' / 5000';
      counter.className = 'char-count';
      if (len > 4500) counter.className = 'char-count warning';
      if (len > 5000) counter.className = 'char-count over';
    });
  }
})();

// Form submission
function submitFeedback(e) {
  e.preventDefault();

  var form = document.getElementById('feedbackForm');
  var btn = document.getElementById('submitBtn');
  var successEl = document.getElementById('successMsg');
  var errorEl = document.getElementById('errorMsg');

  // Hide previous messages
  successEl.style.display = 'none';
  errorEl.style.display = 'none';

  // Validate category
  var category = form.querySelector('input[name="category"]:checked');
  if (!category) {
    errorEl.textContent = 'Please select a feedback category.';
    errorEl.style.display = 'block';
    errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return false;
  }

  // Validate subject
  var subject = document.getElementById('subject').value.trim();
  if (!subject) {
    errorEl.textContent = 'Please enter a subject.';
    errorEl.style.display = 'block';
    return false;
  }

  // Validate message
  var message = document.getElementById('message').value.trim();
  if (!message) {
    errorEl.textContent = 'Please enter a message.';
    errorEl.style.display = 'block';
    return false;
  }
  if (message.length < 10) {
    errorEl.textContent = 'Please provide a more detailed message (at least 10 characters).';
    errorEl.style.display = 'block';
    return false;
  }

  // Build payload
  var payload = {
    category: category.value,
    subject: subject,
    message: message,
    board: document.getElementById('board').value || null,
    url: document.getElementById('url').value.trim() || null,
    browser: document.getElementById('browser').value.trim() || null,
    priority: (form.querySelector('input[name="priority"]:checked') || {}).value || 'normal',
    email: document.getElementById('email').value.trim() || null,
    name: document.getElementById('name').value.trim() || null
  };

  // Submit
  btn.disabled = true;
  btn.textContent = 'Submitting...';

  fetch('/api/v1/feedback', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(function(res) { return res.json().then(function(data) { return { ok: res.ok, data: data }; }); })
  .then(function(result) {
    if (result.ok) {
      successEl.innerHTML = '<strong>Thank you!</strong> Your feedback has been received (reference: <strong>#' + (result.data.id || '—') + '</strong>). ' +
        'We appreciate you taking the time to help improve ashchan. ' +
        (payload.email ? 'We may reach out to you at the email you provided.' : 'Since no email was provided, this submission is fully anonymous.');
      successEl.style.display = 'block';
      form.reset();
      document.querySelectorAll('.category-card').forEach(function(c) { c.classList.remove('selected'); });
      document.querySelectorAll('.priority-option').forEach(function(p) { p.classList.remove('selected'); });
      var normalPri = document.querySelector('input[name="priority"][value="normal"]');
      if (normalPri) { normalPri.checked = true; normalPri.closest('.priority-option').classList.add('selected'); }
      document.getElementById('charCount').textContent = '0 / 5000';
      successEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
      errorEl.textContent = result.data.error || result.data.message || 'An error occurred. Please try again.';
      errorEl.style.display = 'block';
      errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  })
  .catch(function(err) {
    errorEl.textContent = 'Network error — please check your connection and try again.';
    errorEl.style.display = 'block';
    errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  })
  .finally(function() {
    btn.disabled = false;
    btn.textContent = 'Submit Feedback';
  });

  return false;
}
</script>

</body>
</html>
