<?php

/**
 * ⚙️ Admin Panel - Manage Scoring Questions
 *
 * This script handles administrative tasks for managing scoring questions in the Value Score system.
 * It enables error reporting, connects to the MySQL database, and processes various actions including:
 *   - Reordering questions by swapping order values.
 *   - Adding new questions.
 *   - Adding options to multiple-choice questions.
 *   - Updating existing questions.
 *   - Deleting questions and their associated responses or options.
 *
 * Debugging information is logged to the error log for transparency during development.
 *
 * Prerequisites:
 *   - A valid MySQL database connection.
 *   - Database tables: scoring_questions, scoring_options, score_responses.
 *
 * @package AdminPanel
 * @version 1.0
 */

// Require admin login
 session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}
 
// --- ERROR REPORTING SETUP ---
// Enable full error reporting for debugging purposes.
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- DATABASE CONNECTION ---
// Establish connection to the MySQL database using external credentials.
require_once('secure/db_connection.php');

// Connect to MySQL using MySQLi.
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// Question ordering is now managed per-form in admin_forms.php.

// --- HANDLE ADDING A NEW QUESTION ---
// Process the form submission for adding a new question.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_question'])) {
    $question = $_POST['question'];
    $points = $_POST['points'];
    $type = $_POST['type'];
// Validate inputs: ensure question text is provided and points is numeric.
    if (!empty($question) && is_numeric($points)) {
        $max_order = $conn->query("SELECT MAX(question_order) AS max_order FROM scoring_questions")->fetch_assoc()['max_order'] ?? 0;
        $new_order = $max_order + 1;
// Insert the new question into the database.
        $stmt = $conn->prepare("INSERT INTO scoring_questions (question_text, points, type, question_order) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sisi", $question, $points, $type, $new_order);
        $stmt->execute();
        $stmt->close();
    }
}

// --- HANDLE ADDING AN OPTION ---
// Process adding a new option for a multiple-choice question.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_option'])) {
    error_log("🚀 Option form submitted: " . print_r($_POST, true));

    $question_id = $_POST['question_id'];
    $option_text = trim($_POST['option_text']);
    $option_points = trim($_POST['option_points']);
// Validate inputs: option text must not be empty, option points must be numeric.
    if (!empty($option_text) && is_numeric($option_points) && !empty($question_id)) {
        $stmt = $conn->prepare("INSERT INTO scoring_options (question_id, option_text, option_points) VALUES (?, ?, ?)");
        $stmt->bind_param("isi", $question_id, $option_text, $option_points);

        if ($stmt->execute()) {
            error_log("✅ Option added successfully: " . print_r($_POST, true));
        } else {
            error_log("❌ SQL ERROR: " . $stmt->error);
        }

        $stmt->close();
    } else {
        error_log("⚠️ Invalid option input: " . print_r($_POST, true));
    }
}

// --- FETCH CURRENT QUESTIONS ---
// Retrieve all scoring questions ordered by the defined question_order.
$result = $conn->query("SELECT * FROM scoring_questions ORDER BY type ASC, question_text ASC");


// --- FETCH MULTIPLE-CHOICE OPTIONS ---
// Build an associative array mapping each question's ID to its options.
$options = [];
$options_result = $conn->query("SELECT * FROM scoring_options");
while ($row = $options_result->fetch_assoc()) {
    $options[$row['question_id']][] = $row;
}

// --- HANDLE DELETING A QUESTION ---
// If a delete request for a question is received, first remove its related responses.
if (isset($_GET['delete_question'])) {
    $delete_id = intval($_GET['delete_question']);
    error_log("🚀 Attempting to delete question ID: " . $delete_id);

    // Step 1: Delete all responses associated with the question.
    $stmt = $conn->prepare("DELETE FROM score_responses WHERE question_id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        error_log("✅ Related responses deleted successfully for question ID: " . $delete_id);
    } else {
        error_log("❌ Failed to delete related responses: " . $stmt->error);
    }
    $stmt->close();

    // Step 2: Delete the question itself.
    $stmt = $conn->prepare("DELETE FROM scoring_questions WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        error_log("✅ Question deleted successfully: " . $delete_id);
    } else {
        error_log("❌ Deletion failed: " . $stmt->error);
    }
    $stmt->close();

   // Redirect to refresh the admin panel.
    header("Location: admin_panel.php");
    exit();
}

