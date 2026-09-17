<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require_once __DIR__ . '/secure/db_connection.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
  echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
  exit;
}

// ---- pull same filters as main report ----
$default_start = date('Y-m-d', strtotime('-30 days'));
$default_end   = date('Y-m-d');

$start_date = $_GET['start_date'] ?? $default_start;
$end_date   = $_GET['end_date']   ?? $default_end;

$selected_users     = isset($_GET['users']) && is_array($_GET['users']) ? array_filter($_GET['users'], 'ctype_digit') : [];
$selected_teams     = isset($_GET['teams']) && is_array($_GET['teams']) ? array_filter($_GET['teams'], 'ctype_digit') : [];
$selected_locations = isset($_GET['locations']) && is_array($_GET['locations']) ? array_filter($_GET['locations'], 'ctype_digit') : [];
$selected_forms     = isset($_GET['forms']) && is_array($_GET['forms']) ? array_filter($_GET['forms'], 'ctype_digit') : [];

$selected_event_types = isset($_GET['event_types']) && is_array($_GET['event_types'])
  ? array_values(array_filter(array_map('trim', $_GET['event_types']), 'strlen'))
  : [];

$min_score      = isset($_GET['min_score']) && $_GET['min_score'] !== '' ? floatval($_GET['min_score']) : null;
$min_attendance = isset($_GET['min_attendance']) && $_GET['min_attendance'] !== '' ? intval($_GET['min_attendance']) : null;
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

$only_specialty  = isset($_GET['only_specialty']) && $_GET['only_specialty'] === '1';
$exclude_special = isset($_GET['exclude_special']) && $_GET['exclude_special'] === '1';

// ---- build same WHERE ----
$where  = [];
$params = [];
$types  = "";

$where[]  = "s.program_date BETWEEN ? AND ?";
$params[] = $start_date;
$params[] = $end_date;
$types   .= "ss";

if (!empty($selected_users)) {
  $ph = implode(',', array_fill(0, count($selected_users), '?'));
  $where[] = "s.user_id IN ($ph)";
  foreach ($selected_users as $v) { $params[] = $v; $types .= "i"; }
}

if (!empty($selected_teams)) {
  $ph = implode(',', array_fill(0, count($selected_teams), '?'));
  $where[] = "s.team_id IN ($ph)";
  foreach ($selected_teams as $v) { $params[] = $v; $types .= "i"; }
}

if (!empty($selected_locations)) {
  $ph = implode(',', array_fill(0, count($selected_locations), '?'));
  $where[] = "s.location_id IN ($ph)";
  foreach ($selected_locations as $v) { $params[] = $v; $types .= "i"; }
}

if (!empty($selected_forms)) {
  $ph = implode(',', array_fill(0, count($selected_forms), '?'));
  $where[] = "s.form_id IN ($ph)";
  foreach ($selected_forms as $v) { $params[] = $v; $types .= "i"; }
}

if ($min_score !== null) {
  $where[] = "s.adjusted_impact_score >= ?";
  $params[] = $min_score;
  $types .= "d";
}

if ($min_attendance !== null) {
  $where[] = "s.attendance >= ?";
  $params[] = $min_attendance;
  $types .= "i";
}

if ($only_specialty && !$exclude_special) {
  $where[] = "fp.name <> 'Program'";
} elseif ($exclude_special && !$only_specialty) {
  $where[] = "fp.name = 'Program'";
}

if (!empty($selected_event_types)) {
  $ph = implode(',', array_fill(0, count($selected_event_types), '?'));
  $where[] = "so4.option_text IN ($ph)";
  foreach ($selected_event_types as $v) { $params[] = $v; $types .= "s"; }
}

if ($keyword !== '') {
  $where[] = "s.program LIKE ?";
  $params[] = "%" . $keyword . "%";
  $types .= "s";
}

// ---- add DIM constraints based on clicked row ----
// We expect things like dim_user_name, dim_team_name, dim_location_name, dim_form_name, dim_event_type, dim_month, dim_year, dim_day_of_week
$dimMap = [
  'user_name'     => 'u.name',
  'team_name'     => 't.name',
  'location_name' => 'l.name',
  'form_name'     => 'fp.name',
  'event_type'    => 'so4.option_text',
  'month'         => "DATE_FORMAT(s.program_date, '%Y-%m')",
  'year'          => "YEAR(s.program_date)",
  'day_of_week'   => "DAYNAME(s.program_date)"
];

foreach ($dimMap as $alias => $expr) {
  $key = "dim_" . $alias;
  if (isset($_GET[$key]) && $_GET[$key] !== '') {
    $where[] = "$expr = ?";
    $params[] = $_GET[$key];
    $types .= "s";
  }
}

// ---- query programs ----
$sql = "
  SELECT
    DATE(s.program_date) AS program_date,
    s.program AS program,
    ROUND(s.adjusted_impact_score, 2) AS score,
    s.attendance AS attendance
  FROM scores s
  LEFT JOIN users u           ON s.user_id = u.id
  LEFT JOIN teams t           ON s.team_id = t.id
  LEFT JOIN locations l       ON s.location_id = l.id
  LEFT JOIN form_profiles fp  ON s.form_id = fp.id
  LEFT JOIN score_responses sr4
         ON sr4.score_id = s.id
        AND sr4.question_id = 4
  -- Join by option_text = response (unique) instead of option_points = points.
  -- option_points is NOT unique (Exhibit=1, Outreach=1), so the old join
  -- duplicated every points=1 row once per matching scoring_option.
  LEFT JOIN scoring_options so4
         ON so4.question_id = 4
        AND so4.option_text = sr4.response
";

if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY s.program_date ASC, s.program ASC LIMIT 500";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo json_encode(['ok' => false, 'error' => 'Prepare failed: ' . $conn->error]);
  exit;
}
if (!empty($params)) $stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
  echo json_encode(['ok' => false, 'error' => 'Execute failed: ' . $stmt->error]);
  exit;
}

$res = $stmt->get_result();
$programs = [];
while ($row = $res->fetch_assoc()) $programs[] = $row;

echo json_encode(['ok' => true, 'programs' => $programs]);
