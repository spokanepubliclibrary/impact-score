<?php
/**
 * Single Event Score Submission
 *
 * @package ScoreSubmission
 * @version 1.2
 */

// 1) Turn on error reporting to display any issues during execution.
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2) Start a new session if one is not already active.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// 3) Retrieve variables from GET, POST, or SESSION.
$userId      = $_GET['user']         ?? $_POST['user']       ?? $_POST['user_id']    ?? $_SESSION['user_id']      ?? null;
$teamId      = $_GET['team']         ?? $_POST['team']         ?? $_SESSION['team_id']      ?? null;
$formId      = $_GET['form']         ?? $_POST['form']         ?? $_SESSION['form_id']      ?? null;
$programName = $_GET['program_name'] ?? $_POST['program_name'] ?? $_SESSION['program_name'] ?? null;
$programDate = $_GET['program_date'] ?? $_POST['program_date'] ?? $_SESSION['program_date'] ?? null;
$totalScore  = $_GET['total_score']  ?? $_POST['total_score']  ?? 0;
$location_id = $_POST['location_id'] ?? $_SESSION['location_id'] ?? null;

/* === Robust multi-staff collector (accepts many field names/sources) === */
function collect_user_ids(): array {
    $ids = [];

    // Preferred: user_ids[] (multi-select)
    foreach (['_POST', '_GET'] as $scope) {
        if (!empty($GLOBALS[$scope]['user_ids']) && is_array($GLOBALS[$scope]['user_ids'])) {
            $ids = array_merge($ids, $GLOBALS[$scope]['user_ids']);
        }
    }

    // ✅ Also accept users[] (your form is posting this)
    foreach (['_POST', '_GET'] as $scope) {
        if (!empty($GLOBALS[$scope]['users']) && is_array($GLOBALS[$scope]['users'])) {
            $ids = array_merge($ids, $GLOBALS[$scope]['users']);
        }
    }

    // Also accept user_id[] (some forms use this)
    foreach (['_POST', '_GET'] as $scope) {
        if (!empty($GLOBALS[$scope]['user_id']) && is_array($GLOBALS[$scope]['user_id'])) {
            $ids = array_merge($ids, $GLOBALS[$scope]['user_id']);
        }
    }

    // Single user_id
    foreach (['_POST', '_GET'] as $scope) {
        if (!empty($GLOBALS[$scope]['user_id']) && !is_array($GLOBALS[$scope]['user_id'])) {
            $ids[] = $GLOBALS[$scope]['user_id'];
        }
    }

    // Legacy single 'user'
    foreach (['_POST', '_GET'] as $scope) {
        if (!empty($GLOBALS[$scope]['user']) && !is_array($GLOBALS[$scope]['user'])) {
            $ids[] = $GLOBALS[$scope]['user'];
        }
    }

    // Session fallback
    if (empty($ids) && !empty($_SESSION['user_id'])) {
        $ids[] = $_SESSION['user_id'];
    }

    // Normalize, dedupe, remove invalids
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));
    return $ids;
}
// ➜ put these RIGHT AFTER the collect_user_ids() definition:
$selectedUserIds = collect_user_ids();                           // ← populate it



// 4) Retrieve the 'attendance' parameter for single-event scenarios.
$rawAttendance = $_GET['attendance'] ?? $_POST['attendance'] ?? null;
$attendance = (is_numeric($rawAttendance) && $rawAttendance !== '') ? (int)$rawAttendance : null;

// 5) Persist values in the session when available.
if ($userId)      $_SESSION['user_id']      = $userId;
if ($teamId)      $_SESSION['team_id']      = $teamId;
if ($formId)      $_SESSION['form_id']      = $formId;
if ($programName) $_SESSION['program_name'] = $programName;
if ($programDate) $_SESSION['program_date'] = $programDate;

// 6) Validate required parameters.
if (
    empty($teamId) ||
    empty($formId) ||
    empty($programName) ||
    empty($programDate) ||
    (empty($userId) && empty($selectedUserIds)) // need at least one staff ID from either source
) {
    die("<strong>⚠️ ERROR: Missing required parameters.</strong>");
}


// 7) DB connection.
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("<p style='color:red;'>Connection failed: " . $conn->connect_error . "</p>");
}

require_once('secure/score_helpers.php');
$optionsByQuestion = loadScoringOptionsByQuestion($conn);




