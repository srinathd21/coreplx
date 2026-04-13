<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login-admin.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, $tableName) {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

$currentUserId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && $_SESSION['full_name'] !== '' ? $_SESSION['full_name'] : 'QA Admin';

$successMessage = '';
$errorMessage   = '';

$hasUsersTable           = tableExists($conn, 'users');
$hasRolesTable           = tableExists($conn, 'roles');
$hasUserRoleHistoryTable = tableExists($conn, 'user_role_history');

$validCurrentUserId = 0;

if ($hasUsersTable && $currentUserId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $currentUserId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && mysqli_num_rows($res) > 0) {
            $validCurrentUserId = $currentUserId;
        }
        mysqli_stmt_close($stmt);
    }
}

if ($hasUsersTable && $validCurrentUserId === 0) {
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE current_role_id IN (2, 3) ORDER BY id ASC LIMIT 1");
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && mysqli_num_rows($res) > 0) {
            $row = mysqli_fetch_assoc($res);
            $validCurrentUserId = (int)$row['id'];
        }
        mysqli_stmt_close($stmt);
    }
}

$selectedUserId = 0;
$selectedRoleId = 0;
$reasonValue    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'assign_role') {
        $selectedUserId = (int)($_POST['user_id'] ?? 0);
        $selectedRoleId = (int)($_POST['new_role_id'] ?? 0);
        $reasonValue    = trim($_POST['reason_for_change'] ?? '');

        if (!$hasUsersTable) {
            $errorMessage = 'Users table not found.';
        } elseif (!$hasRolesTable) {
            $errorMessage = 'Roles table not found.';
        } elseif ($selectedUserId <= 0 || $selectedRoleId <= 0) {
            $errorMessage = 'Please select user and new role.';
        } else {
            $userStmt = mysqli_prepare($conn, "
                SELECT u.id, u.first_name, u.last_name, u.current_role_id, r.role_name AS current_role_name
                FROM users u
                LEFT JOIN roles r ON r.id = u.current_role_id
                WHERE u.id = ?
                LIMIT 1
            ");

            if (!$userStmt) {
                $errorMessage = 'Failed to prepare user query.';
            } else {
                mysqli_stmt_bind_param($userStmt, "i", $selectedUserId);
                mysqli_stmt_execute($userStmt);
                $userRes = mysqli_stmt_get_result($userStmt);
                $userRow = $userRes ? mysqli_fetch_assoc($userRes) : null;
                mysqli_stmt_close($userStmt);

                if (!$userRow) {
                    $errorMessage = 'Selected user not found.';
                } else {
                    $roleStmt = mysqli_prepare($conn, "SELECT id, role_name FROM roles WHERE id = ? AND is_active = 1 LIMIT 1");
                    if (!$roleStmt) {
                        $errorMessage = 'Failed to prepare role query.';
                    } else {
                        mysqli_stmt_bind_param($roleStmt, "i", $selectedRoleId);
                        mysqli_stmt_execute($roleStmt);
                        $roleRes = mysqli_stmt_get_result($roleStmt);
                        $roleRow = $roleRes ? mysqli_fetch_assoc($roleRes) : null;
                        mysqli_stmt_close($roleStmt);

                        if (!$roleRow) {
                            $errorMessage = 'Selected role not found or inactive.';
                        } else {
                            $oldRoleId = (int)($userRow['current_role_id'] ?? 0);

                            if ($oldRoleId === $selectedRoleId) {
                                $errorMessage = 'Selected user already has this role.';
                            } else {
                                mysqli_begin_transaction($conn);

                                try {
                                    if ($validCurrentUserId > 0) {
                                        $updateStmt = mysqli_prepare($conn, "UPDATE users SET current_role_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                                        if (!$updateStmt) {
                                            throw new Exception('Failed to prepare update statement.');
                                        }
                                        mysqli_stmt_bind_param($updateStmt, "iii", $selectedRoleId, $validCurrentUserId, $selectedUserId);
                                    } else {
                                        $updateStmt = mysqli_prepare($conn, "UPDATE users SET current_role_id = ?, updated_at = NOW() WHERE id = ?");
                                        if (!$updateStmt) {
                                            throw new Exception('Failed to prepare update statement.');
                                        }
                                        mysqli_stmt_bind_param($updateStmt, "ii", $selectedRoleId, $selectedUserId);
                                    }

                                    if (!mysqli_stmt_execute($updateStmt)) {
                                        $updateError = mysqli_error($conn);
                                        mysqli_stmt_close($updateStmt);
                                        throw new Exception('Failed to update role: ' . $updateError);
                                    }
                                    mysqli_stmt_close($updateStmt);

                                    if ($hasUserRoleHistoryTable) {
                                        $historyReason = $reasonValue !== '' ? $reasonValue : 'Role updated from role assignment page';

                                        if ($validCurrentUserId > 0) {
                                            $historyStmt = mysqli_prepare(
                                                $conn,
                                                "INSERT INTO user_role_history (user_id, old_role_id, new_role_id, reason_for_change, changed_by) VALUES (?, ?, ?, ?, ?)"
                                            );
                                            if (!$historyStmt) {
                                                throw new Exception('Failed to prepare role history statement.');
                                            }
                                            mysqli_stmt_bind_param($historyStmt, "iiisi", $selectedUserId, $oldRoleId, $selectedRoleId, $historyReason, $validCurrentUserId);
                                        } else {
                                            $historyStmt = mysqli_prepare(
                                                $conn,
                                                "INSERT INTO user_role_history (user_id, old_role_id, new_role_id, reason_for_change) VALUES (?, ?, ?, ?)"
                                            );
                                            if (!$historyStmt) {
                                                throw new Exception('Failed to prepare role history statement.');
                                            }
                                            mysqli_stmt_bind_param($historyStmt, "iiis", $selectedUserId, $oldRoleId, $selectedRoleId, $historyReason);
                                        }

                                        if (!mysqli_stmt_execute($historyStmt)) {
                                            $historyError = mysqli_error($conn);
                                            mysqli_stmt_close($historyStmt);
                                            throw new Exception('Failed to save role history: ' . $historyError);
                                        }
                                        mysqli_stmt_close($historyStmt);
                                    }

                                    mysqli_commit($conn);

                                    $successMessage = 'Role assigned successfully.';
                                    $selectedUserId = 0;
                                    $selectedRoleId = 0;
                                    $reasonValue    = '';
                                } catch (Exception $e) {
                                    mysqli_rollback($conn);
                                    $errorMessage = $e->getMessage();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$users = [];
if ($hasUsersTable && $hasRolesTable) {
    $sql = "
        SELECT 
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.current_role_id,
            r.role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.current_role_id
        ORDER BY u.first_name ASC, u.last_name ASC, u.id ASC
    ";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $users[] = $row;
        }
    }
}

$roles = [];
if ($hasRolesTable) {
    $res = mysqli_query($conn, "SELECT id, role_code, role_name FROM roles WHERE is_active = 1 ORDER BY id ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $roles[] = $row;
        }
    }
}

$recentAssignments = [];
if ($hasUserRoleHistoryTable && $hasUsersTable && $hasRolesTable) {
    $historySql = "
        SELECT 
            urh.id,
            urh.user_id,
            urh.old_role_id,
            urh.new_role_id,
            urh.reason_for_change,
            urh.changed_at,
            CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS user_name,
            oldr.role_name AS old_role_name,
            newr.role_name AS new_role_name
        FROM user_role_history urh
        LEFT JOIN users u ON u.id = urh.user_id
        LEFT JOIN roles oldr ON oldr.id = urh.old_role_id
        LEFT JOIN roles newr ON newr.id = urh.new_role_id
        ORDER BY urh.id DESC
        LIMIT 5
    ";
    $historyRes = mysqli_query($conn, $historySql);
    if ($historyRes) {
        while ($row = mysqli_fetch_assoc($historyRes)) {
            $recentAssignments[] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Role Assignment</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .cp-card {
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 18px;
      box-shadow: 0 6px 24px rgba(0,0,0,.06);
      background: #fff;
    }
    .page-title {
      font-size: 1.75rem;
      font-weight: 700;
    }
    .page-subtitle,
    .card-subtitle {
      color: #6c757d;
    }
    .note-list {
      padding-left: 1rem;
    }
    .history-list .history-item {
      border-bottom: 1px solid rgba(0,0,0,.08);
      padding: .75rem 0;
    }
    .history-list .history-item:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }
    .history-label {
      font-size: .8rem;
      color: #6c757d;
    }
    .history-value {
      font-size: .95rem;
      font-weight: 500;
      color: #212529;
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item"><a class="nav-link active" href="dashboard-admin.php">Dashboard</a></li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item" href="repository.php">Repository</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Workflow</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="document-types.php">Document Types</a></li>
            <li><a class="dropdown-item" href="document-id.php">Document ID</a></li>
            <li><a class="dropdown-item" href="content-editor.php">Content Editor</a></li>
            <li><a class="dropdown-item" href="form-builder.php">Form Builder</a></li>
            <li><a class="dropdown-item" href="form-type-name.php">Form Type &amp; Name</a></li>
            <li><a class="dropdown-item" href="approver-selection.php">Approver Selection</a></li>
            <li><a class="dropdown-item" href="submit-review.php">Submit for Review</a></li>
            <li><a class="dropdown-item" href="electronic-signature.php">Electronic Signature</a></li>
            <li><a class="dropdown-item" href="approver-comments.php">Approver Comments</a></li>
            <li><a class="dropdown-item" href="notifications.php">Notifications</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-creation.php">Audit - Creation</a></li>
            <li><a class="dropdown-item" href="audit-approval.php">Audit - Approval</a></li>
            <li><a class="dropdown-item" href="audit-comments.php">Audit - Comments</a></li>
            <li><a class="dropdown-item" href="qa-admin.php">QA Admin</a></li>
            <li><a class="dropdown-item" href="employee-role.php">Employee Role</a></li>
            <li><a class="dropdown-item" href="super-admin.php">Super Admin</a></li>
            <li><a class="dropdown-item" href="user-management.php">User Management</a></li>
            <li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li>
          </ul>
        </li>

        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>

      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($currentUserName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small">Profile</span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
  <div class="content-wrap px-4 py-4 mx-auto">
    <div class="mb-4">
      <h1 class="page-title mb-2">Role Assignment</h1>
      <p class="page-subtitle mb-0">Assign and maintain role-based permissions for secure document access and workflow control.</p>
    </div>

    <?php if ($successMessage !== ''): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo e($successMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo e($errorMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Assign Role</h2>
            <p class="card-subtitle mb-3">Maintain role-based permissions for secure workflow control.</p>

            <form method="post">
              <input type="hidden" name="action" value="assign_role">

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">User</label>
                  <select class="form-select" name="user_id" required>
                    <option value="">Select User</option>
                    <?php foreach ($users as $user): ?>
                      <?php
                        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                        if ($fullName === '') {
                            $fullName = 'Unnamed User';
                        }
                        $roleName = $user['role_name'] ?? 'No Role';
                      ?>
                      <option value="<?php echo (int)$user['id']; ?>" <?php echo ($selectedUserId === (int)$user['id']) ? 'selected' : ''; ?>>
                        <?php echo e($fullName . ' - ' . $roleName); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label">New Role</label>
                  <select class="form-select" name="new_role_id" required>
                    <option value="">Select Role</option>
                    <?php foreach ($roles as $role): ?>
                      <option value="<?php echo (int)$role['id']; ?>" <?php echo ($selectedRoleId === (int)$role['id']) ? 'selected' : ''; ?>>
                        <?php echo e($role['role_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-12">
                  <label class="form-label">Reason for Change</label>
                  <textarea class="form-control" name="reason_for_change" rows="3"><?php echo e($reasonValue); ?></textarea>
                </div>
              </div>

              <div class="d-flex gap-2 mt-4">
                <a href="role-assignment.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Apply Role</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Audit Requirement</h2>
            <ul class="small text-secondary note-list mb-0">
              <li>All role assignments are logged.</li>
              <li>Old role and new role must be preserved.</li>
              <li>Effective date and approver can be added if governance requires.</li>
            </ul>
          </div>
        </div>

        <?php if ($hasUserRoleHistoryTable): ?>
          <div class="card cp-card mt-3">
            <div class="card-body">
              <h2 class="card-title mb-1">Recent Assignments</h2>
              <p class="card-subtitle mb-3">Latest role assignment activity.</p>

              <div class="history-list">
                <?php if (!empty($recentAssignments)): ?>
                  <?php foreach ($recentAssignments as $item): ?>
                    <?php
                      $userName = trim((string)($item['user_name'] ?? ''));
                      if ($userName === '') {
                          $userName = 'Unknown User';
                      }
                    ?>
                    <div class="history-item">
                      <div class="history-label">User</div>
                      <div class="history-value"><?php echo e($userName); ?></div>

                      <div class="history-label mt-2">Role Change</div>
                      <div class="history-value">
                        <?php echo e(($item['old_role_name'] ?? 'No Role') . ' → ' . ($item['new_role_name'] ?? 'No Role')); ?>
                      </div>

                      <div class="history-label mt-2">Reason</div>
                      <div class="history-value"><?php echo e($item['reason_for_change'] ?: '-'); ?></div>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <p class="small text-secondary mb-0">No recent role assignments found.</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>