<?php
require_once 'secure/db_connection.php';
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) die("DB error");

// Official map
$VALID = [0,1,2,5,10];

// 1. Missing event types
$missing = $conn->query("
    SELECT s.id, s.program, s.program_date, s.team_id
    FROM scores s
    LEFT JOIN score_responses r 
      ON r.score_id = s.id AND r.question_id = 4
    WHERE r.response IS NULL
    ORDER BY s.program_date DESC
");

// 2. Non-numeric
$nonNumeric = $conn->query("
    SELECT s.id, s.program, s.program_date, r.response
    FROM score_responses r
    JOIN scores s ON r.score_id = s.id
    WHERE r.question_id = 4
      AND (r.response REGEXP '^[0-9]+$') = 0
");

// 3. Invalid numeric (not in official list)
$invalid = $conn->query("
    SELECT s.id, s.program, s.program_date, r.response
    FROM score_responses r
    JOIN scores s ON r.score_id = s.id
    WHERE r.question_id = 4
      AND r.response REGEXP '^[0-9]+$'
      AND r.response NOT IN (0,1,2,5,10)
");

// 4. Multiple event types on one score
$multi = $conn->query("
    SELECT score_id, COUNT(*) AS count_rows
    FROM score_responses
    WHERE question_id = 4
    GROUP BY score_id
    HAVING COUNT(*) > 1
");

// Output
function section($title) {
    echo "<h2 style='margin-top:40px;'>$title</h2>";
}

echo "<h1>Event Type Audit</h1>";
echo "<p>This shows all broken or inconsistent event type entries.</p>";

section("1. Missing Event Type (NULL)");
while ($r = $missing->fetch_assoc()) {
    echo "{$r['id']} — {$r['program']} ({$r['program_date']})<br>";
}

section("2. Non-Numeric Event Types");
while ($r = $nonNumeric->fetch_assoc()) {
    echo "{$r['id']} — {$r['program']} — response={$r['response']}<br>";
}

section("3. Invalid Numeric Event Types");
while ($r = $invalid->fetch_assoc()) {
    echo "{$r['id']} — {$r['program']} — response={$r['response']}<br>";
}

section("4. Multiple Event Type Entries for Same Score");
while ($r = $multi->fetch_assoc()) {
    echo "Score {$r['score_id']} has {$r['count_rows']} entries<br>";
}

echo "<p style='margin-top:40px;font-size:14px;'>
      Fix tool coming next — this page is read-only.</p>";
