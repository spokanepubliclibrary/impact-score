<?php
session_start();
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: index.php");
    exit();
}

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// ---- Load performer ----
$stmt = $conn->prepare("SELECT * FROM performers WHERE performer_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$p) { header("Location: index.php"); exit(); }

// ---- Handle POST actions (admin only) ----
$actionMsg = '';
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_contact') {
        $cname    = trim($_POST['contact_name'] ?? '');
        $crole    = trim($_POST['role'] ?? '') ?: null;
        $cemail   = trim($_POST['email'] ?? '') ?: null;
        $cphone   = trim($_POST['phone'] ?? '') ?: null;
        $cmethod  = ($_POST['preferred_contact_method'] ?? '') ?: null;
        $cprimary = isset($_POST['is_primary']) ? 1 : 0;
        $cnotes   = trim($_POST['notes'] ?? '') ?: null;
        if ($cprimary) {
            $upd = $conn->prepare("UPDATE performer_contacts SET is_primary=0 WHERE performer_id=?");
            $upd->bind_param("i", $id); $upd->execute(); $upd->close();
        }
        $stmt = $conn->prepare("INSERT INTO performer_contacts (performer_id, contact_name, role, email, phone, preferred_contact_method, is_primary, notes) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("isssssis", $id, $cname, $crole, $cemail, $cphone, $cmethod, $cprimary, $cnotes);
        $stmt->execute(); $stmt->close();
        $actionMsg = "Contact added.";
    }

    elseif ($action === 'delete_contact') {
        $cid = (int)($_POST['contact_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM performer_contacts WHERE contact_id=? AND performer_id=?");
        $stmt->bind_param("ii", $cid, $id); $stmt->execute(); $stmt->close();
        $actionMsg = "Contact deleted.";
    }

    elseif ($action === 'add_note') {
        $ntype = $_POST['note_type'] ?? 'general';
        $nvis  = $_POST['visibility'] ?? 'internal';
        $ntext = trim($_POST['note_text'] ?? '');
        $nby   = trim($_POST['entered_by'] ?? '');
        $nbid  = ($_POST['booking_id'] ?? '') !== '' ? (int)$_POST['booking_id'] : null;
        if ($ntext !== '') {
            $stmt = $conn->prepare("INSERT INTO performer_notes (performer_id, booking_id, note_type, visibility, note_text, entered_by) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("iissss", $id, $nbid, $ntype, $nvis, $ntext, $nby);
            $stmt->execute(); $stmt->close();
            $actionMsg = "Note added.";
        }
    }

    elseif ($action === 'delete_note') {
        $nid = (int)($_POST['note_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM performer_notes WHERE note_id=? AND performer_id=?");
        $stmt->bind_param("ii", $nid, $id); $stmt->execute(); $stmt->close();
        $actionMsg = "Note deleted.";
    }

    elseif ($action === 'add_program') {
        $ptitle  = trim($_POST['program_title'] ?? '');
        $pdesc   = trim($_POST['program_description'] ?? '');
        $paud    = $_POST['audience'] ?? 'all_ages';
        $pfmt    = $_POST['program_format'] ?? 'performance';
        $pdur    = ($_POST['duration_minutes'] ?? '') !== '' ? (int)$_POST['duration_minutes'] : null;
        $pbase   = ($_POST['base_fee'] ?? '') !== '' ? (float)$_POST['base_fee'] : null;
        $ptravel = ($_POST['travel_fee'] ?? '') !== '' ? (float)$_POST['travel_fee'] : null;
        $pvirt   = isset($_POST['virtual_available']) ? 1 : 0;
        if ($ptitle !== '') {
            $stmt = $conn->prepare("INSERT INTO performer_programs (performer_id, program_title, program_description, audience, program_format, duration_minutes, base_fee, travel_fee, virtual_available) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("issssiddi", $id, $ptitle, $pdesc, $paud, $pfmt, $pdur, $pbase, $ptravel, $pvirt);
            $stmt->execute(); $stmt->close();
            $actionMsg = "Program added.";
        }
    }

    elseif ($action === 'delete_program') {
        $pid = (int)($_POST['program_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM performer_programs WHERE program_id=? AND performer_id=?");
        $stmt->bind_param("ii", $pid, $id); $stmt->execute(); $stmt->close();
        $actionMsg = "Program deleted.";
    }

    elseif ($action === 'save_payment') {
        $payee   = trim($_POST['payee_name'] ?? '');
        $method  = $_POST['payment_method'] ?? 'check';
        $terms   = trim($_POST['payment_terms'] ?? '');
        $req_po  = isset($_POST['requires_po']) ? 1 : 0;
        $req_ct  = isset($_POST['requires_contract']) ? 1 : 0;
        if ($payee !== '') {
            // Upsert: delete existing then insert
            $d = $conn->prepare("DELETE FROM performer_payment_profiles WHERE performer_id=?");
            $d->bind_param("i", $id); $d->execute(); $d->close();
            $stmt = $conn->prepare("INSERT INTO performer_payment_profiles (performer_id, payee_name, payment_method, payment_terms, requires_po, requires_contract) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("isssii", $id, $payee, $method, $terms, $req_po, $req_ct);
            $stmt->execute(); $stmt->close();
            $actionMsg = "Payment info saved.";
        }
    }

    elseif ($action === 'upload_file') {
        if (isset($_FILES['performer_file']) && $_FILES['performer_file']['error'] === UPLOAD_ERR_OK) {
            $ftype  = $_POST['file_type'] ?? 'other';
            $fby    = trim($_POST['uploaded_by'] ?? '');
            $tmpPath = $_FILES['performer_file']['tmp_name'];
            $origName = basename($_FILES['performer_file']['name']);
            $mime   = $_FILES['performer_file']['type'];
            $size   = $_FILES['performer_file']['size'];

            // Sanitize filename
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
            $uploadDir = "../uploads/performers/{$id}/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $destPath = $uploadDir . time() . '_' . $safeName;
            $webPath  = "uploads/performers/{$id}/" . time() . '_' . $safeName;

            if (move_uploaded_file($tmpPath, $destPath)) {
                $stmt = $conn->prepare("INSERT INTO performer_files (performer_id, file_type, file_name, file_path, mime_type, file_size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param("issssls", $id, $ftype, $origName, $webPath, $mime, $size, $fby);
                $stmt->execute(); $stmt->close();
                $actionMsg = "File uploaded.";
            } else {
                $actionMsg = "File upload failed.";
            }
        }
    }

    elseif ($action === 'delete_file') {
        $fid = (int)($_POST['file_id'] ?? 0);
        $stmt = $conn->prepare("SELECT file_path FROM performer_files WHERE file_id=? AND performer_id=?");
        $stmt->bind_param("ii", $fid, $id);
        $stmt->execute();
        $frow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($frow) {
            @unlink('../' . $frow['file_path']);
            $d = $conn->prepare("DELETE FROM performer_files WHERE file_id=?");
            $d->bind_param("i", $fid); $d->execute(); $d->close();
        }
        $actionMsg = "File deleted.";
    }

    elseif ($action === 'save_program_defaults') {
        $prog_id = (int)($_POST['program_id'] ?? 0);
        $form_id = (int)($_POST['form_profile_id'] ?? 0);
        if ($prog_id > 0 && $form_id > 0) {
            // Verify program belongs to this performer
            $chk = $conn->prepare("SELECT program_id FROM performer_programs WHERE program_id=? AND performer_id=?");
            $chk->bind_param("ii", $prog_id, $id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $chk->close();
                // Delete existing defaults for this program+form only
                $del = $conn->prepare("DELETE FROM performer_program_default_scores WHERE program_id=? AND form_profile_id=?");
                $del->bind_param("ii", $prog_id, $form_id); $del->execute(); $del->close();
                if (!empty($_POST['defaults']) && is_array($_POST['defaults'])) {
                    $ins = $conn->prepare("INSERT INTO performer_program_default_scores (program_id, form_profile_id, question_id, response_value) VALUES (?, ?, ?, ?)");
                    foreach ($_POST['defaults'] as $qid => $val) {
                        $qid = (int)$qid; $val = (int)$val;
                        if ($qid > 0 && $val >= 0) { // -1 means "no default", skip
                            $ins->bind_param("iiii", $prog_id, $form_id, $qid, $val);
                            $ins->execute();
                        }
                    }
                    $ins->close();
                }
                $actionMsg = "Default scores saved.";
            } else {
                $chk->close();
                $actionMsg = "Permission denied.";
            }
        } elseif ($prog_id > 0 && $form_id <= 0) {
            $actionMsg = "Please select a form before saving defaults.";
        }
    }

    elseif ($action === 'delete_performer') {
        $stmt = $conn->prepare("DELETE FROM performers WHERE performer_id=?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
        $conn->close();
        header("Location: index.php?msg=Performer+deleted");
        exit();
    }
}

// ---- Load all related data ----

// Form profiles (non-bulk only, for program defaults UI)
$formProfiles = [];
$fpRes = $conn->query("SELECT id, name FROM form_profiles WHERE bulk_entry = 0 ORDER BY name ASC");
if ($fpRes) { while ($fp = $fpRes->fetch_assoc()) $formProfiles[(int)$fp['id']] = $fp; }

// Questions per form profile: form_id => [question rows]
$formQuestions = [];
$fqRes = $conn->query("
    SELECT fq.form_id, sq.id, sq.question_text, sq.question_type, sq.points
    FROM form_questions fq
    JOIN scoring_questions sq ON sq.id = fq.question_id
    ORDER BY fq.form_id ASC, sq.id ASC
");
if ($fqRes) { while ($q = $fqRes->fetch_assoc()) $formQuestions[(int)$q['form_id']][] = $q; }

// Scoring options (for multiple_choice questions)
$allOptions = [];
$oRes = $conn->query("SELECT id, question_id, option_text, option_points FROM scoring_options ORDER BY question_id ASC, id ASC");
if ($oRes) { while ($o = $oRes->fetch_assoc()) $allOptions[(int)$o['question_id']][] = $o; }

$contacts = $conn->prepare("SELECT * FROM performer_contacts WHERE performer_id=? ORDER BY is_primary DESC, contact_name");
$contacts->bind_param("i", $id); $contacts->execute();
$contacts = $contacts->get_result()->fetch_all(MYSQLI_ASSOC);

$programs = $conn->prepare("SELECT * FROM performer_programs WHERE performer_id=? ORDER BY program_title");
$programs->bind_param("i", $id); $programs->execute();
$programs = $programs->get_result()->fetch_all(MYSQLI_ASSOC);

// Program default scores: [program_id][form_profile_id][question_id] => response_value
$programDefaults = [];
// Also track which form profiles have defaults saved per program
$programDefaultForms = []; // [program_id] => [form_profile_id, ...]
if (!empty($programs)) {
    $progIds = array_column($programs, 'program_id');
    $placeholders = implode(',', array_fill(0, count($progIds), '?'));
    $types = str_repeat('i', count($progIds));
    $dStmt = $conn->prepare("SELECT program_id, form_profile_id, question_id, response_value FROM performer_program_default_scores WHERE program_id IN ($placeholders)");
    $dStmt->bind_param($types, ...$progIds);
    $dStmt->execute();
    foreach ($dStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $dr) {
        $pid = (int)$dr['program_id']; $fid = (int)$dr['form_profile_id']; $qid = (int)$dr['question_id'];
        $programDefaults[$pid][$fid][$qid] = (int)$dr['response_value'];
        if (!isset($programDefaultForms[$pid]) || !in_array($fid, $programDefaultForms[$pid])) {
            $programDefaultForms[$pid][] = $fid;
        }
    }
    $dStmt->close();
}

// Per-program impact stats from linked scores
$programStats = [];
$psStmt = $conn->prepare("
    SELECT iep.program_id,
           COUNT(*) AS event_count,
           COALESCE(ROUND(AVG(s.total_score), 1), 0) AS avg_score,
           COALESCE(ROUND(AVG(s.adjusted_impact_score), 1), 0) AS avg_impact,
           MAX(s.program_date) AS last_date
    FROM impact_event_performers iep
    JOIN scores s ON s.id = iep.score_id
    WHERE iep.performer_id = ? AND iep.program_id IS NOT NULL
    GROUP BY iep.program_id
");
$psStmt->bind_param("i", $id); $psStmt->execute();
foreach ($psStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $pr) {
    $programStats[(int)$pr['program_id']] = $pr;
}
$psStmt->close();

$bookings = $conn->prepare("SELECT pb.*, pp.program_title FROM performer_bookings pb LEFT JOIN performer_programs pp ON pb.program_id=pp.program_id WHERE pb.performer_id=? ORDER BY pb.event_date DESC LIMIT 20");
$bookings->bind_param("i", $id); $bookings->execute();
$bookings = $bookings->get_result()->fetch_all(MYSQLI_ASSOC);

$reviews = $conn->prepare("SELECT * FROM performer_reviews WHERE performer_id=? ORDER BY review_date DESC");
$reviews->bind_param("i", $id); $reviews->execute();
$reviews = $reviews->get_result()->fetch_all(MYSQLI_ASSOC);

$notes = $conn->prepare("SELECT * FROM performer_notes WHERE performer_id=? ORDER BY created_at DESC");
$notes->bind_param("i", $id); $notes->execute();
$notes = $notes->get_result()->fetch_all(MYSQLI_ASSOC);

$files = $conn->prepare("SELECT * FROM performer_files WHERE performer_id=? ORDER BY uploaded_at DESC");
$files->bind_param("i", $id); $files->execute();
$files = $files->get_result()->fetch_all(MYSQLI_ASSOC);

$payment = $conn->prepare("SELECT * FROM performer_payment_profiles WHERE performer_id=? AND active=1 LIMIT 1");
$payment->bind_param("i", $id); $payment->execute();
$payment = $payment->get_result()->fetch_assoc() ?: [];

$tags = $conn->prepare("SELECT t.tag_name FROM tags t JOIN performer_tag_map ptm ON t.tag_id=ptm.tag_id WHERE ptm.performer_id=? ORDER BY t.tag_name");
$tags->bind_param("i", $id); $tags->execute();
$tags = array_column($tags->get_result()->fetch_all(MYSQLI_ASSOC), 'tag_name');

// ---- Stats tab data ----
// Impact scores linked via impact_event_performers → scores
$st = $conn->prepare("
    SELECT
        COUNT(DISTINCT s.id)                              AS total_events,
        COALESCE(SUM(s.attendance), 0)                   AS total_patrons,
        COALESCE(ROUND(AVG(s.adjusted_impact_score),1),0) AS avg_impact,
        COALESCE(ROUND(MAX(s.adjusted_impact_score),1),0) AS best_impact,
        COALESCE(ROUND(AVG(s.attendance),0),0)            AS avg_attendance,
        COALESCE(ROUND(AVG(s.total_score),1),0)           AS avg_score
    FROM impact_event_performers iep
    JOIN scores s ON s.id = iep.score_id
    WHERE iep.performer_id = ?
");
$st->bind_param("i", $id); $st->execute();
$perf_stats = $st->get_result()->fetch_assoc() ?: [];
$st->close();

// Monthly trend (last 24 months)
$st = $conn->prepare("
    SELECT DATE_FORMAT(s.program_date, '%Y-%m') AS month,
           COUNT(*)                              AS events,
           COALESCE(SUM(s.attendance),0)        AS patrons,
           COALESCE(ROUND(AVG(s.adjusted_impact_score),1),0) AS avg_impact
    FROM impact_event_performers iep
    JOIN scores s ON s.id = iep.score_id
    WHERE iep.performer_id = ?
      AND s.program_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
    GROUP BY month
    ORDER BY month ASC
");
$st->bind_param("i", $id); $st->execute();
$perf_monthly_raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Programs performed with scores
$st = $conn->prepare("
    SELECT s.program,
           COUNT(*)                                        AS times,
           COALESCE(SUM(s.attendance),0)                  AS total_patrons,
           COALESCE(ROUND(AVG(s.adjusted_impact_score),1),0) AS avg_impact,
           MAX(s.program_date)                             AS last_date
    FROM impact_event_performers iep
    JOIN scores s ON s.id = iep.score_id
    WHERE iep.performer_id = ?
    GROUP BY s.program
    ORDER BY avg_impact DESC
    LIMIT 10
");
$st->bind_param("i", $id); $st->execute();
$perf_programs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Recent scored events
$st = $conn->prepare("
    SELECT s.program, s.program_date, s.attendance, s.total_score,
           s.adjusted_impact_score, u.name AS staff_name
    FROM impact_event_performers iep
    JOIN scores s ON s.id = iep.score_id
    LEFT JOIN users u ON u.id = s.user_id
    WHERE iep.performer_id = ?
    ORDER BY s.program_date DESC
    LIMIT 15
");
$st->bind_param("i", $id); $st->execute();
$perf_events = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Build chart arrays
$perf_months = []; $perf_events_chart = []; $perf_patrons_chart = []; $perf_impact_chart = [];
foreach ($perf_monthly_raw as $r) {
    $perf_months[]       = date('M \'y', strtotime($r['month'].'-01'));
    $perf_events_chart[] = (int)$r['events'];
    $perf_patrons_chart[]= (int)$r['patrons'];
    $perf_impact_chart[] = (float)$r['avg_impact'];
}

// SPC data (all scores, chronological)
$st = $conn->prepare("
    SELECT s.adjusted_impact_score AS score, s.program_date
    FROM impact_event_performers iep
    JOIN scores s ON s.id = iep.score_id
    WHERE iep.performer_id = ?
    ORDER BY s.program_date ASC
");
$st->bind_param("i", $id); $st->execute();
$spc_rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$spc_scores = array_column($spc_rows, 'score');
$spc_labels = array_map(fn($r) => date('M j \'y', strtotime($r['program_date'])), $spc_rows);
$spc_mean = count($spc_scores) ? array_sum($spc_scores)/count($spc_scores) : 0;
$spc_sd   = 0;
if (count($spc_scores) > 1) {
    $variance = array_sum(array_map(fn($v) => pow($v-$spc_mean,2), $spc_scores)) / count($spc_scores);
    $spc_sd   = sqrt($variance);
}
$spc_ucl  = round($spc_mean + 3*$spc_sd, 1);
$spc_lcl  = round(max(0, $spc_mean - 3*$spc_sd), 1);
$spc_mean = round($spc_mean, 1);

$conn->close();

// Helper to render star ratings
function stars($rating) {
    if (!$rating) return '<span class="text-muted">—</span>';
    $out = '';
    for ($i=1;$i<=5;$i++) $out .= $i <= $rating ? '&#9733;' : '&#9734;';
    return '<span style="color:#bb1b51;">' . $out . '</span>';
}

$location = implode(', ', array_filter([$p['home_city'], $p['home_state']]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($p['stage_name']) ?> — Performer</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; }
    .btn-custom { background-color: #480d3c; color: #fff; border-color: #480d3c; }
    .btn-custom:hover { background-color: #bb1b51; border-color: #bb1b51; color: #fff; }
    .performer-header { background: #480d3c; color: #fff; border-radius: 10px; padding: 1.5rem; margin-bottom: 1.5rem; }
    .performer-header a { color: #f5e0ef; }
    .nav-tabs .nav-link.active { background-color: #480d3c; color: #fff; border-color: #480d3c; }
    .nav-tabs .nav-link { color: #480d3c; }
    .card-section { background: #fff; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-bottom: 1rem; }
    .badge-status-active { background-color: #198754; }
    .badge-status-inactive { background-color: #6c757d; }
    .badge-status-pending { background-color: #ffc107; color: #000; }
    .badge-status-do_not_book { background-color: #dc3545; }
    .doc-pill { display:inline-block; padding:.2rem .6rem; border-radius:20px; font-size:.8rem; margin:.1rem; }
    .doc-ok { background:#d4edda; color:#155724; }
    .doc-no { background:#f8d7da; color:#721c24; }
    .add-form-toggle { font-size:.85rem; }
  </style>
</head>
<body>
<div class="container mt-4">
  <div class="mb-3">
    <a href="index.php" class="btn btn-secondary btn-sm">&#8592; Directory</a>
    <?php if ($isAdmin): ?>
      <a href="edit.php?id=<?= $id ?>" class="btn btn-custom btn-sm ms-2">Edit Performer</a>
      <a href="booking_edit.php?performer_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm ms-2">+ Add Booking</a>
      <a href="review_edit.php?performer_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm ms-2">+ Add Review</a>
    <?php endif; ?>
  </div>

  <?php if ($actionMsg): ?>
    <div class="alert alert-success alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button><?= htmlspecialchars($actionMsg) ?></div>
  <?php endif; ?>

  <!-- Header -->
  <div class="performer-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <h2 class="mb-1"><?= htmlspecialchars($p['stage_name']) ?></h2>
        <?php if ($p['organization_name']): ?><div class="opacity-75"><?= htmlspecialchars($p['organization_name']) ?></div><?php endif; ?>
        <?php if ($location): ?><div class="small opacity-75">&#128205; <?= htmlspecialchars($location) ?></div><?php endif; ?>
        <?php if ($p['performer_type']): ?><div class="small opacity-75 mt-1">&#127932; <?= htmlspecialchars(ucfirst($p['performer_type'])) ?></div><?php endif; ?>
      </div>
      <div class="text-end">
        <span class="badge badge-status-<?= $p['status'] ?> fs-6"><?= ucfirst(str_replace('_',' ',$p['status'])) ?></span>
        <?php if ($p['average_rating']): ?>
          <div class="mt-1" style="color:#f5c040;"><?= str_repeat('&#9733;', (int)round($p['average_rating'])) ?><?= str_repeat('&#9734;', 5-(int)round($p['average_rating'])) ?></div>
          <div class="small"><?= number_format($p['average_rating'],1) ?> / 5 (<?= $p['total_reviews'] ?> review<?= $p['total_reviews'] != 1 ? 's' : '' ?>)</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($tags): ?>
      <div class="mt-2">
        <?php foreach ($tags as $tag): ?>
          <span class="badge" style="background:rgba(255,255,255,.2);"><?= htmlspecialchars($tag) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="mt-2 d-flex flex-wrap gap-2">
      <?php if ($p['website_url']): ?><a href="<?= htmlspecialchars($p['website_url']) ?>" target="_blank" class="small">&#127760; Website</a><?php endif; ?>
      <?php if ($p['social_facebook']): ?><a href="<?= htmlspecialchars($p['social_facebook']) ?>" target="_blank" class="small">Facebook</a><?php endif; ?>
      <?php if ($p['social_instagram']): ?><a href="<?= htmlspecialchars($p['social_instagram']) ?>" target="_blank" class="small">Instagram</a><?php endif; ?>
      <?php if ($p['social_youtube']): ?><a href="<?= htmlspecialchars($p['social_youtube']) ?>" target="_blank" class="small">YouTube</a><?php endif; ?>
    </div>

    <div class="mt-2 d-flex flex-wrap gap-2">
      <span class="doc-pill <?= $p['insurance_on_file'] ? 'doc-ok' : 'doc-no' ?>">Insurance <?= $p['insurance_on_file'] ? '&#10003;' : '&#10007;' ?></span>
      <span class="doc-pill <?= $p['w9_on_file'] ? 'doc-ok' : 'doc-no' ?>">W-9 <?= $p['w9_on_file'] ? '&#10003;' : '&#10007;' ?></span>
      <span class="doc-pill <?= $p['background_check_on_file'] ? 'doc-ok' : 'doc-no' ?>">Background Check <?= $p['background_check_on_file'] ? '&#10003;' : '&#10007;' ?></span>
      <?php if ($p['virtual_programs_available']): ?><span class="doc-pill doc-ok">Virtual Available</span><?php endif; ?>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3" id="performerTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#overview">Overview</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#contacts">Contacts (<?= count($contacts) ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#programs">Programs (<?= count($programs) ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#bookings">Bookings (<?= count($bookings) ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#reviews">Reviews (<?= count($reviews) ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#notes">Notes (<?= count($notes) ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#files">Files (<?= count($files) ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#stats">Impact Stats</a></li>
    <?php if ($isAdmin): ?><li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#payment">Payment/Admin</a></li><?php endif; ?>
  </ul>

  <div class="tab-content">

    <!-- Overview Tab -->
    <div class="tab-pane fade show active" id="overview">
      <div class="card-section">
        <?php if ($p['short_description']): ?>
          <p class="lead"><?= htmlspecialchars($p['short_description']) ?></p>
        <?php endif; ?>
        <?php if ($p['full_bio']): ?>
          <div><?= nl2br(htmlspecialchars($p['full_bio'])) ?></div>
        <?php else: ?>
          <p class="text-muted">No bio on file.</p>
        <?php endif; ?>
        <?php if ($p['travel_radius_miles']): ?>
          <p class="mt-2 text-muted small">Travel radius: <?= (int)$p['travel_radius_miles'] ?> miles. <?= $p['willing_to_travel'] ? 'Willing to travel.' : 'Local only.' ?></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Contacts Tab -->
    <div class="tab-pane fade" id="contacts">
      <?php foreach ($contacts as $c): ?>
        <div class="card-section d-flex justify-content-between align-items-start">
          <div>
            <strong><?= htmlspecialchars($c['contact_name']) ?></strong>
            <?php if ($c['is_primary']): ?> <span class="badge bg-primary">Primary</span><?php endif; ?>
            <?php if ($c['role']): ?> &mdash; <em><?= htmlspecialchars($c['role']) ?></em><?php endif; ?>
            <?php if ($c['email']): ?><br><a href="mailto:<?= htmlspecialchars($c['email']) ?>"><?= htmlspecialchars($c['email']) ?></a><?php endif; ?>
            <?php if ($c['phone']): ?><br><?= htmlspecialchars($c['phone']) ?><?php endif; ?>
            <?php if ($c['preferred_contact_method']): ?><br><small class="text-muted">Preferred: <?= htmlspecialchars($c['preferred_contact_method']) ?></small><?php endif; ?>
            <?php if ($c['notes']): ?><br><small class="text-muted"><?= htmlspecialchars($c['notes']) ?></small><?php endif; ?>
          </div>
          <?php if ($isAdmin): ?>
            <form method="POST" onsubmit="return confirm('Delete this contact?')">
              <input type="hidden" name="action" value="delete_contact">
              <input type="hidden" name="contact_id" value="<?= $c['contact_id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($contacts)): ?><p class="text-muted">No contacts on file.</p><?php endif; ?>

      <?php if ($isAdmin): ?>
        <div class="card-section mt-2">
          <h6>Add Contact</h6>
          <form method="POST">
            <input type="hidden" name="action" value="add_contact">
            <div class="row g-2">
              <div class="col-md-4"><input type="text" name="contact_name" class="form-control form-control-sm" placeholder="Name *" required></div>
              <div class="col-md-4"><input type="text" name="role" class="form-control form-control-sm" placeholder="Role (performer, manager…)"></div>
              <div class="col-md-4"><input type="email" name="email" class="form-control form-control-sm" placeholder="Email"></div>
              <div class="col-md-3"><input type="text" name="phone" class="form-control form-control-sm" placeholder="Phone"></div>
              <div class="col-md-3">
                <select name="preferred_contact_method" class="form-select form-select-sm">
                  <option value="">Preferred contact</option>
                  <option value="email">Email</option><option value="phone">Phone</option>
                  <option value="text">Text</option><option value="other">Other</option>
                </select>
              </div>
              <div class="col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_primary" id="isp"><label class="form-check-label" for="isp">Primary</label></div></div>
              <div class="col-12"><textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Notes"></textarea></div>
              <div class="col-12"><button type="submit" class="btn btn-custom btn-sm">Add Contact</button></div>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <!-- Programs Tab -->
    <div class="tab-pane fade" id="programs">
      <?php foreach ($programs as $prog): ?>
        <?php
          $pid = (int)$prog['program_id'];
          $pStats = $programStats[$pid] ?? null;
          $savedForms = $programDefaultForms[$pid] ?? [];   // form_profile_ids with saved defaults
          $hasDefaults = !empty($savedForms);
        ?>
        <div class="card-section mb-2">
          <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
              <strong><?= htmlspecialchars($prog['program_title']) ?></strong>
              <?php if (!$prog['active']): ?> <span class="badge bg-secondary">Inactive</span><?php endif; ?>
              <br>
              <span class="badge bg-secondary small"><?= ucfirst(str_replace('_',' ',$prog['audience'])) ?></span>
              <span class="badge bg-secondary small"><?= ucfirst($prog['program_format']) ?></span>
              <?php if ($prog['virtual_available']): ?><span class="badge bg-info small">Virtual OK</span><?php endif; ?>
              <?php if ($prog['duration_minutes']): ?><span class="badge bg-light text-dark small"><?= $prog['duration_minutes'] ?> min</span><?php endif; ?>
              <?php if ($prog['program_description']): ?><p class="mt-1 mb-1 small"><?= htmlspecialchars($prog['program_description']) ?></p><?php endif; ?>
              <?php if ($prog['base_fee'] !== null): ?>
                <small class="text-muted">Base fee: $<?= number_format($prog['base_fee'], 2) ?><?= $prog['travel_fee'] ? ' + $' . number_format($prog['travel_fee'], 2) . ' travel' : '' ?></small>
              <?php endif; ?>

              <?php if ($pStats): ?>
                <div class="mt-2 p-2 rounded" style="background:#f0e8f0; font-size:.82rem;">
                  <strong style="color:#480d3c;">&#128200; Impact Score History:</strong>
                  <?= (int)$pStats['event_count'] ?> event<?= $pStats['event_count'] != 1 ? 's' : '' ?>
                  &bull; Avg score: <strong><?= $pStats['avg_score'] ?></strong>
                  &bull; Avg impact: <strong><?= $pStats['avg_impact'] ?></strong>
                  &bull; Last: <?= htmlspecialchars($pStats['last_date']) ?>
                </div>
              <?php endif; ?>

              <?php if ($hasDefaults): ?>
                <div class="mt-2">
                  <?php foreach ($savedForms as $sfid):
                    $formName    = $formProfiles[$sfid]['name'] ?? "Form #$sfid";
                    $defVals     = $programDefaults[$pid][$sfid] ?? [];
                    $defTotal    = array_sum($defVals);
                    // Count how many questions in this form have a default set
                    $formQCount  = count($formQuestions[$sfid] ?? []);
                    $defCount    = count($defVals);
                  ?>
                  <div class="d-inline-flex align-items-center gap-2 me-2 mb-1 px-2 py-1 rounded"
                       style="background:#eaf5ea;border:1px solid #b6dfb6;font-size:.82rem;">
                    <span style="color:#155724;">&#10003; <?= htmlspecialchars($formName) ?></span>
                    <span class="fw-bold" style="color:#480d3c;font-size:.95rem;"><?= $defTotal ?> pts</span>
                    <span class="text-muted"><?= $defCount ?>/<?= $formQCount ?> questions set</span>
                    <?php if ($isAdmin): ?>
                      <a href="#" class="text-decoration-none text-muted" style="font-size:.78rem;"
                         onclick="toggleDefaults(<?= $pid ?>);document.getElementById('form-sel-<?= $pid ?>').value='<?= $sfid ?>';switchFormQuestions(<?= $pid ?>,'<?= $sfid ?>');return false;">edit</a>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($isAdmin): ?>
                <div class="mt-1">
                  <small><a href="#" class="text-decoration-none text-muted" onclick="toggleDefaults(<?= $pid ?>);return false;">
                    <?= $hasDefaults ? '+ Set defaults for another form' : '+ Set default Impact Scores for this program' ?>
                  </a></small>
                </div>
              <?php endif; ?>
            </div>
            <div class="d-flex flex-column gap-1 align-items-end ms-2">
              <button type="button" class="btn btn-sm btn-custom" onclick="toggleUsePicker(<?= $pid ?>)">&#9654; Use this program</button>
              <?php if ($isAdmin): ?>
                <form method="POST" onsubmit="return confirm('Delete this program?')">
                  <input type="hidden" name="action" value="delete_program">
                  <input type="hidden" name="program_id" value="<?= $pid ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <!-- "Use this program" form picker -->
          <div id="use-picker-<?= $pid ?>" class="mt-2 p-2 rounded" style="display:none;background:#f8f0f8;border:1px solid #dcc;">
            <p class="small fw-bold mb-2" style="color:#480d3c;">Which form would you like to score this program with?</p>
            <?php if (empty($formProfiles)): ?>
              <p class="small text-muted">No scoring forms found.</p>
            <?php else: ?>
              <?php $firstSet = true; foreach ($formProfiles as $fpId => $fp):
                $hasDefForForm = isset($programDefaults[$pid][$fpId]);
              ?>
                <div class="form-check">
                  <input class="form-check-input" type="radio"
                         name="use_form_<?= $pid ?>" value="<?= $fpId ?>"
                         id="uf_<?= $pid ?>_<?= $fpId ?>"
                         <?= ($firstSet && $hasDefForForm) ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="uf_<?= $pid ?>_<?= $fpId ?>">
                    <?= htmlspecialchars($fp['name']) ?>
                    <?php if ($hasDefForForm):
                      $defTotal = array_sum($programDefaults[$pid][$fpId]);
                    ?> <span class="badge" style="background:#480d3c;color:#fff;"><?= $defTotal ?> pts default</span>
                    <?php endif; ?>
                  </label>
                </div>
              <?php $firstSet = $firstSet && !$hasDefForForm; endforeach; ?>
              <div class="mt-2 d-flex gap-2">
                <button type="button" class="btn btn-sm btn-custom"
                  onclick="goToScoreForm(<?= $pid ?>, <?= $id ?>, <?= htmlspecialchars(json_encode($prog['program_title']), ENT_QUOTES) ?>)">
                  Start Scoring &#8594;
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleUsePicker(<?= $pid ?>)">Cancel</button>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($isAdmin && !empty($formProfiles)): ?>
          <!-- Default scores collapse panel -->
          <div id="defaults-panel-<?= $pid ?>" class="mt-3" style="display:none;border-top:1px solid #e0d0e0;padding-top:.75rem;">
            <h6 class="mb-1" style="color:#480d3c;">Default Impact Score Answers for This Program</h6>
            <p class="small text-muted mb-2">Pre-selected when staff submit a score and pick this program. Staff can still adjust any answer.</p>
            <form method="POST">
              <input type="hidden" name="action" value="save_program_defaults">
              <input type="hidden" name="program_id" value="<?= $pid ?>">

              <div class="mb-3">
                <label class="form-label small fw-bold">Select Form to Set Defaults For</label>
                <select class="form-select form-select-sm" name="form_profile_id" id="form-sel-<?= $pid ?>"
                        onchange="switchFormQuestions(<?= $pid ?>, this.value)" style="max-width:320px;">
                  <option value="">— Choose a form —</option>
                  <?php foreach ($formProfiles as $fpId => $fp): ?>
                    <option value="<?= $fpId ?>">
                      <?= htmlspecialchars($fp['name']) ?>
                      <?php if (isset($programDefaultForms[$pid]) && in_array($fpId, $programDefaultForms[$pid])): ?> ✓<?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <?php foreach ($formProfiles as $fpId => $fp):
                $fpQuestions = $formQuestions[$fpId] ?? [];
                if (empty($fpQuestions)) continue;
                $fpDefs = $programDefaults[$pid][$fpId] ?? [];
              ?>
                <div id="fq-group-<?= $pid ?>-<?= $fpId ?>" style="display:none;">
                  <?php foreach ($fpQuestions as $q):
                    $qid = (int)$q['id'];
                    $storedVal = isset($fpDefs[$qid]) ? $fpDefs[$qid] : null;
                    $qType = $q['question_type'] ?? 'binary';
                  ?>
                    <div class="mb-2">
                      <label class="form-label small fw-bold mb-1">
                        <?= htmlspecialchars($q['question_text']) ?>
                        <span class="text-muted fw-normal">(<?= (int)$q['points'] ?> pts)</span>
                      </label>
                      <?php if ($qType === 'binary'): ?>
                        <div class="d-flex gap-3 flex-wrap">
                          <div class="form-check">
                            <input class="form-check-input" type="radio" name="defaults[<?= $qid ?>]"
                              value="<?= (int)$q['points'] ?>" id="def_y_<?= $pid ?>_<?= $fpId ?>_<?= $qid ?>"
                              <?= ($storedVal !== null && $storedVal == $q['points']) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="def_y_<?= $pid ?>_<?= $fpId ?>_<?= $qid ?>">Yes (<?= (int)$q['points'] ?>)</label>
                          </div>
                          <div class="form-check">
                            <input class="form-check-input" type="radio" name="defaults[<?= $qid ?>]"
                              value="0" id="def_n_<?= $pid ?>_<?= $fpId ?>_<?= $qid ?>"
                              <?= ($storedVal !== null && $storedVal == 0) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="def_n_<?= $pid ?>_<?= $fpId ?>_<?= $qid ?>">No</label>
                          </div>
                          <div class="form-check">
                            <input class="form-check-input" type="radio" name="defaults[<?= $qid ?>]"
                              value="-1" id="def_skip_<?= $pid ?>_<?= $fpId ?>_<?= $qid ?>"
                              <?= ($storedVal === null) ? 'checked' : '' ?>>
                            <label class="form-check-label small text-muted" for="def_skip_<?= $pid ?>_<?= $fpId ?>_<?= $qid ?>">No default</label>
                          </div>
                        </div>
                      <?php elseif ($qType === 'multiple_choice' && !empty($allOptions[$qid])): ?>
                        <select class="form-select form-select-sm" name="defaults[<?= $qid ?>]" style="max-width:320px;">
                          <option value="-1" <?= ($storedVal === null) ? 'selected' : '' ?>>— No default —</option>
                          <?php foreach ($allOptions[$qid] as $opt): ?>
                            <option value="<?= (int)$opt['option_points'] ?>"
                              <?= ($storedVal !== null && $storedVal == $opt['option_points']) ? 'selected' : '' ?>>
                              <?= htmlspecialchars($opt['option_text']) ?> (<?= (int)$opt['option_points'] ?>)
                            </option>
                          <?php endforeach; ?>
                        </select>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>

              <div id="defaults-submit-<?= $pid ?>" style="display:none;">
                <div id="defaults-score-preview-<?= $pid ?>" class="mb-2 p-2 rounded d-inline-flex align-items-center gap-2"
                     style="background:#480d3c;color:#fff;font-size:.9rem;">
                  Resulting score: <strong id="defaults-score-val-<?= $pid ?>">0</strong>
                  <span id="defaults-score-note-<?= $pid ?>" class="opacity-75" style="font-size:.8rem;"></span>
                </div>
                <div>
                  <button type="submit" class="btn btn-custom btn-sm">Save Default Scores</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm ms-1" onclick="toggleDefaults(<?= $pid ?>)">Cancel</button>
                </div>
              </div>
              <div id="defaults-no-form-<?= $pid ?>">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleDefaults(<?= $pid ?>)">Cancel</button>
              </div>
            </form>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($programs)): ?><p class="text-muted">No programs on file.</p><?php endif; ?>

      <?php if ($isAdmin): ?>
        <div class="card-section mt-2">
          <h6>Add Program</h6>
          <form method="POST">
            <input type="hidden" name="action" value="add_program">
            <div class="row g-2">
              <div class="col-md-8"><input type="text" name="program_title" class="form-control form-control-sm" placeholder="Program title *" required></div>
              <div class="col-md-4">
                <select name="audience" class="form-select form-select-sm">
                  <option value="all_ages">All Ages</option>
                  <option value="early_learning">Early Learning</option>
                  <option value="kids">Kids</option>
                  <option value="teens">Teens</option>
                  <option value="adults">Adults</option>
                </select>
              </div>
              <div class="col-md-4">
                <select name="program_format" class="form-select form-select-sm">
                  <option value="performance">Performance</option>
                  <option value="workshop">Workshop</option>
                  <option value="lecture">Lecture</option>
                  <option value="interactive">Interactive</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="col-md-2"><input type="number" name="duration_minutes" class="form-control form-control-sm" placeholder="Duration (min)"></div>
              <div class="col-md-2"><input type="number" name="base_fee" step="0.01" class="form-control form-control-sm" placeholder="Base fee $"></div>
              <div class="col-md-2"><input type="number" name="travel_fee" step="0.01" class="form-control form-control-sm" placeholder="Travel fee $"></div>
              <div class="col-md-2 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="virtual_available" id="pvirt"><label class="form-check-label" for="pvirt">Virtual</label></div></div>
              <div class="col-12"><textarea name="program_description" class="form-control form-control-sm" rows="2" placeholder="Description"></textarea></div>
              <div class="col-12"><button type="submit" class="btn btn-custom btn-sm">Add Program</button></div>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <!-- Bookings Tab -->
    <div class="tab-pane fade" id="bookings">
      <?php if ($isAdmin): ?>
        <div class="mb-2"><a href="booking_edit.php?performer_id=<?= $id ?>" class="btn btn-custom btn-sm">+ New Booking</a></div>
      <?php endif; ?>
      <?php if (empty($bookings)): ?>
        <p class="text-muted">No bookings on file.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm bg-white">
            <thead style="background:#480d3c; color:#fff;"><tr><th>Date</th><th>Event</th><th>Branch</th><th>Program</th><th>Fee</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($bookings as $b): ?>
                <tr>
                  <td><?= htmlspecialchars($b['event_date']) ?></td>
                  <td><?= htmlspecialchars($b['event_title']) ?></td>
                  <td><?= htmlspecialchars($b['branch_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($b['program_title'] ?? '—') ?></td>
                  <td><?= $b['total_cost'] !== null ? '$' . number_format($b['total_cost'], 2) : '—' ?></td>
                  <td><span class="badge bg-secondary"><?= ucfirst($b['booking_status']) ?></span></td>
                  <td><a href="booking_view.php?id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Reviews Tab -->
    <div class="tab-pane fade" id="reviews">
      <?php if ($isAdmin): ?>
        <div class="mb-2"><a href="review_edit.php?performer_id=<?= $id ?>" class="btn btn-custom btn-sm">+ Add Review</a></div>
      <?php endif; ?>
      <?php if (empty($reviews)): ?>
        <p class="text-muted">No reviews yet.</p>
      <?php else: ?>
        <?php foreach ($reviews as $r): ?>
          <div class="card-section">
            <div class="d-flex justify-content-between">
              <div>
                <strong><?= htmlspecialchars($r['reviewer_name']) ?></strong> &mdash; <?= htmlspecialchars($r['review_date']) ?>
                <?php if ($r['would_book_again']): ?><span class="badge bg-success ms-2">Would book again</span><?php else: ?><span class="badge bg-warning ms-2 text-dark">Would not book again</span><?php endif; ?>
              </div>
              <div><?= stars($r['rating_overall']) ?> Overall</div>
            </div>
            <div class="row mt-2 small text-muted">
              <div class="col-md-3">Professionalism: <?= stars($r['rating_professionalism']) ?></div>
              <div class="col-md-3">Engagement: <?= stars($r['rating_engagement']) ?></div>
              <div class="col-md-3">Value: <?= stars($r['rating_value']) ?></div>
              <div class="col-md-3">Audience Response: <?= stars($r['rating_audience_response']) ?></div>
            </div>
            <?php if ($r['strengths']): ?><p class="mt-2 mb-0"><strong>Strengths:</strong> <?= htmlspecialchars($r['strengths']) ?></p><?php endif; ?>
            <?php if ($r['concerns']): ?><p class="mb-0"><strong>Concerns:</strong> <?= htmlspecialchars($r['concerns']) ?></p><?php endif; ?>
            <?php if ($r['public_notes']): ?><p class="mb-0 small"><?= htmlspecialchars($r['public_notes']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Notes Tab -->
    <div class="tab-pane fade" id="notes">
      <?php foreach ($notes as $n): ?>
        <div class="card-section d-flex justify-content-between align-items-start">
          <div>
            <span class="badge bg-secondary"><?= ucfirst($n['note_type']) ?></span>
            <?php if ($n['visibility'] === 'admin_only'): ?><span class="badge bg-warning text-dark">Admin Only</span><?php endif; ?>
            <?php if ($n['entered_by']): ?><small class="text-muted ms-2"><?= htmlspecialchars($n['entered_by']) ?></small><?php endif; ?>
            <small class="text-muted ms-2"><?= date('M j, Y', strtotime($n['created_at'])) ?></small>
            <p class="mt-1 mb-0"><?= nl2br(htmlspecialchars($n['note_text'])) ?></p>
          </div>
          <?php if ($isAdmin): ?>
            <form method="POST" onsubmit="return confirm('Delete this note?')">
              <input type="hidden" name="action" value="delete_note">
              <input type="hidden" name="note_id" value="<?= $n['note_id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($notes)): ?><p class="text-muted">No notes on file.</p><?php endif; ?>

      <?php if ($isAdmin): ?>
        <div class="card-section mt-2">
          <h6>Add Note</h6>
          <form method="POST">
            <input type="hidden" name="action" value="add_note">
            <div class="row g-2">
              <div class="col-md-3">
                <select name="note_type" class="form-select form-select-sm">
                  <option value="general">General</option><option value="booking">Booking</option>
                  <option value="payment">Payment</option><option value="behavior">Behavior</option>
                  <option value="accessibility">Accessibility</option><option value="other">Other</option>
                </select>
              </div>
              <div class="col-md-3">
                <select name="visibility" class="form-select form-select-sm">
                  <option value="internal">Internal</option>
                  <option value="admin_only">Admin Only</option>
                </select>
              </div>
              <div class="col-md-3"><input type="text" name="entered_by" class="form-control form-control-sm" placeholder="Your name"></div>
              <div class="col-12"><textarea name="note_text" class="form-control form-control-sm" rows="3" placeholder="Note text *" required></textarea></div>
              <div class="col-12"><button type="submit" class="btn btn-custom btn-sm">Add Note</button></div>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <!-- Files Tab -->
    <div class="tab-pane fade" id="files">
      <?php if (empty($files)): ?><p class="text-muted">No files uploaded.</p><?php endif; ?>
      <?php foreach ($files as $f): ?>
        <div class="card-section d-flex justify-content-between align-items-center">
          <div>
            <span class="badge bg-secondary"><?= ucfirst($f['file_type']) ?></span>
            <a href="../<?= htmlspecialchars($f['file_path']) ?>" target="_blank"><?= htmlspecialchars($f['file_name']) ?></a>
            <small class="text-muted ms-2"><?= $f['file_size_bytes'] ? round($f['file_size_bytes']/1024) . ' KB' : '' ?></small>
            <?php if ($f['uploaded_by']): ?><small class="text-muted ms-2">by <?= htmlspecialchars($f['uploaded_by']) ?></small><?php endif; ?>
            <small class="text-muted ms-2"><?= date('M j, Y', strtotime($f['uploaded_at'])) ?></small>
          </div>
          <?php if ($isAdmin): ?>
            <form method="POST" onsubmit="return confirm('Delete this file?')">
              <input type="hidden" name="action" value="delete_file">
              <input type="hidden" name="file_id" value="<?= $f['file_id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($isAdmin): ?>
        <div class="card-section mt-2">
          <h6>Upload File</h6>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_file">
            <div class="row g-2">
              <div class="col-md-3">
                <select name="file_type" class="form-select form-select-sm">
                  <option value="other">Other</option><option value="contract">Contract</option>
                  <option value="w9">W-9</option><option value="insurance">Insurance</option>
                  <option value="photo">Photo</option><option value="promo">Promo</option>
                  <option value="invoice">Invoice</option>
                </select>
              </div>
              <div class="col-md-3"><input type="text" name="uploaded_by" class="form-control form-control-sm" placeholder="Uploaded by"></div>
              <div class="col-md-4"><input type="file" name="performer_file" class="form-control form-control-sm" required></div>
              <div class="col-md-2"><button type="submit" class="btn btn-custom btn-sm w-100">Upload</button></div>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <!-- Payment/Admin Tab (admin only) -->
    <?php if ($isAdmin): ?>
    <div class="tab-pane fade" id="payment">
      <div class="card-section">
        <form method="POST">
          <input type="hidden" name="action" value="save_payment">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Payee Name</label>
              <input type="text" name="payee_name" class="form-control" value="<?= htmlspecialchars($payment['payee_name'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Payment Method</label>
              <select name="payment_method" class="form-select">
                <?php foreach (['check','ach','direct_deposit','invoice','other'] as $m): ?>
                  <option value="<?= $m ?>" <?= ($payment['payment_method'] ?? 'check') === $m ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$m)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Payment Terms</label>
              <input type="text" name="payment_terms" class="form-control" placeholder="e.g. Net 30" value="<?= htmlspecialchars($payment['payment_terms'] ?? '') ?>">
            </div>
            <div class="col-md-4 d-flex gap-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="requires_po" id="req_po" <?= ($payment['requires_po'] ?? 0) ? 'checked' : '' ?>>
                <label class="form-check-label" for="req_po">Requires PO</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="requires_contract" id="req_ct" <?= ($payment['requires_contract'] ?? 0) ? 'checked' : '' ?>>
                <label class="form-check-label" for="req_ct">Requires Contract</label>
              </div>
            </div>
            <div class="col-12"><button type="submit" class="btn btn-custom">Save Payment Info</button></div>
          </div>
        </form>
      </div>

      <?php if ($isAdmin): ?>
        <div class="card-section mt-3 border border-danger">
          <h6 class="text-danger">Danger Zone</h6>
          <form method="POST" onsubmit="return confirm('Permanently delete this performer and all related data? This cannot be undone.');">
            <input type="hidden" name="action" value="delete_performer">
            <button type="submit" class="btn btn-danger btn-sm">Delete Performer</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stats Tab -->
    <div class="tab-pane fade" id="stats">
      <?php if (empty($perf_events)): ?>
        <div class="card-section text-muted">No impact score data linked to this performer yet.</div>
      <?php else: ?>

      <?php
        // Score pill helper
        function scorePill($v, $ucl, $lcl) {
            if ($v >= $ucl) $cls = '#198754';
            elseif ($v <= $lcl && $lcl > 0) $cls = '#dc3545';
            else $cls = '#e6a817';
            return "<span style='background:{$cls};color:#fff;padding:.15rem .55rem;border-radius:20px;font-size:.82rem;font-weight:600;'>" . number_format($v,1) . "</span>";
        }
      ?>

      <!-- Stat cards -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="card-section text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#480d3c;"><?= (int)$perf_stats['total_events'] ?></div>
            <div class="text-muted small text-uppercase">Scored Events</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card-section text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#480d3c;"><?= number_format((int)$perf_stats['total_patrons']) ?></div>
            <div class="text-muted small text-uppercase">Total Patrons</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card-section text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#480d3c;"><?= number_format((float)$perf_stats['avg_impact'],1) ?></div>
            <div class="text-muted small text-uppercase">Avg Impact Score</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card-section text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#480d3c;"><?= number_format((int)$perf_stats['avg_attendance']) ?></div>
            <div class="text-muted small text-uppercase">Avg Attendance</div>
          </div>
        </div>
      </div>

      <?php if (count($spc_scores) >= 2): ?>
      <!-- SPC Control Chart -->
      <div class="card-section mb-3">
        <h6 class="fw-bold mb-3">Impact Score Control Chart</h6>
        <p class="text-muted small mb-2">
          Mean: <strong><?= $spc_mean ?></strong> &nbsp;|&nbsp;
          UCL: <strong><?= $spc_ucl ?></strong> &nbsp;|&nbsp;
          LCL: <strong><?= $spc_lcl ?></strong>
        </p>
        <canvas id="spcChart" height="90"></canvas>
      </div>
      <?php endif; ?>

      <?php if (!empty($perf_monthly_raw)): ?>
      <!-- Monthly activity chart -->
      <div class="card-section mb-3">
        <h6 class="fw-bold mb-3">Monthly Activity (Last 24 Months)</h6>
        <canvas id="monthlyChart" height="90"></canvas>
      </div>
      <?php endif; ?>

      <div class="row g-3">
        <!-- Top Programs -->
        <?php if (!empty($perf_programs)): ?>
        <div class="col-md-6">
          <div class="card-section h-100">
            <h6 class="fw-bold mb-3">Programs Performed</h6>
            <?php $maxImp = max(array_column($perf_programs, 'avg_impact')) ?: 1; ?>
            <?php foreach ($perf_programs as $pp): ?>
              <div class="mb-2">
                <div class="d-flex justify-content-between">
                  <span class="small fw-500"><?= htmlspecialchars($pp['program']) ?></span>
                  <span class="small text-muted"><?= number_format((float)$pp['avg_impact'],1) ?></span>
                </div>
                <div style="background:#e9ecef;border-radius:4px;height:6px;">
                  <div style="background:#480d3c;border-radius:4px;height:6px;width:<?= round($pp['avg_impact']/$maxImp*100) ?>%"></div>
                </div>
                <div class="text-muted" style="font-size:.72rem;">
                  <?= (int)$pp['times'] ?> time<?= $pp['times']!=1?'s':'' ?>
                  &nbsp;&middot;&nbsp; <?= number_format((int)$pp['total_patrons']) ?> patrons
                  &nbsp;&middot;&nbsp; Last: <?= date('M j, Y', strtotime($pp['last_date'])) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Recent events -->
        <div class="col-md-6">
          <div class="card-section h-100">
            <h6 class="fw-bold mb-3">Recent Scored Events</h6>
            <table class="table table-sm table-borderless mb-0">
              <thead><tr>
                <th class="text-muted small text-uppercase">Program</th>
                <th class="text-muted small text-uppercase">Date</th>
                <th class="text-muted small text-uppercase">Att.</th>
                <th class="text-muted small text-uppercase">Impact</th>
              </tr></thead>
              <tbody>
              <?php foreach ($perf_events as $ev): ?>
                <tr>
                  <td class="small"><?= htmlspecialchars($ev['program']) ?></td>
                  <td class="small text-muted"><?= date('M j, Y', strtotime($ev['program_date'])) ?></td>
                  <td class="small"><?= (int)$ev['attendance'] ?></td>
                  <td><?= scorePill((float)$ev['adjusted_impact_score'], $spc_ucl, $spc_lcl) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php endif; ?>
    </div><!-- end #stats -->

  </div><!-- end tab-content -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php if (isset($_GET['tab'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var tab = document.querySelector('[href="#<?= htmlspecialchars($_GET['tab']) ?>"]');
  if (tab) new bootstrap.Tab(tab).show();
});
</script>
<?php endif; ?>

<?php if (!empty($perf_events)): ?>
<script>
const PLUM   = '#480d3c';
const FUCHSIA= '#bb1b51';

<?php if (count($spc_scores) >= 2): ?>
// SPC Chart — initialise only when tab becomes visible
(function() {
  const labels = <?= json_encode($spc_labels) ?>;
  const scores = <?= json_encode(array_map('floatval', $spc_scores)) ?>;
  const mean   = <?= $spc_mean ?>;
  const ucl    = <?= $spc_ucl ?>;
  const lcl    = <?= $spc_lcl ?>;

  function buildSpc() {
    const ctx = document.getElementById('spcChart');
    if (!ctx || ctx._chartBuilt) return;
    ctx._chartBuilt = true;
    new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Impact Score', data: scores, borderColor: PLUM, backgroundColor: 'rgba(72,13,60,.1)', tension:.3, pointRadius:4, fill:true },
          { label: 'UCL',  data: Array(scores.length).fill(ucl),  borderColor:'#dc3545', borderDash:[6,3], pointRadius:0 },
          { label: 'Mean', data: Array(scores.length).fill(mean), borderColor:'#6c757d', borderDash:[4,4], pointRadius:0 },
          { label: 'LCL',  data: Array(scores.length).fill(lcl),  borderColor:'#0d6efd', borderDash:[6,3], pointRadius:0 },
        ]
      },
      options: { responsive:true, plugins:{ legend:{ position:'top' } }, scales:{ y:{ beginAtZero:false } } }
    });
  }

  // Build when tab is first shown
  document.querySelector('[href="#stats"]').addEventListener('shown.bs.tab', buildSpc);
  // Also build if already on stats tab on load
  document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash === '#stats') buildSpc();
  });
})();
<?php endif; ?>

<?php if (!empty($perf_monthly_raw)): ?>
(function() {
  const labels  = <?= json_encode($perf_months) ?>;
  const events  = <?= json_encode($perf_events_chart) ?>;
  const patrons = <?= json_encode($perf_patrons_chart) ?>;

  function buildMonthly() {
    const ctx = document.getElementById('monthlyChart');
    if (!ctx || ctx._chartBuilt) return;
    ctx._chartBuilt = true;
    new Chart(ctx, {
      data: {
        labels,
        datasets: [
          { type:'bar',  label:'Events',  data:events,  backgroundColor:'rgba(72,13,60,.7)', yAxisID:'yL' },
          { type:'line', label:'Patrons', data:patrons, borderColor:FUCHSIA, backgroundColor:'transparent', tension:.3, pointRadius:4, yAxisID:'yR' }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend:{ position:'top' } },
        scales: {
          yL: { type:'linear', position:'left',  title:{ display:true, text:'Events' },  beginAtZero:true, ticks:{ stepSize:1 } },
          yR: { type:'linear', position:'right', title:{ display:true, text:'Patrons' }, beginAtZero:true, grid:{ drawOnChartArea:false } }
        }
      }
    });
  }

  document.querySelector('[href="#stats"]').addEventListener('shown.bs.tab', buildMonthly);
  document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash === '#stats') buildMonthly();
  });
})();
<?php endif; ?>
</script>
<?php endif; ?>
<script>
function toggleUsePicker(programId) {
  const div = document.getElementById('use-picker-' + programId);
  if (div) div.style.display = div.style.display === 'none' ? 'block' : 'none';
}

function goToScoreForm(programId, performerId, programName) {
  const checked = document.querySelector('input[name="use_form_' + programId + '"]:checked');
  if (!checked) { alert('Please select a form first.'); return; }
  const url = '../value_score_form.php'
    + '?prefill_form_id='      + encodeURIComponent(checked.value)
    + '&prefill_performer_id=' + encodeURIComponent(performerId)
    + '&prefill_program_id='   + encodeURIComponent(programId)
    + '&prefill_program_name=' + encodeURIComponent(programName);
  window.location.href = url;
}

function toggleDefaults(programId) {
  const panel = document.getElementById('defaults-panel-' + programId);
  if (!panel) return;
  const isHidden = panel.style.display === 'none';
  panel.style.display = isHidden ? 'block' : 'none';
  if (isHidden) {
    // Reset form selector back to blank when opening
    const sel = document.getElementById('form-sel-' + programId);
    if (sel) { sel.value = ''; switchFormQuestions(programId, ''); }
  }
}

function switchFormQuestions(programId, formId) {
  // Hide all question groups for this program
  document.querySelectorAll('[id^="fq-group-' + programId + '-"]').forEach(el => el.style.display = 'none');

  const submitDiv = document.getElementById('defaults-submit-' + programId);
  const noFormDiv = document.getElementById('defaults-no-form-' + programId);

  if (!formId) {
    if (submitDiv) submitDiv.style.display = 'none';
    if (noFormDiv) noFormDiv.style.display = 'block';
    return;
  }

  const group = document.getElementById('fq-group-' + programId + '-' + formId);
  if (group) {
    group.style.display = 'block';
    // Attach live score listeners to all inputs in this group
    group.querySelectorAll('input[type="radio"], select').forEach(el => {
      el.removeEventListener('change', el._scoreHandler);
      el._scoreHandler = () => updateDefaultsScore(programId, formId);
      el.addEventListener('change', el._scoreHandler);
    });
    updateDefaultsScore(programId, formId);
  }
  if (submitDiv) submitDiv.style.display = 'block';
  if (noFormDiv) noFormDiv.style.display = 'none';
}

function updateDefaultsScore(programId, formId) {
  const group = document.getElementById('fq-group-' + programId + '-' + formId);
  const valEl = document.getElementById('defaults-score-val-' + programId);
  const noteEl = document.getElementById('defaults-score-note-' + programId);
  if (!group || !valEl) return;

  let total = 0;
  let unanswered = 0;
  let answered = 0;

  // Find each question by its unique name pattern within this group
  const seen = new Set();
  group.querySelectorAll('input[type="radio"], select').forEach(el => {
    const name = el.name; // "defaults[N]"
    if (seen.has(name)) return;

    if (el.tagName === 'SELECT') {
      seen.add(name);
      const v = parseInt(el.value, 10);
      if (v >= 0) { total += v; answered++; }
      else unanswered++;
    } else {
      // Radio group — only process once per name
      seen.add(name);
      const checked = group.querySelector('input[name="' + name + '"]:checked');
      if (checked) {
        const v = parseInt(checked.value, 10);
        if (v >= 0) { total += v; answered++; }
        else unanswered++; // -1 = no default
      } else {
        unanswered++;
      }
    }
  });

  valEl.textContent = total;
  if (noteEl) {
    noteEl.textContent = unanswered > 0
      ? '(' + unanswered + ' question' + (unanswered > 1 ? 's' : '') + ' left at "no default")'
      : '(all questions have a default)';
  }
}
</script>
</body>
</html>
