<?php
session_start();
// compare_report.php

/**
 * This script merges the functionality of:
 *  1. Importing CSV data into the `calendar_upload` table.
 *  2. Purging all data from `calendar_upload`.
 *  3. Filtering & displaying only the CSV-uploaded records.
 *  4. Checking if each CSV record is present in the `scores` table.
 *  5. Exporting an email of CSV events missing in the Impact Score App,
 *     using the user's name in the greeting if available.
 *  6. Needs PHPMailer installed before it will work.
 *  7. SMTP credentials need to be added to outlook_config.php
 *  8. This is an experimental file because I have been unable to test it. 
 */

// (Optional) Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Load DB credentials and establish a connection
require_once('secure/db_connection.php');

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function normalize_name($name) {
    return preg_replace('/\s+/', ' ', trim($name));
}

function get_team_name_by_user($conn, $username) {
    // Normalize input name
    $username = preg_replace('/\s+/', ' ', trim($username));

    if (!$conn || empty($username)) {
        return 'Unknown';
    }

    // Step 1: Check scores.team if non-numeric
    $stmt = $conn->prepare("
        SELECT s.team 
        FROM scores s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE TRIM(REPLACE(u.name, '  ', ' ')) = ?
        AND s.team IS NOT NULL AND s.team != ''
        ORDER BY s.program_date DESC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($team_from_scores);
        if ($stmt->fetch()) {
            $stmt->close();
            if (!is_numeric($team_from_scores)) {
                return $team_from_scores;
            } else {
            }
        } else {
            $stmt->close();
        }
    }

    // Step 2: Get default_team from users
    $default_team_id = null;
    $stmt = $conn->prepare("SELECT default_team FROM users WHERE TRIM(REPLACE(name, '  ', ' ')) = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($default_team_id);
        $hasResult = $stmt->fetch();
        $stmt->close();
    }

    // Step 3: Translate default_team_id to team name
    if (!empty($default_team_id)) {
        $stmt = $conn->prepare("SELECT name FROM teams WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $default_team_id);
            $stmt->execute();
            $stmt->bind_result($team_name);
            if ($stmt->fetch()) {
                $stmt->close();
                return $team_name;
            }
            $stmt->close();
        }
    }

    // Fallback if all else fails
    return 'Unknown';
}


// ----------------------------------------------------------------------
// PHPMailer Setup
// ----------------------------------------------------------------------
// Include Outlook configuration and PHPMailer classes
require_once('outlook_config.php'); // Contains $outlook_host, $outlook_port, $outlook_username, $outlook_password, $from_address, $from_name

// PHPMailer is installed via Composer; load the autoloader.
// `make composer-install` builds vendor/ before the Docker image is built;
// the Dockerfile's builder stage runs the same in CI.
require_once __DIR__ . '/vendor/autoload.php';

// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ----------------------------------------------------------------------
// SECTION 1: CSV IMPORT LOGIC
// ----------------------------------------------------------------------
$importMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_submit'])) {
    // Process CSV file upload
    $file = $_FILES['csv_file']['tmp_name'] ?? null;
    
    if ($file && ($handle = fopen($file, "r")) !== FALSE) {
        // Optional: Validate header row
        $headers = fgetcsv($handle);
        
        // Loop through each CSV row
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Extract CSV fields
            $date_relative    = $data[0] ?? null;
            $title            = $data[1] ?? null;
            $creator = preg_replace('/\s+/', ' ', trim($data[2] ?? ''));
            $total_attendance = isset($data[3]) ? intval($data[3]) : 0;
            $attendance_notes = $data[4] ?? null;
            $private_event    = $data[5] ?? '0';
            $changed          = $data[6] ?? null;
            $status           = $data[7] ?? null;
            
            // Attempt to parse an absolute date from the "date_relative" value
            $date = null;
            if (!empty($date_relative)) {
                $timestamp = strtotime($date_relative);
                if ($timestamp !== false) {
                    $date = date('Y-m-d', $timestamp);
                }
            }
            
            // Convert "private_event" to boolean
            $private_event_bool = (strtolower($private_event) == 'yes' 
                || $private_event == '1' 
                || strtolower($private_event) == 'true') ? 1 : 0;
            
            // Insert into calendar_upload
            $stmt = $conn->prepare(
                "INSERT INTO calendar_upload 
                (date_relative, date, title, creator, total_attendance, attendance_notes, private_event, changed, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "ssssissss", 
                $date_relative, 
                $date, 
                $title, 
                $creator, 
                $total_attendance, 
                $attendance_notes, 
                $private_event_bool, 
                $changed, 
                $status
            );
            $stmt->execute();
        }
        fclose($handle);
        $importMessage = "✅ CSV file imported successfully!";
        $_SESSION['csv_uploaded'] = true;
    } else {
        $importMessage = "⚠️ Failed to open the CSV file.";
    }
}

