<?php
/**
 * programming_report.php
 *
 * This script generates a detailed programming report for Youth and Adult services,
 * including:
 *   - Filtering data by date range.
 *   - Fetching and calculating impact scores, attendance, and specialty form stats.
 *   - Displaying charts (Process Control Charts) for Youth and Adult impact scores.
 *   - Handling image uploads and session management for storing a report image.
 *
 * Major Steps:
 *   1. Enable error reporting and set up logging for debugging.
 *   2. Start the session to manage uploaded images or other session-based data.
 *   3. Connect to the MySQL database using secure credentials.
 *   4. Retrieve and process data for Youth Services and Adult Services:
 *        • Impact scores, highest attended, highest impact, average impact scores.
 *   5. Compute mean, standard deviation, and upper/lower limits for chart display.
 *   6. Handle an optional file upload for a report image.
 *   7. Build an HTML page that displays the results, charts, and highlights.
 *   8. Provide functionality to save the report as a PDF using html2canvas and jsPDF.
 *
 * Prerequisites:
 *   - Database tables: scores, teams, users, form_profiles (for specialty forms).
 *   - Chart.js, html2canvas, and jsPDF libraries for charting and PDF generation.
 *   - A writable 'uploads/' directory for image uploads.
 *
 * @package ProgrammingReport
 * @version 1.0
 */

// --- 1. Enable Error Reporting and Logging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.txt');

// --- 2. Start the Session ---
session_start();

// --- 3. Database Connection ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("❌ Database connection failed: " . $conn->connect_error);
}

// --- Handle Date Range Filters (Default: last 30 days) ---
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 month'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

// --- Fetch Data for Youth Services ---
// Query selects program, date, and adjusted impact score for Youth Services.
$sql = "
    SELECT scores.program, scores.program_date, scores.adjusted_impact_score
    FROM scores
    LEFT JOIN teams ON scores.team_id = teams.id
    WHERE program_date BETWEEN ? AND ?
      AND teams.name = 'Youth Services'
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

$youthData = [];
while ($row = $result->fetch_assoc()) {
    $youthData[] = [
        'program' => $row['program'],
        'date'    => $row['program_date'],
        'score'   => (float)$row['adjusted_impact_score']
    ];
}
$youth_scores = array_map(fn($yd) => $yd['score'], $youthData);

// --- Fetch Data for Adult Services ---
$sql = "
    SELECT scores.program, scores.program_date, scores.adjusted_impact_score
    FROM scores
    LEFT JOIN teams ON scores.team_id = teams.id
    WHERE program_date BETWEEN ? AND ?
      AND teams.name = 'Adult Services'
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

$adultData = [];
while ($row = $result->fetch_assoc()) {
    $adultData[] = [
        'program' => $row['program'],
        'date'    => $row['program_date'],
        'score'   => (float)$row['adjusted_impact_score']
    ];
}
$adult_scores = array_map(fn($ad) => $ad['score'], $adultData);

// --- Function: Get All Specialty Form Stats ---
/**
 * Fetches counts of specialty programs and total attendance for each specialty form.
 *
 * @param mysqli $conn        The database connection.
 * @param string $start_date  The start date for filtering.
 * @param string $end_date    The end date for filtering.
 * @return array An associative array of specialty forms => ['programs' => #, 'attendees' => #].
 */
function getAllSpecialtyFormStats($conn, $start_date, $end_date) {
    $specialtyCounts = [];
    $sql = "
        SELECT s.program AS program_name, COUNT(s.id) AS program_count,
               COALESCE(SUM(s.attendance), 0) AS total_attendance
        FROM scores s
        LEFT JOIN form_profiles fp ON s.form_id = fp.id
        WHERE s.program_date BETWEEN ? AND ?
          AND fp.name <> 'Program'  -- Exclude generic program entries
        GROUP BY s.program
        ORDER BY program_count DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result !== false && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $specialtyCounts[$row['program_name']] = [
                'programs'  => (int)($row['program_count'] ?? 0),
                'attendees' => (int)($row['total_attendance'] ?? 0)
            ];
        }
    }
    return $specialtyCounts;
}

