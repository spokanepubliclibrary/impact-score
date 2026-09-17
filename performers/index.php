<?php
session_start();
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Build search/filter query
$search      = trim($_GET['search'] ?? '');
$filterType  = trim($_GET['type'] ?? '');
$filterStatus = trim($_GET['status'] ?? 'active');
$filterTag   = (int)($_GET['tag'] ?? 0);

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "(p.stage_name LIKE ? OR p.organization_name LIKE ?)";
    $like     = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
if ($filterType !== '') {
    $where[]  = "p.performer_type = ?";
    $params[] = $filterType;
    $types   .= 's';
}
if ($filterStatus !== '') {
    $where[]  = "p.status = ?";
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($filterTag > 0) {
    $where[]  = "EXISTS (SELECT 1 FROM performer_tag_map ptm WHERE ptm.performer_id = p.performer_id AND ptm.tag_id = ?)";
    $params[] = $filterTag;
    $types   .= 'i';
}

$sql = "
    SELECT p.performer_id, p.stage_name, p.organization_name, p.performer_type,
           p.home_city, p.home_state, p.status, p.average_rating, p.total_reviews,
           p.virtual_programs_available,
           COUNT(DISTINCT pb.booking_id) AS booking_count
    FROM performers p
    LEFT JOIN performer_bookings pb ON p.performer_id = pb.performer_id
";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " GROUP BY p.performer_id ORDER BY p.stage_name ASC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$performers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch distinct performer types for filter dropdown
$types_res = $conn->query("SELECT DISTINCT performer_type FROM performers WHERE performer_type IS NOT NULL ORDER BY performer_type");
$performerTypes = $types_res->fetch_all(MYSQLI_ASSOC);

// Fetch tags for filter
$tags_res = $conn->query("SELECT tag_id, tag_name FROM tags ORDER BY tag_name");
$allTags = $tags_res->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Performer Directory</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; }
    .btn-custom { background-color: #480d3c; color: #fff; border-color: #480d3c; }
    .btn-custom:hover { background-color: #bb1b51; border-color: #bb1b51; color: #fff; }
    .page-header { background-color: #480d3c; color: #fff; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; }
    .rating-stars { color: #bb1b51; }
    .badge-status-active { background-color: #198754; }
    .badge-status-inactive { background-color: #6c757d; }
    .badge-status-pending { background-color: #ffc107; color: #000; }
    .badge-status-do_not_book { background-color: #dc3545; }
    .filter-card { background: #fff; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  </style>
</head>
<body>
<div class="container mt-4">
  <div style="margin-bottom: 1rem;">
    <a href="../index.php" class="btn btn-secondary btn-sm">&#8592; Dashboard</a>
    <?php if ($isAdmin): ?>
      <a href="../admin_portal.php" class="btn btn-secondary btn-sm ms-2">Admin Portal</a>
    <?php endif; ?>
  </div>

  <div class="page-header d-flex justify-content-between align-items-center">
    <div>
      <h2 class="mb-0">Performer Directory</h2>
      <small><?= count($performers) ?> performer<?= count($performers) != 1 ? 's' : '' ?> found</small>
    </div>
    <?php if ($isAdmin): ?>
      <a href="edit.php" class="btn btn-light">+ Add Performer</a>
    <?php endif; ?>
  </div>

  <!-- Filters -->
  <div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label mb-1 small fw-bold">Search</label>
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Name or organization..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach ($performerTypes as $pt): ?>
            <option value="<?= htmlspecialchars($pt['performer_type']) ?>" <?= $filterType === $pt['performer_type'] ? 'selected' : '' ?>>
              <?= htmlspecialchars(ucfirst($pt['performer_type'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
          <option value="do_not_book" <?= $filterStatus === 'do_not_book' ? 'selected' : '' ?>>Do Not Book</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-bold">Tag</label>
        <select name="tag" class="form-select form-select-sm">
          <option value="">All Tags</option>
          <?php foreach ($allTags as $tag): ?>
            <option value="<?= $tag['tag_id'] ?>" <?= $filterTag === (int)$tag['tag_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($tag['tag_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-custom btn-sm w-100">Filter</button>
      </div>
    </form>
  </div>

  <!-- Performer Table -->
  <?php if (empty($performers)): ?>
    <div class="alert alert-info">No performers found. <?php if ($isAdmin): ?><a href="edit.php">Add the first performer.</a><?php endif; ?></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover bg-white rounded shadow-sm">
      <thead style="background-color: #480d3c; color: #fff;">
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>Location</th>
          <th>Rating</th>
          <th>Bookings</th>
          <th>Virtual</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($performers as $p): ?>
          <?php
            $statusClass = 'badge-status-' . str_replace(' ', '_', $p['status']);
            $stars = '';
            if ($p['average_rating']) {
                $full = floor($p['average_rating']);
                for ($i = 0; $i < $full; $i++) $stars .= '&#9733;';
                for ($i = $full; $i < 5; $i++) $stars .= '&#9734;';
            }
            $location = array_filter([$p['home_city'], $p['home_state']]);
          ?>
          <tr>
            <td>
              <strong><a href="view.php?id=<?= $p['performer_id'] ?>" class="text-decoration-none" style="color:#480d3c;"><?= htmlspecialchars($p['stage_name']) ?></a></strong>
              <?php if ($p['organization_name']): ?>
                <br><small class="text-muted"><?= htmlspecialchars($p['organization_name']) ?></small>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(ucfirst($p['performer_type'] ?? '—')) ?></td>
            <td><?= htmlspecialchars(implode(', ', $location) ?: '—') ?></td>
            <td>
              <?php if ($p['average_rating']): ?>
                <span class="rating-stars small"><?= $stars ?></span>
                <span class="small text-muted"><?= number_format($p['average_rating'], 1) ?> (<?= $p['total_reviews'] ?>)</span>
              <?php else: ?>
                <span class="text-muted small">No reviews</span>
              <?php endif; ?>
            </td>
            <td><?= (int)$p['booking_count'] ?></td>
            <td><?= $p['virtual_programs_available'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
            <td><span class="badge <?= $statusClass ?>"><?= ucfirst(str_replace('_', ' ', $p['status'])) ?></span></td>
            <td>
              <a href="view.php?id=<?= $p['performer_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
              <?php if ($isAdmin): ?>
                <a href="edit.php?id=<?= $p['performer_id'] ?>" class="btn btn-sm btn-custom">Edit</a>
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