// ----------------------------------------------------------------------
// SECTION 2: PURGE CALENDAR DATA
// ----------------------------------------------------------------------
$purgeMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge'])) {
    if ($conn->query("DELETE FROM calendar_upload") === TRUE) {
        $purgeMessage = "All calendar data has been purged.";
    } else {
        $purgeMessage = "Error purging calendar data: " . $conn->error;
    }
}

// ----------------------------------------------------------------------
// SECTION 3: PREPARE DROPDOWNS & FILTERS
// ----------------------------------------------------------------------
// Get distinct users from `users` table for the User dropdown
$usersDropdown = [];
$userQuery = "SELECT DISTINCT name FROM users ORDER BY name";
$userResult = $conn->query($userQuery);
if ($userResult && $userResult->num_rows > 0) {
    while ($row = $userResult->fetch_assoc()) {
        $usersDropdown[] = $row['name'];
    }
}

// Get distinct teams from `teams` table for the Team dropdown
$teamsDropdown = [];
$teamQuery = "SELECT DISTINCT name FROM teams ORDER BY name";
$teamResult = $conn->query($teamQuery);
if ($teamResult && $teamResult->num_rows > 0) {
    while ($row = $teamResult->fetch_assoc()) {
        $teamsDropdown[] = $row['name'];
    }
}

// Grab filter parameters from GET
$user_filter    = isset($_GET['user']) ? $conn->real_escape_string(trim($_GET['user'])) : '';
$team_filter    = isset($_GET['team']) ? $conn->real_escape_string(trim($_GET['team'])) : '';
$program_filter = isset($_GET['program']) ? $conn->real_escape_string(trim($_GET['program'])) : '';
$start_date     = isset($_GET['start_date']) ? $conn->real_escape_string(trim($_GET['start_date'])) : '';
$end_date       = isset($_GET['end_date']) ? $conn->real_escape_string(trim($_GET['end_date'])) : '';

// Prepare a firstName variable for the email text
$firstName = "partner";
if (!empty($user_filter)) {
    // If user has multiple words, drop only the last word (assuming last word is last name)
    $nameParts = explode(" ", $user_filter);
    if (count($nameParts) > 1) {
        $firstName = implode(" ", array_slice($nameParts, 0, -1));
    } else {
        $firstName = $nameParts[0];
    }
}

// ----------------------------------------------------------------------
// SECTION 4: FETCH CALENDAR DATA (calendar_upload) - CSV Records
// ----------------------------------------------------------------------
$calendarEvents = [];
$scoresEvents = [];



$calendarQuery = "SELECT * FROM calendar_upload WHERE 1=1";
if ($user_filter) {
    $calendarQuery .= " AND creator = '$user_filter'";
}
if ($program_filter) {
    $calendarQuery .= " AND title LIKE '%$program_filter%'";
}
if ($start_date && $end_date) {
    $calendarQuery .= " AND date BETWEEN '$start_date' AND '$end_date'";
} elseif ($start_date) {
    $calendarQuery .= " AND date >= '$start_date'";
} elseif ($end_date) {
    $calendarQuery .= " AND date <= '$end_date'";
}

$calendarResult = $conn->query($calendarQuery);
$calendarEvents = [];
if ($calendarResult && $calendarResult->num_rows > 0) {
    while ($row = $calendarResult->fetch_assoc()) {
        $calendarEvents[] = [
    'name'          => trim($row['creator'] ?? ''),
    'date'          => $row['date'],
    'program_title' => trim($row['title'] ?? ''),
    'communico'     => true
];

    }
}

// ----------------------------------------------------------------------
// SECTION 5: FETCH SCORES DATA (scores + users)
// ----------------------------------------------------------------------
$scoresQuery = "SELECT s.*, u.name AS creator,
                COALESCE(t.name, s.team) AS team_name
                FROM scores s
                LEFT JOIN users u ON s.user_id = u.id
                LEFT JOIN teams t ON s.team_id = t.id
                WHERE 1=1";

