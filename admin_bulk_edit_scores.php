<?php
/**
 * admin_bulk_edit_scores.php
 *
 * Bulk edit tool for admins:
 * - Filter scores (program submissions) by user/team/location/date/event type + keyword
 * - Select one or more score rows
 * - Apply ONE change (one field) to all selected scores
 *
 * Notes:
 * - Event Type is stored in score_responses where question_id = 4
 * - If Event Type changes, we recalc total_score based on all responses
 * - “Program Type” / “single vs bulk” is schema-aware: we only show options if columns exist
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// --- CSRF ---
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// --- Helpers ---
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function columnExists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) AS c
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?";
    $st = $conn->prepare($sql);
    $st->bind_param("ss", $table, $column);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function recalcTotalScore(mysqli $conn, int $score_id): int {
    $st = $conn->prepare("SELECT COALESCE(SUM(points),0) AS total FROM score_responses WHERE score_id = ?");
    $st->bind_param("i", $score_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['total'] ?? 0);
}

function upsertScoreResponse(mysqli $conn, int $score_id, int $question_id, int $points, string $responseText = ''): void {
    $st = $conn->prepare("SELECT id FROM score_responses WHERE score_id = ? AND question_id = ?");
    $st->bind_param("ii", $score_id, $question_id);
    $st->execute();
    $st->store_result();
    $exists = ($st->num_rows > 0);
    $st->close();

    if ($exists) {
        if ($responseText !== '') {
            $st = $conn->prepare("UPDATE score_responses SET points = ?, response = ? WHERE score_id = ? AND question_id = ?");
            $st->bind_param("isii", $points, $responseText, $score_id, $question_id);
        } else {
            $st = $conn->prepare("UPDATE score_responses SET points = ? WHERE score_id = ? AND question_id = ?");
            $st->bind_param("iii", $points, $score_id, $question_id);
        }
        $st->execute();
        $st->close();
    } else {
        if ($responseText !== '') {
            $st = $conn->prepare("INSERT INTO score_responses (score_id, question_id, response, points) VALUES (?,?,?,?)");
            $st->bind_param("iisi", $score_id, $question_id, $responseText, $points);
        } else {
            $st = $conn->prepare("INSERT INTO score_responses (score_id, question_id, points) VALUES (?,?,?)");
            $st->bind_param("iii", $score_id, $question_id, $points);
        }
        $st->execute();
        $st->close();
    }
}

// --- Load dropdown data ---
$users = [];
$ures = $conn->query("SELECT id, name FROM users ORDER BY name ASC");
while ($r = $ures->fetch_assoc()) $users[] = $r;

$teams = [];
$tres = $conn->query("SELECT id, name FROM teams ORDER BY name ASC");
while ($r = $tres->fetch_assoc()) $teams[] = $r;

$locations = [];
$lres = $conn->query("SELECT id, name FROM locations ORDER BY name ASC");
while ($r = $lres->fetch_assoc()) $locations[] = $r;

// Event type options (Q4)
$eventTypeOptions   = [];
$eventTypeByPoints  = [];
$evRes = $conn->query("
    SELECT id, option_text, points, option_points
    FROM scoring_options
    WHERE question_id = 4
    ORDER BY id
");
if ($evRes) {
    while ($row = $evRes->fetch_assoc()) {
        $pts = (int)($row['points'] ?: $row['option_points']);
        $eventTypeOptions[] = ['text' => $row['option_text'], 'points' => $pts];
        $eventTypeByPoints[$pts] = (string)$row['option_text'];
    }
}

// Schema-aware “program type / bulk mode”
$has_form_profile_id = columnExists($conn, 'scores', 'form_profile_id');
$has_form_id         = columnExists($conn, 'scores', 'form_id');
$has_is_bulk         = columnExists($conn, 'scores', 'is_bulk');
$has_entry_mode      = columnExists($conn, 'scores', 'entry_mode');

// --- If scores.form_id is used for bulk/single, populate form ids for a dropdown ---
$bulkForms = [];
if ($has_form_id) {
    // This assumes your “available forms” live in form_profiles (id, name)
    $bf = $conn->query("SELECT id, name FROM form_profiles ORDER BY name ASC");
    if ($bf) {
        while ($row = $bf->fetch_assoc()) {
            $bulkForms[] = [
                'id'   => (int)$row['id'],
                'name' => (string)($row['name'] ?? ('Form #' . (int)$row['id']))
            ];
        }
    }
}

// --- Handle POST: Apply Bulk Change ---
$flash = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $errors[] = "Invalid CSRF token.";
    }

    $selected = $_POST['selected_scores'] ?? [];
    $selected = is_array($selected) ? array_values(array_filter(array_map('intval', $selected))) : [];

    $field = trim($_POST['field_to_change'] ?? '');

    if (empty($selected)) $errors[] = "Select at least one program.";
    if ($field === '')    $errors[] = "Choose a field to change.";

    // Gather new value(s)
    $new_user_id      = (int)($_POST['new_user_id'] ?? 0);
    $new_team_id      = (int)($_POST['new_team_id'] ?? 0);
    $new_location_id  = (int)($_POST['new_location_id'] ?? 0);
    $new_program      = trim($_POST['new_program'] ?? '');
    $new_program_date = trim($_POST['new_program_date'] ?? '');
    $new_event_points = (int)($_POST['new_event_type_points'] ?? 0);

    // Bulk/single conversion value (schema-aware)
    $new_bulk_value = $_POST['new_bulk_value'] ?? '';
    if ($new_bulk_value === '__custom__') {
        $new_bulk_value = $_POST['new_bulk_value_custom'] ?? '';
    }
    $bulk_mode = ($new_bulk_value === '__single__') ? 'clear' : 'set';

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            foreach ($selected as $score_id) {

                if ($field === 'user_id') {
                    if ($new_user_id <= 0) throw new Exception("Invalid user selection.");
                    $st = $conn->prepare("UPDATE scores SET user_id = ? WHERE id = ?");
                    $st->bind_param("ii", $new_user_id, $score_id);
                    $st->execute(); $st->close();
                }

                elseif ($field === 'team_id') {
                    if ($new_team_id <= 0) throw new Exception("Invalid team selection.");
                    $st = $conn->prepare("UPDATE scores SET team_id = ? WHERE id = ?");
                    $st->bind_param("ii", $new_team_id, $score_id);
                    $st->execute(); $st->close();
                }

                elseif ($field === 'location_id') {
                    if ($new_location_id <= 0) throw new Exception("Invalid location selection.");
                    $st = $conn->prepare("UPDATE scores SET location_id = ? WHERE id = ?");
                    $st->bind_param("ii", $new_location_id, $score_id);
                    $st->execute(); $st->close();
                }

                elseif ($field === 'program') {
                    if ($new_program === '') throw new Exception("Program name cannot be blank.");
                    $st = $conn->prepare("UPDATE scores SET program = ? WHERE id = ?");
                    $st->bind_param("si", $new_program, $score_id);
                    $st->execute(); $st->close();
                }

                elseif ($field === 'program_date') {
                    if ($new_program_date === '') throw new Exception("Program date cannot be blank.");
                    $st = $conn->prepare("UPDATE scores SET program_date = ? WHERE id = ?");
                    $st->bind_param("si", $new_program_date, $score_id);
                    $st->execute(); $st->close();
                }

                elseif ($field === 'total_score') {
                    $new_total_score = (int)($_POST['new_total_score'] ?? -1);
                    if ($new_total_score < 0) throw new Exception("Score must be 0 or greater.");
                    $st = $conn->prepare("UPDATE scores SET total_score = ?, adjusted_impact_score = ? * scaled_attendance WHERE id = ?");
                    $st->bind_param("iii", $new_total_score, $new_total_score, $score_id);
                    $st->execute(); $st->close();
                }

                elseif ($field === 'event_type_q4') {
                    if ($new_event_points < 0) throw new Exception("Invalid event type.");
                    $label = $eventTypeByPoints[$new_event_points] ?? '';
                    upsertScoreResponse($conn, $score_id, 4, $new_event_points, $label);

                    $newTotal = recalcTotalScore($conn, $score_id);
                    $st = $conn->prepare("UPDATE scores SET total_score = ? WHERE id = ?");
                    $st->bind_param("ii", $newTotal, $score_id);
                    $st->execute(); $st->close();
                }

                elseif ($field === 'convert_single_to_bulk') {

                    if ($has_is_bulk) {
                        // 1 = bulk, 0 = single
                        $val = ($new_bulk_value === '1') ? 1 : 0;
                        $st = $conn->prepare("UPDATE scores SET is_bulk = ? WHERE id = ?");
                        $st->bind_param("ii", $val, $score_id);
                        $st->execute(); $st->close();
                    }

                    elseif ($has_entry_mode) {
                        if (!in_array($new_bulk_value, ['single','bulk'], true)) {
                            throw new Exception("Invalid entry_mode value.");
                        }
                        $st = $conn->prepare("UPDATE scores SET entry_mode = ? WHERE id = ?");
                        $st->bind_param("si", $new_bulk_value, $score_id);
                        $st->execute(); $st->close();
                    }

                    elseif ($has_form_profile_id) {
                        if ($bulk_mode === 'clear') {
                            $st = $conn->prepare("UPDATE scores SET form_profile_id = NULL WHERE id = ?");
                            $st->bind_param("i", $score_id);
                            $st->execute(); $st->close();
                        } else {
                            $bulk_profile_id = (int)$new_bulk_value;
                            if ($bulk_profile_id <= 0) throw new Exception("Choose a bulk form_profile_id or pick Single (clear).");
                            $st = $conn->prepare("UPDATE scores SET form_profile_id = ? WHERE id = ?");
                            $st->bind_param("ii", $bulk_profile_id, $score_id);
                            $st->execute(); $st->close();
                        }
                    }

                    elseif ($has_form_id) {
                        if ($bulk_mode === 'clear') {
                            $st = $conn->prepare("UPDATE scores SET form_id = NULL WHERE id = ?");
                            $st->bind_param("i", $score_id);
                            $st->execute(); $st->close();
                        } else {
                            $bulk_form_id = (int)$new_bulk_value;
                            if ($bulk_form_id <= 0) throw new Exception("Choose a bulk form_id or pick Single (clear).");
                            $st = $conn->prepare("UPDATE scores SET form_id = ? WHERE id = ?");
                            $st->bind_param("ii", $bulk_form_id, $score_id);
                            $st->execute(); $st->close();
                        }
                    }

                    else {
                        throw new Exception("No known bulk/single column exists in scores table yet.");
                    }
                }

                else {
                    throw new Exception("Unsupported field change: " . $field);
                }
            }

            $conn->commit();
            $flash = "✅ Updated " . count($selected) . " program(s).";
        } catch (Exception $ex) {
            $conn->rollback();
            $errors[] = "Save failed: " . $ex->getMessage();
        }
    }
}

// --- Handle GET: Build filtered list ---
$f_user_id     = (int)($_GET['user_id'] ?? 0);
$f_team_id     = (int)($_GET['team_id'] ?? 0);
$f_location_id = (int)($_GET['location_id'] ?? 0);
$f_date_from   = trim($_GET['date_from'] ?? '');
$f_date_to     = trim($_GET['date_to'] ?? '');
$f_event_pts   = (int)($_GET['event_points'] ?? 0);
$f_keyword     = trim($_GET['keyword'] ?? '');

$where = [];
$params = [];
$types  = "";

$sql = "
    SELECT
        s.id,
        s.program,
        s.program_date,
        s.total_score,
        u.name AS user_name,
        t.name AS team_name,
        l.name AS location_name,
        sr4.points AS event_points
    FROM scores s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN teams t ON t.id = s.team_id
    LEFT JOIN locations l ON l.id = s.location_id
    LEFT JOIN score_responses sr4
           ON sr4.score_id = s.id AND sr4.question_id = 4
";

if ($f_user_id > 0)     { $where[]="s.user_id = ?";      $types.="i";  $params[]=$f_user_id; }
if ($f_team_id > 0)     { $where[]="s.team_id = ?";      $types.="i";  $params[]=$f_team_id; }
if ($f_location_id > 0) { $where[]="s.location_id = ?";  $types.="i";  $params[]=$f_location_id; }

if ($f_date_from !== '') { $where[]="s.program_date >= ?"; $types.="s"; $params[]=$f_date_from; }
if ($f_date_to !== '')   { $where[]="s.program_date <= ?"; $types.="s"; $params[]=$f_date_to; }

if ($f_event_pts > 0) {
    $where[]="COALESCE(sr4.points,0) = ?";
    $types.="i"; $params[]=$f_event_pts;
}

if ($f_keyword !== '') {
    $where[]="(s.program LIKE ? OR u.name LIKE ?)";
    $types.="ss";
    $kw = "%".$f_keyword."%";
    $params[]=$kw; $params[]=$kw;
}

if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY s.program_date DESC, s.id DESC LIMIT 500";

$list = [];
$st = $conn->prepare($sql);
if ($types !== "") $st->bind_param($types, ...$params);
$st->execute();
$res = $st->get_result();
while ($r = $res->fetch_assoc()) $list[] = $r;
$st->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Bulk Edit Programs</title>
<style>
    body { font-family: Montserrat, Arial, sans-serif; background:#f5f2ec; padding:20px; }
    .container { max-width:1100px; margin:auto; background:#fff; padding:16px; border-radius:10px; box-shadow:0 0 10px rgba(0,0,0,.08); }
    h2 { margin-top:0; }
    label { font-weight:700; font-size:13px; }
    input, select { width:100%; padding:8px; border:1px solid #ccc; border-radius:6px; }
    .row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
    .btn { padding:10px 14px; border:0; border-radius:8px; background:#480d3c; color:#fff; cursor:pointer; }
    .btn:hover { background:#bb1b51; }
    .btn-secondary { background:#666; }
    .btn-secondary:hover { background:#444; }
    table { width:100%; border-collapse:collapse; margin-top:14px; }
    th, td { padding:10px; border-bottom:1px solid #eee; vertical-align:top; }
    th { text-align:left; background:#faf7f2; position:sticky; top:0; }
    .flash { padding:10px; border-radius:8px; margin:10px 0; }
    .ok { background:#e9f7ef; color:#1e6b3a; }
    .err { background:#fdecea; color:#8a1f11; }
    .actions { margin-top:18px; padding-top:14px; border-top:1px solid #eee; }
    .hint { color:#666; font-size:12px; }

    .btn-link {
    display:inline-block;
    text-decoration:none;
    margin-bottom:12px;
}


</style>
<script>
function toggleAll(source){
    document.querySelectorAll('input[name="selected_scores[]"]').forEach(cb => cb.checked = source.checked);
}
function showFieldInputs(){
    const field = document.getElementById('field_to_change').value;
    document.querySelectorAll('.field-input').forEach(el => el.style.display='none');
    const target = document.querySelector('.field-' + field);
    if (target) target.style.display='block';
}
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('field_to_change');
    if (sel) sel.addEventListener('change', showFieldInputs);
    showFieldInputs();

    const bulkSel = document.getElementById('bulk_form_id_select');
    const bulkCustom = document.getElementById('bulk_form_id_custom');
    function syncBulkCustom() {
        if (!bulkSel || !bulkCustom) return;
        const isCustom = bulkSel.value === '__custom__';
        bulkCustom.style.display = isCustom ? 'block' : 'none';
        if (!isCustom) bulkCustom.value = '';
    }
    if (bulkSel) bulkSel.addEventListener('change', syncBulkCustom);
    syncBulkCustom();
});
</script>
</head>
<body>
<div class="container">
<a class="btn btn-secondary btn-link" href="admin_portal.php">⬅️ Back to Admin</a>

    <h2>🛠️ Admin Bulk Edit Programs</h2>

    <?php if ($flash): ?>
        <div class="flash ok"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="flash err">
            <strong>Fix the following:</strong>
            <ul><?php foreach ($errors as $e) echo "<li>".h($e)."</li>"; ?></ul>
        </div>
    <?php endif; ?>

    <!-- FILTERS (GET) -->
    <form method="GET" class="row" style="margin-bottom:14px;">
        <div style="flex:1; min-width:180px;">
            <label>User</label>
            <select name="user_id">
                <option value="0">All</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id']===$f_user_id?'selected':'') ?>>
                        <?= h($u['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1; min-width:180px;">
            <label>Team</label>
            <select name="team_id">
                <option value="0">All</option>
                <?php foreach ($teams as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id']===$f_team_id?'selected':'') ?>>
                        <?= h($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1; min-width:180px;">
            <label>Location</label>
            <select name="location_id">
                <option value="0">All</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int)$l['id'] ?>" <?= ((int)$l['id']===$f_location_id?'selected':'') ?>>
                        <?= h($l['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1; min-width:160px;">
            <label>Date from</label>
            <input type="date" name="date_from" value="<?= h($f_date_from) ?>">
        </div>

        <div style="flex:1; min-width:160px;">
            <label>Date to</label>
            <input type="date" name="date_to" value="<?= h($f_date_to) ?>">
        </div>

        <div style="flex:1; min-width:180px;">
            <label>Event Type (Q4)</label>
            <select name="event_points">
                <option value="0">All</option>
                <?php foreach ($eventTypeOptions as $opt): ?>
                    <option value="<?= (int)$opt['points'] ?>" <?= ((int)$opt['points']===$f_event_pts?'selected':'') ?>>
                        <?= h($opt['text']) ?> (<?= (int)$opt['points'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:2; min-width:240px;">
            <label>Keyword</label>
            <input type="text" name="keyword" value="<?= h($f_keyword) ?>" placeholder="Search program or user...">
        </div>

        <div style="min-width:160px;">
            <button class="btn" type="submit">Filter</button>
            <a class="btn btn-secondary" style="text-decoration:none; display:inline-block;"
               href="admin_bulk_edit_scores.php">Reset</a>
        </div>
    </form>

    <div class="hint">Showing up to 500 results. Use filters to narrow down.</div>

    <!-- RESULTS + APPLY (POST) -->
    <form method="POST">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="apply">

        <table>
            <thead>
                <tr>
                    <th style="width:34px;"><input type="checkbox" onclick="toggleAll(this)"></th>
                    <th>ID</th>
                    <th>Program</th>
                    <th>Date</th>
                    <th>User</th>
                    <th>Team</th>
                    <th>Location</th>
                    <th>Event Type</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($list)): ?>
                <tr><td colspan="9">No matching programs found.</td></tr>
            <?php else: foreach ($list as $r): ?>
                <tr>
                    <td><input type="checkbox" name="selected_scores[]" value="<?= (int)$r['id'] ?>"></td>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($r['program']) ?></td>
                    <td><?= h($r['program_date']) ?></td>
                    <td><?= h($r['user_name']) ?></td>
                    <td><?= h($r['team_name'] ?? '') ?></td>
                    <td><?= h($r['location_name'] ?? '') ?></td>
                    <td><?= (int)($r['event_points'] ?? 0) ?></td>
                    <td><?= (int)($r['total_score'] ?? 0) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <div class="actions">
            <h3>Apply One Change to Selected Programs</h3>

            <div class="row">
                <div style="flex:2; min-width:280px;">
                    <label>Field to change</label>
                    <select name="field_to_change" id="field_to_change" required>
                        <option value="">-- Choose --</option>
                        <option value="user_id">Primary Staff (scores.user_id)</option>
                        <option value="team_id">Team</option>
                        <option value="location_id">Location</option>
                        <option value="program">Program Name</option>
                        <option value="program_date">Program Date</option>
                        <option value="total_score">Total Score (manual override)</option>
                        <option value="event_type_q4">Event Type (Question 4)</option>

                        <?php if ($has_is_bulk || $has_entry_mode || $has_form_profile_id || $has_form_id): ?>
                            <option value="convert_single_to_bulk">Convert Single ↔ Bulk (schema-aware)</option>
                        <?php endif; ?>
                    </select>
                    <div class="hint">Only the chosen field is updated. Everything else remains unchanged.</div>
                </div>

                <!-- Field-specific inputs (shown/hidden) -->
                <div class="field-input field-user_id" style="flex:2; min-width:280px; display:none;">
                    <label>New Primary Staff</label>
                    <select name="new_user_id">
                        <option value="0">-- Select --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-input field-team_id" style="flex:2; min-width:280px; display:none;">
                    <label>New Team</label>
                    <select name="new_team_id">
                        <option value="0">-- Select --</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-input field-location_id" style="flex:2; min-width:280px; display:none;">
                    <label>New Location</label>
                    <select name="new_location_id">
                        <option value="0">-- Select --</option>
                        <?php foreach ($locations as $l): ?>
                            <option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-input field-program" style="flex:2; min-width:280px; display:none;">
                    <label>New Program Name</label>
                    <input type="text" name="new_program" placeholder="e.g., Storytime">
                </div>

                <div class="field-input field-program_date" style="flex:2; min-width:280px; display:none;">
                    <label>New Program Date</label>
                    <input type="date" name="new_program_date">
                </div>

                <div class="field-input field-total_score" style="flex:2; min-width:280px; display:none;">
                    <label>New Total Score</label>
                    <input type="number" name="new_total_score" min="0" placeholder="e.g., 75">
                    <div class="hint">Sets <code>total_score</code> directly and recalculates <code>adjusted_impact_score</code>. Does not modify individual question responses.</div>
                </div>

                <div class="field-input field-event_type_q4" style="flex:2; min-width:280px; display:none;">
                    <label>New Event Type (Q4)</label>
                    <select name="new_event_type_points">
                        <option value="0">-- Select --</option>
                        <?php foreach ($eventTypeOptions as $opt): ?>
                            <option value="<?= (int)$opt['points'] ?>">
                                <?= h($opt['text']) ?> (<?= (int)$opt['points'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-input field-convert_single_to_bulk" style="flex:2; min-width:280px; display:none;">
                    <label>Bulk/Single Value</label>

                    <?php if ($has_is_bulk): ?>
                        <select name="new_bulk_value">
                            <option value="1">Bulk (is_bulk = 1)</option>
                            <option value="0">Single (is_bulk = 0)</option>
                        </select>
                        <div class="hint">This uses <code>scores.is_bulk</code>.</div>

                    <?php elseif ($has_entry_mode): ?>
                        <select name="new_bulk_value">
                            <option value="bulk">Bulk (entry_mode = bulk)</option>
                            <option value="single">Single (entry_mode = single)</option>
                        </select>
                        <div class="hint">This uses <code>scores.entry_mode</code>.</div>

                    <?php elseif ($has_form_profile_id): ?>
                        <select name="new_bulk_value">
                            <option value="__single__">Single (clear form_profile_id)</option>
                            <option value="__custom__">Custom ID…</option>
                        </select>
                        <input
                            type="number"
                            name="new_bulk_value_custom"
                            placeholder="Enter form_profile_id manually"
                            style="margin-top:8px;"
                        >
                        <div class="hint">This uses <code>scores.form_profile_id</code>. Pick Single to clear it, or enter a bulk profile id.</div>

                    <?php elseif ($has_form_id): ?>
                        <select name="new_bulk_value" id="bulk_form_id_select">
                            <option value="">-- Choose --</option>
                            <option value="__single__">Single (clear form_id)</option>
                            <option disabled>──────────</option>
                            <option value="">Bulk (choose a form)</option>
                            <?php foreach ($bulkForms as $f): ?>
                                <option value="<?= (int)$f['id'] ?>">
                                    #<?= (int)$f['id'] ?> — <?= h($f['name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__custom__">Custom ID…</option>
                        </select>

                        <input
                            type="number"
                            name="new_bulk_value_custom"
                            id="bulk_form_id_custom"
                            placeholder="Enter form_id manually"
                            style="display:none; margin-top:8px;"
                        >

                        <div class="hint">This uses <code>scores.form_id</code>. Pick Single to clear it, or select a bulk form id.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top:12px;">
                <button class="btn" type="submit">✅ Apply Change to Selected</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>
<?php $conn->close(); ?>
