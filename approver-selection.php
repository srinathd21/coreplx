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

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, $tableName) {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, $tableName, $columnName) {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? $_SESSION['full_name']
    : 'Pradeep';

$successMessage = '';
$errorMessage = '';

if (!isset($_SESSION['document_approver_selections']) || !is_array($_SESSION['document_approver_selections'])) {
    $_SESSION['document_approver_selections'] = [];
}

$documentId = trim($_GET['document_id'] ?? $_POST['document_id'] ?? 'DOC-001');
$documentOwner = trim($_GET['document_owner'] ?? $_POST['document_owner'] ?? $currentUserName);
$requestedAction = trim($_GET['requested_action'] ?? $_POST['requested_action'] ?? 'Create Document');
$selectedApproverId = (int)($_POST['approver_id'] ?? 0);

$usersTableExists = tableExists($conn, 'users');
$rolesTableExists = tableExists($conn, 'roles');
$documentApproversTableExists = tableExists($conn, 'document_approvers');

$approvers = [];

if ($usersTableExists) {
    $hasFirstName = columnExists($conn, 'users', 'first_name');
    $hasLastName = columnExists($conn, 'users', 'last_name');
    $hasFullName = columnExists($conn, 'users', 'full_name');
    $hasStatus = columnExists($conn, 'users', 'status');
    $hasRoleId = columnExists($conn, 'users', 'current_role_id');

    $nameExpr = "CAST(u.id AS CHAR)";
    if ($hasFirstName && $hasLastName) {
        $nameExpr = "TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')))";
    } elseif ($hasFullName) {
        $nameExpr = "u.full_name";
    }

    $sql = "SELECT u.id, {$nameExpr} AS approver_name";

    if ($rolesTableExists && $hasRoleId && columnExists($conn, 'roles', 'id') && columnExists($conn, 'roles', 'role_name')) {
        $sql .= ", r.role_name";
    } else {
        $sql .= ", '' AS role_name";
    }

    $sql .= " FROM users u";

    if ($rolesTableExists && $hasRoleId && columnExists($conn, 'roles', 'id') && columnExists($conn, 'roles', 'role_name')) {
        $sql .= " LEFT JOIN roles r ON r.id = u.current_role_id";
    }

    $where = [];
    if ($hasStatus) {
        $where[] = "(u.status = 'active' OR u.status = 'Active' OR u.status = 1)";
    }
    if ($currentUserId > 0) {
        $where[] = "u.id != " . (int)$currentUserId;
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY approver_name ASC";

    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $name = trim((string)($row['approver_name'] ?? ''));
            if ($name === '') {
                $name = 'User #' . (int)$row['id'];
            }

            $approvers[] = [
                'id' => (int)$row['id'],
                'name' => $name,
                'role_name' => (string)($row['role_name'] ?? '')
            ];
        }
    }
}