if ($user_filter) {
    $scoresQuery .= " AND u.name = '$user_filter'";
}
if ($team_filter) {
    $team_filter_escaped = $conn->real_escape_string($team_filter);
    $scoresQuery .= " AND COALESCE(t.name, s.team) = '$team_filter_escaped'";
}
if ($program_filter) {
    $scoresQuery .= " AND s.program LIKE '%$program_filter%'";
}
if ($start_date && $end_date) {
    $scoresQuery .= " AND s.program_date BETWEEN '$start_date' AND '$end_date'";
} elseif ($start_date) {
    $scoresQuery .= " AND s.program_date >= '$start_date'";
} elseif ($end_date) {
    $scoresQuery .= " AND s.program_date <= '$end_date'";
}

$scoresResult = $conn->query($scoresQuery);
$scoresEvents = [];
if ($scoresResult && $scoresResult->num_rows > 0) {
    while ($row = $scoresResult->fetch_assoc()) {
        $scoresEvents[] = [
    'name'             => trim($row['creator'] ?? ''),
    'date'             => $row['program_date'],
    'program_title'    => trim($row['program'] ?? ''),
    'team_name'        => trim($row['team_name'] ?? ''),
    'impact_score_app' => true
];


    }
}

// ----------------------------------------------------------------------
// SECTION 6: FUZZY MATCHING (CSV-ONLY)
// ----------------------------------------------------------------------

// Define the fuzzy_match function
function fuzzy_match($str1, $str2, $threshold = 70) {
    similar_text(strtolower($str1), strtolower($str2), $percent);
    return ($percent >= $threshold);
}

$csvOnlyEvents = [];
foreach ($calendarEvents as $calEvent) {
    $foundMatch = false;
    $matchedTeam = '';

    foreach ($scoresEvents as $scoreEvent) {
        if (
            $calEvent['date'] == $scoreEvent['date'] &&
            fuzzy_match($calEvent['name'], $scoreEvent['name']) &&
            fuzzy_match($calEvent['program_title'], $scoreEvent['program_title'])
        ) {
            $foundMatch = true;
            $matchedTeam = $scoreEvent['team_name'] ?? '';
            break;
        }
    }

    $calEvent['impact_score_app'] = $foundMatch;

    if ($foundMatch) {
        $calEvent['team_name'] = $matchedTeam;
    } else {
        $calEvent['team_name'] = get_team_name_by_user($conn, $calEvent['name']);
    }

    // ✅ TEAM FILTER APPLIED *HERE*
    if (empty($team_filter) || $calEvent['team_name'] === $team_filter) {
        $csvOnlyEvents[] = $calEvent;
    }
}
 // END if csv_uploaded


// ----------------------------------------------------------------------
// SECTION 7: EXPORT AND SEND EMAIL (Missing from Impact Score App)
// ----------------------------------------------------------------------
$exportPrograms = [];
foreach ($csvOnlyEvents as $event) {
    if (!$event['impact_score_app']) {
        $exportPrograms[] = $event;
    }
}

$emailText = "";
if (isset($_GET['export']) && $_GET['export'] == 1) {
    // Build the email subject and body using the user's first name
    $subject = "Missing Impact Score Entries";
    $body = "Howdy, $firstName! Looks like these here events done showed up on the calendar but ain’t moseyed on over to the Impact Score App just yet.\r\n\r\n";
    foreach ($exportPrograms as $prog) {
        $body .= "Date: {$prog['date']}, Program: {$prog['program_title']}\r\n";
    }

    // Retrieve the user's email from the users table using the selected user_filter
    if (!empty($user_filter)) {
        $userEmailQuery = "SELECT email FROM users WHERE name = '" . $conn->real_escape_string($user_filter) . "' LIMIT 1";
        $emailResult = $conn->query($userEmailQuery);
        if ($emailResult && $emailResult->num_rows > 0) {
            $row = $emailResult->fetch_assoc();
            $to = $row['email'];
        } else {
            $to = "recipient@example.com"; // fallback email
        }
    } else {
        $to = "recipient@example.com"; // fallback if no user filter provided
    }

    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $outlook_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $outlook_username;
        $mail->Password   = $outlook_password;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = $outlook_port;

        // Recipients
        $mail->setFrom($from_address, $from_name);
        $mail->addAddress($to);

        // Email content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        $emailText = "Email has been sent successfully to $to.";
    } catch (Exception $e) {
        $emailText = "Email could not be sent. PHPMailer Error: " . $mail->ErrorInfo;
    }
}

