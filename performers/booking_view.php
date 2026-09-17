<?php
session_start();
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

$bid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($bid <= 0) { header("Location: booking_list.php"); exit(); }

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$stmt = $conn->prepare("
    SELECT pb.*, p.stage_name, p.performer_id, pp.program_title, pp.audience, pp.program_format
    FROM performer_bookings pb
    JOIN performers p ON pb.performer_id=p.performer_id
    LEFT JOIN performer_programs pp ON pb.program_id=pp.program_id
    WHERE pb.booking_id=?
");
$stmt->bind_param("i", $bid); $stmt->execute();
$b = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$b) { header("Location: booking_list.php"); exit(); }

// Review for this booking
$stmt = $conn->prepare("SELECT * FROM performer_reviews WHERE booking_id=? LIMIT 1");
$stmt->bind_param("i", $bid); $stmt->execute();
$review = $stmt->get_result()->fetch_assoc(); $stmt->close();

// Notes for this booking
$stmt = $conn->prepare("SELECT * FROM performer_notes WHERE booking_id=? ORDER BY created_at DESC");
$stmt->bind_param("i", $bid); $stmt->execute();
$notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Files for performer (filterable by booking association if needed)
$stmt = $conn->prepare("SELECT * FROM performer_files WHERE performer_id=? AND file_type IN ('contract','invoice') ORDER BY uploaded_at DESC LIMIT 10");
$stmt->bind_param("i", $b['performer_id']); $stmt->execute();
$files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Handle delete
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $d = $conn->prepare("DELETE FROM performer_bookings WHERE booking_id=?");
    $d->bind_param("i", $bid); $d->execute(); $d->close();
    $conn->close();
    header("Location: booking_list.php?msg=Booking+deleted");
    exit();
}

$conn->close();

