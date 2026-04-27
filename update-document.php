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
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('increment_version_label')) {
    function increment_version_label(string $current): string
    {
        $current = trim($current);
        if ($current === '') return '02';

        if (preg_match('/^([A-Za-z]+)(\d+)$/', $current, $m)) {
            $prefix = $m[1];
            $num = (int)$m[2] + 1;
            $padded = str_pad((string)$num, strlen($m[2]), '0', STR_PAD_LEFT);
            return $prefix . $padded;
        }

        if (ctype_digit($current)) {
            return str_pad((string)(((int)$current) + 1), max(2, strlen($current)), '0', STR_PAD_LEFT);
        }

        return $current . '-R1';
    }
}

if (!function_exists('upload_document_file')) {
    function upload_document_file($file): ?array
    {
        if (
            !isset($file['tmp_name'], $file['name']) ||
            !is_uploaded_file($file['tmp_name']) ||
            (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            return null;
        }

        $maxSize = 25 * 1024 * 1024;
        if ((int)$file['size'] > $maxSize) {
            throw new RuntimeException('Uploaded file exceeds maximum size of 25 MB.');
        }

        $uploadDir = __DIR__ . '/uploads/documents/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Unable to create upload directory.');
        }

        $originalName = (string)$file['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Invalid file type. Allowed: PDF, DOC, DOCX, XLS, XLSX, TXT.');
        }

        $storedName = uniqid('doc_', true) . '.' . $ext;
        $targetPath = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to upload document file.');
        }

        $mimeType = function_exists('mime_content_type')
            ? (mime_content_type($targetPath) ?: ($file['type'] ?? 'application/octet-stream'))
            : ($file['type'] ?? 'application/octet-stream');

        return [
            'original_name' => $originalName,
            'stored_name'   => $storedName,
            'path'          => 'uploads/documents/' . $storedName,
            'mime'          => $mimeType,
            'size'          => (int)filesize($targetPath),
            'sha256'        => hash_file('sha256', $targetPath),
        ];
    }
}

if (!function_exists('parse_document_json_content')) {
    function parse_document_json_content($contentText): array
    {
        $result = [
            'is_json_form' => false,
            'purpose_scope' => '',
            'form_responses' => [],
            'raw_text' => (string)$contentText,
        ];

        $contentText = trim((string)$contentText);
        if ($contentText === '') {
            return $result;
        }

        $decoded = json_decode($contentText, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (array_key_exists('purpose_scope', $decoded) || array_key_exists('form_responses', $decoded)) {
                $result['is_json_form'] = true;
                $result['purpose_scope'] = (string)($decoded['purpose_scope'] ?? '');
                $result['form_responses'] = is_array($decoded['form_responses'] ?? null) ? $decoded['form_responses'] : [];
            }
        }

        return $result;
    }
}

if (!function_exists('format_field_label')) {
    function format_field_label(string $key): string
    {
        $key = trim($key);
        if ($key === '') return 'Field';
        $key = str_replace(['_', '-'], ' ', $key);
        return ucwords($key);
    }
}

if (!function_exists('normalize_form_response_value')) {
    function normalize_form_response_value($key, $value)
    {
        $key = strtolower(trim((string)$key));
        $value = is_string($value) ? trim($value) : $value;

        if (in_array($key, ['dob', 'date', 'birth_date', 'date_of_birth'], true)) {
            if (is_string($value) && preg_match('/^\d{13}$/', $value)) {
                $ts = (int) substr($value, 0, 10);
                return date('Y-m-d', $ts);
            }
            if (is_string($value) && preg_match('/^\d{10}$/', $value)) {
                return date('Y-m-d', (int)$value);
            }
            if (is_string($value) && strtotime($value) !== false) {
                return date('Y-m-d', strtotime($value));
            }
        }

        return $value;
    }
}

