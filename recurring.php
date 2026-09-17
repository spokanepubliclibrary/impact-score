<?php
/**
 * recurring.php
 *
 * Handles bulk recurring programs (recurring programs & one-on-one lessons).
 *
 * PROGRAM TYPES:
 *   - program_type = "recurring"
 *         → Month/year grid + variable attendance
 *
 *   - program_type = "one_on_one"
 *         → Month/year grid
 *         → Attendance always forced = 1
 *
 * Supports:
 *   - multi-staff score_users linking
 *   - default attendance for recurring programs
 *   - question responses passed through hidden fields
 *   - location_id
 */

ob_start();
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// ------------------------------------------------------
// 0. Grab incoming data
// ------------------------------------------------------
$program_type = $_POST['program_type'] ?? $_GET['program_type'] ?? 'program';
$formId       = $_POST['form_id']      ?? $_GET['form_id']      ?? null;
$teamId       = $_POST['team_id']      ?? $_GET['team_id']      ?? null;
$programName  = $_POST['program_name'] ?? $_GET['program_name'] ?? null;
$programDate  = $_POST['program_date'] ?? $_GET['program_date'] ?? null;
$totalScore   = $_POST['total_score']  ?? $_GET['total_score']  ?? 0;
$location_id  = $_POST['location_id']  ?? $_SESSION['location_id'] ?? null;

// Multi-staff selected:
$selectedUserIds = [];

// hidden user_ids[] (carried from value_score_form)
if (!empty($_POST['user_ids']) && is_array($_POST['user_ids'])) {
    $selectedUserIds = array_merge($selectedUserIds, array_map('intval', $_POST['user_ids']));
}

// visible users[] multi-select (value_score_form)
if (!empty($_POST['users']) && is_array($_POST['users'])) {
    $selectedUserIds = array_merge($selectedUserIds, array_map('intval', $_POST['users']));
}

$selectedUserIds = array_values(array_unique(array_filter($selectedUserIds, fn($v) => $v > 0)));
if (empty($selectedUserIds)) {
    die("<p style='color:red;'>No staff selected.</p>");
}
$primaryUserId = $selectedUserIds[0];

// attendance override for one-on-one
$forceOneOnOne = (isset($_POST['force_attendance_one']) && $_POST['force_attendance_one'] == "1");

// ------------------------------------------------------
// 1. DB Connection
// ------------------------------------------------------
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("<p style='color:red;'>Connection failed: {$conn->connect_error}</p>");
}

// ------------------------------------------------------
// 2. Build a Calendar Array
// ------------------------------------------------------
function buildCalendarArray($month, $year) {
    $start = strtotime("$year-$month-01");
    $numDays = date("t", $start);
    $startDow = date("w", $start);

    $weeks = [];
    $current = 0;

    for ($i = 0; $i < $startDow; $i++) {
        $weeks[$current][$i] = null;
    }

    for ($d = 1; $d <= $numDays; $d++) {
        $dow = date("w", strtotime("$year-$month-$d"));
        $weeks[$current][$dow] = $d;
        if ($dow == 6) $current++;
    }

    return $weeks;
}

// ------------------------------------------------------
// 3. If no date chosen, show month/year selector
// ------------------------------------------------------
if (!$programDate) {

    if (isset($_POST['date_submit'])) {
        $m = (int)($_POST['month'] ?? 0);
        $y = (int)($_POST['year']  ?? 0);
        if ($m > 0 && $y > 0) {
            $programDate = sprintf("%04d-%02d-01", $y, $m);
        }
    }

    if (!$programDate) {
        ?>
        <h2>Select Month & Year for <strong><?= htmlspecialchars($programName) ?></strong></h2>
        <form method="POST" action="recurring.php">
            <input type="hidden" name="form_id" value="<?= htmlspecialchars($formId) ?>">
            <input type="hidden" name="team_id" value="<?= htmlspecialchars($teamId) ?>">
            <input type="hidden" name="program_type" value="<?= htmlspecialchars($program_type) ?>">
            <input type="hidden" name="program_name" value="<?= htmlspecialchars($programName) ?>">
            <input type="hidden" name="total_score" value="<?= htmlspecialchars($totalScore) ?>">
            <input type="hidden" name="location_id" value="<?= htmlspecialchars($location_id) ?>">

            <?php foreach ($selectedUserIds as $uid): ?>
                <input type="hidden" name="user_ids[]" value="<?= (int)$uid ?>">
            <?php endforeach; ?>

            <?php if ($forceOneOnOne): ?>
                <input type="hidden" name="force_attendance_one" value="1">
            <?php endif; ?>

            <label>Month:</label>
            <select name="month" required>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>"><?= date("F", mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>

            <label>Year:</label>
            <select name="year" required>
                <?php
                $cy = date("Y");
                for ($y = $cy; $y <= $cy + 4; $y++):
                ?>
                    <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
            </select>

            <button type="submit" name="date_submit">Continue</button>
        </form>
        <?php
        exit();
    }
}

// ------------------------------------------------------
// 4. With programDate chosen → show calendar grid
// ------------------------------------------------------
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $programDate)) {
    die("<p style='color:red;'>Invalid program date: $programDate</p>");
}

list($yr, $mo, $dummy) = explode('-', $programDate);
$weeks = buildCalendarArray($mo, $yr);

$defaultAttendance = $_SESSION['default_attendance'] ?? 0;

