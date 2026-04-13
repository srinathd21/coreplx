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

if (!function_exists('firstExistingColumn')) {
    function firstExistingColumn(mysqli $conn, $tableName, array $columns) {
        foreach ($columns as $column) {
            if (columnExists($conn, $tableName, $column)) {
                return $column;
            }
        }
        return null;
    }
}

date_default_timezone_set('Asia/Kolkata');

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? trim($_SESSION['full_name'])
    : 'QA Admin';

$successMessage = '';
$errorMessage = '';

if (!isset($_SESSION['electronic_signatures']) || !is_array($_SESSION['electronic_signatures'])) {
    $_SESSION['electronic_signatures'] = [];
}

$documentId = trim($_GET['document_id'] ?? $_POST['document_id'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$signatureMeaning = trim($_POST['signature_meaning'] ?? 'Approved');
$reason = trim($_POST['reason'] ?? '');
$timestampValue = date('d-M-Y H:i:s');

$usersTableExists = tableExists($conn, 'users');
$documentsTableExists = tableExists($conn, 'documents');

$signatureTable = '';
foreach (['electronic_signatures', 'document_signatures', 'approval_signatures', 'signatures'] as $tbl) {
    if (tableExists($conn, $tbl)) {
        $signatureTable = $tbl;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $timestampValue = date('d-M-Y H:i:s');

    if ($action === 'apply_signature') {
        if ($username === '') {
            $errorMessage = 'Username is required.';
        } elseif ($password === '') {
            $errorMessage = 'Password is required.';
        } elseif (!in_array($signatureMeaning, ['Approved', 'Denied'], true)) {
            $errorMessage = 'Invalid signature meaning.';
        } elseif ($reason === '') {
            $errorMessage = 'Reason is required.';
        } else {
            $verifiedUserId = 0;
            $verifiedUserName = '';
            $verified = false;

            if ($usersTableExists) {
                $loginCols = [];
                foreach (['username', 'email', 'employee_code', 'full_name', 'name'] as $col) {
                    if (columnExists($conn, 'users', $col)) {
                        $loginCols[] = $col;
                    }
                }

                $passwordCols = [];
                foreach (['password', 'password_hash', 'user_password'] as $col) {
                    if (columnExists($conn, 'users', $col)) {
                        $passwordCols[] = $col;
                    }
                }

                $statusCol = null;
                foreach (['status', 'is_active'] as $col) {
                    if (columnExists($conn, 'users', $col)) {
                        $statusCol = $col;
                        break;
                    }
                }

                if (!empty($loginCols) && !empty($passwordCols)) {
                    $orParts = [];
                    foreach ($loginCols as $col) {
                        $orParts[] = "`{$col}` = ?";
                    }

                    $sql = "SELECT * FROM `users` WHERE (" . implode(' OR ', $orParts) . ") LIMIT 1";
                    $stmt = mysqli_prepare($conn, $sql);

                    if ($stmt) {
                        $types = str_repeat('s', count($loginCols));
                        $params = array_fill(0, count($loginCols), $username);
                        mysqli_stmt_bind_param($stmt, $types, ...$params);
                        mysqli_stmt_execute($stmt);
                        $res = mysqli_stmt_get_result($stmt);

                        if ($res && mysqli_num_rows($res) > 0) {
                            $userRow = mysqli_fetch_assoc($res);

                            $isActiveUser = true;
                            if ($statusCol !== null) {
                                if ($statusCol === 'is_active') {
                                    $isActiveUser = ((string)($userRow[$statusCol] ?? '') === '1' || (int)($userRow[$statusCol] ?? 0) === 1);
                                } else {
                                    $statusValue = strtolower(trim((string)($userRow[$statusCol] ?? '')));
                                    $isActiveUser = in_array($statusValue, ['active', '1', 'yes', 'enabled', 'approved'], true);
                                }
                            }

                            if ($isActiveUser) {
                                foreach ($passwordCols as $passwordCol) {
                                    $dbPassword = (string)($userRow[$passwordCol] ?? '');
                                    if ($dbPassword === '') {
                                        continue;
                                    }

                                    $passwordMatched = false;

                                    if (password_verify($password, $dbPassword)) {
                                        $passwordMatched = true;
                                    } elseif (hash_equals($dbPassword, $password)) {
                                        $passwordMatched = true;
                                    } elseif (md5($password) === $dbPassword) {
                                        $passwordMatched = true;
                                    } elseif (sha1($password) === $dbPassword) {
                                        $passwordMatched = true;
                                    }

                                    if ($passwordMatched) {
                                        $verified = true;
                                        $verifiedUserId = (int)($userRow['id'] ?? 0);

                                        if (columnExists($conn, 'users', 'full_name')) {
                                            $verifiedUserName = trim((string)($userRow['full_name'] ?? ''));
                                        } elseif (columnExists($conn, 'users', 'first_name') && columnExists($conn, 'users', 'last_name')) {
                                            $verifiedUserName = trim((string)($userRow['first_name'] ?? '') . ' ' . (string)($userRow['last_name'] ?? ''));
                                        } else {
                                            foreach ($loginCols as $loginCol) {
                                                if (!empty($userRow[$loginCol])) {
                                                    $verifiedUserName = trim((string)$userRow[$loginCol]);
                                                    break;
                                                }
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                        }

                        mysqli_stmt_close($stmt);
                    }
                }
            } else {
                $verified = true;
                $verifiedUserId = 0;
                $verifiedUserName = $username;
            }

            if (!$verified) {
                $errorMessage = 'Invalid username or password.';
            } else {
                $statusAfter = ($signatureMeaning === 'Approved') ? 'Approved' : 'Denied';

                if ($signatureTable !== '') {
                    $docIdCol = firstExistingColumn($conn, $signatureTable, ['document_id', 'doc_id', 'document_code']);
                    $userIdCol = firstExistingColumn($conn, $signatureTable, ['user_id', 'signed_by', 'approver_id']);
                    $userNameCol = firstExistingColumn($conn, $signatureTable, ['user_name', 'signed_by_name', 'approver_name']);
                    $meaningCol = firstExistingColumn($conn, $signatureTable, ['signature_meaning', 'meaning', 'status', 'decision']);
                    $reasonCol = firstExistingColumn($conn, $signatureTable, ['reason', 'comments', 'comment', 'remarks', 'remark']);
                    $timeCol = firstExistingColumn($conn, $signatureTable, ['signed_at', 'created_at', 'timestamp', 'action_at']);
                    $createdByCol = firstExistingColumn($conn, $signatureTable, ['created_by']);
                    $updatedByCol = firstExistingColumn($conn, $signatureTable, ['updated_by']);

                    if ($meaningCol === null || $reasonCol === null) {
                        $errorMessage = $signatureTable . ' table structure is incomplete.';
                    } else {
                        $cols = [];
                        $vals = [];
                        $binds = [];
                        $types = '';

                        if ($docIdCol !== null) {
                            $cols[] = "`{$docIdCol}`";
                            $vals[] = "?";
                            $binds[] = $documentId;
                            $types .= 's';
                        }

                        if ($userIdCol !== null) {
                            $cols[] = "`{$userIdCol}`";
                            $vals[] = "?";
                            $binds[] = $verifiedUserId;
                            $types .= 'i';
                        }

                        if ($userNameCol !== null) {
                            $cols[] = "`{$userNameCol}`";
                            $vals[] = "?";
                            $binds[] = $verifiedUserName;
                            $types .= 's';
                        }

                        $cols[] = "`{$meaningCol}`";
                        $vals[] = "?";
                        $binds[] = $signatureMeaning;
                        $types .= 's';

                        $cols[] = "`{$reasonCol}`";
                        $vals[] = "?";
                        $binds[] = $reason;
                        $types .= 's';

                        if ($timeCol !== null && in_array($timeCol, ['signed_at', 'created_at', 'timestamp', 'action_at'], true)) {
                            $cols[] = "`{$timeCol}`";
                            $vals[] = "NOW()";
                        }

                        if ($createdByCol !== null) {
                            $cols[] = "`{$createdByCol}`";
                            $vals[] = "?";
                            $binds[] = $verifiedUserId > 0 ? $verifiedUserId : $currentUserId;
                            $types .= 'i';
                        }

                        if ($updatedByCol !== null) {
                            $cols[] = "`{$updatedByCol}`";
                            $vals[] = "?";
                            $binds[] = $verifiedUserId > 0 ? $verifiedUserId : $currentUserId;
                            $types .= 'i';
                        }

                        $sql = "INSERT INTO `{$signatureTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                        $stmt = mysqli_prepare($conn, $sql);

                        if ($stmt) {
                            if ($types !== '') {
                                mysqli_stmt_bind_param($stmt, $types, ...$binds);
                            }

                            if (!mysqli_stmt_execute($stmt)) {
                                $errorMessage = 'Failed to save signature: ' . mysqli_error($conn);
                            }
                            mysqli_stmt_close($stmt);
                        } else {
                            $errorMessage = 'Failed to prepare signature insert query.';
                        }
                    }
                } else {
                    $_SESSION['electronic_signatures'][] = [
                        'document_id' => $documentId,
                        'username' => $verifiedUserName,
                        'signature_meaning' => $signatureMeaning,
                        'reason' => $reason,
                        'timestamp' => $timestampValue
                    ];
                }

                if ($errorMessage === '' && $documentsTableExists && $documentId !== '') {
                    $docIdCol = firstExistingColumn($conn, 'documents', ['document_id', 'doc_id', 'document_code']);
                    $statusCol = firstExistingColumn($conn, 'documents', ['status']);
                    $approverCol = firstExistingColumn($conn, 'documents', ['approver', 'approver_name']);
                    $updatedByCol = firstExistingColumn($conn, 'documents', ['updated_by']);
                    $reviewCommentCol = firstExistingColumn($conn, 'documents', ['review_comments', 'comments', 'reason']);

                    if ($docIdCol !== null && $statusCol !== null) {
                        $updateParts = [];
                        $binds = [];
                        $types = '';

                        $updateParts[] = "`{$statusCol}` = ?";
                        $binds[] = $statusAfter;
                        $types .= 's';

                        if ($approverCol !== null) {
                            $updateParts[] = "`{$approverCol}` = ?";
                            $binds[] = $verifiedUserName;
                            $types .= 's';
                        }

                        if ($reviewCommentCol !== null) {
                            $updateParts[] = "`{$reviewCommentCol}` = ?";
                            $binds[] = $reason;
                            $types .= 's';
                        }

                        if ($updatedByCol !== null) {
                            $updateParts[] = "`{$updatedByCol}` = ?";
                            $binds[] = $verifiedUserId > 0 ? $verifiedUserId : $currentUserId;
                            $types .= 'i';
                        }

                        $binds[] = $documentId;
                        $types .= 's';

                        $sql = "UPDATE `documents` SET " . implode(', ', $updateParts) . " WHERE `{$docIdCol}` = ?";
                        $stmt = mysqli_prepare($conn, $sql);

                        if ($stmt) {
                            mysqli_stmt_bind_param($stmt, $types, ...$binds);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }
                    }
                }

                if ($errorMessage === '') {
                    $successMessage = 'Electronic signature applied successfully.';
                    $username = '';
                    $password = '';
                    $reason = '';
                    $signatureMeaning = 'Approved';
                    $timestampValue = date('d-M-Y H:i:s');
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Electronic Signature</title>
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

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="create-document.php">Create Document</a></li><li><a class="dropdown-item" href="update-document.php">Update Document</a></li><li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li><li><a class="dropdown-item" href="repository.php">Repository</a></li></ul></li>

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Workflow</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="document-types.php">Document Types</a></li><li><a class="dropdown-item" href="document-id.php">Document ID</a></li><li><a class="dropdown-item" href="content-editor.php">Content Editor</a></li><li><a class="dropdown-item" href="form-builder.php">Form Builder</a></li><li><a class="dropdown-item" href="form-type-name.php">Form Type &amp; Name</a></li><li><a class="dropdown-item" href="approver-selection.php">Approver Selection</a></li><li><a class="dropdown-item" href="submit-review.php">Submit for Review</a></li><li><a class="dropdown-item" href="electronic-signature.php">Electronic Signature</a></li><li><a class="dropdown-item" href="approver-comments.php">Approver Comments</a></li><li><a class="dropdown-item" href="notifications.php">Notifications</a></li></ul></li>

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="audit-creation.php">Audit - Creation</a></li><li><a class="dropdown-item" href="audit-approval.php">Audit - Approval</a></li><li><a class="dropdown-item" href="audit-comments.php">Audit - Comments</a></li><li><a class="dropdown-item" href="qa-admin.php">QA Admin</a></li><li><a class="dropdown-item" href="employee-role.php">Employee Role</a></li><li><a class="dropdown-item" href="super-admin.php">Super Admin</a></li><li><a class="dropdown-item" href="user-management.php">User Management</a></li><li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li></ul></li>

        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3"><span class="navbar-text small"><?php echo e($currentUserName); ?></span><a class="nav-link px-0" href="notifications.php">Notifications</a><span class="navbar-text small">Profile</span></div>
    </div>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">
<div class="mb-4">
<h1 class="page-title mb-2">Electronic Signature</h1>
<p class="page-subtitle mb-0">Capture approval or denial with secure credentials, signature meaning, and timestamp.</p>
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
<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Electronic Signature</h2>
<p class="card-subtitle mb-3">Approver enters username, password, meaning, and reason. Timestamp is auto-captured.</p>

<form method="post">
<input type="hidden" name="action" value="apply_signature">

<div class="row g-3">
<div class="col-md-6"><label class="form-label">Document ID</label><input class="form-control" name="document_id" value="<?php echo e($documentId); ?>" placeholder="Enter document ID"></div>
<div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="username" value="<?php echo e($username); ?>"></div>
<div class="col-md-6"><label class="form-label">Password</label><input class="form-control" name="password" type="password" value="<?php echo e($password); ?>"></div>
<div class="col-md-6"><label class="form-label">Signature Meaning</label><select class="form-select" name="signature_meaning"><option value="Approved" <?php echo $signatureMeaning === 'Approved' ? 'selected' : ''; ?>>Approved</option><option value="Denied" <?php echo $signatureMeaning === 'Denied' ? 'selected' : ''; ?>>Denied</option></select></div>
<div class="col-md-6"><label class="form-label">Timestamp</label><input class="form-control readonly" readonly value="<?php echo e($timestampValue); ?>"></div>
<div class="col-12"><label class="form-label">Reason</label><textarea class="form-control" name="reason" rows="3"><?php echo e($reason); ?></textarea></div>
</div>

<div class="d-flex gap-2 mt-4">
<button type="reset" class="btn btn-outline-secondary">Cancel</button>
<button type="submit" class="btn btn-success">Apply Signature</button>
</div>
</form>

</div></div>
</div>
</div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>