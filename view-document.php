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

if (!function_exists('makeRefs')) {
    function makeRefs(array &$arr): array
    {
        $refs = [];
        foreach ($arr as $key => &$value) {
            $refs[$key] = &$value;
        }
        return $refs;
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
            $bind = array_merge([$types], $params);
            $refs = makeRefs($bind);
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        return $row ?: null;
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
            $bind = array_merge([$types], $params);
            $refs = makeRefs($bind);
            call_user_func_array([$stmt, 'bind_param'], $refs);
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

function status_badge(string $status): string
{
    $statusRaw = trim($status);
    $status = strtolower($statusRaw);

    if (in_array($status, ['effective', 'approved', 'published'], true)) {
        return '<span class="badge badge-soft-success">Effective</span>';
    }
    if (in_array($status, ['pending_approval', 'pending approval', 'pending'], true)) {
        return '<span class="badge badge-soft-warning">Pending Approval</span>';
    }
    if ($status === 'draft') {
        return '<span class="badge badge-soft-secondary">Draft</span>';
    }
    if (in_array($status, ['rejected', 'returned'], true)) {
        return '<span class="badge badge-soft-danger">' . e(ucwords(str_replace('_', ' ', $statusRaw))) . '</span>';
    }
    if (in_array($status, ['retired', 'obsolete', 'superseded'], true)) {
        return '<span class="badge badge-soft-secondary">' . e(ucwords(str_replace('_', ' ', $statusRaw))) . '</span>';
    }

    return '<span class="badge badge-soft-info">' . e(ucwords(str_replace('_', ' ', $statusRaw ?: 'Unknown'))) . '</span>';
}

function format_date_display($date): string
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '—';
    }

    $ts = strtotime((string)$date);
    return $ts ? date('d M Y', $ts) : e($date);
}

function format_datetime_display($date): string
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '—';
    }

    $ts = strtotime((string)$date);
    return $ts ? date('d M Y h:i A', $ts) : e($date);
}

