<?php
/**
 * user_logout.php — Signs out the current staff user.
 * Destroys only user session keys; admin session (if active) is preserved.
 */
session_start();

// Remove user-specific session keys only
unset(
    $_SESSION['user_logged_in'],
    $_SESSION['user_id'],
    $_SESSION['user_name'],
    $_SESSION['user_email']
);

// Destroy full session only if admin is not also logged in
if (empty($_SESSION['admin_logged_in'])) {
    session_destroy();
}

header('Location: user_login.php?notice=loggedout');
exit;
