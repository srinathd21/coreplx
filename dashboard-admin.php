<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('Asia/Kolkata');

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
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
        if ($res) {
            mysqli_free_result($res);
        }
        return $ok;
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        if (!tableExists($conn, $tableName)) {
            return false;
        }

        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);

        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        $ok = ($res && mysqli_num_rows($res) > 0);

        if ($res) {
            mysqli_free_result($res);
        }

        return $ok;
    }
}

if (!function_exists('bindDynamic')) {
    function bindDynamic(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '' || empty($params)) {
            return true;
        }

        $refs = [];
        $refs[] = $types;

        foreach ($params as $key => &$value) {
            $refs[] = &$value;
        }

        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('fetch_one')) {
    function fetch_one(mysqli $conn, string $sql, string $types = '', array $params = [])
    {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return null;
        }

        if ($types !== '' && !empty($params)) {
            bindDynamic($stmt, $types, $params);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        mysqli_stmt_close($stmt);
        return $row;
    }
}

if (!function_exists('fetch_all')) {
    function fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
    {
        $rows = [];

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return $rows;
        }

        if ($types !== '' && !empty($params)) {
            bindDynamic($stmt, $types, $params);
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
}

if (!function_exists('count_query')) {
    function count_query(mysqli $conn, string $sql, string $types = '', array $params = []): int
    {
        $row = fetch_one($conn, $sql, $types, $params);
        return (int)($row['cnt'] ?? 0);
    }
}

if (!function_exists('status_badge')) {
    function status_badge(string $status): string
    {
        $status = strtolower(trim($status));

        if (in_array($status, ['effective', 'approved', 'published'], true)) {
            return '<span class="badge badge-soft-success">Effective</span>';
        }

        if (in_array($status, ['pending_approval', 'pending approval'], true)) {
            return '<span class="badge badge-soft-warning">Pending Approval</span>';
        }

        if ($status === 'draft') {
            return '<span class="badge badge-soft-secondary">Draft</span>';
        }

        if ($status === 'overdue') {
            return '<span class="badge badge-soft-danger">Overdue</span>';
        }

        if (in_array($status, ['in_progress', 'in progress'], true)) {
            return '<span class="badge badge-soft-info">In Progress</span>';
        }

        if ($status === 'rejected') {
            return '<span class="badge badge-soft-danger">Rejected</span>';
        }

        if ($status === 'returned') {
            return '<span class="badge badge-soft-warning">Returned</span>';
        }

        if ($status === 'pending_retirement') {
            return '<span class="badge badge-soft-warning">Pending Retirement</span>';
        }

        if ($status === '') {
            return '<span class="badge badge-soft-secondary">Unknown</span>';
        }

        return '<span class="badge badge-soft-info">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
    }
}

if (!function_exists('short_name')) {
    function short_name(array $row): string
    {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        if (!empty($row['full_name']) && trim((string)$row['full_name']) !== '' && (string)$row['full_name'] !== '0') {
            return trim((string)$row['full_name']);
        }

        return (string)($row['email'] ?? 'User');
    }
}

if (!function_exists('displayDate')) {
    function displayDate($value): string
    {
        if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '—';
        }

        $ts = strtotime((string)$value);
        return $ts ? date('d M Y', $ts) : (string)$value;
    }
}

/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| TABLE / COLUMN CHECKS
|--------------------------------------------------------------------------
*/
$hasDocuments         = tableExists($conn, 'documents');
$hasDocVersions       = tableExists($conn, 'document_versions');
$hasDocTypes          = tableExists($conn, 'document_types');
$hasUsers             = tableExists($conn, 'users');
$hasRoles             = tableExists($conn, 'roles');
$hasWorkflowSteps     = tableExists($conn, 'workflow_steps');
$hasNotifications     = tableExists($conn, 'notifications');
$hasAuditLogs         = tableExists($conn, 'audit_logs');
$hasAckAssignments    = tableExists($conn, 'acknowledgement_assignments');

if (!$hasDocuments || !$hasDocVersions) {
    die('Required tables missing: documents and document_versions are required for dashboard.');
}

$hasDocumentsTitle        = columnExists($conn, 'documents', 'title');
$hasDocumentsTopic        = columnExists($conn, 'documents', 'topic');
$hasDocumentsCreatedBy    = columnExists($conn, 'documents', 'created_by');
$hasDocumentsUpdatedBy    = columnExists($conn, 'documents', 'updated_by');
$hasDocumentsUpdatedAt    = columnExists($conn, 'documents', 'updated_at');
$hasDocumentsCreatedAt    = columnExists($conn, 'documents', 'created_at');
$hasDocumentsOwner        = columnExists($conn, 'documents', 'owner_user_id');
$hasDocumentsApprover     = columnExists($conn, 'documents', 'approver');
$hasDocumentsDepartmentId = columnExists($conn, 'documents', 'department_id');

