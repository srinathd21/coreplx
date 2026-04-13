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

if (!function_exists('normalize_topic')) {
    function normalize_topic($topic) {
        $topic = strtoupper(trim((string)$topic));
        $topic = preg_replace('/[^A-Z0-9]+/', '-', $topic);
        $topic = preg_replace('/-+/', '-', $topic);
        return trim($topic, '-');
    }
}

$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? $_SESSION['full_name']
    : 'QA Admin';

$documentTypes = [
    ['label' => 'SOP', 'code' => 'SOP'],
    ['label' => 'POL', 'code' => 'POL'],
    ['label' => 'GUI', 'code' => 'GUI'],
    ['label' => 'FRM', 'code' => 'FRM'],
];

if (tableExists($conn, 'document_types')) {
    $nameCol = null;

    foreach (['type_name', 'name', 'document_type', 'title'] as $col) {
        if (columnExists($conn, 'document_types', $col)) {
            $nameCol = $col;
            break;
        }
    }

    if ($nameCol !== null) {
        $documentTypes = [];
        $sql = "SELECT `{$nameCol}` AS type_name FROM `document_types` ORDER BY `{$nameCol}` ASC";
        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $typeName = trim((string)($row['type_name'] ?? ''));
                if ($typeName !== '') {
                    $code = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $typeName), 0, 3));
                    if ($code === '') {
                        $code = strtoupper($typeName);
                    }
                    $documentTypes[] = [
                        'label' => $typeName,
                        'code'  => $code
                    ];
                }
            }
        }

        if (empty($documentTypes)) {
            $documentTypes = [
                ['label' => 'SOP', 'code' => 'SOP'],
                ['label' => 'POL', 'code' => 'POL'],
                ['label' => 'GUI', 'code' => 'GUI'],
                ['label' => 'FRM', 'code' => 'FRM'],
            ];
        }
    }
}

$selectedType = $_GET['type'] ?? 'SOP';
$selectedNumber = trim($_GET['number'] ?? '104');
$selectedTopic = trim($_GET['topic'] ?? 'CAPA');
$selectedVersion = trim($_GET['version'] ?? '01');

$availableCodes = array_column($documentTypes, 'code');
if (!in_array($selectedType, $availableCodes, true)) {
    $selectedType = $availableCodes[0] ?? 'SOP';
}

$selectedNumber = preg_replace('/[^0-9]/', '', $selectedNumber);
if ($selectedNumber === '') {
    $selectedNumber = '104';
}

$selectedTopic = normalize_topic($selectedTopic);
if ($selectedTopic === '') {
    $selectedTopic = 'CAPA';
}

$selectedVersion = preg_replace('/[^0-9]/', '', $selectedVersion);
if ($selectedVersion === '') {
    $selectedVersion = '01';
}
$selectedVersion = str_pad(substr($selectedVersion, 0, 2), 2, '0', STR_PAD_LEFT);

$previewId = $selectedType . '-' . $selectedNumber . '-' . $selectedTopic . '-' . $selectedVersion;

$isDuplicate = false;
$duplicateFoundIn = '';

$possibleTables = [
    'documents',
    'document_repository',
    'document_master',
    'document_register',
    'controlled_documents',
    'repository'
];

foreach ($possibleTables as $table) {
    if (!tableExists($conn, $table)) {
        continue;
    }

    $docIdCol = null;
    foreach (['document_id', 'doc_id', 'document_code', 'doc_no', 'document_number', 'reference_no'] as $col) {
        if (columnExists($conn, $table, $col)) {
            $docIdCol = $col;
            break;
        }
    }

    if ($docIdCol === null) {
        continue;
    }

    $sql = "SELECT `{$docIdCol}` FROM `{$table}` WHERE `{$docIdCol}` = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $previewId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && mysqli_num_rows($res) > 0) {
            $isDuplicate = true;
            $duplicateFoundIn = $table;
            mysqli_stmt_close($stmt);
            break;
        }
        mysqli_stmt_close($stmt);
    }
}

