<?php
/**
 * export_scores_csv.php
 *
 * This script exports value score data as a CSV file.
 * It dynamically builds a query based on filter parameters provided via GET,
 * retrieves the matching score records, and outputs them in CSV format.
 *
 * Steps:
 *   1. Enable error reporting for debugging.
 *   2. Load database credentials and establish a MySQL connection.
 *   3. Process filter parameters from the GET request.
 *   4. Build and execute a dynamic SQL query based on these filters.
 *   5. Set HTTP headers to initiate a CSV file download.
 *   6. Write column headers and fetched data to the CSV output.
 *   7. Close resources and end the script.
 *
 * Prerequisites:
 *   - A valid MySQL connection provided via 'secure/db_connection.php'.
 *   - The 'scores' table with columns: name, team, program, total_score, program_date.
 *
 * @package ImpactScoreExport
 * @version 1.0
 */

// --- 1. Enable Error Reporting ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 2. Load DB Credentials and Connect ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- 3. Handle Filters from GET Request ---
// Initialize arrays to build dynamic WHERE clauses
$whereClauses = [];
$params = [];
$types = "";

if (!empty($_GET['name'])) {
    $whereClauses[] = "name = ?";
    $params[] = $_GET['name'];
    $types .= "s";
}
if (!empty($_GET['team'])) {
    $whereClauses[] = "team = ?";
    $params[] = $_GET['team'];
    $types .= "s";
}
if (!empty($_GET['program'])) {
    $whereClauses[] = "program LIKE ?";
    $params[] = "%" . $_GET['program'] . "%";
    $types .= "s";
}
if (!empty($_GET['start_date'])) {
    $whereClauses[] = "program_date >= ?";
    $params[] = $_GET['start_date'];
    $types .= "s";
}
if (!empty($_GET['end_date'])) {
    $whereClauses[] = "program_date <= ?";
    $params[] = $_GET['end_date'];
    $types .= "s";
}

// --- 4. Build Query Based on Filters ---
$query = "SELECT name, team, program, total_score, program_date FROM scores";
if (!empty($whereClauses)) {
    $query .= " WHERE " . implode(" AND ", $whereClauses);
}
$query .= " ORDER BY program_date DESC";

// Prepare and bind parameters if available
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// --- 5. Set Headers for CSV Download ---
$filename = "value_scores_" . date("Y-m-d") . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// --- 6. Write Data to CSV ---
// Open the output stream
$output = fopen('php://output', 'w');

// Output CSV column headers
fputcsv($output, ['Name', 'Team', 'Program', 'Total Score', 'Program Date']);

// Loop through each row and write to CSV
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['name'],
        $row['team'],
        $row['program'],
        $row['total_score'],
        !empty($row['program_date']) ? date("m/d/Y", strtotime($row['program_date'])) : "No Date Provided"
    ]);
}

// --- 7. Cleanup and Exit ---
fclose($output);
$conn->close();
exit;
?>
