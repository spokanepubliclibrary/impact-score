<?php
session_start();
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$filterAudience  = trim($_GET['audience'] ?? '');
$filterFormat    = trim($_GET['format'] ?? '');
$filterPerformer = (int)($_GET['performer_id'] ?? 0);
$filterTag       = (int)($_GET['tag'] ?? 0);
$filterVirtual   = isset($_GET['virtual']) ? 1 : -1;
$maxFee          = ($_GET['max_fee'] ?? '') !== '' ? (float)$_GET['max_fee'] : null;

$where  = ["pp.active = 1", "p.status = 'active'"];
$params = [];
$types  = '';

if ($filterAudience !== '') { $where[] = "pp.audience = ?"; $params[] = $filterAudience; $types .= 's'; }
if ($filterFormat !== '') { $where[] = "pp.program_format = ?"; $params[] = $filterFormat; $types .= 's'; }
if ($filterPerformer > 0) { $where[] = "pp.performer_id = ?"; $params[] = $filterPerformer; $types .= 'i'; }
if ($filterVirtual === 1) { $where[] = "pp.virtual_available = 1"; }
if ($maxFee !== null) { $where[] = "(pp.base_fee IS NULL OR pp.base_fee <= ?)"; $params[] = $maxFee; $types .= 'd'; }
if ($filterTag > 0) {
    $where[] = "EXISTS (SELECT 1 FROM program_tag_map ptm WHERE ptm.program_id=pp.program_id AND ptm.tag_id=?)";
    $params[] = $filterTag; $types .= 'i';
}

$sql = "
    SELECT pp.*, p.stage_name, p.performer_id, p.home_city, p.home_state
    FROM performer_programs pp
    JOIN performers p ON pp.performer_id=p.performer_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY pp.program_title ASC
";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$performers = $conn->query("SELECT performer_id, stage_name FROM performers WHERE status='active' ORDER BY stage_name")->fetch_all(MYSQLI_ASSOC);
$allTags    = $conn->query("SELECT tag_id, tag_name FROM tags ORDER BY tag_name")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Program Catalog — Performers</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; }
    .btn-custom { background-color: #480d3c; color: #fff; border-color: #480d3c; }
    .btn-custom:hover { background-color: #bb1b51; border-color: #bb1b51; color: #fff; }
    .page-header { background-color: #480d3c; color: #fff; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; }
    .filter-card { background: #fff; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .program-card { background: #fff; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); transition: box-shadow .2s; }
    .program-card:hover { box-shadow: 0 3px 10px rgba(0,0,0,.15); }
  </style>
</head>
<body>
<div class="container mt-4">
  <div class="mb-3">
    <a href="index.php" class="btn btn-secondary btn-sm">&#8592; Performer Directory</a>
    <a href="booking_list.php" class="btn btn-outline-secondary btn-sm ms-2">Booking List</a>
  </div>

  <div class="page-header">
    <h2 class="mb-0">Program Catalog</h2>
    <small><?= count($programs) ?> program<?= count($programs) != 1 ? 's' : '' ?> available</small>
  </div>

  <div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">Audience</label>
        <select name="audience" class="form-select form-select-sm">
          <option value="">All Audiences</option>
          <?php foreach (['early_learning','kids','teens','adults','all_ages'] as $aud): ?>
            <option value="<?= $aud ?>" <?= $filterAudience === $aud ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$aud)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">Format</label>
        <select name="format" class="form-select form-select-sm">
          <option value="">All Formats</option>
          <?php foreach (['performance','workshop','lecture','interactive','other'] as $fmt): ?>
            <option value="<?= $fmt ?>" <?= $filterFormat === $fmt ? 'selected' : '' ?>><?= ucfirst($fmt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 small fw-bold">Performer</label>
        <select name="performer_id" class="form-select form-select-sm">
          <option value="">All Performers</option>
          <?php foreach ($performers as $perf): ?>
            <option value="<?= $perf['performer_id'] ?>" <?= $filterPerformer == $perf['performer_id'] ? 'selected' : '' ?>><?= htmlspecialchars($perf['stage_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">Tag</label>
        <select name="tag" class="form-select form-select-sm">
          <option value="">All Tags</option>
          <?php foreach ($allTags as $tag): ?>
            <option value="<?= $tag['tag_id'] ?>" <?= $filterTag == $tag['tag_id'] ? 'selected' : '' ?>><?= htmlspecialchars($tag['tag_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label mb-1 small fw-bold">Max $</label>
        <input type="number" name="max_fee" step="50" min="0" class="form-control form-control-sm" placeholder="Any" value="<?= htmlspecialchars($_GET['max_fee'] ?? '') ?>">
      </div>
      <div class="col-md-1 d-flex align-items-end pb-1">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="virtual" id="virt" <?= $filterVirtual === 1 ? 'checked' : '' ?>>
          <label class="form-check-label small" for="virt">Virtual</label>
        </div>
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-custom btn-sm w-100">Filter</button>
      </div>
    </form>
  </div>

  <?php if (empty($programs)): ?>
    <div class="alert alert-info">No programs found. <a href="index.php">Browse performers</a> to add programs.</div>
  <?php else: ?>
    <?php foreach ($programs as $prog): ?>
      <div class="program-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <h5 class="mb-1"><?= htmlspecialchars($prog['program_title']) ?></h5>
            <div>
              <a href="view.php?id=<?= $prog['performer_id'] ?>" style="color:#480d3c;"><?= htmlspecialchars($prog['stage_name']) ?></a>
              <?php if ($prog['home_city']): ?>
                <span class="text-muted small"> — <?= htmlspecialchars(implode(', ', array_filter([$prog['home_city'], $prog['home_state']]))) ?></span>
              <?php endif; ?>
            </div>
            <div class="mt-1">
              <span class="badge bg-secondary"><?= ucfirst(str_replace('_',' ',$prog['audience'])) ?></span>
              <span class="badge bg-secondary"><?= ucfirst($prog['program_format']) ?></span>
              <?php if ($prog['virtual_available']): ?><span class="badge bg-info">Virtual OK</span><?php endif; ?>
              <?php if ($prog['duration_minutes']): ?><span class="badge bg-light text-dark"><?= $prog['duration_minutes'] ?> min</span><?php endif; ?>
              <?php if ($prog['capacity_max']): ?><span class="badge bg-light text-dark">Up to <?= $prog['capacity_max'] ?></span><?php endif; ?>
            </div>
            <?php if ($prog['program_description']): ?><p class="mt-2 mb-0 small"><?= htmlspecialchars($prog['program_description']) ?></p><?php endif; ?>
          </div>
          <div class="text-end">
            <?php if ($prog['base_fee'] !== null): ?>
              <div class="fs-5" style="color:#480d3c;">$<?= number_format($prog['base_fee'], 2) ?></div>
              <?php if ($prog['travel_fee']): ?><div class="small text-muted">+ $<?= number_format($prog['travel_fee'], 2) ?> travel</div><?php endif; ?>
            <?php else: ?>
              <div class="text-muted small">Fee: contact</div>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
              <a href="booking_edit.php?performer_id=<?= $prog['performer_id'] ?>" class="btn btn-custom btn-sm mt-2">Book</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</body>
</html>
