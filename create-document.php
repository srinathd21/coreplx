<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $tableName, string $columnName): bool {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('firstExistingColumn')) {
    function firstExistingColumn(mysqli $conn, string $tableName, array $columns): ?string {
        foreach ($columns as $column) {
            if (columnExists($conn, $tableName, $column)) {
                return $column;
            }
        }
        return null;
    }
}

date_default_timezone_set('Asia/Kolkata');

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && trim((string)$_SESSION['full_name']) !== ''
    ? trim((string)$_SESSION['full_name'])
    : 'QA Admin';

$successMessage = '';
$errorMessage = '';

if (!isset($_SESSION['create_document_fallback']) || !is_array($_SESSION['create_document_fallback'])) {
    $_SESSION['create_document_fallback'] = [];
}

$documentType   = trim((string)($_POST['document_type'] ?? 'SOP'));
$documentTopic  = trim((string)($_POST['document_topic'] ?? 'CAPA'));
$documentNumber = trim((string)($_POST['document_number'] ?? '104'));
$version        = trim((string)($_POST['version'] ?? '01'));
$owner          = trim((string)($_POST['owner'] ?? $currentUserName));
$approver       = trim((string)($_POST['approver'] ?? ''));
$effectiveDate  = trim((string)($_POST['effective_date'] ?? ''));
$reviewDate     = trim((string)($_POST['review_date'] ?? ''));
$changeSummary  = trim((string)($_POST['change_summary'] ?? ''));
$contentMethod  = trim((string)($_POST['content_method'] ?? 'rich_text'));
$documentBody   = trim((string)($_POST['document_body'] ?? ''));
$status         = trim((string)($_POST['status'] ?? 'Draft'));

if (!in_array($contentMethod, ['rich_text', 'file_upload'], true)) {
    $contentMethod = 'rich_text';
}

$documentIdPreview = trim($documentType) . '-' . trim($documentNumber) . '-' . trim($documentTopic) . '-' . trim($version);

$users = [];
if (tableExists($conn, 'users')) {
    $nameExpr = '';
    if (columnExists($conn, 'users', 'full_name')) {
        $nameExpr = "full_name";
    } elseif (columnExists($conn, 'users', 'first_name') && columnExists($conn, 'users', 'last_name')) {
        $nameExpr = "TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')))";
    } elseif (columnExists($conn, 'users', 'name')) {
        $nameExpr = "name";
    } elseif (columnExists($conn, 'users', 'username')) {
        $nameExpr = "username";
    }

    if ($nameExpr !== '') {
        $statusCol = firstExistingColumn($conn, 'users', ['status', 'is_active']);
        $sql = "SELECT {$nameExpr} AS user_name FROM users";
        if ($statusCol !== null) {
            if ($statusCol === 'is_active') {
                $sql .= " WHERE (`{$statusCol}` = 1 OR `{$statusCol}` = '1')";
            } else {
                $sql .= " WHERE (`{$statusCol}` = 'active' OR `{$statusCol}` = 'Active' OR `{$statusCol}` = 'ACTIVE' OR `{$statusCol}` = 1 OR `{$statusCol}` = '1')";
            }
        }
        $sql .= " ORDER BY user_name ASC";

        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $name = trim((string)($row['user_name'] ?? ''));
                if ($name !== '') {
                    $users[] = $name;
                }
            }
        }
    }
}

$users = array_values(array_unique($users));
if (empty($users)) {
    $users = ['Pradeep', 'QA Manager', 'QA Head', 'Compliance Manager'];
}
if ($owner === '' && !empty($users)) {
    $owner = $users[0];
}

