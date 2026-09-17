<?php
session_start();
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$filterStatus    = trim($_GET['status'] ?? '');
$filterPerformer = (int)($_GET['performer_id'] ?? 0);
$filterBranch    = trim($_GET['branch'] ?? '');
$dateFrom        = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo          = trim($_GET['date_to'] ?? date('Y-m-t', strtotime('+3 months')));

$where  = ["pb.event_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];
$types  = 'ss';

if ($filterStatus !== '') { $where[] = "pb.booking_status = ?"; $params[] = $filterStatus; $types .= 's'; }
if ($filterPerformer > 0) { $where[] = "pb.performer_id = ?"; $params[] = $filterPerformer; $types .= 'i'; }
if ($filterBranch !== '') { $where[] = "pb.branch_name LIKE ?"; $params[] = "%{$filterBranch}%"; $types .= 's'; }

$sql = "SELECT pb.*, p.stage_name, pp.program_title
        FROM performer_bookings pb
        JOIN performers p ON pb.performer_id=p.performer_id
        LEFT JOIN performer_programs pp ON pb.program_id=pp.program_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY pb.event_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$performers = $conn->query("SELECT performer_id, stage_name FROM performers ORDER BY stage_name")->fetch_all(MYSQLI_ASSOC);
$conn->close();

$statusColors = ['inquiry'=>'secondary','tentative'=>'warning','confirmed'=>'primary','completed'=>'success','cancelled'=>'danger','no_show'=>'dark'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking List — Performers</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; }
    .btn-custom { background-color: #480d3c; color: #fff; border-color: #480d3c; }
    .btn-custom:hover { background-color: #bb1b51; border-color: #bb1b51; color: #fff; }
    .page-header { background-color: #480d3c; color: #fff; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; }
    .filter-card { background: #fff; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  </style>
</head>
<body>
<div class="container mt-4">
  <div class="mb-3">
    <a href="index.php" class="btn btn-secondary btn-sm">&#8592; Performer Directory</a>
    <?php if ($isAdmin): ?>
      <a href="booking_edit.php" class="btn btn-custom btn-sm ms-2">+ New Booking</a>
    <?php endif; ?>
  </div>

  <div class="page-header d-flex justify-content-between align-items-center">
    <div>
      <h2 class="mb-0">Booking List</h2>
      <small><?= count($bookings) ?> booking<?= count($bookings) != 1 ? 's' : '' ?></small>
    </div>
  </div>

  <div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">From</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">To</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>">
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
        <label class="form-label mb-1 small fw-bold">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['inquiry','tentative','confirmed','completed','cancelled','no_show'] as $s): ?>
            <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">Branch</label>
        <input type="text" name="branch" class="form-control form-control-sm" placeholder="Branch name…" value="<?= htmlspecialchars($filterBranch) ?>">
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-custom btn-sm w-100">Go</button>
      </div>
    </form>
  </div>

  <?php if (empty($bookings)): ?>
    <div class="alert alert-info">No bookings found for these filters.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover bg-white rounded shadow-sm">
        <thead style="background-color: #480d3c; color: #fff;">
          <tr><th>Date</th><th>Performer</th><th>Event</th><th>Branch</th><th>Program</th><th>Fee</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $b): ?>
            <tr>
              <td><?= htmlspecialchars($b['event_date']) ?><?php if ($b['start_time']): ?><br><small class="text-muted"><?= substr($b['start_time'],0,5) ?></small><?php endif; ?></td>
              <td><a href="view.php?id=<?= $b['performer_id'] ?>" style="color:#480d3c;"><?= htmlspecialchars($b['stage_name']) ?></a></td>
              <td><?= htmlspecialchars($b['event_title']) ?></td>
              <td><?= htmlspecialchars($b['branch_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($b['program_title'] ?? '—') ?></td>
              <td><?= $b['total_cost'] !== null ? '$' . number_format($b['total_cost'], 2) : '—' ?></td>
              <td><span class="badge bg-<?= $statusColors[$b['booking_status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$b['booking_status'])) ?></span></td>
              <td>
                <a href="booking_view.php?id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                <?php if ($isAdmin): ?>
                  <a href="booking_edit.php?id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-custom">Edit</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
