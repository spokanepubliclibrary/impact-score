<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$msg = '';

// ── ADD FIELD ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_field') {
    $label   = trim($_POST['field_label'] ?? '');
    $key     = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($_POST['field_key'] ?? '')));
    $desc    = trim($_POST['description'] ?? '');
    $req     = isset($_POST['is_required']) ? 1 : 0;
    $order   = (int)($_POST['display_order'] ?? 0);

    if ($label === '' || $key === '') {
        $msg = 'error:Label and key are required.';
    } else {
        $stmt = $conn->prepare("INSERT INTO custom_fields (field_label, field_key, description, is_required, display_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssii", $label, $key, $desc, $req, $order);
        if ($stmt->execute()) {
            $msg = 'success:Field created.';
        } else {
            $msg = 'error:Could not create field (key may already exist): ' . $stmt->error;
        }
        $stmt->close();
    }
}

// ── EDIT FIELD ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_field') {
    $id      = (int)$_POST['field_id'];
    $label   = trim($_POST['field_label'] ?? '');
    $desc    = trim($_POST['description'] ?? '');
    $req     = isset($_POST['is_required']) ? 1 : 0;
    $active  = isset($_POST['is_active']) ? 1 : 0;
    $order   = (int)($_POST['display_order'] ?? 0);

    $stmt = $conn->prepare("UPDATE custom_fields SET field_label=?, description=?, is_required=?, is_active=?, display_order=? WHERE field_id=?");
    $stmt->bind_param("ssiiii", $label, $desc, $req, $active, $order, $id);
    $stmt->execute() ? $msg = 'success:Field updated.' : $msg = 'error:' . $stmt->error;
    $stmt->close();

    // Team filters
    $delTF = $conn->prepare("DELETE FROM custom_field_team_filter WHERE field_id=?");
    $delTF->bind_param("i", $id);
    $delTF->execute();
    $delTF->close();
    if (!empty($_POST['team_filters']) && is_array($_POST['team_filters'])) {
        $insTF = $conn->prepare("INSERT IGNORE INTO custom_field_team_filter (field_id, team_id) VALUES (?, ?)");
        foreach ($_POST['team_filters'] as $tid) {
            $tid = (int)$tid;
            $insTF->bind_param("ii", $id, $tid);
            $insTF->execute();
        }
        $insTF->close();
    }

    // Form filters
    $delFF = $conn->prepare("DELETE FROM custom_field_form_filter WHERE field_id=?");
    $delFF->bind_param("i", $id);
    $delFF->execute();
    $delFF->close();
    if (!empty($_POST['form_filters']) && is_array($_POST['form_filters'])) {
        $insFF = $conn->prepare("INSERT IGNORE INTO custom_field_form_filter (field_id, form_id) VALUES (?, ?)");
        foreach ($_POST['form_filters'] as $fid) {
            $fid = (int)$fid;
            $insFF->bind_param("ii", $id, $fid);
            $insFF->execute();
        }
        $insFF->close();
    }
}

// ── DELETE FIELD ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_field') {
    $id = (int)$_POST['field_id'];
    $stmt = $conn->prepare("DELETE FROM custom_fields WHERE field_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute() ? $msg = 'success:Field deleted.' : $msg = 'error:' . $stmt->error;
    $stmt->close();
}

// ── ADD OPTION ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_option') {
    $field_id = (int)$_POST['field_id'];
    $label    = trim($_POST['option_label'] ?? '');
    $order    = (int)($_POST['option_order'] ?? 0);
    if ($label !== '') {
        $stmt = $conn->prepare("INSERT INTO custom_field_options (field_id, option_label, display_order) VALUES (?, ?, ?)");
        $stmt->bind_param("isi", $field_id, $label, $order);
        $stmt->execute() ? $msg = 'success:Option added.' : $msg = 'error:' . $stmt->error;
        $stmt->close();
    }
}

