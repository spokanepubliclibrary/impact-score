<?php
/**
 * secure/db_connection.php — placeholder credentials file
 *
 * This file is committed to the repo as a template ONLY.
 * Real credentials must NEVER be committed.
 *
 * Resolution order:
 *   1. Environment variables (DB_HOST, DB_USER, DB_PASSWORD, DB_NAME)
 *      — preferred in Docker and modern deployments.
 *   2. Values defined in this file (the literal "changeme" defaults below)
 *      — these will refuse to connect in production; replace locally for
 *        non-Docker dev only, never commit real values.
 *
 * Docker / docker-compose builds replace this file at image build time
 * with docker/db_connection.php (see Dockerfile), which is env-driven only.
 */

$servername = getenv('DB_HOST')     ?: 'localhost';
$username   = getenv('DB_USER')     ?: 'changeme_user';
$password   = getenv('DB_PASSWORD') ?: 'changeme_password';
$database   = getenv('DB_NAME')     ?: 'impact_score';

// Production safety: refuse to start if placeholder credentials survived
// into a non-dev environment. APP_ENV is set by docker-compose / .env.
$appEnv = getenv('APP_ENV') ?: 'production';
if ($appEnv === 'production') {
    if ($password === 'changeme_password' || $username === 'changeme_user') {
        error_log('[CRITICAL] secure/db_connection.php: placeholder credentials detected in production. Refusing to connect.');
        http_response_code(500);
        die('Server configuration error. See server logs.');
    }
}

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Database connection failed. See server logs.');
}

$conn->set_charset('utf8mb4');

// Admin-password preflight: db/migrations/000_ensure_admin_user.sql seeds a
// bootstrap admin account with a known, documented password (admin /
// changeme). Refuse to serve in production until it's been rotated.
// See README.md -> Production secret preflight for how to rotate it.
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
            error_log('[CRITICAL] secure/db_connection.php: bootstrap admin password (admin / changeme) still active for: '
                . implode(', ', $offenders) . '. Refusing to serve.');
            http_response_code(500);
            die('Server configuration error. See server logs.');
        }
    } else {
        error_log('[WARN] Admin-password preflight could not run: ' . $conn->error);
    }
}
