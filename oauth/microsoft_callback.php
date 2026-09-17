<?php
/**
 * oauth/microsoft_callback.php
 * Step 2 of Microsoft OAuth: exchange code for tokens, identify user, log in.
 *
 * No Composer dependencies — uses curl for HTTP calls and manual JWT parsing
 * (we only need the payload claims, not full signature verification, since we
 * fetched the token directly from Microsoft over TLS).
 */
session_start();

require_once __DIR__ . '/../secure/db_connection.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die('DB connection failed.');

function oauth_error(string $msg): void {
    header('Location: ../user_login.php?error=oauth');
    // Log actual message to error log for debugging
    error_log('[OAuth] ' . $msg);
    exit;
}

// ── Load config ────────────────────────────────────────────────────────────
$cfg = include __DIR__ . '/../secure/microsoft_oauth.php';
$tenant = $cfg['tenant_id'] ?? 'common';

// ── CSRF: verify state ─────────────────────────────────────────────────────
if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
    oauth_error('State mismatch — possible CSRF');
}
unset($_SESSION['oauth_state']);

// ── Handle error response from Microsoft ──────────────────────────────────
if (!empty($_GET['error'])) {
    oauth_error('Microsoft returned: ' . $_GET['error'] . ' — ' . ($_GET['error_description'] ?? ''));
}

if (empty($_GET['code'])) {
    oauth_error('No authorization code returned');
}

// ── Exchange code for tokens ───────────────────────────────────────────────
$token_url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";

$post_data = http_build_query([
    'client_id'     => $cfg['client_id'],
    'client_secret' => $cfg['client_secret'],
    'code'          => $_GET['code'],
    'redirect_uri'  => $cfg['redirect_uri'],
    'grant_type'    => 'authorization_code',
    'scope'         => 'openid profile email',
]);

$ch = curl_init($token_url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post_data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$response = curl_exec($ch);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    oauth_error('curl error fetching token: ' . $curl_err);
}

$token_data = json_decode($response, true);
if (empty($token_data['id_token'])) {
    oauth_error('No id_token in response: ' . $response);
}

// ── Decode ID token payload (base64url, no sig verification needed — fetched over TLS) ──
$id_token = $token_data['id_token'];
$parts = explode('.', $id_token);
if (count($parts) !== 3) {
    oauth_error('Malformed id_token');
}

// base64url → base64 → decode
$payload_json = base64_decode(str_pad(
    strtr($parts[1], '-_', '+/'),
    strlen($parts[1]) + (4 - strlen($parts[1]) % 4) % 4,
    '='
));
$claims = json_decode($payload_json, true);

if (empty($claims['oid'])) {
    oauth_error('No oid claim in id_token');
}

$ms_oid   = $claims['oid'];
$ms_email = $claims['email'] ?? $claims['preferred_username'] ?? '';
$ms_name  = $claims['name']  ?? '';

// ── Find matching user ─────────────────────────────────────────────────────
//  1. Match by microsoft_oid (fastest, most stable)
//  2. Fall back to email match (links existing records automatically)

$user = null;

$stmt = $conn->prepare(
    "SELECT id, name, email, account_active, must_change_password, is_admin
     FROM users WHERE microsoft_oid = ? LIMIT 1"
);
$stmt->bind_param('s', $ms_oid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user && $ms_email) {
    // Try matching by email
    $stmt = $conn->prepare(
        "SELECT id, name, email, account_active, must_change_password, is_admin
         FROM users WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param('s', $ms_email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        // Backfill the OID and microsoft_email so future logins use the fast path
        $link = $conn->prepare(
            "UPDATE users SET microsoft_oid = ?, microsoft_email = ? WHERE id = ?"
        );
        $link->bind_param('ssi', $ms_oid, $ms_email, $user['id']);
        $link->execute();
        $link->close();
    }
}

if (!$user) {
    // No account found — no self-registration allowed
    header('Location: ../user_login.php?error=nolink');
    exit;
}

if (!$user['account_active']) {
    header('Location: ../user_login.php?error=inactive');
    exit;
}

// ── Update last_login ──────────────────────────────────────────────────────
$upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
$upd->bind_param('i', $user['id']);
$upd->execute();
$upd->close();

$conn->close();

// ── Establish session ──────────────────────────────────────────────────────
session_regenerate_id(true);

$_SESSION['user_logged_in'] = true;
$_SESSION['user_id']        = (int)$user['id'];
$_SESSION['user_name']      = $user['name'];
$_SESSION['user_email']     = $user['email'];
$_SESSION['auth_method']    = 'microsoft';
$_SESSION['user_is_admin']  = (bool)$user['is_admin'];

if ($user['is_admin']) {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username']  = $user['name'];
}

// Force password change flag still applies (e.g., if admin set one)
if ($user['must_change_password']) {
    header('Location: ../user_change_password.php?forced=1');
    exit;
}

// Redirect to saved destination or dashboard
$redirect = $_SESSION['oauth_redirect'] ?? '';
unset($_SESSION['oauth_redirect']);

if ($redirect && strpos($redirect, '/') !== 0 && strpos($redirect, 'http') !== 0) {
    header('Location: ../' . $redirect);
} else {
    header('Location: ../my_dashboard.php');
}
exit;
