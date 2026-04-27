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
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
        $ok = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $ok;
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $ok = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $ok;
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($value): string
    {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$value);
        return $ts ? date('d M Y h:i A', $ts) : e($value);
    }
}

if (!function_exists('formatDateShort')) {
    function formatDateShort($value): string
    {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$value);
        return $ts ? date('d M Y', $ts) : e($value);
    }
}

if (!function_exists('notificationBadgeClass')) {
    function notificationBadgeClass(string $type): string
    {
        $type = strtolower(trim($type));

        if (in_array($type, ['approve', 'retirement_approved'], true)) {
            return 'badge badge-soft-success';
        }
        if (in_array($type, ['reject', 'retirement_rejected'], true)) {
            return 'badge badge-soft-danger';
        }
        if (in_array($type, ['return', 'retirement_request', 'overdue_review', 'ack_reminder'], true)) {
            return 'badge badge-soft-warning';
        }
        if (in_array($type, ['submit', 'ack_assignment', 'synthetic_pending_approval'], true)) {
            return 'badge badge-soft-info';
        }

        return 'badge badge-soft-secondary';
    }
}

if (!function_exists('notificationTypeLabel')) {
    function notificationTypeLabel(string $type): string
    {
        $type = strtolower(trim($type));
        $map = [
            'submit'                     => 'Submitted',
            'approve'                    => 'Approved',
            'reject'                     => 'Rejected',
            'return'                     => 'Returned',
            'retirement_request'         => 'Retirement Request',
            'retirement_approved'        => 'Retirement Approved',
            'retirement_rejected'        => 'Retirement Rejected',
            'overdue_review'             => 'Overdue Review',
            'ack_assignment'             => 'Acknowledgement',
            'ack_reminder'               => 'Reminder',
            'system'                     => 'System',
            'synthetic_pending_approval' => 'Pending Approval',
        ];

        return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
    }
}

if (!function_exists('fetchAllPrepared')) {
    function fetchAllPrepared(mysqli $conn, string $sql, string $types = '', array $params = []): array
    {
        $rows = [];
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return $rows;
        }

        if ($types !== '' && !empty($params)) {
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
        return $rows;
    }
}

if (!function_exists('fetchOnePrepared')) {
    function fetchOnePrepared(mysqli $conn, string $sql, string $types = '', array $params = [])
    {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return null;
        }

        if ($types !== '' && !empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }

        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row;
    }
}

