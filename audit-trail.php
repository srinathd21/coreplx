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

$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo   = trim((string)($_GET['to'] ?? ''));
$filterUser = (int)($_GET['user_id'] ?? 0);
$filterDoc  = trim((string)($_GET['doc_id'] ?? ''));
$activeTab  = trim((string)($_GET['tab'] ?? 'creation'));

if (!in_array($activeTab, ['creation', 'approval', 'comments', 'users'], true)) {
    $activeTab = 'creation';
}

$exportType = trim((string)($_GET['export'] ?? ''));

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

$hasWorkflowSteps = tableExists($conn, 'workflow_steps');
$hasDocuments = tableExists($conn, 'documents');
$hasDocumentVersions = tableExists($conn, 'document_versions');
$hasDocumentTypes = tableExists($conn, 'document_types');
$hasAuditLogs = tableExists($conn, 'audit_logs');
$hasDocComments = tableExists($conn, 'document_comments');
$hasElectronicSignatures = tableExists($conn, 'electronic_signatures');
$hasLoginAttempts = tableExists($conn, 'login_attempts');
$hasDocumentsApprover = $hasDocuments && columnExists($conn, 'documents', 'approver');

function build_common_filters(string $dateField, string $userField, string $docNumberField, array &$params, string &$types, string $filterFrom, string $filterTo, int $filterUser, string $filterDoc): string
{
    $where = " WHERE 1=1 ";

    if ($filterFrom !== '') {
        $where .= " AND DATE($dateField) >= ? ";
        $types .= 's';
        $params[] = $filterFrom;
    }

    if ($filterTo !== '') {
        $where .= " AND DATE($dateField) <= ? ";
        $types .= 's';
        $params[] = $filterTo;
    }

    if ($filterUser > 0 && $userField !== '') {
        $where .= " AND $userField = ? ";
        $types .= 'i';
        $params[] = $filterUser;
    }

    if ($filterDoc !== '' && $docNumberField !== '') {
        $like = '%' . $filterDoc . '%';
        $where .= " AND $docNumberField LIKE ? ";
        $types .= 's';
        $params[] = $like;
    }

    return $where;
}

function execute_prepared_query(mysqli $conn, string $sql, string $types = '', array $params = []): array
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
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/* ---------------------------------------------------------
   USER FILTER OPTIONS
--------------------------------------------------------- */
$userOptions = [];
$userSql = "
    SELECT id, first_name, last_name, email
    FROM users
    WHERE status IN ('active','inactive','locked','suspended')
    ORDER BY first_name ASC, last_name ASC, email ASC
";
$userResult = mysqli_query($conn, $userSql);
if ($userResult) {
    while ($row = mysqli_fetch_assoc($userResult)) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($name === '') {
            $name = (string)$row['email'];
        }
        $row['display_name'] = $name;
        $userOptions[] = $row;
    }
    mysqli_free_result($userResult);
}

/* ---------------------------------------------------------
   TAB 1 - DOCUMENT CREATION
--------------------------------------------------------- */
$creationRows = [];
if ($hasAuditLogs && $hasDocuments) {
    $creationParams = [];
    $creationTypes = '';
    $creationWhere = build_common_filters(
        'al.performed_at',
        'al.performed_by',
        'd.document_number',
        $creationParams,
        $creationTypes,
        $filterFrom,
        $filterTo,
        $filterUser,
        $filterDoc
    );

    $creationWhere .= " AND al.entity_type = 'document' AND al.action IN ('create','draft_save','save_draft','submit','revision_submit','submit_review') ";

    $creationSql = "
        SELECT
            al.id,
            al.performed_at,
            al.action,
            al.ip_address,
            al.remarks,
            al.new_value,
            al.old_value,
            al.entity_id,
            d.document_number,
            dv.version_label,
            u.first_name,
            u.last_name,
            u.email
        FROM audit_logs al
        LEFT JOIN documents d
            ON d.id = al.entity_id
        LEFT JOIN document_versions dv
            ON dv.id = COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(al.new_value, '$.document_version_id')),
                JSON_UNQUOTE(JSON_EXTRACT(al.old_value, '$.document_version_id'))
            )
        LEFT JOIN users u
            ON u.id = al.performed_by
        $creationWhere
        ORDER BY al.performed_at DESC, al.id DESC
    ";
    $creationRows = execute_prepared_query($conn, $creationSql, $creationTypes, $creationParams);
}

/* ---------------------------------------------------------
   TAB 2 - APPROVAL / DENIAL / PENDING
--------------------------------------------------------- */
$approvalRows = [];

