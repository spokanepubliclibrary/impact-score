<?php
/**
 * Update Sort Order for Scoring Questions (Bulk via JSON)
 *
 * This script accepts a JSON payload (typically sent via `fetch()` or AJAX)
 * and updates the `sort_order` values for rows in the `scoring_questions` table.
 * It's used when questions are reordered via drag-and-drop in the UI.
 *
 * Features:
 *  - Accepts and decodes raw JSON input from php://input
 *  - Updates each scoring question's `sort_order` by ID
 *  - Uses prepared statements for safety
 *
 * Prerequisites:
 *  - A valid MySQL connection via `secure/db_connection.php`
 *  - Table: `scoring_questions`
 *
 * @package SortOrderUpdater
 * @version 1.0
 */

// --- Enable error reporting (for debugging; disable in production) ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Load DB credentials and connect ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- Decode raw JSON input ---
$data = json_decode(file_get_contents("php://input"), true);

// --- Loop through each item and update its sort_order ---
foreach ($data as $row) {
    $stmt = $conn->prepare("UPDATE scoring_questions SET sort_order = ? WHERE id = ?");
    $stmt->bind_param("ii", $row['sort_order'], $row['id']);
    $stmt->execute();
    $stmt->close();
}

// --- Close DB connection ---
$conn->close();
?>