// --- Fetch Additional Data for Control Charts ---
$programs = [];
$scores   = [];

$sql = "SELECT program, adjusted_impact_score FROM scores WHERE program_date BETWEEN ? AND ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $programs[] = $row['program'];
    $scores[]   = $row['adjusted_impact_score'];
}

// Compute Mean and Standard Deviation for All Scores in the Date Range
$mean = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;
$std_dev = (count($scores) > 1)
    ? sqrt(array_sum(array_map(fn($x) => pow($x - $mean, 2), $scores)) / count($scores))
    : 0;
$upper_limit = $mean + $std_dev;
$lower_limit = $mean - $std_dev;

// --- Function: Get Average Impact Scores ---
/**
 * Returns average impact scores for Youth Services and Adult Services within a date range.
 *
 * @param mysqli $conn       The database connection.
 * @param string $start_date Start date for filtering.
 * @param string $end_date   End date for filtering.
 * @return array An associative array containing 'youth services' and 'adult services' average scores.
 */
function getAverageImpactScores($conn, $start_date, $end_date) {
    $sql = "
        SELECT t.name AS team_name, AVG(s.adjusted_impact_score) AS avg_impact_score
        FROM scores s
        LEFT JOIN teams t ON s.team_id = t.id
        WHERE s.program_date BETWEEN ? AND ?
          AND s.adjusted_impact_score > 0
        GROUP BY t.name
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $averages = ['youth services' => 0, 'adult services' => 0];
    while ($row = $result->fetch_assoc()) {
        $team = strtolower(trim($row['team_name'] ?? ''));
        if ($team === 'youth services') {
            $averages['youth services'] = $row['avg_impact_score'] ?? 0;
        } elseif ($team === 'adult services') {
            $averages['adult services'] = $row['avg_impact_score'] ?? 0;
        }
    }
    return $averages;
}

// --- Function: Get Program Stats ---
/**
 * Retrieves aggregate stats for all programs within a date range, grouped by team.
 *
 * @param mysqli $conn       The database connection.
 * @param string $start_date Start date for filtering.
 * @param string $end_date   End date for filtering.
 * @return array Associative array of total program counts, attendance, and breakdowns by team.
 */
function getProgramStats($conn, $start_date, $end_date) {
    $stats = [
        'total_programs'    => 0,
        'total_attendance'  => 0,
        'adult_services'    => ['programs' => 0, 'attendees' => 0],
        'youth_services'    => ['programs' => 0, 'attendees' => 0],
        'one_on_ones'       => ['programs' => 0, 'attendees' => 0]
    ];

    $sql = "
        SELECT t.name AS team_name, COUNT(s.id) AS program_count, COALESCE(SUM(s.attendance), 0) AS total_attendance
        FROM scores s
        LEFT JOIN teams t ON s.team_id = t.id
        WHERE s.program_date BETWEEN ? AND ?
        GROUP BY t.name
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $team = strtolower(trim($row['team_name'] ?? 'Unknown'));
            $stats['total_programs']    += $row['program_count'];
            $stats['total_attendance']  += $row['total_attendance'];

            if ($team === 'adult services') {
                $stats['adult_services']['programs']   = $row['program_count'];
                $stats['adult_services']['attendees']  = $row['total_attendance'];
            } elseif ($team === 'youth services') {
                $stats['youth_services']['programs']   = $row['program_count'];
                $stats['youth_services']['attendees']  = $row['total_attendance'];
            } elseif ($team === 'one-on-ones') {
                $stats['one_on_ones']['programs']   = $row['program_count'];
                $stats['one_on_ones']['attendees']  = $row['total_attendance'];
            }
        }
    }
    return $stats;
}

// --- Function: Get Highest Impact Scores ---
/**
 * Retrieves the highest impact scores for Youth and Adult services within a date range.
 *
 * @param mysqli $conn       The database connection.
 * @param string $start_date Start date for filtering.
 * @param string $end_date   End date for filtering.
 * @return array Associative array containing highest scores for youth and adult.
 */
