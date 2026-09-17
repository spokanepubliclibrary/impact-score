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
$user_id    = $_GET['user_id'] ?? null;
$team       = $_GET['team'] ?? null;
$program    = $_GET['program'] ?? null;
$start_date = $_GET['start_date'] ?? null;
$end_date   = $_GET['end_date'] ?? null;
$limit      = $_GET['limit'] ?? 50;

$sql = "
    SELECT 
        scores.id,
        users.name AS user_name,
        IFNULL(teams.name, 'Unknown') AS team_name,
        scores.program,
        scores.total_score,
        scores.program_date,
        scores.attendance,
        IFNULL(scores.scaled_attendance, 0) AS scaled_attendance,
        IFNULL(scores.adjusted_impact_score, 0) AS adjusted_impact_score,
        scores.submission_date
    FROM scores
    LEFT JOIN users ON scores.user_id = users.id
    LEFT JOIN teams ON scores.team_id = teams.id
    WHERE 1=1
";

$params = [];
$types = "";

// Filters
if (!empty($user_id)) {
    $sql .= " AND users.id = ?";
    $params[] = $user_id;
    $types .= "i";
}
if (!empty($team)) {
    $sql .= " AND teams.name = ?";
    $params[] = $team;
    $types .= "s";
}
if (!empty($program)) {
    $sql .= " AND scores.program LIKE ?";
    $params[] = "%" . $program . "%";
    $types .= "s";
}
if (!empty($start_date)) {
    $sql .= " AND scores.program_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if (!empty($end_date)) {
    $sql .= " AND scores.program_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

$sql .= " ORDER BY scores.program_date DESC";

// Handle limit safely
if ($limit !== 'all') {
    $limitInt = (int)$limit;
    if ($limitInt < 1) {
        $limitInt = 50;
    }
    $sql .= " LIMIT " . $limitInt;
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL Prepare Failed']);
    exit;
}

// Bind filters if present
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$scores = [];
while ($row = $result->fetch_assoc()) {
    $scores[] = $row;
}

echo json_encode($scores);

$conn->close();
?>
