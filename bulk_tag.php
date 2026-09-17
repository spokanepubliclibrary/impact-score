<?php
/**
 * bulk_tag.php
 * Filter programs by team, staff, keyword; select rows; bulk add or remove tags.
 * Available to all users.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', 1);
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->query("SET SESSION group_concat_max_len = 4096");

// ── Bulk tag save ────────────────────────────────────────────────────────────
$saveMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_tags'])) {
    $scoreIds = array_map('intval', (array)($_POST['score_ids'] ?? []));
    $tagIds   = array_map('intval', (array)($_POST['tag_ids']   ?? []));
    $action   = $_POST['tag_action'] ?? 'add';
    if (!empty($scoreIds) && !empty($tagIds)) {
        $affected = 0;
        foreach ($scoreIds as $sid) {
            foreach ($tagIds as $tid) {
                if ($sid <= 0 || $tid <= 0) continue;
                $st = $action === 'remove'
                    ? $conn->prepare("DELETE FROM score_tags WHERE score_id = ? AND tag_id = ?")
                    : $conn->prepare("INSERT IGNORE INTO score_tags (score_id, tag_id) VALUES (?, ?)");
                $st->bind_param("ii", $sid, $tid); $st->execute();
                $affected += $st->affected_rows; $st->close();
            }
        }
        $verb = $action === 'remove' ? 'removed from' : 'added to';
        $saveMessage = "✅ " . count($tagIds) . " tag(s) $verb " . count($scoreIds) . " program(s). ($affected changes)";
    } else {
        $saveMessage = "⚠️ Select at least one program and one tag.";
    }
}

// ── Reference data ───────────────────────────────────────────────────────────
$users   = $conn->query("SELECT id, name FROM users ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$teams   = $conn->query("SELECT id, name FROM teams ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$allTags = $conn->query("SELECT id, name FROM program_tags ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// ── Filter query ─────────────────────────────────────────────────────────────
$where = []; $params = []; $types = "";

if (!empty($_GET['user_id'])) {
    $uid = (int)$_GET['user_id'];
    $where[] = "(s.id IN (SELECT score_id FROM score_users WHERE user_id = ?) OR s.user_id = ?)";
    $params[] = $uid; $params[] = $uid; $types .= "ii";
}
if (!empty($_GET['team_id'])) {
    $where[] = "s.team_id = ?"; $params[] = (int)$_GET['team_id']; $types .= "i";
}
if (!empty($_GET['keyword'])) {
    $where[] = "s.program LIKE ?"; $params[] = "%" . $_GET['keyword'] . "%"; $types .= "s";
}
if (!empty($_GET['start_date'])) {
    $where[] = "s.program_date >= ?"; $params[] = $_GET['start_date']; $types .= "s";
}
if (!empty($_GET['end_date'])) {
    $where[] = "s.program_date <= ?"; $params[] = $_GET['end_date']; $types .= "s";
}

// Unreviewed only (default ON)
$unreviewedOnly = !isset($_GET['_filtered']) || !empty($_GET['unreviewed_only']);
if ($unreviewedOnly) {
    $where[] = "s.id NOT IN (SELECT score_id FROM score_tags)";
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

$scores = null;
if (isset($_GET['_filtered'])) {
    $sql = "SELECT s.id, s.program, s.program_date,
                   t.name AS team_name,
                   GROUP_CONCAT(DISTINCT u.name  ORDER BY u.name  SEPARATOR ', ') AS staff,
                   GROUP_CONCAT(DISTINCT pt.name ORDER BY pt.name SEPARATOR ', ') AS tags
            FROM scores s
            LEFT JOIN teams        t  ON t.id  = s.team_id
            LEFT JOIN score_users  su ON su.score_id = s.id
            LEFT JOIN users        u  ON u.id  = su.user_id
            LEFT JOIN score_tags   st ON st.score_id = s.id
            LEFT JOIN program_tags pt ON pt.id = st.tag_id
            $whereSQL
            GROUP BY s.id ORDER BY s.program_date DESC LIMIT 100";
    if ($types) {
        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params);
        $stmt->execute(); $scores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    } else {
        $scores = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }
}

$conn->close();

$getQS = http_build_query(array_filter([
    'user_id'         => $_GET['user_id']         ?? '',
    'team_id'         => $_GET['team_id']          ?? '',
    'keyword'         => $_GET['keyword']          ?? '',
    'start_date'      => $_GET['start_date']       ?? '',
    'end_date'        => $_GET['end_date']         ?? '',
    'unreviewed_only' => $unreviewedOnly ? '1' : '',
    '_filtered'       => '1',
]));
?><!DOCTYPE html><html lang="en"><head>
  <meta charset="UTF-8"><title>Bulk Tag Programs</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; padding: 24px; }
    .btn-custom { background-color: #480d3c; color: #fff; border: none; }
    .btn-custom:hover { background-color: #bb1b51; color: #fff; }
    th { background-color: #480d3c; color: #fff; }
    .tag-chip { display:inline-block; background:#e0d4db; color:#480d3c; border-radius:12px;
                padding:1px 8px; font-size:.78rem; margin:1px; font-weight:600; }
    .table tbody tr:hover { background: #f0e8ee; }
    .filter-card, .action-card { background:#fff; border-radius:8px; padding:20px;
                                  margin-bottom:20px; box-shadow:0 2px 6px rgba(0,0,0,.07); }
  </style>
</head><body>
<div class="container-fluid" style="max-width:1100px;">
  <a href="index.php" class="btn btn-secondary mb-3" style="background:#6c757d;color:#fff;border:none;">⬅️ Back to Dashboard</a>
  <h2 class="mb-1">🏷️ Bulk Tag Programs</h2>
  <p class="text-muted mb-4">Filter programs, select rows, then add or remove tags in bulk.</p>

  <?php if ($saveMessage): ?>
    <div class="alert <?= str_starts_with($saveMessage, '✅') ? 'alert-success' : 'alert-warning' ?>">
      <?= htmlspecialchars($saveMessage) ?>
    </div>
  <?php endif; ?>

  <!-- Filter Form -->
  <div class="filter-card">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-sm-6 col-md-2">
        <label class="form-label fw-semibold">Staff Member</label>
        <select name="user_id" class="form-select">
          <option value="">All Staff</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= ($_GET['user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label fw-semibold">Team</label>
        <select name="team_id" class="form-select">
          <option value="">All Teams</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($_GET['team_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($t['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label fw-semibold">Keyword</label>
        <input type="text" name="keyword" class="form-control"
               placeholder="e.g. storytime" value="<?= htmlspecialchars($_GET['keyword'] ?? '') ?>">
      </div>
      <div class="col-sm-3 col-md-2">
        <label class="form-label fw-semibold">From</label>
        <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
      </div>
      <div class="col-sm-3 col-md-2">
        <label class="form-label fw-semibold">To</label>
        <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label">&nbsp;</label>
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" name="unreviewed_only" id="unreviewed_only"
                 value="1" <?= $unreviewedOnly ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold text-danger" for="unreviewed_only">Unreviewed only</label>
        </div>
      </div>
      <input type="hidden" name="_filtered" value="1">
      <div class="col-sm-6 col-md-1 d-flex align-items-end">
        <button type="submit" class="btn btn-custom w-100">Filter</button>
      </div>
      <div class="col-sm-6 col-md-1 d-flex align-items-end">
        <a href="bulk_tag.php" class="btn btn-outline-secondary w-100">Reset</a>
      </div>
    </form>
  </div>

  <?php if ($scores === null): ?>
    <div class="text-center text-muted py-5">Use the filters above to find programs, then select and tag them.</div>
  <?php elseif (empty($scores)): ?>
    <div class="alert alert-info">No programs match those filters<?= $unreviewedOnly ? ' (all may already be tagged)' : '' ?>.</div>
  <?php else: ?>

  <form method="post" action="bulk_tag.php<?= $getQS ? '?' . $getQS : '' ?>">
    <div class="action-card">
      <div class="row g-3 align-items-end">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Tags to Apply / Remove</label>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($allTags as $tag): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="tag_ids[]"
                       id="atag_<?= $tag['id'] ?>" value="<?= $tag['id'] ?>">
                <label class="form-check-label" for="atag_<?= $tag['id'] ?>">
                  <?= htmlspecialchars($tag['name']) ?>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Action</label>
          <div class="d-flex gap-3">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="tag_action" id="action_add" value="add" checked>
              <label class="form-check-label" for="action_add">Add</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="tag_action" id="action_remove" value="remove">
              <label class="form-check-label" for="action_remove">Remove</label>
            </div>
          </div>
        </div>
        <div class="col-sm-3 d-flex align-items-end gap-2">
          <button type="submit" name="apply_tags" class="btn btn-custom px-4">Apply to Selected</button>
          <span class="text-muted small" id="selCount">0 selected</span>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-sm bg-white">
        <thead>
          <tr>
            <th style="width:36px;"><input type="checkbox" id="selectAll" title="Select all"></th>
            <th>ID</th><th>Program</th><th>Date</th><th>Team</th><th>Staff</th><th>Current Tags</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($scores as $row): ?>
          <tr>
            <td class="text-center">
              <input type="checkbox" name="score_ids[]" value="<?= $row['id'] ?>" class="row-check">
            </td>
            <td class="text-muted small"><?= $row['id'] ?></td>
            <td><?= htmlspecialchars($row['program']) ?></td>
            <td><?= htmlspecialchars($row['program_date']) ?></td>
            <td><?= htmlspecialchars($row['team_name'] ?? '') ?></td>
            <td class="small"><?= htmlspecialchars($row['staff'] ?? '') ?></td>
            <td>
              <?php if ($row['tags']): foreach (explode(', ', $row['tags']) as $chip):
                echo '<span class="tag-chip">' . htmlspecialchars($chip) . '</span> ';
              endforeach; else: echo '<span class="text-muted small">—</span>'; endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted small">
      Showing <?= count($scores) ?> record(s)<?= $unreviewedOnly ? ' · <strong>unreviewed only</strong>' : '' ?> — max 100 per query.
    </p>
  </form>

  <?php endif; ?>
</div>
<script>
const selectAll = document.getElementById('selectAll');
const selCount  = document.getElementById('selCount');
function updateCount() {
    const n = document.querySelectorAll('.row-check:checked').length;
    if (selCount) selCount.textContent = n + ' selected';
}
if (selectAll) {
    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        updateCount();
    });
}
document.querySelectorAll('.row-check').forEach(cb => {
    cb.addEventListener('change', () => {
        updateCount();
        if (!cb.checked && selectAll) selectAll.checked = false;
    });
});
</script>
</body></html>
