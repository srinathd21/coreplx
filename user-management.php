<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatDateOnly')) {
    function formatDateOnly($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime($datetime);
        return $ts ? date('d-M-Y', $ts) : '-';
    }
}

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass($status) {
        $status = strtolower(trim((string)$status));
        switch ($status) {
            case 'active':
                return 'badge-soft-success';
            case 'locked':
                return 'badge-soft-warning';
            case 'suspended':
                return 'badge-soft-danger';
            case 'inactive':
            default:
                return 'badge-soft-secondary';
        }
    }
}

$currentUserId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && $_SESSION['full_name'] !== '' ? $_SESSION['full_name'] : 'QA Admin';

// Check if current user exists in database for foreign key reference
$validCurrentUserId = 0;
if ($currentUserId > 0) {
    $checkUserStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($checkUserStmt, "i", $currentUserId);
    mysqli_stmt_execute($checkUserStmt);
    $checkUserRes = mysqli_stmt_get_result($checkUserStmt);
    if ($checkUserRes && mysqli_num_rows($checkUserRes) > 0) {
        $validCurrentUserId = $currentUserId;
    }
}

// If no valid current user, try to get the first admin user
if ($validCurrentUserId === 0) {
    $adminStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE current_role_id IN (2, 3) LIMIT 1");
    mysqli_stmt_execute($adminStmt);
    $adminRes = mysqli_stmt_get_result($adminStmt);
    if ($adminRes && mysqli_num_rows($adminRes) > 0) {
        $adminRow = mysqli_fetch_assoc($adminRes);
        $validCurrentUserId = (int)$adminRow['id'];
    }
}

$successMessage = '';
$errorMessage   = '';

/*
|--------------------------------------------------------------------------
| EXPORT CSV
|--------------------------------------------------------------------------
*/
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "
        SELECT
            u.first_name,
            u.last_name,
            u.email,
            r.role_name,
            u.status,
            u.last_login_at
        FROM users u
        LEFT JOIN roles r ON r.id = u.current_role_id
        ORDER BY u.id DESC
    ";
    $res = mysqli_query($conn, $sql);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=user-management-' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Email', 'Role', 'Status', 'Last Login']);

    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            fputcsv($output, [
                $name,
                $row['email'] ?? '',
                $row['role_name'] ?? '',
                ucfirst($row['status'] ?? ''),
                formatDateOnly($row['last_login_at'] ?? '')
            ]);
        }
    }
    fclose($output);
    exit;
}

