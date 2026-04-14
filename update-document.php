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
                $parts = explode(',', (string)$$_SERVER[$key] ?? (string)$_SERVER[$key]);
                return trim($parts[0]);
            }
        }
        return '';
    }
}

if (!function_exists('normalize_content_format')) {
    function normalize_content_format($contentText, $file) {
        $hasText = trim((string)$contentText) !== '';
        $hasFile = isset($file['tmp_name']) && is_uploaded_file($file['tmp_name']);

        if ($hasText && $hasFile) return 'mixed';
        if ($hasFile) return 'file';
        return 'rich_text';
    }
}

if (!function_exists('upload_document_file')) {
    function upload_document_file($file) {
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

if (!function_exists('increment_version_label')) {
    function increment_version_label($current) {
        $current = trim((string)$current);
        if ($current === '') return '02';

        if (ctype_digit($current)) {
            return str_pad((string)(((int)$current) + 1), max(2, strlen($current)), '0', STR_PAD_LEFT);
        }

        if (preg_match('/^(\d+)(\.\d+)?$/', $current)) {
            if (strpos($current, '.') !== false) {
                $parts = explode('.', $current);
                $major = (int)$parts[0];
                $minor = isset($parts[1]) ? (int)$parts[1] : 0;
                $minor++;
                return $major . '.' . $minor;
            }
            return str_pad((string)(((int)$current) + 1), max(2, strlen($current)), '0', STR_PAD_LEFT);
        }

        return $current . '-R1';
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
        u.last_login_at,
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

$docTypeSql = "SELECT id, type_name, prefix FROM document_types WHERE status = 'active' ORDER BY type_name ASC";
$docTypeRes = mysqli_query($conn, $docTypeSql);
if ($docTypeRes) {
    while ($row = mysqli_fetch_assoc($docTypeRes)) {
        $documentTypes[] = $row;
    }
}

$deptSql = "SELECT id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC";
$deptRes = mysqli_query($conn, $deptSql);
if ($deptRes) {
    while ($row = mysqli_fetch_assoc($deptRes)) {
        $departments[] = $row;
    }
}

$userOptionsQueries = [
    "SELECT id, CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS name FROM users WHERE status = 'active' ORDER BY first_name ASC, last_name ASC",
    "SELECT id, full_name AS name FROM users WHERE status = 'active' ORDER BY full_name ASC",
    "SELECT id, username AS name FROM users WHERE status = 'active' ORDER BY username ASC",
    "SELECT id, CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS name FROM users ORDER BY first_name ASC, last_name ASC",
    "SELECT id, full_name AS name FROM users ORDER BY full_name ASC",
    "SELECT id, username AS name FROM users ORDER BY username ASC"
];

foreach ($userOptionsQueries as $sql) {
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        $tempUsers = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $row['name'] = trim((string)($row['name'] ?? ''));
            if ($row['name'] === '') {
                $row['name'] = 'User #' . (int)$row['id'];
            }
            $tempUsers[] = $row;
        }
        $owners = $tempUsers;
        foreach ($tempUsers as $u) {
            if ((int)$u['id'] !== $userId) {
                $approvers[] = $u;
            }
        }
        break;
    }
}

$filterId = trim($_GET['filter_id'] ?? '');
$selectedDocumentId = (int)($_GET['id'] ?? 0);

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
        dt.type_name,
        dv.version_label,
        dv.review_date
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
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
            $documentList[] = $row;
        }
    }
    mysqli_stmt_close($listStmt);
}

