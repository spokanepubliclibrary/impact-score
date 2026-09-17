<?php
/**
 * Community Engagement Impact Score
 *
 * This script handles the submission and processing of community engagement impact scores.
 * It manages user sessions, processes form submissions (both bulk and non-bulk entries),
 * calculates and verifies total scores based on individual question responses, and saves the results
 * into the database using prepared statements. Additionally, the script dynamically loads form details
 * and questions via JavaScript, updating the UI in real-time.
 *
 * Key Features:
 * - Session management for resetting and maintaining form data.
 * - Database interactions including inserting new scores and responses.
 * - Dynamic form handling that distinguishes between bulk and non-bulk form entries.
 * - Real-time score calculation and JavaScript-driven UI updates.
 *
 * Prerequisites:
 * - A valid MySQL database connection provided by 'secure/db_connection.php'.
 * - Required database tables: `users`, `teams`, `form_profiles`, `scores`, and `score_responses`.
 *
 * Usage:
 * Include this script in your project to facilitate community engagement scoring. Ensure that
 * the necessary database setup and configuration are completed before use.
 *
 * @package CommunityEngagementImpactScore
 * @version 1.0
 */
 
// Start the session
session_start();

// --- AUTH GUARD ---
// Require user login. Redirect to login page if not authenticated.
if (empty($_SESSION['user_logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: user_login.php?redirect=value_score_form.php' . (isset($_GET['fresh']) ? '%3Ffresh%3D1' : ''));
    exit;
}

// --- SESSION MANAGEMENT ---
// Step 0: Clear specific session variables if a "fresh" link is clicked.
// This resets the session state to ensure no residual data from previous entries.
if (isset($_GET['fresh'])) {
    unset($_SESSION['user'],
          $_SESSION['team'],
          $_SESSION['form'],
          $_SESSION['program_name'],
          $_SESSION['program_date'],
          $_SESSION['total_score'],
          $_SESSION['performer_id'],
          $_SESSION['prefill_score_id']);
}

// --- FORM SUBMISSION HANDLING ---
// Step 1: Store submitted form values into the session.
// This allows the application to pre-select values when the user navigates back.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['final_submit'])) {
  // NEW: capture multi-select users
    $_SESSION['users']        = isset($_POST['users']) ? array_map('intval', (array)$_POST['users']) : [];
    $_SESSION['user']         = $_POST['user']         ?? '';
    $_SESSION['team']         = $_POST['team']         ?? '';
    $_SESSION['form']         = $_POST['form']         ?? '';
    $_SESSION['program_name'] = $_POST['program_name'] ?? '';
    $_SESSION['program_date'] = $_POST['program_date'] ?? '';
    $location_id = isset($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $_SESSION['location_id'] = $location_id;
    $_SESSION['total_score']  = $_POST['total_score']  ?? 0;
    $_SESSION['performer_id'] = isset($_POST['performer_id']) ? (int)$_POST['performer_id'] : 0;
    $_SESSION['program_tags'] = isset($_POST['program_tags']) ? array_map('intval', (array)$_POST['program_tags']) : [];
}

// --- SESSION DATA RETRIEVAL ---
// Step 2: Retrieve stored session data for pre-selecting dropdowns.
// Logged-in user always takes precedence; fall back to last session value.
$loggedInUserId = (int)$_SESSION['user_id'];
$selectedUser = $loggedInUserId > 0 ? $loggedInUserId : ($_SESSION['user'] ?? '');
$selectedTeam = $_SESSION['team'] ?? '';
$selectedForm = $_SESSION['form'] ?? '';

// --- DATABASE CONNECTION ---
// Establish connection to the MySQL database using external credentials.
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch location options for the dropdown
$locationOptions = [];
$result = $conn->query("SELECT id, name FROM locations ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $locationOptions[] = $row;
}

// Fetch active performers for optional performer selection
$performerOptions = [];
$perfResult = $conn->query("SELECT performer_id, stage_name, performer_type FROM performers WHERE status='active' ORDER BY stage_name");
if ($perfResult) {
    while ($row = $perfResult->fetch_assoc()) {
        $performerOptions[] = $row;
    }
}

// Fetch program tags for checkboxes
$tagOptions = [];
$tagResult = $conn->query("SELECT id, name FROM program_tags ORDER BY name ASC");
if ($tagResult) {
    while ($row = $tagResult->fetch_assoc()) {
        $tagOptions[] = $row;
    }
}

// --- FORM DATA PROCESSING AND DATABASE INSERTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['final_submit'])) {

  $conn->begin_transaction();
  try {
            // --- QUESTION RESPONSES PROCESSING ---
// $responses will store the *actual points* earned for each question.
// For question 4, we'll also remember which option_id was chosen so we can save the label.
$responses        = [];  // question_id => points (1,2,5,10,…)
$eventOptionIds   = [];  // question_id => option_id (for q4 label only)
$calculated_total = 0;

// Load scoring option points: id => points
// Use option_points (actual score) from scoring_options
$optionPoints = [];
$optQ = $conn->query("SELECT id, option_points AS points FROM scoring_options");
while ($r = $optQ->fetch_assoc()) {
    $optionPoints[(int)$r['id']] = (int)$r['points'];  // 10, 5, 2, 1, etc.
}


foreach ($_POST as $key => $value) {
    // Only look at fields named question_X
    if (strpos($key, 'question_') !== 0) {
        continue;
    }

    $qid    = (int)substr($key, 9);
    $rawVal = (int)$value; // usually the posted number, for q4 it's the option_id

    // Default: posted value IS the points
    $scorePoints = $rawVal;

    // If this matches a scoring_options.id, map it to its point value
    if (isset($optionPoints[$rawVal])) {
        $scorePoints = $optionPoints[$rawVal];

        // For the event-type question (id 4), remember which option was chosen
        if ($qid === 4) {
            $eventOptionIds[$qid] = $rawVal; // rawVal is the option_id
        }
    }

    // Store the actual points for all questions
    $responses[$qid] = $scorePoints;
    $calculated_total += $scorePoints;
}

      


      // Override the posted total_score with our calculated total for accuracy.
      $_POST['total_score'] = $calculated_total;

      // NEW: get selected users (multi-staff) and validate
      $selectedUsers = isset($_POST['users']) ? array_filter(array_map('intval', (array)$_POST['users'])) : [];
      if (empty($selectedUsers)) {
          throw new Exception("Please select at least one staff member.");
      }
      // Use first selected as legacy owner for scores.user_id
      $legacy_user_id = reset($selectedUsers);

      // --- MAIN SCORE RECORD INSERTION (legacy owner = first selected user) ---
      $team_id      = intval($_POST['team'] ?? 0);
      $program      = $_POST['program_name'] ?? '';
      $program_date = $_POST['program_date'] ?? '';
      $total_score  = $calculated_total;
      $location_id  = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;

      $stmt = $conn->prepare("INSERT INTO scores (user_id, team_id, program, program_date, total_score, location_id)
                              VALUES (?, ?, ?, ?, ?, ?)");
      if (!$stmt) {
          throw new Exception("Prepare error (scores): " . $conn->error);
      }
      $stmt->bind_param("iissii", $legacy_user_id, $team_id, $program, $program_date, $total_score, $location_id);
      if (!$stmt->execute()) {
          throw new Exception("Execute error (scores): " . $stmt->error);
      }
      $new_score_id = $stmt->insert_id;
      $stmt->close();

      // --- INDIVIDUAL QUESTION RESPONSE INSERTION ---
      if (!empty($responses)) {
          // --- INDIVIDUAL QUESTION RESPONSE INSERTION (patched to save response text) ---
$stmt2 = $conn->prepare("INSERT INTO score_responses (score_id, question_id, response, points) VALUES (?, ?, ?, ?)");
if (!$stmt2) {
    throw new Exception("Prepare error (score_responses): " . $conn->error);
}


/// Load event type options for question 4: option_id => label
$eventTypes = [];
$q = $conn->query("SELECT id, option_text AS text FROM scoring_options WHERE question_id = 4");
while ($row = $q->fetch_assoc()) {
    $eventTypes[(int)$row['id']] = $row['text'];  // 'One-on-one', 'Small group…', etc.
}


foreach ($responses as $question_id => $points) {
    // default: no extra text
    $responseText = "";

    // For the event-type question, look up the label based on the chosen option_id
    if ($question_id === 4 && isset($eventOptionIds[4])) {
        $selectedOptionId = (int)$eventOptionIds[4];
        if (isset($eventTypes[$selectedOptionId])) {
            $responseText = $eventTypes[$selectedOptionId];
        }
    }

    // $points is now ALWAYS the actual score (1,2,5,10,…)
    $stmt2->bind_param("iisi", $new_score_id, $question_id, $responseText, $points);

    if (!$stmt2->execute()) {
        throw new Exception("Execute error (score_responses for question {$question_id}): " . $stmt2->error);
    }
}
$stmt2->close();


      }

      // --- DYNAMIC UPDATE FOR q1..q21 COLUMNS (only if columns exist) ---
$fieldsToUpdate = [];

// Discover which q-columns exist in this database
$existingCols = [];
$colRes = $conn->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'scores'
      AND COLUMN_NAME REGEXP '^q([1-9]|1[0-9]|2[0-1])$'
");
if ($colRes) {
    while ($c = $colRes->fetch_assoc()) {
        $existingCols[$c['COLUMN_NAME']] = true;
    }
}

foreach ($responses as $question_id => $points) {
    $col = 'q' . (int)$question_id;
    if (isset($existingCols[$col])) {
        $fieldsToUpdate[$col] = (int)$points;
    }
}

if (!empty($fieldsToUpdate)) {
    $setClauses = [];
    $types = '';
    $values = [];
    foreach ($fieldsToUpdate as $column => $val) {
        $setClauses[] = "$column = ?";
        $types       .= 'i';
        $values[]     = $val;
    }
    // Append the score_id for the WHERE clause.
    $types   .= 'i';
    $values[] = $new_score_id;

    $sql = "UPDATE scores SET " . implode(", ", $setClauses) . " WHERE id = ?";
    $stmtUpdate = $conn->prepare($sql);
    if (!$stmtUpdate) {
        throw new Exception("Prepare error (q-columns): " . $conn->error);
    }
    $stmtUpdate->bind_param($types, ...$values);
    if (!$stmtUpdate->execute()) {
        throw new Exception("Execute error (q-columns): " . $stmtUpdate->error);
    }
    $stmtUpdate->close();
}
// --- end q-column update ---


      // Save custom dropdown field selections
      foreach ($_POST as $cfKey => $cfVal) {
          if (!preg_match('/^custom_field_(\d+)$/', $cfKey, $cfM)) continue;
          $cf_fid = (int)$cfM[1];
          if ($cf_fid <= 0 || $cfVal === '' || !ctype_digit((string)$cfVal)) continue;
          $cf_oid = (int)$cfVal;
          $cfSt = $conn->prepare("
              INSERT INTO score_custom_field_values (score_id, field_id, option_id)
              VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)
          ");
          if ($cfSt) {
              $cfSt->bind_param("iii", $new_score_id, $cf_fid, $cf_oid);
              $cfSt->execute();
              $cfSt->close();
          }
      }

      // --- PROGRAM TAG INSERTION ---
      $selectedTags = isset($_POST['program_tags']) ? array_map('intval', (array)$_POST['program_tags']) : [];
      if (!empty($selectedTags)) {
          $tagStmt = $conn->prepare("INSERT IGNORE INTO score_tags (score_id, tag_id) VALUES (?, ?)");
          if (!$tagStmt) {
              throw new Exception("Prepare error (score_tags): " . $conn->error);
          }
          foreach ($selectedTags as $tagId) {
              if ($tagId > 0) {
                  $tagStmt->bind_param("ii", $new_score_id, $tagId);
                  if (!$tagStmt->execute()) {
                      throw new Exception("Execute error (score_tags tag_id={$tagId}): " . $tagStmt->error);
                  }
              }
          }
          $tagStmt->close();
      }

      // All good
      $conn->commit();

      // Redirect to a list/detail page (adjust the target to your app)
header("Location: view_scores.php?notice=Score%20submitted&id={$new_score_id}");
exit;

  } catch (Throwable $e) {
      $conn->rollback();
      echo "<p style='color:red;'>Submission failed: " . htmlspecialchars($e->getMessage()) . "</p>";
  }
}


// --- PREFILL FROM PERFORMERS/VIEW.PHP "USE THIS PROGRAM" ---
$prefillFormId      = isset($_GET['prefill_form_id'])      ? (int)$_GET['prefill_form_id']      : 0;
$prefillPerformerId = isset($_GET['prefill_performer_id']) ? (int)$_GET['prefill_performer_id'] : 0;
$prefillProgramId   = isset($_GET['prefill_program_id'])   ? (int)$_GET['prefill_program_id']   : 0;
$prefillProgramName = isset($_GET['prefill_program_name']) ? trim($_GET['prefill_program_name']) : '';

if ($prefillFormId > 0 && empty($_POST)) {
    // Look up the team for this form so we can pre-select team + form
    $pfStmt = $conn->prepare("SELECT team_id FROM form_profiles WHERE id = ? AND bulk_entry = 0");
    $pfStmt->bind_param("i", $prefillFormId);
    $pfStmt->execute();
    $pfRow = $pfStmt->get_result()->fetch_assoc();
    $pfStmt->close();
    if ($pfRow) {
        $selectedTeam = $pfRow['team_id'];
        $selectedForm = $prefillFormId;
    }
    if ($prefillPerformerId > 0) {
        $_SESSION['performer_id'] = $prefillPerformerId;
    }
}

// --- DATA RETRIEVAL FOR DROPDOWNS ---
// Fetch users, teams, and (if applicable) forms to populate dropdown menus in the HTML.
$users = $conn->query("SELECT id, name FROM users ORDER BY name ASC");
$teams = $conn->query("SELECT id, name FROM teams ORDER BY name ASC");

// If a team is already selected, retrieve the associated forms.
$forms = [];
if (!empty($selectedTeam)) {
    $stmt = $conn->prepare("SELECT id, name FROM form_profiles WHERE team_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $selectedTeam);
    $stmt->execute();
    $forms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$conn->close();

// --- DEFAULT VALUES SETUP ---
// Set the default date format for form entries (e.g., current month).
$defaultDate = sprintf("%04d-%02d-01", date("Y"), date("m"));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 
    Head Section for Community Engagement Impact Score Page

    This section sets up the document metadata, title, and styling for the page.
    Key elements include:
    - Meta charset: Ensures proper text rendering.
    - Title: Defines the browser tab text.
    - External CSS libraries: 
        * Bootstrap (via CDN) for responsive design and basic styling.
        * Google Fonts (Montserrat) to maintain consistent typography.
    - Inline CSS styles:
        * #floatingScore: Styles a fixed position element to display the score.
        * body: Applies a parchment background color and uses the Montserrat font.
        * Custom button classes (.btn-custom, .btn-secondary, .btn-primary): Define specific color schemes and hover effects.
    
    This documentation helps maintain consistency and aids in troubleshooting or future enhancements.
  -->
  <meta charset="UTF-8">
  <title>Community Engagement Impact Score</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
  /* Floating Score Element - fixed to bottom-right corner */
    #floatingScore {
      position: fixed;
      bottom: 10px;
      right: 10px;
      background: #bb1b51;
      color: white;
      padding: 10px 20px;
      border-radius: 8px;
      font-size: 16px;
      font-weight: bold;
      box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.3);
    }     
/* Base styles for the body element */
      body {
  background-color: #f5f2ec; /* Parchment color background */
  font-family: 'Montserrat', Arial, sans-serif;
}
 /* Custom button styles */
.btn-custom {
  background-color: #480d3c; /* Deep Plum */
  color: #fff;
}
.btn-custom:hover {
  background-color: #bb1b51; /* Fuchsia on hover */
}
.btn-secondary {
    background-color: #480d3c; /* Deep Plum */
    color: #fff;
  }
  .btn-secondary:hover {
    background-color: #bb1b51; /* Fuchsia */
  }
  .btn-primary {
  background-color: #480d3c;
  border-color: #480d3c;
}
.btn-primary:hover {
  background-color: #bb1b51;
  border-color: #bb1b51;
}
    }
  </style>