/* A. LIVE PENDING APPROVALS FROM WORKFLOW_STEPS */
$pendingWorkflowRows = [];
if ($hasWorkflowSteps && $hasDocuments && $hasDocumentVersions) {
    $pendingParams = [];
    $pendingTypes = '';
    $pendingWhere = build_common_filters(
        'COALESCE(dv.submitted_at, ws.due_at)',
        'ws.approver_user_id',
        'd.document_number',
        $pendingParams,
        $pendingTypes,
        $filterFrom,
        $filterTo,
        $filterUser > 0 ? $filterUser : $currentUserId,
        $filterDoc
    );

    $pendingWhere .= " AND ws.status = 'pending' ";

    $pendingSql = "
        SELECT
            ws.id,
            COALESCE(dv.submitted_at, ws.due_at, dv.created_at) AS event_time,
            'pending' AS action,
            'pending' AS comment_type,
            'pending' AS decision,
            '' AS comment_text,
            ws.approver_user_id AS created_by,
            ws.document_version_id,
            ws.id AS workflow_step_id,
            d.document_number,
            dv.version_label,
            u.first_name,
            u.last_name,
            u.email,
            '' AS sign_action,
            NULL AS signed_at,
            '' AS sign_ip_address,
            '' AS signature_reason,
            'pending_workflow' AS source_type,
            d.title AS document_title,
            dt.type_name,
            submitter.first_name AS submitter_first_name,
            submitter.last_name AS submitter_last_name,
            submitter.email AS submitter_email,
            ws.due_at
        FROM workflow_steps ws
        INNER JOIN document_versions dv
            ON dv.id = ws.document_version_id
        INNER JOIN documents d
            ON d.id = dv.document_id
        LEFT JOIN document_types dt
            ON dt.id = d.document_type_id
        LEFT JOIN users u
            ON u.id = ws.approver_user_id
        LEFT JOIN users submitter
            ON submitter.id = dv.submitted_by
        $pendingWhere
        ORDER BY event_time DESC, ws.id DESC
    ";
    $pendingWorkflowRows = execute_prepared_query($conn, $pendingSql, $pendingTypes, $pendingParams);
}

/* B. FALLBACK PENDING APPROVALS FROM DOCUMENTS.APPROVER */
$pendingFallbackRows = [];
if ($hasDocuments && $hasDocumentVersions && $hasDocumentsApprover) {
    $fallbackParams = [];
    $fallbackTypes = '';
    $fallbackWhere = build_common_filters(
        'dv.submitted_at',
        '',
        'd.document_number',
        $fallbackParams,
        $fallbackTypes,
        $filterFrom,
        $filterTo,
        0,
        $filterDoc
    );

    $approverUserToCheck = $filterUser > 0 ? $filterUser : $currentUserId;

    $fallbackWhere .= " AND d.current_status = 'pending_approval'
                        AND dv.status = 'pending_approval'
                        AND TRIM(COALESCE(d.approver, '')) = ?
                    ";
    $fallbackTypes .= 's';
    $fallbackParams[] = (string)$approverUserToCheck;

    if ($hasWorkflowSteps) {
        $fallbackWhere .= " AND NOT EXISTS (
            SELECT 1
            FROM workflow_steps ws2
            WHERE ws2.document_version_id = d.current_version_id
              AND ws2.approver_user_id = ?
              AND ws2.status = 'pending'
        ) ";
        $fallbackTypes .= 'i';
        $fallbackParams[] = $approverUserToCheck;
    }

    $fallbackSql = "
        SELECT
            d.id,
            COALESCE(dv.submitted_at, dv.created_at) AS event_time,
            'pending' AS action,
            'pending' AS comment_type,
            'pending' AS decision,
            '' AS comment_text,
            ? AS created_by,
            dv.id AS document_version_id,
            NULL AS workflow_step_id,
            d.document_number,
            dv.version_label,
            u.first_name,
            u.last_name,
            u.email,
            '' AS sign_action,
            NULL AS signed_at,
            '' AS sign_ip_address,
            '' AS signature_reason,
            'pending_fallback' AS source_type,
            d.title AS document_title,
            dt.type_name,
            submitter.first_name AS submitter_first_name,
            submitter.last_name AS submitter_last_name,
            submitter.email AS submitter_email,
            NULL AS due_at
        FROM documents d
        INNER JOIN document_versions dv
            ON dv.id = d.current_version_id
        LEFT JOIN document_types dt
            ON dt.id = d.document_type_id
        LEFT JOIN users u
            ON u.id = ?
        LEFT JOIN users submitter
            ON submitter.id = dv.submitted_by
        $fallbackWhere
        ORDER BY event_time DESC, d.id DESC
    ";
    $pendingFallbackRows = execute_prepared_query(
        $conn,
        $fallbackSql,
        'ii' . $fallbackTypes,
        array_merge([$approverUserToCheck, $approverUserToCheck], $fallbackParams)
    );
}