$selectedDocument = null;
$currentVersion = null;
$currentVersionId = 0;
$currentVersionLabel = '';
$nextVersionLabel = '';
$comparisonText = 'Previous version highlights, redline preview, and impacted metadata should be visible before submit.';
$existingFileName = '';

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
    'next_version'        => '',
    'document_id_preview' => ''
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
            dv.primary_file_name,
            dv.primary_file_path
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

        $form['document_id'] = (string)$selectedDocument['id'];
        $form['document_type_id'] = (string)($selectedDocument['document_type_id'] ?? '');
        $form['department_id'] = (string)($selectedDocument['department_id'] ?? '');
        $form['title'] = (string)($selectedDocument['title_snapshot'] ?: $selectedDocument['title']);
        $form['topic'] = (string)($selectedDocument['topic_snapshot'] ?: $selectedDocument['topic']);
        $form['document_number'] = (string)($selectedDocument['document_number'] ?? '');
        $form['owner_user_id'] = (string)(($selectedDocument['version_owner_user_id'] ?? 0) ?: ($selectedDocument['owner_user_id'] ?? ''));
        $form['approver_user_id'] = (string)($selectedDocument['approver'] ?? '');
        $form['effective_date'] = (string)($selectedDocument['effective_date'] ?? '');
        $form['review_date'] = (string)($selectedDocument['review_date'] ?? '');
        $form['change_summary'] = '';
        $form['content_text'] = (string)($selectedDocument['content_text'] ?? '');
        $form['current_version'] = $currentVersionLabel;
        $form['next_version'] = $nextVersionLabel;

        $selectedPrefix = $selectedDocument['prefix'] ?? 'DOC';
        $topicPart = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', ($form['topic'] !== '' ? $form['topic'] : $form['title'])));
        $topicPart = trim($topicPart, '-');
        $form['document_id_preview'] = $selectedPrefix . '-' . $form['document_number'] . ($topicPart !== '' ? '-' . $topicPart : '') . '-' . $nextVersionLabel;

        $comparisonText = "Current Version: " . e($currentVersionLabel)
            . " | Next Version: " . e($nextVersionLabel)
            . " | Current Title: " . e($form['title'])
            . ($existingFileName !== '' ? " | Current File: " . e($existingFileName) : "");
    } else {
        $errors[] = 'Selected document not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_draft';
    $selectedDocumentId = (int)($_POST['document_id'] ?? 0);
    $currentVersionId = (int)($_POST['current_version_id'] ?? 0);

    $form['document_id'] = (string)$selectedDocumentId;
    $form['document_type_id'] = trim((string)($_POST['document_type_id'] ?? ''));
    $form['department_id'] = trim((string)($_POST['department_id'] ?? ''));
    $form['title'] = trim((string)($_POST['title'] ?? ''));
    $form['topic'] = trim((string)($_POST['topic'] ?? ''));
    $form['document_number'] = trim((string)($_POST['document_number'] ?? ''));
    $form['owner_user_id'] = trim((string)($_POST['owner_user_id'] ?? ''));
    $form['approver_user_id'] = trim((string)($_POST['approver_user_id'] ?? ''));
    $form['effective_date'] = trim((string)($_POST['effective_date'] ?? ''));
    $form['review_date'] = trim((string)($_POST['review_date'] ?? ''));
    $form['change_summary'] = trim((string)($_POST['change_summary'] ?? ''));
    $form['content_text'] = trim((string)($_POST['content_text'] ?? ''));
    $form['current_version'] = trim((string)($_POST['current_version'] ?? ''));
    $form['next_version'] = trim((string)($_POST['next_version'] ?? ''));

    $documentTypeId = (int)$form['document_type_id'];
    $departmentId = ($form['department_id'] !== '') ? (int)$form['department_id'] : null;
    $ownerUserId = (int)$form['owner_user_id'];
    $approverUserId = (int)$form['approver_user_id'];

    if ($selectedDocumentId <= 0) $errors[] = 'Please select a document to update.';
    if ($documentTypeId <= 0) $errors[] = 'Document Type is required.';
    if ($form['title'] === '') $errors[] = 'Document Topic or Title is required.';
    if ($form['document_number'] === '') $errors[] = 'Document Number is required.';
    if ($ownerUserId <= 0) $errors[] = 'Owner is required.';
    if ($approverUserId <= 0) $errors[] = 'Approver is required.';
    if ($approverUserId === $userId) $errors[] = 'Creator cannot select self as approver.';
    if ($form['effective_date'] === '') $errors[] = 'Effective Date is required.';
    if ($form['review_date'] === '') $errors[] = 'Review Date is required.';
    if ($form['change_summary'] === '') $errors[] = 'Change Summary is required.';

    $hasFile = isset($_FILES['primary_file']) &&
        (int)($_FILES['primary_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($form['content_text'] === '' && !$hasFile) {
        $errors[] = 'Document Content or File Upload is required.';
    }

    $selectedType = null;
    foreach ($documentTypes as $type) {
        if ((int)$type['id'] === $documentTypeId) {
            $selectedType = $type;
            break;
        }
    }
    if (!$selectedType) {
        $errors[] = 'Invalid Document Type selected.';
    }

    $prefix = $selectedType['prefix'] ?? 'DOC';
    $topicPart = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', ($form['topic'] !== '' ? $form['topic'] : $form['title'])));
    $topicPart = trim($topicPart, '-');
    $form['document_id_preview'] = $prefix . '-' . $form['document_number'] . ($topicPart !== '' ? '-' . $topicPart : '') . '-' . $form['next_version'];

    if (!$errors) {
        mysqli_begin_transaction($conn);

        try {
            $checkCurrentSql = "
                SELECT d.*, dv.version_sequence
                FROM documents d
                LEFT JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE d.id = ?
                LIMIT 1
            ";
            $checkCurrentStmt = mysqli_prepare($conn, $checkCurrentSql);
            if (!$checkCurrentStmt) {
                throw new RuntimeException('Unable to load selected document.');
            }
            mysqli_stmt_bind_param($checkCurrentStmt, "i", $selectedDocumentId);
            mysqli_stmt_execute($checkCurrentStmt);
            $checkCurrentRes = mysqli_stmt_get_result($checkCurrentStmt);
            $selectedDocument = ($checkCurrentRes && mysqli_num_rows($checkCurrentRes) > 0) ? mysqli_fetch_assoc($checkCurrentRes) : null;
            mysqli_stmt_close($checkCurrentStmt);

            if (!$selectedDocument) {
                throw new RuntimeException('Selected document not found.');
            }

            $uploadedFile = upload_document_file($_FILES['primary_file'] ?? []);
            $contentFormat = normalize_content_format($form['content_text'], $_FILES['primary_file'] ?? []);
            $documentStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';
            $versionStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';
            $nextSequence = ((int)($selectedDocument['version_sequence'] ?? 0)) + 1;
            if ($nextSequence <= 0) $nextSequence = 1;

            $topicValue = ($form['topic'] !== '') ? $form['topic'] : null;
            $changeSummaryValue = $form['change_summary'];
            $contentTextValue = ($form['content_text'] !== '') ? $form['content_text'] : null;
            $primaryFileName = $uploadedFile['original_name'] ?? null;
            $primaryFilePath = $uploadedFile['path'] ?? null;
            $primaryFileMime = $uploadedFile['mime'] ?? null;
            $primaryFileSize = $uploadedFile['size'] ?? null;
            $checksumSha256 = $uploadedFile['sha256'] ?? null;
            $submittedBy = ($action === 'submit_review') ? $userId : null;
            $submittedAt = ($action === 'submit_review') ? date('Y-m-d H:i:s') : null;

            $verSql = "
                INSERT INTO document_versions (
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
                    submitted_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $verStmt = mysqli_prepare($conn, $verSql);
            if (!$verStmt) {
                throw new RuntimeException('Failed to prepare revision version insert.');
            }

            mysqli_stmt_bind_param(
                $verStmt,
                "iiisssiisssssssssisis",
                $selectedDocumentId,
                $currentVersionId,
                $nextSequence,
                $form['next_version'],
                $form['title'],
                $topicValue,
                $ownerUserId,
                $userId,
                $changeSummaryValue,
                $form['effective_date'],
                $form['review_date'],
                $versionStatus,
                $contentFormat,
                $contentTextValue,
                $primaryFileName,
                $primaryFilePath,
                $primaryFileMime,
                $primaryFileSize,
                $checksumSha256,
                $submittedBy,
                $submittedAt
            );

            if (!mysqli_stmt_execute($verStmt)) {
                throw new RuntimeException('Failed to create revision version: ' . mysqli_stmt_error($verStmt));
            }
            mysqli_stmt_close($verStmt);

            $newVersionId = (int)mysqli_insert_id($conn);

            $updateDocSql = "
                UPDATE documents
                SET
                    document_type_id = ?,
                    department_id = ?,
                    title = ?,
                    topic = ?,
                    owner_user_id = ?,
                    current_status = ?,
                    remarks = ?,
                    approver = ?,
                    current_version_id = ?
                WHERE id = ?
            ";
            $updateDocStmt = mysqli_prepare($conn, $updateDocSql);
            if (!$updateDocStmt) {
                throw new RuntimeException('Failed to prepare document update.');
            }

            $approverValue = (string)$approverUserId;

            mysqli_stmt_bind_param(
                $updateDocStmt,
                "iississsii",
                $documentTypeId,
                $departmentId,
                $form['title'],
                $topicValue,
                $ownerUserId,
                $documentStatus,
                $changeSummaryValue,
                $approverValue,
                $newVersionId,
                $selectedDocumentId
            );

            if (!mysqli_stmt_execute($updateDocStmt)) {
                throw new RuntimeException('Failed to update document header: ' . mysqli_stmt_error($updateDocStmt));
            }
            mysqli_stmt_close($updateDocStmt);

            if ($uploadedFile && tableExists($conn, 'document_version_attachments')) {
                $attSql = "
                    INSERT INTO document_version_attachments (
                        document_version_id,
                        attachment_type,
                        original_file_name,
                        stored_file_name,
                        file_path,
                        mime_type,
                        file_size,
                        checksum_sha256,
                        uploaded_by
                    ) VALUES (?, 'primary', ?, ?, ?, ?, ?, ?, ?)
                ";
                $attStmt = mysqli_prepare($conn, $attSql);
                if (!$attStmt) {
                    throw new RuntimeException('Failed to prepare attachment insert.');
                }

                mysqli_stmt_bind_param(
                    $attStmt,
                    "issssisi",
                    $newVersionId,
                    $uploadedFile['original_name'],
                    $uploadedFile['stored_name'],
                    $uploadedFile['path'],
                    $uploadedFile['mime'],
                    $uploadedFile['size'],
                    $uploadedFile['sha256'],
                    $userId
                );

                if (!mysqli_stmt_execute($attStmt)) {
                    throw new RuntimeException('Failed to save attachment: ' . mysqli_stmt_error($attStmt));
                }
                mysqli_stmt_close($attStmt);
            }

            if (tableExists($conn, 'audit_logs')) {
                $auditAction = ($action === 'submit_review') ? 'revision_submit' : 'revision_draft';
                $auditRemarks = ($action === 'submit_review')
                    ? 'Document revision created and submitted for review.'
                    : 'Document revision draft created.';

                $oldPayload = json_encode([
                    'previous_version_id'    => $currentVersionId,
                    'previous_version_label' => $form['current_version']
                ], JSON_UNESCAPED_UNICODE);

                $newPayload = json_encode([
                    'document_id'         => $selectedDocumentId,
                    'document_version_id' => $newVersionId,
                    'document_number'     => $form['document_number'],
                    'title'               => $form['title'],
                    'topic'               => $form['topic'],
                    'status'              => $documentStatus,
                    'current_version'     => $form['current_version'],
                    'next_version'        => $form['next_version'],
                    'approver_user_id'    => $approverUserId,
                    'change_summary'      => $form['change_summary']
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

                $entityType = 'document';
                $ipAddress = get_client_ip();
                $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
                $eventId = generate_uuid_v4();

                mysqli_stmt_bind_param(
                    $auditStmt,
                    "ssisssisss",
                    $eventId,
                    $entityType,
                    $selectedDocumentId,
                    $auditAction,
                    $oldPayload,
                    $newPayload,
                    $userId,
                    $auditRemarks,
                    $ipAddress,
                    $userAgent
                );

                if (!mysqli_stmt_execute($auditStmt)) {
                    throw new RuntimeException('Failed to write audit log: ' . mysqli_stmt_error($auditStmt));
                }
                mysqli_stmt_close($auditStmt);
            }

            if ($form['change_summary'] !== '' && tableExists($conn, 'approver_comments')) {
                $cols = [];
                $vals = [];
                $types = '';
                $bind = [];

                $map = [
                    'document_id'         => $selectedDocumentId,
                    'document_version_id' => $newVersionId,
                    'user_id'             => $userId,
                    'commented_by'        => $userId,
                    'action_name'         => 'Revision Created',
                    'comment_text'        => $form['change_summary'],
                    'comment'             => $form['change_summary'],
                    'comments'            => $form['change_summary'],
                    'created_at'          => date('Y-m-d H:i:s'),
                    'commented_at'        => date('Y-m-d H:i:s')
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

            if ($action === 'submit_review' && tableExists($conn, 'document_approvals')) {
                $cols = [];
                $vals = [];
                $types = '';
                $bind = [];

                $map = [
                    'document_id'         => $selectedDocumentId,
                    'document_version_id' => $newVersionId,
                    'approver_id'         => $approverUserId,
                    'approved_by'         => $approverUserId,
                    'created_by'          => $userId,
                    'user_id'             => $userId,
                    'status'              => 'Pending Review',
                    'meaning'             => 'Pending Review',
                    'reason'              => 'Document revision submitted for review',
                    'comments'            => 'Document revision submitted for review',
                    'created_at'          => date('Y-m-d H:i:s')
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

            if ($action === 'submit_review' && tableExists($conn, 'notifications')) {
                $notifSql = "
                    INSERT INTO notifications (
                        user_id,
                        notification_type,
                        reference_type,
                        reference_id,
                        title,
                        message
                    ) VALUES (?, 'submit', 'document_version', ?, ?, ?)
                ";
                $notifStmt = mysqli_prepare($conn, $notifSql);
                if ($notifStmt) {
                    $notifTitle = 'Document Revision Submitted';
                    $notifMessage = 'A revision for document "' . $form['title'] . '" has been submitted for your review.';
                    mysqli_stmt_bind_param(
                        $notifStmt,
                        "iiss",
                        $approverUserId,
                        $newVersionId,
                        $notifTitle,
                        $notifMessage
                    );
                    mysqli_stmt_execute($notifStmt);
                    mysqli_stmt_close($notifStmt);
                }
            }

            mysqli_commit($conn);

            if ($action === 'submit_review') {
                $successMessage = 'Document revision created successfully and submitted for review.';
                $badgeClass = 'badge badge-soft-warning';
                $badgeLabel = 'Pending Approval';
            } else {
                $successMessage = 'Document revision draft saved successfully.';
                $badgeClass = 'badge badge-soft-secondary';
                $badgeLabel = 'Draft';
            }

            header('Location: update-document.php?id=' . $selectedDocumentId . '&updated=1');
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = $e->getMessage();
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $successMessage = 'Revision details loaded successfully.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Update Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    body { background:#F5F7FA; color:#1F2937; }
   
    .cp-card {
      border: 1px solid #E0E7EF;
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
      background: #fff;
    }
    .page-title {
      font-size: 2rem;
      font-weight: 700;
      color: #1F4685;
    }
    .page-subtitle,
    .card-subtitle,
    .form-text,
    .note-list {
      color: #6B7280;
    }
    .card-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: #1F2937;
    }
    .form-label {
      font-weight: 600;
      color: #1F2937;
    }
    .readonly {
      background: #f8fafc;
    }
    .kv {
      border: 1px solid #E0E7EF;
      border-radius: 12px;
      background: #F8FBFF;
    }
    .upload-box {
      border: 1px dashed #cbd5e1;
      border-radius: 14px;
      background: #fafcff;
      cursor: pointer;
    }
    .upload-box:hover {
      background: #f4f8ff;
    }
    .tab-pill {
      border: 1px solid #dbe4ef;
      border-radius: 999px !important;
      color: #1F4685;
      background: #fff;
      padding: .45rem .9rem;
      font-weight: 600;
    }
    .nav-pills .nav-link.active.tab-pill {
      background: #1F4685;
      color: #fff;
      border-color: #1F4685;
    }
    .note-list {
      padding-left: 1rem;
      margin-bottom: 0;
    }
    .note-list li {
      margin-bottom: .6rem;
    }
    .doc-list-table td, .doc-list-table th {
      vertical-align: middle;
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

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="create-document.php">Create Document</a></li><li><a class="dropdown-item active" href="update-document.php">Update Document</a></li><li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li><li><a class="dropdown-item" href="repository.php">Repository</a></li></ul></li>

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
<h1 class="page-title mb-2">Update Controlled Document</h1>
<p class="page-subtitle mb-0">Revise an existing controlled document with version control and change justification.</p>
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
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h2 class="card-title mb-1">Select Document to Update</h2>
        <p class="card-subtitle mb-0">Filter by document ID / number and choose a document to revise.</p>
      </div>
    </div>

    <form method="get" class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Filter by ID / Document Number</label>
        <input type="text" name="filter_id" class="form-control" value="<?php echo e($filterId); ?>" placeholder="Enter ID or document number">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <a href="update-document.php" class="btn btn-outline-secondary w-100">Reset</a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table align-middle doc-list-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Document Number</th>
            <th>Type</th>
            <th>Title</th>
            <th>Current Version</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($documentList)): ?>
            <?php foreach ($documentList as $doc): ?>
              <tr>
                <td><?php echo (int)$doc['id']; ?></td>
                <td><?php echo e($doc['document_number'] ?: '-'); ?></td>
                <td><?php echo e($doc['type_name'] ?: '-'); ?></td>
                <td><?php echo e($doc['title'] ?: ($doc['topic'] ?: '-')); ?></td>
                <td><?php echo e($doc['version_label'] ?: '-'); ?></td>
                <td><?php echo e($doc['current_status'] ?: '-'); ?></td>
                <td>
                  <a href="update-document.php?id=<?php echo (int)$doc['id']; ?>" class="btn btn-sm btn-outline-primary">Update</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center text-secondary py-4">No documents found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($selectedDocument): ?>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="document_id" value="<?php echo (int)$selectedDocumentId; ?>">
  <input type="hidden" name="current_version_id" value="<?php echo (int)$currentVersionId; ?>">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <span class="<?php echo e($badgeClass); ?>"><?php echo e($badgeLabel); ?></span>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-secondary" href="update-document.php">Cancel</a>
      <button type="submit" name="action" value="save_draft" class="btn btn-outline-primary">Save Draft</button>
      <button type="submit" name="action" value="submit_review" class="btn btn-success">Submit for Review</button>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card cp-card mb-3"><div class="card-body">
        <h2 class="card-title mb-1">Document Information</h2>
        <p class="card-subtitle mb-3">Review current version data and enter controlled changes.</p>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Document Type</label>
            <select name="document_type_id" class="form-select" required>
              <option value="">Select Document Type</option>
              <?php foreach ($documentTypes as $type): ?>
                <option value="<?php echo (int)$type['id']; ?>" <?php echo ((string)$form['document_type_id'] === (string)$type['id']) ? 'selected' : ''; ?>>
                  <?php echo e($type['type_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6"><label class="form-label">Document Topic</label><input class="form-control" name="topic" value="<?php echo e($form['topic']); ?>"></div>
          <div class="col-md-6"><label class="form-label">Document Number</label><input class="form-control readonly" readonly value="<?php echo e($form['document_number']); ?>"><input type="hidden" name="document_number" value="<?php echo e($form['document_number']); ?>"></div>
          <div class="col-md-6"><label class="form-label">Version</label><input class="form-control readonly" readonly value="<?php echo e($form['next_version']); ?>"></div>

          <div class="col-md-6"><label class="form-label">Owner</label>
            <select name="owner_user_id" class="form-select" required>
              <option value="">Select Owner</option>
              <?php foreach ($owners as $owner): ?>
                <option value="<?php echo (int)$owner['id']; ?>" <?php echo ((string)$form['owner_user_id'] === (string)$owner['id']) ? 'selected' : ''; ?>>
                  <?php echo e($owner['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6"><label class="form-label">Approver</label>
            <select name="approver_user_id" class="form-select" required>
              <option value="">Select Approver</option>
              <?php foreach ($approvers as $approver): ?>
                <option value="<?php echo (int)$approver['id']; ?>" <?php echo ((string)$form['approver_user_id'] === (string)$approver['id']) ? 'selected' : ''; ?>>
                  <?php echo e($approver['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Creator cannot select themselves as approver.</div>
          </div>

          <div class="col-md-6"><label class="form-label">Effective Date</label><input class="form-control" type="date" name="effective_date" value="<?php echo e($form['effective_date']); ?>" required></div>
          <div class="col-md-6"><label class="form-label">Review Date</label><input class="form-control" type="date" name="review_date" value="<?php echo e($form['review_date']); ?>" required></div>
          <div class="col-md-6"><label class="form-label">Current Version</label><input class="form-control readonly" readonly value="<?php echo e($form['current_version']); ?>"><input type="hidden" name="current_version" value="<?php echo e($form['current_version']); ?>"></div>
          <div class="col-md-6"><label class="form-label">Next Version</label><input class="form-control readonly" readonly value="<?php echo e($form['next_version']); ?>"><input type="hidden" name="next_version" value="<?php echo e($form['next_version']); ?>"></div>

          <div class="col-md-6"><label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">Select Department</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?php echo (int)$dept['id']; ?>" <?php echo ((string)$form['department_id'] === (string)$dept['id']) ? 'selected' : ''; ?>>
                  <?php echo e($dept['department_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6"><label class="form-label">Document Title</label><input class="form-control" name="title" value="<?php echo e($form['title']); ?>" required></div>

          <div class="col-12"><label class="form-label">Change Summary</label><textarea class="form-control" name="change_summary" placeholder="Mandatory description of what changed and why" rows="3"><?php echo e($form['change_summary']); ?></textarea><div class="form-text">Required for revision traceability and approval context.</div></div>
          <div class="col-12"><label class="form-label">Version Comparison</label><div class="kv p-3 small"><?php echo $comparisonText; ?></div></div>
        </div>
      </div></div>

      <div class="card cp-card"><div class="card-body">
        <h2 class="card-title mb-1">Document Content</h2>
        <p class="card-subtitle mb-3">Add document content using rich text or controlled file upload.</p>
        <ul class="nav nav-pills gap-2 mb-3">
          <li class="nav-item"><button type="button" class="nav-link active tab-pill" id="tabTextBtn">Rich Text Editor</button></li>
          <li class="nav-item"><button type="button" class="nav-link tab-pill" id="tabFileBtn">File Upload</button></li>
        </ul>

        <div id="richTextBlock">
          <div class="mb-3"><label class="form-label">Document Body</label><textarea class="form-control" name="content_text" placeholder="Enter document content here" rows="9"><?php echo e($form['content_text']); ?></textarea></div>
        </div>

        <div id="fileUploadBlock" style="display:none;">
          <label class="form-label">Upload Revised File</label>
          <input type="file" name="primary_file" class="form-control d-none" id="primaryFileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt">
          <div class="upload-box p-4 text-center small text-secondary" id="uploadBox">
            Drag and drop file here or click to browse.<br/>Supported: PDF, DOCX, XLSX | Maximum size: 25 MB
          </div>
          <div class="mt-2 small text-secondary" id="selectedFileName"><?php echo $existingFileName !== '' ? 'Current File: ' . e($existingFileName) : ''; ?></div>
        </div>
      </div></div>
    </div>

    <div class="col-lg-4">
      <div class="card cp-card mb-3"><div class="card-body">
        <h2 class="card-title mb-1">Submission Readiness</h2>
        <p class="card-subtitle mb-3">Verify required information before sending for approval.</p>
        <ul class="small text-secondary note-list mb-0">
          <li>Metadata completed.</li>
          <li>Unique document ID validated.</li>
          <li>Content entered or file attached.</li>
          <li>Approver selected and validated.</li>
          <li>Email notification will be generated on submit.</li>
        </ul>
      </div></div>

      <div class="card cp-card"><div class="card-body">
        <h2 class="card-title mb-1">Audit Controls</h2>
        <p class="card-subtitle mb-3">Key controls expected for an audit-grade process.</p>
        <ul class="small text-secondary note-list mb-0">
          <li>Created by / created on / IP address captured automatically.</li>
          <li>Draft saves logged with timestamp.</li>
          <li>Critical field changes stored with old and new values.</li>
          <li>Immutable audit record for every workflow action.</li>
        </ul>
      </div></div>
    </div>
  </div>
</form>
<?php endif; ?>

</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  const textBtn = document.getElementById('tabTextBtn');
  const fileBtn = document.getElementById('tabFileBtn');
  const textBlock = document.getElementById('richTextBlock');
  const fileBlock = document.getElementById('fileUploadBlock');
  const uploadBox = document.getElementById('uploadBox');
  const fileInput = document.getElementById('primaryFileInput');
  const selectedFileName = document.getElementById('selectedFileName');

  if (textBtn && fileBtn && textBlock && fileBlock) {
    textBtn.addEventListener('click', function () {
      textBtn.classList.add('active');
      fileBtn.classList.remove('active');
      textBlock.style.display = '';
      fileBlock.style.display = 'none';
    });

    fileBtn.addEventListener('click', function () {
      fileBtn.classList.add('active');
      textBtn.classList.remove('active');
      fileBlock.style.display = '';
      textBlock.style.display = 'none';
    });
  }

  if (uploadBox && fileInput) {
    uploadBox.addEventListener('click', function () {
      fileInput.click();
    });

    fileInput.addEventListener('change', function () {
      if (fileInput.files.length > 0) {
        selectedFileName.textContent = 'Selected: ' + fileInput.files[0].name;
      }
    });

    ['dragenter', 'dragover'].forEach(function (eventName) {
      uploadBox.addEventListener(eventName, function (e) {
        e.preventDefault();
        e.stopPropagation();
        uploadBox.classList.add('border-primary');
      });
    });

    ['dragleave', 'drop'].forEach(function (eventName) {
      uploadBox.addEventListener(eventName, function (e) {
        e.preventDefault();
        e.stopPropagation();
        uploadBox.classList.remove('border-primary');
      });
    });

    uploadBox.addEventListener('drop', function (e) {
      if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        selectedFileName.textContent = 'Selected: ' + e.dataTransfer.files[0].name;
      }
    });
  }
})();
</script>
</body>
</html>