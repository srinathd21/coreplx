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

if (!function_exists('formatDateTimeDisplay')) {
    function formatDateTimeDisplay($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime($datetime);
        return $ts ? date('d-M-Y h:i A', $ts) : '-';
    }
}

/*
|--------------------------------------------------------------------------
| ALLOW ONLY SUPER ADMIN
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['admin_logged_in']) ||
    $_SESSION['admin_logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    (int)$_SESSION['user_id'] <= 0
) {
    header('Location: login-admin.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];

$currentUser = null;
$currentUserSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        r.role_code,
        r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
";
$currentUserStmt = mysqli_prepare($conn, $currentUserSql);
if ($currentUserStmt) {
    mysqli_stmt_bind_param($currentUserStmt, "i", $currentUserId);
    mysqli_stmt_execute($currentUserStmt);
    $currentUserRes = mysqli_stmt_get_result($currentUserStmt);
    $currentUser = ($currentUserRes && mysqli_num_rows($currentUserRes) > 0) ? mysqli_fetch_assoc($currentUserRes) : null;
    mysqli_stmt_close($currentUserStmt);
}

if (!$currentUser || ($currentUser['role_code'] ?? '') !== 'super_admin') {
    header('Location: login-admin.php');
    exit;
}

$currentUserName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
if ($currentUserName === '') {
    $currentUserName = $_SESSION['full_name'] ?? 'Super Admin';
}

$hasUsersTable           = tableExists($conn, 'users');
$hasRolesTable           = tableExists($conn, 'roles');
$hasUserRoleHistoryTable = tableExists($conn, 'user_role_history');

$totalUsers = 0;
$activeUsers = 0;
$superAdmins = 0;
$qaAdmins = 0;
$employees = 0;

if ($hasUsersTable) {
    $res = mysqli_query($conn, "SELECT COUNT(*) AS total_count FROM users");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $totalUsers = (int)($row['total_count'] ?? 0);
    }

    $res = mysqli_query($conn, "SELECT COUNT(*) AS active_count FROM users WHERE status = 'active'");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $activeUsers = (int)($row['active_count'] ?? 0);
    }
}

if ($hasUsersTable && $hasRolesTable) {
    $res = mysqli_query($conn, "
        SELECT COUNT(*) AS total_count
        FROM users u
        INNER JOIN roles r ON r.id = u.current_role_id
        WHERE r.role_code = 'super_admin'
    ");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $superAdmins = (int)($row['total_count'] ?? 0);
    }

    $res = mysqli_query($conn, "
        SELECT COUNT(*) AS total_count
        FROM users u
        INNER JOIN roles r ON r.id = u.current_role_id
        WHERE r.role_code = 'qa_admin'
    ");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $qaAdmins = (int)($row['total_count'] ?? 0);
    }

    $res = mysqli_query($conn, "
        SELECT COUNT(*) AS total_count
        FROM users u
        INNER JOIN roles r ON r.id = u.current_role_id
        WHERE r.role_code = 'employee'
    ");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $employees = (int)($row['total_count'] ?? 0);
    }
}

$roles = [];
if ($hasRolesTable) {
    $res = mysqli_query($conn, "
        SELECT id, role_code, role_name, description, is_active, created_at
        FROM roles
        WHERE role_code = 'super_admin'
        ORDER BY id ASC
    ");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $roles[] = $row;
        }
    }
}

$recentRoleChanges = [];
if ($hasUserRoleHistoryTable && $hasUsersTable && $hasRolesTable) {
    $sql = "
        SELECT
            urh.id,
            urh.reason_for_change,
            urh.changed_at,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS target_user_name,
            oldr.role_name AS old_role_name,
            newr.role_name AS new_role_name,
            CONCAT(COALESCE(cb.first_name, ''), ' ', COALESCE(cb.last_name, '')) AS changed_by_name
        FROM user_role_history urh
        LEFT JOIN users u ON u.id = urh.user_id
        LEFT JOIN roles oldr ON oldr.id = urh.old_role_id
        LEFT JOIN roles newr ON newr.id = urh.new_role_id
        LEFT JOIN users cb ON cb.id = urh.changed_by
        LEFT JOIN roles nr ON nr.id = urh.new_role_id
        WHERE nr.role_code = 'super_admin'
           OR oldr.role_code = 'super_admin'
        ORDER BY urh.id DESC
        LIMIT 5
    ";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $recentRoleChanges[] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Super Admin</title>
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
    .stat-number {
      font-size: 1.85rem;
      font-weight: 700;
      line-height: 1.1;
      color: #212529;
    }
    .stat-label {
      font-size: .9rem;
      color: #6c757d;
    }
    .soft-badge {
      display: inline-flex;
      align-items: center;
      padding: .42rem .72rem;
      border-radius: 999px;
      font-size: .8rem;
      font-weight: 600;
      background: rgba(13,110,253,.10);
      color: #0d6efd;
    }
    .soft-badge.success {
      background: rgba(25,135,84,.12);
      color: #198754;
    }
    .soft-badge.secondary {
      background: rgba(108,117,125,.12);
      color: #6c757d;
    }
    .table thead th {
      white-space: nowrap;
    }
    .table td,
    .table th {
      vertical-align: middle;
    }
    .activity-item {
      padding: .85rem 0;
      border-bottom: 1px solid rgba(0,0,0,.08);
    }
    .activity-item:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }
    .activity-title {
      font-size: .96rem;
      font-weight: 600;
      color: #212529;
      margin-bottom: .2rem;
    }
    .activity-meta {
      font-size: .82rem;
      color: #6c757d;
      margin-bottom: .2rem;
    }
    .activity-reason {
      font-size: .88rem;
      color: #495057;
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
            <li><a class="dropdown-item" href="manage-user.php">User Management</a></li>
            <li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li>
          </ul>
        </li>

        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($currentUserName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small">Super Admin</span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
  <div class="content-wrap px-4 py-4 mx-auto">
    <div class="mb-4">
      <h1 class="page-title mb-2">Super Admin Controls</h1>
      <p class="page-subtitle mb-0">Manage only super admin access and high-level administrative control.</p>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card h-100">
          <div class="card-body">
            <div class="stat-number"><?php echo (int)$superAdmins; ?></div>
            <div class="stat-label">Total Super Admins</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card h-100">
          <div class="card-body">
            <div class="stat-number"><?php echo (int)$activeUsers; ?></div>
            <div class="stat-label">All Active Users</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card h-100">
          <div class="card-body">
            <div class="stat-number"><?php echo (int)$qaAdmins; ?></div>
            <div class="stat-label">QA Admins</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card h-100">
          <div class="card-body">
            <div class="stat-number"><?php echo (int)$employees; ?></div>
            <div class="stat-label">Employees</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6 col-xl-4">
        <div class="card cp-card h-100">
          <div class="card-body">
            <h2 class="card-title mb-1">Role Summary</h2>
            <p class="card-subtitle mb-3">Super Admin access only.</p>
            <ul class="small text-secondary mb-0">
              <li>Only super admin can open this page.</li>
              <li>Can manage roles and users.</li>
              <li>Can review administrative changes.</li>
              <li>Audit trail remains traceable.</li>
            </ul>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-xl-4">
        <div class="card cp-card h-100">
          <div class="card-body">
            <h2 class="card-title mb-1">Access Matrix</h2>
            <p class="card-subtitle mb-3">Showing only super admin role definition.</p>
            <div class="small text-secondary">This page is restricted to the highest access role only.</div>

            <div class="mt-3 d-flex flex-wrap gap-2">
              <span class="soft-badge secondary">Super Admin: <?php echo (int)$superAdmins; ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-xl-4">
        <div class="card cp-card h-100">
          <div class="card-body">
            <h2 class="card-title mb-1">Audit Expectation</h2>
            <p class="card-subtitle mb-3">Every super admin permission change must be traceable.</p>
            <div class="small text-secondary">Capture changed by, changed on, old role, new role, and reason.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-xl-12">
        <div class="card cp-card h-100">
          <div class="card-body">
            <h2 class="card-title mb-1">Role Definitions</h2>
            <p class="card-subtitle mb-3">Only super admin role is shown here.</p>

            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th>Role</th>
                    <th>Code</th>
                    <th>Description</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($roles)): ?>
                    <?php foreach ($roles as $role): ?>
                      <tr>
                        <td><?php echo e($role['role_name'] ?? '-'); ?></td>
                        <td><?php echo e($role['role_code'] ?? '-'); ?></td>
                        <td><?php echo e($role['description'] ?? '-'); ?></td>
                        <td>
                          <?php if ((int)($role['is_active'] ?? 0) === 1): ?>
                            <span class="soft-badge success">Active</span>
                          <?php else: ?>
                            <span class="soft-badge secondary">Inactive</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="4" class="text-center text-muted py-4">No super admin role found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>