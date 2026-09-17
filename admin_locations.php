<?php
/**
 * Admin Locations Management
 *
 * Allows admins to:
 *  - View all existing location values
 *  - Add a new location
 *  - Edit an existing location
 *  - Delete an existing location
 *
 * Requirements:
 * - Database table `locations` with fields: id (INT, PK), name (VARCHAR)
 * - A valid MySQL connection file: secure/db_connection.php
 *
 * @package ImpactScoreAdmin
 * @version 1.1
 */

session_start();
require_once('secure/db_connection.php');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- Handle Add ---
if (isset($_POST['add']) && !empty(trim($_POST['new_location']))) {
    $newLocation = trim($_POST['new_location']);
    $stmt = $conn->prepare("INSERT INTO locations (name) VALUES (?)");
    $stmt->bind_param("s", $newLocation);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_locations.php");
    exit;
}

// --- Handle Delete ---
if (isset($_POST['delete']) && is_numeric($_POST['id'])) {
    $locationId = (int) $_POST['id'];
    $stmt = $conn->prepare("DELETE FROM locations WHERE id = ?");
    $stmt->bind_param("i", $locationId);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_locations.php");
    exit;
}

// --- Handle Edit ---
if (isset($_POST['save_edit']) && is_numeric($_POST['id']) && !empty(trim($_POST['updated_name']))) {
    $locationId = (int) $_POST['id'];
    $updatedName = trim($_POST['updated_name']);
    $stmt = $conn->prepare("UPDATE locations SET name = ? WHERE id = ?");
    $stmt->bind_param("si", $updatedName, $locationId);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_locations.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin - Manage Locations</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { padding: 40px; background-color: #f8f9fa; font-family: Arial, sans-serif; }
        .container { max-width: 700px; margin: auto; }
        .location-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #ccc; }
        .edit-form { display: flex; gap: 10px; width: 100%; }
    </style>
</head>
<body>
<div class="container">
    <h2 class="mb-4">📍 Manage Locations</h2>

    <!-- Add New Location -->
    <form method="post" class="input-group mb-4">
        <input type="text" name="new_location" class="form-control" placeholder="Enter new location" required>
        <button type="submit" name="add" class="btn btn-primary">Add Location</button>
    </form>

    <!-- List Existing Locations -->
    <div class="location-list">
        <h5>Current Locations</h5>
        <?php
        $editId = (isset($_POST['edit']) && isset($_POST['id'])) ? $_POST['id'] : null;
        $result = $conn->query("SELECT * FROM locations ORDER BY name");
        if ($result->num_rows > 0):
            while ($row = $result->fetch_assoc()):
                $isEditing = ($editId == $row['id']);
        ?>
        <div class="location-item">
            <?php if ($isEditing): ?>
                <form method="post" class="edit-form">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <input type="text" name="updated_name" class="form-control" value="<?= htmlspecialchars($row['name']) ?>" required>
                    <button type="submit" name="save_edit" class="btn btn-success btn-sm">Save</button>
                    <a href="admin_locations.php" class="btn btn-secondary btn-sm">Cancel</a>
                </form>
            <?php else: ?>
                <span><?= htmlspecialchars($row['name']) ?></span>
                <div class="d-flex gap-2">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <button type="submit" name="edit" class="btn btn-sm btn-warning">Edit</button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this location?');">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <button type="submit" name="delete" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
            endwhile;
        else:
            echo "<p>No locations defined yet.</p>";
        endif;
        ?>
    </div>
</div>
</body>
</html>