if (!tableExists($conn, 'notifications')) {
    die('Notifications table not found.');
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login-admin.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

$currentUser = fetchOnePrepared($conn, "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
", 'i', [$userId]);

if (!$currentUser) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

$displayName = trim((string)($currentUser['first_name'] ?? '') . ' ' . (string)($currentUser['last_name'] ?? ''));
if ($displayName === '') {
    $displayName = (string)($currentUser['email'] ?? 'QA Admin');
}
$roleName = trim((string)($currentUser['role_name'] ?? 'QA Admin'));

$flashSuccess = '';
$flashError = '';

$hasWorkflowSteps   = tableExists($conn, 'workflow_steps');
$hasDocuments       = tableExists($conn, 'documents');
$hasDocVersions     = tableExists($conn, 'document_versions');
$hasDocTypes        = tableExists($conn, 'document_types');
$hasUsers           = tableExists($conn, 'users');
$hasDocumentsApprover = $hasDocuments && columnExists($conn, 'documents', 'approver');

/* -------------------- ACTIONS -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'mark_all_read') {
        $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flashSuccess = 'All stored notifications marked as read.';
        } else {
            $flashError = 'Unable to update notifications.';
        }
    }

    if ($action === 'mark_read') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);

        $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $notificationId, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flashSuccess = 'Notification marked as read.';
        } else {
            $flashError = 'Unable to update notification.';
        }
    }

    if ($action === 'mark_unread') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);

        $sql = "UPDATE notifications SET is_read = 0, read_at = NULL WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $notificationId, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $flashSuccess = 'Notification marked as unread.';
        } else {
            $flashError = 'Unable to update notification.';
        }
    }
}

/* -------------------- FILTERS -------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));

$availableTypes = [];
$typeRes = mysqli_query($conn, "SELECT DISTINCT notification_type FROM notifications ORDER BY notification_type ASC");
if ($typeRes) {
    while ($row = mysqli_fetch_assoc($typeRes)) {
        if (!empty($row['notification_type'])) {
            $availableTypes[] = $row['notification_type'];
        }
    }
    mysqli_free_result($typeRes);
}
if (!in_array('synthetic_pending_approval', $availableTypes, true)) {
    $availableTypes[] = 'synthetic_pending_approval';
}
sort($availableTypes);

/* -------------------- STORED NOTIFICATION COUNTS -------------------- */
$totalStoredCount = 0;
$unreadStoredCount = 0;

$row = fetchOnePrepared($conn, "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ?", 'i', [$userId]);
$totalStoredCount = (int)($row['cnt'] ?? 0);

$row = fetchOnePrepared($conn, "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0", 'i', [$userId]);
$unreadStoredCount = (int)($row['cnt'] ?? 0);

/* -------------------- STORED NOTIFICATIONS -------------------- */
$sql = "
    SELECT
        CONCAT('db_', n.id) AS row_key,
        n.id,
        n.notification_type,
        n.reference_type,
        n.reference_id,
        n.title,
        n.message,
        n.is_read,
        n.read_at,
        n.created_at,
        0 AS is_synthetic,
        '' AS source_type
    FROM notifications n
    WHERE n.user_id = ?
";
$params = [$userId];
$types = 'i';

if ($search !== '') {
    $sql .= " AND (n.title LIKE ? OR n.message LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($statusFilter === 'unread') {
    $sql .= " AND n.is_read = 0";
} elseif ($statusFilter === 'read') {
    $sql .= " AND n.is_read = 1";
}

if ($typeFilter !== '') {
    if ($typeFilter === 'synthetic_pending_approval') {
        $sql .= " AND 1 = 0";
    } else {
        $sql .= " AND n.notification_type = ?";
        $params[] = $typeFilter;
        $types .= 's';
    }
}

$sql .= " ORDER BY n.created_at DESC, n.id DESC";

$storedNotifications = fetchAllPrepared($conn, $sql, $types, $params);

/* -------------------- SYNTHETIC PENDING APPROVAL ALERTS -------------------- */
/*
   These are generated when a document is pending approval for the current user
   but no notification row exists. This compensates for your broken submit flow.
*/
$syntheticNotifications = [];
if ($hasDocuments && $hasDocVersions) {
    $workflowRows = [];
    if ($hasWorkflowSteps) {
        $workflowRows = fetchAllPrepared($conn, "
            SELECT
                d.id AS document_id,
                d.document_number,
                d.title,
                dt.type_name,
                dv.id AS document_version_id,
                dv.version_label,
                dv.submitted_at,
                submitter.first_name,
                submitter.last_name,
                submitter.email,
                'workflow' AS source_type
            FROM workflow_steps ws
            INNER JOIN document_versions dv ON dv.id = ws.document_version_id
            INNER JOIN documents d ON d.id = dv.document_id
            LEFT JOIN document_types dt ON dt.id = d.document_type_id
            LEFT JOIN users submitter ON submitter.id = dv.submitted_by
            WHERE ws.approver_user_id = ?
              AND ws.status = 'pending'
        ", 'i', [$userId]);
    }

    $fallbackRows = [];
    if ($hasDocumentsApprover) {
        $fallbackRows = fetchAllPrepared($conn, "
            SELECT
                d.id AS document_id,
                d.document_number,
                d.title,
                dt.type_name,
                dv.id AS document_version_id,
                dv.version_label,
                dv.submitted_at,
                submitter.first_name,
                submitter.last_name,
                submitter.email,
                'document_fallback' AS source_type
            FROM documents d
            INNER JOIN document_versions dv ON dv.id = d.current_version_id
            LEFT JOIN document_types dt ON dt.id = d.document_type_id
            LEFT JOIN users submitter ON submitter.id = dv.submitted_by
            WHERE d.current_status = 'pending_approval'
              AND dv.status = 'pending_approval'
              AND TRIM(COALESCE(d.approver, '')) = ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM workflow_steps ws
                  WHERE ws.document_version_id = d.current_version_id
                    AND ws.approver_user_id = ?
                    AND ws.status = 'pending'
              )
        ", 'si', [(string)$userId, $userId]);
    }

    $pendingRows = array_merge($workflowRows, $fallbackRows);

    $seenVersionIds = [];
    foreach ($pendingRows as $row) {
        $versionId = (int)($row['document_version_id'] ?? 0);
        if ($versionId <= 0 || isset($seenVersionIds[$versionId])) {
            continue;
        }
        $seenVersionIds[$versionId] = true;

        $submittedByName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        if ($submittedByName === '') {
            $submittedByName = (string)($row['email'] ?? 'Unknown User');
        }

        $createdAt = (string)($row['submitted_at'] ?? '');
        $documentNumber = (string)($row['document_number'] ?? '');
        $documentTitle = (string)($row['title'] ?? 'Untitled Document');
        $docType = (string)($row['type_name'] ?? 'Document');
        $versionLabel = (string)($row['version_label'] ?? '—');

        $title = 'Document Pending Your Approval';
        $message = $docType . ' "' . $documentTitle . '" (' . $documentNumber . ') version ' . $versionLabel .
            ' is waiting for your approval. Submitted by ' . $submittedByName . '.';

        $syntheticNotifications[] = [
            'row_key'            => 'syn_' . $versionId,
            'id'                 => 0,
            'notification_type'  => 'synthetic_pending_approval',
            'reference_type'     => 'document_version',
            'reference_id'       => $versionId,
            'title'              => $title,
            'message'            => $message,
            'is_read'            => 0,
            'read_at'            => null,
            'created_at'         => $createdAt,
            'is_synthetic'       => 1,
            'source_type'        => (string)($row['source_type'] ?? ''),
            'document_number'    => $documentNumber,
        ];
    }
}

/* -------------------- MERGE + FILTER -------------------- */
$notifications = array_merge($storedNotifications, $syntheticNotifications);

/* filter by type for synthetic rows */
if ($typeFilter !== '') {
    $notifications = array_values(array_filter($notifications, function ($row) use ($typeFilter) {
        return (string)($row['notification_type'] ?? '') === $typeFilter;
    }));
}

/* filter by search for synthetic rows too */
if ($search !== '') {
    $q = mb_strtolower($search);
    $notifications = array_values(array_filter($notifications, function ($row) use ($q) {
        $haystack = mb_strtolower(
            (string)($row['title'] ?? '') . ' ' .
            (string)($row['message'] ?? '') . ' ' .
            (string)($row['reference_type'] ?? '') . ' ' .
            (string)($row['document_number'] ?? '')
        );
        return strpos($haystack, $q) !== false;
    }));
}

/* filter by status for synthetic */
if ($statusFilter === 'unread') {
    $notifications = array_values(array_filter($notifications, function ($row) {
        return (int)($row['is_read'] ?? 0) === 0;
    }));
} elseif ($statusFilter === 'read') {
    $notifications = array_values(array_filter($notifications, function ($row) {
        return (int)($row['is_read'] ?? 0) === 1;
    }));
}

/* sort latest first */
usort($notifications, function ($a, $b) {
    $aTime = !empty($a['created_at']) ? strtotime((string)$a['created_at']) : 0;
    $bTime = !empty($b['created_at']) ? strtotime((string)$b['created_at']) : 0;

    if ($aTime === $bTime) {
        return strcmp((string)($b['row_key'] ?? ''), (string)($a['row_key'] ?? ''));
    }
    return $bTime <=> $aTime;
});

$notifications = array_slice($notifications, 0, 200);

$totalCount = $totalStoredCount + count($syntheticNotifications);
$unreadCount = $unreadStoredCount + count($syntheticNotifications);
$readCount = max(0, $totalCount - $unreadCount);
$filteredCount = count($notifications);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Notifications</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .notification-item.unread {
      border-left: 4px solid #2563eb;
      background: #f8fbff;
    }
    .notification-item.read {
      border-left: 4px solid #e5e7eb;
      background: #fff;
    }
    .notification-item.synthetic {
      border-left: 4px solid #f59e0b;
      background: #fffaf0;
    }
    .notification-message {
      color: #6b7280;
      font-size: 13px;
      line-height: 1.5;
    }
    .notification-meta {
      color: #6b7280;
      font-size: 12px;
    }
    .notification-actions form {
      display: inline-block;
      margin: 0;
    }
    .notification-list-scroll {
      max-height: 720px;
      overflow-y: auto;
    }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item"><a class="nav-link" href="dashboard-admin.php">Dashboard</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item" href="repository.php">Repository</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-trail.php">Audit Trail</a></li>
            <li><a class="dropdown-item" href="document-assignment.php">Document Assignment</a></li>
            <li><a class="dropdown-item" href="user-management.php">User Management</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($roleName); ?></span>
        <a class="nav-link px-0 active" href="notifications.php">Notifications</a>
        <span class="navbar-text small"><?php echo e($displayName); ?></span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
  <div class="content-wrap px-4 py-4 mx-auto">

    <div class="mb-4">
      <h1 class="page-title mb-2">Notifications</h1>
      <p class="page-subtitle mb-0">Review workflow, acknowledgement, and system alerts for your account.</p>
    </div>

    <?php if ($flashSuccess !== ''): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo e($flashSuccess); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo e($flashError); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <?php if (!empty($syntheticNotifications)): ?>
      <div class="alert alert-warning border-0 shadow-sm">
        <strong>Notice:</strong> <?php echo count($syntheticNotifications); ?> approval alert<?php echo count($syntheticNotifications) !== 1 ? 's are' : ' is'; ?> shown from live document status because matching notification rows were not created in the database.
      </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
      <div class="col-sm-6 col-xl-4">
        <div class="card cp-card">
          <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
            <div>
              <div class="section-kicker mb-1">Total Notifications</div>
              <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$totalCount; ?></div>
            </div>
            <span style="font-size:1.8rem;">🔔</span>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-4">
        <div class="card cp-card">
          <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
            <div>
              <div class="section-kicker mb-1">Unread</div>
              <div class="stat-value" style="font-size:1.6rem;color:#2563eb;"><?php echo (int)$unreadCount; ?></div>
            </div>
            <span style="font-size:1.8rem;">📩</span>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-4">
        <div class="card cp-card">
          <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
            <div>
              <div class="section-kicker mb-1">Read</div>
              <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$readCount; ?></div>
            </div>
            <span style="font-size:1.8rem;">✅</span>
          </div>
        </div>
      </div>
    </div>

    <div class="card cp-card mb-3">
      <div class="card-body py-3">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-end" id="notificationFilterForm">
          <div>
            <label class="form-label mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="search" value="<?php echo e($search); ?>" placeholder="Search title or message..." style="max-width:240px;">
          </div>

          <div>
            <label class="form-label mb-1">Status</label>
            <select class="form-select form-select-sm auto-submit-filter" name="status" style="max-width:140px;">
              <option value="">All</option>
              <option value="unread" <?php echo $statusFilter === 'unread' ? 'selected' : ''; ?>>Unread</option>
              <option value="read" <?php echo $statusFilter === 'read' ? 'selected' : ''; ?>>Read</option>
            </select>
          </div>

          <div>
            <label class="form-label mb-1">Type</label>
            <select class="form-select form-select-sm auto-submit-filter" name="type" style="max-width:220px;">
              <option value="">All Types</option>
              <?php foreach ($availableTypes as $availableType): ?>
                <option value="<?php echo e($availableType); ?>" <?php echo $typeFilter === $availableType ? 'selected' : ''; ?>>
                  <?php echo e(notificationTypeLabel($availableType)); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="d-flex gap-2 ms-auto">
            <a class="btn btn-sm btn-outline-secondary" href="notifications.php">Reset</a>
            <button class="btn btn-sm btn-primary" type="submit">Search</button>
          </div>
        </form>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h2 class="page-title mb-0" style="font-size:1.2rem;">Notification List</h2>
        <p class="page-subtitle mb-0 small">Showing <?php echo (int)$filteredCount; ?> notification<?php echo $filteredCount !== 1 ? 's' : ''; ?>.</p>
      </div>
      <form method="post" class="m-0">
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-sm btn-outline-primary" <?php echo $unreadStoredCount <= 0 ? 'disabled' : ''; ?>>
          Mark All Stored as Read
        </button>
      </form>
    </div>

    <div class="card cp-card">
      <div class="card-body p-0">
        <div class="notification-list-scroll">
          <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $row): ?>
              <?php
                $isRead = (int)($row['is_read'] ?? 0) === 1;
                $isSynthetic = (int)($row['is_synthetic'] ?? 0) === 1;
                $typeLabel = notificationTypeLabel((string)($row['notification_type'] ?? 'system'));
                $typeClass = notificationBadgeClass((string)($row['notification_type'] ?? 'system'));
                $referenceType = trim((string)($row['reference_type'] ?? ''));
                $referenceId = (int)($row['reference_id'] ?? 0);

                $viewUrl = '#';
                if ($referenceType === 'document' && $referenceId > 0) {
                    $viewUrl = 'repository.php?view_id=' . $referenceId;
                } elseif ($referenceType === 'document_version' && $referenceId > 0) {
                    $viewUrl = 'audit-trail.php?tab=approval';
                } elseif ($referenceType === 'acknowledgement' && $referenceId > 0) {
                    $viewUrl = 'document-assignment.php';
                } elseif ($referenceType === 'retirement_request' && $referenceId > 0) {
                    $viewUrl = 'retire-document.php';
                }

                $itemClass = $isSynthetic ? 'synthetic unread' : ($isRead ? 'read' : 'unread');
              ?>
              <div class="notification-item <?php echo e($itemClass); ?> border-bottom p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                  <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                      <h3 class="card-title mb-0" style="font-size:1rem;">
                        <?php echo e($row['title'] ?: 'Notification'); ?>
                      </h3>

                      <span class="<?php echo e($typeClass); ?>"><?php echo e($typeLabel); ?></span>

                      <?php if ($isSynthetic): ?>
                        <span class="badge badge-soft-warning">Live Status</span>
                      <?php elseif (!$isRead): ?>
                        <span class="badge badge-soft-primary">Unread</span>
                      <?php endif; ?>
                    </div>

                    <p class="notification-message mb-2">
                      <?php echo nl2br(e($row['message'] ?: 'No message available.')); ?>
                    </p>

                    <div class="notification-meta d-flex flex-wrap gap-3">
                      <span><strong>Created:</strong> <?php echo e(formatDateTime($row['created_at'] ?? '')); ?></span>
                      <span><strong>Read At:</strong> <?php echo e(formatDateTime($row['read_at'] ?? '')); ?></span>
                      <span><strong>Reference:</strong> <?php echo e($referenceType !== '' ? ucwords(str_replace('_', ' ', $referenceType)) : '—'); ?></span>
                      <?php if ($isSynthetic): ?>
                        <span><strong>Source:</strong> <?php echo e(ucwords(str_replace('_', ' ', (string)($row['source_type'] ?? 'fallback')))); ?></span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="notification-actions d-flex gap-2 flex-wrap">
                    <?php if ($viewUrl !== '#'): ?>
                      <a href="<?php echo e($viewUrl); ?>" class="btn btn-sm btn-outline-primary" style="height:32px;padding:0 12px;font-size:12px;display:flex;align-items:center;">
                        View
                      </a>
                    <?php endif; ?>

                    <?php if (!$isSynthetic): ?>
                      <?php if (!$isRead): ?>
                        <form method="post">
                          <input type="hidden" name="action" value="mark_read">
                          <input type="hidden" name="notification_id" value="<?php echo (int)$row['id']; ?>">
                          <button type="submit" class="btn btn-sm btn-outline-success" style="height:32px;padding:0 12px;font-size:12px;">
                            Mark Read
                          </button>
                        </form>
                      <?php else: ?>
                        <form method="post">
                          <input type="hidden" name="action" value="mark_unread">
                          <input type="hidden" name="notification_id" value="<?php echo (int)$row['id']; ?>">
                          <button type="submit" class="btn btn-sm btn-outline-secondary" style="height:32px;padding:0 12px;font-size:12px;">
                            Mark Unread
                          </button>
                        </form>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-center text-secondary py-5">
              No notifications found.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.auto-submit-filter').forEach(function(el) {
  el.addEventListener('change', function() {
    document.getElementById('notificationFilterForm').submit();
  });
});
</script>
</body>
</html>