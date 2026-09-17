<?php
/**
 * delete_score.php
 *
 * This script handles the deletion of a score record from the database. 
 * It expects an 'id' parameter (via GET) indicating which score to remove.
 * The script returns a JSON response indicating success or failure.
 *
 * Steps:
 *  1. Enable error reporting (for development/debugging).
 *  2. Load database credentials and establish a MySQL connection.
 *  3. Check if 'id' is provided in the GET request.
 *  4. Perform a hard delete on the specified record in the 'scores' table.
 *  5. Return a JSON response with the result (success or error).
 *
 * Prerequisites:
 *  - A valid MySQL database and credentials in 'secure/db_connection.php'.
 *  - The 'scores' table must exist and contain the relevant score records.
 *
 * Notes:
 *  - In production, you may want to handle errors differently (e.g., logging rather than exposing).
 *  - If soft deletes are preferred, update the logic to mark the record as deleted instead.
 */

// --- 1. Enable error reporting ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 2. Load DB credentials and connect ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    // Return a JSON error if connection fails
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// --- 3. Check if 'id' is provided via GET ---
if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'No score ID provided.']);
    exit;
}
$scoreId = $_GET['id'];

// --- 4. Perform a hard delete of the specified score record ---
$sql = "DELETE FROM scores WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $scoreId);

// --- 5. Execute and return JSON response ---
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Delete failed: ' . $stmt->error]);
}

$stmt->close();
$conn->close();

