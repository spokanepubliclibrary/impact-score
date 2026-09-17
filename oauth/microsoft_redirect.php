<?php
/**
 * oauth/microsoft_redirect.php
 * Step 1 of Microsoft OAuth: generate state, store in session, redirect to Microsoft.
 */
session_start();

$config_path = __DIR__ . '/../secure/microsoft_oauth.php';
if (!file_exists($config_path)) {
    die('Microsoft OAuth is not configured. See AZURE_SETUP.md.');
}
$cfg = include $config_path;

if (empty($cfg['client_id']) || $cfg['client_id'] === 'YOUR_CLIENT_ID') {
    die('Microsoft OAuth credentials are not set. See AZURE_SETUP.md.');
}

// CSRF protection: random state token stored in session
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state']    = $state;
$_SESSION['oauth_redirect'] = $_GET['redirect'] ?? '';

$params = http_build_query([
    'client_id'     => $cfg['client_id'],
    'response_type' => 'code',
    'redirect_uri'  => $cfg['redirect_uri'],
    'response_mode' => 'query',
    'scope'         => 'openid profile email',
    'state'         => $state,
    // prompt=select_account forces Microsoft to show the account picker
    'prompt'        => 'select_account',
]);

$tenant = $cfg['tenant_id'] ?? 'common';
$auth_url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?{$params}";

header('Location: ' . $auth_url);
exit;
