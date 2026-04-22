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

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4() {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('table_exists')) {
    function table_exists(mysqli $conn, string $table): bool {
        $table = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
        $has = $res && mysqli_num_rows($res) > 0;
        if ($res) {
            mysqli_free_result($res);
        }
        return $has;
    }
}

if (!function_exists('make_bind_refs')) {
    function make_bind_refs(array &$arr): array {
        $refs = [];
        foreach ($arr as $key => &$value) {
            $refs[$key] = &$value;
        }
        return $refs;
    }
}

if (!function_exists('stmt_bind_execute')) {
    function stmt_bind_execute(mysqli_stmt $stmt, array $params = []): bool {
        if (empty($params)) {
            return mysqli_stmt_execute($stmt);
        }

        $types = '';
        $values = [];

        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $param;
        }

        $bindParams = array_merge([$types], $values);
        $refs = make_bind_refs($bindParams);

        call_user_func_array([$stmt, 'bind_param'], $refs);
        return mysqli_stmt_execute($stmt);
    }
}

if (!function_exists('write_audit_log')) {
    function write_audit_log(mysqli $conn, string $entityType, $entityId, string $action, $oldValue, $newValue, $performedBy, string $remarks = ''): void {
        if (!table_exists($conn, 'audit_logs')) {
            return;
        }

        $eventId = generate_uuid_v4();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $oldJson = $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $newJson = $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $sql = "
            INSERT INTO audit_logs
            (event_id, entity_type, entity_id, action, old_value, new_value, performed_by, performed_at, remarks, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            stmt_bind_execute($stmt, [
                $eventId,
                $entityType,
                $entityId !== null ? (int)$entityId : null,
                $action,
                $oldJson,
                $newJson,
                $performedBy !== null ? (int)$performedBy : null,
                $remarks,
                $ipAddress,
                $userAgent
            ]);
            mysqli_stmt_close($stmt);
        }
    }
}

if (!function_exists('formatDateDisplay')) {
    function formatDateDisplay($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$date);
        return $ts ? date('d M Y', $ts) : e((string)$date);
    }
}

if (!function_exists('formatDateTimeDisplay')) {
    function formatDateTimeDisplay($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$date);
        return $ts ? date('d M Y h:i A', $ts) : e((string)$date);
    }
}

if (!function_exists('getFileExtensionSafe')) {
    function getFileExtensionSafe($path) {
        $path = (string)$path;
        $cleanPath = strtok($path, '?');
        return strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
    }
}

if (!function_exists('canPreviewInline')) {
    function canPreviewInline($path) {
        $ext = getFileExtensionSafe($path);
        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }
}

if (!function_exists('isImageFile')) {
    function isImageFile($path) {
        $ext = getFileExtensionSafe($path);
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }
}

if (!function_exists('parseDocumentContent')) {
    function parseDocumentContent($contentText) {
        $result = [
            'is_json_form'   => false,
            'purpose_scope'  => '',
            'form_responses' => [],
            'raw_text'       => trim((string)$contentText),
        ];

        if (trim((string)$contentText) === '') {
            return $result;
        }

        $decoded = json_decode((string)$contentText, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (array_key_exists('purpose_scope', $decoded) || array_key_exists('form_responses', $decoded)) {
                $result['is_json_form'] = true;
                $result['purpose_scope'] = trim((string)($decoded['purpose_scope'] ?? ''));
                $result['form_responses'] = is_array($decoded['form_responses'] ?? null) ? $decoded['form_responses'] : [];
            }
        }

        return $result;
    }
}

if (!function_exists('formatFormResponseLabel')) {
    function formatFormResponseLabel($key) {
        $key = (string)$key;
        if ($key === '') return 'Field';
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }
}

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass($status) {
        $status = strtolower(trim((string)$status));

        if ($status === 'draft') return 'badge badge-soft-secondary';
        if ($status === 'pending_approval') return 'badge badge-soft-warning';
        if ($status === 'approved') return 'badge badge-soft-success';
        if ($status === 'effective') return 'badge badge-soft-success';
        if ($status === 'rejected') return 'badge badge-soft-danger';
        if ($status === 'returned') return 'badge badge-soft-warning';
        if ($status === 'retired') return 'badge badge-soft-secondary';
        if ($status === 'pending_retirement') return 'badge badge-soft-warning';
        if ($status === 'obsolete') return 'badge badge-soft-secondary';

        return 'badge badge-soft-info';
    }
}