</head>
<body>
  <!-- 
    Main Content Section:
    This section renders the primary interface for submitting the Community Engagement Impact Score.
    It includes:
      - A navigation link to return to the dashboard.
      - A header displaying the page title.
      - A form that gathers data from the user:
          * User Dropdown: Select who is submitting.
          * Team Dropdown: Choose the team, triggering dynamic filtering of available forms.
          * Form Dropdown: Select a specific form.
          * Program Fields: Displayed only for non-bulk forms to capture additional details like Program Name and Date.
          * Question Fields: Loaded dynamically via JavaScript based on the selected form.
          * Hidden Fields: Store additional data for form submission, including score calculations and routing info.
      - A floating element that displays the calculated score in real time.
  -->
<div class="container mt-4">

<!-- Navigation: Link back to the dashboard -->
  <a href="index.php" class="btn btn-secondary mb-3">⬅️ Back to Dashboard</a>

<!-- Page Title -->
  <h2>Community Engagement Impact Score</h2>

<!-- Score Submission Form -->
<form id="scoreForm" method="POST" action="program.php">
      
    <!-- 1) User Dropdown: Select the submitting user -->
    <div class="mb-3">
    <label for="users" class="form-label">Who is submitting / staff on this?</label>
<select name="users[]" id="users" class="form-select" multiple required>
  <?php
    // Pre-select: session users list if set, otherwise fall back to the logged-in user
    if (!empty($_SESSION['users'])) {
        $preselectedUsers = array_map('intval', (array)$_SESSION['users']);
    } else {
        $preselectedUsers = [$loggedInUserId];
    }
    if ($users): $users->data_seek(0); while ($u = $users->fetch_assoc()):
  ?>
    <option value="<?= $u['id'] ?>"
      <?= in_array((int)$u['id'], array_map('intval', $preselectedUsers)) ? 'selected' : '' ?>>
      <?= htmlspecialchars($u['name']) ?>
    </option>
  <?php endwhile; endif; ?>
