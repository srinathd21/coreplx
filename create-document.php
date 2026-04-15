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
                $parts = explode(',', (string)$_SERVER[$key]);
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

if (!function_exists('slug_part')) {
    function slug_part($text) {
        $text = strtoupper(trim((string)$text));
        $text = preg_replace('/[^A-Z0-9]+/', '-', $text);
        $text = trim((string)$text, '-');
        return $text;
    }
}

if (!function_exists('build_document_id_preview')) {
    function build_document_id_preview($prefix, $documentNumber, $topic, $title, $versionLabel = '01') {
        $prefix = slug_part($prefix !== '' ? $prefix : 'DOC');
        $numberPart = slug_part($documentNumber);
        $topicSource = trim((string)$topic) !== '' ? $topic : $title;
        $topicPart = slug_part($topicSource);
        $versionPart = slug_part($versionLabel !== '' ? $versionLabel : '01');

        $parts = [];
        $parts[] = $prefix !== '' ? $prefix : 'DOC';

        if ($numberPart !== '') {
            $parts[] = $numberPart;
        }

        if ($topicPart !== '') {
            $parts[] = $topicPart;
        }

        $parts[] = $versionPart !== '' ? $versionPart : '01';

        return implode('-', $parts);
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

$errors = [];
$successMessage = '';
$form = [
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
    'version_label'       => '01',
    'document_id_preview' => ''
];

$badgeClass = 'badge badge-soft-secondary';
$badgeLabel = 'Draft';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_draft';

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
    $form['version_label'] = '01';

    $documentTypeId = (int)$form['document_type_id'];
    $departmentId = ($form['department_id'] !== '') ? (int)$form['department_id'] : null;
    $ownerUserId = (int)$form['owner_user_id'];
    $approverUserId = (int)$form['approver_user_id'];

    if ($documentTypeId <= 0) $errors[] = 'Document Type is required.';
    if ($form['title'] === '') $errors[] = 'Document Topic or Title is required.';
    if ($form['document_number'] === '') $errors[] = 'Document Number / ID component is required.';
    if ($ownerUserId <= 0) $errors[] = 'Owner is required.';
    if ($approverUserId <= 0) $errors[] = 'Approver is required.';
    if ($approverUserId === $userId) $errors[] = 'Creator cannot select self as approver.';
    if ($form['effective_date'] === '') $errors[] = 'Effective Date is required.';
    if ($form['review_date'] === '') $errors[] = 'Review Date is required.';

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

    $versionLabel = '01';
    $prefix = $selectedType['prefix'] ?? 'DOC';
    $form['document_id_preview'] = build_document_id_preview(
        $prefix,
        $form['document_number'],
        $form['topic'],
        $form['title'],
        $versionLabel
    );

    if (!$errors) {
        $checkSql = "SELECT id FROM documents WHERE document_number = ? LIMIT 1";
        $checkStmt = mysqli_prepare($conn, $checkSql);
        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, "s", $form['document_number']);
            mysqli_stmt_execute($checkStmt);
            $checkRes = mysqli_stmt_get_result($checkStmt);
            if ($checkRes && mysqli_num_rows($checkRes) > 0) {
                $errors[] = 'Duplicate Document ID / Number is not allowed.';
            }
            mysqli_stmt_close($checkStmt);
        } else {
            $errors[] = 'Unable to validate duplicate document number.';
        }
    }

    if (!$errors) {
        mysqli_begin_transaction($conn);

        try {
            $uploadedFile = upload_document_file($_FILES['primary_file'] ?? []);
            $contentFormat = normalize_content_format($form['content_text'], $_FILES['primary_file'] ?? []);
            $documentStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';
            $versionStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';

            $docSql = "
                INSERT INTO documents (
                    document_number,
                    document_type_id,
                    department_id,
                    title,
                    topic,
                    owner_user_id,
                    created_by,
                    current_status,
                    is_acknowledgement_required,
                    remarks,
                    approver
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $docStmt = mysqli_prepare($conn, $docSql);
            if (!$docStmt) {
                throw new RuntimeException('Failed to prepare documents insert.');
            }

            $isAckRequired = 0;
            $topicValue = ($form['topic'] !== '') ? $form['topic'] : null;
            $remarksValue = ($form['change_summary'] !== '') ? $form['change_summary'] : null;
            $approverValue = (string)$approverUserId;

            mysqli_stmt_bind_param(
                $docStmt,
                "siissiisiss",
                $form['document_number'],
                $documentTypeId,
                $departmentId,
                $form['title'],
                $topicValue,
                $ownerUserId,
                $userId,
                $documentStatus,
                $isAckRequired,
                $remarksValue,
                $approverValue
            );

            if (!mysqli_stmt_execute($docStmt)) {
                throw new RuntimeException('Failed to create document: ' . mysqli_stmt_error($docStmt));
            }
            mysqli_stmt_close($docStmt);

            $documentId = (int)mysqli_insert_id($conn);

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
                ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $verStmt = mysqli_prepare($conn, $verSql);
            if (!$verStmt) {
                throw new RuntimeException('Failed to prepare document version insert.');
            }

            $versionSequence = 1;
            $changeSummaryValue = ($form['change_summary'] !== '') ? $form['change_summary'] : null;
            $contentTextValue = ($form['content_text'] !== '') ? $form['content_text'] : null;
            $primaryFileName = $uploadedFile['original_name'] ?? null;
            $primaryFilePath = $uploadedFile['path'] ?? null;
            $primaryFileMime = $uploadedFile['mime'] ?? null;
            $primaryFileSize = $uploadedFile['size'] ?? null;
            $checksumSha256 = $uploadedFile['sha256'] ?? null;
            $submittedBy = ($action === 'submit_review') ? $userId : null;
            $submittedAt = ($action === 'submit_review') ? date('Y-m-d H:i:s') : null;

            mysqli_stmt_bind_param(
                $verStmt,
                "iisssiisssssssssisis",
                $documentId,
                $versionSequence,
                $versionLabel,
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
                throw new RuntimeException('Failed to create document version: ' . mysqli_stmt_error($verStmt));
            }
            mysqli_stmt_close($verStmt);

            $versionId = (int)mysqli_insert_id($conn);

            $updSql = "UPDATE documents SET current_version_id = ? WHERE id = ?";
            $updStmt = mysqli_prepare($conn, $updSql);
            if (!$updStmt) {
                throw new RuntimeException('Failed to prepare document update.');
            }
            mysqli_stmt_bind_param($updStmt, "ii", $versionId, $documentId);
            if (!mysqli_stmt_execute($updStmt)) {
                throw new RuntimeException('Failed to update current version: ' . mysqli_stmt_error($updStmt));
            }
            mysqli_stmt_close($updStmt);

            if ($uploadedFile) {
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
                    $versionId,
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

            $auditAction = ($action === 'submit_review') ? 'submit' : 'draft_create';
            $auditRemarks = ($action === 'submit_review')
                ? 'Document created and submitted for review.'
                : 'Document draft created.';

            $auditPayload = json_encode([
                'document_id'         => $documentId,
                'document_version_id' => $versionId,
                'document_number'     => $form['document_number'],
                'title'               => $form['title'],
                'topic'               => $form['topic'],
                'status'              => $documentStatus,
                'version_label'       => $versionLabel,
                'approver_user_id'    => $approverUserId,
                'document_id_preview' => $form['document_id_preview']
            ], JSON_UNESCAPED_UNICODE);

            if (tableExists($conn, 'audit_logs')) {
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
                $oldValue = null;
                $ipAddress = get_client_ip();
                $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
                $eventId = generate_uuid_v4();

                mysqli_stmt_bind_param(
                    $auditStmt,
                    "ssisssisss",
                    $eventId,
                    $entityType,
                    $documentId,
                    $auditAction,
                    $oldValue,
                    $auditPayload,
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

            if (tableExists($conn, 'audit_creation')) {
                $cols = [];
                $vals = [];
                $types = '';
                $bind = [];

                $map = [
                    'document_id' => $documentId,
                    'user_id' => $userId,
                    'action_name' => ($action === 'submit_review') ? 'Document Created & Submitted' : 'Document Draft Created',
                    'ip_address' => get_client_ip(),
                    'created_at' => date('Y-m-d H:i:s')
                ];

                foreach ($map as $col => $val) {
                    if (columnExists($conn, 'audit_creation', $col)) {
                        $cols[] = "`{$col}`";
                        $vals[] = "?";
                        $types .= is_int($val) ? 'i' : 's';
                        $bind[] = $val;
                    }
                }

                if (!empty($cols)) {
                    $sql = "INSERT INTO audit_creation (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $types, ...$bind);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
            }

            if ($form['change_summary'] !== '' && tableExists($conn, 'approver_comments')) {
                $cols = [];
                $vals = [];
                $types = '';
                $bind = [];

                $map = [
                    'document_id' => $documentId,
                    'document_version_id' => $versionId,
                    'user_id' => $userId,
                    'commented_by' => $userId,
                    'action_name' => 'Document Created',
                    'comment_text' => $form['change_summary'],
                    'comment' => $form['change_summary'],
                    'comments' => $form['change_summary'],
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
                    'document_id' => $documentId,
                    'document_version_id' => $versionId,
                    'approver_id' => $approverUserId,
                    'approved_by' => $approverUserId,
                    'created_by' => $userId,
                    'user_id' => $userId,
                    'status' => 'Pending Review',
                    'meaning' => 'Pending Review',
                    'reason' => 'Document submitted for review by creator',
                    'comments' => 'Document submitted for review by creator',
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
                    $notifTitle = 'Document Submitted for Review';
                    $notifMessage = 'A new document "' . $form['title'] . '" has been submitted for your review.';
                    mysqli_stmt_bind_param(
                        $notifStmt,
                        "iiss",
                        $approverUserId,
                        $versionId,
                        $notifTitle,
                        $notifMessage
                    );
                    mysqli_stmt_execute($notifStmt);
                    mysqli_stmt_close($notifStmt);
                }
            }

            mysqli_commit($conn);

            if ($action === 'submit_review') {
                $successMessage = 'Document created successfully and submitted for review.';
                $badgeClass = 'badge badge-soft-warning';
                $badgeLabel = 'Pending Approval';
            } else {
                $successMessage = 'Document draft created successfully.';
                $badgeClass = 'badge badge-soft-secondary';
                $badgeLabel = 'Draft';
            }

            $form = [
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
                'version_label'       => '01',
                'document_id_preview' => ''
            ];
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Create Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    body {
      background: #F5F7FA;
      color: #1F2937;
    }
    .app-shell {
      min-height: calc(100vh - 74px);
    }
    .content-wrap {
      max-width: 100%;
      width: 100%;
      padding-left: 20px;
      padding-right: 20px;
    }
    .cp-card {
      border: 1px solid #E0E7EF;
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
      background: #fff;
      height: 100%;
    }
    .page-title {
      font-size: 2rem;
      font-weight: 700;
      color: #1F4685;
      margin-bottom: .35rem;
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
      margin-bottom: .45rem;
    }
    .readonly {
      background: #f8fafc;
    }
    .kv {
      border: 1px solid #E0E7EF;
      border-radius: 12px;
      background: #F8FBFF;
      min-height: 58px;
      display: flex;
      align-items: center;
    }
    .upload-box {
      border: 1px dashed #cbd5e1;
      border-radius: 14px;
      background: #fafcff;
      cursor: pointer;
      min-height: 180px;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
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
      margin-bottom: .85rem;
      line-height: 1.5;
    }
    .main-grid > .col-lg-8,
    .main-grid > .col-lg-4 {
      display: flex;
      flex-direction: column;
    }
    .left-stack,
    .right-stack {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      height: 100%;
      flex: 1;
    }
    .right-stack .cp-card {
      flex: 1 1 0;
    }
    .document-content-card .card-body {
      display: flex;
      flex-direction: column;
      min-height: 460px;
    }
    #richTextBlock,
    #fileUploadBlock {
      flex: 1;
    }
    .document-body-textarea {
      min-height: 300px;
      resize: vertical;
    }
    .top-action-bar {
      margin-bottom: 1rem;
    }
    @media (min-width: 1400px) {
      .content-wrap {
        padding-left: 28px;
        padding-right: 28px;
      }
      .document-content-card .card-body {
        min-height: 520px;
      }
      .document-body-textarea {
        min-height: 340px;
      }
    }
    @media (max-width: 991.98px) {
      .right-stack .cp-card {
        flex: unset;
      }
      .document-content-card .card-body {
        min-height: auto;
      }
      .upload-box {
        min-height: 140px;
      }
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

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item active" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item" href="repository.php">Repository</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Workflow</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="document-types.php">Document Types</a></li>
            <li><a class="dropdown-item" href="document-id.php">Document ID</a></li>
            <li><a class="dropdown-item" href="content-editor.php">Content Editor</a></li>
            <li><a class="dropdown-item" href="form-builder.php">Form Builder</a></li>
            <li><a class="dropdown-item" href="form-type-name.php">Form Type &amp; Name</a></li>
            <li><a class="dropdown-item" href="approver-selection.php">Approver Selection</a></li>
            <li><a class="dropdown-item" href="submit-review.php">Submit for Review</a></li>
            <li><a class="dropdown-item" href="electronic-signature.php">Electronic Signature</a></li>
            <li><a class="dropdown-item" href="approver-comments.php">Approver Comments</a></li>
            <li><a class="dropdown-item" href="notifications.php">Notifications</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-creation.php">Audit - Creation</a></li>
            <li><a class="dropdown-item" href="audit-approval.php">Audit - Approval</a></li>
            <li><a class="dropdown-item" href="audit-comments.php">Audit - Comments</a></li>
            <li><a class="dropdown-item" href="qa-admin.php">QA Admin</a></li>
            <li><a class="dropdown-item" href="employee-role.php">Employee Role</a></li>
            <li><a class="dropdown-item" href="super-admin.php">Super Admin</a></li>
            <li><a class="dropdown-item" href="user-management.php">User Management</a></li>
            <li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li>
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
  <div class="content-wrap py-4 mx-auto">
    <div class="mb-4">
      <h1 class="page-title">Create Controlled Document</h1>
      <p class="page-subtitle mb-0">Create a new controlled document with required metadata, ownership, and approval workflow.</p>
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

    <form method="post" enctype="multipart/form-data" id="createDocumentForm">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 top-action-bar">
        <span class="<?php echo e($badgeClass); ?>"><?php echo e($badgeLabel); ?></span>
        <div class="d-flex gap-2 flex-wrap">
          <a class="btn btn-outline-secondary" href="dashboard-admin.php">Cancel</a>
          <button type="submit" name="action" value="save_draft" class="btn btn-outline-primary">Save Draft</button>
          <button type="submit" name="action" value="submit_review" class="btn btn-success">Submit for Review</button>
        </div>
      </div>

      <div class="row g-3 main-grid">
        <div class="col-lg-8">
          <div class="left-stack">
            <div class="card cp-card">
              <div class="card-body">
                <h2 class="card-title mb-1">Document Information</h2>
                <p class="card-subtitle mb-3">Enter the required document metadata and ownership details.</p>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Document Type</label>
                    <select name="document_type_id" class="form-select" id="document_type_id" required>
                      <option value="">Select Document Type</option>
                      <?php foreach ($documentTypes as $type): ?>
                        <option
                          value="<?php echo (int)$type['id']; ?>"
                          data-prefix="<?php echo e($type['prefix'] ?: 'DOC'); ?>"
                          <?php echo ((string)$form['document_type_id'] === (string)$type['id']) ? 'selected' : ''; ?>
                        >
                          <?php echo e($type['type_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Document Topic</label>
                    <input class="form-control" name="topic" id="topic" value="<?php echo e($form['topic']); ?>">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Document Number</label>
                    <input class="form-control" name="document_number" id="document_number" value="<?php echo e($form['document_number']); ?>" required>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Version</label>
                    <input class="form-control readonly" id="version_label" readonly value="<?php echo e($form['version_label']); ?>">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Owner</label>
                    <select name="owner_user_id" class="form-select" required>
                      <option value="">Select Owner</option>
                      <?php foreach ($owners as $owner): ?>
                        <option value="<?php echo (int)$owner['id']; ?>" <?php echo ((string)$form['owner_user_id'] === (string)$owner['id']) ? 'selected' : ''; ?>>
                          <?php echo e($owner['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Approver</label>
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

                  <div class="col-md-6">
                    <label class="form-label">Effective Date</label>
                    <input class="form-control" type="date" name="effective_date" value="<?php echo e($form['effective_date']); ?>" required>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Review Date</label>
                    <input class="form-control" type="date" name="review_date" value="<?php echo e($form['review_date']); ?>" required>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select">
                      <option value="">Select Department</option>
                      <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo (int)$dept['id']; ?>" <?php echo ((string)$form['department_id'] === (string)$dept['id']) ? 'selected' : ''; ?>>
                          <?php echo e($dept['department_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Document Title</label>
                    <input class="form-control" name="title" id="title" value="<?php echo e($form['title']); ?>" required>
                  </div>

                  <div class="col-12">
                    <label class="form-label">Change Summary</label>
                    <textarea class="form-control" name="change_summary" placeholder="Enter document purpose or summary" rows="3"><?php echo e($form['change_summary']); ?></textarea>
                    <div class="form-text">Mandatory for traceability and approval context.</div>
                  </div>

                  <div class="col-12">
                    <label class="form-label">Document ID Preview</label>
                    <div class="kv p-3 fw-semibold text-primary" id="docIdPreview">
                      <?php echo e($form['document_id_preview'] !== '' ? $form['document_id_preview'] : 'Format: [Type]-[Number]-[Topic]-[Version]'); ?>
                    </div>
                    <div class="form-text">Format: [Type]-[Number]-[Topic]-[Version]</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="card cp-card document-content-card">
              <div class="card-body">
                <h2 class="card-title mb-1">Document Content</h2>
                <p class="card-subtitle mb-3">Add document content using rich text or controlled file upload.</p>

                <ul class="nav nav-pills gap-2 mb-3">
                  <li class="nav-item">
                    <button type="button" class="nav-link active tab-pill" id="tabTextBtn">Rich Text Editor</button>
                  </li>
                  <li class="nav-item">
                    <button type="button" class="nav-link tab-pill" id="tabFileBtn">File Upload</button>
                  </li>
                </ul>

                <div id="richTextBlock">
                  <div class="mb-3 h-100 d-flex flex-column">
                    <label class="form-label">Document Body</label>
                    <textarea class="form-control document-body-textarea flex-grow-1" name="content_text" placeholder="Enter document content here"><?php echo e($form['content_text']); ?></textarea>
                  </div>
                </div>

                <div id="fileUploadBlock" style="display:none;">
                  <label class="form-label">Upload File</label>
                  <input type="file" name="primary_file" class="form-control d-none" id="primaryFileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt">
                  <div class="upload-box p-4 text-center small text-secondary" id="uploadBox">
                    <div>
                      Drag and drop file here or click to browse.<br>
                      Supported: PDF, DOCX, XLSX | Maximum size: 25 MB
                    </div>
                  </div>
                  <div class="mt-2 small text-secondary" id="selectedFileName"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="right-stack">
            <div class="card cp-card">
              <div class="card-body d-flex flex-column">
                <div>
                  <h2 class="card-title mb-1">Submission Readiness</h2>
                  <p class="card-subtitle mb-3">Verify required information before sending for approval.</p>
                </div>
                <ul class="small text-secondary note-list mt-auto mb-0">
                  <li>Metadata completed.</li>
                  <li>Unique document ID validated.</li>
                  <li>Content entered or file attached.</li>
                  <li>Approver selected and validated.</li>
                  <li>Email notification will be generated on submit.</li>
                </ul>
              </div>
            </div>

            <div class="card cp-card">
              <div class="card-body d-flex flex-column">
                <div>
                  <h2 class="card-title mb-1">Audit Controls</h2>
                  <p class="card-subtitle mb-3">Key controls expected for an audit-grade process.</p>
                </div>
                <ul class="small text-secondary note-list mt-auto mb-0">
                  <li>Created by / created on / IP address captured automatically.</li>
                  <li>Draft saves logged with timestamp.</li>
                  <li>Critical field changes stored with old and new values.</li>
                  <li>Immutable audit record for every workflow action.</li>
                </ul>
              </div>
            </div>

            <div class="card cp-card">
              <div class="card-body d-flex flex-column">
                <div>
                  <h2 class="card-title mb-1">Workflow Status</h2>
                  <p class="card-subtitle mb-3">Current flow for this document once you save or submit.</p>
                </div>
                <ul class="small text-secondary note-list mt-auto mb-0">
                  <li>Save Draft → document stays in draft status.</li>
                  <li>Submit for Review → document moves to pending approval.</li>
                  <li>Creation audit is saved automatically.</li>
                  <li>Change summary is stored in comments when entered.</li>
                  <li>Approver notification is generated on submit.</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </form>
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
      } else {
        selectedFileName.textContent = '';
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

  const docType = document.getElementById('document_type_id');
  const docNumber = document.getElementById('document_number');
  const topic = document.getElementById('topic');
  const title = document.getElementById('title');
  const version = document.getElementById('version_label');
  const preview = document.getElementById('docIdPreview');

  function makeSlug(text) {
    return String(text || '')
      .trim()
      .toUpperCase()
      .replace(/[^A-Z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  function updatePreview() {
    if (!preview) return;

    let prefix = 'DOC';
    if (docType && docType.selectedIndex > 0) {
      const selected = docType.options[docType.selectedIndex];
      const dbPrefix = selected.getAttribute('data-prefix') || '';
      prefix = makeSlug(dbPrefix || 'DOC');
    }

    const numberPart = makeSlug(docNumber ? docNumber.value : '');
    const topicSource = (topic && topic.value.trim() !== '')
      ? topic.value
      : (title ? title.value : '');
    const topicPart = makeSlug(topicSource);
    const versionPart = makeSlug(version ? version.value : '01') || '01';

    const parts = [];
    parts.push(prefix || 'DOC');
    if (numberPart) parts.push(numberPart);
    if (topicPart) parts.push(topicPart);
    parts.push(versionPart);

    preview.textContent = parts.join('-');
  }

  [docType, docNumber, topic, title].forEach(function (el) {
    if (el) {
      el.addEventListener('input', updatePreview);
      el.addEventListener('change', updatePreview);
    }
  });

  updatePreview();
})();
</script>
</body>
</html>