// ── EDIT OPTION ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_option') {
    $opt_id = (int)$_POST['option_id'];
    $label  = trim($_POST['option_label'] ?? '');
    $order  = (int)($_POST['option_order'] ?? 0);
    $stmt = $conn->prepare("UPDATE custom_field_options SET option_label=?, display_order=? WHERE option_id=?");
    $stmt->bind_param("sii", $label, $order, $opt_id);
    $stmt->execute() ? $msg = 'success:Option updated.' : $msg = 'error:' . $stmt->error;
    $stmt->close();
}

// ── DELETE OPTION ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_option') {
    $opt_id = (int)$_POST['option_id'];
    $stmt = $conn->prepare("DELETE FROM custom_field_options WHERE option_id=?");
    $stmt->bind_param("i", $opt_id);
    $stmt->execute() ? $msg = 'success:Option deleted.' : $msg = 'error:' . $stmt->error;
    $stmt->close();
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────
$fields = [];
$res = $conn->query("SELECT * FROM custom_fields ORDER BY display_order ASC, field_id ASC");
while ($row = $res->fetch_assoc()) {
    $fields[] = $row;
}

// Options keyed by field_id
$all_options = [];
$res2 = $conn->query("SELECT * FROM custom_field_options ORDER BY display_order ASC, option_id ASC");
while ($row = $res2->fetch_assoc()) {
    $all_options[(int)$row['field_id']][] = $row;
}

// Team filters keyed by field_id
$field_team_filters = [];
$res3 = $conn->query("SELECT field_id, team_id FROM custom_field_team_filter");
while ($row = $res3->fetch_assoc()) {
    $field_team_filters[(int)$row['field_id']][] = (int)$row['team_id'];
}

// Form filters keyed by field_id
$field_form_filters = [];
$res4 = $conn->query("SELECT field_id, form_id FROM custom_field_form_filter");
while ($row = $res4->fetch_assoc()) {
    $field_form_filters[(int)$row['field_id']][] = (int)$row['form_id'];
}

// All teams for filter UI
$teams = [];
$res5 = $conn->query("SELECT id, name FROM teams ORDER BY name ASC");
while ($row = $res5->fetch_assoc()) $teams[] = $row;

// All form profiles for filter UI
$form_profiles = [];
$res6 = $conn->query("SELECT fp.id, fp.name, t.name AS team_name FROM form_profiles fp JOIN teams t ON fp.team_id = t.id ORDER BY t.name, fp.name");
while ($row = $res6->fetch_assoc()) $form_profiles[] = $row;

$conn->close();

