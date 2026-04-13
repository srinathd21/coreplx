<?php
session_start();

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$roleName = $_SESSION['role_name'] ?? 'QA Admin';
$displayName = $_SESSION['admin_name'] ?? ($_SESSION['full_name'] ?? 'Profile');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Update Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .readonly{background-color:#f8f9fa;}
    .app-shell{min-height:calc(100vh - 72px);}
    .content-wrap{max-width:1400px;}
    .page-title{font-size:1.75rem;font-weight:700;color:#0D2144;}
    .page-subtitle{color:#6c757d;}
    .cp-card{border:1px solid #e9ecef;border-radius:16px;box-shadow:0 6px 18px rgba(15,23,42,.05);}
    .card-title{font-size:1.1rem;font-weight:700;color:#0D2144;}
    .card-subtitle{color:#6c757d;font-size:.95rem;}
    .tab-pill{border-radius:999px;padding:.55rem 1rem;}
    .upload-box{border:1.5px dashed #cfd6e4;border-radius:14px;background:#fbfcfe;}
    .kv{background:#f8fbff;border:1px solid #d8e6ff;border-radius:12px;}
    .note-list{padding-left:1rem;}
    .badge-soft-info{
      background:#e7f1ff;
      color:#0b5ed7;
      border:1px solid #cfe2ff;
      padding:.5rem .75rem;
      border-radius:999px;
      font-weight:600;
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
        <span class="navbar-text small"><?php echo e($roleName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small"><?php echo e($displayName); ?></span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
  <div class="content-wrap px-4 py-4 mx-auto">
    <div class="mb-4">
      <h1 class="page-title mb-2">Update Controlled Document</h1>
      <p class="page-subtitle mb-0">Revise an existing controlled document with version control and change justification.</p>
    </div>

    <form method="post" enctype="multipart/form-data">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <span class="badge badge-soft-info">Under Review</span>
        <div class="d-flex gap-2 flex-wrap">
          <a class="btn btn-outline-secondary" href="dashboard-admin.php">Cancel</a>
          <button type="button" class="btn btn-outline-primary">Save Draft</button>
          <button type="button" class="btn btn-success">Submit for Review</button>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-8">
          <div class="card cp-card mb-3">
            <div class="card-body">
              <h2 class="card-title mb-1">Document Information</h2>
              <p class="card-subtitle mb-3">Review current version data and enter controlled changes.</p>

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Document Type</label>
                  <select class="form-select" name="document_type_id">
                    <option value="">Select document type</option>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Document Topic</label>
                  <input class="form-control" type="text" name="title" value="">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Document Number</label>
                  <input class="form-control" type="text" name="document_number" value="">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Version</label>
                  <input class="form-control readonly" type="text" name="version_label" readonly value="">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Owner</label>
                  <select class="form-select" name="owner_user_id">
                    <option value="">Select owner</option>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Approver</label>
                  <select class="form-select" name="approver_user_id">
                    <option value="">Select approver</option>
                  </select>
                  <div class="form-text">Creator cannot select themselves as approver.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Effective Date</label>
                  <input class="form-control" type="date" name="effective_date" value="">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Review Date</label>
                  <input class="form-control" type="date" name="review_date" value="">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Current Version</label>
                  <input class="form-control readonly" type="text" name="current_version" readonly value="">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Next Version</label>
                  <input class="form-control readonly" type="text" name="next_version" readonly value="">
                </div>

                <div class="col-12">
                  <label class="form-label">Change Summary</label>
                  <textarea class="form-control" name="change_summary" placeholder="Mandatory description of what changed and why" rows="3"></textarea>
                  <div class="form-text">Required for revision traceability and approval context.</div>
                </div>

                <div class="col-12">
                  <label class="form-label">Version Comparison</label>
                  <div class="kv p-3 small"></div>
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
                <textarea class="form-control" name="content_text" placeholder="Enter document content here" rows="9"></textarea>
              </div>

              <div class="mb-3">
                <input type="file" name="primary_file" class="form-control">
              </div>

              <div class="upload-box p-4 text-center small text-secondary">
                Drag and drop file here or click to browse.<br>Supported: PDF, DOCX, XLSX | Maximum size: 25 MB
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
</body>
</html>