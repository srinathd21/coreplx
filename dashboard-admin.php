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

if (!function_exists('formatDateOnly')) {
    function formatDateOnly($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime($datetime);
        return $ts ? date('d-M-Y', $ts) : '-';
    }
}

if (!function_exists('badgeClassByStatus')) {
    function badgeClassByStatus($status) {
        $status = strtolower(trim((string)$status));

        switch ($status) {
            case 'pending approval':
            case 'pending_approval':
            case 'pending':
                return 'badge-soft-warning';

            case 'overdue':
            case 'rejected':
                return 'badge-soft-danger';

            case 'in progress':
            case 'in_progress':
            case 'effective':
            case 'approved':
                return 'badge-soft-success';

            case 'draft':
            case 'returned':
            default:
                return 'badge-soft-secondary';
        }
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

/*
|--------------------------------------------------------------------------
| CURRENT ADMIN
|--------------------------------------------------------------------------
*/
$currentUser = null;

$userSql = "
    SELECT
        u.id,
        u.employee_code,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.current_role_id,
        u.department_id,
        u.status,
        u.last_login_at,
        r.role_code,
        r.role_name,
        d.department_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    LEFT JOIN departments d ON d.id = u.department_id
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

if (!$currentUser) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

$displayName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
if ($displayName === '') {
    $displayName = $_SESSION['admin_name'] ?? ($_SESSION['full_name'] ?? 'Admin');
}
$roleName = trim((string)($currentUser['role_name'] ?? ($_SESSION['role_name'] ?? 'QA Admin')));
$avatarLetter = strtoupper(substr($displayName, 0, 1));
if ($avatarLetter === '') {
    $avatarLetter = 'A';
}

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/
$documentsOwnedCount = 0;
$pendingApprovalCount = 0;
$reviewsDueCount = 0;
$unreadAlertsCount = 0;
$myDraftsCount = 0;
$effectiveRecordsCount = 0;

/* Documents owned by current admin */
$sql = "SELECT COUNT(*) AS total_count FROM documents WHERE owner_user_id = ?";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $documentsOwnedCount = (int)$row['total_count'];
    }
    mysqli_stmt_close($stmt);
}

/* My drafts */
$sql = "SELECT COUNT(*) AS total_count FROM document_versions WHERE created_by = ? AND status = 'draft'";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $myDraftsCount = (int)$row['total_count'];
    }
    mysqli_stmt_close($stmt);
}

/* Pending approvals assigned to current admin */
$sql = "SELECT COUNT(*) AS total_count FROM workflow_steps WHERE approver_user_id = ? AND status = 'pending'";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $pendingApprovalCount = (int)$row['total_count'];
    }
    mysqli_stmt_close($stmt);
} else {
    /* Fallback if workflow_steps table is not yet available */
    $pendingApprovalCount = 0;
}

/* Reviews due */
$sql = "
    SELECT COUNT(*) AS total_count
    FROM document_versions dv
    INNER JOIN documents d ON d.current_version_id = dv.id
    WHERE dv.review_date IS NOT NULL
      AND dv.review_date <= CURDATE()
      AND dv.status = 'effective'
";
$res = mysqli_query($conn, $sql);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $reviewsDueCount = (int)$row['total_count'];
}

/* Unread alerts */
$sql = "SELECT COUNT(*) AS total_count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $unreadAlertsCount = (int)$row['total_count'];
    }
    mysqli_stmt_close($stmt);
}

/* Effective records */
$sql = "
    SELECT COUNT(*) AS total_count
    FROM document_versions dv
    INNER JOIN documents d ON d.current_version_id = dv.id
    WHERE dv.status = 'effective'
";
$res = mysqli_query($conn, $sql);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $effectiveRecordsCount = (int)$row['total_count'];
}

/*
|--------------------------------------------------------------------------
| WORK QUEUE
|--------------------------------------------------------------------------
*/
$workQueueRows = [];

/*
 Try workflow_steps if available.
 Expected typical columns:
 - workflow_steps.document_version_id
 - workflow_steps.approver_user_id
 - workflow_steps.status
 - workflow_steps.due_at
 - workflow_steps.step_name
*/
$queueSql = "
    SELECT
        d.document_number,
        dv.title_snapshot,
        COALESCE(ws.step_name, 'Approval Required') AS task_name,
        ws.status,
        ws.due_at
    FROM workflow_steps ws
    INNER JOIN document_versions dv ON dv.id = ws.document_version_id
    INNER JOIN documents d ON d.id = dv.document_id
    WHERE ws.approver_user_id = ?
    ORDER BY
        CASE WHEN ws.status = 'pending' THEN 0 ELSE 1 END,
        ws.due_at ASC,
        ws.id DESC
    LIMIT 6