// Parse message
$alert_type = '';
$alert_text = '';
if ($msg) {
    [$alert_type, $alert_text] = explode(':', $msg, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Custom Dropdown Fields</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Montserrat', Arial, sans-serif; background: #f5f2ec; }
    .btn-plum  { background: #480d3c; color: #fff; border: none; }
    .btn-plum:hover { background: #bb1b51; color: #fff; }
    .card { border-radius: 8px; }
    .field-card { border-left: 4px solid #480d3c; }
    .field-card.inactive { border-left-color: #aaa; opacity: .75; }
    .badge-team { background: #480d3c; }
    .badge-form { background: #bb1b51; }
    .section-hint { font-size: .82rem; color: #666; }
    details summary { cursor: pointer; font-weight: 500; }
  </style>
</head>
<body>
<div class="container py-4">
  <a href="admin_portal.php" class="btn btn-secondary mb-3">⬅ Back to Admin Portal</a>
  <h2>Manage Custom Dropdown Fields</h2>
  <p class="text-muted">Create dropdown fields that appear on the score submission form (e.g. "Age Group"). Options, team restrictions, and form restrictions are all configurable per field.</p>

  <?php if ($alert_type === 'success'): ?>
    <div class="alert alert-success"><?= htmlspecialchars($alert_text) ?></div>
  <?php elseif ($alert_type === 'error'): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($alert_text) ?></div>
  <?php endif; ?>

  <!-- ── ADD NEW FIELD ─────────────────────────────────────────────────── -->
  <div class="card p-4 mb-4 shadow-sm">
    <h5>Add New Field</h5>
    <form method="post">
      <input type="hidden" name="action" value="add_field">
      <div class="row g-2">
        <div class="col-md-4">
          <label class="form-label">Field Label <span class="text-danger">*</span></label>
          <input name="field_label" class="form-control" placeholder="e.g. Age Group" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Field Key <span class="text-danger">*</span> <span class="section-hint">(unique, snake_case)</span></label>
          <input name="field_key" class="form-control" placeholder="e.g. age_group" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Description</label>
          <input name="description" class="form-control" placeholder="Optional hint text">
        </div>
        <div class="col-md-1">
          <label class="form-label">Order</label>
          <input name="display_order" type="number" class="form-control" value="10">
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_required" id="add_req">
            <label class="form-check-label" for="add_req">Required</label>
          </div>
        </div>
      </div>
      <button type="submit" class="btn btn-plum mt-3">Add Field</button>
    </form>
  </div>

  <!-- ── EXISTING FIELDS ───────────────────────────────────────────────── -->
  <?php if (empty($fields)): ?>
    <div class="alert alert-info">No custom fields yet. Add one above.</div>
  <?php endif; ?>

  <?php foreach ($fields as $field):
    $fid      = (int)$field['field_id'];
    $opts     = $all_options[$fid] ?? [];
    $tf       = $field_team_filters[$fid] ?? [];
    $ff       = $field_form_filters[$fid] ?? [];
    $inactive = !$field['is_active'];
  ?>
  <div class="card mb-4 shadow-sm field-card <?= $inactive ? 'inactive' : '' ?>">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h5 class="mb-0"><?= htmlspecialchars($field['field_label']) ?>
            <?php if ($inactive): ?><span class="badge bg-secondary ms-1">Inactive</span><?php endif; ?>
            <?php if ($field['is_required']): ?><span class="badge bg-warning text-dark ms-1">Required</span><?php endif; ?>
          </h5>
          <small class="text-muted">Key: <code><?= htmlspecialchars($field['field_key']) ?></code> · Order: <?= (int)$field['display_order'] ?></small>
          <?php if ($field['description']): ?>
            <p class="mb-1 mt-1 text-muted section-hint"><?= htmlspecialchars($field['description']) ?></p>
          <?php endif; ?>
          <!-- Visibility badges -->
          <div class="mt-1">
            <?php if (empty($tf)): ?>
              <span class="badge badge-team">All Teams</span>
            <?php else: ?>
              <?php foreach ($teams as $t): if (in_array((int)$t['id'], $tf)): ?>
                <span class="badge badge-team"><?= htmlspecialchars($t['name']) ?></span>
              <?php endif; endforeach; ?>
            <?php endif; ?>
            <?php if (empty($ff)): ?>
              <span class="badge badge-form ms-1">All Forms</span>
            <?php else: ?>
              <?php foreach ($form_profiles as $fp): if (in_array((int)$fp['id'], $ff)): ?>
                <span class="badge badge-form ms-1"><?= htmlspecialchars($fp['name']) ?></span>
              <?php endif; endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary" type="button"
                  data-bs-toggle="collapse" data-bs-target="#edit-field-<?= $fid ?>">
            Edit Field
          </button>
          <form method="post" onsubmit="return confirm('Delete this field and all its options?')">
            <input type="hidden" name="action" value="delete_field">
            <input type="hidden" name="field_id" value="<?= $fid ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
          </form>
        </div>
      </div>

      <!-- Edit Field Form -->
      <div class="collapse mt-3" id="edit-field-<?= $fid ?>">
        <form method="post" class="border-top pt-3">
          <input type="hidden" name="action" value="edit_field">
          <input type="hidden" name="field_id" value="<?= $fid ?>">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Label</label>
              <input name="field_label" class="form-control" value="<?= htmlspecialchars($field['field_label']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Description</label>
              <input name="description" class="form-control" value="<?= htmlspecialchars($field['description'] ?? '') ?>">
            </div>
            <div class="col-md-1">
              <label class="form-label">Order</label>
              <input name="display_order" type="number" class="form-control" value="<?= (int)$field['display_order'] ?>">
            </div>
            <div class="col-md-1 d-flex align-items-end gap-2 flex-column justify-content-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_required" id="req_<?= $fid ?>"
                  <?= $field['is_required'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="req_<?= $fid ?>">Required</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="act_<?= $fid ?>"
                  <?= $field['is_active'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="act_<?= $fid ?>">Active</label>
              </div>
            </div>
          </div>

          <!-- Team Filters -->
          <div class="mt-3">
            <label class="form-label fw-bold">Show for Teams <span class="section-hint">(leave all unchecked = show for ALL teams)</span></label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach ($teams as $t): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="team_filters[]"
                         value="<?= $t['id'] ?>" id="tf_<?= $fid ?>_<?= $t['id'] ?>"
                    <?= in_array((int)$t['id'], $tf) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="tf_<?= $fid ?>_<?= $t['id'] ?>">
                    <?= htmlspecialchars($t['name']) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Form Filters -->
          <div class="mt-3">
            <label class="form-label fw-bold">Show for Forms <span class="section-hint">(leave all unchecked = show for ALL forms)</span></label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach ($form_profiles as $fp): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="form_filters[]"
                         value="<?= $fp['id'] ?>" id="ff_<?= $fid ?>_<?= $fp['id'] ?>"
                    <?= in_array((int)$fp['id'], $ff) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="ff_<?= $fid ?>_<?= $fp['id'] ?>">
                    <?= htmlspecialchars($fp['name']) ?> <span class="text-muted">(<?= htmlspecialchars($fp['team_name']) ?>)</span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <button type="submit" class="btn btn-plum mt-3">Save Field</button>
        </form>
      </div>

      <!-- ── OPTIONS ─────────────────────────────────────────────────── -->
      <div class="mt-3 border-top pt-3">
        <h6>Options (<?= count($opts) ?>)</h6>
        <?php if (empty($opts)): ?>
          <p class="text-muted section-hint">No options yet.</p>
        <?php endif; ?>
        <?php foreach ($opts as $opt): ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-light text-dark border"><?= (int)$opt['display_order'] ?></span>
            <span><?= htmlspecialchars($opt['option_label']) ?></span>
            <button class="btn btn-sm btn-link p-0 ms-1" type="button"
                    data-bs-toggle="collapse" data-bs-target="#edit-opt-<?= $opt['option_id'] ?>">
              Edit
            </button>
            <form method="post" class="d-inline" onsubmit="return confirm('Delete this option?')">
              <input type="hidden" name="action" value="delete_option">
              <input type="hidden" name="option_id" value="<?= $opt['option_id'] ?>">
              <button class="btn btn-sm btn-link text-danger p-0">Delete</button>
            </form>
          </div>
          <div class="collapse mb-2 ps-4" id="edit-opt-<?= $opt['option_id'] ?>">
            <form method="post" class="d-flex gap-2 align-items-end">
              <input type="hidden" name="action" value="edit_option">
              <input type="hidden" name="option_id" value="<?= $opt['option_id'] ?>">
              <input name="option_label" class="form-control form-control-sm" value="<?= htmlspecialchars($opt['option_label']) ?>" style="width:220px">
              <input name="option_order" type="number" class="form-control form-control-sm" value="<?= (int)$opt['display_order'] ?>" style="width:70px" placeholder="Order">
              <button type="submit" class="btn btn-sm btn-plum">Save</button>
            </form>
          </div>
        <?php endforeach; ?>

        <!-- Add Option -->
        <form method="post" class="d-flex gap-2 align-items-end mt-2">
          <input type="hidden" name="action" value="add_option">
          <input type="hidden" name="field_id" value="<?= $fid ?>">
          <input name="option_label" class="form-control form-control-sm" placeholder="New option label" style="width:220px" required>
          <input name="option_order" type="number" class="form-control form-control-sm" placeholder="Order" style="width:80px" value="<?= (count($opts) + 1) * 10 ?>">
          <button type="submit" class="btn btn-sm btn-plum">Add Option</button>
        </form>
      </div>

    </div>
  </div>
  <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