$documentsTableExists = tableExists($conn, 'documents');
$uploadDir = __DIR__ . '/uploads/document-files/';
$uploadDirRelative = 'uploads/document-files/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}
$uploadedFileName = '';
$uploadedFilePath = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($owner === '') {
        $errorMessage = 'Owner is required.';
    } elseif ($approver === '') {
        $errorMessage = 'Approver is required.';
    } elseif (strcasecmp($owner, $approver) === 0) {
        $errorMessage = 'Creator cannot select themselves as approver.';
    } elseif ($documentType === '' || $documentTopic === '' || $documentNumber === '' || $version === '') {
        $errorMessage = 'Document Type, Topic, Number, and Version are required.';
    } elseif ($changeSummary === '') {
        $errorMessage = 'Change Summary is required.';
    } elseif ($contentMethod === 'rich_text' && $documentBody === '') {
        $errorMessage = 'Please enter document content.';
    } elseif ($contentMethod === 'file_upload') {
        if (!isset($_FILES['content_file']) || !is_array($_FILES['content_file']) || (int)$_FILES['content_file']['error'] === 4) {
            $errorMessage = 'Please upload a document file.';
        } else {
            $file = $_FILES['content_file'];
            if ((int)$file['error'] !== 0) {
                $errorMessage = 'File upload failed.';
            } else {
                $allowedExtensions = ['pdf', 'docx', 'xlsx'];
                $maxSize = 25 * 1024 * 1024;
                $originalName = (string)$file['name'];
                $tmpName = (string)$file['tmp_name'];
                $fileSize = (int)$file['size'];
                $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

                if (!in_array($extension, $allowedExtensions, true)) {
                    $errorMessage = 'Only PDF, DOCX, or XLSX files are allowed.';
                } elseif ($fileSize > $maxSize) {
                    $errorMessage = 'File size must be 25 MB or less.';
                } else {
                    $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', (string)pathinfo($originalName, PATHINFO_FILENAME));
                    $newFileName = $safeName . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $extension;
                    $destination = $uploadDir . $newFileName;

                    if (move_uploaded_file($tmpName, $destination)) {
                        $uploadedFileName = $originalName;
                        $uploadedFilePath = $uploadDirRelative . $newFileName;
                        $documentBody = '';
                    } else {
                        $errorMessage = 'Unable to move uploaded file.';
                    }
                }
            }
        }
    }

    if ($errorMessage === '') {
        if ($action === 'save_draft') {
            $status = 'Draft';
        } elseif ($action === 'submit_review') {
            $status = 'Pending Approval';
        } else {
            $status = 'Draft';
        }

        if ($documentsTableExists) {
            $docIdCol         = firstExistingColumn($conn, 'documents', ['document_id', 'doc_id', 'document_code']);
            $typeCol          = firstExistingColumn($conn, 'documents', ['document_type', 'type']);
            $topicCol         = firstExistingColumn($conn, 'documents', ['document_topic', 'topic']);
            $numberCol        = firstExistingColumn($conn, 'documents', ['document_number', 'number']);
            $versionCol       = firstExistingColumn($conn, 'documents', ['version']);
            $ownerCol         = firstExistingColumn($conn, 'documents', ['owner', 'document_owner']);
            $approverCol      = firstExistingColumn($conn, 'documents', ['approver', 'approver_name']);
            $effectiveDateCol = firstExistingColumn($conn, 'documents', ['effective_date']);
            $reviewDateCol    = firstExistingColumn($conn, 'documents', ['review_date']);
            $summaryCol       = firstExistingColumn($conn, 'documents', ['change_summary', 'summary', 'purpose']);
            $statusCol        = firstExistingColumn($conn, 'documents', ['status']);
            $methodCol        = firstExistingColumn($conn, 'documents', ['content_method']);
            $bodyCol          = firstExistingColumn($conn, 'documents', ['document_body', 'content']);
            $fileNameCol      = firstExistingColumn($conn, 'documents', ['file_name']);
            $filePathCol      = firstExistingColumn($conn, 'documents', ['file_path']);
            $createdByCol     = firstExistingColumn($conn, 'documents', ['created_by']);
            $updatedByCol     = firstExistingColumn($conn, 'documents', ['updated_by']);

            if ($docIdCol === null) {
                $errorMessage = 'documents table structure is incomplete.';
            } else {
                $existsSql = "SELECT id FROM `documents` WHERE `{$docIdCol}` = ? LIMIT 1";
                $existsStmt = mysqli_prepare($conn, $existsSql);

                if ($existsStmt) {
                    mysqli_stmt_bind_param($existsStmt, "s", $documentIdPreview);
                    mysqli_stmt_execute($existsStmt);
                    $existsRes = mysqli_stmt_get_result($existsStmt);
                    $existsRow = $existsRes ? mysqli_fetch_assoc($existsRes) : null;
                    mysqli_stmt_close($existsStmt);

                    if ($existsRow) {
                        $updateParts = [];
                        $bindValues = [];
                        $bindTypes = '';

                        $map = [
                            $typeCol => $documentType,
                            $topicCol => $documentTopic,
                            $numberCol => $documentNumber,
                            $versionCol => $version,
                            $ownerCol => $owner,
                            $approverCol => $approver,
                            $effectiveDateCol => $effectiveDate,
                            $reviewDateCol => $reviewDate,
                            $summaryCol => $changeSummary,
                            $statusCol => $status,
                            $methodCol => $contentMethod,
                            $bodyCol => $documentBody,
                            $fileNameCol => $uploadedFileName,
                            $filePathCol => $uploadedFilePath
                        ];

                        foreach ($map as $col => $value) {
                            if ($col !== null) {
                                $updateParts[] = "`{$col}` = ?";
                                $bindValues[] = $value;
                                $bindTypes .= 's';
                            }
                        }

                        if ($updatedByCol !== null) {
                            $updateParts[] = "`{$updatedByCol}` = ?";
                            $bindValues[] = $currentUserId;
                            $bindTypes .= 'i';
                        }

                        $bindValues[] = $documentIdPreview;
                        $bindTypes .= 's';

                        $sql = "UPDATE `documents` SET " . implode(', ', $updateParts) . " WHERE `{$docIdCol}` = ?";
                        $stmt = mysqli_prepare($conn, $sql);

                        if ($stmt) {
                            mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
                            if (mysqli_stmt_execute($stmt)) {
                                $successMessage = ($status === 'Pending Approval')
                                    ? 'Document submitted for review successfully.'
                                    : 'Draft saved successfully.';
                            } else {
                                $errorMessage = 'Failed to update document: ' . mysqli_error($conn);
                            }
                            mysqli_stmt_close($stmt);
                        } else {
                            $errorMessage = 'Failed to prepare update query.';
                        }
                    } else {
                        $insertCols = [];
                        $insertVals = [];
                        $bindValues = [];
                        $bindTypes = '';

                        $map = [
                            $docIdCol => $documentIdPreview,
                            $typeCol => $documentType,
                            $topicCol => $documentTopic,
                            $numberCol => $documentNumber,
                            $versionCol => $version,
                            $ownerCol => $owner,
                            $approverCol => $approver,
                            $effectiveDateCol => $effectiveDate,
                            $reviewDateCol => $reviewDate,
                            $summaryCol => $changeSummary,
                            $statusCol => $status,
                            $methodCol => $contentMethod,
                            $bodyCol => $documentBody,
                            $fileNameCol => $uploadedFileName,
                            $filePathCol => $uploadedFilePath
                        ];

                        foreach ($map as $col => $value) {
                            if ($col !== null) {
                                $insertCols[] = "`{$col}`";
                                $insertVals[] = "?";
                                $bindValues[] = $value;
                                $bindTypes .= 's';
                            }
                        }

                        if ($createdByCol !== null) {
                            $insertCols[] = "`{$createdByCol}`";
                            $insertVals[] = "?";
                            $bindValues[] = $currentUserId;
                            $bindTypes .= 'i';
                        }

                        if ($updatedByCol !== null) {
                            $insertCols[] = "`{$updatedByCol}`";
                            $insertVals[] = "?";
                            $bindValues[] = $currentUserId;
                            $bindTypes .= 'i';
                        }

                        $sql = "INSERT INTO `documents` (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
                        $stmt = mysqli_prepare($conn, $sql);

                        if ($stmt) {
                            mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
                            if (mysqli_stmt_execute($stmt)) {
                                $successMessage = ($status === 'Pending Approval')
                                    ? 'Document submitted for review successfully.'
                                    : 'Draft saved successfully.';
                            } else {
                                $errorMessage = 'Failed to create document: ' . mysqli_error($conn);
                            }
                            mysqli_stmt_close($stmt);
                        } else {
                            $errorMessage = 'Failed to prepare insert query.';
                        }
                    }
                } else {
                    $errorMessage = 'Failed to prepare exists query.';
                }
            }
        } else {
            $_SESSION['create_document_fallback'][$documentIdPreview] = [
                'document_type' => $documentType,
                'document_topic' => $documentTopic,
                'document_number' => $documentNumber,
                'version' => $version,
                'owner' => $owner,
                'approver' => $approver,
                'effective_date' => $effectiveDate,
                'review_date' => $reviewDate,
                'change_summary' => $changeSummary,
                'status' => $status,
                'content_method' => $contentMethod,
                'document_body' => $documentBody,
                'file_name' => $uploadedFileName,
                'file_path' => $uploadedFilePath
            ];

            $successMessage = ($status === 'Pending Approval')
                ? 'Document submitted for review successfully.'
                : 'Draft saved successfully.';
        }
    }
}

