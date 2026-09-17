<?php
/**
 * seed_dev_endpoint.php
 * 
 * Endpoint to execute db/seed_dev.sql on demand from the admin portal.
 * Only callable by authenticated admins. Returns JSON success/error response.
 */

session_start();

// Verify admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Check environment — refuse to seed in production
$env = getenv('APP_ENV') ?: 'development';
if ($env === 'production') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Seeding is disabled in production']);
    exit();
}

// Include database connection
require_once('db_connect.php');

// Read and execute the seed file
$seed_file = __DIR__ . '/db/seed_dev.sql';

if (!file_exists($seed_file)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Seed file not found']);
    exit();
}

$sql = file_get_contents($seed_file);

// Execute the seed script
// Split by semicolon and execute each statement
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function($stmt) { return !empty($stmt) && strpos($stmt, '--') !== 0; }
);

$errors = [];
foreach ($statements as $statement) {
    // Skip comment-only lines
    if (strpos(trim($statement), '--') === 0) {
        continue;
    }
    
    if (!$conn->query($statement)) {
        $errors[] = $conn->error;
    }
}

if (empty($errors)) {
    echo json_encode(['success' => true, 'message' => 'Development seed applied successfully']);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Some statements failed', 'details' => $errors]);
}
?>
