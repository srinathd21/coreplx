<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, $tableName)
    {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, $tableName, $columnName)
    {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip()
    {
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

if (!function_exists('stmt_dynamic_bind_execute')) {
    function stmt_dynamic_bind_execute(mysqli_stmt $stmt, string $types, array $values): bool
    {
        if ($types === '' || empty($values)) {
            return mysqli_stmt_execute($stmt);
        }

        $bindParams = [$types];
        foreach ($values as $k => $v) {
            $bindParams[] = &$values[$k];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        return mysqli_stmt_execute($stmt);
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

$documentTypes = [];
$docTypeSql = "SELECT id, type_name, prefix FROM document_types WHERE status='active' ORDER BY type_name ASC";
$docTypeRes = mysqli_query($conn, $docTypeSql);
if ($docTypeRes) {
    while ($row = mysqli_fetch_assoc($docTypeRes)) {
        $documentTypes[] = $row;
    }
}

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
            if ($name === '') {
                $name = 'User #' . (int)$row['id'];
            }
            $users[] = ['id' => (int)$row['id'], 'name' => $name];
        }
        if (!empty($users)) {
            break;
        }
    }
}

$approvers = [];
foreach ($users as $u) {
    if ($u['id'] !== $userId) {
        $approvers[] = $u;
    }
}

$documentsRaw = [];
$listSql = "
    SELECT
        d.id,
        d.document_number,
        d.title,
        d.topic,
        d.current_status,
        d.owner_user_id,
        d.current_version_id,
        d.document_type_id,
        dt.type_name,
        dv.version_label,
        dv.effective_date,
        dv.review_date
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE d.current_status IN ('effective','approved','published','draft','pending_approval')
    ORDER BY dt.type_name ASC, d.id DESC
";
$listRes = mysqli_query($conn, $listSql);
if ($listRes) {
    while ($row = mysqli_fetch_assoc($listRes)) {
        $documentsRaw[] = $row;
    }
}

$documentsByType = [];
foreach ($documentsRaw as $doc) {
    $typeName = (string)($doc['type_name'] ?? 'Other');
    if (!isset($documentsByType[$typeName])) {
        $documentsByType[$typeName] = [];
    }

    $ownerName = 'Owner #' . (int)$doc['owner_user_id'];
    foreach ($users as $u) {
        if ($u['id'] === (int)$doc['owner_user_id']) {
            $ownerName = $u['name'];
            break;
        }
    }

    $documentsByType[$typeName][] = [
        'id' => (int)$doc['id'],
        'doc_id' => (string)($doc['document_number'] ?? ''),
        'topic' => (string)($doc['title'] ?: $doc['topic']),
        'number' => (string)($doc['document_number'] ?? ''),
        'version' => (string)($doc['version_label'] ?: '01'),
        'owner' => $ownerName,
        'effectiveDate' => (string)($doc['effective_date'] ?? ''),
        'status' => ucfirst((string)($doc['current_status'] ?: 'effective'))
    ];
}

$replacementDocuments = [];
foreach ($documentsRaw as $row) {
    $replacementDocuments[] = [
        'id' => (int)$row['id'],
        'document_number' => (string)($row['document_number'] ?? ''),
        'title' => (string)($row['title'] ?: $row['topic'] ?: 'Untitled'),
    ];
}

$selectedDocumentId = (int)($_GET['id'] ?? $_POST['document_id'] ?? 0);
$selectedDocument = null;
$form = [
    'document_id' => '',
    'document_number' => '',
    'current_status' => 'Effective',
    'owner_name' => '',
    'requested_by' => $displayName,
    'retirement_reason' => '',
    'replacement_document_id' => '',
    'approver_user_id' => '',
    'document_type_name' => ''
];

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
            d.document_type_id,
            dt.type_name,
            dv.version_label,
            dv.effective_date
        FROM documents d
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
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
        $form['current_status'] = ucfirst((string)($selectedDocument['current_status'] ?: 'effective'));
        $form['owner_name'] = $ownerName;
        $form['document_type_name'] = (string)($selectedDocument['type_name'] ?? '');
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
    $form['document_type_name'] = trim((string)($_POST['document_type_name'] ?? ''));

    $approverUserId = (int)$form['approver_user_id'];
    $replacementDocumentId = $form['replacement_document_id'] !== '' ? (int)$form['replacement_document_id'] : null;

    if ($selectedDocumentId <= 0) {
        $errors[] = 'Please select a document.';
    }
    if ($form['retirement_reason'] === '' || mb_strlen($form['retirement_reason']) < 20) {
        $errors[] = 'Retirement reason must be at least 20 characters.';
    }
    if ($approverUserId <= 0) {
        $errors[] = 'Approver is required.';
    }
    if ($approverUserId === $userId) {
        $errors[] = 'Requested by and approver cannot be the same user.';
    }
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

            $updateSql = "UPDATE documents SET current_status = ?, remarks = ?, approver = ?, updated_at = NOW() WHERE id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            if (!$updateStmt) {
                throw new RuntimeException('Failed to prepare document update.');
            }

            $approverText = (string)$approverUserId;
            mysqli_stmt_bind_param(
                $updateStmt,
                "sssi",
                $newStatus,
                $form['retirement_reason'],
                $approverText,
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
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                        if (!stmt_dynamic_bind_execute($stmt, $types, $bind)) {
                            throw new RuntimeException('Failed to insert document approval: ' . mysqli_stmt_error($stmt));
                        }
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
                        if (!stmt_dynamic_bind_execute($stmt, $types, $bind)) {
                            throw new RuntimeException('Failed to insert approver comment: ' . mysqli_stmt_error($stmt));
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
            }

            if (tableExists($conn, 'notifications')) {
                $notifColumns = [];
                $notifPlaceholders = [];
                $notifTypes = '';
                $notifValues = [];

                $notifMap = [
                    'user_id' => $approverUserId,
                    'notification_type' => 'retirement_request',
                    'reference_type' => 'document',
                    'reference_id' => $selectedDocumentId,
                    'title' => 'Document Retirement Request',
                    'message' => 'A retirement request has been raised for document "' . $form['document_number'] . '".',
                ];

                foreach ($notifMap as $col => $val) {
                    if (columnExists($conn, 'notifications', $col)) {
                        $notifColumns[] = "`{$col}`";
                        $notifPlaceholders[] = "?";
                        $notifTypes .= is_int($val) ? 'i' : 's';
                        $notifValues[] = $val;
                    }
                }

                if (!empty($notifColumns)) {
                    $notifSql = "INSERT INTO notifications (" . implode(', ', $notifColumns) . ") VALUES (" . implode(', ', $notifPlaceholders) . ")";
                    $notifStmt = mysqli_prepare($conn, $notifSql);
                    if ($notifStmt) {
                        stmt_dynamic_bind_execute($notifStmt, $notifTypes, $notifValues);
                        mysqli_stmt_close($notifStmt);
                    }
                }
            }

            mysqli_commit($conn);
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

$documentsByTypeJson = json_encode($documentsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$replacementDocumentsJson = json_encode($replacementDocuments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Retire Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .field-locked {
      background: #f5f7fa !important;
      color: #6b7280 !important;
      cursor: not-allowed;
    }
    select:disabled {
      background: #f5f7fa;
      color: #aaa;
      cursor: not-allowed;
    }
    #docInfoPanel {
      background: #f8fafc;
      border: 1px solid #dde3ec;
      border-radius: 8px;
      padding: 12px 16px;
      font-size: 13px;
    }
    #docInfoPanel .di-id   { font-weight: 700; color: #2563eb; font-size: 14px; }
    #docInfoPanel .di-meta { color: #6b7280; margin-top: 2px; }
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
            <li><a class="dropdown-item active" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item" href="repository.php">Repository</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-trail.php">Audit Trail</a></li>
            <li><a class="dropdown-item" href="document-assignment.php">Document Assignment</a></li>
            <li><a class="dropdown-item" href="user-management.php">User Management</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($roleName ?: 'QA Admin'); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small"><?php echo e($displayName ?: 'Profile'); ?></span>
      </div>
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

  <form method="post" id="retireForm">
    <input type="hidden" name="document_id" id="document_id" value="<?php echo e($form['document_id']); ?>">
    <input type="hidden" name="document_number" id="document_number" value="<?php echo e($form['document_number']); ?>">
    <input type="hidden" name="current_status" id="current_status" value="<?php echo e($form['current_status']); ?>">
    <input type="hidden" name="owner_name" id="owner_name" value="<?php echo e($form['owner_name']); ?>">
    <input type="hidden" name="requested_by" id="requested_by" value="<?php echo e($form['requested_by']); ?>">
    <input type="hidden" name="document_type_name" id="document_type_name" value="<?php echo e($form['document_type_name']); ?>">

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Retire Request</h2>
            <p class="card-subtitle mb-3">Retirement requires approval and must not be immediate.</p>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Step 1 — Document Type <span class="text-danger">*</span></label>
                <select class="form-select" id="docTypeSelect" onchange="onTypeChange(this.value)">
                  <option value="">-- Select Type --</option>
                  <?php foreach ($documentTypes as $type): ?>
                    <option value="<?php echo e($type['type_name']); ?>" <?php echo ($form['document_type_name'] === $type['type_name']) ? 'selected' : ''; ?>>
                      <?php echo e($type['type_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Only Effective documents will appear below.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Step 2 — Select Document <span class="text-danger">*</span></label>
                <select class="form-select" id="docSelect" disabled onchange="onDocSelect(this.value)">
                  <option value="">-- Select Type first --</option>
                </select>
                <div class="form-text" id="docSelectHint">Choose a document type above to populate this list.</div>
              </div>

              <div class="col-12 <?php echo $selectedDocument ? '' : 'd-none'; ?>" id="docInfoWrap">
                <div id="docInfoPanel">
                  <div class="di-id" id="diId"><?php echo e($form['document_number'] ?: '—'); ?></div>
                  <div class="di-meta" id="diMeta">—</div>
                </div>
              </div>

              <div class="col-md-6 <?php echo $selectedDocument ? '' : 'd-none'; ?> doc-field">
                <label class="form-label">Current Status</label>
                <input class="form-control field-locked" id="fStatus" readonly value="<?php echo e($form['current_status'] ?: 'Effective'); ?>">
              </div>

              <div class="col-md-6 <?php echo $selectedDocument ? '' : 'd-none'; ?> doc-field">
                <label class="form-label">Owner</label>
                <input class="form-control field-locked" id="fOwner" readonly value="<?php echo e($form['owner_name']); ?>">
              </div>

              <div class="col-md-6 <?php echo $selectedDocument ? '' : 'd-none'; ?> doc-field">
                <label class="form-label">Requested By</label>
                <input class="form-control field-locked" id="fRequestedBy" readonly value="<?php echo e($form['requested_by']); ?>">
              </div>

              <div class="col-md-6 <?php echo $selectedDocument ? '' : 'd-none'; ?> doc-field">
                <label class="form-label">Retirement Date <span class="text-danger">*</span></label>
                <input class="form-control field-locked" id="fRetireDate" readonly value="<?php echo e(date('Y-m-d')); ?>">
                <div class="form-text">Auto-set to today — read only.</div>
              </div>

              <div class="col-12 <?php echo $selectedDocument ? '' : 'd-none'; ?> doc-field">
                <label class="form-label">Retirement Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" id="fReason" name="retirement_reason" placeholder="Minimum 20 characters required — describe why this document is being retired" rows="4"><?php echo e($form['retirement_reason']); ?></textarea>
                <div class="form-text d-flex justify-content-between">
                  <span>Retirement reason is mandatory and fully auditable.</span>
                  <span id="charCount" class="text-secondary">0 / 20 min</span>
                </div>
              </div>

              <div class="col-md-6 <?php echo $selectedDocument ? '' : 'd-none'; ?> doc-field">
                <label class="form-label">Replacement Document <span class="text-secondary fw-normal">(if applicable)</span></label>
                <select class="form-select" id="fReplacement" name="replacement_document_id">
                  <option value="">None — no replacement</option>
                </select>
                <div class="form-text">Select a replacement document if this one is being superseded.</div>
              </div>

              <div class="col-md-6 <?php echo $selectedDocument ? '' : 'd-none'; ?> doc-field">
                <label class="form-label">Approver <span class="text-danger">*</span></label>
                <select class="form-select" id="fApprover" name="approver_user_id">
                  <option value="">-- Select Approver --</option>
                  <?php foreach ($approvers as $approver): ?>
                    <option value="<?php echo (int)$approver['id']; ?>" <?php echo ((string)$form['approver_user_id'] === (string)$approver['id']) ? 'selected' : ''; ?>>
                      <?php echo e($approver['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Creator cannot select themselves as approver.</div>
              </div>
            </div>

            <div class="d-flex gap-2 mt-4">
              <a class="btn btn-outline-secondary" href="retire-document.php">Cancel</a>
              <button type="submit" class="btn btn-danger" id="submitBtn" <?php echo $selectedDocument ? '' : 'disabled'; ?>>
                Submit Retirement Request
              </button>
            </div>

          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Control Notes</h2>
            <p class="card-subtitle mb-3">Retired records remain traceable.</p>
            <ul class="small text-secondary note-list mb-0">
              <li>Status moves to Pending Retirement until approved.</li>
              <li>Repository history still shows prior effective versions.</li>
              <li>Delete is never available for controlled records.</li>
              <li>Approval, denial, and comment actions are fully logged.</li>
              <li>Retirement date and approver are captured in the audit trail.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </form>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var EFFECTIVE_DOCS = <?php echo $documentsByTypeJson ?: '{}'; ?>;
var REPLACEMENT_DOCS = <?php echo $replacementDocumentsJson ?: '[]'; ?>;
var SELECTED_DOC_ID = "<?php echo e($form['document_id']); ?>";
var SELECTED_DOC_TYPE = "<?php echo e($form['document_type_name']); ?>";
var SELECTED_APPROVER = "<?php echo e($form['approver_user_id']); ?>";
var SELECTED_REPLACEMENT = "<?php echo e($form['replacement_document_id']); ?>";

function onTypeChange(type) {
  var docSel = document.getElementById('docSelect');
  var hint   = document.getElementById('docSelectHint');
  resetFields();

  if (!type) {
    docSel.disabled = true;
    docSel.innerHTML = '<option value="">-- Select Type first --</option>';
    hint.textContent = 'Choose a document type above to populate this list.';
    return;
  }

  var docs = EFFECTIVE_DOCS[type] || [];
  docSel.innerHTML = '<option value="">-- Select a ' + type + ' document --</option>';

  docs.forEach(function(doc) {
    var opt = document.createElement('option');
    opt.value = doc.id;
    opt.textContent = doc.doc_id + '  —  ' + doc.topic;
    if (String(doc.id) === String(SELECTED_DOC_ID)) {
      opt.selected = true;
    }
    docSel.appendChild(opt);
  });

  docSel.disabled = false;
  hint.textContent = docs.length + ' effective ' + type + ' document' + (docs.length !== 1 ? 's' : '') + ' available.';
}

function onDocSelect(docId) {
  resetFields();
  if (!docId) return;
  window.location.href = 'retire-document.php?id=' + encodeURIComponent(docId);
}

function resetFields() {
  if (!SELECTED_DOC_ID) {
    document.getElementById('docInfoWrap').classList.add('d-none');
    document.querySelectorAll('.doc-field').forEach(function(el) {
      el.classList.add('d-none');
    });
    document.getElementById('submitBtn').disabled = true;
  }
}

function populateSelectedInfo() {
  if (!SELECTED_DOC_ID || !SELECTED_DOC_TYPE) return;

  var docs = EFFECTIVE_DOCS[SELECTED_DOC_TYPE] || [];
  var doc  = docs.find(function(d) { return String(d.id) === String(SELECTED_DOC_ID); });
  if (!doc) return;

  document.getElementById('diId').textContent = doc.doc_id || '—';
  document.getElementById('diMeta').textContent =
    'Topic: ' + (doc.topic || '—') +
    '  ·  Version: ' + (doc.version || '—') +
    '  ·  Owner: ' + (doc.owner || '—') +
    '  ·  Effective: ' + formatDate(doc.effectiveDate);

  var repSel = document.getElementById('fReplacement');
  repSel.innerHTML = '<option value="">None — no replacement</option>';
  REPLACEMENT_DOCS.forEach(function(d) {
    if (String(d.id) !== String(SELECTED_DOC_ID)) {
      var opt = document.createElement('option');
      opt.value = d.id;
      opt.textContent = d.document_number + '  —  ' + d.title;
      if (String(d.id) === String(SELECTED_REPLACEMENT)) {
        opt.selected = true;
      }
      repSel.appendChild(opt);
    }
  });
}

function updateCharCount() {
  var txt = document.getElementById('fReason');
  var el = document.getElementById('charCount');
  if (!txt || !el) return;
  var len = txt.value.trim().length;
  if (len < 20) {
    el.textContent = len + ' / 20 min';
    el.className = 'text-danger';
  } else {
    el.textContent = len + ' chars ✓';
    el.className = 'text-success';
  }
}

document.addEventListener('DOMContentLoaded', function() {
  if (SELECTED_DOC_TYPE) {
    onTypeChange(SELECTED_DOC_TYPE);
  }
  populateSelectedInfo();
  updateCharCount();

  var reason = document.getElementById('fReason');
  if (reason) {
    reason.addEventListener('input', updateCharCount);
  }

  var form = document.getElementById('retireForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      var reasonText = document.getElementById('fReason') ? document.getElementById('fReason').value.trim() : '';
      var approver = document.getElementById('fApprover') ? document.getElementById('fApprover').value : '';
      if (reasonText.length < 20) {
        e.preventDefault();
        alert('Retirement Reason must be at least 20 characters.');
        document.getElementById('fReason').focus();
        return false;
      }
      if (!approver) {
        e.preventDefault();
        alert('Please select an Approver before submitting.');
        document.getElementById('fApprover').focus();
        return false;
      }
    });
  }
});

function formatDate(str) {
  if (!str) return '—';
  var d = new Date(str);
  if (isNaN(d.getTime())) return str;
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
</script>
</body>
</html>