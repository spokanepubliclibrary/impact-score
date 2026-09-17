<?php
/**
 * my_dashboard.php — Personal Staff Dashboard
 * Requires user login. Session-based identity.
 */

session_start();

// ── Auth guard ─────────────────────────────────────────────────────────────
if (empty($_SESSION['user_logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: user_login.php?redirect=my_dashboard.php');
    exit;
}

require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$current_user_id = (int)$_SESSION['user_id'];

// ── Handle profile update ──────────────────────────────────────────────────
$profile_error   = '';
$profile_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $new_name  = trim($_POST['profile_name']  ?? '');
    $new_email = trim($_POST['profile_email'] ?? '');

    if (!$new_name) {
        $profile_error = 'Name cannot be empty.';
    } elseif ($new_email && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $profile_error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
        $stmt->bind_param('ssi', $new_name, $new_email, $current_user_id);
        $stmt->execute();
        $stmt->close();
        // Refresh session name
        $_SESSION['user_name']  = $new_name;
        $_SESSION['user_email'] = $new_email;
        $profile_success = 'Profile updated.';
    }
}

// ── Handle "Use Again" quick-start ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_start') {
    $pname   = trim($_POST['program_name'] ?? '');
    $team_id = (int)($_POST['team_id'] ?? 0);
    $form_id = (int)($_POST['form_profile_id'] ?? 0);

    if ($pname !== '') {
        $_SESSION['program_name'] = $pname;
        $_SESSION['program_date'] = '';
    }
    if ($team_id > 0)  $_SESSION['team'] = $team_id;
    if ($form_id > 0)  $_SESSION['form'] = $form_id;

    $_SESSION['users'] = [$current_user_id];
    $_SESSION['user']  = $current_user_id;

    $prefill_sid = (int)($_POST['prefill_score_id'] ?? 0);
    if ($prefill_sid > 0) {
        $_SESSION['prefill_score_id'] = $prefill_sid;
    } else {
        unset($_SESSION['prefill_score_id']);
    }
    header('Location: value_score_form.php');
    exit;
}

// ── Data variables ─────────────────────────────────────────────────────────
$user_name      = '';
$user_email_val = '';
$stats          = [];
$chart_data     = [];
$top_programs   = [];
$recent         = [];
$monthly        = [];
$frequent       = [];
$by_location    = [];
$team_avg       = 0;
$personal_best  = null;

// Validate user still exists in DB
$st = $conn->prepare("SELECT name, email FROM users WHERE id = ? AND account_active = 1");
$st->bind_param("i", $current_user_id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
    // Account deleted or deactivated — force logout
    header('Location: user_logout.php');
    exit;
}

$user_name      = $row['name'];
$user_email_val = $row['email'] ?? '';

// Keep session name in sync
$_SESSION['user_name']  = $user_name;
$_SESSION['user_email'] = $user_email_val;

