<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/secure/db_connection.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

/* ─────────────────────────────────────────────────────────────
   1) SAME DIMENSIONS as your report (must match!)
───────────────────────────────────────────────────────────── */

$DIMENSIONS = [
    'user' => ['sql' => 'u.name', 'alias' => 'user_name', 'label' => 'User'],
    'team' => ['sql' => 't.name', 'alias' => 'team_name', 'label' => 'Team'],
    'location' => ['sql' => 'l.name', 'alias' => 'location_name', 'label' => 'Location'],
    'program_type' => ['sql' => 'fp.name', 'alias' => 'form_name', 'label' => 'Program Type'],
    'program_date' => ['sql' => "DATE(s.program_date)", 'alias' => 'program_date', 'label' => 'Program Date'],
    'event_type' => ['sql' => 'so4.option_text', 'alias' => 'event_type', 'label' => 'Event Type'],
    'month' => ['sql' => "DATE_FORMAT(s.program_date, '%Y-%m')", 'alias' => 'month', 'label' => 'Month'],
    'year' => ['sql' => "YEAR(s.program_date)", 'alias' => 'year', 'label' => 'Year'],
    'day_of_week' => ['sql' => "DAYNAME(s.program_date)", 'alias' => 'day_of_week', 'label' => 'Day of Week'],
];

/* ─────────────────────────────────────────────────────────────
   2) INPUT (same as report)
───────────────────────────────────────────────────────────── */

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
$keyword        = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

$only_specialty  = isset($_GET['only_specialty']) && $_GET['only_specialty'] === '1';
$exclude_special = isset($_GET['exclude_special']) && $_GET['exclude_special'] === '1';

$selected_group_by = isset($_GET['group_by']) && is_array($_GET['group_by']) ? $_GET['group_by'] : ['user'];
$selected_group_by = array_values(array_filter($selected_group_by, fn($k) => isset($DIMENSIONS[$k])));
if (empty($selected_group_by)) $selected_group_by = ['user'];

/* ─────────────────────────────────────────────────────────────
   3) BUILD WHERE (same as report)
───────────────────────────────────────────────────────────── */

$where  = [];
$params = [];
$types  = "";

// date range
$where[]  = "s.program_date BETWEEN ? AND ?";
$params[] = $start_date;
$params[] = $end_date;
$types   .= "ss";

if (!empty($selected_users)) {
    $ph = implode(',', array_fill(0, count($selected_users), '?'));
    $where[] = "s.user_id IN ($ph)";
    foreach ($selected_users as $v) { $params[] = (int)$v; $types .= "i"; }
}

if (!empty($selected_teams)) {
    $ph = implode(',', array_fill(0, count($selected_teams), '?'));
    $where[] = "s.team_id IN ($ph)";
    foreach ($selected_teams as $v) { $params[] = (int)$v; $types .= "i"; }
}

if (!empty($selected_locations)) {
    $ph = implode(',', array_fill(0, count($selected_locations), '?'));
    $where[] = "s.location_id IN ($ph)";
    foreach ($selected_locations as $v) { $params[] = (int)$v; $types .= "i"; }
}

if (!empty($selected_forms)) {
    $ph = implode(',', array_fill(0, count($selected_forms), '?'));
    $where[] = "s.form_id IN ($ph)";
    foreach ($selected_forms as $v) { $params[] = (int)$v; $types .= "i"; }
}

if ($min_score !== null) {
    $where[]  = "s.adjusted_impact_score >= ?";
    $params[] = $min_score;
    $types   .= "d";
}

if ($min_attendance !== null) {
    $where[]  = "s.attendance >= ?";
    $params[] = $min_attendance;
    $types   .= "i";
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
    $where[]  = "s.program LIKE ?";
    $params[] = "%" . $keyword . "%";
    $types   .= "s";
}

/* ─────────────────────────────────────────────────────────────
   4) BUILD GROUP SELECTS
───────────────────────────────────────────────────────────── */

$dimSelectParts = [];
$dimAliases     = [];
$dimLabels      = [];

foreach ($selected_group_by as $k) {
    $dim = $DIMENSIONS[$k];
    $dimSelectParts[] = "{$dim['sql']} AS {$dim['alias']}";
    $dimAliases[] = $dim['alias'];
    $dimLabels[$dim['alias']] = $dim['label'];
}

$baseFrom = "
    FROM scores s
    LEFT JOIN users u           ON s.user_id = u.id
    LEFT JOIN teams t           ON s.team_id = t.id
    LEFT JOIN locations l       ON s.location_id = l.id
    LEFT JOIN form_profiles fp  ON s.form_id = fp.id
    LEFT JOIN score_responses sr4
           ON sr4.score_id = s.id AND sr4.question_id = 4
    -- Join by option_text = response (unique) instead of option_points = points.
    -- option_points is NOT unique (Exhibit=1, Outreach=1), so the old join
    -- duplicated every points=1 row once per matching scoring_option.
    LEFT JOIN scoring_options so4
           ON so4.question_id = 4 AND so4.option_text = sr4.response
";

