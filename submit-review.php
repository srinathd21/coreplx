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

if (!function_exists('has_column')) {
    function has_column(mysqli $conn, string $table, string $column): bool
    {
        if (!table_exists($conn, $table)) {
            return false;
        }

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

if (!function_exists('insert_dynamic')) {
    function insert_dynamic(mysqli $conn, string $table, array $data): int
    {
        if (empty($data)) {
            throw new Exception('No insert data supplied.');
        }

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = "
            INSERT INTO `$table`
            (`" . implode('`, `', $columns) . "`)
            VALUES (" . implode(', ', $placeholders) . ")
        ";

        $stmt = exec_prepared($conn, $sql, array_values($data));
        $insertId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        return $insertId;
    }
}

if (!function_exists('write_audit_log')) {
    function write_audit_log(
        mysqli $conn,
        string $entityType,
        $entityId,
        string $action,
        $oldValue,
        $newValue,
        $performedBy,
        string $remarks = ''
    ): void {
        if (!table_exists($conn, 'audit_logs')) {
            return;
        }

        $requiredColumns = [
            'event_id',
            'entity_type',
            'entity_id',
            'action',
            'old_value',
            'new_value',
            'performed_by',
            'performed_at',
            'remarks',
            'ip_address',
            'user_agent'
        ];

        foreach ($requiredColumns as $col) {
            if (!has_column($conn, 'audit_logs', $col)) {
                return;
            }
        }

        $eventId = generate_uuid_v4();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $oldJson = $oldValue !== null
            ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $newJson = $newValue !== null
            ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

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

if (!function_exists('format_display_date')) {
    function format_display_date($dateValue): string
    {
        $dateValue = trim((string)$dateValue);

        if ($dateValue === '' || $dateValue === '0000-00-00' || $dateValue === '0000-00-00 00:00:00') {
            return '—';
        }

        $ts = strtotime($dateValue);

        return $ts ? date('d M Y', $ts) : $dateValue;
    }
}

if (!function_exists('parse_json_array')) {
    function parse_json_array($json): array
    {
        $json = trim((string)$json);

        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('normalize_builder_key')) {
    function normalize_builder_key(string $label, int $index = 0): string
    {
        $key = strtolower(trim($label));
        $key = preg_replace('/[^a-z0-9]+/i', '_', $key);
        $key = trim((string)$key, '_');

        if ($key === '') {
            $key = 'field_' . ($index + 1);
        }

        return $key;
    }
}

if (!function_exists('is_checked_value')) {
    function is_checked_value($value): bool
    {
        return $value === 1
            || $value === '1'
            || $value === true
            || $value === 'true'
            || $value === 'Yes'
            || $value === 'yes'
            || $value === 'checked'
            || $value === 'on';
    }
}

if (!function_exists('is_checklist_document_type')) {
    function is_checklist_document_type(string $typeName): bool
    {
        return strtolower(trim($typeName)) === 'checklist';
    }
}

if (!function_exists('is_form_document_type')) {
    function is_form_document_type(string $typeName): bool
    {
        $typeName = strtolower(trim($typeName));

        return in_array($typeName, ['form', 'checklist'], true);
    }
}

if (!function_exists('safe_document_id_part')) {
    function safe_document_id_part(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return $value;
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);

$currentUserName = isset($_SESSION['full_name']) && trim((string)$_SESSION['full_name']) !== ''
    ? trim((string)$_SESSION['full_name'])
    : 'QA Admin';

if ($currentUserId <= 0) {
    header('Location: login-admin.php');
    exit;
}

$payload = $_SESSION['submit_review_payload'] ?? null;

if (!$payload || !is_array($payload)) {
    $_SESSION['flash_error'] = 'No submit review data found.';
    header('Location: create-document.php');
    exit;
}

$successMessage = '';
$errorMessage = '';

$hasDocumentsApprover        = has_column($conn, 'documents', 'approver');
$hasDocumentsApproverUserId = has_column($conn, 'documents', 'approver_user_id');

$hasFormDefinitionLink = has_column($conn, 'document_versions', 'form_definition_id');
$hasPrimaryFileName    = has_column($conn, 'document_versions', 'primary_file_name');
$hasPrimaryFilePath    = has_column($conn, 'document_versions', 'primary_file_path');
$hasPrimaryFileMime    = has_column($conn, 'document_versions', 'primary_file_mime');
$hasPrimaryFileSize    = has_column($conn, 'document_versions', 'primary_file_size');
$hasChecksumSha256     = has_column($conn, 'document_versions', 'checksum_sha256');
$hasSubmittedBy        = has_column($conn, 'document_versions', 'submitted_by');
$hasSubmittedAt        = has_column($conn, 'document_versions', 'submitted_at');

$hasFormBuilderJson = table_exists($conn, 'form_definitions') && has_column($conn, 'form_definitions', 'builder_json');

$documentTypeId = (int)($payload['document_type_id'] ?? 0);
$documentType   = trim((string)($payload['document_type_name'] ?? ''));
$documentPrefix = trim((string)($payload['document_prefix'] ?? ''));

$documentNumberOnly = trim((string)($payload['document_number'] ?? ''));
$documentTopic      = trim((string)($payload['document_topic'] ?? ''));

$documentIdFull = trim((string)($payload['document_number_full'] ?? ''));

if ($documentIdFull === '') {
    $documentIdFull = safe_document_id_part($documentPrefix) . '-' .
        safe_document_id_part($documentNumberOnly) . '-' .
        safe_document_id_part($documentTopic) . '-01';
}

$owner         = trim((string)($payload['owner_name'] ?? ''));
$approver      = trim((string)($payload['approver_name'] ?? ''));
$effectiveDate = trim((string)($payload['effective_date'] ?? ''));
$reviewDate    = trim((string)($payload['review_date'] ?? ''));

$purposeScope = trim((string)($payload['purpose_scope'] ?? ''));

$contentMode    = trim((string)($payload['content_mode'] ?? 'file'));
$contentTextRaw = trim((string)($payload['content_text'] ?? ''));

$formName         = trim((string)($payload['form_name'] ?? ''));
$formType         = trim((string)($payload['form_type'] ?? ''));
$formDesc         = trim((string)($payload['form_desc'] ?? ''));
$formBuilderJson  = trim((string)($payload['form_builder_json'] ?? ''));
$formResponseJson = trim((string)($payload['form_response_json'] ?? ''));

$isFormDocument = !empty($payload['is_form_document']) || is_form_document_type($documentType);

$primaryFileName = trim((string)($payload['existing_file_name'] ?? ''));
$primaryFilePath = trim((string)($payload['existing_file_path'] ?? ''));
$primaryFileMime = trim((string)($payload['existing_file_mime'] ?? ''));
$primaryFileSize = (int)($payload['existing_file_size'] ?? 0);
$uploadedChecksum = trim((string)($payload['uploaded_checksum'] ?? ''));

$formBuilderData = parse_json_array($formBuilderJson);
$formFields = is_array($formBuilderData['fields'] ?? null) ? $formBuilderData['fields'] : [];
$formResponses = parse_json_array($formResponseJson);

$resolvedBuilderType = $formType !== '' ? $formType : $documentType;
$isChecklistDocument = is_checklist_document_type($resolvedBuilderType) || is_checklist_document_type($documentType);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $confirmRead = isset($_POST['confirm_review_read']) ? 1 : 0;

    if ($action === 'confirm_submit') {
        if (!$confirmRead) {
            $errorMessage = 'Please confirm that you reviewed all details before submitting.';
        } elseif ($documentTypeId <= 0) {
            $errorMessage = 'Invalid document type.';
        } elseif ($documentTopic === '') {
            $errorMessage = 'Document topic is missing.';
        } elseif ($documentIdFull === '') {
            $errorMessage = 'Document ID is missing.';
        } else {
            mysqli_begin_transaction($conn);

            try {
                $approverUserId = (int)($payload['approver_user_id'] ?? 0);
                $ownerUserId = (int)($payload['owner_user_id'] ?? $currentUserId);
                $ackReq = (int)($payload['ack_required'] ?? 0);

                $currentStatus = 'pending_approval';
                $versionStatus = 'pending_approval';
                $submittedBy = $currentUserId;
                $submittedAt = date('Y-m-d H:i:s');

                $finalContentFormat = $isFormDocument
                    ? 'rich_text'
                    : ($contentMode === 'file' ? 'file' : 'rich_text');

                if ($isFormDocument) {
                    $contentText = json_encode([
                        'purpose_scope'  => $purposeScope,
                        'form_responses' => $formResponses,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $contentText = ($finalContentFormat === 'rich_text')
                        ? $contentTextRaw
                        : $purposeScope;
                }

                $formDefinitionId = null;

                if ($isFormDocument && $formBuilderJson !== '' && table_exists($conn, 'form_definitions') && $hasFormBuilderJson) {
                    $formNameToStore = $formName !== '' ? $formName : ($documentTopic . ' Builder');
                    $formTypeToStore = $resolvedBuilderType !== '' ? $resolvedBuilderType : $documentType;

                    $formInsert = [
                        'form_name' => $formNameToStore,
                        'form_type' => $formTypeToStore,
                        'linked_document_type_id' => $documentTypeId,
                        'status' => 'active',
                        'builder_json' => $formBuilderJson,
                        'created_by' => $currentUserId,
                        'updated_by' => $currentUserId,
                    ];

                    if (has_column($conn, 'form_definitions', 'description')) {
                        $formInsert['description'] = $formDesc;
                    }

                    if (has_column($conn, 'form_definitions', 'created_at')) {
                        $formInsert['created_at'] = date('Y-m-d H:i:s');
                    }

                    if (has_column($conn, 'form_definitions', 'updated_at')) {
                        $formInsert['updated_at'] = date('Y-m-d H:i:s');
                    }

                    $formDefinitionId = insert_dynamic($conn, 'form_definitions', $formInsert);
                }

                $documentInsert = [
                    'document_number' => $documentIdFull,
                    'document_type_id' => $documentTypeId,
                    'title' => $documentTopic,
                    'topic' => $documentTopic,
                    'owner_user_id' => $ownerUserId,
                    'created_by' => $currentUserId,
                    'current_status' => $currentStatus,
                    'is_acknowledgement_required' => $ackReq,
                    'remarks' => $purposeScope,
                ];

                if ($hasDocumentsApproverUserId) {
                    $documentInsert['approver_user_id'] = $approverUserId > 0 ? $approverUserId : null;
                } elseif ($hasDocumentsApprover) {
                    $documentInsert['approver'] = $approverUserId > 0 ? (string)$approverUserId : null;
                }

                if (has_column($conn, 'documents', 'created_at')) {
                    $documentInsert['created_at'] = date('Y-m-d H:i:s');
                }

                if (has_column($conn, 'documents', 'updated_at')) {
                    $documentInsert['updated_at'] = date('Y-m-d H:i:s');
                }

                $newDocumentId = insert_dynamic($conn, 'documents', $documentInsert);

                $versionInsert = [
                    'document_id' => $newDocumentId,
                    'previous_version_id' => null,
                    'version_sequence' => 1,
                    'version_label' => '01',
                    'title_snapshot' => $documentTopic,
                    'topic_snapshot' => $documentTopic,
                    'owner_user_id' => $ownerUserId,
                    'created_by' => $currentUserId,
                    'change_summary' => 'Document created and submitted for review',
                    'effective_date' => $effectiveDate,
                    'review_date' => $reviewDate,
                    'status' => $versionStatus,
                    'content_format' => $finalContentFormat,
                    'content_text' => $contentText,
                ];

                if ($hasFormDefinitionLink) {
                    $versionInsert['form_definition_id'] = $formDefinitionId;
                }

                if ($hasPrimaryFileName) {
                    $versionInsert['primary_file_name'] = $primaryFileName !== '' ? $primaryFileName : null;
                }

                if ($hasPrimaryFilePath) {
                    $versionInsert['primary_file_path'] = $primaryFilePath !== '' ? $primaryFilePath : null;
                }

                if ($hasPrimaryFileMime) {
                    $versionInsert['primary_file_mime'] = $primaryFileMime !== '' ? $primaryFileMime : null;
                }

                if ($hasPrimaryFileSize) {
                    $versionInsert['primary_file_size'] = $primaryFileSize > 0 ? $primaryFileSize : null;
                }

                if ($hasChecksumSha256) {
                    $versionInsert['checksum_sha256'] = $uploadedChecksum !== '' ? $uploadedChecksum : null;
                }

                if ($hasSubmittedBy) {
                    $versionInsert['submitted_by'] = $submittedBy;
                }

                if ($hasSubmittedAt) {
                    $versionInsert['submitted_at'] = $submittedAt;
                }

                if (has_column($conn, 'document_versions', 'created_at')) {
                    $versionInsert['created_at'] = date('Y-m-d H:i:s');
                }

                if (has_column($conn, 'document_versions', 'updated_at')) {
                    $versionInsert['updated_at'] = date('Y-m-d H:i:s');
                }

                $documentVersionId = insert_dynamic($conn, 'document_versions', $versionInsert);

                if (has_column($conn, 'documents', 'current_version_id')) {
                    $stmt = exec_prepared($conn, "UPDATE documents SET current_version_id = ? WHERE id = ?", [
                        $documentVersionId,
                        $newDocumentId
                    ]);
                    mysqli_stmt_close($stmt);
                }

                write_audit_log(
                    $conn,
                    'document',
                    $newDocumentId,
                    'submit',
                    null,
                    [
                        'document_id' => $newDocumentId,
                        'document_version_id' => $documentVersionId,
                        'document_number' => $documentIdFull,
                        'title' => $documentTopic,
                        'topic' => $documentTopic,
                        'status' => $currentStatus,
                        'version_label' => '01',
                        'approver_user_id' => $approverUserId,
                        'owner_user_id' => $ownerUserId,
                    ],
                    $currentUserId,
                    'Document created and submitted for review.'
                );

                mysqli_commit($conn);

                unset(
                    $_SESSION['submit_review_payload'],
                    $_SESSION['submit_review_data'],
                    $_SESSION['create_document_old']
                );

                $_SESSION['flash_success'] = 'Document submitted for review successfully.';
                header('Location: repository.php');
                exit;
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $errorMessage = $e->getMessage();
            }
        }
    }
}

$fileSizeDisplay = $primaryFileSize > 0
    ? round($primaryFileSize / 1024, 2) . ' KB'
    : '—';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Submit for Review</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">

  <style>
    .cp-card {
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 18px;
      box-shadow: 0 6px 24px rgba(0,0,0,.06);
      background: #fff;
    }

    .page-title {
      font-size: 1.75rem;
      font-weight: 700;
    }

    .page-subtitle,
    .card-subtitle {
      color: #6c757d;
    }

    .note-list {
      padding-left: 1rem;
    }

    .summary-box {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 12px;
      padding: 14px;
    }

    .summary-box h6 {
      font-size: 13px;
      font-weight: 700;
      color: #0D2144;
      margin-bottom: 10px;
      text-transform: uppercase;
      letter-spacing: .3px;
    }

    .summary-pre {
      white-space: pre-wrap;
      word-break: break-word;
      margin: 0;
      font-size: 14px;
      color: #1f2937;
      font-family: inherit;
    }

    .review-check-box {
      background: #f8fbff;
      border: 1px solid #d8e4ff;
      border-radius: 12px;
      padding: 14px 16px;
    }

    .checklist-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .checklist-item {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 14px;
      border: 1px solid #dde3ec;
      border-radius: 12px;
      background: #fff;
    }

    .checklist-item-label {
      font-weight: 600;
      color: #1f2937;
      margin: 0;
    }

    .status-badge {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }

    .status-checked {
      background: #dcfce7;
      color: #166534;
    }

    .status-unchecked {
      background: #fee2e2;
      color: #991b1b;
    }

    .readonly-control {
      background: #f8f9fb !important;
      color: #111827 !important;
      font-weight: 500;
    }
  </style>
</head>

<body>

<?php
$navbarPath = __DIR__ . '/includes/navbar.php';
if (file_exists($navbarPath)) {
    include $navbarPath;
} else {
?>
<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>

    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item">
          <a class="nav-link active" href="dashboard-admin.php">Dashboard</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item" href="repository.php">Repository</a></li>
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link" href="portal-select.php">Switch to User</a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($currentUserName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small">Profile</span>
      </div>
    </div>
  </div>
</nav>
<?php } ?>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <div class="mb-4">
    <h1 class="page-title mb-2">Submit for Review</h1>
    <p class="page-subtitle mb-0">
      Review all document details, created fields or checklist items, attached content, and confirm before submitting for approval.
    </p>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?php echo e($successMessage); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?php echo e($errorMessage); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-8">

      <div class="card cp-card">
        <div class="card-body">
          <h2 class="card-title mb-1">Submission Summary</h2>
          <p class="card-subtitle mb-3">Check all details carefully before status changes to Pending Approval.</p>

          <form method="post">

            <div class="table-responsive mb-3">
              <table class="table align-middle">
                <tbody>
                  <tr>
                    <th class="w-25">Document ID</th>
                    <td>
                      <input type="text" class="form-control readonly-control" value="<?php echo e($documentIdFull); ?>" readonly>
                    </td>
                  </tr>

                  <tr>
                    <th>Document Topic</th>
                    <td>
                      <input type="text" class="form-control readonly-control" value="<?php echo e($documentTopic); ?>" readonly>
                    </td>
                  </tr>

                  <tr>
                    <th>Type</th>
                    <td>
                      <input type="text" class="form-control readonly-control" value="<?php echo e($documentType); ?>" readonly>
                    </td>
                  </tr>

                  <tr>
                    <th>Owner</th>
                    <td>
                      <input type="text" class="form-control readonly-control" value="<?php echo e($owner); ?>" readonly>
                    </td>
                  </tr>

                  <tr>
                    <th>Approver</th>
                    <td>
                      <input type="text" class="form-control readonly-control" value="<?php echo e($approver); ?>" readonly>
                    </td>
                  </tr>

                  <tr>
                    <th>Effective Date</th>
                    <td>
                      <input type="text" class="form-control readonly-control" value="<?php echo e(format_display_date($effectiveDate)); ?>" readonly>
                    </td>
                  </tr>

                  <tr>
                    <th>Review Date</th>
                    <td>
                      <input type="text" class="form-control readonly-control" value="<?php echo e(format_display_date($reviewDate)); ?>" readonly>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="summary-box mb-3">
              <h6>Purpose & Scope</h6>
              <pre class="summary-pre"><?php echo e($purposeScope !== '' ? $purposeScope : '—'); ?></pre>
            </div>

            <?php if ($isFormDocument): ?>

              <div class="summary-box mb-3">
                <h6><?php echo $isChecklistDocument ? 'Checklist Details' : 'Form Details'; ?></h6>

                <div class="table-responsive">
                  <table class="table table-sm mb-0">
                    <tbody>
                      <tr>
                        <th style="width:180px;">
                          <?php echo $isChecklistDocument ? 'Checklist Name' : 'Form Name'; ?>
                        </th>
                        <td><?php echo e($formName !== '' ? $formName : '—'); ?></td>
                      </tr>

                      <tr>
                        <th>Type</th>
                        <td><?php echo e($resolvedBuilderType !== '' ? $resolvedBuilderType : '—'); ?></td>
                      </tr>

                      <tr>
                        <th>Description</th>
                        <td><?php echo e($formDesc !== '' ? $formDesc : '—'); ?></td>
                      </tr>

                      <tr>
                        <th>Total Items</th>
                        <td><?php echo e((string)count($formFields)); ?></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <?php if ($isChecklistDocument): ?>

                <div class="summary-box mb-3">
                  <h6>Checklist Items</h6>

                  <?php if (!empty($formFields)): ?>
                    <div class="checklist-list">
                      <?php
                      $usedKeys = [];

                      foreach ($formFields as $index => $field):
                          $label = trim((string)($field['label'] ?? ('Checklist Item ' . ($index + 1))));
                          $type = trim((string)($field['type'] ?? 'checklist_item'));

                          $key = normalize_builder_key($label, $index);
                          $baseKey = $key;
                          $counter = 2;

                          while (isset($usedKeys[$key])) {
                              $key = $baseKey . '_' . $counter;
                              $counter++;
                          }

                          $usedKeys[$key] = true;

                          $value = $formResponses[$key] ?? '';
                          $isChecked = is_checked_value($value);
                      ?>
                        <div class="checklist-item">
                          <div>
                            <div class="checklist-item-label"><?php echo e($label); ?></div>
                            <div class="small text-secondary mt-1">
                              <?php echo e(ucwords(str_replace(['_', '-'], ' ', $type))); ?>
                            </div>
                          </div>

                          <div>
                            <span class="status-badge <?php echo $isChecked ? 'status-checked' : 'status-unchecked'; ?>">
                              <?php echo $isChecked ? 'Checked' : 'Not Checked'; ?>
                            </span>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="text-secondary">No checklist items found.</div>
                  <?php endif; ?>
                </div>

              <?php else: ?>

                <div class="summary-box mb-3">
                  <h6>Created Fields & Entered Values</h6>

                  <?php if (!empty($formFields)): ?>
                    <div class="table-responsive">
                      <table class="table table-bordered table-sm mb-0">
                        <thead>
                          <tr>
                            <th style="width:60px;">#</th>
                            <th>Field Label</th>
                            <th style="width:160px;">Field Type</th>
                            <th>Entered Value</th>
                          </tr>
                        </thead>

                        <tbody>
                          <?php
                          $usedKeys = [];

                          foreach ($formFields as $index => $field):
                              $label = trim((string)($field['label'] ?? ('Field ' . ($index + 1))));
                              $type = trim((string)($field['type'] ?? 'text'));

                              $key = normalize_builder_key($label, $index);
                              $baseKey = $key;
                              $counter = 2;

                              while (isset($usedKeys[$key])) {
                                  $key = $baseKey . '_' . $counter;
                                  $counter++;
                              }

                              $usedKeys[$key] = true;

                              $value = $formResponses[$key] ?? '';

                              if (is_array($value)) {
                                  $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                              }

                              if ($type === 'checkbox') {
                                  $value = is_checked_value($value) ? 'Checked' : 'Not Checked';
                              }

                              if ($value === '' || $value === null) {
                                  $value = '—';
                              }
                          ?>
                            <tr>
                              <td><?php echo e((string)($index + 1)); ?></td>
                              <td><?php echo e($label); ?></td>
                              <td><?php echo e(ucwords(str_replace(['_', '-'], ' ', $type))); ?></td>
                              <td><?php echo e((string)$value); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php else: ?>
                    <div class="text-secondary">No created fields found.</div>
                  <?php endif; ?>
                </div>

              <?php endif; ?>

            <?php else: ?>

              <?php if ($contentMode === 'rich_text'): ?>
                <div class="summary-box mb-3">
                  <h6>Document Text Content</h6>
                  <pre class="summary-pre"><?php echo e($contentTextRaw !== '' ? $contentTextRaw : '—'); ?></pre>
                </div>
              <?php endif; ?>

              <?php if ($contentMode === 'file' || $primaryFileName !== '' || $primaryFilePath !== ''): ?>
                <div class="summary-box mb-3">
                  <h6>Attached File</h6>

                  <div class="table-responsive">
                    <table class="table table-sm mb-0">
                      <tbody>
                        <tr>
                          <th style="width:180px;">File Name</th>
                          <td><?php echo e($primaryFileName !== '' ? $primaryFileName : '—'); ?></td>
                        </tr>

                        <tr>
                          <th>File Path</th>
                          <td><?php echo e($primaryFilePath !== '' ? $primaryFilePath : '—'); ?></td>
                        </tr>

                        <tr>
                          <th>MIME Type</th>
                          <td><?php echo e($primaryFileMime !== '' ? $primaryFileMime : '—'); ?></td>
                        </tr>

                        <tr>
                          <th>File Size</th>
                          <td><?php echo e($fileSizeDisplay); ?></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endif; ?>

            <?php endif; ?>

            <div class="review-check-box mb-3">
              <div class="form-check mb-0">
                <input
                  class="form-check-input"
                  type="checkbox"
                  name="confirm_review_read"
                  id="confirm_review_read"
                  value="1"
                  <?php echo isset($_POST['confirm_review_read']) ? 'checked' : ''; ?>
                >

                <label class="form-check-label fw-semibold" for="confirm_review_read">
                  I reviewed all document details, created fields or checklist items, entered values, and attached content.
                </label>
              </div>
            </div>

            <input type="hidden" name="action" value="confirm_submit">

            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary" onclick="history.back();">Back</button>
              <button type="submit" class="btn btn-success">Confirm Submit</button>
            </div>

          </form>
        </div>
      </div>

    </div>

    <div class="col-lg-4">
      <div class="card cp-card">
        <div class="card-body">
          <h2 class="card-title mb-1">System Actions After Submit</h2>

          <ul class="small text-secondary note-list mb-0">
            <li>Status changes to Pending Approval.</li>
            <li>Submission event is written to audit trail.</li>
            <li>Document version is saved as review submission.</li>
            <li>Reviewed content becomes part of workflow record.</li>
            <li>Approver can review the submitted record in repository/workflow screens.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>