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

if (!function_exists('normalize_content_format')) {
    function normalize_content_format($contentText, $file)
    {
        $hasText = trim((string)$contentText) !== '';
        $hasFile = isset($file['tmp_name']) && is_uploaded_file($file['tmp_name']);

        if ($hasText && $hasFile) return 'mixed';
        if ($hasFile) return 'file';
        return 'rich_text';
    }
}

if (!function_exists('upload_document_file')) {
    function upload_document_file($file)
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

if (!function_exists('increment_version_label')) {
    function increment_version_label($current)
    {
        $current = trim((string)$current);
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
        d.document_type_id,
        d.department_id,
        dt.type_name,
        dt.id AS type_id,
        dt.prefix,
        dv.version_label,
        dv.effective_date,
        dv.review_date
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE d.current_status IN ('draft','pending_approval','effective','approved','published')
";
$listParams = [];
$listTypes = '';

if ($filterId !== '') {
    $listSql .= " AND (d.document_number LIKE ? OR d.id = ?)";
    $listParams[] = '%' . $filterId . '%';
    $listParams[] = (int)$filterId;
    $listTypes .= 'si';
}

$listSql .= " ORDER BY dt.type_name ASC, d.document_number ASC, d.id DESC";

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

$documentsByType = [];
foreach ($documentList as $doc) {
    $typeName = (string)($doc['type_name'] ?? 'Other');
    if (!isset($documentsByType[$typeName])) {
        $documentsByType[$typeName] = [];
    }

    $ownerName = '';
    foreach ($owners as $owner) {
        if ((int)$owner['id'] === (int)$doc['owner_user_id']) {
            $ownerName = $owner['name'];
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
        'effectiveDate' => !empty($doc['effective_date']) ? (string)$doc['effective_date'] : '',
        'reviewDate' => !empty($doc['review_date']) ? (string)$doc['review_date'] : '',
    ];
}

$selectedDocument = null;
$currentVersionId = 0;
$currentVersionLabel = '';
$nextVersionLabel = '';
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
        $form['effective_date'] = date('Y-m-d');
        $form['review_date'] = date('Y-m-d', strtotime('+2 years'));
        $form['change_summary'] = '';
        $form['content_text'] = (string)($selectedDocument['content_text'] ?? '');
        $form['current_version'] = $currentVersionLabel;
        $form['next_version'] = $nextVersionLabel;

        $selectedPrefix = $selectedDocument['prefix'] ?? 'DOC';
        $topicPart = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', ($form['topic'] !== '' ? $form['topic'] : $form['title'])));
        $topicPart = trim($topicPart, '-');
        $form['document_id_preview'] = $selectedPrefix . '-' . $form['document_number'] . ($topicPart !== '' ? '-' . $topicPart : '') . '-' . $nextVersionLabel;
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
    if ($form['title'] === '' && $form['topic'] === '') $errors[] = 'Document Title / Topic is required.';
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

            $titleValue = ($form['title'] !== '') ? $form['title'] : $form['topic'];
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
                $titleValue,
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
                $titleValue,
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
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $newPayload = json_encode([
                    'document_id'         => $selectedDocumentId,
                    'document_version_id' => $newVersionId,
                    'document_number'     => $form['document_number'],
                    'title'               => $titleValue,
                    'topic'               => $form['topic'],
                    'status'              => $documentStatus,
                    'current_version'     => $form['current_version'],
                    'next_version'        => $form['next_version'],
                    'approver_user_id'    => $approverUserId,
                    'change_summary'      => $form['change_summary']
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
                    $notifMessage = 'A revision for document "' . $titleValue . '" has been submitted for your review.';
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
            header('Location: update-document.php?id=' . $selectedDocumentId . '&updated=1');
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = $e->getMessage();
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $successMessage = 'Document revision saved successfully.';
    $badgeClass = 'badge badge-soft-success';
    $badgeLabel = 'Updated';
}

$documentsByTypeJson = json_encode($documentsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            <li><a class="dropdown-item active" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
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
    <h1 class="page-title mb-2">Update Controlled Document</h1>
    <p class="page-subtitle mb-0">Select the document you want to update. All existing details will load automatically — only the fields you need to change should be edited.</p>
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

  <form method="post" enctype="multipart/form-data" id="updateForm">
    <input type="hidden" name="document_id" id="document_id" value="<?php echo (int)$selectedDocumentId; ?>">
    <input type="hidden" name="current_version_id" id="current_version_id" value="<?php echo (int)$currentVersionId; ?>">
    <input type="hidden" name="document_type_id" id="document_type_id" value="<?php echo e($form['document_type_id']); ?>">
    <input type="hidden" name="document_number" id="document_number" value="<?php echo e($form['document_number']); ?>">
    <input type="hidden" name="current_version" id="current_version" value="<?php echo e($form['current_version']); ?>">
    <input type="hidden" name="next_version" id="next_version" value="<?php echo e($form['next_version']); ?>">

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
        <div class="card cp-card mb-3">
          <div class="card-body">
            <h2 class="card-title mb-1">Document Selection</h2>
            <p class="card-subtitle mb-3">Select the document type first, then choose the specific document to update.</p>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label">Step 1 — Document Type <span class="text-danger">*</span></label>
                <select class="form-select" id="docTypeSelect" onchange="onTypeChange(this.value)">
                  <option value="">-- Select Type --</option>
                  <?php foreach ($documentTypes as $type): ?>
                    <option value="<?php echo e($type['type_name']); ?>" <?php echo (($selectedDocument && $selectedDocument['type_name'] === $type['type_name']) ? 'selected' : ''); ?>>
                      <?php echo e($type['type_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Only Effective documents will appear in the next dropdown.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Step 2 — Select Document <span class="text-danger">*</span></label>
                <select class="form-select" id="docSelect" <?php echo $selectedDocument ? '' : 'disabled'; ?> onchange="onDocSelect(this.value)">
                  <option value=""><?php echo $selectedDocument ? '-- Select Document --' : '-- Select Type first --'; ?></option>
                </select>
                <div class="form-text" id="docSelectHint">Choose a document type above to populate this list.</div>
              </div>
            </div>

            <div id="docInfoPanel" class="<?php echo $selectedDocument ? '' : 'd-none'; ?> mb-1">
              <div class="di-id" id="diId"><?php echo e($form['document_number']); ?></div>
              <div class="di-meta" id="diMeta">
                Topic: <?php echo e($form['topic'] !== '' ? $form['topic'] : $form['title']); ?>
                · Owner:
                <?php
                  $ownerLabel = '—';
                  foreach ($owners as $owner) {
                      if ((string)$owner['id'] === (string)$form['owner_user_id']) {
                          $ownerLabel = $owner['name'];
                          break;
                      }
                  }
                  echo e($ownerLabel);
                ?>
                · Effective:
                <?php echo e($form['effective_date'] !== '' ? date('d M Y', strtotime($form['effective_date'])) : '—'); ?>
              </div>
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
                <input class="form-control" id="fDocTopic" name="title" value="<?php echo e($form['title'] !== '' ? $form['title'] : $form['topic']); ?>">
                <input type="hidden" name="topic" id="topic_hidden" value="<?php echo e($form['topic']); ?>">
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
                  <span class="vs-val" id="vsCurrentBadge"><?php echo e($form['current_version'] !== '' ? $form['current_version'] : '—'); ?></span>
                  <span class="vs-arrow">→</span>
                  <span class="vs-label">New version after update:</span>
                  <span class="vs-new" id="vsNewBadge"><?php echo e($form['next_version'] !== '' ? $form['next_version'] : '—'); ?></span>
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Owner</label>
                <select class="form-select" id="fOwner" name="owner_user_id">
                  <option value="">-- Select Owner --</option>
                  <?php foreach ($owners as $owner): ?>
                    <option value="<?php echo (int)$owner['id']; ?>" <?php echo ((string)$form['owner_user_id'] === (string)$owner['id']) ? 'selected' : ''; ?>>
                      <?php echo e($owner['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
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

              <div class="col-md-6">
                <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                <input class="form-control" id="fEffectiveDate" type="date" name="effective_date" value="<?php echo e($form['effective_date']); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label">Review Date <span class="text-danger">*</span></label>
                <input class="form-control" id="fReviewDate" type="date" name="review_date" value="<?php echo e($form['review_date']); ?>">
              </div>

              <div class="col-12">
                <label class="form-label">Change Summary <span class="text-danger">*</span></label>
                <textarea class="form-control" id="fChangeSummary" name="change_summary" placeholder="Describe what changed in this version and why — e.g. updated escalation path in Section 3, revised approval thresholds" rows="3"><?php echo e($form['change_summary']); ?></textarea>
                <div class="form-text">Mandatory — used in the approval notification, audit trail, and version history.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="card cp-card <?php echo $selectedDocument ? '' : 'd-none'; ?>" id="contentCard">
          <div class="card-body">
            <h2 class="card-title mb-1">Updated Document Content</h2>
            <p class="card-subtitle mb-3">Replace or revise the document content for the new version.</p>
            <ul class="nav nav-pills gap-2 mb-3">
              <li class="nav-item"><a class="nav-link active tab-pill" href="#" id="tabTextBtn">Rich Text Editor</a></li>
              <li class="nav-item"><a class="nav-link tab-pill" href="#" id="tabFileBtn">File Upload</a></li>
            </ul>

            <div id="richTextBlock">
              <div class="mb-3">
                <label class="form-label">Document Body</label>
                <textarea class="form-control" name="content_text" id="content_text" placeholder="Enter updated document content here" rows="9"><?php echo e($form['content_text']); ?></textarea>
              </div>
            </div>

            <div id="fileUploadBlock" style="display:none;">
              <input type="file" name="primary_file" class="form-control d-none" id="primaryFileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt">
              <div class="upload-box p-4 text-center small text-secondary" id="uploadBox">
                Drag and drop file here or click to browse.<br/>
                Supported: PDF, DOCX, XLSX | Maximum size: 25 MB
              </div>
              <div class="mt-2 small text-secondary" id="selectedFileName">
                <?php echo $existingFileName !== '' ? 'Current File: ' . e($existingFileName) : ''; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card cp-card mb-3">
          <div class="card-body">
            <h2 class="card-title mb-1">Submission Readiness</h2>
            <p class="card-subtitle mb-3">Verify required information before sending for approval.</p>
            <ul class="small text-secondary note-list mb-0">
              <li>Document selected from effective repository.</li>
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

function onTypeChange(type) {
  var docSel  = document.getElementById('docSelect');
  var hint    = document.getElementById('docSelectHint');

  resetDocDetails(true);

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
    opt.textContent = (doc.doc_id || ('ID #' + doc.id)) + '  —  ' + (doc.topic || 'Untitled');
    if (String(doc.id) === String(<?php echo (int)$selectedDocumentId; ?>)) {
      opt.selected = true;
    }
    docSel.appendChild(opt);
  });

  docSel.disabled = false;
  hint.textContent = docs.length + ' effective ' + type + ' document' + (docs.length !== 1 ? 's' : '') + ' available.';
}

function onDocSelect(docId) {
  if (!docId) {
    resetDocDetails(true);
    return;
  }
  window.location.href = 'update-document.php?id=' + encodeURIComponent(docId);
}

function resetDocDetails(resetAll) {
  if (!resetAll) return;
  document.getElementById('docInfoPanel').classList.add('d-none');
  document.getElementById('docDetailsCard').classList.add('d-none');
  document.getElementById('contentCard').classList.add('d-none');
}

(function initTypeAndDoc() {
  var typeSel = document.getElementById('docTypeSelect');
  if (typeSel.value) {
    onTypeChange(typeSel.value);
  }
})();

(function () {
  const textBtn = document.getElementById('tabTextBtn');
  const fileBtn = document.getElementById('tabFileBtn');
  const textBlock = document.getElementById('richTextBlock');
  const fileBlock = document.getElementById('fileUploadBlock');
  const uploadBox = document.getElementById('uploadBox');
  const fileInput = document.getElementById('primaryFileInput');
  const selectedFileName = document.getElementById('selectedFileName');

  if (textBtn && fileBtn && textBlock && fileBlock) {
    textBtn.addEventListener('click', function (e) {
      e.preventDefault();
      textBtn.classList.add('active');
      fileBtn.classList.remove('active');
      textBlock.style.display = '';
      fileBlock.style.display = 'none';
    });

    fileBtn.addEventListener('click', function (e) {
      e.preventDefault();
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