if (!function_exists('statusLabel')) {
    function statusLabel($status) {
        $status = strtolower(trim((string)$status));

        if ($status === 'pending_approval') return 'Pending Approval';
        if ($status === 'pending_retirement') return 'Pending Retirement';
        if ($status === 'approved') return 'Approved';
        if ($status === 'effective') return 'Effective';
        if ($status === 'rejected') return 'Rejected';
        if ($status === 'returned') return 'Returned';
        if ($status === 'retired') return 'Retired';
        if ($status === 'obsolete') return 'Obsolete';
        if ($status === 'draft') return 'Draft';

        return ucwords(str_replace('_', ' ', $status));
    }
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

$message = '';
$error = '';

/* ---------------------------------------------------------
   APPROVE / RETURN / REJECT WITH AUDIT LOG
--------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_action'])) {
    $action = strtolower(trim((string)($_POST['approve_action'] ?? '')));
    $documentId = (int)($_POST['document_id'] ?? 0);
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($documentId <= 0 || !in_array($action, ['approved', 'returned', 'rejected'], true)) {
        $error = 'Invalid request.';
    } else {
        mysqli_begin_transaction($conn);

        try {
            $docSql = "
                SELECT
                    d.id,
                    d.document_number,
                    d.title,
                    d.topic,
                    d.current_status,
                    d.approver,
                    d.review_comments,
                    d.current_version_id,
                    dv.version_label,
                    dv.status AS version_status
                FROM documents d
                LEFT JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE d.id = ?
                  AND d.approver = ?
                  AND LOWER(COALESCE(d.current_status, 'draft')) <> 'draft'
                LIMIT 1
            ";
            $docStmt = mysqli_prepare($conn, $docSql);
            if (!$docStmt) {
                throw new Exception('Failed to prepare document query.');
            }

            $userIdStr = (string)$userId;
            mysqli_stmt_bind_param($docStmt, "is", $documentId, $userIdStr);
            mysqli_stmt_execute($docStmt);
            $docRes = mysqli_stmt_get_result($docStmt);
            $docRow = ($docRes && mysqli_num_rows($docRes) > 0) ? mysqli_fetch_assoc($docRes) : null;
            mysqli_stmt_close($docStmt);

            if (!$docRow) {
                throw new Exception('Assigned document not found.');
            }

            $currentStatus = strtolower((string)($docRow['current_status'] ?? ''));
            $currentVersionId = (int)($docRow['current_version_id'] ?? 0);

            if ($currentStatus !== 'pending_approval') {
                throw new Exception('Only pending approval documents can be processed.');
            }

            $oldSnapshot = [
                'document_id'         => (int)$docRow['id'],
                'document_version_id' => $currentVersionId,
                'document_number'     => (string)($docRow['document_number'] ?? ''),
                'title'               => (string)($docRow['title'] ?? ''),
                'topic'               => (string)($docRow['topic'] ?? ''),
                'status'              => (string)($docRow['current_status'] ?? ''),
                'version_status'      => (string)($docRow['version_status'] ?? ''),
                'version_label'       => (string)($docRow['version_label'] ?? ''),
                'approver_user_id'    => (string)($docRow['approver'] ?? ''),
                'review_comments'     => (string)($docRow['review_comments'] ?? '')
            ];

            if ($action === 'approved') {
                $newDocStatus = 'approved';
                $newVersionStatus = 'approved';

                $updDoc = mysqli_prepare($conn, "
                    UPDATE documents
                    SET current_status = ?, review_comments = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                if (!$updDoc) {
                    throw new Exception('Failed to update document.');
                }
                mysqli_stmt_bind_param($updDoc, "ssii", $newDocStatus, $remarks, $userId, $documentId);
                mysqli_stmt_execute($updDoc);
                mysqli_stmt_close($updDoc);

                if ($currentVersionId > 0) {
                    $updVer = mysqli_prepare($conn, "
                        UPDATE document_versions
                        SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ");
                    if (!$updVer) {
                        throw new Exception('Failed to update document version.');
                    }
                    mysqli_stmt_bind_param($updVer, "sii", $newVersionStatus, $userId, $currentVersionId);
                    mysqli_stmt_execute($updVer);
                    mysqli_stmt_close($updVer);
                }

                $newSnapshot = [
                    'document_id'         => (int)$docRow['id'],
                    'document_version_id' => $currentVersionId,
                    'document_number'     => (string)($docRow['document_number'] ?? ''),
                    'title'               => (string)($docRow['title'] ?? ''),
                    'topic'               => (string)($docRow['topic'] ?? ''),
                    'status'              => $newDocStatus,
                    'version_status'      => $newVersionStatus,
                    'version_label'       => (string)($docRow['version_label'] ?? ''),
                    'approver_user_id'    => (string)($docRow['approver'] ?? ''),
                    'review_comments'     => $remarks
                ];

                write_audit_log(
                    $conn,
                    'document',
                    $documentId,
                    'approve',
                    $oldSnapshot,
                    $newSnapshot,
                    $userId,
                    $remarks !== '' ? $remarks : 'Document approved from assigned documents.'
                );

                $message = 'Document approved successfully.';
            } elseif ($action === 'returned') {
                $newDocStatus = 'draft';
                $newVersionStatus = 'returned';

                $updDoc = mysqli_prepare($conn, "
                    UPDATE documents
                    SET current_status = ?, review_comments = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                if (!$updDoc) {
                    throw new Exception('Failed to update document.');
                }
                mysqli_stmt_bind_param($updDoc, "ssii", $newDocStatus, $remarks, $userId, $documentId);
                mysqli_stmt_execute($updDoc);
                mysqli_stmt_close($updDoc);

                if ($currentVersionId > 0) {
                    $updVer = mysqli_prepare($conn, "
                        UPDATE document_versions
                        SET status = ?, returned_by = ?, returned_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ");
                    if (!$updVer) {
                        throw new Exception('Failed to update document version.');
                    }
                    mysqli_stmt_bind_param($updVer, "sii", $newVersionStatus, $userId, $currentVersionId);
                    mysqli_stmt_execute($updVer);
                    mysqli_stmt_close($updVer);
                }

                $newSnapshot = [
                    'document_id'         => (int)$docRow['id'],
                    'document_version_id' => $currentVersionId,
                    'document_number'     => (string)($docRow['document_number'] ?? ''),
                    'title'               => (string)($docRow['title'] ?? ''),
                    'topic'               => (string)($docRow['topic'] ?? ''),
                    'status'              => $newDocStatus,
                    'version_status'      => $newVersionStatus,
                    'version_label'       => (string)($docRow['version_label'] ?? ''),
                    'approver_user_id'    => (string)($docRow['approver'] ?? ''),
                    'review_comments'     => $remarks
                ];

                write_audit_log(
                    $conn,
                    'document',
                    $documentId,
                    'return',
                    $oldSnapshot,
                    $newSnapshot,
                    $userId,
                    $remarks !== '' ? $remarks : 'Document returned from assigned documents.'
                );

                $message = 'Document returned successfully.';
            } else {
                $newDocStatus = 'rejected';
                $newVersionStatus = 'rejected';

                $updDoc = mysqli_prepare($conn, "
                    UPDATE documents
                    SET current_status = ?, review_comments = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                if (!$updDoc) {
                    throw new Exception('Failed to update document.');
                }
                mysqli_stmt_bind_param($updDoc, "ssii", $newDocStatus, $remarks, $userId, $documentId);
                mysqli_stmt_execute($updDoc);
                mysqli_stmt_close($updDoc);

                if ($currentVersionId > 0) {
                    $updVer = mysqli_prepare($conn, "
                        UPDATE document_versions
                        SET status = ?, rejected_by = ?, rejected_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ");
                    if (!$updVer) {
                        throw new Exception('Failed to update document version.');
                    }
                    mysqli_stmt_bind_param($updVer, "sii", $newVersionStatus, $userId, $currentVersionId);
                    mysqli_stmt_execute($updVer);
                    mysqli_stmt_close($updVer);
                }

                $newSnapshot = [
                    'document_id'         => (int)$docRow['id'],
                    'document_version_id' => $currentVersionId,
                    'document_number'     => (string)($docRow['document_number'] ?? ''),
                    'title'               => (string)($docRow['title'] ?? ''),
                    'topic'               => (string)($docRow['topic'] ?? ''),
                    'status'              => $newDocStatus,
                    'version_status'      => $newVersionStatus,
                    'version_label'       => (string)($docRow['version_label'] ?? ''),
                    'approver_user_id'    => (string)($docRow['approver'] ?? ''),
                    'review_comments'     => $remarks
                ];

                write_audit_log(
                    $conn,
                    'document',
                    $documentId,
                    'reject',
                    $oldSnapshot,
                    $newSnapshot,
                    $userId,
                    $remarks !== '' ? $remarks : 'Document rejected from assigned documents.'
                );

                $message = 'Document rejected successfully.';
            }

            mysqli_commit($conn);
            header('Location: assigned-documents.php?msg=' . urlencode($message));
            exit;
        } catch (Throwable $th) {
            mysqli_rollback($conn);
            $error = $th->getMessage();
        }
    }
}

if ($message === '' && isset($_GET['msg']) && trim((string)$_GET['msg']) !== '') {
    $message = trim((string)$_GET['msg']);
}

/* ---------------------------------------------------------
   FILTERS
--------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$viewId = (int)($_GET['view_id'] ?? 0);

$types = [];
$typeRes = mysqli_query($conn, "SELECT DISTINCT type_name FROM document_types WHERE status = 'active' ORDER BY type_name ASC");
if ($typeRes) {
    while ($row = mysqli_fetch_assoc($typeRes)) {
        $types[] = $row['type_name'];
    }
    mysqli_free_result($typeRes);
}

/* ---------------------------------------------------------
   VIEW DETAILS
--------------------------------------------------------- */
$viewDocument = null;
$viewParsedContent = null;

if ($viewId > 0) {
    $viewSql = "
        SELECT
            d.id AS document_id,
            d.document_number,
            d.title,
            d.topic,
            d.current_status,
            d.approver,
            d.review_comments,
            dt.type_name,
            dept.department_name,
            dv.id AS document_version_id,
            dv.version_label,
            dv.title_snapshot,
            dv.topic_snapshot,
            dv.effective_date,
            dv.review_date,
            dv.status AS version_status,
            dv.content_text,
            dv.primary_file_name,
            dv.primary_file_path,
            dv.submitted_at,
            CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,'')) AS owner_name,
            CONCAT(COALESCE(submitter.first_name,''), ' ', COALESCE(submitter.last_name,'')) AS submitted_by_name,
            CONCAT(COALESCE(apr.first_name,''), ' ', COALESCE(apr.last_name,'')) AS approver_name
        FROM documents d
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        LEFT JOIN departments dept ON dept.id = d.department_id
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        LEFT JOIN users owner ON owner.id = d.owner_user_id
        LEFT JOIN users submitter ON submitter.id = dv.submitted_by
        LEFT JOIN users apr ON apr.id = CAST(d.approver AS UNSIGNED)
        WHERE d.id = ?
          AND d.approver = ?
          AND LOWER(COALESCE(d.current_status, 'draft')) <> 'draft'
        LIMIT 1
    ";
    $viewStmt = mysqli_prepare($conn, $viewSql);
    if ($viewStmt) {
        $userIdStr = (string)$userId;
        mysqli_stmt_bind_param($viewStmt, "is", $viewId, $userIdStr);
        mysqli_stmt_execute($viewStmt);
        $viewRes = mysqli_stmt_get_result($viewStmt);
        $viewDocument = ($viewRes && mysqli_num_rows($viewRes) > 0) ? mysqli_fetch_assoc($viewRes) : null;
        mysqli_stmt_close($viewStmt);

        if ($viewDocument) {
            $viewParsedContent = parseDocumentContent($viewDocument['content_text'] ?? '');
        }
    }
}

