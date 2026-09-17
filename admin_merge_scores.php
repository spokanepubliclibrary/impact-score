<?php
/**
 * admin_merge_scores.php
 *
 * Merge duplicate program entries (scores) into a single "target" score.
 * Rule: TARGET WINS conflicts.
 *
 * What gets merged:
 * - score_users: union of all user_id onto target score_id
 * - score_responses: per question_id:
 *     - if target missing OR target.points == 0 -> copy source points/response
 *     - else keep target
 * - Deletes source scores and their dependent rows
 * - Recalculates target total_score (SUM(score_responses.points))
 *
 * Includes Step 2 "Preview what will change" per target candidate.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Require admin login
session_start();
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

function recalcTotalScore(mysqli $conn, int $score_id): int {
    $st = $conn->prepare("SELECT COALESCE(SUM(points),0) AS total FROM score_responses WHERE score_id = ?");
    $st->bind_param("i", $score_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['total'] ?? 0);
}

function fetchScoreSummary(mysqli $conn, int $score_id): ?array {
    $st = $conn->prepare("
        SELECT
            s.id,
            s.program,
            s.program_date,
            s.total_score,
            s.user_id,
            u.name AS user_name,
            s.team_id,
            t.name AS team_name,
            s.location_id,
            l.name AS location_name
        FROM scores s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN teams t ON t.id = s.team_id
        LEFT JOIN locations l ON l.id = s.location_id
        WHERE s.id = ?
    ");
    $st->bind_param("i", $score_id);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function fetchResponsesByQuestion(mysqli $conn, int $score_id): array {
    $out = [];
    $st = $conn->prepare("SELECT question_id, points, response FROM score_responses WHERE score_id = ?");
    $st->bind_param("i", $score_id);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $qid = (int)$r['question_id'];
        $out[$qid] = [
            'points'   => (int)$r['points'],
            'response' => (string)($r['response'] ?? '')
        ];
    }
    $st->close();
    return $out;
}

function fetchScoreUsers(mysqli $conn, int $score_id): array {
    $out = [];
    $st = $conn->prepare("
        SELECT su.user_id, u.name
        FROM score_users su
        JOIN users u ON u.id = su.user_id
        WHERE su.score_id = ?
        ORDER BY u.name
    ");
    $st->bind_param("i", $score_id);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $uid = (int)$r['user_id'];
        $out[$uid] = (string)$r['name'];
    }
    $st->close();
    return $out; // [user_id => name]
}

/**
 * Build "what will change" preview if $target_id is kept.
 * Rule: target wins. Preview shows only fills/additions to target.
 *
 * Returns:
 *  - responses_to_fill: [qid => ['from_score'=>id,'from_points'=>pts,'from_response'=>txt]]
 *  - users_to_add: [user_id => name]
 */
function buildMergePreview(mysqli $conn, int $target_id, array $all_selected_ids): array {
    $sources = array_values(array_diff($all_selected_ids, [$target_id]));

    $targetResp = fetchResponsesByQuestion($conn, $target_id);
    $responses_to_fill = [];

    foreach ($sources as $src_id) {
        $srcResp = fetchResponsesByQuestion($conn, $src_id);

        foreach ($srcResp as $qid => $src) {
            $srcPts = (int)$src['points'];
            if ($srcPts === 0) continue; // nothing useful to fill

            $tExists = array_key_exists($qid, $targetResp);
            $tPts    = $tExists ? (int)$targetResp[$qid]['points'] : null;

            // Fill only if target missing OR target points == 0
            if (!$tExists || $tPts === 0) {
                // First source wins for preview (fine; merge will behave consistently)
                if (!isset($responses_to_fill[$qid])) {
                    $responses_to_fill[$qid] = [
                        'from_score'    => $src_id,
                        'from_points'   => $srcPts,
                        'from_response' => (string)($src['response'] ?? '')
                    ];
                }
            }
        }
    }

    // Users to add
    $targetUsers = fetchScoreUsers($conn, $target_id);
    $users_to_add = [];

    foreach ($sources as $src_id) {
        $srcUsers = fetchScoreUsers($conn, $src_id);
        foreach ($srcUsers as $uid => $name) {
            if (!isset($targetUsers[$uid])) {
                $users_to_add[$uid] = $name;
            }
        }
    }

    ksort($responses_to_fill);
    asort($users_to_add);

    return [
        'responses_to_fill' => $responses_to_fill,
        'users_to_add'      => $users_to_add
    ];
}

// --- Dropdown data for filters ---
$users = [];
$ures = $conn->query("SELECT id, name FROM users ORDER BY name ASC");
while ($r = $ures->fetch_assoc()) $users[] = $r;

$teams = [];
$tres = $conn->query("SELECT id, name FROM teams ORDER BY name ASC");
while ($r = $tres->fetch_assoc()) $teams[] = $r;

