<?php
/**
 * report2.php / programming_report.php
 *
 * Programming Impact Report (Annual-Report Style)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log_programming_report.txt');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// --- Database connection ---
require_once 'secure/db_connection.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("❌ Database connection failed: " . $conn->connect_error);
}

// ---------- Date range ----------
$default_start = date('Y-m-d', strtotime('-1 month'));
$default_end   = date('Y-m-d');

$start_date = $_GET['start_date'] ?? $_POST['start_date'] ?? $default_start;
$end_date   = $_GET['end_date']   ?? $_POST['end_date']   ?? $default_end;

$start_date = date('Y-m-d', strtotime($start_date));
$end_date   = date('Y-m-d', strtotime($end_date));

/**
 * ---------- Dynamic Event Types ----------
 * scoring_options.question_id = 4; option_points used in score_responses.points
 */
$ALL_EVENT_TYPES   = []; // [label => value]
$EVENT_TYPE_LABELS = []; // [value => label]

$evtSql = "
    SELECT option_text, option_points
    FROM scoring_options
    WHERE question_id = 4
    ORDER BY option_points ASC, option_text ASC
";
if ($evtRes = $conn->query($evtSql)) {
    while ($erow = $evtRes->fetch_assoc()) {
        $label = trim($erow['option_text'] ?? '');
        $val   = (int)($erow['option_points'] ?? 0);
        if ($label === '') continue;

        $ALL_EVENT_TYPES[$label] = $val;
        $EVENT_TYPE_LABELS[$val] = $label;
    }
    $evtRes->free();
}
if (empty($ALL_EVENT_TYPES)) {
    // Safety fallback
    $ALL_EVENT_TYPES = [
        'Outreach'                              => 1,
        'Exhibit'                               => 0,
        'Larger audience (21 or more)'          => 2,
        'Small group instruction (20 or fewer)' => 5,
        'One-on-one'                            => 10
    ];
    $EVENT_TYPE_LABELS = array_flip($ALL_EVENT_TYPES);
}

/**
 * ---------- Report Config Persistence ----------
 */
function loadReportConfig(mysqli $conn, string $start_date, string $end_date): array {
    $config = [
        'signatures'  => [],
        'highlights'  => [],
        'photos'      => [],
        'event_types' => []
    ];

    $sql = "SELECT config_json FROM programming_reports WHERE start_date = ? AND end_date = ? LIMIT 1";
    if (!$stmt = $conn->prepare($sql)) return $config;

    $stmt->bind_param("ss", $start_date, $end_date);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $decoded = json_decode($row['config_json'] ?? '', true);
            if (is_array($decoded)) {
                $config = array_merge($config, $decoded);
            }
        }
    }
    $stmt->close();
    return $config;
}

function saveReportConfig(mysqli $conn, string $start_date, string $end_date, array $config): void {
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);
    if ($json === false) return;

    $sqlSelect = "SELECT id FROM programming_reports WHERE start_date = ? AND end_date = ? LIMIT 1";
    if (!$stmt = $conn->prepare($sqlSelect)) return;

    $stmt->bind_param("ss", $start_date, $end_date);
    if (!$stmt->execute()) { $stmt->close(); return; }

    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $id = (int)$row['id'];
        $stmt->close();

        $sqlUpdate = "UPDATE programming_reports SET config_json = ? WHERE id = ?";
        if ($stmt2 = $conn->prepare($sqlUpdate)) {
            $stmt2->bind_param("si", $json, $id);
            $stmt2->execute();
            $stmt2->close();
        }
    } else {
        $stmt->close();

        $sqlInsert = "INSERT INTO programming_reports (start_date, end_date, config_json) VALUES (?, ?, ?)";
        if ($stmt2 = $conn->prepare($sqlInsert)) {
            $stmt2->bind_param("sss", $start_date, $end_date, $json);
            $stmt2->execute();
            $stmt2->close();
        }
    }
}

// Load config
$reportConfig = loadReportConfig($conn, $start_date, $end_date);

// ---------- Event type selection bootstrap (before any POST logic) ----------
$allEventTypeValues = array_values($ALL_EVENT_TYPES);
$selectedEventTypes = [];

// Start from whatever is in the saved config (if any)
if (!empty($reportConfig['event_types']) && is_array($reportConfig['event_types'])) {
    $selectedEventTypes = array_values(
        array_intersect(
            array_map('intval', $reportConfig['event_types']),
            $allEventTypeValues
        )
    );
}

// On an initial GET (no saved config yet), default to ALL event types.
// On POST we will trust whatever the form sent, even if empty.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($selectedEventTypes)) {
    $selectedEventTypes = $allEventTypeValues;
    $reportConfig['event_types'] = $selectedEventTypes;
}


/**
 * Event type WHERE fragment
 */
function buildEventTypeSql(array $selectedEventTypes, array $allEventTypeValues): string {
    $selectedEventTypes = array_unique(array_map('intval', $selectedEventTypes));
    $allEventTypeValues = array_unique(array_map('intval', $allEventTypeValues));
    sort($selectedEventTypes);
    sort($allEventTypeValues);

    if ($selectedEventTypes === $allEventTypeValues || empty($selectedEventTypes)) {
        return "";
    }

    $safe = implode(",", $selectedEventTypes);
    return " AND et.event_type IN ($safe) ";
}

