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
    function tableExists(mysqli $conn, string $tableName): bool {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, string $tableName, string $columnName): bool {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('firstExistingColumn')) {
    function firstExistingColumn(mysqli $conn, string $tableName, array $columns): ?string {
        foreach ($columns as $column) {
            if (columnExists($conn, $tableName, $column)) {
                return $column;
            }
        }
        return null;
    }
}

$currentUserName = isset($_SESSION['full_name']) && trim((string)$_SESSION['full_name']) !== ''
    ? trim((string)$_SESSION['full_name'])
    : 'QA Admin';

$notificationsTable = '';
foreach (['notifications', 'notification_logs'] as $tbl) {
    if (tableExists($conn, $tbl)) {
        $notificationsTable = $tbl;
        break;
    }
}

$notifications = [];

if ($notificationsTable !== '') {
    $typeCol = firstExistingColumn($conn, $notificationsTable, ['type', 'notification_type']);
    $recipientCol = firstExistingColumn($conn, $notificationsTable, ['recipient', 'recipient_name', 'user_name']);
    $triggerCol = firstExistingColumn($conn, $notificationsTable, ['trigger_event', 'trigger', 'event_name', 'event']);
    $statusCol = firstExistingColumn($conn, $notificationsTable, ['status']);
    $sentOnCol = firstExistingColumn($conn, $notificationsTable, ['sent_on', 'sent_at', 'created_at', 'timestamp']);

    if ($typeCol !== null && $recipientCol !== null && $triggerCol !== null && $statusCol !== null && $sentOnCol !== null) {
        $sql = "
            SELECT
                `{$typeCol}` AS notification_type,
                `{$recipientCol}` AS recipient_name,
                `{$triggerCol}` AS trigger_name,
                `{$statusCol}` AS status_name,
                `{$sentOnCol}` AS sent_on
            FROM `{$notificationsTable}`
            ORDER BY `{$sentOnCol}` DESC
            LIMIT 50
        ";
        $res = mysqli_query($conn, $sql);

        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $notifications[] = [
                    'type' => (string)($row['notification_type'] ?? ''),
                    'recipient' => (string)($row['recipient_name'] ?? ''),
                    'trigger' => (string)($row['trigger_name'] ?? ''),
                    'status' => (string)($row['status_name'] ?? ''),
                    'sent_on' => (string)($row['sent_on'] ?? '')
                ];
            }
        }
    }
}

if (empty($notifications)) {
    $notifications = [
        [
            'type' => 'Approval Request',
            'recipient' => 'QA Head',
            'trigger' => 'Submit for Review',
            'status' => 'Sent',
            'sent_on' => '07-Apr-2026 10:58'
        ],
        [
            'type' => 'Overdue Review',
            'recipient' => 'Anita Rao',
            'trigger' => 'Review Date Passed',
            'status' => 'Queued',
            'sent_on' => '07-Apr-2026 09:00'
        ],
        [
            'type' => 'Read Acknowledgement',
            'recipient' => 'Employee Group',
            'trigger' => 'Document Effective',
            'status' => 'Sent',
            'sent_on' => '06-Apr-2026 16:12'
        ]
    ];
}

function formatNotificationDate($value): string {
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('d-M-Y H:i', $ts);
}

