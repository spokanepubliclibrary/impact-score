<?php
// Admin Tool: Promote Submitted Program to Bulk Form Template (Clean Version)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once('secure/db_connection.php');
session_start();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['program_name'], $_POST['team_id'])) {
    $programName = trim($_POST['program_name']);
    $teamId = intval($_POST['team_id']);

    $checkStmt = $conn->prepare("SELECT id FROM form_profiles WHERE name = ? AND bulk_entry = 1");
    $checkStmt->bind_param("s", $programName);
    $checkStmt->execute();
    $checkStmt->store_result();

    if ($checkStmt->num_rows > 0) {
        $message = "❗ A bulk form for this program already exists.";
    } else {
        $insertStmt = $conn->prepare("INSERT INTO form_profiles (team_id, name, bulk_entry) VALUES (?, ?, 1)");
        $insertStmt->bind_param("is", $teamId, $programName);
        $insertStmt->execute();
        $insertStmt->close();
        $newFormId = $conn->insert_id;

        $scoreStmt = $conn->prepare("
            SELECT s.id
            FROM scores s
            WHERE s.program = ?
            AND EXISTS (SELECT 1 FROM score_responses sr WHERE sr.score_id = s.id)
            ORDER BY s.submission_date DESC
            LIMIT 1
        ");
        $scoreStmt->bind_param("s", $programName);
        $scoreStmt->execute();
        $scoreResult = $scoreStmt->get_result();
        $scoreRow = $scoreResult->fetch_assoc();
        $scoreStmt->close();

        if ($scoreRow) {
            $scoreId = $scoreRow['id'];

            $responseStmt = $conn->prepare("SELECT question_id, response FROM score_responses WHERE score_id = ?");
            $responseStmt->bind_param("i", $scoreId);
            $responseStmt->execute();
            $responseResult = $responseStmt->get_result();

            $assignQStmt = $conn->prepare("INSERT IGNORE INTO form_questions (form_id, question_id) VALUES (?, ?)");
            $assignedQs = [];
            $count = 0;

            while ($row = $responseResult->fetch_assoc()) {
                $qId = $row['question_id'];
                $resp = $row['response'];

                if ($newFormId && $qId && isset($resp) && trim($resp) !== '') {
                    $prefillStmt = $conn->prepare("INSERT INTO form_prefill_values (form_id, question_id, prefill_value) VALUES (?, ?, ?)");
                    $prefillStmt->bind_param("iis", $newFormId, $qId, $resp);
                    if ($prefillStmt->execute()) {
                        $count++;
                    }
                    $prefillStmt->close();

                    if (!in_array($qId, $assignedQs)) {
                        $assignQStmt->bind_param("ii", $newFormId, $qId);
                        $assignQStmt->execute();
                        $assignedQs[] = $qId;
                    }
                }
            }

            $responseStmt->close();
            $assignQStmt->close();

            $message = "✅ Done. $count prefill(s) inserted for '$programName'.";
        } else {
            $message = "❌ No scored responses found for this program.";
        }
    }

    $checkStmt->close();
}

$programsResult = $conn->query("
    SELECT DISTINCT s.program
    FROM scores s
    LEFT JOIN form_profiles f ON s.program = f.name AND f.bulk_entry = 1
    WHERE f.id IS NULL AND s.program IS NOT NULL AND s.program != ''
    ORDER BY s.program
");
$programs = $programsResult ? $programsResult->fetch_all(MYSQLI_ASSOC) : [];

$teamsResult = $conn->query("SELECT id, name FROM teams ORDER BY name");
$teams = $teamsResult ? $teamsResult->fetch_all(MYSQLI_ASSOC) : [];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Promote Program to Bulk Form</title>
</head>
<body>
    <h2>Promote a Submitted Program to Bulk Entry</h2>
    <?php if (!empty($message)) echo "<p><strong>$message</strong></p>"; ?>

    <form method="POST">
        <label for="program_name">Select Program:</label>
        <select name="program_name" id="program_name" required>
            <option value="">-- Choose Program --</option>
            <?php foreach ($programs as $p): ?>
                <option value="<?= htmlspecialchars($p['program']) ?>"><?= htmlspecialchars($p['program']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="team_id">Assign to Team:</label>
        <select name="team_id" id="team_id" required>
            <option value="">-- Choose Team --</option>
            <?php foreach ($teams as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Promote to Bulk</button>
    </form>
</body>
</html>
