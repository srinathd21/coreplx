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

if (!function_exists('nextVersionValue')) {
    function nextVersionValue(string $currentVersion): string {
        $currentVersion = trim($currentVersion);
        if ($currentVersion === '') {
            return '01';
        }

        if (ctype_digit($currentVersion)) {
            return str_pad((string)((int)$currentVersion + 1), max(2, strlen($currentVersion)), '0', STR_PAD_LEFT);
        }

        if (preg_match('/(\d+)$/', $currentVersion, $m)) {
            $num = (int)$m[1] + 1;
            $pad = strlen($m[1]);
            return preg_replace('/\d+$/', str_pad((string)$num, $pad, '0', STR_PAD_LEFT), $currentVersion);
        }

        return $currentVersion;
    }
}

date_default_timezone_set('Asia/Kolkata');

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && trim((string)$_SESSION['full_name']) !== ''
    ? trim((string)$_SESSION['full_name'])
    : 'QA Admin';

$successMessage = '';
$errorMessage = '';

if (!isset($_SESSION['update_document_fallback']) || !is_array($_SESSION['update_document_fallback'])) {
    $_SESSION['update_document_fallback'] = [];
}

$documentIdInput = trim((string)($_GET['document_id'] ?? $_POST['document_id'] ?? ''));
$documentType    = trim((string)($_POST['document_type'] ?? 'SOP'));
$documentTopic   = trim((string)($_POST['document_topic'] ?? 'CAPA'));
$documentNumber  = trim((string)($_POST['document_number'] ?? '104'));
$currentVersion  = trim((string)($_POST['current_version'] ?? '03'));
$nextVersion     = trim((string)($_POST['next_version'] ?? nextVersionValue($currentVersion)));
$owner           = trim((string)($_POST['owner'] ?? $currentUserName));
$approver        = trim((string)($_POST['approver'] ?? ''));
$effectiveDate   = trim((string)($_POST['effective_date'] ?? ''));
$reviewDate      = trim((string)($_POST['review_date'] ?? ''));
$changeSummary   = trim((string)($_POST['change_summary'] ?? ''));
$versionComparison = trim((string)($_POST['version_comparison'] ?? 'Previous version highlights, redline preview, and impacted metadata should be visible before submit.'));
$contentMethod   = trim((string)($_POST['content_method'] ?? 'rich_text'));
$documentBody    = trim((string)($_POST['document_body'] ?? ''));
$status          = trim((string)($_POST['status'] ?? 'Under Review'));
$currentFileName = '';
$currentFilePath = '';