$hasDvStatus          = columnExists($conn, 'document_versions', 'status');
$hasDvSubmittedBy     = columnExists($conn, 'document_versions', 'submitted_by');
$hasDvSubmittedAt     = columnExists($conn, 'document_versions', 'submitted_at');
$hasDvReviewDate      = columnExists($conn, 'document_versions', 'review_date');
$hasDvEffectiveDate   = columnExists($conn, 'document_versions', 'effective_date');
$hasDvVersionLabel    = columnExists($conn, 'document_versions', 'version_label');
$hasDvContentText     = columnExists($conn, 'document_versions', 'content_text');

$titleSelect = $hasDocumentsTitle ? "d.title" : "'' AS title";
$topicSelect = $hasDocumentsTopic ? "d.topic" : "'' AS topic";
$displayTitleSql = $hasDocumentsTitle && $hasDocumentsTopic
    ? "COALESCE(NULLIF(d.title,''), NULLIF(d.topic,''), 'Untitled')"
    : ($hasDocumentsTitle ? "COALESCE(NULLIF(d.title,''), 'Untitled')" : ($hasDocumentsTopic ? "COALESCE(NULLIF(d.topic,''), 'Untitled')" : "'Untitled'"));

$sortDateCol = $hasDocumentsUpdatedAt
    ? "d.updated_at"
    : ($hasDocumentsCreatedAt ? "d.created_at" : "d.id");

$sortDateSelect = $hasDocumentsUpdatedAt && $hasDocumentsCreatedAt
    ? "COALESCE(d.updated_at, d.created_at)"
    : ($hasDocumentsUpdatedAt ? "d.updated_at" : ($hasDocumentsCreatedAt ? "d.created_at" : "NULL"));

$ownerWhereParts = [];
$ownerParams = [];
$ownerTypes = '';

if ($hasDocumentsOwner) {
    $ownerWhereParts[] = "d.owner_user_id = ?";
    $ownerParams[] = $currentUserId;
    $ownerTypes .= 'i';
}

if ($hasDocumentsCreatedBy) {
    $ownerWhereParts[] = "d.created_by = ?";
    $ownerParams[] = $currentUserId;
    $ownerTypes .= 'i';
}

if ($hasDocumentsUpdatedBy) {
    $ownerWhereParts[] = "d.updated_by = ?";
    $ownerParams[] = $currentUserId;
    $ownerTypes .= 'i';
}

if (empty($ownerWhereParts)) {
    $ownerWhereParts[] = "1 = 0";
}

$ownerWhereSql = "(" . implode(" OR ", $ownerWhereParts) . ")";

/*
|--------------------------------------------------------------------------
| USER INFO
|--------------------------------------------------------------------------
*/
$userRow = null;