/* C. COMPLETED HISTORY FROM DOCUMENT_COMMENTS */
$approvalRows1 = [];
if ($hasDocComments && $hasDocuments && $hasDocumentVersions) {
    $approvalParams1 = [];
    $approvalTypes1 = '';
    $approvalWhere1 = build_common_filters(
        'dc.created_at',
        'dc.created_by',
        'd.document_number',
        $approvalParams1,
        $approvalTypes1,
        $filterFrom,
        $filterTo,
        $filterUser,
        $filterDoc
    );

    $approvalWhere1 .= " AND dc.comment_type IN ('approval','rejection','return') ";

    $approvalSql1 = "
        SELECT
            dc.id,
            dc.created_at AS event_time,
            dc.comment_type,
            dc.decision,
            dc.comment_text,
            dc.created_by,
            dc.document_version_id,
            dc.workflow_step_id,
            d.document_number,
            dv.version_label,
            u.first_name,
            u.last_name,
            u.email,
            es.sign_action,
            es.signed_at,
            es.ip_address AS sign_ip_address,
            es.signature_reason,
            'comment' AS source_type,
            d.title AS document_title,
            dt.type_name,
            '' AS submitter_first_name,
            '' AS submitter_last_name,
            '' AS submitter_email,
            NULL AS due_at,
            '' AS action
        FROM document_comments dc
        INNER JOIN document_versions dv
            ON dv.id = dc.document_version_id
        INNER JOIN documents d
            ON d.id = dv.document_id
        LEFT JOIN document_types dt
            ON dt.id = d.document_type_id
        LEFT JOIN users u
            ON u.id = dc.created_by
        LEFT JOIN electronic_signatures es
            ON es.document_version_id = dc.document_version_id
           AND es.workflow_step_id = dc.workflow_step_id
           AND es.signed_by = dc.created_by
        $approvalWhere1
        ORDER BY dc.created_at DESC, dc.id DESC
    ";
    $approvalRows1 = execute_prepared_query($conn, $approvalSql1, $approvalTypes1, $approvalParams1);
}

/* D. COMPLETED HISTORY FROM AUDIT_LOGS */
$approvalRows2 = [];
if ($hasAuditLogs && $hasDocuments) {
    $approvalParams2 = [];
    $approvalTypes2 = '';
    $approvalWhere2 = build_common_filters(
        'al.performed_at',
        'al.performed_by',
        'd.document_number',
        $approvalParams2,
        $approvalTypes2,
        $filterFrom,
        $filterTo,
        $filterUser,
        $filterDoc
    );

    $approvalWhere2 .= " AND al.entity_type = 'document' AND al.action IN ('approve','reject','return') ";

    $approvalSql2 = "
        SELECT
            al.id,
            al.performed_at AS event_time,
            al.action,
            '' AS comment_type,
            '' AS decision,
            al.remarks AS comment_text,
            al.performed_by AS created_by,
            COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(al.new_value, '$.document_version_id')),
                JSON_UNQUOTE(JSON_EXTRACT(al.old_value, '$.document_version_id'))
            ) AS document_version_id,
            NULL AS workflow_step_id,
            d.document_number,
            dv.version_label,
            u.first_name,
            u.last_name,
            u.email,
            '' AS sign_action,
            NULL AS signed_at,
            al.ip_address AS sign_ip_address,
            '' AS signature_reason,
            'audit' AS source_type,
            d.title AS document_title,
            dt.type_name,
            '' AS submitter_first_name,
            '' AS submitter_last_name,
            '' AS submitter_email,
            NULL AS due_at
        FROM audit_logs al
        LEFT JOIN documents d
            ON d.id = al.entity_id
        LEFT JOIN document_versions dv
            ON dv.id = COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(al.new_value, '$.document_version_id')),
                JSON_UNQUOTE(JSON_EXTRACT(al.old_value, '$.document_version_id'))
            )
        LEFT JOIN document_types dt
            ON dt.id = d.document_type_id
        LEFT JOIN users u
            ON u.id = al.performed_by
        $approvalWhere2
        ORDER BY al.performed_at DESC, al.id DESC
    ";
    $approvalRows2 = execute_prepared_query($conn, $approvalSql2, $approvalTypes2, $approvalParams2);
}

$approvalRows = array_merge($pendingWorkflowRows, $pendingFallbackRows, $approvalRows1, $approvalRows2);

/* Deduplicate same pending version appearing twice */
$seenPendingVersions = [];
$approvalRowsDeduped = [];
foreach ($approvalRows as $row) {
    $sourceType = (string)($row['source_type'] ?? '');
    $versionId = (int)($row['document_version_id'] ?? 0);

    if (in_array($sourceType, ['pending_workflow', 'pending_fallback'], true)) {
        if ($versionId > 0) {
            if (isset($seenPendingVersions[$versionId])) {
                continue;
            }
            $seenPendingVersions[$versionId] = true;
        }
    }

    $approvalRowsDeduped[] = $row;
}
$approvalRows = $approvalRowsDeduped;

usort($approvalRows, function ($a, $b) {
    return strtotime((string)($b['event_time'] ?? '')) <=> strtotime((string)($a['event_time'] ?? ''));
});

/* ---------------------------------------------------------
   TAB 3 - APPROVER COMMENTS
--------------------------------------------------------- */
$commentRows = [];
if ($hasDocComments && $hasDocuments && $hasDocumentVersions) {
    $commentParams = [];
    $commentTypes = '';
    $commentWhere = build_common_filters(
        'dc.created_at',
        'dc.created_by',
        'd.document_number',
        $commentParams,
        $commentTypes,
        $filterFrom,
        $filterTo,
        $filterUser,
        $filterDoc
    );

    $commentWhere .= " AND dc.comment_type IN ('general','review') ";

    $commentSql = "
        SELECT
            dc.id,
            dc.created_at,
            dc.comment_type,
            dc.comment_text,
            dc.created_by,
            dc.document_version_id,
            d.document_number,
            dv.version_label,
            u.first_name,
            u.last_name,
            u.email,
            al.ip_address
        FROM document_comments dc
        INNER JOIN document_versions dv
            ON dv.id = dc.document_version_id
        INNER JOIN documents d
            ON d.id = dv.document_id
        LEFT JOIN users u
            ON u.id = dc.created_by
        LEFT JOIN audit_logs al
            ON al.entity_type = 'document'
           AND al.performed_by = dc.created_by
           AND JSON_UNQUOTE(JSON_EXTRACT(al.new_value, '$.document_version_id')) = CAST(dc.document_version_id AS CHAR)
           AND DATE(al.performed_at) = DATE(dc.created_at)
        $commentWhere
        ORDER BY dc.created_at DESC, dc.id DESC
    ";
    $commentRows = execute_prepared_query($conn, $commentSql, $commentTypes, $commentParams);
}

