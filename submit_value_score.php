<?php
/**
 * Score Submission Handler (Single Entry & One-on-One Specialty)
 *
 * This script processes form submissions for both single "Program" entries and
 * one-on-one specialty sessions with date-specific lesson counts. It handles input
 * validation, session management, database inserts for scores, and dynamic redirection
 * based on the form type.
 *
 * Features:
 *  - Persists team ID in session for reuse across views.
 *  - Validates user ID and confirms it exists in the database.
 *  - Supports two workflows:
 *      - One-on-one forms with lesson counts across multiple dates.
 *      - Single-event programs with a direct score insert.
 *  - Computes adjusted impact scores using square-root scaling.
 *  - Redirects to the appropriate follow-up page after processing.
 *
 * Prerequisites:
 *  - A valid MySQL connection via `secure/db_connection.php`
 *  - Database tables: `scores`, `users`
 *
 * @package ScoreProcessing
 * @version 1.0
 */

// --- SESSION START ---
session_start(); // Ensure session is started

// --- SESSION & INPUT MANAGEMENT ---
// Store team_id from POST if provided
if (!empty($_POST['team_id'])) {
    $_SESSION['team_id'] = $_POST['team_id'];
}

// Retrieve team_id from POST or session
$teamIdRaw = $_POST['team_id'] ?? $_SESSION['team_id'] ?? null;
$teamId = is_numeric($teamIdRaw) ? (int)$teamIdRaw : null;

// Fail early if team_id is not valid
if (is_null($teamId)) {
    die("⚠️ Invalid or missing team_id.");
}

// Debug: Log final team_id used
error_log("Debug: Final team_id used: " . $teamId);

// --- ERROR REPORTING ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- REQUIRED POST VALUES ---
if (!isset($_POST['user_id']) || intval($_POST['user_id']) <= 0) {
    die("No valid user_id provided.");
}

$userId       = (int)$_POST['user_id'];
$formId       = $_POST['form_id'] ?? null;
$programName  = $_POST['program_name'] ?? '';
$programDate  = $_POST['program_date'] ?? null;
$totalScore   = (int)($_POST['total_score'] ?? 0);
$lessonCounts = ($_POST['lessonCount'] ?? []) ?: [];

// Debug: Log incoming data
error_log("Debug: Received program_name = " . json_encode($programName));
error_log("Debug: Received total_score = " . json_encode($totalScore));
error_log("Debug: Received lessonCounts = " . json_encode($lessonCounts));

// --- DATABASE CONNECTION ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- USER VALIDATION ---
$user_check_stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
$user_check_stmt->bind_param("i", $userId);
$user_check_stmt->execute();
$user_check_stmt->store_result();
if ($user_check_stmt->num_rows === 0) {
    die("Invalid user selected. Please try again.");
}
$user_check_stmt->bind_result($userName);
$user_check_stmt->fetch();
$user_check_stmt->close();

// --- ONE-ON-ONE LESSON ENTRY HANDLING ---
if (!empty($lessonCounts) && strtolower($formId) !== 'program') {
    $stmt = $conn->prepare("
        INSERT INTO scores (
            user_id, team_id, form_id, program, total_score,
            program_date, attendance, scaled_attendance,
            adjusted_impact_score, submission_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }

    foreach ($lessonCounts as $date => $countStr) {
        error_log("Debug: Checking date format for: " . $date);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            error_log("Invalid date format: " . $date);
            continue;
        }

        $count = (int)$countStr;

        if ($count > 0) {
            for ($i = 0; $i < $count; $i++) {
                $attendance = 1;
                $scaledAttendance = sqrt($attendance);
                $adjustedImpact = $totalScore + $scaledAttendance;

                $stmt->bind_param(
                    "iiisisidd",
                    $userId,
                    $teamId,
                    $formId,
                    $programName,
                    $totalScore,
                    $date,
                    $attendance,
                    $scaledAttendance,
                    $adjustedImpact
                );

                if ($stmt->execute()) {
                    $newScoreId = $stmt->insert_id;
                
                    // Insert staff list into score_users (if provided)
                    if (isset($_POST['staff']) && is_array($_POST['staff'])) {
                        $staffInsertStmt = $conn->prepare("INSERT INTO score_users (score_id, user_id, role) VALUES (?, ?, ?)");
                        foreach ($_POST['staff'] as $index => $staffId) {
                            $role = $_POST['role'][$index] ?? 'assistant';
                            $staffInsertStmt->bind_param("iis", $newScoreId, $staffId, $role);
                            $staffInsertStmt->execute();
                        }
                        $staffInsertStmt->close();
                    }
                
                } else {
                    echo "<p style='color:red;'>Failed to insert record for $date: " . $stmt->error . "</p>";
                }
                
            }
        }
    }

    $stmt->close();
}

// --- REDIRECT FOR ONE-ON-ONE FORMS ---
if (strtolower($formId) !== 'program') {
    $redirectUrl = "one_on_one_specialty.php?"
        . "user_id=" . urlencode($userId)
        . "&team_id=" . urlencode($teamId)
        . "&form_id=" . urlencode($formId)
        . "&program_name=" . urlencode($programName)
        . "&program_date=" . urlencode($programDate)
        . "&total_score=" . urlencode($totalScore);

    header("Location: $redirectUrl");
    exit();
}

// --- SINGLE PROGRAM ENTRY HANDLING ---
if (strtolower($formId) === 'program' || $programName !== '') {
    $stmt = $conn->prepare("
        INSERT INTO scores (
            user_id, team_id, form_id, program,
            total_score, program_date, submission_date
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }

    $stmt->bind_param(
        "iiisis",
        $userId,
        $teamId,
        $formId,
        $programName,
        $totalScore,
        $programDate
    );

    if ($stmt->execute()) {
        $newScoreId = $stmt->insert_id;
    
        // Insert staff list into score_users (if provided)
        if (isset($_POST['staff']) && is_array($_POST['staff'])) {
            $staffInsertStmt = $conn->prepare("INSERT INTO score_users (score_id, user_id, role) VALUES (?, ?, ?)");
            foreach ($_POST['staff'] as $index => $staffId) {
                $role = $_POST['role'][$index] ?? 'assistant';
                $staffInsertStmt->bind_param("iis", $newScoreId, $staffId, $role);
                $staffInsertStmt->execute();
            }
            $staffInsertStmt->close();
        }
    
        header("Location: view_scores.php?"
    
            . "team_id=" . urlencode($teamId)
            . "&program=" . urlencode($programName)
            . "&success=1");
        exit();
    } else {
        echo "<p style='color:red;'>Failed to record program score: " . $stmt->error . "</p>";
    }

    $stmt->close();
}

// --- FINAL FALLBACK REDIRECT ---
header("Location: view_scores.php?"
    . "team_id=" . urlencode($teamId)
    . "&success=1");

$conn->close();
exit();
?>