/*
|--------------------------------------------------------------------------
| HANDLE ADD USER
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($action === 'add_user') {
        $firstName          = trim($_POST['first_name'] ?? '');
        $lastName           = trim($_POST['last_name'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $phone              = trim($_POST['phone'] ?? '');
        $employeeCode       = trim($_POST['employee_code'] ?? '');
        $password           = trim($_POST['password'] ?? '');
        $currentRoleId      = (int)($_POST['current_role_id'] ?? 0);
        $departmentIdRaw    = trim($_POST['department_id'] ?? '');
        $status             = trim($_POST['status'] ?? 'active');
        $timezone           = trim($_POST['timezone'] ?? 'Asia/Kolkata');
        $mustChangePassword = isset($_POST['must_change_password']) ? 1 : 0;

        $allowedStatuses = ['active', 'inactive', 'locked', 'suspended'];

        if ($firstName === '' || $email === '' || $password === '' || $currentRoleId <= 0) {
            $errorMessage = 'Please fill all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please enter a valid email address.';
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $errorMessage = 'Invalid user status.';
        } else {
            $checkStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
            mysqli_stmt_bind_param($checkStmt, "s", $email);
            mysqli_stmt_execute($checkStmt);
            $checkRes = mysqli_stmt_get_result($checkStmt);

            if ($checkRes && mysqli_num_rows($checkRes) > 0) {
                $errorMessage = 'This email already exists.';
            } else {
                $departmentId = ($departmentIdRaw !== '') ? (int)$departmentIdRaw : null;
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Use valid current user ID or set to NULL (allow NULL in database)
                $createdBy = ($validCurrentUserId > 0) ? $validCurrentUserId : null;
                $updatedBy = ($validCurrentUserId > 0) ? $validCurrentUserId : null;

                $insertSql = "
                    INSERT INTO users (
                        employee_code,
                        first_name,
                        last_name,
                        email,
                        phone,
                        password_hash,
                        current_role_id,
                        department_id,
                        status,
                        must_change_password,
                        timezone,
                        created_by,
                        updated_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                $stmt = mysqli_prepare($conn, $insertSql);

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssssssissisii",
                    $employeeCode,
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $passwordHash,
                    $currentRoleId,
                    $departmentId,
                    $status,
                    $mustChangePassword,
                    $timezone,
                    $createdBy,
                    $updatedBy
                );

                if (mysqli_stmt_execute($stmt)) {
                    $newUserId = mysqli_insert_id($conn);

                    if ($newUserId > 0 && $validCurrentUserId > 0) {
                        $reason = 'Initial role assigned during user creation';
                        $historyStmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO user_role_history (user_id, old_role_id, new_role_id, reason_for_change, changed_by) VALUES (?, NULL, ?, ?, ?)"
                        );
                        mysqli_stmt_bind_param($historyStmt, "iisi", $newUserId, $currentRoleId, $reason, $validCurrentUserId);
                        mysqli_stmt_execute($historyStmt);
                    }

                    $successMessage = 'User created successfully.';
                } else {
                    $errorMessage = 'Failed to create user: ' . mysqli_error($conn);
                }
            }
        }
    }

    if ($action === 'update_user') {
        $userId              = (int)($_POST['user_id'] ?? 0);
        $firstName           = trim($_POST['first_name'] ?? '');
        $lastName            = trim($_POST['last_name'] ?? '');
        $email               = trim($_POST['email'] ?? '');
        $phone               = trim($_POST['phone'] ?? '');
        $employeeCode        = trim($_POST['employee_code'] ?? '');
        $password            = trim($_POST['password'] ?? '');
        $currentRoleId       = (int)($_POST['current_role_id'] ?? 0);
        $departmentIdRaw     = trim($_POST['department_id'] ?? '');
        $status              = trim($_POST['status'] ?? 'active');
        $timezone            = trim($_POST['timezone'] ?? 'Asia/Kolkata');
        $mustChangePassword  = isset($_POST['must_change_password']) ? 1 : 0;
        $roleChangeReason    = trim($_POST['role_change_reason'] ?? 'Role updated from user management');

        $allowedStatuses = ['active', 'inactive', 'locked', 'suspended'];

        if ($userId <= 0 || $firstName === '' || $email === '' || $currentRoleId <= 0) {
            $errorMessage = 'Please fill all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please enter a valid email address.';
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $errorMessage = 'Invalid user status.';
        } else {
            $oldStmt = mysqli_prepare($conn, "SELECT current_role_id FROM users WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($oldStmt, "i", $userId);
            mysqli_stmt_execute($oldStmt);
            $oldRes = mysqli_stmt_get_result($oldStmt);
            $oldRow = $oldRes ? mysqli_fetch_assoc($oldRes) : null;

            if (!$oldRow) {
                $errorMessage = 'User not found.';
            } else {
                $dupStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                mysqli_stmt_bind_param($dupStmt, "si", $email, $userId);
                mysqli_stmt_execute($dupStmt);
                $dupRes = mysqli_stmt_get_result($dupStmt);

                if ($dupRes && mysqli_num_rows($dupRes) > 0) {
                    $errorMessage = 'Another user already uses this email.';
                } else {
                    $departmentId = ($departmentIdRaw !== '') ? (int)$departmentIdRaw : null;
                    $updatedBy = ($validCurrentUserId > 0) ? $validCurrentUserId : null;

                    if ($password !== '') {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        $updateSql = "
                            UPDATE users
                            SET employee_code = ?, first_name = ?, last_name = ?, email = ?, phone = ?,
                                password_hash = ?, current_role_id = ?, department_id = ?, status = ?,
                                must_change_password = ?, timezone = ?, updated_by = ?
                            WHERE id = ?
                        ";
                        $stmt = mysqli_prepare($conn, $updateSql);
                        mysqli_stmt_bind_param(
                            $stmt,
                            "ssssssissisii",
                            $employeeCode,
                            $firstName,
                            $lastName,
                            $email,
                            $phone,
                            $passwordHash,
                            $currentRoleId,
                            $departmentId,
                            $status,
                            $mustChangePassword,
                            $timezone,
                            $updatedBy,
                            $userId
                        );
                    } else {
                        $updateSql = "
                            UPDATE users
                            SET employee_code = ?, first_name = ?, last_name = ?, email = ?, phone = ?,
                                current_role_id = ?, department_id = ?, status = ?, must_change_password = ?,
                                timezone = ?, updated_by = ?
                            WHERE id = ?
                        ";
                        $stmt = mysqli_prepare($conn, $updateSql);
                        mysqli_stmt_bind_param(
                            $stmt,
                            "sssssissisii",
                            $employeeCode,
                            $firstName,
                            $lastName,
                            $email,
                            $phone,
                            $currentRoleId,
                            $departmentId,
                            $status,
                            $mustChangePassword,
                            $timezone,
                            $updatedBy,
                            $userId
                        );
                    }

                    if (mysqli_stmt_execute($stmt)) {
                        $oldRoleId = (int)$oldRow['current_role_id'];

                        if ($oldRoleId !== $currentRoleId && $validCurrentUserId > 0) {
                            $historyStmt = mysqli_prepare(
                                $conn,
                                "INSERT INTO user_role_history (user_id, old_role_id, new_role_id, reason_for_change, changed_by) VALUES (?, ?, ?, ?, ?)"
                            );
                            mysqli_stmt_bind_param($historyStmt, "iiisi", $userId, $oldRoleId, $currentRoleId, $roleChangeReason, $validCurrentUserId);
                            mysqli_stmt_execute($historyStmt);
                        }

                        $successMessage = 'User updated successfully.';
                    } else {
                        $errorMessage = 'Failed to update user: ' . mysqli_error($conn);
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD DROPDOWNS
|--------------------------------------------------------------------------
*/
$roles = [];
$rolesRes = mysqli_query($conn, "SELECT id, role_name FROM roles WHERE is_active = 1 ORDER BY role_name ASC");
if ($rolesRes) {
    while ($row = mysqli_fetch_assoc($rolesRes)) {
        $roles[] = $row;
    }
}

