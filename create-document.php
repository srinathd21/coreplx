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

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('has_column')) {
    function has_column(mysqli $conn, string $table, string $column): bool
    {
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        $has = $res && mysqli_num_rows($res) > 0;
        if ($res) {
            mysqli_free_result($res);
        }
        return $has;
    }
}

if (!function_exists('table_exists')) {
    function table_exists(mysqli $conn, string $table): bool
    {
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
    function make_bind_refs(array &$arr): array
    {
        $refs = [];
        foreach ($arr as $key => &$value) {
            $refs[$key] = &$value;
        }
        return $refs;
    }
}

if (!function_exists('stmt_bind_execute')) {
    function stmt_bind_execute(mysqli_stmt $stmt, array $params = []): bool
    {
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

if (!function_exists('fetch_all_assoc')) {
    function fetch_all_assoc(mysqli $conn, string $sql): array
    {
        $rows = [];
        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
            mysqli_free_result($res);
        }
        return $rows;
    }
}

if (!function_exists('fetch_one_prepared')) {
    function fetch_one_prepared(mysqli $conn, string $sql, array $params = [])
    {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return null;
        }
        stmt_bind_execute($stmt, $params);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }
}

if (!function_exists('exec_prepared')) {
    function exec_prepared(mysqli $conn, string $sql, array $params = []): mysqli_stmt
    {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            throw new Exception('Database prepare failed: ' . mysqli_error($conn));
        }
        if (!stmt_bind_execute($stmt, $params)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new Exception('Database execute failed: ' . $err);
        }
        return $stmt;
    }
}

if (!function_exists('redirect_back')) {
    function redirect_back(): void
    {
        header('Location: create-document.php');
        exit;
    }
}

