<?php
header('Content-Type: application/json');
require_once('../secure/db_connection.php');

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Filters
$id         = $_GET['id'] ?? null;
$team       = $_GET['team'] ?? null;
$start_date = $_GET['start_date'] ?? null;
$end_date   = $_GET['end_date'] ?? null;

$sql = "SELECT 
    scores.id,
    users.name AS user_name,
    IFNULL(scores.team_id, 1) AS team_id, 
    IFNULL(teams.name, 'Unknown') AS team_name, 
    scores.program,
    scores.total_score,
    scores.program_date,
    scores.attendance,
    IFNULL(scores.scaled_attendance, 0) AS scaled_attendance,
    IFNULL(scores.adjusted_impact_score, 0) AS adjusted_impact_score
FROM scores
LEFT JOIN users ON scores.user_id = users.id
LEFT JOIN teams ON scores.team_id = teams.id
WHERE 1=1";

$params = [];
$types = "";

if ($id) {
    $sql .= " AND scores.id = ?";
    $params[] = $id;
    $types .= "i";
}
if ($team) {
    $sql .= " AND teams.name = ?";
    $params[] = $team;
    $types .= "s";
}
if ($start_date) {
    $sql .= " AND scores.program_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if ($end_date) {
    $sql .= " AND scores.program_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

$sql .= " ORDER BY scores.program_date DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($id ? ($data[0] ?? null) : $data);

$conn->close();
?>
