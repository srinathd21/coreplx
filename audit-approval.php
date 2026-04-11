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
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00' || $datetime === '0000-00-00') {
            return '-';
        }

        $ts = strtotime((string)$datetime);
        if ($ts) {
            return date('d-M-Y h:i A', $ts);
        }

        return e((string)$datetime);
    }
}

$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? $_SESSION['full_name']
    : 'QA Admin';

$successMessage = '';
$errorMessage   = '';

$search        = trim($_GET['search'] ?? '');
$userFilter    = trim($_GET['user'] ?? '');
$meaningFilter = trim($_GET['meaning'] ?? '');
$docFilter     = trim($_GET['document_id'] ?? '');
$dateFrom      = trim($_GET['date_from'] ?? '');
$dateTo        = trim($_GET['date_to'] ?? '');
$export        = trim($_GET['export'] ?? '');

$rows = [];
$dataSourceLabel = 'No approval audit table found.';

$possibleSources = [];
if (tableExists($conn, 'document_approvals')) $possibleSources[] = 'document_approvals';
if (tableExists($conn, 'approval_logs'))      $possibleSources[] = 'approval_logs';
if (tableExists($conn, 'audit_approval'))     $possibleSources[] = 'audit_approval';
if (tableExists($conn, 'audit_trail'))        $possibleSources[] = 'audit_trail';
if (tableExists($conn, 'audit_logs'))         $possibleSources[] = 'audit_logs';
if (tableExists($conn, 'activity_logs'))      $possibleSources[] = 'activity_logs';

$selectedSource = !empty($possibleSources) ? $possibleSources[0] : '';

if ($selectedSource !== '') {
    $dataSourceLabel = ucfirst(str_replace('_', ' ', $selectedSource));
}

if ($selectedSource !== '') {
    $params = [];
    $types  = '';
    $where  = [];

    $timestampCol = firstExistingColumn($conn, $selectedSource, [
        'created_at', 'approved_at', 'action_at', 'logged_at', 'updated_at',
        'timestamp', 'event_time', 'date_time', 'entry_date', 'approval_date'
    ]);

    $orderCol = $timestampCol ?: firstExistingColumn($conn, $selectedSource, ['id']);

    $reasonCol = firstExistingColumn($conn, $selectedSource, [
        'reason', 'comments', 'comment', 'remark', 'remarks', 'note',
        'notes', 'approval_reason', 'message', 'description'
    ]);

    $meaningCol = firstExistingColumn($conn, $selectedSource, [
        'meaning', 'status', 'action', 'action_name', 'decision',
        'approval_status', 'event_name', 'event', 'result'
    ]);

    $documentCol = firstExistingColumn($conn, $selectedSource, [
        'document_id', 'document_code', 'doc_no', 'document_number',
        'doc_id', 'doc_code', 'reference_no'
    ]);

    $userNameCol = firstExistingColumn($conn, $selectedSource, [
        'user_name', 'approver_name', 'actor_name', 'approved_by_name',
        'employee_name', 'full_name'
    ]);

    $userIdCol = firstExistingColumn($conn, $selectedSource, [
        'user_id', 'approved_by', 'approver_id', 'actor_id', 'created_by',
        'employee_id'
    ]);

    $signatureCol = firstExistingColumn($conn, $selectedSource, [
        'signature', 'signature_hash', 'signature_token',
        'esignature', 'electronic_signature'
    ]);

    if ($meaningCol === null) {
        $errorMessage = 'No approval status / meaning column found in ' . $selectedSource . '.';
    } else {
        $joins = [];

        $eventTimeExpr = $timestampCol !== null ? "a.`{$timestampCol}`" : "''";
        $reasonExpr    = $reasonCol !== null ? "a.`{$reasonCol}`" : "''";
        $meaningExpr   = "a.`{$meaningCol}`";
        $documentExpr  = $documentCol !== null ? "a.`{$documentCol}`" : "'-'";
        $signatureExpr = $signatureCol !== null ? "a.`{$signatureCol}`" : "''";
        $userExpr      = "'-'";

        if ($userNameCol !== null) {
            $userExpr = "a.`{$userNameCol}`";
        } elseif ($userIdCol !== null && tableExists($conn, 'users')) {
            $joins[] = "LEFT JOIN users u ON u.id = a.`{$userIdCol}`";
            $userExpr = "TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')))";
        }

        $approvalCondition = "(
            LOWER(COALESCE({$meaningExpr}, '')) LIKE '%approve%' OR
            LOWER(COALESCE({$meaningExpr}, '')) LIKE '%approved%' OR
            LOWER(COALESCE({$meaningExpr}, '')) LIKE '%deny%' OR
            LOWER(COALESCE({$meaningExpr}, '')) LIKE '%denied%' OR
            LOWER(COALESCE({$meaningExpr}, '')) LIKE '%reject%' OR
            LOWER(COALESCE({$meaningExpr}, '')) LIKE '%rejected%'
        )";
        $where[] = $approvalCondition;

        if ($search !== '') {
            $where[] = "({$reasonExpr} LIKE ? OR {$documentExpr} LIKE ? OR {$userExpr} LIKE ? OR {$meaningExpr} LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'ssss';
        }

        if ($userFilter !== '') {
            $where[] = "({$userExpr} LIKE ?)";
            $params[] = '%' . $userFilter . '%';
            $types .= 's';
        }

        if ($meaningFilter !== '') {
            $where[] = "({$meaningExpr} LIKE ?)";
            $params[] = '%' . $meaningFilter . '%';
            $types .= 's';
        }

        if ($docFilter !== '') {
            $where[] = "({$documentExpr} LIKE ?)";
            $params[] = '%' . $docFilter . '%';
            $types .= 's';
        }

        if ($timestampCol !== null && $dateFrom !== '') {
            $where[] = "DATE(a.`{$timestampCol}`) >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }

        if ($timestampCol !== null && $dateTo !== '') {
            $where[] = "DATE(a.`{$timestampCol}`) <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }

        $sql = "
            SELECT
                {$eventTimeExpr} AS event_time,
                {$userExpr} AS user_name,
                {$meaningExpr} AS meaning_name,
                {$documentExpr} AS document_id,
                {$reasonExpr} AS reason_text,
                {$signatureExpr} AS signature_text
            FROM `{$selectedSource}` a
            " . implode("\n", $joins);

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        if ($orderCol !== null) {
            $sql .= " ORDER BY a.`{$orderCol}` DESC";
        }

        $sql .= " LIMIT 500";

        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            if (!empty($params)) {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }

            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                if ($res) {
                    while ($row = mysqli_fetch_assoc($res)) {
                        $meaning = trim((string)($row['meaning_name'] ?? ''));
                        $reason  = trim((string)($row['reason_text'] ?? ''));
                        $sig     = trim((string)($row['signature_text'] ?? ''));

                        if ($reason === '' && $sig !== '') {
                            $reason = 'Electronic signature captured';
                        } elseif ($reason !== '' && $sig !== '') {
                            $reason .= ' | Signature captured';
                        } elseif ($reason === '') {
                            $reason = '-';
                        }

                        $rows[] = [
                            'event_time'   => $row['event_time'] ?? '',
                            'user_name'    => trim((string)($row['user_name'] ?? '')) !== '' ? trim((string)$row['user_name']) : '-',
                            'meaning_name' => $meaning !== '' ? $meaning : '-',
                            'document_id'  => trim((string)($row['document_id'] ?? '')) !== '' ? trim((string)$row['document_id']) : '-',
                            'reason_text'  => $reason,
                        ];
                    }
                }
            } else {
                $errorMessage = 'Failed to load approval audit records: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } else {
            $errorMessage = 'Failed to prepare query: ' . mysqli_error($conn);
        }
    }
}