if (!function_exists('write_audit_log')) {
    function write_audit_log(mysqli $conn, string $entityType, $entityId, string $action, $oldValue, $newValue, $performedBy, string $remarks = ''): void
    {
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

if (!function_exists('ensure_upload_dir')) {
    function ensure_upload_dir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}

if (!function_exists('save_document_upload')) {
    function save_document_upload(array $file): array
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('Please choose a file to upload.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed. Error code: ' . (int)$file['error']);
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Invalid uploaded file.');
        }

        $maxSize = 25 * 1024 * 1024;
        if ((int)$file['size'] <= 0) {
            throw new Exception('Uploaded file is empty.');
        }
        if ((int)$file['size'] > $maxSize) {
            throw new Exception('Maximum allowed file size is 25 MB.');
        }

        $originalName = trim((string)($file['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new Exception('Only PDF, DOC, DOCX, XLS, XLSX files are allowed.');
        }

        $uploadDir = __DIR__ . '/uploads/documents';
        ensure_upload_dir($uploadDir);

        $storedName = 'doc_' . uniqid('', true) . '.' . $extension;
        $absolutePath = $uploadDir . '/' . $storedName;
        $relativePath = 'uploads/documents/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new Exception('Failed to save uploaded file.');
        }

        $mimeType = function_exists('mime_content_type') ? mime_content_type($absolutePath) : ($file['type'] ?? 'application/octet-stream');
        $checksum = hash_file('sha256', $absolutePath);

        return [
            'original_name' => $originalName,
            'stored_name'   => $storedName,
            'relative_path' => $relativePath,
            'mime_type'     => $mimeType ?: 'application/octet-stream',
            'file_size'     => (int)filesize($absolutePath),
            'checksum'      => $checksum,
        ];
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
$currentRoleCode = (string)($_SESSION['role_code'] ?? '');
$currentDisplayName = (string)($_SESSION['full_name'] ?? $_SESSION['admin_name'] ?? 'Profile');

if ($currentUserId <= 0) {
    header('Location: login-admin.php');
    exit;
}

if (!in_array($currentRoleCode, ['qa_admin', 'super_admin'], true)) {
    die('Access denied.');
}

$hasFormDefinitionLink = has_column($conn, 'document_versions', 'form_definition_id');
$hasFormBuilderJson = has_column($conn, 'form_definitions', 'builder_json');

$currentUser = fetch_one_prepared($conn, "
    SELECT u.*, r.role_code, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
", [$currentUserId]);

if (!$currentUser) {
    die('User not found.');
}

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$documentTypes = fetch_all_assoc($conn, "
    SELECT id, type_name, prefix, review_cycle_days, acknowledgement_required
    FROM document_types
    WHERE status = 'active'
    ORDER BY type_name ASC
");

$departments = fetch_all_assoc($conn, "
    SELECT id, department_name, department_code
    FROM departments
    WHERE is_active = 1
    ORDER BY department_name ASC
");

$approvers = fetch_all_assoc($conn, "
    SELECT u.id, u.first_name, u.last_name, u.email, r.role_code, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.status = 'active'
      AND r.role_code IN ('qa_admin', 'super_admin')
    ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC
");

$defaultTypeId = (int)($documentTypes[0]['id'] ?? 0);
$defaultReviewCycle = (int)($documentTypes[0]['review_cycle_days'] ?? 365);

$old = $_SESSION['create_document_old'] ?? [];
unset($_SESSION['create_document_old']);

$queryDraftId = trim((string)($_GET['draft_id'] ?? ''));
if ($queryDraftId !== '') {
    $old['draft_id'] = $queryDraftId;
}

$creatorName = trim((string)($currentUser['first_name'] ?? '') . ' ' . (string)($currentUser['last_name'] ?? ''));
if ($creatorName === '') {
    $creatorName = (string)($currentUser['email'] ?? $currentDisplayName);
}

$formData = [
    'document_type_id'     => (string)($old['document_type_id'] ?? $defaultTypeId),
    'document_topic'       => (string)($old['document_topic'] ?? 'CAPA'),
    'document_number'      => (string)($old['document_number'] ?? ''),
    'department_id'        => (string)($old['department_id'] ?? ''),
    'owner_user_id'        => (string)$currentUserId,
    'approver_user_id'     => (string)($old['approver_user_id'] ?? ''),
    'effective_date'       => (string)($old['effective_date'] ?? ''),
    'review_date'          => (string)($old['review_date'] ?? ''),
    'purpose_scope'        => (string)($old['purpose_scope'] ?? ''),
    'content_mode'         => (string)($old['content_mode'] ?? 'file'),
    'content_text'         => (string)($old['content_text'] ?? ''),
    'draft_id'             => (string)($old['draft_id'] ?? ''),
    'form_name'            => (string)($old['form_name'] ?? ''),
    'form_type'            => (string)($old['form_type'] ?? ''),
    'form_desc'            => (string)($old['form_desc'] ?? ''),
    'form_builder_json'    => (string)($old['form_builder_json'] ?? ''),
    'existing_file_name'   => (string)($old['existing_file_name'] ?? ''),
    'existing_file_path'   => (string)($old['existing_file_path'] ?? ''),
    'existing_file_mime'   => (string)($old['existing_file_mime'] ?? ''),
    'existing_file_size'   => (string)($old['existing_file_size'] ?? ''),
];

if ($formData['effective_date'] === '') {
    $formData['effective_date'] = date('Y-m-d');
}
if ($formData['review_date'] === '') {
    $formData['review_date'] = date('Y-m-d', strtotime('+' . max(1, $defaultReviewCycle) . ' days'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'save_draft'));

    $formData = [
        'document_type_id'     => trim((string)($_POST['document_type_id'] ?? '')),
        'document_topic'       => trim((string)($_POST['document_topic'] ?? '')),
        'document_number'      => trim((string)($_POST['document_number'] ?? '')),
        'department_id'        => trim((string)($_POST['department_id'] ?? '')),
        'owner_user_id'        => (string)$currentUserId,
        'approver_user_id'     => trim((string)($_POST['approver_user_id'] ?? '')),
        'effective_date'       => trim((string)($_POST['effective_date'] ?? '')),
        'review_date'          => trim((string)($_POST['review_date'] ?? '')),
        'purpose_scope'        => trim((string)($_POST['purpose_scope'] ?? '')),
        'content_mode'         => trim((string)($_POST['content_mode'] ?? 'file')),
        'content_text'         => trim((string)($_POST['content_text'] ?? '')),
        'draft_id'             => trim((string)($_POST['draft_id'] ?? '')),
        'form_name'            => trim((string)($_POST['form_name'] ?? '')),
        'form_type'            => trim((string)($_POST['form_type'] ?? '')),
        'form_desc'            => trim((string)($_POST['form_desc'] ?? '')),
        'form_builder_json'    => trim((string)($_POST['form_builder_json'] ?? '')),
        'existing_file_name'   => trim((string)($_POST['existing_file_name'] ?? '')),
        'existing_file_path'   => trim((string)($_POST['existing_file_path'] ?? '')),
        'existing_file_mime'   => trim((string)($_POST['existing_file_mime'] ?? '')),
        'existing_file_size'   => trim((string)($_POST['existing_file_size'] ?? '')),
    ];

    if ($formData['document_type_id'] === '' && $defaultTypeId > 0) {
        $formData['document_type_id'] = (string)$defaultTypeId;
    }
    if ($formData['effective_date'] === '') {
        $formData['effective_date'] = date('Y-m-d');
    }
    if ($formData['review_date'] === '') {
        $formData['review_date'] = date('Y-m-d', strtotime('+' . max(1, $defaultReviewCycle) . ' days'));
    }

    $_SESSION['create_document_old'] = $formData;

    $documentTypeId = (int)$formData['document_type_id'];
    $departmentId = $formData['department_id'] !== '' ? (int)$formData['department_id'] : null;
    $ownerUserId = $currentUserId;
    $approverUserId = (int)$formData['approver_user_id'];
    $draftId = (int)$formData['draft_id'];

    $docType = null;
    foreach ($documentTypes as $row) {
        if ((int)$row['id'] === $documentTypeId) {
            $docType = $row;
            break;
        }
    }

    if (!$docType) {
        $_SESSION['flash_error'] = 'Please select a valid document type.';
        redirect_back();
    }

    $docTypeName = (string)$docType['type_name'];
    $docPrefix = (string)$docType['prefix'];
    $isFormDocument = strtolower($docTypeName) === 'form';

    if ($formData['document_topic'] === '') {
        $_SESSION['flash_error'] = 'Document topic is required.';
        redirect_back();
    }

    if ($formData['document_number'] === '') {
        $_SESSION['flash_error'] = 'Document number is required.';
        redirect_back();
    }

    if ($action === 'submit_review') {
        if ($approverUserId <= 0) {
            $_SESSION['flash_error'] = 'Please select an approver before submitting.';
            redirect_back();
        }
        if ($approverUserId === $currentUserId) {
            $_SESSION['flash_error'] = 'Creator cannot select themselves as approver.';
            redirect_back();
        }
        if ($formData['purpose_scope'] === '') {
            $_SESSION['flash_error'] = 'Document Purpose & Scope is required before submission.';
            redirect_back();
        }

        if (!$isFormDocument) {
            $hasNewFile = isset($_FILES['document_file']) && ($_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $hasExistingFile = $formData['existing_file_path'] !== '';

            if ($formData['content_mode'] === 'file') {
                if (!$hasNewFile && !$hasExistingFile) {
                    $_SESSION['flash_error'] = 'Please upload a file before submission.';
                    redirect_back();
                }
            } else {
                if ($formData['content_text'] === '') {
                    $_SESSION['flash_error'] = 'Document content is required before submission.';
                    redirect_back();
                }
            }
        }

        if ($isFormDocument && $formData['form_name'] === '' && $formData['form_builder_json'] === '') {
            $_SESSION['flash_error'] = 'Please build or attach a form before submission.';
            redirect_back();
        }
    }

    mysqli_begin_transaction($conn);

    try {
        $existingDoc = null;

        if ($draftId > 0) {
            $checkSql = "
                SELECT d.*, 
                       dv.id AS version_id,
                       dv.primary_file_name,
                       dv.primary_file_path,
                       dv.primary_file_mime,
                       dv.primary_file_size,
                       dv.checksum_sha256,
                       dv.content_text
                       " . ($hasFormDefinitionLink ? ", dv.form_definition_id" : "") . "
                FROM documents d
                LEFT JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE d.id = ?
                LIMIT 1
            ";
            $existingDoc = fetch_one_prepared($conn, $checkSql, [$draftId]);
        } else {
            $newDocFullNumber = $docPrefix . '-' . $formData['document_number'] . '-' . $formData['document_topic'] . '-01';
            $dupStmt = exec_prepared($conn, "SELECT id FROM documents WHERE document_number = ? LIMIT 1", [$newDocFullNumber]);
            $dupRes = mysqli_stmt_get_result($dupStmt);
            $dupRow = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
            mysqli_stmt_close($dupStmt);
            if ($dupRow) {
                throw new Exception('Document ID already exists. Please change the number/topic.');
            }
        }

        $documentTitle = $formData['document_topic'];
        $documentNumberFull = $docPrefix . '-' . $formData['document_number'] . '-' . $formData['document_topic'] . '-01';
        $currentStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';
        $versionStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';
        $submittedBy = ($action === 'submit_review') ? $currentUserId : null;
        $submittedAt = ($action === 'submit_review') ? date('Y-m-d H:i:s') : null;
        $ackReq = (int)($docType['acknowledgement_required'] ?? 0);
        $remarks = $formData['purpose_scope'];
        $approverText = $approverUserId > 0 ? (string)$approverUserId : null;

        $existingFileName = $formData['existing_file_name'];
        $existingFilePath = $formData['existing_file_path'];
        $existingFileMime = $formData['existing_file_mime'];
        $existingFileSize = (int)$formData['existing_file_size'];
        $existingChecksum = '';

        if ($existingDoc) {
            $existingFileName = (string)($existingDoc['primary_file_name'] ?? '');
            $existingFilePath = (string)($existingDoc['primary_file_path'] ?? '');
            $existingFileMime = (string)($existingDoc['primary_file_mime'] ?? '');
            $existingFileSize = (int)($existingDoc['primary_file_size'] ?? 0);
            $existingChecksum = (string)($existingDoc['checksum_sha256'] ?? '');
        }

        $uploadedFileMeta = null;
        if (isset($_FILES['document_file']) && ($_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadedFileMeta = save_document_upload($_FILES['document_file']);
        }

        $primaryFileName = $uploadedFileMeta['original_name'] ?? $existingFileName;
        $primaryFilePath = $uploadedFileMeta['relative_path'] ?? $existingFilePath;
        $primaryFileMime = $uploadedFileMeta['mime_type'] ?? $existingFileMime;
        $primaryFileSize = $uploadedFileMeta['file_size'] ?? $existingFileSize;
        $checksumSha256 = $uploadedFileMeta['checksum'] ?? $existingChecksum;

        $finalContentFormat = $isFormDocument ? 'rich_text' : ($formData['content_mode'] === 'file' ? 'file' : 'rich_text');
        $contentText = $isFormDocument
            ? $formData['purpose_scope']
            : ($finalContentFormat === 'rich_text' ? $formData['content_text'] : $formData['purpose_scope']);

        $formDefinitionId = null;

        if ($isFormDocument && $formData['form_builder_json'] !== '' && $hasFormBuilderJson) {
            $existingFormDefinitionId = 0;
            if ($existingDoc && !empty($existingDoc['form_definition_id'])) {
                $existingFormDefinitionId = (int)$existingDoc['form_definition_id'];
            }

            if ($existingFormDefinitionId > 0) {
                $stmt = exec_prepared($conn, "
                    UPDATE form_definitions
                    SET form_name = ?, form_type = ?, linked_document_type_id = ?, builder_json = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                ", [
                    $formData['form_name'],
                    $formData['form_type'],
                    $documentTypeId,
                    $formData['form_builder_json'],
                    $currentUserId,
                    $existingFormDefinitionId
                ]);
                mysqli_stmt_close($stmt);
                $formDefinitionId = $existingFormDefinitionId;
            } else {
                $stmt = exec_prepared($conn, "
                    INSERT INTO form_definitions
                    (form_name, form_type, linked_document_type_id, status, builder_json, created_by, updated_by, created_at, updated_at)
                    VALUES (?, ?, ?, 'active', ?, ?, ?, NOW(), NOW())
                ", [
                    $formData['form_name'],
                    $formData['form_type'],
                    $documentTypeId,
                    $formData['form_builder_json'],
                    $currentUserId,
                    $currentUserId
                ]);
                $formDefinitionId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }
        }

        if ($existingDoc) {
            $documentId = (int)$existingDoc['id'];
            $documentVersionId = (int)($existingDoc['current_version_id'] ?? 0);
            $oldDocument = $existingDoc;

            $stmt = exec_prepared($conn, "
                UPDATE documents
                SET document_number = ?,
                    document_type_id = ?,
                    department_id = ?,
                    title = ?,
                    topic = ?,
                    owner_user_id = ?,
                    current_status = ?,
                    is_acknowledgement_required = ?,
                    remarks = ?,
                    updated_by = ?,
                    updated_at = NOW(),
                    approver = ?
                WHERE id = ?
            ", [
                $documentNumberFull,
                $documentTypeId,
                $departmentId,
                $documentTitle,
                $formData['document_topic'],
                $ownerUserId,
                $currentStatus,
                $ackReq,
                $remarks,
                $currentUserId,
                $approverText,
                $documentId
            ]);
            mysqli_stmt_close($stmt);

            if ($documentVersionId > 0) {
                $changeSummary = ($action === 'submit_review') ? 'Draft updated and submitted for review' : 'Draft updated';

                if ($hasFormDefinitionLink) {
                    $stmt = exec_prepared($conn, "
                        UPDATE document_versions
                        SET title_snapshot = ?,
                            topic_snapshot = ?,
                            owner_user_id = ?,
                            change_summary = ?,
                            effective_date = ?,
                            review_date = ?,
                            status = ?,
                            content_format = ?,
                            content_text = ?,
                            form_definition_id = ?,
                            primary_file_name = ?,
                            primary_file_path = ?,
                            primary_file_mime = ?,
                            primary_file_size = ?,
                            checksum_sha256 = ?,
                            submitted_by = ?,
                            submitted_at = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ", [
                        $documentTitle,
                        $formData['document_topic'],
                        $ownerUserId,
                        $changeSummary,
                        $formData['effective_date'],
                        $formData['review_date'],
                        $versionStatus,
                        $finalContentFormat,
                        $contentText,
                        $formDefinitionId,
                        $primaryFileName !== '' ? $primaryFileName : null,
                        $primaryFilePath !== '' ? $primaryFilePath : null,
                        $primaryFileMime !== '' ? $primaryFileMime : null,
                        $primaryFileSize > 0 ? $primaryFileSize : null,
                        $checksumSha256 !== '' ? $checksumSha256 : null,
                        $submittedBy,
                        $submittedAt,
                        $documentVersionId
                    ]);
                    mysqli_stmt_close($stmt);
                } else {
                    $stmt = exec_prepared($conn, "
                        UPDATE document_versions
                        SET title_snapshot = ?,
                            topic_snapshot = ?,
                            owner_user_id = ?,
                            change_summary = ?,
                            effective_date = ?,
                            review_date = ?,
                            status = ?,
                            content_format = ?,
                            content_text = ?,
                            primary_file_name = ?,
                            primary_file_path = ?,
                            primary_file_mime = ?,
                            primary_file_size = ?,
                            checksum_sha256 = ?,
                            submitted_by = ?,
                            submitted_at = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ", [
                        $documentTitle,
                        $formData['document_topic'],
                        $ownerUserId,
                        $changeSummary,
                        $formData['effective_date'],
                        $formData['review_date'],
                        $versionStatus,
                        $finalContentFormat,
                        $contentText,
                        $primaryFileName !== '' ? $primaryFileName : null,
                        $primaryFilePath !== '' ? $primaryFilePath : null,
                        $primaryFileMime !== '' ? $primaryFileMime : null,
                        $primaryFileSize > 0 ? $primaryFileSize : null,
                        $checksumSha256 !== '' ? $checksumSha256 : null,
                        $submittedBy,
                        $submittedAt,
                        $documentVersionId
                    ]);
                    mysqli_stmt_close($stmt);
                }
            } else {
                $changeSummary = ($action === 'submit_review') ? 'Document created and submitted for review' : 'Initial draft created';

                if ($hasFormDefinitionLink) {
                    $stmt = exec_prepared($conn, "
                        INSERT INTO document_versions
                        (document_id, previous_version_id, version_sequence, version_label, title_snapshot, topic_snapshot, owner_user_id, created_by, change_summary, effective_date, review_date, status, content_format, content_text, form_definition_id, primary_file_name, primary_file_path, primary_file_mime, primary_file_size, checksum_sha256, submitted_by, submitted_at, created_at, updated_at)
                        VALUES (?, NULL, 1, '01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ", [
                        $documentId,
                        $documentTitle,
                        $formData['document_topic'],
                        $ownerUserId,
                        $currentUserId,
                        $changeSummary,
                        $formData['effective_date'],
                        $formData['review_date'],
                        $versionStatus,
                        $finalContentFormat,
                        $contentText,
                        $formDefinitionId,
                        $primaryFileName !== '' ? $primaryFileName : null,
                        $primaryFilePath !== '' ? $primaryFilePath : null,
                        $primaryFileMime !== '' ? $primaryFileMime : null,
                        $primaryFileSize > 0 ? $primaryFileSize : null,
                        $checksumSha256 !== '' ? $checksumSha256 : null,
                        $submittedBy,
                        $submittedAt
                    ]);
                    $documentVersionId = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);
                } else {
                    $stmt = exec_prepared($conn, "
                        INSERT INTO document_versions
                        (document_id, previous_version_id, version_sequence, version_label, title_snapshot, topic_snapshot, owner_user_id, created_by, change_summary, effective_date, review_date, status, content_format, content_text, primary_file_name, primary_file_path, primary_file_mime, primary_file_size, checksum_sha256, submitted_by, submitted_at, created_at, updated_at)
                        VALUES (?, NULL, 1, '01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ", [
                        $documentId,
                        $documentTitle,
                        $formData['document_topic'],
                        $ownerUserId,
                        $currentUserId,
                        $changeSummary,
                        $formData['effective_date'],
                        $formData['review_date'],
                        $versionStatus,
                        $finalContentFormat,
                        $contentText,
                        $primaryFileName !== '' ? $primaryFileName : null,
                        $primaryFilePath !== '' ? $primaryFilePath : null,
                        $primaryFileMime !== '' ? $primaryFileMime : null,
                        $primaryFileSize > 0 ? $primaryFileSize : null,
                        $checksumSha256 !== '' ? $checksumSha256 : null,
                        $submittedBy,
                        $submittedAt
                    ]);
                    $documentVersionId = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);
                }

                $stmt = exec_prepared($conn, "UPDATE documents SET current_version_id = ? WHERE id = ?", [
                    $documentVersionId,
                    $documentId
                ]);
                mysqli_stmt_close($stmt);
            }

            if ($uploadedFileMeta && table_exists($conn, 'document_version_attachments')) {
                $stmt = exec_prepared($conn, "
                    INSERT INTO document_version_attachments
                    (document_version_id, attachment_type, original_file_name, stored_file_name, file_path, mime_type, file_size, checksum_sha256, uploaded_by, uploaded_at)
                    VALUES (?, 'primary', ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $documentVersionId,
                    $uploadedFileMeta['original_name'],
                    $uploadedFileMeta['stored_name'],
                    $uploadedFileMeta['relative_path'],
                    $uploadedFileMeta['mime_type'],
                    $uploadedFileMeta['file_size'],
                    $uploadedFileMeta['checksum'],
                    $currentUserId
                ]);
                mysqli_stmt_close($stmt);
            }

            if ($action === 'submit_review' && $approverUserId > 0) {
                $workflowExists = fetch_one_prepared($conn, "
                    SELECT id
                    FROM workflow_instances
                    WHERE document_version_id = ? AND workflow_type = 'document_approval'
                    LIMIT 1
                ", [$documentVersionId]);

                if (!$workflowExists) {
                    $stmt = exec_prepared($conn, "
                        INSERT INTO workflow_instances
                        (document_version_id, workflow_type, workflow_status, current_step_number, initiated_by, initiated_at)
                        VALUES (?, 'document_approval', 'pending', 1, ?, NOW())
                    ", [
                        $documentVersionId,
                        $currentUserId
                    ]);
                    $workflowInstanceId = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);

                    $stmt = exec_prepared($conn, "
                        INSERT INTO workflow_steps
                        (workflow_instance_id, document_version_id, step_number, step_type, approver_user_id, step_name, status, action_status, due_at)
                        VALUES (?, ?, 1, 'approval', ?, 'Approval Required', 'pending', 'pending', NULL)
                    ", [
                        $workflowInstanceId,
                        $documentVersionId,
                        $approverUserId
                    ]);
                    mysqli_stmt_close($stmt);
                }

                if (table_exists($conn, 'notifications')) {
                    $stmt = exec_prepared($conn, "
                        INSERT INTO notifications
                        (user_id, notification_type, reference_type, reference_id, title, message, is_read, created_at)
                        VALUES (?, 'submit', 'document_version', ?, ?, ?, 0, NOW())
                    ", [
                        $approverUserId,
                        $documentVersionId,
                        'Document Submitted for Review',
                        'A new document "' . $documentTitle . '" has been submitted for your review.'
                    ]);
                    mysqli_stmt_close($stmt);
                }
            }

            $newValue = [
                'document_id' => $documentId,
                'document_version_id' => $documentVersionId,
                'document_number' => $documentNumberFull,
                'title' => $documentTitle,
                'topic' => $formData['document_topic'],
                'status' => $currentStatus,
                'version_label' => '01',
                'approver_user_id' => $approverUserId,
                'owner_user_id' => $ownerUserId,
            ];

            write_audit_log(
                $conn,
                'document',
                $documentId,
                $action === 'submit_review' ? 'submit' : 'draft_save',
                $oldDocument,
                $newValue,
                $currentUserId,
                $action === 'submit_review' ? 'Document draft updated and submitted for review.' : 'Document draft updated.'
            );

            mysqli_commit($conn);

            unset($_SESSION['create_document_old']);

            if ($action === 'submit_review') {
                header('Location: submit-review.php?id=' . $documentId . '&submitted=1');
                exit;
            }

            $_SESSION['flash_success'] = 'Draft saved successfully.';
            $_SESSION['create_document_old'] = $formData;
            $_SESSION['create_document_old']['draft_id'] = (string)$documentId;
            $_SESSION['create_document_old']['existing_file_name'] = $primaryFileName;
            $_SESSION['create_document_old']['existing_file_path'] = $primaryFilePath;
            $_SESSION['create_document_old']['existing_file_mime'] = $primaryFileMime;
            $_SESSION['create_document_old']['existing_file_size'] = (string)$primaryFileSize;
            redirect_back();
        } else {
            $stmt = exec_prepared($conn, "
                INSERT INTO documents
                (document_number, document_type_id, department_id, title, topic, owner_user_id, created_by, current_status, is_acknowledgement_required, remarks, created_at, updated_at, approver)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
            ", [
                $documentNumberFull,
                $documentTypeId,
                $departmentId,
                $documentTitle,
                $formData['document_topic'],
                $ownerUserId,
                $currentUserId,
                $currentStatus,
                $ackReq,
                $remarks,
                $approverText
            ]);
            $documentId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $changeSummary = ($action === 'submit_review') ? 'Document created and submitted for review' : 'Initial draft created';

            if ($hasFormDefinitionLink) {
                $stmt = exec_prepared($conn, "
                    INSERT INTO document_versions
                    (document_id, previous_version_id, version_sequence, version_label, title_snapshot, topic_snapshot, owner_user_id, created_by, change_summary, effective_date, review_date, status, content_format, content_text, form_definition_id, primary_file_name, primary_file_path, primary_file_mime, primary_file_size, checksum_sha256, submitted_by, submitted_at, created_at, updated_at)
                    VALUES (?, NULL, 1, '01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ", [
                    $documentId,
                    $documentTitle,
                    $formData['document_topic'],
                    $ownerUserId,
                    $currentUserId,
                    $changeSummary,
                    $formData['effective_date'],
                    $formData['review_date'],
                    $versionStatus,
                    $finalContentFormat,
                    $contentText,
                    $formDefinitionId,
                    $primaryFileName !== '' ? $primaryFileName : null,
                    $primaryFilePath !== '' ? $primaryFilePath : null,
                    $primaryFileMime !== '' ? $primaryFileMime : null,
                    $primaryFileSize > 0 ? $primaryFileSize : null,
                    $checksumSha256 !== '' ? $checksumSha256 : null,
                    $submittedBy,
                    $submittedAt
                ]);
                $documentVersionId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            } else {
                $stmt = exec_prepared($conn, "
                    INSERT INTO document_versions
                    (document_id, previous_version_id, version_sequence, version_label, title_snapshot, topic_snapshot, owner_user_id, created_by, change_summary, effective_date, review_date, status, content_format, content_text, primary_file_name, primary_file_path, primary_file_mime, primary_file_size, checksum_sha256, submitted_by, submitted_at, created_at, updated_at)
                    VALUES (?, NULL, 1, '01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ", [
                    $documentId,
                    $documentTitle,
                    $formData['document_topic'],
                    $ownerUserId,
                    $currentUserId,
                    $changeSummary,
                    $formData['effective_date'],
                    $formData['review_date'],
                    $versionStatus,
                    $finalContentFormat,
                    $contentText,
                    $primaryFileName !== '' ? $primaryFileName : null,
                    $primaryFilePath !== '' ? $primaryFilePath : null,
                    $primaryFileMime !== '' ? $primaryFileMime : null,
                    $primaryFileSize > 0 ? $primaryFileSize : null,
                    $checksumSha256 !== '' ? $checksumSha256 : null,
                    $submittedBy,
                    $submittedAt
                ]);
                $documentVersionId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            $stmt = exec_prepared($conn, "UPDATE documents SET current_version_id = ? WHERE id = ?", [
                $documentVersionId,
                $documentId
            ]);
            mysqli_stmt_close($stmt);

            if ($uploadedFileMeta && table_exists($conn, 'document_version_attachments')) {
                $stmt = exec_prepared($conn, "
                    INSERT INTO document_version_attachments
                    (document_version_id, attachment_type, original_file_name, stored_file_name, file_path, mime_type, file_size, checksum_sha256, uploaded_by, uploaded_at)
                    VALUES (?, 'primary', ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $documentVersionId,
                    $uploadedFileMeta['original_name'],
                    $uploadedFileMeta['stored_name'],
                    $uploadedFileMeta['relative_path'],
                    $uploadedFileMeta['mime_type'],
                    $uploadedFileMeta['file_size'],
                    $uploadedFileMeta['checksum'],
                    $currentUserId
                ]);
                mysqli_stmt_close($stmt);
            }

            if ($action === 'submit_review' && $approverUserId > 0) {
                $stmt = exec_prepared($conn, "
                    INSERT INTO workflow_instances
                    (document_version_id, workflow_type, workflow_status, current_step_number, initiated_by, initiated_at)
                    VALUES (?, 'document_approval', 'pending', 1, ?, NOW())
                ", [
                    $documentVersionId,
                    $currentUserId
                ]);
                $workflowInstanceId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $stmt = exec_prepared($conn, "
                    INSERT INTO workflow_steps
                    (workflow_instance_id, document_version_id, step_number, step_type, approver_user_id, step_name, status, action_status, due_at)
                    VALUES (?, ?, 1, 'approval', ?, 'Approval Required', 'pending', 'pending', NULL)
                ", [
                    $workflowInstanceId,
                    $documentVersionId,
                    $approverUserId
                ]);
                mysqli_stmt_close($stmt);

                if (table_exists($conn, 'notifications')) {
                    $stmt = exec_prepared($conn, "
                        INSERT INTO notifications
                        (user_id, notification_type, reference_type, reference_id, title, message, is_read, created_at)
                        VALUES (?, 'submit', 'document_version', ?, ?, ?, 0, NOW())
                    ", [
                        $approverUserId,
                        $documentVersionId,
                        'Document Submitted for Review',
                        'A new document "' . $documentTitle . '" has been submitted for your review.'
                    ]);
                    mysqli_stmt_close($stmt);
                }
            }

            $newValue = [
                'document_id' => $documentId,
                'document_version_id' => $documentVersionId,
                'document_number' => $documentNumberFull,
                'title' => $documentTitle,
                'topic' => $formData['document_topic'],
                'status' => $currentStatus,
                'version_label' => '01',
                'approver_user_id' => $approverUserId,
                'owner_user_id' => $ownerUserId,
            ];

            write_audit_log(
                $conn,
                'document',
                $documentId,
                $action === 'submit_review' ? 'submit' : 'create',
                null,
                $newValue,
                $currentUserId,
                $action === 'submit_review' ? 'Document created and submitted for review.' : 'Document draft created.'
            );

            mysqli_commit($conn);

            unset($_SESSION['create_document_old']);

            if ($action === 'submit_review') {
                header('Location: submit-review.php?id=' . $documentId . '&submitted=1');
                exit;
            }

            $_SESSION['flash_success'] = 'Draft created successfully.';
            $_SESSION['create_document_old'] = $formData;
            $_SESSION['create_document_old']['draft_id'] = (string)$documentId;
            $_SESSION['create_document_old']['existing_file_name'] = $primaryFileName;
            $_SESSION['create_document_old']['existing_file_path'] = $primaryFilePath;
            $_SESSION['create_document_old']['existing_file_mime'] = $primaryFileMime;
            $_SESSION['create_document_old']['existing_file_size'] = (string)$primaryFileSize;
            redirect_back();
        }
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['flash_error'] = $e->getMessage();
        redirect_back();
    }
}

