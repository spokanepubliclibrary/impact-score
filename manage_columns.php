<?php
/**
 *
 * This script provides functions to dynamically add or remove columns
 * from the "scores" table, and it ensures that a record of these columns
 * is maintained in a separate "columns" table.
 *
 * Major Functions:
 *   - addColumn($column_name, $calc_formula = NULL):
 *       Checks if a column exists in the "scores" table; if not, it adds the
 *       column (of type FLOAT, allowing NULL values) and logs its name and an
 *       optional calculation formula into the "columns" table.
 *
 *   - removeColumn($column_name):
 *       Checks if the specified column exists in the "scores" table; if so,
 *       it drops the column and removes the corresponding record from the
 *       "columns" table.
 *
 * Additionally, the script ensures that the necessary table structures exist:
 *   - A "columns" table to store metadata about dynamically added columns.
 *   - A "scores" table where the dynamic columns will be added.
 *
 * Prerequisites:
 *   - A valid database credentials file at 'secure/db_connection.php'.
 *   - The "scores" table will be created if it does not exist.
 *   - The "columns" table will be created if it does not exist.
 *
 * Usage:
 *   - Include this script in your project to access the addColumn() and removeColumn()
 *     functions for dynamic schema changes.
 *
 * @package DynamicColumns
 * @version 1.0
 */

// --- Load Database Credentials ---
require_once('secure/db_connection.php');

// --- Create Database Connection ---
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * Adds a new column to the "scores" table dynamically.
 *
 * This function checks if a column with the specified name exists. If it does not,
 * it adds a new column of type FLOAT (allowing NULL values) to the "scores" table.
 * It also logs the column name and an optional calculation formula in the "columns" table.
 *
 * @param string $column_name  The desired name of the new column.
 * @param string|null $calc_formula  An optional calculation formula for the column.
 * @return void Outputs a message indicating success or error.
 */
function addColumn($column_name, $calc_formula = NULL) {
    global $conn;
    // Sanitize column name to allow only alphanumeric characters and underscores.
    $column_name = preg_replace("/[^a-zA-Z0-9_]/", "", $column_name);

    // Check if column already exists in the scores table.
    $check_query = "SHOW COLUMNS FROM scores LIKE '$column_name'";
    $check_result = $conn->query($check_query);
    
    if ($check_result->num_rows > 0) {
        echo "Error: Column '$column_name' already exists.";
        return;
    }

    // Add new column to the scores table.
    $query = "ALTER TABLE scores ADD `$column_name` FLOAT NULL";
    if ($conn->query($query) === TRUE) {
        echo "Column added successfully.";
    } else {
        echo "Error adding column: " . $conn->error;
    }

    // Log the new column in the columns table.
    $stmt = $conn->prepare("INSERT INTO columns (name, formula) VALUES (?, ?) ON DUPLICATE KEY UPDATE formula = VALUES(formula)");
    $stmt->bind_param("ss", $column_name, $calc_formula);
    $stmt->execute();
    $stmt->close();
}

/**
 * Removes an existing column from the "scores" table.
 *
 * This function checks if the specified column exists; if it does, it drops the column
 * from the "scores" table and removes its record from the "columns" table.
 *
 * @param string $column_name  The name of the column to remove.
 * @return void Outputs a message indicating success or error.
 */
function removeColumn($column_name) {
    global $conn;
    // Sanitize column name.
    $column_name = preg_replace("/[^a-zA-Z0-9_]/", "", $column_name);
    
    // Check if the column exists.
    $check_query = "SHOW COLUMNS FROM scores LIKE '$column_name'";
    $check_result = $conn->query($check_query);
    
    if ($check_result->num_rows == 0) {
        echo "Error: Column '$column_name' does not exist.";
        return;
    }
    
    // Drop the column from the scores table.
    $query = "ALTER TABLE scores DROP COLUMN `$column_name`";
    if ($conn->query($query) === TRUE) {
        // Remove the column record from the columns table.
        $stmt = $conn->prepare("DELETE FROM columns WHERE name = ?");
        $stmt->bind_param("s", $column_name);
        $stmt->execute();
        $stmt->close();
        echo "Column removed successfully.";
    } else {
        echo "Error removing column: " . $conn->error;
    }
}

// --- Create Required Tables If They Do Not Exist ---
// Create the "columns" table to store metadata for dynamic columns.
$conn->query("CREATE TABLE IF NOT EXISTS columns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE,
    formula TEXT NULL
)");

// Create the "scores" table if it doesn't exist. Additional columns can be added dynamically.
$conn->query("CREATE TABLE IF NOT EXISTS scores (
    id INT AUTO_INCREMENT PRIMARY KEY
)");
?>