function getHighestImpactScores($conn, $start_date, $end_date) {
    $highestScores = [
        'youth' => ['program' => 'PLACEHOLDER', 'score' => 0, 'attendees' => 0],
        'adult' => ['program' => 'PLACEHOLDER', 'score' => 0, 'attendees' => 0]
    ];

    $sql = "
        SELECT s.program, s.adjusted_impact_score, s.attendance, t.name AS team_name
        FROM scores s
        LEFT JOIN teams t ON s.team_id = t.id
        WHERE s.program_date BETWEEN ? AND ?
        ORDER BY s.adjusted_impact_score DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $team = strtolower(trim($row['team_name'] ?? ''));
            if ($team === 'youth services' && $highestScores['youth']['score'] < $row['adjusted_impact_score']) {
                $highestScores['youth'] = [
                    'program'   => $row['program'],
                    'score'     => $row['adjusted_impact_score'],
                    'attendees' => $row['attendance']
                ];
            }
            if ($team === 'adult services' && $highestScores['adult']['score'] < $row['adjusted_impact_score']) {
                $highestScores['adult'] = [
                    'program'   => $row['program'],
                    'score'     => $row['adjusted_impact_score'],
                    'attendees' => $row['attendance']
                ];
            }
        }
    }
    return $highestScores;
}

// --- Function: Get Highest Attended Programs ---
/**
 * Retrieves the highest attended program for Youth and Adult services within a date range.
 *
 * @param mysqli $conn       The database connection.
 * @param string $start_date Start date for filtering.
 * @param string $end_date   End date for filtering.
 * @return array Associative array containing the highest attended program for youth and adult.
 */
function getHighestAttendedPrograms($conn, $start_date, $end_date) {
    $highestAttendance = [
        'youth' => ['program' => 'PLACEHOLDER', 'attendees' => 0],
        'adult' => ['program' => 'PLACEHOLDER', 'attendees' => 0]
    ];

    $sql = "
        SELECT s.program, s.attendance, t.name AS team_name
        FROM scores s
        LEFT JOIN teams t ON s.team_id = t.id
        WHERE s.program_date BETWEEN ? AND ?
        ORDER BY s.attendance DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $team = strtolower(trim($row['team_name'] ?? ''));
            if ($team === 'youth services' && $highestAttendance['youth']['attendees'] < $row['attendance']) {
                $highestAttendance['youth'] = [
                    'program'   => $row['program'],
                    'attendees' => $row['attendance']
                ];
            }
            if ($team === 'adult services' && $highestAttendance['adult']['attendees'] < $row['attendance']) {
                $highestAttendance['adult'] = [
                    'program'   => $row['program'],
                    'attendees' => $row['attendance']
                ];
            }
        }
    }
    return $highestAttendance;
}

// --- Function: Calculate Mean, Upper, and Lower Limits ---
/**
 * Calculates the mean and +/- 1 standard deviation for an array of scores.
 *
 * @param array $scores An array of numeric scores.
 * @return array [mean, upper_limit, lower_limit].
 */
function calculateStats($scores) {
    if (empty($scores)) {
        return [0, 0, 0]; // Prevent division by zero
    }
    $mean = array_sum($scores) / count($scores);
    $std_dev = sqrt(array_sum(array_map(fn($x) => pow($x - $mean, 2), $scores)) / count($scores));
    $upper_limit = $mean + $std_dev;
    $lower_limit = $mean - $std_dev;
    return [$mean, $upper_limit, $lower_limit];
}

// --- Ensure 'uploads/' directory exists for image uploads ---
$upload_dir = "uploads/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// --- Handle Image Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['report_image'])) {
    $file = $_FILES['report_image'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $filename    = basename($file['name']);
        $target_file = $upload_dir . $filename;
        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            // Store path in session
            $_SESSION['report_image'] = "/uploads/" . $filename;
        } else {
            echo "<p style='color: red;'>Error moving uploaded file.</p>";
        }
    } else {
        echo "<p style='color: red;'>File upload error: " . $file['error'] . "</p>";
    }
}

