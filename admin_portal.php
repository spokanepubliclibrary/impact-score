<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Portal - Impact Score Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      font-family: 'Montserrat', Arial, sans-serif;
      background-color: #f8f9fa;
    }
    .container {
      margin-top: 50px;
    }
    .card {
      transition: transform 0.2s;
    }
    .card:hover {
      transform: scale(1.05);
    }
    .btn-custom {
      background-color: #480d3c;
      color: white;
    }
    .btn-custom:hover {
      background-color: #bb1b51;
    }
    .alert {
      margin-top: 20px;
    }
  </style>
</head>
<body>
<div id="seedAlert"></div>
<div style="margin-bottom: 20px;">
    <a href="index.php" class="btn btn-secondary">⬅️ Back to Dashboard</a>
</div>

  <div class="container">

    <h1 class="mb-1 text-center">⚙️ Admin Portal</h1>
    <p class="lead text-center mb-5">Manage scoring questions, users, configurations, and API access.</p>

    <!-- ── Forms & Questions ─────────────────────────────────────────── -->
    <h5 class="text-uppercase text-muted fw-bold mb-3" style="letter-spacing:.05em;">Forms &amp; Questions</h5>
    <div class="row mb-5">
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>📝 Scoring Questions</h4>
          <p>Add, edit, or remove scoring questions.</p>
          <a href="admin_panel.php" class="btn btn-custom mt-auto">Manage Questions</a>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>🛠 Forms</h4>
          <p>Create forms, assign questions, set prefill defaults, and control per-form question order.</p>
          <a href="admin_forms.php" class="btn btn-custom mt-auto">Configure Forms</a>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>🗂 Custom Dropdown Fields</h4>
          <p>Create custom dropdowns (e.g. Age Group) that appear on the score form and can be filtered in reports.</p>
          <a href="admin_custom_fields.php" class="btn btn-custom mt-auto">Manage Fields</a>
        </div>
      </div>

    </div>

    <!-- ── Programs ──────────────────────────────────────────────────── -->
    <h5 class="text-uppercase text-muted fw-bold mb-3" style="letter-spacing:.05em;">Programs</h5>
    <div class="row mb-5">
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>📦 Promote to Bulk Entry</h4>
          <p>Convert a single submitted program into a reusable bulk-entry template with prefilled answers.</p>
          <a href="admin_convert_bulk.php" class="btn btn-custom mt-auto">Promote to Bulk</a>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>📦 Bulk Edit Program Fields</h4>
          <p>Bulk change various fields across multiple program entries.</p>
          <a href="admin_bulk_edit_scores.php" class="btn btn-custom mt-auto">Bulk Edit</a>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>📦 Merge Program Entries</h4>
          <p>Merge duplicate program entries into a single record.</p>
          <a href="admin_merge_scores.php" class="btn btn-custom mt-auto">Merge</a>
        </div>
      </div>
    </div>

    <!-- ── Performers ─────────────────────────────────────────────────── -->
    <h5 class="text-uppercase text-muted fw-bold mb-3" style="letter-spacing:.05em;">Performers</h5>
    <div class="row mb-5">
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>🎭 Performer Directory</h4>
          <p>Browse, search, and manage performer profiles.</p>
          <a href="performers/index.php" class="btn btn-custom mt-auto">Manage Performers</a>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>📅 Bookings</h4>
          <p>View and manage all performer booking records.</p>
          <a href="performers/booking_list.php" class="btn btn-custom mt-auto">View Bookings</a>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>📋 Program Catalog</h4>
          <p>Browse all available performer programs across the directory.</p>
          <a href="performers/program_catalog.php" class="btn btn-custom mt-auto">Browse Programs</a>
        </div>
      </div>
    </div>

    <!-- ── Users ─────────────────────────────────────────────────────── -->
    <h5 class="text-uppercase text-muted fw-bold mb-3" style="letter-spacing:.05em;">Users</h5>
    <div class="row mb-5">
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>👥 Users &amp; Teams</h4>
          <p>Manage user and team names.</p>
          <a href="admin_users.php" class="btn btn-custom mt-auto">Manage Users</a>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>🔗 API Access</h4>
          <p>Test or connect with the Impact Score API.</p>
          <a href="api-docs/api.php" class="btn btn-custom mt-auto">Open API UI</a>
        </div>
      </div>
    </div>

    <!-- ── Locations & Tags ─────────────────────────────────────────── -->
    <h5 class="text-uppercase text-muted fw-bold mb-3" style="letter-spacing:.05em;">Locations &amp; Tags</h5>
    <div class="row mb-5">
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>📍 Locations</h4>
          <p>Add, edit, or remove location names.</p>
          <a href="admin_locations.php" class="btn btn-custom mt-auto">Manage Locations</a>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>🏷️ Program Tags</h4>
          <p>Add, edit, or delete tags used to categorize programs on the score form.</p>
          <a href="admin_tags.php" class="btn btn-custom mt-auto">Manage Tags</a>
        </div>
      </div>
    </div>

    <!-- ── Developer Tools ───────────────────────────────────────────── -->
    <h5 class="text-uppercase text-muted fw-bold mb-3" style="letter-spacing:.05em;">Developer Tools</h5>
    <div class="row mb-5">
      <div class="col-md-4 mb-4">
        <div class="card p-4 shadow-sm h-100 d-flex flex-column">
          <h4>🌱 Seed Development Data</h4>
          <p>Pre-fill the database with example data for demonstrations.</p>
          <button id="seedBtn" class="btn btn-custom mt-auto" onclick="seedDevData()">Seed Database</button>
        </div>
      </div>
    </div>

  </div>

  <script>
    function seedDevData() {
      if (!confirm('This will populate the database with development example data.\n\nContinue?')) {
        return;
      }

      const btn = document.getElementById('seedBtn');
      const alertDiv = document.getElementById('seedAlert');
      
      btn.disabled = true;
      btn.textContent = 'Seeding...';
      alertDiv.innerHTML = '';

      fetch('seed_dev_endpoint.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
      })
      .then(response => {
        if (!response.ok) {
          return response.json().then(data => {
            throw new Error(data.error || 'Seed failed');
          });
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          alertDiv.innerHTML = '<div class="alert alert-success alert-dismissible fade show" role="alert">' +
            '✓ ' + data.message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
          btn.textContent = 'Seed Database';
        } else {
          throw new Error(data.error || 'Unknown error');
        }
      })
      .catch(error => {
        alertDiv.innerHTML = '<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
          '✗ Seed failed: ' + error.message +
          '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        btn.textContent = 'Seed Database';
      })
      .finally(() => {
        btn.disabled = false;
      });
    }
  </script>
</body>
</html>
