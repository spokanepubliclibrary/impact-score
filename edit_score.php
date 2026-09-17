<?php
/**
 * edit_score.php
 *
 * This script displays and processes a form for editing a score submission.
 * It performs the following tasks:
 *   1. Establishes a database connection using secure credentials.
 *   2. Validates the presence of a numeric score ID via GET.
 *   3. Fetches the main score details for the provided ID.
 *   4. Retrieves all users for the dropdown list.
 *   5. Processes form submissions (via POST) to update the main score details,
 *      update or insert individual question responses, and recalculate the total score.
 *   6. Fetches all available questions and existing responses for display.
 *
 * The page outputs an HTML form with prefilled values for editing the score,
 * and includes a live-updating total score display using JavaScript.
 *
 * Prerequisites:
 *   - A valid MySQL connection provided by 'secure/db_connection.php'.
 *   - Database tables: scores, users, scoring_questions, score_responses.
 *
 * @package ImpactScoreAdmin
 * @version 1.1
 */

// --- Enable Error Reporting ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Load Database Credentials and Connect ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- Validate GET Parameter for Score ID ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid request. No ID provided.");
}
$score_id = intval($_GET['id']);

// NEW: remember where to go back to (passed in from view_scores.php)
$return_url = $_GET['return_url'] ?? 'view_scores.php';


