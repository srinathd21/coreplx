<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $safe = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
        $exists = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $exists;
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $table = mysqli_real_escape_string($conn, $tableName);
        $column = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $exists = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $exists;
    }
}

if (!function_exists('formatDateOnly')) {
    function formatDateOnly($date): string
    {
        $date = trim((string)($date ?? ''));
        if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($date);
        return $ts ? date('d-M-Y', $ts) : '—';
    }
}

if (!function_exists('formatDateTimeValue')) {
    function formatDateTimeValue($date): string
    {
        $date = trim((string)($date ?? ''));
        if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($date);
        return $ts ? date('d-M-Y H:i', $ts) : '—';
    }
}

if (!function_exists('findFirstExistingColumn')) {
    function findFirstExistingColumn(mysqli $conn, string $table, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (columnExists($conn, $table, $candidate)) {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('resolveAssignmentTable')) {
    function resolveAssignmentTable(mysqli $conn): ?string
    {
        $candidates = [
            'acknowledgement_assignments',
            'document_assignments',
            'document_acknowledgements'
        ];

        $bestTable = null;
        $bestScore = -1;

        $userCandidates = [
            'assigned_to_user_id',
            'assignee_user_id',
            'assigned_user_id',
            'employee_user_id',
            'employee_id',
            'user_id',
            'assigned_to',
            'assignee_id',
            'employee',
            'user'
        ];

        $docCandidates = [
            'document_id',
            'document_number',
            'document_title'
        ];

        foreach ($candidates as $table) {
            if (!tableExists($conn, $table)) {
                continue;
            }

            $score = 0;

            foreach ($userCandidates as $col) {
                if (columnExists($conn, $table, $col)) {
                    $score += 5;
                }
            }

            foreach ($docCandidates as $col) {
                if (columnExists($conn, $table, $col)) {
                    $score += 2;
                }
            }

            if (columnExists($conn, $table, 'status')) $score++;
            if (columnExists($conn, $table, 'acknowledgement_status')) $score++;
            if (columnExists($conn, $table, 'assignment_status')) $score++;
            if (columnExists($conn, $table, 'due_date')) $score++;
            if (columnExists($conn, $table, 'deadline_date')) $score++;
            if (columnExists($conn, $table, 'created_at')) $score++;
            if (columnExists($conn, $table, 'assigned_at')) $score++;
            if (columnExists($conn, $table, 'acknowledged_at')) $score++;
            if (columnExists($conn, $table, 'confirmed_at')) $score++;
            if (columnExists($conn, $table, 'completed_at')) $score++;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTable = $table;
            }
        }

        return $bestTable;
    }
}

if (!function_exists('resolveUserField')) {
    function resolveUserField(mysqli $conn, string $table): string
    {
        return findFirstExistingColumn($conn, $table, [
            'assigned_to_user_id',
            'assignee_user_id',
            'assigned_user_id',
            'employee_user_id',
            'employee_id',
            'user_id',
            'assigned_to',
            'assignee_id',
            'employee',
            'user'
        ]);
    }
}

if (!function_exists('resolveStatusField')) {
    function resolveStatusField(mysqli $conn, string $table): string
    {
        return findFirstExistingColumn($conn, $table, [
            'acknowledgement_status',
            'assignment_status',
            'status'
        ]);
    }
}

if (!function_exists('resolveAssignedDateExpr')) {
    function resolveAssignedDateExpr(mysqli $conn, string $table): string
    {
        $col = findFirstExistingColumn($conn, $table, [
            'assigned_at',
            'created_at',
            'assigned_on',
            'created_on'
        ]);

        return $col !== '' ? "a.`{$col}`" : "NULL";
    }
}

if (!function_exists('resolveAcknowledgedDateExpr')) {
    function resolveAcknowledgedDateExpr(mysqli $conn, string $table): string
    {
        $col = findFirstExistingColumn($conn, $table, [
            'acknowledged_at',
            'confirmed_at',
            'completed_at',
            'read_at',
            'updated_at'
        ]);

        return $col !== '' ? "a.`{$col}`" : "NULL";
    }
}

if (!function_exists('statusBadgeHtml')) {
    function statusBadgeHtml(string $status): string
    {
        $status = strtolower(trim($status));

        if (in_array($status, ['acknowledged', 'confirmed', 'completed', 'read'], true)) {
            return '<span class="badge badge-soft-success">Acknowledged</span>';
        }

        if ($status === 'overdue') {
            return '<span class="badge badge-soft-danger">Overdue</span>';
        }

        return '<span class="badge badge-soft-warning">Pending</span>';
    }
}

if (!function_exists('normalizeHistoryStatus')) {
    function normalizeHistoryStatus(string $rawStatus, $ackDate = null, $dueDate = null): string
    {
        $raw = strtolower(trim($rawStatus));

        if (in_array($raw, ['acknowledged', 'confirmed', 'completed', 'read'], true)) {
            return 'acknowledged';
        }

        $ackDate = trim((string)($ackDate ?? ''));
        if ($ackDate !== '' && $ackDate !== '0000-00-00' && $ackDate !== '0000-00-00 00:00:00') {
            return 'acknowledged';
        }

        $dueDate = trim((string)($dueDate ?? ''));
        if ($dueDate !== '' && $dueDate !== '0000-00-00') {
            $dueTs = strtotime($dueDate);
            if ($dueTs && $dueTs < strtotime(date('Y-m-d'))) {
                return 'overdue';
            }
        }

        return 'pending';
    }
}

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header('Location: login-employee.php');
    exit;
}

