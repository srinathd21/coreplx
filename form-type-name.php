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
    : 'QA Admin';

$successMessage = '';
$errorMessage = '';

if (!isset($_SESSION['form_type_names']) || !is_array($_SESSION['form_type_names'])) {
    $_SESSION['form_type_names'] = [];
}

$formType = trim($_POST['form_type'] ?? '');
$formName = trim($_POST['form_name'] ?? '');

$isAvailable = false;
$uniquenessPassed = false;

$hasFormTypeNamesTable = tableExists($conn, 'form_type_names');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($formType === '') {
        $errorMessage = 'Form type is required.';
    } elseif ($formName === '') {
        $errorMessage = 'Form name is required.';
    } else {
        $nameExists = false;

        if ($hasFormTypeNamesTable) {
            $typeCol = columnExists($conn, 'form_type_names', 'form_type') ? 'form_type' : '';
            $nameCol = columnExists($conn, 'form_type_names', 'form_name') ? 'form_name' : '';
            $createdByCol = columnExists($conn, 'form_type_names', 'created_by') ? 'created_by' : '';
            $updatedByCol = columnExists($conn, 'form_type_names', 'updated_by') ? 'updated_by' : '';

            if ($typeCol === '' || $nameCol === '') {
                $errorMessage = 'form_type_names table structure is incomplete.';
            } else {
                $checkSql = "SELECT id FROM `form_type_names` WHERE LOWER(`{$nameCol}`) = LOWER(?) LIMIT 1";
                $checkStmt = mysqli_prepare($conn, $checkSql);
                if ($checkStmt) {
                    mysqli_stmt_bind_param($checkStmt, "s", $formName);
                    mysqli_stmt_execute($checkStmt);
                    $checkRes = mysqli_stmt_get_result($checkStmt);
                    $nameExists = ($checkRes && mysqli_num_rows($checkRes) > 0);
                    mysqli_stmt_close($checkStmt);
                }

                if ($nameExists) {
                    $errorMessage = 'Form name already exists.';
                } else {
                    $cols = ["`{$typeCol}`", "`{$nameCol}`"];
                    $vals = ["?", "?"];
                    $types = "ss";
                    $binds = [$formType, $formName];

                    if ($createdByCol !== '') {
                        $cols[] = "`{$createdByCol}`";
                        $vals[] = "?";
                        $types .= "i";
                        $binds[] = $currentUserId;
                    }

                    if ($updatedByCol !== '') {
                        $cols[] = "`{$updatedByCol}`";
                        $vals[] = "?";
                        $types .= "i";
                        $binds[] = $currentUserId;
                    }

                    $sql = "INSERT INTO `form_type_names` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $types, ...$binds);
                        if (mysqli_stmt_execute($stmt)) {
                            $successMessage = 'Form identity saved successfully.';
                            $isAvailable = true;
                            $uniquenessPassed = true;
                        } else {
                            $errorMessage = 'Failed to save form identity: ' . mysqli_error($conn);
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $errorMessage = 'Failed to prepare insert query.';
                    }
                }
            }
        } else {
            foreach ($_SESSION['form_type_names'] as $row) {
                if (strcasecmp((string)$row['form_name'], $formName) === 0) {
                    $nameExists = true;
                    break;
                }
            }

            if ($nameExists) {
                $errorMessage = 'Form name already exists.';
            } else {
                $_SESSION['form_type_names'][] = [
                    'form_type' => $formType,
                    'form_name' => $formName
                ];
                $successMessage = 'Form identity saved successfully.';
                $isAvailable = true;
                $uniquenessPassed = true;
            }
        }
    }
} elseif ($formName !== '') {
    $nameExists = false;

    if ($hasFormTypeNamesTable) {
        $nameCol = columnExists($conn, 'form_type_names', 'form_name') ? 'form_name' : '';
        if ($nameCol !== '') {
            $checkSql = "SELECT id FROM `form_type_names` WHERE LOWER(`{$nameCol}`) = LOWER(?) LIMIT 1";
            $checkStmt = mysqli_prepare($conn, $checkSql);
            if ($checkStmt) {
                mysqli_stmt_bind_param($checkStmt, "s", $formName);
                mysqli_stmt_execute($checkStmt);
                $checkRes = mysqli_stmt_get_result($checkStmt);
                $nameExists = ($checkRes && mysqli_num_rows($checkRes) > 0);
                mysqli_stmt_close($checkStmt);
            }
        }
    } else {
        foreach ($_SESSION['form_type_names'] as $row) {
            if (strcasecmp((string)$row['form_name'], $formName) === 0) {
                $nameExists = true;
                break;
            }
        }
    }

    $isAvailable = !$nameExists;
    $uniquenessPassed = !$nameExists;
}

if ($formName === '') {
    $isAvailable = true;
    $uniquenessPassed = true;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Form Type & Name</title>
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
    .badge-soft-success {
      background: rgba(25,135,84,.12);
      color: #198754;
    }
    .badge-soft-info {
      background: rgba(13,202,240,.12);
      color: #0dcaf0;
    }
    .badge-soft-danger {
      background: rgba(220,53,69,.12);
      color: #dc3545;
    }
    .badge {
      padding: .45rem .7rem;
      border-radius: 999px;
      font-weight: 600;
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
      <h1 class="page-title mb-2">Form Type &amp; Unique Naming</h1>
      <p class="page-subtitle mb-0">Define the form category and assign a unique controlled form name.</p>
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

    <div class="row justify-content-center">
      <div class="col-lg-7">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Form Identity</h2>
            <p class="card-subtitle mb-3">Form names must be unique and controlled.</p>

            <form method="post" id="formIdentityForm">
              <div class="mb-3">
                <label class="form-label">Form Type</label>
                <input class="form-control" name="form_type" id="form_type" placeholder="e.g. Training, Quality, Audit" value="<?php echo e($formType); ?>">
              </div>

              <div class="mb-3">
                <label class="form-label">Form Name</label>
                <input class="form-control" name="form_name" id="form_name" placeholder="Enter unique form name" value="<?php echo e($formName); ?>">
              </div>

              <div class="d-flex gap-2 flex-wrap mb-3">
                <span class="badge <?php echo $isAvailable ? 'badge-soft-success' : 'badge-soft-danger'; ?>" id="nameAvailableBadge">
                  <?php echo $isAvailable ? 'Name Available' : 'Name Exists'; ?>
                </span>
                <span class="badge <?php echo $uniquenessPassed ? 'badge-soft-info' : 'badge-soft-danger'; ?>" id="uniqueCheckBadge">
                  <?php echo $uniquenessPassed ? 'Uniqueness Check Passed' : 'Uniqueness Check Failed'; ?>
                </span>
              </div>

              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Form Identity</button>
                <a href="form-type-name.php" class="btn btn-outline-secondary">Reset</a>
              </div>
            </form>

          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const formNameInput = document.getElementById('form_name');
    const nameAvailableBadge = document.getElementById('nameAvailableBadge');
    const uniqueCheckBadge = document.getElementById('uniqueCheckBadge');

    function updateBadges() {
        const value = (formNameInput.value || '').trim();

        if (value === '') {
            nameAvailableBadge.textContent = 'Name Available';
            nameAvailableBadge.className = 'badge badge-soft-success';

            uniqueCheckBadge.textContent = 'Uniqueness Check Passed';
            uniqueCheckBadge.className = 'badge badge-soft-info';
        }
    }

    if (formNameInput) {
        formNameInput.addEventListener('input', updateBadges);
    }
});
</script>
</body>
</html>