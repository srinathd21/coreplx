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

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? trim($_SESSION['full_name'])
    : 'QA Admin';

$successMessage = '';
$errorMessage = '';

if (!isset($_SESSION['submit_review_data']) || !is_array($_SESSION['submit_review_data'])) {
    $_SESSION['submit_review_data'] = [];
}

$documentId = trim($_GET['document_id'] ?? $_POST['document_id'] ?? 'SOP-104-CAPA-01');
$documentType = trim($_GET['document_type'] ?? $_POST['document_type'] ?? 'SOP');
$owner = trim($_GET['owner'] ?? $_POST['owner'] ?? 'Pradeep');
$approver = trim($_GET['approver'] ?? $_POST['approver'] ?? 'QA Head');
$effectiveDate = trim($_GET['effective_date'] ?? $_POST['effective_date'] ?? '2026-04-15');
$reviewDate = trim($_GET['review_date'] ?? $_POST['review_date'] ?? '2027-04-15');

$documentsTableExists = tableExists($conn, 'documents');
$documentApproversTableExists = tableExists($conn, 'document_approvers');
$auditTrailTableExists = tableExists($conn, 'audit_trail');
$auditLogsTableExists = tableExists($conn, 'audit_logs');
$activityLogsTableExists = tableExists($conn, 'activity_logs');