/* ---------------------------------------------------------
   LIST QUERY
--------------------------------------------------------- */
$sql = "
    SELECT
        d.id AS document_id,
        d.document_number,
        d.title,
        d.topic,
        d.current_status,
        d.approver,
        d.review_comments,
        dt.type_name,
        dept.department_name,
        dv.id AS document_version_id,
        dv.version_label,
        dv.title_snapshot,
        dv.topic_snapshot,
        dv.effective_date,
        dv.review_date,
        dv.status AS version_status,
        dv.content_text,
        dv.primary_file_name,
        dv.primary_file_path,
        dv.submitted_at,
        CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,'')) AS owner_name,
        CONCAT(COALESCE(submitter.first_name,''), ' ', COALESCE(submitter.last_name,'')) AS submitted_by_name,
        CONCAT(COALESCE(apr.first_name,''), ' ', COALESCE(apr.last_name,'')) AS approver_name
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    LEFT JOIN departments dept ON dept.id = d.department_id
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    LEFT JOIN users owner ON owner.id = d.owner_user_id
    LEFT JOIN users submitter ON submitter.id = dv.submitted_by
    LEFT JOIN users apr ON apr.id = CAST(d.approver AS UNSIGNED)
    WHERE d.approver = ?
      AND LOWER(COALESCE(d.current_status, 'draft')) <> 'draft'
