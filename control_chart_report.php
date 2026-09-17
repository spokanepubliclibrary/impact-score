<?php
/**
 * Impact Score Report Page
 *
 * This page generates an impact score report by retrieving score data from the database.
 * It allows filtering by user, team, program, and date range. The results are then
 * visualized using a Chart.js line chart.
 *
 * @package ImpactScoreReport
 * @version 1.1
 */

// --- Enable Error Reporting for Debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Database Connection ---
require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- Fetch Users for Dropdown ---
$userQuery = $conn->query("SELECT DISTINCT users.id, users.name FROM users 
                          LEFT JOIN scores ON users.id = scores.user_id 
                          ORDER BY users.name ASC");
$users = [];
while ($row = $userQuery->fetch_assoc()) {
    $users[] = $row;
}

// --- Build Filter Conditions ---
$whereClauses = [];
$params = [];
$types = "";

if (!empty($_GET['user_id'])) {
    // Match the primary user_id on the score row, OR a secondary
    // staff member recorded in score_users (multi-staff programs).
    $whereClauses[] = "(user_id = ? OR id IN (SELECT score_id FROM score_users WHERE user_id = ?))";
    $params[] = $_GET['user_id'];
    $params[] = $_GET['user_id'];
    $types .= "ii";
}
if (!empty($_GET['team'])) {
    $team_map = [
        'Youth Services' => 1,
        'Adult Services' => 2,
        'Community Technology' => 3
    ];
    if (isset($team_map[$_GET['team']])) {
        $whereClauses[] = "team_id = ?";
        $params[] = $team_map[$_GET['team']];
        $types .= "i";
    }
}
if (!empty($_GET['program'])) {
    $whereClauses[] = "program LIKE ?";
    $params[] = "%" . $_GET['program'] . "%";
    $types .= "s";
}
if (!empty($_GET['start_date'])) {
    $whereClauses[] = "program_date >= ?";
    $params[] = $_GET['start_date'];
    $types .= "s";
}
if (!empty($_GET['end_date'])) {
    $whereClauses[] = "program_date <= ?";
    $params[] = $_GET['end_date'];
    $types .= "s";
}
if (!empty($_GET['location_id'])) {
    $whereClauses[] = "location_id = ?";
    $params[] = $_GET['location_id'];
    $types .= "i";
}

// --- Execute Main Score Query ---
$sql = "SELECT program, adjusted_impact_score, location_id FROM scores";
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}
$sql .= " ORDER BY program_date ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$rawRows = [];
while ($row = $result->fetch_assoc()) {
    $rawRows[] = $row;
}
$stmt->close();

// --- Fetch Location Names ---
$locationNames = [];
$locRes = $conn->query("SELECT id, name FROM locations ORDER BY name ASC");
while ($row = $locRes->fetch_assoc()) {
    $locationNames[$row['id']] = $row['name'];
}
$conn->close();

// --- Prepare Chart Data ---
$programs = [];
$scores = [];

if (count($rawRows) === 0) {
    echo "<p style='color:red; font-weight:bold;'>⚠️ No data matched your filters. Try adjusting them.</p>";
}

foreach ($rawRows as $row) {
    $locId = $row['location_id'] ?? 0;
    $locName = $locationNames[$locId] ?? "Unknown";
    $programs[] = "{$row['program']} ({$locName})";
    $scores[] = $row['adjusted_impact_score'];
}

// --- Calculate Statistics ---
$mean = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;
$std_dev = count($scores) > 1 ? sqrt(array_sum(array_map(fn($x) => pow($x - $mean, 2), $scores)) / count($scores)) : 0;
$upper_limit = $mean + $std_dev;
$lower_limit = $mean - $std_dev;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Impact Score Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background-color: #f5f2ec;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin: auto;
        }
        h2 {
            text-align: center;
            color: #333;
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
            margin-bottom: 20px;
        }
        .filter-form select,
        .filter-form input,
        .filter-form button {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .filter-form button {
            background-color: #480d3c;
            color: white;
            border: none;
            cursor: pointer;
        }
        .filter-form button:hover {
            background-color: #bb1b51;
        }
        canvas {
            width: 100% !important;
            height: 500px !important;
        }
        .btn-secondary {
            background-color: #480d3c;
            color: white;
            border: none;
        }
        .btn-secondary:hover {
            background-color: #bb1b51;
        }
        .btn {
            display: inline-block;
            font-weight: 500;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.2s ease;
        }
    </style>
</head>
<body>
    <div style="margin-bottom: 20px;">
        <a href="stats.html" class="btn btn-secondary">⬅️ Back to Reports</a>
    </div>
    <div class="container">
        <h2>📊 Impact Score Report</h2>

        <form class="filter-form" method="GET">
            <select name="user_id">
                <option value="">Filter by User</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['id'] ?>" <?= ($_GET['user_id'] ?? '') == $user['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="team">
                <option value="">Filter by Team</option>
                <option value="Youth Services" <?= ($_GET['team'] ?? '') == 'Youth Services' ? 'selected' : '' ?>>Youth Services</option>
                <option value="Adult Services" <?= ($_GET['team'] ?? '') == 'Adult Services' ? 'selected' : '' ?>>Adult Services</option>
                <option value="Community Technology" <?= ($_GET['team'] ?? '') == 'Community Technology' ? 'selected' : '' ?>>Community Technology</option>
            </select>

            <select name="location_id">
                <option value="">Filter by Location</option>
                <?php foreach ($locationNames as $id => $name): ?>
                    <option value="<?= $id ?>" <?= ($_GET['location_id'] ?? '') == $id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="program" placeholder="Filter by Program" value="<?= htmlspecialchars($_GET['program'] ?? '') ?>">
            <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
            <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">

            <button type="submit">Apply Filters</button>
        </form>

        <canvas id="impactChart"></canvas>
    </div>

    <script>
        const ctx = document.getElementById('impactChart').getContext('2d');
        const dataLabels = <?= json_encode($programs) ?>;
        const impactScores = <?= json_encode($scores) ?>;
        const mean = <?= json_encode($mean) ?>;
        const upperLimit = <?= json_encode($upper_limit) ?>;
        const lowerLimit = <?= json_encode($lower_limit) ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: dataLabels,
                datasets: [
                    {
                        label: "Adjusted Impact Score",
                        data: impactScores,
                        borderColor: "#6395CF",
                        fill: false,
                        tension: 0.1
                    },
                    {
                        label: "Mean",
                        data: Array(dataLabels.length).fill(mean),
                        borderColor: "#7DB652",
                        borderDash: [5, 5]
                    },
                    {
                        label: "Upper Limit (+1 SD)",
                        data: Array(dataLabels.length).fill(upperLimit),
                        borderColor: "#E4781F",
                        borderDash: [5, 5]
                    },
                    {
                        label: "Lower Limit (-1 SD)",
                        data: Array(dataLabels.length).fill(lowerLimit),
                        borderColor: "#E4781F",
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: false
                    }
                }
            }
        });
    </script>
</body>
</html>