function stars($r) { if (!$r) return '—'; $o=''; for($i=1;$i<=5;$i++) $o.=$i<=$r?'&#9733;':'&#9734;'; return '<span style="color:#bb1b51;">'.$o.'</span>'; }
$statusColors = ['inquiry'=>'secondary','tentative'=>'warning','confirmed'=>'primary','completed'=>'success','cancelled'=>'danger','no_show'=>'dark'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking: <?= htmlspecialchars($b['event_title']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    body { background-color: #f5f2ec; font-family: 'Montserrat', Arial, sans-serif; }
    .btn-custom { background-color: #480d3c; color: #fff; border-color: #480d3c; }
    .btn-custom:hover { background-color: #bb1b51; border-color: #bb1b51; color: #fff; }
    .booking-header { background: #480d3c; color: #fff; border-radius: 10px; padding: 1.5rem; margin-bottom: 1.5rem; }
    .card-section { background: #fff; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-bottom: 1rem; }
    .milestone { display: flex; align-items: center; gap: .5rem; margin-bottom: .3rem; }
    .milestone-done { color: #198754; }
    .milestone-pending { color: #adb5bd; }
    dt { font-weight: 600; color: #480d3c; }
  </style>
</head>
<body>
<div class="container mt-4">
  <div class="mb-3">
    <a href="booking_list.php" class="btn btn-secondary btn-sm">&#8592; Booking List</a>
    <a href="view.php?id=<?= $b['performer_id'] ?>&tab=bookings" class="btn btn-outline-secondary btn-sm ms-2"><?= htmlspecialchars($b['stage_name']) ?></a>
    <?php if ($isAdmin): ?>
      <a href="booking_edit.php?id=<?= $bid ?>" class="btn btn-custom btn-sm ms-2">Edit Booking</a>
      <?php if (!$review): ?>
        <a href="review_edit.php?booking_id=<?= $bid ?>&performer_id=<?= $b['performer_id'] ?>" class="btn btn-outline-secondary btn-sm ms-2">+ Add Review</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['msg']) ?></div>
  <?php endif; ?>

  <div class="booking-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <h2 class="mb-1"><?= htmlspecialchars($b['event_title']) ?></h2>
        <div class="opacity-75">
          <a href="view.php?id=<?= $b['performer_id'] ?>" style="color:#f5e0ef;"><?= htmlspecialchars($b['stage_name']) ?></a>
        </div>
        <?php if ($b['branch_name']): ?><div class="small opacity-75">&#128205; <?= htmlspecialchars($b['branch_name']) ?><?= $b['room_name'] ? ' &mdash; ' . htmlspecialchars($b['room_name']) : '' ?></div><?php endif; ?>
      </div>
      <div class="text-end">
        <span class="badge bg-<?= $statusColors[$b['booking_status']] ?? 'secondary' ?> fs-6"><?= ucfirst(str_replace('_',' ',$b['booking_status'])) ?></span>
        <?php if ($b['total_cost'] !== null): ?>
          <div class="mt-1 fs-5">$<?= number_format($b['total_cost'], 2) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-md-6">
      <div class="card-section">
        <h5>Event Details</h5>
        <dl class="row mb-0">
          <dt class="col-5">Date</dt><dd class="col-7"><?= htmlspecialchars($b['event_date']) ?></dd>
          <?php if ($b['start_time']): ?><dt class="col-5">Time</dt><dd class="col-7"><?= substr($b['start_time'],0,5) ?><?= $b['end_time'] ? ' – ' . substr($b['end_time'],0,5) : '' ?></dd><?php endif; ?>
          <?php if ($b['program_title']): ?><dt class="col-5">Program</dt><dd class="col-7"><?= htmlspecialchars($b['program_title']) ?></dd><?php endif; ?>
          <?php if ($b['target_audience']): ?><dt class="col-5">Audience</dt><dd class="col-7"><?= htmlspecialchars($b['target_audience']) ?></dd><?php endif; ?>
          <?php if ($b['attendance_count'] !== null): ?><dt class="col-5">Attendance</dt><dd class="col-7"><?= (int)$b['attendance_count'] ?></dd><?php endif; ?>
          <?php if ($b['booked_by_staff_name']): ?><dt class="col-5">Booked By</dt><dd class="col-7"><?= htmlspecialchars($b['booked_by_staff_name']) ?></dd><?php endif; ?>
        </dl>
      </div>

      <div class="card-section">
        <h5>Fees</h5>
        <dl class="row mb-0">
          <?php if ($b['agreed_fee'] !== null): ?><dt class="col-6">Agreed Fee</dt><dd class="col-6">$<?= number_format($b['agreed_fee'],2) ?></dd><?php endif; ?>
          <?php if ($b['travel_fee'] !== null): ?><dt class="col-6">Travel Fee</dt><dd class="col-6">$<?= number_format($b['travel_fee'],2) ?></dd><?php endif; ?>
          <?php if ($b['materials_fee'] !== null): ?><dt class="col-6">Materials Fee</dt><dd class="col-6">$<?= number_format($b['materials_fee'],2) ?></dd><?php endif; ?>
          <?php if ($b['total_cost'] !== null): ?><dt class="col-6"><strong>Total Cost</strong></dt><dd class="col-6"><strong>$<?= number_format($b['total_cost'],2) ?></strong></dd><?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card-section">
        <h5>Contract &amp; Payment Milestones</h5>
        <?php
        $milestones = [
            'contract_sent_date'    => 'Contract Sent',
            'contract_signed_date'  => 'Contract Signed',
            'invoice_received_date' => 'Invoice Received',
            'payment_sent_date'     => 'Payment Sent',
            'payment_cleared_date'  => 'Payment Cleared',
        ];
        foreach ($milestones as $field => $label):
            $done = !empty($b[$field]);
        ?>
          <div class="milestone <?= $done ? 'milestone-done' : 'milestone-pending' ?>">
            <span><?= $done ? '&#10003;' : '&#9675;' ?></span>
            <span><?= $label ?></span>
            <?php if ($done): ?><span class="small text-muted"><?= htmlspecialchars($b[$field]) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($b['internal_notes']): ?>
        <div class="card-section">
          <h5>Notes</h5>
          <p class="mb-0"><?= nl2br(htmlspecialchars($b['internal_notes'])) ?></p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Review -->
  <?php if ($review): ?>
    <div class="card-section">
      <h5>Review</h5>
      <div class="d-flex justify-content-between flex-wrap">
        <div>
          <strong><?= htmlspecialchars($review['reviewer_name']) ?></strong> &mdash; <?= htmlspecialchars($review['review_date']) ?>
          <?php if ($review['would_book_again']): ?><span class="badge bg-success ms-2">Would book again</span><?php else: ?><span class="badge bg-warning text-dark ms-2">Would not book again</span><?php endif; ?>
        </div>
        <div><?= stars($review['rating_overall']) ?> Overall</div>
      </div>
      <?php if ($review['strengths']): ?><p class="mt-2 mb-1"><strong>Strengths:</strong> <?= htmlspecialchars($review['strengths']) ?></p><?php endif; ?>
      <?php if ($review['concerns']): ?><p class="mb-0"><strong>Concerns:</strong> <?= htmlspecialchars($review['concerns']) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Files -->
  <?php if (!empty($files)): ?>
    <div class="card-section">
      <h5>Related Files</h5>
      <?php foreach ($files as $f): ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge bg-secondary"><?= ucfirst($f['file_type']) ?></span>
          <a href="../<?= htmlspecialchars($f['file_path']) ?>" target="_blank"><?= htmlspecialchars($f['file_name']) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
    <div class="card-section border border-danger mt-3">
      <h6 class="text-danger">Danger Zone</h6>
      <form method="POST" onsubmit="return confirm('Delete this booking permanently?');">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger btn-sm">Delete Booking</button>
      </form>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