$departments = [];
$deptRes = mysqli_query($conn, "SELECT id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC");
if ($deptRes) {
    while ($row = mysqli_fetch_assoc($deptRes)) {
        $departments[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| USER LIST
|--------------------------------------------------------------------------
*/
$users = [];
$userSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.status,
        u.last_login_at,
        u.employee_code,
        u.phone,
        u.current_role_id,
        u.department_id,
        u.must_change_password,
        u.timezone,
        r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    ORDER BY u.id DESC
";
$userRes = mysqli_query($conn, $userSql);
if ($userRes) {
    while ($row = mysqli_fetch_assoc($userRes)) {
        $users[] = $row;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - User Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .badge-soft-success{background:rgba(25,135,84,.12);color:#198754}
    .badge-soft-warning{background:rgba(255,193,7,.18);color:#8a6d03}
    .badge-soft-danger{background:rgba(220,53,69,.12);color:#dc3545}
    .badge-soft-secondary{background:rgba(108,117,125,.12);color:#6c757d}
    .badge{padding:.45rem .7rem;border-radius:999px;font-weight:600}
    .table td,.table th{vertical-align:middle}
    .modal .form-label{font-weight:500}
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
        <span class="navbar-text small">Profile</span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
  <div class="content-wrap px-4 py-4 mx-auto">
    <div class="mb-4">
      <h1 class="page-title mb-2">User Management</h1>
      <p class="page-subtitle mb-0">Create, update, deactivate, and control user access based on assigned roles.</p>
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

    <div class="card cp-card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h2 class="card-title mb-1">Users</h2>
            <p class="card-subtitle mb-0">Create, update, deactivate, and control user access based on assigned roles.</p>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">Add User</button>
            <a href="manage-user.php?export=csv" class="btn btn-outline-primary">Export</a>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($users)): ?>
                <?php foreach ($users as $user): ?>
                  <?php
                    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    if ($fullName === '') {
                        $fullName = 'Unnamed User';
                    }
                    $badgeClass = statusBadgeClass($user['status'] ?? 'inactive');
                  ?>
                  <tr>
                    <td><?php echo e($fullName); ?></td>
                    <td><?php echo e($user['email'] ?? '-'); ?></td>
                    <td><?php echo e($user['role_name'] ?? '-'); ?></td>
                    <td>
                      <span class="badge <?php echo e($badgeClass); ?>">
                        <?php echo e(ucfirst($user['status'] ?? 'inactive')); ?>
                      </span>
                    </td>
                    <td><?php echo e(formatDateOnly($user['last_login_at'] ?? '')); ?></td>
                    <td>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-primary edit-user-btn"
                        data-bs-toggle="modal"
                        data-bs-target="#editUserModal"
                        data-id="<?php echo (int)$user['id']; ?>"
                        data-first_name="<?php echo e($user['first_name']); ?>"
                        data-last_name="<?php echo e($user['last_name']); ?>"
                        data-email="<?php echo e($user['email']); ?>"
                        data-phone="<?php echo e($user['phone']); ?>"
                        data-employee_code="<?php echo e($user['employee_code']); ?>"
                        data-current_role_id="<?php echo (int)$user['current_role_id']; ?>"
                        data-department_id="<?php echo (int)$user['department_id']; ?>"
                        data-status="<?php echo e($user['status']); ?>"
                        data-must_change_password="<?php echo (int)$user['must_change_password']; ?>"
                        data-timezone="<?php echo e($user['timezone']); ?>"
                      >
                        Edit
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">No users found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
        </div>

      </div>
    </div>
  </div>
</main>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="add_user">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Employee Code</label>
            <input type="text" name="employee_code" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">First Name *</label>
            <input type="text" name="first_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" class="form-control">
          </div>

          <div class="col-md-6">
            <label class="form-label">Email *</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control">
          </div>

          <div class="col-md-6">
            <label class="form-label">Password *</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Role *</label>
            <select name="current_role_id" class="form-select" required>
              <option value="">Select</option>
              <?php foreach ($roles as $role): ?>
                <option value="<?php echo (int)$role['id']; ?>"><?php echo e($role['role_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">Select</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?php echo (int)$dept['id']; ?>"><?php echo e($dept['department_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="locked">Locked</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Timezone</label>
            <input type="text" name="timezone" class="form-control" value="Asia/Kolkata">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="must_change_password" id="add_must_change_password" value="1">
              <label class="form-check-label" for="add_must_change_password">
                Must change password
              </label>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary">Save User</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="update_user">
        <input type="hidden" name="user_id" id="edit_user_id">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Employee Code</label>
            <input type="text" name="employee_code" id="edit_employee_code" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">First Name *</label>
            <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" id="edit_last_name" class="form-control">
          </div>

          <div class="col-md-6">
            <label class="form-label">Email *</label>
            <input type="email" name="email" id="edit_email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" id="edit_phone" class="form-control">
          </div>

          <div class="col-md-6">
            <label class="form-label">New Password</label>
            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
          </div>
          <div class="col-md-3">
            <label class="form-label">Role *</label>
            <select name="current_role_id" id="edit_current_role_id" class="form-select" required>
              <option value="">Select</option>
              <?php foreach ($roles as $role): ?>
                <option value="<?php echo (int)$role['id']; ?>"><?php echo e($role['role_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Department</label>
            <select name="department_id" id="edit_department_id" class="form-select">
              <option value="">Select</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?php echo (int)$dept['id']; ?>"><?php echo e($dept['department_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" id="edit_status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="locked">Locked</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Timezone</label>
            <input type="text" name="timezone" id="edit_timezone" class="form-control">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="must_change_password" id="edit_must_change_password" value="1">
              <label class="form-check-label" for="edit_must_change_password">
                Must change password
              </label>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Role Change Reason</label>
            <input type="text" name="role_change_reason" class="form-control" value="Role updated from user management">
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary">Update User</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var editButtons = document.querySelectorAll('.edit-user-btn');

    editButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('edit_user_id').value = this.getAttribute('data-id') || '';
            document.getElementById('edit_employee_code').value = this.getAttribute('data-employee_code') || '';
            document.getElementById('edit_first_name').value = this.getAttribute('data-first_name') || '';
            document.getElementById('edit_last_name').value = this.getAttribute('data-last_name') || '';
            document.getElementById('edit_email').value = this.getAttribute('data-email') || '';
            document.getElementById('edit_phone').value = this.getAttribute('data-phone') || '';
            document.getElementById('edit_current_role_id').value = this.getAttribute('data-current_role_id') || '';
            document.getElementById('edit_department_id').value = this.getAttribute('data-department_id') || '';
            document.getElementById('edit_status').value = this.getAttribute('data-status') || 'active';
            document.getElementById('edit_timezone').value = this.getAttribute('data-timezone') || 'Asia/Kolkata';
            document.getElementById('edit_must_change_password').checked = this.getAttribute('data-must_change_password') === '1';
        });
    });
});
</script>
</body>
</html>