$locations = [];
$lres = $conn->query("SELECT id, name FROM locations ORDER BY name ASC");
while ($r = $lres->fetch_assoc()) $locations[] = $r;

// Event type options (Q4) for filter display
$eventTypeOptions = [];
$evRes = $conn->query("
    SELECT option_text, COALESCE(points, option_points, 0) AS pts
    FROM scoring_options
    WHERE question_id = 4
    ORDER BY id
");
if ($evRes) {
    while ($row = $evRes->fetch_assoc()) {
        $eventTypeOptions[] = ['text' => $row['option_text'], 'points' => (int)$row['pts']];
    }
}

// --- State ---
$step = $_POST['step'] ?? ($_GET['step'] ?? 'select');
$flash = '';
$errors = [];

// --- Handle POST: MERGE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'merge') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $errors[] = "Invalid CSRF token.";
    }

    $target_id = (int)($_POST['target_id'] ?? 0);
    $selected  = $_POST['selected_scores'] ?? [];
    $selected  = is_array($selected) ? array_values(array_filter(array_map('intval', $selected))) : [];
    $selected  = array_values(array_unique($selected));

    if ($target_id <= 0) $errors[] = "Choose a target program.";
    if (count($selected) < 2) $errors[] = "Select at least two programs to merge.";
    if (!in_array($target_id, $selected, true)) $errors[] = "Target must be one of the selected programs.";

    if (empty($errors)) {
        $sources = array_values(array_diff($selected, [$target_id]));
        if (empty($sources)) $errors[] = "No source programs found to merge.";

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                // Ensure all selected IDs exist
                foreach ($selected as $sid) {
                    $chk = $conn->prepare("SELECT id FROM scores WHERE id = ?");
                    $chk->bind_param("i", $sid);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows === 0) {
                        $chk->close();
                        throw new Exception("Score id {$sid} not found.");
                    }
                    $chk->close();
                }

                // 1) Merge score_users (union onto target)
                $insUsers = $conn->prepare("
                    INSERT INTO score_users (score_id, user_id)
                    SELECT ?, su.user_id
                    FROM score_users su
                    WHERE su.score_id = ?
                      AND NOT EXISTS (
                          SELECT 1 FROM score_users t
                          WHERE t.score_id = ? AND t.user_id = su.user_id
                      )
                ");
                if (!$insUsers) throw new Exception("Prepare failed (merge users): " . $conn->error);

                foreach ($sources as $src_id) {
                    $insUsers->bind_param("iii", $target_id, $src_id, $target_id);
                    if (!$insUsers->execute()) {
                        throw new Exception("Execute failed (merge users): " . $insUsers->error);
                    }
                }
                $insUsers->close();

                // Ensure target primary user exists in score_users
                $stPrim = $conn->prepare("SELECT user_id FROM scores WHERE id = ?");
                $stPrim->bind_param("i", $target_id);
                $stPrim->execute();
                $prim = $stPrim->get_result()->fetch_assoc();
                $stPrim->close();
                $primary_user_id = (int)($prim['user_id'] ?? 0);

                if ($primary_user_id > 0) {
                    $insPrim = $conn->prepare("
                        INSERT INTO score_users (score_id, user_id)
                        SELECT ?, ?
                        WHERE NOT EXISTS (
                            SELECT 1 FROM score_users WHERE score_id = ? AND user_id = ?
                        )
                    ");
                    if (!$insPrim) throw new Exception("Prepare failed (ensure primary user): " . $conn->error);
                    $insPrim->bind_param("iiii", $target_id, $primary_user_id, $target_id, $primary_user_id);
                    $insPrim->execute();
                    $insPrim->close();
                }

                // 2) Merge score_responses (TARGET WINS)
                $selTarget = $conn->prepare("SELECT points FROM score_responses WHERE score_id = ? AND question_id = ?");
                $insResp   = $conn->prepare("INSERT INTO score_responses (score_id, question_id, response, points) VALUES (?,?,?,?)");
                $updResp   = $conn->prepare("UPDATE score_responses SET response = ?, points = ? WHERE score_id = ? AND question_id = ?");

                if (!$selTarget || !$insResp || !$updResp) {
                    throw new Exception("Prepare failed (merge responses): " . $conn->error);
                }

                foreach ($sources as $src_id) {
                    $stSrc = $conn->prepare("SELECT question_id, points, response FROM score_responses WHERE score_id = ?");
                    if (!$stSrc) throw new Exception("Prepare failed (select source responses): " . $conn->error);

                    $stSrc->bind_param("i", $src_id);
                    $stSrc->execute();
                    $srcRes = $stSrc->get_result();

                    while ($r = $srcRes->fetch_assoc()) {
                        $qid    = (int)$r['question_id'];
                        $srcPts = (int)$r['points'];
                        $srcTxt = (string)($r['response'] ?? '');

                        // Does target have this question?
                        $selTarget->bind_param("ii", $target_id, $qid);
                        $selTarget->execute();
                        $selTarget->store_result();

                        if ($selTarget->num_rows === 0) {
                            // Insert into target
                            $insResp->bind_param("iisi", $target_id, $qid, $srcTxt, $srcPts);
                            if (!$insResp->execute()) {
                                throw new Exception("Insert failed (target response): " . $insResp->error);
                            }
                        } else {
                            $selTarget->bind_result($tPts);
                            $selTarget->fetch();
                            $tPts = (int)$tPts;

                            // Only fill if target points == 0 and source is non-zero
                            if ($tPts === 0 && $srcPts !== 0) {
                                $updResp->bind_param("siii", $srcTxt, $srcPts, $target_id, $qid);
                                if (!$updResp->execute()) {
                                    throw new Exception("Update failed (target response): " . $updResp->error);
                                }
                            }
                        }

                        $selTarget->free_result();
                    }

                    $stSrc->close();
                }

                $selTarget->close();
                $insResp->close();
                $updResp->close();

                // 3) Delete sources (responses, users, score rows)
                $delResp = $conn->prepare("DELETE FROM score_responses WHERE score_id = ?");
                $delUsers= $conn->prepare("DELETE FROM score_users WHERE score_id = ?");
                $delScore= $conn->prepare("DELETE FROM scores WHERE id = ?");
                if (!$delResp || !$delUsers || !$delScore) {
                    throw new Exception("Prepare failed (delete sources): " . $conn->error);
                }

                foreach ($sources as $src_id) {
                    $delResp->bind_param("i", $src_id);
                    $delResp->execute();

                    $delUsers->bind_param("i", $src_id);
                    $delUsers->execute();

                    $delScore->bind_param("i", $src_id);
                    $delScore->execute();
                }

                $delResp->close();
                $delUsers->close();
                $delScore->close();

                // 4) Recalc and update target total_score
                $newTotal = recalcTotalScore($conn, $target_id);
                $updTotal = $conn->prepare("UPDATE scores SET total_score = ? WHERE id = ?");
                $updTotal->bind_param("ii", $newTotal, $target_id);
                $updTotal->execute();
                $updTotal->close();

                $conn->commit();
                $flash = "✅ Merge complete. Target kept: #{$target_id}. Deleted sources: " . implode(', ', $sources) . ".";
                $step = 'select';

            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = "Merge failed: " . $ex->getMessage();
                $step = 'confirm';
            }
        }
    }
}

