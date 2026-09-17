<?php
/**
 * Bulk Entry Form for One-on-One Specialty Programs
 *
 * This page allows bulk data entry for programs like one-on-one lessons.
 * It enables users to select a month/year, enter lesson counts for each day,
 * and saves multiple score entries into the database accordingly.
 *
 * Features:
 *  - Uses session to persist form state and total scores.
 *  - Dynamically builds a calendar based on selected month/year.
 *  - Accepts user-submitted lesson counts per day and inserts that many rows.
 *  - Computes adjusted impact score and saves related question responses.
 *
 * Prerequisites:
 *  - A valid MySQL connection via secure/db_connection.php
 *  - Tables: scores, score_responses, form_profiles
 *
 * @package BulkLessonEntry
 * @version 1.1
 */

// --- Start Output Buffering and Session ---
ob_start();
session_start();

// --- Enable Error Reporting for Debugging ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// --- Retrieve or Store Total Score in Session ---
$totalScore = $_POST['total_score'] ?? $_SESSION['totalScore'] ?? 0;
$_SESSION['totalScore'] = $totalScore;

// --- Persist team_id to Session if provided ---
if (!empty($_GET['team_id'])) {
    $_SESSION['team_id'] = $_GET['team_id'];
}
if (!empty($_POST['team_id'])) {
    $_SESSION['team_id'] = $_POST['team_id'];
}

// --- NEW: Default attendance (sticky via session) ---
if (isset($_POST['default_attendance'])) {
    $_SESSION['default_attendance'] = max(0, (int)$_POST['default_attendance']);
}
$defaultAttendance = $_SESSION['default_attendance'] ?? 0;

// --- Database Connection ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("<p style='color:red;'>Connection failed: " . $conn->connect_error . "</p>");
}

// --- Load scoring helpers and options (REQUIRED for normalizeResponse) ---
require_once('secure/score_helpers.php');
$optionsByQuestion = loadScoringOptionsByQuestion($conn);


// --- Build a Calendar Array for the Given Month/Year ---
function buildCalendarArray($month, $year) {
    $startDate = strtotime("$year-$month-01");
    $numDays   = date("t", $startDate);
    $startDow  = date("w", $startDate);

    $weeks = [];
    $currentWeek = 0;

    // Fill blanks for days before the 1st
    for ($i = 0; $i < $startDow; $i++) {
        $weeks[$currentWeek][$i] = null;
    }

    // Fill the actual days
    for ($d = 1; $d <= $numDays; $d++) {
        $dow = date("w", strtotime("$year-$month-$d"));
        $weeks[$currentWeek][$dow] = $d;
        if ($dow == 6) {
            $currentWeek++;
        }
    }
    return $weeks;
}

// --- Read GET or POST Parameters ---
$userId      = $_POST['user_id']      ?? $_GET['user_id']      ?? null;
$teamId      = $_POST['team_id']      ?? $_GET['team_id']      ?? null;
$formId      = $_POST['form_id']      ?? $_GET['form_id']      ?? null;
$bulkEntry   = $_GET['bulk_entry']    ?? $_POST['bulk_entry']  ?? null;
$programName = $_POST['program_name'] ?? $_GET['program_name'] ?? null;
$programDate = $_POST['program_date'] ?? $_GET['program_date'] ?? null;
$totalScore  = $_POST['total_score']  ?? $_GET['total_score']  ?? $_SESSION['totalScore'] ?? 0;
$location_id = $_POST['location_id'] ?? $_SESSION['location_id'] ?? null;


function collect_user_ids(): array {
    $ids = [];

    foreach (['_POST', '_GET'] as $scope) {
        if (!empty($GLOBALS[$scope]['user_ids']) && is_array($GLOBALS[$scope]['user_ids'])) {
            $ids = array_merge($ids, $GLOBALS[$scope]['user_ids']);
        }
        if (!empty($GLOBALS[$scope]['users']) && is_array($GLOBALS[$scope]['users'])) {
            $ids = array_merge($ids, $GLOBALS[$scope]['users']);
        }
        if (!empty($GLOBALS[$scope]['user_id']) && is_array($GLOBALS[$scope]['user_id'])) {
            $ids = array_merge($ids, $GLOBALS[$scope]['user_id']);
        }
        if (!empty($GLOBALS[$scope]['user_id']) && !is_array($GLOBALS[$scope]['user_id'])) {
            $ids[] = $GLOBALS[$scope]['user_id'];
        }
        if (!empty($GLOBALS[$scope]['user']) && !is_array($GLOBALS[$scope]['user'])) {
            $ids[] = $GLOBALS[$scope]['user'];
        }
    }

    if (empty($ids) && !empty($_SESSION['user_id'])) {
        $ids[] = $_SESSION['user_id'];
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));
    return $ids;
}

