<?php

/**
 * db_connect.php
 *
 * This file establishes a secure database connection for the application.
 * It relies on credentials stored outside the public directory (db_connection.php).
 * After a successful connection, the $conn variable is available for use in subsequent queries.
 *
 * Usage:
 *   - Include or require this file in any script that needs database access.
 *   - Example:
 *       require_once('db_connect.php');
 *       $result = $conn->query("SELECT * FROM some_table");
 *
 * Prerequisites:
 *   - A valid credentials file located at '/var/www/secure/db_connection.php'.
 *   - The $servername, $username, $password, and $database variables defined in the credentials file.
 *
 * Note:
 *   - Additional database-related setup or error handling can be added here if needed.
 *   - For production, consider how you handle error messages and logging (rather than using die()).
 */


// 1. Load the credentials file.
//    In the Docker image this resolves to /var/www/html/secure/db_connection.php
//    (env-driven, with the production secret preflight from Pass 4c built in).
//    Outside Docker, set the SECURE_DIR env var to point to a directory OUTSIDE
//    the web root, e.g. SECURE_DIR=/var/www/secure
$secureDir = getenv('SECURE_DIR') ?: __DIR__ . '/secure';
require_once($secureDir . '/db_connection.php');

// 2. db_connection.php constructs $conn itself (with preflight + error
//    handling). The $conn variable is now available to callers. No further
//    setup is needed here, but additional shared initialization can go below.


// Optional: Additional database-related setup can be added below if needed