// ---------- Handle POST: editor save ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {

        // Event types
    // NOTE: if the user unchecks everything, event_types[] will NOT exist in $_POST.
    if (array_key_exists('event_types', $_POST)) {
        $posted = $_POST['event_types'];
        if (!is_array($posted)) {
            $posted = [];
        }
    } else {
        // Form was submitted but no event_types[] key => user unchecked them all.
        $posted = [];
    }

    $postedEventTypes = array_values(
        array_intersect(
            array_map('intval', $posted),
            $allEventTypeValues
        )
    );

    // IMPORTANT: do NOT auto-fill an empty selection with "all";
    // once a row exists for this date range, we trust whatever the user chose.
    $reportConfig['event_types'] = $postedEventTypes;
    $selectedEventTypes          = $postedEventTypes;


    // Signature programs
    $selectedSignatures = $_POST['signature_programs'] ?? [];
    if (!is_array($selectedSignatures)) $selectedSignatures = [];
    $reportConfig['signatures'] = $selectedSignatures;

    // Highlights
    $highlightTexts    = $_POST['highlight_text'] ?? [];
    $highlightPrograms = $_POST['highlight_program'] ?? [];
    $highlights        = [];

    if (is_array($highlightTexts) && is_array($highlightPrograms)) {
        $count = max(count($highlightTexts), count($highlightPrograms));
        for ($i = 0; $i < $count; $i++) {
            $text = trim($highlightTexts[$i] ?? '');
            $prog = trim($highlightPrograms[$i] ?? '');
            if ($text === '') continue;
            $highlights[] = ['text' => $text, 'program' => $prog];
        }
    }
    $reportConfig['highlights'] = $highlights;

    // Photos (existing)
    $existingPhotos = $reportConfig['photos'] ?? [];
    $updatedPhotos  = [];

    $postedUrl     = $_POST['photo_url']       ?? [];
    $postedCaption = $_POST['photo_caption']   ?? [];
    $postedPlace   = $_POST['photo_placement'] ?? [];
    $deletePhoto   = $_POST['delete_photo']    ?? [];

    foreach ($existingPhotos as $idx => $photo) {
        if (isset($deletePhoto[$idx])) continue;

        $path = trim($postedUrl[$idx] ?? $photo['path'] ?? '');
        if ($path === '') continue;

        $caption   = trim($postedCaption[$idx] ?? ($photo['caption'] ?? ''));
        $placement = $postedPlace[$idx] ?? ($photo['placement'] ?? 'top');
        if (!in_array($placement, ['top','after_highlights','after_charts','end'], true)) {
            $placement = 'top';
        }

        $updatedPhotos[] = [
            'path'      => $path,
            'caption'   => $caption,
            'placement' => $placement
        ];
    }

    // New uploads
    if (!empty($_FILES['report_photos']) && is_array($_FILES['report_photos']['name'])) {
        $publicDir = "uploads/report_photos/";
        if (!is_dir($publicDir)) mkdir($publicDir, 0755, true);

        $names = $_FILES['report_photos']['name'];
        $tmp   = $_FILES['report_photos']['tmp_name'];
        $errs  = $_FILES['report_photos']['error'];

        for ($i = 0; $i < count($names); $i++) {
            if ($errs[$i] !== UPLOAD_ERR_OK) continue;
            $basename = basename($names[$i]);
            $ext      = pathinfo($basename, PATHINFO_EXTENSION);
            $safeName = uniqid('rp_', true) . ($ext ? "." . $ext : '');
            $publicTarget = $publicDir . $safeName;
            if (move_uploaded_file($tmp[$i], $publicTarget)) {
                $updatedPhotos[] = [
                    'path'      => '/' . $publicTarget,
                    'caption'   => '',
                    'placement' => 'top'
                ];
            }
        }
    }

    // New photo via URL
    if (!empty($_POST['new_photo_url'])) {
        $url = trim($_POST['new_photo_url']);
        if ($url !== '') {
            $updatedPhotos[] = [
                'path'      => $url,
                'caption'   => trim($_POST['new_photo_caption'] ?? ''),
                'placement' => $_POST['new_photo_placement'] ?? 'top'
            ];
        }
    }

    $reportConfig['photos'] = $updatedPhotos;

    // Save to DB
    saveReportConfig($conn, $start_date, $end_date, $reportConfig);
}


// ---------- Helper for SPC stats ----------
function calculateStats(array $scores): array {
    if (empty($scores)) return [0, 0, 0];
    $mean = array_sum($scores) / count($scores);
    $std  = sqrt(array_sum(array_map(fn($x) => ($x - $mean) ** 2, $scores)) / count($scores));
    return [$mean, $mean + $std, $mean - $std];
}

// ---------- Main data query ----------
$eventTypeWhere = buildEventTypeSql($selectedEventTypes, $allEventTypeValues);

$sql = "
    SELECT
        s.id,
        s.program,
        s.program_date,
        s.adjusted_impact_score,
        s.attendance,
        l.name AS location_name,
        t.name AS team_name,
        fp.name AS form_name,
        et.event_type AS event_type,
        et.event_type_label AS event_type_label
    FROM scores s
    LEFT JOIN teams t
        ON s.team_id = t.id
    LEFT JOIN locations l
        ON s.location_id = l.id
    LEFT JOIN form_profiles fp
        ON s.form_id = fp.id
    LEFT JOIN (
        SELECT score_id, points AS event_type, response AS event_type_label
        FROM score_responses
        WHERE question_id = 4
    ) AS et ON et.score_id = s.id
    WHERE s.program_date BETWEEN ? AND ?
      $eventTypeWhere
    ORDER BY s.program_date ASC, s.id ASC
";


$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

// ---------- Derive all stats ----------
$totalPrograms   = 0;  // ✅ programming totals (EXCLUDING outreach)
$totalAttendance = 0;  // ✅ programming totals (EXCLUDING outreach)
$totalImpactSum  = 0;  // ✅ programming totals (EXCLUDING outreach)
$totalImpactCnt  = 0;  // ✅ programming totals (EXCLUDING outreach)

$teamStats      = []; // ✅ EXCLUDING outreach
$eventTypeStats = []; // ✅ INCLUDING outreach (display-only mix table)

$oneOnOne = ['programs'=>0,'attendance'=>0,'impact_sum'=>0,'impact_count'=>0];
$outreach = ['programs'=>0,'attendance'=>0,'impact_sum'=>0,'impact_count'=>0];

