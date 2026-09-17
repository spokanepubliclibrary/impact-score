<?php
session_start();
require_once 'secure/db_connection.php';

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    error_log("Login failed: empty username or password");
    header("Location: admin_login.php?error=1");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

error_log("Login attempt for user: $username, rows found: " . $result->num_rows);

if ($result->num_rows === 1) {
    $admin = $result->fetch_assoc();
    error_log("Admin found. Hash: " . substr($admin['password'], 0, 20) . "...");
    error_log("Password verify result: " . (password_verify($password, $admin['password']) ? 'true' : 'false'));
    
    if (password_verify($password, $admin['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        error_log("Login successful for: $username");
        header("Location: admin_portal.php");
        exit();
    }
} else {
    error_log("User not found: $username");
}

error_log("Login failed for user: $username");
header("Location: admin_login.php?error=1");
exit();
?>
