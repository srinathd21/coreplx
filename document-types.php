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

$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? $_SESSION['full_name']
    : 'QA Admin';

$documentTypes = [];
$hasDocumentTypesTable = tableExists($conn, 'document_types');

if ($hasDocumentTypesTable) {
    $nameCol = null;
    $descriptionCol = null;
    $statusCol = null;

    $possibleNameCols = ['type_name', 'name', 'document_type', 'title'];
    foreach ($possibleNameCols as $col) {
        if (columnExists($conn, 'document_types', $col)) {
            $nameCol = $col;
            break;
        }
    }

    $possibleDescriptionCols = ['description', 'type_description', 'details', 'summary'];
    foreach ($possibleDescriptionCols as $col) {
        if (columnExists($conn, 'document_types', $col)) {
            $descriptionCol = $col;
            break;
        }
    }

    $possibleStatusCols = ['status', 'is_active'];
    foreach ($possibleStatusCols as $col) {
        if (columnExists($conn, 'document_types', $col)) {
            $statusCol = $col;
            break;
        }
    }

    if ($nameCol !== null) {
        $selectParts = [];
        $selectParts[] = "`{$nameCol}` AS type_name";

        if ($descriptionCol !== null) {
            $selectParts[] = "`{$descriptionCol}` AS description";
        } else {
            $selectParts[] = "'' AS description";
        }

        if ($statusCol !== null) {
            $selectParts[] = "`{$statusCol}` AS row_status";
        } else {
            $selectParts[] = "'active' AS row_status";
        }

        $sql = "SELECT " . implode(', ', $selectParts) . " FROM `document_types`";

        if ($statusCol === 'is_active') {
            $sql .= " WHERE `is_active` = 1";
        } elseif ($statusCol === 'status') {
            $sql .= " WHERE (`status` = 'active' OR `status` = 'Active' OR `status` = 1)";
        }

        $sql .= " ORDER BY `{$nameCol}` ASC";

        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $documentTypes[] = [
                    'type_name' => $row['type_name'] ?? '',
                    'description' => $row['description'] ?? '',
                    'row_status' => $row['row_status'] ?? 'active'
                ];
            }
        }
    }
}

if (empty($documentTypes)) {
    $documentTypes = [
        [
            'type_name' => 'SOP',
            'description' => 'Controlled operational procedures used for standardized process execution.'
        ],
        [
            'type_name' => 'Policy',
            'description' => 'Governance and business rules that define required compliance expectations.'
        ],
        [
            'type_name' => 'Guidance',
            'description' => 'Supporting instructions, interpretation, and explanatory controlled content.'
        ],
        [
            'type_name' => 'Forms',
            'description' => 'Controlled forms used to capture data, sign-off, or quality evidence.'
        ]
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Document Types</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .badge-soft-success {
      background: rgba(25,135,84,.12);
      color: #198754;
    }
    .badge-soft-info {
      background: rgba(13,202,240,.12);
      color: #0dcaf0;
    }
    .badge-dark-soft {
      background: rgba(33,37,41,.12);
      color: #212529;
    }
    .badge {
      padding: .45rem .7rem;
      border-radius: 999px;
      font-weight: 600;
      margin-right: .25rem;
    }
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
      <h1 class="page-title mb-2">Controlled Document Types</h1>
      <p class="page-subtitle mb-0">Manage standardized document categories used across the quality system.</p>
    </div>

    <div class="row g-3">
      <?php foreach ($documentTypes as $type): ?>
        <div class="col-md-6 col-xl-4">
          <div class="card cp-card h-100">
            <div class="card-body">
              <h2 class="card-title mb-1"><?php echo e($type['type_name']); ?></h2>
              <p class="card-subtitle mb-3">
                <?php
                  $desc = trim((string)($type['description'] ?? ''));
                  echo e($desc !== '' ? $desc : 'Controlled document category used across the quality system.');
                ?>
              </p>
              <span class="badge badge-soft-success">Create</span>
              <span class="badge badge-soft-info">Update</span>
              <span class="badge badge-dark-soft">Retire</span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="col-md-6 col-xl-4">
        <div class="card cp-card h-100">
          <div class="card-body">
            <h2 class="card-title mb-1">Control Rules</h2>
            <p class="card-subtitle mb-3">Allowed actions are governed by status, role, and approval state.</p>
            <ul class="small text-secondary mb-0">
              <li>No direct delete for controlled records.</li>
              <li>Retirement requires approval.</li>
              <li>Repository stays read-only.</li>
            </ul>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-xl-4">
        <div class="card cp-card h-100">
          <div class="card-body">
            <h2 class="card-title mb-1">Developer Note</h2>
            <p class="card-subtitle mb-3">Keep one shared component for type selection across create and update screens.</p>
            <div class="small text-secondary">
              Use the same labels, descriptions, validation, and status logic across all modules.
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>