// --- Build and Execute Main Scores Query for the Table ---
$whereClauses = [];
$params       = [];
$types        = "";

if (!empty($start_date)) {
    $whereClauses[] = "program_date >= ?";
    $params[]       = $start_date;
    $types         .= "s";
}
if (!empty($end_date)) {
    $whereClauses[] = "program_date <= ?";
    $params[]       = $end_date;
    $types         .= "s";
}

$sql = "
    SELECT scores.id, users.name, teams.name AS team_name, scores.program, scores.total_score, 
           scores.adjusted_impact_score, scores.attendance, scores.program_date 
    FROM scores
    LEFT JOIN users ON scores.user_id = users.id
    LEFT JOIN teams ON scores.team_id = teams.id
    WHERE program_date >= ? AND program_date <= ?
    ORDER BY program_date DESC
";
$stmt = $conn->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
}
if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
} else {
    $result = null;
}

// --- Summaries for the Table ---
$total_programs = 0;
$total_attendance = 0;
$impact_scores_youth = [];
$impact_scores_adult = [];

$highestImpactScores      = getHighestImpactScores($conn, $start_date, $end_date);
$highestAttendedPrograms  = getHighestAttendedPrograms($conn, $start_date, $end_date);

// If query returned results, accumulate counts
if ($result !== null) {
    $total_programs = $result->num_rows;
    while ($row = $result->fetch_assoc()) {
        $total_attendance += $row['attendance'];
        $team = strtolower(trim($row['team_name'] ?? 'unknown'));

        if ($team === "youth services") {
            $impact_scores_youth[] = $row['adjusted_impact_score'];
        } elseif ($team === "adult services") {
            $impact_scores_adult[] = $row['adjusted_impact_score'];
        }
    }
}

// --- Youth & Adult Stats from Calculated Arrays ---
list($youth_mean, $youth_upper_limit, $youth_lower_limit) = calculateStats($youth_scores);
list($adult_mean, $adult_upper_limit, $adult_lower_limit) = calculateStats($adult_scores);

// Separate arrays for above/below limit scores in Youth
$aboveYouth = [];
$belowYouth = [];
foreach ($youthData as $yd) {
    if ($yd['score'] > $youth_upper_limit) {
        $aboveYouth[] = $yd;
    } elseif ($yd['score'] < $youth_lower_limit) {
        $belowYouth[] = $yd;
    }
}
// Sort and slice to get top/bottom 5
usort($aboveYouth, fn($a, $b) => $b['score'] <=> $a['score']); // Desc
$aboveYouth = array_slice($aboveYouth, 0, 5);
usort($belowYouth, fn($a, $b) => $a['score'] <=> $b['score']); // Asc
$belowYouth = array_slice($belowYouth, 0, 5);

// Separate arrays for above/below limit scores in Adult
$aboveAdult = [];
$belowAdult = [];
foreach ($adultData as $ad) {
    if ($ad['score'] > $adult_upper_limit) {
        $aboveAdult[] = $ad;
    } elseif ($ad['score'] < $adult_lower_limit) {
        $belowAdult[] = $ad;
    }
}
usort($aboveAdult, fn($a, $b) => $b['score'] <=> $a['score']); // Desc
$aboveAdult = array_slice($aboveAdult, 0, 5);
usort($belowAdult, fn($a, $b) => $a['score'] <=> $b['score']); // Asc
$belowAdult = array_slice($belowAdult, 0, 5);

// Create arrays of short dates for the X-axis
$youth_programs = [];
foreach ($youthData as $yd) {
    $shortDate       = date("m/d", strtotime($yd['date']));
    $youth_programs[] = $shortDate;
}
$adult_programs = [];
foreach ($adultData as $ad) {
    $shortDate       = date("m/d", strtotime($ad['date']));
    $adult_programs[] = $shortDate;
}

// Fetch specialty form stats
$specialtyCounts = getAllSpecialtyFormStats($conn, $start_date, $end_date);
// Format specialty counts
foreach ($specialtyCounts as $form => $data) {
    $specialtyCounts[$form] = $data['programs'] . " programs, " . $data['attendees'] . " attendees";
}