// --- 1) Fetch Main Score Details ---
$stmt = $conn->prepare("
    SELECT user_id, team_id, program, program_date, total_score, location_id
    FROM scores
    WHERE id = ?
");
$stmt->bind_param("i", $score_id);
$stmt->execute();
$score_result = $stmt->get_result();
if ($score_result->num_rows === 0) {
    echo "<p style='color:red;'>No row found for score id: {$score_id}</p>";
    die("No record found.");
}
$score = $score_result->fetch_assoc();
$stmt->close();
// Use the stored total_score from the database for display
$total_score = (int)$score['total_score'];

// --- 1b) Fetch all staff linked via score_users for this score ---
$linkedUserIds = [];
$ls = $conn->prepare("SELECT DISTINCT user_id FROM score_users WHERE score_id = ?");
$ls->bind_param("i", $score_id);
$ls->execute();
$lsRes = $ls->get_result();
while ($r = $lsRes->fetch_assoc()) {
    $linkedUserIds[] = (int)$r['user_id'];
}
$ls->close();

// Extras we’ll preselect in the multi-select (exclude the primary)
$selectedExtras = array_values(array_diff($linkedUserIds, [(int)$score['user_id']]));


// --- 2) Fetch All Users for the Dropdown ---
$user_query = "SELECT id, name FROM users ORDER BY name ASC";
$user_result = $conn->query($user_query);
$users = [];
while ($row = $user_result->fetch_assoc()) {
    $users[] = $row;
}

// --- 2.5) Fetch All Locations for the Dropdown ---
$location_query = $conn->query("SELECT id, name FROM locations ORDER BY name ASC");
$locations = [];
while ($row = $location_query->fetch_assoc()) {
    $locations[] = $row;
}

/* --- 2.6) Fetch All Teams for the Dropdown (dynamic team IDs so FK never fails) --- */
$teams = [];
$tres = $conn->query("SELECT id, name FROM teams ORDER BY name ASC");
while ($row = $tres->fetch_assoc()) {
    $teams[] = $row;
}

/* --- 2.7) Event-type options for Question 4 (option_id => points/text) --- */
$eventTypeOptions = []; // for building the dropdown
$eventTypeById    = []; // option_id => ['text'=>..., 'points'=>...]
$eventTypeByPoints = []; // points => option_id (best-effort, assumes unique)

$evRes = $conn->query("
    SELECT id, option_text, points, option_points
    FROM scoring_options
    WHERE question_id = 4
    ORDER BY id
");
if ($evRes) {
    while ($row = $evRes->fetch_assoc()) {
        $oid = (int)$row['id'];
        $pts = (int)($row['option_points'] ?: $row['points']); // keep your “prefer option_points” safety

        $eventTypeById[$oid] = [
            'text'   => (string)$row['option_text'],
            'points' => $pts,
        ];

        // best-effort reverse map (OK if points are unique; if not, last one wins)
        if (!isset($eventTypeByPoints[$pts])) {
            $eventTypeByPoints[$pts] = $oid;
        }

        $eventTypeOptions[] = [
            'id'     => $oid,
            'text'   => (string)$row['option_text'],
            'points' => $pts,
        ];
    }
}

/* --- 2.7) Program tags --- */
$allTags = [];
$tagRes = $conn->query("SELECT id, name FROM program_tags ORDER BY name ASC");
if ($tagRes) { while ($r = $tagRes->fetch_assoc()) $allTags[] = $r; }

$currentTagIds = [];
$ctStmt = $conn->prepare("SELECT tag_id FROM score_tags WHERE score_id = ?");
$ctStmt->bind_param("i", $score_id);
$ctStmt->execute();
$ctRes = $ctStmt->get_result();
while ($r = $ctRes->fetch_assoc()) $currentTagIds[] = (int)$r['tag_id'];
$ctStmt->close();

/* --- 2.75) Performer data for optional linking --- */
$allPerformers = [];
$pRes = $conn->query("SELECT performer_id, stage_name, performer_type FROM performers WHERE status='active' ORDER BY stage_name");
if ($pRes) { while ($r = $pRes->fetch_assoc()) $allPerformers[] = $r; }

// Currently linked performer/program for this score
$linkedPerformer = null;
$lpStmt = $conn->prepare("SELECT iep.id AS iep_id, iep.performer_id, iep.program_id FROM impact_event_performers iep WHERE iep.score_id = ? LIMIT 1");
$lpStmt->bind_param("i", $score_id);
$lpStmt->execute();
$linkedPerformer = $lpStmt->get_result()->fetch_assoc() ?: null;
$lpStmt->close();


/* --- 2.8) Allowed points per question (server-side validation) --- */
$allowedPointsByQuestion = []; // qid => [points=>true, ...]
$optRes = $conn->query("
    SELECT question_id, points, option_points
    FROM scoring_options
");
if ($optRes) {
    while ($r = $optRes->fetch_assoc()) {
        $qid = (int)$r['question_id'];
        $pts = (int)($r['option_points'] ?: $r['points']);
        if (!isset($allowedPointsByQuestion[$qid])) $allowedPointsByQuestion[$qid] = [];
        $allowedPointsByQuestion[$qid][$pts] = true;
    }
}



// --- 3) Process Form Submission for Updates ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['score_id'])) {
    $score_id     = (int)$_POST['score_id'];
    $user_id      = (int)$_POST['name'];       // User ID from dropdown
    $team_id      = (int)$_POST['team_id'];    // Team ID (cast to int)
    $program      = $_POST['program'];
    $program_date = $_POST['program_date'] ?? null;
    $location_id  = isset($_POST['location_id']) ? (int)$_POST['location_id'] : null;

    // Multi-staff from form (users[]) — may be empty
    $extraUsers = [];
    if (!empty($_POST['users']) && is_array($_POST['users'])) {
        $extraUsers = array_values(
            array_filter(
                array_map('intval', $_POST['users']),
                fn($v) => $v > 0
            )
        );
    }

    // Build the full, de-duped set we’ll persist to score_users (primary + extras)
    $allUserIds = array_values(array_unique(array_merge([$user_id], $extraUsers)));

    // 🔒 Validate required fields
    $errors = [];
    if (!$location_id)    { $errors[] = "⚠️ Location is required. Please select one."; }
    if ($team_id <= 0)    { $errors[] = "⚠️ Team is required. Please select one."; }
    if ($user_id <= 0)    { $errors[] = "⚠️ Primary staff is required. Please select one."; }
    if (empty($program))  { $errors[] = "⚠️ Program is required."; }
    if (empty($program_date)) { $errors[] = "⚠️ Program date is required."; }

    if (!empty($errors)) {
        echo "<div style='color:red;'><strong>Please fix the following:</strong><ul>";
        foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>";
        echo "</ul></div>";
    } else {

        // ✅ NEW: TRANSACTION so all writes succeed together or none do
        $conn->begin_transaction();
        try {

            // Update main score entry
            $stmt = $conn->prepare("
                UPDATE scores
                   SET user_id = ?, team_id = ?, program = ?, program_date = ?, location_id = ?
                 WHERE id = ?
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed (scores update): {$conn->error}");
            }
            // Binding types: i (user_id), i (team_id), s (program), s (program_date), i (location_id), i (score_id)
            $stmt->bind_param("iissii", $user_id, $team_id, $program, $program_date, $location_id, $score_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed (scores update): {$stmt->error}");
            }
            $stmt->close();

            // --- Keep score_users in sync with the edited staff set ---
            // Save program tags
            $delTags = $conn->prepare("DELETE FROM score_tags WHERE score_id = ?");
            $delTags->bind_param("i", $score_id); $delTags->execute(); $delTags->close();
            $selectedTags = isset($_POST['program_tags']) ? array_map('intval', (array)$_POST['program_tags']) : [];
            if (!empty($selectedTags)) {
                $insTags = $conn->prepare("INSERT IGNORE INTO score_tags (score_id, tag_id) VALUES (?, ?)");
                foreach ($selectedTags as $tid) {
                    if ($tid > 0) { $insTags->bind_param("ii", $score_id, $tid); $insTags->execute(); }
                }
                $insTags->close();
            }

            $del = $conn->prepare("DELETE FROM score_users WHERE score_id = ?");
            if (!$del) {
                throw new Exception("Prepare failed (score_users delete): {$conn->error}");
            }
            $del->bind_param("i", $score_id);
            if (!$del->execute()) {
                throw new Exception("Execute failed (score_users delete): {$del->error}");
            }
            $del->close();

            if (!empty($allUserIds)) {
                // safest: rely on DB default for `role`
                $ins = $conn->prepare("INSERT INTO score_users (score_id, user_id) VALUES (?, ?)");
                if (!$ins) {
                    throw new Exception("Prepare failed (score_users insert): {$conn->error}");
                }
                foreach ($allUserIds as $uid) {
                    if ($uid > 0) {
                        $ins->bind_param("ii", $score_id, $uid);
                        if (!$ins->execute()) {
                            throw new Exception("Insert failed (score_users): {$ins->error}");
                        }
                    }
                }
                $ins->close();
            }

            // --- 4) Update Responses and Calculate Total Score ---
            if (!isset($_POST['responses'])) {
                // keep existing total_score if no responses posted
            } else {
                $total_score = 0; // Only reset if we actually have responses

                foreach ($_POST['responses'] as $question_id => $points) {
                    $question_id = (int)$question_id;
                    $rawValue    = (int)$points; // this is “points” for numeric inputs, but will be option_id for Q4
                    $points      = 0;
                    $responseText = null;


            
                    // Special handling for Question 4 (event type): select posts option_id
if ($question_id === 4) {
    $optionId = $rawValue;

    $points = $eventTypeById[$optionId]['points'] ?? 0;
    $responseText = $eventTypeById[$optionId]['text'] ?? '';

    $total_score += $points;

    // Upsert score_responses row for q4 (store points + label)
    $stmt = $conn->prepare("
        SELECT id
        FROM score_responses
        WHERE score_id = ?
          AND question_id = ?
    ");
    if (!$stmt) throw new Exception("Prepare failed (select score_responses q4): {$conn->error}");
    $stmt->bind_param("ii", $score_id, $question_id);
    if (!$stmt->execute()) throw new Exception("Execute failed (select score_responses q4): {$stmt->error}");
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        $stmt = $conn->prepare("
            UPDATE score_responses
               SET points = ?, response = ?
             WHERE score_id = ?
               AND question_id = ?
        ");
        if (!$stmt) throw new Exception("Prepare failed (update score_responses q4): {$conn->error}");
        $stmt->bind_param("isii", $points, $responseText, $score_id, $question_id);
        if (!$stmt->execute()) throw new Exception("Execute failed (update score_responses q4): {$stmt->error}");
        $stmt->close();
    } else {
        $stmt->close();
        $stmt = $conn->prepare("
            INSERT INTO score_responses (score_id, question_id, response, points)
            VALUES (?, ?, ?, ?)
        ");
        if (!$stmt) throw new Exception("Prepare failed (insert score_responses q4): {$conn->error}");
        $stmt->bind_param("iisi", $score_id, $question_id, $responseText, $points);
        if (!$stmt->execute()) throw new Exception("Execute failed (insert score_responses q4): {$stmt->error}");
        $stmt->close();
    }

    continue;
}
// Default: numeric inputs (but clamp if this question has scoring_options)
$points = $rawValue;

// If the question has defined options, only accept those points
if (isset($allowedPointsByQuestion[$question_id])) {
    if (!isset($allowedPointsByQuestion[$question_id][$points])) {
        $points = 0; // reject junk values like 37
    }
}

// Optional: always enforce non-negative
if ($points < 0) $points = 0;

$total_score += $points;


                    // --- Default path for all other questions (same as before) ---

                    // Check if a response exists for this question
                    $stmt = $conn->prepare("
                        SELECT id
                        FROM score_responses
                        WHERE score_id = ?
                          AND question_id = ?
                    ");
                    if (!$stmt) {
                        throw new Exception("Prepare failed (select score_responses): {$conn->error}");
                    }
                    $stmt->bind_param("ii", $score_id, $question_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed (select score_responses): {$stmt->error}");
                    }
                    $stmt->store_result();

                    // Update existing response or insert new one
                    if ($stmt->num_rows > 0) {
                        $stmt->close();
                        $stmt = $conn->prepare("
                            UPDATE score_responses
                               SET points = ?
                             WHERE score_id = ?
                               AND question_id = ?
                        ");
                        if (!$stmt) {
                            throw new Exception("Prepare failed (update score_responses): {$conn->error}");
                        }
                        $stmt->bind_param("iii", $points, $score_id, $question_id);
                        if (!$stmt->execute()) {
                            throw new Exception("Execute failed (update score_responses): {$stmt->error}");
                        }
                        $stmt->close();
                    } else {
                        $stmt->close();
                        $stmt = $conn->prepare("
                            INSERT INTO score_responses (score_id, question_id, points)
                            VALUES (?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Prepare failed (insert score_responses): {$conn->error}");
                        }
                        $stmt->bind_param("iii", $score_id, $question_id, $points);
                        if (!$stmt->execute()) {
                            throw new Exception("Execute failed (insert score_responses): {$stmt->error}");
                        }
                        $stmt->close();
                    }
                }

                // --- 5) Update Total Score in Scores Table (recomputed) ---
                $stmt = $conn->prepare("
                    UPDATE scores
                       SET total_score = ?
                     WHERE id = ?
                ");
                if (!$stmt) {
                    throw new Exception("Prepare failed (scores total_score update): {$conn->error}");
                }
                $stmt->bind_param("ii", $total_score, $score_id);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed (scores total_score update): {$stmt->error}");
                }
                $stmt->close();
            }

            // ✅ All good
            $conn->commit();

            // Redirect after successful updates (preserve filters)
$returnUrl = $_POST['return_url'] ?? $_GET['return_url'] ?? 'view_scores.php';

// Safety: only allow local relative return paths (no absolute URLs)
if (!is_string($returnUrl) || $returnUrl === '' || preg_match('/^https?:\/\//i', $returnUrl)) {
    $returnUrl = 'view_scores.php';
}

header('Location: ' . $returnUrl);
exit;


        } catch (Exception $ex) {
            // 👀 If any step failed, nothing is persisted
            $conn->rollback();
            echo "<p style='color:red;'>Save failed: " . htmlspecialchars($ex->getMessage()) . "</p>";
        }
    }
}

// --- 6) Fetch All Possible Questions ---
$questions = [];
$stmt = $conn->prepare("
    SELECT id, question_text
    FROM scoring_questions
    ORDER BY id ASC
");
$stmt->execute();
$question_results = $stmt->get_result();
while ($row = $question_results->fetch_assoc()) {
    $questions[$row['id']] = $row['question_text'];
}
$stmt->close();

// --- 7) Fetch Existing Responses for Display ---
$responses = [];
$q4ResponseText = null; // label stored alongside points for Q4; used as canonical identity when option_points collide (e.g. Exhibit=1 and Outreach=1)
$stmt = $conn->prepare("
    SELECT question_id, points, response
    FROM score_responses
    WHERE score_id = ?
");
$stmt->bind_param("i", $score_id);
$stmt->execute();
$response_results = $stmt->get_result();
while ($row = $response_results->fetch_assoc()) {
    $qid = (int)$row['question_id'];
    $responses[$qid] = $row['points'];
    if ($qid === 4) {
        $q4ResponseText = $row['response'];
    }
}
$stmt->close();

// Normalize legacy event-type values for Q4:
// If stored value looks like an option_id (exists in scoring_options.id for q4), convert to option_points
if (isset($responses[4])) {
    $v = (int)$responses[4];

    // If it's already one of the valid points, keep it
    $isValidPoints = isset($allowedPointsByQuestion[4]) && isset($allowedPointsByQuestion[4][$v]);

    if (!$isValidPoints && isset($eventTypeById[$v])) {
        // It was an option_id stored in points column (legacy). Convert to real points.
        $responses[4] = (int)$eventTypeById[$v]['points'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!--
      Edit Submission Page
      This page allows users to edit an existing score submission.
      It displays a form prefilled with current score details, user selections, and response values.
      Users can update the main score details as well as individual question responses.
    -->
    <meta charset="UTF-8">
    <title>Edit Submission</title>
    <style>
        /* Global Styles */
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background: #f5f2ec; /* Parchment background */
            padding: 20px;
        }
        /* Container Styling */
        .container {
            max-width: 600px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin: auto;
        }
        h2 {
            text-align: center;
        }
        label {
            font-weight: bold;
            display: block;
            margin-top: 10px;
        }
        /* Form Controls */
        input, select {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        /* Button Styling */
        .btn {
            display: block;
            width: 100%;
            padding: 10px;
            background: #480d3c; /* Deep Plum */
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            margin-top: 20px;
            cursor: pointer;
        }
        .btn:hover {
            background: #bb1b51; /* Fuchsia */
        }
        /* Score Display */
        .score-container {
            text-align: center;
            font-size: 20px;
            margin-bottom: 10px;
            font-weight: bold;
        }
        /* Floating Score Display */
        .floating-score {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #ffcc00;
            padding: 10px 20px;
            font-size: 18px;
            font-weight: bold;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
        }
    </style>
    <script>
        // Update the total score dynamically as responses change
        function updateTotalScore() {
    let total = 0;

    document.querySelectorAll(".score-input").forEach(el => {
        if (el.tagName === "SELECT") {
            const opt = el.options[el.selectedIndex];
            const pts = parseInt(opt?.dataset?.points || "0", 10) || 0;
            total += pts;
        } else {
            total += parseInt(el.value, 10) || 0;
        }
    });

    document.getElementById("total-score").innerText = total;
    document.getElementById("floating-score").innerText = "Total Score: " + total;
}

document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".score-input").forEach(input => {
        input.addEventListener("input", updateTotalScore);
        input.addEventListener("change", updateTotalScore);
    });
    updateTotalScore();
});
    </script>
</head>
<body>
<div class="container">
    <!-- Display the current total score -->
    <div class="score-container">
        Total Score: <span id="total-score"><?= htmlspecialchars($total_score) ?></span>
    </div>
    <h2>✏️ Edit Submission</h2>
    <form method="POST" action="edit_score.php?id=<?= (int)$score_id ?>&return_url=<?= urlencode($return_url) ?>">

        <input type="hidden" name="score_id" value="<?= $score_id ?>">
        <!-- NEW: keep the return URL during POST -->
        <input type="hidden" name="return_url" value="<?= htmlspecialchars($return_url) ?>">

        <!-- Primary Staff (stored on scores.user_id) -->
        <label><strong>Primary staff:</strong></label>
        <select name="name" required>
            <option value="">Select a user</option>
            <?php foreach ($users as $user): ?>
                <option value="<?= (int)$user['id'] ?>"
                    <?= ((int)$user['id'] === (int)$score['user_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($user['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Additional Staff (stored in score_users) -->
        <label style="margin-top:12px;"><strong>Additional staff on this score (hold Ctrl/Cmd to multi-select):</strong></label>
        <select name="users[]" multiple size="6">
            <?php foreach ($users as $user):
                $uid = (int)$user['id'];
                // Preselect extras; exclude primary here to avoid duplicate selection confusion
                $sel = in_array($uid, $selectedExtras, true) ? 'selected' : '';
            ?>
                <option value="<?= $uid ?>" <?= $sel ?>>
                    <?= htmlspecialchars($user['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <small style="display:block;margin-top:4px;color:#555;">
            Tip: Primary is set above. Everyone selected here will also be linked to the score.
        </small>

        <!-- Team Dropdown -->
        <label><strong>Team:</strong></label>
        <select name="team_id">
            <?php foreach ($teams as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === (int)$score['team_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Location Dropdown -->
        <label><strong>Location:</strong></label>
        <select name="location_id" required>
            <option value="">-- Select Location --</option>
            <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= ($loc['id'] == $score['location_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($loc['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Program Name Input -->
        <label><strong>Program:</strong></label>
        <input type="text" name="program"
               value="<?= htmlspecialchars($score['program']) ?>" required>

        <!-- Program Date Input -->
        <label><strong>Program Date:</strong></label>
        <input type="date" name="program_date"
               value="<?= htmlspecialchars($score['program_date'] ?? '') ?>" required>

        <!-- Performer (optional) -->
        <label style="margin-top:12px;"><strong>Performer (optional):</strong></label>
        <select name="performer_id" id="edit_performer_id" onchange="editLoadPrograms(this.value)" style="width:100%;padding:8px;margin-top:5px;border:1px solid #ccc;border-radius:4px;">
          <option value="">-- No Performer --</option>
          <?php foreach ($allPerformers as $perf): ?>
            <option value="<?= (int)$perf['performer_id'] ?>"
              <?= ($linkedPerformer && $linkedPerformer['performer_id'] == $perf['performer_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($perf['stage_name']) ?>
              <?php if ($perf['performer_type']): ?>(<?= htmlspecialchars(ucfirst($perf['performer_type'])) ?>)<?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div id="edit_program_select_div" style="display:none;margin-top:8px;">
          <label><strong>Performer's Program (optional):</strong></label>
          <select name="performer_program_id" id="edit_performer_program_id" style="width:100%;padding:8px;margin-top:5px;border:1px solid #ccc;border-radius:4px;">
            <option value="">-- Select a program --</option>
          </select>
        </div>

        <div id="add_to_catalog_div" style="display:none;margin-top:8px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">
            <input type="checkbox" name="add_to_catalog" value="1" style="width:auto;">
            Add "<?= htmlspecialchars($score['program']) ?>" to this performer's program catalog
          </label>
          <small style="color:#888;display:block;margin-top:2px;">Only adds if not already listed.</small>
        </div>

        <?php if (!empty($allTags)): ?>
        <div style="margin-bottom:20px;">
          <label style="font-weight:600;display:block;margin-bottom:8px;">Program Tags</label>
          <div style="display:flex;flex-wrap:wrap;gap:12px;">
            <?php foreach ($allTags as $tag): ?>
              <div class="form-check" style="min-width:120px;">
                <input class="form-check-input" type="checkbox"
                       name="program_tags[]"
                       id="etag_<?= $tag['id'] ?>"
                       value="<?= $tag['id'] ?>"
                       <?= in_array((int)$tag['id'], $currentTagIds) ? 'checked' : '' ?>>
                <label class="form-check-label" for="etag_<?= $tag['id'] ?>">
                  <?= htmlspecialchars($tag['name']) ?>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>


        <h3>Responses</h3>
        <!-- Loop through each question to display its corresponding response input -->
        <?php foreach ($questions as $id => $question_text): ?>
            <label><strong><?= htmlspecialchars($question_text) ?>:</strong></label>

            <?php if ($id == 4 && !empty($eventTypeOptions)): ?>
    <?php
        // Prefer the stored response label (unique per option) over points
        // (which can collide — e.g. Exhibit=1 pt and Outreach=1 pt). Fall back
        // to points-based reverse lookup only for legacy rows where `response`
        // is NULL or numeric.
        $selectedOptionId = 0;
        if (is_string($q4ResponseText) && trim($q4ResponseText) !== '') {
            $needle = strtolower(trim($q4ResponseText));
            foreach ($eventTypeOptions as $opt) {
                if (strtolower(trim($opt['text'])) === $needle) {
                    $selectedOptionId = (int)$opt['id'];
                    break;
                }
            }
        }
        if ($selectedOptionId === 0) {
            $storedPoints = isset($responses[4]) ? (int)$responses[4] : 0;
            $selectedOptionId = $eventTypeByPoints[$storedPoints] ?? 0;
        }
    ?>
    <select name="responses[4]" class="score-input" data-kind="event-type">
        <option value="0" data-points="0">-- Select event type --</option>
        <?php foreach ($eventTypeOptions as $opt): ?>
            <option
                value="<?= (int)$opt['id'] ?>"
                data-points="<?= (int)$opt['points'] ?>"
                <?= ((int)$opt['id'] === (int)$selectedOptionId) ? 'selected' : '' ?>
            >
                <?= htmlspecialchars($opt['text']) ?> (<?= (int)$opt['points'] ?>)
            </option>
        <?php endforeach; ?>
    </select>
<?php else: ?>

                <!-- All other questions stay as numeric inputs -->
                <input type="number" class="score-input"
                       name="responses[<?= (int)$id ?>]"
                       value="<?= htmlspecialchars($responses[$id] ?? '0') ?>"
                       min="0">
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Submit Button to Save Changes -->
        <button type="submit" class="btn">💾 Save Changes</button>
    </form>
</div>
<!-- Floating Score Display for Real-Time Updates -->
<div class="floating-score" id="floating-score">Total Score: <?= htmlspecialchars($total_score) ?></div>
</body>
</html>
<?php
$conn->close();
?>
