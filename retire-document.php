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

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4() {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip() {
        $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $parts = explode(',', (string)$_SERVER[$key]);
                return trim($parts[0]);
            }
        }
        return '';
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
        u.employee_code,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.current_role_id,
        u.department_id,
        u.status,
        r.role_code,
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

if (!$currentUser) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

$displayName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
if ($displayName === '') {
    $displayName = $_SESSION['admin_name'] ?? ($_SESSION['full_name'] ?? 'Admin');
}
$roleName = trim((string)($currentUser['role_name'] ?? ($_SESSION['role_name'] ?? 'QA Admin')));

$users = [];
$userQueries = [
    "SELECT id, CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS name FROM users WHERE status='active' ORDER BY first_name, last_name",
    "SELECT id, full_name AS name FROM users WHERE status='active' ORDER BY full_name",
    "SELECT id, username AS name FROM users WHERE status='active' ORDER BY username",
    "SELECT id, CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS name FROM users ORDER BY first_name, last_name",
    "SELECT id, full_name AS name FROM users ORDER BY full_name",
    "SELECT id, username AS name FROM users ORDER BY username"
];
foreach ($userQueries as $sql) {
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') $name = 'User #' . (int)$row['id'];
            $users[] = ['id' => (int)$row['id'], 'name' => $name];
        }
        if (!empty($users)) break;
    }
}

$approvers = [];
foreach ($users as $u) {
    if ($u['id'] !== $userId) {
        $approvers[] = $u;
    }
}

$filterId = trim($_GET['filter_id'] ?? '');
$selectedDocumentId = (int)($_GET['id'] ?? 0);

$documents = [];
$listSql = "
    SELECT
        d.id,
        d.document_number,
        d.title,
        d.topic,
        d.current_status,
        d.owner_user_id,
        d.current_version_id
    FROM documents d
    WHERE 1=1
";
$listParams = [];
$listTypes = '';

if ($filterId !== '') {
    $listSql .= " AND (d.document_number LIKE ? OR d.id = ?)";
    $listParams[] = '%' . $filterId . '%';
    $listParams[] = (int)$filterId;
    $listTypes .= 'si';
}

$listSql .= " ORDER BY d.id DESC LIMIT 100";

$listStmt = mysqli_prepare($conn, $listSql);
if ($listStmt) {
    if (!empty($listParams)) {
        mysqli_stmt_bind_param($listStmt, $listTypes, ...$listParams);
    }
    mysqli_stmt_execute($listStmt);
    $listRes = mysqli_stmt_get_result($listStmt);
    if ($listRes) {
        while ($row = mysqli_fetch_assoc($listRes)) {
            $documents[] = $row;
        }
    }
    mysqli_stmt_close($listStmt);
}

$replacementDocuments = [];
$repSql = "SELECT id, document_number, title FROM documents ORDER BY id DESC LIMIT 200";
$repRes = mysqli_query($conn, $repSql);
if ($repRes) {
    while ($row = mysqli_fetch_assoc($repRes)) {
        $replacementDocuments[] = $row;
    }
}

$form = [
    'document_id' => '',
    'document_number' => '',
    'current_status' => '',
    'owner_name' => '',
    'requested_by' => $displayName,
    'retirement_reason' => '',
    'replacement_document_id' => '',
    'approver_user_id' => ''
];

$selectedDocument = null;
$errors = [];
$successMessage = '';

