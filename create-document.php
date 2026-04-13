<?php
declare(strict_types=1);
session_start();

/*
|--------------------------------------------------------------------------
| Database include
|--------------------------------------------------------------------------
| Adjust this include path only if your project uses a different file.
| This page supports PDO directly.
*/
require_once __DIR__ . '/includes/config.php';

/*
|--------------------------------------------------------------------------
| Basic auth/session assumptions
|--------------------------------------------------------------------------
*/
$currentUserId   = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : 'QA Admin';

if ($currentUserId <= 0) {
    header('Location: admin-login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PDO resolver
|--------------------------------------------------------------------------
*/
$pdo = null;

if (isset($conn) && $conn instanceof PDO) {
    $pdo = $conn;
} elseif (isset($pdo) && $pdo instanceof PDO) {
    $pdo = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    $pdo = $db;
}

if (!$pdo instanceof PDO) {
    die('Database connection not found. Please check includes/config.php');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function generate_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function get_client_ip(): string
{
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $parts = explode(',', (string) $_SERVER[$key]);
            return trim($parts[0]);
        }
    }
    return '';
}

function normalize_version_label(string $format = 'numeric_2digit'): string
{
    return ($format === 'numeric_decimal') ? '1.0' : '01';
}

function normalize_content_format(string $contentText, array $file): string
{
    $hasText = trim($contentText) !== '';
    $hasFile = isset($file['tmp_name']) && is_uploaded_file($file['tmp_name']);

    if ($hasText && $hasFile) {
        return 'mixed';
    }
    if ($hasFile) {
        return 'file';
    }
    return 'rich_text';
}

function upload_document_file(array $file): ?array
{
    if (
        !isset($file['tmp_name'], $file['name']) ||
        !is_uploaded_file($file['tmp_name']) ||
        (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        return null;
    }

    $maxSize = 25 * 1024 * 1024; // 25MB
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

    return [
        'original_name' => $originalName,
        'stored_name'   => $storedName,
        'path'          => 'uploads/documents/' . $storedName,
        'mime'          => mime_content_type($targetPath) ?: ($file['type'] ?? 'application/octet-stream'),
        'size'          => (int) filesize($targetPath),
        'sha256'        => hash_file('sha256', $targetPath),
    ];
}

function fetch_users_for_select(PDO $pdo, int $excludeUserId = 0): array
{
    $sqlOptions = [
        "SELECT id, full_name AS name FROM users WHERE id != :uid AND status = 'active' ORDER BY full_name ASC",
        "SELECT id, username AS name FROM users WHERE id != :uid AND status = 'active' ORDER BY username ASC",
        "SELECT id, full_name AS name FROM users WHERE id != :uid ORDER BY full_name ASC",
        "SELECT id, username AS name FROM users WHERE id != :uid ORDER BY username ASC"
    ];

    foreach ($sqlOptions as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':uid' => $excludeUserId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rows)) {
                return $rows;
            }
        } catch (Throwable $e) {
            // try next query option
        }
    }

    return [];
}