$badgeClass = $isDuplicate ? 'badge-soft-danger' : 'badge-soft-success';
$badgeText  = $isDuplicate ? 'Duplicate Found' : 'No Duplicate Found';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Document ID</title>
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
    .badge-soft-success {
      background: rgba(25,135,84,.12);
      color: #198754;
    }
    .badge-soft-danger {
      background: rgba(220,53,69,.12);
      color: #dc3545;
    }
    .kv {
      border-radius: 12px;
      background: rgba(13,110,253,.05);
      border: 1px solid rgba(13,110,253,.08);
    }
    .note-list {
      padding-left: 1rem;
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
      <h1 class="page-title mb-2">Document ID Format &amp; Validation</h1>
      <p class="page-subtitle mb-0">Generate and validate unique document identifiers using the approved naming structure.</p>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">ID Generator</h2>
            <p class="card-subtitle mb-3">Live preview with duplicate validation.</p>

            <form method="get" id="idGeneratorForm">
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label">Type</label>
                  <select class="form-select" name="type" id="doc_type">
                    <?php foreach ($documentTypes as $type): ?>
                      <option value="<?php echo e($type['code']); ?>" <?php echo $selectedType === $type['code'] ? 'selected' : ''; ?>>
                        <?php echo e($type['code']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Number</label>
                  <input class="form-control" name="number" id="doc_number" value="<?php echo e($selectedNumber); ?>">
                </div>

                <div class="col-md-3">
                  <label class="form-label">Topic</label>
                  <input class="form-control" name="topic" id="doc_topic" value="<?php echo e($selectedTopic); ?>">
                </div>

                <div class="col-md-3">
                  <label class="form-label">Version</label>
                  <input class="form-control" name="version" id="doc_version" value="<?php echo e($selectedVersion); ?>">
                </div>
              </div>

              <div class="mt-3">
                <label class="form-label">Preview</label>
                <div class="kv p-3 fw-semibold text-primary" id="doc_preview"><?php echo e($previewId); ?></div>
              </div>

              <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                <span class="badge <?php echo e($badgeClass); ?>" id="duplicate_status"><?php echo e($badgeText); ?></span>
                <?php if ($isDuplicate && $duplicateFoundIn !== ''): ?>
                  <span class="small text-danger">Found in table: <?php echo e($duplicateFoundIn); ?></span>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Validation Rules</h2>
            <p class="card-subtitle mb-3">Recommended checks for an audit-grade identifier.</p>
            <ul class="small text-secondary note-list mb-0">
              <li>Type segment must be system-driven.</li>
              <li>Version must auto-increment during update.</li>
              <li>Topic should use approved naming convention.</li>
              <li>Duplicate prevention should check current and retired records.</li>
              <li>ID format must be locked after document approval.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('idGeneratorForm');
    const typeEl = document.getElementById('doc_type');
    const numberEl = document.getElementById('doc_number');
    const topicEl = document.getElementById('doc_topic');
    const versionEl = document.getElementById('doc_version');
    const previewEl = document.getElementById('doc_preview');

    function normalizeTopic(value) {
        return String(value || '')
            .toUpperCase()
            .trim()
            .replace(/[^A-Z0-9]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }

    function updatePreview() {
        const type = (typeEl.value || '').trim();
        const number = (numberEl.value || '').replace(/[^0-9]/g, '');
        const topic = normalizeTopic(topicEl.value || '');
        let version = (versionEl.value || '').replace(/[^0-9]/g, '');

        if (version.length === 1) {
            version = '0' + version;
        }
        if (version.length === 0) {
            version = '01';
        }
        version = version.substring(0, 2);

        previewEl.textContent = [type, number, topic, version].filter(Boolean).join('-');
    }

    [typeEl, numberEl, topicEl, versionEl].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', updatePreview);
        el.addEventListener('change', updatePreview);
    });

    updatePreview();

    [typeEl, numberEl, topicEl, versionEl].forEach(function (el) {
        if (!el) return;
        el.addEventListener('change', function () {
            form.submit();
        });
    });
});
</script>
</body>
</html>