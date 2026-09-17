<?php
/**
 * user_login.php — Staff Login
 * Supports password login and Microsoft OAuth (when configured).
 */
session_start();

// Already logged in → go to dashboard
if (!empty($_SESSION['user_logged_in']) && !empty($_SESSION['user_id'])) {
    header('Location: my_dashboard.php');
    exit;
}

$error   = $_GET['error']   ?? '';
$notice  = $_GET['notice']  ?? '';
$redirect = $_GET['redirect'] ?? '';

// Human-readable error messages
$error_messages = [
    'invalid'   => 'Incorrect email or password.',
    'inactive'  => 'Your account has been deactivated. Please contact an administrator.',
    'nolink'    => 'No account is linked to that Microsoft login. Please contact an administrator.',
    'oauth'     => 'Microsoft sign-in failed. Please try again or use your password.',
    'csrf'      => 'Session expired. Please try signing in again.',
];
$notice_messages = [
    'loggedout' => 'You have been signed out.',
    'changed'   => 'Password updated successfully. Please sign in.',
];

$error_text  = $error_messages[$error]   ?? '';
$notice_text = $notice_messages[$notice] ?? '';

// Check if Microsoft OAuth is configured
$ms_configured = false;
$ms_config_path = __DIR__ . '/secure/microsoft_oauth.php';
if (file_exists($ms_config_path)) {
    $cfg = include $ms_config_path;
    $ms_configured = !empty($cfg['client_id']) && $cfg['client_id'] !== 'YOUR_CLIENT_ID';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In — Impact Score</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --plum: #480d3c;
        --fuchsia: #bb1b51;
        --parchment: #f5f2ec;
    }
    * { font-family: 'Montserrat', sans-serif; }
    body {
        background: linear-gradient(135deg, var(--plum) 0%, #6b1659 60%, var(--fuchsia) 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }
    .login-card {
        background: #fff;
        border-radius: 20px;
        padding: 48px 44px 40px;
        max-width: 420px;
        width: 100%;
        box-shadow: 0 24px 60px rgba(0,0,0,.3);
    }
    .login-logo {
        text-align: center;
        margin-bottom: 28px;
    }
    .login-logo .app-name {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--plum);
        letter-spacing: -.02em;
    }
    .login-logo .tagline {
        font-size: .8rem;
        color: #888;
        margin-top: 2px;
    }
    h2 {
        font-size: 1.25rem;
        font-weight: 700;
        color: #222;
        margin-bottom: 24px;
        text-align: center;
    }
    .form-label {
        font-weight: 600;
        font-size: .85rem;
        color: #444;
    }
    .form-control {
        border-radius: 10px;
        border: 2px solid #e0e0e0;
        padding: 11px 14px;
        font-size: .95rem;
        transition: border-color .2s;
    }
    .form-control:focus {
        border-color: var(--plum);
        box-shadow: 0 0 0 3px rgba(72,13,60,.12);
    }
    .btn-plum {
        background: var(--plum);
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 13px;
        font-weight: 700;
        font-size: 1rem;
        width: 100%;
        transition: background .2s;
    }
    .btn-plum:hover { background: var(--fuchsia); color: #fff; }
    .divider {
        display: flex;
        align-items: center;
        gap: 12px;
        color: #bbb;
        font-size: .8rem;
        margin: 20px 0;
    }
    .divider::before, .divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: #e8e8e8;
    }
    .btn-microsoft {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        background: #fff;
        color: #222;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        padding: 11px;
        font-weight: 600;
        font-size: .92rem;
        text-decoration: none;
        transition: border-color .2s, background .2s;
    }
    .btn-microsoft:hover {
        border-color: #0078d4;
        background: #f0f6ff;
        color: #0078d4;
    }
    .ms-logo {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }
    .btn-microsoft.disabled {
        opacity: .5;
        pointer-events: none;
        cursor: not-allowed;
    }
    .ms-note {
        font-size: .72rem;
        color: #aaa;
        text-align: center;
        margin-top: 6px;
    }
    .alert { border-radius: 10px; font-size: .88rem; }
    .back-link {
        text-align: center;
        margin-top: 20px;
        font-size: .8rem;
        color: #aaa;
    }
    .back-link a { color: var(--plum); text-decoration: none; font-weight: 600; }
    .back-link a:hover { color: var(--fuchsia); }
</style>
</head>
<body>

<div class="login-card">
    <div class="login-logo">
        <div class="app-name">📊 Impact Score</div>
        <div class="tagline">Community Engagement Dashboard</div>
    </div>

    <h2>Sign in to your account</h2>

    <?php if ($error_text): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_text) ?></div>
    <?php endif; ?>
    <?php if ($notice_text): ?>
        <div class="alert alert-success"><?= htmlspecialchars($notice_text) ?></div>
    <?php endif; ?>

    <!-- Password login form -->
    <form method="POST" action="user_login_process.php">
        <?php if ($redirect): ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label" for="email">Email address</label>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="you@example.com" required autofocus
                   value="<?= htmlspecialchars($_GET['email'] ?? '') ?>">
        </div>
        <div class="mb-4">
            <label class="form-label" for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control"
                   placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn-plum">Sign in →</button>
    </form>

    <!-- Microsoft OAuth -->
    <div class="divider">or</div>

    <?php if ($ms_configured): ?>
        <a href="oauth/microsoft_redirect.php<?= $redirect ? '?redirect=' . urlencode($redirect) : '' ?>"
           class="btn-microsoft">
            <svg class="ms-logo" viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg">
                <rect x="1"  y="1"  width="9" height="9" fill="#f25022"/>
                <rect x="11" y="1"  width="9" height="9" fill="#7fba00"/>
                <rect x="1"  y="11" width="9" height="9" fill="#00a4ef"/>
                <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
            </svg>
            Sign in with Microsoft
        </a>
    <?php else: ?>
        <a class="btn-microsoft disabled" href="#" aria-disabled="true">
            <svg class="ms-logo" viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg">
                <rect x="1"  y="1"  width="9" height="9" fill="#f25022"/>
                <rect x="11" y="1"  width="9" height="9" fill="#7fba00"/>
                <rect x="1"  y="11" width="9" height="9" fill="#00a4ef"/>
                <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
            </svg>
            Sign in with Microsoft
        </a>
        <div class="ms-note">Microsoft sign-in is not yet configured. See AZURE_SETUP.md.</div>
    <?php endif; ?>

    <div class="back-link">
        <a href="index.php">← Back to main dashboard</a>
    </div>
</div>

</body>
</html>