$currentTypePrefix = 'SOP';
$currentTypeReviewCycle = 365;
foreach ($documentTypes as $row) {
    if ((string)$row['id'] === (string)$formData['document_type_id']) {
        $currentTypePrefix = (string)$row['prefix'];
        $currentTypeReviewCycle = (int)$row['review_cycle_days'];
        break;
    }
}

$docIdPreview = $currentTypePrefix . '-' .
    ($formData['document_number'] !== '' ? $formData['document_number'] : '104') . '-' .
    ($formData['document_topic'] !== '' ? $formData['document_topic'] : 'CAPA') . '-01';

$builderDraftId = $formData['draft_id'] !== '' ? $formData['draft_id'] : 'new';
$builderUrl = 'form-builder.php?' . http_build_query([
    'draft_id' => $builderDraftId,
    'document_type_id' => $formData['document_type_id'],
    'document_topic' => $formData['document_topic'],
    'document_number' => $formData['document_number'],
]);

$existingFileDisplay = $formData['existing_file_name'] !== '' ? $formData['existing_file_name'] : '';
$existingFileSizeDisplay = ((int)$formData['existing_file_size'] > 0) ? round(((int)$formData['existing_file_size']) / 1024, 2) . ' KB' : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Create Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .upload-box.drag-over {
      border-color: #0d6efd !important;
      background: #eef5ff !important;
    }
    .file-selected-box {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 8px;
      padding: 12px 14px;
      font-size: 13px;
    }
    .readonly-field {
      background: #f5f7fa !important;
      color: #6b7280 !important;
      cursor: not-allowed;
    }
  </style>
