
<?php
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// uncomment the following lines and change the values - then load the page for creation. 
// $username = 'superadmin'; // Change to your desired username
// $password_plain = 'NewPasswordHere'; // Change this to your desired password

// Hash the password securely
$password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);

// Insert into the admins table
$stmt = $conn->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
$stmt->bind_param("ss", $username, $password_hashed);

if ($stmt->execute()) {
    echo "✅ Admin user created successfully!";
} else {
    echo "❌ Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