$selectedUserIds = collect_user_ids();

// ✅ Make selected users sticky across steps
if (!empty($selectedUserIds)) {
    $_SESSION['selected_user_ids'] = $selectedUserIds;
} elseif (!empty($_SESSION['selected_user_ids'])) {
    $selectedUserIds = $_SESSION['selected_user_ids'];
}


if (empty($userId) && !empty($selectedUserIds)) {
    $userId = (int)$selectedUserIds[0]; // primary fallback
}


// Safety check: ensure location is present
if (empty($location_id)) {
    echo "<p style='color:red;'>⚠️ Location is missing. Please go back and select a location.</p>";
    exit();
}

// --- Ensure Required Params Exist ---
if ((empty($userId) && empty($selectedUserIds)) || empty($teamId) || empty($formId)) {
    echo "<p style='color:red;'>Missing user, team, or form.</p>";
    exit();
}


// --- If No Program Name Provided, Get It from DB ---
if (empty($programName)) {
    $stmt = $conn->prepare("SELECT name FROM form_profiles WHERE id = ?");
    $stmt->bind_param("i", $formId);
    $stmt->execute();
    $stmt->bind_result($fName);
    if ($stmt->fetch()) {
        $programName = $fName;
    }
    $stmt->close();
    if (empty($programName)) {
        $programName = "Bulk Form";
    }
}

// --- If No Program Date Chosen, Ask for Month & Year ---
if (empty($programDate)) {
    if (isset($_POST['date_submit'])) {
        $selectedMonth = $_POST['month'] ?? null;
        $selectedYear  = $_POST['year']  ?? null;
        if ($selectedMonth && $selectedYear) {
            $programDate = sprintf("%04d-%02d-01", $selectedYear, $selectedMonth);
        }
    }

    // Still no date — prompt user
    if (empty($programDate)) {
        ?>
        <h2>Please pick a month & year for <strong><?= htmlspecialchars($programName) ?></strong></h2>
        <form method="POST" action="one_on_one_specialty.php">
            <!-- Hidden fields to retain context -->
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
            <input type="hidden" name="team_id" value="<?= htmlspecialchars($teamId) ?>">
            <input type="hidden" name="form_id" value="<?= htmlspecialchars($formId) ?>">
            <input type="hidden" name="bulk_entry" value="1">
            <input type="hidden" name="program_name" value="<?= htmlspecialchars($programName) ?>">
            <input type="hidden" name="total_score" value="<?= htmlspecialchars($totalScore) ?>">
            <input type="hidden" name="location_id" value="<?= htmlspecialchars($location_id) ?>">
            <?php
            // ✅ Preserve multi-staff selection into the date_submit POST
            if (!empty($selectedUserIds)) {
                foreach ($selectedUserIds as $uid) {
                    echo '<input type="hidden" name="users[]" value="' . (int)$uid . '">';
                }
            }
            ?>




            <!-- Month/Year Dropdowns -->
            <label for="month">Choose Month:</label>
            <select name="month" id="month" required>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>"><?= date("F", mktime(0, 0, 0, $m, 1)) ?></option>
                <?php endfor; ?>
            </select>

            <label for="year">Choose Year:</label>
            <select name="year" id="year" required>
                <?php
                $currentYear = date("Y");
                for ($y = $currentYear; $y <= $currentYear + 5; $y++) {
                    echo "<option value=\"$y\">$y</option>";
                }
                ?>
            </select>

            <button type="submit" name="date_submit">Continue</button>
        </form>
        <?php
        exit();
    }
}

// --- Validate and Build Calendar Array ---
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $programDate)) {
    echo "<p style='color:red;'>Invalid date format. Cannot build calendar.</p>";
    exit();
}
list($year, $month, $day) = explode('-', $programDate);
$weeks = buildCalendarArray($month, $year);

