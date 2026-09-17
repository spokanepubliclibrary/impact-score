<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: ../admin_login.php");
    exit();
}

require_once('../secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$rid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$prePerformer = (int)($_GET['performer_id'] ?? 0);
$preBooking   = (int)($_GET['booking_id'] ?? 0);

$review = [];
$errors = [];

if ($rid > 0) {
    $stmt = $conn->prepare("SELECT * FROM performer_reviews WHERE review_id=?");
    $stmt->bind_param("i", $rid); $stmt->execute();
    $review = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$review) die("Review not found.");
    $prePerformer = $prePerformer ?: $review['performer_id'];
    $preBooking   = $preBooking ?: $review['booking_id'];
}

// Load performers and bookings for dropdowns
$performers = $conn->query("SELECT performer_id, stage_name FROM performers ORDER BY stage_name")->fetch_all(MYSQLI_ASSOC);

$bookings = [];
if ($prePerformer > 0) {
    $stmt = $conn->prepare("SELECT booking_id, event_title, event_date FROM performer_bookings WHERE performer_id=? ORDER BY event_date DESC LIMIT 50");
    $stmt->bind_param("i", $prePerformer); $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $perf_id  = (int)($_POST['performer_id'] ?? 0);
    $book_id  = ($_POST['booking_id'] ?? '') !== '' ? (int)$_POST['booking_id'] : null;
    $reviewer = trim($_POST['reviewer_name'] ?? '');
    $rev_date = $_POST['review_date'] ?? date('Y-m-d');
    $r_overall = ($_POST['rating_overall'] ?? '') !== '' ? (int)$_POST['rating_overall'] : null;
    $r_prof    = ($_POST['rating_professionalism'] ?? '') !== '' ? (int)$_POST['rating_professionalism'] : null;
    $r_eng     = ($_POST['rating_engagement'] ?? '') !== '' ? (int)$_POST['rating_engagement'] : null;
    $r_val     = ($_POST['rating_value'] ?? '') !== '' ? (int)$_POST['rating_value'] : null;
    $r_aud     = ($_POST['rating_audience_response'] ?? '') !== '' ? (int)$_POST['rating_audience_response'] : null;
    $wba       = isset($_POST['would_book_again']) ? 1 : 0;
    $strengths = trim($_POST['strengths'] ?? '');
    $concerns  = trim($_POST['concerns'] ?? '');
    $pub_notes = trim($_POST['public_notes'] ?? '');
    $int_notes = trim($_POST['internal_notes'] ?? '');

    if ($perf_id <= 0) $errors[] = "Please select a performer.";
    if ($reviewer === '') $errors[] = "Reviewer name is required.";

    if (empty($errors)) {
        if ($rid > 0) {
            $stmt = $conn->prepare("UPDATE performer_reviews SET performer_id=?, booking_id=?, reviewer_name=?, review_date=?, rating_overall=?, rating_professionalism=?, rating_engagement=?, rating_value=?, rating_audience_response=?, would_book_again=?, strengths=?, concerns=?, public_notes=?, internal_notes=? WHERE review_id=?");
            $stmt->bind_param("iissiiiiisssssi", $perf_id, $book_id, $reviewer, $rev_date, $r_overall, $r_prof, $r_eng, $r_val, $r_aud, $wba, $strengths, $concerns, $pub_notes, $int_notes, $rid);
        } else {
            $stmt = $conn->prepare("INSERT INTO performer_reviews (performer_id, booking_id, reviewer_name, review_date, rating_overall, rating_professionalism, rating_engagement, rating_value, rating_audience_response, would_book_again, strengths, concerns, public_notes, internal_notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("iissiiiiisssss", $perf_id, $book_id, $reviewer, $rev_date, $r_overall, $r_prof, $r_eng, $r_val, $r_aud, $wba, $strengths, $concerns, $pub_notes, $int_notes);
        }
        if ($stmt->execute()) {
            $new_rid = $rid ?: $conn->insert_id;
            $stmt->close();

            // Update performer average rating
            $upd = $conn->prepare("
                UPDATE performers SET
                    average_rating = (SELECT AVG(rating_overall) FROM performer_reviews WHERE performer_id=? AND rating_overall IS NOT NULL),
                    total_reviews  = (SELECT COUNT(*) FROM performer_reviews WHERE performer_id=?)
                WHERE performer_id=?
            ");
            $upd->bind_param("iii", $perf_id, $perf_id, $perf_id);
            $upd->execute(); $upd->close();

            $conn->close();
            header("Location: view.php?id={$perf_id}&tab=reviews&msg=Review+saved");
            exit();
        } else {
            $errors[] = "Database error: " . $stmt->error;
            $stmt->close();
        }
    }
}

$conn->close();
$isNew = ($rid === 0);

function ratingSelect($name, $selected) {
    $out = '<select name="'.$name.'" class="form-select form-select-sm">';
    $out .= '<option value="">—</option>';
    for ($i=1; $i<=5; $i++) {
        $sel = ($selected == $i) ? 'selected' : '';
        $out .= "<option value=\"{$i}\" {$sel}>{$i} " . str_repeat('★',$i) . str_repeat('☆',5-$i) . "</option>";
    }
    $out .= '</select>';
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isNew ? 'Add Review' : 'Edit Review' ?> — Performers</title>
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
<div class="container mt-4" style="max-width: 800px;">
  <div class="mb-3">
    <?php if ($prePerformer): ?>
      <a href="view.php?id=<?= $prePerformer ?>&tab=reviews" class="btn btn-secondary btn-sm">&#8592; Performer Profile</a>
    <?php else: ?>
      <a href="index.php" class="btn btn-secondary btn-sm">&#8592; Performer Directory</a>
    <?php endif; ?>
  </div>

  <h2 style="color:#480d3c;"><?= $isNew ? 'Add Review' : 'Edit Review' ?></h2>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="form-card">
      <div class="section-header">Review Info</div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Performer <span class="text-danger">*</span></label>
          <select name="performer_id" class="form-select" required onchange="this.form.submit()">
            <option value="">Select performer</option>
            <?php foreach ($performers as $perf): ?>
              <option value="<?= $perf['performer_id'] ?>" <?= ($review['performer_id'] ?? $prePerformer) == $perf['performer_id'] ? 'selected' : '' ?>><?= htmlspecialchars($perf['stage_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Associated Booking (optional)</label>
          <select name="booking_id" class="form-select">
            <option value="">— No specific booking —</option>
            <?php foreach ($bookings as $bk): ?>
              <option value="<?= $bk['booking_id'] ?>" <?= ($review['booking_id'] ?? $preBooking) == $bk['booking_id'] ? 'selected' : '' ?>><?= htmlspecialchars($bk['event_date'] . ' — ' . $bk['event_title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Reviewer Name <span class="text-danger">*</span></label>
          <input type="text" name="reviewer_name" class="form-control" required value="<?= htmlspecialchars($review['reviewer_name'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Review Date</label>
          <input type="date" name="review_date" class="form-control" value="<?= htmlspecialchars($review['review_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end pb-1">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="would_book_again" id="wba" <?= ($review['would_book_again'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="wba">Would book again</label>
          </div>
        </div>
      </div>

      <div class="section-header">Ratings (1–5)</div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Overall</label>
          <?= ratingSelect('rating_overall', $review['rating_overall'] ?? '') ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Professionalism</label>
          <?= ratingSelect('rating_professionalism', $review['rating_professionalism'] ?? '') ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Engagement</label>
          <?= ratingSelect('rating_engagement', $review['rating_engagement'] ?? '') ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Value</label>
          <?= ratingSelect('rating_value', $review['rating_value'] ?? '') ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Audience Response</label>
          <?= ratingSelect('rating_audience_response', $review['rating_audience_response'] ?? '') ?>
        </div>
      </div>

      <div class="section-header">Comments</div>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Strengths</label>
          <textarea name="strengths" class="form-control" rows="3"><?= htmlspecialchars($review['strengths'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Concerns</label>
          <textarea name="concerns" class="form-control" rows="3"><?= htmlspecialchars($review['concerns'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Public Notes</label>
          <textarea name="public_notes" class="form-control" rows="2"><?= htmlspecialchars($review['public_notes'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Internal Notes <small class="text-muted">(not shown publicly)</small></label>
          <textarea name="internal_notes" class="form-control" rows="2"><?= htmlspecialchars($review['internal_notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button type="submit" class="btn btn-custom"><?= $isNew ? 'Submit Review' : 'Save Changes' ?></button>
      <a href="<?= $prePerformer ? 'view.php?id='.$prePerformer.'&tab=reviews' : 'index.php' ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
</body>
</html>