// ----------------------------------------------------------------------
// HTML OUTPUT
// ----------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Compare & Import Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background: #f5f2ec;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .check { color: green; font-weight: bold; }
        .cross { color: red; font-weight: bold; }
        .export-text {
            white-space: pre-wrap;
            background: #eee;
            padding: 10px;
            border-radius: 5px;
        }
        .btn-submit-import {
            background-color: #28a745; /* success green */
            border-color: #28a745;
            color: #fff;
        }
        .btn-submit-import:hover {
            background-color: #218838;
            border-color: #1e7e34;
        }
        
        .btn {
    display: inline-block;
    font-weight: 500;
    padding: 8px 16px;
    text-decoration: none;
    border-radius: 5px;
    transition: background-color 0.2s ease;
}

.btn-secondary {
    background-color: #480d3c; /* Deep Plum */
    color: #fff;
    border: none;
}

.btn-secondary:hover {
    background-color: #bb1b51; /* Fuchsia */
    color: #fff;
}

    </style>
</head>
<body>
    <a href="stats.html" class="btn btn-secondary mb-3">⬅️ Back to Reports</a>
    <div class="container">
        <h2 class="text-center mb-4">Compare & Import Report</h2>
        
        <!-- CSV Import Form -->
        <form action="" method="post" enctype="multipart/form-data" class="mb-5">
            <h5>1. Upload & Import Calendar CSV</h5>
            <div class="mb-3">
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
            </div>
            <button type="submit" name="import_submit" class="btn btn-submit-import">Upload &amp; Import CSV</button>
            <?php if (!empty($importMessage)): ?>
                <div class="mt-3"><?php echo $importMessage; ?></div>
            <?php endif; ?>
        </form>
        
        <!-- Purge Calendar Data Button -->
        <form method="POST" 
              onsubmit="return confirm('Are you sure you want to purge all calendar data? This cannot be undone.');" 
              class="mb-4">
            <h5>2. Purge Existing Calendar Data</h5>
            <button type="submit" name="purge" value="1" class="btn btn-danger">Purge Calendar Data</button>
            <?php if (!empty($purgeMessage)): ?>
                <span class="ms-3"><?php echo $purgeMessage; ?></span>
            <?php endif; ?>
        </form>
        
        <!-- Filter Form -->
        <h5>3. Compare Calendar & Impact Score Data</h5>
        <form method="GET" class="mb-4">
            <!-- Row 1: User, Team, Program -->
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label for="user" class="form-label">User</label>
                    <select name="user" id="user" class="form-control">
                        <option value="">-- All Users --</option>
                        <?php foreach ($usersDropdown as $userName): ?>
                            <option value="<?php echo htmlspecialchars($userName); ?>" 
                                <?php if ($user_filter == $userName) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($userName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="team" class="form-label">Team</label>
                    <select name="team" id="team" class="form-control">
                        <option value="">-- All Teams --</option>
                        <?php foreach ($teamsDropdown as $teamName): ?>
                            <option value="<?php echo htmlspecialchars($teamName); ?>" 
                                <?php if ($team_filter == $teamName) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($teamName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="program" class="form-label">Program</label>
                    <input type="text" name="program" id="program" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($program_filter); ?>">
                </div>
            </div>
            
            <!-- Row 2: Start Date, End Date, Actions -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" name="start_date" id="start_date" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                
                <div class="col-md-4">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" name="end_date" id="end_date" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>1])); ?>" 
                       class="btn btn-secondary">
                       Export Email Text
                    </a>
                </div>
            </div>
        </form>
        
        <!-- If Export was requested, show the email text -->
        <?php if (isset($_GET['export']) && $_GET['export'] == 1): ?>
            <div class="mb-4">
                <h4>Email Text</h4>
                <div class="export-text"><?php echo htmlspecialchars($emailText); ?></div>
            </div>
        <?php endif; ?>
        
        <!-- Comparison Table (CSV records only) -->
        <table class="table table-bordered">
<thead>
    <tr>
        <th>Name</th>
        <th>Date</th>
        <th>Program Title</th>
        <th>Team</th> <!-- Add this -->
        <th>Communico</th>
        <th>Impact Score App</th>
    </tr>
</thead>
            <tbody>
    <?php foreach ($csvOnlyEvents as $event): ?>
        <tr>
            <td><?php echo htmlspecialchars($event['name']); ?></td>
            <td><?php echo htmlspecialchars($event['date']); ?></td>
            <td><?php echo htmlspecialchars($event['program_title']); ?></td>
            <td><?php echo htmlspecialchars($event['team_name'] ?? ''); ?></td> <!-- Add this -->
            <td class="text-center">
                <span class="check">&#10003;</span>
            </td>
            <td class="text-center">
                <?php echo $event['impact_score_app'] 
                    ? '<span class="check">&#10003;</span>' 
                    : '<span class="cross">&#10007;</span>'; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</tbody>

        </table>
    </div>
</body>
</html>
