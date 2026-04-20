<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatDateDisplay')) {
    function formatDateDisplay($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$date);
        return $ts ? date('d M Y', $ts) : e((string)$date);
    }
}

if (!function_exists('isOverdueDate')) {
    function isOverdueDate($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return false;
        }
        $reviewTs = strtotime((string)$date);
        if (!$reviewTs) {
            return false;
        }
        $today = strtotime(date('Y-m-d'));
        return $reviewTs < $today;
    }
}

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass($status, $reviewDate = '') {
        $status = strtolower(trim((string)$status));

        if ($status === 'deleted') return 'badge badge-soft-danger';
        if ($status === 'retired') return 'badge badge-soft-secondary';
        if ($status === 'pending_retirement') return 'badge badge-soft-warning';
        if ($status === 'pending_approval') return 'badge badge-soft-warning';
        if ($status === 'draft') return 'badge badge-soft-secondary';

        if (isOverdueDate($reviewDate)) {
            return 'badge badge-soft-danger';
        }

        return 'badge badge-soft-success';
    }
}

if (!function_exists('displayStatusLabel')) {
    function displayStatusLabel($status, $reviewDate = '') {
        $status = strtolower(trim((string)$status));

        if ($status === 'deleted') return 'Deleted';
        if ($status === 'retired') return 'Retired';
        if ($status === 'pending_retirement') return 'Pending Retirement';
        if ($status === 'pending_approval') return 'Pending Approval';
        if ($status === 'draft') return 'Draft';

        if (isOverdueDate($reviewDate)) {
            return 'Overdue Review';
        }

        return 'Effective';
    }
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login-admin.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

$currentUser = null;
$userSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
";
$userStmt = mysqli_prepare($conn, $userSql);
if ($userStmt) {
    mysqli_stmt_bind_param($userStmt, "i", $userId);
    mysqli_stmt_execute($userStmt);
    $userRes = mysqli_stmt_get_result($userStmt);
    $currentUser = ($userRes && mysqli_num_rows($userRes) > 0) ? mysqli_fetch_assoc($userRes) : null;
    mysqli_stmt_close($userStmt);
}

$displayName = 'QA Admin';
$roleName = 'Profile';
if ($currentUser) {
    $displayName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
    if ($displayName === '') $displayName = 'QA Admin';
    $roleName = trim((string)($currentUser['role_name'] ?? 'Profile'));
}

$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$deptFilter = trim((string)($_GET['department'] ?? ''));
$reviewFilter = trim((string)($_GET['review'] ?? ''));
$viewId = (int)($_GET['view_id'] ?? 0);

$types = [];
$typeRes = mysqli_query($conn, "SELECT DISTINCT type_name FROM document_types WHERE status='active' ORDER BY type_name ASC");
if ($typeRes) {
    while ($row = mysqli_fetch_assoc($typeRes)) {
        $types[] = $row['type_name'];
    }
}

$departments = [];
$deptRes = mysqli_query($conn, "SELECT DISTINCT department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC");
if ($deptRes) {
    while ($row = mysqli_fetch_assoc($deptRes)) {
        $departments[] = $row['department_name'];
    }
}

$viewDocument = null;
if ($viewId > 0) {
    $viewSql = "
        SELECT
            d.id,
            d.document_number,
            d.title,
            d.topic,
            d.current_status,
            dt.type_name,
            dept.department_name,
            dv.version_label,
            dv.effective_date,
            dv.review_date,
            dv.content_text,
            dv.primary_file_name,
            dv.primary_file_path,
            CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,'')) AS owner_name
        FROM documents d
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        LEFT JOIN departments dept ON dept.id = d.department_id
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        LEFT JOIN users owner ON owner.id = d.owner_user_id
        WHERE d.id = ?
        LIMIT 1
    ";
    $viewStmt = mysqli_prepare($conn, $viewSql);
    if ($viewStmt) {
        mysqli_stmt_bind_param($viewStmt, "i", $viewId);
        mysqli_stmt_execute($viewStmt);
        $viewRes = mysqli_stmt_get_result($viewStmt);
        $viewDocument = ($viewRes && mysqli_num_rows($viewRes) > 0) ? mysqli_fetch_assoc($viewRes) : null;
        mysqli_stmt_close($viewStmt);
    }
}