$monthlyStats   = []; // ✅ EXCLUDING outreach
$programAgg     = []; // ✅ EXCLUDING outreach
$specialtyForms = []; // ✅ EXCLUDING outreach
$programNameSet = []; // ✅ EXCLUDING outreach

$youthData = [];      // ✅ EXCLUDING outreach
$adultData = [];      // ✅ EXCLUDING outreach

foreach ($rows as $r) {

    $attendance   = (int)($r['attendance'] ?? 0);
    $score        = is_null($r['adjusted_impact_score']) ? null : (float)$r['adjusted_impact_score'];
    $teamName     = trim($r['team_name'] ?? 'Unassigned');
    $formName     = trim($r['form_name'] ?? '');
    $progName     = trim($r['program'] ?? '');
    $eventTypeRaw   = (int)($r['event_type'] ?? -1);
    $eventTypeLabel = trim((string)($r['event_type_label'] ?? ''));

    // Prefer the stored label (unique per option) over the points-based reverse
    // lookup, because option_points is NOT unique — Exhibit=1 and Outreach=1
    // both map to the same key in $EVENT_TYPE_LABELS, which caused Exhibit
    // events to be relabeled "Outreach" and silently excluded from totals.
    if ($eventTypeLabel !== '' && isset($ALL_EVENT_TYPES[$eventTypeLabel])) {
        $eventType = $eventTypeLabel;
    } else {
        $eventType = $EVENT_TYPE_LABELS[$eventTypeRaw] ?? 'Unspecified';
    }

    // ✅ Location-based outreach flag (backup to event type)
    $locationName = strtolower(trim($r['location_name'] ?? ''));
    $isOutreachLocation = ($locationName === 'outreach');
    


    // ✅ Primary outreach flag: either Event Type Outreach OR Location Outreach
    $isOutreach = ($eventType === 'Outreach') || $isOutreachLocation;

    // -----------------------------
    // Event Type stats: INCLUDE outreach (this is "mix" reporting)
    // -----------------------------
    if (!isset($eventTypeStats[$eventType])) {
        $eventTypeStats[$eventType] = ['programs'=>0,'attendance'=>0];
    }
    $eventTypeStats[$eventType]['programs']++;
    $eventTypeStats[$eventType]['attendance'] += $attendance;

    // -----------------------------
    // Outreach bucket: always track it, but DO NOT let it affect programming totals
    // -----------------------------
    if ($isOutreach) {
        $outreach['programs']++;
        $outreach['attendance'] += $attendance;
        if (!is_null($score) && $score > 0) {
            $outreach['impact_sum']   += $score;
            $outreach['impact_count'] += 1;
        }
        continue; // ✅ KEY LINE: outreach rows do not flow into totals/charts/team stats/etc.
    }

    // -----------------------------
    // From here down: "PROGRAMMING TOTALS" (excludes outreach)
    // -----------------------------
    $totalPrograms++;
    $totalAttendance += $attendance;

    if (!is_null($score) && $score > 0) {
        $totalImpactSum += $score;
        $totalImpactCnt++;
    }

    // Team stats (exclude outreach)
    if (!isset($teamStats[$teamName])) {
        $teamStats[$teamName] = ['programs'=>0,'attendance'=>0,'impact_sum'=>0,'impact_count'=>0];
    }
    $teamStats[$teamName]['programs']++;
    $teamStats[$teamName]['attendance'] += $attendance;
    if (!is_null($score) && $score > 0) {
        $teamStats[$teamName]['impact_sum']   += $score;
        $teamStats[$teamName]['impact_count'] += 1;
    }

    // One-on-one (exclude outreach already)
    if ($eventType === 'One-on-one') {
        $oneOnOne['programs']++;
        $oneOnOne['attendance'] += $attendance;
        if (!is_null($score) && $score > 0) {
            $oneOnOne['impact_sum']   += $score;
            $oneOnOne['impact_count'] += 1;
        }
    }

    // Monthly (exclude outreach)
    $ym = date('Y-m', strtotime($r['program_date']));
    if (!isset($monthlyStats[$ym])) {
        $monthlyStats[$ym] = ['impact_sum'=>0,'impact_count'=>0,'attendance'=>0];
    }
    $monthlyStats[$ym]['attendance'] += $attendance;
    if (!is_null($score) && $score > 0) {
        $monthlyStats[$ym]['impact_sum']   += $score;
        $monthlyStats[$ym]['impact_count'] += 1;
    }

    // Program aggregates / top programs / signature program stats (exclude outreach)
    if ($progName !== '') {
        if (!isset($programAgg[$progName])) {
            $programAgg[$progName] = [
                'attendance'=>0,'impact_max'=>null,'impact_sum'=>0,'impact_count'=>0,'team'=>$teamName
            ];
        }
        $programAgg[$progName]['attendance'] += $attendance;

        if (!is_null($score) && $score > 0) {
            $programAgg[$progName]['impact_sum']   += $score;
            $programAgg[$progName]['impact_count'] += 1;

            if ($programAgg[$progName]['impact_max'] === null || $score > $programAgg[$progName]['impact_max']) {
                $programAgg[$progName]['impact_max'] = $score;
            }
        }
        $programNameSet[$progName] = true;
    }

    // Specialty (non-program) forms (exclude outreach)
    if ($formName !== '' && strtolower($formName) !== 'program') {
        if (!isset($specialtyForms[$formName])) {
            $specialtyForms[$formName] = ['programs'=>0,'attendance'=>0];
        }
        $specialtyForms[$formName]['programs']++;
        $specialtyForms[$formName]['attendance'] += $attendance;
    }

    // Youth / Adult SPC (exclude outreach)
    if (strtolower($teamName) === 'youth services' && $score !== null) {
        $youthData[] = ['program'=>$progName,'date'=>$r['program_date'],'score'=>$score,'attendance'=>$attendance];
    }
    if (strtolower($teamName) === 'adult services' && $score !== null) {
        $adultData[] = ['program'=>$progName,'date'=>$r['program_date'],'score'=>$score,'attendance'=>$attendance];
    }
}


