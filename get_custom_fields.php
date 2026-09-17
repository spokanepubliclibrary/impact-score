<?php
/**
 * AJAX endpoint: returns active custom fields applicable to a given team and/or form.
 *
 * A field is included if:
 *   - It has NO team filters (applies to all teams), OR the selected team_id matches one of its team filters
 *   - AND it has NO form filters (applies to all forms), OR the selected form_id matches one of its form filters
 *
 * GET params: team_id (int), form_id (int)
 * Returns: JSON array of { field_id, field_label, field_key, description, is_required, options: [{option_id, option_label}] }
 */

header('Content-Type: application/json');

require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit();
}

$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
$form_id = isset($_GET['form_id']) ? (int)$_GET['form_id'] : 0;

// Load all active fields
$fields = [];
$res = $conn->query("SELECT field_id, field_label, field_key, description, is_required FROM custom_fields WHERE is_active = 1 ORDER BY display_order ASC, field_id ASC");
if (!$res) {
    echo json_encode([]);
    exit();
}
while ($row = $res->fetch_assoc()) {
    $fields[(int)$row['field_id']] = $row;
}
$res->free();

if (empty($fields)) {
    echo json_encode([]);
    exit();
}

$field_ids = array_keys($fields);
$ids_str = implode(',', $field_ids);

// Load team filters for these fields
$team_filters = []; // field_id => [team_id, ...]
$res = $conn->query("SELECT field_id, team_id FROM custom_field_team_filter WHERE field_id IN ($ids_str)");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $team_filters[(int)$row['field_id']][] = (int)$row['team_id'];
    }
    $res->free();
}

// Load form filters for these fields
$form_filters = []; // field_id => [form_id, ...]
$res = $conn->query("SELECT field_id, form_id FROM custom_field_form_filter WHERE field_id IN ($ids_str)");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $form_filters[(int)$row['field_id']][] = (int)$row['form_id'];
    }
    $res->free();
}

// Load options for all fields
$all_options = []; // field_id => [{option_id, option_label}, ...]
$res = $conn->query("SELECT field_id, option_id, option_label FROM custom_field_options WHERE field_id IN ($ids_str) ORDER BY display_order ASC, option_id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $all_options[(int)$row['field_id']][] = [
            'option_id'    => (int)$row['option_id'],
            'option_label' => $row['option_label'],
        ];
    }
    $res->free();
}

$conn->close();

// Filter fields by team/form applicability
$result = [];
foreach ($fields as $fid => $field) {
    // Team check: no filters = applies to all; otherwise must match
    $tf = $team_filters[$fid] ?? [];
    if (!empty($tf) && $team_id > 0 && !in_array($team_id, $tf, true)) {
        continue;
    }

    // Form check: no filters = applies to all; otherwise must match
    $ff = $form_filters[$fid] ?? [];
    if (!empty($ff) && $form_id > 0 && !in_array($form_id, $ff, true)) {
        continue;
    }

    $result[] = [
        'field_id'    => $fid,
        'field_label' => $field['field_label'],
        'field_key'   => $field['field_key'],
        'description' => $field['description'],
        'is_required' => (bool)$field['is_required'],
        'options'     => $all_options[$fid] ?? [],
    ];
}

echo json_encode($result);
