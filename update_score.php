<?php
/**
 * Update Score Attendance and Impact (Simple AJAX Endpoint)
 *
 * This endpoint is designed to update a single row in the `scores` table
 * when attendance is changed. It recalculates both `scaled_attendance` and
 * `adjusted_impact_score`, and returns the updated values as JSON.
 *
 * Features:
 *  - Validates incoming POST parameters (`id`, `attendance`)
 *  - Uses square root logic to compute scaled attendance
 *  - Recalculates adjusted impact score by adding scaled attendance to total score
 *  - Updates the database and returns JSON response for frontend rendering
 *
 * Prerequisites:
 *  - A valid MySQL connection via `secure/db_connection.php`
 *  - Table: `scores`
 *
 * @package AjaxUpdateScoreSimple
 * @version 1.0
 */

// --- Enable full error reporting (disable in production) ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json'); // 🔥 This tells JS that response is JSON

// --- Connect to database ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die(json_encode(["success" => false, "error" => "Database connection failed"]));
}

// --- Validate required POST parameters ---
if (!isset($_POST['id']) || !is_numeric($_POST['id']) || !isset($_POST['attendance'])) {
    die(json_encode(["success" => false, "error" => "Invalid request parameters"]));
}

$score_id   = intval($_POST['id']);
$attendance = intval($_POST['attendance']);

if ($attendance < 0) {
    die(json_encode(["success" => false, "error" => "Attendance cannot be negative"]));
}

// --- Update attendance and recompute adjusted_impact_score ---
// scaled_attendance is a generated column (MySQL sets it automatically).
// adjusted_impact_score is a plain column; formula is: total_score + SQRT(attendance).
// Zero attendance yields a zero adjusted score.
$stmt = $conn->prepare("
    UPDATE scores
    SET attendance            = ?,
        adjusted_impact_score = CASE WHEN ? = 0 THEN 0 ELSE total_score + SQRT(?) END
    WHERE id = ?
");
$stmt->bind_param("iiii", $attendance, $attendance, $attendance, $score_id);
$success = $stmt->execute();
$stmt->close();

// --- Read back the values (scaled_attendance was auto-updated by MySQL) ---
$scaled_attendance     = 0;
$adjusted_impact_score = 0;
if ($success) {
    $stmt = $conn->prepare("SELECT scaled_attendance, adjusted_impact_score FROM scores WHERE id = ?");
    $stmt->bind_param("i", $score_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $scaled_attendance     = (float)($row['scaled_attendance']     ?? 0);
    $adjusted_impact_score = (float)($row['adjusted_impact_score'] ?? 0);
}

// --- Return JSON response ---
if ($success) {
    $response = [
        "success"               => true,
        "scaled_attendance"     => number_format($scaled_attendance, 2),
        "adjusted_impact_score" => number_format($adjusted_impact_score, 2)
    ];
} else {
    $response = [
        "success" => false,
        "error"   => "Database update failed"
    ];
}

error_log("update_score.php JSON response: " . json_encode($response));
echo json_encode($response);
exit;

// --- Close DB connection ---
$conn->close();
?>