// --- Handle step: CONFIRM (build summaries + previews for chosen scores) ---
$confirm_selected  = [];
$confirm_summaries = [];
$confirm_responses = [];
$confirm_previews  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'confirm') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $confirm_selected = $_POST['selected_scores'] ?? [];
        $confirm_selected = is_array($confirm_selected) ? array_values(array_filter(array_map('intval', $confirm_selected))) : [];
        $confirm_selected = array_values(array_unique($confirm_selected));

        if (count($confirm_selected) < 2) {
            $errors[] = "Select at least two programs to merge.";
        } else {
            foreach ($confirm_selected as $sid) {
                $sum = fetchScoreSummary($conn, $sid);
                if (!$sum) { $errors[] = "Score id {$sid} not found."; continue; }
                $confirm_summaries[$sid] = $sum;
                $confirm_responses[$sid] = fetchResponsesByQuestion($conn, $sid);
            }

            // Build preview per candidate target
            if (empty($errors)) {
                foreach ($confirm_selected as $sid) {
                    $confirm_previews[$sid] = buildMergePreview($conn, $sid, $confirm_selected);
                }
            }
        }
    }
}

// --- Build filtered list (GET) ---
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

if ($f_user_id > 0) { $where[]="s.user_id = ?"; $types.="i"; $params[]=$f_user_id; }
if ($f_team_id > 0) { $where[]="s.team_id = ?"; $types.="i"; $params[]=$f_team_id; }
if ($f_location_id > 0) { $where[]="s.location_id = ?"; $types.="i"; $params[]=$f_location_id; }
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
<title>Admin Merge Programs</title>
<style>
    body { font-family: Montserrat, Arial, sans-serif; background:#f5f2ec; padding:20px; }
    .container { max-width:1200px; margin:auto; background:#fff; padding:16px; border-radius:10px; box-shadow:0 0 10px rgba(0,0,0,.08); }
    h2 { margin:0 0 10px; }
    .row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
    label { font-weight:700; font-size:13px; }
    input, select { width:100%; padding:8px; border:1px solid #ccc; border-radius:6px; }
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
    .hint { color:#666; font-size:12px; }
    .grid2 { display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-top:14px; }
    .card { border:1px solid #eee; border-radius:10px; padding:12px; background:#fff; }
    .subcard { border:1px solid #e7e3dc; border-radius:10px; padding:10px; background:#faf7f2; margin-top:8px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px; white-space:pre-wrap; }

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
</script>
</head>
<body>
<div class="container">

<a class="btn btn-secondary btn-link" href="admin_portal.php">⬅️ Back to Admin</a>

    <h2>🧩 Admin Merge Programs (Target Wins)</h2>
    <div class="hint">
        Select 2+ duplicate program entries, then choose the target to keep. Target wins all conflicts.
        Target keeps its program/team/location/user/date. Missing/zero responses get filled from sources.
    </div>

    <?php if ($flash): ?>
        <div class="flash ok"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="flash err">
            <strong>Fix the following:</strong>
            <ul><?php foreach ($errors as $e) echo "<li>".h($e)."</li>"; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($step === 'confirm' && empty($errors)): ?>
        <h3>Step 2: Choose Target + Preview What Will Change</h3>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="merge">

            <?php foreach ($confirm_selected as $sid): ?>
                <input type="hidden" name="selected_scores[]" value="<?= (int)$sid ?>">
            <?php endforeach; ?>

            <div class="grid2">
                <?php foreach ($confirm_selected as $sid):
                    $sum = $confirm_summaries[$sid];
                    $resp = $confirm_responses[$sid];
                    $preview = $confirm_previews[$sid] ?? ['responses_to_fill'=>[], 'users_to_add'=>[]];
                    $fill = $preview['responses_to_fill'];
                    $adds = $preview['users_to_add'];
                ?>
                <div class="card">
                    <label style="display:flex; gap:8px; align-items:center;">
                        <input type="radio" name="target_id" value="<?= (int)$sid ?>" required>
                        <span><strong>Keep as Target: #<?= (int)$sid ?></strong></span>
                    </label>

                    <div style="margin-top:8px;">
                        <div><strong>Program:</strong> <?= h($sum['program']) ?></div>
                        <div><strong>Date:</strong> <?= h($sum['program_date']) ?></div>
                        <div><strong>User:</strong> <?= h($sum['user_name']) ?></div>
                        <div><strong>Team:</strong> <?= h($sum['team_name'] ?? '') ?></div>
                        <div><strong>Location:</strong> <?= h($sum['location_name'] ?? '') ?></div>
                        <div><strong>Total Score:</strong> <?= (int)($sum['total_score'] ?? 0) ?></div>
                    </div>

                    <div class="subcard">
                        <div class="hint"><strong>Current responses (question_id => points):</strong></div>
                        <div class="mono"><?php
                            ksort($resp);
                            foreach ($resp as $qid => $r) echo (int)$qid . " => " . (int)$r['points'] . "\n";
                        ?></div>
                    </div>

                    <div class="subcard">
                        <div class="hint"><strong>Preview what will change if this is the target:</strong></div>

                        <div style="margin-top:6px;"><strong>Responses that would be filled in:</strong> <?= count($fill) ?></div>
                        <?php if (empty($fill)): ?>
                            <div class="hint">None — this target already has non-zero values where sources do.</div>
                        <?php else: ?>
                            <div class="mono" style="margin-top:6px;"><?php
                                foreach ($fill as $qid => $info) {
                                    $from = (int)$info['from_score'];
                                    $pts  = (int)$info['from_points'];
                                    echo "Q{$qid}: 0 → {$pts} (from #{$from})\n";
                                }
                            ?></div>
                        <?php endif; ?>

                        <div style="margin-top:10px;"><strong>Staff that would be added:</strong> <?= count($adds) ?></div>
                        <?php if (empty($adds)): ?>
                            <div class="hint">None — target already includes all staff from sources.</div>
                        <?php else: ?>
                            <div class="mono" style="margin-top:6px;"><?php
                                foreach ($adds as $uid => $name) {
                                    echo "#{$uid} — {$name}\n";
                                }
                            ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:14px;">
                <button class="btn" type="submit"
                        onclick="return confirm('Merge selected scores? Target wins. Sources will be deleted.');">
                    ✅ Merge Into Target
                </button>
                <a class="btn btn-secondary" style="text-decoration:none; display:inline-block;"
                   href="admin_merge_scores.php">Cancel</a>
            </div>
        </form>

    <?php else: ?>
        <h3>Step 1: Filter and Select Programs to Merge</h3>

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
                   href="admin_merge_scores.php">Reset</a>
            </div>
        </form>

        <div class="hint">Showing up to 500 results. Select 2+ and continue.</div>

        <!-- SELECT + CONTINUE -->
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="confirm">

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

            <div style="margin-top:14px;">
                <button class="btn" type="submit">➡️ Continue to Choose Target</button>
            </div>
        </form>
    <?php endif; ?>

</div>
</body>
</html>
<?php $conn->close(); ?>
