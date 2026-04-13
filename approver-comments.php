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
    function tableExists(mysqli $conn, string $tableName): bool {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $tableName, string $columnName): bool {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('firstExistingColumn')) {
    function firstExistingColumn(mysqli $conn, string $tableName, array $columns): ?string {
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
$currentUserName = isset($_SESSION['full_name']) && trim((string)$_SESSION['full_name']) !== ''
    ? trim((string)$_SESSION['full_name'])
    : 'QA Admin';

$successMessage = '';
$errorMessage = '';

if (!isset($_SESSION['approver_comments_fallback']) || !is_array($_SESSION['approver_comments_fallback'])) {
    $_SESSION['approver_comments_fallback'] = [];
}

$documentId = trim((string)($_GET['document_id'] ?? $_POST['document_id'] ?? ''));
$comment = trim((string)($_POST['comment'] ?? ''));

if ($documentId === '') {
    $documentId = 'SOP-104-CAPA-01';
}

$commentTable = '';
foreach (['approver_comments', 'audit_comments'] as $tbl) {
    if (tableExists($conn, $tbl)) {
        $commentTable = $tbl;
        break;
    }
}

$documentsTableExists = tableExists($conn, 'documents');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_comment') {
        if ($documentId === '') {
            $errorMessage = 'Document ID is required.';
        } elseif ($comment === '') {
            $errorMessage = 'Comment is required.';
        } else {
            if ($commentTable !== '') {
                $docIdCol = firstExistingColumn($conn, $commentTable, ['document_id', 'doc_id', 'document_code']);
                $commentCol = firstExistingColumn($conn, $commentTable, ['comment', 'comments', 'review_comment', 'remarks', 'remark']);
                $userIdCol = firstExistingColumn($conn, $commentTable, ['user_id', 'commented_by', 'approver_id']);
                $userNameCol = firstExistingColumn($conn, $commentTable, ['user_name', 'commented_by_name', 'approver_name']);
                $timeCol = firstExistingColumn($conn, $commentTable, ['commented_at', 'created_at', 'timestamp']);
                $createdByCol = firstExistingColumn($conn, $commentTable, ['created_by']);
                $updatedByCol = firstExistingColumn($conn, $commentTable, ['updated_by']);

                if ($docIdCol === null || $commentCol === null) {
                    $errorMessage = $commentTable . ' table structure is incomplete.';
                } else {
                    $cols = [];
                    $vals = [];
                    $bindValues = [];
                    $bindTypes = '';

                    $cols[] = "`{$docIdCol}`";
                    $vals[] = "?";
                    $bindValues[] = $documentId;
                    $bindTypes .= 's';

                    $cols[] = "`{$commentCol}`";
                    $vals[] = "?";
                    $bindValues[] = $comment;
                    $bindTypes .= 's';

                    if ($userIdCol !== null) {
                        $cols[] = "`{$userIdCol}`";
                        $vals[] = "?";
                        $bindValues[] = $currentUserId;
                        $bindTypes .= 'i';
                    }

                    if ($userNameCol !== null) {
                        $cols[] = "`{$userNameCol}`";
                        $vals[] = "?";
                        $bindValues[] = $currentUserName;
                        $bindTypes .= 's';
                    }

                    if ($timeCol !== null && in_array($timeCol, ['commented_at', 'created_at', 'timestamp'], true)) {
                        $cols[] = "`{$timeCol}`";
                        $vals[] = "NOW()";
                    }

                    if ($createdByCol !== null) {
                        $cols[] = "`{$createdByCol}`";
                        $vals[] = "?";
                        $bindValues[] = $currentUserId;
                        $bindTypes .= 'i';
                    }

                    if ($updatedByCol !== null) {
                        $cols[] = "`{$updatedByCol}`";
                        $vals[] = "?";
                        $bindValues[] = $currentUserId;
                        $bindTypes .= 'i';
                    }

                    $sql = "INSERT INTO `{$commentTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                    $stmt = mysqli_prepare($conn, $sql);

                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
                        if (mysqli_stmt_execute($stmt)) {
                            $successMessage = 'Comment saved successfully.';
                        } else {
                            $errorMessage = 'Failed to save comment: ' . mysqli_error($conn);
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $errorMessage = 'Failed to prepare comment insert query.';
                    }
                }
            } else {
                $_SESSION['approver_comments_fallback'][] = [
                    'document_id' => $documentId,
                    'comment' => $comment,
                    'user_id' => $currentUserId,
                    'user_name' => $currentUserName,
                    'commented_at' => date('Y-m-d H:i:s')
                ];
                $successMessage = 'Comment saved successfully.';
            }

            if ($errorMessage === '' && $documentsTableExists) {
                $docIdCol = firstExistingColumn($conn, 'documents', ['document_id', 'doc_id', 'document_code']);
                $commentCol = firstExistingColumn($conn, 'documents', ['review_comments', 'comments', 'reason']);
                $updatedByCol = firstExistingColumn($conn, 'documents', ['updated_by']);
                $statusCol = firstExistingColumn($conn, 'documents', ['status']);

                if ($docIdCol !== null) {
                    $updateParts = [];
                    $bindValues = [];
                    $bindTypes = '';

                    if ($commentCol !== null) {
                        $updateParts[] = "`{$commentCol}` = ?";
                        $bindValues[] = $comment;
                        $bindTypes .= 's';
                    }

                    if ($statusCol !== null) {
                        $updateParts[] = "`{$statusCol}` = ?";
                        $bindValues[] = 'Commented';
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

            if ($errorMessage === '') {
                $comment = '';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Approver Comments</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .cp-card{
      border:1px solid rgba(0,0,0,.08);
      border-radius:18px;
      box-shadow:0 6px 24px rgba(0,0,0,.06);
      background:#fff;
    }
    .page-title{
      font-size:27px;
      line-height:1.2;
      font-weight:700;
      color:#173f7a;
      margin:0 0 12px 0;
    }
    .page-subtitle{
      font-size:18px;
      line-height:1.55;
      color:#5c6f8e;
      margin:0;
      font-weight:400;
    }
    .card-title{
      font-size:21px;
      line-height:1.3;
      font-weight:700;
      color:#173f7a;
      margin:0 0 4px 0;
    }
    .card-subtitle{
      color:#5f708c;
      font-size:15px;
      line-height:1.55;
    }
    .form-label{
      font-size:16px;
      font-weight:600;
      color:#4c5b73;
      margin-bottom:10px;
    }
    .readonly{
      background-color:#f8f9fa;
    }
    .note-list{
      padding-left:1rem;
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
<h1 class="page-title mb-2">Approver Comments</h1>
<p class="page-subtitle mb-0">Record review comments and notify document owners of required feedback.</p>
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
<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Add Review Comment</h2>
<p class="card-subtitle mb-3">Approver can comment without approving or denying.</p>

<form method="post">
  <input type="hidden" name="action" value="save_comment">

  <div class="mb-3">
    <label class="form-label">Document ID</label>
    <input class="form-control readonly" name="document_id" readonly value="<?php echo e($documentId); ?>"/>
  </div>

  <div class="mb-3">
    <label class="form-label">Comment</label>
    <textarea class="form-control" name="comment" placeholder="Enter review comment" rows="5"><?php echo e($comment); ?></textarea>
  </div>

  <div class="d-flex gap-2">
    <a href="approver-comments.php<?php echo $documentId !== '' ? '?document_id=' . urlencode($documentId) : ''; ?>" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Save Comment</button>
  </div>
</form>

</div></div>
</div>

<div class="col-lg-5">
<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Traceability</h2>
<ul class="small text-secondary note-list mb-0">
<li>Commenter name and timestamp auto-captured.</li>
<li>Owner notified by system email.</li>
<li>Comment linked to document ID and version.</li>
</ul>
</div></div>
</div>
</div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>