/*
|--------------------------------------------------------------------------
| LOAD EXISTING DOCUMENT DATA
|--------------------------------------------------------------------------
*/
if ($documentsTableExists && $documentId !== '') {
    $docIdCol = firstExistingColumn($conn, 'documents', ['document_id', 'doc_id', 'document_code']);
    $typeCol = firstExistingColumn($conn, 'documents', ['document_type', 'type']);
    $ownerCol = firstExistingColumn($conn, 'documents', ['owner', 'document_owner']);
    $effectiveDateCol = firstExistingColumn($conn, 'documents', ['effective_date']);
    $reviewDateCol = firstExistingColumn($conn, 'documents', ['review_date']);

    if ($docIdCol !== null) {
        $selectCols = ["`{$docIdCol}` AS document_id"];
        $selectCols[] = $typeCol !== null ? "`{$typeCol}` AS document_type" : "'' AS document_type";
        $selectCols[] = $ownerCol !== null ? "`{$ownerCol}` AS owner_name" : "'' AS owner_name";
        $selectCols[] = $effectiveDateCol !== null ? "`{$effectiveDateCol}` AS effective_date" : "'' AS effective_date";
        $selectCols[] = $reviewDateCol !== null ? "`{$reviewDateCol}` AS review_date" : "'' AS review_date";

        $sql = "SELECT " . implode(', ', $selectCols) . " FROM `documents` WHERE `{$docIdCol}` = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $documentId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && mysqli_num_rows($res) > 0) {
                $row = mysqli_fetch_assoc($res);
                if (!empty($row['document_type'])) {
                    $documentType = (string)$row['document_type'];
                }
                if (!empty($row['owner_name'])) {
                    $owner = (string)$row['owner_name'];
                }
                if (!empty($row['effective_date'])) {
                    $effectiveDate = (string)$row['effective_date'];
                }
                if (!empty($row['review_date'])) {
                    $reviewDate = (string)$row['review_date'];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

if ($documentApproversTableExists && $documentId !== '') {
    $docIdCol = firstExistingColumn($conn, 'document_approvers', ['document_id']);
    $approverNameCol = firstExistingColumn($conn, 'document_approvers', ['approver_name']);
    $ownerCol = firstExistingColumn($conn, 'document_approvers', ['document_owner']);

    if ($docIdCol !== null) {
        $selectCols = [];
        $selectCols[] = $approverNameCol !== null ? "`{$approverNameCol}` AS approver_name" : "'' AS approver_name";
        $selectCols[] = $ownerCol !== null ? "`{$ownerCol}` AS document_owner" : "'' AS document_owner";

        $sql = "SELECT " . implode(', ', $selectCols) . " FROM `document_approvers` WHERE `{$docIdCol}` = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $documentId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && mysqli_num_rows($res) > 0) {
                $row = mysqli_fetch_assoc($res);
                if (!empty($row['approver_name'])) {
                    $approver = (string)$row['approver_name'];
                }
                if (!empty($row['document_owner'])) {
                    $owner = (string)$row['document_owner'];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

if (isset($_SESSION['submit_review_data'][$documentId]) && is_array($_SESSION['submit_review_data'][$documentId])) {
    $saved = $_SESSION['submit_review_data'][$documentId];
    $documentType = $saved['document_type'] ?? $documentType;
    $owner = $saved['owner'] ?? $owner;
    $approver = $saved['approver'] ?? $approver;
    $effectiveDate = $saved['effective_date'] ?? $effectiveDate;
    $reviewDate = $saved['review_date'] ?? $reviewDate;
}

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    $documentId = trim($_POST['document_id'] ?? '');
    $documentType = trim($_POST['document_type'] ?? '');
    $owner = trim($_POST['owner'] ?? '');
    $approver = trim($_POST['approver'] ?? '');
    $effectiveDate = trim($_POST['effective_date'] ?? '');
    $reviewDate = trim($_POST['review_date'] ?? '');

    if ($action === 'confirm_submit') {
        if ($documentId === '') {
            $errorMessage = 'Document ID is required.';
        } elseif ($documentType === '') {
            $errorMessage = 'Type is required.';
        } elseif ($owner === '') {
            $errorMessage = 'Owner is required.';
        } elseif ($approver === '') {
            $errorMessage = 'Approver is required.';
        } elseif ($effectiveDate === '') {
            $errorMessage = 'Effective Date is required.';
        } elseif ($reviewDate === '') {
            $errorMessage = 'Review Date is required.';
        } else {
            $_SESSION['submit_review_data'][$documentId] = [
                'document_type' => $documentType,
                'owner' => $owner,
                'approver' => $approver,
                'effective_date' => $effectiveDate,
                'review_date' => $reviewDate,
                'status' => 'Pending Approval'
            ];

            if ($documentsTableExists) {
                $docIdCol = firstExistingColumn($conn, 'documents', ['document_id', 'doc_id', 'document_code']);
                $typeCol = firstExistingColumn($conn, 'documents', ['document_type', 'type']);
                $ownerCol = firstExistingColumn($conn, 'documents', ['owner', 'document_owner']);
                $approverCol = firstExistingColumn($conn, 'documents', ['approver', 'approver_name']);
                $effectiveDateCol = firstExistingColumn($conn, 'documents', ['effective_date']);
                $reviewDateCol = firstExistingColumn($conn, 'documents', ['review_date']);
                $statusCol = firstExistingColumn($conn, 'documents', ['status']);
                $updatedByCol = firstExistingColumn($conn, 'documents', ['updated_by']);

                if ($docIdCol !== null) {
                    $updateParts = [];
                    $bindValues = [];
                    $bindTypes = '';

                    if ($typeCol !== null) {
                        $updateParts[] = "`{$typeCol}` = ?";
                        $bindValues[] = $documentType;
                        $bindTypes .= 's';
                    }
                    if ($ownerCol !== null) {
                        $updateParts[] = "`{$ownerCol}` = ?";
                        $bindValues[] = $owner;
                        $bindTypes .= 's';
                    }
                    if ($approverCol !== null) {
                        $updateParts[] = "`{$approverCol}` = ?";
                        $bindValues[] = $approver;
                        $bindTypes .= 's';
                    }
                    if ($effectiveDateCol !== null) {
                        $updateParts[] = "`{$effectiveDateCol}` = ?";
                        $bindValues[] = $effectiveDate;
                        $bindTypes .= 's';
                    }
                    if ($reviewDateCol !== null) {
                        $updateParts[] = "`{$reviewDateCol}` = ?";
                        $bindValues[] = $reviewDate;
                        $bindTypes .= 's';
                    }
                    if ($statusCol !== null) {
                        $updateParts[] = "`{$statusCol}` = ?";
                        $bindValues[] = 'Pending Approval';
                        $bindTypes .= 's';
                    }
                    if ($updatedByCol !== null) {
                        $updateParts[] = "`{$updatedByCol}` = ?";
                        $bindValues[] = $currentUserId;
                        $bindTypes .= 'i';
                    }

                    if (!empty($updateParts)) {
                        $bindValues[] = $documentId;
                        $bindTypes .= 's';

                        $sql = "UPDATE `documents` SET " . implode(', ', $updateParts) . " WHERE `{$docIdCol}` = ?";
                        $stmt = mysqli_prepare($conn, $sql);
                        if ($stmt) {
                            mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }
                    }
                }
            }

            $logSaved = false;

            if ($auditTrailTableExists) {
                $actionCol = firstExistingColumn($conn, 'audit_trail', ['action', 'action_name', 'event_name']);
                $docIdCol = firstExistingColumn($conn, 'audit_trail', ['document_id', 'doc_id']);
                $userCol = firstExistingColumn($conn, 'audit_trail', ['user_name']);
                $reasonCol = firstExistingColumn($conn, 'audit_trail', ['reason', 'comment', 'comments', 'remarks']);
                $createdAtCol = firstExistingColumn($conn, 'audit_trail', ['created_at', 'logged_at', 'timestamp']);

                if ($actionCol !== null && $docIdCol !== null) {
                    $cols = [];
                    $vals = [];
                    $binds = [];
                    $types = '';

                    $cols[] = "`{$actionCol}`";
                    $vals[] = "?";
                    $binds[] = 'Submitted for Review';
                    $types .= 's';

                    $cols[] = "`{$docIdCol}`";
                    $vals[] = "?";
                    $binds[] = $documentId;
                    $types .= 's';

                    if ($userCol !== null) {
                        $cols[] = "`{$userCol}`";
                        $vals[] = "?";
                        $binds[] = $currentUserName;
                        $types .= 's';
                    }

                    if ($reasonCol !== null) {
                        $cols[] = "`{$reasonCol}`";
                        $vals[] = "?";
                        $binds[] = 'Status changed to Pending Approval';
                        $types .= 's';
                    }

                    $sql = "INSERT INTO `audit_trail` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $types, ...$binds);
                        if (mysqli_stmt_execute($stmt)) {
                            $logSaved = true;
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
            }

            if (!$logSaved && $auditLogsTableExists) {
                $actionCol = firstExistingColumn($conn, 'audit_logs', ['action', 'action_name', 'event_name']);
                $docIdCol = firstExistingColumn($conn, 'audit_logs', ['document_id', 'doc_id']);
                $userCol = firstExistingColumn($conn, 'audit_logs', ['user_name']);
                $reasonCol = firstExistingColumn($conn, 'audit_logs', ['reason', 'comment', 'comments', 'remarks']);

                if ($actionCol !== null && $docIdCol !== null) {
                    $cols = [];
                    $vals = [];
                    $binds = [];
                    $types = '';

                    $cols[] = "`{$actionCol}`";
                    $vals[] = "?";
                    $binds[] = 'Submitted for Review';
                    $types .= 's';

                    $cols[] = "`{$docIdCol}`";
                    $vals[] = "?";
                    $binds[] = $documentId;
                    $types .= 's';

                    if ($userCol !== null) {
                        $cols[] = "`{$userCol}`";
                        $vals[] = "?";
                        $binds[] = $currentUserName;
                        $types .= 's';
                    }

                    if ($reasonCol !== null) {
                        $cols[] = "`{$reasonCol}`";
                        $vals[] = "?";
                        $binds[] = 'Status changed to Pending Approval';
                        $types .= 's';
                    }

                    $sql = "INSERT INTO `audit_logs` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $types, ...$binds);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
            }

            if (!$logSaved && $activityLogsTableExists) {
                $actionCol = firstExistingColumn($conn, 'activity_logs', ['action', 'action_name', 'event_name']);
                $docIdCol = firstExistingColumn($conn, 'activity_logs', ['document_id', 'doc_id']);
                $userCol = firstExistingColumn($conn, 'activity_logs', ['user_name']);
                $reasonCol = firstExistingColumn($conn, 'activity_logs', ['reason', 'comment', 'comments', 'remarks']);

                if ($actionCol !== null && $docIdCol !== null) {
                    $cols = [];
                    $vals = [];
                    $binds = [];
                    $types = '';

                    $cols[] = "`{$actionCol}`";
                    $vals[] = "?";
                    $binds[] = 'Submitted for Review';
                    $types .= 's';

                    $cols[] = "`{$docIdCol}`";
                    $vals[] = "?";
                    $binds[] = $documentId;
                    $types .= 's';

                    if ($userCol !== null) {
                        $cols[] = "`{$userCol}`";
                        $vals[] = "?";
                        $binds[] = $currentUserName;
                        $types .= 's';
                    }

                    if ($reasonCol !== null) {
                        $cols[] = "`{$reasonCol}`";
                        $vals[] = "?";
                        $binds[] = 'Status changed to Pending Approval';
                        $types .= 's';
                    }

                    $sql = "INSERT INTO `activity_logs` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $types, ...$binds);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
            }

            $successMessage = 'Document submitted for review successfully.';
        }
    }
}

function displayDateValue($dateValue) {
    if ($dateValue === '') {
        return '-';
    }
    $ts = strtotime($dateValue);
    return $ts ? date('d-M-Y', $ts) : $dateValue;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Submit for Review</title>
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
<h1 class="page-title mb-2">Submit for Review</h1>
<p class="page-subtitle mb-0">Send the document into workflow for formal review and approval.</p>
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
<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Submission Summary</h2>
<p class="card-subtitle mb-3">Review all key data before status changes to Pending Approval.</p>

<form method="post">
<div class="table-responsive">
<table class="table">
<tbody>
<tr>
  <th class="w-25">Document ID</th>
  <td><input type="text" name="document_id" class="form-control" value="<?php echo e($documentId); ?>"></td>
</tr>
<tr>
  <th>Type</th>
  <td><input type="text" name="document_type" class="form-control" value="<?php echo e($documentType); ?>"></td>
</tr>
<tr>
  <th>Owner</th>
  <td><input type="text" name="owner" class="form-control" value="<?php echo e($owner); ?>"></td>
</tr>
<tr>
  <th>Approver</th>
  <td><input type="text" name="approver" class="form-control" value="<?php echo e($approver); ?>"></td>
</tr>
<tr>
  <th>Effective Date</th>
  <td><input type="date" name="effective_date" class="form-control" value="<?php echo e($effectiveDate); ?>"></td>
</tr>
<tr>
  <th>Review Date</th>
  <td><input type="date" name="review_date" class="form-control" value="<?php echo e($reviewDate); ?>"></td>
</tr>
</tbody>
</table>
</div>

<input type="hidden" name="action" value="confirm_submit">

<div class="d-flex gap-2">
<button type="button" class="btn btn-outline-secondary" onclick="history.back();">Back</button>
<button type="submit" class="btn btn-success">Confirm Submit</button>
</div>
</form>

</div></div>
</div>

<div class="col-lg-4">
<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">System Actions After Submit</h2>
<ul class="small text-secondary note-list mb-0">
<li>Status changes to Pending Approval.</li>
<li>Email notification sent to approver.</li>
<li>Submission event written to audit trail.</li>
<li>Editable content locks based on workflow rules.</li>
</ul>
</div></div>
</div>
</div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>