function notificationBadgeClass($status): string {
    $status = strtolower(trim((string)$status));
    if (in_array($status, ['sent', 'success', 'delivered'], true)) {
        return 'badge-soft-success';
    }
    if (in_array($status, ['queued', 'pending', 'waiting'], true)) {
        return 'badge-soft-warning';
    }
    if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
        return 'badge-soft-danger';
    }
    return 'badge-soft-secondary';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Notifications</title>
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
    .card-subtitle{
      color:#5f708c;
      font-size:15px;
      line-height:1.55;
    }
    .note-list{
      padding-left:1rem;
    }
    .badge-soft-success{
      background:rgba(25,135,84,.12);
      color:#198754;
    }
    .badge-soft-warning{
      background:rgba(255,193,7,.18);
      color:#9a6b00;
    }
    .badge-soft-danger{
      background:rgba(220,53,69,.12);
      color:#dc3545;
    }
    .badge-soft-secondary{
      background:rgba(108,117,125,.12);
      color:#6c757d;
    }
    .table > :not(caption) > * > *{
      padding:.9rem .75rem;
      vertical-align:middle;
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
        
        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="create-document.php">Create Document</a></li><li><a class="dropdown-item" href="update-document.php">Update Document</a></li><li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li><li><a class="dropdown-item" href="repository.php">Repository</a></li></ul></li>
        
        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Workflow</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="document-types.php">Document Types</a></li><li><a class="dropdown-item" href="document-id.php">Document ID</a></li><li><a class="dropdown-item" href="content-editor.php">Content Editor</a></li><li><a class="dropdown-item" href="form-builder.php">Form Builder</a></li><li><a class="dropdown-item" href="form-type-name.php">Form Type &amp; Name</a></li><li><a class="dropdown-item" href="approver-selection.php">Approver Selection</a></li><li><a class="dropdown-item" href="submit-review.php">Submit for Review</a></li><li><a class="dropdown-item" href="electronic-signature.php">Electronic Signature</a></li><li><a class="dropdown-item" href="approver-comments.php">Approver Comments</a></li><li><a class="dropdown-item" href="notifications.php">Notifications</a></li></ul></li>
        
        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a><ul class="dropdown-menu"><li><a class="dropdown-item" href="audit-creation.php">Audit - Creation</a></li><li><a class="dropdown-item" href="audit-approval.php">Audit - Approval</a></li><li><a class="dropdown-item" href="audit-comments.php">Audit - Comments</a></li><li><a class="dropdown-item" href="qa-admin.php">QA Admin</a></li><li><a class="dropdown-item" href="employee-role.php">Employee Role</a></li><li><a class="dropdown-item" href="super-admin.php">Super Admin</a></li><li><a class="dropdown-item" href="user-management.php">User Management</a></li><li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li></ul></li>
        
        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3"><span class="navbar-text small"><?php echo e($currentUserName); ?></span><a class="nav-link px-0" href="notifications.php">Notifications</a><span class="navbar-text small">Profile</span></div>
    </div>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">
<div class="mb-4">
<h1 class="page-title mb-2">Notifications &amp; Alerts</h1>
<p class="page-subtitle mb-0">Monitor approval requests, overdue reviews, acknowledgements, and important system events.</p>
</div>

<div class="row g-3">
<div class="col-lg-8">
<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Notifications Queue</h2>
<p class="card-subtitle mb-3">Monitor approval requests, overdue reviews, acknowledgements, and important system events.</p>

<div class="table-responsive">
<table class="table align-middle">
<thead>
<tr>
  <th>Type</th>
  <th>Recipient</th>
  <th>Trigger</th>
  <th>Status</th>
  <th>Sent On</th>
</tr>
</thead>
<tbody>
<?php if (!empty($notifications)): ?>
  <?php foreach ($notifications as $row): ?>
    <tr>
      <td><?php echo e($row['type']); ?></td>
      <td><?php echo e($row['recipient']); ?></td>
      <td><?php echo e($row['trigger']); ?></td>
      <td><span class="badge <?php echo e(notificationBadgeClass($row['status'])); ?>"><?php echo e($row['status']); ?></span></td>
      <td><?php echo e(formatNotificationDate($row['sent_on'])); ?></td>
    </tr>
  <?php endforeach; ?>
<?php else: ?>
  <tr>
    <td colspan="5" class="text-center text-muted py-4">No notifications found.</td>
  </tr>
<?php endif; ?>
</tbody>
</table>
</div>

</div></div>
</div>

<div class="col-lg-4">
<div class="card cp-card"><div class="card-body">
<h2 class="card-title mb-1">Developer Guidance</h2>
<ul class="small text-secondary note-list mb-0">
<li>Write email and in-app notification events to audit trail.</li>
<li>Keep retry / failure status visible to admins.</li>
<li>Template IDs and recipients should be traceable.</li>
</ul>
</div></div>
</div>
</div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>