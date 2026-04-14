<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

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

if (!function_exists('formatDateDisplay')) {
    function formatDateDisplay($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime((string)$date);
        return $ts ? date('d-M-Y', $ts) : e((string)$date);
    }
}

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass($status, $reviewDate = '') {
        $status = strtolower(trim((string)$status));
        if ($status === 'deleted') return 'badge badge-soft-danger';
        if ($status === 'retired') return 'badge badge-soft-secondary';
        if ($status === 'pending_retirement') return 'badge badge-soft-warning';
        if ($status === 'pending_approval') return 'badge badge-soft-warning';
        if ($status === 'draft') return 'badge badge-soft-secondary';

        if ($reviewDate !== '' && $reviewDate !== '0000-00-00') {
            $today = strtotime(date('Y-m-d'));
            $reviewTs = strtotime($reviewDate);
            if ($reviewTs && $reviewTs < $today) {
                return 'badge badge-soft-danger';
            }
        }

        return 'badge badge-soft-success';
    }
}

if (!function_exists('displayStatusLabel')) {
    function displayStatusLabel($status, $reviewDate = '') {
        $status = trim((string)$status);
        $lower = strtolower($status);

        if ($lower === 'effective' || $lower === 'approved') {
            if ($reviewDate !== '' && $reviewDate !== '0000-00-00') {
                $today = strtotime(date('Y-m-d'));
                $reviewTs = strtotime($reviewDate);
                if ($reviewTs && $reviewTs < $today) {
                    return 'Overdue';
                }
            }
            return 'Effective';
        }

        if ($lower === 'pending_approval') return 'Pending Approval';
        if ($lower === 'pending_retirement') return 'Pending Retirement';
        if ($lower === 'retired') return 'Retired';
        if ($lower === 'deleted') return 'Deleted';
        if ($lower === 'draft') return 'Draft';

        return $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Effective';
    }
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

$currentUser = null;
$userSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
";
$userStmt = mysqli_prepare($conn, $userSql);
if ($userStmt) {
    mysqli_stmt_bind_param($userStmt, "i", $userId);
    mysqli_stmt_execute($userStmt);
    $userRes = mysqli_stmt_get_result($userStmt);
    $currentUser = ($userRes && mysqli_num_rows($userRes) > 0) ? mysqli_fetch_assoc($userRes) : null;
    mysqli_stmt_close($userStmt);
}

$displayName = 'QA Admin';
$roleName = 'Profile';
if ($currentUser) {
    $displayName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
    if ($displayName === '') $displayName = 'QA Admin';
    $roleName = trim((string)($currentUser['role_name'] ?? 'Profile'));
}

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_document') {
    $deleteId = (int)($_POST['document_id'] ?? 0);

    if ($deleteId <= 0) {
        $errorMessage = 'Invalid document selected for delete.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            if (columnExists($conn, 'documents', 'current_status')) {
                $delSql = "UPDATE documents SET current_status = 'deleted' WHERE id = ?";
                $delStmt = mysqli_prepare($conn, $delSql);
                if (!$delStmt) {
                    throw new RuntimeException('Failed to prepare delete update.');
                }
                mysqli_stmt_bind_param($delStmt, "i", $deleteId);
                if (!mysqli_stmt_execute($delStmt)) {
                    throw new RuntimeException('Failed to delete document: ' . mysqli_stmt_error($delStmt));
                }
                mysqli_stmt_close($delStmt);
            } else {
                $delSql = "DELETE FROM documents WHERE id = ?";
                $delStmt = mysqli_prepare($conn, $delSql);
                if (!$delStmt) {
                    throw new RuntimeException('Failed to prepare delete query.');
                }
                mysqli_stmt_bind_param($delStmt, "i", $deleteId);
                if (!mysqli_stmt_execute($delStmt)) {
                    throw new RuntimeException('Failed to delete document: ' . mysqli_stmt_error($delStmt));
                }
                mysqli_stmt_close($delStmt);
            }

            if (tableExists($conn, 'audit_logs')) {
                $eventId = bin2hex(random_bytes(16));
                $entityType = 'document';
                $action = 'delete';
                $oldValue = null;
                $newValue = json_encode(['document_id' => $deleteId, 'status' => 'deleted'], JSON_UNESCAPED_UNICODE);
                $remarks = 'Document deleted from repository.';
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
                $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

                $auditSql = "
                    INSERT INTO audit_logs (
                        event_id,
                        entity_type,
                        entity_id,
                        action,
                        old_value,
                        new_value,
                        performed_by,
                        remarks,
                        ip_address,
                        user_agent
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                $auditStmt = mysqli_prepare($conn, $auditSql);
                if ($auditStmt) {
                    mysqli_stmt_bind_param(
                        $auditStmt,
                        "ssisssisss",
                        $eventId,
                        $entityType,
                        $deleteId,
                        $action,
                        $oldValue,
                        $newValue,
                        $userId,
                        $remarks,
                        $ipAddress,
                        $userAgent
                    );
                    mysqli_stmt_execute($auditStmt);
                    mysqli_stmt_close($auditStmt);
                }
            }

            mysqli_commit($conn);
            $successMessage = 'Document deleted successfully.';
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

$viewId = (int)($_GET['view_id'] ?? 0);
$viewDocument = null;

if ($viewId > 0) {
    $viewSql = "
        SELECT
            d.id,
            d.document_number,
            d.title,
            d.topic,
            d.current_status,
            dv.version_label,
            dv.effective_date,
            dv.review_date,
            dv.content_text,
            dv.primary_file_name,
            dv.primary_file_path
        FROM documents d
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE d.id = ?
        LIMIT 1
    ";
    $viewStmt = mysqli_prepare($conn, $viewSql);
    if ($viewStmt) {
        mysqli_stmt_bind_param($viewStmt, "i", $viewId);
        mysqli_stmt_execute($viewStmt);
        $viewRes = mysqli_stmt_get_result($viewStmt);
        $viewDocument = ($viewRes && mysqli_num_rows($viewRes) > 0) ? mysqli_fetch_assoc($viewRes) : null;
        mysqli_stmt_close($viewStmt);
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$rows = [];

$sql = "
    SELECT
        d.id,
        d.document_number,
        d.title,
        d.topic,
        d.current_status,
        dv.version_label,
        dv.effective_date,
        dv.review_date,
        dv.primary_file_name,
        dv.primary_file_path
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE 1=1
";

$params = [];
$types  = '';

if ($search !== '') {
    $sql .= " AND (
        d.document_number LIKE ?
        OR d.title LIKE ?
        OR d.topic LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($statusFilter !== '') {
    if ($statusFilter === 'effective') {
        $sql .= " AND (LOWER(COALESCE(d.current_status, 'effective')) IN ('effective','approved'))";
    } elseif ($statusFilter === 'overdue') {
        $sql .= " AND dv.review_date IS NOT NULL AND dv.review_date <> '0000-00-00' AND dv.review_date < CURDATE()";
    } else {
        $sql .= " AND LOWER(COALESCE(d.current_status, '')) = ?";
        $params[] = strtolower($statusFilter);
        $types .= 's';
    }
} else {
    $sql .= " AND LOWER(COALESCE(d.current_status, 'effective')) <> 'deleted'";
}

$sql .= " ORDER BY d.id DESC LIMIT 200";

$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
} else {
    $errorMessage = 'Failed to load repository documents.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Repository</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .table td, .table th { vertical-align: middle; }
    .action-btns { white-space: nowrap; }
    .view-card pre {
      white-space: pre-wrap;
      word-wrap: break-word;
      margin: 0;
      font-family: inherit;
      font-size: 14px;
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

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="create-document.php">Create Document</a></li><li><a class="dropdown-item" href="update-document.php">Update Document</a></li><li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li><li><a class="dropdown-item active" href="repository.php">Repository</a></li></ul></li>

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Workflow</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="document-types.php">Document Types</a></li><li><a class="dropdown-item" href="document-id.php">Document ID</a></li><li><a class="dropdown-item" href="content-editor.php">Content Editor</a></li><li><a class="dropdown-item" href="form-builder.php">Form Builder</a></li><li><a class="dropdown-item" href="form-type-name.php">Form Type &amp; Name</a></li><li><a class="dropdown-item" href="approver-selection.php">Approver Selection</a></li><li><a class="dropdown-item" href="submit-review.php">Submit for Review</a></li><li><a class="dropdown-item" href="electronic-signature.php">Electronic Signature</a></li><li><a class="dropdown-item" href="approver-comments.php">Approver Comments</a></li><li><a class="dropdown-item" href="notifications.php">Notifications</a></li></ul></li>

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="audit-creation.php">Audit - Creation</a></li><li><a class="dropdown-item" href="audit-approval.php">Audit - Approval</a></li><li><a class="dropdown-item" href="audit-comments.php">Audit - Comments</a></li><li><a class="dropdown-item" href="qa-admin.php">QA Admin</a></li><li><a class="dropdown-item" href="employee-role.php">Employee Role</a></li><li><a class="dropdown-item" href="super-admin.php">Super Admin</a></li><li><a class="dropdown-item" href="user-management.php">User Management</a></li><li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li></ul></li>

        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3"><span class="navbar-text small"><?php echo e($displayName); ?></span><a class="nav-link px-0" href="notifications.php">Notifications</a><span class="navbar-text small"><?php echo e($roleName); ?></span></div>
    </div>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">
<div class="mb-4">
<h1 class="page-title mb-2">Effective Documents Repository</h1>
<p class="page-subtitle mb-0">Access approved and effective controlled documents in a read-only repository.</p>
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

<div class="card cp-card mb-3">
  <div class="card-body">
    <form method="get" class="row g-3 mb-0">
      <div class="col-md-4">
        <label class="form-label">Search</label>
        <input type="text" name="search" class="form-control" value="<?php echo e($search); ?>" placeholder="Search document number / title / topic">
      </div>
      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">All Active</option>
          <option value="effective" <?php echo $statusFilter === 'effective' ? 'selected' : ''; ?>>Effective</option>
          <option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
          <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
          <option value="pending_approval" <?php echo $statusFilter === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
          <option value="pending_retirement" <?php echo $statusFilter === 'pending_retirement' ? 'selected' : ''; ?>>Pending Retirement</option>
          <option value="retired" <?php echo $statusFilter === 'retired' ? 'selected' : ''; ?>>Retired</option>
          <option value="deleted" <?php echo $statusFilter === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <a href="repository.php" class="btn btn-outline-secondary w-100">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php if ($viewDocument): ?>
<div class="card cp-card mb-3 view-card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h2 class="card-title mb-1">Document View</h2>
        <p class="card-subtitle mb-0"><?php echo e($viewDocument['document_number']); ?> - <?php echo e($viewDocument['title'] ?: ($viewDocument['topic'] ?: 'Untitled')); ?></p>
      </div>
      <a href="repository.php" class="btn btn-outline-secondary">Close View</a>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-3"><strong>Version:</strong> <?php echo e($viewDocument['version_label'] ?: '-'); ?></div>
      <div class="col-md-3"><strong>Effective Date:</strong> <?php echo e(formatDateDisplay($viewDocument['effective_date'] ?? '')); ?></div>
      <div class="col-md-3"><strong>Review Date:</strong> <?php echo e(formatDateDisplay($viewDocument['review_date'] ?? '')); ?></div>
      <div class="col-md-3"><strong>Status:</strong> <?php echo e(displayStatusLabel($viewDocument['current_status'] ?? '', $viewDocument['review_date'] ?? '')); ?></div>
    </div>

    <div class="border rounded p-3 bg-light">
      <?php if (!empty($viewDocument['content_text'])): ?>
        <pre><?php echo e($viewDocument['content_text']); ?></pre>
      <?php elseif (!empty($viewDocument['primary_file_name'])): ?>
        <div class="text-secondary">No text content available. Attached file: <strong><?php echo e($viewDocument['primary_file_name']); ?></strong></div>
      <?php else: ?>
        <div class="text-secondary">No document content available.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Effective Controlled Documents</h2>
<p class="card-subtitle mb-3">Approved documents appear here in a read-only repository.</p>
<div class="table-responsive">
<table class="table align-middle">
<thead><tr><th>Document ID</th><th>Title</th><th>Effective Date</th><th>Next Review Date</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php if (!empty($rows)): ?>
  <?php foreach ($rows as $row): ?>
    <?php
      $docId = (int)$row['id'];
      $statusLabel = displayStatusLabel($row['current_status'] ?? '', $row['review_date'] ?? '');
      $statusClass = statusBadgeClass($row['current_status'] ?? '', $row['review_date'] ?? '');
      $title = $row['title'] ?: ($row['topic'] ?: 'Untitled');
      $pdfLink = '#';
      if (!empty($row['primary_file_path'])) {
          $pdfLink = e($row['primary_file_path']);
      } else {
          $pdfLink = 'repository.php?view_id=' . $docId;
      }
    ?>
    <tr>
      <td class="fw-semibold"><?php echo e($row['document_number'] ?: ('DOC-' . $docId)); ?></td>
      <td><?php echo e($title); ?></td>
      <td><?php echo e(formatDateDisplay($row['effective_date'] ?? '')); ?></td>
      <td><?php echo e(formatDateDisplay($row['review_date'] ?? '')); ?></td>
      <td><span class="<?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span></td>
      <td class="action-btns">
        <a href="repository.php?view_id=<?php echo $docId; ?>" class="btn btn-sm btn-outline-primary">View</a>
        <a href="<?php echo $pdfLink; ?>" class="btn btn-sm btn-outline-secondary" target="_blank">PDF</a>
        <a href="update-document.php?id=<?php echo $docId; ?>" class="btn btn-sm btn-outline-warning">Edit</a>
        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this document?');">
          <input type="hidden" name="action" value="delete_document">
          <input type="hidden" name="document_id" value="<?php echo $docId; ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
<?php else: ?>
  <tr>
    <td colspan="6" class="text-center text-secondary py-4">No documents found.</td>
  </tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div></div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>