$sql = "
    SELECT
        d.id,
        d.document_number,
        d.title,
        d.topic,
        d.current_status,
        dt.type_name,
        dept.department_name,
        dv.version_label,
        dv.effective_date,
        dv.review_date,
        dv.primary_file_name,
        dv.primary_file_path,
        CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,'')) AS owner_name
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    LEFT JOIN departments dept ON dept.id = d.department_id
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    LEFT JOIN users owner ON owner.id = d.owner_user_id
    WHERE LOWER(COALESCE(d.current_status, 'effective')) <> 'deleted'
";

$params = [];
$bindTypes = '';

if ($search !== '') {
    $sql .= " AND (
        d.document_number LIKE ?
        OR d.title LIKE ?
        OR d.topic LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $bindTypes .= 'sss';
}

if ($typeFilter !== '') {
    $sql .= " AND dt.type_name = ?";
    $params[] = $typeFilter;
    $bindTypes .= 's';
}

if ($deptFilter !== '') {
    $sql .= " AND dept.department_name = ?";
    $params[] = $deptFilter;
    $bindTypes .= 's';
}

if ($reviewFilter === 'overdue') {
    $sql .= " AND dv.review_date IS NOT NULL AND dv.review_date <> '0000-00-00' AND dv.review_date < CURDATE()";
} elseif ($reviewFilter === 'current') {
    $sql .= " AND (
        dv.review_date IS NULL
        OR dv.review_date = '0000-00-00'
        OR dv.review_date >= CURDATE()
    )";
}

$sql .= " ORDER BY d.id DESC LIMIT 500";

$rows = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $bindTypes, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

$totalEffective = 0;
$overdueReview = 0;
$addedThisMonth = 0;
$pendingRetirement = 0;

$summarySql = "
    SELECT
        d.id,
        d.current_status,
        d.created_at,
        dv.effective_date,
        dv.review_date
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE LOWER(COALESCE(d.current_status, 'effective')) <> 'deleted'
";
$summaryRes = mysqli_query($conn, $summarySql);
if ($summaryRes) {
    $monthStart = date('Y-m-01 00:00:00');
    $monthEnd = date('Y-m-t 23:59:59');

    while ($row = mysqli_fetch_assoc($summaryRes)) {
        $status = strtolower((string)($row['current_status'] ?? 'effective'));

        if (in_array($status, ['effective', 'approved'], true) || $status === '') {
            $totalEffective++;
        }

        if (isOverdueDate($row['review_date'] ?? '')) {
            $overdueReview++;
        }

        if (!empty($row['created_at']) && $row['created_at'] >= $monthStart && $row['created_at'] <= $monthEnd) {
            $addedThisMonth++;
        }

        if ($status === 'pending_retirement') {
            $pendingRetirement++;
        }
    }
}