// programs list for editor
$allProgramsForSelect = array_keys($programNameSet);
sort($allProgramsForSelect);

// prune signatures that don't exist in this date range
if (!empty($reportConfig['signatures'])) {
    $reportConfig['signatures'] = array_values(
        array_intersect($reportConfig['signatures'], $allProgramsForSelect)
    );
}

// Overall averages
$overallAvgImpact = ($totalImpactCnt > 0) ? ($totalImpactSum / $totalImpactCnt) : 0;

// Team averages
foreach ($teamStats as $teamName => $stat) {
    $avg = ($stat['impact_count'] > 0) ? ($stat['impact_sum'] / $stat['impact_count']) : 0;
    $teamStats[$teamName]['avg_impact'] = $avg;
}

// One-on-one & outreach averages
$oneOnOneAvg = ($oneOnOne['impact_count'] > 0)
    ? ($oneOnOne['impact_sum'] / $oneOnOne['impact_count']) : 0;
$outreachAvg = ($outreach['impact_count'] > 0)
    ? ($outreach['impact_sum'] / $outreach['impact_count']) : 0;

// Monthly chart arrays
ksort($monthlyStats);
$monthLabels         = [];
$monthImpactAverages = [];
$monthAttendance     = [];
foreach ($monthlyStats as $ym => $mStat) {
    $monthLabels[] = date('M Y', strtotime($ym . '-01'));
    $avg = ($mStat['impact_count'] > 0) ? ($mStat['impact_sum'] / $mStat['impact_count']) : 0;
    $monthImpactAverages[] = $avg;
    $monthAttendance[]     = $mStat['attendance'];
}

// SPC + top/bottom 5
$youth_scores = array_map(fn($d) => $d['score'], $youthData);
$adult_scores = array_map(fn($d) => $d['score'], $adultData);

list($youth_mean, $youth_upper_limit, $youth_lower_limit) = calculateStats($youth_scores);
list($adult_mean, $adult_upper_limit, $adult_lower_limit) = calculateStats($adult_scores);

$splitAboveBelow = function(array $data, float $upper, float $lower): array {
    $above = [];
    $below = [];
    foreach ($data as $item) {
        if ($item['score'] > $upper)      $above[] = $item;
        elseif ($item['score'] < $lower)  $below[] = $item;
    }
    usort($above, fn($a,$b) => $b['score'] <=> $a['score']);
    usort($below, fn($a,$b) => $a['score'] <=> $b['score']);
    return [array_slice($above,0,5), array_slice($below,0,5)];
};

list($aboveYouth, $belowYouth) = $splitAboveBelow($youthData, $youth_upper_limit, $youth_lower_limit);
list($aboveAdult, $belowAdult) = $splitAboveBelow($adultData, $adult_upper_limit, $adult_lower_limit);

$youthLabels       = array_map(fn($d) => date('m/d', strtotime($d['date'])), $youthData);
$adultLabels       = array_map(fn($d) => date('m/d', strtotime($d['date'])), $adultData);
$youthProgramNames = array_map(fn($d) => $d['program'], $youthData);
$adultProgramNames = array_map(fn($d) => $d['program'], $adultData);

// Top programs lists
$topImpactPrograms = [];
foreach ($programAgg as $progName => $data) {
    $data['program'] = $progName;
    $topImpactPrograms[] = $data;
}
usort($topImpactPrograms, fn($a,$b) => ($b['impact_max'] ?? 0) <=> ($a['impact_max'] ?? 0));
$topImpactPrograms = array_slice($topImpactPrograms, 0, 5);

$topAttendancePrograms = [];
foreach ($programAgg as $progName => $data) {
    $data['program'] = $progName;
    $topAttendancePrograms[] = $data;
}
usort($topAttendancePrograms, fn($a,$b) => ($b['attendance'] ?? 0) <=> ($a['attendance'] ?? 0));
$topAttendancePrograms = array_slice($topAttendancePrograms, 0, 5);

