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

if (!function_exists('firstExistingColumn')) {
    function firstExistingColumn(mysqli $conn, $tableName, array $columns) {
        foreach ($columns as $column) {
            if (columnExists($conn, $tableName, $column)) {
                return $column;
            }
        }
        return null;
    }
}

if (!function_exists('formatDateTimeDisplay')) {
    function formatDateTimeDisplay($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime($datetime);
        return $ts ? date('d-M-Y h:i A', $ts) : '-';
    }
}

$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? $_SESSION['full_name']
    : 'QA Admin';

$successMessage = '';
$errorMessage   = '';

$search       = trim($_GET['search'] ?? '');
$userFilter   = trim($_GET['user'] ?? '');
$actionFilter = trim($_GET['action_name'] ?? '');
$docFilter    = trim($_GET['document_id'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');
$export       = trim($_GET['export'] ?? '');

$rows = [];
$dataSourceLabel = 'No audit comment table found.';

$possibleSources = [];
if (tableExists($conn, 'approver_comments')) $possibleSources[] = 'approver_comments';
if (tableExists($conn, 'audit_comments'))    $possibleSources[] = 'audit_comments';
if (tableExists($conn, 'document_comments')) $possibleSources[] = 'document_comments';
if (tableExists($conn, 'audit_trail'))       $possibleSources[] = 'audit_trail';
if (tableExists($conn, 'audit_logs'))        $possibleSources[] = 'audit_logs';
if (tableExists($conn, 'activity_logs'))     $possibleSources[] = 'activity_logs';

$selectedSource = !empty($possibleSources) ? $possibleSources[0] : '';

if ($selectedSource !== '') {
    $dataSourceLabel = ucfirst(str_replace('_', ' ', $selectedSource));
}

if ($selectedSource !== '') {
    $params = [];
    $types  = '';
    $where  = [];

    if (in_array($selectedSource, ['approver_comments', 'audit_comments', 'document_comments'], true)) {
        $timestampCol = firstExistingColumn($conn, $selectedSource, ['created_at', 'commented_at', 'updated_at', 'timestamp']);
        $commentCol   = firstExistingColumn($conn, $selectedSource, ['comment', 'comments', 'remark', 'remarks', 'note', 'notes', 'comment_text']);
        $actionCol    = firstExistingColumn($conn, $selectedSource, ['action', 'action_name', 'event_name', 'event']);
        $documentCol  = firstExistingColumn($conn, $selectedSource, ['document_id', 'document_code', 'doc_no', 'document_number', 'doc_id']);
        $userNameCol  = firstExistingColumn($conn, $selectedSource, ['user_name', 'commented_by_name', 'actor_name']);
        $userIdCol    = firstExistingColumn($conn, $selectedSource, ['user_id', 'commented_by', 'created_by', 'actor_id']);

        if ($timestampCol === null) {
            $errorMessage = 'No timestamp column found in ' . $selectedSource . '.';
        } elseif ($commentCol === null) {
            $errorMessage = 'No comment column found in ' . $selectedSource . '.';
        } else {
            $joins = [];

            $timestampExpr = "c.`{$timestampCol}`";
            $commentExpr   = "c.`{$commentCol}`";
            $actionExpr    = $actionCol !== null ? "c.`{$actionCol}`" : "'Comment Added'";
            $documentExpr  = $documentCol !== null ? "c.`{$documentCol}`" : "'-'";
            $userExpr      = "'-'";

            if ($userNameCol !== null) {
                $userExpr = "c.`{$userNameCol}`";
            } elseif ($userIdCol !== null && tableExists($conn, 'users')) {
                $joins[] = "LEFT JOIN users u ON u.id = c.`{$userIdCol}`";
                $userExpr = "TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')))";
            }

            if ($search !== '') {
                $where[] = "({$commentExpr} LIKE ? OR {$documentExpr} LIKE ? OR {$userExpr} LIKE ?)";
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $types .= 'sss';
            }

            if ($userFilter !== '') {
                $where[] = "({$userExpr} LIKE ?)";
                $params[] = '%' . $userFilter . '%';
                $types .= 's';
            }

            if ($actionFilter !== '') {
                $where[] = "({$actionExpr} LIKE ?)";
                $params[] = '%' . $actionFilter . '%';
                $types .= 's';
            }

            if ($docFilter !== '') {
                $where[] = "({$documentExpr} LIKE ?)";
                $params[] = '%' . $docFilter . '%';
                $types .= 's';
            }

            if ($dateFrom !== '') {
                $where[] = "DATE({$timestampExpr}) >= ?";
                $params[] = $dateFrom;
                $types .= 's';
            }

            if ($dateTo !== '') {
                $where[] = "DATE({$timestampExpr}) <= ?";
                $params[] = $dateTo;
                $types .= 's';
            }

            $sql = "
                SELECT
                    {$timestampExpr} AS event_time,
                    {$userExpr} AS user_name,
                    {$actionExpr} AS action_name,
                    {$documentExpr} AS document_id,
                    {$commentExpr} AS comment_text
                FROM `{$selectedSource}` c
                " . implode("\n", $joins);

            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }

            $sql .= " ORDER BY {$timestampExpr} DESC LIMIT 500";

            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                if (!empty($params)) {
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                }

                if (mysqli_stmt_execute($stmt)) {
                    $res = mysqli_stmt_get_result($stmt);
                    if ($res) {
                        while ($row = mysqli_fetch_assoc($res)) {
                            $rows[] = [
                                'event_time'   => $row['event_time'] ?? '',
                                'user_name'    => trim((string)($row['user_name'] ?? '')) !== '' ? trim((string)$row['user_name']) : '-',
                                'action_name'  => trim((string)($row['action_name'] ?? '')) !== '' ? trim((string)$row['action_name']) : 'Comment Added',
                                'document_id'  => trim((string)($row['document_id'] ?? '')) !== '' ? trim((string)$row['document_id']) : '-',
                                'comment_text' => trim((string)($row['comment_text'] ?? '')) !== '' ? trim((string)$row['comment_text']) : '-',
                            ];
                        }
                    }
                } else {
                    $errorMessage = 'Failed to load audit comments: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            } else {
                $errorMessage = 'Failed to prepare query: ' . mysqli_error($conn);
            }
        }
    } else {
        $timestampCol = firstExistingColumn($conn, $selectedSource, ['created_at', 'logged_at', 'updated_at', 'timestamp']);
        $commentCol   = firstExistingColumn($conn, $selectedSource, ['comment', 'comments', 'remark', 'remarks', 'note', 'notes', 'comment_text']);
        $actionCol    = firstExistingColumn($conn, $selectedSource, ['action', 'action_name', 'event_name', 'event']);
        $documentCol  = firstExistingColumn($conn, $selectedSource, ['document_id', 'document_code', 'doc_no', 'document_number', 'doc_id']);
        $userNameCol  = firstExistingColumn($conn, $selectedSource, ['user_name', 'actor_name', 'commented_by_name']);

        if ($timestampCol === null) {
            $errorMessage = 'No timestamp column found in ' . $selectedSource . '.';
        } elseif ($commentCol === null) {
            $errorMessage = 'No comment column found in ' . $selectedSource . '.';
        } else {
            $timestampExpr = "a.`{$timestampCol}`";
            $commentExpr   = "a.`{$commentCol}`";
            $actionExpr    = $actionCol !== null ? "a.`{$actionCol}`" : "'Comment Added'";
            $documentExpr  = $documentCol !== null ? "a.`{$documentCol}`" : "'-'";
            $userExpr      = $userNameCol !== null ? "a.`{$userNameCol}`" : "'-'";

            $where[] = "TRIM(COALESCE({$commentExpr}, '')) <> ''";

            if ($search !== '') {
                $where[] = "({$commentExpr} LIKE ? OR {$documentExpr} LIKE ? OR {$userExpr} LIKE ?)";
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $types .= 'sss';
            }

            if ($userFilter !== '') {
                $where[] = "({$userExpr} LIKE ?)";
                $params[] = '%' . $userFilter . '%';
                $types .= 's';
            }

            if ($actionFilter !== '') {
                $where[] = "({$actionExpr} LIKE ?)";
                $params[] = '%' . $actionFilter . '%';
                $types .= 's';
            }

            if ($docFilter !== '') {
                $where[] = "({$documentExpr} LIKE ?)";
                $params[] = '%' . $docFilter . '%';
                $types .= 's';
            }

            if ($dateFrom !== '') {
                $where[] = "DATE({$timestampExpr}) >= ?";
                $params[] = $dateFrom;
                $types .= 's';
            }

            if ($dateTo !== '') {
                $where[] = "DATE({$timestampExpr}) <= ?";
                $params[] = $dateTo;
                $types .= 's';
            }

            $sql = "
                SELECT
                    {$timestampExpr} AS event_time,
                    {$userExpr} AS user_name,
                    {$actionExpr} AS action_name,
                    {$documentExpr} AS document_id,
                    {$commentExpr} AS comment_text
                FROM `{$selectedSource}` a
            ";

            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }

            $sql .= " ORDER BY {$timestampExpr} DESC LIMIT 500";

            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                if (!empty($params)) {
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                }

                if (mysqli_stmt_execute($stmt)) {
                    $res = mysqli_stmt_get_result($stmt);
                    if ($res) {
                        while ($row = mysqli_fetch_assoc($res)) {
                            $rows[] = [
                                'event_time'   => $row['event_time'] ?? '',
                                'user_name'    => trim((string)($row['user_name'] ?? '')) !== '' ? trim((string)$row['user_name']) : '-',
                                'action_name'  => trim((string)($row['action_name'] ?? '')) !== '' ? trim((string)$row['action_name']) : 'Comment Added',
                                'document_id'  => trim((string)($row['document_id'] ?? '')) !== '' ? trim((string)$row['document_id']) : '-',
                                'comment_text' => trim((string)($row['comment_text'] ?? '')) !== '' ? trim((string)$row['comment_text']) : '-',
                            ];
                        }
                    }
                } else {
                    $errorMessage = 'Failed to load audit comments: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            } else {
                $errorMessage = 'Failed to prepare query: ' . mysqli_error($conn);
            }
        }
    }
}