$whereSql = !empty($where) ? (" WHERE " . implode(" AND ", $where)) : "";

/* ─────────────────────────────────────────────────────────────
   5) QUERY A: SUMMARY PER GROUP
───────────────────────────────────────────────────────────── */

$summary_sql = "
    SELECT
        " . implode(", ", $dimSelectParts) . ",
        AVG(s.adjusted_impact_score) AS avg_score,
        COUNT(s.id) AS program_count,
        COALESCE(SUM(s.attendance), 0) AS total_attendance
    $baseFrom
    $whereSql
    GROUP BY " . implode(", ", array_map(fn($k) => $DIMENSIONS[array_search($k, $dimAliases, true) !== false ? array_keys($DIMENSIONS)[0] : 'user']['sql'], [])) . "
";

# NOTE: The above GROUP BY line is intentionally NOT used (it's messy).
# We'll build correct GROUP BY from the same dimension SQLs:

$groupSqlParts = [];
foreach ($selected_group_by as $k) $groupSqlParts[] = $DIMENSIONS[$k]['sql'];
$summary_sql = "
    SELECT
        " . implode(", ", $dimSelectParts) . ",
        AVG(s.adjusted_impact_score) AS avg_score,
        COUNT(s.id) AS program_count,
        COALESCE(SUM(s.attendance), 0) AS total_attendance
    $baseFrom
    $whereSql
    GROUP BY " . implode(", ", $groupSqlParts) . "
    ORDER BY " . implode(", ", $groupSqlParts) . "
";

$summaryRows = [];
$summaryByKey = [];

$st = $conn->prepare($summary_sql);
if (!$st) die("Prepare failed (summary): " . $conn->error);
if (!empty($params)) $st->bind_param($types, ...$params);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) {
    $summaryRows[] = $row;
    $keyParts = [];
    foreach ($dimAliases as $a) $keyParts[] = (string)($row[$a] ?? '');
    $key = implode("||", $keyParts);
    $summaryByKey[$key] = $row;
}
$r->free();
$st->close();

/* ─────────────────────────────────────────────────────────────
   6) QUERY B: ALL PROGRAMS (detail), then group in PHP
───────────────────────────────────────────────────────────── */

$detail_sql = "
    SELECT
        " . implode(", ", $dimSelectParts) . ",
        DATE(s.program_date) AS program_date,
        s.program AS program,
        s.adjusted_impact_score AS impact_score,
        s.attendance AS attendance,
        l.name AS location_name,
        fp.name AS form_name,
        so4.option_text AS event_type
    $baseFrom
    $whereSql
    ORDER BY " . implode(", ", $groupSqlParts) . ", s.program_date ASC
";

$detailByKey = [];

$dt = $conn->prepare($detail_sql);
if (!$dt) die("Prepare failed (detail): " . $conn->error);
if (!empty($params)) $dt->bind_param($types, ...$params);
$dt->execute();
$dr = $dt->get_result();
while ($row = $dr->fetch_assoc()) {
    $keyParts = [];
    foreach ($dimAliases as $a) $keyParts[] = (string)($row[$a] ?? '');
    $key = implode("||", $keyParts);
    if (!isset($detailByKey[$key])) $detailByKey[$key] = [];
    $detailByKey[$key][] = $row;
}
$dr->free();
$dt->close();

$conn->close();

/* ─────────────────────────────────────────────────────────────
   7) STREAM CSV
───────────────────────────────────────────────────────────── */

$filename = "impact_report_normalized_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"$filename\"");

$out = fopen('php://output', 'w');

// Header
$header = array_merge(
    ['Row Type'],
    array_map(fn($a) => $dimLabels[$a] ?? $a, $dimAliases),
    ['Avg Impact Score', 'Program Count', 'Total Attendance'],
    ['Program Date', 'Program', 'Impact Score', 'Attendance', 'Location', 'Program Type', 'Event Type']
);
fputcsv($out, $header);

// Body: SUMMARY row then PROGRAM rows
foreach ($summaryRows as $srow) {
    $keyParts = [];
    foreach ($dimAliases as $a) $keyParts[] = (string)($srow[$a] ?? '');
    $key = implode("||", $keyParts);

    // SUMMARY row
    fputcsv($out, array_merge(
        ['SUMMARY'],
        $keyParts,
        [
            number_format((float)$srow['avg_score'], 2, '.', ''),
            (int)$srow['program_count'],
            (int)$srow['total_attendance']
        ],
        ['', '', '', '', '', '', '']
    ));

    // PROGRAM rows
    $progs = $detailByKey[$key] ?? [];
    foreach ($progs as $prow) {
        fputcsv($out, array_merge(
            ['PROGRAM'],
            $keyParts,
            ['', '', ''],
            [
                $prow['program_date'] ?? '',
                $prow['program'] ?? '',
                isset($prow['impact_score']) ? number_format((float)$prow['impact_score'], 2, '.', '') : '',
                $prow['attendance'] ?? '',
                $prow['location_name'] ?? '',
                $prow['form_name'] ?? '',
                $prow['event_type'] ?? '',
            ]
        ));
    }
}

fclose($out);
exit;
