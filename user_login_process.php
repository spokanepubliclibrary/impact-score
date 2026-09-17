<?php
/**
 * user_login_process.php — Handles password login form submission.
 */
session_start();

require_once __DIR__ . '/secure/db_connection.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error);

// Redirect helper
function redirect_login(string $error, string $email = ''): void {
    $qs = 'error=' . urlencode($error);
    if ($email) $qs .= '&email=' . urlencode($email);
    if (!empty($_POST['redirect'])) $qs .= '&redirect=' . urlencode($_POST['redirect']);
    header('Location: user_login.php?' . $qs);
    exit;
}

$email    = trim($_POST['email']    ?? '');
$password_input = $_POST['password'] ?? '';
$redirect = trim($_POST['redirect'] ?? '');

if (!$email || !$password_input) {
    redirect_login('invalid', $email);
}

// Look up user by email
$stmt = $conn->prepare(
    "SELECT id, name, password_hash, must_change_password, account_active, is_admin
     FROM users
     WHERE email = ?
     LIMIT 1"
);
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !$user['password_hash']) {
    redirect_login('invalid', $email);
}

if (!$user['account_active']) {
    redirect_login('inactive', $email);
}

if (!password_verify($password_input, $user['password_hash'])) {
    redirect_login('invalid', $email);
}

// --- Login successful ---

// Update last_login timestamp
$upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
$upd->bind_param('i', $user['id']);
$upd->execute();
$upd->close();

$conn->close();

// Regenerate session ID to prevent fixation
session_regenerate_id(true);

$_SESSION['user_logged_in'] = true;
$_SESSION['user_id']        = (int)$user['id'];
$_SESSION['user_name']      = $user['name'];
$_SESSION['user_email']     = $email;
$_SESSION['user_is_admin']  = (bool)$user['is_admin'];

// Admin users get portal access without a separate admin login
if ($user['is_admin']) {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username']  = $user['name'];
}

// Force password change if flagged
if ($user['must_change_password']) {
    header('Location: user_change_password.php?forced=1');
    exit;
}

// Redirect to intended page or dashboard
if ($redirect && strpos($redirect, '/') !== 0 && strpos($redirect, 'http') !== 0) {
    // Relative path only — no open redirect
    header('Location: ' . $redirect);
} else {
    header('Location: my_dashboard.php');
}
exit;