$filteredCount = count($rows);
$totalRepoCount = 0;
$totalCountSql = "SELECT COUNT(*) AS cnt FROM documents WHERE LOWER(COALESCE(current_status,'effective')) <> 'deleted'";
$totalCountRes = mysqli_query($conn, $totalCountSql);
if ($totalCountRes && ($cntRow = mysqli_fetch_assoc($totalCountRes))) {
    $totalRepoCount = (int)$cntRow['cnt'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Repository</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .view-card pre {
      white-space: pre-wrap;
      word-wrap: break-word;
      margin: 0;
      font-family: inherit;
      font-size: 14px;
    }
    .table td, .table th {
      vertical-align: middle;
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item"><a class="nav-link" href="dashboard-admin.php">Dashboard</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item active" href="repository.php">Repository</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-trail.php">Audit Trail</a></li>
            <li><a class="dropdown-item" href="document-assignment.php">Document Assignment</a></li>
            <li><a class="dropdown-item" href="user-management.php">User Management</a></li>
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
    <h1 class="page-title mb-2">Effective Documents Repository</h1>
    <p class="page-subtitle mb-0">Organisation-wide library of all approved and effective controlled documents. Read-only.</p>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Total Effective</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$totalEffective; ?></div>
          </div>
          <span style="font-size:1.8rem;">📋</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Overdue Review</div>
            <div class="stat-value" style="font-size:1.6rem;color:#dc2626;"><?php echo (int)$overdueReview; ?></div>
          </div>
          <span style="font-size:1.8rem;">⚠️</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Added This Month</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$addedThisMonth; ?></div>
          </div>
          <span style="font-size:1.8rem;">✅</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Pending Retirement</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$pendingRetirement; ?></div>
          </div>
          <span style="font-size:1.8rem;">🗄️</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card cp-card mb-3">
    <div class="card-body py-3">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-end">
        <div>
          <label class="form-label mb-1">Search</label>
          <input type="text" class="form-control form-control-sm" name="search" value="<?php echo e($search); ?>" placeholder="Search ID or title..." style="max-width:220px;">
        </div>

        <div>
          <label class="form-label mb-1">Type</label>
          <select class="form-select form-select-sm" name="type" style="max-width:140px;">
            <option value="">All Types</option>
            <?php foreach ($types as $type): ?>
              <option value="<?php echo e($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                <?php echo e($type); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="form-label mb-1">Department</label>
          <select class="form-select form-select-sm" name="department" style="max-width:180px;">
            <option value="">All Departments</option>
            <?php foreach ($departments as $department): ?>
              <option value="<?php echo e($department); ?>" <?php echo $deptFilter === $department ? 'selected' : ''; ?>>
                <?php echo e($department); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="form-label mb-1">Review Status</label>
          <select class="form-select form-select-sm" name="review" style="max-width:160px;">
            <option value="">All</option>
            <option value="overdue" <?php echo $reviewFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
            <option value="current" <?php echo $reviewFilter === 'current' ? 'selected' : ''; ?>>Current</option>
          </select>
        </div>

        <div class="d-flex gap-2 ms-auto">
          <a class="btn btn-sm btn-outline-secondary" href="repository.php">Reset</a>
          <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
          <button class="btn btn-sm btn-outline-primary" type="button">↓ Export PDF</button>
          <button class="btn btn-sm btn-outline-primary" type="button">↓ Export Excel</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($viewDocument): ?>
    <div class="card cp-card mb-3 view-card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h2 class="card-title mb-1">Document View</h2>
            <p class="card-subtitle mb-0">
              <?php echo e($viewDocument['document_number']); ?> - <?php echo e($viewDocument['title'] ?: ($viewDocument['topic'] ?: 'Untitled')); ?>
            </p>
          </div>
          <a href="repository.php" class="btn btn-outline-secondary">Close View</a>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-3"><strong>Type:</strong> <?php echo e($viewDocument['type_name'] ?: '—'); ?></div>
          <div class="col-md-3"><strong>Version:</strong> <?php echo e($viewDocument['version_label'] ?: '—'); ?></div>
          <div class="col-md-3"><strong>Department:</strong> <?php echo e($viewDocument['department_name'] ?: '—'); ?></div>
          <div class="col-md-3"><strong>Owner:</strong> <?php echo e(trim((string)$viewDocument['owner_name']) !== '' ? $viewDocument['owner_name'] : '—'); ?></div>
          <div class="col-md-3"><strong>Effective Date:</strong> <?php echo e(formatDateDisplay($viewDocument['effective_date'] ?? '')); ?></div>
          <div class="col-md-3"><strong>Next Review:</strong> <?php echo e(formatDateDisplay($viewDocument['review_date'] ?? '')); ?></div>
          <div class="col-md-3"><strong>Status:</strong> <?php echo e(displayStatusLabel($viewDocument['current_status'] ?? '', $viewDocument['review_date'] ?? '')); ?></div>
        </div>

        <div class="border rounded p-3 bg-light">
          <?php if (!empty($viewDocument['content_text'])): ?>
            <pre><?php echo e($viewDocument['content_text']); ?></pre>
          <?php elseif (!empty($viewDocument['primary_file_name'])): ?>
            <div class="text-secondary">
              No text content available. Attached file:
              <strong><?php echo e($viewDocument['primary_file_name']); ?></strong>
            </div>
          <?php else: ?>
            <div class="text-secondary">No document content available.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card cp-card" style="padding:0;">
    <table class="table mb-0" id="repoTable">
      <thead>
        <tr>
          <th>Document ID</th>
          <th>Title</th>
          <th>Type</th>
          <th>Version</th>
          <th>Department</th>
          <th>Owner</th>
          <th>Effective Date</th>
          <th>Next Review</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="repoBody">
        <?php if (!empty($rows)): ?>
          <?php foreach ($rows as $row): ?>
            <?php
              $docId = (int)$row['id'];
              $docNumber = $row['document_number'] ?: ('DOC-' . $docId);
              $title = $row['title'] ?: ($row['topic'] ?: 'Untitled');
              $type = $row['type_name'] ?: '—';
              $version = $row['version_label'] ?: '—';
              $dept = $row['department_name'] ?: '—';
              $owner = trim((string)$row['owner_name']) !== '' ? $row['owner_name'] : '—';
              $effectiveDate = formatDateDisplay($row['effective_date'] ?? '');
              $reviewDate = formatDateDisplay($row['review_date'] ?? '');
              $isOverdue = isOverdueDate($row['review_date'] ?? '');
              $reviewStyle = $isOverdue ? 'color:#dc2626;font-weight:600;' : 'color:#6b7280;';
              $statusLabel = displayStatusLabel($row['current_status'] ?? '', $row['review_date'] ?? '');
              $statusClass = statusBadgeClass($row['current_status'] ?? '', $row['review_date'] ?? '');
              $pdfLink = !empty($row['primary_file_path']) ? $row['primary_file_path'] : ('repository.php?view_id=' . $docId);
            ?>
            <tr>
              <td class="fw-semibold" style="color:#2563eb;font-size:13px;"><?php echo e($docNumber); ?></td>
              <td style="font-size:13px;"><?php echo e($title); ?></td>
              <td><span class="badge badge-soft-info"><?php echo e($type); ?></span></td>
              <td style="font-size:13px;"><?php echo e($version); ?></td>
              <td style="font-size:12px;color:#6b7280;"><?php echo e($dept); ?></td>
              <td style="font-size:12px;color:#6b7280;"><?php echo e($owner); ?></td>
              <td style="font-size:12px;color:#6b7280;"><?php echo e($effectiveDate); ?></td>
              <td style="font-size:12px;<?php echo e($reviewStyle); ?>"><?php echo e($reviewDate); ?></td>
              <td><span class="<?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span></td>
              <td style="white-space:nowrap;">
                <a class="btn btn-sm btn-outline-primary" style="height:28px;padding:0 10px;font-size:12px;" href="repository.php?view_id=<?php echo $docId; ?>">View</a>
                <a class="btn btn-sm btn-outline-secondary" style="height:28px;padding:0 10px;font-size:12px;" href="<?php echo e($pdfLink); ?>" target="_blank">↓ PDF</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="10" class="text-center text-secondary py-4 small">No documents found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="text-center text-secondary py-4 small <?php echo !empty($rows) ? 'd-none' : ''; ?>" id="repoEmpty">No documents found.</div>

    <div class="px-4 py-2 border-top d-flex justify-content-between align-items-center">
      <span class="small text-secondary" id="repoCount">Showing <?php echo (int)$filteredCount; ?> of <?php echo (int)$totalRepoCount; ?> documents</span>
      <span class="small" style="color:#dc2626;font-weight:600;">🔒 Read-only — no documents can be edited or deleted from this view.</span>
    </div>
  </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>