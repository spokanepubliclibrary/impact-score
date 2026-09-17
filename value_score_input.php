<?php
require_once 'manage_columns.php';

// Fetch all columns from the 'columns' table
$columns_result = $conn->query("SELECT name, formula FROM columns");
$columns = [];
while ($row = $columns_result->fetch_assoc()) {
    $columns[] = $row;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_scores'])) {
    $values = $_POST['values'];

// Ensure all empty inputs are stored as 0 instead of NULL
foreach ($values as $key => $value) {
    $values[$key] = ($value === "") ? 0 : $value; 
}

$query = "INSERT INTO scores (" . implode(", ", array_keys($values)) . ") VALUES (";
$query .= implode(", ", array_map(fn($v) => is_numeric($v) ? $v : "'" . $conn->real_escape_string($v) . "'", array_values($values))) . ")";

    
    if ($conn->query($query) === TRUE) {
        echo "Scores saved successfully!";
    } else {
        echo "Error saving scores: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Value Score Input</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f4f4f4; }
        input { width: 100%; padding: 5px; }
    </style>
</head>
<body>
    <h2>Enter Values</h2>
    <form method="POST">
        <table>
            <tr>
                <?php foreach ($columns as $col): ?>
                    <th><?php echo htmlspecialchars($col['name']); ?></th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($columns as $col): ?>
                    <td><input type="text" name="values[<?php echo htmlspecialchars($col['name']); ?>]"></td>
                <?php endforeach; ?>
            </tr>
        </table>
        <button type="submit" name="submit_scores">Save Scores</button>
    </form>
</body>
</html>
