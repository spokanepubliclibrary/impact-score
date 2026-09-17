<?php
/**
 * import.php
 *
 * This script imports calendar data from a CSV file into the calendar_upload table.
 * It processes each row of the CSV file to:
 *   - Insert the raw "date_relative" string.
 *   - Parse and reformat the "date_relative" value into an absolute date for the "date" column.
 *   - Insert the remaining fields (title, creator, total attendance, attendance notes,
 *     private event, changed, status) into the table.
 *
 * Expected CSV headers (in order):
 *   Date (relative), Title, Creator, Total attendance, Attendance notes, Private event, Changed, Status
 *
 * Prerequisites:
 *   - A valid database credentials file at 'secure/db_connection.php'.
 *   - The calendar_upload table is already created with the following structure:
 *       date_relative VARCHAR(50),
 *       date DATE,
 *       title VARCHAR(255),
 *       creator VARCHAR(255),
 *       total_attendance INT,
 *       attendance_notes TEXT,
 *       private_event BOOLEAN,    
 *       changed VARCHAR(50),          
 *       status VARCHAR(50)
 */

// DB credentials
require_once('secure/db_connection.php');

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Process CSV file upload
if (isset($_POST['submit'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        // Read header row (optional: you can validate header names if needed)
        $headers = fgetcsv($handle);
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Extract CSV fields according to their position
            $date_relative    = $data[0] ?? null;
            $title            = $data[1] ?? null;
            $creator          = $data[2] ?? null;
            $total_attendance = isset($data[3]) ? intval($data[3]) : 0;
            $attendance_notes = $data[4] ?? null;
            $private_event    = $data[5] ?? '0';
            $changed          = $data[6] ?? null;
            $status           = $data[7] ?? null;
            
            // Attempt to parse an absolute date from the problematic "date_relative" value.
            // For example, if the value is "3 days ago" or "today", strtotime() may work.
            $date = null;
            if (!empty($date_relative)) {
                $timestamp = strtotime($date_relative);
                if ($timestamp !== false) {
                    $date = date('Y-m-d', $timestamp);
                }
            }
            
            // Convert "private_event" to a boolean value (1 for true, 0 for false)
            $private_event_bool = (strtolower($private_event) == 'yes' || $private_event == '1' || strtolower($private_event) == 'true') ? 1 : 0;
            
            // Prepare SQL insert statement for the calendar_upload table.
            $stmt = $conn->prepare(
                "INSERT INTO calendar_upload 
                (date_relative, date, title, creator, total_attendance, attendance_notes, private_event, changed, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            // Bind parameters:
            // date_relative: string, date: string, title: string, creator: string,
            // total_attendance: int, attendance_notes: string, private_event: int,
            // changed: string, status: string.
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
        echo "✅ CSV file imported successfully!";
    } else {
        echo "⚠️ Failed to open the CSV file.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CSV Import - Calendar Data</title>
    <!-- Include Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Include Montserrat Font -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background: #f5f2ec;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            width: 100%;
            margin: auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .btn, button {
            background-color: #480d3c;
            color: #fff;
            border: none;
            border-radius: 5px;
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover, button:hover {
            background-color: #bb1b51;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="text-center">📂 Import Calendar Data</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
            </div>
            <button type="submit" name="submit" class="btn btn-success">Upload &amp; Import CSV</button>
        </form>
    </div>
</body>
</html>
