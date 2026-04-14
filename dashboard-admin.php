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

if (!function_exists('safeCountQuery')) {
    function safeCountQuery(mysqli $conn, $sql, $types = '', $params = []) {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return 0;
        }

        if ($types !== '' && !empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return 0;
        }

        $res = mysqli_stmt_get_result($stmt);
        $count = 0;
        if ($res && ($row = mysqli_fetch_row($res))) {
            $count = (int)($row[0] ?? 0);
        }

        mysqli_stmt_close($stmt);
        return $count;
    }
}

if (!function_exists('safeRowsQuery')) {
    function safeRowsQuery(mysqli $conn, $sql, $types = '', $params = []) {
        $rows = [];
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return $rows;
        }

        if ($types !== '' && !empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return $rows;
        }

        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
        }

        mysqli_stmt_close($stmt);
        return $rows;
    }
}

if (!function_exists('formatDateDisplay')) {
    function formatDateDisplay($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime((string)$date);
        return $ts ? date('d-M-Y', $ts) : (string)$date;
    }
}

if (!function_exists('formatTimeDisplay')) {
    function formatTimeDisplay($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime((string)$datetime);
        return $ts ? date('H:i', $ts) : '-';
    }
}

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass($status, $reviewDate = '') {
        $status = strtolower(trim((string)$status));

        if ($status === 'pending_approval' || $status === 'pending approval' || $status === 'pending review' || $status === 'pending') {
            return 'badge badge-soft-warning';
        }
        if ($status === 'draft') return 'badge badge-soft-secondary';
        if ($status === 'pending_retirement') return 'badge badge-soft-warning';
        if ($status === 'retired') return 'badge badge-soft-secondary';
        if ($status === 'deleted') return 'badge badge-soft-danger';
        if ($status === 'in_progress') return 'badge badge-soft-info';

        if ($reviewDate !== '' && $reviewDate !== '0000-00-00') {
            $reviewTs = strtotime($reviewDate);
            if ($reviewTs && $reviewTs < strtotime(date('Y-m-d'))) {
                return 'badge badge-soft-danger';
            }
        }

        return 'badge badge-soft-success';
    }
}

if (!function_exists('displayStatusLabel')) {
    function displayStatusLabel($status, $reviewDate = '') {
        $status = strtolower(trim((string)$status));

        if (in_array($status, ['effective', 'approved'], true)) {
            if ($reviewDate !== '' && $reviewDate !== '0000-00-00') {
                $reviewTs = strtotime($reviewDate);
                if ($reviewTs && $reviewTs < strtotime(date('Y-m-d'))) {
                    return 'Overdue';
                }
            }
            return 'Effective';
        }

        if (in_array($status, ['pending_approval', 'pending approval', 'pending review', 'pending'], true)) {
            return 'Pending Approval';
        }

        if ($status === 'draft') return 'Draft';
        if ($status === 'in_progress') return 'In Progress';
        if ($status === 'pending_retirement') return 'Pending Retirement';
        if ($status === 'retired') return 'Retired';
        if ($status === 'deleted') return 'Deleted';

        return $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Effective';
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
        u.email,
        u.employee_code,
        u.department_id,
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

if (!$currentUser) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

$displayName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
if ($displayName === '') {
    $displayName = $_SESSION['admin_name'] ?? 'QA Admin';
}
$roleName = trim((string)($currentUser['role_name'] ?? 'QA Admin'));
$userInitial = strtoupper(substr($displayName, 0, 1));

$documentsOwned = 0;
$pendingApprovals = 0;
$overdueReviews = 0;
$unreadAlerts = 0;
$myDrafts = 0;
$effectiveRecords = 0;

if (tableExists($conn, 'documents')) {
    if (columnExists($conn, 'documents', 'owner_user_id')) {
        $documentsOwned = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM documents WHERE owner_user_id = ? AND LOWER(COALESCE(current_status,'')) <> 'deleted'",
            "i",
            [$userId]
        );
    } elseif (columnExists($conn, 'documents', 'created_by')) {
        $documentsOwned = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM documents WHERE created_by = ? AND LOWER(COALESCE(current_status,'')) <> 'deleted'",
            "i",
            [$userId]
        );
    }

    $myDrafts = safeCountQuery(
        $conn,
        "SELECT COUNT(*) FROM documents WHERE LOWER(TRIM(COALESCE(current_status,''))) = 'draft'"
    );

    $effectiveRecords = safeCountQuery(
        $conn,
        "SELECT COUNT(*) FROM documents WHERE LOWER(TRIM(COALESCE(current_status,'effective'))) IN ('effective','approved')"
    );
}