";
$queueStmt = mysqli_prepare($conn, $queueSql);
if ($queueStmt) {
    mysqli_stmt_bind_param($queueStmt, "i", $userId);
    mysqli_stmt_execute($queueStmt);
    $queueRes = mysqli_stmt_get_result($queueStmt);
    while ($queueRes && $row = mysqli_fetch_assoc($queueRes)) {
        $statusText = $row['status'] ?? 'pending';
        if ($statusText === 'pending') {
            $row['display_status'] = 'Pending Approval';
        } elseif ($statusText === 'approved') {
            $row['display_status'] = 'Approved';
        } elseif ($statusText === 'rejected') {
            $row['display_status'] = 'Rejected';
        } else {
            $row['display_status'] = ucfirst(str_replace('_', ' ', $statusText));
        }
        $workQueueRows[] = $row;
    }
    mysqli_stmt_close($queueStmt);
}

/*
|--------------------------------------------------------------------------
| RECENT ACTIVITY
|--------------------------------------------------------------------------
*/
$activityRows = [];

$activitySql = "
    SELECT
        al.action,
        al.entity_type,
        al.remarks,
        al.performed_at,
        d.document_number
    FROM audit_logs al
    LEFT JOIN documents d
        ON al.entity_type = 'document' AND al.entity_id = d.id
    WHERE al.performed_by = ?
    ORDER BY al.performed_at DESC
    LIMIT 6
";
$activityStmt = mysqli_prepare($conn, $activitySql);
if ($activityStmt) {
    mysqli_stmt_bind_param($activityStmt, "i", $userId);
    mysqli_stmt_execute($activityStmt);
    $activityRes = mysqli_stmt_get_result($activityStmt);
    while ($activityRes && $row = mysqli_fetch_assoc($activityRes)) {
        $activityRows[] = $row;
    }
    mysqli_stmt_close($activityStmt);
}

/*
|--------------------------------------------------------------------------
| RECENT DOCUMENTS
|--------------------------------------------------------------------------
*/
$recentDocuments = [];
$recentDocsSql = "
    SELECT
        d.document_number,
        dt.type_name,
        dv.title_snapshot,
        dv.status
    FROM document_versions dv
    INNER JOIN documents d ON d.id = dv.document_id
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    ORDER BY dv.updated_at DESC, dv.id DESC
    LIMIT 3
