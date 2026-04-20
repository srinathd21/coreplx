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
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
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
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        $exists = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $exists;
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

        foreach ($candidates as $table) {
            if (tableExists($conn, $table)) {
                return $table;
            }
        }
        return null;
    }
}

if (!function_exists('formatDateDisplay')) {
    function formatDateDisplay($date): string
    {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$date);
        return $ts ? date('d-M-Y', $ts) : '—';
    }
}

if (!function_exists('resolveUserDisplayName')) {
    function resolveUserDisplayName(array $row): string
    {
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if (!empty($row['full_name'])) {
            return trim((string)$row['full_name']);
        }
        if (!empty($row['name'])) {
            return trim((string)$row['name']);
        }
        if (!empty($row['email'])) {
            return (string)$row['email'];
        }
        return 'Profile';
    }
}

if (!function_exists('buildStatusBadge')) {
    function buildStatusBadge(string $status, string $dueDate = ''): string
    {
        $status = strtolower(trim($status));

        if (in_array($status, ['confirmed', 'completed', 'acknowledged', 'read'], true)) {
            return '<span class="badge badge-soft-success">Acknowledged</span>';
        }

        if ($dueDate !== '') {
            $dueTs = strtotime($dueDate);
            $todayTs = strtotime(date('Y-m-d'));
            if ($dueTs && $dueTs < $todayTs) {
                return '<span class="badge badge-soft-danger">Overdue</span>';
            }
        }

        return '<span class="badge badge-soft-warning">Pending</span>';
    }
}

if (!function_exists('buildViewUrl')) {
    function buildViewUrl(int $documentId): string
    {
        return 'employee-document-view.php?id=' . $documentId;
    }
}

/* ---------------- AUTH ---------------- */
if (
    (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) &&
    (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0)
) {
    header('Location: login-employee.php');
    exit;
}

$employeeId = (int)($_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? 0);
if ($employeeId <= 0) {
    session_destroy();
    header('Location: login-employee.php');
    exit;
}

/* ---------------- CURRENT USER ---------------- */
$currentUser = null;
$userQueries = [
    "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.full_name,
        u.email,
        r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
    ",
    "
    SELECT
        id,
        first_name,
        last_name,
        full_name,
        email
    FROM users
    WHERE id = ?
    LIMIT 1
    "
];

foreach ($userQueries as $sql) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $employeeId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && mysqli_num_rows($res) > 0) {
            $currentUser = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            break;
        }
        mysqli_stmt_close($stmt);
    }
}

$displayName = $currentUser ? resolveUserDisplayName($currentUser) : 'Profile';
$roleName = trim((string)($currentUser['role_name'] ?? 'Employee'));
if ($roleName === '') {
    $roleName = 'Employee';
}

/* ---------------- LOAD ASSIGNMENTS ---------------- */
$assignmentTable = resolveAssignmentTable($conn);
$errorMessage = '';
$rows = [];
$pendingCount = 0;

