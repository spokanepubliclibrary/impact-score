<?php
/**
 * admin_users.php — Manage Users, Teams & Credentials
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit();
}

require_once('secure/db_connection.php');
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error);

$success_msg = '';
$error_msg   = '';

// ── Handle POST actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Add new user
    if ($action === 'add_user' && !empty($_POST['user_name'])) {
        $user_name    = trim($_POST['user_name']);
        $user_email   = trim($_POST['user_email'] ?? '');
        $default_team = (int)($_POST['default_team'] ?? 0) ?: null;
        $init_pw      = $_POST['initial_password'] ?? '';

        $pw_hash = null;
        $must_change = 0;
        if ($init_pw !== '') {
            $pw_hash     = password_hash($init_pw, PASSWORD_BCRYPT);
            $must_change = 1;
        }

        $stmt = $conn->prepare(
            "INSERT INTO users (name, email, default_team, password_hash, must_change_password)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssisi', $user_name, $user_email, $default_team, $pw_hash, $must_change);
        $stmt->execute();
        $stmt->close();
        $success_msg = "User '{$user_name}' added.";
    }

    // Edit basic user info
    elseif ($action === 'edit_user' && !empty($_POST['user_id'])) {
        $user_id      = (int)$_POST['user_id'];
        $user_name    = trim($_POST['user_name']  ?? '');
        $user_email   = trim($_POST['user_email'] ?? '');
        $default_team = (int)($_POST['default_team'] ?? 0) ?: null;
        $active       = isset($_POST['account_active']) ? 1 : 0;

        $stmt = $conn->prepare(
            "UPDATE users SET name = ?, email = ?, default_team = ?, account_active = ? WHERE id = ?"
        );
        $stmt->bind_param('ssiii', $user_name, $user_email, $default_team, $active, $user_id);
        $stmt->execute();
        $stmt->close();
        $success_msg = "User updated.";
    }

    // Set / reset password (admin-initiated)
    elseif ($action === 'set_password' && !empty($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $new_pw  = $_POST['new_password'] ?? '';

        if (strlen($new_pw) < 8) {
            $error_msg = 'Password must be at least 8 characters.';
        } else {
            $hash = password_hash($new_pw, PASSWORD_BCRYPT);
            $stmt = $conn->prepare(
                "UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?"
            );
            $stmt->bind_param('si', $hash, $user_id);
            $stmt->execute();
            $stmt->close();
            $success_msg = 'Password set. User will be required to change it on next login.';
        }
    }

    // Clear password (remove manual login)
    elseif ($action === 'clear_password' && !empty($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET password_hash = NULL, must_change_password = 0 WHERE id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        $success_msg = 'Password login removed for this user.';
    }

    // Force must-change-password flag
    elseif ($action === 'force_reset' && !empty($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET must_change_password = 1 WHERE id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        $success_msg = 'User will be required to change their password on next login.';
    }

    // Link Microsoft account by email
    elseif ($action === 'link_microsoft' && !empty($_POST['user_id'])) {
        $user_id  = (int)$_POST['user_id'];
        $ms_email = trim($_POST['microsoft_email'] ?? '');
        if ($ms_email) {
            $stmt = $conn->prepare(
                "UPDATE users SET microsoft_email = ?, microsoft_oid = NULL WHERE id = ?"
            );
            $stmt->bind_param('si', $ms_email, $user_id);
            $stmt->execute();
            $stmt->close();
            $success_msg = "Microsoft email set. The account will be fully linked on first Microsoft sign-in.";
        }
    }

    // Toggle admin privilege
    elseif ($action === 'toggle_admin' && !empty($_POST['user_id'])) {
        $user_id   = (int)$_POST['user_id'];
        $new_value = (int)($_POST['new_admin_value'] ?? 0);
        $stmt = $conn->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
        $stmt->bind_param('ii', $new_value, $user_id);
        $stmt->execute();
        $stmt->close();
        $success_msg = $new_value ? 'Admin privileges granted.' : 'Admin privileges revoked.';
    }

    // Unlink Microsoft account
    elseif ($action === 'unlink_microsoft' && !empty($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $stmt = $conn->prepare(
            "UPDATE users SET microsoft_oid = NULL, microsoft_email = NULL WHERE id = ?"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        $success_msg = 'Microsoft account unlinked.';
    }

    // Delete user
    elseif ($action === 'delete_user' && !empty($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        $success_msg = 'User deleted.';
    }

    // Add team
    elseif ($action === 'add_team' && !empty($_POST['team_name'])) {
        $team_name = trim($_POST['team_name']);
        $stmt = $conn->prepare("INSERT INTO teams (name) VALUES (?)");
        $stmt->bind_param('s', $team_name);
        $stmt->execute();
        $stmt->close();
        $success_msg = "Team '{$team_name}' added.";
    }

    // Delete team
    elseif ($action === 'delete_team' && !empty($_POST['team_id'])) {
        $team_id = (int)$_POST['team_id'];
        $stmt = $conn->prepare("DELETE FROM teams WHERE id = ?");
        $stmt->bind_param('i', $team_id);
        $stmt->execute();
        $stmt->close();
        $success_msg = 'Team deleted.';
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────
$res = $conn->query(
    "SELECT u.id, u.name, u.email, u.account_active,
            u.password_hash, u.must_change_password,
            u.microsoft_oid, u.microsoft_email,
            u.is_admin, u.last_login,
            t.id AS team_id, t.name AS team_name
     FROM users u
     LEFT JOIN teams t ON u.default_team = t.id
     ORDER BY u.name ASC"
);
$users = [];
while ($row = $res->fetch_assoc()) $users[] = $row;

$teams_res = $conn->query("SELECT * FROM teams ORDER BY name ASC");
$teams = [];
while ($row = $teams_res->fetch_assoc()) $teams[] = $row;

$conn->close();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Users & Teams — Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;700&display=swap" rel="stylesheet">
<style>
    :root { --plum: #480d3c; --fuchsia: #bb1b51; --parchment: #f5f2ec; }
    body { font-family: 'Montserrat', sans-serif; background: var(--parchment); padding: 20px; }
    .page-header {
        background: linear-gradient(135deg, var(--plum), var(--fuchsia));
        color: #fff; border-radius: 14px; padding: 22px 28px; margin-bottom: 24px;
    }
    .page-header h2 { font-weight: 800; margin: 0; }
    .card { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.07); margin-bottom: 24px; }
    .card-header { background: var(--plum); color: #fff; border-radius: 14px 14px 0 0 !important; font-weight: 700; padding: 14px 20px; }
    .card-body { padding: 22px; }

    /* Auth status badges */
    .auth-badge {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: .7rem; font-weight: 700; padding: 3px 8px;
        border-radius: 20px; white-space: nowrap;
    }
    .badge-pw    { background: #d4edda; color: #155724; }
    .badge-ms    { background: #cfe2ff; color: #0a3678; }
    .badge-none  { background: #f8d7da; color: #721c24; }
    .badge-force { background: #fff3cd; color: #856404; }
    .badge-off   { background: #e2e3e5; color: #41464b; }

    /* User row */
    .user-row { border-bottom: 1px solid #eee; padding: 14px 0; }
    .user-row:last-child { border-bottom: none; }
    .user-name { font-weight: 700; font-size: .95rem; color: #222; }
    .user-meta { font-size: .78rem; color: #888; margin-top: 2px; }

    /* Collapsible credential panel */
    .cred-panel { background: #faf7f2; border-radius: 10px; padding: 16px 18px; margin-top: 10px; }
    .cred-panel h6 { font-weight: 700; font-size: .82rem; color: var(--plum); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px; }

    /* Buttons */
    .btn-plum  { background: var(--plum);   color: #fff; border: none; }
    .btn-plum:hover  { background: var(--fuchsia); color: #fff; }
    .btn-fuchsia { background: var(--fuchsia); color: #fff; border: none; }
    .btn-fuchsia:hover { background: #9a1540; color: #fff; }
    .btn-outline-plum { border: 2px solid var(--plum); color: var(--plum); background: transparent; }
    .btn-outline-plum:hover { background: var(--plum); color: #fff; }
    .btn-sm { font-size: .78rem; padding: 4px 10px; border-radius: 7px; }
    .btn { font-family: 'Montserrat', sans-serif; font-weight: 600; }

    /* Form controls */
    .form-control, .form-select {
        border-radius: 8px; border: 2px solid #e0e0e0; font-family: 'Montserrat', sans-serif;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--plum); box-shadow: 0 0 0 3px rgba(72,13,60,.1);
    }
    .form-label { font-weight: 600; font-size: .82rem; color: #444; }
    .alert { border-radius: 10px; }

    .section-title { font-weight: 800; font-size: 1.1rem; color: var(--plum); margin-bottom: 16px; }
    .back-btn { background: var(--plum); color: #fff; border: none; border-radius: 8px; padding: 8px 18px; font-weight: 600; font-size: .88rem; text-decoration: none; }
    .back-btn:hover { background: var(--fuchsia); color: #fff; }

    .last-login { font-size: .72rem; color: #aaa; }
</style>
</head>
<body>

<a href="admin_portal.php" class="back-btn mb-3 d-inline-block">⬅ Admin Portal</a>

<div class="page-header">
    <h2>👥 Manage Users, Teams & Credentials</h2>
    <div style="opacity:.8; font-size:.88rem; margin-top:4px;">
        Set passwords, manage Microsoft sign-in, and control account access.
    </div>
</div>

<?php if ($success_msg): ?>
    <div class="alert alert-success"><?= h($success_msg) ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger"><?= h($error_msg) ?></div>
<?php endif; ?>

<!-- ── Add New User ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">➕ Add New User</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="user_name" class="form-control" placeholder="Jane Smith" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="user_email" class="form-control" placeholder="jane@example.com" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Default Team</label>
                    <select name="default_team" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Initial Password <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="initial_password" class="form-control"
                           placeholder="They'll be forced to change it"
                           autocomplete="off">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-plum w-100">Add</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Users List ──────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">👤 Existing Users (<?= count($users) ?>)</div>
    <div class="card-body">

        <?php if (empty($users)): ?>
            <div class="text-muted">No users yet.</div>
        <?php else: foreach ($users as $u):
            $has_pw    = !empty($u['password_hash']);
            $has_ms    = !empty($u['microsoft_oid']) || !empty($u['microsoft_email']);
            $ms_linked = !empty($u['microsoft_oid']);
            $forced    = $u['must_change_password'];
            $active    = $u['account_active'];
            $is_admin  = $u['is_admin'];
        ?>
        <div class="user-row">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">

                <!-- User info + auth badges -->
                <div>
                    <div class="user-name">
                        <?= h($u['name']) ?>
                        <?php if (!$active): ?>
                            <span class="auth-badge badge-off ms-1">Deactivated</span>
                        <?php endif; ?>
                    </div>
                    <div class="user-meta">
                        <?= $u['email'] ? h($u['email']) : '<em>no email</em>' ?>
                        &nbsp;·&nbsp;
                        <?= $u['team_name'] ? h($u['team_name']) : '<em>no team</em>' ?>
                        <?php if ($u['last_login']): ?>
                            &nbsp;·&nbsp;
                            <span class="last-login">Last login: <?= date('M j, Y g:ia', strtotime($u['last_login'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <!-- Auth status badges -->
                    <div class="mt-1 d-flex gap-1 flex-wrap">
                        <?php if ($has_pw): ?>
                            <span class="auth-badge badge-pw">🔑 Password</span>
                            <?php if ($forced): ?>
                                <span class="auth-badge badge-force">⚠ Must change</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="auth-badge badge-none">🔑 No password</span>
                        <?php endif; ?>

                        <?php if ($ms_linked): ?>
                            <span class="auth-badge badge-ms">🪟 Microsoft linked</span>
                        <?php elseif (!empty($u['microsoft_email'])): ?>
                            <span class="auth-badge badge-ms" style="opacity:.7;" title="Email set but not yet signed in via Microsoft">🪟 MS email set</span>
                        <?php else: ?>
                            <span class="auth-badge badge-none">🪟 No Microsoft</span>
                        <?php endif; ?>

                        <?php if ($is_admin): ?>
                            <span class="auth-badge" style="background:#e8d5f5; color:#480d3c;">⚙️ Admin</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action buttons -->
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-sm btn-outline-plum"
                            onclick="togglePanel('edit-<?= $u['id'] ?>')">Edit</button>
                    <button class="btn btn-sm btn-outline-plum"
                            onclick="togglePanel('cred-<?= $u['id'] ?>')">Credentials</button>
                    <!-- Grant / Revoke admin -->
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('<?= $is_admin
                              ? 'Revoke admin privileges for ' . h(addslashes($u['name'])) . '?'
                              : 'Grant admin privileges to ' . h(addslashes($u['name'])) . '? They will be able to access the Admin Portal.' ?>') ">
                        <input type="hidden" name="action" value="toggle_admin">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="new_admin_value" value="<?= $is_admin ? 0 : 1 ?>">
                        <button type="submit" class="btn btn-sm <?= $is_admin ? 'btn-fuchsia' : 'btn-outline-plum' ?>">
                            <?= $is_admin ? '⚙️ Revoke Admin' : '⚙️ Grant Admin' ?>
                        </button>
                    </form>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('Delete <?= h(addslashes($u['name'])) ?>? This cannot be undone.')">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-fuchsia">Delete</button>
                    </form>
                </div>
            </div>

            <!-- ── Edit basic info panel ── -->
            <div id="edit-<?= $u['id'] ?>" class="cred-panel mt-2" style="display:none;">
                <h6>Edit User</h6>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="user_name" class="form-control"
                                   value="<?= h($u['name']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="user_email" class="form-control"
                                   value="<?= h($u['email']) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Default Team</label>
                            <select name="default_team" class="form-select">
                                <option value="">— None —</option>
                                <?php foreach ($teams as $t): ?>
                                    <option value="<?= $t['id'] ?>"
                                        <?= $u['team_id'] == $t['id'] ? 'selected' : '' ?>>
                                        <?= h($t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end gap-2">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="account_active"
                                       id="active-<?= $u['id'] ?>" value="1"
                                       <?= $active ? 'checked' : '' ?>>
                                <label class="form-check-label" for="active-<?= $u['id'] ?>">
                                    Account active
                                </label>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-plum btn-sm w-100">Save changes</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ── Credentials panel ── -->
            <div id="cred-<?= $u['id'] ?>" class="cred-panel mt-2" style="display:none;">

                <!-- Password section -->
                <h6>🔑 Password Login</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <form method="POST" class="d-flex gap-2 align-items-end">
                            <input type="hidden" name="action" value="set_password">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <div class="flex-grow-1">
                                <label class="form-label">
                                    <?= $has_pw ? 'Set new temporary password' : 'Create password' ?>
                                </label>
                                <input type="text" name="new_password" class="form-control"
                                       placeholder="Min. 8 characters" autocomplete="off">
                            </div>
                            <button type="submit" class="btn btn-plum btn-sm"
                                    onclick="return confirm('Set a new temporary password for <?= h(addslashes($u['name'])) ?>? They will be required to change it on next login.')">
                                Set password
                            </button>
                        </form>
                        <div class="mt-1" style="font-size:.72rem; color:#888;">
                            User will be forced to change this on next login.
                        </div>
                    </div>

                    <?php if ($has_pw): ?>
                    <div class="col-md-4 d-flex gap-2 align-items-end">
                        <?php if (!$forced): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="force_reset">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-plum">
                                ⚠ Force password reset
                            </button>
                        </form>
                        <?php endif; ?>
                        <form method="POST"
                              onsubmit="return confirm('Remove password login for <?= h(addslashes($u['name'])) ?>?')">
                            <input type="hidden" name="action" value="clear_password">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-fuchsia">Remove password</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <hr style="border-color:#e8e2da;">

                <!-- Microsoft section -->
                <h6>🪟 Microsoft Sign-In</h6>
                <?php if ($ms_linked): ?>
                    <div class="mb-2">
                        <span style="font-size:.82rem;">
                            ✅ Linked — OID: <code><?= h(substr($u['microsoft_oid'], 0, 12)) ?>…</code>
                            <?php if ($u['microsoft_email']): ?>
                                &nbsp;·&nbsp; <?= h($u['microsoft_email']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('Unlink Microsoft account for <?= h(addslashes($u['name'])) ?>?')">
                        <input type="hidden" name="action" value="unlink_microsoft">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-fuchsia">Unlink Microsoft</button>
                    </form>

                <?php elseif (!empty($u['microsoft_email'])): ?>
                    <div class="mb-2" style="font-size:.82rem;">
                        📧 Email set: <strong><?= h($u['microsoft_email']) ?></strong>
                        — will be fully linked on first Microsoft sign-in.
                    </div>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('Remove Microsoft link for <?= h(addslashes($u['name'])) ?>?')">
                        <input type="hidden" name="action" value="unlink_microsoft">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-fuchsia">Remove</button>
                    </form>

                <?php else: ?>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <form method="POST" class="d-flex gap-2 align-items-end">
                                <input type="hidden" name="action" value="link_microsoft">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <div class="flex-grow-1">
                                    <label class="form-label">Microsoft / work email</label>
                                    <input type="email" name="microsoft_email" class="form-control"
                                           placeholder="jane@yourorg.com">
                                </div>
                                <button type="submit" class="btn btn-plum btn-sm">Set email</button>
                            </form>
                            <div class="mt-1" style="font-size:.72rem; color:#888;">
                                The account will be fully linked the first time the user signs in with Microsoft.
                                Alternatively, if their Microsoft email matches their account email above, it links automatically.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- ── Teams ───────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">🏷 Manage Teams</div>
    <div class="card-body">
        <form method="POST" class="d-flex gap-2 mb-4" style="max-width:400px;">
            <input type="hidden" name="action" value="add_team">
            <input type="text" name="team_name" class="form-control" placeholder="Team name" required>
            <button type="submit" class="btn btn-plum">Add Team</button>
        </form>

        <?php if (empty($teams)): ?>
            <div class="text-muted">No teams yet.</div>
        <?php else: ?>
        <ul class="list-unstyled mb-0">
            <?php foreach ($teams as $t): ?>
            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                <span style="font-weight:600;"><?= h($t['name']) ?></span>
                <form method="POST"
                      onsubmit="return confirm('Delete team <?= h(addslashes($t['name'])) ?>?')">
                    <input type="hidden" name="action" value="delete_team">
                    <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-fuchsia">Delete</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePanel(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const isHidden = el.style.display === 'none' || el.style.display === '';
    // Close all panels for this user row first
    const row = el.closest('.user-row');
    row.querySelectorAll('.cred-panel').forEach(p => p.style.display = 'none');
    if (isHidden) el.style.display = 'block';
}
</script>

</body>
</html>
