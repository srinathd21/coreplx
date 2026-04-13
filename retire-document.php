<?php
session_start();

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$currentUserName = isset($_SESSION['full_name']) && trim((string)$_SESSION['full_name']) !== ''
    ? trim((string)$_SESSION['full_name'])
    : 'QA Admin';

$replacementDocuments = [
    'Select replacement if applicable'
];

$approvers = [
    'Select Approver'
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Retire Document</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .cp-card{
      border:1px solid rgba(0,0,0,.08);
      border-radius:18px;
      box-shadow:0 6px 24px rgba(0,0,0,.06);
      background:#fff;
    }
    .page-title{
      font-size:27px;
      line-height:1.2;
      font-weight:700;
      color:#173f7a;
      margin:0 0 12px 0;
    }
    .page-subtitle{
      font-size:18px;
      line-height:1.55;
      color:#5c6f8e;
      margin:0;
      font-weight:400;
    }
    .card-title{
      font-size:21px;
      line-height:1.3;
      font-weight:700;
      color:#173f7a;
      margin:0 0 4px 0;
    }
    .card-subtitle,
    .form-text{
      color:#5f708c;
      font-size:15px;
      line-height:1.55;
    }
    .form-label{
      font-size:16px;
      font-weight:600;
      color:#4c5b73;
      margin-bottom:10px;
    }
    .readonly{
      background-color:#f8f9fa;
    }
    .note-list{
      padding-left:1rem;
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
    <h1 class="page-title mb-2">Retire Controlled Document</h1>
    <p class="page-subtitle mb-0">Submit a controlled document for retirement with reason, review, and approval.</p>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card cp-card">
        <div class="card-body">
          <h2 class="card-title mb-1">Retire Request</h2>
          <p class="card-subtitle mb-3">Retirement requires approval and must not be immediate.</p>

          <form>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Document ID</label>
                <input class="form-control readonly" readonly value="">
              </div>

              <div class="col-md-6">
                <label class="form-label">Current Status</label>
                <input class="form-control readonly" readonly value="">
              </div>

              <div class="col-md-6">
                <label class="form-label">Owner</label>
                <input class="form-control readonly" readonly value="">
              </div>

              <div class="col-md-6">
                <label class="form-label">Requested By</label>
                <input class="form-control readonly" readonly value="">
              </div>

              <div class="col-12">
                <label class="form-label">Retirement Reason</label>
                <textarea class="form-control" placeholder="Minimum 20 characters required" rows="4"></textarea>
                <div class="form-text">Retirement reason is mandatory and fully auditable.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Replacement Document</label>
                <select class="form-select">
                  <?php foreach ($replacementDocuments as $doc): ?>
                    <option value="<?php echo e($doc); ?>"><?php echo e($doc); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Approver</label>
                <select class="form-select">
                  <?php foreach ($approvers as $item): ?>
                    <option value="<?php echo e($item); ?>"><?php echo e($item); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="d-flex gap-2 mt-4">
              <button type="button" class="btn btn-outline-secondary">Cancel</button>
              <button type="button" class="btn btn-danger">Submit Retirement Request</button>
            </div>
          </form>

        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card cp-card">
        <div class="card-body">
          <h2 class="card-title mb-1">Control Notes</h2>
          <p class="card-subtitle mb-3">Retired records remain traceable.</p>
          <ul class="small text-secondary note-list mb-0">
            <li>Status should move to Pending Retirement until approved.</li>
            <li>Repository history must still show prior effective versions.</li>
            <li>Delete should never be available for controlled records.</li>
            <li>Approval, denial, and comment actions must be fully logged.</li>
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