try {
    // 8) Compute adjusted impact score.
    if (is_null($attendance)) {
        $adjustedImpact = null;
    } elseif ($attendance === 0) {
        $adjustedImpact = 0;
    } else {
        $adjustedImpact = $totalScore + sqrt($attendance);
    }

    // ✅ BONUS HARDENING: Start transaction before ANY database writes
$conn->begin_transaction();


    // 9) Insert the main score row.
    $stmt = $conn->prepare("
        INSERT INTO scores (
            user_id, team_id, form_id, 
            program, total_score, program_date, 
            attendance, adjusted_impact_score, location_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception("Database Error (prepare scores): " . $conn->error);
    }
    

    $stmt->bind_param(
        "iiisisidi",
        $userId,
        $teamId,
        $formId,
        $programName,
        $totalScore,
        $programDate,
        $attendance,
        $adjustedImpact,
        $location_id
    );

    if ($stmt->execute()) {
        // 10) New score id
        $newScoreId = $conn->insert_id;

        /* === Attach staff to score (ALWAYS includes primary $userId) === */

        // Start with the primary user, always link it first
        $primary = (int)$userId;
        $role    = 'staff';

        $linkStmt = $conn->prepare("INSERT IGNORE INTO score_users (score_id, user_id, role) VALUES (?, ?, ?)");
        if ($linkStmt) {
            // 10a) Ensure primary link exists
            if ($primary > 0) {
                $linkStmt->bind_param("iis", $newScoreId, $primary, $role);
                if (!$linkStmt->execute()) {
                    throw new Exception(
                        "score_users primary insert failed: score_id={$newScoreId}, user_id={$primary}, err={$linkStmt->error}"
                    );
                }
                
            }

            // 10b) Link any additional selected users (skip the primary if present)
            $extraIds = array_values(array_filter(
                array_unique(array_map('intval', $selectedUserIds)),
                fn($id) => $id > 0 && $id !== $primary
            ));

            foreach ($extraIds as $uid) {
                $linkStmt->bind_param("iis", $newScoreId, $uid, $role);
                if (!$linkStmt->execute()) {
                    throw new Exception(
                        "score_users extra insert failed: score_id={$newScoreId}, user_id={$uid}, err={$linkStmt->error}"
                    );
                }
                
            }

            $linkStmt->close();
        } else {
            error_log("[program.php] prepare INSERT score_users failed: " . $conn->error);
            throw new Exception("Prepare staff link error: " . $conn->error);
        }
        /* === END staff linking === */

        // 10c) Save program tags
        $selectedTags = isset($_POST['program_tags']) ? array_map('intval', (array)$_POST['program_tags']) : [];
        if (!empty($selectedTags)) {
            $tagStmt = $conn->prepare("INSERT IGNORE INTO score_tags (score_id, tag_id) VALUES (?, ?)");
            if (!$tagStmt) {
                throw new Exception("Prepare error (score_tags): " . $conn->error);
            }
            foreach ($selectedTags as $tagId) {
                if ($tagId > 0) {
                    $tagStmt->bind_param("ii", $newScoreId, $tagId);
                    if (!$tagStmt->execute()) {
                        throw new Exception("Insert error (score_tags tag_id={$tagId}): " . $tagStmt->error);
                    }
                }
            }
            $tagStmt->close();
        }

              // 11) Insert question responses (if any), mapping option_id -> points + label
$totalFromResponses = 0;

foreach ($_POST as $key => $value) {
    if (!preg_match('/^question_(\d+)$/', $key, $matches)) {
        continue;
    }

    $questionId = (int)$matches[1];

    // ✅ Canonical normalization: prevents option_id vs points mixups
    [$responseText, $points] = normalizeResponse($questionId, $value, $optionsByQuestion);

    $totalFromResponses += (int)$points;

    $stmtResp = $conn->prepare("
        INSERT INTO score_responses (score_id, question_id, response, points)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmtResp) {
        throw new Exception("Prepare question failed: " . $conn->error);
    }

    $stmtResp->bind_param("iisi", $newScoreId, $questionId, $responseText, $points);

    if (!$stmtResp->execute()) {
        throw new Exception("Insert question error: " . $stmtResp->error);
    }

    $stmtResp->close();
}

// ✅ Update total_score ONCE, after all responses are inserted
$upd = $conn->prepare("UPDATE scores SET total_score = ? WHERE id = ?");
if (!$upd) {
    throw new Exception("Prepare total_score update failed: " . $conn->error);
}
$upd->bind_param("ii", $totalFromResponses, $newScoreId);
if (!$upd->execute()) {
    throw new Exception("total_score update error: " . $upd->error);
}
$upd->close();

// 12) Link performer to this score event if one was selected
$performer_id = isset($_POST['performer_id']) ? (int)$_POST['performer_id'] : 0;
if ($performer_id > 0) {
    $performer_program_id = isset($_POST['performer_program_id']) ? (int)$_POST['performer_program_id'] : null;
    if ($performer_program_id !== null && $performer_program_id <= 0) $performer_program_id = null;
    $iepStmt = $conn->prepare("INSERT INTO impact_event_performers (score_id, performer_id, program_id) VALUES (?, ?, ?)");
    if ($iepStmt) {
        $iepStmt->bind_param("iii", $newScoreId, $performer_id, $performer_program_id);
        $iepStmt->execute();
        $iepStmt->close();
    }
}

// 13) Save custom field selections (skip blank selections)
foreach ($_POST as $key => $value) {
    if (!preg_match('/^custom_field_(\d+)$/', $key, $m)) continue;
    $cf_field_id = (int)$m[1];
    if ($cf_field_id <= 0 || $value === '' || !ctype_digit((string)$value)) continue;
    $cf_option_id = (int)$value;
    $cfStmt = $conn->prepare("
        INSERT INTO score_custom_field_values (score_id, field_id, option_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)
    ");
    if ($cfStmt) {
        $cfStmt->bind_param("iii", $newScoreId, $cf_field_id, $cf_option_id);
        $cfStmt->execute();
        $cfStmt->close();
    }
}

// ✅ BONUS HARDENING: Commit only after everything succeeded
$conn->commit();
$stmt->close();


// 13) Redirect after success.
header("Location: view_scores.php?team_id=" . urlencode($_SESSION['team_id']));
exit();

} else {
    $err = $stmt->error;
    $stmt->close();
    throw new Exception("Database Insert Error: " . $err);
}


} catch (Throwable $e) {
    // ❌ If anything failed mid-flight, undo it all
    if ($conn) {
        $conn->rollback(); // safe even if no active transaction
    }

    echo "<p style='color:red;'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}




// 13) Close DB.
$conn->close();
?>
