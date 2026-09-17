<?php
/**
 * docker/db_connection.php — env-driven DB connection (production shim)
 *
 * Baked into the Docker image at /var/www/html/secure/db_connection.php via
 * the Dockerfile, replacing the placeholder committed in the repo.
 *
 * Pass 4c — Secret-handling preflight:
 *   When APP_ENV=production, refuse to start if any secret variable still
 *   matches a known placeholder value (changeme / change-this-secret-token).
 *   This blocks the classic "deployed with .env.example defaults" footgun.
 */

// ── 1. Load .env if present ─────────────────────────────────────────────────
// The base compose mounts the host .env at /var/www/html/.env:ro. PHP's
// docker-compose `environment:` block has already populated getenv(); this
// pass is a defense in depth for non-Docker callers.
$_envFile = __DIR__ . '/../.env';
if (is_readable($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') {
            continue;
        }
        if (strpos($_line, '=') === false) {
            continue;
        }
        [$_k, $_v] = array_map('trim', explode('=', $_line, 2));
        // Don't clobber a value that's already in the environment.
        if (getenv($_k) === false) {
            putenv("{$_k}={$_v}");
            $_ENV[$_k] = $_v;
        }
    }
}

// ── 2. Resolve runtime values ───────────────────────────────────────────────
$servername = getenv('DB_HOST')     ?: 'db';
$username   = getenv('DB_USER')     ?: 'impact_user';
$password   = getenv('DB_PASSWORD') ?: '';
$database   = getenv('DB_NAME')     ?: 'impact_score';

// ── 3. Production preflight ─────────────────────────────────────────────────
$appEnv = getenv('APP_ENV') ?: 'production';
if ($appEnv === 'production') {
    $checks = [
        'DB_PASSWORD'           => ['value' => $password,                                   'placeholder' => ['changeme', 'changeme_password', 'impact_pass', '']],
        'BOOTSTRAP_ADMIN_TOKEN' => ['value' => getenv('BOOTSTRAP_ADMIN_TOKEN') ?: '',       'placeholder' => ['change-this-secret-token', '']],
        'MYSQL_ROOT_PASSWORD'   => ['value' => getenv('MYSQL_ROOT_PASSWORD')   ?: '',       'placeholder' => ['changeme_root', 'rootpassword']],
    ];
    $offenders = [];
    foreach ($checks as $name => $info) {
        if (in_array($info['value'], $info['placeholder'], true)) {
            $offenders[] = $name;
        }
    }
    if (!empty($offenders)) {
        $msg = '[CRITICAL] APP_ENV=production but placeholder secrets detected for: '
             . implode(', ', $offenders)
             . '. Refusing to start.';
        error_log($msg);
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Server configuration error. See server logs.\n";
        exit;
    }
}

// ── 4. Connect ──────────────────────────────────────────────────────────────
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database connection failed. See server logs.\n";
    exit;
}

$conn->set_charset('utf8mb4');

// ── 5. Admin-password preflight ─────────────────────────────────────────────
// db/migrations/000_ensure_admin_user.sql seeds a bootstrap admin account
// with a known, documented password (admin / changeme). That hash is a DB
// row, not an env var, so the checks in step 3 can't see it. Refuse to serve
// in production until every account has been rotated off that known hash.
// See README.md → Production secret preflight for how to rotate it.
if ($appEnv === 'production') {
    $knownDefaultAdminHash = '$2y$12$RkWvXALsT/0nyqcQtI.Tp.8.YY0eGMovCCKBSDwon8sUCsm83UCAC';
    $stmt = $conn->prepare('SELECT username FROM admins WHERE password = ?');
    if ($stmt !== false) {
        $stmt->bind_param('s', $knownDefaultAdminHash);
        $stmt->execute();
        $stmt->bind_result($offendingUsername);
        $offenders = [];
        while ($stmt->fetch()) {
            $offenders[] = $offendingUsername;
        }
        $stmt->close();
        if (!empty($offenders)) {
            $msg = '[CRITICAL] APP_ENV=production but the bootstrap admin password '
                 . '(admin / changeme) is still active for: ' . implode(', ', $offenders)
                 . '. Log in and change it, or set a new hash directly. Refusing to start.';
            error_log($msg);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Server configuration error. See server logs.\n";
            exit;
        }
    } else {
        // admins table may not exist yet on a brand-new host mid-migration;
        // don't hard-fail the whole app on a missing table here.
        error_log('[WARN] Admin-password preflight could not run: ' . $conn->error);
    }
}