if (tableExists($conn, 'document_approvals')) {
    $statusCondition = "
        LOWER(TRIM(COALESCE(status,''))) IN ('pending review','pending approval','pending')
        OR LOWER(TRIM(COALESCE(meaning,''))) IN ('pending review','pending approval','pending')
    ";

    if (columnExists($conn, 'document_approvals', 'approver_id')) {
        $pendingApprovals = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM document_approvals WHERE approver_id = ? AND ({$statusCondition})",
            "i",
            [$userId]
        );
    } elseif (columnExists($conn, 'document_approvals', 'approved_by')) {
        $pendingApprovals = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM document_approvals WHERE approved_by = ? AND ({$statusCondition})",
            "i",
            [$userId]
        );
    } else {
        $pendingApprovals = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM document_approvals WHERE {$statusCondition}"
        );
    }
}

if ($pendingApprovals === 0 && tableExists($conn, 'documents')) {
    if (columnExists($conn, 'documents', 'approver')) {
        $pendingApprovals = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM documents
             WHERE TRIM(COALESCE(approver,'')) = ?
               AND LOWER(TRIM(COALESCE(current_status,''))) IN ('pending_approval','pending approval','pending review','pending')",
            "s",
            [(string)$userId]
        );
    }
}

if (tableExists($conn, 'document_versions') && columnExists($conn, 'document_versions', 'review_date')) {
    $overdueReviews = safeCountQuery(
        $conn,
        "SELECT COUNT(*)
         FROM document_versions dv
         INNER JOIN documents d ON d.current_version_id = dv.id
         WHERE dv.review_date IS NOT NULL
           AND dv.review_date <> '0000-00-00'
           AND dv.review_date < CURDATE()
           AND LOWER(COALESCE(d.current_status,'effective')) NOT IN ('deleted','retired')"
    );
}

if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        $unreadAlerts = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND COALESCE(is_read,0) = 0",
            "i",
            [$userId]
        );
    } elseif (columnExists($conn, 'notifications', 'status')) {
        $unreadAlerts = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND LOWER(TRIM(COALESCE(status,''))) IN ('unread','new','pending')",
            "i",
            [$userId]
        );
    } else {
        $unreadAlerts = safeCountQuery(
            $conn,
            "SELECT COUNT(*) FROM notifications WHERE user_id = ?",
            "i",
            [$userId]
        );
    }
}

$workQueue = [];
if (tableExists($conn, 'documents') && tableExists($conn, 'document_versions')) {
    $workQueueSql = "
        SELECT
            d.id,
            d.document_number,
            d.title,
            d.topic,
            d.current_status,
            dv.review_date,
            CASE
                WHEN LOWER(TRIM(COALESCE(d.current_status,''))) IN ('pending_approval','pending approval','pending review','pending') THEN 'Approval Required'
                WHEN dv.review_date IS NOT NULL AND dv.review_date <> '0000-00-00' AND dv.review_date < CURDATE() THEN 'Periodic Review'
                WHEN LOWER(TRIM(COALESCE(d.current_status,''))) = 'draft' THEN 'Draft Review'
                ELSE 'General Review'
            END AS task_name
        FROM documents d
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE LOWER(TRIM(COALESCE(d.current_status,''))) IN ('draft','pending_approval','pending approval','pending review','pending','effective','approved')
        ORDER BY
            CASE
                WHEN LOWER(TRIM(COALESCE(d.current_status,''))) IN ('pending_approval','pending approval','pending review','pending') THEN 1
                WHEN dv.review_date IS NOT NULL AND dv.review_date <> '0000-00-00' AND dv.review_date < CURDATE() THEN 2
                WHEN LOWER(TRIM(COALESCE(d.current_status,''))) = 'draft' THEN 3
                ELSE 4
            END,
            d.id DESC
        LIMIT 4
    ";
    $workQueue = safeRowsQuery($conn, $workQueueSql);
}