/* ---------------------------------------------------------
   TAB 4 - USER ACTIVITY
--------------------------------------------------------- */
$userActivityRows = [];

$userAuditRows = [];
if ($hasAuditLogs) {
    $userAuditParams = [];
    $userAuditTypes = '';
    $userAuditWhere = " WHERE 1=1 ";

    if ($filterFrom !== '') {
        $userAuditWhere .= " AND DATE(al.performed_at) >= ? ";
        $userAuditTypes .= 's';
        $userAuditParams[] = $filterFrom;
    }
    if ($filterTo !== '') {
        $userAuditWhere .= " AND DATE(al.performed_at) <= ? ";
        $userAuditTypes .= 's';
        $userAuditParams[] = $filterTo;
    }
    if ($filterUser > 0) {
        $userAuditWhere .= " AND al.performed_by = ? ";
        $userAuditTypes .= 'i';
        $userAuditParams[] = $filterUser;
    }

    $userAuditWhere .= " AND (
        al.entity_type = 'user'
        OR al.entity_type = 'auth'
        OR al.entity_type = 'system_limits'
        OR al.entity_type = 'department'
        OR al.entity_type = 'department_caps'
        OR (al.entity_type = 'system_setting' AND al.action IN ('create','update'))
    ) ";

    $userAuditSql = "
        SELECT
            al.id,
            al.performed_at AS event_time,
            al.action,
            al.entity_type,
            al.entity_id,
            al.old_value,
            al.new_value,
            al.remarks,
            al.ip_address,
            u.first_name,
            u.last_name,
            u.email,
            target.first_name AS target_first_name,
            target.last_name AS target_last_name,
            target.email AS target_email
        FROM audit_logs al
        LEFT JOIN users u
            ON u.id = al.performed_by
        LEFT JOIN users target
            ON target.id = al.entity_id AND al.entity_type = 'user'
        $userAuditWhere
        ORDER BY al.performed_at DESC, al.id DESC
    ";
    $userAuditRows = execute_prepared_query($conn, $userAuditSql, $userAuditTypes, $userAuditParams);
}

$loginRows = [];
if ($hasLoginAttempts) {
    $loginParams = [];
    $loginTypes = '';
    $loginWhere = " WHERE 1=1 ";

    if ($filterFrom !== '') {
        $loginWhere .= " AND DATE(la.attempted_at) >= ? ";
        $loginTypes .= 's';
        $loginParams[] = $filterFrom;
    }
    if ($filterTo !== '') {
        $loginWhere .= " AND DATE(la.attempted_at) <= ? ";
        $loginTypes .= 's';
        $loginParams[] = $filterTo;
    }
    if ($filterUser > 0) {
        $loginWhere .= " AND la.user_id = ? ";
        $loginTypes .= 'i';
        $loginParams[] = $filterUser;
    }

    $loginSql = "
        SELECT
            la.id,
            la.attempted_at AS event_time,
            la.attempt_status,
            la.failure_reason,
            la.email AS login_email,
            la.ip_address,
            la.portal_type,
            u.first_name,
            u.last_name,
            u.email
        FROM login_attempts la
        LEFT JOIN users u
            ON u.id = la.user_id
        $loginWhere
        ORDER BY la.attempted_at DESC, la.id DESC
    ";
    $loginRows = execute_prepared_query($conn, $loginSql, $loginTypes, $loginParams);
}

foreach ($userAuditRows as $row) {
    $userActivityRows[] = [
        'event_time' => $row['event_time'] ?? '',
        'user_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        'user_email' => $row['email'] ?? '',
        'activity_type' => 'audit',
        'action' => $row['action'] ?? '',
        'entity_type' => $row['entity_type'] ?? '',
        'target_name' => trim(($row['target_first_name'] ?? '') . ' ' . ($row['target_last_name'] ?? '')),
        'target_email' => $row['target_email'] ?? '',
        'remarks' => $row['remarks'] ?? '',
        'ip_address' => $row['ip_address'] ?? '',
    ];
}

foreach ($loginRows as $row) {
    $label = 'Login Attempt';
    if (($row['attempt_status'] ?? '') === 'success') {
        $label = 'Login Success';
    } elseif (($row['attempt_status'] ?? '') === 'failed') {
        $label = 'Login Failed';
    } elseif (($row['attempt_status'] ?? '') === 'blocked') {
        $label = 'Login Blocked';
    }

    $userActivityRows[] = [
        'event_time' => $row['event_time'] ?? '',
        'user_name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
        'user_email' => $row['email'] ?: ($row['login_email'] ?? ''),
        'activity_type' => 'login',
        'action' => $label,
        'entity_type' => 'login_attempt',
        'target_name' => '',
        'target_email' => '',
        'remarks' => $row['failure_reason'] ?: ('Portal: ' . ($row['portal_type'] ?? '')),
        'ip_address' => $row['ip_address'] ?? '',
    ];
}

