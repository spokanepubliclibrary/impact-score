<?php
/**
 * get_forms.php
 *
 * This script returns JSON-encoded form data based on GET parameters.
 *
 * It supports two modes:
 *  1. If a "team_id" parameter is provided, it returns all form profiles
 *     for that team (id and name).
 *  2. If a "form_id" parameter is provided, it returns detailed information
 *     about that form, including:
 *       - Form details (name and bulk_entry flag)
 *       - Associated questions, with their options and prefill values
 *         (grouped so that each question includes an array of options).
 *
 * If neither "team_id" nor "form_id" is provided, the script returns an error message.
 *
 * Prerequisites:
 *  - A valid database connection is provided by 'secure/db_connection.php'.
 *  - The following tables must exist:
 *       * form_profiles (with columns id, name, bulk_entry, team_id, etc.)
 *       * form_questions (associating forms with questions)
 *       * scoring_questions (question details)
 *       * scoring_options (for multiple-choice questions)
 *       * form_prefill_values (for default values)
 *
 * Usage:
 *  - To retrieve form profiles for a team, pass: ?team_id=XYZ
 *  - To retrieve full form details, pass: ?form_id=XYZ
 *
 * @package FormDataAPI
 * @version 1.0
 */

// --- Enable Error Reporting and Set JSON Header ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// --- Load Database Credentials and Establish Connection ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(["error" => "Connection failed: " . $conn->connect_error]);
    exit;
}

// --- Mode 1: Return Forms for a Given Team ---
if (isset($_GET['team_id'])) {
    $team_id = intval($_GET['team_id']);

    $stmt = $conn->prepare("SELECT id, name FROM form_profiles WHERE team_id = ?");
    if (!$stmt) {
        echo json_encode(["error" => "Query prepare failed: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("i", $team_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $forms = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode($forms);
    $stmt->close();
    $conn->close();
    exit;
}

// --- Mode 2: Return Detailed Form Data for a Given Form ID ---
if (isset($_GET['form_id'])) {
    $form_id = intval($_GET['form_id']);

    // Fetch form details from form_profiles
    $stmtForm = $conn->prepare("SELECT name, bulk_entry FROM form_profiles WHERE id = ?");
    if (!$stmtForm) {
        echo json_encode(["error" => "Form query prepare failed: " . $conn->error]);
        exit;
    }
    $stmtForm->bind_param("i", $form_id);
    $stmtForm->execute();
    $formResult = $stmtForm->get_result();
    $formInfo = $formResult->fetch_assoc();
    $stmtForm->close();

    if (!$formInfo) {
        echo json_encode(["error" => "Form not found for form_id " . $form_id]);
        $conn->close();
        exit;
    }

    // Fetch questions, options, and prefill values for the form
    $stmt = $conn->prepare("
        SELECT
            q.id AS question_id,
            q.question_text,
            CASE q.type
                WHEN 'multiple_choice' THEN 'multiple_choice'
                ELSE 'binary'
            END AS question_type,
            q.points,
            so.id AS option_id,
            so.option_text,
            so.option_points,
            fp.prefill_value

        FROM form_questions fq
        JOIN scoring_questions q ON fq.question_id = q.id
        LEFT JOIN scoring_options so ON q.id = so.question_id
        LEFT JOIN form_prefill_values fp ON fq.form_id = fp.form_id AND fq.question_id = fp.question_id
        WHERE fq.form_id = ?
        ORDER BY fq.sort_order ASC, q.question_order ASC
    ");
    if (!$stmt) {
        echo json_encode(["error" => "Question query prepare failed: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("i", $form_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Group rows by question so that each question includes an array of options
    $question_map = [];
    while ($row = $result->fetch_assoc()) {
        $qid = $row['question_id'];
        if (!isset($question_map[$qid])) {
            $question_map[$qid] = [
                'question_id'   => $row['question_id'],
                'question_text' => $row['question_text'],
                'question_type' => $row['question_type'],
                'points'        => $row['points'],
                'prefill_value' => $row['prefill_value'],
                'options'       => []
            ];
        }
        if (!is_null($row['option_text'])) {
            $question_map[$qid]['options'][] = [
                'option_id' => $row['option_id'],
                'text'      => $row['option_text'],
                'points'    => $row['option_points']
            ];
        }
        
    }
    $stmt->close();

    // --- Optional: overlay previous score_responses as prefill values ---
    $prefill_score_id = isset($_GET['prefill_score_id']) ? (int)$_GET['prefill_score_id'] : 0;
    if ($prefill_score_id > 0) {
        $stmtR = $conn->prepare("SELECT question_id, points FROM score_responses WHERE score_id = ?");
        if ($stmtR) {
            $stmtR->bind_param("i", $prefill_score_id);
            $stmtR->execute();
            $rRes = $stmtR->get_result();
            while ($rRow = $rRes->fetch_assoc()) {
                $qid = $rRow['question_id'];
                if (isset($question_map[$qid])) {
                    $question_map[$qid]['prefill_value'] = $rRow['points'];
                }
            }
            $stmtR->close();
        }
    }

    $conn->close();

    $questions = array_values($question_map);
    if (empty($questions)) {
        echo json_encode(["error" => "No questions found for form_id " . $form_id]);
    } else {
        echo json_encode([
            "form_name"  => $formInfo['name'],
            "bulk_entry" => (int)$formInfo['bulk_entry'],
            "questions"  => $questions
        ]);
    }
    exit;
}

// --- Mode 3: Neither team_id nor form_id Provided ---
echo json_encode(["error" => "No valid parameter provided (team_id or form_id)."]);
$conn->close();
exit;
?>