if (empty($approvers)) {
    $approvers = [
        ['id' => 101, 'name' => 'QA Head', 'role_name' => 'QA Admin'],
        ['id' => 102, 'name' => 'Compliance Manager', 'role_name' => 'QA Admin'],
        ['id' => 103, 'name' => 'Document Control Lead', 'role_name' => 'QA Admin'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_approver') {
        if ($documentId === '') {
            $errorMessage = 'Document ID is required.';
        } elseif ($documentOwner === '') {
            $errorMessage = 'Document owner is required.';
        } elseif ($requestedAction === '') {
            $errorMessage = 'Requested action is required.';
        } elseif ($selectedApproverId <= 0) {
            $errorMessage = 'Please select an approver.';
        } elseif ($currentUserId > 0 && $selectedApproverId === $currentUserId) {
            $errorMessage = 'Creator cannot select themselves as approver.';
        } else {
            $selectedApproverName = '';
            foreach ($approvers as $approver) {
                if ((int)$approver['id'] === $selectedApproverId) {
                    $selectedApproverName = $approver['name'];
                    break;
                }
            }

            if ($selectedApproverName === '') {
                $errorMessage = 'Selected approver is invalid.';
            } else {
                if ($documentApproversTableExists) {
                    $docIdCol = columnExists($conn, 'document_approvers', 'document_id') ? 'document_id' : '';
                    $ownerCol = columnExists($conn, 'document_approvers', 'document_owner') ? 'document_owner' : '';
                    $actionCol = columnExists($conn, 'document_approvers', 'requested_action') ? 'requested_action' : '';
                    $approverIdCol = columnExists($conn, 'document_approvers', 'approver_id') ? 'approver_id' : '';
                    $approverNameCol = columnExists($conn, 'document_approvers', 'approver_name') ? 'approver_name' : '';
                    $createdByCol = columnExists($conn, 'document_approvers', 'created_by') ? 'created_by' : '';
                    $updatedByCol = columnExists($conn, 'document_approvers', 'updated_by') ? 'updated_by' : '';

                    if ($docIdCol === '' || $ownerCol === '' || $actionCol === '' || $approverIdCol === '') {
                        $errorMessage = 'document_approvers table structure is incomplete.';
                    } else {
                        $checkSql = "SELECT id FROM `document_approvers` WHERE `{$docIdCol}` = ? LIMIT 1";
                        $checkStmt = mysqli_prepare($conn, $checkSql);

                        if ($checkStmt) {
                            mysqli_stmt_bind_param($checkStmt, "s", $documentId);
                            mysqli_stmt_execute($checkStmt);
                            $checkRes = mysqli_stmt_get_result($checkStmt);
                            $existsRow = $checkRes ? mysqli_fetch_assoc($checkRes) : null;
                            mysqli_stmt_close($checkStmt);

                            if ($existsRow) {
                                $updateParts = [];
                                $updateParts[] = "`{$ownerCol}` = ?";
                                $updateParts[] = "`{$actionCol}` = ?";
                                $updateParts[] = "`{$approverIdCol}` = ?";

                                if ($approverNameCol !== '') {
                                    $updateParts[] = "`{$approverNameCol}` = ?";
                                }
                                if ($updatedByCol !== '') {
                                    $updateParts[] = "`{$updatedByCol}` = ?";
                                }

                                $sql = "UPDATE `document_approvers` SET " . implode(', ', $updateParts) . " WHERE `{$docIdCol}` = ?";
                                $stmt = mysqli_prepare($conn, $sql);

                                if ($stmt) {
                                    if ($approverNameCol !== '' && $updatedByCol !== '') {
                                        mysqli_stmt_bind_param(
                                            $stmt,
                                            "ssisis",
                                            $documentOwner,
                                            $requestedAction,
                                            $selectedApproverId,
                                            $selectedApproverName,
                                            $currentUserId,
                                            $documentId
                                        );
                                    } elseif ($approverNameCol !== '') {
                                        mysqli_stmt_bind_param(
                                            $stmt,
                                            "ssiss",
                                            $documentOwner,
                                            $requestedAction,
                                            $selectedApproverId,
                                            $selectedApproverName,
                                            $documentId
                                        );
                                    } elseif ($updatedByCol !== '') {
                                        mysqli_stmt_bind_param(
                                            $stmt,
                                            "ssiis",
                                            $documentOwner,
                                            $requestedAction,
                                            $selectedApproverId,
                                            $currentUserId,
                                            $documentId
                                        );
                                    } else {
                                        mysqli_stmt_bind_param(
                                            $stmt,
                                            "ssis",
                                            $documentOwner,
                                            $requestedAction,
                                            $selectedApproverId,
                                            $documentId
                                        );
                                    }

                                    if (mysqli_stmt_execute($stmt)) {
                                        $successMessage = 'Approver selection updated successfully.';
                                    } else {
                                        $errorMessage = 'Failed to update approver selection: ' . mysqli_error($conn);
                                    }
                                    mysqli_stmt_close($stmt);
                                } else {
                                    $errorMessage = 'Failed to prepare update query.';
                                }
                            } else {
                                $cols = [];
                                $vals = [];
                                $binds = [];
                                $types = '';

                                $cols[] = "`{$docIdCol}`";
                                $vals[] = "?";
                                $binds[] = $documentId;
                                $types .= 's';

                                $cols[] = "`{$ownerCol}`";
                                $vals[] = "?";
                                $binds[] = $documentOwner;
                                $types .= 's';

                                $cols[] = "`{$actionCol}`";
                                $vals[] = "?";
                                $binds[] = $requestedAction;
                                $types .= 's';

                                $cols[] = "`{$approverIdCol}`";
                                $vals[] = "?";
                                $binds[] = $selectedApproverId;
                                $types .= 'i';

                                if ($approverNameCol !== '') {
                                    $cols[] = "`{$approverNameCol}`";
                                    $vals[] = "?";
                                    $binds[] = $selectedApproverName;
                                    $types .= 's';
                                }

                                if ($createdByCol !== '') {
                                    $cols[] = "`{$createdByCol}`";
                                    $vals[] = "?";
                                    $binds[] = $currentUserId;
                                    $types .= 'i';
                                }

                                if ($updatedByCol !== '') {
                                    $cols[] = "`{$updatedByCol}`";
                                    $vals[] = "?";
                                    $binds[] = $currentUserId;
                                    $types .= 'i';
                                }

                                $sql = "INSERT INTO `document_approvers` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                                $stmt = mysqli_prepare($conn, $sql);

                                if ($stmt) {
                                    mysqli_stmt_bind_param($stmt, $types, ...$binds);
                                    if (mysqli_stmt_execute($stmt)) {
                                        $successMessage = 'Approver selected successfully.';
                                    } else {
                                        $errorMessage = 'Failed to save approver selection: ' . mysqli_error($conn);
                                    }
                                    mysqli_stmt_close($stmt);
                                } else {
                                    $errorMessage = 'Failed to prepare insert query.';
                                }
                            }
                        } else {
                            $errorMessage = 'Failed to prepare check query.';
                        }
                    }
                } else {
                    $_SESSION['document_approver_selections'][$documentId] = [
                        'document_owner' => $documentOwner,
                        'requested_action' => $requestedAction,
                        'approver_id' => $selectedApproverId,
                        'approver_name' => $selectedApproverName
                    ];
                    $successMessage = 'Approver selected successfully.';
                }
            }
        }
    }
}

$selectedApproverName = '';

if ($documentApproversTableExists) {
    $docIdCol = columnExists($conn, 'document_approvers', 'document_id') ? 'document_id' : '';
    $ownerCol = columnExists($conn, 'document_approvers', 'document_owner') ? 'document_owner' : '';
    $actionCol = columnExists($conn, 'document_approvers', 'requested_action') ? 'requested_action' : '';
    $approverIdCol = columnExists($conn, 'document_approvers', 'approver_id') ? 'approver_id' : '';
    $approverNameCol = columnExists($conn, 'document_approvers', 'approver_name') ? 'approver_name' : '';

    if ($docIdCol !== '' && $approverIdCol !== '') {
        $selectCols = [];
        if ($ownerCol !== '') $selectCols[] = "`{$ownerCol}` AS document_owner";
        if ($actionCol !== '') $selectCols[] = "`{$actionCol}` AS requested_action";
        $selectCols[] = "`{$approverIdCol}` AS approver_id";
        if ($approverNameCol !== '') $selectCols[] = "`{$approverNameCol}` AS approver_name";

        $sql = "SELECT " . implode(', ', $selectCols) . " FROM `document_approvers` WHERE `{$docIdCol}` = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $documentId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && mysqli_num_rows($res) > 0) {
                $row = mysqli_fetch_assoc($res);
                if (!empty($row['document_owner'])) {
                    $documentOwner = (string)$row['document_owner'];
                }
                if (!empty($row['requested_action'])) {
                    $requestedAction = (string)$row['requested_action'];
                }
                $selectedApproverId = (int)($row['approver_id'] ?? 0);
                $selectedApproverName = (string)($row['approver_name'] ?? '');
            }
            mysqli_stmt_close($stmt);
        }
    }
} else {
    if (isset($_SESSION['document_approver_selections'][$documentId])) {
        $saved = $_SESSION['document_approver_selections'][$documentId];
        $documentOwner = (string)($saved['document_owner'] ?? $documentOwner);
        $requestedAction = (string)($saved['requested_action'] ?? $requestedAction);
        $selectedApproverId = (int)($saved['approver_id'] ?? 0);
        $selectedApproverName = (string)($saved['approver_name'] ?? '');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Approver Selection</title>
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
    .card-subtitle,
    .form-text {
      color: #6c757d;
    }
    .note-list {
      padding-left: 1rem;
    }
    .readonly {
      background-color: #f8f9fa;
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
      <h1 class="page-title mb-2">Approver Selection</h1>
      <p class="page-subtitle mb-0">Assign an authorized approver to review and act on the document.</p>
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
      <div class="col-lg-8">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Select Approver</h2>
            <p class="card-subtitle mb-3">Every create and update action requires an approver selected from system users.</p>

            <form method="post">
              <input type="hidden" name="action" value="save_approver">
              <input type="hidden" name="document_id" value="<?php echo e($documentId); ?>">

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Document Owner</label>
                  <input class="form-control readonly" name="document_owner" readonly value="<?php echo e($documentOwner); ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Requested Action</label>
                  <input class="form-control readonly" name="requested_action" readonly value="<?php echo e($requestedAction); ?>">
                </div>

                <div class="col-12">
                  <label class="form-label">Approver</label>
                  <select class="form-select" name="approver_id" required>
                    <option value="">Select Approver</option>
                    <?php foreach ($approvers as $approver): ?>
                      <option value="<?php echo (int)$approver['id']; ?>" <?php echo $selectedApproverId === (int)$approver['id'] ? 'selected' : ''; ?>>
                        <?php echo e($approver['name'] . ($approver['role_name'] !== '' ? ' - ' . $approver['role_name'] : '')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="form-text mt-3">The creator must never be allowed to select themselves as approver.</div>

              <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Save Approver</button>
                <a href="approver-selection.php?document_id=<?php echo urlencode($documentId); ?>&document_owner=<?php echo urlencode($documentOwner); ?>&requested_action=<?php echo urlencode($requestedAction); ?>" class="btn btn-outline-secondary">Reset</a>
              </div>
            </form>

          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Approval Rules</h2>
            <ul class="small text-secondary note-list mb-0">
              <li>Only active users with approval rights should be listed.</li>
              <li>Delegation rules should be logged if proxy approval is allowed.</li>
              <li>Approver changes before submission must be tracked in audit trail.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>