// --- Show Calendar Form to Enter Daily Lesson Counts ---
// IMPORTANT: value_score_form.php also posts "final_submit".
// If we have no lessonCount yet, we are NOT in the real final step — show the calendar.
if (!isset($_POST['final_submit']) || empty($_POST['lessonCount'])) {

    ?>
    <h2>📚 Enter the Number of Daily Programs for <strong><?= htmlspecialchars($programName) ?></strong>
        (<?= date("F Y", strtotime($programDate)) ?>)</h2>
    <h3>You can set a <em>default attendance</em> that will be applied to each generated entry (you can edit later in View Scores).</h3>

    <form method="POST" action="one_on_one_specialty.php">
        <!-- Hidden context fields -->
        <input type="hidden" name="total_score" value="<?= (int)$totalScore ?>">
        <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
        <input type="hidden" name="team_id" value="<?= htmlspecialchars($teamId) ?>">
        <input type="hidden" name="program_name" value="<?= htmlspecialchars($programName) ?>">
        <input type="hidden" name="program_date" value="<?= htmlspecialchars($programDate) ?>">
        <input type="hidden" name="form_id" value="<?= htmlspecialchars($formId) ?>">
        <input type="hidden" name="bulk_entry" value="1">
        <input type="hidden" name="location_id" value="<?= htmlspecialchars($location_id) ?>">

        <?php
        // ✅ Preserve multi-staff selection into the final_submit POST
        if (!empty($selectedUserIds)) {
            foreach ($selectedUserIds as $uid) {
                echo '<input type="hidden" name="users[]" value="' . (int)$uid . '">';
            }
        }
        ?>


        <!-- NEW: Default attendance control -->
        <div class="mb-3" style="margin: 10px 0;">
            <label for="default_attendance" class="form-label"><strong>Default attendance for each entry</strong></label><br>
            <input type="number" min="0" step="1" id="default_attendance" name="default_attendance"
                   value="<?= htmlspecialchars((string)$defaultAttendance) ?>" style="width: 120px;">
            <div class="form-text">Used when creating entries below.</div>
        </div>

        <?php
        // Preserve any question_* inputs submitted earlier
        foreach ($_POST as $key => $value) {
            if (preg_match('/^question_\d+$/', $key)) {
                echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
            }
        }
        ?>

        <!-- Calendar Table Layout -->
        <table border="1" cellpadding="5" cellspacing="0">
            <thead>
                <tr>
                    <th>Sun</th><th>Mon</th><th>Tue</th>
                    <th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($weeks as $daysInWeek): ?>
                <tr>
                    <?php foreach (range(0, 6) as $dow):
                        $dayNumber = $daysInWeek[$dow] ?? null;
                        if ($dayNumber):
                            $dateString = sprintf("%04d-%02d-%02d", $year, $month, $dayNumber); ?>
                            <td style="text-align:center;">
                                <div><?= $dayNumber ?></div>
                                <input type="number" name="lessonCount[<?= $dateString ?>]"
                                       min="0" placeholder="0" style="width:60px">
                            </td>
                        <?php else: ?>
                            <td></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <br>
        <button type="submit" name="final_submit" class="btn btn-primary mt-3">
            ✅ Submit
        </button>
    </form>
    <?php
}