if ($hasUsers) {
    $userRow = fetch_one($conn, "
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            " . (columnExists($conn, 'users', 'full_name') ? "u.full_name" : "'' AS full_name") . ",
            u.email,
            " . ($hasRoles ? "r.role_name" : "'' AS role_name") . "
        FROM users u
        " . ($hasRoles ? "LEFT JOIN roles r ON r.id = u.current_role_id" : "") . "
        WHERE u.id = ?
        LIMIT 1
    ", 'i', [$currentUserId]);
}

$welcomeName = $userRow ? short_name($userRow) : $currentDisplayName;
$avatarLetter = strtoupper(substr($welcomeName, 0, 1));

if (!empty($userRow['role_name'])) {
    $currentRoleName = $userRow['role_name'];
}

/*
|--------------------------------------------------------------------------
| DOCUMENTS OWNED
|--------------------------------------------------------------------------
*/
$documentsOwned = count_query($conn, "
    SELECT COUNT(DISTINCT d.id) AS cnt
    FROM documents d
    WHERE {$ownerWhereSql}
", $ownerTypes, $ownerParams);

/*
|--------------------------------------------------------------------------
| PENDING APPROVALS
|--------------------------------------------------------------------------
*/
$workflowPendingApprovals = 0;

if ($hasWorkflowSteps) {
    $workflowPendingApprovals = count_query($conn, "
        SELECT COUNT(DISTINCT ws.document_version_id) AS cnt
        FROM workflow_steps ws
        WHERE ws.approver_user_id = ?
          AND LOWER(COALESCE(ws.status,'')) = 'pending'
    ", 'i', [$currentUserId]);
}

$fallbackPendingApprovals = 0;

if ($hasDocumentsApprover) {
    $fallbackSql = "
        SELECT COUNT(DISTINCT d.id) AS cnt
        FROM documents d
        INNER JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE LOWER(COALESCE(d.current_status,'')) = 'pending_approval'
          AND TRIM(COALESCE(d.approver,'')) = ?
    ";

    if ($hasDvStatus) {
        $fallbackSql .= " AND LOWER(COALESCE(dv.status,'')) = 'pending_approval' ";
    }

    if ($hasWorkflowSteps) {
        $fallbackSql .= "
          AND NOT EXISTS (
              SELECT 1
              FROM workflow_steps ws
              WHERE ws.document_version_id = d.current_version_id
                AND ws.approver_user_id = ?
                AND LOWER(COALESCE(ws.status,'')) = 'pending'
          )
        ";

        $fallbackPendingApprovals = count_query($conn, $fallbackSql, 'si', [(string)$currentUserId, $currentUserId]);
    } else {
        $fallbackPendingApprovals = count_query($conn, $fallbackSql, 's', [(string)$currentUserId]);
    }
}

$pendingApprovals = $workflowPendingApprovals + $fallbackPendingApprovals;

/*
|--------------------------------------------------------------------------
| OVERDUE REVIEWS
|--------------------------------------------------------------------------
*/
$overdueReviews = 0;

if ($hasDvReviewDate) {
    $overdueReviews = count_query($conn, "
        SELECT COUNT(DISTINCT d.id) AS cnt
        FROM documents d
        INNER JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE dv.review_date IS NOT NULL
          AND dv.review_date <> '0000-00-00'
          AND dv.review_date < CURDATE()
          AND LOWER(COALESCE(d.current_status,'')) IN ('effective', 'approved', 'published')
    ");
}

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
*/
$notificationUnreadCount = 0;

if ($hasNotifications && columnExists($conn, 'notifications', 'user_id') && columnExists($conn, 'notifications', 'is_read')) {
    $notificationUnreadCount = count_query($conn, "
        SELECT COUNT(*) AS cnt
        FROM notifications
        WHERE user_id = ?
          AND is_read = 0
    ", 'i', [$currentUserId]);
}

if ($pendingApprovals > 0) {
    $alertStatCount = $pendingApprovals;
    $alertStatLabel = 'Pending Approval Alerts';
    $alertStatLink = 'audit-trail.php?tab=approval';
    $alertStatTitle = 'View documents pending your approval';
} else {
    $alertStatCount = $notificationUnreadCount;
    $alertStatLabel = 'Unread Alerts';
    $alertStatLink = 'notifications.php?filter=unread';
    $alertStatTitle = 'View unread alerts';
}

/*
|--------------------------------------------------------------------------
| PENDING APPROVAL ROWS
|--------------------------------------------------------------------------
*/
$pendingApprovalRows = [];

if ($hasWorkflowSteps) {
    $workflowRowsSql = "
        SELECT
            d.document_number,
            {$displayTitleSql} AS title,
            " . ($hasDocTypes ? "dt.type_name" : "'Document' AS type_name") . ",
            " . ($hasDvSubmittedAt ? "dv.submitted_at" : "NULL AS submitted_at") . ",
            submitter.first_name,
            submitter.last_name,
            " . (columnExists($conn, 'users', 'email') ? "submitter.email" : "'' AS email") . ",
            ws.document_version_id,
            'workflow' AS source_type
        FROM workflow_steps ws
        INNER JOIN document_versions dv ON dv.id = ws.document_version_id
        INNER JOIN documents d ON d.id = dv.document_id
        " . ($hasDocTypes ? "LEFT JOIN document_types dt ON dt.id = d.document_type_id" : "") . "
        " . ($hasUsers && $hasDvSubmittedBy ? "LEFT JOIN users submitter ON submitter.id = dv.submitted_by" : "LEFT JOIN users submitter ON 1 = 0") . "
        WHERE ws.approver_user_id = ?
          AND LOWER(COALESCE(ws.status,'')) = 'pending'
        ORDER BY " . ($hasDvSubmittedAt ? "dv.submitted_at ASC" : "d.id ASC") . "
        LIMIT 10
    ";

    $workflowRows = fetch_all($conn, $workflowRowsSql, 'i', [$currentUserId]);
    $pendingApprovalRows = array_merge($pendingApprovalRows, $workflowRows);
}

if ($hasDocumentsApprover) {
    $fallbackRowsSql = "
        SELECT
            d.document_number,
            {$displayTitleSql} AS title,
            " . ($hasDocTypes ? "dt.type_name" : "'Document' AS type_name") . ",
            " . ($hasDvSubmittedAt ? "dv.submitted_at" : "NULL AS submitted_at") . ",
            submitter.first_name,
            submitter.last_name,
            " . (columnExists($conn, 'users', 'email') ? "submitter.email" : "'' AS email") . ",
            d.current_version_id AS document_version_id,
            'document_fallback' AS source_type
        FROM documents d
        INNER JOIN document_versions dv ON dv.id = d.current_version_id
        " . ($hasDocTypes ? "LEFT JOIN document_types dt ON dt.id = d.document_type_id" : "") . "
        " . ($hasUsers && $hasDvSubmittedBy ? "LEFT JOIN users submitter ON submitter.id = dv.submitted_by" : "LEFT JOIN users submitter ON 1 = 0") . "
        WHERE LOWER(COALESCE(d.current_status,'')) = 'pending_approval'
          AND TRIM(COALESCE(d.approver,'')) = ?
    ";

    if ($hasDvStatus) {
        $fallbackRowsSql .= " AND LOWER(COALESCE(dv.status,'')) = 'pending_approval' ";
    }

    if ($hasWorkflowSteps) {
        $fallbackRowsSql .= "
          AND NOT EXISTS (
              SELECT 1
              FROM workflow_steps ws
              WHERE ws.document_version_id = d.current_version_id
                AND ws.approver_user_id = ?
                AND LOWER(COALESCE(ws.status,'')) = 'pending'
          )
        ";

        $fallbackRowsSql .= " ORDER BY " . ($hasDvSubmittedAt ? "dv.submitted_at ASC" : "d.id ASC") . " LIMIT 10";

        $fallbackRows = fetch_all($conn, $fallbackRowsSql, 'si', [(string)$currentUserId, $currentUserId]);
    } else {
        $fallbackRowsSql .= " ORDER BY " . ($hasDvSubmittedAt ? "dv.submitted_at ASC" : "d.id ASC") . " LIMIT 10";

        $fallbackRows = fetch_all($conn, $fallbackRowsSql, 's', [(string)$currentUserId]);
    }

    $pendingApprovalRows = array_merge($pendingApprovalRows, $fallbackRows);
}

usort($pendingApprovalRows, function ($a, $b) {
    $aTime = !empty($a['submitted_at']) ? strtotime($a['submitted_at']) : 0;
    $bTime = !empty($b['submitted_at']) ? strtotime($b['submitted_at']) : 0;
    return $aTime <=> $bTime;
});

$pendingApprovalRows = array_slice($pendingApprovalRows, 0, 5);

/*
|--------------------------------------------------------------------------
| WORK QUEUE
|--------------------------------------------------------------------------
*/
$workQueue = [];

if ($hasWorkflowSteps) {
    $workflowQueueRows = fetch_all($conn, "
        SELECT
            d.document_number,
            {$displayTitleSql} AS title,
            d.current_status,
            " . ($hasDvReviewDate ? "dv.review_date" : "NULL AS review_date") . ",
            'pending' AS workflow_status,
            'Approval Required' AS queue_task,
            {$sortDateSelect} AS sort_dt
        FROM workflow_steps ws
        INNER JOIN document_versions dv ON dv.id = ws.document_version_id
        INNER JOIN documents d ON d.id = dv.document_id
        WHERE ws.approver_user_id = ?
          AND LOWER(COALESCE(ws.status,'')) = 'pending'
        ORDER BY " . ($hasDvSubmittedAt ? "dv.submitted_at ASC" : "d.id DESC") . "
        LIMIT 6
    ", 'i', [$currentUserId]);

    $workQueue = array_merge($workQueue, $workflowQueueRows);
}

if ($hasDocumentsApprover) {
    $fallbackQueueSql = "
        SELECT
            d.document_number,
            {$displayTitleSql} AS title,
            d.current_status,
            " . ($hasDvReviewDate ? "dv.review_date" : "NULL AS review_date") . ",
            'pending' AS workflow_status,
            'Approval Required' AS queue_task,
            {$sortDateSelect} AS sort_dt
        FROM documents d
        INNER JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE LOWER(COALESCE(d.current_status,'')) = 'pending_approval'
          AND TRIM(COALESCE(d.approver,'')) = ?
    ";

    if ($hasDvStatus) {
        $fallbackQueueSql .= " AND LOWER(COALESCE(dv.status,'')) = 'pending_approval' ";
    }

    if ($hasWorkflowSteps) {
        $fallbackQueueSql .= "
          AND NOT EXISTS (
              SELECT 1
              FROM workflow_steps ws
              WHERE ws.document_version_id = d.current_version_id
                AND ws.approver_user_id = ?
                AND LOWER(COALESCE(ws.status,'')) = 'pending'
          )
        ";

        $fallbackQueueRows = fetch_all($conn, $fallbackQueueSql . " ORDER BY d.id DESC LIMIT 6", 'si', [(string)$currentUserId, $currentUserId]);
    } else {
        $fallbackQueueRows = fetch_all($conn, $fallbackQueueSql . " ORDER BY d.id DESC LIMIT 6", 's', [(string)$currentUserId]);
    }

    $workQueue = array_merge($workQueue, $fallbackQueueRows);
}

$ownerQueueRows = fetch_all($conn, "
    SELECT
        d.document_number,
        {$displayTitleSql} AS title,
        d.current_status,
        " . ($hasDvReviewDate ? "dv.review_date" : "NULL AS review_date") . ",
        '' AS workflow_status,
        CASE
            WHEN LOWER(COALESCE(d.current_status,'')) = 'draft' THEN 'Draft Update'
            WHEN LOWER(COALESCE(d.current_status,'')) = 'pending_retirement' THEN 'Retirement Follow-up'
            ELSE 'Owner Task'
        END AS queue_task,
        {$sortDateSelect} AS sort_dt
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE {$ownerWhereSql}
    ORDER BY {$sortDateCol} DESC
    LIMIT 6
", $ownerTypes, $ownerParams);

$workQueue = array_merge($workQueue, $ownerQueueRows);

$dedupedQueue = [];
foreach ($workQueue as $item) {
    $key = ($item['document_number'] ?? '') . '|' . ($item['queue_task'] ?? '');
    $dedupedQueue[$key] = $item;
}

$workQueue = array_values($dedupedQueue);

usort($workQueue, function ($a, $b) {
    $aTime = !empty($a['sort_dt']) ? strtotime($a['sort_dt']) : 0;
    $bTime = !empty($b['sort_dt']) ? strtotime($b['sort_dt']) : 0;
    return $bTime <=> $aTime;
});

$workQueue = array_slice($workQueue, 0, 6);

/*
|--------------------------------------------------------------------------
| RECENT ACTIVITY
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| RECENT DOCUMENTS FROM CREATE DOCUMENT TABLES
|--------------------------------------------------------------------------
| Main source used by create/submit flow:
| - documents
| - document_versions
| - document_types
|--------------------------------------------------------------------------
*/
$priorityParts = [];
$recentParams = [];
$recentTypes = '';

if ($hasDocumentsCreatedBy) {
    $priorityParts[] = "CASE WHEN d.created_by = ? THEN 1 ELSE 0 END";
    $recentParams[] = $currentUserId;
    $recentTypes .= 'i';
}

if ($hasDocumentsUpdatedBy) {
    $priorityParts[] = "CASE WHEN d.updated_by = ? THEN 1 ELSE 0 END";
    $recentParams[] = $currentUserId;
    $recentTypes .= 'i';
}

if ($hasDocumentsOwner) {
    $priorityParts[] = "CASE WHEN d.owner_user_id = ? THEN 1 ELSE 0 END";
    $recentParams[] = $currentUserId;
    $recentTypes .= 'i';
}

$priorityOrder = !empty($priorityParts)
    ? "(" . implode(" + ", $priorityParts) . ") DESC,"
    : "";

$recentDocuments = fetch_all($conn, "
    SELECT
        d.id,
        d.document_number,
        {$displayTitleSql} AS title,
        d.current_status,
        " . ($hasDocTypes ? "dt.type_name" : "'Document' AS type_name") . ",
        " . ($hasDvVersionLabel ? "dv.version_label" : "'' AS version_label") . ",
        " . ($hasDvEffectiveDate ? "dv.effective_date" : "NULL AS effective_date") . ",
        " . ($hasDvReviewDate ? "dv.review_date" : "NULL AS review_date") . "
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    " . ($hasDocTypes ? "LEFT JOIN document_types dt ON dt.id = d.document_type_id" : "") . "
    WHERE LOWER(COALESCE(d.current_status,'')) <> 'deleted'
    ORDER BY
        {$priorityOrder}
        CASE WHEN LOWER(COALESCE(d.current_status,'')) = 'draft' THEN 0 ELSE 1 END ASC,
        {$sortDateCol} DESC,
        d.id DESC
    LIMIT 6
", $recentTypes, $recentParams);

/*
|--------------------------------------------------------------------------
| SEARCH DOCUMENTS FROM CREATE DOCUMENT TABLES
|--------------------------------------------------------------------------
*/
$searchDocuments = fetch_all($conn, "
    SELECT
        d.id,
        d.document_number,
        {$titleSelect},
        {$topicSelect},
        {$displayTitleSql} AS display_title,
        d.current_status,
        " . ($hasDocumentsCreatedAt ? "d.created_at" : "NULL AS created_at") . ",
        " . ($hasDocTypes ? "dt.type_name" : "'Document' AS type_name") . ",
        " . ($hasDvVersionLabel ? "dv.version_label" : "'' AS version_label") . ",
        " . ($hasDvEffectiveDate ? "dv.effective_date" : "NULL AS effective_date") . ",
        " . ($hasDvReviewDate ? "dv.review_date" : "NULL AS review_date") . "
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    " . ($hasDocTypes ? "LEFT JOIN document_types dt ON dt.id = d.document_type_id" : "") . "
    WHERE LOWER(COALESCE(d.current_status,'')) <> 'deleted'
    ORDER BY d.document_number ASC, d.id DESC
    LIMIT 1000
");

/*
|--------------------------------------------------------------------------
| REVIEW QUEUE COUNT
|--------------------------------------------------------------------------
*/
$reviewQueueCount = 0;

if ($hasDvReviewDate) {
    $reviewQueueCount = count_query($conn, "
        SELECT COUNT(DISTINCT d.id) AS cnt
        FROM documents d
        INNER JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE dv.review_date IS NOT NULL
          AND dv.review_date <> '0000-00-00'
          AND dv.review_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND LOWER(COALESCE(d.current_status,'')) IN ('effective', 'approved', 'published')
    ");
}

/*
|--------------------------------------------------------------------------
| ACKNOWLEDGEMENT PENDING
|--------------------------------------------------------------------------
*/
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
if (!$searchDocumentsJson) {
    $searchDocumentsJson = '[]';
}
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
    .dashboard-top-row {
      align-items: flex-start !important;
    }

    .dashboard-user-card {
      height: auto !important;
    }

    .dashboard-user-card .card-body {
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      padding-bottom: 1.25rem !important;
    }

    .user-meta-grid {
      margin-bottom: 0 !important;
    }

    .dashboard-hero-stack {
      height: auto !important;
      gap: 1rem !important;
    }

    .search-card .card-body {
      padding-bottom: 1rem !important;
    }

    .hero-carousel {
      min-height: 265px;
      height: 265px !important;
      overflow: hidden;
    }

    .hero-carousel .carousel-item,
    .hero-carousel .hero-banner {
      min-height: 265px;
      height: 265px !important;
    }

    .hero-banner {
      padding: 1.5rem !important;
    }

    .dashboard-search-wrap {
      position: relative;
    }

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

    .dashboard-search-results.show {
      display: block;
    }

    .dashboard-search-item {
      padding: 14px 16px;
      border-bottom: 1px solid #eef2f7;
    }

    .dashboard-search-item:last-child {
      border-bottom: none;
    }

    .dashboard-search-item:hover {
      background: #f8fbff;
    }

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

    .dashboard-search-actions {
      margin-top: 8px;
    }

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

    .recent-doc-box .small {
      font-size: 11px;
    }

    .recent-doc-box .fw-semibold {
      font-size: 13px;
      line-height: 1.35;
      min-height: 34px;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .table td,
    .table th {
      vertical-align: middle;
    }
  </style>
</head>

<body>

<?php include __DIR__ . '/includes/navbar.php'; ?>

<main class="app-shell">
  <div class="content-wrap px-4 px-xxl-5 mx-auto">

    <div class="mb-4 mt-3">
      <h1 class="page-title mb-2">Welcome back, <?php echo e($welcomeName); ?></h1>
      <p class="page-subtitle mb-0">Dashboard data is loaded from created document records: documents, document_versions, and document_types.</p>
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
              <a href="repository.php?filter=owned" class="user-meta-box text-decoration-none" title="View all your documents">
                <div class="user-meta-value"><?php echo (int)$documentsOwned; ?></div>
                <div class="user-meta-label">Documents Owned</div>
              </a>

              <a href="audit-trail.php?tab=approval" class="user-meta-box text-decoration-none" title="View documents pending approval">
                <div class="user-meta-value"><?php echo (int)$pendingApprovals; ?></div>
                <div class="user-meta-label">Pending Approvals</div>
              </a>

              <a href="repository.php?review=overdue" class="user-meta-box text-decoration-none" title="View overdue documents">
                <div class="user-meta-value"><?php echo (int)$overdueReviews; ?></div>
                <div class="user-meta-label">Overdue Reviews</div>
              </a>

              <a href="<?php echo e($alertStatLink); ?>" class="user-meta-box text-decoration-none" title="<?php echo e($alertStatTitle); ?>">
                <div class="user-meta-value"><?php echo (int)$alertStatCount; ?></div>
                <div class="user-meta-label"><?php echo e($alertStatLabel); ?></div>
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
                      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16">
                        <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/>
                      </svg>
                    </span>

                    <input
                      type="text"
                      id="dashboardSearchInput"
                      class="form-control border-start-0 ps-0"
                      placeholder="Search created documents by ID, title, topic, type, or status">
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
                  <a href="repository.php?review=overdue" class="btn btn-light hero-btn">Open Review Queue</a>
                </div>
              </div>

              <div class="carousel-item">
                <div class="hero-banner hero-banner-policy">
                  <div>
                    <div class="hero-eyebrow">Repository Update</div>
                    <h2 class="hero-title"><?php echo (int)$ackPending; ?> acknowledgement assignments are still pending</h2>
                    <p class="hero-copy mb-0">Share latest effective documents and track read confirmation across assigned users.</p>
                  </div>
                  <a href="notifications.php" class="btn btn-light hero-btn">View Notifications</a>
                </div>
              </div>

              <div class="carousel-item">
                <div class="hero-banner hero-banner-search">
                  <div>
                    <div class="hero-eyebrow">Search Tip</div>
                    <h2 class="hero-title">Find created documents faster with ID, topic, title, or type search</h2>
                    <p class="hero-copy mb-0">Search results are loaded directly from the document creation tables.</p>
                  </div>
                  <a href="repository.php" class="btn btn-light hero-btn">Open Repository</a>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <?php if ($pendingApprovals > 0): ?>
      <div class="card cp-card mb-4" id="actionRequiredPanel">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
              <span style="font-size:1.2rem;">⚠️</span>
              <div>
                <h2 class="card-title mb-0" style="color:#b45309;">Action Required — Pending Approvals</h2>
                <p class="card-subtitle mb-0">These submitted documents are waiting for your electronic signature.</p>
              </div>
            </div>

            <div class="d-flex gap-2 align-items-center">
              <a href="audit-trail.php?tab=approval" class="btn btn-sm btn-warning" style="font-weight:600;">View All Pending</a>
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
                <?php foreach ($pendingApprovalRows as $row): ?>
                  <?php
                    $submittedByName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    if ($submittedByName === '') {
                        $submittedByName = $row['email'] ?? '—';
                    }

                    $submittedOn = !empty($row['submitted_at']) ? strtotime($row['submitted_at']) : null;
                    $daysWaiting = $submittedOn ? max(0, floor((time() - $submittedOn) / 86400)) : 0;
                    $daysBadge = $daysWaiting >= 4 ? 'badge-soft-danger' : 'badge-soft-warning';
                  ?>

                  <tr>
                    <td class="fw-semibold" style="color:#2563eb;"><?php echo e($row['document_number']); ?></td>
                    <td><?php echo e($row['title']); ?></td>
                    <td><span class="badge badge-soft-info"><?php echo e($row['type_name'] ?: 'Document'); ?></span></td>
                    <td><?php echo e($submittedByName); ?></td>
                    <td style="color:#6b7280;"><?php echo e($submittedOn ? date('d M Y', $submittedOn) : '—'); ?></td>
                    <td>
                      <span class="badge <?php echo e($daysBadge); ?>">
                        <?php echo (int)$daysWaiting; ?> day<?php echo $daysWaiting !== 1 ? 's' : ''; ?>
                      </span>
                    </td>
                    <td>
                      <a href="audit-trail.php?tab=approval&doc_id=<?php echo urlencode((string)$row['document_number']); ?>"
                         class="btn btn-sm btn-success"
                         style="height:28px;padding:0 12px;font-size:12px;font-weight:600;">
                        Review &amp; Sign
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <?php if (empty($pendingApprovalRows)): ?>
                  <tr>
                    <td colspan="7" class="text-center text-secondary py-3">No pending approval records found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="row g-3 g-xxl-4 mb-4">
      <div class="col-xl-8">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
              <div>
                <h2 class="card-title mb-1">My Work Queue</h2>
                <p class="card-subtitle mb-0">Documents created, owned, updated, or waiting for your approval.</p>
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
                      <tr>
                        <td class="fw-semibold"><?php echo e($row['document_number']); ?></td>
                        <td><?php echo e($row['title']); ?></td>
                        <td><?php echo e($row['queue_task'] ?? 'Owner Task'); ?></td>
                        <td><?php echo status_badge((string)($row['current_status'] ?? 'draft')); ?></td>
                        <td><?php echo e(displayDate($row['review_date'] ?? '')); ?></td>
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
                    if ($actor === '') {
                        $actor = $row['email'] ?? 'System';
                    }

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
                <h2 class="card-title mb-1">Recent Created Documents</h2>
                <p class="card-subtitle mb-0">Loaded from documents + document_versions + document_types.</p>
              </div>

              <a href="repository.php" class="btn btn-sm btn-outline-primary">Open Repository</a>
            </div>

            <div class="row g-2 recent-docs-row">
              <?php if (!empty($recentDocuments)): ?>
                <?php foreach (array_slice($recentDocuments, 0, 6) as $row): ?>
                  <div class="col-auto">
                    <div class="repo-box recent-doc-box h-100">
                      <div class="small text-secondary mb-2"><?php echo e($row['type_name'] ?: 'Document'); ?></div>
                      <div class="fw-semibold mb-2"><?php echo e($row['title']); ?></div>
                      <div class="small text-secondary mb-2"><?php echo e($row['document_number']); ?></div>
                      <?php echo status_badge((string)($row['current_status'] ?? 'draft')); ?>
                    </div>
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
            <p class="card-subtitle mb-3">Start common document-control tasks.</p>

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
  const panel = document.getElementById('actionRequiredPanel');
  if (panel) {
    panel.style.display = 'none';
    sessionStorage.setItem('actionPanelDismissed', '1');
  }
}

if (sessionStorage.getItem('actionPanelDismissed') === '1') {
  const panel = document.getElementById('actionRequiredPanel');
  if (panel) {
    panel.style.display = 'none';
  }
}

const DASHBOARD_DOCUMENTS = <?php echo $searchDocumentsJson; ?>;
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
  if (isNaN(d.getTime())) {
    return str;
  }

  return d.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  });
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
    const displayTitle = String(doc.display_title || '').toLowerCase();
    const type = String(doc.type_name || '').toLowerCase();
    const status = String(doc.current_status || '').toLowerCase();

    if (docNo === q) {
      exactMatches.push(doc);
    } else if (
      docNo.indexOf(q) !== -1 ||
      title.indexOf(q) !== -1 ||
      topic.indexOf(q) !== -1 ||
      displayTitle.indexOf(q) !== -1 ||
      type.indexOf(q) !== -1 ||
      status.indexOf(q) !== -1
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
    const documentNumber = doc.document_number || '';
    const title = doc.display_title || doc.title || doc.topic || 'Untitled Document';

    return '' +
      '<div class="dashboard-search-item">' +
        '<div class="dashboard-search-docid">' + escapeHtml(documentNumber) + '</div>' +
        '<div class="dashboard-search-title">' + escapeHtml(title) + '</div>' +
        '<div class="dashboard-search-meta">' +
          'Type: ' + escapeHtml(doc.type_name || 'Document') + '<br>' +
          'Version: ' + escapeHtml(doc.version_label || '—') + ' &nbsp;|&nbsp; ' +
          'Status: ' + escapeHtml(doc.current_status || '—') + '<br>' +
          'Effective Date: ' + escapeHtml(formatDocDate(doc.effective_date)) + ' &nbsp;|&nbsp; ' +
          'Review Date: ' + escapeHtml(formatDocDate(doc.review_date)) +
        '</div>' +
        '<div class="dashboard-search-actions">' +
          '<a href="repository.php?search=' + encodeURIComponent(documentNumber) + '" class="btn btn-sm btn-outline-primary">Open in Repository</a>' +
        '</div>' +
      '</div>';
  }).join('');

  dashboardSearchResults.classList.add('show');
}

if (dashboardSearchInput && dashboardSearchResults) {
  dashboardSearchInput.addEventListener('input', function() {
    renderSearchResults(this.value);
  });

  dashboardSearchInput.addEventListener('focus', function() {
    renderSearchResults(this.value);
  });

  dashboardSearchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();

      const q = String(this.value || '').trim();

      if (q !== '') {
        window.location.href = 'repository.php?search=' + encodeURIComponent(q);
      }
    }
  });

  document.addEventListener('click', function(e) {
    if (!dashboardSearchResults.contains(e.target) && e.target !== dashboardSearchInput) {
      dashboardSearchResults.classList.remove('show');
    }
  });
}
</script>

</body>
</html>