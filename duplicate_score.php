<?php
/**
 * duplicate_score.php
 *
 * Duplicates a score and its related rows:
 *  - scores  (omits generated cols; keeps total_score; sets attendance = NULL)
 *  - score_responses (copies dynamically; excludes PK; swaps score_id)
 *  - score_users     (copies; excludes PK; swaps score_id; keeps multi-staff)
 *
 * JSON: { success:true, new_id:<int> } or { success:false, error:"..." }
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . $conn->connect_error]);
    exit;
}

$origId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($origId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid score id']);
    exit;
}

/* ===== helper (kept from your version) ===== */
function columnExists($conn, $database, $table, $column) {
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME   = ?
          AND COLUMN_NAME  = ?
        LIMIT 1
    ";
    if (!$stmt = $conn->prepare($sql)) return false;
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

// Helper: clone child rows by discovering columns
function cloneChildRows($conn, $database, $table, $pk, $scoreCol, $origId, $newId) {
    $q = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME   = ?
          AND COLUMN_NAME <> ?
        ORDER BY ORDINAL_POSITION
    ";
    $stmt = $conn->prepare($q);
    if (!$stmt) { throw new Exception("Describe $table failed: " . $conn->error); }
    $stmt->bind_param('sss', $database, $table, $pk);
    $stmt->execute();
    $res = $stmt->get_result();
    $cols = [];
    while ($r = $res->fetch_assoc()) { $cols[] = $r['COLUMN_NAME']; }
    $stmt->close();

    if (empty($cols)) return;

    $insertCols = implode(", ", array_map(fn($c) => "`$c`", $cols));

    $selectParts = [];
    foreach ($cols as $c) {
        $selectParts[] = ($c === $scoreCol) ? "? AS `$scoreCol`" : "`$c`";
    }
    $selectCols = implode(", ", $selectParts);

    $sql = "
        INSERT INTO `$table` ($insertCols)
        SELECT $selectCols
        FROM `$table`
        WHERE `$scoreCol` = ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { throw new Exception("Prepare clone for $table failed: " . $conn->error); }
    $stmt->bind_param('ii', $newId, $origId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("Clone $table failed: " . $err);
    }
    $stmt->close();
}

$conn->begin_transaction();

try {
    // 1) Fetch original (omit generated cols)
    $sqlFetch = "
        SELECT team_id, user_id, location_id, program, program_date, total_score, attendance
        FROM scores
        WHERE id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sqlFetch);
    if (!$stmt) { throw new Exception('Prepare fetch failed: ' . $conn->error); }
    $stmt->bind_param('i', $origId);
    $stmt->execute();
    $orig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orig) throw new Exception('Original score not found.');

    $teamId      = (int)$orig['team_id'];
    $primaryUser = (int)$orig['user_id'];
    $locationId  = (int)$orig['location_id'];
    $program     = (string)$orig['program'];
    $programDate = (string)$orig['program_date'];
    $totalScore  = is_null($orig['total_score']) ? null : (float)$orig['total_score'];

    // 2) Insert cloned base score (attendance starts NULL)
    $cleanAttendance = null;
    $sqlInsert = "
        INSERT INTO scores
            (team_id, user_id, location_id, program, program_date, total_score, attendance)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sqlInsert);
    if (!$stmt) { throw new Exception('Prepare insert failed: ' . $conn->error); }
    $stmt->bind_param('iiissdi',
        $teamId, $primaryUser, $locationId, $program, $programDate, $totalScore, $cleanAttendance
    );
    if (!$stmt->execute()) {
        throw new Exception('Insert new score failed: ' . $stmt->error);
    }
    $newId = $conn->insert_id;
    $stmt->close();

    // 3) Clone children
    cloneChildRows($conn, $database, 'score_responses', 'id', 'score_id', $origId, $newId);
    cloneChildRows($conn, $database, 'score_users',     'id', 'score_id', $origId, $newId);

    // Ensure primary user also present in score_users
    $sqlEnsurePrimary = "
        INSERT INTO score_users (score_id, user_id)
        SELECT ?, ?
        WHERE NOT EXISTS (SELECT 1 FROM score_users WHERE score_id = ? AND user_id = ?)
    ";
    if ($stmt = $conn->prepare($sqlEnsurePrimary)) {
        $stmt->bind_param('iiii', $newId, $primaryUser, $newId, $primaryUser);
        $stmt->execute();
        $stmt->close();
    }

    // NOTE: No explicit updates to generated columns.
    // If scaled_attendance/adjusted_impact_score are generated, they'll compute themselves.

    $conn->commit();
    echo json_encode(['success' => true, 'new_id' => $newId]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('Duplicate failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    $conn->close();
}
