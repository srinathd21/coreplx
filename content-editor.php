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

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? $_SESSION['full_name']
    : 'QA Admin';

$successMessage = '';
$errorMessage = '';

$documentId = trim($_GET['document_id'] ?? $_POST['document_id'] ?? '');
$contentMethod = trim($_POST['content_method'] ?? 'rich_text');
$documentBody = trim($_POST['document_body'] ?? '');
$currentFileName = '';
$currentFilePath = '';
$currentBody = '';

$uploadDir = __DIR__ . '/uploads/document-content/';
$uploadDirRelative = 'uploads/document-content/';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

$hasDocumentsTable = tableExists($conn, 'documents');
$hasDocumentContentTable = tableExists($conn, 'document_content');

if ($documentId !== '') {
    if ($hasDocumentContentTable) {
        $bodyCol = columnExists($conn, 'document_content', 'document_body') ? 'document_body' : (columnExists($conn, 'document_content', 'content') ? 'content' : '');
        $fileNameCol = columnExists($conn, 'document_content', 'file_name') ? 'file_name' : '';
        $filePathCol = columnExists($conn, 'document_content', 'file_path') ? 'file_path' : '';
        $docIdCol = columnExists($conn, 'document_content', 'document_id') ? 'document_id' : '';

        if ($docIdCol !== '') {
            $selectCols = [];
            $selectCols[] = $bodyCol !== '' ? "`{$bodyCol}` AS body_text" : "'' AS body_text";
            $selectCols[] = $fileNameCol !== '' ? "`{$fileNameCol}` AS existing_file_name" : "'' AS existing_file_name";
            $selectCols[] = $filePathCol !== '' ? "`{$filePathCol}` AS existing_file_path" : "'' AS existing_file_path";

            $sql = "SELECT " . implode(', ', $selectCols) . " FROM `document_content` WHERE `{$docIdCol}` = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $documentId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                if ($res && mysqli_num_rows($res) > 0) {
                    $row = mysqli_fetch_assoc($res);
                    $currentBody = (string)($row['body_text'] ?? '');
                    $currentFileName = (string)($row['existing_file_name'] ?? '');
                    $currentFilePath = (string)($row['existing_file_path'] ?? '');
                }
                mysqli_stmt_close($stmt);
            }
        }
    } elseif ($hasDocumentsTable) {
        $bodyCol = columnExists($conn, 'documents', 'document_body') ? 'document_body' : (columnExists($conn, 'documents', 'content') ? 'content' : '');
        $fileNameCol = columnExists($conn, 'documents', 'file_name') ? 'file_name' : '';
        $filePathCol = columnExists($conn, 'documents', 'file_path') ? 'file_path' : '';
        $docIdCol = columnExists($conn, 'documents', 'document_id') ? 'document_id' : '';

        if ($docIdCol !== '') {
            $selectCols = [];
            $selectCols[] = $bodyCol !== '' ? "`{$bodyCol}` AS body_text" : "'' AS body_text";
            $selectCols[] = $fileNameCol !== '' ? "`{$fileNameCol}` AS existing_file_name" : "'' AS existing_file_name";
            $selectCols[] = $filePathCol !== '' ? "`{$filePathCol}` AS existing_file_path" : "'' AS existing_file_path";

            $sql = "SELECT " . implode(', ', $selectCols) . " FROM `documents` WHERE `{$docIdCol}` = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $documentId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                if ($res && mysqli_num_rows($res) > 0) {
                    $row = mysqli_fetch_assoc($res);
                    $currentBody = (string)($row['body_text'] ?? '');
                    $currentFileName = (string)($row['existing_file_name'] ?? '');
                    $currentFilePath = (string)($row['existing_file_path'] ?? '');
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

if ($documentBody === '' && $currentBody !== '') {
    $documentBody = $currentBody;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($documentId === '') {
        $errorMessage = 'Document ID is required.';
    } else {
        $uploadedFileName = $currentFileName;
        $uploadedFilePath = $currentFilePath;

        if ($contentMethod === 'rich_text') {
            if ($documentBody === '') {
                $errorMessage = 'Please enter document body content.';
            } else {
                $uploadedFileName = '';
                $uploadedFilePath = '';
            }
        } elseif ($contentMethod === 'file_upload') {
            if (!isset($_FILES['content_file']) || !is_array($_FILES['content_file']) || (int)$_FILES['content_file']['error'] === 4) {
                if ($currentFilePath === '') {
                    $errorMessage = 'Please upload a file.';
                }
                $documentBody = '';
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
                    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                    if (!in_array($extension, $allowedExtensions, true)) {
                        $errorMessage = 'Only PDF, DOCX, or XLSX files are allowed.';
                    } elseif ($fileSize > $maxSize) {
                        $errorMessage = 'File size must be 25 MB or less.';
                    } else {
                        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
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
        } else {
            $errorMessage = 'Invalid content method selected.';
        }

        if ($errorMessage === '') {
            if ($hasDocumentContentTable) {
                $docIdCol = columnExists($conn, 'document_content', 'document_id') ? 'document_id' : '';
                $bodyCol = columnExists($conn, 'document_content', 'document_body') ? 'document_body' : (columnExists($conn, 'document_content', 'content') ? 'content' : '');
                $methodCol = columnExists($conn, 'document_content', 'content_method') ? 'content_method' : '';
                $fileNameCol = columnExists($conn, 'document_content', 'file_name') ? 'file_name' : '';
                $filePathCol = columnExists($conn, 'document_content', 'file_path') ? 'file_path' : '';
                $createdByCol = columnExists($conn, 'document_content', 'created_by') ? 'created_by' : '';
                $updatedByCol = columnExists($conn, 'document_content', 'updated_by') ? 'updated_by' : '';

                if ($docIdCol === '' || $bodyCol === '') {
                    $errorMessage = 'document_content table structure is incomplete.';
                } else {
                    $existsSql = "SELECT id FROM `document_content` WHERE `{$docIdCol}` = ? LIMIT 1";
                    $existsStmt = mysqli_prepare($conn, $existsSql);
                    if ($existsStmt) {
                        mysqli_stmt_bind_param($existsStmt, "s", $documentId);
                        mysqli_stmt_execute($existsStmt);
                        $existsRes = mysqli_stmt_get_result($existsStmt);
                        $existsRow = $existsRes ? mysqli_fetch_assoc($existsRes) : null;
                        mysqli_stmt_close($existsStmt);

                        if ($existsRow) {
                            $updateParts = [];
                            $updateParts[] = "`{$bodyCol}` = ?";
                            if ($methodCol !== '') $updateParts[] = "`{$methodCol}` = ?";
                            if ($fileNameCol !== '') $updateParts[] = "`{$fileNameCol}` = ?";
                            if ($filePathCol !== '') $updateParts[] = "`{$filePathCol}` = ?";
                            if ($updatedByCol !== '') $updateParts[] = "`{$updatedByCol}` = ?";

                            $sql = "UPDATE `document_content` SET " . implode(', ', $updateParts) . " WHERE `{$docIdCol}` = ?";
                            $stmt = mysqli_prepare($conn, $sql);

                            if ($stmt) {
                                if ($updatedByCol !== '') {
                                    mysqli_stmt_bind_param(
                                        $stmt,
                                        "ssssis",
                                        $documentBody,
                                        $contentMethod,
                                        $uploadedFileName,
                                        $uploadedFilePath,
                                        $currentUserId,
                                        $documentId
                                    );
                                } else {
                                    mysqli_stmt_bind_param(
                                        $stmt,
                                        "sssss",
                                        $documentBody,
                                        $contentMethod,
                                        $uploadedFileName,
                                        $uploadedFilePath,
                                        $documentId
                                    );
                                }

                                if (mysqli_stmt_execute($stmt)) {
                                    $successMessage = 'Content updated successfully.';
                                    $currentBody = $documentBody;
                                    $currentFileName = $uploadedFileName;
                                    $currentFilePath = $uploadedFilePath;
                                } else {
                                    $errorMessage = 'Failed to update content: ' . mysqli_error($conn);
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

                            $insertCols[] = "`{$docIdCol}`";
                            $insertVals[] = "?";
                            $bindValues[] = $documentId;
                            $bindTypes .= 's';

                            $insertCols[] = "`{$bodyCol}`";
                            $insertVals[] = "?";
                            $bindValues[] = $documentBody;
                            $bindTypes .= 's';

                            if ($methodCol !== '') {
                                $insertCols[] = "`{$methodCol}`";
                                $insertVals[] = "?";
                                $bindValues[] = $contentMethod;
                                $bindTypes .= 's';
                            }

                            if ($fileNameCol !== '') {
                                $insertCols[] = "`{$fileNameCol}`";
                                $insertVals[] = "?";
                                $bindValues[] = $uploadedFileName;
                                $bindTypes .= 's';
                            }

                            if ($filePathCol !== '') {
                                $insertCols[] = "`{$filePathCol}`";
                                $insertVals[] = "?";
                                $bindValues[] = $uploadedFilePath;
                                $bindTypes .= 's';
                            }

                            if ($createdByCol !== '') {
                                $insertCols[] = "`{$createdByCol}`";
                                $insertVals[] = "?";
                                $bindValues[] = $currentUserId;
                                $bindTypes .= 'i';
                            }

                            if ($updatedByCol !== '') {
                                $insertCols[] = "`{$updatedByCol}`";
                                $insertVals[] = "?";
                                $bindValues[] = $currentUserId;
                                $bindTypes .= 'i';
                            }

                            $sql = "INSERT INTO `document_content` (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
                            $stmt = mysqli_prepare($conn, $sql);

                            if ($stmt) {
                                mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
                                if (mysqli_stmt_execute($stmt)) {
                                    $successMessage = 'Content saved successfully.';
                                    $currentBody = $documentBody;
                                    $currentFileName = $uploadedFileName;
                                    $currentFilePath = $uploadedFilePath;
                                } else {
                                    $errorMessage = 'Failed to save content: ' . mysqli_error($conn);
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
            } elseif ($hasDocumentsTable) {
                $docIdCol = columnExists($conn, 'documents', 'document_id') ? 'document_id' : '';
                $bodyCol = columnExists($conn, 'documents', 'document_body') ? 'document_body' : (columnExists($conn, 'documents', 'content') ? 'content' : '');
                $methodCol = columnExists($conn, 'documents', 'content_method') ? 'content_method' : '';
                $fileNameCol = columnExists($conn, 'documents', 'file_name') ? 'file_name' : '';
                $filePathCol = columnExists($conn, 'documents', 'file_path') ? 'file_path' : '';
                $updatedByCol = columnExists($conn, 'documents', 'updated_by') ? 'updated_by' : '';

                if ($docIdCol === '' || $bodyCol === '') {
                    $errorMessage = 'documents table structure is incomplete.';
                } else {
                    $updateParts = [];
                    $updateParts[] = "`{$bodyCol}` = ?";
                    if ($methodCol !== '') $updateParts[] = "`{$methodCol}` = ?";
                    if ($fileNameCol !== '') $updateParts[] = "`{$fileNameCol}` = ?";
                    if ($filePathCol !== '') $updateParts[] = "`{$filePathCol}` = ?";
                    if ($updatedByCol !== '') $updateParts[] = "`{$updatedByCol}` = ?";

                    $sql = "UPDATE `documents` SET " . implode(', ', $updateParts) . " WHERE `{$docIdCol}` = ?";
                    $stmt = mysqli_prepare($conn, $sql);

                    if ($stmt) {
                        if ($updatedByCol !== '') {
                            mysqli_stmt_bind_param(
                                $stmt,
                                "ssssis",
                                $documentBody,
                                $contentMethod,
                                $uploadedFileName,
                                $uploadedFilePath,
                                $currentUserId,
                                $documentId
                            );
                        } else {
                            mysqli_stmt_bind_param(
                                $stmt,
                                "sssss",
                                $documentBody,
                                $contentMethod,
                                $uploadedFileName,
                                $uploadedFilePath,
                                $documentId
                            );
                        }

                        if (mysqli_stmt_execute($stmt)) {
                            if (mysqli_stmt_affected_rows($stmt) >= 0) {
                                $successMessage = 'Content updated successfully.';
                                $currentBody = $documentBody;
                                $currentFileName = $uploadedFileName;
                                $currentFilePath = $uploadedFilePath;
                            } else {
                                $errorMessage = 'No document found for update.';
                            }
                        } else {
                            $errorMessage = 'Failed to update content: ' . mysqli_error($conn);
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $errorMessage = 'Failed to prepare documents update query.';
                    }
                }
            } else {
                $errorMessage = 'No supported table found. Create document_content or documents table first.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Content Editor</title>
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
    .card-subtitle,
    .form-text {
      color: #6c757d;
    }
    .upload-box {
      min-height: 250px;
      border: 1px dashed rgba(0,0,0,.15);
      border-radius: 16px;
      background: #f8f9fa;
    }
    .nav-tabs .nav-link {
      cursor: pointer;
    }
    .tab-pane-custom {
      display: none;
    }
    .tab-pane-custom.active {
      display: block;
    }
    .current-file-box {
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 12px;
      background: #f8f9fa;
      padding: .75rem;
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
        <span class="navbar-text small"><?php echo e($currentUserName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small">Profile</span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
  <div class="content-wrap px-4 py-4 mx-auto">
    <div class="mb-4">
      <h1 class="page-title mb-2">Document Content</h1>
      <p class="page-subtitle mb-0">Add document content using rich text or controlled file upload.</p>
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

    <div class="card cp-card">
      <div class="card-body">
        <h2 class="card-title mb-1">Rich Text / File Upload</h2>
        <p class="card-subtitle mb-3">Only one content method can be active at a time.</p>

        <form method="post" enctype="multipart/form-data" id="contentEditorForm">
          <input type="hidden" name="content_method" id="content_method" value="<?php echo e($contentMethod); ?>">

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Document ID</label>
              <input type="text" name="document_id" class="form-control" value="<?php echo e($documentId); ?>" placeholder="Enter document ID">
            </div>
          </div>

          <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
              <a class="nav-link <?php echo $contentMethod === 'rich_text' ? 'active' : ''; ?>" href="#" id="tabRichText">Rich Text</a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?php echo $contentMethod === 'file_upload' ? '' : ''; ?><?php echo $contentMethod === 'file_upload' ? ' active' : ''; ?>" href="#" id="tabFileUpload">File Upload</a>
            </li>
          </ul>

          <div class="row g-3">
            <div class="col-lg-8">
              <div class="tab-pane-custom <?php echo $contentMethod === 'rich_text' ? 'active' : ''; ?>" id="paneRichText">
                <label class="form-label">Document Body</label>
                <textarea class="form-control" name="document_body" id="document_body" placeholder="Enter controlled content" rows="14"><?php echo e($contentMethod === 'rich_text' ? $documentBody : $currentBody); ?></textarea>
                <div class="form-text">Formatting should remain version-controlled after submit.</div>
              </div>

              <div class="tab-pane-custom <?php echo $contentMethod === 'file_upload' ? 'active' : ''; ?>" id="paneFileUpload">
                <label class="form-label">Upload Controlled File</label>
                <input type="file" class="form-control" name="content_file" id="content_file" accept=".pdf,.docx,.xlsx">
                <div class="form-text">Allowed files: PDF, DOCX, XLSX. Maximum size: 25 MB.</div>

                <?php if ($currentFileName !== ''): ?>
                  <div class="current-file-box mt-3">
                    <div class="small text-secondary mb-1">Current File</div>
                    <div class="fw-semibold"><?php echo e($currentFileName); ?></div>
                    <?php if ($currentFilePath !== ''): ?>
                      <div class="mt-2">
                        <a href="<?php echo e($currentFilePath); ?>" target="_blank" class="btn btn-sm btn-outline-primary">View File</a>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-lg-4">
              <div class="upload-box p-4 text-center h-100 d-flex align-items-center justify-content-center">
                <div class="small text-secondary">
                  Upload PDF, DOCX, or XLSX files up to 25 MB.
                  <br><br>
                  Switching tabs clears the alternate input to keep the methods mutually exclusive.
                </div>
              </div>
            </div>
          </div>

          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save Content</button>
            <a href="content-editor.php<?php echo $documentId !== '' ? '?document_id=' . urlencode($documentId) : ''; ?>" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabRichText = document.getElementById('tabRichText');
    const tabFileUpload = document.getElementById('tabFileUpload');
    const paneRichText = document.getElementById('paneRichText');
    const paneFileUpload = document.getElementById('paneFileUpload');
    const contentMethod = document.getElementById('content_method');
    const documentBody = document.getElementById('document_body');
    const contentFile = document.getElementById('content_file');

    function activateRichText(e) {
        if (e) e.preventDefault();

        tabRichText.classList.add('active');
        tabFileUpload.classList.remove('active');

        paneRichText.classList.add('active');
        paneFileUpload.classList.remove('active');

        contentMethod.value = 'rich_text';

        if (contentFile) {
            contentFile.value = '';
        }
    }

    function activateFileUpload(e) {
        if (e) e.preventDefault();

        tabFileUpload.classList.add('active');
        tabRichText.classList.remove('active');

        paneFileUpload.classList.add('active');
        paneRichText.classList.remove('active');

        contentMethod.value = 'file_upload';

        if (documentBody) {
            documentBody.value = '';
        }
    }

    if (tabRichText) {
        tabRichText.addEventListener('click', activateRichText);
    }

    if (tabFileUpload) {
        tabFileUpload.addEventListener('click', activateFileUpload);
    }
});
</script>
</body>
</html>