$employeeId = (int)($_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? 0);
if ($employeeId <= 0) {
    session_destroy();
    header('Location: login-employee.php');
    exit;
}

$currentUser = null;
$userSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
";
$userStmt = mysqli_prepare($conn, $userSql);
if ($userStmt) {
    mysqli_stmt_bind_param($userStmt, "i", $employeeId);
    mysqli_stmt_execute($userStmt);
    $userRes = mysqli_stmt_get_result($userStmt);
    $currentUser = ($userRes && mysqli_num_rows($userRes) > 0) ? mysqli_fetch_assoc($userRes) : null;
    mysqli_stmt_close($userStmt);
}

if (!$currentUser) {
    session_destroy();
    header('Location: login-employee.php');
    exit;
}

$displayName = trim((string)($currentUser['first_name'] ?? '') . ' ' . (string)($currentUser['last_name'] ?? ''));
if ($displayName === '') {
    $displayName = (string)($currentUser['email'] ?? 'Profile');
}
$roleName = trim((string)($currentUser['role_name'] ?? 'Employee'));
if ($roleName === '') {
    $roleName = 'Employee';
}

$errorMessage = '';
$rows = [];
$completedCount = 0;

$assignmentTable = resolveAssignmentTable($conn);

if ($assignmentTable === null) {
    $errorMessage = 'Acknowledgement records table not found.';
} else {
    $userField = resolveUserField($conn, $assignmentTable);

    if ($userField === '') {
        $errorMessage = 'Could not identify employee field in acknowledgement table.';
    } else {
        $statusField = resolveStatusField($conn, $assignmentTable);
        $assignedExpr = resolveAssignedDateExpr($conn, $assignmentTable);
        $ackExpr = resolveAcknowledgedDateExpr($conn, $assignmentTable);

        $statusExpr = $statusField !== '' ? "a.`{$statusField}`" : "'pending'";

        $documentIdExpr = columnExists($conn, $assignmentTable, 'document_id') ? "a.document_id" : "0";

        $documentNumberExpr = columnExists($conn, $assignmentTable, 'document_number')
            ? "a.document_number"
            : "COALESCE(d.document_number, CONCAT('DOC-', {$documentIdExpr}))";

        $documentTitleExpr = columnExists($conn, $assignmentTable, 'document_title')
            ? "a.document_title"
            : "COALESCE(d.title, d.topic, 'Untitled')";

        $versionExpr = columnExists($conn, $assignmentTable, 'version_label')
            ? "a.version_label"
            : "COALESCE(dv.version_label, '01')";

        $dueExpr = columnExists($conn, $assignmentTable, 'due_date')
            ? "a.due_date"
            : (columnExists($conn, $assignmentTable, 'deadline_date') ? "a.deadline_date" : "NULL");

        $sql = "
            SELECT
                {$documentNumberExpr} AS document_number,
                {$documentTitleExpr} AS document_title,
                {$versionExpr} AS version_label,
                {$assignedExpr} AS assigned_at,
                {$ackExpr} AS acknowledged_at,
                {$dueExpr} AS due_date,
                {$statusExpr} AS row_status
            FROM `{$assignmentTable}` a
            LEFT JOIN documents d ON d.id = {$documentIdExpr}
            LEFT JOIN document_versions dv ON dv.id = d.current_version_id
            WHERE a.`{$userField}` = ?
            ORDER BY
                CASE
                    WHEN {$ackExpr} IS NULL OR {$ackExpr} = '0000-00-00 00:00:00' THEN 0
                    ELSE 1
                END ASC,
                {$assignedExpr} DESC,
                {$documentIdExpr} DESC
        ";

        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $employeeId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);

            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $normalizedStatus = normalizeHistoryStatus(
                        (string)($row['row_status'] ?? 'pending'),
                        $row['acknowledged_at'] ?? null,
                        $row['due_date'] ?? null
                    );

                    if ($normalizedStatus === 'acknowledged') {
                        $completedCount++;
                    }

                    $rows[] = [
                        'document_number' => (string)($row['document_number'] ?: '—'),
                        'document_title' => (string)($row['document_title'] ?: 'Untitled'),
                        'version_label' => (string)($row['version_label'] ?: '01'),
                        'assigned_at' => $row['assigned_at'] ?? '',
                        'acknowledged_at' => $row['acknowledged_at'] ?? '',
                        'status' => $normalizedStatus,
                    ];
                }
            }

            mysqli_stmt_close($stmt);
        } else {
            $errorMessage = 'Failed to load acknowledgement history.';
        }
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CorePlx Quality DMS - My Acknowledgements</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/styles.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-employee.php">CorePlx Quality DMS</a>
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item"><a class="nav-link active" href="dashboard-employee.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="repository.php">Repository</a></li>
        
        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Acknowledgements</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="pending-acknowledgements.php">Pending Acknowledgements</a></li><li><a class="dropdown-item active" href="my-acknowledgements.php">My Acknowledgements</a></li></ul></li>
        
        
        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to Admin</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3"><span class="navbar-text small"><?php echo e($roleName); ?></span><a class="nav-link px-0" href="notifications.php">Notifications</a><span class="navbar-text small"><?php echo e($displayName); ?></span></div>
    </div>
  </div>