// Build the data structure for the chart
$programData = [
    'Youth' => [
        'programs'       => array_keys($impact_scores_youth),
        'impact_scores'  => array_values($impact_scores_youth),
        'dates'          => array_keys($impact_scores_youth)
    ],
    'Adult' => [
        'programs'       => array_keys($impact_scores_adult),
        'impact_scores'  => array_values($impact_scores_adult),
        'dates'          => array_keys($impact_scores_adult)
    ]
];

// Retrieve final sets for display
$impactScores  = getAverageImpactScores($conn, $start_date, $end_date);
$programStats  = getProgramStats($conn, $start_date, $end_date);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!--
      Programming Report
      This HTML section renders the entire report, including:
       - Date filters for the user to adjust the date range.
       - Display of highlight items, average impact scores, highest attendance, etc.
       - Process Control Charts for Youth and Adult services.
       - An optional image upload, if needed, plus a "Save as PDF" feature.
    -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programming Report</title>
    
    <!-- External CSS for Layout and Styling -->
    <link rel="stylesheet" type="text/css" href="css/programming_report_styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Chart.js for Charting -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const highlightList = document.getElementById("highlight-list");

            // Allow adding new list items on Enter
            highlightList.addEventListener("keypress", function (event) {
                if (event.key === "Enter") {
                    event.preventDefault();
                    let newItem = document.createElement("li");
                    newItem.textContent = "New Highlight...";
                    highlightList.appendChild(newItem);
                    let range = document.createRange();
                    let selection = window.getSelection();
                    range.selectNodeContents(newItem);
                    range.collapse(false);
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            });
        });
        
        
    </script>
</head>
<body>
    
<!-- Back to Admin Panel Button -->
<div style="margin-bottom: 20px;">
    <a href="stats.html" class="btn btn-secondary">⬅️ Back to Reports</a>
</div>