usort($userActivityRows, function ($a, $b) {
    return strtotime((string)$b['event_time']) <=> strtotime((string)$a['event_time']);
});

/* ---------------------------------------------------------
   CSV EXPORT
--------------------------------------------------------- */
if ($exportType === 'csv') {
    $filename = 'audit-trail-' . $activeTab . '-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    if ($activeTab === 'creation') {
        fputcsv($out, ['Timestamp', 'User', 'Action', 'Document ID', 'Version', 'IP Address', 'Remarks']);
        foreach ($creationRows as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($name === '') {
                $name = (string)($row['email'] ?? 'System');
            }
            fputcsv($out, [
                $row['performed_at'] ?? '',
                $name,
                $row['action'] ?? '',
                $row['document_number'] ?? '',
                $row['version_label'] ?? '',
                $row['ip_address'] ?? '',
                $row['remarks'] ?? ''
            ]);
        }
    } elseif ($activeTab === 'approval') {
        fputcsv($out, ['Timestamp', 'User', 'Meaning', 'Document ID', 'Version', 'Reason', 'IP Address']);

        foreach ($approvalRows as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($name === '') {
                $name = (string)($row['email'] ?? 'System');
            }

            $meaning = '';
            $sourceType = (string)($row['source_type'] ?? '');

            if (in_array($sourceType, ['pending_workflow', 'pending_fallback'], true)) {
                $meaning = 'pending';
            } elseif ($sourceType === 'audit') {
                $meaning = strtolower((string)($row['action'] ?? ''));
            } else {
                $meaning = (string)($row['decision'] ?: $row['comment_type']);
            }

            if ($meaning === 'approve' || $meaning === 'approval') $meaning = 'approved';
            if ($meaning === 'reject' || $meaning === 'rejection') $meaning = 'rejected';
            if ($meaning === 'return') $meaning = 'returned';

            $reason = $row['comment_text'] ?? '';
            if ($reason === '' && ($row['source_type'] ?? '') === 'comment') {
                $reason = $row['signature_reason'] ?? '';
            }
            if ($reason === '' && in_array($sourceType, ['pending_workflow', 'pending_fallback'], true)) {
                $submitter = trim(($row['submitter_first_name'] ?? '') . ' ' . ($row['submitter_last_name'] ?? ''));
                if ($submitter === '') {
                    $submitter = (string)($row['submitter_email'] ?? 'Unknown User');
                }
                $reason = 'Pending approval for approver. Submitted by ' . $submitter;
            }

            fputcsv($out, [
                $row['event_time'] ?? '',
                $name,
                ucfirst($meaning),
                $row['document_number'] ?? '',
                $row['version_label'] ?? '',
                $reason,
                $row['sign_ip_address'] ?? ''
            ]);
        }
    } elseif ($activeTab === 'comments') {
        fputcsv($out, ['Timestamp', 'User', 'Document ID', 'Version', 'Comment', 'IP Address']);
        foreach ($commentRows as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($name === '') {
                $name = (string)($row['email'] ?? 'System');
            }
            fputcsv($out, [
                $row['created_at'] ?? '',
                $name,
                $row['document_number'] ?? '',
                $row['version_label'] ?? '',
                $row['comment_text'] ?? '',
                $row['ip_address'] ?? ''
            ]);
        }
    } else {
        fputcsv($out, ['Timestamp', 'User', 'Action', 'Target', 'Details', 'IP Address']);
        foreach ($userActivityRows as $row) {
            $name = trim((string)$row['user_name']);
            if ($name === '') {
                $name = (string)$row['user_email'];
            }

            $target = trim((string)$row['target_name']);
            if ($target === '') {
                $target = (string)$row['target_email'];
            }

            fputcsv($out, [
                $row['event_time'] ?? '',
                $name,
                $row['action'] ?? '',
                $target,
                $row['remarks'] ?? '',
                $row['ip_address'] ?? ''
            ]);
        }
    }

    fclose($out);
    exit;
}

