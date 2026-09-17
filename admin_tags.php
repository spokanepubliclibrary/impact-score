<?php
/**
 * admin_tags.php — Manage program tags (add / rename / delete).
 * Deleting a tag cascades to score_tags, removing it from all historical submissions.
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php"); exit();
}
require_once('secure/db_connection.php');
ini_set('display_errors', 1); error_reporting(E_ALL);

if (isset($_POST['add']) && !empty(trim($_POST['new_tag'] ?? ''))) {
    $name = trim($_POST['new_tag']);
    $st = $conn->prepare("INSERT IGNORE INTO program_tags (name) VALUES (?)");
    $st->bind_param("s", $name); $st->execute(); $st->close();
    header("Location: admin_tags.php"); exit;
}
if (isset($_POST['delete']) && is_numeric($_POST['id'] ?? '')) {
    $id = (int)$_POST['id'];
    $st = $conn->prepare("DELETE FROM program_tags WHERE id = ?");
    $st->bind_param("i", $id); $st->execute(); $st->close();
    header("Location: admin_tags.php"); exit;
}
if (isset($_POST['save_edit']) && is_numeric($_POST['id'] ?? '') && !empty(trim($_POST['updated_name'] ?? ''))) {
    $id = (int)$_POST['id']; $name = trim($_POST['updated_name']);
    $st = $conn->prepare("UPDATE program_tags SET name = ? WHERE id = ?");
    $st->bind_param("si", $name, $id); $st->execute(); $st->close();
    header("Location: admin_tags.php"); exit;
}
?><!DOCTYPE html><html lang="en"><head>
  <meta charset="UTF-8"><title>Admin - Program Tags</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { padding: 40px; background-color: #f8f9fa; font-family: Arial, sans-serif; }
    .container { max-width: 700px; margin: auto; }
    .tag-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #ccc; }
    .edit-form { display: flex; gap: 10px; width: 100%; }
    .btn-custom { background-color: #480d3c; color: white; }
    .btn-custom:hover { background-color: #bb1b51; color: white; }
  </style>
</head><body><div class="container">
  <a href="admin_portal.php" class="btn btn-secondary mb-4">⬅️ Back to Admin Portal</a>
  <h2 class="mb-4">🏷️ Manage Program Tags</h2>
  <p class="text-muted">Tags appear as checkboxes on the score form. Deleting a tag removes it from all historical submissions.</p>

  <form method="post" class="input-group mb-4">
    <input type="text" name="new_tag" class="form-control" placeholder="Enter new tag name" required>
    <button type="submit" name="add" class="btn btn-custom">Add Tag</button>
  </form>

  <div>
    <h5>Current Tags</h5>
    <?php
    $editId = (isset($_POST['edit'], $_POST['id'])) ? (int)$_POST['id'] : null;
    $result = $conn->query("SELECT id, name FROM program_tags ORDER BY name ASC");
    if ($result && $result->num_rows > 0):
        while ($row = $result->fetch_assoc()):
            $isEditing = ($editId === (int)$row['id']);
    ?>
    <div class="tag-item">
      <?php if ($isEditing): ?>
        <form method="post" class="edit-form">
          <input type="hidden" name="id" value="<?= $row['id'] ?>">
          <input type="text" name="updated_name" class="form-control" value="<?= htmlspecialchars($row['name']) ?>" required>
          <button type="submit" name="save_edit" class="btn btn-success btn-sm">Save</button>
          <a href="admin_tags.php" class="btn btn-secondary btn-sm">Cancel</a>
        </form>
      <?php else: ?>
        <span><?= htmlspecialchars($row['name']) ?></span>
        <div class="d-flex gap-2">
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <button type="submit" name="edit" class="btn btn-sm btn-warning">Edit</button>
          </form>
          <form method="post" style="display:inline;"
                onsubmit="return confirm('Delete &quot;<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>&quot;? This removes it from all historical submissions.');">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <button type="submit" name="delete" class="btn btn-sm btn-danger">Delete</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
    <?php endwhile; else: echo "<p class='text-muted'>No tags yet.</p>"; endif; ?>
  </div>
</div></body></html>