$recentActivity = [];
if (tableExists($conn, 'audit_logs')) {
    $recentActivitySql = "
        SELECT
            action,
            remarks,
            created_at
        FROM audit_logs
        ORDER BY id DESC
        LIMIT 4
    ";
    if (columnExists($conn, 'audit_logs', 'created_at')) {
        $recentActivity = safeRowsQuery($conn, $recentActivitySql);
    } elseif (columnExists($conn, 'audit_logs', 'performed_at')) {
        $recentActivitySql = "
            SELECT
                action,
                remarks,
                performed_at AS created_at
            FROM audit_logs
            ORDER BY id DESC
            LIMIT 4
        ";
        $recentActivity = safeRowsQuery($conn, $recentActivitySql);
    }
}

$recentDocuments = [];
if (tableExists($conn, 'documents')) {
    $recentDocumentsSql = "
        SELECT
            d.id,
            d.document_number,
            d.title,
            d.topic,
            d.current_status,
            dt.type_name,
            dv.review_date
        FROM documents d
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        WHERE LOWER(COALESCE(d.current_status,'')) <> 'deleted'
        ORDER BY d.id DESC
        LIMIT 3
    ";
    $recentDocuments = safeRowsQuery($conn, $recentDocumentsSql);
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
      <div class="d-flex align-items-center gap-3 ms-xl-3"><span class="navbar-text small"><?php echo e($roleName); ?></span><a class="nav-link px-0" href="notifications.php">Notifications</a><span class="navbar-text small"><?php echo e($displayName); ?></span></div>
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
              <div class="user-avatar"><?php echo e($userInitial); ?></div>
              <div>
                <div class="fw-bold fs-5"><?php echo e($displayName); ?></div>
                <div class="text-secondary small"><?php echo e($roleName); ?></div>
              </div>
            </div>

            <div class="user-meta-grid mb-4">
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo (int)$documentsOwned; ?></div>
                <div class="user-meta-label">Documents Owned</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo (int)$pendingApprovals; ?></div>
                <div class="user-meta-label">Pending Approvals</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo (int)$overdueReviews; ?></div>
                <div class="user-meta-label">Overdue Reviews</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo (int)$unreadAlerts; ?></div>
                <div class="user-meta-label">Unread Alerts</div>
              </div>
            </div>

            <div class="section-kicker mb-2">Quick Actions</div>
            <div class="d-grid gap-2 mt-auto">
              <a class="btn btn-primary" href="create-document.php">Create Document</a>
              <a class="btn btn-outline-primary" href="update-document.php">Update Document</a>
              <a class="btn btn-outline-primary" href="submit-review.php">Open Review Queue</a>
              <a class="btn btn-outline-primary" href="notifications.php">View Notifications</a>
            </div>
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
                    <h2 class="hero-title"><?php echo (int)$overdueReviews; ?> documents are overdue for periodic review</h2>
                    <p class="hero-copy mb-0">Open the review queue and assign owners before compliance due dates are missed.</p>
                  </div>
                  <a href="submit-review.php" class="btn btn-light hero-btn">Open Review Queue</a>
                </div>
              </div>
              <div class="carousel-item h-100">
                <div class="hero-banner hero-banner-policy h-100">
                  <div>
                    <div class="hero-eyebrow">Repository Update</div>
                    <h2 class="hero-title"><?php echo (int)$unreadAlerts; ?> unread notifications are waiting for action</h2>
                    <p class="hero-copy mb-0">Check alerts for approvals, comments, and new acknowledgement items.</p>
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
        <div class="card cp-card stat-card h-100"><div class="card-body p-4">
          <div class="section-kicker mb-2">My Drafts</div>
          <div class="stat-value"><?php echo (int)$myDrafts; ?></div>
          <div class="stat-note">Draft documents still editable before submission.</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100"><div class="card-body p-4">
          <div class="section-kicker mb-2">Pending Approval</div>
          <div class="stat-value"><?php echo (int)$pendingApprovals; ?></div>
          <div class="stat-note">Items waiting for your review or approval.</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100"><div class="card-body p-4">
          <div class="section-kicker mb-2">Reviews Due</div>
          <div class="stat-value"><?php echo (int)$overdueReviews; ?></div>
          <div class="stat-note">Documents approaching or past review date.</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100"><div class="card-body p-4">
          <div class="section-kicker mb-2">Effective Records</div>
          <div class="stat-value"><?php echo (int)$effectiveRecords; ?></div>
          <div class="stat-note">Approved records currently published in repository.</div>
        </div></div>
      </div>
    </div>

    <div class="row g-3 g-xxl-4 mb-4">
      <div class="col-xl-8">
        <div class="card cp-card h-100"><div class="card-body p-4">
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
                <tr><th>Document ID</th><th>Title</th><th>Task</th><th>Status</th><th>Due</th></tr>
              </thead>
              <tbody>
                <?php if (!empty($workQueue)): ?>
                  <?php foreach ($workQueue as $item): ?>
                    <?php
                      $title = $item['title'] ?: ($item['topic'] ?: 'Untitled');
                      $statusLabel = displayStatusLabel($item['current_status'] ?? '', $item['review_date'] ?? '');
                      $statusClass = statusBadgeClass($item['current_status'] ?? '', $item['review_date'] ?? '');
                    ?>
                    <tr>
                      <td class="fw-semibold"><?php echo e($item['document_number'] ?: ('DOC-' . (int)$item['id'])); ?></td>
                      <td><?php echo e($title); ?></td>
                      <td><?php echo e($item['task_name'] ?? 'General Review'); ?></td>
                      <td><span class="<?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span></td>
                      <td><?php echo e(formatDateDisplay($item['review_date'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" class="text-center text-secondary py-4">No work queue items found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div></div>
      </div>

      <div class="col-xl-4">
        <div class="card cp-card h-100"><div class="card-body p-4">
          <h2 class="card-title mb-1">Recent Activity</h2>
          <p class="card-subtitle mb-3">Latest recorded actions in your workspace.</p>
          <div class="activity-list small">
            <?php if (!empty($recentActivity)): ?>
              <?php foreach ($recentActivity as $activity): ?>
                <div class="activity-item">
                  <span class="activity-time"><?php echo e(formatTimeDisplay($activity['created_at'] ?? '')); ?></span>
                  <div>
                    <div class="fw-semibold"><?php echo e(ucwords(str_replace('_', ' ', (string)($activity['action'] ?? 'Activity')))); ?></div>
                    <div class="text-secondary"><?php echo e($activity['remarks'] ?: 'Activity recorded in audit log.'); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-secondary small">No recent activity found.</div>
            <?php endif; ?>
          </div>
        </div></div>
      </div>
    </div>

    <div class="row g-3 g-xxl-4">
      <div class="col-xl-12">
        <div class="card cp-card"><div class="card-body p-4">
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
                  $title = $doc['title'] ?: ($doc['topic'] ?: 'Untitled');
                  $docType = $doc['type_name'] ?: 'Document';
                  $statusLabel = displayStatusLabel($doc['current_status'] ?? '', $doc['review_date'] ?? '');
                  $statusClass = statusBadgeClass($doc['current_status'] ?? '', $doc['review_date'] ?? '');
                ?>
                <div class="col-md-4">
                  <div class="repo-box h-100">
                    <div class="small text-secondary mb-2"><?php echo e($docType); ?></div>
                    <div class="fw-semibold mb-2"><?php echo e($title); ?></div>
                    <div class="small text-secondary mb-3"><?php echo e($doc['document_number'] ?: ('DOC-' . (int)$doc['id'])); ?></div>
                    <span class="<?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="col-12">
                <div class="text-secondary">No recent documents found.</div>
              </div>
            <?php endif; ?>
          </div>
        </div></div>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>