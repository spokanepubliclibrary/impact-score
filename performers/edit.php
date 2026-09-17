<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: ../admin_login.php");
    exit();
}

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$performer = [];
$selectedTags = [];
$errors = [];
$success = '';

// Fetch all tags for the tag picker
$allTags = $conn->query("SELECT tag_id, tag_name FROM tags ORDER BY tag_name")->fetch_all(MYSQLI_ASSOC);

// Load existing performer if editing
if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM performers WHERE performer_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $performer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$performer) {
        die("Performer not found.");
    }
    // Load selected tags
    $stmt = $conn->prepare("SELECT tag_id FROM performer_tag_map WHERE performer_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $tagRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $selectedTags = array_column($tagRows, 'tag_id');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stage_name     = trim($_POST['stage_name'] ?? '');
    $legal_name     = trim($_POST['legal_name'] ?? '');
    $org_name       = trim($_POST['organization_name'] ?? '');
    $perf_type      = trim($_POST['performer_type'] ?? '');
    $short_desc     = trim($_POST['short_description'] ?? '');
    $full_bio       = trim($_POST['full_bio'] ?? '');
    $website        = trim($_POST['website_url'] ?? '');
    $facebook       = trim($_POST['social_facebook'] ?? '');
    $instagram      = trim($_POST['social_instagram'] ?? '');
    $youtube        = trim($_POST['social_youtube'] ?? '');
    $social_other   = trim($_POST['social_other'] ?? '');
    $home_city      = trim($_POST['home_city'] ?? '');
    $home_state     = trim($_POST['home_state'] ?? '');
    $home_country   = trim($_POST['home_country'] ?? 'USA');
    $travel_radius  = ($_POST['travel_radius_miles'] ?? '') !== '' ? (int)$_POST['travel_radius_miles'] : null;
    $willing_travel = isset($_POST['willing_to_travel']) ? 1 : 0;
    $virtual        = isset($_POST['virtual_programs_available']) ? 1 : 0;
    $insurance      = isset($_POST['insurance_on_file']) ? 1 : 0;
    $w9             = isset($_POST['w9_on_file']) ? 1 : 0;
    $bgcheck        = isset($_POST['background_check_on_file']) ? 1 : 0;
    $status         = $_POST['status'] ?? 'active';
    $postTags       = isset($_POST['tags']) ? array_map('intval', (array)$_POST['tags']) : [];

    if ($stage_name === '') {
        $errors[] = "Stage name is required.";
    }

    if (empty($errors)) {
        if ($id > 0) {
            // Update
            $stmt = $conn->prepare("
                UPDATE performers SET
                    stage_name=?, legal_name=?, organization_name=?, performer_type=?,
                    short_description=?, full_bio=?, website_url=?, social_facebook=?,
                    social_instagram=?, social_youtube=?, social_other=?,
                    home_city=?, home_state=?, home_country=?, travel_radius_miles=?,
                    willing_to_travel=?, virtual_programs_available=?,
                    insurance_on_file=?, w9_on_file=?, background_check_on_file=?, status=?
                WHERE performer_id=?
            ");
            $stmt->bind_param(
                "ssssssssssssssiiiiiisi",
                $stage_name, $legal_name, $org_name, $perf_type,
                $short_desc, $full_bio, $website, $facebook,
                $instagram, $youtube, $social_other,
                $home_city, $home_state, $home_country, $travel_radius,
                $willing_travel, $virtual,
                $insurance, $w9, $bgcheck, $status, $id
            );
            $stmt->execute();
            $stmt->close();
            $success = "Performer updated successfully.";
        } else {
            // Insert
            $stmt = $conn->prepare("
                INSERT INTO performers (
                    stage_name, legal_name, organization_name, performer_type,
                    short_description, full_bio, website_url, social_facebook,
                    social_instagram, social_youtube, social_other,
                    home_city, home_state, home_country, travel_radius_miles,
                    willing_to_travel, virtual_programs_available,
                    insurance_on_file, w9_on_file, background_check_on_file, status
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param(
                "ssssssssssssssiiiiiis",
                $stage_name, $legal_name, $org_name, $perf_type,
                $short_desc, $full_bio, $website, $facebook,
                $instagram, $youtube, $social_other,
                $home_city, $home_state, $home_country, $travel_radius,
                $willing_travel, $virtual,
                $insurance, $w9, $bgcheck, $status
            );
            $stmt->execute();
            $id = $conn->insert_id;
            $stmt->close();
            $success = "Performer added successfully.";
        }

        // Sync tags
        $delStmt = $conn->prepare("DELETE FROM performer_tag_map WHERE performer_id = ?");
        $delStmt->bind_param("i", $id);
        $delStmt->execute();
        $delStmt->close();

        if (!empty($postTags)) {
            $tagStmt = $conn->prepare("INSERT IGNORE INTO performer_tag_map (performer_id, tag_id) VALUES (?, ?)");
            foreach ($postTags as $tid) {
                $tagStmt->bind_param("ii", $id, $tid);
                $tagStmt->execute();
            }
            $tagStmt->close();
        }

        // Reload performer
        $stmt = $conn->prepare("SELECT * FROM performers WHERE performer_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $performer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $selectedTags = $postTags;
    }
}

$conn->close();
$isNew = ($id === 0 || empty($performer));
$title = $isNew ? 'Add Performer' : 'Edit: ' . htmlspecialchars($performer['stage_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $title ?> — Performers</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; }
    .btn-custom { background-color: #480d3c; color: #fff; border-color: #480d3c; }
    .btn-custom:hover { background-color: #bb1b51; border-color: #bb1b51; color: #fff; }
    .section-header { background-color: #480d3c; color: #fff; padding: .5rem 1rem; border-radius: 6px; margin: 1.5rem 0 1rem; }
    .form-card { background: #fff; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .tag-grid { display: flex; flex-wrap: wrap; gap: .4rem; }
    .tag-label { display: inline-flex; align-items: center; gap: .25rem; background: #f0e8ee; border-radius: 20px; padding: .25rem .75rem; font-size: .85rem; cursor: pointer; }
    .tag-label input { margin: 0; }
    .tag-label:has(input:checked) { background: #480d3c; color: #fff; }
  </style>
</head>
<body>
<div class="container mt-4" style="max-width: 900px;">
  <div class="mb-3">
    <a href="index.php" class="btn btn-secondary btn-sm">&#8592; Performer Directory</a>
    <?php if (!$isNew): ?>
      <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm ms-2">View Profile</a>
    <?php endif; ?>
  </div>

  <h2 style="color:#480d3c;"><?= $title ?></h2>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?> <a href="view.php?id=<?= $id ?>">View profile &rarr;</a></div>
  <?php endif; ?>

  <form method="POST">
    <div class="form-card">
      <div class="section-header">Basic Information</div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Stage Name <span class="text-danger">*</span></label>
          <input type="text" name="stage_name" class="form-control" required value="<?= htmlspecialchars($performer['stage_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Legal Name</label>
          <input type="text" name="legal_name" class="form-control" value="<?= htmlspecialchars($performer['legal_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Organization Name</label>
          <input type="text" name="organization_name" class="form-control" value="<?= htmlspecialchars($performer['organization_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Performer Type</label>
          <input type="text" name="performer_type" class="form-control" placeholder="musician, storyteller, author…" value="<?= htmlspecialchars($performer['performer_type'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['active','inactive','pending','do_not_book'] as $s): ?>
              <option value="<?= $s ?>" <?= ($performer['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Short Description</label>
          <input type="text" name="short_description" class="form-control" maxlength="500" value="<?= htmlspecialchars($performer['short_description'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Full Bio</label>
          <textarea name="full_bio" class="form-control" rows="5"><?= htmlspecialchars($performer['full_bio'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="section-header">Location &amp; Travel</div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Home City</label>
          <input type="text" name="home_city" class="form-control" value="<?= htmlspecialchars($performer['home_city'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">State</label>
          <input type="text" name="home_state" class="form-control" value="<?= htmlspecialchars($performer['home_state'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Country</label>
          <input type="text" name="home_country" class="form-control" value="<?= htmlspecialchars($performer['home_country'] ?? 'USA') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Travel Radius (miles)</label>
          <input type="number" name="travel_radius_miles" class="form-control" min="0" value="<?= htmlspecialchars($performer['travel_radius_miles'] ?? '') ?>">
        </div>
        <div class="col-md-4 d-flex align-items-end gap-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="willing_to_travel" id="willing_to_travel" <?= ($performer['willing_to_travel'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="willing_to_travel">Willing to travel</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="virtual_programs_available" id="virtual" <?= ($performer['virtual_programs_available'] ?? 0) ? 'checked' : '' ?>>
            <label class="form-check-label" for="virtual">Virtual programs available</label>
          </div>
        </div>
      </div>

      <div class="section-header">Online Presence</div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Website</label>
          <input type="url" name="website_url" class="form-control" value="<?= htmlspecialchars($performer['website_url'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Facebook</label>
          <input type="url" name="social_facebook" class="form-control" value="<?= htmlspecialchars($performer['social_facebook'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Instagram</label>
          <input type="url" name="social_instagram" class="form-control" value="<?= htmlspecialchars($performer['social_instagram'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">YouTube</label>
          <input type="url" name="social_youtube" class="form-control" value="<?= htmlspecialchars($performer['social_youtube'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Other Social</label>
          <input type="url" name="social_other" class="form-control" value="<?= htmlspecialchars($performer['social_other'] ?? '') ?>">
        </div>
      </div>

      <div class="section-header">Documentation Status</div>
      <div class="d-flex gap-4 flex-wrap">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="insurance_on_file" id="ins" <?= ($performer['insurance_on_file'] ?? 0) ? 'checked' : '' ?>>
          <label class="form-check-label" for="ins">Insurance on file</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="w9_on_file" id="w9" <?= ($performer['w9_on_file'] ?? 0) ? 'checked' : '' ?>>
          <label class="form-check-label" for="w9">W-9 on file</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="background_check_on_file" id="bgcheck" <?= ($performer['background_check_on_file'] ?? 0) ? 'checked' : '' ?>>
          <label class="form-check-label" for="bgcheck">Background check on file</label>
        </div>
      </div>

      <div class="section-header">Tags</div>
      <div class="tag-grid">
        <?php foreach ($allTags as $tag): ?>
          <label class="tag-label">
            <input type="checkbox" name="tags[]" value="<?= $tag['tag_id'] ?>" <?= in_array((int)$tag['tag_id'], $selectedTags) ? 'checked' : '' ?>>
            <?= htmlspecialchars($tag['tag_name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button type="submit" class="btn btn-custom"><?= $isNew ? 'Add Performer' : 'Save Changes' ?></button>
      <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
</body>
</html>
