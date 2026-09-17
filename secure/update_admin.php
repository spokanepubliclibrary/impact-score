<?php
// Require admin login
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: ../admin_login.php");
    exit();
}

// Include database connection
require_once 'db_connection.php';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $newPassword = $_POST['new_password'];

    if (!empty($username) && !empty($newPassword)) {
        // Hash the new password securely
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Prepare the SQL update
        $sql = "UPDATE admins SET password = ? WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $hashedPassword, $username);

        if ($stmt->execute()) {
            echo "✅ Password updated successfully for user '$username'.";
        } else {
            echo "❌ Error updating password: " . $stmt->error;
        }

        $stmt->close();
    } else {
        echo "⚠️ Username and new password are required.";
    }
}
?>

<!-- HTML form with dynamic username dropdown -->
<h2>Update Admin Password</h2>
<form method="post" action="update_admin.php">
    <label for="username">Select Admin Username:</label>
    <select name="username" required>
        <option value="">-- Choose a username --</option>
        <?php
        // Fetch all admin usernames for dropdown
        $result = $conn->query("SELECT username FROM admins ORDER BY username");

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                echo '<option value="' . htmlspecialchars($row['username']) . '">' . htmlspecialchars($row['username']) . '</option>';
            }
        } else {
            echo '<option disabled>No admins found</option>';
        }

        $conn->close();
        ?>
    </select><br><br>

    <label for="new_password">New Password:</label>
    <input type="password" name="new_password" required><br><br>

    <input type="submit" value="Update Password">
</form>
