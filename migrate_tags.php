<?php
/**
 * migrate_tags.php — run once via browser, then delete.
 */
session_start();
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$errors = []; $steps = [];

$conn->query("CREATE TABLE IF NOT EXISTS program_tags (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") ? $steps[] = "✅ program_tags table created" : $errors[] = "❌ program_tags: " . $conn->error;

$conn->query("CREATE TABLE IF NOT EXISTS score_tags (
    score_id INT NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (score_id, tag_id),
    CONSTRAINT fk_st_score FOREIGN KEY (score_id) REFERENCES scores(id) ON DELETE CASCADE,
    CONSTRAINT fk_st_tag   FOREIGN KEY (tag_id)   REFERENCES program_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") ? $steps[] = "✅ score_tags table created (CASCADE DELETE)" : $errors[] = "❌ score_tags: " . $conn->error;

$stmt = $conn->prepare("INSERT IGNORE INTO program_tags (name) VALUES (?)");
foreach (['Baby','Toddlers','Preschool','Elementary','Middle School','High School','Adults','All Ages'] as $tag) {
    $stmt->bind_param("s", $tag);
    $stmt->execute();
    $steps[] = ($stmt->affected_rows > 0 ? "✅" : "⚠️ already exists:") . " <strong>" . htmlspecialchars($tag) . "</strong>";
}
$stmt->close(); $conn->close();
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Tag Migration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4"><h2>Tag Migration Results</h2>
<?php foreach ($steps  as $s): ?><p><?= $s ?></p><?php endforeach; ?>
<?php foreach ($errors as $e): ?><p class="text-danger"><?= $e ?></p><?php endforeach; ?>
<div class="alert <?= empty($errors) ? 'alert-success' : 'alert-danger' ?> mt-3">
  <?= empty($errors) ? 'Migration complete. <strong>Delete this file.</strong>' : 'Some steps failed.' ?>
</div>
<a href="admin_portal.php" class="btn btn-secondary">Back to Admin Portal</a></body></html>
