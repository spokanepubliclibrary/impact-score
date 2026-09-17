<?php
/**
 * index.php — Impact Score Dashboard Hub
 */
session_start();

$is_user_logged_in = !empty($_SESSION['user_logged_in']) && !empty($_SESSION['user_id']);
$is_admin          = !empty($_SESSION['user_is_admin']) || !empty($_SESSION['admin_logged_in']);
$user_name         = $_SESSION['user_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Impact Score Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
      body {
          background-color: #f5f2ec;
          font-family: 'Montserrat', Arial, sans-serif;
      }
      .container { margin-top: 50px; }
      .card { transition: transform 0.2s; }
      .card:hover { transform: scale(1.05); }
      .btn-custom { background-color: #480d3c; color: #fff; }
      .btn-custom:hover { background-color: #bb1b51; color: #fff; }
      .user-bar {
          background: #480d3c;
          color: #fff;
          padding: 8px 20px;
          font-size: .83rem;
          display: flex;
          justify-content: flex-end;
          align-items: center;
          gap: 16px;
      }
      .user-bar a { color: rgba(255,255,255,.75); text-decoration: none; font-weight: 600; }
      .user-bar a:hover { color: #fff; }
      .user-bar .name { font-weight: 700; color: #fff; }
  </style>
</head>
<body>

<?php if ($is_user_logged_in): ?>
<div class="user-bar">
    <span class="name">👤 <?= htmlspecialchars($user_name) ?></span>
    <a href="my_dashboard.php">My Dashboard</a>
    <a href="user_change_password.php">Change Password</a>
    <a href="user_logout.php">Sign out</a>
</div>
<?php else: ?>
<div class="user-bar">
    <a href="user_login.php">Sign in</a>
</div>
<?php endif; ?>

  <div class="container text-center">
    <h1 class="mb-4">📊 Impact Score Dashboard</h1>
    <p class="lead">Welcome to the Impact Score Dashboard. Select an option below.</p>
    <div class="row justify-content-center">

      <!-- Submit Impact Score -->
      <div class="col-md-3">
        <div class="card p-4 shadow-sm">
          <h4>📝 Submit Impact Score</h4>
          <p>Fill out the form.</p>
          <a href="value_score_form.php?fresh=1" class="btn btn-custom">Go to Form</a>
        </div>
      </div>

      <!-- View Scores -->
      <div class="col-md-3">
        <div class="card p-4 shadow-sm">
          <h4>📊 View Scores</h4>
          <p>See all submitted scores, search, and export data.</p>
          <a href="view_scores.php" class="btn btn-custom">View Scores</a>
        </div>
      </div>

      <!-- Reports -->
      <div class="col-md-3">
        <div class="card p-4 shadow-sm">
          <h4>📈 Reports</h4>
          <p>Access detailed reports and analyses.</p>
          <a href="stats.html" class="btn btn-custom">View Reports</a>
        </div>
      </div>

      <!-- Admin — only shown to admin users -->
      <?php if ($is_admin): ?>
      <div class="col-md-3">
        <div class="card p-4 shadow-sm">
          <h4>⚙️ Admin</h4>
          <p>Manage questions, users, and configuration.</p>
          <a href="admin_portal.php" class="btn btn-custom">Admin Panel</a>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <div class="row mt-3 justify-content-center">

      <!-- Tag Programs -->
      <div class="col-md-4">
        <div class="card p-4 shadow-sm">
          <h4>🏷️ Tag Programs</h4>
          <p>Filter your programs and bulk-apply tags.</p>
          <a href="bulk_tag.php" class="btn btn-custom">Tag Programs</a>
        </div>
      </div>

      <!-- My Dashboard -->
      <div class="col-md-4">
        <div class="card p-4 shadow-sm">
          <h4>👤 My Dashboard</h4>
          <p>Your personal stats, control chart, and quick-submit tools.</p>
          <a href="my_dashboard.php" class="btn btn-custom">My Dashboard</a>
        </div>
      </div>

      <!-- Performer Database -->
      <div class="col-md-4">
        <div class="card p-4 shadow-sm">
          <h4>🎭 Performer Database</h4>
          <p>Browse performers, programs, and booking history.</p>
          <a href="performers/index.php" class="btn btn-custom">Open Directory</a>
        </div>
      </div>

    </div>
  </div>

</body>
</html>
