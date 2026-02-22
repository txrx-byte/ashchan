<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Report Post</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body {
  font-family: arial, helvetica, sans-serif;
  font-size: 10pt;
  margin: 0;
  padding: 8px;
  background: #eef2ff;
}
fieldset {
  border: 1px solid #aaa;
  padding: 6px 10px;
  margin: 0 0 8px 0;
}
legend {
  font-weight: bold;
  font-size: 10pt;
}
label {
  display: block;
  margin: 3px 0;
  font-size: 10pt;
}
label input[type="radio"] {
  vertical-align: middle;
  margin-right: 4px;
}
select {
  font-size: 10pt;
  margin-left: 18px;
}
.submit-row {
  margin-top: 8px;
  text-align: center;
}
.submit-row input[type="submit"] {
  font-size: 10pt;
  padding: 2px 12px;
}
.error {
  color: red;
  font-weight: bold;
  margin: 6px 0;
}
.success {
  color: green;
  font-weight: bold;
  margin: 6px 0;
  text-align: center;
}
.info {
  font-size: 9pt;
  color: #666;
  margin: 4px 0;
}
altcha-widget {
  display: block;
  margin: 8px 0;
  max-width: 260px;
}
</style>
</head>
<body>
<div id="report-content">
<?php if (!empty($success)): ?>
  <div class="success">Report submitted!</div>
  <div class="submit-row"><input type="button" value="Close" onclick="closeReport()"></div>
  <script>
  function closeReport() {
    if (window.opener) { window.opener.postMessage('done-report', '*'); }
    window.close();
  }
  // Auto-close after 2 seconds
  setTimeout(closeReport, 2000);
  </script>
<?php elseif (!empty($error)): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <p class="info"><a href="javascript:history.back()">Go back</a></p>
<?php else: ?>
  <form method="POST" action="/report/<?= htmlspecialchars($board) ?>/<?= (int) $post_no ?>" id="reportForm">
    <input type="hidden" name="board" value="<?= htmlspecialchars($board) ?>">
    <input type="hidden" name="no" value="<?= (int) $post_no ?>">
    <fieldset>
      <legend>Report Post No.<?= (int) $post_no ?></legend>
      <label>
        <input type="radio" name="cat" value="rule" checked onchange="toggleCat()">
        This post violates a rule.
      </label>
      <select name="cat_id" id="catSelect">
        <?php foreach (($categories['rule'] ?? []) as $cat): ?>
        <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['title']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($categories['illegal'])): ?>
      <label style="margin-top: 6px;">
        <input type="radio" name="cat" value="illegal" onchange="toggleCat()">
        This post violates United States law.
      </label>
      <?php endif; ?>
    </fieldset>

    <altcha-widget
      challengeurl="/api/v1/altcha/challenge"
      hidefooter
      hidelogo
    ></altcha-widget>

    <div class="submit-row">
      <input type="submit" value="Submit">
    </div>
  </form>

  <script>
  function toggleCat() {
    var sel = document.getElementById('catSelect');
    var checked = document.querySelector('input[name="cat"]:checked');
    sel.disabled = (checked && checked.value === 'illegal');
  }
  </script>
  <script src="/static/js/altcha.min.js" defer></script>
<?php endif; ?>
</div>
</body>
</html>
