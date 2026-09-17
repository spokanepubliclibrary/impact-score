<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: ../admin_login.php");
    exit();
}

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$bid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$prePerformer = isset($_GET['performer_id']) ? (int)$_GET['performer_id'] : 0;

$booking = [];
$errors  = [];
$success = '';

// Load existing booking
if ($bid > 0) {
    $stmt = $conn->prepare("SELECT * FROM performer_bookings WHERE booking_id = ?");
    $stmt->bind_param("i", $bid); $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$booking) die("Booking not found.");
    $prePerformer = $prePerformer ?: $booking['performer_id'];
}

// Fetch all active performers for dropdown
$performers = $conn->query("SELECT performer_id, stage_name FROM performers WHERE status='active' ORDER BY stage_name")->fetch_all(MYSQLI_ASSOC);

// Fetch programs for selected performer (populated via JS or PHP)
$programs = [];
if ($prePerformer > 0) {
    $stmt = $conn->prepare("SELECT program_id, program_title FROM performer_programs WHERE performer_id=? AND active=1 ORDER BY program_title");
    $stmt->bind_param("i", $prePerformer); $stmt->execute();
    $programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $perf_id     = (int)($_POST['performer_id'] ?? 0);
    $prog_id     = ($_POST['program_id'] ?? '') !== '' ? (int)$_POST['program_id'] : null;
    $event_title = trim($_POST['event_title'] ?? '');
    $branch      = trim($_POST['branch_name'] ?? '');
    $room        = trim($_POST['room_name'] ?? '');
    $event_date  = $_POST['event_date'] ?? '';
    $start_time  = ($_POST['start_time'] ?? '') ?: null;
    $end_time    = ($_POST['end_time'] ?? '') ?: null;
    $attendance  = ($_POST['attendance_count'] ?? '') !== '' ? (int)$_POST['attendance_count'] : null;
    $audience    = trim($_POST['target_audience'] ?? '');
    $agreed_fee  = ($_POST['agreed_fee'] ?? '') !== '' ? (float)$_POST['agreed_fee'] : null;
    $travel_fee  = ($_POST['travel_fee'] ?? '') !== '' ? (float)$_POST['travel_fee'] : null;
    $mat_fee     = ($_POST['materials_fee'] ?? '') !== '' ? (float)$_POST['materials_fee'] : null;
    $total_cost  = ($_POST['total_cost'] ?? '') !== '' ? (float)$_POST['total_cost'] : null;
    $status      = $_POST['booking_status'] ?? 'inquiry';
    $staff       = trim($_POST['booked_by_staff_name'] ?? '');
    $notes       = trim($_POST['internal_notes'] ?? '');

    $contract_sent   = ($_POST['contract_sent_date'] ?? '') ?: null;
    $contract_signed = ($_POST['contract_signed_date'] ?? '') ?: null;
    $invoice_recv    = ($_POST['invoice_received_date'] ?? '') ?: null;
    $payment_sent    = ($_POST['payment_sent_date'] ?? '') ?: null;
    $payment_cleared = ($_POST['payment_cleared_date'] ?? '') ?: null;

    if ($perf_id <= 0) $errors[] = "Please select a performer.";
    if ($event_title === '') $errors[] = "Event title is required.";
    if ($event_date === '') $errors[] = "Event date is required.";

    if (empty($errors)) {
        // Type string key: i=int, s=string, d=double/decimal
        // Fields: perf_id(i) prog_id(i) event_title(s) branch(s) room(s)
        //         event_date(s) start_time(s) end_time(s) attendance(i) audience(s)
        //         agreed_fee(d) travel_fee(d) mat_fee(d) total_cost(d)
        //         contract_sent(s) contract_signed(s) invoice_recv(s) payment_sent(s) payment_cleared(s)
        //         status(s) staff(s) notes(s)
        if ($bid > 0) {
            $stmt = $conn->prepare("
                UPDATE performer_bookings SET performer_id=?, program_id=?, event_title=?, branch_name=?, room_name=?,
                event_date=?, start_time=?, end_time=?, attendance_count=?, target_audience=?,
                agreed_fee=?, travel_fee=?, materials_fee=?, total_cost=?,
                contract_sent_date=?, contract_signed_date=?, invoice_received_date=?, payment_sent_date=?, payment_cleared_date=?,
                booking_status=?, booked_by_staff_name=?, internal_notes=?
                WHERE booking_id=?
            ");
            // 22 SET params + 1 WHERE = 23 total
            $stmt->bind_param("iissssssisddddssssssssi",
                $perf_id, $prog_id, $event_title, $branch, $room,
                $event_date, $start_time, $end_time, $attendance, $audience,
                $agreed_fee, $travel_fee, $mat_fee, $total_cost,
                $contract_sent, $contract_signed, $invoice_recv, $payment_sent, $payment_cleared,
                $status, $staff, $notes, $bid
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO performer_bookings (performer_id, program_id, event_title, branch_name, room_name,
                event_date, start_time, end_time, attendance_count, target_audience,
                agreed_fee, travel_fee, materials_fee, total_cost,
                contract_sent_date, contract_signed_date, invoice_received_date, payment_sent_date, payment_cleared_date,
                booking_status, booked_by_staff_name, internal_notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param("iissssssisddddssssssss",
                $perf_id, $prog_id, $event_title, $branch, $room,
                $event_date, $start_time, $end_time, $attendance, $audience,
                $agreed_fee, $travel_fee, $mat_fee, $total_cost,
                $contract_sent, $contract_signed, $invoice_recv, $payment_sent, $payment_cleared,
                $status, $staff, $notes
            );
        }
        if ($stmt->execute()) {
            $new_bid = $bid ?: $conn->insert_id;
            $stmt->close();
            $conn->close();
            header("Location: booking_view.php?id={$new_bid}&msg=saved");
            exit();
        } else {
            $errors[] = "Database error: " . $stmt->error;
            $stmt->close();
        }
    }
}

$conn->close();
$isNew = ($bid === 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isNew ? 'New Booking' : 'Edit Booking' ?> — Performers</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; }
    .btn-custom { background-color: #480d3c; color: #fff; border-color: #480d3c; }
    .btn-custom:hover { background-color: #bb1b51; border-color: #bb1b51; color: #fff; }
    .section-header { background-color: #480d3c; color: #fff; padding: .5rem 1rem; border-radius: 6px; margin: 1.5rem 0 1rem; }
    .form-card { background: #fff; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  </style>
</head>
<body>
<div class="container mt-4" style="max-width: 900px;">
  <div class="mb-3">
    <a href="booking_list.php" class="btn btn-secondary btn-sm">&#8592; Booking List</a>
    <?php if ($prePerformer): ?>
      <a href="view.php?id=<?= $prePerformer ?>&tab=bookings" class="btn btn-outline-secondary btn-sm ms-2">Performer Profile</a>
    <?php endif; ?>
  </div>

  <h2 style="color:#480d3c;"><?= $isNew ? 'New Booking' : 'Edit Booking' ?></h2>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="form-card">
      <div class="section-header">Event Details</div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Performer <span class="text-danger">*</span></label>
          <select name="performer_id" id="performer_id" class="form-select" required onchange="loadPrograms(this.value)">
            <option value="">Select performer</option>
            <?php foreach ($performers as $perf): ?>
              <option value="<?= $perf['performer_id'] ?>" <?= ($booking['performer_id'] ?? $prePerformer) == $perf['performer_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($perf['stage_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Program</label>
          <select name="program_id" id="program_id" class="form-select">
            <option value="">— No specific program —</option>
            <?php foreach ($programs as $prog): ?>
              <option value="<?= $prog['program_id'] ?>" <?= ($booking['program_id'] ?? '') == $prog['program_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($prog['program_title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label">Event Title <span class="text-danger">*</span></label>
          <input type="text" name="event_title" class="form-control" required value="<?= htmlspecialchars($booking['event_title'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="booking_status" class="form-select">
            <?php foreach (['inquiry','tentative','confirmed','completed','cancelled','no_show'] as $s): ?>
              <option value="<?= $s ?>" <?= ($booking['booking_status'] ?? 'inquiry') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Branch</label>
          <input type="text" name="branch_name" class="form-control" value="<?= htmlspecialchars($booking['branch_name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Room</label>
          <input type="text" name="room_name" class="form-control" value="<?= htmlspecialchars($booking['room_name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Target Audience</label>
          <input type="text" name="target_audience" class="form-control" value="<?= htmlspecialchars($booking['target_audience'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Event Date <span class="text-danger">*</span></label>
          <input type="date" name="event_date" class="form-control" required value="<?= htmlspecialchars($booking['event_date'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Start Time</label>
          <input type="time" name="start_time" class="form-control" value="<?= htmlspecialchars($booking['start_time'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">End Time</label>
          <input type="time" name="end_time" class="form-control" value="<?= htmlspecialchars($booking['end_time'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Attendance</label>
          <input type="number" name="attendance_count" class="form-control" min="0" value="<?= htmlspecialchars($booking['attendance_count'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Booked By (Staff Name)</label>
          <input type="text" name="booked_by_staff_name" class="form-control" value="<?= htmlspecialchars($booking['booked_by_staff_name'] ?? '') ?>">
        </div>
      </div>

      <div class="section-header">Fees</div>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Agreed Fee</label>
          <div class="input-group"><span class="input-group-text">$</span>
            <input type="number" name="agreed_fee" step="0.01" min="0" class="form-control" value="<?= htmlspecialchars($booking['agreed_fee'] ?? '') ?>">
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Travel Fee</label>
          <div class="input-group"><span class="input-group-text">$</span>
            <input type="number" name="travel_fee" step="0.01" min="0" class="form-control" value="<?= htmlspecialchars($booking['travel_fee'] ?? '') ?>">
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Materials Fee</label>
          <div class="input-group"><span class="input-group-text">$</span>
            <input type="number" name="materials_fee" step="0.01" min="0" class="form-control" value="<?= htmlspecialchars($booking['materials_fee'] ?? '') ?>">
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Total Cost</label>
          <div class="input-group"><span class="input-group-text">$</span>
            <input type="number" name="total_cost" id="total_cost" step="0.01" min="0" class="form-control" value="<?= htmlspecialchars($booking['total_cost'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="section-header">Contract &amp; Payment Milestones</div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Contract Sent</label>
          <input type="date" name="contract_sent_date" class="form-control" value="<?= htmlspecialchars($booking['contract_sent_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Contract Signed</label>
          <input type="date" name="contract_signed_date" class="form-control" value="<?= htmlspecialchars($booking['contract_signed_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Invoice Received</label>
          <input type="date" name="invoice_received_date" class="form-control" value="<?= htmlspecialchars($booking['invoice_received_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Payment Sent</label>
          <input type="date" name="payment_sent_date" class="form-control" value="<?= htmlspecialchars($booking['payment_sent_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Payment Cleared</label>
          <input type="date" name="payment_cleared_date" class="form-control" value="<?= htmlspecialchars($booking['payment_cleared_date'] ?? '') ?>">
        </div>
      </div>

      <div class="section-header">Notes</div>
      <textarea name="internal_notes" class="form-control" rows="4" placeholder="Internal notes…"><?= htmlspecialchars($booking['internal_notes'] ?? '') ?></textarea>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button type="submit" class="btn btn-custom"><?= $isNew ? 'Create Booking' : 'Save Changes' ?></button>
      <a href="booking_list.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<script>
function loadPrograms(performerId) {
  const sel = document.getElementById('program_id');
  sel.innerHTML = '<option value="">— No specific program —</option>';
  if (!performerId) return;
  fetch('ajax_programs.php?performer_id=' + performerId)
    .then(r => r.json())
    .then(progs => {
      progs.forEach(function(p) {
        const opt = document.createElement('option');
        opt.value = p.program_id;
        opt.textContent = p.program_title;
        sel.appendChild(opt);
      });
    });
}

// Auto-sum fees
['agreed_fee','travel_fee','materials_fee'].forEach(function(id) {
  document.querySelector('[name="'+id+'"]').addEventListener('input', function() {
    const a = parseFloat(document.querySelector('[name="agreed_fee"]').value)||0;
    const t = parseFloat(document.querySelector('[name="travel_fee"]').value)||0;
    const m = parseFloat(document.querySelector('[name="materials_fee"]').value)||0;
    document.getElementById('total_cost').value = (a+t+m).toFixed(2);
  });
});
</script>
</body>
</html>