function fetch_document_types(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, type_name, prefix FROM document_types WHERE status = 'active' ORDER BY type_name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_departments(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/*
|--------------------------------------------------------------------------
| Load select options
|--------------------------------------------------------------------------
*/
$documentTypes = fetch_document_types($pdo);
$owners        = fetch_users_for_select($pdo, 0);
$approvers     = fetch_users_for_select($pdo, $currentUserId);
$departments   = fetch_departments($pdo);

/*
|--------------------------------------------------------------------------
| Form defaults
|--------------------------------------------------------------------------
*/
$errors = [];
$successMessage = '';
$form = [
    'document_type_id'   => '',
    'department_id'      => '',
    'title'              => '',
    'topic'              => '',
    'document_number'    => '',
    'owner_user_id'      => '',
    'approver_user_id'   => '',
    'effective_date'     => '',
    'review_date'        => '',
    'change_summary'     => '',
    'content_text'       => '',
    'version_label'      => '01',
    'document_id_preview'=> '',
];

$badgeClass  = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle';
$badgeLabel  = 'Draft';

/*
|--------------------------------------------------------------------------
| Submit handling
|--------------------------------------------------------------------------
*/
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
    $departmentId   = $form['department_id'] !== '' ? (int)$form['department_id'] : null;
    $ownerUserId    = (int)$form['owner_user_id'];
    $approverUserId = (int)$form['approver_user_id'];

    if ($documentTypeId <= 0) {
        $errors[] = 'Document Type is required.';
    }
    if ($form['title'] === '') {
        $errors[] = 'Document Topic or Title is required.';
    }
    if ($form['document_number'] === '') {
        $errors[] = 'Document Number / ID component is required.';
    }
    if ($ownerUserId <= 0) {
        $errors[] = 'Owner is required.';
    }
    if ($approverUserId <= 0) {
        $errors[] = 'Approver is required.';
    }
    if ($approverUserId === $currentUserId) {
        $errors[] = 'Creator cannot select self as approver.';
    }
    if ($form['effective_date'] === '') {
        $errors[] = 'Effective Date is required.';
    }
    if ($form['review_date'] === '') {
        $errors[] = 'Review Date is required.';
    }

    $hasFile = isset($_FILES['primary_file']) && (int)($_FILES['primary_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
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
    $topicPart = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $form['topic'] !== '' ? $form['topic'] : $form['title']));
    $topicPart = trim($topicPart, '-');
    $form['document_id_preview'] = $prefix . '-' . $form['document_number'] . ($topicPart !== '' ? '-' . $topicPart : '') . '-' . $versionLabel;

    try {
        if (!$errors) {
            $checkStmt = $pdo->prepare("SELECT id FROM documents WHERE document_number = :document_number LIMIT 1");
            $checkStmt->execute([':document_number' => $form['document_number']]);
            if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
                $errors[] = 'Duplicate Document ID / Number is not allowed.';
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();

            $uploadedFile = upload_document_file($_FILES['primary_file'] ?? []);
            $contentFormat = normalize_content_format($form['content_text'], $_FILES['primary_file'] ?? []);
            $documentStatus = ($action === 'submit_review') ? 'pending_approval' : 'draft';
            $versionStatus  = ($action === 'submit_review') ? 'pending_approval' : 'draft';

            $docStmt = $pdo->prepare("
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
                ) VALUES (
                    :document_number,
                    :document_type_id,
                    :department_id,
                    :title,
                    :topic,
                    :owner_user_id,
                    :created_by,
                    :current_status,
                    :is_ack_required,
                    :remarks,
                    :approver
                )
            ");

            $docStmt->execute([
                ':document_number'   => $form['document_number'],
                ':document_type_id'  => $documentTypeId,
                ':department_id'     => $departmentId,
                ':title'             => $form['title'],
                ':topic'             => $form['topic'] !== '' ? $form['topic'] : null,
                ':owner_user_id'     => $ownerUserId,
                ':created_by'        => $currentUserId,
                ':current_status'    => $documentStatus,
                ':is_ack_required'   => 0,
                ':remarks'           => $form['change_summary'] !== '' ? $form['change_summary'] : null,
                ':approver'          => (string)$approverUserId,
            ]);

            $documentId = (int)$pdo->lastInsertId();

            $verStmt = $pdo->prepare("
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
                ) VALUES (
                    :document_id,
                    NULL,
                    :version_sequence,
                    :version_label,
                    :title_snapshot,
                    :topic_snapshot,
                    :owner_user_id,
                    :created_by,
                    :change_summary,
                    :effective_date,
                    :review_date,
                    :status,
                    :content_format,
                    :content_text,
                    :primary_file_name,
                    :primary_file_path,
                    :primary_file_mime,
                    :primary_file_size,
                    :checksum_sha256,
                    :submitted_by,
                    :submitted_at
                )
            ");

            $verStmt->execute([
                ':document_id'       => $documentId,
                ':version_sequence'  => 1,
                ':version_label'     => $versionLabel,
                ':title_snapshot'    => $form['title'],
                ':topic_snapshot'    => $form['topic'] !== '' ? $form['topic'] : null,
                ':owner_user_id'     => $ownerUserId,
                ':created_by'        => $currentUserId,
                ':change_summary'    => $form['change_summary'] !== '' ? $form['change_summary'] : null,
                ':effective_date'    => $form['effective_date'],
                ':review_date'       => $form['review_date'],
                ':status'            => $versionStatus,
                ':content_format'    => $contentFormat,
                ':content_text'      => $form['content_text'] !== '' ? $form['content_text'] : null,
                ':primary_file_name' => $uploadedFile['original_name'] ?? null,
                ':primary_file_path' => $uploadedFile['path'] ?? null,
                ':primary_file_mime' => $uploadedFile['mime'] ?? null,
                ':primary_file_size' => $uploadedFile['size'] ?? null,
                ':checksum_sha256'   => $uploadedFile['sha256'] ?? null,
                ':submitted_by'      => ($action === 'submit_review') ? $currentUserId : null,
                ':submitted_at'      => ($action === 'submit_review') ? date('Y-m-d H:i:s') : null,
            ]);

            $versionId = (int)$pdo->lastInsertId();

            $updStmt = $pdo->prepare("UPDATE documents SET current_version_id = :version_id WHERE id = :document_id");
            $updStmt->execute([
                ':version_id'  => $versionId,
                ':document_id' => $documentId,
            ]);

            if ($uploadedFile) {
                $attStmt = $pdo->prepare("
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
                    ) VALUES (
                        :document_version_id,
                        'primary',
                        :original_file_name,
                        :stored_file_name,
                        :file_path,
                        :mime_type,
                        :file_size,
                        :checksum_sha256,
                        :uploaded_by
                    )
                ");

                $attStmt->execute([
                    ':document_version_id' => $versionId,
                    ':original_file_name'  => $uploadedFile['original_name'],
                    ':stored_file_name'    => $uploadedFile['stored_name'],
                    ':file_path'           => $uploadedFile['path'],
                    ':mime_type'           => $uploadedFile['mime'],
                    ':file_size'           => $uploadedFile['size'],
                    ':checksum_sha256'     => $uploadedFile['sha256'],
                    ':uploaded_by'         => $currentUserId,
                ]);
            }

            $auditAction = ($action === 'submit_review') ? 'submit' : 'draft_create';
            $auditRemarks = ($action === 'submit_review')
                ? 'Document created and submitted for review.'
                : 'Document draft created.';

            $auditStmt = $pdo->prepare("
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
                ) VALUES (
                    :event_id,
                    :entity_type,
                    :entity_id,
                    :action,
                    :old_value,
                    :new_value,
                    :performed_by,
                    :remarks,
                    :ip_address,
                    :user_agent
                )
            ");

            $auditPayload = [
                'document_id'        => $documentId,
                'document_version_id'=> $versionId,
                'document_number'    => $form['document_number'],
                'title'              => $form['title'],
                'topic'              => $form['topic'],
                'status'             => $documentStatus,
                'version_label'      => $versionLabel,
                'approver_user_id'   => $approverUserId,
            ];

            $auditStmt->execute([
                ':event_id'     => generate_uuid_v4(),
                ':entity_type'  => 'document',
                ':entity_id'    => $documentId,
                ':action'       => $auditAction,
                ':old_value'    => null,
                ':new_value'    => json_encode($auditPayload, JSON_UNESCAPED_UNICODE),
                ':performed_by' => $currentUserId,
                ':remarks'      => $auditRemarks,
                ':ip_address'   => get_client_ip(),
                ':user_agent'   => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);

            if ($action === 'submit_review') {
                try {
                    $notifStmt = $pdo->prepare("
                        INSERT INTO notifications (
                            user_id,
                            notification_type,
                            reference_type,
                            reference_id,
                            title,
                            message
                        ) VALUES (
                            :user_id,
                            'submit',
                            'document_version',
                            :reference_id,
                            :title,
                            :message
                        )
                    ");

                    $notifStmt->execute([
                        ':user_id'      => $approverUserId,
                        ':reference_id' => $versionId,
                        ':title'        => 'Document Submitted for Review',
                        ':message'      => 'A new document "' . $form['title'] . '" has been submitted for your review.',
                    ]);
                } catch (Throwable $e) {
                    // Notification failure should not break save
                }
            }

            $pdo->commit();

            if ($action === 'submit_review') {
                $successMessage = 'Document created successfully and submitted for review.';
                $badgeClass = 'badge bg-info-subtle text-info-emphasis border border-info-subtle';
                $badgeLabel = 'Pending Approval';
            } else {
                $successMessage = 'Document draft created successfully.';
                $badgeClass = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                $badgeLabel = 'Draft';
            }

            $form = [
                'document_type_id'   => '',
                'department_id'      => '',
                'title'              => '',
                'topic'              => '',
                'document_number'    => '',
                'owner_user_id'      => '',
                'approver_user_id'   => '',
                'effective_date'     => '',
                'review_date'        => '',
                'change_summary'     => '',
                'content_text'       => '',
                'version_label'      => '01',
                'document_id_preview'=> '',
            ];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Error: ' . $e->getMessage();
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
        .readonly {
            background-color: #f8f9fa;
        }
        .app-shell {
            min-height: calc(100vh - 72px);
            background: #f7f9fc;
        }
        .content-wrap {
            max-width: 1400px;
        }
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #0D2144;
        }
        .page-subtitle {
            color: #6c757d;
        }
        .cp-card {
            border: 1px solid #e9ecef;
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
        }
        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0D2144;
        }
        .card-subtitle {
            color: #6c757d;
            font-size: 0.95rem;
        }
        .tab-pill {
            border-radius: 999px;
            padding: 0.55rem 1rem;
        }
        .upload-box {
            border: 1.5px dashed #cfd6e4;
            border-radius: 14px;
            background: #fbfcfe;
        }
        .kv {
            background: #f8fbff;
            border: 1px solid #d8e6ff;
            border-radius: 12px;
        }
        .note-list {
            padding-left: 1rem;
        }
        .badge-soft-secondary {
            background: #eef2f7;
            color: #5b6472;
            border: 1px solid #e2e8f0;
            padding: 0.5rem 0.75rem;
            border-radius: 999px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top bg-white border-bottom">
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
                <span class="navbar-text small">QA Admin</span>
                <a class="nav-link px-0" href="notifications.php">Notifications</a>
                <span class="navbar-text small"><?php echo e($currentUserName); ?></span>
            </div>
        </div>
    </div>
</nav>

<main class="app-shell">
    <div class="content-wrap px-4 py-4 mx-auto">
        <div class="mb-4">
            <h1 class="page-title mb-2">Create Controlled Document</h1>
            <p class="page-subtitle mb-0">Create a new controlled document with required metadata, ownership, and approval workflow.</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger rounded-4">
                <strong>Please fix the following:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success rounded-4"><?php echo e($successMessage); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="createDocumentForm">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <span class="<?php echo e($badgeClass); ?>"><?php echo e($badgeLabel); ?></span>

                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-outline-secondary" href="dashboard-admin.php">Cancel</a>
                    <button type="submit" name="action" value="save_draft" class="btn btn-outline-primary">Save Draft</button>
                    <button type="submit" name="action" value="submit_review" class="btn btn-success">Submit for Review</button>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card cp-card mb-3">
                        <div class="card-body">
                            <h2 class="card-title mb-1">Document Information</h2>
                            <p class="card-subtitle mb-3">Enter the required document metadata and ownership details.</p>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Document Type <span class="text-danger">*</span></label>
                                    <select class="form-select" name="document_type_id" id="document_type_id" required>
                                        <option value="">Select document type</option>
                                        <?php foreach ($documentTypes as $type): ?>
                                            <option value="<?php echo (int)$type['id']; ?>" data-prefix="<?php echo e($type['prefix']); ?>" <?php echo ((string)$form['document_type_id'] === (string)$type['id']) ? 'selected' : ''; ?>>
                                                <?php echo e($type['type_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Document Topic or Title <span class="text-danger">*</span></label>
                                    <input class="form-control" type="text" name="title" id="title" value="<?php echo e($form['title']); ?>" placeholder="Enter document title">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Document Topic</label>
                                    <input class="form-control" type="text" name="topic" id="topic" value="<?php echo e($form['topic']); ?>" placeholder="Enter topic">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Document Number / ID Component <span class="text-danger">*</span></label>
                                    <input class="form-control" type="text" name="document_number" id="document_number" value="<?php echo e($form['document_number']); ?>" placeholder="Enter unique document number">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Version</label>
                                    <input class="form-control readonly" type="text" name="version_label" id="version_label" readonly value="<?php echo e($form['version_label']); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Department</label>
                                    <select class="form-select" name="department_id">
                                        <option value="">Select department</option>
                                        <?php foreach ($departments as $department): ?>
                                            <option value="<?php echo (int)$department['id']; ?>" <?php echo ((string)$form['department_id'] === (string)$department['id']) ? 'selected' : ''; ?>>
                                                <?php echo e($department['department_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Owner <span class="text-danger">*</span></label>
                                    <select class="form-select" name="owner_user_id" required>
                                        <option value="">Select owner</option>
                                        <?php foreach ($owners as $owner): ?>
                                            <option value="<?php echo (int)$owner['id']; ?>" <?php echo ((string)$form['owner_user_id'] === (string)$owner['id']) ? 'selected' : ''; ?>>
                                                <?php echo e($owner['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Approver <span class="text-danger">*</span></label>
                                    <select class="form-select" name="approver_user_id" required>
                                        <option value="">Select approver</option>
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
                                    <input class="form-control" type="date" name="effective_date" value="<?php echo e($form['effective_date']); ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Review Date <span class="text-danger">*</span></label>
                                    <input class="form-control" type="date" name="review_date" value="<?php echo e($form['review_date']); ?>" required>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Change Summary</label>
                                    <textarea class="form-control" name="change_summary" placeholder="Enter document purpose or summary" rows="3"><?php echo e($form['change_summary']); ?></textarea>
                                    <div class="form-text">Mandatory for traceability and approval context.</div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Document ID Preview</label>
                                    <div class="kv p-3 fw-semibold text-primary" id="documentIdPreview">
                                        <?php echo e($form['document_id_preview'] !== '' ? $form['document_id_preview'] : 'Preview will appear here'); ?>
                                    </div>
                                    <div class="form-text">Format: [Type]-[Number]-[Topic]-[Version]</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card cp-card">
                        <div class="card-body">
                            <h2 class="card-title mb-1">Document Content</h2>
                            <p class="card-subtitle mb-3">Add document content using rich text or controlled file upload.</p>

                            <ul class="nav nav-pills gap-2 mb-3">
                                <li class="nav-item"><a class="nav-link active tab-pill" href="javascript:void(0);">Rich Text Editor</a></li>
                                <li class="nav-item"><a class="nav-link tab-pill" href="javascript:void(0);">File Upload</a></li>
                            </ul>

                            <div class="mb-3">
                                <label class="form-label">Document Body</label>
                                <textarea class="form-control" name="content_text" placeholder="Enter document content here" rows="9"><?php echo e($form['content_text']); ?></textarea>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Document File Upload</label>
                                <input type="file" name="primary_file" class="form-control">
                            </div>

                            <div class="upload-box p-4 text-center small text-secondary">
                                Drag and drop file here or click to browse.<br>
                                Supported: PDF, DOCX, XLSX, DOC, XLS, TXT | Maximum size: 25 MB
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
                                <li>Audit event will be generated on save and submit.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="card cp-card">
                        <div class="card-body">
                            <h2 class="card-title mb-1">Developer Rules</h2>
                            <p class="card-subtitle mb-3">Applied backend logic for controlled document creation.</p>

                            <div class="small text-secondary">
                                <div class="mb-2">• Default status is Draft on initial save.</div>
                                <div class="mb-2">• Duplicate Document ID is blocked.</div>
                                <div class="mb-2">• Initial version is created as 01.</div>
                                <div class="mb-2">• Self-approver selection is blocked.</div>
                                <div class="mb-0">• Audit log is written on draft create and submit.</div>
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
    const typeSelect = document.getElementById('document_type_id');
    const numberInput = document.getElementById('document_number');
    const topicInput = document.getElementById('topic');
    const titleInput = document.getElementById('title');
    const versionInput = document.getElementById('version_label');
    const previewBox = document.getElementById('documentIdPreview');
    const form = document.getElementById('createDocumentForm');

    function slugify(str) {
        return (str || '')
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function updatePreview() {
        const selected = typeSelect.options[typeSelect.selectedIndex];
        const prefix = selected ? (selected.getAttribute('data-prefix') || 'DOC') : 'DOC';
        const number = (numberInput.value || '').trim();
        const topic = (topicInput.value || titleInput.value || '').trim();
        const version = (versionInput.value || '01').trim();

        if (!number && !topic && !typeSelect.value) {
            previewBox.textContent = 'Preview will appear here';
            return;
        }

        let preview = prefix;
        if (number) preview += '-' + number;
        if (topic) preview += '-' + slugify(topic);
        preview += '-' + version;

        previewBox.textContent = preview;
    }

    [typeSelect, numberInput, topicInput, titleInput].forEach(el => {
        if (el) el.addEventListener('input', updatePreview);
        if (el) el.addEventListener('change', updatePreview);
    });

    form.addEventListener('submit', function (e) {
        const requiredFields = [
            typeSelect,
            document.getElementById('title'),
            numberInput
        ];

        let valid = true;
        requiredFields.forEach(field => {
            if (field && !String(field.value).trim()) {
                valid = false;
            }
        });

        if (!valid) {
            e.preventDefault();
            alert('Please fill all required fields before continuing.');
        }
    });

    updatePreview();
})();
</script>
</body>
</html>