<?php
/**
 * get_form_details.php
 *
 * This script retrieves information for a specific form from the database and returns it as JSON.
 * It performs the following steps:
 *   1. Enables error reporting and sets the content type to JSON.
 *   2. Loads secure database credentials and establishes a connection.
 *   3. Validates the presence of a "form_id" parameter from the GET request.
 *   4. Queries the "form_profiles" table for the form's name and bulk_entry flag.
 *   5. Returns the form ID, form name, and bulk_entry value as a JSON object.
 *
 * Prerequisites:
 *   - A valid database credentials file at 'secure/db_connection.php'.
 *   - The "form_profiles" table must have at least the columns: id, name, bulk_entry.
 *
 * Usage:
 *   Access this script via a URL, for example:
 *     get_form_info.php?form_id=123
 *
 * @package FormInfoAPI
 * @version 1.0
 */

// --- Enable Error Reporting and Set JSON Header ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// --- Load Database Credentials and Establish Connection ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(["error" => "Connection failed: " . $conn->connect_error]);
    exit;
}

// --- Validate Input: Check for form_id Parameter ---
if (!isset($_GET['form_id'])) {
    echo json_encode(["error" => "No form_id provided."]);
    $conn->close();
    exit;
}
$formId = (int)$_GET['form_id'];

// --- Fetch Form Information from form_profiles ---
$stmt = $conn->prepare("SELECT name, bulk_entry FROM form_profiles WHERE id = ?");
if (!$stmt) {
    echo json_encode(["error" => "Query prepare failed: " . $conn->error]);
    $conn->close();
    exit;
}
$stmt->bind_param("i", $formId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(["error" => "Form not found."]);
    $conn->close();
    exit;
}

$conn->close();

// --- Return the Form Information as JSON ---
echo json_encode([
    "form_id"    => $formId,
    "form_name"  => $row['name'],
    "bulk_entry" => (int)$row['bulk_entry']
]);
exit;
?>