<!-- Main Report Container -->
<div id="reportContent">
    <div class="container">
        <center>
            <h2 class="section-title">📊 Programming Report (<?= htmlspecialchars($start_date) ?> - <?= htmlspecialchars($end_date) ?>)</h2>
        </center>

        <!-- Date Filters -->
        <div class="filter-container">
            <center>
                <form method="GET" action="report.php">
                    <label>Start Date:</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    <label>End Date:</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                    <button type="submit">Filter</button>
                </form>
            </center>
        </div>

        <div class="grid-container">
            <!-- Full-width Highlights Box -->
            <div class="grid-highlights" style="grid-column: span 3;">
                <label class="highlight-label" style="font-size: 24px; font-weight: bold;"><em>Highlights:</em></label>
                <ul id="highlight-list" contenteditable="true">
                    <li>Highlight 1</li>
                    <li>Highlight 2</li>
                    <li>Highlight 3</li>
                </ul>
            </div>

            <!-- Average Impact Scores Box -->
            <div class="metric-box">
                <h3><em><u>Average Impact Scores</u></em></h3>
                <p><strong>Youth:</strong> <?= number_format($impactScores['youth services'], 2) ?></p>
                <p><strong>Adult:</strong> <?= number_format($impactScores['adult services'], 2) ?></p>
            </div>

            <!-- Program Stats Box -->
            <div class="metric-box">
                <h3><em><u>Program Stats</u></em></h3>
                <p><strong>Total Programs:</strong> <?= $programStats['total_programs'] ?? 0 ?>, <?= $programStats['total_attendance'] ?? 0 ?> Attendees</p>
                <p><strong>Adult Services:</strong> <?= $programStats['adult_services']['programs'] ?? 0 ?> Programs, <?= $programStats['adult_services']['attendees'] ?? 0 ?> Attendees</p>
                <p><strong>Youth Services:</strong> <?= $programStats['youth_services']['programs'] ?? 0 ?> Programs, <?= $programStats['youth_services']['attendees'] ?? 0 ?> Attendees</p>
                <p><strong>One-on-Ones:</strong> <?= $programStats['one_on_ones']['programs'] ?? 0 ?> Programs, <?= $programStats['one_on_ones']['attendees'] ?? 0 ?> Attendees</p>
            </div>

            <!-- Highest Youth Impact Score -->
            <div class="metric-box">
                <h3><em><u>Highest Youth Impact Score</u></em></h3>
                <p><strong><?= htmlspecialchars($highestImpactScores['youth']['program']) ?></strong></p>
                <p>Impact Score: <?= $highestImpactScores['youth']['score'] ?> | Attendees: <?= $highestImpactScores['youth']['attendees'] ?></p>
            </div>

            <!-- Highest Adult Impact Score -->
            <div class="metric-box">
                <h3><em><u>Highest Adult Impact Score</u></em></h3>
                <p><strong><?= htmlspecialchars($highestImpactScores['adult']['program']) ?></strong></p>
                <p>Impact Score: <?= $highestImpactScores['adult']['score'] ?> | Attendees: <?= $highestImpactScores['adult']['attendees'] ?></p>
            </div>

            <!-- Highest Attended Youth -->
            <div class="metric-box">
                <h3><em><u>Highest Attended Youth</u></em></h3>
                <p><strong><?= htmlspecialchars($highestAttendedPrograms['youth']['program']) ?></strong></p>
                <p class="large-number" style="font-size: 60px; font-weight: bold; color: black;">
                    <?= $highestAttendedPrograms['youth']['attendees'] ?>
                </p>
            </div>

            <!-- Highest Attended Adult -->
            <div class="metric-box">
                <h3><em><u>Highest Attended Adult</u></em></h3>
                <p><strong><?= htmlspecialchars($highestAttendedPrograms['adult']['program']) ?></strong></p>
                <p class="large-number" style="font-size: 60px; font-weight: bold; color: black;">
                    <?= $highestAttendedPrograms['adult']['attendees'] ?>
                </p>
            </div>

            <!-- Signature / Specialty Programs Box -->
            <div class="specialty-box">
                <h3><em><u>Signature Programs</u></em></h3>
                <ul class="no-bullets">
                    <?php if (!empty($specialtyCounts)): ?>
                        <?php foreach ($specialtyCounts as $category => $count): ?>
                            <li><strong><?= htmlspecialchars($category) ?>:</strong> <?= $count ?></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li>No specialty programs recorded.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Process Control Charts -->
        <div class="grid-container">
            <!-- Title -->
            <div class="grid-highlights" style="grid-column: span 3;">
                <center><h1 class="process-control-charts">📈 Process Control Charts</h1></center>
            </div>

            <!-- Youth Chart -->
            <div style="grid-column: span 3; width: 100%; margin-bottom: 30px; height: auto;">
                <h3 style="text-align: center; font-weight: bold;">📘 Youth Services Impact Scores</h3>
                <canvas id="youthChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Adult Chart -->
            <div style="grid-column: span 3; width: 100%; margin-bottom: 30px; height: auto;">
                <h3 style="text-align: center; font-weight: bold;">📗 Adult Services Impact Scores</h3>
                <canvas id="adultChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Youth Above/Below Limit Tables -->
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <!-- Above Limit (Youth) -->
                <div>
                    <h3>Youth Services: Above Limit (Top 5)</h3>
                    <?php if (!empty($aboveYouth)): ?>
                        <table border="1" cellpadding="5" cellspacing="0">
                            <thead>
                                <tr><th>Date</th><th>Program</th><th>Score</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($aboveYouth as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date("m/d/Y", strtotime($item['date']))) ?></td>
                                        <td><?= htmlspecialchars($item['program']) ?></td>
                                        <td><?= htmlspecialchars($item['score']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No Youth programs above limit.</p>
                    <?php endif; ?>
                </div>

                <!-- Below Limit (Youth) -->
                <div>
                    <h3>Youth Services: Below Limit (Top 5)</h3>
                    <?php if (!empty($belowYouth)): ?>
                        <table border="1" cellpadding="5" cellspacing="0">
                            <thead>
                                <tr><th>Date</th><th>Program</th><th>Score</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($belowYouth as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date("m/d/Y", strtotime($item['date']))) ?></td>
                                        <td><?= htmlspecialchars($item['program']) ?></td>
                                        <td><?= htmlspecialchars($item['score']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No Youth programs below limit.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Adult Above/Below Limit Tables -->
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <!-- Above Limit (Adult) -->
                <div>
                    <h3>Adult Services: Above Limit (Top 5)</h3>
                    <?php if (!empty($aboveAdult)): ?>
                        <table border="1" cellpadding="5" cellspacing="0">
                            <thead>
                                <tr><th>Date</th><th>Program</th><th>Score</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($aboveAdult as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date("m/d/Y", strtotime($item['date']))) ?></td>
                                        <td><?= htmlspecialchars($item['program']) ?></td>
                                        <td><?= htmlspecialchars($item['score']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No Adult programs above limit.</p>
                    <?php endif; ?>
                </div>

                <!-- Below Limit (Adult) -->
                <div>
                    <h3>Adult Services: Below Limit (Top 5)</h3>
                    <?php if (!empty($belowAdult)): ?>
                        <table border="1" cellpadding="5" cellspacing="0">
                            <thead>
                                <tr><th>Date</th><th>Program</th><th>Score</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($belowAdult as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date("m/d/Y", strtotime($item['date']))) ?></td>
                                        <td><?= htmlspecialchars($item['program']) ?></td>
                                        <td><?= htmlspecialchars($item['score']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No Adult programs below limit.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Save as PDF Button -->
<button onclick="captureReport()">Save as PDF</button>

<!-- Load html2canvas and jsPDF for PDF Generation -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<!-- Capture and Split into Two-Page PDF -->
<script>
    async function captureReport() {
        const container = document.getElementById('reportContent');
        const heading = document.querySelector('.process-control-charts');
        if (!container || !heading) {
            console.error("Container or heading not found");
            return;
        }

        // 1) Capture entire container
        const fullCanvas = await html2canvas(container);

        // 2) Measure the offset of the heading
        const containerRect = container.getBoundingClientRect();
        const headingRect   = heading.getBoundingClientRect();
        // Add some offset if needed
        const headingOffset = (headingRect.top - containerRect.top) + 1300;

        // 3) Create two sub-canvas objects for top and bottom
        const topCanvas = document.createElement('canvas');
        topCanvas.width = fullCanvas.width;
        topCanvas.height = headingOffset;
        const topCtx = topCanvas.getContext('2d');
        topCtx.drawImage(fullCanvas, 0, 0, fullCanvas.width, headingOffset, 0, 0, fullCanvas.width, headingOffset);

        const bottomCanvas = document.createElement('canvas');
        bottomCanvas.width = fullCanvas.width;
        bottomCanvas.height = fullCanvas.height - headingOffset;
        const bottomCtx = bottomCanvas.getContext('2d');
        bottomCtx.drawImage(fullCanvas, 0, headingOffset, fullCanvas.width, fullCanvas.height - headingOffset, 0, 0, fullCanvas.width, fullCanvas.height - headingOffset);

        // 4) Convert each sub-canvas to an image
        const topImgData    = topCanvas.toDataURL('image/png');
        const bottomImgData = bottomCanvas.toDataURL('image/png');

        // 5) Use jsPDF to create two pages
        const { jsPDF } = window.jspdf;
        const pdf       = new jsPDF('p', 'pt', 'letter'); // 612 x 792
        const pageWidth  = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();

        // Scale top image
        const topScale = Math.min(pageWidth / topCanvas.width, pageHeight / topCanvas.height);
        const topW     = topCanvas.width * topScale;
        const topH     = topCanvas.height * topScale;
        const topX     = (pageWidth - topW) / 2;
        const topY     = (pageHeight - topH) / 2;
        pdf.addImage(topImgData, 'PNG', topX, topY, topW, topH);

        // Scale bottom image
        const bottomScale = Math.min(pageWidth / bottomCanvas.width, pageHeight / bottomCanvas.height);
        const botW        = bottomCanvas.width * bottomScale;
        const botH        = bottomCanvas.height * bottomScale;
        const botX        = (pageWidth - botW) / 2;
        const botY        = (pageHeight - botH) / 2;

        // Add second page
        pdf.addPage();
        pdf.addImage(bottomImgData, 'PNG', botX, botY, botW, botH);

        // 6) Save the PDF
        pdf.save("report_two_pages.pdf");
    }
</script>

<!-- Chart Initialization -->
<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Debugging Output
        console.log("Youth Labels:", <?= json_encode($youth_programs ?? []) ?>);
        console.log("Youth Scores:", <?= json_encode($youth_scores ?? []) ?>);
        console.log("Adult Labels:", <?= json_encode($adult_programs ?? []) ?>);
        console.log("Adult Scores:", <?= json_encode($adult_scores ?? []) ?>);

        const youthCanvas = document.getElementById('youthChart');
        const adultCanvas = document.getElementById('adultChart');

        if (!youthCanvas || !adultCanvas) {
            console.error("Error: One or more chart elements are missing.");
            return;
        }

        const youthCtx = youthCanvas.getContext('2d');
        const adultCtx = adultCanvas.getContext('2d');

        // Data from PHP
        const youthLabels  = <?= json_encode($youth_programs ?? []) ?>;
        const youthScores  = <?= json_encode($youth_scores ?? []) ?>;
        const adultLabels  = <?= json_encode($adult_programs ?? []) ?>;
        const adultScores  = <?= json_encode($adult_scores ?? []) ?>;

        const youthMean        = <?= json_encode($youth_mean) ?>;
        const youthUpperLimit  = <?= json_encode($youth_upper_limit) ?>;
        const youthLowerLimit  = <?= json_encode($youth_lower_limit) ?>;

        const adultMean        = <?= json_encode($adult_mean) ?>;
        const adultUpperLimit  = <?= json_encode($adult_upper_limit) ?>;
        const adultLowerLimit  = <?= json_encode($adult_lower_limit) ?>;

        // Program names for tooltip callbacks
        const youthProgramNames = <?= json_encode(array_column($youthData, 'program')) ?>;
        const adultProgramNames = <?= json_encode(array_column($adultData, 'program')) ?>;

        // Reusable function to create line charts
        function createChart(ctx, labels, scores, mean, upperLimit, lowerLimit, color, programNames) {
            if (labels.length === 0 || scores.length === 0) {
                console.warn("Skipping chart due to missing data.");
                return;
            }
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: "Adjusted Impact Score",
                            data: scores,
                            borderColor: "#6395CF", // RIVER
                            fill: false,
                            tension: 0.1
                        },
                        {
                            label: "Mean",
                            data: Array(labels.length).fill(mean),
                            borderColor: "#7DB652", // GRASS
                            borderDash: [5, 5],
                            pointRadius: 0
                        },
                        {
                            label: "Upper Limit (+1 SD)",
                            data: Array(labels.length).fill(upperLimit),
                            borderColor: "#E4781F", // PUMPKIN
                            borderDash: [5, 5],
                            pointRadius: 0
                        },
                        {
                            label: "Lower Limit (-1 SD)",
                            data: Array(labels.length).fill(lowerLimit),
                            borderColor: "#E4781F", // PUMPKIN
                            borderDash: [5, 5],
                            pointRadius: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const idx     = context.dataIndex;
                                    const score   = context.parsed.y;
                                    const program = programNames[idx] || "Program";
                                    return program + ": " + score;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: { display: false },
                            grid:  { display: false }
                        },
                        y: {
                            beginAtZero: false
                        }
                    }
                }
            });
        }

        // Create Youth and Adult Charts
        createChart(youthCtx, youthLabels, youthScores, youthMean, youthUpperLimit, youthLowerLimit, "blue", youthProgramNames);
        createChart(adultCtx, adultLabels, adultScores, adultMean, adultUpperLimit, adultLowerLimit, "green", adultProgramNames);
    });
</script>
</body>
</html>
