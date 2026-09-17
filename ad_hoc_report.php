<?php
/**
 * Super Ad-Hoc Impact Report with Control Charts + Raw-Score Mode
 *
 * Features:
 *  - Flexible filters: date range, users, teams, locations, forms, min score, min attendance.
 *  - NEW: Event Type filter based on Question #4 (Exhibit, Outreach, etc.).
 *  - Group-by builder: user, team, location, program type, event type, month, year, day-of-week.
 *  - Metrics: avg score, program count, total attendance, std dev, min, max.
 *  - Chart.js visualizations (1–2 dimensions).
 *  - Control chart overlays (Mean, +1 SD, -1 SD) with:
 *        • Per-series mode: each series gets its own limits.
 *        • Global mode: one set of limits for all series.
 *  - RAW IMPACT SCORE MODE (manual checkbox):
 *        • Activated when "Raw Impact Score" metric is selected.
 *        • Other metrics + group-by options are ignored (and greyed out in UI).
 *        • Returns one row per program and plots an averaged Impact Score
 *          per user per date (one dot per user per date).
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ─────────────────────────────────────────────────────────────
// DB CONNECTION
// ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/secure/db_connection.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// ─────────────────────────────────────────────────────────────
// DETECT EVENT TYPE QUESTION ID(S)
// ─────────────────────────────────────────────────────────────
$EVENT_TYPE_QIDS = [];
if ($res = $conn->query("
    SELECT id
    FROM scoring_questions
    WHERE question_text LIKE '%what type of event%'
       OR question_text LIKE '%type of event%'
")) {
    while ($row = $res->fetch_assoc()) {
        $EVENT_TYPE_QIDS[] = (int)$row['id'];
    }
    $res->free();
}
$EVENT_TYPE_QIDS = array_values(array_unique($EVENT_TYPE_QIDS));


// ─────────────────────────────────────────────────────────────
// LOOKUP DATA FOR FILTERS
// ─────────────────────────────────────────────────────────────
$users        = [];
$teams        = [];
$locations    = [];
$forms        = [];
$event_types  = []; // distinct event types for Question #4

// Users
if ($res = $conn->query("SELECT id, name FROM users ORDER BY name ASC")) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
    $res->free();
}

// Teams
if ($res = $conn->query("SELECT id, name FROM teams ORDER BY name ASC")) {
    while ($row = $res->fetch_assoc()) {
        $teams[] = $row;
    }
    $res->free();
}

// Locations
if ($res = $conn->query("SELECT id, name FROM locations ORDER BY name ASC")) {
    while ($row = $res->fetch_assoc()) {
        $locations[] = $row;
    }
    $res->free();
}

// Form Profiles (program type / specialty)
if ($res = $conn->query("SELECT id, name FROM form_profiles ORDER BY name ASC")) {
    while ($row = $res->fetch_assoc()) {
        $forms[] = $row;
    }
    $res->free();
}

// Event Types (from detected Event Type question id(s))
if (!empty($EVENT_TYPE_QIDS)) {
    $qidList = implode(',', array_map('intval', $EVENT_TYPE_QIDS));

    if ($res = $conn->query("
        SELECT DISTINCT option_text
        FROM scoring_options
        WHERE question_id IN ($qidList)
          AND option_text IS NOT NULL
          AND option_text <> ''
        ORDER BY option_text ASC
    ")) {
        while ($row = $res->fetch_assoc()) {
            $event_types[] = $row; // ['option_text' => 'Exhibit', etc.]
        }
        $res->free();
    }
}


// ─────────────────────────────────────────────────────────────
// DIMENSION & METRIC DEFINITIONS (WHITELISTED)
// ─────────────────────────────────────────────────────────────

$DIMENSIONS = [
    'user' => [
        'sql'   => 'u.name',
        'alias' => 'user_name',
        'label' => 'User'
    ],
    'team' => [
        'sql'   => 't.name',
        'alias' => 'team_name',
        'label' => 'Team'
    ],
    'location' => [
        'sql'   => 'l.name',
        'alias' => 'location_name',
        'label' => 'Location'
    ],
    'program_type' => [
        'sql'   => 'fp.name',
        'alias' => 'form_name',
        'label' => 'Program Type'
    ],
    'program_date' => [
    'sql'   => "DATE(s.program_date)",
    'alias' => 'program_date',
    'label' => 'Program Date'
],
    // NEW: Event Type dimension (Question #4)
    'event_type' => [
        'sql'   => 'soET.option_text',
        'alias' => 'event_type',
        'label' => 'Event Type'
    ],
    'month' => [
        'sql'   => "DATE_FORMAT(s.program_date, '%Y-%m')",
        'alias' => 'month',
        'label' => 'Month'
    ],
    'year' => [
        'sql'   => "YEAR(s.program_date)",
        'alias' => 'year',
        'label' => 'Year'
    ],
    'day_of_week' => [
        'sql'   => "DAYNAME(s.program_date)",
        'alias' => 'day_of_week',
        'label' => 'Day of Week'
    ]
];

$METRICS = [
    'avg_score' => [
        'sql'   => 'AVG(s.adjusted_impact_score)',
        'alias' => 'avg_score',
        'label' => 'Average Impact Score'
    ],
    'program_count' => [
        'sql'   => 'COUNT(s.id)',
        'alias' => 'program_count',
        'label' => 'Program Count'
    ],
    'total_attendance' => [
        'sql'   => 'SUM(s.attendance)',
        'alias' => 'total_attendance',
        'label' => 'Total Attendance'
    ],
    'stddev_score' => [
        'sql'   => 'STDDEV(s.adjusted_impact_score)',
        'alias' => 'stddev_score',
        'label' => 'Std Dev of Score'
    ],
    'max_score' => [
        'sql'   => 'MAX(s.adjusted_impact_score)',
        'alias' => 'max_score',
        'label' => 'Max Impact Score'
    ],
    'min_score' => [
        'sql'   => 'MIN(s.adjusted_impact_score)',
        'alias' => 'min_score',
        'label' => 'Min Impact Score'
    ],
    // Special manual "raw" mode metric – NOT used in aggregated SQL
    'raw_score' => [
        'sql'   => 's.adjusted_impact_score',
        'alias' => 'raw_score',
        'label' => 'Raw Impact Score (one dot per user per date)'
    ]
];

// ─────────────────────────────────────────────────────────────
// INPUT HANDLING
// ─────────────────────────────────────────────────────────────

$default_start = date('Y-m-d', strtotime('-30 days'));
$default_end   = date('Y-m-d');

$start_date = $_GET['start_date'] ?? $default_start;
$end_date   = $_GET['end_date']   ?? $default_end;

$show_drilldown = isset($_GET['show_drilldown']) && $_GET['show_drilldown'] === '1';


// Multi-select filters
$selected_users     = isset($_GET['users']) && is_array($_GET['users']) ? array_filter($_GET['users'], 'ctype_digit') : [];
$selected_teams     = isset($_GET['teams']) && is_array($_GET['teams']) ? array_filter($_GET['teams'], 'ctype_digit') : [];
$selected_locations = isset($_GET['locations']) && is_array($_GET['locations']) ? array_filter($_GET['locations'], 'ctype_digit') : [];
$selected_forms     = isset($_GET['forms']) && is_array($_GET['forms']) ? array_filter($_GET['forms'], 'ctype_digit') : [];

// NEW: Event Type filter (multi-select, string values)
$selected_event_types = isset($_GET['event_types']) && is_array($_GET['event_types'])
    ? array_values(array_filter(array_map('trim', $_GET['event_types']), 'strlen'))
    : [];


$min_score      = isset($_GET['min_score']) && $_GET['min_score'] !== '' ? floatval($_GET['min_score']) : null;
$min_attendance = isset($_GET['min_attendance']) && $_GET['min_attendance'] !== '' ? intval($_GET['min_attendance']) : null;
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';


$only_specialty  = isset($_GET['only_specialty']) && $_GET['only_specialty'] === '1';
$exclude_special = isset($_GET['exclude_special']) && $_GET['exclude_special'] === '1';

// Group-by and metrics (with defaults)
$selected_group_by = isset($_GET['group_by']) && is_array($_GET['group_by']) ? $_GET['group_by'] : ['user'];
$selected_metrics  = isset($_GET['metrics']) && is_array($_GET['metrics']) ? $_GET['metrics'] : ['avg_score', 'program_count'];

$chart_type       = $_GET['chart_type'] ?? 'bar';
$include_control  = isset($_GET['include_control']) && $_GET['include_control'] == '1';
$control_mode     = $_GET['control_mode'] ?? 'series'; // 'series' or 'global'
if (!in_array($control_mode, ['series', 'global'], true)) {
    $control_mode = 'series';
}

// Ensure at least one dimension/metric, and keep only valid ones
if (empty($selected_group_by)) {
    $selected_group_by = ['user'];
}
if (empty($selected_metrics)) {
    $selected_metrics = ['avg_score', 'program_count'];
}
$selected_group_by = array_values(array_filter($selected_group_by, fn($k) => isset($DIMENSIONS[$k])));
$selected_metrics  = array_values(array_filter($selected_metrics,  fn($k) => isset($METRICS[$k])));
if (empty($selected_group_by)) {
    $selected_group_by = ['user'];
}
if (empty($selected_metrics)) {
    $selected_metrics = ['avg_score', 'program_count'];
}

// ─────────────────────────────────────────────────────────────
// RAW IMPACT SCORE MODE – manual checkbox
// ─────────────────────────────────────────────────────────────

$raw_mode = in_array('raw_score', $selected_metrics, true);
if ($raw_mode) {
    // Raw mode overrides all other metrics
    $selected_metrics = ['raw_score'];
}

// ─────────────────────────────────────────────────────────────
// BUILD WHERE CLAUSE (shared between modes)
// ─────────────────────────────────────────────────────────────

$where  = [];
$params = [];
$types  = "";

// Date range (always)
$where[]  = "s.program_date BETWEEN ? AND ?";
$params[] = $start_date;
$params[] = $end_date;
$types   .= "ss";

// Users
if (!empty($selected_users)) {
    $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
    // Match users as the primary s.user_id OR as a secondary staff
    // member recorded in score_users (multi-staff programs) so
    // co-presenters aren't dropped from the report.
    $where[] = "(s.user_id IN ($placeholders) OR s.id IN (SELECT score_id FROM score_users WHERE user_id IN ($placeholders)))";
    foreach ($selected_users as $uid) {
        $params[] = $uid;
        $types   .= "i";
    }
    foreach ($selected_users as $uid) {
        $params[] = $uid;
        $types   .= "i";
    }
}

// Teams
if (!empty($selected_teams)) {
    $placeholders = implode(',', array_fill(0, count($selected_teams), '?'));
    $where[] = "s.team_id IN ($placeholders)";
    foreach ($selected_teams as $tid) {
        $params[] = $tid;
        $types   .= "i";
    }
}

// Locations
if (!empty($selected_locations)) {
    $placeholders = implode(',', array_fill(0, count($selected_locations), '?'));
    $where[] = "s.location_id IN ($placeholders)";
    foreach ($selected_locations as $lid) {
        $params[] = $lid;
        $types   .= "i";
    }
}

// Forms
if (!empty($selected_forms)) {
    $placeholders = implode(',', array_fill(0, count($selected_forms), '?'));
    $where[] = "s.form_id IN ($placeholders)";
    foreach ($selected_forms as $fid) {
        $params[] = $fid;
        $types   .= "i";
    }
}

// Min score
if ($min_score !== null) {
    $where[]  = "s.adjusted_impact_score >= ?";
    $params[] = $min_score;
    $types   .= "d";
}

// Min attendance
if ($min_attendance !== null) {
    $where[]  = "s.attendance >= ?";
    $params[] = $min_attendance;
    $types   .= "i";
}

// Specialty filters
if ($only_specialty && !$exclude_special) {
    $where[] = "fp.name <> 'Program'";
} elseif ($exclude_special && !$only_specialty) {
    $where[] = "fp.name = 'Program'";
}

// Event Type filter (detected question id(s))
if (!empty($selected_event_types) && !empty($EVENT_TYPE_QIDS)) {

    $tPlace = implode(',', array_fill(0, count($selected_event_types), '?'));
$where[] = "(soET.option_text IN ($tPlace))";

foreach ($selected_event_types as $et) {
    $params[] = $et;
    $types   .= "s";
}
}



// Keyword search (Program name)
if ($keyword !== '') {
    $where[]  = "s.program LIKE ?";
    $params[] = "%" . $keyword . "%";
    $types   .= "s";
}


// ─────────────────────────────────────────────────────────────
// SELECT CLAUSES & SQL BUILDING
// ─────────────────────────────────────────────────────────────

$select_parts      = [];
$group_parts       = [];
$dimension_labels  = [];
$dimension_aliases = [];
$metric_labels     = [];

// RAW MODE: one row per program
if ($raw_mode) {

    $select_parts = [
        "s.program_date AS program_date",
        "u.name AS user_name",
        "t.name AS team_name",
        "l.name AS location_name",
        "fp.name AS form_name",
        "s.program AS program",
        "s.attendance AS attendance",
        "s.adjusted_impact_score AS raw_score"
    ];

    $dimension_aliases = [
        'program_date',
        'user_name',
        'team_name',
        'location_name',
        'form_name',
        'program',
        'attendance'
    ];
    $dimension_labels = [
        'program_date'   => 'Program Date',
        'user_name'      => 'User',
        'team_name'      => 'Team',
        'location_name'  => 'Location',
        'form_name'      => 'Program Type',
        'program'        => 'Program',
        'attendance'     => 'Attendance'
    ];
    $metric_labels = [
        'raw_score'      => 'Impact Score'
    ];

} else {
    // AGGREGATED MODE
    foreach ($selected_group_by as $dimKey) {
        $dim = $DIMENSIONS[$dimKey];
        $select_parts[] = $dim['sql'] . " AS " . $dim['alias'];
        $group_parts[]  = $dim['sql'];
        $dimension_labels[$dim['alias']] = $dim['label'];
        $dimension_aliases[] = $dim['alias'];
    }

    foreach ($selected_metrics as $metKey) {
        if ($metKey === 'raw_score') {
            continue; // raw_score is only for raw mode
        }
        $met = $METRICS[$metKey];
        $select_parts[] = $met['sql'] . " AS " . $met['alias'];
        $metric_labels[$met['alias']] = $met['label'];
    }
}

$sql = "
    SELECT " . implode(", ", $select_parts) . "
    FROM scores s
    LEFT JOIN users u           ON s.user_id = u.id
    LEFT JOIN teams t           ON s.team_id = t.id
    LEFT JOIN locations l       ON s.location_id = l.id
    LEFT JOIN form_profiles fp  ON s.form_id = fp.id
    -- Join Event Type (Question #4) via score_responses + scoring_options
    LEFT JOIN score_responses srET
       ON srET.score_id = s.id
      AND srET.question_id IN ($qidList)
    -- Join by option_text = response (unique per option) instead of
    -- option_points = points. option_points is NOT unique (e.g. Exhibit=1
    -- and Outreach=1 both share the same point value), so the old join
    -- duplicated every points=1 row once per matching scoring_option.
    LEFT JOIN scoring_options soET
       ON soET.question_id = srET.question_id
      AND soET.option_text = srET.response


";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

if (!$raw_mode && !empty($group_parts)) {
    $sql .= " GROUP BY " . implode(", ", $group_parts);
}

if ($raw_mode) {
    $sql .= " ORDER BY s.program_date ASC, u.name ASC";
} else {
    $sql .= " ORDER BY " . (!empty($group_parts) ? implode(", ", $group_parts) : "s.program_date DESC");
}

// ─────────────────────────────────────────────────────────────
// EXECUTE QUERY
// ─────────────────────────────────────────────────────────────

$summary = [
    'program_count'    => 0,
    'total_attendance' => 0,
    'avg_score'        => 0,
    'location_count'   => 0
];

$results   = [];
$error_msg = "";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    $error_msg = "Query preparation failed: " . $conn->error . " | SQL: " . $sql;
} else {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $results[] = $row;
        }
        $res->free();
    } else {
        $error_msg = "Query execution failed: " . $stmt->error;
    }
    $stmt->close();
}

// ─────────────────────────────────────────────────────────────
// SUMMARY STATS (same filters, no GROUP BY)
// ─────────────────────────────────────────────────────────────
$summary_sql = "
    SELECT
        COUNT(s.id)                                AS program_count,
        COALESCE(SUM(s.attendance), 0)             AS total_attendance,
        COALESCE(AVG(s.adjusted_impact_score), 0)  AS avg_score,
        COUNT(DISTINCT s.location_id)              AS location_count
    FROM scores s
    LEFT JOIN users u           ON s.user_id = u.id
    LEFT JOIN teams t           ON s.team_id = t.id
    LEFT JOIN locations l       ON s.location_id = l.id
    LEFT JOIN form_profiles fp  ON s.form_id = fp.id
   LEFT JOIN score_responses srET
       ON srET.score_id = s.id
      AND srET.question_id IN ($qidList)
    -- Join by option_text = response (unique per option) instead of
    -- option_points = points. option_points is NOT unique (e.g. Exhibit=1
    -- and Outreach=1 both share the same point value), so the old join
    -- duplicated every points=1 row once per matching scoring_option.
LEFT JOIN scoring_options soET
       ON soET.question_id = srET.question_id
      AND soET.option_text = srET.response


";

if (!empty($where)) {
    $summary_sql .= " WHERE " . implode(" AND ", $where);
}

$sum_stmt = $conn->prepare($summary_sql);
if ($sum_stmt && !empty($params)) {
    $sum_stmt->bind_param($types, ...$params);
}
if ($sum_stmt && $sum_stmt->execute()) {
    $sum_res = $sum_stmt->get_result();
    if ($sum_row = $sum_res->fetch_assoc()) {
        $summary['program_count']    = (int)$sum_row['program_count'];
        $summary['total_attendance'] = (int)$sum_row['total_attendance'];
        $summary['avg_score']        = (float)$sum_row['avg_score'];
        $summary['location_count']   = (int)$sum_row['location_count'];
    }
    $sum_res->free();
}
if ($sum_stmt) $sum_stmt->close();


// ─────────────────────────────────────────────────────────────
// CONTROL LIMIT CALCULATIONS (PHP)
// ─────────────────────────────────────────────────────────────

function calc_series_limits(array $results, array $dim_keys, string $metricKey): array {
    $grouped = [];
    foreach ($results as $row) {
        if (!isset($row[$metricKey])) continue;
        $val = (float)$row[$metricKey];
        if (count($dim_keys) >= 2) {
            $seriesKey = (string)($row[$dim_keys[1]] ?? 'Unknown');
        } else {
            $seriesKey = 'ALL';
        }
        $grouped[$seriesKey][] = $val;
    }

    $stats = [];
    foreach ($grouped as $seriesName => $values) {
        if (empty($values)) {
            $stats[$seriesName] = ['mean' => 0, 'upper' => 0, 'lower' => 0];
            continue;
        }
        $mean = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $v) {
            $variance += pow($v - $mean, 2);
        }
        $sd = sqrt($variance / count($values));
        $stats[$seriesName] = [
            'mean'  => $mean,
            'upper' => $mean + $sd,
            'lower' => $mean - $sd
        ];
    }
    return $stats;
}

function calc_global_limits(array $results, string $metricKey): array {
    $values = [];
    foreach ($results as $row) {
        if (isset($row[$metricKey])) {
            $values[] = (float)$row[$metricKey];
        }
    }
    if (empty($values)) {
        return ['mean' => 0, 'upper' => 0, 'lower' => 0];
    }
    $mean = array_sum($values) / count($values);
    $variance = 0;
    foreach ($values as $v) {
        $variance += pow($v - $mean, 2);
    }
    $sd = sqrt($variance / count($values));
    return [
        'mean'  => $mean,
        'upper' => $mean + $sd,
        'lower' => $mean - $sd
    ];
}

// Data for JS
$results_for_js = $results;
$dim_for_js     = $dimension_aliases;
if ($raw_mode) {
    $metrics_for_js = ['raw_score'];
} else {
    $metrics_for_js = array_keys($metric_labels);
}
$primary_metric = $metrics_for_js[0] ?? null;

$control_stats = null;
if ($include_control && $primary_metric && !empty($results)) {
    if ($raw_mode) {
        // Raw mode: series keyed by user_name
        $control_stats = ($control_mode === 'series')
            ? calc_series_limits($results, ['program_date','user_name'], 'raw_score')
            : calc_global_limits($results, 'raw_score');
    } else {
        $control_stats = ($control_mode === 'series')
            ? calc_series_limits($results, $dimension_aliases, $primary_metric)
            : calc_global_limits($results, $primary_metric);
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Super Ad-Hoc Impact Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background-color: #f5f2ec;
            margin: 0;
            padding: 0;
        }
        .page-wrapper {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .btn {
            display: inline-block;
            font-weight: 500;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.2s ease;
            border: none;
            cursor: pointer;
        }
        .btn-secondary {
            background-color: #480d3c;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #bb1b51;
        }
        h1, h2, h3 {
            color: #333;
        }
        .layout {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            grid-gap: 20px;
        }
        .panel {
            background: white;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .panel h3 {
            margin-top: 0;
        }
        label {
            font-size: 0.9rem;
            font-weight: 500;
            display: block;
            margin-bottom: 4px;
        }
        input[type="date"],
input[type="number"],
input[type="text"],
        select {
            width: 100%;
            padding: 6px 8px;
            margin-bottom: 10px;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-size: 0.9rem;
        }
        select[multiple] {
            min-height: 80px;
        }
        .checkbox-row {
            margin-bottom: 6px;
        }
        .checkbox-row input {
            margin-right: 6px;
        }
        .section-title {
            text-align: center;
            margin-bottom: 10px;
        }
        .submit-row {
            text-align: right;
            margin-top: 8px;
        }
        .results-panel {
            margin-top: 20px;
        }
        .tabs {
            margin-bottom: 10px;
        }
        .tab-btn {
            border-radius: 4px 4px 0 0;
            padding: 6px 10px;
            margin-right: 4px;
            border: 1px solid #ccc;
            border-bottom: none;
            background: #eee;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .tab-btn.active {
            background: white;
            font-weight: 600;
        }
        .tab-content {
            border: 1px solid #ccc;
            border-radius: 0 4px 4px 4px;
            padding: 10px;
            background: white;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 4px 6px;
        }
        th {
            background-color: #f2f0ea;
            text-align: left;
        }
        .chart-container {
            position: relative;
            width: 100%;
            height: 450px;
        }
        .note {
            font-size: 0.8rem;
            color: #555;
        }
        .error-msg {
            color: red;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .greyed-out {
            opacity: 0.5;
        }
        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="page-wrapper">
    <div style="margin-bottom: 20px;">
        <a href="stats.html" class="btn btn-secondary">⬅️ Back to Reports</a>
    </div>

    <h2 class="section-title">📊 Super Ad-Hoc Impact Report</h2>

    <?php if (!empty($error_msg)): ?>
        <div class="error-msg"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <form method="GET">
        <div class="layout">
            <!-- FILTER PANEL -->
            <div class="panel">
                <h3>Filters</h3>

                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">

                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">

                <label for="users">Users (multi-select)</label>
                <select id="users" name="users[]" multiple>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= in_array((string)$u['id'], $selected_users, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="teams">Teams (multi-select)</label>
                <select id="teams" name="teams[]" multiple>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= in_array((string)$t['id'], $selected_teams, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="locations">Locations (multi-select)</label>
                <select id="locations" name="locations[]" multiple>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= in_array((string)$l['id'], $selected_locations, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($l['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="forms">Program Type / Form (multi-select)</label>
                <select id="forms" name="forms[]" multiple>
                    <?php foreach ($forms as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= in_array((string)$f['id'], $selected_forms, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="keyword">Keyword in Program Name (optional)</label>
<input
    type="text"
    id="keyword"
    name="keyword"
    value="<?= htmlspecialchars($keyword) ?>"
    placeholder='e.g., story'>


                <!-- NEW: EVENT TYPE FILTER (Question #4) -->
                <h4>Event Types (Question #4)</h4>
                <?php if (empty($event_types)): ?>
                    <p class="note">No event types found for Question #4.</p>
                <?php else: ?>
                    <?php foreach ($event_types as $et): ?>
                        <?php $etText = $et['option_text']; ?>
                        <div class="checkbox-row">
                            <label>
                                <input type="checkbox"
                                       name="event_types[]"
                                       value="<?= htmlspecialchars($etText) ?>"
                                    <?= in_array($etText, $selected_event_types, true) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($etText) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div style="display:flex; gap:8px; margin:8px 0 12px;">
    <button type="button"
            class="btn btn-secondary"
            id="clearEventTypesBtn"
            style="background:#666;">
        Clear
    </button>

    <button type="button"
            class="btn btn-secondary"
            id="selectAllEventTypesBtn">
        Select All
    </button>
</div>


                <label for="min_score">Min Impact Score (optional)</label>
                <input type="number" step="0.1" id="min_score" name="min_score"
                       value="<?= $min_score !== null ? htmlspecialchars((string)$min_score) : '' ?>">

                <label for="min_attendance">Min Attendance (optional)</label>
                <input type="number" step="1" id="min_attendance" name="min_attendance"
                       value="<?= $min_attendance !== null ? htmlspecialchars((string)$min_attendance) : '' ?>">

                <div class="checkbox-row">
                    <label>
                        <input type="checkbox" name="only_specialty" value="1" <?= $only_specialty ? 'checked' : '' ?>>
                        Only specialty forms (non "Program")
                    </label>
                </div>
                <div class="checkbox-row">
                    <label>
                        <input type="checkbox" name="exclude_special" value="1" <?= $exclude_special ? 'checked' : '' ?>>
                        Exclude specialty forms (only "Program")
                    </label>
                </div>

                <p class="note">
                    Selecting <strong>Raw Impact Score</strong> (under Metrics) switches to a control-chart-style view:
                    one dot per user per date, based on individual program scores.
                </p>
            </div>

            <!-- GROUP BY / METRICS PANEL -->
            <div class="panel">
                <h3>Grouping & Metrics</h3>

                <h4>Group By (choose 1–3)</h4>
                <?php foreach ($DIMENSIONS as $key => $dim): ?>
                    <div class="checkbox-row group-by-row">
                        <label>
                            <input type="checkbox" name="group_by[]" value="<?= $key ?>"
                                <?= in_array($key, $selected_group_by, true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($dim['label']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>

                <h4>Metrics</h4>
                <?php foreach ($METRICS as $key => $met): ?>
                    <div class="checkbox-row metric-row<?= $key === 'raw_score' ? ' metric-raw' : '' ?>">
                        <label>
                            <input type="checkbox" name="metrics[]" value="<?= $key ?>"
                                <?= in_array($key, $selected_metrics, true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($met['label']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>

                <p id="raw-mode-note" class="note" style="display: <?= $raw_mode ? 'block' : 'none' ?>; margin-top:6px;">
                    <strong>Raw Impact Score mode is active.</strong><br>
                    Group By and other metrics are ignored, and the chart shows one line per user with
                    one averaged Impact Score per date.
                </p>

                <h4>Chart Type</h4>
                <select name="chart_type" id="chart_type">
                    <option value="bar" <?= $chart_type === 'bar' ? 'selected' : '' ?>>Bar</option>
                    <option value="line" <?= $chart_type === 'line' ? 'selected' : '' ?>>Line</option>
                    <option value="stacked" <?= $chart_type === 'stacked' ? 'selected' : '' ?>>Stacked Bar</option>
                    <option value="scatter" <?= $chart_type === 'scatter' ? 'selected' : '' ?>>Scatter</option>
                </select>

                <h4>Control Chart Lines</h4>
                <div class="checkbox-row">
                    <label>
                        <input type="checkbox" name="include_control" value="1" <?= $include_control ? 'checked' : '' ?>>
                        Show Control Lines (Mean, +1 SD, -1 SD)
                    </label>
                </div>

                <label for="control_mode">Control Mode</label>
                <select name="control_mode" id="control_mode">
                    <option value="series" <?= $control_mode === 'series' ? 'selected' : '' ?>>
                        Per-Series Limits
                    </option>
                    <option value="global" <?= $control_mode === 'global' ? 'selected' : '' ?>>
                        Global Limits
                    </option>
                </select>

                <div class="checkbox-row">
  <label>
    <input type="checkbox" name="show_drilldown" value="1" <?= $show_drilldown ? 'checked' : '' ?>>
    Enable drill-down (show included programs)
  </label>
</div>


                <div class="submit-row">
                    <button type="submit" class="btn btn-secondary">Run Report</button>
                </div>

                <p class="note">
                    In aggregated mode, charts use the <strong>first selected metric</strong> on the Y-axis.
                    In Raw Impact Score mode, they always use the raw Impact Score per user per date.
                </p>
            </div>
        </div>
    </form>

    <!-- RESULTS -->
    <div class="results-panel">
        <div class="tabs">
            <button class="tab-btn active" data-tab="table-tab">Table</button>
            <button class="tab-btn" data-tab="chart-tab">Chart</button>
        </div>
        <div class="tab-content" id="table-tab">
            <?php if (empty($results)): ?>
                <p><strong>No data matched your filters.</strong> Try adjusting the date range or filters.</p>
            <?php else: ?>
                <?php if ($raw_mode): ?>
                    <p class="note"><strong>Raw Impact Score mode:</strong> each row is a single program/event and Impact Score.</p>
                <?php endif; ?>
                <div style="display:grid; grid-template-columns: repeat(4, minmax(160px, 1fr)); gap:10px; margin-bottom:12px;">
    <div class="panel" style="box-shadow:none; border:1px solid #eee;">
        <div class="note">Avg Impact Score</div>
        <div style="font-size:1.4rem; font-weight:700;"><?= htmlspecialchars(number_format($summary['avg_score'], 2)) ?></div>
    </div>
    <div class="panel" style="box-shadow:none; border:1px solid #eee;">
        <div class="note">Program Count</div>
        <div style="font-size:1.4rem; font-weight:700;"><?= htmlspecialchars((string)$summary['program_count']) ?></div>
    </div>
    <div class="panel" style="box-shadow:none; border:1px solid #eee;">
        <div class="note">Total Attendance</div>
        <div style="font-size:1.4rem; font-weight:700;"><?= htmlspecialchars((string)$summary['total_attendance']) ?></div>
    </div>
    <div class="panel" style="box-shadow:none; border:1px solid #eee;">
        <div class="note">Location Count</div>
        <div style="font-size:1.4rem; font-weight:700;"><?= htmlspecialchars((string)$summary['location_count']) ?></div>
    </div>
</div>

                <div style="overflow-x:auto;">
                    <button id="exportCsvBtn" class="btn btn-secondary" style="margin-bottom:10px;">
                        ⬇️ Export Table to CSV
                    </button>

                    <table>
                        <thead>
                        <tr>
                            <?php foreach ($dimension_aliases as $alias): ?>
                                <th><?= htmlspecialchars($dimension_labels[$alias] ?? $alias) ?></th>
                            <?php endforeach; ?>
                            <?php foreach ($metric_labels as $alias => $label): ?>
                                <th><?= htmlspecialchars($label) ?></th>
                            <?php endforeach; ?>

                            <?php if ($show_drilldown && !$raw_mode): ?>
  <th>Programs</th>
<?php endif; ?>


                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <?php foreach ($dimension_aliases as $alias): ?>
                                    <td><?= htmlspecialchars((string)($row[$alias] ?? '')) ?></td>
                                <?php endforeach; ?>
                                <?php foreach ($metric_labels as $alias => $label): ?>
                                    <td><?= htmlspecialchars((string)round((float)($row[$alias] ?? 0), 2)) ?></td>
                                <?php endforeach; ?>

                                <?php if ($show_drilldown && !$raw_mode): ?>
  <td>
    <button
      type="button"
      class="btn btn-secondary drill-btn"
      style="padding:4px 10px; font-size:0.8rem;"
      data-row='<?= htmlspecialchars(json_encode(array_intersect_key($row, array_flip($dimension_aliases))), ENT_QUOTES) ?>'>
      Show programs
    </button>
  </td>
<?php endif; ?>



                            </tr>

                            <?php if ($show_drilldown && !$raw_mode): ?>
  <tr class="drill-row" style="display:none;">
    <td colspan="<?= count($dimension_aliases) + count($metric_labels) + 1 ?>">
      <div class="drill-content note">Loading…</div>
    </td>
  </tr>
<?php endif; ?>


                        <?php endforeach; ?>
                        </tbody>

                        <?php
// Totals for visible metric columns
$metricTotals = [];
foreach ($metric_labels as $alias => $label) {
    $metricTotals[$alias] = 0;
}
foreach ($results as $row) {
    foreach ($metric_labels as $alias => $label) {
        if (in_array($alias, ['program_count', 'total_attendance'], true)) {
            $metricTotals[$alias] += (float)($row[$alias] ?? 0);
        }
    }
}
?>

<tfoot>
<tr>
    <?php foreach ($dimension_aliases as $i => $alias): ?>
        <?php if ($i === 0): ?>
            <th>Totals</th>
        <?php else: ?>
            <th></th>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php foreach ($metric_labels as $alias => $label): ?>
        <?php if ($alias === 'avg_score'): ?>
            <th><?= htmlspecialchars(number_format($summary['avg_score'], 2)) ?></th>
        <?php elseif (in_array($alias, ['program_count','total_attendance'], true)): ?>
            <th><?= htmlspecialchars((string)round($metricTotals[$alias], 0)) ?></th>
        <?php else: ?>
            <th></th>
        <?php endif; ?>
    <?php endforeach; ?>
</tr>
</tfoot>


                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-content" id="chart-tab" style="display:none;">
            <?php if (empty($results) || !$primary_metric): ?>
                <p><strong>No data available for chart.</strong></p>
            <?php else: ?>
                <?php if (!$raw_mode && count($dimension_aliases) > 2): ?>
                    <p>
                        <strong>Charting is currently limited to 1–2 group-by dimensions.</strong><br>
                        Please reduce your group-by selection and run the report again.
                    </p>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="adHocChart"></canvas>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Tab switching
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tableTab   = document.getElementById('table-tab');
    const chartTab   = document.getElementById('chart-tab');

    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            tabButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const target = btn.dataset.tab;
            tableTab.style.display = (target === 'table-tab') ? 'block' : 'none';
            chartTab.style.display = (target === 'chart-tab') ? 'block' : 'none';
        });
    });

    // Grey-out behavior for Raw Impact Score metric
    (function() {
        const rawCheckbox = document.querySelector('input[name="metrics[]"][value="raw_score"]');
        if (!rawCheckbox) return;

        const metricCheckboxes = Array.from(document.querySelectorAll('.metric-row input[name="metrics[]"]'))
            .filter(cb => cb !== rawCheckbox);
        const groupCheckboxes  = Array.from(document.querySelectorAll('.group-by-row input[name="group_by[]"]'));
        const chartTypeSelect  = document.getElementById('chart_type');
        const rawNote          = document.getElementById('raw-mode-note');

        function updateRawModeUI() {
            const rawOn = rawCheckbox.checked;

            metricCheckboxes.forEach(cb => {
                cb.disabled = rawOn;
                const labelRow = cb.closest('.metric-row');
                if (labelRow) {
                    labelRow.classList.toggle('greyed-out', rawOn);
                }
            });

            groupCheckboxes.forEach(cb => {
                cb.disabled = rawOn;
                const row = cb.closest('.group-by-row');
                if (row) {
                    row.classList.toggle('greyed-out', rawOn);
                }
            });

            if (chartTypeSelect) {
                if (rawOn) {
                    chartTypeSelect.value = 'line';
                    chartTypeSelect.disabled = true;
                } else {
                    chartTypeSelect.disabled = false;
                }
            }

            if (rawNote) {
                rawNote.style.display = rawOn ? 'block' : 'none';
            }
        }

        rawCheckbox.addEventListener('change', updateRawModeUI);
        // Initialize on load
        updateRawModeUI();
    })();

    // Chart.js logic
    (function() {
        const results        = <?= json_encode($results_for_js, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
        const dims           = <?= json_encode($dim_for_js) ?>;
        const metricKey      = <?= json_encode($primary_metric) ?>;
        const chartType      = <?= json_encode($chart_type) ?>;
        const includeControl = <?= $include_control ? 'true' : 'false' ?>;
        const controlMode    = <?= json_encode($control_mode) ?>;
        const controlStats   = <?= json_encode($control_stats) ?>;
        const rawMode        = <?= $raw_mode ? 'true' : 'false' ?>;

        if (!results.length || !metricKey) return;

        const ctx = document.getElementById('adHocChart');
        if (!ctx) return;

        let config = null;

        // ─────────────────────────────────────────────
        // RAW IMPACT SCORE MODE (one averaged dot per user per date)
        // ─────────────────────────────────────────────
        if (rawMode) {
            // Unique sorted dates (YYYY-MM-DD)
            const uniqueDates = [...new Set(results.map(r => r.program_date))].sort();

            // Axis labels: MM/DD only
            const labels = uniqueDates.map(d => {
                const dt = new Date(d);
                if (isNaN(dt)) return d; // fallback
                const mm = dt.getMonth() + 1;
                const dd = dt.getDate();
                return mm + '/' + dd;
            });

            const users = results.map(r => r.user_name || '');
            const uniqueUsers = [...new Set(users)];

            // Build lookup of programs per user+date for tooltips
            const programLookup = {};
            results.forEach(r => {
                const user = r.user_name || '';
                const date = r.program_date || '';
                const key  = user + '||' + date;
                if (!programLookup[key]) programLookup[key] = [];
                programLookup[key].push({
                    program: r.program || '',
                    score: Number(r.raw_score || 0)
                });
            });

            const palette = [
                '#e41a1c', '#377eb8', '#4daf4a', '#984ea3',
                '#ff7f00', '#ffff33', '#a65628', '#f781bf',
                '#999999', '#66c2a5', '#fc8d62', '#8da0cb',
                '#e78ac3', '#a6d854'
            ];

            const datasets = uniqueUsers.map((user, index) => {
                const seriesData = uniqueDates.map(date => {
                    const key = user + '||' + date;
                    const rows = programLookup[key] || [];
                    if (!rows.length) return null;

                    const avg = rows.reduce((sum, row) => sum + row.score, 0) / rows.length;
                    return avg;
                });
                const color = palette[index % palette.length];
                return {
                    label: user,
                    data: seriesData,
                    borderColor: color,
                    backgroundColor: color,
                    pointBackgroundColor: color,
                    pointBorderColor: color,
                    borderWidth: 2,
                    fill: false,
                    tension: 0.2
                };
            });

            // Control lines
            if (includeControl && controlStats) {
                if (controlMode === 'series') {
                    uniqueUsers.forEach(user => {
                        const st = controlStats[user] || null;
                        if (!st) return;
                        const meanLine  = labels.map(() => st.mean);
                        const upperLine = labels.map(() => st.upper);
                        const lowerLine = labels.map(() => st.lower);

                        datasets.push({
                            label: user + " Mean",
                            data: meanLine,
                            borderColor: "#7DB652",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                        datasets.push({
                            label: user + " +1 SD",
                            data: upperLine,
                            borderColor: "#E4781F",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                        datasets.push({
                            label: user + " -1 SD",
                            data: lowerLine,
                            borderColor: "#E4781F",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                    });
                } else {
                    const st = controlStats;
                    const meanLine  = labels.map(() => st.mean);
                    const upperLine = labels.map(() => st.upper);
                    const lowerLine = labels.map(() => st.lower);

                    datasets.push({
                        label: "Mean",
                        data: meanLine,
                        borderColor: "#7DB652",
                        borderDash: [5,5],
                        pointRadius: 0,
                        fill: false
                    });
                    datasets.push({
                        label: "+1 SD",
                        data: upperLine,
                        borderColor: "#E4781F",
                        borderDash: [5,5],
                        pointRadius: 0,
                        fill: false
                    });
                    datasets.push({
                        label: "-1 SD",
                        data: lowerLine,
                        borderColor: "#E4781F",
                        borderDash: [5,5],
                        pointRadius: 0,
                        fill: false
                    });
                }
            }

            function formatFullDate(dateStr) {
                const dt = new Date(dateStr);
                if (isNaN(dt)) return dateStr;
                const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
                return dt.toLocaleDateString(undefined, options);
            }

            config = {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    spanGaps: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                title: (ctx) => {
                                    const idx = ctx[0].dataIndex;
                                    const dateStr = uniqueDates[idx] || '';
                                    return formatFullDate(dateStr);
                                },
                                label: (context) => {
                                    const idx = context.dataIndex;
                                    const dateStr = uniqueDates[idx] || '';
                                    const user = context.dataset.label || '';
                                    const key = user + '||' + dateStr;
                                    const rows = programLookup[key] || [];
                                    const avgVal = context.parsed.y;

                                    let lines = [];
                                    lines.push(user + " – Avg Score: " + avgVal.toFixed(2));
                                    if (rows.length) {
                                        lines.push("Programs:");
                                        rows.forEach(r => {
                                            const progName = r.program || 'Program';
                                            lines.push("• " + progName + " — " + r.score.toFixed(2));
                                        });
                                    }
                                    return lines;
                                }
                            }
                        },
                        legend: { position: 'top' }
                    },
                    scales: {
                        x: {
                            title: { display: true, text: "Program Date" }
                        },
                        y: {
                            title: { display: true, text: "Impact Score" }
                        }
                    }
                }
            };

        // ─────────────────────────────────────────────
        // AGGREGATED MODE (previous behavior)
        // ─────────────────────────────────────────────
        } else {
            if (dims.length === 1) {
                const dim   = dims[0];
                const labels = results.map(r => String(r[dim]));
                const data   = results.map(r => Number(r[metricKey] || 0));

                let type = (chartType === 'line' ? 'line' : (chartType === 'scatter' ? 'scatter' : 'bar'));

                const datasetConfig = {
                    label: metricKey,
                    data: type === 'scatter'
                        ? labels.map((lbl, idx) => ({x: idx + 1, y: data[idx]}))
                        : data,
                    borderWidth: 2,
                    fill: false
                };

                const datasets = [datasetConfig];

                if (includeControl && controlStats) {
                    if (controlMode === 'series') {
                        const st = controlStats['ALL'] || controlStats['All'] || controlStats['all'] || null;
                        if (st) {
                            const meanLine  = labels.map(() => st.mean);
                            const upperLine = labels.map(() => st.upper);
                            const lowerLine = labels.map(() => st.lower);
                            datasets.push({
                                label: "Mean",
                                data: meanLine,
                                borderColor: "#7DB652",
                                borderDash: [5,5],
                                pointRadius: 0,
                                fill: false
                            });
                            datasets.push({
                                label: "+1 SD",
                                data: upperLine,
                                borderColor: "#E4781F",
                                borderDash: [5,5],
                                pointRadius: 0,
                                fill: false
                            });
                            datasets.push({
                                label: "-1 SD",
                                data: lowerLine,
                                borderColor: "#E4781F",
                                borderDash: [5,5],
                                pointRadius: 0,
                                fill: false
                            });
                        }
                    } else {
                        const st = controlStats;
                        const meanLine  = labels.map(() => st.mean);
                        const upperLine = labels.map(() => st.upper);
                        const lowerLine = labels.map(() => st.lower);
                        datasets.push({
                            label: "Mean",
                            data: meanLine,
                            borderColor: "#7DB652",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                        datasets.push({
                            label: "+1 SD",
                            data: upperLine,
                            borderColor: "#E4781F",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                        datasets.push({
                            label: "-1 SD",
                            data: lowerLine,
                            borderColor: "#E4781F",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                    }
                }

                config = {
                    type: type,
                    data: {
                        labels: type === 'scatter' ? labels.map((lbl, idx) => idx + 1) : labels,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    title: (ctx) => {
                                        const index = ctx[0].dataIndex;
                                        return labels[index] || '';
                                    }
                                }
                            }
                        },
                        scales: type === 'scatter' ? {
                            x: {title: {display: true, text: 'Index'}},
                            y: {title: {display: true, text: metricKey}}
                        } : {
                            x: {title: {display: true, text: dims[0]}},
                            y: {title: {display: true, text: metricKey}}
                        }
                    }
                };

            } else if (dims.length === 2) {
                const dim1 = dims[0]; // X-axis
                const dim2 = dims[1]; // series
                const uniqueDim1 = [...new Set(results.map(r => String(r[dim1])))];
                const uniqueDim2 = [...new Set(results.map(r => String(r[dim2])))];

                const valueMap = {};
                results.forEach(r => {
                    const k1 = String(r[dim1]);
                    const k2 = String(r[dim2]);
                    valueMap[k1 + '||' + k2] = Number(r[metricKey] || 0);
                });

                const datasets = uniqueDim2.map((seriesName) => {
                    const seriesData = uniqueDim1.map(lbl => {
                        const key = lbl + '||' + seriesName;
                        return valueMap.hasOwnProperty(key) ? valueMap[key] : null;
                    });
                    return {
                        label: seriesName,
                        data: seriesData,
                        borderWidth: 2,
                        fill: false
                    };
                });

                if (includeControl && controlStats) {
                    if (controlMode === 'series') {
                        uniqueDim2.forEach(seriesName => {
                            const st = controlStats[seriesName] || null;
                            if (!st) return;
                            const meanLine  = uniqueDim1.map(() => st.mean);
                            const upperLine = uniqueDim1.map(() => st.upper);
                            const lowerLine = uniqueDim1.map(() => st.lower);

                            datasets.push({
                                label: seriesName + " Mean",
                                data: meanLine,
                                borderColor: "#7DB652",
                                borderDash: [5,5],
                                pointRadius: 0,
                                fill: false
                            });
                            datasets.push({
                                label: seriesName + " +1 SD",
                                data: upperLine,
                                borderColor: "#E4781F",
                                borderDash: [5,5],
                                pointRadius: 0,
                                fill: false
                            });
                            datasets.push({
                                label: seriesName + " -1 SD",
                                data: lowerLine,
                                borderColor: "#E4781F",
                                borderDash: [5,5],
                                pointRadius: 0,
                                fill: false
                            });
                        });
                    } else {
                        const st = controlStats;
                        const meanLine  = uniqueDim1.map(() => st.mean);
                        const upperLine = uniqueDim1.map(() => st.upper);
                        const lowerLine = uniqueDim1.map(() => st.lower);

                        datasets.push({
                            label: "Mean",
                            data: meanLine,
                            borderColor: "#7DB652",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                        datasets.push({
                            label: "+1 SD",
                            data: upperLine,
                            borderColor: "#E4781F",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                        datasets.push({
                            label: "-1 SD",
                            data: lowerLine,
                            borderColor: "#E4781F",
                            borderDash: [5,5],
                            pointRadius: 0,
                            fill: false
                        });
                    }
                }

                let type = (chartType === 'line' ? 'line' : 'bar');
                if (chartType === 'stacked') {
                    type = 'bar';
                }

                config = {
                    type: type,
                    data: {
                        labels: uniqueDim1,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            },
                            legend: {
                                position: 'top'
                            }
                        },
                        scales: {
                            x: {
                                stacked: chartType === 'stacked',
                                title: {display: true, text: dim1}
                            },
                            y: {
                                stacked: chartType === 'stacked',
                                title: {display: true, text: metricKey}
                            }
                        }
                    }
                };
            }
        }

        if (config) {
            new Chart(ctx, config);
        }
    })();

    // ----- CSV EXPORT LOGIC -----
    document.getElementById('exportCsvBtn')?.addEventListener('click', () => {
  const qs = window.location.search || '';
  window.location.href = 'export_impact_report_normalized.php' + qs;
});


    (function () {
    const clearBtn = document.getElementById('clearEventTypesBtn');
    const allBtn   = document.getElementById('selectAllEventTypesBtn');
    const boxes    = Array.from(document.querySelectorAll('input[type="checkbox"][name="event_types[]"]'));

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            boxes.forEach(b => b.checked = false);
        });
    }
    if (allBtn) {
        allBtn.addEventListener('click', () => {
            boxes.forEach(b => b.checked = true);
        });
    }
})();

(function() {
  const buttons = document.querySelectorAll('.drill-btn');
  if (!buttons.length) return;

  buttons.forEach(btn => {
    btn.addEventListener('click', async () => {
      const mainRow = btn.closest('tr');
      const drillRow = mainRow?.nextElementSibling;
      if (!drillRow || !drillRow.classList.contains('drill-row')) return;

      // toggle open/close if already loaded
      const isOpen = drillRow.style.display !== 'none';
      drillRow.style.display = isOpen ? 'none' : 'table-row';
      if (isOpen) return;

      const contentDiv = drillRow.querySelector('.drill-content');
      if (!contentDiv) return;

      contentDiv.textContent = 'Loading…';

      // Build query string using current page filters + row dimensions
      const params = new URLSearchParams(window.location.search);

      // Add the clicked row's dimension values
      const rowData = JSON.parse(btn.dataset.row || "{}");
      Object.keys(rowData).forEach(k => {
        params.set("dim_" + k, rowData[k] ?? "");
      });

      try {
        const resp = await fetch("ad_hoc_programs.php?" + params.toString(), {
          headers: { "Accept": "application/json" }
        });
        const data = await resp.json();

        if (!data.ok) {
          contentDiv.textContent = data.error || "Failed to load programs.";
          return;
        }

        // Render list
        if (!data.programs.length) {
          contentDiv.textContent = "No programs found for this row.";
          return;
        }

        const ul = document.createElement('ul');
        ul.style.margin = "6px 0 0 16px";

        data.programs.forEach(p => {
          const li = document.createElement('li');
          li.textContent = `${p.program_date} — ${p.program} (Score: ${p.score}, Attendance: ${p.attendance})`;
          ul.appendChild(li);
        });

        contentDiv.innerHTML = "";
        contentDiv.appendChild(ul);

      } catch (e) {
        contentDiv.textContent = "Error loading programs.";
      }
    });
  });
})();


</script>
</body>
</html>
