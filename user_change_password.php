<?php
/**
 * user_change_password.php
 * Self-service password change for logged-in staff users.
 * Also handles forced change (must_change_password = 1) on first login.
 */
session_start();

require_once __DIR__ . '/secure/db_connection.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error);

// Must be logged in
if (empty($_SESSION['user_logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: user_login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$forced  = !empty($_GET['forced']) || !empty($_POST['forced']);  // forced reset mode

// Load current user record
$stmt = $conn->prepare("SELECT id, name, email, password_hash, must_change_password FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: user_logout.php');
    exit;
}

// If must_change_password is set, treat as forced regardless of GET param
if ($user['must_change_password']) $forced = true;

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $current_pw  = $_POST['current_password'] ?? '';
    $new_pw      = $_POST['new_password']      ?? '';
    $confirm_pw  = $_POST['confirm_password']  ?? '';

    // Verify current password (skip if forced AND no password set yet)
    if (!$forced || $user['password_hash']) {
        if (!$forced && !password_verify($current_pw, $user['password_hash'] ?? '')) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    // Validate new password
    if (strlen($new_pw) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new_pw !== $confirm_pw) {
        $errors[] = 'New passwords do not match.';
    }

    if (empty($errors)) {
        $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
        $upd = $conn->prepare(
            "UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?"
        );
        $upd->bind_param('si', $new_hash, $user_id);
        $upd->execute();
        $upd->close();

        $success = true;

        // If this was a forced change, redirect to dashboard now
        if ($forced) {
            header('Location: my_dashboard.php');
            exit;
        }
    }
}

$conn->close();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $forced ? 'Set Your Password' : 'Change Password' ?> — Impact Score</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --plum: #480d3c; --fuchsia: #bb1b51; --parchment: #f5f2ec; }
    * { font-family: 'Montserrat', sans-serif; }
    body {
        background: var(--parchment);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }
    .card {
        max-width: 480px;
        width: 100%;
        border-radius: 18px;
        border: none;
        box-shadow: 0 4px 24px rgba(0,0,0,.1);
        padding: 40px 44px;
    }
    .card-header-custom {
        text-align: center;
        margin-bottom: 28px;
    }
    .card-header-custom h2 {
        font-weight: 800;
        font-size: 1.5rem;
        color: var(--plum);
    }
    .card-header-custom p {
        color: #666;
        font-size: .88rem;
        margin: 6px 0 0;
    }
    .form-label { font-weight: 600; font-size: .85rem; color: #444; }
    .form-control {
        border-radius: 10px;
        border: 2px solid #e0e0e0;
        padding: 11px 14px;
        font-size: .95rem;
    }
    .form-control:focus { border-color: var(--plum); box-shadow: 0 0 0 3px rgba(72,13,60,.12); }
    .btn-plum {
        background: var(--plum); color: #fff; border: none;
        border-radius: 10px; padding: 12px; font-weight: 700;
        font-size: .95rem; width: 100%; transition: background .2s;
    }
    .btn-plum:hover { background: var(--fuchsia); color: #fff; }
    .strength-bar { height: 5px; border-radius: 3px; margin-top: 6px; background: #eee; overflow: hidden; }
    .strength-fill { height: 100%; width: 0; border-radius: 3px; transition: width .3s, background .3s; }
    .strength-label { font-size: .72rem; color: #888; margin-top: 3px; }
    .alert { border-radius: 10px; font-size: .88rem; }
    .back-link { text-align: center; margin-top: 16px; font-size: .82rem; }
    .back-link a { color: var(--plum); text-decoration: none; font-weight: 600; }
    .back-link a:hover { color: var(--fuchsia); }
</style>
</head>
<body>
<div class="card">
    <div class="card-header-custom">
        <div style="font-size:2.5rem; margin-bottom:8px;"><?= $forced ? '🔐' : '🔑' ?></div>
        <h2><?= $forced ? 'Set your password' : 'Change your password' ?></h2>
        <p>
            <?php if ($forced): ?>
                Your administrator has set a temporary password. Please choose a new one before continuing.
            <?php else: ?>
                Update the password for <strong><?= h($user['name']) ?></strong>.
            <?php endif; ?>
        </p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-3">
            <?php foreach ($errors as $e): ?>
                <div><?= h($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success mb-3">Password updated successfully!</div>
    <?php endif; ?>

    <form method="POST">
        <?php if ($forced): ?>
            <input type="hidden" name="forced" value="1">
        <?php endif; ?>

        <!-- Current password — only shown if not forced OR user already has a password -->
        <?php if (!$forced): ?>
        <div class="mb-3">
            <label class="form-label" for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password"
                   class="form-control" required autocomplete="current-password">
        </div>
        <?php endif; ?>

        <div class="mb-3">
            <label class="form-label" for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password"
                   class="form-control" required minlength="8"
                   autocomplete="new-password" oninput="checkStrength(this.value)">
            <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
            <div class="strength-label" id="strength-label">Minimum 8 characters</div>
        </div>

        <div class="mb-4">
            <label class="form-label" for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   class="form-control" required minlength="8" autocomplete="new-password">
        </div>

        <button type="submit" class="btn-plum">
            <?= $forced ? 'Set password and continue →' : 'Update password' ?>
        </button>
    </form>

    <?php if (!$forced): ?>
    <div class="back-link">
        <a href="my_dashboard.php">← Back to my dashboard</a>
    </div>
    <?php endif; ?>
</div>

<script>
function checkStrength(pw) {
    const fill  = document.getElementById('strength-fill');
    const label = document.getElementById('strength-label');
    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    const levels = [
        { pct: 0,   color: '#eee',    text: 'Minimum 8 characters' },
        { pct: 20,  color: '#e74c3c', text: 'Weak' },
        { pct: 40,  color: '#e67e22', text: 'Fair' },
        { pct: 60,  color: '#f1c40f', text: 'Good' },
        { pct: 80,  color: '#2ecc71', text: 'Strong' },
        { pct: 100, color: '#1a7f4b', text: 'Very strong' },
    ];
    const l = levels[score] || levels[0];
    fill.style.width     = l.pct + '%';
    fill.style.background = l.color;
    label.textContent    = l.text;
}
</script>
</body>
</html>
