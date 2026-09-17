<?php
/**
 * View Submitted Scores Page
 *
 * This page displays a dynamic, filterable table of submitted scores.
 * It supports AJAX-based inline attendance editing, deletion, and duplication.
 * Filters include user, team, program, and date range. It also allows for
 * paginated result limits.
 *
 * Features:
 *  - Loads data from the `scores` table with joined user/team names.
 *  - Supports dynamic filtering via GET parameters.
 *  - AJAX functionality for:
 *      - Updating attendance in place
 *      - Deleting a score row
 *      - Duplicating an existing score row
 *  - Error logging for debugging during development
 *
 * Prerequisites:
 *  - A valid MySQL connection via `secure/db_connection.php`
 *  - Tables: `scores`, `users`, `teams`
 *
 * @package ScoreViewer
 * @version 1.0
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// DB credentials
require_once('secure/db_connection.php');

// Connect to MySQL
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional: allow long concatenated name lists
$conn->query("SET SESSION group_concat_max_len = 4096");

session_start();

// Capture active filters and build a reusable query string
$filterKeys = ['user_id','team','location_id','program','start_date','end_date','limit'];
$activeFilters = [];
foreach ($filterKeys as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
        $activeFilters[$k] = $_GET[$k];
    }
}
$filterQS = http_build_query($activeFilters); // e.g., user_id=13&team=Adult%20Services...

if (isset($_GET['team_id'])) {
    $_SESSION['team_id'] = (int) $_GET['team_id']; // Store in session
}
error_log("Step 1 - team_id immediately after assignment: " . ($team_id ?? 'MISSING'));

// Debugging: Show values in session
error_log("Debugging - GET team_id: " . ($_GET['team_id'] ?? 'MISSING'));
error_log("Debugging - SESSION team_id before assignment: " . ($_SESSION['team_id'] ?? 'MISSING'));

$team_id = $_SESSION['team_id'] ?? null;
error_log("Step 1 - team_id immediately after assignment: " . ($team_id ?? 'MISSING'));

// Debugging: Verify final assignment
error_log("Debugging - Final team_id used: " . ($team_id ?? 'MISSING'));

// ✅ Retrieve form data safely (kept for future use/consistency)
$userId      = $_POST['user_id']      ?? $_GET['user_id']      ?? null;
$team_id     = isset($_GET['team_id']) ? (int)$_GET['team_id'] : ($_SESSION['team_id'] ?? null);
$formId      = $_POST['form_id']      ?? $_GET['form_id']      ?? 0;
$programDate = $_POST['program_date'] ?? $_GET['program_date'] ?? null;
$programName = $_POST['program_name'] ?? $_GET['program_name'] ?? 'Unknown Program';
$totalScore  = $_POST['total_score']  ?? $_GET['total_score']  ?? 0; // ensure never NULL

error_log("Debug: view_scores.php - team_id from POST = " . ($_POST['team_id'] ?? 'MISSING'));
error_log("Debug: view_scores.php - team_id from GET = " . ($_GET['team_id'] ?? 'MISSING'));

// ✅ Fetch unique user names for the dropdown
$nameQuery = $conn->query("SELECT DISTINCT id, name FROM users ORDER BY name ASC");
$users = [];
while ($row = $nameQuery->fetch_assoc()) {
    $users[] = $row;
}

// ✅ Fetch locations for filter dropdown
$locationQuery = $conn->query("SELECT id, name FROM locations ORDER BY name ASC");
$locations = [];
while ($row = $locationQuery->fetch_assoc()) {
    $locations[] = $row;
}

// ✅ Handle filters (use aliases consistently: s, t, l)
$whereClauses = [];
$params = [];
$types = "";

if (!empty($_GET['user_id'])) {
    // match either via score_users or the legacy scores.user_id
    $whereClauses[] = "(s.id IN (SELECT score_id FROM score_users WHERE user_id = ?) OR s.user_id = ?)";
    $params[] = (int)$_GET['user_id'];
    $params[] = (int)$_GET['user_id'];
    $types .= "ii";
}
if (!empty($_GET['team'])) {
    $whereClauses[] = "t.name = ?";
    $params[] = $_GET['team'];
    $types .= "s";
}
if (!empty($_GET['program'])) {
    // ✅ your table uses `program`
    $whereClauses[] = "s.program LIKE ?";
    $params[] = "%" . $_GET['program'] . "%";
    $types .= "s";
}
if (!empty($_GET['start_date'])) {
    $whereClauses[] = "s.program_date >= ?";
    $params[] = $_GET['start_date'];
    $types .= "s";
}
if (!empty($_GET['end_date'])) {
    $whereClauses[] = "s.program_date <= ?";
    $params[] = $_GET['end_date'];
    $types .= "s";
}
if (!empty($_GET['location_id'])) {
    $whereClauses[] = "s.location_id = ?";
    $params[] = (int) $_GET['location_id'];
    $types .= "i";
}

// ✅ Base SELECT (no ORDER/LIMIT here)
$sql = "
SELECT
  s.id,
  s.team_id,
  s.user_id,
  s.location_id,
  s.program,          -- changed from program_name
  s.program_date,
  s.total_score,
  s.attendance,
  s.scaled_attendance,
  s.adjusted_impact_score,

  t.name AS team_name,
  l.name AS location_name,

  GROUP_CONCAT(DISTINCT staff.name ORDER BY staff.name SEPARATOR ', ') AS staff_names
FROM scores s
LEFT JOIN teams t     ON t.id = s.team_id
LEFT JOIN locations l ON l.id = s.location_id

LEFT JOIN (
  -- primary user on the score
  SELECT s2.id AS score_id, u1.name
  FROM scores s2
  JOIN users u1 ON u1.id = s2.user_id

  UNION ALL

  -- additional users via score_users
  SELECT su.score_id, u2.name
  FROM score_users su
  JOIN users u2 ON u2.id = su.user_id
) staff ON staff.score_id = s.id
";

// ✅ WHERE (once)
$whereSql = !empty($whereClauses) ? (" WHERE " . implode(" AND ", $whereClauses)) : "";

// ✅ GROUP BY + ORDER BY
$orderSql = " GROUP BY s.id ORDER BY s.program_date DESC, s.id DESC";

// ✅ LIMIT: default 50, no placeholders
$limitSel = (string)($_GET['limit'] ?? '50'); // keep for dropdown state
$limitSql = "";
if ($limitSel !== 'all') {
    $limitInt = (int)$limitSel;
    if ($limitInt < 1) { $limitInt = 50; }
    $limitSql = " LIMIT " . $limitInt;
}

// ✅ Final SQL
$sql = $sql . $whereSql . $orderSql . $limitSql;

error_log("Final SQL Query: " . $sql);

// ✅ Prepare the statement
$select_stmt = $conn->prepare($sql);
if (!$select_stmt) {
    die("Select Prepare failed: " . $conn->error);
}

// ✅ Bind parameters dynamically
if (!empty($params)) {
    $select_stmt->bind_param($types, ...$params);
}

// ✅ Execute and fetch results
$select_stmt->execute();
$result = $select_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submitted Scores</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: 'Montserrat', Arial, sans-serif; margin: 20px; background-color: #f5f2ec; /* Parchment */ }
        .container { max-width: 1100px; background: #f5f2ec; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); margin: auto; }
        h2 { text-align: center; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #ccc; text-align: left; }
        th { background: #480d3c; color: #f5f2ec; }
        tr:nth-child(even) { background: #f9f9f9; }
        .filter-form { margin-bottom: 20px; text-align: center; }
        .filter-form select, .filter-form input, .filter-form button {
            padding: 8px; margin: 5px; border: 1px solid #ccc; border-radius: 4px;
        }
        .attendance-input { width: 60px; }
        .edit-link, .delete-link { color: #bb1b51; text-decoration: none; font-weight: bold; }
        .edit-link:hover, .delete-link:hover { text-decoration: underline; }
        .btn {
            display: inline-block;
            font-weight: 500;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.2s ease;
        }
        .btn-secondary { background-color: #480d3c; color: #fff; border: none; }
        .btn-secondary:hover { background-color: #bb1b51; color: #fff; }
    </style>
</head>
<body>
<!-- Back to Admin Panel Button -->
<div style="margin-bottom: 20px;">
    <a href="index.php" class="btn btn-secondary">⬅️ Back to Dashboard</a>
</div>
<div class="container">
    <h2>📊 Submitted Scores</h2>

    <!-- Filters -->
    <form class="filter-form" method="GET">
        <select name="user_id">
            <option value="">Filter by Name</option>
            <?php foreach ($users as $user): ?>
                <option value="<?= htmlspecialchars($user['id']) ?>" <?= ($_GET['user_id'] ?? '') == $user['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($user['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="team">
            <option value="">Filter by Team</option>
            <option value="Youth Services" <?= ($_GET['team'] ?? '') == 'Youth Services' ? 'selected' : '' ?>>Youth Services</option>
            <option value="Adult Services" <?= ($_GET['team'] ?? '') == 'Adult Services' ? 'selected' : '' ?>>Adult Services</option>
        </select>

        <select name="location_id">
            <option value="">Filter by Location</option>
            <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= ($_GET['location_id'] ?? '') == $loc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($loc['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="program" placeholder="Filter by Program" value="<?= htmlspecialchars($_GET['program'] ?? '') ?>">
        <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
        <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">

        <!-- Limit dropdown -->
        <label for="limit">Records to Display:</label>
        <select name="limit">
            <option value="10"  <?= $limitSel === '10'  ? 'selected' : '' ?>>10</option>
            <option value="50"  <?= $limitSel === '50'  ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $limitSel === '100' ? 'selected' : '' ?>>100</option>
            <option value="200" <?= $limitSel === '200' ? 'selected' : '' ?>>200</option>
            <option value="all" <?= $limitSel === 'all' ? 'selected' : '' ?>>All</option>
        </select>

        <button type="submit">Apply Filters</button>
        <a href="view_scores.php"><button type="button">Reset</button></a>
    </form>

    <!-- Score Table -->
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Team</th>
                <th>Location</th>
                <th>Program</th>
                <th>Total Score</th>
                <th>Date</th>
                <th>Attendance</th>
                <th>Scaled Attendance</th>
                <th>Adjusted Impact Score</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr data-score-id="<?= $row['id'] ?>">
                    <td><?= htmlspecialchars($row['id']) ?></td>
                    <td><?= htmlspecialchars($row['staff_names'] ?? 'No Name') ?></td>
                    <td><?= htmlspecialchars($row['team_name'] ?? 'Unknown') ?></td>
                    <td><?= htmlspecialchars($row['location_name'] ?? 'Unknown') ?></td>
                    <td><?= htmlspecialchars($row['program'] ?? 'Unknown') ?></td>
                    <td><strong><?= htmlspecialchars($row['total_score']) ?></strong></td>
                    <td>
                        <?php
                        $pd = $row['program_date'] ?? null;
                        echo !empty($pd) ? date("m/d/Y", strtotime($pd)) : "No Date";
                        ?>
                    </td>
                    <td>
                        <input type="number" class="attendance-input" data-id="<?= $row['id'] ?>"
                               min="0"
                               value="<?= $row['attendance'] === null ? '' : htmlspecialchars($row['attendance']) ?>">
                    </td>
                    <td class="scaled-attendance">
                        <?= is_null($row['scaled_attendance']) ? '' : number_format($row['scaled_attendance'], 2) ?>
                    </td>
                    <td class="adjusted-impact-score">
                        <?= is_null($row['adjusted_impact_score']) ? '' : number_format($row['adjusted_impact_score'], 2) ?>
                    </td>
                    <td>
                        <a href="edit_score.php?id=<?= $row['id'] ?>&return_url=<?= urlencode('view_scores.php' . ($filterQS ? "?$filterQS" : '')) ?>" class="edit-link">Edit</a> |
                        <a href="#" class="delete-link" data-id="<?= $row['id'] ?>">Delete</a> |
                        <a href="#" class="duplicate-link" data-id="<?= $row['id'] ?>">Duplicate</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
$(document).ready(function() {
    // 1) Handle attendance updates
    $(".attendance-input").on("change", function () {
        let row = $(this).closest("tr");
        let scoreId = row.data("score-id");
        let newAttendance = parseInt($(this).val()) || 0;
        if (newAttendance < 0) { $(this).val(0); newAttendance = 0; }

        $.ajax({
            url: "update_score.php",
            type: "POST",
            data: { id: scoreId, attendance: newAttendance },
            dataType: "json",
            success: function(response) {
                if (response.success) {
                    row.find(".scaled-attendance").text(response.scaled_attendance);
                    row.find(".adjusted-impact-score").text(response.adjusted_impact_score);
                } else {
                    alert("Failed to update attendance: " + response.error);
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error", {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                alert("Error communicating with the server.\nStatus: " + status + "\nError: " + error + "\nResponse: " + xhr.responseText);
            }
        });
    });

    // 2) Handle delete functionality
    $(".delete-link").on("click", function(event) {
        event.preventDefault();
        let scoreId = $(this).data("id");
        let row = $(this).closest("tr");

        if (!confirm("Are you sure you want to delete this score?")) return;

        $.ajax({
            url: "delete_score.php",
            type: "GET",
            data: { id: scoreId },
            dataType: "json",
            success: function(response) {
                if (response.success) {
                    row.fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert("Error deleting score: " + response.error);
                }
            },
            error: function() {
                alert("Failed to communicate with the server.");
            }
        });
    });

    // 3) Handle duplicate functionality
    $(".duplicate-link").on("click", function(event) {
        event.preventDefault();
        let scoreId = $(this).data("id");

        $.ajax({
            url: "duplicate_score.php",
            type: "GET",
            data: { id: scoreId },
            dataType: "json",
            success: function(response) {
                if (response.success) {
                    alert("Row duplicated successfully!");
                    location.reload();
                } else {
                    alert("Error duplicating row: " + response.error);
                }
            },
            error: function() {
                alert("Failed to communicate with the server.");
            }
        });
    });
});
</script>

</body>
</html>