";
$recentDocsRes = mysqli_query($conn, $recentDocsSql);
if ($recentDocsRes) {
    while ($row = mysqli_fetch_assoc($recentDocsRes)) {
        $recentDocuments[] = $row;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .badge-soft-success{background:rgba(25,135,84,.12);color:#198754}
    .badge-soft-warning{background:rgba(255,193,7,.18);color:#8a6d03}
    .badge-soft-danger{background:rgba(220,53,69,.12);color:#dc3545}
    .badge-soft-secondary{background:rgba(108,117,125,.12);color:#6c757d}
    .badge{padding:.45rem .7rem;border-radius:999px;font-weight:600}
    .activity-empty,.repo-empty{color:#6c757d;font-size:.95rem}
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
  <div class="content-wrap px-4 px-xxl-5 mx-auto">
    <div class="mb-4 mt-3">
      <h1 class="page-title mb-2">Welcome back, <?php echo e($displayName); ?></h1>
      <p class="page-subtitle mb-0">Manage document activity, pending actions, and repository access from your workspace.</p>
    </div>

    <div class="row g-3 g-xxl-4 mb-4 align-items-stretch">
      <div class="col-xl-3">
        <div class="card cp-card dashboard-user-card h-100">
          <div class="card-body p-4 d-flex flex-column">
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="user-avatar"><?php echo e($avatarLetter); ?></div>
              <div>
                <div class="fw-bold fs-5"><?php echo e($displayName); ?></div>
                <div class="text-secondary small"><?php echo e($roleName); ?></div>
              </div>
            </div>

            <div class="user-meta-grid mb-4">
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo $documentsOwnedCount; ?></div>
                <div class="user-meta-label">Documents Owned</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo $pendingApprovalCount; ?></div>
                <div class="user-meta-label">Pending Approvals</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo $reviewsDueCount; ?></div>
                <div class="user-meta-label">Overdue Reviews</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo $unreadAlertsCount; ?></div>
                <div class="user-meta-label">Unread Alerts</div>
              </div>
            </div>

            <div class="section-kicker mb-2">Workspace</div>
            <ul class="dashboard-link-list list-unstyled mb-0 mt-auto" style="font-size:0.78rem;">
              <li style="margin-bottom:4px;"><a href="create-document.php" style="text-decoration:none;">New Document</a></li>
              <li style="margin-bottom:4px;"><a href="submit-review.php" style="text-decoration:none;">Review Queue</a></li>
              <li><a href="notifications.php" style="text-decoration:none;">Notifications</a></li>
            </ul>
          </div>
        </div>
      </div>

      <div class="col-xl-9">
        <div class="dashboard-hero-stack h-100 d-flex flex-column gap-3">
          <div class="card cp-card search-card">
            <div class="card-body p-4">
              <div class="search-label mb-2">Global Search</div>
              <form action="repository.php" method="get">
                <div class="input-group input-group-lg search-group">
                  <span class="input-group-text bg-white border-end-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>
                  </span>
                  <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Search documents, IDs, owners, or keywords">
                </div>
              </form>
            </div>
          </div>

          <div id="dashboardCarousel" class="carousel slide h-100" data-bs-ride="carousel">
            <div class="carousel-indicators dashboard-indicators">
              <button type="button" data-bs-target="#dashboardCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
              <button type="button" data-bs-target="#dashboardCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
              <button type="button" data-bs-target="#dashboardCarousel" data-bs-slide-to="2" aria-label="Slide 3"></button>
            </div>
            <div class="carousel-inner hero-carousel cp-card h-100">
              <div class="carousel-item active h-100">
                <div class="hero-banner hero-banner-review h-100">
                  <div>
                    <div class="hero-eyebrow">Attention Required</div>
                    <h2 class="hero-title"><?php echo $reviewsDueCount; ?> documents are overdue for periodic review</h2>
                    <p class="hero-copy mb-0">Open the review queue and assign owners before compliance due dates are missed.</p>
                  </div>
                  <a href="submit-review.php" class="btn btn-light hero-btn">Open Review Queue</a>
                </div>
              </div>
              <div class="carousel-item h-100">
                <div class="hero-banner hero-banner-policy h-100">
                  <div>
                    <div class="hero-eyebrow">Repository Update</div>
                    <h2 class="hero-title"><?php echo $unreadAlertsCount; ?> unread notifications are waiting in your workspace</h2>
                    <p class="hero-copy mb-0">Check new workflow actions, comments, and repository notifications.</p>
                  </div>
                  <a href="notifications.php" class="btn btn-light hero-btn">View Notifications</a>
                </div>
              </div>
              <div class="carousel-item h-100">
                <div class="hero-banner hero-banner-search h-100">
                  <div>
                    <div class="hero-eyebrow">Search Tip</div>
                    <h2 class="hero-title">Find documents faster with ID, owner, or keyword search</h2>
                    <p class="hero-copy mb-0">Use the workspace search bar to jump directly to drafts, approvals, and effective records.</p>
                  </div>
                  <a href="document-id.php" class="btn btn-light hero-btn">Open Document ID Rules</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 g-xxl-4 mb-4">
      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100">
          <div class="card-body p-4">
            <div class="section-kicker mb-2">My Drafts</div>
            <div class="stat-value"><?php echo $myDraftsCount; ?></div>
            <div class="stat-note">Draft documents still editable before submission.</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100">
          <div class="card-body p-4">
            <div class="section-kicker mb-2">Pending Approval</div>
            <div class="stat-value"><?php echo $pendingApprovalCount; ?></div>
            <div class="stat-note">Items waiting for your review or approval.</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100">
          <div class="card-body p-4">
            <div class="section-kicker mb-2">Reviews Due</div>
            <div class="stat-value"><?php echo $reviewsDueCount; ?></div>
            <div class="stat-note">Documents approaching or past review date.</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100">
          <div class="card-body p-4">
            <div class="section-kicker mb-2">Effective Records</div>
            <div class="stat-value"><?php echo $effectiveRecordsCount; ?></div>
            <div class="stat-note">Approved records currently published in repository.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 g-xxl-4 mb-4">
      <div class="col-xl-8">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
              <div>
                <h2 class="card-title mb-1">My Work Queue</h2>
                <p class="card-subtitle mb-0">Items that require your action today.</p>
              </div>
              <a href="submit-review.php" class="btn btn-sm btn-outline-primary">Open Queue</a>
            </div>

            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th>Document ID</th>
                    <th>Title</th>
                    <th>Task</th>
                    <th>Status</th>
                    <th>Due</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($workQueueRows)): ?>
                    <?php foreach ($workQueueRows as $row): ?>
                      <tr>
                        <td class="fw-semibold"><?php echo e($row['document_number'] ?: '-'); ?></td>
                        <td><?php echo e($row['title_snapshot'] ?: '-'); ?></td>
                        <td><?php echo e($row['task_name'] ?: 'Approval Required'); ?></td>
                        <td>
                          <span class="badge <?php echo e(badgeClassByStatus($row['display_status'])); ?>">
                            <?php echo e($row['display_status']); ?>
                          </span>
                        </td>
                        <td><?php echo e(formatDateOnly($row['due_at'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-4">No work queue items found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>

      <div class="col-xl-4">
        <div class="card cp-card h-100">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Recent Activity</h2>
            <p class="card-subtitle mb-3">Latest recorded actions in your workspace.</p>

            <div class="activity-list small">
              <?php if (!empty($activityRows)): ?>
                <?php foreach ($activityRows as $activity): ?>
                  <?php
                    $timeText = !empty($activity['performed_at']) ? date('H:i', strtotime($activity['performed_at'])) : '--:--';
                    $actionText = ucwords(str_replace(['_', '-'], ' ', (string)($activity['action'] ?? 'activity')));
                    $messageText = trim((string)($activity['remarks'] ?? ''));

                    if ($messageText === '') {
                        if (!empty($activity['document_number'])) {
                            $messageText = $activity['document_number'] . ' action recorded in audit log.';
                        } else {
                            $messageText = 'Activity recorded in audit log.';
                        }
                    }
                  ?>
                  <div class="activity-item">
                    <span class="activity-time"><?php echo e($timeText); ?></span>
                    <div>
                      <div class="fw-semibold"><?php echo e($actionText); ?></div>
                      <div class="text-secondary"><?php echo e($messageText); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="activity-empty">No recent activity found.</div>
              <?php endif; ?>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 g-xxl-4">
      <div class="col-xl-8">
        <div class="card cp-card">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
              <div>
                <h2 class="card-title mb-1">Recent Documents</h2>
                <p class="card-subtitle mb-0">Recently updated and published records.</p>
              </div>
              <a href="repository.php" class="btn btn-sm btn-outline-primary">Open Repository</a>
            </div>

            <div class="row g-3">
              <?php if (!empty($recentDocuments)): ?>
                <?php foreach ($recentDocuments as $doc): ?>
                  <?php
                    $statusText = ucfirst(str_replace('_', ' ', (string)($doc['status'] ?? 'draft')));
                    $statusClass = badgeClassByStatus($statusText);
                  ?>
                  <div class="col-md-4">
                    <div class="repo-box h-100">
                      <div class="small text-secondary mb-2"><?php echo e($doc['type_name'] ?: 'Document'); ?></div>
                      <div class="fw-semibold mb-2"><?php echo e($doc['title_snapshot'] ?: '-'); ?></div>
                      <div class="small text-secondary mb-3"><?php echo e($doc['document_number'] ?: '-'); ?></div>
                      <span class="badge <?php echo e($statusClass); ?>"><?php echo e($statusText); ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="col-12">
                  <div class="repo-empty">No recent documents found.</div>
                </div>
              <?php endif; ?>
            </div>

          </div>
        </div>
      </div>

      <div class="col-xl-4">
        <div class="card cp-card">
          <div class="card-body p-4">
            <h2 class="card-title mb-1">Quick Actions</h2>
            <p class="card-subtitle mb-3">Start the most common tasks from one place.</p>
            <div class="d-grid gap-2">
              <a class="btn btn-primary" href="create-document.php">Create Document</a>
              <a class="btn btn-outline-primary" href="update-document.php">Update Document</a>
              <a class="btn btn-outline-primary" href="repository.php">Open Repository</a>
              <a class="btn btn-outline-primary" href="audit-creation.php">View Audit Trail</a>
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