// ------------------------------------------------------
// Show calendar entry form
// ------------------------------------------------------
if (!isset($_POST['final_submit'])) {
?>
<h2>Enter Recurring Sessions for <strong><?= htmlspecialchars($programName) ?></strong> (<?= date("F Y", strtotime($programDate)) ?>)</h2>

<form method="POST" action="recurring.php">

    <input type="hidden" name="form_id" value="<?= htmlspecialchars($formId) ?>">
    <input type="hidden" name="team_id" value="<?= htmlspecialchars($teamId) ?>">
    <input type="hidden" name="program_type" value="<?= htmlspecialchars($program_type) ?>">
    <input type="hidden" name="program_name" value="<?= htmlspecialchars($programName) ?>">
    <input type="hidden" name="program_date" value="<?= htmlspecialchars($programDate) ?>">
    <input type="hidden" name="total_score" value="<?= htmlspecialchars($totalScore) ?>">
    <input type="hidden" name="location_id" value="<?= htmlspecialchars($location_id) ?>">

    <?php foreach ($selectedUserIds as $uid): ?>
        <input type="hidden" name="user_ids[]" value="<?= (int)$uid ?>">
    <?php endforeach; ?>

    <?php if ($forceOneOnOne): ?>
        <input type="hidden" name="force_attendance_one" value="1">
    <?php endif; ?>

    <?php if ($program_type === 'recurring'): ?>
        <div class="mb-3">
            <label><strong>Default Attendance</strong></label>
            <input type="number" min="0" name="default_attendance" value="<?= htmlspecialchars($defaultAttendance) ?>" style="width:120px;">
        </div>
    <?php endif; ?>

    <table border="1" cellpadding="4">
        <thead>
            <tr>
                <th>Sun</th><th>Mon</th><th>Tue</th>
                <th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
            </tr>
        </thead>

        <tbody>
        <?php foreach ($weeks as $week): ?>
            <tr>
                <?php foreach (range(0,6) as $dow):
                    $day = $week[$dow] ?? null;
                    if (!$day): ?>
                        <td></td>
                    <?php else:
                        $ds = sprintf("%04d-%02d-%02d", $yr, $mo, $day); ?>
                        <td style="text-align:center;">
                            <div><?= $day ?></div>

                            <?php if ($forceOneOnOne): ?>
                                <input type="hidden" name="lessonCount[<?= $ds ?>]" value="1">
                                <em>1</em>
                            <?php else: ?>
                                <input type="number"
                                       min="0"
                                       name="lessonCount[<?= $ds ?>]"
                                       placeholder="0"
                                       style="width:60px;">
                            <?php endif; ?>

                        </td>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>

    </table>

    <button type="submit" name="final_submit" class="btn btn-primary mt-3">Submit All</button>

</form>
<?php
exit();
}

// ------------------------------------------------------
// 5. Handle Final Submission
// ------------------------------------------------------
if (isset($_POST['final_submit'])) {

    // Save default attendance for next time
    if (!$forceOneOnOne && isset($_POST['default_attendance'])) {
        $_SESSION['default_attendance'] = max(0, (int)$_POST['default_attendance']);
    }

    $lessonCounts = $_POST['lessonCount'] ?? [];

    foreach ($lessonCounts as $date => $cntRaw) {
        $count = (int)$cntRaw;
        if ($count <= 0) continue;

        for ($i = 0; $i < $count; $i++) {

            // determine attendance
            if ($forceOneOnOne) {
                $attendance = 1;
            } else {
                $attendance = $_SESSION['default_attendance'] ?? 0;
            }

            // compute adjusted impact score using attendance
            $scaled = sqrt((float)$attendance);
            $adjustedImpactScore = (float)$totalScore + $scaled;

            $stmt = $conn->prepare("
                INSERT INTO scores
                    (user_id, team_id, form_id, program, program_date,
                     total_score, attendance, adjusted_impact_score, location_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                echo "<p style='color:red;'>Prepare error: {$conn->error}</p>";
                continue;
            }

            $stmt->bind_param(
                "iiisssidi",
                $primaryUserId,
                $teamId,
                $formId,
                $programName,
                $date,
                $totalScore,
                $attendance,
                $adjustedImpactScore,
                $location_id
            );
            $stmt->execute();
            $newScoreId = $stmt->insert_id;
            $stmt->close();

            // attach staff
            $ins = $conn->prepare("
                INSERT IGNORE INTO score_users (score_id, user_id, role)
                VALUES (?, ?, 'staff')
            ");
            foreach ($selectedUserIds as $uid) {
                if ($uid > 0) {
                    $ins->bind_param("ii", $newScoreId, $uid);
                    $ins->execute();
                }
            }
            $ins->close();

            // attach question responses
            foreach ($_POST as $k => $v) {
                if (preg_match('/^question_(\d+)$/', $k, $m)) {
                    $qid = (int)$m[1];
                    $points = (int)$v;

                    $qr = $conn->prepare("
                        INSERT INTO score_responses (score_id, question_id, points)
                        VALUES (?, ?, ?)
                    ");
                    $qr->bind_param("iii", $newScoreId, $qid, $points);
                    $qr->execute();
                    $qr->close();
                }
            }
        }
    }

    header("Location: view_scores.php?team_id=" . urlencode($teamId));
    exit();
}

$conn->close();
ob_end_flush();
?>