</head>
<body>
<?php include('includes/navbar.php'); ?>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <div class="mb-4">
    <h1 class="page-title mb-2">Create Controlled Document</h1>
    <p class="page-subtitle mb-0">Create a new controlled document with required metadata, ownership, and approval workflow.</p>
  </div>

  <?php if ($flashSuccess !== ''): ?>
    <div class="alert alert-success"><?php echo e($flashSuccess); ?></div>
  <?php endif; ?>
  <?php if ($flashError !== ''): ?>
    <div class="alert alert-danger"><?php echo e($flashError); ?></div>
  <?php endif; ?>

  <form method="post" id="docForm" enctype="multipart/form-data">
    <input type="hidden" name="draft_id" id="draft_id" value="<?php echo e($formData['draft_id']); ?>">
    <input type="hidden" name="action" id="form_action" value="save_draft">
    <input type="hidden" name="content_mode" id="content_mode" value="<?php echo e($formData['content_mode']); ?>">
    <input type="hidden" name="form_name" id="form_name_hidden" value="<?php echo e($formData['form_name']); ?>">
    <input type="hidden" name="form_type" id="form_type_hidden" value="<?php echo e($formData['form_type']); ?>">
    <input type="hidden" name="form_desc" id="form_desc_hidden" value="<?php echo e($formData['form_desc']); ?>">
    <input type="hidden" name="form_builder_json" id="form_builder_json_hidden" value="<?php echo e($formData['form_builder_json']); ?>">
    <input type="hidden" name="owner_user_id" value="<?php echo (int)$currentUserId; ?>">
    <input type="hidden" name="existing_file_name" value="<?php echo e($formData['existing_file_name']); ?>">
    <input type="hidden" name="existing_file_path" value="<?php echo e($formData['existing_file_path']); ?>">
    <input type="hidden" name="existing_file_mime" value="<?php echo e($formData['existing_file_mime']); ?>">
    <input type="hidden" name="existing_file_size" value="<?php echo e($formData['existing_file_size']); ?>">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <span class="badge badge-soft-secondary"><?php echo $formData['draft_id'] !== '' ? 'Draft Saved' : 'Draft'; ?></span>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary" href="dashboard-admin.php">Cancel</a>
        <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('form_action').value='save_draft';">Save Draft</button>
        <button type="submit" class="btn btn-success" onclick="document.getElementById('form_action').value='submit_review';">Submit for Review</button>
      </div>
    </div>

    <div id="formReturnBanner" class="alert alert-success d-flex align-items-center gap-2 mb-3 d-none" role="alert">
      <span id="formReturnMsg">Form attached successfully.</span>
    </div>

    <div class="row g-3">
      <div class="col-lg-8">

        <div class="card cp-card mb-3">
          <div class="card-body">
            <h2 class="card-title mb-1">Document Information</h2>
            <p class="card-subtitle mb-3">Enter the required document metadata and ownership details.</p>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Document Type</label>
                <select class="form-select" id="docTypeSelect" name="document_type_id" onchange="handleDocTypeChange(this)">
                  <?php foreach ($documentTypes as $type): ?>
                    <option value="<?php echo (int)$type['id']; ?>"
                            data-prefix="<?php echo e($type['prefix']); ?>"
                            data-name="<?php echo e($type['type_name']); ?>"
                            data-review-days="<?php echo (int)$type['review_cycle_days']; ?>"
                            <?php echo (string)$formData['document_type_id'] === (string)$type['id'] ? 'selected' : ''; ?>>
                      <?php echo e($type['type_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Document Topic</label>
                <input class="form-control" id="docTopic" name="document_topic" value="<?php echo e($formData['document_topic']); ?>"/>
              </div>

              <div class="col-md-6">
                <label class="form-label">Document Number</label>
                <input class="form-control" id="docNumber" name="document_number" value="<?php echo e($formData['document_number']); ?>" oninput="updateDocIdPreview()"/>
              </div>

              <div class="col-md-6">
                <label class="form-label">Version</label>
                <input class="form-control readonly" readonly value="01"/>
              </div>

              <div class="col-md-6">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id">
                  <option value="">-- Select Department --</option>
                  <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo (int)$dept['id']; ?>" <?php echo (string)$formData['department_id'] === (string)$dept['id'] ? 'selected' : ''; ?>>
                      <?php echo e($dept['department_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Owner</label>
                <input class="form-control readonly-field" type="text" readonly value="<?php echo e($creatorName); ?>">
                <div class="form-text">Owner is automatically set as the document creator.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Approver</label>
                <select class="form-select" name="approver_user_id">
                  <option value="">-- Select Approver --</option>
                  <?php foreach ($approvers as $approver): ?>
                    <?php
                      $approverName = trim(($approver['first_name'] ?? '') . ' ' . ($approver['last_name'] ?? ''));
                      $label = $approverName . ' — ' . ($approver['role_name'] ?? 'Approver');
                    ?>
                    <option value="<?php echo (int)$approver['id']; ?>" <?php echo (string)$formData['approver_user_id'] === (string)$approver['id'] ? 'selected' : ''; ?>>
                      <?php echo e($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Creator cannot select themselves as approver.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Effective Date</label>
                <input class="form-control" type="date" name="effective_date" id="effective_date" value="<?php echo e($formData['effective_date']); ?>"/>
              </div>

              <div class="col-md-6">
                <label class="form-label">Review Date</label>
                <input class="form-control" type="date" name="review_date" id="review_date" value="<?php echo e($formData['review_date']); ?>"/>
              </div>

              <div class="col-12">
                <label class="form-label">Document Purpose &amp; Scope <span class="text-danger">*</span></label>
                <textarea class="form-control" name="purpose_scope" placeholder="Describe the purpose, scope and intended audience of this document" rows="3"><?php echo e($formData['purpose_scope']); ?></textarea>
                <div class="form-text">Mandatory — this will be used as the document summary in the approval workflow and audit trail.</div>
              </div>

              <div class="col-12">
                <label class="form-label">Document ID Preview</label>
                <div class="kv p-3 fw-semibold text-primary" id="docIdPreview"><?php echo e($docIdPreview); ?></div>
                <div class="form-text">Format: [Type]-[Number]-[Topic]-[Version]</div>
              </div>
            </div>
          </div>
        </div>

        <div class="card cp-card" id="contentCard">
          <div class="card-body">
            <h2 class="card-title mb-1">Document Content</h2>
            <p class="card-subtitle mb-3">Add document content using rich text or controlled file upload.</p>

            <ul class="nav nav-pills gap-2 mb-3">
              <li class="nav-item">
                <a class="nav-link tab-pill <?php echo $formData['content_mode'] === 'file' ? 'active' : ''; ?>" href="#" id="tabFileUpload" onclick="setContentMode('file'); return false;">File Upload</a>
              </li>
              <li class="nav-item">
                <a class="nav-link tab-pill <?php echo $formData['content_mode'] === 'rich_text' ? 'active' : ''; ?>" href="#" id="tabRichText" onclick="setContentMode('rich_text'); return false;">Rich Text Editor</a>
              </li>
            </ul>

            <div id="fileUploadPanel" class="<?php echo $formData['content_mode'] === 'file' ? '' : 'd-none'; ?>">
              <input type="file" name="document_file" id="document_file" accept=".pdf,.doc,.docx,.xls,.xlsx" style="display:none;">

              <div class="upload-box p-4 text-center small text-secondary mb-3" id="documentUploadBox" style="cursor:pointer;">
                Drag and drop file here or click to browse.<br/>
                Supported: PDF, DOCX, XLSX | Maximum size: 25 MB
              </div>

              <div id="selectedFileInfo" class="file-selected-box <?php echo $existingFileDisplay !== '' ? '' : 'd-none'; ?>">
                <div class="fw-semibold text-primary" id="selectedFileName"><?php echo e($existingFileDisplay); ?></div>
                <div class="small text-secondary" id="selectedFileMeta"><?php echo e($existingFileSizeDisplay); ?></div>
              </div>
            </div>

            <div id="richTextPanel" class="<?php echo $formData['content_mode'] === 'rich_text' ? '' : 'd-none'; ?>">
              <div class="mb-3">
                <label class="form-label">Document Body</label>
                <textarea class="form-control" name="content_text" id="content_text" placeholder="Enter document content here" rows="9"><?php echo e($formData['content_text']); ?></textarea>
              </div>
            </div>
          </div>
        </div>

        <div class="card cp-card d-none" id="formTypePanel">
          <div class="card-body">
            <h2 class="card-title mb-1">Form / Checklist</h2>
            <p class="card-subtitle mb-3">Build a custom form or checklist, or upload an existing one.</p>

            <ul class="nav nav-pills gap-2 mb-4" id="formModeTabs">
              <li class="nav-item">
                <a class="nav-link active tab-pill" href="#" id="tabBuild" onclick="switchFormMode('build'); return false;">Build in System</a>
              </li>
              <li class="nav-item">
                <a class="nav-link tab-pill" href="#" id="tabUpload" onclick="switchFormMode('upload'); return false;">Upload Existing</a>
              </li>
            </ul>

            <div id="formBuildMode">
              <div id="attachedFormSummary" class="d-none mb-3">
                <div class="d-flex align-items-start justify-content-between gap-3 p-3 rounded-3" style="background:var(--cp-surface,#f8f9fb);border:1px solid var(--cp-border,#dde3ec);">
                  <div>
                    <div class="fw-semibold text-primary mb-1" id="attachedFormName">—</div>
                    <div class="small text-secondary" id="attachedFormMeta">—</div>
                  </div>
                  <div class="d-flex gap-2 flex-shrink-0">
                    <a id="editFormLink" href="<?php echo e($builderUrl); ?>&edit=1" class="btn btn-sm btn-outline-secondary">Edit Form</a>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="detachForm()">Remove</button>
                  </div>
                </div>
              </div>

              <div id="noFormAttached">
                <div class="upload-box p-4 text-center mb-3" style="cursor:default;">
                  <div class="mb-2" style="font-size:2rem;">📋</div>
                  <div class="fw-semibold mb-1">No form built yet</div>
                  <p class="small text-secondary mb-3">
                    Use the Form Builder to add fields, checkboxes, dropdowns and signature blocks.<br>
                    Once confirmed, it will be attached to this document automatically.
                  </p>
                  <a href="<?php echo e($builderUrl); ?>" class="btn btn-primary" id="openBuilderBtn">
                    + Create Form / Checklist
                  </a>
                </div>
              </div>
            </div>

            <div id="formUploadMode" class="d-none">
              <div class="upload-box p-4 text-center mb-3">
                Drag and drop your form file here or click to browse.<br/>
                <span class="small text-secondary">Supported: PDF, DOCX, XLSX | Maximum size: 25 MB</span>
              </div>
              <div class="mb-3">
                <label class="form-label">Form / Checklist Name <span class="text-danger">*</span></label>
                <input class="form-control" type="text" placeholder="Enter a unique name for this form">
                <div class="form-text">Form name must be unique across the system.</div>
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
              <li>Metadata completed.</li>
              <li>Unique document ID validated.</li>
              <li>Content entered or file attached.</li>
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
              <li>Created by / created on / IP address captured automatically.</li>
              <li>Draft saves logged with timestamp.</li>
              <li>Critical field changes stored with old and new values.</li>
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
function getSelectedTypeOption() {
  return document.getElementById('docTypeSelect').selectedOptions[0];
}

function handleDocTypeChange(selectEl) {
  const opt = selectEl.selectedOptions[0];
  const typeName = (opt.dataset.name || '').toLowerCase();
  const isForm = typeName === 'form';

  document.getElementById('contentCard').classList.toggle('d-none', isForm);
  document.getElementById('formTypePanel').classList.toggle('d-none', !isForm);

  const reviewDays = parseInt(opt.dataset.reviewDays || '365', 10);
  const eff = document.getElementById('effective_date').value;
  if (eff) {
    const base = new Date(eff + 'T00:00:00');
    base.setDate(base.getDate() + reviewDays);
    document.getElementById('review_date').value = base.toISOString().slice(0, 10);
  }

  updateDocIdPreview();
}

function setContentMode(mode) {
  document.getElementById('content_mode').value = mode;
  document.getElementById('tabFileUpload').classList.toggle('active', mode === 'file');
  document.getElementById('tabRichText').classList.toggle('active', mode === 'rich_text');
  document.getElementById('fileUploadPanel').classList.toggle('d-none', mode !== 'file');
  document.getElementById('richTextPanel').classList.toggle('d-none', mode !== 'rich_text');
}

function switchFormMode(mode) {
  document.getElementById('formBuildMode').classList.toggle('d-none', mode !== 'build');
  document.getElementById('formUploadMode').classList.toggle('d-none', mode === 'build');
  document.getElementById('tabBuild').classList.toggle('active', mode === 'build');
  document.getElementById('tabUpload').classList.toggle('active', mode !== 'build');
}

function detachForm() {
  const draftId = document.getElementById('draft_id').value || 'new';
  const raw = sessionStorage.getItem('cpBuiltForm');

  if (raw) {
    try {
      const data = JSON.parse(raw);
      if ((data.draftId || 'new') === draftId) {
        sessionStorage.removeItem('cpBuiltForm');
      }
    } catch (e) {}
  }

  document.getElementById('form_name_hidden').value = '';
  document.getElementById('form_type_hidden').value = '';
  document.getElementById('form_desc_hidden').value = '';
  document.getElementById('form_builder_json_hidden').value = '';
  document.getElementById('attachedFormSummary').classList.add('d-none');
  document.getElementById('noFormAttached').classList.remove('d-none');
  document.getElementById('formReturnBanner').classList.add('d-none');
}

function updateDocIdPreview() {
  const opt = getSelectedTypeOption();
  const prefix = opt ? (opt.dataset.prefix || 'SOP') : 'SOP';
  const number = document.getElementById('docNumber').value || '104';
  const topic = document.getElementById('docTopic').value || 'CAPA';
  document.getElementById('docIdPreview').textContent = prefix + '-' + number + '-' + topic + '-01';
}

document.getElementById('docTopic').addEventListener('input', updateDocIdPreview);
document.getElementById('docNumber').addEventListener('input', updateDocIdPreview);

document.getElementById('effective_date').addEventListener('change', function() {
  handleDocTypeChange(document.getElementById('docTypeSelect'));
});

document.getElementById('openBuilderBtn').addEventListener('click', function() {
  const draftId = document.getElementById('draft_id').value || 'new';

  const ctx = {
    draftId: draftId,
    docTypeId: document.getElementById('docTypeSelect').value,
    docType: getSelectedTypeOption() ? getSelectedTypeOption().dataset.name : '',
    docNumber: document.getElementById('docNumber').value,
    docTopic: document.getElementById('docTopic').value,
    returnUrl: 'create-document.php?draft_id=' + encodeURIComponent(draftId)
  };

  sessionStorage.setItem('cpFormBuilderContext', JSON.stringify(ctx));
});

(function restoreBuiltForm() {
  const raw = sessionStorage.getItem('cpBuiltForm');
  const draftId = document.getElementById('draft_id').value || 'new';

  if (!raw) {
    handleDocTypeChange(document.getElementById('docTypeSelect'));
    setContentMode(document.getElementById('content_mode').value || 'file');
    return;
  }

  try {
    const data = JSON.parse(raw);

    if ((data.draftId || 'new') !== draftId) {
      handleDocTypeChange(document.getElementById('docTypeSelect'));
      setContentMode(document.getElementById('content_mode').value || 'file');
      return;
    }

    document.getElementById('form_name_hidden').value = data.formName || '';
    document.getElementById('form_type_hidden').value = data.formType || '';
    document.getElementById('form_desc_hidden').value = data.formDesc || '';
    document.getElementById('form_builder_json_hidden').value = JSON.stringify(data);

    const sel = document.getElementById('docTypeSelect');
    for (let i = 0; i < sel.options.length; i++) {
      if ((sel.options[i].dataset.name || '').toLowerCase() === 'form') {
        sel.selectedIndex = i;
        break;
      }
    }
    handleDocTypeChange(sel);

    const fieldCount = Array.isArray(data.fields) ? data.fields.length : 0;
    const sectionCount = Array.isArray(data.sections) ? data.sections.length : 0;

    document.getElementById('attachedFormName').textContent = data.formName || 'Untitled Form';
    document.getElementById('attachedFormMeta').textContent =
      fieldCount + ' field' + (fieldCount !== 1 ? 's' : '') +
      (sectionCount > 1 ? ', ' + sectionCount + ' sections' : '') +
      ' · Built ' + new Date().toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' });

    document.getElementById('attachedFormSummary').classList.remove('d-none');
    document.getElementById('noFormAttached').classList.add('d-none');

    const banner = document.getElementById('formReturnBanner');
    const msg = document.getElementById('formReturnMsg');
    msg.textContent = '"' + (data.formName || 'Untitled Form') + '" attached — ' +
                      fieldCount + ' field' + (fieldCount !== 1 ? 's' : '') + ' ready.';
    banner.classList.remove('d-none');
  } catch (e) {
    console.warn('Could not restore built form:', e);
    handleDocTypeChange(document.getElementById('docTypeSelect'));
  }

  setContentMode(document.getElementById('content_mode').value || 'file');
})();

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

updateDocIdPreview();
setContentMode(document.getElementById('content_mode').value || 'file');
</script>
</body>
</html>