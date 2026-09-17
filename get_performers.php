<?php
// AJAX endpoint: returns active performers as JSON
header('Content-Type: application/json');
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { echo json_encode([]); exit(); }

$result = $conn->query("SELECT performer_id, stage_name, performer_type FROM performers WHERE status='active' ORDER BY stage_name");
$performers = $result->fetch_all(MYSQLI_ASSOC);
$conn->close();
echo json_encode($performers);
