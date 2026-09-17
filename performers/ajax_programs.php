<?php
header('Content-Type: application/json');

$performer_id = isset($_GET['performer_id']) ? (int)$_GET['performer_id'] : 0;
if ($performer_id <= 0) { echo json_encode([]); exit(); }

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { echo json_encode([]); exit(); }

$stmt = $conn->prepare("SELECT program_id, program_title FROM performer_programs WHERE performer_id=? AND active=1 ORDER BY program_title");
$stmt->bind_param("i", $performer_id);
$stmt->execute();
$programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

echo json_encode($programs);
