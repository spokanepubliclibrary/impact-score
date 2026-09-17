<?php
/**
 * tag_report.php — Tag usage counts, filterable by team and date.
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php"); exit();
}
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$team_id    = isset($_GET['team_id']) && is_numeric($_GET['team_id']) ? (int)$_GET['team_id'] : 0;

$teams = $conn->query("SELECT id, name FROM teams ORDER BY name ASC");

$params = [$start_date, $end_date]; $types = "ss"; $team_clause = "";
if ($team_id > 0) { $team_clause = "AND s.team_id = ?"; $params[] = $team_id; $types .= "i"; }

$sql = "SELECT pt.name AS tag_name, COUNT(st.score_id) AS usage_count
        FROM program_tags pt
        LEFT JOIN score_tags st ON st.tag_id = pt.id
        LEFT JOIN scores s ON s.id = st.score_id AND s.program_date BETWEEN ? AND ? $team_clause
        GROUP BY pt.id, pt.name ORDER BY usage_count DESC, pt.name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params); $stmt->execute();
$tagRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$totalSql = "SELECT COUNT(*) AS cnt FROM scores s WHERE s.program_date BETWEEN ? AND ?" . ($team_id > 0 ? " AND s.team_id = ?" : "");
$tStmt = $conn->prepare($totalSql);
if ($team_id > 0) $tStmt->bind_param("ssi", $start_date, $end_date, $team_id);
else $tStmt->bind_param("ss", $start_date, $end_date);
$tStmt->execute();
$totalPrograms = $tStmt->get_result()->fetch_assoc()['cnt']; $tStmt->close();
$conn->close();

$maxCount = max(1, ...array_column($tagRows, 'usage_count'));
?><!DOCTYPE html><html lang="en"><head>
  <meta charset="UTF-8"><title>Tag Usage Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; padding: 30px; }
    .btn-custom { background-color: #480d3c; color: #fff; border: none; }
    .btn-custom:hover { background-color: #bb1b51; color: #fff; }
    .btn-secondary { background-color: #6c757d; color: #fff; border: none; }
    .tag-bar-wrap { background: #e9ecef; border-radius: 6px; height: 28px; }
    .tag-bar { background: #480d3c; border-radius: 6px; height: 28px; transition: width 0.4s; }
    .tag-row:hover .tag-bar { background: #bb1b51; }
    .summary-num { font-size: 2rem; font-weight: 700; color: #480d3c; }
    .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
  </style>
</head><body>
<div class="container-fluid" style="max-width:860px;">
  <a href="stats.html" class="btn btn-secondary mb-4">⬅️ Back to Reports</a>
  <h2 class="mb-1">🏷️ Tag Usage Report</h2>
  <p class="text-muted mb-4">How many program submissions used each tag within the selected filters.</p>

  <div class="card p-4 mb-4">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Start Date</label>
        <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">End Date</label>
        <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Team</label>
        <select name="team_id" class="form-select">
          <option value="0">All Teams</option>
          <?php if ($teams) while ($t = $teams->fetch_assoc()): ?>
            <option value="<?= $t['id'] ?>" <?= $team_id === (int)$t['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($t['name']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-custom px-4">Apply Filters</button>
        <a href="tag_report.php" class="btn btn-outline-secondary ms-2">Reset</a>
      </div>
    </form>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-4"><div class="card p-3 text-center">
      <div class="summary-num"><?= $totalPrograms ?></div><div class="text-muted small">Total Programs</div>
    </div></div>
    <div class="col-4"><div class="card p-3 text-center">
      <div class="summary-num"><?= array_sum(array_column($tagRows, 'usage_count')) ?></div><div class="text-muted small">Total Tag Uses</div>
    </div></div>
    <div class="col-4"><div class="card p-3 text-center">
      <div class="summary-num"><?= count(array_filter($tagRows, fn($r) => $r['usage_count'] > 0)) ?></div><div class="text-muted small">Tags Used</div>
    </div></div>
  </div>

  <div class="card p-4">
    <h5 class="mb-4">Tag Breakdown</h5>
    <?php if (empty($tagRows)): ?>
      <p class="text-muted">No tags found.</p>
    <?php else: ?>
      <?php foreach ($tagRows as $row):
          $pct = round(($row['usage_count'] / $maxCount) * 100); ?>
      <div class="tag-row mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="fw-semibold"><?= htmlspecialchars($row['tag_name']) ?></span>
          <span class="text-muted"><?= $row['usage_count'] ?></span>
        </div>
        <div class="tag-bar-wrap"><div class="tag-bar" style="width:<?= $pct ?>%"></div></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <p class="text-muted small mt-3">
    <?= htmlspecialchars($start_date) ?> – <?= htmlspecialchars($end_date) ?>
    · <?= $team_id > 0 ? 'filtered by team' : 'all teams' ?>
  </p>
</div></body></html>
