<?php
header('Content-Type: application/json');
require_once('../secure/db_connection.php');

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Optional: date filters
$start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end   = $_GET['end_date']   ?? date('Y-m-d');

// --- Get Average Impact Scores ---
$sql = "
    SELECT t.name AS team_name, AVG(s.adjusted_impact_score) AS avg_score
    FROM scores s
    LEFT JOIN teams t ON s.team_id = t.id
    WHERE s.program_date BETWEEN ? AND ?
      AND s.adjusted_impact_score > 0
    GROUP BY t.name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();

$averages = ['youth' => 0, 'adult' => 0];
while ($row = $result->fetch_assoc()) {
    $team = strtolower($row['team_name']);
    if ($team === 'youth services') {
        $averages['youth'] = (float)$row['avg_score'];
    } elseif ($team === 'adult services') {
        $averages['adult'] = (float)$row['avg_score'];
    }
}

// --- Highest Impact Scores ---
$sql = "
    SELECT s.program, s.adjusted_impact_score, s.attendance, t.name AS team_name
    FROM scores s
    LEFT JOIN teams t ON s.team_id = t.id
    WHERE s.program_date BETWEEN ? AND ?
    ORDER BY s.adjusted_impact_score DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();

$highest = ['youth' => null, 'adult' => null];
while ($row = $result->fetch_assoc()) {
    $team = strtolower($row['team_name']);
    if ($team === 'youth services' && !$highest['youth']) {
        $highest['youth'] = [
            'program' => $row['program'],
            'score' => (float)$row['adjusted_impact_score'],
            'attendees' => (int)$row['attendance']
        ];
    }
    if ($team === 'adult services' && !$highest['adult']) {
        $highest['adult'] = [
            'program' => $row['program'],
            'score' => (float)$row['adjusted_impact_score'],
            'attendees' => (int)$row['attendance']
        ];
    }
    if ($highest['youth'] && $highest['adult']) break;
}

// --- Highest Attended Programs ---
$sql = "
    SELECT s.program, s.attendance, t.name AS team_name
    FROM scores s
    LEFT JOIN teams t ON s.team_id = t.id
    WHERE s.program_date BETWEEN ? AND ?
    ORDER BY s.attendance DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();

$attendance = ['youth' => null, 'adult' => null];
while ($row = $result->fetch_assoc()) {
    $team = strtolower($row['team_name']);
    if ($team === 'youth services' && !$attendance['youth']) {
        $attendance['youth'] = [
            'program' => $row['program'],
            'attendees' => (int)$row['attendance']
        ];
    }
    if ($team === 'adult services' && !$attendance['adult']) {
        $attendance['adult'] = [
            'program' => $row['program'],
            'attendees' => (int)$row['attendance']
        ];
    }
    if ($attendance['youth'] && $attendance['adult']) break;
}

// --- Program Totals ---
$sql = "
    SELECT t.name AS team_name, COUNT(s.id) AS programs, COALESCE(SUM(s.attendance), 0) AS attendees
    FROM scores s
    LEFT JOIN teams t ON s.team_id = t.id
    WHERE s.program_date BETWEEN ? AND ?
    GROUP BY t.name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();

$totals = [
    'total_programs' => 0,
    'total_attendees' => 0,
    'adult_services' => ['programs' => 0, 'attendees' => 0],
    'youth_services' => ['programs' => 0, 'attendees' => 0],
    'one_on_ones' => ['programs' => 0, 'attendees' => 0]
];

while ($row = $result->fetch_assoc()) {
    $team = strtolower(trim($row['team_name']));
    $totals['total_programs'] += $row['programs'];
    $totals['total_attendees'] += $row['attendees'];

    if ($team === 'adult services') {
        $totals['adult_services'] = ['programs' => $row['programs'], 'attendees' => $row['attendees']];
    } elseif ($team === 'youth services') {
        $totals['youth_services'] = ['programs' => $row['programs'], 'attendees' => $row['attendees']];
    } elseif ($team === 'one-on-ones') {
        $totals['one_on_ones'] = ['programs' => $row['programs'], 'attendees' => $row['attendees']];
    }
}

// ✅ Final output
echo json_encode([
    'average_impact_scores' => $averages,
    'highest_impact_scores' => $highest,
    'highest_attendance' => $attendance,
    'program_stats' => $totals,
    'date_range' => ['start' => $start, 'end' => $end]
]);

$conn->close();
?>