if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit-approval-' . date('Ymd_His') . '.xls');

    echo '<table border="1">';
    echo '<tr><th>Timestamp</th><th>User</th><th>Meaning</th><th>Document ID</th><th>Reason</th></tr>';

    if (!empty($rows)) {
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . e(formatDateTimeDisplay($row['event_time'])) . '</td>';
            echo '<td>' . e($row['user_name']) . '</td>';
            echo '<td>' . e($row['meaning_name']) . '</td>';
            echo '<td>' . e($row['document_id']) . '</td>';
            echo '<td>' . e($row['reason_text']) . '</td>';
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
    header('Content-Disposition: attachment; filename=audit-approval-' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Meaning', 'Document ID', 'Reason']);

    if (!empty($rows)) {
        foreach ($rows as $row) {
            fputcsv($out, [
                formatDateTimeDisplay($row['event_time']),
                $row['user_name'],
                $row['meaning_name'],
                $row['document_id'],
                $row['reason_text']
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
        <title>Audit Approval Export</title>
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
        <h2>Audit Trail - Approval & Denial</h2>
        <p>Captures who approved or denied, meaning, reason, and electronic signature evidence.</p>

        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Meaning</th>
                    <th>Document ID</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo e(formatDateTimeDisplay($row['event_time'])); ?></td>
                            <td><?php echo e($row['user_name']); ?></td>
                            <td><?php echo e($row['meaning_name']); ?></td>
                            <td><?php echo e($row['document_id']); ?></td>
                            <td><?php echo nl2br(e($row['reason_text'])); ?></td>
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
    .table td.reason-cell {
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
      <h1 class="page-title mb-2">Audit Trail - Approval &amp; Denial</h1>
      <p class="page-subtitle mb-0">Capture and review full approval, denial, and signature evidence.</p>
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
            <p class="card-subtitle mb-0">Captures who approved or denied, meaning, reason, and electronic signature evidence.</p>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="toggleFilterBtn">Filter</button>
            <a href="?<?php echo e(http_build_query(array_merge($_GET, ['export' => 'pdf']))); ?>" class="btn btn-outline-primary">Export PDF</a>
            <a href="?<?php echo e(http_build_query(array_merge($_GET, ['export' => 'excel']))); ?>" class="btn btn-outline-primary">Export Excel</a>
          </div>
        </div>

        <div class="filter-box <?php echo ($search !== '' || $userFilter !== '' || $meaningFilter !== '' || $docFilter !== '' || $dateFrom !== '' || $dateTo !== '') ? 'active' : ''; ?>" id="filterBox">
          <form method="get">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" value="<?php echo e($search); ?>" placeholder="Search reason, user, document, meaning">
              </div>
              <div class="col-md-2">
                <label class="form-label">User</label>
                <input type="text" name="user" class="form-control" value="<?php echo e($userFilter); ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Meaning</label>
                <input type="text" name="meaning" class="form-control" value="<?php echo e($meaningFilter); ?>">
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
              <a href="audit-approval.php" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>User</th>
                <th>Meaning</th>
                <th>Document ID</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td><?php echo e(formatDateTimeDisplay($row['event_time'])); ?></td>
                    <td><?php echo e($row['user_name']); ?></td>
                    <td><?php echo e($row['meaning_name']); ?></td>
                    <td><?php echo e($row['document_id']); ?></td>
                    <td class="reason-cell"><?php echo nl2br(e($row['reason_text'])); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">No approval audit records found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="small text-secondary mt-2">
          Export to PDF / Excel preserves full reason and approval evidence text. Data source: <?php echo e($dataSourceLabel); ?>
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