if ($selectedDocumentId > 0) {
    $docSql = "
        SELECT
            d.id,
            d.document_number,
            d.current_status,
            d.owner_user_id,
            d.title,
            d.topic,
            d.current_version_id,
            dv.version_label,
            dv.title_snapshot,
            dv.topic_snapshot
        FROM documents d
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE d.id = ?
        LIMIT 1
    ";
    $docStmt = mysqli_prepare($conn, $docSql);
    if ($docStmt) {
        mysqli_stmt_bind_param($docStmt, "i", $selectedDocumentId);
        mysqli_stmt_execute($docStmt);
        $docRes = mysqli_stmt_get_result($docStmt);
        $selectedDocument = ($docRes && mysqli_num_rows($docRes) > 0) ? mysqli_fetch_assoc($docRes) : null;
        mysqli_stmt_close($docStmt);
    }

    if ($selectedDocument) {
        $ownerName = 'Owner #' . (int)$selectedDocument['owner_user_id'];
        foreach ($users as $u) {
            if ($u['id'] === (int)$selectedDocument['owner_user_id']) {
                $ownerName = $u['name'];
                break;
            }
        }

        $form['document_id'] = (string)$selectedDocument['id'];
        $form['document_number'] = (string)$selectedDocument['document_number'];
        $form['current_status'] = (string)($selectedDocument['current_status'] ?: 'Effective');
        $form['owner_name'] = $ownerName;
    } else {
        $errors[] = 'Selected document not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedDocumentId = (int)($_POST['document_id'] ?? 0);

    $form['document_id'] = (string)$selectedDocumentId;
    $form['document_number'] = trim((string)($_POST['document_number'] ?? ''));
    $form['current_status'] = trim((string)($_POST['current_status'] ?? ''));
    $form['owner_name'] = trim((string)($_POST['owner_name'] ?? ''));
    $form['requested_by'] = trim((string)($_POST['requested_by'] ?? $displayName));
    $form['retirement_reason'] = trim((string)($_POST['retirement_reason'] ?? ''));
    $form['replacement_document_id'] = trim((string)($_POST['replacement_document_id'] ?? ''));
    $form['approver_user_id'] = trim((string)($_POST['approver_user_id'] ?? ''));

    $approverUserId = (int)$form['approver_user_id'];
    $replacementDocumentId = $form['replacement_document_id'] !== '' ? (int)$form['replacement_document_id'] : null;

    if ($selectedDocumentId <= 0) $errors[] = 'Please select a document.';
    if ($form['retirement_reason'] === '' || mb_strlen($form['retirement_reason']) < 20) {
        $errors[] = 'Retirement reason must be at least 20 characters.';
    }
    if ($approverUserId <= 0) $errors[] = 'Approver is required.';
    if ($approverUserId === $userId) $errors[] = 'Requested by and approver cannot be the same user.';
    if ($replacementDocumentId !== null && $replacementDocumentId === $selectedDocumentId) {
        $errors[] = 'Replacement document cannot be the same as the selected document.';
    }

    if (!$errors) {
        mysqli_begin_transaction($conn);

        try {
            $docCheckSql = "SELECT * FROM documents WHERE id = ? LIMIT 1";
            $docCheckStmt = mysqli_prepare($conn, $docCheckSql);
            if (!$docCheckStmt) {
                throw new RuntimeException('Failed to load selected document.');
            }
            mysqli_stmt_bind_param($docCheckStmt, "i", $selectedDocumentId);
            mysqli_stmt_execute($docCheckStmt);
            $docCheckRes = mysqli_stmt_get_result($docCheckStmt);
            $selectedDocument = ($docCheckRes && mysqli_num_rows($docCheckRes) > 0) ? mysqli_fetch_assoc($docCheckRes) : null;
            mysqli_stmt_close($docCheckStmt);

            if (!$selectedDocument) {
                throw new RuntimeException('Selected document not found.');
            }

            $newStatus = 'pending_retirement';

            $updateSql = "UPDATE documents SET current_status = ?, remarks = ? WHERE id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            if (!$updateStmt) {
                throw new RuntimeException('Failed to prepare document update.');
            }

            mysqli_stmt_bind_param(
                $updateStmt,
                "ssi",
                $newStatus,
                $form['retirement_reason'],
                $selectedDocumentId
            );

            if (!mysqli_stmt_execute($updateStmt)) {
                throw new RuntimeException('Failed to update document status: ' . mysqli_stmt_error($updateStmt));
            }
            mysqli_stmt_close($updateStmt);

            if (tableExists($conn, 'audit_logs')) {
                $auditPayload = json_encode([
                    'document_id' => $selectedDocumentId,
                    'document_number' => $form['document_number'],
                    'requested_by' => $userId,
                    'approver_user_id' => $approverUserId,
                    'replacement_document_id' => $replacementDocumentId,
                    'retirement_reason' => $form['retirement_reason'],
                    'new_status' => $newStatus
                ], JSON_UNESCAPED_UNICODE);

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
                if (!$auditStmt) {
                    throw new RuntimeException('Failed to prepare audit log insert.');
                }

                $eventId = generate_uuid_v4();
                $entityType = 'document';
                $action = 'retirement_request';
                $oldValue = json_encode([
                    'status' => $form['current_status']
                ], JSON_UNESCAPED_UNICODE);
                $remarks = 'Document retirement requested';
                $ipAddress = get_client_ip();
                $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

                mysqli_stmt_bind_param(
                    $auditStmt,
                    "ssisssisss",
                    $eventId,
                    $entityType,
                    $selectedDocumentId,
                    $action,
                    $oldValue,
                    $auditPayload,
                    $userId,
                    $remarks,
                    $ipAddress,
                    $userAgent
                );

                if (!mysqli_stmt_execute($auditStmt)) {
                    throw new RuntimeException('Failed to write audit log: ' . mysqli_stmt_error($auditStmt));
                }
                mysqli_stmt_close($auditStmt);
            }

            if (tableExists($conn, 'document_approvals')) {
                $cols = [];
                $vals = [];
                $types = '';
                $bind = [];

                $map = [
                    'document_id' => $selectedDocumentId,
                    'document_version_id' => (int)($selectedDocument['current_version_id'] ?? 0),
                    'approver_id' => $approverUserId,
                    'approved_by' => $approverUserId,
                    'created_by' => $userId,
                    'user_id' => $userId,
                    'status' => 'Pending Retirement',
                    'meaning' => 'Pending Retirement',
                    'reason' => $form['retirement_reason'],
                    'comments' => $form['retirement_reason'],
                    'created_at' => date('Y-m-d H:i:s')
                ];

                foreach ($map as $col => $val) {
                    if (columnExists($conn, 'document_approvals', $col)) {
                        $cols[] = "`{$col}`";
                        $vals[] = "?";
                        $types .= is_int($val) ? 'i' : 's';
                        $bind[] = $val;
                    }
                }

                if (!empty($cols)) {
                    $sql = "INSERT INTO document_approvals (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $types, ...$bind);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
            }

            if (tableExists($conn, 'approver_comments')) {
                $cols = [];
                $vals = [];
                $types = '';
                $bind = [];

                $map = [
                    'document_id' => $selectedDocumentId,
                    'document_version_id' => (int)($selectedDocument['current_version_id'] ?? 0),
                    'user_id' => $userId,
                    'commented_by' => $userId,
                    'action_name' => 'Retirement Requested',
                    'comment_text' => $form['retirement_reason'],
                    'comment' => $form['retirement_reason'],
                    'comments' => $form['retirement_reason'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'commented_at' => date('Y-m-d H:i:s')
                ];

                foreach ($map as $col => $val) {
                    if (columnExists($conn, 'approver_comments', $col)) {
                        $cols[] = "`{$col}`";
                        $vals[] = "?";
                        $types .= is_int($val) ? 'i' : 's';
                        $bind[] = $val;
                    }
                }

                if (!empty($cols)) {
                    $sql = "INSERT INTO approver_comments (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $types, ...$bind);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
            }

            if (tableExists($conn, 'notifications')) {
                $notifSql = "
                    INSERT INTO notifications (
                        user_id,
                        notification_type,
                        reference_type,
                        reference_id,
                        title,
                        message
                    ) VALUES (?, 'retirement_request', 'document', ?, ?, ?)
                ";
                $notifStmt = mysqli_prepare($conn, $notifSql);
                if ($notifStmt) {
                    $notifTitle = 'Document Retirement Request';
                    $notifMessage = 'A retirement request has been raised for document "' . $form['document_number'] . '".';
                    mysqli_stmt_bind_param(
                        $notifStmt,
                        "iiss",
                        $approverUserId,
                        $selectedDocumentId,
                        $notifTitle,
                        $notifMessage
                    );
                    mysqli_stmt_execute($notifStmt);
                    mysqli_stmt_close($notifStmt);
                }
            }

            mysqli_commit($conn);
            $successMessage = 'Retirement request submitted successfully.';
            header('Location: retire-document.php?id=' . $selectedDocumentId . '&success=1');
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = $e->getMessage();
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $successMessage = 'Retirement request submitted successfully.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Retire Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .doc-list-table td,
    .doc-list-table th {
      vertical-align: middle;
    }
    .readonly {
      background: #f8fafc;
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

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="create-document.php">Create Document</a></li><li><a class="dropdown-item" href="update-document.php">Update Document</a></li><li><a class="dropdown-item active" href="retire-document.php">Retire Document</a></li><li><a class="dropdown-item" href="repository.php">Repository</a></li></ul></li>

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Workflow</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="document-types.php">Document Types</a></li><li><a class="dropdown-item" href="document-id.php">Document ID</a></li><li><a class="dropdown-item" href="content-editor.php">Content Editor</a></li><li><a class="dropdown-item" href="form-builder.php">Form Builder</a></li><li><a class="dropdown-item" href="form-type-name.php">Form Type &amp; Name</a></li><li><a class="dropdown-item" href="approver-selection.php">Approver Selection</a></li><li><a class="dropdown-item" href="submit-review.php">Submit for Review</a></li><li><a class="dropdown-item" href="electronic-signature.php">Electronic Signature</a></li><li><a class="dropdown-item" href="approver-comments.php">Approver Comments</a></li><li><a class="dropdown-item" href="notifications.php">Notifications</a></li></ul></li>

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="audit-creation.php">Audit - Creation</a></li><li><a class="dropdown-item" href="audit-approval.php">Audit - Approval</a></li><li><a class="dropdown-item" href="audit-comments.php">Audit - Comments</a></li><li><a class="dropdown-item" href="qa-admin.php">QA Admin</a></li><li><a class="dropdown-item" href="employee-role.php">Employee Role</a></li><li><a class="dropdown-item" href="super-admin.php">Super Admin</a></li><li><a class="dropdown-item" href="user-management.php">User Management</a></li><li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li></ul></li>

        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3"><span class="navbar-text small"><?php echo e($roleName ?: 'QA Admin'); ?></span><a class="nav-link px-0" href="notifications.php">Notifications</a><span class="navbar-text small"><?php echo e($displayName ?: 'Profile'); ?></span></div>
    </div>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">
<div class="mb-4">
<h1 class="page-title mb-2">Retire Controlled Document</h1>
<p class="page-subtitle mb-0">Submit a controlled document for retirement with reason, review, and approval.</p>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Please fix the following:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($errors as $err): ?>
        <li><?php echo e($err); ?></li>
      <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($successMessage !== ''): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo e($successMessage); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<div class="card cp-card mb-3">
  <div class="card-body">
    <h2 class="card-title mb-1">Select Document</h2>
    <p class="card-subtitle mb-3">Filter and choose a document to submit for retirement.</p>

    <form method="get" class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Filter by ID / Document Number</label>
        <input type="text" name="filter_id" class="form-control" value="<?php echo e($filterId); ?>" placeholder="Enter ID or document number">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <a href="retire-document.php" class="btn btn-outline-secondary w-100">Reset</a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table align-middle doc-list-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Document Number</th>
            <th>Title</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($documents)): ?>
            <?php foreach ($documents as $doc): ?>
              <tr>
                <td><?php echo (int)$doc['id']; ?></td>
                <td><?php echo e($doc['document_number'] ?: '-'); ?></td>
                <td><?php echo e($doc['title'] ?: ($doc['topic'] ?: '-')); ?></td>
                <td><?php echo e($doc['current_status'] ?: '-'); ?></td>
                <td>
                  <a href="retire-document.php?id=<?php echo (int)$doc['id']; ?>" class="btn btn-sm btn-outline-primary">Select</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" class="text-center text-secondary py-4">No documents found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($selectedDocument): ?>
<form method="post">
  <input type="hidden" name="document_id" value="<?php echo (int)$selectedDocumentId; ?>">
  <input type="hidden" name="document_number" value="<?php echo e($form['document_number']); ?>">
  <input type="hidden" name="current_status" value="<?php echo e($form['current_status']); ?>">
  <input type="hidden" name="owner_name" value="<?php echo e($form['owner_name']); ?>">
  <input type="hidden" name="requested_by" value="<?php echo e($form['requested_by']); ?>">

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card cp-card"><div class="card-body">
        <h2 class="card-title mb-1">Retire Request</h2>
        <p class="card-subtitle mb-3">Retirement requires approval and must not be immediate.</p>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Document ID</label><input class="form-control readonly" readonly value="<?php echo e($form['document_number']); ?>"></div>
          <div class="col-md-6"><label class="form-label">Current Status</label><input class="form-control readonly" readonly value="<?php echo e($form['current_status']); ?>"></div>
          <div class="col-md-6"><label class="form-label">Owner</label><input class="form-control readonly" readonly value="<?php echo e($form['owner_name']); ?>"></div>
          <div class="col-md-6"><label class="form-label">Requested By</label><input class="form-control readonly" readonly value="<?php echo e($form['requested_by']); ?>"></div>

          <div class="col-12">
            <label class="form-label">Retirement Reason</label>
            <textarea name="retirement_reason" class="form-control" placeholder="Minimum 20 characters required" rows="4"><?php echo e($form['retirement_reason']); ?></textarea>
            <div class="form-text">Retirement reason is mandatory and fully auditable.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Replacement Document</label>
            <select name="replacement_document_id" class="form-select">
              <option value="">Select replacement if applicable</option>
              <?php foreach ($replacementDocuments as $rep): ?>
                <?php if ((int)$rep['id'] !== (int)$selectedDocumentId): ?>
                  <option value="<?php echo (int)$rep['id']; ?>" <?php echo ((string)$form['replacement_document_id'] === (string)$rep['id']) ? 'selected' : ''; ?>>
                    <?php echo e($rep['document_number'] . ' - ' . ($rep['title'] ?: 'Untitled')); ?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Approver</label>
            <select name="approver_user_id" class="form-select">
              <option value="">Select Approver</option>
              <?php foreach ($approvers as $approver): ?>
                <option value="<?php echo (int)$approver['id']; ?>" <?php echo ((string)$form['approver_user_id'] === (string)$approver['id']) ? 'selected' : ''; ?>>
                  <?php echo e($approver['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="d-flex gap-2 mt-4">
          <a href="retire-document.php" class="btn btn-outline-secondary">Cancel</a>
          <button type="submit" class="btn btn-danger">Submit Retirement Request</button>
        </div>
      </div></div>
    </div>

    <div class="col-lg-4">
      <div class="card cp-card"><div class="card-body">
        <h2 class="card-title mb-1">Control Notes</h2>
        <p class="card-subtitle mb-3">Retired records remain traceable.</p>
        <ul class="small text-secondary note-list mb-0">
          <li>Status should move to Pending Retirement until approved.</li>
          <li>Repository history must still show prior effective versions.</li>
          <li>Delete should never be available for controlled records.</li>
          <li>Approval, denial, and comment actions must be fully logged.</li>
        </ul>
      </div></div>
    </div>
  </div>
</form>
<?php endif; ?>

</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>