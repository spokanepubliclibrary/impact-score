<?php
/*******************************************************
 * admin_forms.php
 * 
 * A complete file to:
 *  1) Pick a team
 *  2) Show forms for that team
 *  3) Add/Edit/Delete forms
 *  4) Assign questions to a chosen form
 *******************************************************/
// Require admin login
 session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// DB credentials
require_once('secure/db_connection.php');


// 1) Capture team_id from GET or POST for filtering
$selected_team_id = $_GET['team_id'] ?? $_POST['team_id'] ?? '';

// 2) Process form actions (add/edit/delete/assign)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'add_form' && !empty($_POST['form_name']) && !empty($_POST['team_id'])) {
            $form_name  = $_POST['form_name'];
            $team_id    = (int)$_POST['team_id'];
            $bulk_entry = isset($_POST['bulk_entry']) ? 1 : 0;

            $stmt = $conn->prepare("
                INSERT INTO form_profiles (name, team_id, bulk_entry)
                VALUES (?, ?, ?)
            ");
            if (!$stmt) {
                die("Add Form Prepare Failed: " . $conn->error);
            }
            $stmt->bind_param("sii", $form_name, $team_id, $bulk_entry);
            $stmt->execute();
            $stmt->close();

            // Redirect so the page reloads with the chosen team
            header("Location: admin_forms.php?team_id=" . urlencode($team_id));
            exit();
        }
        elseif ($action === 'edit_form' && !empty($_POST['form_id'])) {
            $form_id   = (int)$_POST['form_id'];
            $form_name = $_POST['form_name'];
            $team_id   = (int)$_POST['team_id'];
            $bulk_entry= isset($_POST['bulk_entry']) ? 1 : 0;

            $stmt = $conn->prepare("
                UPDATE form_profiles
                   SET name = ?, team_id = ?, bulk_entry = ?
                 WHERE id = ?
            ");
            if (!$stmt) {
                die("Edit Form Prepare Failed: " . $conn->error);
            }
            $stmt->bind_param("siii", $form_name, $team_id, $bulk_entry, $form_id);
            $stmt->execute();
            $stmt->close();

            header("Location: admin_forms.php?team_id=" . urlencode($team_id));
            exit();
        }
        elseif ($action === 'delete_form' && !empty($_POST['form_id'])) {
            $form_id = (int)$_POST['form_id'];

            $stmt = $conn->prepare("DELETE FROM form_profiles WHERE id = ?");
            if (!$stmt) {
                die("Delete Form Prepare Failed: " . $conn->error);
            }
            $stmt->bind_param("i", $form_id);
            $stmt->execute();
            $stmt->close();

            // Stay on the same team filter
            header("Location: admin_forms.php?team_id=" . urlencode($selected_team_id));
            exit();
        }
        elseif ($action === 'assign_questions' && !empty($_POST['form_id'])) {
            $form_id = (int)$_POST['form_id'];

            // Wrap everything in a transaction so we don't end up half-saved
            $conn->begin_transaction();

            try {
                // 1) Clear existing assignments safely
                $delFQ = $conn->prepare("DELETE FROM form_questions WHERE form_id = ?");
                $delFQ->bind_param("i", $form_id);
                $delFQ->execute();
                $delFQ->close();

                $delFP = $conn->prepare("DELETE FROM form_prefill_values WHERE form_id = ?");
                $delFP->bind_param("i", $form_id);
                $delFP->execute();
                $delFP->close();

                // 2) Insert new ones
                if (!empty($_POST['question_ids'])) {
                    $insertFQ = $conn->prepare("
                        INSERT INTO form_questions (form_id, question_id, sort_order)
                        VALUES (?, ?, ?)
                    ");
                    $insertFP = $conn->prepare("
                        INSERT INTO form_prefill_values (form_id, question_id, prefill_value)
                        VALUES (?, ?, ?)
                    ");

                    foreach ($_POST['question_ids'] as $qID) {
                        $qID = (int)$qID;
                        $sort_order = isset($_POST['sort_orders'][$qID]) ? (int)$_POST['sort_orders'][$qID] : 9999;

                        // Insert into form_questions with sort_order
                        $insertFQ->bind_param("iii", $form_id, $qID, $sort_order);
                        $insertFQ->execute();

                        // Only insert a prefill if something was actually entered
                        if (isset($_POST['prefill_values'][$qID])) {
                            $prefill_val = trim($_POST['prefill_values'][$qID]);

                            // Allow "0" as a valid prefill (so we check for empty string, not empty())
                            if ($prefill_val !== '') {
                                $insertFP->bind_param("iis", $form_id, $qID, $prefill_val);
                                $insertFP->execute();
                            }
                        }
                    }

                    $insertFQ->close();
                    $insertFP->close();
                }

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                die("Error saving question assignments: " . $e->getMessage());
            }

            header(
                "Location: admin_forms.php?team_id=" . urlencode($selected_team_id)
                . "&form_id=$form_id&assigned=1"
            );
            exit();
        }

    }
}

// 3) Fetch all teams for the top-level dropdown
$all_teams = $conn->query("SELECT id, name FROM teams ORDER BY name ASC");

// 4) If a team is selected, fetch only that team's forms
$forms = [];
if (!empty($selected_team_id)) {
    $stmt = $conn->prepare("
        SELECT f.id, f.name AS form_name, f.team_id, f.bulk_entry, t.name AS team_name
          FROM form_profiles f
          JOIN teams t ON f.team_id = t.id
         WHERE f.team_id = ?
         ORDER BY f.name ASC
    ");
    $stmt->bind_param("i", $selected_team_id);
    $stmt->execute();
    $forms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// We'll also need to keep $conn open for the "Assign Questions" part, so do not close yet.
// $conn->close(); // We'll do this at the end.
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Forms</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body {
        background-color: #f5f2ec; /* Parchment */
        font-family: 'Montserrat', Arial, sans-serif;
    }
    .container {
        margin-top: 50px;
    }
    .card {
        transition: transform 0.2s;
    }
    .card:hover {
        transform: scale(1.05);
    }
    /* Custom button styling for consistency */
    .btn-custom {
        background-color: #480d3c; /* Deep Plum */
        color: #fff;
        border: none;
    }
    .btn-custom:hover {
        background-color: #bb1b51; /* Fuchsia */
        color: #fff;
    }
    /* Secondary buttons: override Bootstrap default for consistent color */
    .btn-secondary {
        background-color: #480d3c; /* Deep Plum */
        color: #fff;
        border: none;
    }
    .btn-secondary:hover {
        background-color: #bb1b51; /* Fuchsia */
        color: #fff;
    }
    /* Optional: Consistent styling for other buttons if desired */
    .btn-primary {
        background-color: #480d3c;
        border-color: #480d3c;
    }
    .btn-primary:hover {
        background-color: #bb1b51;
        border-color: #bb1b51;
    }
    .btn-success {
        background-color: #480d3c;
        border-color: #480d3c;
    }
    .btn-success:hover {
        background-color: #bb1b51;
        border-color: #bb1b51;
    }
    .btn-info {
        background-color: #480d3c;
        border-color: #480d3c;
    }
    .btn-info:hover {
        background-color: #bb1b51;
        border-color: #bb1b51;
    }
    .btn-danger {
        background-color: #480d3c;
        border-color: #480d3c;
    }
    .btn-danger:hover {
        background-color: #bb1b51;
        border-color: #bb1b51;
    }
    
  .btn-info {
      background-color: #480d3c;
      border-color: #480d3c;
      color: #fff; /* Set text to white */
  }
  .btn-info:hover {
      background-color: #bb1b51;
      border-color: #bb1b51;
      color: #fff; /* Keep text white on hover */
  }
</style>
</head>
<body class="p-3">

<a href="index.php" class="btn btn-secondary mb-3">⬅️ Back to Dashboard</a>
<h2>Manage Forms</h2>

<!-- 1) Team Filter at top -->
<form method="GET" class="d-flex gap-2 align-items-center mb-4">
    <label for="team_id" class="form-label">Filter by Team:</label>
    <select name="team_id" id="team_id" class="form-select" onchange="this.form.submit()">
        <option value="">-- Select a Team --</option>
        <?php
        if ($all_teams && $all_teams->num_rows > 0) {
            $all_teams->data_seek(0);
            while ($t = $all_teams->fetch_assoc()):
        ?>
            <option value="<?= $t['id'] ?>" <?= ($selected_team_id == $t['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['name']) ?>
            </option>
        <?php endwhile; } ?>
    </select>
    <button type="submit" class="btn btn-primary">Go</button>
</form>

<!-- 2) Create New Form (only if a team is selected) -->
<?php if (!empty($selected_team_id)): ?>
<form method="POST" class="d-flex gap-2 mb-4 align-items-center">
    <input type="hidden" name="action" value="add_form">
    <input type="hidden" name="team_id" value="<?= htmlspecialchars($selected_team_id) ?>">

    <input type="text" name="form_name" class="form-control" placeholder="Form Name" required>

    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="bulk_entry" id="bulk_entry_add" value="1">
        <label class="form-check-label" for="bulk_entry_add">
            Bulk Entry?
        </label>
    </div>

    <button type="submit" class="btn btn-success">Add Form</button>
</form>
<?php else: ?>
<p><em>Select a team above to manage forms.</em></p>
<?php endif; ?>

<!-- 3) Existing Forms for the chosen team -->
<?php if (!empty($selected_team_id) && !empty($forms)): ?>
<h4>Existing Forms for Team ID <?= htmlspecialchars($selected_team_id) ?></h4>
<table class="table table-bordered align-middle">
    <thead>
        <tr>
            <th>Form Name</th>
            <th>Bulk Entry?</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($forms as $f): ?>
        <?php
            $fid      = $f['id'];
            $formName = $f['form_name'];
            $isBulk   = $f['bulk_entry'];
        ?>
        <tr>
  <!-- 1) Update Form: includes text fields, bulk checkbox, and an Update button -->
  <td>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="action" value="edit_form">
      <input type="hidden" name="form_id" value="<?= $fid ?>">
      <input type="hidden" name="team_id" value="<?= htmlspecialchars($selected_team_id) ?>">

      <!-- Text field for the form name -->
      <input type="text" name="form_name"
             value="<?= htmlspecialchars($formName) ?>"
             class="form-control"
             required>
  </td>
  <td class="text-center">
      <div class="form-check d-inline-block">
        <input class="form-check-input" type="checkbox" name="bulk_entry"
               value="1" <?= ($isBulk == 1) ? 'checked' : '' ?>>
        <label class="form-check-label">Bulk?</label>
      </div>
  </td>
  <td>
      <!-- "Update" button is part of the same form -->
      <button type="submit" class="btn btn-primary">Update</button>
    </form>

    <!-- 2) "Assign Questions" is just a link, no form -->
    <a href="?team_id=<?= urlencode($selected_team_id) ?>&form_id=<?= $fid ?>"
       class="btn btn-info">
      Assign Questions
    </a>

    <!-- 3) Separate Delete Form (no nesting!) -->
    <form method="POST" style="display:inline;"
          onsubmit="return confirm('Delete this form?');">
      <input type="hidden" name="action" value="delete_form">
      <input type="hidden" name="form_id" value="<?= $fid ?>">
      <button type="submit" class="btn btn-danger">Delete</button>
    </form>
  </td>