function readinessChecks(array $data): array {
    return [
        trim((string)$data['document_type']) !== '' &&
        trim((string)$data['document_topic']) !== '' &&
        trim((string)$data['document_number']) !== '' &&
        trim((string)$data['version']) !== '' &&
        trim((string)$data['owner']) !== '',
        trim((string)$data['document_id_preview']) !== '',
        ($data['content_method'] === 'rich_text' && trim((string)$data['document_body']) !== '') ||
        ($data['content_method'] === 'file_upload'),
        trim((string)$data['approver']) !== '' && strcasecmp((string)$data['owner'], (string)$data['approver']) !== 0,
        true
    ];
}

$checks = readinessChecks([
    'document_type' => $documentType,
    'document_topic' => $documentTopic,
    'document_number' => $documentNumber,
    'version' => $version,
    'owner' => $owner,
    'approver' => $approver,
    'document_id_preview' => $documentIdPreview,
    'content_method' => $contentMethod,
    'document_body' => $documentBody
]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Create Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .cp-card{border:1px solid rgba(0,0,0,.08);border-radius:18px;box-shadow:0 6px 24px rgba(0,0,0,.06);background:#fff;}
    .page-title{font-size:27px;line-height:1.2;font-weight:700;color:#173f7a;margin:0 0 12px 0;}
    .page-subtitle{font-size:18px;line-height:1.55;color:#5c6f8e;margin:0;font-weight:400;}
    .card-title{font-size:21px;line-height:1.3;font-weight:700;color:#173f7a;margin:0 0 4px 0;}
    .card-subtitle,.form-text{color:#5f708c;font-size:15px;line-height:1.55;}
    .form-label{font-size:16px;font-weight:600;color:#4c5b73;margin-bottom:10px;}
    .readonly{background-color:#f8f9fa;}
    .kv{border:1px dashed rgba(13,110,253,.25);border-radius:12px;background:#f8fbff;}
    .upload-box{border:1px dashed rgba(0,0,0,.15);border-radius:16px;background:#f8f9fa;}
    .note-list{padding-left:1rem;}
    .badge-soft-secondary{background:rgba(108,117,125,.12);color:#6c757d;}
    .tab-pill{border-radius:999px!important;}
    .content-upload-pane{display:none;}
    .content-upload-pane.active{display:block;}
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
        
        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="create-document.php">Create Document</a></li><li><a class="dropdown-item" href="update-document.php">Update Document</a></li><li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li><li><a class="dropdown-item" href="repository.php">Repository</a></li></ul></li>
        
        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Workflow</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="document-types.php">Document Types</a></li><li><a class="dropdown-item" href="document-id.php">Document ID</a></li><li><a class="dropdown-item" href="content-editor.php">Content Editor</a></li><li><a class="dropdown-item" href="form-builder.php">Form Builder</a></li><li><a class="dropdown-item" href="form-type-name.php">Form Type &amp; Name</a></li><li><a class="dropdown-item" href="approver-selection.php">Approver Selection</a></li><li><a class="dropdown-item" href="submit-review.php">Submit for Review</a></li><li><a class="dropdown-item" href="electronic-signature.php">Electronic Signature</a></li><li><a class="dropdown-item" href="approver-comments.php">Approver Comments</a></li><li><a class="dropdown-item" href="notifications.php">Notifications</a></li></ul></li>
        
        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="audit-creation.php">Audit - Creation</a></li><li><a class="dropdown-item" href="audit-approval.php">Audit - Approval</a></li><li><a class="dropdown-item" href="audit-comments.php">Audit - Comments</a></li><li><a class="dropdown-item" href="qa-admin.php">QA Admin</a></li><li><a class="dropdown-item" href="employee-role.php">Employee Role</a></li><li><a class="dropdown-item" href="super-admin.php">Super Admin</a></li><li><a class="dropdown-item" href="user-management.php">User Management</a></li><li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li></ul></li>
        
        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3"><span class="navbar-text small"><?php echo e($currentUserName); ?></span><a class="nav-link px-0" href="notifications.php">Notifications</a><span class="navbar-text small">Profile</span></div>
    </div>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">
<div class="mb-4">
<h1 class="page-title mb-2">Create Controlled Document</h1>
<p class="page-subtitle mb-0">Create a new controlled document with required metadata, ownership, and approval workflow.</p>
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

<form method="post" enctype="multipart/form-data">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
<span class="badge badge-soft-secondary"><?php echo e($status); ?></span>
<div class="d-flex gap-2 flex-wrap">
<a class="btn btn-outline-secondary" href="create-document.php">Cancel</a>
<button class="btn btn-outline-primary" type="submit" name="action" value="save_draft">Save Draft</button>
<button class="btn btn-success" type="submit" name="action" value="submit_review">Submit for Review</button>
</div>
</div>

<div class="row g-3">
<div class="col-lg-8">

<div class="card cp-card mb-3"><div class="card-body">
<h2 class="card-title mb-1">Document Information</h2>
<p class="card-subtitle mb-3">Enter the required document metadata and ownership details.</p>

<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Document Type</label>
<select class="form-select" name="document_type" id="document_type">
  <?php foreach (['SOP', 'Policy', 'Guidance', 'Form'] as $type): ?>
    <option value="<?php echo e($type); ?>" <?php echo $documentType === $type ? 'selected' : ''; ?>><?php echo e($type); ?></option>
  <?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label">Document Topic</label>
<input class="form-control" name="document_topic" id="document_topic" value="<?php echo e($documentTopic); ?>"/>
</div>

<div class="col-md-6">
<label class="form-label">Document Number</label>
<input class="form-control" name="document_number" id="document_number" value="<?php echo e($documentNumber); ?>"/>
</div>

<div class="col-md-6">
<label class="form-label">Version</label>
<input class="form-control readonly" name="version" id="version" readonly value="<?php echo e($version); ?>"/>
</div>

<div class="col-md-6">
<label class="form-label">Owner</label>
<select class="form-select" name="owner">
  <?php foreach ($users as $user): ?>
    <option value="<?php echo e($user); ?>" <?php echo $owner === $user ? 'selected' : ''; ?>><?php echo e($user); ?></option>
  <?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label">Approver</label>
<select class="form-select" name="approver">
  <option value="">Select Approver</option>
  <?php foreach ($users as $user): ?>
    <?php if (strcasecmp($user, $owner) !== 0): ?>
      <option value="<?php echo e($user); ?>" <?php echo $approver === $user ? 'selected' : ''; ?>><?php echo e($user); ?></option>
    <?php endif; ?>
  <?php endforeach; ?>
</select>
<div class="form-text">Creator cannot select themselves as approver.</div>
</div>

<div class="col-md-6">
<label class="form-label">Effective Date</label>
<input class="form-control" name="effective_date" type="date" value="<?php echo e($effectiveDate); ?>"/>
</div>

<div class="col-md-6">
<label class="form-label">Review Date</label>
<input class="form-control" name="review_date" type="date" value="<?php echo e($reviewDate); ?>"/>
</div>

<div class="col-12">
<label class="form-label">Change Summary</label>
<textarea class="form-control" name="change_summary" placeholder="Enter document purpose or summary" rows="3"><?php echo e($changeSummary); ?></textarea>
<div class="form-text">Mandatory for traceability and approval context.</div>
</div>

<div class="col-12">
<label class="form-label">Document ID Preview</label>
<div class="kv p-3 fw-semibold text-primary" id="document_id_preview"><?php echo e($documentIdPreview); ?></div>
<div class="form-text">Format: [Type]-[Number]-[Topic]-[Version]</div>
</div>
</div>
</div></div>

<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Document Content</h2>
<p class="card-subtitle mb-3">Add document content using rich text or controlled file upload.</p>

<input type="hidden" name="content_method" id="content_method" value="<?php echo e($contentMethod); ?>">

<ul class="nav nav-pills gap-2 mb-3">
<li class="nav-item"><a class="nav-link tab-pill <?php echo $contentMethod === 'rich_text' ? 'active' : ''; ?>" href="#" id="tabRichText">Rich Text Editor</a></li>
<li class="nav-item"><a class="nav-link tab-pill <?php echo $contentMethod === 'file_upload' ? 'active' : ''; ?>" href="#" id="tabFileUpload">File Upload</a></li>
</ul>

<div class="content-upload-pane <?php echo $contentMethod === 'rich_text' ? 'active' : ''; ?>" id="paneRichText">
  <div class="mb-3">
    <label class="form-label">Document Body</label>
    <textarea class="form-control" name="document_body" placeholder="Enter document content here" rows="9"><?php echo e($documentBody); ?></textarea>
  </div>
</div>

<div class="content-upload-pane <?php echo $contentMethod === 'file_upload' ? 'active' : ''; ?>" id="paneFileUpload">
  <div class="upload-box p-4 text-center small text-secondary">
    <div class="mb-3">Drag and drop file here or click to browse.<br/>Supported: PDF, DOCX, XLSX | Maximum size: 25 MB</div>
    <input type="file" class="form-control" name="content_file" accept=".pdf,.docx,.xlsx">
  </div>
</div>

</div></div>
</div>

<div class="col-lg-4">
<div class="card cp-card mb-3"><div class="card-body">
<h2 class="card-title mb-1">Submission Readiness</h2>
<p class="card-subtitle mb-3">Verify required information before sending for approval.</p>
<ul class="small text-secondary note-list mb-0">
<li><?php echo $checks[0] ? 'Metadata completed.' : 'Metadata pending.'; ?></li>
<li><?php echo $checks[1] ? 'Unique document ID validated.' : 'Unique document ID pending.'; ?></li>
<li><?php echo $checks[2] ? 'Content entered or file attached.' : 'Content pending.'; ?></li>
<li><?php echo $checks[3] ? 'Approver selected and validated.' : 'Approver pending or invalid.'; ?></li>
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
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeEl = document.getElementById('document_type');
    const topicEl = document.getElementById('document_topic');
    const numberEl = document.getElementById('document_number');
    const versionEl = document.getElementById('version');
    const previewEl = document.getElementById('document_id_preview');

    function updatePreview() {
        const type = (typeEl.value || '').trim();
        const topic = (topicEl.value || '').trim();
        const number = (numberEl.value || '').trim();
        const version = (versionEl.value || '').trim();
        previewEl.textContent = [type, number, topic, version].join('-');
    }

    if (typeEl) typeEl.addEventListener('change', updatePreview);
    if (topicEl) topicEl.addEventListener('input', updatePreview);
    if (numberEl) numberEl.addEventListener('input', updatePreview);

    const tabRichText = document.getElementById('tabRichText');
    const tabFileUpload = document.getElementById('tabFileUpload');
    const paneRichText = document.getElementById('paneRichText');
    const paneFileUpload = document.getElementById('paneFileUpload');
    const contentMethod = document.getElementById('content_method');

    function setContentTab(mode) {
        if (mode === 'rich_text') {
            tabRichText.classList.add('active');
            tabFileUpload.classList.remove('active');
            paneRichText.classList.add('active');
            paneFileUpload.classList.remove('active');
            contentMethod.value = 'rich_text';
        } else {
            tabFileUpload.classList.add('active');
            tabRichText.classList.remove('active');
            paneFileUpload.classList.add('active');
            paneRichText.classList.remove('active');
            contentMethod.value = 'file_upload';
        }
    }

    if (tabRichText) {
        tabRichText.addEventListener('click', function (e) {
            e.preventDefault();
            setContentTab('rich_text');
        });
    }

    if (tabFileUpload) {
        tabFileUpload.addEventListener('click', function (e) {
            e.preventDefault();
            setContentTab('file_upload');
        });
    }
});
</script>
</body>
</html>