</nav>
<main class="app-shell">
  <div class="content-wrap px-4 px-xxl-5 mx-auto py-4">
    <div class="mb-4">
      <h1 class="page-title mb-2">My Acknowledgements</h1>
      <p class="page-subtitle mb-0">View your acknowledgement history by document and version.</p>
    </div>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo e($errorMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <div class="card cp-card">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
          <div>
            <h2 class="card-title mb-1">Acknowledgement History</h2>
            <p class="card-subtitle mb-0">Completed and pending acknowledgement records for the logged-in employee.</p>
          </div>
          <span class="badge badge-soft-success"><?php echo (int)$completedCount; ?> Completed</span>
        </div>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr><th>Document ID</th><th>Title</th><th>Version</th><th>Assigned Date</th><th>Acknowledged Date</th><th>Status</th></tr></thead>
            <tbody>
              <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td class="fw-semibold"><?php echo e($row['document_number']); ?></td>
                    <td><?php echo e($row['document_title']); ?></td>
                    <td><?php echo e($row['version_label']); ?></td>
                    <td><?php echo e(formatDateOnly($row['assigned_at'])); ?></td>
                    <td><?php echo e($row['status'] === 'acknowledged' ? formatDateTimeValue($row['acknowledged_at']) : '—'); ?></td>
                    <td><?php echo statusBadgeHtml($row['status']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" class="text-center text-secondary py-4">No acknowledgement records found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>