function display_user_name(array $row, string $prefix): string
{
    $name = trim((string)($row[$prefix . '_first_name'] ?? '') . ' ' . (string)($row[$prefix . '_last_name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    if (!empty($row[$prefix . '_full_name'])) {
        return (string)$row[$prefix . '_full_name'];
    }

    if (!empty($row[$prefix . '_email'])) {
        return (string)$row[$prefix . '_email'];
    }

    return '—';
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
$currentRoleCode = (string)($_SESSION['role_code'] ?? '');
$currentRoleName = (string)($_SESSION['role_name'] ?? 'QA Admin');

if ($currentUserId <= 0) {
    header('Location: login-admin.php');
    exit;
}

if (!in_array($currentRoleCode, ['qa_admin', 'super_admin'], true)) {
    die('Access denied.');
}

if (!tableExists($conn, 'documents')) {
    die('documents table not found.');
}

$hasDocVersions = tableExists($conn, 'document_versions');
$hasDocTypes = tableExists($conn, 'document_types');
$hasUsers = tableExists($conn, 'users');
$hasWorkflowSteps = tableExists($conn, 'workflow_steps');
$hasAuditLogs = tableExists($conn, 'audit_logs');
$hasAttempts = tableExists($conn, 'document_version_attempts');
$hasAckAssignments = tableExists($conn, 'acknowledgement_assignments');
$hasFormDefinitions = tableExists($conn, 'form_definitions');

$idParam = (int)($_GET['id'] ?? 0);
$documentIdParam = (int)($_GET['document_id'] ?? 0);
$docIdParam = (int)($_GET['doc_id'] ?? 0);
$versionIdParam = (int)($_GET['version_id'] ?? $_GET['document_version_id'] ?? 0);
$documentNumberParam = trim((string)($_GET['document_number'] ?? $_GET['doc_no'] ?? ''));

$resolvedDocumentId = 0;
$resolvedVersionId = 0;

if ($documentIdParam > 0) {
    $resolvedDocumentId = $documentIdParam;
} elseif ($docIdParam > 0) {
    $resolvedDocumentId = $docIdParam;
} elseif ($idParam > 0) {
    $checkDoc = fetch_one($conn, "SELECT id FROM documents WHERE id = ? LIMIT 1", 'i', [$idParam]);

    if ($checkDoc) {
        $resolvedDocumentId = (int)$checkDoc['id'];
    } elseif ($hasDocVersions) {
        $checkVersion = fetch_one($conn, "SELECT id, document_id FROM document_versions WHERE id = ? LIMIT 1", 'i', [$idParam]);
        if ($checkVersion) {
            $resolvedVersionId = (int)$checkVersion['id'];
            $resolvedDocumentId = (int)$checkVersion['document_id'];
        }
    }
}

if ($versionIdParam > 0 && $hasDocVersions) {
    $checkVersion = fetch_one($conn, "SELECT id, document_id FROM document_versions WHERE id = ? LIMIT 1", 'i', [$versionIdParam]);
    if ($checkVersion) {
        $resolvedVersionId = (int)$checkVersion['id'];
        $resolvedDocumentId = (int)$checkVersion['document_id'];
    }
}

if ($resolvedDocumentId <= 0 && $documentNumberParam !== '') {
    $checkDoc = fetch_one($conn, "SELECT id FROM documents WHERE document_number = ? LIMIT 1", 's', [$documentNumberParam]);

    if (!$checkDoc) {
        $checkDoc = fetch_one($conn, "SELECT id FROM documents WHERE document_number LIKE ? LIMIT 1", 's', ['%' . $documentNumberParam . '%']);
    }

    if ($checkDoc) {
        $resolvedDocumentId = (int)$checkDoc['id'];
    }
}

if ($resolvedDocumentId <= 0) {
    die('Document not found. Invalid document reference.');
}

if ($hasDocVersions && $resolvedVersionId <= 0) {
    $versionRow = fetch_one($conn, "
        SELECT id
        FROM document_versions
        WHERE document_id = ?
        ORDER BY
            CASE
                WHEN status IN ('effective','approved','published') THEN 1
                WHEN status = 'pending_approval' THEN 2
                WHEN status = 'draft' THEN 3
                ELSE 4
            END ASC,
            version_sequence DESC,
            id DESC
        LIMIT 1
    ", 'i', [$resolvedDocumentId]);

    if ($versionRow) {
        $resolvedVersionId = (int)$versionRow['id'];
    }
}

$selectParts = [];
$joinParts = [];

$selectParts[] = "d.id AS document_id";
$selectParts[] = "d.document_number";
$selectParts[] = "d.title";
$selectParts[] = columnExists($conn, 'documents', 'topic') ? "d.topic" : "NULL AS topic";
$selectParts[] = columnExists($conn, 'documents', 'current_status') ? "d.current_status" : "'draft' AS current_status";
$selectParts[] = columnExists($conn, 'documents', 'current_version_id') ? "d.current_version_id" : "NULL AS current_version_id";
$selectParts[] = columnExists($conn, 'documents', 'owner_user_id') ? "d.owner_user_id" : "NULL AS owner_user_id";
$selectParts[] = columnExists($conn, 'documents', 'created_by') ? "d.created_by" : "NULL AS created_by";
$selectParts[] = columnExists($conn, 'documents', 'created_at') ? "d.created_at AS document_created_at" : "NULL AS document_created_at";
$selectParts[] = columnExists($conn, 'documents', 'updated_at') ? "d.updated_at AS document_updated_at" : "NULL AS document_updated_at";
$selectParts[] = columnExists($conn, 'documents', 'remarks') ? "d.remarks" : "NULL AS remarks";
$selectParts[] = columnExists($conn, 'documents', 'is_acknowledgement_required') ? "d.is_acknowledgement_required" : "0 AS is_acknowledgement_required";
$selectParts[] = columnExists($conn, 'documents', 'approver') ? "d.approver" : "NULL AS approver";

if ($hasDocTypes) {
    $selectParts[] = "dt.type_name";
    $selectParts[] = "dt.prefix";
    $joinParts[] = "LEFT JOIN document_types dt ON dt.id = d.document_type_id";
} else {
    $selectParts[] = "NULL AS type_name";
    $selectParts[] = "NULL AS prefix";
}

if ($hasDocVersions) {
    $selectParts[] = "dv.id AS version_id";
    $selectParts[] = columnExists($conn, 'document_versions', 'version_label') ? "dv.version_label" : "NULL AS version_label";
    $selectParts[] = columnExists($conn, 'document_versions', 'version_sequence') ? "dv.version_sequence" : "NULL AS version_sequence";
    $selectParts[] = columnExists($conn, 'document_versions', 'status') ? "dv.status AS version_status" : "NULL AS version_status";
    $selectParts[] = columnExists($conn, 'document_versions', 'title_snapshot') ? "dv.title_snapshot" : "NULL AS title_snapshot";
    $selectParts[] = columnExists($conn, 'document_versions', 'topic_snapshot') ? "dv.topic_snapshot" : "NULL AS topic_snapshot";
    $selectParts[] = columnExists($conn, 'document_versions', 'content_format') ? "dv.content_format" : "NULL AS content_format";
    $selectParts[] = columnExists($conn, 'document_versions', 'content_text') ? "dv.content_text" : "NULL AS content_text";
    $selectParts[] = columnExists($conn, 'document_versions', 'effective_date') ? "dv.effective_date" : "NULL AS effective_date";
    $selectParts[] = columnExists($conn, 'document_versions', 'review_date') ? "dv.review_date" : "NULL AS review_date";
    $selectParts[] = columnExists($conn, 'document_versions', 'submitted_by') ? "dv.submitted_by" : "NULL AS submitted_by";
    $selectParts[] = columnExists($conn, 'document_versions', 'submitted_at') ? "dv.submitted_at" : "NULL AS submitted_at";
    $selectParts[] = columnExists($conn, 'document_versions', 'approved_by') ? "dv.approved_by" : "NULL AS approved_by";
    $selectParts[] = columnExists($conn, 'document_versions', 'approved_at') ? "dv.approved_at" : "NULL AS approved_at";
    $selectParts[] = columnExists($conn, 'document_versions', 'rejected_by') ? "dv.rejected_by" : "NULL AS rejected_by";
    $selectParts[] = columnExists($conn, 'document_versions', 'rejected_at') ? "dv.rejected_at" : "NULL AS rejected_at";
    $selectParts[] = columnExists($conn, 'document_versions', 'rejection_reason') ? "dv.rejection_reason" : "NULL AS rejection_reason";
    $selectParts[] = columnExists($conn, 'document_versions', 'change_summary') ? "dv.change_summary" : "NULL AS change_summary";
    $selectParts[] = columnExists($conn, 'document_versions', 'primary_file_name') ? "dv.primary_file_name" : "NULL AS primary_file_name";
    $selectParts[] = columnExists($conn, 'document_versions', 'primary_file_path') ? "dv.primary_file_path" : "NULL AS primary_file_path";
    $selectParts[] = columnExists($conn, 'document_versions', 'primary_file_mime') ? "dv.primary_file_mime" : "NULL AS primary_file_mime";
    $selectParts[] = columnExists($conn, 'document_versions', 'primary_file_size') ? "dv.primary_file_size" : "NULL AS primary_file_size";
    $selectParts[] = columnExists($conn, 'document_versions', 'checksum_sha256') ? "dv.checksum_sha256" : "NULL AS checksum_sha256";
    $selectParts[] = columnExists($conn, 'document_versions', 'form_definition_id') ? "dv.form_definition_id" : "NULL AS form_definition_id";

    if ($resolvedVersionId > 0) {
        $joinParts[] = "LEFT JOIN document_versions dv ON dv.id = " . (int)$resolvedVersionId . " AND dv.document_id = d.id";
    } else {
        $joinParts[] = "LEFT JOIN document_versions dv ON dv.id = d.current_version_id";
    }
} else {
    $selectParts[] = "NULL AS version_id";
    $selectParts[] = "NULL AS version_label";
    $selectParts[] = "NULL AS version_sequence";
    $selectParts[] = "NULL AS version_status";
    $selectParts[] = "NULL AS title_snapshot";
    $selectParts[] = "NULL AS topic_snapshot";
    $selectParts[] = "NULL AS content_format";
    $selectParts[] = "NULL AS content_text";
    $selectParts[] = "NULL AS effective_date";
    $selectParts[] = "NULL AS review_date";
    $selectParts[] = "NULL AS submitted_by";
    $selectParts[] = "NULL AS submitted_at";
    $selectParts[] = "NULL AS approved_by";
    $selectParts[] = "NULL AS approved_at";
    $selectParts[] = "NULL AS rejected_by";
    $selectParts[] = "NULL AS rejected_at";
    $selectParts[] = "NULL AS rejection_reason";
    $selectParts[] = "NULL AS change_summary";
    $selectParts[] = "NULL AS primary_file_name";
    $selectParts[] = "NULL AS primary_file_path";
    $selectParts[] = "NULL AS primary_file_mime";
    $selectParts[] = "NULL AS primary_file_size";
    $selectParts[] = "NULL AS checksum_sha256";
    $selectParts[] = "NULL AS form_definition_id";
}

if ($hasUsers) {
    $selectParts[] = "owner.first_name AS owner_first_name";
    $selectParts[] = "owner.last_name AS owner_last_name";
    $selectParts[] = columnExists($conn, 'users', 'full_name') ? "owner.full_name AS owner_full_name" : "NULL AS owner_full_name";
    $selectParts[] = "owner.email AS owner_email";

    $selectParts[] = "creator.first_name AS creator_first_name";
    $selectParts[] = "creator.last_name AS creator_last_name";
    $selectParts[] = columnExists($conn, 'users', 'full_name') ? "creator.full_name AS creator_full_name" : "NULL AS creator_full_name";
    $selectParts[] = "creator.email AS creator_email";

    $selectParts[] = "submitter.first_name AS submitter_first_name";
    $selectParts[] = "submitter.last_name AS submitter_last_name";
    $selectParts[] = columnExists($conn, 'users', 'full_name') ? "submitter.full_name AS submitter_full_name" : "NULL AS submitter_full_name";
    $selectParts[] = "submitter.email AS submitter_email";

    $selectParts[] = "approverUser.first_name AS approver_first_name";
    $selectParts[] = "approverUser.last_name AS approver_last_name";
    $selectParts[] = columnExists($conn, 'users', 'full_name') ? "approverUser.full_name AS approver_full_name" : "NULL AS approver_full_name";
    $selectParts[] = "approverUser.email AS approver_email";

    $joinParts[] = "LEFT JOIN users owner ON owner.id = d.owner_user_id";
    $joinParts[] = "LEFT JOIN users creator ON creator.id = d.created_by";

    if ($hasDocVersions) {
        $joinParts[] = "LEFT JOIN users submitter ON submitter.id = dv.submitted_by";
        $joinParts[] = "LEFT JOIN users approverUser ON approverUser.id = dv.approved_by";
    } else {
        $joinParts[] = "LEFT JOIN users submitter ON submitter.id = 0";
        $joinParts[] = "LEFT JOIN users approverUser ON approverUser.id = 0";
    }
} else {
    $selectParts[] = "NULL AS owner_first_name";
    $selectParts[] = "NULL AS owner_last_name";
    $selectParts[] = "NULL AS owner_full_name";
    $selectParts[] = "NULL AS owner_email";
    $selectParts[] = "NULL AS creator_first_name";
    $selectParts[] = "NULL AS creator_last_name";
    $selectParts[] = "NULL AS creator_full_name";
    $selectParts[] = "NULL AS creator_email";
    $selectParts[] = "NULL AS submitter_first_name";
    $selectParts[] = "NULL AS submitter_last_name";
    $selectParts[] = "NULL AS submitter_full_name";
    $selectParts[] = "NULL AS submitter_email";
    $selectParts[] = "NULL AS approver_first_name";
    $selectParts[] = "NULL AS approver_last_name";
    $selectParts[] = "NULL AS approver_full_name";
    $selectParts[] = "NULL AS approver_email";
}

$documentSql = "
    SELECT " . implode(",\n        ", $selectParts) . "
    FROM documents d
    " . implode("\n    ", $joinParts) . "
    WHERE d.id = ?
    LIMIT 1
";

$document = fetch_one($conn, $documentSql, 'i', [$resolvedDocumentId]);

if (!$document) {
    die('Document not found. Please check document id.');
}

$documentId = (int)$document['document_id'];
$versionId = (int)($document['version_id'] ?? 0);

$versions = [];
if ($hasDocVersions) {
    $versions = fetch_all($conn, "
        SELECT
            id,
            " . (columnExists($conn, 'document_versions', 'version_label') ? "version_label" : "NULL AS version_label") . ",
            " . (columnExists($conn, 'document_versions', 'version_sequence') ? "version_sequence" : "NULL AS version_sequence") . ",
            " . (columnExists($conn, 'document_versions', 'status') ? "status" : "NULL AS status") . ",
            " . (columnExists($conn, 'document_versions', 'effective_date') ? "effective_date" : "NULL AS effective_date") . ",
            " . (columnExists($conn, 'document_versions', 'review_date') ? "review_date" : "NULL AS review_date") . ",
            " . (columnExists($conn, 'document_versions', 'submitted_at') ? "submitted_at" : "NULL AS submitted_at") . ",
            " . (columnExists($conn, 'document_versions', 'approved_at') ? "approved_at" : "NULL AS approved_at") . ",
            " . (columnExists($conn, 'document_versions', 'change_summary') ? "change_summary" : "NULL AS change_summary") . "
        FROM document_versions
        WHERE document_id = ?
        ORDER BY version_sequence ASC, id ASC
    ", 'i', [$documentId]);
}

$attempts = [];
if ($hasAttempts && $versionId > 0) {
    $attempts = fetch_all($conn, "
        SELECT
            id,
            attempt_number,
            status,
            submitted_at,
            approved_at,
            rejected_at,
            rejection_remark,
            effective_at,
            created_at
        FROM document_version_attempts
        WHERE document_version_id = ?
        ORDER BY attempt_number ASC, id ASC
    ", 'i', [$versionId]);
}

$workflowRows = [];
if ($hasWorkflowSteps && $versionId > 0) {
    $wfSelect = [
        "ws.id",
        columnExists($conn, 'workflow_steps', 'status') ? "ws.status" : "NULL AS status",
        columnExists($conn, 'workflow_steps', 'action_status') ? "ws.action_status" : "NULL AS action_status",
        columnExists($conn, 'workflow_steps', 'step_name') ? "ws.step_name" : "NULL AS step_name",
        columnExists($conn, 'workflow_steps', 'decision') ? "ws.decision" : "NULL AS decision",
        columnExists($conn, 'workflow_steps', 'comment_text') ? "ws.comment_text" : "NULL AS comment_text",
        columnExists($conn, 'workflow_steps', 'decided_at') ? "ws.decided_at" : "NULL AS decided_at"
    ];

    if ($hasUsers) {
        $wfSelect[] = "approver.first_name";
        $wfSelect[] = "approver.last_name";
        $wfSelect[] = "approver.email";
        $wfJoin = "LEFT JOIN users approver ON approver.id = ws.approver_user_id";
    } else {
        $wfSelect[] = "NULL AS first_name";
        $wfSelect[] = "NULL AS last_name";
        $wfSelect[] = "NULL AS email";
        $wfJoin = "";
    }

    $workflowRows = fetch_all($conn, "
        SELECT " . implode(", ", $wfSelect) . "
        FROM workflow_steps ws
        {$wfJoin}
        WHERE ws.document_version_id = ?
        ORDER BY ws.id ASC
    ", 'i', [$versionId]);
}

$auditRows = [];
if ($hasAuditLogs) {
    $auditHasDocumentId = columnExists($conn, 'audit_logs', 'document_id');
    $auditHasVersionId = columnExists($conn, 'audit_logs', 'document_version_id');

    $auditWhere = "al.entity_type = 'document' AND al.entity_id = ?";
    $auditTypes = 'i';
    $auditParams = [$documentId];

    if ($auditHasDocumentId && $auditHasVersionId) {
        $auditWhere = "al.entity_type = 'document' AND (al.entity_id = ? OR al.document_id = ? OR al.document_version_id = ?)";
        $auditTypes = 'iii';
        $auditParams = [$documentId, $documentId, $versionId];
    } elseif ($auditHasDocumentId) {
        $auditWhere = "al.entity_type = 'document' AND (al.entity_id = ? OR al.document_id = ?)";
        $auditTypes = 'ii';
        $auditParams = [$documentId, $documentId];
    }

    $auditRows = fetch_all($conn, "
        SELECT
            al.performed_at,
            al.action,
            al.remarks,
            actor.first_name,
            actor.last_name,
            actor.email
        FROM audit_logs al
        LEFT JOIN users actor ON actor.id = al.performed_by
        WHERE {$auditWhere}
        ORDER BY al.performed_at DESC, al.id DESC
        LIMIT 20
    ", $auditTypes, $auditParams);
}

$formDefinition = null;
if (
    $hasFormDefinitions &&
    !empty($document['form_definition_id']) &&
    columnExists($conn, 'form_definitions', 'builder_json')
) {
    $formDefinition = fetch_one($conn, "
        SELECT form_name, form_type, builder_json
        FROM form_definitions
        WHERE id = ?
        LIMIT 1
    ", 'i', [(int)$document['form_definition_id']]);
}

$ackCount = 0;
if ($hasAckAssignments && $versionId > 0 && columnExists($conn, 'acknowledgement_assignments', 'document_version_id')) {
    $ackRow = fetch_one($conn, "
        SELECT COUNT(*) AS cnt
        FROM acknowledgement_assignments
        WHERE document_version_id = ?
    ", 'i', [$versionId]);
    $ackCount = (int)($ackRow['cnt'] ?? 0);
}

$title = $document['title_snapshot'] ?: $document['title'];
$topic = $document['topic_snapshot'] ?: $document['topic'];
$typeName = $document['type_name'] ?: 'Document';
$statusForDisplay = $document['version_status'] ?: $document['current_status'];
$contentText = (string)($document['content_text'] ?? '');
$filePath = (string)($document['primary_file_path'] ?? '');
$fileName = (string)($document['primary_file_name'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - View Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .view-kv-table{width:100%;font-size:13px;margin:0;}
    .view-kv-table th,.view-kv-table td{padding:10px 12px;border:1px solid #e8edf3;vertical-align:top;}
    .view-kv-table th{width:190px;background:#f8f9fb;color:#0D2144;font-weight:700;}
    .doc-content-box{border:1px solid #dde3ec;background:#fff;border-radius:12px;padding:18px;min-height:180px;white-space:pre-wrap;line-height:1.7;font-size:14px;color:#1f2937;}
    .doc-file-box{border:1px solid #dde3ec;background:#f8f9fb;border-radius:12px;padding:16px;}
    .timeline-list{border-left:2px solid #e5eaf2;margin-left:8px;padding-left:18px;}
    .timeline-item{position:relative;padding-bottom:16px;}
    .timeline-item:before{content:"";position:absolute;left:-25px;top:4px;width:12px;height:12px;border-radius:999px;background:#2563eb;border:2px solid #fff;box-shadow:0 0 0 2px #dbeafe;}
    .version-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid #dbe3ef;background:#fff;font-size:12px;font-weight:700;color:#0D2144;margin:0 6px 6px 0;}
    @media(max-width:768px){
      .view-kv-table th,.view-kv-table td{display:block;width:100%;}
      .view-kv-table th{border-bottom:0;}
      .view-kv-table td{border-top:0;margin-bottom:8px;}
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/navbar.php'; ?>

<main class="app-shell">
  <div class="content-wrap px-4 py-4 mx-auto">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
      <div>
        <h1 class="page-title mb-2">View Controlled Document</h1>
        <p class="page-subtitle mb-0">Review document details, content, workflow, versions and audit trail.</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard-admin.php" class="btn btn-outline-secondary">Back Dashboard</a>
        <a href="repository.php?q=<?php echo urlencode((string)$document['document_number']); ?>" class="btn btn-outline-primary">Open Repository</a>

        <?php if (in_array(strtolower((string)$document['current_status']), ['draft', 'rejected'], true)): ?>
          <a href="create-document.php?draft_id=<?php echo (int)$documentId; ?>" class="btn btn-primary">Edit / Correct</a>
        <?php endif; ?>

        <?php if (in_array(strtolower((string)$document['current_status']), ['effective', 'approved', 'published'], true)): ?>
          <a href="update-document.php?id=<?php echo (int)$documentId; ?>" class="btn btn-primary">Update Document</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-lg-8">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
              <div>
                <h2 class="card-title mb-1"><?php echo e($title ?: 'Untitled Document'); ?></h2>
                <p class="card-subtitle mb-0"><?php echo e($document['document_number']); ?></p>
              </div>
              <div><?php echo status_badge((string)$statusForDisplay); ?></div>
            </div>

            <table class="view-kv-table">
              <tr><th>Document ID</th><td class="fw-semibold text-primary"><?php echo e($document['document_number']); ?></td></tr>
              <tr><th>Type</th><td><?php echo e($typeName); ?></td></tr>
              <tr><th>Topic</th><td><?php echo e($topic ?: '—'); ?></td></tr>
              <tr><th>Version</th><td><?php echo e($document['version_label'] ?: '—'); ?></td></tr>
              <tr><th>Owner</th><td><?php echo e(display_user_name($document, 'owner')); ?></td></tr>
              <tr><th>Created By</th><td><?php echo e(display_user_name($document, 'creator')); ?></td></tr>
              <tr><th>Submitted By</th><td><?php echo e(display_user_name($document, 'submitter')); ?></td></tr>
              <tr><th>Approved By</th><td><?php echo e(display_user_name($document, 'approver')); ?></td></tr>
              <tr><th>Effective Date</th><td><?php echo e(format_date_display($document['effective_date'] ?? '')); ?></td></tr>
              <tr><th>Review Date</th><td><?php echo e(format_date_display($document['review_date'] ?? '')); ?></td></tr>
              <tr><th>Purpose & Scope</th><td><?php echo nl2br(e($document['remarks'] ?: '—')); ?></td></tr>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card cp-card mb-3">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Document Summary</h2>
            <p class="card-subtitle mb-3">Current controlled status.</p>

            <div class="mb-3">
              <div class="small text-secondary">Current Status</div>
              <div><?php echo status_badge((string)$document['current_status']); ?></div>
            </div>

            <div class="mb-3">
              <div class="small text-secondary">Version Status</div>
              <div><?php echo status_badge((string)$document['version_status']); ?></div>
            </div>

            <div class="mb-3">
              <div class="small text-secondary">Submitted At</div>
              <div class="fw-semibold"><?php echo e(format_datetime_display($document['submitted_at'] ?? '')); ?></div>
            </div>

            <div class="mb-3">
              <div class="small text-secondary">Approved At</div>
              <div class="fw-semibold"><?php echo e(format_datetime_display($document['approved_at'] ?? '')); ?></div>
            </div>

            <div>
              <div class="small text-secondary">Acknowledgement Assignments</div>
              <div class="fw-semibold"><?php echo (int)$ackCount; ?></div>
            </div>
          </div>
        </div>

        <div class="card cp-card">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Quick Actions</h2>
            <p class="card-subtitle mb-3">Continue workflow action.</p>
            <div class="d-grid gap-2">
              <?php if (strtolower((string)$document['current_status']) === 'pending_approval'): ?>
                <a href="review-document.php?id=<?php echo (int)$documentId; ?>&version_id=<?php echo (int)$versionId; ?>" class="btn btn-success">Review / Approve</a>
              <?php endif; ?>

              <a href="repository.php?q=<?php echo urlencode((string)$document['document_number']); ?>" class="btn btn-outline-primary">View in Repository</a>
              <a href="audit-trail.php?doc_id=<?php echo urlencode((string)$document['document_number']); ?>" class="btn btn-outline-primary">View Audit Trail</a>

              <?php if (in_array(strtolower((string)$document['current_status']), ['effective', 'approved', 'published'], true)): ?>
                <a href="acknowledgement-assign.php?id=<?php echo (int)$documentId; ?>" class="btn btn-outline-primary">Assign Acknowledgement</a>
                <a href="retire-document.php?id=<?php echo (int)$documentId; ?>" class="btn btn-outline-danger">Retire Document</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card cp-card mb-4">
      <div class="card-body p-4">
        <h2 class="card-title mb-1">Document Content</h2>
        <p class="card-subtitle mb-3">Controlled document file, text content, or form/checklist builder data.</p>

        <?php if ($filePath !== ''): ?>
          <div class="doc-file-box">
            <div class="fw-semibold text-primary mb-1"><?php echo e($fileName ?: basename($filePath)); ?></div>
            <div class="small text-secondary mb-3">
              MIME: <?php echo e($document['primary_file_mime'] ?: '—'); ?> |
              Size: <?php echo !empty($document['primary_file_size']) ? e(round(((int)$document['primary_file_size']) / 1024, 2) . ' KB') : '—'; ?>
            </div>
            <a href="<?php echo e($filePath); ?>" target="_blank" class="btn btn-primary">Open File</a>
          </div>
        <?php elseif ($formDefinition): ?>
          <div class="doc-content-box">
<strong>Form Name:</strong> <?php echo e($formDefinition['form_name'] ?? '—'); ?>

<strong>Form Type:</strong> <?php echo e($formDefinition['form_type'] ?? '—'); ?>


<?php
$builder = json_decode((string)($formDefinition['builder_json'] ?? ''), true);
if (is_array($builder) && !empty($builder['fields'])):
?>
<?php foreach ($builder['fields'] as $idx => $field): ?>
<?php echo ($idx + 1) . '. ' . e($field['label'] ?? 'Field') . ' - ' . e($field['type'] ?? 'text') . "\n"; ?>
<?php endforeach; ?>
<?php else: ?>
No builder fields found.
<?php endif; ?>
          </div>
        <?php elseif ($contentText !== ''): ?>
          <div class="doc-content-box"><?php echo e($contentText); ?></div>
        <?php else: ?>
          <div class="text-center text-secondary py-4">No document content found.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-lg-6">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Version History</h2>
            <p class="card-subtitle mb-3">All versions created for this document.</p>

            <?php if (!empty($versions)): ?>
              <?php foreach ($versions as $v): ?>
                <span class="version-pill">
                  V<?php echo e($v['version_label'] ?: $v['version_sequence']); ?>
                  <?php echo status_badge((string)$v['status']); ?>
                </span>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-secondary">No version history found.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Attempt History</h2>
            <p class="card-subtitle mb-3">Rejected correction attempts under same version.</p>

            <?php if (!empty($attempts)): ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Attempt</th>
                      <th>Status</th>
                      <th>Submitted</th>
                      <th>Remark</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($attempts as $attempt): ?>
                      <tr>
                        <td>Attempt <?php echo (int)$attempt['attempt_number']; ?></td>
                        <td><?php echo status_badge((string)$attempt['status']); ?></td>
                        <td><?php echo e(format_datetime_display($attempt['submitted_at'] ?? '')); ?></td>
                        <td><?php echo e($attempt['rejection_remark'] ?? '—'); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-secondary">No attempt history found.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Workflow</h2>
            <p class="card-subtitle mb-3">Approval workflow steps for this document.</p>

            <?php if (!empty($workflowRows)): ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Step</th>
                      <th>Approver</th>
                      <th>Status</th>
                      <th>Decision</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($workflowRows as $wf): ?>
                      <?php
                        $wfApprover = trim((string)($wf['first_name'] ?? '') . ' ' . (string)($wf['last_name'] ?? ''));
                        if ($wfApprover === '') {
                            $wfApprover = $wf['email'] ?? '—';
                        }
                      ?>
                      <tr>
                        <td><?php echo e($wf['step_name'] ?: 'Approval'); ?></td>
                        <td><?php echo e($wfApprover); ?></td>
                        <td><?php echo status_badge((string)($wf['status'] ?: $wf['action_status'])); ?></td>
                        <td><?php echo e($wf['decision'] ?: '—'); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-secondary">No workflow steps found.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Audit Activity</h2>
            <p class="card-subtitle mb-3">Recent audit events for this document.</p>

            <?php if (!empty($auditRows)): ?>
              <div class="timeline-list">
                <?php foreach ($auditRows as $audit): ?>
                  <?php
                    $actor = trim((string)($audit['first_name'] ?? '') . ' ' . (string)($audit['last_name'] ?? ''));
                    if ($actor === '') {
                        $actor = $audit['email'] ?? 'System';
                    }
                  ?>
                  <div class="timeline-item">
                    <div class="fw-semibold"><?php echo e(ucwords(str_replace('_', ' ', (string)$audit['action']))); ?></div>
                    <div class="small text-secondary"><?php echo e(format_datetime_display($audit['performed_at'])); ?> by <?php echo e($actor); ?></div>
                    <div class="small"><?php echo e($audit['remarks'] ?: '—'); ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-secondary">No audit logs found.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>