if (!function_exists('write_audit_log')) {
    function write_audit_log(mysqli $conn, int $documentId, int $versionId, string $action, int $performedBy, array $newValue = []): void
    {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $eventId = generate_uuid_v4();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $newJson = !empty($newValue) ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $sql = "
            INSERT INTO audit_logs
            (event_id, entity_type, entity_id, action, old_value, new_value, performed_by, performed_at, remarks, ip_address, user_agent)
            VALUES (?, 'document', ?, ?, NULL, ?, ?, NOW(), ?, ?, ?)
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            $remarks = ($action === 'submit_review') ? 'Document updated and submitted for review.' : 'Document update draft saved.';
            mysqli_stmt_bind_param(
                $stmt,
                "sississs",
                $eventId,
                $documentId,
                $action,
                $newJson,
                $performedBy,
                $remarks,
                $ipAddress,
                $userAgent
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
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
        u.email,
        u.current_role_id,
        u.department_id,
        u.status,
        r.role_code,
        r.role_name,
        d.department_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    LEFT JOIN departments d ON d.id = u.department_id
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
$departments = [];
$owners = [];
$approvers = [];

$docTypeRes = mysqli_query($conn, "SELECT id, type_name, prefix FROM document_types WHERE status = 'active' ORDER BY type_name ASC");
if ($docTypeRes) {
    while ($row = mysqli_fetch_assoc($docTypeRes)) {
        $documentTypes[] = $row;
    }
}

$deptRes = mysqli_query($conn, "SELECT id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC");
if ($deptRes) {
    while ($row = mysqli_fetch_assoc($deptRes)) {
        $departments[] = $row;
    }
}

$userOptionSql = "
    SELECT id, TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) AS name
    FROM users
    WHERE status = 'active'
    ORDER BY first_name ASC, last_name ASC, email ASC
";
$userOptionRes = mysqli_query($conn, $userOptionSql);
if ($userOptionRes) {
    while ($row = mysqli_fetch_assoc($userOptionRes)) {
        $name = trim((string)$row['name']);
        if ($name === '') {
            $name = 'User #' . (int)$row['id'];
        }
        $row['name'] = $name;
        $owners[] = $row;
        if ((int)$row['id'] !== $userId) {
            $approvers[] = $row;
        }
    }
}

$filterId = trim((string)($_GET['filter_id'] ?? ''));
$selectedDocumentId = (int)($_GET['id'] ?? ($_POST['selected_document_id'] ?? 0));

$documentList = [];
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
        d.department_id,
        dt.type_name,
        dt.prefix,
        dv.version_label,
        dv.effective_date,
        dv.review_date
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE d.current_status IN ('draft','pending_approval','effective')
";
$listTypes = '';
$listParams = [];

if ($filterId !== '') {
    $listSql .= " AND (d.document_number LIKE ? OR d.id = ?)";
    $listTypes = 'si';
    $listParams[] = '%' . $filterId . '%';
    $listParams[] = (int)$filterId;
}

$listSql .= " ORDER BY dt.type_name ASC, d.id DESC";

$listStmt = mysqli_prepare($conn, $listSql);
if ($listStmt) {
    if ($listTypes !== '') {
        mysqli_stmt_bind_param($listStmt, $listTypes, ...$listParams);
    }
    mysqli_stmt_execute($listStmt);
    $listRes = mysqli_stmt_get_result($listStmt);
    if ($listRes) {
        while ($row = mysqli_fetch_assoc($listRes)) {
            $documentList[] = $row;
        }
    }
    mysqli_stmt_close($listStmt);
}

$documentsByType = [];
foreach ($documentList as $doc) {
    $typeName = (string)($doc['type_name'] ?? 'Other');
    if (!isset($documentsByType[$typeName])) {
        $documentsByType[$typeName] = [];
    }

    $ownerName = '';
    foreach ($owners as $owner) {
        if ((int)$owner['id'] === (int)$doc['owner_user_id']) {
            $ownerName = (string)$owner['name'];
            break;
        }
    }

    $documentsByType[$typeName][] = [
        'id' => (int)$doc['id'],
        'doc_id' => (string)($doc['document_number'] ?? ''),
        'topic' => (string)($doc['title'] ?: $doc['topic']),
        'number' => (string)($doc['document_number'] ?? ''),
        'version' => (string)($doc['version_label'] ?? '01'),
        'owner' => $ownerName,
        'status' => (string)($doc['current_status'] ?? ''),
        'effectiveDate' => !empty($doc['effective_date']) ? (string)$doc['effective_date'] : '',
        'reviewDate' => !empty($doc['review_date']) ? (string)$doc['review_date'] : '',
    ];
}

$selectedDocument = null;
$currentVersionId = 0;
$currentVersionLabel = '';
$nextVersionLabel = '';
$existingFileName = '';
$existingFilePath = '';
$lockedOwnerName = '—';
$parsedContent = [
    'is_json_form' => false,
    'purpose_scope' => '',
    'form_responses' => [],
    'raw_text' => '',
];

$form = [
    'document_id'         => '',
    'document_type_id'    => '',
    'department_id'       => '',
    'title'               => '',
    'topic'               => '',
    'document_number'     => '',
    'owner_user_id'       => '',
    'approver_user_id'    => '',
    'effective_date'      => '',
    'review_date'         => '',
    'change_summary'      => '',
    'content_text'        => '',
    'current_version'     => '',
    'next_version'        => ''
];

$errors = [];
$successMessage = '';
$badgeClass = 'badge badge-soft-info';
$badgeLabel = 'Under Review';

if ($selectedDocumentId > 0) {
    $detailSql = "
        SELECT
            d.*,
            dt.type_name,
            dt.prefix,
            dv.id AS version_id,
            dv.version_label,
            dv.version_sequence,
            dv.title_snapshot,
            dv.topic_snapshot,
            dv.owner_user_id AS version_owner_user_id,
            dv.change_summary,
            dv.effective_date,
            dv.review_date,
            dv.status AS version_status,
            dv.content_text,
            dv.content_format,
            dv.primary_file_name,
            dv.primary_file_path,
            dv.primary_file_mime,
            dv.primary_file_size,
            dv.checksum_sha256
        FROM documents d
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE d.id = ?
        LIMIT 1
    ";
    $detailStmt = mysqli_prepare($conn, $detailSql);
    if ($detailStmt) {
        mysqli_stmt_bind_param($detailStmt, "i", $selectedDocumentId);
        mysqli_stmt_execute($detailStmt);
        $detailRes = mysqli_stmt_get_result($detailStmt);
        $selectedDocument = ($detailRes && mysqli_num_rows($detailRes) > 0) ? mysqli_fetch_assoc($detailRes) : null;
        mysqli_stmt_close($detailStmt);
    }

    if ($selectedDocument) {
        $currentVersionId = (int)($selectedDocument['version_id'] ?? 0);
        $currentVersionLabel = (string)($selectedDocument['version_label'] ?? '01');
        $nextVersionLabel = increment_version_label($currentVersionLabel);
        $existingFileName = (string)($selectedDocument['primary_file_name'] ?? '');
        $existingFilePath = (string)($selectedDocument['primary_file_path'] ?? '');

        $form['document_id'] = (string)$selectedDocument['id'];
        $form['document_type_id'] = (string)($selectedDocument['document_type_id'] ?? '');
        $form['department_id'] = (string)($selectedDocument['department_id'] ?? '');
        $form['title'] = (string)($selectedDocument['title_snapshot'] ?: $selectedDocument['title']);
        $form['topic'] = (string)($selectedDocument['topic_snapshot'] ?: $selectedDocument['topic']);
        $form['document_number'] = (string)($selectedDocument['document_number'] ?? '');
        $form['owner_user_id'] = (string)(($selectedDocument['version_owner_user_id'] ?? 0) ?: ($selectedDocument['owner_user_id'] ?? ''));
        $form['approver_user_id'] = (string)($selectedDocument['approver'] ?? '');
        $form['effective_date'] = !empty($selectedDocument['effective_date']) ? (string)$selectedDocument['effective_date'] : date('Y-m-d');
        $form['review_date'] = !empty($selectedDocument['review_date']) ? (string)$selectedDocument['review_date'] : date('Y-m-d', strtotime('+2 years'));
        $form['change_summary'] = (string)($selectedDocument['change_summary'] ?? '');
        $form['content_text'] = (string)($selectedDocument['content_text'] ?? '');
        $form['current_version'] = $currentVersionLabel;
        $form['next_version'] = $nextVersionLabel;

        $parsedContent = parse_document_json_content($form['content_text']);

        foreach ($owners as $owner) {
            if ((string)$owner['id'] === (string)$form['owner_user_id']) {
                $lockedOwnerName = (string)$owner['name'];
                break;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_action']) && $selectedDocument) {
    $updateAction = trim((string)$_POST['update_action']);
    $selectedDocumentId = (int)($_POST['selected_document_id'] ?? 0);
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    $reviewDate = trim((string)($_POST['review_date'] ?? ''));
    $changeSummary = trim((string)($_POST['change_summary'] ?? ''));
    $approverUserId = (int)($_POST['approver_user_id'] ?? 0);
    $purposeScope = trim((string)($_POST['purpose_scope'] ?? ''));
    $contentTextPost = trim((string)($_POST['content_text'] ?? ''));
    $formResponsesRaw = trim((string)($_POST['form_responses_json'] ?? '{}'));

    if ($effectiveDate === '') {
        $errors[] = 'Effective Date is required.';
    }
    if ($reviewDate === '') {
        $errors[] = 'Review Date is required.';
    }
    if ($changeSummary === '') {
        $errors[] = 'Change Summary is required.';
    }
    if ($approverUserId <= 0) {
        $errors[] = 'Approver is required.';
    }
    if ($approverUserId === $userId) {
        $errors[] = 'Creator cannot select themselves as approver.';
    }

    $uploadedFile = null;
    if (isset($_FILES['document_file']) && (int)($_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $uploadedFile = upload_document_file($_FILES['document_file']);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $isJsonForm = parse_document_json_content($selectedDocument['content_text'] ?? '')['is_json_form'];
    $newContentText = '';

    if ($isJsonForm) {
        $decodedFormResponses = json_decode($formResponsesRaw, true);
        if (!is_array($decodedFormResponses)) {
            $decodedFormResponses = [];
        }

        $normalizedResponses = [];
        foreach ($decodedFormResponses as $key => $value) {
            $normalizedResponses[$key] = normalize_form_response_value($key, $value);
        }

        $newContentText = json_encode([
            'purpose_scope' => $purposeScope,
            'form_responses' => $normalizedResponses
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $newContentText = $contentTextPost;
    }

    if ($newContentText === '' && !$isJsonForm) {
        $errors[] = 'Document Body is required.';
    }
    if ($isJsonForm && $purposeScope === '') {
        $errors[] = 'Document Purpose & Scope is required.';
    }

    if (empty($errors)) {
        mysqli_begin_transaction($conn);

        try {
            $newVersionStatus = ($updateAction === 'submit_review') ? 'pending_approval' : 'draft';
            $newDocumentStatus = ($updateAction === 'submit_review') ? 'pending_approval' : 'draft';

            $newPrimaryFileName = $existingFileName;
            $newPrimaryFilePath = $existingFilePath;
            $newPrimaryFileMime = (string)($selectedDocument['primary_file_mime'] ?? '');
            $newPrimaryFileSize = (int)($selectedDocument['primary_file_size'] ?? 0);
            $newChecksum = (string)($selectedDocument['checksum_sha256'] ?? '');

            if ($uploadedFile) {
                $newPrimaryFileName = $uploadedFile['original_name'];
                $newPrimaryFilePath = $uploadedFile['path'];
                $newPrimaryFileMime = $uploadedFile['mime'];
                $newPrimaryFileSize = (int)$uploadedFile['size'];
                $newChecksum = $uploadedFile['sha256'];
            }

            $insertVersionSql = "
                INSERT INTO document_versions
                (
                    document_id,
                    previous_version_id,
                    version_sequence,
                    version_label,
                    title_snapshot,
                    topic_snapshot,
                    owner_user_id,
                    created_by,
                    change_summary,
                    effective_date,
                    review_date,
                    status,
                    content_format,
                    content_text,
                    primary_file_name,
                    primary_file_path,
                    primary_file_mime,
                    primary_file_size,
                    checksum_sha256,
                    submitted_by,
                    submitted_at,
                    created_at,
                    updated_at
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ";

            $nextSequence = (int)($selectedDocument['version_sequence'] ?? 1) + 1;
            $contentFormat = ($isJsonForm ? 'rich_text' : ((trim($newPrimaryFilePath) !== '') ? 'file' : 'rich_text'));
            $submittedBy = ($updateAction === 'submit_review') ? $userId : null;
            $submittedAt = ($updateAction === 'submit_review') ? date('Y-m-d H:i:s') : null;
            $ownerUserIdInt = (int)$form['owner_user_id'];

            $insertStmt = mysqli_prepare($conn, $insertVersionSql);
            if (!$insertStmt) {
                throw new RuntimeException('Failed to prepare version insert query.');
            }

            mysqli_stmt_bind_param(
                $insertStmt,
                "iiisssiisssssssssisis",
                $selectedDocumentId,
                $currentVersionId,
                $nextSequence,
                $nextVersionLabel,
                $form['title'],
                $form['topic'],
                $ownerUserIdInt,
                $userId,
                $changeSummary,
                $effectiveDate,
                $reviewDate,
                $newVersionStatus,
                $contentFormat,
                $newContentText,
                $newPrimaryFileName,
                $newPrimaryFilePath,
                $newPrimaryFileMime,
                $newPrimaryFileSize,
                $newChecksum,
                $submittedBy,
                $submittedAt
            );

            if (!mysqli_stmt_execute($insertStmt)) {
                throw new RuntimeException('Failed to save updated version: ' . mysqli_stmt_error($insertStmt));
            }

            $newVersionId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($insertStmt);

            $updateDocumentSql = "
                UPDATE documents
                SET
                    current_version_id = ?,
                    current_status = ?,
                    approver = ?,
                    remarks = ?,
                    updated_at = NOW()
                WHERE id = ?
            ";
            $updateStmt = mysqli_prepare($conn, $updateDocumentSql);
            if (!$updateStmt) {
                throw new RuntimeException('Failed to prepare document update query.');
            }

            $remarks = $isJsonForm ? $purposeScope : $changeSummary;
            $approverAsText = (string)$approverUserId;

            mysqli_stmt_bind_param(
                $updateStmt,
                "isisi",
                $newVersionId,
                $newDocumentStatus,
                $approverAsText,
                $remarks,
                $selectedDocumentId
            );

            if (!mysqli_stmt_execute($updateStmt)) {
                throw new RuntimeException('Failed to update document: ' . mysqli_stmt_error($updateStmt));
            }
            mysqli_stmt_close($updateStmt);

            write_audit_log(
                $conn,
                $selectedDocumentId,
                $newVersionId,
                $updateAction,
                $userId,
                [
                    'document_id' => $selectedDocumentId,
                    'document_version_id' => $newVersionId,
                    'version_label' => $nextVersionLabel,
                    'status' => $newDocumentStatus,
                    'change_summary' => $changeSummary
                ]
            );

            mysqli_commit($conn);

            $_SESSION['flash_success'] = ($updateAction === 'submit_review')
                ? 'Document updated and submitted for review successfully.'
                : 'Document update draft saved successfully.';

            header('Location: update-document.php?id=' . $selectedDocumentId);
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = $e->getMessage();
        }
    }

    if ($isJsonForm) {
        $parsedContent = [
            'is_json_form' => true,
            'purpose_scope' => $purposeScope,
            'form_responses' => json_decode($formResponsesRaw, true) ?: [],
            'raw_text' => $newContentText
        ];
    } else {
        $form['content_text'] = $contentTextPost;
    }

    $form['effective_date'] = $effectiveDate;
    $form['review_date'] = $reviewDate;
    $form['change_summary'] = $changeSummary;
    $form['approver_user_id'] = (string)$approverUserId;
    $form['next_version'] = $nextVersionLabel;

    if ($uploadedFile) {
        $existingFileName = $uploadedFile['original_name'];
        $existingFilePath = $uploadedFile['path'];
    }
}

if (isset($_SESSION['flash_success']) && $_SESSION['flash_success'] !== '') {
    $successMessage = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$documentsByTypeJson = json_encode($documentsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$formResponsesJson = json_encode($parsedContent['form_responses'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Update Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .field-locked {
      background: #f5f7fa !important;
      color: #6b7280 !important;
      cursor: not-allowed;
    }
    .version-strip {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      background: #f0f4ff;
      border: 1px solid #c7d7f8;
      border-radius: 8px;
      font-size: 13px;
    }
    .version-strip .vs-label { color: #6b7280; }
    .version-strip .vs-val   { font-weight: 700; color: #1a3a6e; }
    .version-strip .vs-arrow { color: #9ca3af; font-size: 16px; }
    .version-strip .vs-new   { font-weight: 700; color: #16a34a; font-size: 15px; }
    #docInfoPanel {
      background: #f8fafc;
      border: 1px solid #dde3ec;
      border-radius: 8px;
      padding: 12px 16px;
      font-size: 13px;
    }
    #docInfoPanel .di-id   { font-weight: 700; color: #2563eb; font-size: 14px; }
    #docInfoPanel .di-meta { color: #6b7280; margin-top: 2px; }
    select:disabled { background: #f5f7fa; color: #aaa; cursor: not-allowed; }

    .upload-box {
      border: 2px dashed #cfd8e3;
      border-radius: 12px;
      background: #fff;
      transition: all .2s ease;
    }
    .upload-box:hover {
      border-color: #7aa7ff;
      background: #f8fbff;
    }
    .upload-box.drag-over {
      border-color: #2563eb !important;
      background: #eef5ff !important;
    }
    .file-selected-box,
    .form-fill-card,
    .info-form-summary-card {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      padding: 14px;
    }
    .file-name {
      color: #2563eb;
      font-weight: 700;
      word-break: break-word;
    }
    .file-meta {
      color: #6b7280;
      font-size: 12px;
      margin-top: 3px;
      word-break: break-word;
    }
    .builder-preview-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
    }
    .fill-field-card {
      border: 1px solid #dde3ec;
      border-radius: 10px;
      padding: 14px;
      background: #fff;
    }
    .fill-field-label {
      font-size: 14px;
      font-weight: 600;
      color: #0D2144;
      margin-bottom: 8px;
      display: block;
    }
    .fill-field-type {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      background: #eaf2ff;
      color: #2563eb;
      margin-left: 8px;
    }
    .doc-list-table th,
    .doc-list-table td {
      vertical-align: middle;
      font-size: 13px;
    }
  </style>
</head>
<body>

<?php include('includes/navbar.php'); ?>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-3">
      <?php foreach ($errors as $error): ?>
        <div><?php echo e($error); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($successMessage !== ''): ?>
    <div class="alert alert-success mb-3"><?php echo e($successMessage); ?></div>
  <?php endif; ?>

  <div class="mb-4">
    <h1 class="page-title mb-2">Update Controlled Document</h1>
    <p class="page-subtitle mb-0">Select the document you want to update. All existing details will load automatically — only the fields you need to change should be edited.</p>
  </div>

  <form method="post" enctype="multipart/form-data" id="updateForm">
    <input type="hidden" name="selected_document_id" value="<?php echo (int)$selectedDocumentId; ?>">
    <input type="hidden" name="update_action" id="update_action" value="">
    <input type="hidden" name="form_responses_json" id="form_responses_json" value="">
    <input type="hidden" name="content_text" id="hidden_content_text" value="">
    <input type="hidden" name="purpose_scope" id="hidden_purpose_scope" value="">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <span class="<?php echo e($badgeClass); ?>"><?php echo e($badgeLabel); ?></span>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary" href="dashboard-admin.php">Cancel</a>
        <button type="button" class="btn btn-outline-primary" onclick="submitUpdateForm('save_draft')">Save Draft</button>
        <button type="button" class="btn btn-success" onclick="submitUpdateForm('submit_review')">Submit for Review</button>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card cp-card mb-3">
          <div class="card-body">
            <h2 class="card-title mb-1">Document Selection</h2>
            <p class="card-subtitle mb-3">Select the document type first. All matching documents will appear below.</p>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label">
                  Step 1 — Document Type <span class="text-danger">*</span>
                </label>
                <select class="form-select" id="docTypeSelect" onchange="onTypeChange(this.value)">
                  <option value="">-- Select Type --</option>
                  <?php foreach ($documentTypes as $type): ?>
                    <option value="<?php echo e($type['type_name']); ?>" <?php echo ($selectedDocument && $selectedDocument['type_name'] === $type['type_name']) ? 'selected' : ''; ?>>
                      <?php echo e($type['type_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Documents from your database will appear below.</div>
              </div>
            </div>

            <div id="docFullListWrapper" class="d-none">
              <label class="form-label">Available Documents</label>
              <div class="table-responsive">
                <table class="table align-middle mb-0 doc-list-table">
                  <thead>
                    <tr>
                      <th>Document ID</th>
                      <th>Topic / Title</th>
                      <th>Version</th>
                      <th>Owner</th>
                      <th>Status</th>
                      <th>Effective Date</th>
                      <th>Review Date</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody id="docFullListBody">
                    <tr>
                      <td colspan="8" class="text-center text-secondary py-3">Select document type to view documents.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="form-text mt-2" id="docSelectHint">Choose a document type above to populate this list.</div>
            </div>

            <div id="docInfoPanel" class="<?php echo $selectedDocument ? '' : 'd-none'; ?> mb-1 mt-3">
              <div class="di-id" id="diId"><?php echo e($form['document_number'] ?: '—'); ?></div>
              <div class="di-meta" id="diMeta">—</div>
            </div>
          </div>
        </div>

        <div class="card cp-card mb-3 <?php echo $selectedDocument ? '' : 'd-none'; ?>" id="docDetailsCard">
          <div class="card-body">
            <h2 class="card-title mb-1">Document Information</h2>
            <p class="card-subtitle mb-3">Existing values are pre-filled. Edit only what needs to change in this version.</p>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Document ID</label>
                <input class="form-control field-locked" id="fDocId" readonly value="<?php echo e($form['document_number']); ?>">
                <div class="form-text">Auto-generated — cannot be changed.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Document Title / Topic</label>
                <input class="form-control field-locked" id="fDocTopic" readonly value="<?php echo e($form['title'] !== '' ? $form['title'] : $form['topic']); ?>">
                <div class="form-text">Locked on update page — this cannot be edited here.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Current Version</label>
                <input class="form-control field-locked" id="fCurrentVersion" readonly value="<?php echo e($form['current_version']); ?>">
                <div class="form-text">The version currently in effect.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">New Version <span class="text-success">(auto)</span></label>
                <input class="form-control field-locked" id="fNewVersion" readonly value="<?php echo e($form['next_version']); ?>">
                <div class="form-text">System generated — incremented automatically.</div>
              </div>

              <div class="col-12">
                <div class="version-strip" id="versionStrip">
                  <span class="vs-label">Current:</span>
                  <span class="vs-val" id="vsCurrentBadge"><?php echo e($form['current_version'] ?: '—'); ?></span>
                  <span class="vs-arrow">→</span>
                  <span class="vs-label">New version after update:</span>
                  <span class="vs-new" id="vsNewBadge"><?php echo e($form['next_version'] ?: '—'); ?></span>
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Owner</label>
                <input class="form-control field-locked" id="fOwner" readonly value="<?php echo e($lockedOwnerName); ?>">
                <input type="hidden" id="fOwnerId" value="<?php echo e($form['owner_user_id']); ?>">
                <div class="form-text">Owner cannot be changed on update page.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Approver <span class="text-danger">*</span></label>
                <select class="form-select" id="fApprover" name="approver_user_id">
                  <option value="">-- Select Approver --</option>
                  <?php foreach ($approvers as $approver): ?>
                    <option value="<?php echo (int)$approver['id']; ?>" <?php echo ((string)$approver['id'] === (string)$form['approver_user_id']) ? 'selected' : ''; ?>>
                      <?php echo e($approver['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Creator cannot select themselves as approver.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                <input class="form-control" id="fEffectiveDate" name="effective_date" type="date" value="<?php echo e($form['effective_date']); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label">Review Date <span class="text-danger">*</span></label>
                <input class="form-control" id="fReviewDate" name="review_date" type="date" value="<?php echo e($form['review_date']); ?>">
              </div>

              <div class="col-12">
                <label class="form-label">Change Summary <span class="text-danger">*</span></label>
                <textarea class="form-control" id="fChangeSummary" name="change_summary" rows="3" placeholder="Describe what changed in this version and why — e.g. updated escalation path in Section 3, revised approval thresholds"><?php echo e($form['change_summary']); ?></textarea>
                <div class="form-text">Mandatory — used in the approval notification, audit trail, and version history.</div>
              </div>
            </div>
          </div>
        </div>

        <?php if ($selectedDocument): ?>
        <div class="card cp-card mb-3" id="contentCard">
          <div class="card-body">
            <h2 class="card-title mb-1">Updated Document Content</h2>
            <p class="card-subtitle mb-3">Replace or revise the document content for the new version.</p>

            <?php if ($parsedContent['is_json_form']): ?>
              <div class="mb-3">
                <label class="form-label">Document Purpose &amp; Scope</label>
                <textarea class="form-control" id="fPurposeScope" rows="4" placeholder="Enter purpose and scope"><?php echo e($parsedContent['purpose_scope']); ?></textarea>
              </div>

              <div class="mt-3">
                <label class="form-label">Created Inputs</label>
                <div class="form-fill-card">
                  <div id="dynamicFormFields" class="builder-preview-grid"></div>
                </div>
              </div>
            <?php else: ?>
              <div class="mb-3">
                <label class="form-label">Document Body</label>
                <textarea class="form-control" id="fContentText" rows="9" placeholder="Enter updated document content here"><?php echo e($form['content_text']); ?></textarea>
              </div>
            <?php endif; ?>

            <div class="mb-2 mt-3">
              <label class="form-label">Attach Updated File</label>
              <input type="file" id="document_file" name="document_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt" style="display:none;">
              <div class="upload-box p-4 text-center small text-secondary" id="documentUploadBox" style="cursor:pointer;">
                Drag and drop file here or click to browse.<br/>
                Supported: PDF, DOC, DOCX, XLS, XLSX, TXT | Maximum size: 25 MB
              </div>
            </div>

            <div id="selectedFileInfo" class="file-selected-box mt-3 <?php echo $existingFileName !== '' ? '' : 'd-none'; ?>">
              <div class="file-name" id="selectedFileName"><?php echo e($existingFileName !== '' ? $existingFileName : ''); ?></div>
              <div class="file-meta" id="selectedFileMeta">
                <?php echo e($existingFilePath !== '' ? 'Current file: ' . $existingFilePath : ''); ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-4">
        <div class="card cp-card mb-3">
          <div class="card-body">
            <h2 class="card-title mb-1">Submission Readiness</h2>
            <p class="card-subtitle mb-3">Verify required information before sending for approval.</p>
            <ul class="small text-secondary note-list mb-0">
              <li>Document selected from database list.</li>
              <li>Version auto-incremented by system.</li>
              <li>Change Summary entered.</li>
              <li>Approver selected and validated.</li>
              <li>Email notification will be generated on submit.</li>
            </ul>
          </div>
        </div>
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Audit Controls</h2>
            <p class="card-subtitle mb-3">Key controls expected for an audit-grade process.</p>
            <ul class="small text-secondary note-list mb-0">
              <li>Previous version archived automatically on submit.</li>
              <li>Created by / updated on / IP address captured.</li>
              <li>Draft saves logged with timestamp.</li>
              <li>Old and new field values stored for every change.</li>
              <li>Immutable audit record for every workflow action.</li>
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
var SELECTED_DOC_ID = "<?php echo (int)$selectedDocumentId; ?>";
var FORM_RESPONSES = <?php echo $formResponsesJson ?: '{}'; ?>;
var IS_JSON_FORM = <?php echo $parsedContent['is_json_form'] ? 'true' : 'false'; ?>;

function onTypeChange(type) {
  var wrapper = document.getElementById('docFullListWrapper');
  var body    = document.getElementById('docFullListBody');
  var hint    = document.getElementById('docSelectHint');

  resetDocDetails();

  if (!type) {
    wrapper.classList.add('d-none');
    body.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-3">Select document type to view documents.</td></tr>';
    hint.textContent = 'Choose a document type above to populate this list.';
    return;
  }

  var docs = EFFECTIVE_DOCS[type] || [];
  wrapper.classList.remove('d-none');

  if (!docs.length) {
    body.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-3">No documents found for this type.</td></tr>';
    hint.textContent = '0 documents available.';
    return;
  }

  body.innerHTML = docs.map(function(doc) {
    var selectedClass = String(doc.id) === String(SELECTED_DOC_ID) ? 'table-primary' : '';
    var btnText = String(doc.id) === String(SELECTED_DOC_ID) ? 'Selected' : 'Select';

    return '' +
      '<tr class="' + selectedClass + '">' +
        '<td class="fw-semibold" style="color:#2563eb;">' + escapeHtml(doc.doc_id || ('ID #' + doc.id)) + '</td>' +
        '<td>' + escapeHtml(doc.topic || 'Untitled') + '</td>' +
        '<td>' + escapeHtml(doc.version || '01') + '</td>' +
        '<td>' + escapeHtml(doc.owner || '—') + '</td>' +
        '<td>' + escapeHtml(formatStatus(doc.status || '—')) + '</td>' +
        '<td>' + escapeHtml(formatDate(doc.effectiveDate)) + '</td>' +
        '<td>' + escapeHtml(formatDate(doc.reviewDate)) + '</td>' +
        '<td style="white-space:nowrap;">' +
          '<button type="button" class="btn btn-sm btn-outline-primary" style="height:28px;padding:0 10px;font-size:12px;" onclick="onDocSelect(' + doc.id + ')">' + btnText + '</button>' +
        '</td>' +
      '</tr>';
  }).join('');

  hint.textContent = docs.length + ' document' + (docs.length !== 1 ? 's' : '') + ' available.';
}

function onDocSelect(docId) {
  resetDocDetails();
  if (!docId) return;
  window.location.href = 'update-document.php?id=' + encodeURIComponent(docId);
}

function resetDocDetails() {
  if (!SELECTED_DOC_ID) {
    document.getElementById('docInfoPanel').classList.add('d-none');
    document.getElementById('docDetailsCard').classList.add('d-none');
    var contentCard = document.getElementById('contentCard');
    if (contentCard) contentCard.classList.add('d-none');
  }
}

(function initDocType() {
  var typeSel = document.getElementById('docTypeSelect');
  if (typeSel.value) {
    onTypeChange(typeSel.value);
  }
})();

(function initInfoPanel() {
  if (!SELECTED_DOC_ID) return;
  var typeSel = document.getElementById('docTypeSelect');
  var type = typeSel.value;
  var docs = EFFECTIVE_DOCS[type] || [];
  var doc = docs.find(function(d) { return String(d.id) === String(SELECTED_DOC_ID); });
  if (!doc) return;

  document.getElementById('diId').textContent = doc.doc_id || '—';
  document.getElementById('diMeta').textContent =
    'Topic: ' + (doc.topic || '—') +
    '  ·  Owner: ' + (doc.owner || '—') +
    '  ·  Effective: ' + formatDate(doc.effectiveDate);
})();

function formatStatus(str) {
  str = String(str || '').replace(/_/g, ' ');
  return str.replace(/\b\w/g, function(m) { return m.toUpperCase(); });
}

function formatDate(str) {
  if (!str) return '—';
  var d = new Date(str);
  if (isNaN(d.getTime())) return str;
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

const fileInput = document.getElementById('document_file');
const uploadBox = document.getElementById('documentUploadBox');
const selectedFileInfo = document.getElementById('selectedFileInfo');
const selectedFileName = document.getElementById('selectedFileName');
const selectedFileMeta = document.getElementById('selectedFileMeta');

function showSelectedFile(file) {
  if (!file) return;
  selectedFileName.textContent = file.name;
  selectedFileMeta.textContent = (Math.round((file.size / 1024) * 100) / 100) + ' KB';
  selectedFileInfo.classList.remove('d-none');
}

if (uploadBox && fileInput) {
  uploadBox.addEventListener('click', function(e) {
    e.preventDefault();
    fileInput.click();
  });

  fileInput.addEventListener('change', function() {
    if (this.files && this.files.length > 0) {
      showSelectedFile(this.files[0]);
    }
  });

  uploadBox.addEventListener('dragenter', function(e) {
    e.preventDefault();
    e.stopPropagation();
    uploadBox.classList.add('drag-over');
  });

  uploadBox.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.stopPropagation();
    uploadBox.classList.add('drag-over');
  });

  uploadBox.addEventListener('dragleave', function(e) {
    e.preventDefault();
    e.stopPropagation();
    uploadBox.classList.remove('drag-over');
  });

  uploadBox.addEventListener('drop', function(e) {
    e.preventDefault();
    e.stopPropagation();
    uploadBox.classList.remove('drag-over');

    const files = e.dataTransfer.files;
    if (files && files.length > 0) {
      const dt = new DataTransfer();
      for (let i = 0; i < files.length; i++) {
        dt.items.add(files[i]);
      }
      fileInput.files = dt.files;
      showSelectedFile(files[0]);
    }
  });
}

function escapeHtml(text) {
  var div = document.createElement('div');
  div.textContent = text == null ? '' : String(text);
  return div.innerHTML;
}

function formatFieldLabel(key) {
  key = String(key || '').trim();
  if (!key) return 'Field';
  return key.replace(/[_-]+/g, ' ').replace(/\b\w/g, function(m) { return m.toUpperCase(); });
}

function detectFieldType(value, key) {
  key = String(key || '').toLowerCase();
  if (key.indexOf('date') !== -1 || key.indexOf('dob') !== -1 || key.indexOf('birth') !== -1) {
    return 'date';
  }
  if (typeof value === 'number') {
    return 'number';
  }
  if (typeof value === 'boolean') {
    return 'checkbox';
  }
  if (String(value).length > 100) {
    return 'textarea';
  }
  return 'text';
}

function normalizeDateValue(value) {
  value = String(value || '').trim();

  if (/^\d{13}$/.test(value)) {
    var d1 = new Date(parseInt(value, 10));
    if (!isNaN(d1.getTime())) return d1.toISOString().slice(0, 10);
  }

  if (/^\d{10}$/.test(value)) {
    var d2 = new Date(parseInt(value, 10) * 1000);
    if (!isNaN(d2.getTime())) return d2.toISOString().slice(0, 10);
  }

  var parsed = new Date(value);
  if (!isNaN(parsed.getTime())) {
    return parsed.toISOString().slice(0, 10);
  }

  return value;
}

function renderDynamicFormFields() {
  if (!IS_JSON_FORM) return;

  var container = document.getElementById('dynamicFormFields');
  if (!container) return;

  var entries = Object.entries(FORM_RESPONSES || {});
  if (!entries.length) {
    container.innerHTML = '<div class="text-secondary small">No created fields found.</div>';
    return;
  }

  container.innerHTML = entries.map(function(entry) {
    var key = entry[0];
    var value = entry[1];
    var type = detectFieldType(value, key);
    var label = formatFieldLabel(key);
    var control = '';

    if (type === 'textarea') {
      control = '<textarea class="form-control dynamic-response-input" data-key="' + escapeHtml(key) + '" rows="3">' + escapeHtml(value) + '</textarea>';
    } else if (type === 'number') {
      control = '<input type="number" class="form-control dynamic-response-input" data-key="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '">';
    } else if (type === 'date') {
      control = '<input type="date" class="form-control dynamic-response-input" data-key="' + escapeHtml(key) + '" value="' + escapeHtml(normalizeDateValue(value)) + '">';
    } else if (type === 'checkbox') {
      control = '<div class="form-check"><input class="form-check-input dynamic-response-input" type="checkbox" data-key="' + escapeHtml(key) + '" ' + (value ? 'checked' : '') + '><label class="form-check-label">Checked</label></div>';
    } else {
      control = '<input type="text" class="form-control dynamic-response-input" data-key="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '">';
    }

    return '' +
      '<div class="fill-field-card">' +
        '<label class="fill-field-label">' + escapeHtml(label) +
          '<span class="fill-field-type">Editable</span>' +
        '</label>' +
        control +
      '</div>';
  }).join('');
}

function collectFormResponses() {
  var data = {};
  document.querySelectorAll('.dynamic-response-input').forEach(function(el) {
    var key = el.getAttribute('data-key');
    if (!key) return;

    if (el.type === 'checkbox') {
      data[key] = el.checked ? 1 : 0;
    } else {
      data[key] = el.value;
    }
  });
  return data;
}

function submitUpdateForm(action) {
  document.getElementById('update_action').value = action;

  var hiddenContent = document.getElementById('hidden_content_text');
  var hiddenPurpose = document.getElementById('hidden_purpose_scope');
  var hiddenResponses = document.getElementById('form_responses_json');

  if (IS_JSON_FORM) {
    var purposeEl = document.getElementById('fPurposeScope');
    hiddenPurpose.value = purposeEl ? purposeEl.value : '';
    hiddenResponses.value = JSON.stringify(collectFormResponses());
    hiddenContent.value = '';
  } else {
    var contentEl = document.getElementById('fContentText');
    hiddenContent.value = contentEl ? contentEl.value : '';
    hiddenPurpose.value = '';
    hiddenResponses.value = '{}';
  }

  document.getElementById('updateForm').submit();
}

document.addEventListener('DOMContentLoaded', function() {
  renderDynamicFormFields();
});
</script>
</body>
</html>