// --- Final Submission: Insert Score Rows for Each Lesson Entry ---
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['final_submit'])
    && !empty($_POST['lessonCount'])
    && is_array($_POST['lessonCount'])
) {


    // Sticky default attendance
    if (isset($_POST['default_attendance'])) {
        $_SESSION['default_attendance'] = max(0, (int)$_POST['default_attendance']);
    }
    $defaultAttendance = $_SESSION['default_attendance'] ?? 0;

    if (empty($_POST['lessonCount']) || !is_array($_POST['lessonCount'])) {
        echo "<p style='color:red;'>⚠️ Lesson count data is missing. Please enter at least one lesson.</p>";
        exit();
    }

    // Do ALL writes in a single transaction
    $conn->begin_transaction();

    try {

        // ✅ Force numeric types before bind_param
        $teamId = (int)$teamId;
        $formId = (int)$formId;
        $totalScore = (int)$totalScore;
        $location_id = (int)$location_id;

        // Reuse prepared statements (big speed-up)
        $stmtScore = $conn->prepare("
    INSERT INTO scores (
        user_id, team_id, form_id,
        program, total_score, program_date,
        attendance, adjusted_impact_score, location_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$stmtScore) throw new Exception('Prepare scores insert failed: ' . $conn->error);

        $stmtResp = $conn->prepare("
            INSERT INTO score_responses (score_id, question_id, response, points)
            VALUES (?, ?, ?, ?)
        ");
        if (!$stmtResp) throw new Exception("Prepare score_responses insert failed: " . $conn->error);

        $stmtUpd = $conn->prepare("UPDATE scores SET total_score = ? WHERE id = ?");
        if (!$stmtUpd) throw new Exception("Prepare total_score update failed: " . $conn->error);

        $stmtLink = $conn->prepare("INSERT IGNORE INTO score_users (score_id, user_id, role) VALUES (?, ?, ?)");
        if (!$stmtLink) throw new Exception("Prepare score_users insert failed: " . $conn->error);

        $primary = (int)$userId;
        $role = 'staff';

        // Extra users (skip primary)
        $extraIds = array_values(array_filter(
            array_unique(array_map('intval', $selectedUserIds)),
            fn($id) => $id > 0 && $id !== $primary
        ));

        foreach ($_POST['lessonCount'] as $date => $countStr) {
            if (trim((string)$countStr) === '') continue;

            $numRows = (int)$countStr;
            if ($numRows <= 0) continue;

            // Validate date key
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            for ($i = 0; $i < $numRows; $i++) {

                $attendance = (int)$defaultAttendance;

if ($attendance === 0) {
    $adjustedImpactScore = 0;
} else {
    $adjustedImpactScore = (float)$totalScore + sqrt((float)$attendance);
}

                

                

                // 1) Insert score (temporary total_score; we’ll overwrite with totalFromResponses)
                $stmtScore->bind_param(
                    "iiisisidi",
                    $primary, $teamId, $formId, $programName,
                    $totalScore, $date, $attendance, $adjustedImpactScore, $location_id
                );

                if (!$stmtScore->execute()) {
                    throw new Exception("scores insert failed: " . $stmtScore->error);
                }

                $newScoreId = $conn->insert_id;

                // 2) Link staff (primary + extras)
                if ($primary > 0) {
                    $stmtLink->bind_param("iis", $newScoreId, $primary, $role);
                    if (!$stmtLink->execute()) {
                        throw new Exception("score_users primary insert failed: " . $stmtLink->error);
                    }
                }
                foreach ($extraIds as $uid) {
                    $stmtLink->bind_param("iis", $newScoreId, $uid, $role);
                    if (!$stmtLink->execute()) {
                        throw new Exception("score_users extra insert failed: " . $stmtLink->error);
                    }
                }

                // 3) Insert responses using normalizeResponse
                $totalFromResponses = 0;

                foreach ($_POST as $key => $value) {
                    if (!preg_match('/^question_(\d+)$/', $key, $matches)) continue;

                    $questionId = (int)$matches[1];
                    [$responseText, $points] = normalizeResponse($questionId, $value, $optionsByQuestion);

                    $totalFromResponses += (int)$points;

                    $stmtResp->bind_param("iisi", $newScoreId, $questionId, $responseText, $points);
                    if (!$stmtResp->execute()) {
                        throw new Exception("score_responses insert failed: " . $stmtResp->error);
                    }
                }

                // 4) Update scores.total_score to match the responses we stored
                $stmtUpd->bind_param("ii", $totalFromResponses, $newScoreId);
                if (!$stmtUpd->execute()) {
                    throw new Exception("scores total_score update failed: " . $stmtUpd->error);
                }
            }
        }

        // Close statements
        $stmtScore->close();
        $stmtResp->close();
        $stmtUpd->close();
        $stmtLink->close();

        $conn->commit();

        header("Location: view_scores.php?team_id=" . urlencode($teamId));
        exit();

    } catch (Throwable $e) {
        $conn->rollback();
        echo "<p style='color:red;'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        exit();
    }
}


// --- Close DB Connection and End Output Buffer ---
$conn->close();
ob_end_flush();
?>