// Photos by placement
$photos = $reportConfig['photos'] ?? [];
$photosTop             = array_filter($photos, fn($p) => ($p['placement'] ?? 'top') === 'top');
$photosAfterHighlights = array_filter($photos, fn($p) => ($p['placement'] ?? '') === 'after_highlights');
$photosAfterCharts     = array_filter($photos, fn($p) => ($p['placement'] ?? '') === 'after_charts');
$photosEnd             = array_filter($photos, fn($p) => ($p['placement'] ?? '') === 'end');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Programming Impact Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/programming_report_styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600&family=Domine:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* same styles as before – keeping them for brevity */
        body{margin:0;padding:0;font-family:"Montserrat",system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f2ec;color:#222}
        .page{max-width:900px;margin:0 auto;padding:24px 24px 48px;background:#fdfbf7;box-shadow:0 0 4px rgba(0,0,0,0.1)}
        h1,h2,h3{font-family:"Domine","Georgia",serif;margin-top:0}
        .report-header{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #d3c4a4;padding-bottom:12px;margin-bottom:18px}
        .report-header-left{font-size:.9rem;letter-spacing:.08em;text-transform:uppercase;color:#666}
        .report-title{font-size:1.8rem;line-height:1.3}
        .report-dates{font-size:.9rem;color:#555}
        .btn{display:inline-block;background:#b98943;color:#fff;border-radius:3px;padding:6px 10px;font-size:.85rem;text-decoration:none;border:none;cursor:pointer}
        .btn-secondary{background:#666}
        .section{margin:24px 0;page-break-inside:avoid}
        .section-heading{font-size:1.2rem;margin-bottom:8px;border-bottom:1px solid #e2d5be;padding-bottom:4px}
        .metric-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:8px}
        .metric-card{background:#fbf8f1;border-radius:4px;padding:10px 12px;border:1px solid #e6dcc9}
        .metric-label{font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:#7b6c4a;margin-bottom:4px}
        .metric-value{font-size:1.3rem;font-weight:600}
        .metric-sub{font-size:.8rem;color:#666}
        .highlights-list{list-style:none;padding-left:0;margin:0}
        .highlights-list li{margin-bottom:6px;padding-left:12px;border-left:2px solid #d1b479}
        .photo-grid{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}
        .photo-grid figure{margin:0;max-width:48%}
        .photo-grid img{width:100%;height:auto;border-radius:4px;border:1px solid #ddd}
        .photo-grid figcaption{font-size:.75rem;margin-top:3px;color:#555}
        .two-column{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
        table{width:100%;border-collapse:collapse;font-size:.8rem}
        th,td{border:1px solid #eee2cf;padding:6px 6px}
        th{background:#f3ebdd;text-align:left}
        tr:nth-child(even) td{background:#fcf8f0}
        .editor-panel{margin:0 auto 20px;max-width:900px;background:#f0ece4;border-bottom:1px solid #d1c4aa;padding:12px 20px 16px}
        .editor-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;overflow-x:hidden}
        .editor-box{background:#fdfbf7;border-radius:4px;border:1px solid #e0d5c0;padding:8px 10px;font-size:.8rem;max-height:260px;overflow-y:auto}
        .editor-box h4{margin:0 0 4px;font-size:.9rem}
        /* Safari/Edge grid sizing fix: prevent weird shrink-to-min-content */
.editor-grid > .editor-box {
  min-width: 0;
}

/* Ensure the flex children in highlight rows don't force weird intrinsic sizing */
.highlight-row textarea,
.highlight-row select {
  min-width: 0;
  width: 100%;
}

        .signature-list,.event-type-list{max-height:180px;overflow-y:auto;border:1px solid #eee0c6;padding:6px}
        .signature-list label,.event-type-list label{display:block;white-space:normal;line-height:1.2;margin-bottom:4px}
        .highlight-row{display:flex;gap:4px;margin-bottom:4px}
        .highlight-row textarea{flex:2;font-size:.8rem}
        .highlight-row select{flex:1;font-size:.8rem}
        .photo-list-item{display:flex;gap:6px;margin-bottom:6px}
        .photo-meta input,.photo-meta select{width:100%;font-size:.75rem;margin-bottom:2px}
        .small{font-size:.75rem}
        .chart-container{width:100%;height:280px;position:relative;margin-bottom:16px}
        @media print{body{background:#fff}.page{box-shadow:none;margin:0;padding:16px 20px}.editor-panel,.no-print{display:none!important}a{text-decoration:none;color:inherit}}
    

    
    
    </style>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const addHighlightBtn = document.getElementById("add-highlight-row");
            if (addHighlightBtn) {
                addHighlightBtn.addEventListener("click", function (e) {
                    e.preventDefault();
                    const container = document.getElementById("highlight-rows");
                    if (!container) return;
                    const template = container.querySelector(".highlight-row-template");
                    if (!template) return;
                    const clone = template.cloneNode(true);
                    clone.classList.remove("highlight-row-template");
                    clone.style.display = "flex";
                    const txt = clone.querySelector("textarea");
                    const sel = clone.querySelector("select");
                    if (txt) txt.value = "";
                    if (sel) sel.selectedIndex = 0;
                    container.appendChild(clone);
                });
            }
        });
        function printReport(){window.print();}
    </script>
</head>
<body>

<!-- EDITOR -->
<div class="editor-panel no-print">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div>
            <strong>Programming Report Editor</strong><br>
            <span class="small">
                Adjust highlights, signatures, photos, and event types. Settings are saved per date range.
                Changes take effect when you click <em>Save Report Settings</em>.
            </span>
        </div>
        <div>
            <a href="stats.html" class="btn btn-secondary small">⬅ Back to Reports</a>
        </div>
    </div>

    <!-- POST editor form -->
    <form id="editorForm" method="post" enctype="multipart/form-data">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
        <input type="hidden" name="end_date"   value="<?= htmlspecialchars($end_date) ?>">
        <input type="hidden" name="save_report" value="1">

        <div class="editor-grid">
            <!-- Signature Programs -->
            <div class="editor-box">
                <h4>Signature Programs</h4>
                <p class="small">Select programs you want to feature. Only programs in this date range are shown.</p>
                <div class="signature-list small">
                    <?php if (!empty($allProgramsForSelect)): ?>
                        <?php foreach ($allProgramsForSelect as $prog): ?>
                            <?php $checked = in_array($prog, $reportConfig['signatures'] ?? [], true) ? 'checked' : ''; ?>
                            <label>
                                <input type="checkbox" name="signature_programs[]" value="<?= htmlspecialchars($prog) ?>" <?= $checked ?>>
                                <?= htmlspecialchars($prog) ?>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <em>No programs found in this date range.</em>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Event Types -->
            <div class="editor-box">
                <h4>Event Types Included</h4>
                <p class="small">Only selected event types are included in stats and charts.</p>
                <div class="event-type-list small">
                    <?php foreach ($ALL_EVENT_TYPES as $label => $value): ?>
                        <?php $checked = in_array((int)$value, $selectedEventTypes, true) ? 'checked' : ''; ?>
                        <label>
                            <input type="checkbox" name="event_types[]" value="<?= (int)$value ?>" <?= $checked ?>>
                            <?= htmlspecialchars($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Highlights -->
            <div class="editor-box">
                <h4>Highlights</h4>
                <p class="small">Add brief narrative highlights and optionally connect them to a program.</p>
                <div id="highlight-rows">
                    <?php $existingHighlights = $reportConfig['highlights'] ?? []; ?>
                    <?php if (!empty($existingHighlights)): ?>
                        <?php foreach ($existingHighlights as $h): ?>
                            <div class="highlight-row">
                                <textarea name="highlight_text[]" rows="2" placeholder="Highlight text..."><?= htmlspecialchars($h['text'] ?? '') ?></textarea>
                                <select name="highlight_program[]">
                                    <option value="">(No program)</option>
                                    <?php foreach ($allProgramsForSelect as $prog): ?>
                                        <option value="<?= htmlspecialchars($prog) ?>" <?= (!empty($h['program']) && $h['program'] === $prog) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prog) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="highlight-row">
                            <textarea name="highlight_text[]" rows="2" placeholder="Highlight text..."></textarea>
                            <select name="highlight_program[]">
                                <option value="">(No program)</option>
                                <?php foreach ($allProgramsForSelect as $prog): ?>
                                    <option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="highlight-row highlight-row-template" style="display:none;">
                        <textarea name="highlight_text[]" rows="2" placeholder="Highlight text..."></textarea>
                        <select name="highlight_program[]">
                            <option value="">(No program)</option>
                            <?php foreach ($allProgramsForSelect as $prog): ?>
                                <option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button id="add-highlight-row" class="btn small" style="margin-top:4px;">+ Add Highlight</button>
            </div>

            <!-- Photos -->
            <div class="editor-box">
                <h4>Photos</h4>
                <p class="small">Upload photos or paste URLs. Choose where they appear in the report.</p>

                <div class="small" style="margin-bottom:4px;">
                    <label>Upload new photos:</label><br>
                    <input type="file" name="report_photos[]" multiple accept="image/*">
                </div>

                <div class="small" style="margin-top:6px;">
                    <strong>Add photo by URL</strong>
                    <div class="photo-list-item">
                        <div class="photo-meta">
                            <input type="text" name="new_photo_url" placeholder="https://example.com/image.jpg">
                            <input type="text" name="new_photo_caption" placeholder="Caption (optional)">
                            <select name="new_photo_placement">
                                <option value="top">Top of report</option>
                                <option value="after_highlights">After Highlights</option>
                                <option value="after_charts">After Charts</option>
                                <option value="end">End of report</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php if (!empty($photos)): ?>
                    <div class="small" style="margin-top:6px;"><strong>Existing Photos</strong></div>
                    <?php foreach ($photos as $idx => $photo): ?>
                        <div class="photo-list-item small">
                            <img src="<?= htmlspecialchars($photo['path']) ?>" alt="Photo <?= $idx+1 ?>" style="width:50px;height:50px;object-fit:cover;border:1px solid #ccc;">
                            <div class="photo-meta">
                                <input type="text" name="photo_url[<?= $idx ?>]" value="<?= htmlspecialchars($photo['path']) ?>" placeholder="Photo URL">
                                <input type="text" name="photo_caption[<?= $idx ?>]" value="<?= htmlspecialchars($photo['caption'] ?? '') ?>" placeholder="Caption">
                                <select name="photo_placement[<?= $idx ?>]">
                                    <?php
                                    $placement = $photo['placement'] ?? 'top';
                                    $options = [
                                        'top'             => 'Top of report',
                                        'after_highlights'=> 'After Highlights',
                                        'after_charts'    => 'After Charts',
                                        'end'             => 'End of report'
                                    ];
                                    foreach ($options as $val => $label):
                                    ?>
                                        <option value="<?= $val ?>" <?= ($placement === $val) ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="small">
                                    <input type="checkbox" name="delete_photo[<?= $idx ?>]" value="1"> Delete
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div><!-- /editor-grid -->

        <!-- Save/Print -->
        <div style="margin-top:8px;display:flex;justify-content:flex-end;gap:8px;">
            <button type="submit" class="btn">💾 Save Report Settings</button>
            <button type="button" class="btn btn-secondary" onclick="printReport()">🖨 Print / Save as PDF</button>
        </div>
    </form>

    <!-- Date-range GET form -->
    <div class="small" style="display:flex;align-items:center;gap:6px;margin:12px 0 8px 0;">
        <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" style="display:flex;align-items:center;gap:6px;">
            <label>Start:</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            <label>End:</label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            <button type="submit" class="btn small">Apply</button>
        </form>
    </div>
</div><!-- /editor-panel -->

<!-- MAIN REPORT -->
<div class="page" id="reportContent">
    <div class="report-header">
        <div class="report-header-left">
            SPOKANE PUBLIC LIBRARY<br>
            <span style="font-weight:600;">Programming Impact</span>
        </div>
        <div style="text-align:right;">
            <div class="report-title">Programming Impact Report</div>
            <div class="report-dates">
                <?= htmlspecialchars(date('F j, Y', strtotime($start_date))) ?>
                –
                <?= htmlspecialchars(date('F j, Y', strtotime($end_date))) ?>
            </div>
        </div>
    </div>

    <?php if (!empty($photosTop)): ?>
        <div class="photo-grid">
            <?php foreach ($photosTop as $p): ?>
                <figure>
                    <img src="<?= htmlspecialchars($p['path']) ?>" alt="Report photo">
                    <?php if (!empty($p['caption'])): ?><figcaption><?= htmlspecialchars($p['caption']) ?></figcaption><?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-heading">Overview</div>
        <div class="metric-row">
            <div class="metric-card">
                <div class="metric-label">Total Programs</div>
                <div class="metric-value"><?= number_format($totalPrograms) ?></div>
                <div class="metric-sub">Across all included event types (excluding Outreach)</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Attendance</div>
                <div class="metric-value"><?= number_format($totalAttendance) ?></div>
                <div class="metric-sub">People reached (excluding Outreach)</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Average Impact Score</div>
                <div class="metric-value"><?= number_format($overallAvgImpact, 2) ?></div>
                <div class="metric-sub">All programs with calculated scores (excluding Outreach)</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">One-on-One Sessions</div>
                <div class="metric-value"><?= number_format($oneOnOne['programs']) ?></div>
                <div class="metric-sub">
                    <?= number_format($oneOnOne['attendance']) ?> attendees |
                    Avg <?= number_format($oneOnOneAvg, 2) ?>
                </div>
            </div>
            <?php if ($outreach['programs'] > 0): ?>
                <div class="metric-card">
                    <div class="metric-label">Outreach Visits</div>
                    <div class="metric-value"><?= number_format($outreach['programs']) ?></div>
                    <div class="metric-sub">
    <?= number_format($outreach['attendance']) ?> attendees |
    Avg <?= number_format($outreachAvg, 2) ?>
    <span style="color:#777;">(not included in totals)</span>
</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="section">
        <div class="section-heading">Highlights</div>
        <ul class="highlights-list">
            <?php if (!empty($reportConfig['highlights'])): ?>
                <?php foreach ($reportConfig['highlights'] as $h): ?>
                    <?php
                    $prog = trim($h['program'] ?? '');
                    $text = trim($h['text'] ?? '');
                    $display = '';

                    if ($prog !== '') {
                        $agg = $programAgg[$prog] ?? null;
                        $att = $agg['attendance'] ?? 0;
                        $avg = ($agg && $agg['impact_count'] > 0)
                            ? ($agg['impact_sum'] / $agg['impact_count']) : 0;

                        $display .= "<strong>" . htmlspecialchars($prog) . "</strong>";
                        $display .= " (" . number_format($att) . " attendees, avg impact " . number_format($avg, 2) . ")";
                    }
                    if ($text !== '') {
                        if ($display !== '') $display .= ": ";
                        $display .= htmlspecialchars($text);
                    }
                    ?>
                    <li><?= $display ?></li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="small" style="color:#777;">Add highlights in the editor panel above.</li>
            <?php endif; ?>
        </ul>
    </div>

    <?php if (!empty($photosAfterHighlights)): ?>
        <div class="photo-grid">
            <?php foreach ($photosAfterHighlights as $p): ?>
                <figure>
                    <img src="<?= htmlspecialchars($p['path']) ?>" alt="Report photo">
                    <?php if (!empty($p['caption'])): ?><figcaption><?= htmlspecialchars($p['caption']) ?></figcaption><?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-heading">Audience & Event Mix</div>
        <div class="two-column">
            <div>
                <h3 style="font-size:1rem;margin-bottom:4px;">By Event Type</h3>
                <table>
                    <thead><tr><th>Event Type</th><th>Programs</th><th>Attendance</th></tr></thead>
                    <tbody>
                    <?php foreach ($eventTypeStats as $label => $stat): ?>
                        <tr>
                            <td><?= htmlspecialchars($label) ?></td>
                            <td><?= number_format($stat['programs']) ?></td>
                            <td><?= number_format($stat['attendance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <h3 style="font-size:1rem;margin-bottom:4px;">By Team</h3>
                <table>
                    <thead><tr><th>Team</th><th>Programs</th><th>Attendance</th><th>Avg Impact</th></tr></thead>
                    <tbody>
                    <?php foreach ($teamStats as $teamName => $stat): ?>
                        <tr>
                            <td><?= htmlspecialchars($teamName) ?></td>
                            <td><?= number_format($stat['programs']) ?></td>
                            <td><?= number_format($stat['attendance']) ?></td>
                            <td><?= number_format($stat['avg_impact'] ?? 0, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-heading">Signature Programs</div>
        <?php if (!empty($reportConfig['signatures'])): ?>
            <ul class="small">
                <?php foreach ($reportConfig['signatures'] as $sig): ?>
                    <?php
                    $agg = $programAgg[$sig] ?? null;
                    $att = $agg['attendance'] ?? 0;
                    $avg = ($agg && $agg['impact_count'] > 0)
                        ? ($agg['impact_sum'] / $agg['impact_count']) : 0;
                    ?>
                    <li>
                        <strong><?= htmlspecialchars($sig) ?></strong>
                        — <?= number_format($att) ?> attendees,
                        avg impact <?= number_format($avg, 2) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="small" style="color:#777;">No signature programs selected. Choose them in the editor above.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-heading">Top Programs</div>
        <div class="two-column">
            <div>
                <h3 style="font-size:0.95rem;">By Impact Score</h3>
                <table>
                    <thead><tr><th>Program</th><th>Team</th><th>Highest Impact</th><th>Attendance</th></tr></thead>
                    <tbody>
                    <?php if (!empty($topImpactPrograms)): ?>
                        <?php foreach ($topImpactPrograms as $stat): ?>
                            <tr>
                                <td><?= htmlspecialchars($stat['program']) ?></td>
                                <td><?= htmlspecialchars($stat['team']) ?></td>
                                <td><?= number_format($stat['impact_max'] ?? 0, 2) ?></td>
                                <td><?= number_format($stat['attendance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="small">No programs with impact scores.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <h3 style="font-size:0.95rem;">By Attendance</h3>
                <table>
                    <thead><tr><th>Program</th><th>Team</th><th>Total Attendance</th></tr></thead>
                    <tbody>
                    <?php if (!empty($topAttendancePrograms)): ?>
                        <?php foreach ($topAttendancePrograms as $stat): ?>
                            <tr>
                                <td><?= htmlspecialchars($stat['program']) ?></td>
                                <td><?= htmlspecialchars($stat['team']) ?></td>
                                <td><?= number_format($stat['attendance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="small">No programs in date range.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-heading">Impact Over Time (Process Control Charts)</div>

        <h4>Youth Services Impact Scores</h4>
        <div class="chart-container"><canvas id="youthChart"></canvas></div>

        <h4>Adult Services Impact Scores</h4>
        <div class="chart-container"><canvas id="adultChart"></canvas></div>

        <div class="two-column" style="margin-top:16px;">
            <div>
                <h4 style="font-size:0.95rem;">Youth: Above Limit (Top 5)</h4>
                <?php if (!empty($aboveYouth)): ?>
                    <table>
                        <thead><tr><th>Date</th><th>Program</th><th>Attendance</th><th>Score</th></tr></thead>
                        <tbody>
                        <?php foreach ($aboveYouth as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars(date("m/d/Y", strtotime($item['date']))) ?></td>
                                <td><?= htmlspecialchars($item['program']) ?></td>
                                <td><?= number_format($item['attendance']) ?></td>
                                <td><?= number_format($item['score'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="small">No Youth programs above limit.</p>
                <?php endif; ?>
            </div>
            <div>
                <h4 style="font-size:0.95rem;">Youth: Below Limit (Bottom 5)</h4>
                <?php if (!empty($belowYouth)): ?>
                    <table>
                        <thead><tr><th>Date</th><th>Program</th><th>Attendance</th><th>Score</th></tr></thead>
                        <tbody>
                        <?php foreach ($belowYouth as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars(date("m/d/Y", strtotime($item['date']))) ?></td>
                                <td><?= htmlspecialchars($item['program']) ?></td>
                                <td><?= number_format($item['attendance']) ?></td>
                                <td><?= number_format($item['score'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="small">No Youth programs below limit.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="two-column" style="margin-top:16px;">
            <div>
                <h4 style="font-size:0.95rem;">Adult: Above Limit (Top 5)</h4>
                <?php if (!empty($aboveAdult)): ?>
                    <table>
                        <thead><tr><th>Date</th><th>Program</th><th>Attendance</th><th>Score</th></tr></thead>
                        <tbody>
                        <?php foreach ($aboveAdult as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars(date("m/d/Y", strtotime($item['date']))) ?></td>
                                <td><?= htmlspecialchars($item['program']) ?></td>
                                <td><?= number_format($item['attendance']) ?></td>
                                <td><?= number_format($item['score'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="small">No Adult programs above limit.</p>
                <?php endif; ?>
            </div>
            <div>
                <h4 style="font-size:0.95rem;">Adult: Below Limit (Bottom 5)</h4>
                <?php if (!empty($belowAdult)): ?>
                    <table>
                        <thead><tr><th>Date</th><th>Program</th><th>Attendance</th><th>Score</th></tr></thead>
                        <tbody>
                        <?php foreach ($belowAdult as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars(date("m/d/Y", strtotime($item['date']))) ?></td>
                                <td><?= htmlspecialchars($item['program']) ?></td>
                                <td><?= number_format($item['attendance']) ?></td>
                                <td><?= number_format($item['score'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="small">No Adult programs below limit.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($photosAfterCharts)): ?>
        <div class="photo-grid">
            <?php foreach ($photosAfterCharts as $p): ?>
                <figure>
                    <img src="<?= htmlspecialchars($p['path']) ?>" alt="Report photo">
                    <?php if (!empty($p['caption'])): ?><figcaption><?= htmlspecialchars($p['caption']) ?></figcaption><?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($photosEnd)): ?>
        <div class="section">
            <div class="section-heading">Photo Appendix</div>
            <div class="photo-grid">
                <?php foreach ($photosEnd as $p): ?>
                    <figure>
                        <img src="<?= htmlspecialchars($p['path']) ?>" alt="Report photo">
                        <?php if (!empty($p['caption'])): ?><figcaption><?= htmlspecialchars($p['caption']) ?></figcaption><?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    const monthLabels         = <?= json_encode($monthLabels) ?>;
    const monthImpactAverages = <?= json_encode($monthImpactAverages) ?>;
    const monthAttendance     = <?= json_encode($monthAttendance) ?>;

    const youthLabels       = <?= json_encode($youthLabels) ?>;
    const youthScores       = <?= json_encode($youth_scores) ?>;
    const adultLabels       = <?= json_encode($adultLabels) ?>;
    const adultScores       = <?= json_encode($adult_scores) ?>;
    const youthMean         = <?= json_encode($youth_mean) ?>;
    const youthUpperLimit   = <?= json_encode($youth_upper_limit) ?>;
    const youthLowerLimit   = <?= json_encode($youth_lower_limit) ?>;
    const adultMean         = <?= json_encode($adult_mean) ?>;
    const adultUpperLimit   = <?= json_encode($adult_upper_limit) ?>;
    const adultLowerLimit   = <?= json_encode($adult_lower_limit) ?>;
    const youthProgramNames = <?= json_encode($youthProgramNames) ?>;
    const adultProgramNames = <?= json_encode($adultProgramNames) ?>;

    function createSpcChart(ctx, labels, scores, mean, upper, lower, programNames) {
        if (!ctx || !labels.length || !scores.length) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: "Adjusted Impact Score", data: scores, borderColor: "#6395CF", fill:false, tension:0.1 },
                    { label: "Mean", data: Array(labels.length).fill(mean), borderColor:"#7DB652", borderDash:[5,5], pointRadius:0 },
                    { label: "Upper Limit (+1 SD)", data: Array(labels.length).fill(upper), borderColor:"#E4781F", borderDash:[5,5], pointRadius:0 },
                    { label: "Lower Limit (-1 SD)", data: Array(labels.length).fill(lower), borderColor:"#E4781F", borderDash:[5,5], pointRadius:0 }
                ]
            },
            options: {
                responsive:true, maintainAspectRatio:false,
                plugins:{
                    tooltip:{callbacks:{
                        label: function(ctx){
                            const idx = ctx.dataIndex;
                            const score = ctx.parsed.y;
                            const prog = programNames[idx] || "Program";
                            return prog + ": " + score;
                        }
                    }},
                    legend:{display:true}
                },
                scales:{x:{ticks:{display:false},grid:{display:false}},y:{beginAtZero:false}}
            }
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        const youthCanvas = document.getElementById('youthChart')?.getContext('2d');
        const adultCanvas = document.getElementById('adultChart')?.getContext('2d');

        if (youthCanvas && youthLabels.length > 0) {
            createSpcChart(youthCanvas, youthLabels, youthScores, youthMean, youthUpperLimit, youthLowerLimit, youthProgramNames);
        }
        if (adultCanvas && adultLabels.length > 0) {
            createSpcChart(adultCanvas, adultLabels, adultScores, adultMean, adultUpperLimit, adultLowerLimit, adultProgramNames);
        }
    });
</script>

</body>
</html>