// --- HANDLE DELETING AN OPTION ---
// Process deletion of an option if requested.
if (isset($_GET['delete_option'])) {
    $delete_id = intval($_GET['delete_option']);
    error_log("🚀 Attempting to delete option ID: " . $delete_id);

    // Prepare and execute deletion of the option.
    $stmt = $conn->prepare("DELETE FROM scoring_options WHERE id = ?");
    $stmt->bind_param("i", $delete_id);

    if ($stmt->execute()) {
        error_log("✅ Option deleted successfully: " . $delete_id);
    } else {
        error_log("❌ Option deletion failed: " . $stmt->error);
    }

    $stmt->close();
    // Redirect to refresh the page.
    header("Location: admin_panel.php"); // Refresh
    exit();
}

// --- HANDLE UPDATING A QUESTION ---
// Process updating an existing question when the update form is submitted.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_question'])) {
    error_log("🚀 Updating question: " . print_r($_POST, true));

    $id = intval($_POST['id']);
    $question_text = trim($_POST['question']);
    $points = intval($_POST['points']);
    $type = $_POST['type'];

// Validate inputs.
    if (!empty($question_text) && is_numeric($points) && !empty($id)) {
        $stmt = $conn->prepare("UPDATE scoring_questions SET question_text = ?, points = ?, type = ? WHERE id = ?");
        $stmt->bind_param("sisi", $question_text, $points, $type, $id);

        if ($stmt->execute()) {
            error_log("✅ Question updated successfully: " . $id);
        } else {
            error_log("❌ Error updating question: " . $stmt->error);
        }

        $stmt->close();
    } else {
        error_log("⚠️ Invalid input for question update.");
    }

    // Redirect to refresh the admin panel and display updates.
    header("Location: admin_panel.php");
    exit();
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚙️ Admin Panel - Manage Scoring Questions</title>
    <style>
    /* Basic styling for the admin panel */
body { 
    font-family: 'Montserrat', Arial, sans-serif; 
    text-align: center; 
    background: #f5f2ec; /* Parchment */
    padding: 20px; 
}
.container { 
    max-width: 900px; 
    margin: auto; 
    background: white; 
    padding: 20px; 
    border-radius: 8px; 
    box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1); 
}
table { 
    width: 100%; 
    border-collapse: collapse; 
    margin: 20px 0; 
}
th, td { 
    padding: 10px; 
    border: 1px solid #ddd; 
    text-align: left; 
}
th { 
    background: #480d3c; /* Deep Plum for consistency */
    color: white; 
}
.btn { 
    padding: 8px 12px; 
    text-decoration: none; 
    border-radius: 5px; 
}
.save-btn { 
    background: #480d3c; /* Deep Plum */
    color: white; 
    border: none; 
    cursor: pointer; 
}
.save-btn:hover {
    background: #bb1b51; /* Fuchsia */
}
.move-btn { 
    font-size: 16px; 
    background: none; 
    border: none; 
    cursor: pointer; 
    color: #480d3c; /* Deep Plum text for consistency */
}
input, select { 
    width: 100%; 
    padding: 8px; 
    margin: 5px; 
    border: 1px solid #ddd; 
}
.btn {
    display: inline-block;
    font-weight: 500;
    padding: 8px 16px;
    text-decoration: none;
    border-radius: 5px;
    transition: background-color 0.2s ease;
}

.btn-secondary {
    background-color: #480d3c; /* Deep Plum */
    color: white;
    border: none;
}

.btn-secondary:hover {
    background-color: #bb1b51;
    color: white;
}

/* ── Options section ── */
.options-section {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid #eee;
}
.options-section > strong {
    display: block;
    margin-bottom: 6px;
}
.option-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
}
.option-row input.option-text {
    flex: 1;
    width: auto !important;
    margin: 0;
}
.option-row input.option-points {
    width: 70px !important;
    margin: 0;
}
.option-row button,
.option-row a {
    white-space: nowrap;
    flex-shrink: 0;
}
.add-option-row {
    margin-top: 6px;
    padding-top: 8px;
    border-top: 1px dashed #ddd;
}
    </style>