</select>
<small class="text-muted">Tip: hold Ctrl/Cmd to select multiple.</small>
    </div>

    <!-- 2) Team Dropdown: Select the team and trigger form filtering -->
    <div class="mb-3">
      <label for="team" class="form-label">What team are you on?</label>
      <select name="team" id="team" class="form-select" onchange="filterFormsByTeam(this.value)">
        <option value="">Select your team</option>
        <?php
          // Reset pointer and iterate through teams to populate the dropdown
          if ($teams && $teams->num_rows > 0) {
              $teams->data_seek(0);
              while ($t = $teams->fetch_assoc()):
        ?>
          <option value="<?= $t['id'] ?>" <?= ($selectedTeam == $t['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['name']) ?>
          </option>
        <?php 
              endwhile;
          }
        ?>
      </select>
    </div>

    <!-- 3) Form Dropdown: Choose the form to be used -->
    <div class="mb-3">
      <label for="form" class="form-label">Select Form</label>
      <select name="form" id="form" class="form-select">
        <option value="">Select a form</option>
        <?php foreach ($forms as $f): ?>
          <option value="<?= $f['id'] ?>" <?= ($selectedForm == $f['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($f['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Program Fields (visible only for non-bulk forms) -->
    <div id="programFields" style="display:none;">
      <div class="mb-3">
        <label for="program_name" class="form-label">Program Name</label>
        <input type="text" name="program_name" id="program_name" class="form-control"
               value="<?= htmlspecialchars($_SESSION['program_name'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label for="program_date" class="form-label">Program Date</label>
        <input type="date" name="program_date" id="program_date" class="form-control"
               value="<?= htmlspecialchars($_SESSION['program_date'] ?? $defaultDate) ?>">
      </div>

      <div class="mb-3">
    <label for="location_id" class="form-label">Location</label>
    <select name="location_id" id="location_id" class="form-select" required>
    <option value="">-- Select Location --</option>
    <?php foreach ($locationOptions as $loc): ?>
        <option value="<?= $loc['id'] ?>" <?= ($_SESSION['location_id'] ?? '') == $loc['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($loc['name']) ?>
        </option>
    <?php endforeach; ?>
</select>
</div>

      <!-- Program Tags: checkboxes -->
      <?php if (!empty($tagOptions)): ?>
      <div class="mb-3">
        <label class="form-label fw-semibold">Program Tags</label>
        <div class="d-flex flex-wrap gap-3">
          <?php
          $sessionTags = $_SESSION['program_tags'] ?? [];
          foreach ($tagOptions as $tag):
              $checked = in_array((int)$tag['id'], $sessionTags) ? 'checked' : '';
          ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox"
                   name="program_tags[]"
                   id="tag_<?= $tag['id'] ?>"
                   value="<?= $tag['id'] ?>"
                   <?= $checked ?>>
            <label class="form-check-label" for="tag_<?= $tag['id'] ?>">
              <?= htmlspecialchars($tag['name']) ?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($performerOptions)): ?>
      <div class="mb-3">
        <label for="performer_id" class="form-label">Performer <span class="text-muted small">(optional — if a performer was part of this program)</span></label>
        <select name="performer_id" id="performer_id" class="form-select">
          <option value="">-- No Performer / Not Applicable --</option>
          <?php foreach ($performerOptions as $perf): ?>
            <option value="<?= (int)$perf['performer_id'] ?>"
              <?= (($prefillPerformerId > 0 ? $prefillPerformerId : ($_SESSION['performer_id'] ?? 0)) == $perf['performer_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($perf['stage_name']) ?>
              <?php if ($perf['performer_type']): ?>(<?= htmlspecialchars(ucfirst($perf['performer_type'])) ?>)<?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3" id="performerProgramDiv" style="display:none;">
        <label for="performer_program_id" class="form-label">Performer's Program <span class="text-muted small">(optional — pre-fills default scores)</span></label>
        <select name="performer_program_id" id="performer_program_id" class="form-select">
          <option value="">-- Select a program --</option>
        </select>
        <small class="text-muted">Selecting a program will auto-fill its typical scores. You can still adjust any answer.</small>
      </div>
      <?php endif; ?>

      <!-- Custom Dropdown Fields: loaded via AJAX based on team + form selection -->
      <div id="customFieldsContainer"></div>
    </div>

    <!-- Dynamic Question Fields: Loaded based on selected form -->
    <div id="questionFields"></div>

    <!-- Hidden Fields: Used to store computed scores and route data -->
    <input type="hidden" name="total_score" id="total_score" value="0">
    <input type="hidden" name="user_id" id="hidden_user_id" value="">
    <input type="hidden" name="team_id" id="hidden_team_id" value="">
    <input type="hidden" name="form_id" id="hidden_form_id" value="">
    <input type="hidden" name="location_id" id="hidden_location_id" value="">




<!-- Submit Button: Finalize the score submission -->
    <button type="submit" name="final_submit" class="btn btn-primary mt-3">
      Submit Score
    </button>
  </form>
</div>

<!-- Floating Score Display: Shows the current score dynamically updated by JavaScript -->
<div id="floatingScore">Score: <span id="scoreFloating">0</span></div>

<script>
const prefillScoreId       = <?= (int)($_SESSION['prefill_score_id'] ?? 0) ?>;
const prefillPerformerId   = <?= $prefillPerformerId ?>;
const prefillProgramId     = <?= $prefillProgramId ?>;
const prefillProgramName   = <?= json_encode($prefillProgramName) ?>;
/**
   * JavaScript for the Community Engagement Impact Score page.
   *
   * This script handles dynamic behavior for the score submission form:
   * - Automatically triggering change events on page load.
   * - Fetching available forms when a team is selected.
   * - Determining whether the selected form is for bulk or non-bulk entries.
   * - Dynamically loading question fields for the selected form.
   * - Real-time score calculation based on user input.
   * - Validating required fields before form submission.
   */


// On page load, trigger the 'change' event if a form is preselected.
document.addEventListener("DOMContentLoaded", function() {
  const formSelect = document.getElementById("form");
  const locationSelect = document.getElementById("location_id");
  if (locationSelect) {
    locationSelect.addEventListener("change", function() {
      document.getElementById("hidden_location_id").value = this.value;
    });
    document.getElementById("hidden_location_id").value = locationSelect.value;
  }

  // Prefill from "Use this program" link
  if (prefillPerformerId > 0) {
    activePerformerProgramId = prefillProgramId; // set before questions load so defaults apply
  }

  if (formSelect.value) {
    formSelect.dispatchEvent(new Event("change"));
  }

  // After form questions load, trigger performer → program cascade
  if (prefillPerformerId > 0) {
    const perfSel = document.getElementById('performer_id');
    if (perfSel && prefillPerformerId) {
      // Programs are fetched via AJAX; wait for it then auto-select the program
      fetch('performers/ajax_programs.php?performer_id=' + prefillPerformerId)
        .then(r => r.json())
        .then(programs => {
          const progDiv = document.getElementById('performerProgramDiv');
          const progSel = document.getElementById('performer_program_id');
          if (!progSel || !progDiv || programs.length === 0) return;
          programs.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.program_id;
            opt.textContent = p.program_title;
            if (p.program_id == prefillProgramId) opt.selected = true;
            progSel.appendChild(opt);
          });
          progDiv.style.display = 'block';
          // Set program name
          if (prefillProgramName) {
            const pnField = document.getElementById('program_name');
            if (pnField) pnField.value = prefillProgramName;
          }
        })
        .catch(console.error);
    }
  }
});

/**
   * Fetches and updates the forms dropdown based on the selected team.
   *
   * @param {string} teamId - The ID of the selected team.
   */
function filterFormsByTeam(teamId) {
  if (!teamId) {
      // If no team is selected, reset the form dropdown and clear custom fields.
    document.getElementById("form").innerHTML = "<option value=''>Select a form</option>";
    const cf = document.getElementById("customFieldsContainer");
    if (cf) cf.innerHTML = "";
    return;
  }
  fetch("get_forms.php?team_id=" + encodeURIComponent(teamId))
    .then(resp => resp.json())
    .then(forms => {
      const formSelect = document.getElementById("form");
      formSelect.innerHTML = "<option value=''>Select a form</option>";
      forms.forEach(f => {
        let opt = document.createElement("option");
        opt.value = f.id;
        opt.textContent = f.name;
        formSelect.appendChild(opt);
      });
    })
    .catch(err => console.error("Error fetching forms:", err));
}

  /**
   * Handles changes in the form dropdown.
   * Determines if the selected form is for bulk entry and sets the form action accordingly.
   * Also triggers the loading of question fields.
   */
  document.getElementById("form").addEventListener("change", function() {
  const selectedFormId = this.value;
  const usersSel = document.getElementById("users");
  const team = document.getElementById("team").value;

  // first selected option (if any) as legacy owner
  let legacyUser = "";
  if (usersSel && usersSel.selectedOptions.length > 0) {
    legacyUser = usersSel.selectedOptions[0].value;
  }

  if (!selectedFormId) {
    // If no form is selected, hide program fields and clear question fields.
    document.getElementById("programFields").style.display = "none";
    document.getElementById("questionFields").innerHTML = "";
    return;
  }

// Fetch additional form details to check for bulk entry.
fetch("get_form_details.php?form_id=" + encodeURIComponent(selectedFormId))
  .then(resp => resp.json())
  .then(data => {
    // figure out current directory path
    const sameDir = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    const formEl  = document.getElementById("scoreForm");

    // Helper: sync users[] into hidden inputs so the target endpoint always receives them
    (function syncHiddenUsers() {
      // remove any stale hidden users[]
      Array.from(formEl.querySelectorAll('input[name="users[]"][type="hidden"]')).forEach(el => el.remove());
      // add current selections
      Array.from(usersSel.selectedOptions).forEach(opt => {
        const h = document.createElement("input");
        h.type  = "hidden";
        h.name  = "users[]";
        h.value = opt.value;
        formEl.appendChild(h);
      });
    })();

    // Always keep these hidden fields current
    document.getElementById("hidden_user_id").value = legacyUser;     // legacy owner
    document.getElementById("hidden_team_id").value = team;
    document.getElementById("hidden_form_id").value = selectedFormId;
    document.getElementById("hidden_location_id").value = (document.getElementById("location_id")?.value || "");

    if (data.bulk_entry == 1) {
      // Bulk path
      formEl.action = sameDir + "one_on_one_specialty.php";
    } else {
      // Non-bulk path → ensure it goes to program.php so score_users gets written
      formEl.action = sameDir + "program.php";
    }

    // Display the program fields and load the corresponding questions.
    document.getElementById("programFields").style.display = "block";
    loadQuestionFields(selectedFormId);
    loadCustomFields(team, selectedFormId);
  })
  .catch(err => console.error("Error fetching form details:", err));

});

/**
 * Fetches applicable custom dropdown fields for the selected team + form,
 * then renders them inside #customFieldsContainer.
 */
function loadCustomFields(teamId, formId) {
  const container = document.getElementById("customFieldsContainer");
  if (!container) return;
  container.innerHTML = "";

  const params = [];
  if (teamId) params.push("team_id=" + encodeURIComponent(teamId));
  if (formId) params.push("form_id=" + encodeURIComponent(formId));
  if (!params.length) return;

  fetch("get_custom_fields.php?" + params.join("&"))
    .then(r => r.json())
    .then(fields => {
      if (!fields || fields.length === 0) return;
      fields.forEach(field => {
        const wrapper = document.createElement("div");
        wrapper.className = "mb-3";

        const lbl = document.createElement("label");
        lbl.className = "form-label";
        lbl.htmlFor = "cf_" + field.field_id;
        lbl.textContent = field.field_label;
        if (field.is_required) {
          const star = document.createElement("span");
          star.className = "text-danger ms-1";
          star.textContent = "*";
          lbl.appendChild(star);
        }
        wrapper.appendChild(lbl);

        const sel = document.createElement("select");
        sel.name = "custom_field_" + field.field_id;
        sel.id = "cf_" + field.field_id;
        sel.className = "form-select";
        if (field.is_required) sel.required = true;

        const blank = document.createElement("option");
        blank.value = "";
        blank.textContent = field.description ? "-- " + field.description + " --" : "-- Select --";
        sel.appendChild(blank);

        field.options.forEach(opt => {
          const o = document.createElement("option");
          o.value = opt.option_id;
          o.textContent = opt.option_label;
          sel.appendChild(o);
        });

        wrapper.appendChild(sel);
        container.appendChild(wrapper);
      });
    })
    .catch(err => console.error("Error fetching custom fields:", err));
}


/**
   * Attaches change event listeners to all score input elements.
   * When a score input changes, the total score is recalculated.
   */
function attachScoreListeners() {
  document.querySelectorAll(".score-input").forEach(inp => {
    inp.addEventListener("change", updateScore);
  });
}

// Pre-fill the program name based on the selected form option,
// but only if the field is currently empty (session may have already set it).
document.getElementById("form").addEventListener("change", function() {
  const pnField = document.getElementById("program_name");
  if (!pnField.value.trim()) {
    pnField.value = this.options[this.selectedIndex].text;
  }
});

/**
   * Recalculates and updates the total score based on the selected radio inputs.
   */
  function updateScore() {
  let total = 0;

  document.querySelectorAll(".score-input:checked").forEach(inp => {
    // Prefer data-points (used by multiple_choice / event types)
    let pts = inp.dataset.points;

    // Fallback: use the input's value (used by binary Yes/No questions)
    if (pts === undefined || pts === "") {
      pts = inp.value;
    }

    const n = parseInt(pts, 10);
    if (!isNaN(n)) {
      total += n;
    }
  });

  document.getElementById("total_score").value = total;
  document.getElementById("scoreFloating").textContent = total;
}


  /**
   * Loads question fields for the selected form.
   * Dynamically creates HTML for each question based on its type (binary or multiple choice).
   *
   * @param {string} formId - The ID of the selected form.
   */
function loadQuestionFields(formId) {
  const prefillParam = prefillScoreId > 0 ? "&prefill_score_id=" + prefillScoreId : "";
  fetch("get_forms.php?form_id=" + encodeURIComponent(formId) + prefillParam)
    .then(response => response.json())
    .then(data => {
      const questionFieldsDiv = document.getElementById("questionFields");
      questionFieldsDiv.innerHTML = "";
      if (!data.questions || data.questions.length === 0) {
        questionFieldsDiv.innerHTML = "<p>No questions found.</p>";
        return;
      }
      let html = "";
      data.questions.forEach(question => {
        html += `<div class="mb-3">
                   <label class="form-label">${question.question_text} (${question.points} pts)</label>`;
                   if (question.question_type === "binary") {
  const yesChecked = (question.prefill_value == question.points) ? "checked" : "";
  const noChecked  = (question.prefill_value == "0" || question.prefill_value == 0) ? "checked" : "";

  html += `
    <div class="form-check">
      <input class="form-check-input score-input" type="radio"
             name="question_${question.question_id}"
             value="${question.points}"
             data-points="${question.points}"
             ${yesChecked}>
      <label class="form-check-label">Yes (${question.points})</label>
    </div>
    <div class="form-check">
      <input class="form-check-input score-input" type="radio"
             name="question_${question.question_id}"
             value="0"
             data-points="0"
             ${noChecked}>
      <label class="form-check-label">No</label>
    </div>`;

  } else if (question.question_type === "multiple_choice") {

question.options.forEach(opt => {
  // The form now stores the OPTION ID in the value…
  const optionId = opt.option_id;
  // …and the score lives here:
  const points   = opt.points;

  // PREFILL LOGIC:
  // Admin currently saves the *points* (e.g., 10) as prefill_value.
  // But just in case we ever switch to saving option_id, we check both.
  const isChecked =
    String(question.prefill_value) === String(points) ||
    String(question.prefill_value) === String(optionId)
      ? "checked"
      : "";

  html += `
    <div class="form-check">
      <input class="form-check-input score-input event-option"
             type="radio"
             name="question_${question.question_id}"
             value="${optionId}"
             data-points="${points}"
             ${isChecked}>
      <label class="form-check-label">${opt.text} (${points})</label>
    </div>`;
});

}


        html += `</div>`;
      });
      questionFieldsDiv.innerHTML = html;
      // Attach event listeners to the newly created score inputs.
      attachScoreListeners();
      // Recalculate the total score.
      updateScore();
      // Apply performer program defaults if one is selected
      if (activePerformerProgramId > 0) {
        applyProgramDefaults(activePerformerProgramId);
      }
    })
    .catch(error => console.error("Error fetching questions:", error));
}

 /**
   * Validates required fields before form submission.
   * When the form action is "program.php", it ensures that both program name and program date are provided.
   */
  document.getElementById("scoreForm").addEventListener("submit", function(e) {
  const act = this.action || window.location.pathname; // treat "" as same page
  // require program fields for any non-bulk submit
  if (act.indexOf("one_on_one_specialty.php") === -1) {
    const pName = document.getElementById("program_name").value.trim();
    const pDate = document.getElementById("program_date").value.trim();
    if (!pName || !pDate) {
      alert("Please enter both Program Name and Program Date.");
      e.preventDefault();
      return false;
    }
  }
});

function syncHiddenUsers(formEl, usersSel) {
  // remove any stale hidden users[]
  Array.from(formEl.querySelectorAll('input[name="users[]"][type="hidden"]')).forEach(el => el.remove());
  // add current selections
  Array.from(usersSel.selectedOptions).forEach(opt => {
    const h = document.createElement("input");
    h.type = "hidden";
    h.name = "users[]";
    h.value = opt.value;
    formEl.appendChild(h);
  });
}

// ---- Performer → Program dropdown ----
let activePerformerProgramId = 0;

const performerSel = document.getElementById('performer_id');
if (performerSel) {
  performerSel.addEventListener('change', function () {
    const perfId = this.value;
    const progDiv = document.getElementById('performerProgramDiv');
    const progSel = document.getElementById('performer_program_id');

    // Reset program selection
    activePerformerProgramId = 0;
    if (progSel) progSel.innerHTML = '<option value="">-- Select a program --</option>';

    if (!perfId) {
      if (progDiv) progDiv.style.display = 'none';
      return;
    }

    fetch('performers/ajax_programs.php?performer_id=' + encodeURIComponent(perfId))
      .then(r => r.json())
      .then(programs => {
        if (!progSel || !progDiv) return;
        if (programs.length === 0) {
          progDiv.style.display = 'none';
          return;
        }
        programs.forEach(p => {
          const opt = document.createElement('option');
          opt.value = p.program_id;
          opt.textContent = p.program_title;
          progSel.appendChild(opt);
        });
        progDiv.style.display = 'block';
        // Auto-select prefill program if present
        if (activePerformerProgramId > 0) {
          progSel.value = activePerformerProgramId;
          if (prefillProgramName) {
            const pnField = document.getElementById('program_name');
            if (pnField) pnField.value = prefillProgramName;
          }
        }
      })
      .catch(console.error);
  });
}

const performerProgSel = document.getElementById('performer_program_id');
if (performerProgSel) {
  performerProgSel.addEventListener('change', function () {
    const progId = parseInt(this.value, 10) || 0;
    activePerformerProgramId = progId;

    // Auto-fill program name from selected option text
    const pnField = document.getElementById('program_name');
    if (pnField && progId > 0) {
      const selText = this.options[this.selectedIndex].textContent.trim();
      if (selText) pnField.value = selText;
    }

    // Apply defaults if questions are already rendered
    if (progId > 0 && document.querySelectorAll('.score-input').length > 0) {
      applyProgramDefaults(progId);
    }
  });
}

function applyProgramDefaults(programId) {
  if (!programId) return;
  const formProfileId = document.getElementById('form')?.value || 0;
  fetch('performers/ajax_program_defaults.php?program_id=' + encodeURIComponent(programId) + '&form_profile_id=' + encodeURIComponent(formProfileId))
    .then(r => r.json())
    .then(defaults => {
      // defaults is { question_id: response_value }
      Object.entries(defaults).forEach(([qid, val]) => {
        // Try radio buttons first (binary and multiple_choice)
        const radios = document.querySelectorAll('input[name="question_' + qid + '"]');
        if (radios.length > 0) {
          radios.forEach(radio => {
            // Match by value OR by data-points
            if (radio.value == val || radio.dataset.points == val) {
              radio.checked = true;
            }
          });
        }
      });
      updateScore();
    })
    .catch(console.error);
}


</script>

</body>
</html>
