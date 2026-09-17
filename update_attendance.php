<?php
/**
 * Update Attendance and Impact Score via AJAX
 *
 * This script handles POST requests to update the attendance and recompute
 * the adjusted impact score for a specific entry in the `scores` table.
 * It is typically called asynchronously via JavaScript (AJAX) when a user
 * updates attendance inline on the frontend.
 *
 * Features:
 *  - Validates and sanitizes incoming POST data.
 *  - Calculates scaled attendance using the square root of raw attendance.
 *  - Recalculates the adjusted impact score based on current total score.
 *  - Updates the relevant row in the database.
 *  - Returns JSON with updated values for display on the frontend.
 *
 * Prerequisites:
 *  - A valid MySQL connection via `secure/db_connection.php`
 *  - Table: `scores`
 *
 * @package AjaxAttendanceUpdate
 * @version 1.0
 */

// --- Enable error reporting for debugging (disable in production) ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Connect to database ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die(json_encode(["success" => false, "error" => "Database connection failed"]));
}

// --- Validate and sanitize input from POST ---
$score_id   = isset($_POST['score_id']) ? intval($_POST['score_id']) : 0;
$attendance = isset($_POST['attendance']) ? intval($_POST['attendance']) : 0;

if ($score_id <= 0) {
    echo json_encode(["success" => false, "error" => "Invalid Score ID"]);
    exit;
}

if ($attendance < 0) {
    echo json_encode(["success" => false, "error" => "Attendance cannot be negative"]);
    exit;
}

// --- Calculate scaled attendance and adjusted impact score ---
$scaled_attendance = sqrt($attendance);

// Retrieve the existing total_score from the database
$total_score_query = $conn->prepare("SELECT total_score FROM scores WHERE id = ?");
$total_score_query->bind_param("i", $score_id);
$total_score_query->execute();
$result = $total_score_query->get_result();
$row = $result->fetch_assoc();
$total_score = $row['total_score'] ?? 0;
$total_score_query->close();

// Adjusted Impact Score formula
$adjusted_impact_score = ($attendance == 0)
    ? ($scaled_attendance * $total_score)
    : ($scaled_attendance + $total_score);

// --- Update the record in the scores table ---
$stmt = $conn->prepare("
    UPDATE scores 
    SET attendance = ?, scaled_attendance = ?, adjusted_impact_score = ?
    WHERE id = ?
");
$stmt->bind_param("dddi", $attendance, $scaled_attendance, $adjusted_impact_score, $score_id);
$success = $stmt->execute();
$stmt->close();
$conn->close();

// --- Return result as JSON for frontend rendering ---
echo json_encode([
    "success" => $success,
    "scaled_attendance" => number_format($scaled_attendance, 2),
    "adjusted_impact_score" => number_format($adjusted_impact_score, 2)
]);
?>
