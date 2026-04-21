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
    function redirect_back(string $query = ''): void
    {
        $url = 'create-document.php';
        if ($query !== '') {
            $url .= '?' . ltrim($query, '?');
        }
        header('Location: ' . $url);
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

if (!function_exists('build_auto_topic')) {
    function build_auto_topic(string $typeName, string $documentNumber): string
    {
        $typeName = trim($typeName);
        $documentNumber = trim($documentNumber);

        if ($documentNumber !== '') {
            return $typeName . ' ' . $documentNumber;
        }

        return $typeName . ' Draft';
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

$hasFormDefinitionLink = has_column($conn, 'document_versions', 'form_definition_id');
$hasFormBuilderJson = table_exists($conn, 'form_definitions') && has_column($conn, 'form_definitions', 'builder_json');

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

$approvers = fetch_all_assoc($conn, "
    SELECT u.id, u.first_name, u.last_name, u.email, r.role_code, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.status = 'active'
      AND r.role_code IN ('qa_admin', 'super_admin')
    ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC
");

$defaultTypeId = (int)($documentTypes[0]['id'] ?? 0);
$defaultTypeName = (string)($documentTypes[0]['type_name'] ?? 'SOP');
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
    'document_topic'       => (string)($old['document_topic'] ?? build_auto_topic($defaultTypeName, (string)($old['document_number'] ?? ''))),
    'document_number'      => (string)($old['document_number'] ?? ''),
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
    'form_response_json'   => (string)($old['form_response_json'] ?? ''),
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
        'document_topic'       => '',
        'document_number'      => trim((string)($_POST['document_number'] ?? '')),
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
        'form_response_json'   => trim((string)($_POST['form_response_json'] ?? '')),
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

    $documentTypeId = (int)$formData['document_type_id'];
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
        $_SESSION['create_document_old'] = $formData;
        redirect_back();
    }

    $docTypeName = (string)$docType['type_name'];
    $docPrefix = (string)$docType['prefix'];
    $isFormDocument = strtolower($docTypeName) === 'form';

    $formData['document_topic'] = build_auto_topic($docTypeName, $formData['document_number']);
    $_SESSION['create_document_old'] = $formData;

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

        if ($isFormDocument && $formData['form_builder_json'] === '') {
            $_SESSION['flash_error'] = 'Please create Form / Checklist Builder data before submission.';
            redirect_back();
        }
    }

    mysqli_begin_transaction($conn);

    try {
        $documentTitle = $formData['document_topic'];
        $documentNumberFull = $docPrefix . '-' . $formData['document_number'] . '-' . $formData['document_topic'] . '-01';
        $currentStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';
        $versionStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';
        $submittedBy = ($action === 'submit_review') ? $currentUserId : null;
        $submittedAt = ($action === 'submit_review') ? date('Y-m-d H:i:s') : null;
        $ackReq = (int)($docType['acknowledgement_required'] ?? 0);
        $remarks = $formData['purpose_scope'];
        $approverText = $approverUserId > 0 ? (string)$approverUserId : null;

        $finalContentFormat = $isFormDocument ? 'rich_text' : ($formData['content_mode'] === 'file' ? 'file' : 'rich_text');

        if ($isFormDocument) {
            $contentText = json_encode([
                'purpose_scope'   => $formData['purpose_scope'],
                'form_responses'  => $formData['form_response_json'] !== '' ? json_decode($formData['form_response_json'], true) : [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $contentText = ($finalContentFormat === 'rich_text') ? $formData['content_text'] : $formData['purpose_scope'];
        }

        $formDefinitionId = null;
        if ($isFormDocument && $formData['form_builder_json'] !== '' && table_exists($conn, 'form_definitions') && $hasFormBuilderJson) {
            $formNameToStore = $formData['form_name'] !== '' ? $formData['form_name'] : ($documentTitle . ' Form');
            $formTypeToStore = $formData['form_type'] !== '' ? $formData['form_type'] : 'Checklist';

            $stmt = exec_prepared($conn, "
                INSERT INTO form_definitions
                (form_name, form_type, linked_document_type_id, status, builder_json, created_by, updated_by, created_at, updated_at)
                VALUES (?, ?, ?, 'active', ?, ?, ?, NOW(), NOW())
            ", [
                $formNameToStore,
                $formTypeToStore,
                $documentTypeId,
                $formData['form_builder_json'],
                $currentUserId,
                $currentUserId
            ]);
            $formDefinitionId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        }

        $stmt = exec_prepared($conn, "
            INSERT INTO documents
            (document_number, document_type_id, title, topic, owner_user_id, created_by, current_status, is_acknowledgement_required, remarks, created_at, updated_at, approver)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
        ", [
            $documentNumberFull,
            $documentTypeId,
            $documentTitle,
            $formData['document_topic'],
            $currentUserId,
            $currentUserId,
            $currentStatus,
            $ackReq,
            $remarks,
            $approverText
        ]);
        $documentId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        if ($hasFormDefinitionLink) {
            $stmt = exec_prepared($conn, "
                INSERT INTO document_versions
                (document_id, previous_version_id, version_sequence, version_label, title_snapshot, topic_snapshot, owner_user_id, created_by, change_summary, effective_date, review_date, status, content_format, content_text, form_definition_id, submitted_by, submitted_at, created_at, updated_at)
                VALUES (?, NULL, 1, '01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $documentId,
                $documentTitle,
                $formData['document_topic'],
                $currentUserId,
                $currentUserId,
                ($action === 'submit_review') ? 'Document created and submitted for review' : 'Initial draft created',
                $formData['effective_date'],
                $formData['review_date'],
                $versionStatus,
                $finalContentFormat,
                $contentText,
                $formDefinitionId,
                $submittedBy,
                $submittedAt
            ]);
        } else {
            $stmt = exec_prepared($conn, "
                INSERT INTO document_versions
                (document_id, previous_version_id, version_sequence, version_label, title_snapshot, topic_snapshot, owner_user_id, created_by, change_summary, effective_date, review_date, status, content_format, content_text, submitted_by, submitted_at, created_at, updated_at)
                VALUES (?, NULL, 1, '01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $documentId,
                $documentTitle,
                $formData['document_topic'],
                $currentUserId,
                $currentUserId,
                ($action === 'submit_review') ? 'Document created and submitted for review' : 'Initial draft created',
                $formData['effective_date'],
                $formData['review_date'],
                $versionStatus,
                $finalContentFormat,
                $contentText,
                $submittedBy,
                $submittedAt
            ]);
        }

        $documentVersionId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $stmt = exec_prepared($conn, "UPDATE documents SET current_version_id = ? WHERE id = ?", [
            $documentVersionId,
            $documentId
        ]);
        mysqli_stmt_close($stmt);

        write_audit_log(
            $conn,
            'document',
            $documentId,
            $action === 'submit_review' ? 'submit' : 'create',
            null,
            [
                'document_id' => $documentId,
                'document_version_id' => $documentVersionId,
                'document_number' => $documentNumberFull,
                'title' => $documentTitle,
                'topic' => $formData['document_topic'],
                'status' => $currentStatus,
                'version_label' => '01',
                'approver_user_id' => $approverUserId,
                'owner_user_id' => $currentUserId,
            ],
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
        redirect_back('draft_id=' . urlencode((string)$documentId));

    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['flash_error'] = $e->getMessage();
        $_SESSION['create_document_old'] = $formData;
        redirect_back($formData['draft_id'] !== '' ? ('draft_id=' . urlencode($formData['draft_id'])) : '');
    }
}

$currentTypePrefix = 'SOP';
$currentTypeName = 'SOP';
foreach ($documentTypes as $row) {
    if ((string)$row['id'] === (string)$formData['document_type_id']) {
        $currentTypePrefix = (string)$row['prefix'];
        $currentTypeName = (string)$row['type_name'];
        break;
    }
}

$formData['document_topic'] = build_auto_topic($currentTypeName, $formData['document_number']);

$docIdPreview = $currentTypePrefix . '-' .
    ($formData['document_number'] !== '' ? $formData['document_number'] : '104') . '-' .
    ($formData['document_topic'] !== '' ? $formData['document_topic'] : $currentTypeName . ' Draft') . '-01';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Create Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .readonly-field{background:#f5f7fa!important;color:#6b7280!important;cursor:not-allowed;}
    .info-form-summary-card,.form-fill-card{background:#f8f9fb;border:1px solid #dde3ec;border-radius:10px;padding:14px;}
    .info-form-summary-table{width:100%;margin:0;font-size:13px;}
    .info-form-summary-table th,.info-form-summary-table td{padding:9px 10px;border:1px solid #e8edf3;vertical-align:middle;}
    .info-form-summary-table th{background:#eef3fb;color:#0D2144;font-weight:700;width:180px;}
    .builder-preview-grid{display:grid;grid-template-columns:1fr;gap:14px;}
    .fill-field-card{border:1px solid #dde3ec;border-radius:10px;padding:14px;background:#fff;}
    .fill-field-label{font-size:14px;font-weight:600;color:#0D2144;margin-bottom:8px;display:block;}
    .fill-field-type{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#eaf2ff;color:#2563eb;margin-left:8px;}

    .swal2-popup.cp-builder-popup{
      width:920px!important;
      max-width:calc(100vw - 24px)!important;
      border-radius:18px!important;
      padding:0!important;
      overflow:hidden;
      box-shadow:0 20px 60px rgba(13,33,68,.18)!important;
    }
    .cp-builder-modal{background:#fff;}
    .cp-builder-header{
      padding:20px 22px 14px;
      border-bottom:1px solid #e8edf3;
      background:linear-gradient(180deg,#f8fbff 0%,#ffffff 100%);
    }
    .cp-builder-title{margin:0;font-size:20px;font-weight:700;color:#0D2144;}
    .cp-builder-subtitle{margin:4px 0 0;font-size:13px;color:#6b7280;}
    .cp-builder-body{padding:18px 22px 10px;}
    .cp-builder-top-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
    .cp-builder-field-group label{display:block;font-size:13px;font-weight:600;color:#1e2a3a;margin-bottom:6px;}
    .cp-builder-input,.cp-builder-textarea{
      width:100%;border:1px solid #d9e2ec;border-radius:10px;background:#fff;
      padding:11px 12px;font-size:14px;color:#1e2a3a;outline:none;
    }
    .cp-builder-textarea{min-height:96px;resize:vertical;}
    .cp-builder-grid{display:grid;grid-template-columns:320px 1fr;gap:16px;align-items:start;}
    .cp-builder-card{border:1px solid #dde3ec;border-radius:14px;background:#fff;padding:14px;}
    .cp-builder-side-title{font-size:15px;font-weight:700;color:#0D2144;margin-bottom:2px;}
    .cp-builder-side-subtitle{font-size:13px;color:#6b7280;margin-bottom:12px;}
    .cp-builder-type-list{display:grid;grid-template-columns:1fr;gap:10px;}
    .cp-builder-type-btn{
      display:flex;align-items:center;gap:12px;border:1px solid #d7dee8;border-radius:12px;background:#fff;
      padding:12px 14px;font-size:14px;font-weight:600;color:#1e2a3a;cursor:pointer;text-align:left;
    }
    .cp-builder-type-btn:hover{background:#eef4ff;border-color:#9db7ea;color:#0D2144;}
    .cp-builder-type-icon{
      width:36px;height:36px;border-radius:10px;background:#e9eef5;display:flex;
      align-items:center;justify-content:center;font-weight:700;color:#0D2144;flex:0 0 36px;
    }
    .cp-builder-preview-list{border:1px solid #dde3ec;border-radius:12px;background:#f8f9fb;padding:10px 12px;max-height:380px;overflow-y:auto;}
    .cp-builder-preview-item{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #e8edf3;font-size:13px;}
    .cp-builder-preview-item:last-child{border-bottom:none;}
    .cp-builder-chip,.builder-chip{
      display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;
      background:#eaf2ff;color:#2563eb;margin-right:6px;margin-bottom:4px;
    }
    .cp-builder-empty{color:#6b7280;font-size:13px;padding:18px 8px;text-align:center;}
    .swal2-popup.cp-small-popup{width:520px!important;max-width:calc(100vw - 24px)!important;border-radius:16px!important;padding:20px!important;}
    .attached-fields-box{border:1px solid #dde3ec;border-radius:8px;background:#f8f9fb;padding:10px 12px;margin-top:12px;}
    .attached-field-row{font-size:13px;color:#1e2a3a;padding:6px 0;border-bottom:1px solid #e8edf3;}
    .attached-field-row:last-child{border-bottom:none;}

    @media (max-width:768px){
      .cp-builder-top-fields,.cp-builder-grid{grid-template-columns:1fr;}
      .info-form-summary-table th,.info-form-summary-table td{display:block;width:100%;}
      .info-form-summary-table th{border-bottom:0;}
      .info-form-summary-table td{border-top:0;margin-bottom:8px;}
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <div class="mb-4">
    <h1 class="page-title mb-2">Create Controlled Document</h1>
    <p class="page-subtitle mb-0">Create a new controlled document with required metadata, ownership, approval workflow and dynamic form fields.</p>
  </div>

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <span class="badge badge-soft-secondary"><?php echo $formData['draft_id'] !== '' ? 'Draft Saved' : 'Draft'; ?></span>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-secondary" href="dashboard-admin.php">Cancel</a>
      <button type="submit" form="docForm" class="btn btn-outline-primary" onclick="document.getElementById('form_action').value='save_draft'; syncFormResponses();">Save Draft</button>
      <button type="submit" form="docForm" class="btn btn-success" onclick="document.getElementById('form_action').value='submit_review'; syncFormResponses();">Submit for Review</button>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?>
    <div class="alert alert-success mb-3"><?php echo e($flashSuccess); ?></div>
  <?php endif; ?>

  <?php if ($flashError !== ''): ?>
    <div class="alert alert-danger mb-3"><?php echo e($flashError); ?></div>
  <?php endif; ?>

  <form method="post" id="docForm" enctype="multipart/form-data">
    <input type="hidden" name="draft_id" id="draft_id" value="<?php echo e($formData['draft_id']); ?>">
    <input type="hidden" name="action" id="form_action" value="save_draft">
    <input type="hidden" name="content_mode" id="content_mode" value="<?php echo e($formData['content_mode']); ?>">
    <input type="hidden" name="form_name" id="form_name_hidden" value="<?php echo e($formData['form_name']); ?>">
    <input type="hidden" name="form_type" id="form_type_hidden" value="<?php echo e($formData['form_type']); ?>">
    <input type="hidden" name="form_desc" id="form_desc_hidden" value="<?php echo e($formData['form_desc']); ?>">
    <input type="hidden" name="form_builder_json" id="form_builder_json_hidden" value="<?php echo e($formData['form_builder_json']); ?>">
    <input type="hidden" name="form_response_json" id="form_response_json_hidden" value="<?php echo e($formData['form_response_json']); ?>">
    <input type="hidden" name="document_topic" id="document_topic_hidden" value="<?php echo e($formData['document_topic']); ?>">

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
                <input class="form-control readonly-field" id="document_topic_preview" type="text" readonly value="<?php echo e($formData['document_topic']); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label">Document Number</label>
                <input class="form-control" id="docNumber" name="document_number" value="<?php echo e($formData['document_number']); ?>" oninput="updateDocIdPreview()">
              </div>

              <div class="col-md-6">
                <label class="form-label">Version</label>
                <input class="form-control readonly-field" readonly value="01">
              </div>

              <div class="col-md-6">
                <label class="form-label">Owner</label>
                <input class="form-control readonly-field" type="text" readonly value="<?php echo e($creatorName); ?>">
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
              </div>

              <div class="col-md-6">
                <label class="form-label">Effective Date</label>
                <input class="form-control" type="date" name="effective_date" id="effective_date" value="<?php echo e($formData['effective_date']); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label">Review Date</label>
                <input class="form-control" type="date" name="review_date" id="review_date" value="<?php echo e($formData['review_date']); ?>">
              </div>

              <div class="col-12">
                <label class="form-label">Document Purpose &amp; Scope <span class="text-danger">*</span></label>
                <textarea class="form-control" name="purpose_scope" rows="3"><?php echo e($formData['purpose_scope']); ?></textarea>
              </div>

              <div class="col-12">
                <label class="form-label">Document ID Preview</label>
                <div class="kv p-3 fw-semibold text-primary" id="docIdPreview"><?php echo e($docIdPreview); ?></div>
                <div class="form-text">Format: [Type]-[Number]-[Topic]-[Version]</div>
              </div>

              <div class="col-12 d-none" id="documentInfoFormTableWrapper">
                <label class="form-label">Attached Form Builder Details</label>
                <div class="info-form-summary-card">
                  <table class="info-form-summary-table">
                    <tr><th>Form Name</th><td id="infoFormName">—</td></tr>
                    <tr><th>Form Type</th><td id="infoFormType">—</td></tr>
                    <tr><th>Total Fields</th><td id="infoFormFieldCount">0</td></tr>
                    <tr><th>Description</th><td id="infoFormDesc">—</td></tr>
                  </table>
                </div>
              </div>

            </div>
          </div>
        </div>

        <div class="card cp-card d-none" id="formTypePanel">
          <div class="card-body">
            <h2 class="card-title mb-1">Form / Checklist</h2>
            <p class="card-subtitle mb-3">Create fields, then fill the same fields before saving draft or submitting for review.</p>

            <div id="formBuildMode">
              <div id="attachedFormSummary" class="d-none mb-3">
                <div class="d-flex align-items-start justify-content-between gap-3 p-3 rounded-3" style="background:#f8f9fb;border:1px solid #dde3ec;">
                  <div class="flex-grow-1">
                    <div class="fw-semibold text-primary mb-1" id="attachedFormName">—</div>
                    <div class="small text-secondary" id="attachedFormMeta">—</div>
                    <div id="attachedFieldsPreview" class="attached-fields-box d-none"></div>
                  </div>
                  <div class="d-flex gap-2 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openChecklistBuilder(true)">Edit Form</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="detachForm()">Remove</button>
                  </div>
                </div>
              </div>

              <div id="noFormAttached">
                <div class="upload-box p-4 text-center mb-3" style="cursor:default;">
                  <div class="mb-2" style="font-size:2rem;">📋</div>
                  <div class="fw-semibold mb-1">No form built yet</div>
                  <p class="small text-secondary mb-3">
                    Use the builder to add Text Input, Text Area, Number, Date, Dropdown, Checkbox, Yes/No and Signature fields.
                  </p>
                  <button type="button" class="btn btn-primary" id="openBuilderBtn">+ Create Form / Checklist</button>
                </div>
              </div>

              <div id="formFillWrapper" class="d-none mt-3">
                <label class="form-label">Fill Created Inputs</label>
                <div class="form-fill-card">
                  <div id="dynamicFormFields" class="builder-preview-grid"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card cp-card" id="contentCard">
          <div class="card-body">
            <h2 class="card-title mb-1">Document Content</h2>
            <p class="card-subtitle mb-3">Add document content using rich text or controlled file upload.</p>

            <ul class="nav nav-pills gap-2 mb-3">
              <li class="nav-item"><a class="nav-link <?php echo $formData['content_mode'] === 'rich_text' ? 'active' : ''; ?>" id="tabRichText" href="#" onclick="setContentMode('rich_text'); return false;">Rich Text Editor</a></li>
              <li class="nav-item"><a class="nav-link <?php echo $formData['content_mode'] === 'file' ? 'active' : ''; ?>" id="tabFileUpload" href="#" onclick="setContentMode('file'); return false;">File Upload</a></li>
            </ul>

            <div id="richTextPanel" class="<?php echo $formData['content_mode'] === 'rich_text' ? '' : 'd-none'; ?>">
              <textarea class="form-control" name="content_text" id="content_text" rows="9"><?php echo e($formData['content_text']); ?></textarea>
            </div>

            <div id="fileUploadPanel" class="<?php echo $formData['content_mode'] === 'file' ? '' : 'd-none'; ?>">
              <input type="file" name="document_file" id="document_file" accept=".pdf,.doc,.docx,.xls,.xlsx" style="display:none;">
              <div class="upload-box p-4 text-center small text-secondary" id="documentUploadBox" style="cursor:pointer;">
                Drag and drop file here or click to browse.<br>
                Supported: PDF, DOCX, XLSX | Maximum size: 25 MB
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
              <li>Created fields filled.</li>
              <li>Approver selected and validated.</li>
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
const BUILDER_STORAGE_KEY = 'cpBuiltFormInline';
const BUILDER_RESPONSE_KEY = 'cpBuiltFormResponses';

function getSelectedTypeOption() {
  return document.getElementById('docTypeSelect').selectedOptions[0];
}

function buildAutoTopicJs() {
  const opt = getSelectedTypeOption();
  const typeName = opt ? (opt.dataset.name || 'SOP') : 'SOP';
  const docNumber = (document.getElementById('docNumber').value || '').trim();
  return docNumber !== '' ? typeName + ' ' + docNumber : typeName + ' Draft';
}

function handleDocTypeChange(selectEl) {
  const opt = selectEl.selectedOptions[0];
  const typeName = (opt.dataset.name || '').toLowerCase();
  const isForm = typeName === 'form';

  document.getElementById('contentCard').classList.toggle('d-none', isForm);
  document.getElementById('formTypePanel').classList.toggle('d-none', !isForm);
  updateDocIdPreview();
}

function setContentMode(mode) {
  document.getElementById('content_mode').value = mode;
  document.getElementById('tabFileUpload').classList.toggle('active', mode === 'file');
  document.getElementById('tabRichText').classList.toggle('active', mode === 'rich_text');
  document.getElementById('fileUploadPanel').classList.toggle('d-none', mode !== 'file');
  document.getElementById('richTextPanel').classList.toggle('d-none', mode !== 'rich_text');
}

function updateDocIdPreview() {
  const opt = getSelectedTypeOption();
  const prefix = opt ? (opt.dataset.prefix || 'SOP') : 'SOP';
  const number = document.getElementById('docNumber').value || '104';
  const topic = buildAutoTopicJs();

  document.getElementById('document_topic_hidden').value = topic;
  document.getElementById('document_topic_preview').value = topic;
  document.getElementById('docIdPreview').textContent = prefix + '-' + number + '-' + topic + '-01';
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text == null ? '' : String(text);
  return div.innerHTML;
}

function fieldTypeLabel(type) {
  const map = {
    text: 'Text Input',
    textarea: 'Text Area',
    number: 'Number',
    date: 'Date',
    dropdown: 'Dropdown',
    checkbox: 'Checkbox',
    yesno: 'Yes / No',
    signature: 'Signature'
  };
  return map[type] || type;
}

function fieldTypeIcon(type) {
  const map = {
    text: 'T',
    textarea: '¶',
    number: '#',
    date: '📅',
    dropdown: '▼',
    checkbox: '☑',
    yesno: '?',
    signature: '✍'
  };
  return map[type] || '•';
}

function getBuilderData() {
  const rawHidden = document.getElementById('form_builder_json_hidden').value || '';
  if (rawHidden) {
    try { return JSON.parse(rawHidden); } catch (e) {}
  }
  const raw = sessionStorage.getItem(BUILDER_STORAGE_KEY);
  if (raw) {
    try { return JSON.parse(raw); } catch (e) {}
  }
  return { formName: '', formType: 'Checklist', formDesc: '', fields: [] };
}

function getResponseData() {
  const raw = document.getElementById('form_response_json_hidden').value || sessionStorage.getItem(BUILDER_RESPONSE_KEY) || '{}';
  try { return JSON.parse(raw); } catch (e) { return {}; }
}

function saveResponseData(data) {
  document.getElementById('form_response_json_hidden').value = JSON.stringify(data);
  sessionStorage.setItem(BUILDER_RESPONSE_KEY, JSON.stringify(data));
}

function saveBuilderData(data) {
  document.getElementById('form_name_hidden').value = data.formName || '';
  document.getElementById('form_type_hidden').value = data.formType || 'Checklist';
  document.getElementById('form_desc_hidden').value = data.formDesc || '';
  document.getElementById('form_builder_json_hidden').value = JSON.stringify(data);
  sessionStorage.setItem(BUILDER_STORAGE_KEY, JSON.stringify(data));
  renderBuilderSummary(data, true);
  renderDynamicFillForm(data);
}

function detachForm() {
  sessionStorage.removeItem(BUILDER_STORAGE_KEY);
  sessionStorage.removeItem(BUILDER_RESPONSE_KEY);
  document.getElementById('form_name_hidden').value = '';
  document.getElementById('form_type_hidden').value = '';
  document.getElementById('form_desc_hidden').value = '';
  document.getElementById('form_builder_json_hidden').value = '';
  document.getElementById('form_response_json_hidden').value = '';
  document.getElementById('attachedFormSummary').classList.add('d-none');
  document.getElementById('noFormAttached').classList.remove('d-none');
  document.getElementById('documentInfoFormTableWrapper').classList.add('d-none');
  document.getElementById('formFillWrapper').classList.add('d-none');
  document.getElementById('dynamicFormFields').innerHTML = '';
}

function renderAttachedFields(fields) {
  const box = document.getElementById('attachedFieldsPreview');
  if (!Array.isArray(fields) || !fields.length) {
    box.innerHTML = '';
    box.classList.add('d-none');
    return;
  }

  box.innerHTML = fields.map(function(field, index) {
    return '<div class="attached-field-row"><strong>' + (index + 1) + '.</strong> ' +
      escapeHtml(field.label || ('Field ' + (index + 1))) +
      '<span class="builder-chip">' + escapeHtml(fieldTypeLabel(field.type)) + '</span></div>';
  }).join('');
  box.classList.remove('d-none');
}

function renderBuilderInfoTable(data) {
  const wrapper = document.getElementById('documentInfoFormTableWrapper');
  const fields = Array.isArray(data.fields) ? data.fields : [];
  document.getElementById('infoFormName').textContent = data.formName || 'Untitled Form';
  document.getElementById('infoFormType').textContent = data.formType || 'Checklist';
  document.getElementById('infoFormFieldCount').textContent = String(fields.length);
  document.getElementById('infoFormDesc').textContent = data.formDesc || '—';
  wrapper.classList.remove('d-none');
}

function renderBuilderSummary(data, showBanner) {
  const fieldCount = Array.isArray(data.fields) ? data.fields.length : 0;
  document.getElementById('attachedFormName').textContent = data.formName || 'Untitled Form';
  document.getElementById('attachedFormMeta').textContent =
    fieldCount + ' field' + (fieldCount !== 1 ? 's' : '') + (data.formDesc ? ' · ' + data.formDesc : '');

  renderAttachedFields(data.fields || []);
  renderBuilderInfoTable(data);

  document.getElementById('attachedFormSummary').classList.remove('d-none');
  document.getElementById('noFormAttached').classList.add('d-none');
}

function buildFieldsHtml(fields) {
  if (!fields.length) {
    return '<div class="cp-builder-empty">No fields added yet.</div>';
  }

  return '<div class="cp-builder-preview-list">' + fields.map(function(field, index) {
    return '<div class="cp-builder-preview-item">' +
      '<div><strong>' + escapeHtml(field.label || ('Field ' + (index + 1))) + '</strong>' +
      '<div><span class="cp-builder-chip">' + escapeHtml(fieldTypeLabel(field.type)) + '</span></div>' +
      '</div>' +
      '<div><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeBuilderField(' + index + ')">Remove</button></div>' +
      '</div>';
  }).join('') + '</div>';
}

async function addBuilderField(type) {
  let html = '';

  if (type === 'dropdown') {
    html = `
      <input id="swal-field-label" class="swal2-input" placeholder="Field label">
      <textarea id="swal-field-options" class="swal2-textarea" placeholder="Enter options, one per line"></textarea>
    `;
  } else {
    html = `<input id="swal-field-label" class="swal2-input" placeholder="Field label">`;
  }

  const result = await Swal.fire({
    title: 'Add ' + fieldTypeLabel(type),
    html: html,
    showCancelButton: true,
    confirmButtonText: 'Add Field',
    customClass: { popup: 'cp-small-popup' },
    preConfirm: () => {
      const label = (document.getElementById('swal-field-label') || {}).value || '';
      const optionsRaw = (document.getElementById('swal-field-options') || {}).value || '';

      if (!label.trim()) {
        Swal.showValidationMessage('Please enter field label');
        return false;
      }

      const field = { type: type, label: label.trim(), required: false };

      if (type === 'dropdown') {
        const options = optionsRaw.split('\n').map(v => v.trim()).filter(Boolean);
        if (!options.length) {
          Swal.showValidationMessage('Please enter at least one option');
          return false;
        }
        field.options = options;
      }

      return field;
    }
  });

  if (result.isConfirmed && result.value) {
    const data = getBuilderData();
    data.formName = data.formName || (document.getElementById('document_topic_hidden').value + ' Form');
    if (!Array.isArray(data.fields)) {
      data.fields = [];
    }
    data.fields.push(result.value);
    saveBuilderData(data);
    openChecklistBuilder(true);
  }
}

function removeBuilderField(index) {
  const data = getBuilderData();
  if (!Array.isArray(data.fields)) {
    data.fields = [];
  }
  data.fields.splice(index, 1);
  saveBuilderData(data);
  openChecklistBuilder(true);
}

function renderDynamicFillForm(data) {
  const wrapper = document.getElementById('formFillWrapper');
  const container = document.getElementById('dynamicFormFields');
  const responses = getResponseData();
  const fields = Array.isArray(data.fields) ? data.fields : [];

  if (!fields.length) {
    wrapper.classList.add('d-none');
    container.innerHTML = '';
    return;
  }

  container.innerHTML = fields.map((field, index) => {
    const key = 'field_' + index;
    const value = responses[key] ?? '';
    let control = '';

    if (field.type === 'textarea') {
      control = '<textarea class="form-control dynamic-builder-input" data-key="' + key + '" rows="3">' + escapeHtml(value) + '</textarea>';
    } else if (field.type === 'number') {
      control = '<input type="number" class="form-control dynamic-builder-input" data-key="' + key + '" value="' + escapeHtml(value) + '">';
    } else if (field.type === 'date') {
      control = '<input type="date" class="form-control dynamic-builder-input" data-key="' + key + '" value="' + escapeHtml(value) + '">';
    } else if (field.type === 'dropdown') {
      const opts = (field.options || []).map(opt =>
        '<option value="' + escapeHtml(opt) + '" ' + (String(value) === String(opt) ? 'selected' : '') + '>' + escapeHtml(opt) + '</option>'
      ).join('');
      control = '<select class="form-select dynamic-builder-input" data-key="' + key + '"><option value="">Select option</option>' + opts + '</select>';
    } else if (field.type === 'checkbox') {
      control = '<div class="form-check"><input class="form-check-input dynamic-builder-input" type="checkbox" data-key="' + key + '" ' + (value ? 'checked' : '') + '><label class="form-check-label">Checked</label></div>';
    } else if (field.type === 'yesno') {
      control = '<div class="d-flex gap-3">' +
        '<div class="form-check"><input class="form-check-input dynamic-builder-radio" type="radio" name="' + key + '" data-key="' + key + '" value="Yes" ' + (value === 'Yes' ? 'checked' : '') + '><label class="form-check-label">Yes</label></div>' +
        '<div class="form-check"><input class="form-check-input dynamic-builder-radio" type="radio" name="' + key + '" data-key="' + key + '" value="No" ' + (value === 'No' ? 'checked' : '') + '><label class="form-check-label">No</label></div>' +
        '</div>';
    } else if (field.type === 'signature') {
      control = '<input type="text" class="form-control dynamic-builder-input" data-key="' + key + '" placeholder="Enter signature name" value="' + escapeHtml(value) + '">';
    } else {
      control = '<input type="text" class="form-control dynamic-builder-input" data-key="' + key + '" value="' + escapeHtml(value) + '">';
    }

    return '<div class="fill-field-card">' +
      '<label class="fill-field-label">' + escapeHtml(field.label) +
      '<span class="fill-field-type">' + escapeHtml(fieldTypeLabel(field.type)) + '</span></label>' +
      control +
      '</div>';
  }).join('');

  wrapper.classList.remove('d-none');
  bindDynamicInputs();
}

function bindDynamicInputs() {
  document.querySelectorAll('.dynamic-builder-input').forEach(el => {
    el.addEventListener('input', syncFormResponses);
    el.addEventListener('change', syncFormResponses);
  });
  document.querySelectorAll('.dynamic-builder-radio').forEach(el => {
    el.addEventListener('change', syncFormResponses);
  });
}

function syncFormResponses() {
  const data = {};

  document.querySelectorAll('.dynamic-builder-input').forEach(el => {
    const key = el.dataset.key;
    if (el.type === 'checkbox') {
      data[key] = el.checked ? 1 : 0;
    } else {
      data[key] = el.value;
    }
  });

  document.querySelectorAll('.dynamic-builder-radio:checked').forEach(el => {
    data[el.dataset.key] = el.value;
  });

  saveResponseData(data);
}

async function openChecklistBuilder(isEdit) {
  const data = getBuilderData();
  if (!data.formName) {
    data.formName = document.getElementById('document_topic_hidden').value + ' Form';
  }
  if (!Array.isArray(data.fields)) {
    data.fields = [];
  }

  const imageStyleList = [
    {type:'text',label:'Text Input'},
    {type:'textarea',label:'Text Area'},
    {type:'number',label:'Number'},
    {type:'date',label:'Date'},
    {type:'dropdown',label:'Dropdown'},
    {type:'checkbox',label:'Checkbox'},
    {type:'yesno',label:'Yes / No'},
    {type:'signature',label:'Signature'}
  ];

  const buttons = imageStyleList.map(item =>
    '<button type="button" class="cp-builder-type-btn" onclick="addBuilderField(\'' + item.type + '\')">' +
      '<span class="cp-builder-type-icon">' + fieldTypeIcon(item.type) + '</span>' +
      '<span>' + item.label + '</span>' +
    '</button>'
  ).join('');

  const result = await Swal.fire({
    customClass: { popup: 'cp-builder-popup' },
    showCancelButton: true,
    confirmButtonText: 'Attach Builder',
    cancelButtonText: 'Close',
    focusConfirm: false,
    html:
      '<div class="cp-builder-modal">' +
        '<div class="cp-builder-header">' +
          '<h3 class="cp-builder-title">Form / Checklist Builder</h3>' +
          '<p class="cp-builder-subtitle">Add fields like your design and then user can fill them before draft save or submit review.</p>' +
        '</div>' +
        '<div class="cp-builder-body">' +
          '<div class="cp-builder-top-fields">' +
            '<div class="cp-builder-field-group">' +
              '<label>Form / Checklist Name</label>' +
              '<input id="builder-form-name" class="cp-builder-input" type="text" placeholder="Enter form name" value="' + escapeHtml(data.formName) + '">' +
            '</div>' +
            '<div class="cp-builder-field-group">' +
              '<label>Description</label>' +
              '<textarea id="builder-form-desc" class="cp-builder-textarea" placeholder="Enter description">' + escapeHtml(data.formDesc || '') + '</textarea>' +
            '</div>' +
          '</div>' +
          '<div class="cp-builder-grid">' +
            '<div class="cp-builder-card">' +
              '<div class="cp-builder-side-title">Add Field</div>' +
              '<div class="cp-builder-side-subtitle">Click to add to your form</div>' +
              '<div class="cp-builder-type-list">' + buttons + '</div>' +
            '</div>' +
            '<div class="cp-builder-card">' +
              '<div class="cp-builder-side-title">Added Fields</div>' +
              buildFieldsHtml(data.fields) +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>',
    preConfirm: () => {
      const formName = (document.getElementById('builder-form-name') || {}).value || '';
      const formDesc = (document.getElementById('builder-form-desc') || {}).value || '';
      const currentData = getBuilderData();

      if (!formName.trim()) {
        Swal.showValidationMessage('Please enter form name');
        return false;
      }

      if (!Array.isArray(currentData.fields) || !currentData.fields.length) {
        Swal.showValidationMessage('Please add at least one field');
        return false;
      }

      currentData.formName = formName.trim();
      currentData.formType = 'Checklist';
      currentData.formDesc = formDesc.trim();
      return currentData;
    }
  });

  if (result.isConfirmed && result.value) {
    saveBuilderData(result.value);
  }
}

document.getElementById('openBuilderBtn').addEventListener('click', function(e) {
  e.preventDefault();
  openChecklistBuilder(false);
});

document.getElementById('docNumber').addEventListener('input', updateDocIdPreview);

(function restoreBuiltForm() {
  let data = null;
  const rawHidden = document.getElementById('form_builder_json_hidden').value || '';
  const rawSession = sessionStorage.getItem(BUILDER_STORAGE_KEY);

  if (rawHidden) {
    try { data = JSON.parse(rawHidden); } catch (e) {}
  } else if (rawSession) {
    try { data = JSON.parse(rawSession); } catch (e) {}
  }

  if (data && Array.isArray(data.fields) && data.fields.length) {
    renderBuilderSummary(data, false);
    renderDynamicFillForm(data);
  }

  handleDocTypeChange(document.getElementById('docTypeSelect'));
  setContentMode(document.getElementById('content_mode').value || 'file');
})();
</script>
</body>
</html>