if ($current_user_id > 0) {
    // (always true at this point — kept for block structure)
    if (true) {

        // Helper: the WHERE clause that matches scores where the user is primary OR a contributor.
        // Used in every query below so secondary contributors see their entries too.
        $mine = "(user_id = ? OR id IN (SELECT score_id FROM score_users WHERE user_id = ?))";
        $mine_s = "(s.user_id = ? OR s.id IN (SELECT score_id FROM score_users WHERE user_id = ?))";
        $uid2 = [$current_user_id, $current_user_id]; // convenience — always bind these two together

        // ── Overview stats ────────────────────────────────────────────────
        $st = $conn->prepare("
            SELECT
                COUNT(*)                                                       AS total_programs,
                COALESCE(SUM(attendance), 0)                                   AS total_patrons,
                COALESCE(ROUND(AVG(adjusted_impact_score),1), 0)               AS avg_impact,
                COALESCE(ROUND(MAX(adjusted_impact_score),1), 0)               AS best_score,
                COALESCE(ROUND(AVG(attendance),0), 0)                          AS avg_attendance,
                COALESCE(SUM(CASE
                    WHEN program_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN 1 ELSE 0
                END), 0)                                                        AS programs_this_month,
                COALESCE(SUM(CASE
                    WHEN program_date >= DATE_FORMAT(
                            DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m-01')
                     AND program_date <  DATE_FORMAT(CURDATE(),'%Y-%m-01')
                    THEN 1 ELSE 0
                END), 0)                                                        AS programs_last_month,
                COALESCE(SUM(CASE
                    WHEN program_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN attendance ELSE 0
                END), 0)                                                        AS patrons_this_month,
                COALESCE(SUM(CASE
                    WHEN program_date >= DATE_FORMAT(
                            DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m-01')
                     AND program_date <  DATE_FORMAT(CURDATE(),'%Y-%m-01')
                    THEN attendance ELSE 0
                END), 0)                                                        AS patrons_last_month,
                COALESCE(SUM(CASE
                    WHEN YEAR(program_date) = YEAR(CURDATE()) THEN 1 ELSE 0
                END), 0)                                                        AS programs_ytd,
                COALESCE(SUM(CASE
                    WHEN YEAR(program_date) = YEAR(CURDATE()) THEN attendance ELSE 0
                END), 0)                                                        AS patrons_ytd
            FROM scores
            WHERE $mine AND program_date IS NOT NULL
        ");
        $st->bind_param("ii", ...$uid2);
        $st->execute();
        $stats = $st->get_result()->fetch_assoc();
        $st->close();

        // ── Personal best program ─────────────────────────────────────────
        $st = $conn->prepare("
            SELECT program, program_date, total_score, attendance, adjusted_impact_score
            FROM scores
            WHERE $mine AND adjusted_impact_score IS NOT NULL
            ORDER BY adjusted_impact_score DESC
            LIMIT 1
        ");
        $st->bind_param("ii", ...$uid2);
        $st->execute();
        $personal_best = $st->get_result()->fetch_assoc();
        $st->close();

        // ── Chart data (last 24 months) ───────────────────────────────────
        $st = $conn->prepare("
            SELECT program_date, adjusted_impact_score, program, total_score, attendance
            FROM scores
            WHERE $mine
              AND program_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
              AND program_date IS NOT NULL
              AND adjusted_impact_score > 0
            ORDER BY program_date ASC
        ");
        $st->bind_param("ii", ...$uid2);
        $st->execute();
        $res2 = $st->get_result();
        while ($r = $res2->fetch_assoc()) $chart_data[] = $r;
        $st->close();

        // ── Top 5 programs ────────────────────────────────────────────────
        $st = $conn->prepare("
            SELECT program, program_date, total_score, attendance, adjusted_impact_score
            FROM scores
            WHERE $mine AND adjusted_impact_score IS NOT NULL
            ORDER BY adjusted_impact_score DESC
            LIMIT 5
        ");
        $st->bind_param("ii", ...$uid2);
        $st->execute();
        $res2 = $st->get_result();
        while ($r = $res2->fetch_assoc()) $top_programs[] = $r;
        $st->close();

        // ── Recent 10 submissions ─────────────────────────────────────────
        $st = $conn->prepare("
            SELECT s.id, s.program, s.program_date, s.total_score,
                   s.attendance, s.adjusted_impact_score,
                   l.name AS location_name
            FROM scores s
            LEFT JOIN locations l ON l.id = s.location_id
            WHERE $mine_s
            ORDER BY s.program_date DESC, s.id DESC
            LIMIT 10
        ");
        $st->bind_param("ii", ...$uid2);
        $st->execute();
        $res2 = $st->get_result();
        while ($r = $res2->fetch_assoc()) $recent[] = $r;
        $st->close();

        // ── Monthly breakdown (last 13 months) ───────────────────────────
        $st = $conn->prepare("
            SELECT DATE_FORMAT(program_date,'%Y-%m') AS month,
                   COUNT(*)                           AS cnt,
                   COALESCE(SUM(attendance),0)        AS patrons,
                   COALESCE(ROUND(AVG(adjusted_impact_score),1),0) AS avg_impact
            FROM scores
            WHERE $mine
              AND program_date >= DATE_SUB(CURDATE(), INTERVAL 13 MONTH)
            GROUP BY DATE_FORMAT(program_date,'%Y-%m')
            ORDER BY month ASC
        ");
        $st->bind_param("ii", ...$uid2);
        $st->execute();
        $res2 = $st->get_result();
        while ($r = $res2->fetch_assoc()) $monthly[$r['month']] = $r;
        $st->close();

        // ── Most-used programs (for quick re-submit) ──────────────────────
        // Each subquery also uses the contributor-aware filter, so they pick up
        // team/form/score from any entry the user was part of, not just primary.
        $st = $conn->prepare("
            SELECT s.program,
                   COUNT(*)                                       AS times_run,
                   MAX(s.program_date)                            AS last_run,
                   ROUND(AVG(s.adjusted_impact_score),1)          AS avg_impact,
                   ROUND(AVG(s.attendance),0)                     AS avg_attendance,
                   (SELECT s2.team_id FROM scores s2
                    WHERE (s2.user_id = ? OR s2.id IN (SELECT score_id FROM score_users WHERE user_id = ?))
                      AND s2.program = s.program
                    ORDER BY s2.id DESC LIMIT 1)                  AS team_id,
                   (SELECT s3.form_id
                    FROM scores s3
                    WHERE (s3.user_id = ? OR s3.id IN (SELECT score_id FROM score_users WHERE user_id = ?))
                      AND s3.program = s.program
                    ORDER BY s3.id DESC LIMIT 1)                  AS form_profile_id,
                   (SELECT s4.id
                    FROM scores s4
                    WHERE (s4.user_id = ? OR s4.id IN (SELECT score_id FROM score_users WHERE user_id = ?))
                      AND s4.program = s.program
                    ORDER BY s4.id DESC LIMIT 1)                  AS latest_score_id
            FROM scores s
            WHERE $mine_s
            GROUP BY s.program
            ORDER BY times_run DESC, last_run DESC
            LIMIT 8
        ");
        $st->bind_param(
            "iiiiiiii",
            $current_user_id, $current_user_id,  // subquery: team_id
            $current_user_id, $current_user_id,  // subquery: form_profile_id
            $current_user_id, $current_user_id,  // subquery: latest_score_id
            $current_user_id, $current_user_id   // outer WHERE
        );
        $st->execute();
        $res2 = $st->get_result();
        while ($r = $res2->fetch_assoc()) $frequent[] = $r;
        $st->close();

        // ── Programs by location ──────────────────────────────────────────
        $st = $conn->prepare("
            SELECT COALESCE(l.name,'Unknown') AS loc, COUNT(*) AS cnt
            FROM scores s
            LEFT JOIN locations l ON l.id = s.location_id
            WHERE $mine_s
            GROUP BY l.name
            ORDER BY cnt DESC
            LIMIT 6
        ");
        $st->bind_param("ii", ...$uid2);
        $st->execute();
        $res2 = $st->get_result();
        while ($r = $res2->fetch_assoc()) $by_location[] = $r;
        $st->close();

        // ── Team average ──────────────────────────────────────────────────
        $tidrow = $conn->query("
            SELECT team_id FROM scores
            WHERE (user_id = {$current_user_id}
                   OR id IN (SELECT score_id FROM score_users WHERE user_id = {$current_user_id}))
              AND team_id IS NOT NULL
            LIMIT 1
        ")->fetch_assoc();
        if ($tidrow) {
            $tid = (int)$tidrow['team_id'];
            $st = $conn->prepare("SELECT ROUND(AVG(adjusted_impact_score),1) AS avg FROM scores WHERE team_id = ? AND adjusted_impact_score > 0");
            $st->bind_param("i", $tid);
            $st->execute();
            $trow = $st->get_result()->fetch_assoc();
            $st->close();
            $team_avg = (float)($trow['avg'] ?? 0);
        }
    }
}

// ── SPC calculation ────────────────────────────────────────────────────────
$scores_arr = array_column($chart_data, 'adjusted_impact_score');
$n          = count($scores_arr);
$spc_mean   = $n > 0 ? array_sum($scores_arr) / $n : 0;
$variance   = 0;
if ($n > 1) {
    foreach ($scores_arr as $v) $variance += ($v - $spc_mean) ** 2;
    $variance /= ($n - 1);
}
$sigma    = sqrt($variance);
$spc_ucl  = $spc_mean + 3 * $sigma;
$spc_lcl  = max(0, $spc_mean - 3 * $sigma);

// ── Last 12 months for bar chart ───────────────────────────────────────────
$last12 = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $last12[$m] = array_merge(
        ['cnt' => 0, 'patrons' => 0, 'avg_impact' => 0],
        $monthly[$m] ?? []
    );
    $last12[$m]['label'] = date("M 'y", strtotime($m . '-01'));
}

// ── Active-month streak ────────────────────────────────────────────────────
$streak = 0;
$check  = date('Y-m');
while (isset($monthly[$check]) && $monthly[$check]['cnt'] > 0) {
    $streak++;
    $check = date('Y-m', strtotime($check . '-01 -1 month'));
}

// ── Helpers ────────────────────────────────────────────────────────────────
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function greet(): string {
    $h = (int)date('H');
    if ($h < 12) return 'Good morning';
    if ($h < 17) return 'Good afternoon';
    return 'Good evening';
}

function pctChange($current, $prev): array {
    if ($prev == 0) return $current > 0 ? ['up', '+' . $current] : ['neutral', '—'];
    $pct = round((($current - $prev) / $prev) * 100);
    if ($pct > 0)  return ['up',   "+{$pct}%"];
    if ($pct < 0)  return ['down', "{$pct}%"];
    return ['neutral', '0%'];
}

$my_avg  = (float)($stats['avg_impact'] ?? 0);
$vs_team = $team_avg > 0 ? round((($my_avg - $team_avg) / $team_avg) * 100, 1) : null;

$mon_prog_trend  = pctChange($stats['programs_this_month'] ?? 0, $stats['programs_last_month'] ?? 0);
$mon_pat_trend   = pctChange($stats['patrons_this_month']  ?? 0, $stats['patrons_last_month']  ?? 0);

$top_score_for_bar = !empty($top_programs) ? (float)$top_programs[0]['adjusted_impact_score'] : 1;

// ── My tag breakdown ────────────────────────────────────────────────────────
$my_tags = [];
if ($current_user_id > 0) {
    $tq = $conn->prepare("
        SELECT pt.name AS tag_name, COUNT(st.score_id) AS usage_count
        FROM program_tags pt
        LEFT JOIN score_tags st ON st.tag_id = pt.id
        LEFT JOIN scores s      ON s.id = st.score_id
                                AND (s.user_id = ? OR s.id IN (
                                        SELECT score_id FROM score_users WHERE user_id = ?))
        GROUP BY pt.id, pt.name
        ORDER BY usage_count DESC, pt.name ASC
    ");
    if ($tq) {
        $tq->bind_param("ii", $current_user_id, $current_user_id);
        $tq->execute();
        $my_tags = $tq->get_result()->fetch_all(MYSQLI_ASSOC);
        $tq->close();
    }
}
$my_tag_max = max(1, ...array_map(fn($t) => (int)$t['usage_count'], $my_tags ?: [['usage_count'=>0]]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $user_name ? h($user_name) . ' — My Dashboard' : 'My Dashboard' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
    :root {
        --plum: #480d3c;
        --fuchsia: #bb1b51;
        --parchment: #f5f2ec;
        --plum-light: #6b1659;
    }
    * { font-family: 'Montserrat', sans-serif; }
    body { background: var(--parchment); min-height: 100vh; }

    .btn-plum { background: var(--plum); color: #fff; border: 0; border-radius: 10px; padding: 12px 32px; font-weight: 700; font-size: 1rem; transition: background .2s; }
    .btn-plum:hover { background: var(--fuchsia); color: #fff; }

    /* ── Header ── */
    .dash-header {
        background: linear-gradient(135deg, var(--plum) 0%, #6b1659 60%, var(--fuchsia) 100%);
        color: #fff; padding: 28px 32px 24px;
    }
    .dash-header h1 { font-weight: 800; font-size: 1.9rem; margin: 0; }
    .dash-header .subtitle { opacity: .8; font-size: .9rem; margin-top: 2px; }
    .btn-ghost { border: 1px solid rgba(255,255,255,.4); color: #fff; border-radius: 8px; padding: 6px 14px; font-size: .8rem; background: transparent; transition: all .2s; }
    .btn-ghost:hover { background: rgba(255,255,255,.15); color: #fff; }
    .quick-link { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.15); color: #fff; text-decoration: none; border-radius: 10px; padding: 8px 16px; font-size: .85rem; font-weight: 600; transition: background .2s; }
    .quick-link:hover { background: rgba(255,255,255,.28); color: #fff; }

    /* ── Stat cards ── */
    .stat-card { background: #fff; border-radius: 14px; padding: 20px 22px; box-shadow: 0 2px 12px rgba(0,0,0,.06); height: 100%; }
    .stat-card .label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #999; margin-bottom: 6px; }
    .stat-card .value { font-size: 2.1rem; font-weight: 800; color: var(--plum); line-height: 1; }
    .stat-card .sub   { font-size: .78rem; color: #888; margin-top: 6px; }
    .trend-up   { color: #1a7f4b; font-weight: 700; }
    .trend-down { color: #c0392b; font-weight: 700; }
    .trend-neutral { color: #888; }

    /* ── Section cards ── */
    .section-card { background: #fff; border-radius: 14px; padding: 22px 24px; box-shadow: 0 2px 12px rgba(0,0,0,.06); margin-bottom: 20px; }
    .section-card h5 { font-weight: 700; color: var(--plum); margin-bottom: 16px; font-size: 1rem; }

    /* ── Personal best banner ── */
    .best-banner {
        background: linear-gradient(135deg, var(--plum), var(--fuchsia));
        color: #fff; border-radius: 14px; padding: 20px 22px;
        box-shadow: 0 4px 16px rgba(72,13,60,.25);
    }
    .best-banner .crown { font-size: 2rem; }
    .best-banner .score { font-size: 2.4rem; font-weight: 800; line-height: 1; }
    .best-banner .prog  { font-size: .85rem; opacity: .85; margin-top: 4px; }

    /* ── Top programs bar ── */
    .prog-bar-wrap { margin-bottom: 10px; }
    .prog-bar-label { font-size: .82rem; font-weight: 600; color: #333; margin-bottom: 3px; }
    .prog-bar-track { background: #f0eae8; border-radius: 6px; height: 10px; }
    .prog-bar-fill  { background: linear-gradient(90deg, var(--plum), var(--fuchsia)); border-radius: 6px; height: 10px; transition: width .6s ease; }
    .prog-bar-score { font-size: .78rem; color: #666; float: right; }

    /* ── Quick re-submit ── */
    .program-chip {
        display: flex; align-items: center; justify-content: space-between;
        background: #faf7f2; border: 1px solid #e8e2da; border-radius: 10px;
        padding: 10px 14px; margin-bottom: 8px;
    }
    .program-chip .pname { font-weight: 600; font-size: .88rem; color: #333; }
    .program-chip .pmeta { font-size: .75rem; color: #888; margin-top: 2px; }
    .btn-use { background: var(--plum); color: #fff; border: 0; border-radius: 8px; padding: 6px 14px; font-size: .78rem; font-weight: 700; white-space: nowrap; transition: background .2s; }
    .btn-use:hover { background: var(--fuchsia); }

    /* ── Recent table ── */
    .recent-table { font-size: .83rem; }
    .recent-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #999; background: #faf7f2; }
    .score-pill { display: inline-block; padding: 2px 8px; border-radius: 20px; font-weight: 700; font-size: .75rem; }
    .score-high { background: #d4edda; color: #155724; }
    .score-mid  { background: #fff3cd; color: #856404; }
    .score-low  { background: #f8d7da; color: #721c24; }

    /* ── Location bars ── */
    .loc-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .loc-name { font-size: .8rem; font-weight: 600; color: #444; width: 120px; flex-shrink: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .loc-bar  { flex: 1; background: #f0eae8; border-radius: 4px; height: 8px; }
    .loc-fill { background: var(--plum); border-radius: 4px; height: 8px; }
    .loc-cnt  { font-size: .78rem; color: #888; width: 28px; text-align: right; }

    /* ── Streak badge ── */
    .streak-badge { background: linear-gradient(135deg,#f7971e,#ffd200); color: #5a3200; border-radius: 10px; padding: 10px 16px; font-weight: 700; font-size: .88rem; display: inline-flex; align-items: center; gap: 8px; }

    /* ── Month comparison ── */
    .month-compare .side { flex: 1; text-align: center; padding: 12px; border-radius: 10px; }
    .month-compare .side.this  { background: #f0e8ef; }
    .month-compare .side.last  { background: #faf7f2; }
    .month-compare .big { font-size: 1.8rem; font-weight: 800; color: var(--plum); }
    .month-compare .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #888; }
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════
     DASHBOARD
     ═══════════════════════════════════════════════════════ -->

<?php if ($profile_success): ?>
<div class="alert alert-success alert-dismissible fade show mx-3 mt-3" role="alert">
    <?= h($profile_success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Header -->
<div class="dash-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h1><?= greet() ?>, <?= h(explode(' ', $user_name)[0]) ?> 👋</h1>
            <div class="subtitle"><?= date('l, F j, Y') ?> &nbsp;·&nbsp; Your personal impact snapshot</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($streak > 0): ?>
                <span class="streak-badge">🔥 <?= $streak ?>-month streak</span>
            <?php endif; ?>
            <button type="button" class="btn-ghost" data-bs-toggle="modal" data-bs-target="#profileModal">
                ✏️ Edit profile
            </button>
            <a href="user_change_password.php" class="btn-ghost" style="text-decoration:none;">🔑 Change password</a>
            <a href="user_logout.php" class="btn-ghost" style="text-decoration:none;">Sign out</a>
        </div>
    </div>

    <!-- Quick links -->
    <div class="d-flex flex-wrap gap-2">
        <a href="value_score_form.php?fresh=1" class="quick-link">➕ Submit Score</a>
        <a href="view_scores.php?user_id=<?= $current_user_id ?>" class="quick-link">📋 My Submissions</a>
        <a href="report.php" class="quick-link">📊 Full Report</a>
        <a href="performers/index.php" class="quick-link">🎭 Performers</a>
        <a href="index.php" class="quick-link">🏠 Dashboard</a>
    </div>
</div>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1400px;">

    <!-- ── Stat cards ──────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="label">Total Programs</div>
                <div class="value"><?= number_format((int)($stats['total_programs'] ?? 0)) ?></div>
                <div class="sub">
                    <?= number_format((int)($stats['programs_ytd'] ?? 0)) ?> this year
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="label">Patrons Served</div>
                <div class="value"><?= number_format((int)($stats['total_patrons'] ?? 0)) ?></div>
                <div class="sub">
                    Avg <?= number_format((int)($stats['avg_attendance'] ?? 0)) ?> per program
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="label">Avg Impact Score</div>
                <div class="value"><?= number_format((float)($stats['avg_impact'] ?? 0), 1) ?></div>
                <div class="sub">
                    <?php if ($vs_team !== null): ?>
                        <span class="<?= $vs_team >= 0 ? 'trend-up' : 'trend-down' ?>">
                            <?= $vs_team >= 0 ? '▲' : '▼' ?> <?= abs($vs_team) ?>%
                        </span>
                        vs team avg (<?= number_format($team_avg, 1) ?>)
                    <?php else: ?>
                        No team data
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="label">This Month</div>
                <div class="value"><?= (int)($stats['programs_this_month'] ?? 0) ?></div>
                <div class="sub">
                    <?php [$dir, $pct] = $mon_prog_trend; ?>
                    <span class="trend-<?= $dir ?>"><?= $pct ?></span> vs last month
                    &nbsp;·&nbsp; <?= number_format((int)($stats['patrons_this_month'] ?? 0)) ?> patrons
                </div>
            </div>
        </div>
    </div>

    <!-- ── Control chart + sidebar ────────────────────────────────────── -->
    <div class="row g-3 mb-2">

        <!-- Control chart -->
        <div class="col-lg-8">
            <div class="section-card" style="height:340px;">
                <h5>📈 My Impact Score Control Chart
                    <small class="text-muted fw-normal" style="font-size:.75rem;">
                        — last 24 months
                    </small>
                </h5>
                <?php if (count($chart_data) < 2): ?>
                    <div class="text-muted text-center pt-4">Not enough data yet. Submit more programs to see your chart.</div>
                <?php else: ?>
                    <canvas id="spcChart" style="max-height:260px;"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar: best + month compare -->
        <div class="col-lg-4">

            <?php if ($personal_best): ?>
            <div class="best-banner mb-3">
                <div class="crown">🏆</div>
                <div class="score"><?= number_format((float)$personal_best['adjusted_impact_score'], 1) ?></div>
                <div style="font-size:.7rem; opacity:.7; margin-top:2px; text-transform:uppercase; letter-spacing:.06em;">Personal Best</div>
                <div class="prog"><?= h($personal_best['program']) ?></div>
                <div style="font-size:.78rem; opacity:.75; margin-top:2px;">
                    <?= date('M j, Y', strtotime($personal_best['program_date'])) ?>
                    &nbsp;·&nbsp; <?= number_format((int)$personal_best['attendance']) ?> patrons
                    &nbsp;·&nbsp; score <?= (int)$personal_best['total_score'] ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Month comparison -->
            <div class="section-card" style="padding:16px 18px;">
                <h5 style="margin-bottom:12px;">📅 Month Comparison</h5>
                <div class="d-flex gap-2 month-compare">
                    <div class="side this">
                        <div class="big"><?= (int)($stats['programs_this_month'] ?? 0) ?></div>
                        <div class="lbl">Programs<br>This Month</div>
                    </div>
                    <div class="side last">
                        <div class="big"><?= (int)($stats['programs_last_month'] ?? 0) ?></div>
                        <div class="lbl">Programs<br>Last Month</div>
                    </div>
                </div>
                <div class="d-flex gap-2 month-compare mt-2">
                    <div class="side this">
                        <div class="big" style="font-size:1.4rem;"><?= number_format((int)($stats['patrons_this_month'] ?? 0)) ?></div>
                        <div class="lbl">Patrons<br>This Month</div>
                    </div>
                    <div class="side last">
                        <div class="big" style="font-size:1.4rem;"><?= number_format((int)($stats['patrons_last_month'] ?? 0)) ?></div>
                        <div class="lbl">Patrons<br>Last Month</div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ── Activity bar chart + Location breakdown ─────────────────────── -->
    <div class="row g-3 mb-2">

        <div class="col-lg-8">
            <div class="section-card" style="height:220px;">
                <h5>📆 Monthly Activity — Programs &amp; Patrons (Last 12 Months)</h5>
                <canvas id="activityChart" style="max-height:155px;"></canvas>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="section-card" style="height:220px;">
                <h5>📍 Programs by Location</h5>
                <?php
                    $max_loc = !empty($by_location) ? (int)$by_location[0]['cnt'] : 1;
                    foreach ($by_location as $loc):
                        $pct = $max_loc > 0 ? round(($loc['cnt'] / $max_loc) * 100) : 0;
                ?>
                <div class="loc-row">
                    <div class="loc-name" title="<?= h($loc['loc']) ?>"><?= h($loc['loc']) ?></div>
                    <div class="loc-bar"><div class="loc-fill" style="width:<?= $pct ?>%;"></div></div>
                    <div class="loc-cnt"><?= (int)$loc['cnt'] ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($by_location)): ?>
                    <div class="text-muted small">No location data yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Top programs + Quick re-submit ─────────────────────────────── -->
    <div class="row g-3 mb-2">

        <!-- Top programs -->
        <div class="col-lg-5">
            <div class="section-card">
                <h5>🥇 My Top 5 Programs</h5>
                <?php if (empty($top_programs)): ?>
                    <div class="text-muted small">No programs yet.</div>
                <?php else: foreach ($top_programs as $i => $p): ?>
                    <div class="prog-bar-wrap">
                        <div class="prog-bar-label">
                            <span style="color:var(--plum); font-weight:800;"><?= $i+1 ?>.</span>
                            <?= h($p['program']) ?>
                            <span class="prog-bar-score"><?= number_format((float)$p['adjusted_impact_score'],1) ?></span>
                        </div>
                        <div class="prog-bar-track">
                            <div class="prog-bar-fill" style="width:<?= min(100, round(($p['adjusted_impact_score']/$top_score_for_bar)*100)) ?>%;"></div>
                        </div>
                        <div style="font-size:.72rem; color:#999; margin-top:2px;">
                            <?= date('M j, Y', strtotime($p['program_date'])) ?>
                            &nbsp;·&nbsp; <?= number_format((int)$p['attendance']) ?> patrons
                            &nbsp;·&nbsp; score <?= (int)$p['total_score'] ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Quick re-submit -->
        <div class="col-lg-7">
            <div class="section-card">
                <h5>⚡ Quick Re-Submit — My Programs</h5>
                <p class="text-muted" style="font-size:.8rem; margin-bottom:14px;">
                    Programs you've run before. Click <strong>Use Again</strong> to open the submission form pre-filled with that program name.
                </p>
                <?php if (empty($frequent)): ?>
                    <div class="text-muted small">No programs yet.</div>
                <?php else: foreach ($frequent as $f): ?>
                    <div class="program-chip">
                        <div>
                            <div class="pname"><?= h($f['program']) ?></div>
                            <div class="pmeta">
                                Run <?= (int)$f['times_run'] ?> time<?= $f['times_run'] > 1 ? 's' : '' ?>
                                &nbsp;·&nbsp; Last: <?= date('M j, Y', strtotime($f['last_run'])) ?>
                                &nbsp;·&nbsp; Avg attendance: <?= number_format((int)$f['avg_attendance']) ?>
                                <?php if ($f['avg_impact'] > 0): ?>
                                    &nbsp;·&nbsp; Avg impact: <?= number_format((float)$f['avg_impact'], 1) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <form method="POST" class="ms-2">
                            <input type="hidden" name="action" value="quick_start">
                            <input type="hidden" name="program_name" value="<?= h($f['program']) ?>">
                            <input type="hidden" name="team_id" value="<?= (int)($f['team_id'] ?? 0) ?>">
                            <input type="hidden" name="form_profile_id" value="<?= (int)($f['form_profile_id'] ?? 0) ?>">
                            <input type="hidden" name="prefill_score_id" value="<?= (int)($f['latest_score_id'] ?? 0) ?>">
                            <button type="submit" class="btn-use">Use Again →</button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Recent submissions ─────────────────────────────────────────── -->
    <div class="row g-3">
        <div class="col-12">
            <div class="section-card">
                <h5>🕒 My Recent Submissions</h5>
                <?php if (empty($recent)): ?>
                    <div class="text-muted small">No submissions yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover recent-table mb-0">
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th>Date</th>
                                <th>Location</th>
                                <th>Attendance</th>
                                <th>Score</th>
                                <th>Impact</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $r):
                            $imp = (float)($r['adjusted_impact_score'] ?? 0);
                            $cls = $imp >= $spc_ucl ? 'score-high' : ($imp <= $spc_lcl && $spc_lcl > 0 ? 'score-low' : 'score-mid');
                        ?>
                            <tr>
                                <td class="fw-600"><?= h($r['program']) ?></td>
                                <td><?= date('M j, Y', strtotime($r['program_date'])) ?></td>
                                <td><?= h($r['location_name'] ?? '—') ?></td>
                                <td><?= number_format((int)($r['attendance'] ?? 0)) ?></td>
                                <td><?= (int)($r['total_score'] ?? 0) ?></td>
                                <td><span class="score-pill <?= $cls ?>"><?= number_format($imp,1) ?></span></td>
                                <td>
                                    <a href="edit_score.php?id=<?= (int)$r['id'] ?>&return_url=my_dashboard.php"
                                       style="font-size:.78rem; color:var(--plum); text-decoration:none; font-weight:600;">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">
                    <a href="view_scores.php?user_id=<?= $current_user_id ?>"
                       style="font-size:.82rem; color:var(--plum); font-weight:700; text-decoration:none;">
                        View all my submissions →
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── My Tag Breakdown ──────────────────────────────────────────── -->
    <div class="row g-3 mt-0">
        <div class="col-12">
            <div class="section-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">🏷️ My Tag Breakdown</h5>
                    <a href="bulk_tag.php?user_id=<?= $current_user_id ?>&_filtered=1&unreviewed_only=1"
                       style="font-size:.78rem; color:var(--plum); font-weight:700; text-decoration:none;">
                        Tag unreviewed programs →
                    </a>
                </div>
                <?php
                $used_tags = array_filter($my_tags, fn($t) => (int)$t['usage_count'] > 0);
                $unused_tags = array_filter($my_tags, fn($t) => (int)$t['usage_count'] === 0);
                if (empty($my_tags)): ?>
                    <div class="text-muted small">No tags found — ask your admin to add tags in the Admin Portal.</div>
                <?php elseif (empty($used_tags)): ?>
                    <div class="text-muted small mb-2">You haven't tagged any programs yet.</div>
                    <a href="bulk_tag.php?user_id=<?= $current_user_id ?>&_filtered=1&unreviewed_only=1"
                       class="btn btn-sm" style="background:var(--plum);color:#fff;border-radius:8px;">
                        Tag my programs →
                    </a>
                <?php else: ?>
                <div class="row g-3">
                    <div class="col-lg-8">
                        <?php foreach ($my_tags as $t):
                            $pct = $my_tag_max > 0 ? round(((int)$t['usage_count'] / $my_tag_max) * 100) : 0;
                            $opacity = (int)$t['usage_count'] > 0 ? 1 : 0.35;
                        ?>
                        <div class="mb-2" style="opacity:<?= $opacity ?>;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span style="font-size:.82rem; font-weight:600;"><?= h($t['tag_name']) ?></span>
                                <span style="font-size:.78rem; color:#999; font-weight:700; min-width:1.8rem; text-align:right;">
                                    <?= (int)$t['usage_count'] ?>
                                </span>
                            </div>
                            <div style="background:#ede6eb; border-radius:6px; height:22px;">
                                <div style="background:var(--plum); border-radius:6px; height:22px; width:<?= $pct ?>%;
                                            transition:width .4s;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-lg-4 d-flex flex-column justify-content-center align-items-center text-center">
                        <div style="font-size:2.4rem; font-weight:800; color:var(--plum); line-height:1;">
                            <?= array_sum(array_column($my_tags, 'usage_count')) ?>
                        </div>
                        <div style="font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#999; margin-top:4px;">
                            total tag uses
                        </div>
                        <?php if (!empty($unused_tags)): ?>
                        <div style="margin-top:16px; font-size:.75rem; color:#bbb;">
                            <?= count($unused_tags) ?> tag<?= count($unused_tags) > 1 ? 's' : '' ?> unused
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /container -->

<!-- ═══════════════════════════════════════════════════════
     PROFILE EDIT MODAL
     ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:18px; border:none;">
            <div class="modal-header" style="background:var(--plum); color:#fff; border-radius:18px 18px 0 0; border:none;">
                <h5 class="modal-title" id="profileModalLabel" style="font-weight:700;">✏️ Edit My Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-body p-4">
                    <?php if ($profile_error): ?>
                        <div class="alert alert-danger" style="border-radius:10px; font-size:.88rem;">
                            <?= h($profile_error) ?>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:600; font-size:.85rem;">Display Name</label>
                        <input type="text" name="profile_name" class="form-control"
                               value="<?= h($user_name) ?>" required
                               style="border-radius:10px; border:2px solid #e0e0e0;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:600; font-size:.85rem;">Email Address</label>
                        <input type="email" name="profile_email" class="form-control"
                               value="<?= h($user_email_val) ?>"
                               style="border-radius:10px; border:2px solid #e0e0e0;">
                        <div class="form-text" style="font-size:.75rem;">Used for login — update with care.</div>
                    </div>
                    <div class="text-muted" style="font-size:.78rem;">
                        To change your password, use the <strong>🔑 Change password</strong> link in the header.
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid #eee;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn"
                            style="background:var(--plum); color:#fff; border:none; border-radius:8px; font-weight:700;">
                        Save changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     CHARTS
     ═══════════════════════════════════════════════════════ -->
<?php if ($current_user_id > 0 && count($chart_data) >= 2): ?>
<script>
(function(){
    // ── SPC Control Chart ──────────────────────────────────────────────
    const spcLabels = <?= json_encode(array_column($chart_data, 'program_date')) ?>;
    const spcScores = <?= json_encode(array_map('floatval', array_column($chart_data, 'adjusted_impact_score'))) ?>;
    const spcProgs  = <?= json_encode(array_column($chart_data, 'program')) ?>;
    const mean      = <?= round($spc_mean, 2) ?>;
    const ucl       = <?= round($spc_ucl, 2) ?>;
    const lcl       = <?= round($spc_lcl, 2) ?>;

    const meanArr = spcLabels.map(() => mean);
    const uclArr  = spcLabels.map(() => ucl);
    const lclArr  = spcLabels.map(() => lcl);

    new Chart(document.getElementById('spcChart'), {
        type: 'line',
        data: {
            labels: spcLabels,
            datasets: [
                {
                    label: 'Adjusted Impact Score',
                    data: spcScores,
                    borderColor: '#480d3c',
                    backgroundColor: 'rgba(72,13,60,.08)',
                    borderWidth: 2.5,
                    pointRadius: 5,
                    pointBackgroundColor: spcScores.map((v, i) =>
                        v > ucl ? '#1a7f4b' : (v < lcl ? '#c0392b' : '#480d3c')
                    ),
                    tension: 0.35, fill: true,
                },
                { label:'Mean',  data: meanArr, borderColor:'#999',    borderWidth:1.5, borderDash:[4,4], pointRadius:0, fill:false },
                { label:'UCL',   data: uclArr,  borderColor:'#1a7f4b', borderWidth:1.5, borderDash:[4,4], pointRadius:0, fill:false },
                { label:'LCL',   data: lclArr,  borderColor:'#c0392b', borderWidth:1.5, borderDash:[4,4], pointRadius:0, fill:false },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: ctx => spcProgs[ctx[0].dataIndex] || spcLabels[ctx[0].dataIndex],
                        label: ctx => ' Impact: ' + ctx.parsed.y.toFixed(1),
                        afterLabel: ctx => {
                            const v = ctx.parsed.y;
                            if (v > ucl) return '⬆ Above UCL — outstanding!';
                            if (v < lcl) return '⬇ Below LCL — investigate';
                            return '';
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { font: { size: 10 }, maxTicksLimit: 10 }, grid: { display: false } },
                y: { ticks: { font: { size: 10 } }, beginAtZero: true }
            }
        }
    });

    // ── Monthly Activity Chart ─────────────────────────────────────────
    const actLabels   = <?= json_encode(array_column($last12, 'label')) ?>;
    const actCounts   = <?= json_encode(array_column($last12, 'cnt')) ?>;
    const actPatrons  = <?= json_encode(array_column($last12, 'patrons')) ?>;

    new Chart(document.getElementById('activityChart'), {
        type: 'bar',
        data: {
            labels: actLabels,
            datasets: [
                {
                    label: 'Programs',
                    data: actCounts,
                    backgroundColor: 'rgba(72,13,60,.75)',
                    borderRadius: 5, yAxisID: 'y'
                },
                {
                    label: 'Patrons',
                    data: actPatrons,
                    type: 'line',
                    borderColor: '#bb1b51',
                    borderWidth: 2,
                    pointRadius: 4,
                    tension: 0.35,
                    fill: false,
                    yAxisID: 'y2'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { labels: { font: { size: 11 } } } },
            scales: {
                x:  { ticks: { font: { size: 10 } }, grid: { display: false } },
                y:  { ticks: { font: { size: 10 }, stepSize: 1 }, beginAtZero: true, title: { display: true, text: 'Programs', font: { size: 10 } } },
                y2: { ticks: { font: { size: 10 } }, beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Patrons', font: { size: 10 } } }
            }
        }
    });
})();
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($profile_error): ?>
<script>
// Re-open modal if there was a validation error
document.addEventListener('DOMContentLoaded', function() {
    var modal = new bootstrap.Modal(document.getElementById('profileModal'));
    modal.show();
});
</script>
<?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>