if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit-comments-' . date('Ymd_His') . '.xls');

    echo '<table border="1">';
    echo '<tr><th>Timestamp</th><th>User</th><th>Action</th><th>Document ID</th><th>Comment</th></tr>';

    if (!empty($rows)) {
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . e(formatDateTimeDisplay($row['event_time'])) . '</td>';
            echo '<td>' . e($row['user_name']) . '</td>';
            echo '<td>' . e($row['action_name']) . '</td>';
            echo '<td>' . e($row['document_id']) . '</td>';
            echo '<td>' . e($row['comment_text']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No records found.</td></tr>';
    }

    echo '</table>';
    exit;
}

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit-comments-' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Action', 'Document ID', 'Comment']);

    if (!empty($rows)) {
        foreach ($rows as $row) {
            fputcsv($out, [
                formatDateTimeDisplay($row['event_time']),
                $row['user_name'],
                $row['action_name'],
                $row['document_id'],
                $row['comment_text']
            ]);
        }
    } else {
        fputcsv($out, ['No records found']);
    }

    fclose($out);
    exit;
}

if ($export === 'pdf') {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Audit Comments Export</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; color: #222; margin: 20px; }
            h2 { margin: 0 0 6px 0; font-size: 20px; }
            p { margin: 0 0 14px 0; color: #555; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #999; padding: 8px; vertical-align: top; text-align: left; }
            th { background: #f2f2f2; }
            .muted { color: #666; font-size: 11px; margin-top: 10px; }
            @media print {
                .no-print { display: none; }
                body { margin: 0; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom:12px;">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>
        <h2>Audit Trail - Approver Comments</h2>
        <p>Captures who commented, when, full comment text, and linked document version.</p>

        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Document ID</th>
                    <th>Comment</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo e(formatDateTimeDisplay($row['event_time'])); ?></td>
                            <td><?php echo e($row['user_name']); ?></td>
                            <td><?php echo e($row['action_name']); ?></td>
                            <td><?php echo e($row['document_id']); ?></td>
                            <td><?php echo nl2br(e($row['comment_text'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="muted">Export generated on <?php echo e(date('d-M-Y h:i A')); ?></div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Audit Trail</title>
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
    .table td,
    .table th {
      vertical-align: middle;
    }
    .table td.comment-cell {
      min-width: 280px;
      white-space: normal;
    }
    .filter-box {
      display: none;
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 14px;
      background: #f8f9fa;
      padding: 1rem;
      margin-bottom: 1rem;
    }
    .filter-box.active {
      display: block;
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
      <h1 class="page-title mb-2">Audit Trail - Approver Comments</h1>
      <p class="page-subtitle mb-0">Review traceable comment history linked to document versions.</p>
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
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h2 class="card-title mb-1">Audit Events</h2>
            <p class="card-subtitle mb-0">Captures who commented, when, full comment text, and linked document version.</p>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="toggleFilterBtn">Filter</button>
            <a href="?<?php echo e(http_build_query(array_merge($_GET, ['export' => 'pdf']))); ?>" class="btn btn-outline-primary">Export PDF</a>
            <a href="?<?php echo e(http_build_query(array_merge($_GET, ['export' => 'excel']))); ?>" class="btn btn-outline-primary">Export Excel</a>
          </div>
        </div>

        <div class="filter-box <?php echo ($search !== '' || $userFilter !== '' || $actionFilter !== '' || $docFilter !== '' || $dateFrom !== '' || $dateTo !== '') ? 'active' : ''; ?>" id="filterBox">
          <form method="get">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" value="<?php echo e($search); ?>" placeholder="Search comment, user, document">
              </div>
              <div class="col-md-2">
                <label class="form-label">User</label>
                <input type="text" name="user" class="form-control" value="<?php echo e($userFilter); ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Action</label>
                <input type="text" name="action_name" class="form-control" value="<?php echo e($actionFilter); ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Document ID</label>
                <input type="text" name="document_id" class="form-control" value="<?php echo e($docFilter); ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>">
              </div>
            </div>
            <div class="d-flex gap-2 mt-3">
              <button type="submit" class="btn btn-primary">Apply Filter</button>
              <a href="audit-comments.php" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>User</th>
                <th>Action</th>
                <th>Document ID</th>
                <th>Comment</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td><?php echo e(formatDateTimeDisplay($row['event_time'])); ?></td>
                    <td><?php echo e($row['user_name']); ?></td>
                    <td><?php echo e($row['action_name']); ?></td>
                    <td><?php echo e($row['document_id']); ?></td>
                    <td class="comment-cell"><?php echo nl2br(e($row['comment_text'])); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">No audit comment records found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="small text-secondary mt-2">
          Export to PDF / Excel preserves full reason and comment text. Data source: <?php echo e($dataSourceLabel); ?>
        </div>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggleBtn = document.getElementById('toggleFilterBtn');
    var filterBox = document.getElementById('filterBox');

    if (toggleBtn && filterBox) {
        toggleBtn.addEventListener('click', function () {
            filterBox.classList.toggle('active');
        });
    }
});
</script>
</body>
</html>