</head>
<body>
<!-- Back to Admin Panel Button -->
<div style="margin-bottom: 20px; text-align: left;">
    <a href="index.php" class="btn btn-secondary">⬅️ Back to Dashboard</a>
</div>

<div class="container">
    <h2>⚙️ Manage Scoring Questions</h2>

    <form method="POST" action="admin_panel.php">
        <label><strong>Question:</strong></label>
        <input type="text" name="question" placeholder="Enter new question" required>
        <label><strong>Question Type:</strong></label>
        <select name="type" onchange="togglePoints(this)">
            <option value="yesno">Yes/No</option>
            <option value="multiple_choice">Multiple Choice</option>
        </select>
        <div class="points-wrapper">
            <label><strong>Points</strong> <span class="points-hint" style="font-weight:normal;color:#666;">(Yes = this value, No = 0)</span></label>
            <input type="number" name="points" placeholder="Points" value="0">
        </div>
        <button type="submit" name="add_question" class="save-btn">➕ Add Question</button>
    </form>

    <table>
        <tr>
            <th>#</th>
            <th>Question</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= $row['id'] ?></td>
            <td>
                <form method="POST" action="admin_panel.php">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <label><strong>Question:</strong></label>
                    <input type="text" name="question" value="<?= htmlspecialchars($row['question_text']) ?>" required>
                    <label><strong>Question Type:</strong></label>
                    <select name="type" onchange="togglePoints(this)">
                        <option value="yesno" <?= $row['type'] == 'yesno' ? 'selected' : '' ?>>Yes/No</option>
                        <option value="multiple_choice" <?= $row['type'] == 'multiple_choice' ? 'selected' : '' ?>>Multiple Choice</option>
                    </select>
                    <div class="points-wrapper" <?= $row['type'] === 'multiple_choice' ? 'style="display:none"' : '' ?>>
                        <label><strong>Points</strong> <span style="font-weight:normal;color:#666;">(Yes = this value, No = 0)</span></label>
                        <input type="number" name="points" value="<?= $row['points'] ?>">
                    </div>
                    <button type="submit" name="update_question" class="save-btn">💾 Save</button>
                    <a href="?delete_question=<?= $row['id'] ?>" class="btn delete-btn"
                       onclick="return confirm('Delete this question and all its responses?');">🗑 Delete</a>
                </form>

                <?php if ($row['type'] == 'multiple_choice'): ?>
                <div class="options-section">
                    <strong>Options:</strong>

                    <?php if (!empty($options[$row['id']])): ?>
                        <?php foreach ($options[$row['id']] as $option): ?>
                        <form method="POST" class="option-row">
                            <input type="hidden" name="option_id"     value="<?= $option['id'] ?>">
                            <input type="hidden" name="question_id"   value="<?= $row['id'] ?>">
                            <input type="text"   name="option_text"   class="option-text"
                                   value="<?= htmlspecialchars($option['option_text']) ?>" required>
                            <input type="number" name="option_points" class="option-points"
                                   value="<?= $option['option_points'] ?>" required>
                            <button type="submit" name="update_option" class="save-btn">Save</button>
                            <a href="?delete_option=<?= $option['id'] ?>" class="btn delete-btn"
                               onclick="return confirm('Delete this option?');">Delete</a>
                        </form>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:#888; font-style:italic; margin:4px 0;">No options yet.</p>
                    <?php endif; ?>

                    <form method="POST" class="option-row add-option-row">
                        <input type="hidden" name="question_id" value="<?= $row['id'] ?>">
                        <input type="text"   name="option_text"   class="option-text"   placeholder="New option" required>
                        <input type="number" name="option_points" class="option-points" placeholder="Pts" required>
                        <button type="submit" name="add_option" class="save-btn">+ Add</button>
                    </form>
                </div>
                <?php endif; ?>

            </td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>


<script>
function togglePoints(select) {
    const wrapper = select.closest('form').querySelector('.points-wrapper');
    if (!wrapper) return;
    const isMC = select.value === 'multiple_choice';
    wrapper.style.display = isMC ? 'none' : '';
    // Keep value at 0 for MC so PHP validation passes on save
    if (isMC) wrapper.querySelector('input[name="points"]').value = '0';
}

// Apply on load so existing MC questions already have the field hidden
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('select[name="type"]').forEach(togglePoints);
});
</script>
</body>
</html>

<?php $conn->close(); ?>