function action_badge_html(string $action): string
{
    $action = strtolower(trim($action));

    if (in_array($action, ['create', 'created'], true)) {
        return '<span class="badge badge-soft-info">Created</span>';
    }
    if (in_array($action, ['draft_save', 'save_draft', 'draft saved'], true)) {
        return '<span class="badge badge-soft-secondary">Draft Saved</span>';
    }
    if (in_array($action, ['submit', 'revision_submit', 'submit_review'], true)) {
        return '<span class="badge badge-soft-warning">Submitted</span>';
    }
    if (in_array($action, ['approve', 'approved', 'approval', 'login_success'], true)) {
        return '<span class="badge badge-soft-success">Approved</span>';
    }
    if (in_array($action, ['reject', 'rejected', 'rejection', 'login_failed'], true)) {
        return '<span class="badge badge-soft-danger">Denied</span>';
    }
    if (in_array($action, ['return', 'returned'], true)) {
        return '<span class="badge badge-soft-secondary">Returned</span>';
    }
    if (in_array($action, ['pending'], true)) {
        return '<span class="badge badge-soft-warning">Pending</span>';
    }

    if (in_array($action, ['activate'], true)) {
        return '<span class="badge badge-soft-success">Activated</span>';
    }
    if (in_array($action, ['deactivate'], true)) {
        return '<span class="badge badge-soft-danger">Deactivated</span>';
    }
    if (in_array($action, ['update'], true)) {
        return '<span class="badge badge-soft-info">Updated</span>';
    }
    if (in_array($action, ['login success'], true)) {
        return '<span class="badge badge-soft-success">Login Success</span>';
    }
    if (in_array($action, ['login failed'], true)) {
        return '<span class="badge badge-soft-danger">Login Failed</span>';
    }
    if (in_array($action, ['login blocked'], true)) {
        return '<span class="badge badge-soft-warning">Login Blocked</span>';
    }

    return '<span class="badge badge-soft-info">' . e(ucwords(str_replace('_', ' ', $action))) . '</span>';
}

function format_name(array $row): string
{
    $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return (string)($row['email'] ?? 'System');
}

function format_dt(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if (!$ts) return e($dt);
    return date('d M Y H:i', $ts);
}

function user_activity_badge_html(string $action): string
{
    $key = strtolower(trim($action));

    if ($key === 'login success') return '<span class="badge badge-soft-success">Login Success</span>';
    if ($key === 'login failed') return '<span class="badge badge-soft-danger">Login Failed</span>';
    if ($key === 'login blocked') return '<span class="badge badge-soft-warning">Login Blocked</span>';
    if ($key === 'create') return '<span class="badge badge-soft-info">User Created</span>';
    if ($key === 'update') return '<span class="badge badge-soft-info">Updated</span>';
    if ($key === 'activate') return '<span class="badge badge-soft-success">Activated</span>';
    if ($key === 'deactivate') return '<span class="badge badge-soft-danger">Deactivated</span>';

    return '<span class="badge badge-soft-secondary">' . e(ucwords(str_replace('_', ' ', $action))) . '</span>';
}

