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
            case 'pending':
                return 'badge-soft-warning';
            case 'overdue':
                return 'badge-soft-danger';
            case 'acknowledged':
            case 'effective':
            case 'approved':
                return 'badge-soft-success';
            case 'waived':
            case 'inactive':
            default:
                return 'badge-soft-secondary';
        }
    }
}

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header('Location: login-employee.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
    session_destroy();
    header('Location: login-employee.php');
    exit;
}

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
    header('Location: login-employee.php');
    exit;
}

$displayName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
if ($displayName === '') {
    $displayName = $_SESSION['employee_name'] ?? ($_SESSION['full_name'] ?? 'Employee');
}
$avatarLetter = strtoupper(substr(trim($displayName), 0, 1));
if ($avatarLetter === '') {
    $avatarLetter = 'E';
}

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/
$assignedDocumentsCount = 0;
$pendingAcknowledgementsCount = 0;
$overdueCount = 0;
$completedThisMonthCount = 0;
$effectiveRecordsCount = 0;

$countSql = "
    SELECT
        COUNT(*) AS assigned_documents,
        SUM(CASE WHEN aa.status = 'pending' THEN 1 ELSE 0 END) AS pending_ack,
        SUM(CASE WHEN aa.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_ack,
        SUM(
            CASE
                WHEN aa.status = 'acknowledged'
                 AND MONTH(ae.acknowledged_at) = MONTH(CURDATE())
                 AND YEAR(ae.acknowledged_at) = YEAR(CURDATE())
                THEN 1 ELSE 0
            END
        ) AS completed_this_month
    FROM acknowledgement_assignments aa
    LEFT JOIN acknowledgement_events ae
        ON ae.acknowledgement_assignment_id = aa.id
    WHERE aa.assigned_user_id = ?
";
$countStmt = mysqli_prepare($conn, $countSql);
if ($countStmt) {
    mysqli_stmt_bind_param($countStmt, "i", $userId);
    mysqli_stmt_execute($countStmt);
    $countRes = mysqli_stmt_get_result($countStmt);
    $countRow = $countRes ? mysqli_fetch_assoc($countRes) : null;
    mysqli_stmt_close($countStmt);

    if ($countRow) {
        $assignedDocumentsCount       = (int)($countRow['assigned_documents'] ?? 0);
        $pendingAcknowledgementsCount = (int)($countRow['pending_ack'] ?? 0);
        $overdueCount                 = (int)($countRow['overdue_ack'] ?? 0);
        $completedThisMonthCount      = (int)($countRow['completed_this_month'] ?? 0);
    }
}

$effectiveSql = "
    SELECT COUNT(*) AS total_effective
    FROM document_versions dv
    INNER JOIN documents d ON d.id = dv.document_id
    WHERE dv.status = 'effective'
      AND d.current_status IN ('effective', 'pending_retirement')
";
$effectiveRes = mysqli_query($conn, $effectiveSql);
if ($effectiveRes && $effectiveRow = mysqli_fetch_assoc($effectiveRes)) {
    $effectiveRecordsCount = (int)($effectiveRow['total_effective'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| PENDING ACKNOWLEDGEMENTS TABLE
|--------------------------------------------------------------------------
*/
$pendingRows = [];
$pendingSql = "
    SELECT
        aa.id,
        aa.status,
        aa.due_at,
        dv.version_label,
        dv.title_snapshot,
        d.document_number
    FROM acknowledgement_assignments aa
    INNER JOIN document_versions dv ON dv.id = aa.document_version_id
    INNER JOIN documents d ON d.id = dv.document_id
    WHERE aa.assigned_user_id = ?
      AND aa.status IN ('pending', 'overdue')
    ORDER BY
        CASE WHEN aa.status = 'overdue' THEN 0 ELSE 1 END,
        aa.due_at ASC,
        aa.id DESC
    LIMIT 6
";
$pendingStmt = mysqli_prepare($conn, $pendingSql);
if ($pendingStmt) {
    mysqli_stmt_bind_param($pendingStmt, "i", $userId);
    mysqli_stmt_execute($pendingStmt);
    $pendingRes = mysqli_stmt_get_result($pendingStmt);
    while ($pendingRes && $row = mysqli_fetch_assoc($pendingRes)) {
        $pendingRows[] = $row;
    }
    mysqli_stmt_close($pendingStmt);
}

/*
|--------------------------------------------------------------------------
| RECENT ACTIVITY
|--------------------------------------------------------------------------
*/
$activityRows = [];

$activitySql = "
    SELECT
        'acknowledged' AS activity_type,
        ae.acknowledged_at AS activity_time,
        dv.title_snapshot,
        dv.version_label,
        d.document_number,
        aa.status
    FROM acknowledgement_events ae
    INNER JOIN acknowledgement_assignments aa ON aa.id = ae.acknowledgement_assignment_id
    INNER JOIN document_versions dv ON dv.id = ae.document_version_id
    INNER JOIN documents d ON d.id = dv.document_id
    WHERE ae.user_id = ?

    UNION ALL

    SELECT
        'assignment' AS activity_type,
        aa.assigned_at AS activity_time,
        dv.title_snapshot,
        dv.version_label,
        d.document_number,
        aa.status
    FROM acknowledgement_assignments aa
    INNER JOIN document_versions dv ON dv.id = aa.document_version_id
    INNER JOIN documents d ON d.id = dv.document_id
    WHERE aa.assigned_user_id = ?

    ORDER BY activity_time DESC
    LIMIT 6
";
$activityStmt = mysqli_prepare($conn, $activitySql);
if ($activityStmt) {
    mysqli_stmt_bind_param($activityStmt, "ii", $userId, $userId);
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
        dv.version_label,
        CASE
            WHEN aa.status = 'acknowledged' THEN 'Acknowledged'
            WHEN aa.status = 'pending' THEN 'Pending Acknowledgement'
            WHEN aa.status = 'overdue' THEN 'Overdue'
            WHEN dv.status = 'effective' THEN 'Effective'
            ELSE dv.status
        END AS display_status
    FROM acknowledgement_assignments aa
    INNER JOIN document_versions dv ON dv.id = aa.document_version_id
    INNER JOIN documents d ON d.id = dv.document_id
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    WHERE aa.assigned_user_id = ?
    ORDER BY aa.assigned_at DESC
    LIMIT 3
";
$recentDocsStmt = mysqli_prepare($conn, $recentDocsSql);
if ($recentDocsStmt) {
    mysqli_stmt_bind_param($recentDocsStmt, "i", $userId);
    mysqli_stmt_execute($recentDocsStmt);
    $recentDocsRes = mysqli_stmt_get_result($recentDocsStmt);
    while ($recentDocsRes && $row = mysqli_fetch_assoc($recentDocsRes)) {
        $recentDocuments[] = $row;
    }
    mysqli_stmt_close($recentDocsStmt);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Employee Dashboard</title>
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
    <a class="navbar-brand fw-bold" href="dashboard-employee.php">CorePlx Quality DMS</a>
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item"><a class="nav-link active" href="dashboard-employee.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="repository.php">Repository</a></li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Acknowledgements</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="pending-acknowledgements.php">Pending Acknowledgements</a></li>
            <li><a class="dropdown-item" href="my-acknowledgements.php">My Acknowledgements</a></li>
          </ul>
        </li>

        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to Admin</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small">Employee</span>
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
      <p class="page-subtitle mb-0">View assigned documents, complete acknowledgements, and access the repository from your workspace.</p>
    </div>

    <div class="row g-3 g-xxl-4 mb-4 align-items-stretch">
      <div class="col-xl-3">
        <div class="card cp-card dashboard-user-card h-100">
          <div class="card-body p-4 d-flex flex-column">
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="user-avatar"><?php echo e($avatarLetter); ?></div>
              <div>
                <div class="fw-bold fs-5"><?php echo e($displayName); ?></div>
                <div class="text-secondary small"><?php echo e($currentUser['role_name'] ?: 'Employee'); ?></div>
              </div>
            </div>

            <div class="user-meta-grid mb-4">
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo $assignedDocumentsCount; ?></div>
                <div class="user-meta-label">Assigned Documents</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo $pendingAcknowledgementsCount; ?></div>
                <div class="user-meta-label">Pending Acknowledgements</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo $overdueCount; ?></div>
                <div class="user-meta-label">Overdue</div>
              </div>
              <div class="user-meta-box">
                <div class="user-meta-value"><?php echo $completedThisMonthCount; ?></div>
                <div class="user-meta-label">Completed This Month</div>
              </div>
            </div>

            <div class="section-kicker mb-2">Workspace</div>
            <ul class="dashboard-link-list list-unstyled mb-0 mt-auto" style="font-size:0.78rem;">
              <li style="margin-bottom:4px;"><a href="pending-acknowledgements.php" style="text-decoration:none;">Pending Acknowledgements</a></li>
              <li style="margin-bottom:4px;"><a href="repository.php" style="text-decoration:none;">Document Repository</a></li>
              <li><a href="my-acknowledgements.php" style="text-decoration:none;">My Acknowledgements</a></li>
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
                  <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Search documents, IDs, or keywords">
                </div>
              </form>
            </div>
          </div>

          <div id="dashboardCarouselEmployee" class="carousel slide h-100" data-bs-ride="carousel">
            <div class="carousel-indicators dashboard-indicators">
              <button type="button" data-bs-target="#dashboardCarouselEmployee" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
              <button type="button" data-bs-target="#dashboardCarouselEmployee" data-bs-slide-to="1" aria-label="Slide 2"></button>
              <button type="button" data-bs-target="#dashboardCarouselEmployee" data-bs-slide-to="2" aria-label="Slide 3"></button>
            </div>
            <div class="carousel-inner hero-carousel cp-card h-100">
              <div class="carousel-item active h-100">
                <div class="hero-banner hero-banner-review h-100">
                  <div>
                    <div class="hero-eyebrow">Action Required</div>
                    <h2 class="hero-title"><?php echo $pendingAcknowledgementsCount; ?> documents are pending acknowledgement</h2>
                    <p class="hero-copy mb-0">Open your assigned items and confirm the latest effective versions.</p>
                  </div>
                  <a href="pending-acknowledgements.php" class="btn btn-light hero-btn">Open Pending Acknowledgements</a>
                </div>
              </div>
              <div class="carousel-item h-100">
                <div class="hero-banner hero-banner-policy h-100">
                  <div>
                    <div class="hero-eyebrow">Repository Update</div>
                    <h2 class="hero-title"><?php echo $effectiveRecordsCount; ?> effective records are available in repository</h2>
                    <p class="hero-copy mb-0">Review the latest effective documents and complete required confirmations.</p>
                  </div>
                  <a href="repository.php" class="btn btn-light hero-btn">Open Repository</a>
                </div>
              </div>
              <div class="carousel-item h-100">
                <div class="hero-banner hero-banner-search h-100">
                  <div>
                    <div class="hero-eyebrow">History</div>
                    <h2 class="hero-title">Track all completed acknowledgements in one place</h2>
                    <p class="hero-copy mb-0">See assigned, overdue, and completed acknowledgement records linked to document version.</p>
                  </div>
                  <a href="my-acknowledgements.php" class="btn btn-light hero-btn">Open My Acknowledgements</a>
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
            <div class="section-kicker mb-2">Pending Acknowledgements</div>
            <div class="stat-value"><?php echo $pendingAcknowledgementsCount; ?></div>
            <div class="stat-note">Assigned items waiting for your confirmation.</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100">
          <div class="card-body p-4">
            <div class="section-kicker mb-2">Overdue</div>
            <div class="stat-value"><?php echo $overdueCount; ?></div>
            <div class="stat-note">Items that are past due date.</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100">
          <div class="card-body p-4">
            <div class="section-kicker mb-2">Completed This Month</div>
            <div class="stat-value"><?php echo $completedThisMonthCount; ?></div>
            <div class="stat-note">Acknowledgements completed this month.</div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-xl-3">
        <div class="card cp-card stat-card h-100">
          <div class="card-body p-4">
            <div class="section-kicker mb-2">Effective Records</div>
            <div class="stat-value"><?php echo $effectiveRecordsCount; ?></div>
            <div class="stat-note">Current effective documents available in repository.</div>
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
                <h2 class="card-title mb-1">My Pending Acknowledgements</h2>
                <p class="card-subtitle mb-0">Items that require your action today.</p>
              </div>
              <a href="pending-acknowledgements.php" class="btn btn-sm btn-outline-primary">Open List</a>
            </div>

            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th>Document ID</th>
                    <th>Title</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Due</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($pendingRows)): ?>
                    <?php foreach ($pendingRows as $row): ?>
                      <tr>
                        <td class="fw-semibold"><?php echo e($row['document_number'] ?: '-'); ?></td>
                        <td><?php echo e($row['title_snapshot'] ?: '-'); ?></td>
                        <td><?php echo e($row['version_label'] ?: '-'); ?></td>
                        <td>
                          <span class="badge <?php echo e(badgeClassByStatus($row['status'])); ?>">
                            <?php echo e(ucfirst($row['status'])); ?>
                          </span>
                        </td>
                        <td><?php echo e(formatDateOnly($row['due_at'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-4">No pending acknowledgements found.</td>
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
            <p class="card-subtitle mb-3">Latest acknowledgement activity in your workspace.</p>

            <div class="activity-list small">
              <?php if (!empty($activityRows)): ?>
                <?php foreach ($activityRows as $activity): ?>
                  <?php
                    $activityTime = !empty($activity['activity_time']) ? date('H:i', strtotime($activity['activity_time'])) : '--:--';
                    $documentLabel = trim(($activity['document_number'] ?? '') . ' ' . ($activity['version_label'] ?? ''));
                    $documentLabel = trim($documentLabel);

                    if ($activity['activity_type'] === 'acknowledged') {
                        $title = 'Acknowledged';
                        $message = $documentLabel . ' was acknowledged by ' . $displayName . '.';
                    } else {
                        $title = 'New assignment';
                        $message = $documentLabel . ' was assigned for acknowledgement.';
                    }
                  ?>
                  <div class="activity-item">
                    <span class="activity-time"><?php echo e($activityTime); ?></span>
                    <div>
                      <div class="fw-semibold"><?php echo e($title); ?></div>
                      <div class="text-secondary"><?php echo e($message); ?></div>
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
                <p class="card-subtitle mb-0">Recently assigned and effective records.</p>
              </div>
              <a href="repository.php" class="btn btn-sm btn-outline-primary">Open Repository</a>
            </div>

            <div class="row g-3">
              <?php if (!empty($recentDocuments)): ?>
                <?php foreach ($recentDocuments as $doc): ?>
                  <?php
                    $statusText = $doc['display_status'] ?? 'Effective';
                    $statusClass = badgeClassByStatus(strtolower($statusText));
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
                  <div class="repo-empty">No recent documents found for this employee.</div>
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
            <p class="card-subtitle mb-3">Open the most common employee tasks from one place.</p>
            <div class="d-grid gap-2">
              <a class="btn btn-primary" href="pending-acknowledgements.php">Pending Acknowledgements</a>
              <a class="btn btn-outline-primary" href="repository.php">Open Repository</a>
              <a class="btn btn-outline-primary" href="my-acknowledgements.php">My Acknowledgements</a>
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