";

$params = [(string)$userId];
$bindTypes = 's';

if ($statusFilter !== '' && $statusFilter !== 'all') {
    $sql .= " AND LOWER(COALESCE(d.current_status, '')) = ?";
    $params[] = strtolower($statusFilter);
    $bindTypes .= 's';
}

if ($typeFilter !== '') {
    $sql .= " AND dt.type_name = ?";
    $params[] = $typeFilter;
    $bindTypes .= 's';
}

if ($search !== '') {
    $sql .= " AND (
        d.document_number LIKE ?
        OR d.title LIKE ?
        OR d.topic LIKE ?
        OR dv.title_snapshot LIKE ?
        OR dv.topic_snapshot LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $bindTypes .= 'sssss';
}

$sql .= " ORDER BY
            CASE WHEN LOWER(COALESCE(d.current_status, '')) = 'pending_approval' THEN 0 ELSE 1 END,
            d.id DESC
          LIMIT 500";

$rows = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $bindTypes, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);
}

/* ---------------------------------------------------------
   SUMMARY
--------------------------------------------------------- */
$totalAssigned = 0;
$totalPending = 0;
$totalApproved = 0;
$totalRejectedReturned = 0;

$summarySql = "
    SELECT d.current_status
    FROM documents d
    WHERE d.approver = ?
      AND LOWER(COALESCE(d.current_status, 'draft')) <> 'draft'