if ($assignmentTable === null) {
    $errorMessage = 'Acknowledgement assignment table not found in database.';
} else {
    $hasId = columnExists($conn, $assignmentTable, 'id');
    $hasDocumentId = columnExists($conn, $assignmentTable, 'document_id');
    $hasDocumentNumber = columnExists($conn, $assignmentTable, 'document_number');
    $hasDocumentTitle = columnExists($conn, $assignmentTable, 'document_title');
    $hasDocumentType = columnExists($conn, $assignmentTable, 'document_type');
    $hasDueDate = columnExists($conn, $assignmentTable, 'due_date');
    $hasDeadlineDate = columnExists($conn, $assignmentTable, 'deadline_date');
    $hasStatus = columnExists($conn, $assignmentTable, 'status');
    $hasAssignmentStatus = columnExists($conn, $assignmentTable, 'assignment_status');
    $hasAckStatus = columnExists($conn, $assignmentTable, 'acknowledgement_status');
    $hasAssignedToUserId = columnExists($conn, $assignmentTable, 'assigned_to_user_id');
    $hasUserId = columnExists($conn, $assignmentTable, 'user_id');
    $hasEmployeeId = columnExists($conn, $assignmentTable, 'employee_id');

    $selectAssignmentId = $hasId ? "a.id AS assignment_id" : "0 AS assignment_id";
    $selectDocumentId = $hasDocumentId ? "a.document_id" : "0 AS document_id";
    $selectDocumentNumber = $hasDocumentNumber
        ? "a.document_number"
        : ($hasDocumentId
            ? "COALESCE(d.document_number, CONCAT('DOC-', a.document_id)) AS document_number"
            : "'—' AS document_number");
    $selectDocumentTitle = $hasDocumentTitle
        ? "a.document_title"
        : "COALESCE(d.title, d.topic, 'Untitled Document') AS document_title";
    $selectDocumentType = $hasDocumentType
        ? "a.document_type"
        : "COALESCE(dt.type_name, 'Document') AS document_type";
    $selectDueDate = $hasDueDate
        ? "a.due_date"
        : ($hasDeadlineDate ? "a.deadline_date AS due_date" : "NULL AS due_date");

    if ($hasStatus) {
        $statusExpr = "a.status";
    } elseif ($hasAssignmentStatus) {
        $statusExpr = "a.assignment_status";
    } elseif ($hasAckStatus) {
        $statusExpr = "a.acknowledgement_status";
    } else {
        $statusExpr = "'pending'";
    }

    if ($hasAssignedToUserId) {
        $userField = "a.assigned_to_user_id";
    } elseif ($hasUserId) {
        $userField = "a.user_id";
    } elseif ($hasEmployeeId) {
        $userField = "a.employee_id";
    } else {
        $userField = "0";
    }

    $docJoinField = $hasDocumentId ? "a.document_id" : "0";

    $sql = "
        SELECT
            {$selectAssignmentId},
            {$selectDocumentId},
            {$selectDocumentNumber},
            {$selectDocumentTitle},
            {$selectDocumentType},
            {$selectDueDate},
            {$statusExpr} AS status,
            COALESCE(dv.version_label, '01') AS version_label,
            dv.effective_date
        FROM `{$assignmentTable}` a
        LEFT JOIN documents d ON d.id = {$docJoinField}
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        WHERE {$userField} = ?
        ORDER BY
            CASE
                WHEN LOWER({$statusExpr}) IN ('confirmed','completed','acknowledged','read') THEN 2
                ELSE 1
            END,
            " . ($hasDueDate ? "a.due_date" : ($hasDeadlineDate ? "a.deadline_date" : "NULL")) . " ASC,
            " . ($hasId ? "a.id DESC" : "d.id DESC") . "
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $employeeId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $status = strtolower(trim((string)($row['status'] ?? 'pending')));
                if (!in_array($status, ['confirmed', 'completed', 'acknowledged', 'read'], true)) {
                    $pendingCount++;
                }
                $rows[] = $row;
            }
        } else {
            $errorMessage = 'Failed to read acknowledgement results.';
        }

        mysqli_stmt_close($stmt);
    } else {
        $errorMessage = 'Failed to load pending acknowledgement records: ' . mysqli_error($conn);
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CorePlx Quality DMS - Pending Acknowledgements</title>
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

        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Acknowledgements</a><ul class="dropdown-menu"><li><a class="dropdown-item active" href="pending-acknowledgements.php">Pending Acknowledgements</a></li><li><a class="dropdown-item" href="my-acknowledgements.php">My Acknowledgements</a></li></ul></li>

        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to Admin</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3"><span class="navbar-text small"><?php echo e($roleName); ?></span><a class="nav-link px-0" href="notifications.php">Notifications</a><span class="navbar-text small"><?php echo e($displayName); ?></span></div>
    </div>
  </div>
</nav>
<main class="app-shell">
  <div class="content-wrap px-4 px-xxl-5 mx-auto py-4">
    <div class="mb-4">
      <h1 class="page-title mb-2">Pending Acknowledgements</h1>
      <p class="page-subtitle mb-0">Review assigned documents and complete acknowledgement before the due date.</p>
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
            <h2 class="card-title mb-1">Assigned Items</h2>
            <p class="card-subtitle mb-0">Only documents assigned to the logged-in employee are shown here.</p>
          </div>
          <span class="badge badge-soft-warning"><?php echo (int)$pendingCount; ?> Pending</span>
        </div>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr><th>Document ID</th><th>Title</th><th>Version</th><th>Effective Date</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
              <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row): ?>
                  <?php
                    $documentId = (int)($row['document_id'] ?? 0);
                    $documentNumber = (string)($row['document_number'] ?? '—');
                    $title = trim((string)($row['document_title'] ?? 'Untitled Document'));
                    $version = trim((string)($row['version_label'] ?? '01'));
                    $effectiveDate = (string)($row['effective_date'] ?? '');
                    $dueDate = (string)($row['due_date'] ?? '');
                    $status = (string)($row['status'] ?? 'pending');
                    $badgeHtml = buildStatusBadge($status, $dueDate);
                    $viewUrl = buildViewUrl($documentId);
                  ?>
                  <tr>
                    <td class="fw-semibold"><?php echo e($documentNumber); ?></td>
                    <td><?php echo e($title !== '' ? $title : 'Untitled Document'); ?></td>
                    <td><?php echo e($version); ?></td>
                    <td><?php echo e(formatDateDisplay($effectiveDate)); ?></td>
                    <td><?php echo e(formatDateDisplay($dueDate)); ?></td>
                    <td><?php echo $badgeHtml; ?></td>
                    <td><a href="<?php echo e($viewUrl); ?>" class="btn btn-sm btn-primary">View & Acknowledge</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="7" class="text-center text-secondary py-4">No pending acknowledgements found.</td></tr>
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