$queryBase = [
    'from' => $filterFrom,
    'to' => $filterTo,
    'user_id' => $filterUser > 0 ? $filterUser : '',
    'doc_id' => $filterDoc,
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Audit Trail</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .audit-tab-strip {
      display:flex;
      gap:4px;
      border-bottom:2px solid #e0e7ef;
      margin-bottom:20px;
      flex-wrap:wrap;
    }
    .audit-tab {
      padding:10px 22px;
      font-size:13px;
      font-weight:600;
      color:#6b7280;
      border:none;
      background:none;
      border-bottom:3px solid transparent;
      margin-bottom:-2px;
      cursor:pointer;
      transition:color .15s, border-color .15s;
      text-decoration:none;
      display:inline-block;
    }
    .audit-tab:hover { color:#1a3a6e; }
    .audit-tab.active { color:#1a3a6e; border-bottom-color:#2563eb; }

    .audit-filter-grid {
      display: grid;
      grid-template-columns: 150px 150px 220px 170px auto;
      gap: 12px;
      align-items: end;
    }
    .audit-filter-field label {
      display:block;
      font-size:12px;
      font-weight:600;
      color:#5f6b7a;
      margin-bottom:6px;
    }
    .audit-filter-field .form-control-sm,
    .audit-filter-field .form-select-sm {
      height:40px;
      border-radius:8px;
      font-size:14px;
    }
    .audit-filter-actions {
      display:flex;
      align-items:end;
      gap:8px;
      flex-wrap:wrap;
      justify-content:flex-start;
    }
    .audit-filter-actions .btn {
      height:40px;
      border-radius:8px;
      padding:0 16px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:14px;
      font-weight:600;
      white-space:nowrap;
    }

    @media (max-width: 1200px) {
      .audit-filter-grid {
        grid-template-columns: 1fr 1fr;
      }
      .audit-filter-actions {
        grid-column: 1 / -1;
      }
    }

    @media (max-width: 768px) {
      .audit-filter-grid {
        grid-template-columns: 1fr;
      }
      .audit-filter-actions {
        width:100%;
      }
      .audit-filter-actions .btn {
        flex:1 1 auto;
      }
    }
  </style>
</head>
<body>
<?php include('includes/navbar.php'); ?>
<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <div class="mb-4">
    <h1 class="page-title mb-2">Audit Trail</h1>
    <p class="page-subtitle mb-0">Immutable records of all document creation, approval, denial, comment, and user activity. Read-only — cannot be edited or deleted by any role.</p>
  </div>

  <div class="audit-tab-strip">
    <a class="audit-tab <?php echo $activeTab === 'creation' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($queryBase, ['tab' => 'creation'])); ?>" id="tabCreation">Document Creation</a>
    <a class="audit-tab <?php echo $activeTab === 'approval' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($queryBase, ['tab' => 'approval'])); ?>" id="tabApproval">Approval &amp; Denial</a>
    <a class="audit-tab <?php echo $activeTab === 'comments' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($queryBase, ['tab' => 'comments'])); ?>" id="tabComments">Approver Comments</a>
    <a class="audit-tab <?php echo $activeTab === 'users' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($queryBase, ['tab' => 'users'])); ?>" id="tabUsers">User Activity</a>
  </div>

  <div class="card cp-card mb-3">
    <div class="card-body py-3">
      <form method="get">
        <input type="hidden" name="tab" value="<?php echo e($activeTab); ?>">

        <div class="audit-filter-grid">
          <div class="audit-filter-field">
            <label class="form-label mb-1">From</label>
            <input class="form-control form-control-sm" type="date" name="from" value="<?php echo e($filterFrom); ?>">
          </div>

          <div class="audit-filter-field">
            <label class="form-label mb-1">To</label>
            <input class="form-control form-control-sm" type="date" name="to" value="<?php echo e($filterTo); ?>">
          </div>

          <div class="audit-filter-field">
            <label class="form-label mb-1">User</label>
            <select class="form-select form-select-sm" name="user_id">
              <option value="">All Users</option>
              <?php foreach ($userOptions as $user): ?>
                <option value="<?php echo (int)$user['id']; ?>" <?php echo $filterUser === (int)$user['id'] ? 'selected' : ''; ?>>
                  <?php echo e($user['display_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="audit-filter-field">
            <label class="form-label mb-1">Document ID</label>
            <input class="form-control form-control-sm" type="text" name="doc_id" value="<?php echo e($filterDoc); ?>" placeholder="e.g. SOP-104">
          </div>

          <div class="audit-filter-actions">
            <button class="btn btn-primary btn-sm" type="submit">Apply</button>
            <a class="btn btn-outline-secondary btn-sm" href="?<?php echo http_build_query(['tab' => $activeTab]); ?>">Reset</a>
            <a class="btn btn-outline-primary btn-sm" href="?<?php echo http_build_query(array_merge($queryBase, ['tab' => $activeTab, 'export' => 'csv'])); ?>">&#11015; Export CSV</a>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div id="panelCreation" class="<?php echo $activeTab === 'creation' ? '' : 'd-none'; ?>">
    <div class="card cp-card" style="padding:0;">
      <div class="px-4 py-3 border-bottom">
        <div class="fw-bold" style="color:#1a3a6e;font-size:14px;">Document Creation Events</div>
        <div class="text-secondary" style="font-size:12px;">Captures: who created, when, which document, IP address.</div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Action</th>
              <th>Document ID</th>
              <th>Version</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($creationRows)): ?>
              <?php foreach ($creationRows as $row): ?>
                <tr>
                  <td style="font-size:12px;color:#6b7280;"><?php echo e(format_dt($row['performed_at'] ?? '')); ?></td>
                  <td><?php echo e(format_name($row)); ?></td>
                  <td><?php echo action_badge_html((string)($row['action'] ?? '')); ?></td>
                  <td class="fw-semibold text-primary"><?php echo e($row['document_number'] ?: '—'); ?></td>
                  <td><?php echo e($row['version_label'] ? 'V' . $row['version_label'] : '—'); ?></td>
                  <td style="font-size:12px;color:#6b7280;"><?php echo e($row['ip_address'] ?: '—'); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="text-center text-secondary py-4">No creation records found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-2 border-top" style="font-size:12px;color:#dc2626;font-weight:600;">
        &#128274; Read-only — records cannot be edited or deleted by any role including Super Admin.
      </div>
    </div>
  </div>

  <div id="panelApproval" class="<?php echo $activeTab === 'approval' ? '' : 'd-none'; ?>">
    <div class="card cp-card" style="padding:0;">
      <div class="px-4 py-3 border-bottom">
        <div class="fw-bold" style="color:#1a3a6e;font-size:14px;">Approval &amp; Denial Events</div>
        <div class="text-secondary" style="font-size:12px;">Shows live pending approvals plus approval/rejection/return history.</div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Meaning</th>
              <th>Document ID</th>
              <th>Version</th>
              <th>Reason / Details</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($approvalRows)): ?>
              <?php foreach ($approvalRows as $row): ?>
                <?php
                  $meaning = '';
                  $sourceType = (string)($row['source_type'] ?? '');

                  if (in_array($sourceType, ['pending_workflow', 'pending_fallback'], true)) {
                      $meaning = 'pending';
                  } elseif ($sourceType === 'audit') {
                      $meaning = strtolower((string)($row['action'] ?? ''));
                  } else {
                      $meaning = (string)($row['decision'] ?: $row['comment_type']);
                  }

                  if ($meaning === 'approve' || $meaning === 'approval') $meaning = 'approved';
                  if ($meaning === 'reject' || $meaning === 'rejection') $meaning = 'rejected';
                  if ($meaning === 'return') $meaning = 'returned';

                  $displayTime = format_dt($row['event_time'] ?? '');
                  $displayIp = $row['sign_ip_address'] ?? '—';
                  $displayReason = trim((string)($row['comment_text'] ?? ''));

                  if ($displayReason === '' && ($row['source_type'] ?? '') === 'comment') {
                      $displayReason = trim((string)($row['signature_reason'] ?? ''));
                  }

                  if ($displayReason === '' && in_array($sourceType, ['pending_workflow', 'pending_fallback'], true)) {
                      $submitter = trim((string)($row['submitter_first_name'] ?? '') . ' ' . (string)($row['submitter_last_name'] ?? ''));
                      if ($submitter === '') {
                          $submitter = (string)($row['submitter_email'] ?? 'Unknown User');
                      }

                      $docType = trim((string)($row['type_name'] ?? 'Document'));
                      $docTitle = trim((string)($row['document_title'] ?? 'Untitled'));
                      $dueAt = trim((string)($row['due_at'] ?? ''));

                      $displayReason = $docType . ' "' . $docTitle . '" is waiting for approval. Submitted by ' . $submitter . '.';
                      if ($dueAt !== '' && $dueAt !== '0000-00-00 00:00:00') {
                          $displayReason .= ' Due: ' . format_dt($dueAt) . '.';
                      }
                  }

                  if ($displayReason === '') {
                      $displayReason = '—';
                  }

                  if (in_array($sourceType, ['pending_workflow', 'pending_fallback'], true)) {
                      $displayIp = '—';
                  }
                ?>
                <tr>
                  <td style="font-size:12px;color:#6b7280;"><?php echo e($displayTime); ?></td>
                  <td><?php echo e(format_name($row)); ?></td>
                  <td><?php echo action_badge_html($meaning); ?></td>
                  <td class="fw-semibold text-primary"><?php echo e($row['document_number'] ?: '—'); ?></td>
                  <td><?php echo e($row['version_label'] ? 'V' . $row['version_label'] : '—'); ?></td>
                  <td style="font-size:12px;color:#555;"><?php echo e($displayReason); ?></td>
                  <td style="font-size:12px;color:#6b7280;"><?php echo e($displayIp); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="text-center text-secondary py-4">No approval, denial, or pending approval records found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-2 border-top" style="font-size:12px;color:#dc2626;font-weight:600;">
        &#128274; Read-only — records cannot be edited or deleted by any role including Super Admin.
      </div>
    </div>
  </div>

  <div id="panelComments" class="<?php echo $activeTab === 'comments' ? '' : 'd-none'; ?>">
    <div class="card cp-card" style="padding:0;">
      <div class="px-4 py-3 border-bottom">
        <div class="fw-bold" style="color:#1a3a6e;font-size:14px;">Approver Comment Events</div>
        <div class="text-secondary" style="font-size:12px;">Captures: who commented, when, full comment text, linked document ID and version.</div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Document ID</th>
              <th>Version</th>
              <th>Comment</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($commentRows)): ?>
              <?php foreach ($commentRows as $row): ?>
                <tr>
                  <td style="font-size:12px;color:#6b7280;"><?php echo e(format_dt($row['created_at'] ?? '')); ?></td>
                  <td><?php echo e(format_name($row)); ?></td>
                  <td class="fw-semibold text-primary"><?php echo e($row['document_number'] ?: '—'); ?></td>
                  <td><?php echo e($row['version_label'] ? 'V' . $row['version_label'] : '—'); ?></td>
                  <td style="font-size:12px;color:#555;"><?php echo e($row['comment_text'] ?: '—'); ?></td>
                  <td style="font-size:12px;color:#6b7280;"><?php echo e($row['ip_address'] ?: '—'); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="text-center text-secondary py-4">No approver comments found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-2 border-top" style="font-size:12px;color:#dc2626;font-weight:600;">
        &#128274; Read-only — records cannot be edited or deleted by any role including Super Admin.
      </div>
    </div>
  </div>

  <div id="panelUsers" class="<?php echo $activeTab === 'users' ? '' : 'd-none'; ?>">
    <div class="card cp-card" style="padding:0;">
      <div class="px-4 py-3 border-bottom">
        <div class="fw-bold" style="color:#1a3a6e;font-size:14px;">User Activity Events</div>
        <div class="text-secondary" style="font-size:12px;">Captures: user creation, update, activation, deactivation, role/security events, and login activity.</div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Activity</th>
              <th>Target</th>
              <th>Details</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($userActivityRows)): ?>
              <?php foreach ($userActivityRows as $row): ?>
                <?php
                  $actor = trim((string)$row['user_name']);
                  if ($actor === '') $actor = (string)$row['user_email'];
                  if ($actor === '') $actor = 'System';

                  $target = trim((string)$row['target_name']);
                  if ($target === '') $target = (string)$row['target_email'];
                  if ($target === '') $target = '—';

                  $details = trim((string)$row['remarks']);
                  if ($details === '') $details = '—';
                ?>
                <tr>
                  <td style="font-size:12px;color:#6b7280;"><?php echo e(format_dt($row['event_time'] ?? '')); ?></td>
                  <td><?php echo e($actor); ?></td>
                  <td><?php echo user_activity_badge_html((string)($row['action'] ?? '')); ?></td>
                  <td class="fw-semibold text-primary"><?php echo e($target); ?></td>
                  <td style="font-size:12px;color:#555;"><?php echo e($details); ?></td>
                  <td style="font-size:12px;color:#6b7280;"><?php echo e($row['ip_address'] ?: '—'); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="text-center text-secondary py-4">No user activity records found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-2 border-top" style="font-size:12px;color:#dc2626;font-weight:600;">
        &#128274; Read-only — records cannot be edited or deleted by any role including Super Admin.
      </div>
    </div>
  </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>