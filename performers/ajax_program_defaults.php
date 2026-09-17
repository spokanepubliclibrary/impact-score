<?php
// AJAX endpoint: returns default question responses for a performer program + form profile
header('Content-Type: application/json');

$program_id     = isset($_GET['program_id'])     ? (int)$_GET['program_id']     : 0;
$form_profile_id = isset($_GET['form_profile_id']) ? (int)$_GET['form_profile_id'] : 0;
if ($program_id <= 0) { echo json_encode([]); exit(); }

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { echo json_encode([]); exit(); }

$stmt = $conn->prepare("SELECT question_id, response_value FROM performer_program_default_scores WHERE program_id = ? AND form_profile_id = ?");
$stmt->bind_param("ii", $program_id, $form_profile_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

// Return as { question_id: response_value, ... }
$defaults = [];
foreach ($rows as $r) {
    $defaults[(int)$r['question_id']] = (int)$r['response_value'];
}
echo json_encode($defaults);
