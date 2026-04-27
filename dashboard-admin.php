<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        $ok = ($res && mysqli_num_rows($res) > 0);
        if ($res) mysqli_free_result($res);
        return $ok;
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        $ok = ($res && mysqli_num_rows($res) > 0);
        if ($res) mysqli_free_result($res);
        return $ok;
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
$currentRoleCode = (string)($_SESSION['role_code'] ?? '');
$currentRoleName = (string)($_SESSION['role_name'] ?? 'QA Admin');
$currentDisplayName = (string)($_SESSION['full_name'] ?? $_SESSION['admin_name'] ?? 'Profile');

if ($currentUserId <= 0) {
    header('Location: login-admin.php');
    exit;
}

if (!in_array($currentRoleCode, ['qa_admin', 'super_admin'], true)) {
    die('Access denied.');
}

function fetch_one(mysqli $conn, string $sql, string $types = '', array $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;

    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row;
}

function fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = [];
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return $rows;

    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function count_query(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $row = fetch_one($conn, $sql, $types, $params);
    return (int)($row['cnt'] ?? 0);
}

function status_badge(string $status): string
{
    $status = strtolower(trim($status));

    if (in_array($status, ['effective', 'approved', 'published'], true)) {
        return '<span class="badge badge-soft-success">Effective</span>';
    }
    if (in_array($status, ['pending_approval', 'pending approval'], true)) {
        return '<span class="badge badge-soft-warning">Pending Approval</span>';
    }
    if (in_array($status, ['draft'], true)) {
        return '<span class="badge badge-soft-secondary">Draft</span>';
    }
    if (in_array($status, ['overdue'], true)) {
        return '<span class="badge badge-soft-danger">Overdue</span>';
    }
    if (in_array($status, ['in_progress', 'in progress'], true)) {
        return '<span class="badge badge-soft-info">In Progress</span>';
    }

    return '<span class="badge badge-soft-info">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function short_name(array $row): string
{
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($name !== '') return $name;

    if (!empty($row['full_name']) && trim((string)$row['full_name']) !== '' && (string)$row['full_name'] !== '0') {
        return trim((string)$row['full_name']);
    }

    return (string)($row['email'] ?? 'User');
}

$hasDocumentsCreatedBy = tableExists($conn, 'documents') && columnExists($conn, 'documents', 'created_by');
$hasDocumentsUpdatedBy = tableExists($conn, 'documents') && columnExists($conn, 'documents', 'updated_by');
$hasDocumentsUpdatedAt = tableExists($conn, 'documents') && columnExists($conn, 'documents', 'updated_at');
$hasDocumentsCreatedAt = tableExists($conn, 'documents') && columnExists($conn, 'documents', 'created_at');
$hasWorkflowSteps = tableExists($conn, 'workflow_steps');
$hasNotifications = tableExists($conn, 'notifications');
$hasAuditLogs = tableExists($conn, 'audit_logs');
$hasAckAssignments = tableExists($conn, 'acknowledgement_assignments');
$hasDocVersions = tableExists($conn, 'document_versions');

$userRow = fetch_one($conn, "
    SELECT u.id, u.first_name, u.last_name, u.full_name, u.email, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
", 'i', [$currentUserId]);

$welcomeName = $userRow ? short_name($userRow) : $currentDisplayName;
$avatarLetter = strtoupper(substr($welcomeName, 0, 1));

$documentsOwnedSql = "
    SELECT COUNT(*) AS cnt
    FROM documents
    WHERE LOWER(COALESCE(current_status, '')) <> 'deleted'
      AND owner_user_id = ?
";
$documentsOwnedTypes = 'i';
$documentsOwnedParams = [$currentUserId];

if ($hasDocumentsCreatedBy) {
    $documentsOwnedSql = "
        SELECT COUNT(*) AS cnt
        FROM documents
        WHERE LOWER(COALESCE(current_status, '')) <> 'deleted'
          AND (
                owner_user_id = ?
             OR created_by = ?
          )
    ";
    $documentsOwnedTypes = 'ii';
    $documentsOwnedParams = [$currentUserId, $currentUserId];
}

$documentsOwned = count_query($conn, $documentsOwnedSql, $documentsOwnedTypes, $documentsOwnedParams);

$pendingApprovals = 0;
if ($hasWorkflowSteps && $hasDocVersions) {
    $pendingApprovals = count_query($conn, "
        SELECT COUNT(DISTINCT d.id) AS cnt
        FROM workflow_steps ws
        INNER JOIN document_versions dv ON dv.id = ws.document_version_id
        INNER JOIN documents d ON d.id = dv.document_id
        WHERE ws.approver_user_id = ?
          AND ws.status = 'pending'
          AND LOWER(COALESCE(d.current_status, '')) = 'pending_approval'
    ", 'i', [$currentUserId]);
}

$overdueReviews = 0;
if ($hasDocVersions) {
    $overdueReviews = count_query($conn, "
        SELECT COUNT(DISTINCT d.id) AS cnt
        FROM documents d
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE dv.review_date IS NOT NULL
          AND dv.review_date <> '0000-00-00'
          AND dv.review_date < CURDATE()
          AND LOWER(COALESCE(d.current_status, '')) IN ('effective', 'approved', 'published')
    ");
}

$unreadAlerts = 0;
if ($hasNotifications) {
    $unreadAlerts = count_query($conn, "
        SELECT COUNT(*) AS cnt
        FROM notifications
        WHERE user_id = ?
          AND is_read = 0
    ", 'i', [$currentUserId]);
}

$pendingApprovalRows = [];
if ($hasWorkflowSteps && $hasDocVersions) {
    $pendingApprovalRows = fetch_all($conn, "
        SELECT
            d.id AS document_id,
            d.document_number,
            d.title,
            dt.type_name,
            dv.submitted_at,
            submitter.first_name,
            submitter.last_name,
            submitter.email,
            ws.document_version_id
        FROM workflow_steps ws
        INNER JOIN document_versions dv ON dv.id = ws.document_version_id
        INNER JOIN documents d ON d.id = dv.document_id
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        LEFT JOIN users submitter ON submitter.id = dv.submitted_by
        WHERE ws.approver_user_id = ?
          AND ws.status = 'pending'
          AND LOWER(COALESCE(d.current_status, '')) = 'pending_approval'
        ORDER BY dv.submitted_at ASC, ws.id ASC
        LIMIT 5
    ", 'i', [$currentUserId]);
}

$workQueueWhere = [];
$workQueueParams = [];
$workQueueTypes = '';

$workQueueWhere[] = "d.owner_user_id = ?";
$workQueueParams[] = $currentUserId;
$workQueueTypes .= 'i';

if ($hasDocumentsCreatedBy) {
    $workQueueWhere[] = "d.created_by = ?";
    $workQueueParams[] = $currentUserId;
    $workQueueTypes .= 'i';
}

if ($hasDocumentsUpdatedBy) {
    $workQueueWhere[] = "d.updated_by = ?";
    $workQueueParams[] = $currentUserId;
    $workQueueTypes .= 'i';
}

if ($hasWorkflowSteps) {
    $workQueueWhere[] = "ws.approver_user_id = ?";
    $workQueueParams[] = $currentUserId;
    $workQueueTypes .= 'i';
}

$updatedAtOrderCol = $hasDocumentsUpdatedAt ? "d.updated_at" : ($hasDocumentsCreatedAt ? "d.created_at" : "d.id");

$workQueueSql = "
    SELECT
        d.id AS document_id,
        d.current_version_id,
        d.document_number,
        d.title,
        d.current_status,
        dv.review_date,
        ws.status AS workflow_status
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
";

if ($hasWorkflowSteps) {
    $workQueueSql .= "
        LEFT JOIN workflow_steps ws
            ON ws.document_version_id = d.current_version_id
           AND ws.approver_user_id = ?
           AND ws.status = 'pending'
    ";
    $workQueueSqlTypesPrefix = 'i';
    $workQueueSqlParamsPrefix = [$currentUserId];
} else {
    $workQueueSqlTypesPrefix = '';
    $workQueueSqlParamsPrefix = [];
}

$workQueueSql .= "
    WHERE LOWER(COALESCE(d.current_status, '')) <> 'deleted'
      AND (" . implode(' OR ', $workQueueWhere) . ")
    ORDER BY {$updatedAtOrderCol} DESC, d.id DESC
    LIMIT 6
";

$workQueue = fetch_all(
    $conn,
    $workQueueSql,
    $workQueueSqlTypesPrefix . $workQueueTypes,
    array_merge($workQueueSqlParamsPrefix, $workQueueParams)
);

$recentActivity = [];
if ($hasAuditLogs) {
    $recentActivity = fetch_all($conn, "
        SELECT
            al.performed_at,
            al.action,
            al.remarks,
            al.entity_type,
            actor.first_name,
            actor.last_name,
            actor.email
        FROM audit_logs al
        LEFT JOIN users actor ON actor.id = al.performed_by
        WHERE al.performed_by = ?
           OR al.entity_type IN ('document', 'user')
        ORDER BY al.performed_at DESC, al.id DESC
        LIMIT 20
    ", 'i', [$currentUserId]);
}

$recentDocumentsSql = "
    SELECT
        d.id AS document_id,
        d.document_number,
        d.title,
        d.current_status,
        dt.type_name
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    WHERE LOWER(COALESCE(d.current_status, '')) <> 'deleted'
    ORDER BY
";

if ($hasDocumentsCreatedBy || $hasDocumentsUpdatedBy) {
    $priorityParts = [];
    if ($hasDocumentsCreatedBy) $priorityParts[] = "CASE WHEN d.created_by = ? THEN 1 ELSE 0 END";
    if ($hasDocumentsUpdatedBy) $priorityParts[] = "CASE WHEN d.updated_by = ? THEN 1 ELSE 0 END";
    $priorityParts[] = "CASE WHEN d.owner_user_id = ? THEN 1 ELSE 0 END";
    $recentDocumentsSql .= " (" . implode(' + ', $priorityParts) . ") DESC, ";

    $recentDocumentsParams = [];
    $recentDocumentsTypes = '';

    if ($hasDocumentsCreatedBy) {
        $recentDocumentsParams[] = $currentUserId;
        $recentDocumentsTypes .= 'i';
    }
    if ($hasDocumentsUpdatedBy) {
        $recentDocumentsParams[] = $currentUserId;
        $recentDocumentsTypes .= 'i';
    }

    $recentDocumentsParams[] = $currentUserId;
    $recentDocumentsTypes .= 'i';
} else {
    $recentDocumentsParams = [];
    $recentDocumentsTypes = '';
}

$recentDocumentsSql .= " CASE WHEN d.current_status = 'draft' THEN 0 ELSE 1 END ASC, {$updatedAtOrderCol} DESC, d.id DESC LIMIT 6";
$recentDocuments = fetch_all($conn, $recentDocumentsSql, $recentDocumentsTypes, $recentDocumentsParams);

$searchDocuments = fetch_all($conn, "
    SELECT
        d.id,
        d.document_number,
        d.title,
        d.topic,
        d.current_status,
        d.created_at,
        dt.type_name,
        dv.version_label,
        dv.effective_date,
        dv.review_date
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE LOWER(COALESCE(d.current_status, '')) <> 'deleted'
    ORDER BY d.document_number ASC, d.id DESC
");

$reviewQueueCount = 0;
if ($hasDocVersions) {
    $reviewQueueCount = count_query($conn, "
        SELECT COUNT(DISTINCT d.id) AS cnt
        FROM documents d
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE dv.review_date IS NOT NULL
          AND dv.review_date <> '0000-00-00'
          AND dv.review_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND LOWER(COALESCE(d.current_status, '')) IN ('effective','approved','published')
    ");
}

$ackPending = 0;
if ($hasAckAssignments) {
    if (columnExists($conn, 'acknowledgement_assignments', 'assignment_status')) {
        $ackPending = count_query($conn, "
            SELECT COUNT(*) AS cnt
            FROM acknowledgement_assignments
            WHERE assignment_status IN ('assigned', 'pending', 'overdue')
        ");
    } elseif (columnExists($conn, 'acknowledgement_assignments', 'status')) {
        $ackPending = count_query($conn, "
            SELECT COUNT(*) AS cnt
            FROM acknowledgement_assignments
            WHERE status IN ('assigned', 'pending', 'overdue')
        ");
    }
}

$searchDocumentsJson = json_encode($searchDocuments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ownedUrl = 'repository.php?owner_id=' . (int)$currentUserId;
$pendingUrl = 'repository.php?status=pending_approval&approver_id=' . (int)$currentUserId;
$overdueUrl = 'repository.php?review=overdue';
$unreadUrl = 'notifications.php?filter=unread';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .dashboard-top-row { align-items: flex-start !important; }
    .dashboard-user-card { height: auto !important; }
    .dashboard-user-card .card-body {
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      padding-bottom: 1.25rem !important;
    }
    .user-meta-grid { margin-bottom: 0 !important; }
    .dashboard-hero-stack { height: auto !important; gap: 1rem !important; }
    .search-card .card-body { padding-bottom: 1rem !important; }
    .hero-carousel { min-height: 265px; height: 265px !important; overflow: hidden; }
    .hero-carousel .carousel-item,
    .hero-carousel .hero-banner { min-height: 265px; height: 265px !important; }
    .hero-banner { padding: 1.5rem !important; }

    .dashboard-search-wrap { position: relative; }
    .dashboard-search-results {
      position: absolute;
      top: calc(100% + 8px);
      left: 0;
      right: 0;
      z-index: 1050;
      background: #fff;
      border: 1px solid #dbe3ef;
      border-radius: 14px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.10);
      max-height: 360px;
      overflow-y: auto;
      display: none;
    }
    .dashboard-search-results.show { display: block; }
    .dashboard-search-item {
      padding: 14px 16px;
      border-bottom: 1px solid #eef2f7;
    }
    .dashboard-search-item:last-child { border-bottom: none; }
    .dashboard-search-item:hover { background: #f8fbff; }
    .dashboard-search-docid {
      font-size: 13px;
      font-weight: 700;
      color: #2563eb;
      margin-bottom: 4px;
    }
    .dashboard-search-title {
      font-size: 14px;
      font-weight: 600;
      color: #1f2937;
      margin-bottom: 4px;
    }
    .dashboard-search-meta {
      font-size: 12px;
      color: #6b7280;
      line-height: 1.5;
    }
    .dashboard-search-actions { margin-top: 8px; }
    .dashboard-search-empty {
      padding: 14px 16px;
      font-size: 13px;
      color: #6b7280;
    }

    .activity-scroll {
      max-height: 248px;
      overflow-y: auto;
      padding-right: 4px;
    }

    .recent-docs-row {
      flex-wrap: nowrap !important;
      overflow-x: auto;
      margin-right: 0;
      margin-left: 0;
      padding-bottom: 4px;
    }
    .recent-docs-row > [class*="col-"] {
      flex: 0 0 190px;
      max-width: 190px;
      padding-right: 8px;
      padding-left: 8px;
    }
    .recent-doc-box {
      min-height: 132px;
      padding: 12px !important;
    }
    .recent-doc-box .small { font-size: 11px; }
    .recent-doc-box .fw-semibold {
      font-size: 13px;
      line-height: 1.35;
      min-height: 34px;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .doc-id-link {
      color:#2563eb;
      font-weight:700;
      text-decoration:none;
    }
    .doc-id-link:hover {
      text-decoration:underline;
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/navbar.php'; ?>

<main class="app-shell">
  <div class="content-wrap px-4 px-xxl-5 mx-auto">
    <div class="mb-4 mt-3">
      <h1 class="page-title mb-2">Welcome back, <?php echo e($welcomeName); ?></h1>
      <p class="page-subtitle mb-0">Manage document activity, pending actions, and repository access from your workspace.</p>
    </div>

    <div class="row g-3 g-xxl-4 mb-4 dashboard-top-row">
      <div class="col-xl-3">
        <div class="card cp-card dashboard-user-card">
          <div class="card-body p-4">
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="user-avatar"><?php echo e($avatarLetter); ?></div>
              <div>
                <div class="fw-bold fs-5"><?php echo e($welcomeName); ?></div>
                <div class="text-secondary small"><?php echo e($currentRoleName); ?></div>
              </div>
            </div>

            <div class="user-meta-grid">
              <a href="<?php echo e($ownedUrl); ?>" class="user-meta-box text-decoration-none" style="cursor:pointer;" title="View your owned documents only">
                <div class="user-meta-value"><?php echo (int)$documentsOwned; ?></div>
                <div class="user-meta-label">Documents Owned</div>
              </a>

              <a href="<?php echo e($pendingUrl); ?>" class="user-meta-box text-decoration-none" style="cursor:pointer;" title="View pending approval documents only">
                <div class="user-meta-value"><?php echo (int)$pendingApprovals; ?></div>
                <div class="user-meta-label">Pending Approvals</div>
              </a>

              <a href="<?php echo e($overdueUrl); ?>" class="user-meta-box text-decoration-none" style="cursor:pointer;" title="View overdue review documents only">
                <div class="user-meta-value"><?php echo (int)$overdueReviews; ?></div>
                <div class="user-meta-label">Overdue Reviews</div>
              </a>

              <a href="<?php echo e($unreadUrl); ?>" class="user-meta-box text-decoration-none" style="cursor:pointer;" title="View unread notifications only">
                <div class="user-meta-value"><?php echo (int)$unreadAlerts; ?></div>
                <div class="user-meta-label">Unread Alerts</div>
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl-9">
        <div class="dashboard-hero-stack d-flex flex-column">
          <div class="card cp-card search-card">
            <div class="card-body p-4">
              <div class="search-label mb-2">Global Search</div>
              <div class="dashboard-search-wrap">
                <form action="javascript:void(0);" method="get" id="dashboardSearchForm" autocomplete="off">
                  <div class="input-group input-group-lg search-group">
                    <span class="input-group-text bg-white border-end-0">
                      <svg xmlns="https://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>
                    </span>
                    <input type="text" id="dashboardSearchInput" class="form-control border-start-0 ps-0" placeholder="Search documents, IDs, owners, or keywords">
                  </div>
                </form>
                <div class="dashboard-search-results" id="dashboardSearchResults"></div>
              </div>
            </div>
          </div>

          <div id="dashboardCarousel" class="carousel slide" data-bs-ride="carousel">
            <div class="carousel-indicators dashboard-indicators">
              <button type="button" data-bs-target="#dashboardCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
              <button type="button" data-bs-target="#dashboardCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
              <button type="button" data-bs-target="#dashboardCarousel" data-bs-slide-to="2" aria-label="Slide 3"></button>
            </div>

            <div class="carousel-inner hero-carousel cp-card">
              <div class="carousel-item active">
                <div class="hero-banner hero-banner-review">
                  <div>
                    <div class="hero-eyebrow">Attention Required</div>
                    <h2 class="hero-title"><?php echo (int)$reviewQueueCount; ?> documents are due for periodic review</h2>
                    <p class="hero-copy mb-0">Open the review queue and assign owners before compliance due dates are missed.</p>
                  </div>
                  <a href="repository.php?review=current" class="btn btn-light hero-btn">Open Review Queue</a>
                </div>
              </div>

              <div class="carousel-item">
                <div class="hero-banner hero-banner-policy">
                  <div>
                    <div class="hero-eyebrow">Repository Update</div>
                    <h2 class="hero-title"><?php echo (int)$ackPending; ?> acknowledgement assignments are still pending</h2>
                    <p class="hero-copy mb-0">Share the latest effective documents and track read confirmation across assigned users.</p>
                  </div>
                  <a href="notifications.php" class="btn btn-light hero-btn">View Notifications</a>
                </div>
              </div>

              <div class="carousel-item">
                <div class="hero-banner hero-banner-search">
                  <div>
                    <div class="hero-eyebrow">Search Tip</div>
                    <h2 class="hero-title">Find documents faster with ID, owner, or keyword search</h2>
                    <p class="hero-copy mb-0">Use the workspace search bar to jump directly to drafts, approvals, and effective records.</p>
                  </div>
                  <a href="repository.php" class="btn btn-light hero-btn">Open Repository</a>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <div class="card cp-card mb-4" id="actionRequiredPanel" style="border-left:4px solid #f59e0b;background:#fffbeb;">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div class="d-flex align-items-center gap-2">
            <span style="font-size:1.2rem;">⚠️</span>
            <div>
              <h2 class="card-title mb-0" style="color:#b45309;">Action Required — Pending Approvals</h2>
              <p class="card-subtitle mb-0">These documents are waiting for your electronic signature. They cannot proceed until reviewed.</p>
            </div>
          </div>
          <div class="d-flex gap-2 align-items-center">
            <a href="<?php echo e($pendingUrl); ?>" class="btn btn-sm btn-warning" style="font-weight:600;">View All Pending</a>
            <button class="btn btn-sm btn-outline-secondary" onclick="dismissActionPanel()" title="Dismiss for this session">✕</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle mb-0" style="font-size:13px;">
            <thead>
              <tr>
                <th>Document ID</th>
                <th>Title</th>
                <th>Type</th>
                <th>Submitted By</th>
                <th>Submitted On</th>
                <th>Days Waiting</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($pendingApprovalRows)): ?>
                <?php foreach ($pendingApprovalRows as $row): ?>
                  <?php
                    $submittedByName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    if ($submittedByName === '') $submittedByName = $row['email'] ?? '—';

                    $submittedOn = !empty($row['submitted_at']) ? strtotime($row['submitted_at']) : null;
                    $daysWaiting = $submittedOn ? max(0, floor((time() - $submittedOn) / 86400)) : 0;
                    $daysBadge = $daysWaiting >= 4 ? 'badge-soft-danger' : 'badge-soft-warning';
                  ?>
                  <tr>
                    <td>
                      <a class="doc-id-link" href="view-document.php?id=<?php echo (int)$row['document_id']; ?>&version_id=<?php echo (int)$row['document_version_id']; ?>">
                        <?php echo e($row['document_number']); ?>
                      </a>
                    </td>
                    <td><?php echo e($row['title']); ?></td>
                    <td><span class="badge badge-soft-info"><?php echo e($row['type_name'] ?: 'Document'); ?></span></td>
                    <td><?php echo e($submittedByName); ?></td>
                    <td style="color:#6b7280;"><?php echo e($submittedOn ? date('d M Y', $submittedOn) : '—'); ?></td>
                    <td><span class="badge <?php echo e($daysBadge); ?>"><?php echo (int)$daysWaiting; ?> day<?php echo $daysWaiting !== 1 ? 's' : ''; ?></span></td>
                    <td>
                      <a href="review-document.php?id=<?php echo (int)$row['document_id']; ?>&version_id=<?php echo (int)$row['document_version_id']; ?>" class="btn btn-sm btn-success" style="height:28px;padding:0 12px;font-size:12px;font-weight:600;">Review &amp; Sign</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center text-secondary py-4">No pending approvals for you.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>

    <div class="row g-3 g-xxl-4 mb-4">
      <div class="col-xl-8">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
              <div>
                <h2 class="card-title mb-1">My Work Queue</h2>
                <p class="card-subtitle mb-0">Items that require your action today.</p>
              </div>
              <a href="repository.php" class="btn btn-sm btn-outline-primary">Open Queue</a>
            </div>

            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th>Document ID</th>
                    <th>Title</th>
                    <th>Task</th>
                    <th>Status</th>
                    <th>Due</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($workQueue)): ?>
                    <?php foreach ($workQueue as $row): ?>
                      <?php
                        $task = 'Owner Task';

                        if (($row['workflow_status'] ?? '') === 'pending') {
                            $task = 'Approval Required';
                        } elseif (($row['current_status'] ?? '') === 'draft') {
                            $task = 'Draft Update';
                        } elseif (($row['current_status'] ?? '') === 'pending_approval') {
                            $task = 'Pending Workflow';
                        } else {
                            $task = 'Periodic Review';
                        }

                        $viewUrl = 'view-document.php?id=' . (int)($row['document_id'] ?? 0);
                        if (!empty($row['current_version_id'])) {
                            $viewUrl .= '&version_id=' . (int)$row['current_version_id'];
                        }
                      ?>
                      <tr>
                        <td>
                          <a href="<?php echo e($viewUrl); ?>" class="doc-id-link" title="Open document">
                            <?php echo e($row['document_number']); ?>
                          </a>
                        </td>
                        <td><?php echo e($row['title']); ?></td>
                        <td><?php echo e($task); ?></td>
                        <td><?php echo status_badge((string)($row['current_status'] ?? 'draft')); ?></td>
                        <td><?php echo e(!empty($row['review_date']) ? date('d-M-Y', strtotime($row['review_date'])) : '—'); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5" class="text-center text-secondary py-4">No work queue items found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>

      <div class="col-xl-4">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Recent Activity</h2>
            <p class="card-subtitle mb-3">Latest recorded actions in your workspace.</p>

            <div class="activity-list small activity-scroll">
              <?php if (!empty($recentActivity)): ?>
                <?php foreach ($recentActivity as $row): ?>
                  <?php
                    $actor = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    if ($actor === '') $actor = $row['email'] ?? 'System';

                    $timeLabel = !empty($row['performed_at']) ? date('H:i', strtotime($row['performed_at'])) : '--:--';
                    $actionLabel = ucwords(str_replace('_', ' ', (string)($row['action'] ?? 'Activity')));
                    $remark = $row['remarks'] ?: (($row['entity_type'] ?? 'item') . ' activity recorded.');
                  ?>
                  <div class="activity-item">
                    <span class="activity-time"><?php echo e($timeLabel); ?></span>
                    <div>
                      <div class="fw-semibold"><?php echo e($actionLabel); ?></div>
                      <div class="text-secondary"><?php echo e($remark . ' By ' . $actor . '.'); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="text-secondary">No recent activity found.</div>
              <?php endif; ?>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 g-xxl-4">
      <div class="col-xl-8">
        <div class="card cp-card">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
              <div>
                <h2 class="card-title mb-1">Recent Documents</h2>
                <p class="card-subtitle mb-0">Recently updated and published records.</p>
              </div>
              <a href="repository.php" class="btn btn-sm btn-outline-primary">Open Repository</a>
            </div>

            <div class="row g-2 recent-docs-row">
              <?php if (!empty($recentDocuments)): ?>
                <?php foreach (array_slice($recentDocuments, 0, 6) as $row): ?>
                  <div class="col-auto">
                    <a href="view-document.php?id=<?php echo (int)$row['document_id']; ?>" class="text-decoration-none text-reset">
                      <div class="repo-box recent-doc-box h-100">
                        <div class="small text-secondary mb-2"><?php echo e($row['type_name'] ?: 'Document'); ?></div>
                        <div class="fw-semibold mb-2"><?php echo e($row['title']); ?></div>
                        <div class="small text-secondary mb-2"><?php echo e($row['document_number']); ?></div>
                        <?php echo status_badge((string)($row['current_status'] ?? 'draft')); ?>
                      </div>
                    </a>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="col-12">
                  <div class="text-center text-secondary py-4">No recent documents found.</div>
                </div>
              <?php endif; ?>
            </div>

          </div>
        </div>
      </div>

      <div class="col-xl-4">
        <div class="card cp-card">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Quick Actions</h2>
            <p class="card-subtitle mb-3">Start the most common tasks from one place.</p>
            <div class="d-grid gap-2">
              <a class="btn btn-primary" href="create-document.php">Create Document</a>
              <a class="btn btn-outline-primary" href="update-document.php">Update Document</a>
              <a class="btn btn-outline-primary" href="repository.php">Open Repository</a>
              <a class="btn btn-outline-primary" href="audit-trail.php">View Audit Trail</a>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function dismissActionPanel() {
  document.getElementById('actionRequiredPanel').style.display = 'none';
  sessionStorage.setItem('actionPanelDismissed', '1');
}

if (sessionStorage.getItem('actionPanelDismissed') === '1') {
  var p = document.getElementById('actionRequiredPanel');
  if (p) p.style.display = 'none';
}

const DASHBOARD_DOCUMENTS = <?php echo $searchDocumentsJson ?: '[]'; ?>;
const dashboardSearchInput = document.getElementById('dashboardSearchInput');
const dashboardSearchResults = document.getElementById('dashboardSearchResults');

function escapeHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatDocDate(str) {
  if (!str) return '—';
  const d = new Date(str);
  if (isNaN(d.getTime())) return str;
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function renderSearchResults(query) {
  const q = String(query || '').trim().toLowerCase();

  if (q === '') {
    dashboardSearchResults.innerHTML = '';
    dashboardSearchResults.classList.remove('show');
    return;
  }

  let exactMatches = [];
  let partialMatches = [];

  DASHBOARD_DOCUMENTS.forEach(function(doc) {
    const docNo = String(doc.document_number || '').toLowerCase();
    const title = String(doc.title || '').toLowerCase();
    const topic = String(doc.topic || '').toLowerCase();
    const type = String(doc.type_name || '').toLowerCase();

    if (docNo === q) {
      exactMatches.push(doc);
    } else if (
      docNo.indexOf(q) !== -1 ||
      title.indexOf(q) !== -1 ||
      topic.indexOf(q) !== -1 ||
      type.indexOf(q) !== -1
    ) {
      partialMatches.push(doc);
    }
  });

  const matches = exactMatches.length ? exactMatches : partialMatches;

  if (!matches.length) {
    dashboardSearchResults.innerHTML = '<div class="dashboard-search-empty">No matching document found.</div>';
    dashboardSearchResults.classList.add('show');
    return;
  }

  dashboardSearchResults.innerHTML = matches.slice(0, 8).map(function(doc) {
    return '' +
      '<div class="dashboard-search-item">' +
        '<div class="dashboard-search-docid">' + escapeHtml(doc.document_number || '') + '</div>' +
        '<div class="dashboard-search-title">' + escapeHtml(doc.title || 'Untitled Document') + '</div>' +
        '<div class="dashboard-search-meta">' +
          'Type: ' + escapeHtml(doc.type_name || 'Document') + '<br>' +
          'Version: ' + escapeHtml(doc.version_label || '—') + ' &nbsp;|&nbsp; ' +
          'Status: ' + escapeHtml(doc.current_status || '—') + '<br>' +
          'Effective Date: ' + escapeHtml(formatDocDate(doc.effective_date)) + ' &nbsp;|&nbsp; ' +
          'Review Date: ' + escapeHtml(formatDocDate(doc.review_date)) +
        '</div>' +
        '<div class="dashboard-search-actions">' +
          '<a href="view-document.php?id=' + encodeURIComponent(doc.id || '') + '" class="btn btn-sm btn-outline-primary">Open Document</a> ' +
          '<a href="repository.php?search=' + encodeURIComponent(doc.document_number || '') + '" class="btn btn-sm btn-outline-secondary">Open in Repository</a>' +
        '</div>' +
      '</div>';
  }).join('');

  dashboardSearchResults.classList.add('show');
}

dashboardSearchInput.addEventListener('input', function() {
  renderSearchResults(this.value);
});

dashboardSearchInput.addEventListener('focus', function() {
  renderSearchResults(this.value);
});

dashboardSearchInput.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    renderSearchResults(this.value);
  }
});

document.addEventListener('click', function(e) {
  if (!dashboardSearchResults.contains(e.target) && e.target !== dashboardSearchInput) {
    dashboardSearchResults.classList.remove('show');
  }
});
</script>
</body>
</html>