</tr>

    <?php endforeach; ?>
    </tbody>
</table>
<?php elseif (!empty($selected_team_id)): ?>
<p><em>No forms found for this team.</em></p>
<?php endif; ?>

<!-- 4) Assign Questions if form_id is present -->
<?php if (isset($_GET['form_id'])): ?>
    <?php
    $form_id = (int)$_GET['form_id'];

    // Ensure sort_order column exists (migration for existing databases)
    $colCheck = $conn->query("SHOW COLUMNS FROM form_questions LIKE 'sort_order'");
    if ($colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE form_questions ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    }

    // Fetch all questions with type and points
    $questionsRes = $conn->query("
        SELECT id, question_text, type, points
        FROM scoring_questions
        ORDER BY type ASC, question_text ASC
    ");
    $allQuestions = [];
    while ($q = $questionsRes->fetch_assoc()) {
        $allQuestions[(int)$q['id']] = $q;
    }

    // Fetch all multiple-choice options, keyed by question_id
    $allOptions = [];
    $optRes = $conn->query("SELECT question_id, id, option_text, option_points FROM scoring_options ORDER BY id ASC");
    while ($opt = $optRes->fetch_assoc()) {
        $allOptions[$opt['question_id']][] = $opt;
    }

    // Fetch assigned questions in their per-form order, with prefill values
    $assignedOrdered = []; // ordered list of question_ids
    $assignedPrefill = [];
    $assignedSortOrders = [];

    $aqRes = $conn->query("
        SELECT fq.question_id, fq.sort_order, fp.prefill_value
        FROM form_questions fq
        LEFT JOIN form_prefill_values fp
               ON fq.form_id = fp.form_id
              AND fq.question_id = fp.question_id
        WHERE fq.form_id = $form_id
        ORDER BY fq.sort_order ASC, fq.id ASC
    ");
    while ($row = $aqRes->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        $assignedOrdered[] = $qid;
        $assignedPrefill[$qid]    = $row['prefill_value'] ?? '';
        $assignedSortOrders[$qid] = (int)$row['sort_order'];
    }
    $assignedSet = array_flip($assignedOrdered); // for O(1) lookup

    // Fetch form name for the heading
    $fnRow = $conn->query("SELECT name FROM form_profiles WHERE id = $form_id")->fetch_assoc();
    $formDisplayName = $fnRow ? htmlspecialchars($fnRow['name']) : "Form $form_id";
    ?>

    <h4 class="mt-5">Assign &amp; Order Questions — <?= $formDisplayName ?></h4>
    <p class="text-muted small mb-3">
        Use ▲ / ▼ to reorder assigned questions. Check a question to include it; uncheck to remove it.
    </p>

    <form method="POST" id="assign-form">
        <input type="hidden" name="action"  value="assign_questions">
        <input type="hidden" name="form_id" value="<?= htmlspecialchars($form_id) ?>">

        <!-- ── Assigned questions (in form order, reorderable) ── -->
        <h6 class="fw-bold mt-2 mb-2">Questions on this form <span class="text-muted fw-normal">(use ▲▼ to reorder)</span></h6>
        <div id="assigned-list" class="mb-4">
        <?php if (empty($assignedOrdered)): ?>
            <p class="text-muted fst-italic no-assigned-msg">No questions assigned yet — click "+ Add" below to add them.</p>
        <?php endif; ?>
        <?php if (!empty($assignedOrdered)): ?>
            <?php
            $sortCounter = 0;
            foreach ($assignedOrdered as $qID):
                if (!isset($allQuestions[$qID])) continue;
                $q              = $allQuestions[$qID];
                $qType          = $q['type'];
                $qPoints        = $q['points'];
                $currentPrefill = $assignedPrefill[$qID] ?? '';
                $sortVal        = $sortCounter++;
            ?>
            <div class="assigned-item d-flex align-items-start gap-2 mb-2 p-2 border rounded bg-white"
                 data-qid="<?= $qID ?>">

                <!-- Reorder buttons -->
                <div class="d-flex flex-column" style="min-width:28px;">
                    <button type="button" class="btn btn-sm p-0 lh-1 move-up"   title="Move up">▲</button>
                    <button type="button" class="btn btn-sm p-0 lh-1 move-down" title="Move down">▼</button>
                </div>

                <!-- Hidden sort_order input (updated by JS on reorder) -->
                <input type="hidden" name="sort_orders[<?= $qID ?>]" value="<?= $sortVal ?>">

                <!-- Checkbox (always checked; uncheck to remove) -->
                <input type="checkbox"
                       class="form-check-input mt-1"
                       name="question_ids[]"
                       value="<?= $qID ?>"
                       checked>

                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= htmlspecialchars($q['question_text']) ?></div>
                    <div class="text-muted small mb-1"><?= $qType === 'multiple_choice' ? 'Multiple choice' : 'Yes / No' ?></div>

                    <?php if ($qType === 'yesno'): ?>
                        <select name="prefill_values[<?= $qID ?>]"
                                class="form-select form-select-sm"
                                style="max-width:260px;">
                            <option value="">— no prefill —</option>
                            <option value="<?= htmlspecialchars($qPoints) ?>"
                                    <?= ($currentPrefill !== '' && $currentPrefill !== '0') ? 'selected' : '' ?>>
                                Yes
                            </option>
                            <option value="0" <?= ($currentPrefill === '0') ? 'selected' : '' ?>>No</option>
                        </select>

                    <?php elseif ($qType === 'multiple_choice' && !empty($allOptions[$qID])): ?>
                        <select name="prefill_values[<?= $qID ?>]"
                                class="form-select form-select-sm"
                                style="max-width:360px;">
                            <option value="">— no prefill —</option>
                            <?php foreach ($allOptions[$qID] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt['option_points']) ?>"
                                        <?= ($currentPrefill === (string)$opt['option_points']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($opt['option_text']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php else: ?>
                        <input type="text"
                               name="prefill_values[<?= $qID ?>]"
                               placeholder="Prefill value (optional)"
                               class="form-control form-control-sm"
                               style="max-width:260px;"
                               value="<?= htmlspecialchars($currentPrefill) ?>">
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <!-- ── Available (unassigned) questions ── -->
        <?php
        $availableQuestions = array_filter($allQuestions, fn($q) => !isset($assignedSet[(int)$q['id']]));
        if (!empty($availableQuestions)):
        ?>
        <h6 class="fw-bold mb-2">Available questions</h6>
        <div id="available-list" class="mb-4">
            <?php
            $availSortBase = count($assignedOrdered); // new questions appended after existing ones
            $availIdx = 0;
            foreach ($availableQuestions as $q):
                $qID    = (int)$q['id'];
                $qType  = $q['type'];
                $qPoints = $q['points'];
                $newSortVal = $availSortBase + $availIdx++;
            ?>
            <div class="available-item d-flex align-items-start gap-2 mb-2 p-2 border rounded"
                 style="background:#faf9f7;" data-qid="<?= $qID ?>">

                <!-- Placeholder (swapped for move buttons when question is added to the form) -->
                <div class="order-placeholder" style="min-width:28px;"></div>

                <!-- Hidden sort_order so newly-added questions land at the end -->
                <input type="hidden" name="sort_orders[<?= $qID ?>]" value="<?= $newSortVal ?>">

                <!-- Checkbox added by JS when the row is moved to the assigned list -->

                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= htmlspecialchars($q['question_text']) ?></div>
                    <div class="text-muted small mb-1"><?= $qType === 'multiple_choice' ? 'Multiple choice' : 'Yes / No' ?></div>

                    <?php if ($qType === 'yesno'): ?>
                        <select name="prefill_values[<?= $qID ?>]"
                                class="form-select form-select-sm"
                                style="max-width:260px;">
                            <option value="">— no prefill —</option>
                            <option value="<?= htmlspecialchars($qPoints) ?>">Yes</option>
                            <option value="0">No</option>
                        </select>

                    <?php elseif ($qType === 'multiple_choice' && !empty($allOptions[$qID])): ?>
                        <select name="prefill_values[<?= $qID ?>]"
                                class="form-select form-select-sm"
                                style="max-width:360px;">
                            <option value="">— no prefill —</option>
                            <?php foreach ($allOptions[$qID] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt['option_points']) ?>">
                                    <?= htmlspecialchars($opt['option_text']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php else: ?>
                        <input type="text"
                               name="prefill_values[<?= $qID ?>]"
                               placeholder="Prefill value (optional)"
                               class="form-control form-control-sm"
                               style="max-width:260px;">
                    <?php endif; ?>
                </div>

                <!-- One-click add button -->
                <button type="button"
                        class="add-to-form btn btn-sm btn-custom align-self-center"
                        title="Add to form">+ Add</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted fst-italic mb-3">All questions are already assigned to this form.</p>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary mt-1">Save Assignments</button>
    </form>

    <script>
    (function () {
        // Renumber all sort_order hidden inputs in the assigned list to reflect DOM order
        function renumber() {
            document.querySelectorAll('#assigned-list .assigned-item').forEach(function (item, idx) {
                const qid = item.dataset.qid;
                const input = item.querySelector('input[name="sort_orders[' + qid + ']"]');
                if (input) input.value = idx;
            });
        }

        // ── Reorder (▲/▼) within the assigned list ──────────────────────────
        document.getElementById('assigned-list')?.addEventListener('click', function (e) {
            const btn = e.target.closest('.move-up, .move-down');
            if (!btn) return;

            const item = btn.closest('.assigned-item');
            if (!item) return;

            if (btn.classList.contains('move-up')) {
                const prev = item.previousElementSibling;
                if (prev && prev.classList.contains('assigned-item')) {
                    item.parentNode.insertBefore(item, prev);
                }
            } else {
                const next = item.nextElementSibling;
                if (next && next.classList.contains('assigned-item')) {
                    item.parentNode.insertBefore(next, item);
                }
            }

            renumber();
        });

        // ── "+ Add" button: move a row from available → assigned ─────────────
        document.getElementById('available-list')?.addEventListener('click', function (e) {
            const btn = e.target.closest('.add-to-form');
            if (!btn) return;

            const item = btn.closest('.available-item');
            if (!item) return;

            // Remove the add button
            btn.remove();

            // Swap the placeholder div for ▲/▼ move buttons
            const placeholder = item.querySelector('.order-placeholder');
            placeholder.className = 'd-flex flex-column';
            placeholder.style.minWidth = '28px';
            placeholder.innerHTML =
                '<button type="button" class="btn btn-sm p-0 lh-1 move-up"   title="Move up">▲</button>' +
                '<button type="button" class="btn btn-sm p-0 lh-1 move-down" title="Move down">▼</button>';

            // Inject and check the checkbox (not present in available rows)
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'form-check-input mt-1';
            cb.name = 'question_ids[]';
            cb.value = item.dataset.qid;
            cb.checked = true;
            placeholder.insertAdjacentElement('afterend', cb);

            // Re-style as an assigned item
            item.classList.remove('available-item');
            item.classList.add('assigned-item');
            item.style.background = 'white';

            // Clear the "no questions" placeholder if it's there
            const noMsg = document.querySelector('#assigned-list .no-assigned-msg');
            if (noMsg) noMsg.remove();

            // Append to the assigned list and renumber
            document.getElementById('assigned-list').appendChild(item);
            renumber();
        });
    })();
    </script>

<?php endif; ?>

<?php
$conn->close();
?>
</body>
</html>