if (!in_array($contentMethod, ['rich_text', 'file_upload'], true)) {
    $contentMethod = 'rich_text';
}

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
        $statusColUsers = firstExistingColumn($conn, 'users', ['status', 'is_active']);
        $sqlUsers = "SELECT {$nameExpr} AS user_name FROM users";
        if ($statusColUsers !== null) {
            if ($statusColUsers === 'is_active') {
                $sqlUsers .= " WHERE (`{$statusColUsers}` = 1 OR `{$statusColUsers}` = '1')";
            } else {
                $sqlUsers .= " WHERE (`{$statusColUsers}` = 'active' OR `{$statusColUsers}` = 'Active' OR `{$statusColUsers}` = 'ACTIVE' OR `{$statusColUsers}` = 1 OR `{$statusColUsers}` = '1')";
            }
        }
        $sqlUsers .= " ORDER BY user_name ASC";

        $resUsers = mysqli_query($conn, $sqlUsers);
        if ($resUsers) {
            while ($row = mysqli_fetch_assoc($resUsers)) {
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

$documentsTableExists = tableExists($conn, 'documents');

if ($documentsTableExists && $documentIdInput !== '') {
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
    $comparisonCol    = firstExistingColumn($conn, 'documents', ['version_comparison', 'comparison_notes']);

    if ($docIdCol !== null) {
        $selectParts = [];
        $mapCols = [
            'document_type'      => $typeCol,
            'document_topic'     => $topicCol,
            'document_number'    => $numberCol,
            'version'            => $versionCol,
            'owner'              => $ownerCol,
            'approver'           => $approverCol,
            'effective_date'     => $effectiveDateCol,
            'review_date'        => $reviewDateCol,
            'change_summary'     => $summaryCol,
            'status'             => $statusCol,
            'content_method'     => $methodCol,
            'document_body'      => $bodyCol,
            'file_name'          => $fileNameCol,
            'file_path'          => $filePathCol,
            'version_comparison' => $comparisonCol
        ];

        foreach ($mapCols as $alias => $col) {
            $selectParts[] = $col !== null ? "`{$col}` AS `{$alias}`" : "'' AS `{$alias}`";
        }

        $sql = "SELECT " . implode(', ', $selectParts) . " FROM `documents` WHERE `{$docIdCol}` = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $documentIdInput);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && mysqli_num_rows($res) > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
                $row = mysqli_fetch_assoc($res);
                $documentType      = trim((string)($row['document_type'] ?? $documentType));
                $documentTopic     = trim((string)($row['document_topic'] ?? $documentTopic));
                $documentNumber    = trim((string)($row['document_number'] ?? $documentNumber));
                $currentVersion    = trim((string)($row['version'] ?? $currentVersion));
                $nextVersion       = nextVersionValue($currentVersion);
                $owner             = trim((string)($row['owner'] ?? $owner));
                $approver          = trim((string)($row['approver'] ?? $approver));
                $effectiveDate     = trim((string)($row['effective_date'] ?? $effectiveDate));
                $reviewDate        = trim((string)($row['review_date'] ?? $reviewDate));
                $changeSummary     = trim((string)($row['change_summary'] ?? $changeSummary));
                $status            = trim((string)($row['status'] ?? $status));
                $contentMethod     = trim((string)($row['content_method'] ?? $contentMethod));
                $documentBody      = trim((string)($row['document_body'] ?? $documentBody));
                $currentFileName   = trim((string)($row['file_name'] ?? ''));
                $currentFilePath   = trim((string)($row['file_path'] ?? ''));
                $versionComparison = trim((string)($row['version_comparison'] ?? $versionComparison));
                if ($contentMethod === '') {
                    $contentMethod = 'rich_text';
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

$updateDocumentId = trim($documentType) . '-' . trim($documentNumber) . '-' . trim($documentTopic) . '-' . trim($nextVersion);

$uploadDir = __DIR__ . '/uploads/document-files/';
$uploadDirRelative = 'uploads/document-files/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($documentIdInput === '') {
        $errorMessage = 'Document ID is required to update.';
    } elseif ($owner === '') {
        $errorMessage = 'Owner is required.';
    } elseif ($approver === '') {
        $errorMessage = 'Approver is required.';
    } elseif (strcasecmp($owner, $approver) === 0) {
        $errorMessage = 'Creator cannot select themselves as approver.';
    } elseif ($documentType === '' || $documentTopic === '' || $documentNumber === '' || $currentVersion === '' || $nextVersion === '') {
        $errorMessage = 'Document Type, Topic, Number, Current Version, and Next Version are required.';
    } elseif ($changeSummary === '') {
        $errorMessage = 'Change Summary is required.';
    } elseif ($contentMethod === 'rich_text' && $documentBody === '') {
        $errorMessage = 'Please enter document content.';
    }

    $uploadedFileName = $currentFileName;
    $uploadedFilePath = $currentFilePath;

    if ($errorMessage === '' && $contentMethod === 'file_upload') {
        if (isset($_FILES['content_file']) && is_array($_FILES['content_file']) && (int)$_FILES['content_file']['error'] !== 4) {
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
        } elseif ($currentFilePath === '') {
            $errorMessage = 'Please upload a document file.';
        }
    }

    if ($errorMessage === '') {
        if ($action === 'save_draft') {
            $status = 'Draft';
        } elseif ($action === 'submit_review') {
            $status = 'Pending Approval';
        } else {
            $status = 'Under Review';
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
            $comparisonCol    = firstExistingColumn($conn, 'documents', ['version_comparison', 'comparison_notes']);
            $updatedByCol     = firstExistingColumn($conn, 'documents', ['updated_by']);

            if ($docIdCol === null) {
                $errorMessage = 'documents table structure is incomplete.';
            } else {
                $updateParts = [];
                $bindValues = [];
                $bindTypes = '';

                $map = [
                    $docIdCol         => $updateDocumentId,
                    $typeCol          => $documentType,
                    $topicCol         => $documentTopic,
                    $numberCol        => $documentNumber,
                    $versionCol       => $nextVersion,
                    $ownerCol         => $owner,
                    $approverCol      => $approver,
                    $effectiveDateCol => $effectiveDate,
                    $reviewDateCol    => $reviewDate,
                    $summaryCol       => $changeSummary,
                    $statusCol        => $status,
                    $methodCol        => $contentMethod,
                    $bodyCol          => $documentBody,
                    $fileNameCol      => $uploadedFileName,
                    $filePathCol      => $uploadedFilePath,
                    $comparisonCol    => $versionComparison
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

                $bindValues[] = $documentIdInput;
                $bindTypes .= 's';

                $sql = "UPDATE `documents` SET " . implode(', ', $updateParts) . " WHERE `{$docIdCol}` = ?";
                $stmt = mysqli_prepare($conn, $sql);

                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
                    if (mysqli_stmt_execute($stmt)) {
                        $successMessage = ($status === 'Pending Approval')
                            ? 'Document update submitted for review successfully.'
                            : 'Document update saved successfully.';
                        $documentIdInput = $updateDocumentId;
                        $currentVersion = $nextVersion;
                        $nextVersion = nextVersionValue($currentVersion);
                        $currentFileName = $uploadedFileName;
                        $currentFilePath = $uploadedFilePath;
                    } else {
                        $errorMessage = 'Failed to update document: ' . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $errorMessage = 'Failed to prepare update query.';
                }
            }
        } else {
            $_SESSION['update_document_fallback'][$updateDocumentId] = [
                'original_document_id' => $documentIdInput,
                'document_type' => $documentType,
                'document_topic' => $documentTopic,
                'document_number' => $documentNumber,
                'version' => $nextVersion,
                'owner' => $owner,
                'approver' => $approver,
                'effective_date' => $effectiveDate,
                'review_date' => $reviewDate,
                'change_summary' => $changeSummary,
                'status' => $status,
                'content_method' => $contentMethod,
                'document_body' => $documentBody,
                'file_name' => $uploadedFileName,
                'file_path' => $uploadedFilePath,
                'version_comparison' => $versionComparison
            ];

            $successMessage = ($status === 'Pending Approval')
                ? 'Document update submitted for review successfully.'
                : 'Document update saved successfully.';
            $documentIdInput = $updateDocumentId;
            $currentVersion = $nextVersion;
            $nextVersion = nextVersionValue($currentVersion);
        }
    }
}

function readinessChecksUpdate(array $data): array {
    return [
        trim((string)$data['document_type']) !== '' &&
        trim((string)$data['document_topic']) !== '' &&
        trim((string)$data['document_number']) !== '' &&
        trim((string)$data['current_version']) !== '' &&
        trim((string)$data['next_version']) !== '' &&
        trim((string)$data['owner']) !== '',
        trim((string)$data['document_id_input']) !== '',
        ($data['content_method'] === 'rich_text' && trim((string)$data['document_body']) !== '') ||
        ($data['content_method'] === 'file_upload'),
        trim((string)$data['approver']) !== '' && strcasecmp((string)$data['owner'], (string)$data['approver']) !== 0,
        true
    ];
}

$checks = readinessChecksUpdate([
    'document_type' => $documentType,
    'document_topic' => $documentTopic,
    'document_number' => $documentNumber,
    'current_version' => $currentVersion,
    'next_version' => $nextVersion,
    'owner' => $owner,
    'approver' => $approver,
    'document_id_input' => $documentIdInput,
    'content_method' => $contentMethod,
    'document_body' => $documentBody
]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Update Document</title>
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
    .badge-soft-info{background:rgba(13,202,240,.12);color:#0dcaf0;}
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
<h1 class="page-title mb-2">Update Controlled Document</h1>
<p class="page-subtitle mb-0">Revise an existing controlled document with version control and change justification.</p>
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
<input type="hidden" name="status" value="<?php echo e($status); ?>">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
<span class="badge <?php echo $status === 'Under Review' ? 'badge-soft-info' : 'badge-soft-secondary'; ?>"><?php echo e($status); ?></span>
<div class="d-flex gap-2 flex-wrap">
<a class="btn btn-outline-secondary" href="update-document.php">Cancel</a>
<button class="btn btn-outline-primary" type="submit" name="action" value="save_draft">Save Draft</button>
<button class="btn btn-success" type="submit" name="action" value="submit_review">Submit for Review</button>
</div>
</div>

<div class="row g-3">
<div class="col-lg-8">
<div class="card cp-card mb-3"><div class="card-body">
<h2 class="card-title mb-1">Document Information</h2>
<p class="card-subtitle mb-3">Review current version data and enter controlled changes.</p>

<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Document ID</label>
<input class="form-control" name="document_id" value="<?php echo e($documentIdInput); ?>" placeholder="Enter existing document ID"/>
</div>

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
<input class="form-control readonly" name="version" readonly value="<?php echo e($nextVersion); ?>"/>
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

<div class="col-md-6">
<label class="form-label">Current Version</label>
<input class="form-control readonly" name="current_version" id="current_version" readonly value="<?php echo e($currentVersion); ?>"/>
</div>

<div class="col-md-6">
<label class="form-label">Next Version</label>
<input class="form-control readonly" name="next_version" id="next_version" readonly value="<?php echo e($nextVersion); ?>"/>
</div>

<div class="col-12">
<label class="form-label">Change Summary</label>
<textarea class="form-control" name="change_summary" placeholder="Mandatory description of what changed and why" rows="3"><?php echo e($changeSummary); ?></textarea>
<div class="form-text">Required for revision traceability and approval context.</div>
</div>

<div class="col-12">
<label class="form-label">Version Comparison</label>
<div class="kv p-3 small">
  <textarea class="form-control border-0 bg-transparent p-0 shadow-none" name="version_comparison" rows="3" style="resize:vertical;"><?php echo e($versionComparison); ?></textarea>
</div>
</div>
</div>
</div></div>

<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Document Content</h2>
<p class="card-subtitle mb-3">Add document content using rich text or controlled file upload.</p>

<input type="hidden" name="content_method" id="content_method" value="<?php echo e($contentMethod); ?>">

<ul class="nav nav-pills gap-2 mb-3">
<li class="nav-item"><a class="nav-link active tab-pill <?php echo $contentMethod === 'rich_text' ? 'active' : ''; ?>" href="#" id="tabRichText">Rich Text Editor</a></li>
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
  <?php if ($currentFileName !== ''): ?>
    <div class="mt-3 text-start">
      <strong>Current File:</strong> <?php echo e($currentFileName); ?>
      <?php if ($currentFilePath !== ''): ?>
        <div class="mt-2"><a href="<?php echo e($currentFilePath); ?>" target="_blank" class="btn btn-sm btn-outline-primary">View File</a></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
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
<li><?php echo $checks[1] ? 'Unique document ID validated.' : 'Document ID pending.'; ?></li>
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
    const currentVersionEl = document.getElementById('current_version');
    const nextVersionEl = document.getElementById('next_version');

    function nextVersionValue(v) {
        v = (v || '').trim();
        if (!v) return '01';
        if (/^\d+$/.test(v)) {
            const len = Math.max(2, v.length);
            return String(parseInt(v, 10) + 1).padStart(len, '0');
        }
        const m = v.match(/(\d+)$/);
        if (m) {
            const num = String(parseInt(m[1], 10) + 1).padStart(m[1].length, '0');
            return v.replace(/\d+$/, num);
        }
        return v;
    }

    function updateVersion() {
        if (currentVersionEl && nextVersionEl) {
            nextVersionEl.value = nextVersionValue(currentVersionEl.value);
        }
    }

    if (currentVersionEl) {
        currentVersionEl.addEventListener('input', updateVersion);
        currentVersionEl.addEventListener('change', updateVersion);
    }

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