";
$summaryStmt = mysqli_prepare($conn, $summarySql);
if ($summaryStmt) {
    $userIdStr = (string)$userId;
    mysqli_stmt_bind_param($summaryStmt, "s", $userIdStr);
    mysqli_stmt_execute($summaryStmt);
    $summaryRes = mysqli_stmt_get_result($summaryStmt);

    if ($summaryRes) {
        while ($row = mysqli_fetch_assoc($summaryRes)) {
            $totalAssigned++;
            $status = strtolower((string)($row['current_status'] ?? ''));

            if ($status === 'pending_approval') {
                $totalPending++;
            } elseif (in_array($status, ['approved', 'effective'], true)) {
                $totalApproved++;
            } elseif (in_array($status, ['rejected', 'returned'], true)) {
                $totalRejectedReturned++;
            }
        }
        mysqli_free_result($summaryRes);
    }
    mysqli_stmt_close($summaryStmt);
}

$filteredCount = count($rows);
$baseQuery = [
    'search' => $search,
    'status' => $statusFilter,
    'type'   => $typeFilter,
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Assigned Documents</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .table td, .table th { vertical-align: middle; }
    .repo-filter-grid {
      display: grid;
      grid-template-columns: 1.5fr 1fr 1fr auto;
      gap: 10px;
      align-items: end;
    }
    .repo-filter-group label {
      font-size: 12px;
      font-weight: 600;
      color: #5f6b7a;
      margin-bottom: 6px;
      display: block;
    }
    .repo-filter-group .form-control-sm,
    .repo-filter-group .form-select-sm {
      height: 40px;
      border-radius: 8px;
      font-size: 14px;
      padding-left: 12px;
      padding-right: 12px;
    }
    .repo-inline-actions {
      display: flex;
      align-items: end;
      gap: 8px;
      flex-wrap: nowrap;
      min-width: 190px;
    }
    .repo-inline-actions .btn {
      height: 40px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      padding: 0 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      white-space: nowrap;
    }
    .repo-modal-label {
      font-size: 12px;
      color: #6b7280;
      font-weight: 600;
      margin-bottom: 4px;
      text-transform: uppercase;
      letter-spacing: .3px;
    }
    .repo-modal-value {
      font-size: 14px;
      color: #1f2937;
      font-weight: 500;
    }
    .repo-modal-box {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      padding: 14px;
      height: 100%;
    }
    .repo-purpose-box {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      padding: 14px;
      font-size: 14px;
      color: #1f2937;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .repo-form-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }
    .repo-form-table th,
    .repo-form-table td {
      border: 1px solid #e5e7eb;
      padding: 10px 12px;
      vertical-align: top;
    }
    .repo-form-table th {
      width: 30%;
      background: #eef3fb;
      color: #0D2144;
      font-weight: 700;
    }
    .repo-json-pre {
      white-space: pre-wrap;
      word-wrap: break-word;
      margin: 0;
      font-family: inherit;
      font-size: 14px;
      color: #1f2937;
    }
    .repo-file-preview-wrap {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 12px;
      padding: 12px;
    }
    .repo-file-preview-frame {
      width: 100%;
      height: 560px;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      background: #fff;
    }
    .repo-file-preview-image {
      width: 100%;
      max-height: 560px;
      object-fit: contain;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      background: #fff;
    }
    .action-btn {
      height: 32px;
      padding: 0 12px;
      font-size: 12px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      white-space: nowrap;
    }
    .approve-box {
      background: #f8fbff;
      border: 1px solid #d9e7ff;
      border-radius: 12px;
      padding: 16px;
    }
    .approve-box textarea {
      min-height: 90px;
      resize: vertical;
    }

    @media (max-width: 1199.98px) {
      .repo-filter-grid { grid-template-columns: 1fr 1fr; }
      .repo-inline-actions { grid-column: 1 / -1; min-width: 100%; }
    }

    @media (max-width: 767.98px) {
      .repo-filter-grid { grid-template-columns: 1fr; }
      .repo-inline-actions { width: 100%; }
      .repo-inline-actions .btn { flex: 1 1 auto; }
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
        <li class="nav-item">
          <a class="nav-link" href="dashboard-admin.php">Dashboard</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item" href="repository.php">Repository</a></li>
            <li><a class="dropdown-item active" href="assigned-documents.php">Assigned Documents</a></li>
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

        <li class="nav-item">
          <a class="nav-link" href="portal-select.php">Switch to User</a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($roleName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small"><?php echo e($displayName); ?></span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <div class="mb-4">
    <h1 class="page-title mb-2">Assigned Documents</h1>
    <p class="page-subtitle mb-0">Only documents assigned to you as approver are shown here.</p>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-success py-2"><?php echo e($message); ?></div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger py-2"><?php echo e($error); ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Total Assigned</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$totalAssigned; ?></div>
          </div>
          <span style="font-size:1.8rem;">📄</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Pending</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$totalPending; ?></div>
          </div>
          <span style="font-size:1.8rem;">🕒</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Approved / Effective</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$totalApproved; ?></div>
          </div>
          <span style="font-size:1.8rem;">✅</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Rejected / Returned</div>
            <div class="stat-value" style="font-size:1.6rem;color:#dc2626;"><?php echo (int)$totalRejectedReturned; ?></div>
          </div>
          <span style="font-size:1.8rem;">⚠️</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card cp-card mb-3">
    <div class="card-body">
      <form method="get" id="assignedFilterForm">
        <div class="repo-filter-grid">
          <div class="repo-filter-group">
            <label class="form-label mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="search" value="<?php echo e($search); ?>" placeholder="Search ID or title...">
          </div>

          <div class="repo-filter-group">
            <label class="form-label mb-1">Status</label>
            <select class="form-select form-select-sm auto-submit-filter" name="status">
              <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
              <option value="pending_approval" <?php echo $statusFilter === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
              <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
              <option value="effective" <?php echo $statusFilter === 'effective' ? 'selected' : ''; ?>>Effective</option>
              <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
              <option value="returned" <?php echo $statusFilter === 'returned' ? 'selected' : ''; ?>>Returned</option>
            </select>
          </div>

          <div class="repo-filter-group">
            <label class="form-label mb-1">Type</label>
            <select class="form-select form-select-sm auto-submit-filter" name="type">
              <option value="">All Types</option>
              <?php foreach ($types as $type): ?>
                <option value="<?php echo e($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                  <?php echo e($type); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="repo-inline-actions">
            <a class="btn btn-sm btn-outline-secondary" href="assigned-documents.php">Reset</a>
            <button class="btn btn-sm btn-primary" type="submit">Search</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card cp-card" style="padding:0;">
    <table class="table mb-0">
      <thead>
        <tr>
          <th>Document ID</th>
          <th>Title</th>
          <th>Type</th>
          <th>Version</th>
          <th>Department</th>
          <th>Submitted By</th>
          <th>Approver</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($rows)): ?>
          <?php foreach ($rows as $row): ?>
            <?php
              $docId = (int)$row['document_id'];
              $docNumber = trim((string)($row['document_number'] ?? '')) !== '' ? (string)$row['document_number'] : ('DOC-' . $docId);
              $title = trim((string)($row['title_snapshot'] ?? '')) !== '' ? (string)$row['title_snapshot'] : ((trim((string)($row['title'] ?? '')) !== '') ? (string)$row['title'] : 'Untitled');
              $type = trim((string)($row['type_name'] ?? '')) !== '' ? (string)$row['type_name'] : '—';
              $version = trim((string)($row['version_label'] ?? '')) !== '' ? (string)$row['version_label'] : '—';
              $department = trim((string)($row['department_name'] ?? '')) !== '' ? (string)$row['department_name'] : '—';
              $submittedBy = trim((string)($row['submitted_by_name'] ?? '')) !== '' ? (string)$row['submitted_by_name'] : '—';
              $approverName = trim((string)($row['approver_name'] ?? '')) !== '' ? (string)$row['approver_name'] : (string)$row['approver'];
              $statusValue = strtolower((string)($row['current_status'] ?? ''));
              $viewUrl = 'assigned-documents.php?' . http_build_query(array_merge($baseQuery, ['view_id' => $docId]));
            ?>
            <tr>
              <td class="fw-semibold" style="color:#2563eb;font-size:13px;"><?php echo e($docNumber); ?></td>
              <td style="font-size:13px;"><?php echo e($title); ?></td>
              <td><span class="badge badge-soft-info"><?php echo e($type); ?></span></td>
              <td style="font-size:13px;"><?php echo e($version); ?></td>
              <td style="font-size:12px;color:#6b7280;"><?php echo e($department); ?></td>
              <td style="font-size:12px;color:#6b7280;"><?php echo e($submittedBy); ?></td>
              <td style="font-size:12px;color:#6b7280;"><?php echo e($approverName); ?></td>
              <td><span class="<?php echo e(statusBadgeClass($statusValue)); ?>"><?php echo e(statusLabel($statusValue)); ?></span></td>
              <td style="white-space:nowrap;">
                <a class="btn btn-sm btn-outline-primary action-btn" href="<?php echo e($viewUrl); ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="9" class="text-center text-secondary py-4 small">No assigned documents found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="px-4 py-2 border-top d-flex justify-content-between align-items-center">
      <span class="small text-secondary">Showing <?php echo (int)$filteredCount; ?> documents</span>
      <span class="small" style="color:#0D2144;font-weight:600;">Only your approver-assigned non-draft documents are visible here.</span>
    </div>
  </div>

</div>
</main>

<?php if ($viewDocument): ?>
<?php
    $modalDocNumber = trim((string)($viewDocument['document_number'] ?? '')) !== '' ? (string)$viewDocument['document_number'] : ('DOC-' . (int)$viewDocument['document_id']);
    $modalTitle = trim((string)($viewDocument['title_snapshot'] ?? '')) !== '' ? (string)$viewDocument['title_snapshot'] : ((trim((string)($viewDocument['title'] ?? '')) !== '') ? (string)$viewDocument['title'] : 'Untitled');
    $modalOwner = trim((string)($viewDocument['owner_name'] ?? '')) !== '' ? (string)$viewDocument['owner_name'] : '—';
    $modalSubmittedBy = trim((string)($viewDocument['submitted_by_name'] ?? '')) !== '' ? (string)$viewDocument['submitted_by_name'] : '—';
    $modalApprover = trim((string)($viewDocument['approver_name'] ?? '')) !== '' ? (string)$viewDocument['approver_name'] : (string)$viewDocument['approver'];
    $modalStatusValue = strtolower((string)($viewDocument['current_status'] ?? ''));
    $filePath = trim((string)($viewDocument['primary_file_path'] ?? ''));
    $fileName = trim((string)($viewDocument['primary_file_name'] ?? ''));
    $fileExt = getFileExtensionSafe($filePath);
    $canInlinePreview = $filePath !== '' && canPreviewInline($filePath);
    $isImagePreview = $filePath !== '' && isImageFile($filePath);
?>
<div class="modal fade" id="documentViewModal" tabindex="-1" aria-labelledby="documentViewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 980px;">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
      <div class="modal-header" style="background:#f8fbff;border-bottom:1px solid #e8edf3;">
        <div>
          <h5 class="modal-title fw-bold mb-1" id="documentViewModalLabel">Assigned Document View</h5>
          <div class="small text-secondary"><?php echo e($modalDocNumber); ?> - <?php echo e($modalTitle); ?></div>
        </div>
        <a href="assigned-documents.php?<?php echo e(http_build_query($baseQuery)); ?>" class="btn-close"></a>
      </div>

      <div class="modal-body p-4">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Type</div>
              <div class="repo-modal-value"><?php echo e($viewDocument['type_name'] ?: '—'); ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Version</div>
              <div class="repo-modal-value"><?php echo e($viewDocument['version_label'] ?: '—'); ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Status</div>
              <div class="repo-modal-value"><span class="<?php echo e(statusBadgeClass($modalStatusValue)); ?>"><?php echo e(statusLabel($modalStatusValue)); ?></span></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Department</div>
              <div class="repo-modal-value"><?php echo e($viewDocument['department_name'] ?: '—'); ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Owner</div>
              <div class="repo-modal-value"><?php echo e($modalOwner); ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Submitted By</div>
              <div class="repo-modal-value"><?php echo e($modalSubmittedBy); ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Approver</div>
              <div class="repo-modal-value"><?php echo e($modalApprover); ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Effective Date</div>
              <div class="repo-modal-value"><?php echo e(formatDateDisplay($viewDocument['effective_date'] ?? '')); ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Review Date</div>
              <div class="repo-modal-value"><?php echo e(formatDateDisplay($viewDocument['review_date'] ?? '')); ?></div>
            </div>
          </div>

          <div class="col-md-8">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Title</div>
              <div class="repo-modal-value"><?php echo e($modalTitle); ?></div>
            </div>
          </div>
        </div>

        <?php if ($viewParsedContent && $viewParsedContent['is_json_form']): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Purpose & Scope</label>
            <div class="repo-purpose-box">
              <?php echo $viewParsedContent['purpose_scope'] !== '' ? nl2br(e($viewParsedContent['purpose_scope'])) : '—'; ?>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Form Response Details</label>
            <?php if (!empty($viewParsedContent['form_responses'])): ?>
              <div class="table-responsive">
                <table class="repo-form-table">
                  <thead>
                    <tr>
                      <th>Field</th>
                      <th>Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($viewParsedContent['form_responses'] as $fieldKey => $fieldValue): ?>
                      <tr>
                        <td><?php echo e(formatFormResponseLabel($fieldKey)); ?></td>
                        <td>
                          <?php
                            if (is_array($fieldValue)) {
                                echo '<pre class="repo-json-pre">' . e(json_encode($fieldValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
                            } else {
                                $displayValue = (string)$fieldValue;
                                echo $displayValue !== '' ? nl2br(e($displayValue)) : '—';
                            }
                          ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="repo-purpose-box">No form response details available.</div>
            <?php endif; ?>
          </div>
        <?php elseif (!empty($viewDocument['content_text'])): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Document Content</label>
            <div class="repo-purpose-box">
              <pre class="repo-json-pre"><?php echo e($viewDocument['content_text']); ?></pre>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($filePath !== ''): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Attached File</label>
            <div class="repo-file-preview-wrap">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                  <strong><?php echo e($fileName !== '' ? $fileName : basename($filePath)); ?></strong>
                  <div class="small text-secondary"><?php echo e(strtoupper($fileExt !== '' ? $fileExt : 'FILE')); ?> file preview</div>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?php echo e($filePath); ?>" target="_blank">Open File</a>
              </div>

              <?php if ($canInlinePreview): ?>
                <?php if ($isImagePreview): ?>
                  <img src="<?php echo e($filePath); ?>" alt="<?php echo e($fileName); ?>" class="repo-file-preview-image">
                <?php else: ?>
                  <iframe src="<?php echo e($filePath); ?>" class="repo-file-preview-frame"></iframe>
                <?php endif; ?>
              <?php else: ?>
                <div class="repo-purpose-box">
                  Inline preview is not available for this file type.
                  <div class="mt-2">
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo e($filePath); ?>" target="_blank">Open File</a>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif (empty($viewDocument['content_text'])): ?>
          <div class="repo-purpose-box mb-3">No document content available.</div>
        <?php endif; ?>

        <?php if ($modalStatusValue === 'pending_approval'): ?>
          <div class="approve-box">
            <div class="fw-semibold mb-3" style="color:#0D2144;">Approval Action</div>

            <form method="post" class="mb-3">
              <input type="hidden" name="document_id" value="<?php echo (int)$viewDocument['document_id']; ?>">
              <input type="hidden" name="approve_action" value="approved">
              <div class="mb-2">
                <label class="form-label">Approval Remarks</label>
                <textarea name="remarks" class="form-control" placeholder="Enter approval remarks..."></textarea>
              </div>
              <button type="submit" class="btn btn-success">Approve Document</button>
            </form>

            <form method="post" class="mb-3">
              <input type="hidden" name="document_id" value="<?php echo (int)$viewDocument['document_id']; ?>">
              <input type="hidden" name="approve_action" value="returned">
              <div class="mb-2">
                <label class="form-label">Return Remarks</label>
                <textarea name="remarks" class="form-control" placeholder="Enter reason for return..." required></textarea>
              </div>
              <button type="submit" class="btn btn-warning">Return Document</button>
            </form>

            <form method="post">
              <input type="hidden" name="document_id" value="<?php echo (int)$viewDocument['document_id']; ?>">
              <input type="hidden" name="approve_action" value="rejected">
              <div class="mb-2">
                <label class="form-label">Rejection Remarks</label>
                <textarea name="remarks" class="form-control" placeholder="Enter rejection reason..." required></textarea>
              </div>
              <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to reject this document?');">Reject Document</button>
            </form>
          </div>
        <?php else: ?>
          <div class="repo-purpose-box">
            This document has already been processed.
            <?php if (trim((string)($viewDocument['review_comments'] ?? '')) !== ''): ?>
              <div class="mt-2"><strong>Remarks:</strong> <?php echo nl2br(e($viewDocument['review_comments'])); ?></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="modal-footer">
        <a href="assigned-documents.php?<?php echo e(http_build_query($baseQuery)); ?>" class="btn btn-outline-secondary">Close</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.auto-submit-filter').forEach(function(el) {
  el.addEventListener('change', function() {
    document.getElementById('assignedFilterForm').submit();
  });
});

<?php if ($viewDocument): ?>
document.addEventListener('DOMContentLoaded', function () {
  const modalEl = document.getElementById('documentViewModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    modalEl.addEventListener('hidden.bs.modal', function () {
      window.location.href = 'assigned-documents.php?<?php echo e(http_build_query($baseQuery)); ?>';
    });
  }
});
<?php endif; ?>
</script>
</body>
</html>