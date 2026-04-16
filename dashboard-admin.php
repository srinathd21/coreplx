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

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
$currentRoleCode = (string)($_SESSION['role_code'] ?? '');
$currentRoleName = (string)($_SESSION['role_name'] ?? 'QA Admin');
$currentDisplayName = (string)($_SESSION['full_name'] ?? $_SESSION['admin_name'] ?? 'Profile');

if ($currentUserId <= 0) {
    header('Location: login-admin.php');
    exit;
}

if (!in_array($currentRoleCode, ['qa_admin', 'super_admin'], true)) {
    die('Access denied.');
}

function fetch_one(mysqli $conn, string $sql, string $types = '', array $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return null;
    }

    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row;
}

function fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = [];
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $rows;
    }

    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function count_query(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $row = fetch_one($conn, $sql, $types, $params);
    return (int)($row['cnt'] ?? 0);
}

function status_badge(string $status): string
{
    $status = strtolower(trim($status));

    if (in_array($status, ['effective', 'approved', 'published'], true)) {
        return '<span class="badge badge-soft-success">Effective</span>';
    }
    if (in_array($status, ['pending_approval', 'pending approval'], true)) {
        return '<span class="badge badge-soft-warning">Pending Approval</span>';
    }
    if (in_array($status, ['draft'], true)) {
        return '<span class="badge badge-soft-secondary">Draft</span>';
    }
    if (in_array($status, ['overdue'], true)) {
        return '<span class="badge badge-soft-danger">Overdue</span>';
    }
    if (in_array($status, ['in_progress', 'in progress'], true)) {
        return '<span class="badge badge-soft-info">In Progress</span>';
    }

    return '<span class="badge badge-soft-info">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function short_name(array $row): string
{
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    return $name !== '' ? $name : (string)($row['email'] ?? 'User');
}

$userRow = fetch_one($conn, "
    SELECT u.id, u.first_name, u.last_name, u.email, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
", 'i', [$currentUserId]);

$welcomeName = $userRow ? short_name($userRow) : $currentDisplayName;
$avatarLetter = strtoupper(substr($welcomeName, 0, 1));

$documentsOwned = count_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM documents
    WHERE owner_user_id = ?
", 'i', [$currentUserId]);

$pendingApprovals = count_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM workflow_steps
    WHERE approver_user_id = ?
      AND status = 'pending'
", 'i', [$currentUserId]);

$overdueReviews = count_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE dv.review_date IS NOT NULL
      AND dv.review_date < CURDATE()
      AND d.current_status IN ('effective', 'approved', 'published')
", '', []);

$unreadAlerts = count_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM notifications
    WHERE user_id = ?
      AND is_read = 0
", 'i', [$currentUserId]);

$pendingApprovalRows = fetch_all($conn, "
    SELECT
        d.document_number,
        d.title,
        dt.type_name,
        dv.submitted_at,
        submitter.first_name,
        submitter.last_name,
        ws.document_version_id
    FROM workflow_steps ws
    INNER JOIN document_versions dv ON dv.id = ws.document_version_id
    INNER JOIN documents d ON d.id = dv.document_id
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    LEFT JOIN users submitter ON submitter.id = dv.submitted_by
    WHERE ws.approver_user_id = ?
      AND ws.status = 'pending'
    ORDER BY dv.submitted_at ASC, ws.id ASC
    LIMIT 5
", 'i', [$currentUserId]);

$workQueue = fetch_all($conn, "
    SELECT
        d.document_number,
        d.title,
        d.current_status,
        dv.review_date,
        ws.status AS workflow_status
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    LEFT JOIN workflow_steps ws
        ON ws.document_version_id = d.current_version_id
       AND ws.approver_user_id = ?
       AND ws.status = 'pending'
    WHERE d.owner_user_id = ?
       OR ws.approver_user_id = ?
    ORDER BY d.updated_at DESC, d.id DESC
    LIMIT 6
", 'iii', [$currentUserId, $currentUserId, $currentUserId]);

$recentActivity = fetch_all($conn, "
    SELECT
        al.performed_at,
        al.action,
        al.remarks,
        al.entity_type,
        actor.first_name,
        actor.last_name,
        actor.email
    FROM audit_logs al
    LEFT JOIN users actor ON actor.id = al.performed_by
    WHERE al.performed_by = ?
       OR al.entity_type IN ('document', 'user')
    ORDER BY al.performed_at DESC, al.id DESC
    LIMIT 8
", 'i', [$currentUserId]);

$recentDocuments = fetch_all($conn, "
    SELECT
        d.document_number,
        d.title,
        d.current_status,
        dt.type_name
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    ORDER BY d.updated_at DESC, d.id DESC
    LIMIT 6
", '', []);

$reviewQueueCount = count_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE dv.review_date IS NOT NULL
      AND dv.review_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
", '', []);

$ackPending = count_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM acknowledgement_assignments
    WHERE status IN ('pending', 'overdue')
", '', []);
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

<?php include __DIR__ . '/includes/navbar.php'; ?>

<main class="app-shell">
  <div class="content-wrap px-4 px-xxl-5 mx-auto">
    <div class="mb-4 mt-3">
      <h1 class="page-title mb-2">Welcome back, <?php echo e($welcomeName); ?></h1>
      <p class="page-subtitle mb-0">Manage document activity, pending actions, and repository access from your workspace.</p>
    </div>

    <div class="row g-3 g-xxl-4 mb-4 align-items-stretch">
      <div class="col-xl-3">
        <div class="card cp-card dashboard-user-card h-100">
          <div class="card-body p-4 d-flex flex-column">
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="user-avatar"><?php echo e($avatarLetter); ?></div>
              <div>
                <div class="fw-bold fs-5"><?php echo e($welcomeName); ?></div>
                <div class="text-secondary small"><?php echo e($currentRoleName); ?></div>
              </div>
            </div>

            <div class="user-meta-grid mb-4">
              <a href="repository.php?filter=owned" class="user-meta-box text-decoration-none" style="cursor:pointer;" title="View all your documents">
                <div class="user-meta-value"><?php echo (int)$documentsOwned; ?></div>
                <div class="user-meta-label">Documents Owned</div>
              </a>
              <a href="audit-trail.php?tab=approval" class="user-meta-box text-decoration-none" style="cursor:pointer;" title="View documents pending approval">
                <div class="user-meta-value"><?php echo (int)$pendingApprovals; ?></div>
                <div class="user-meta-label">Pending Approvals</div>
              </a>
              <a href="repository.php?filter=overdue" class="user-meta-box text-decoration-none" style="cursor:pointer;" title="View overdue documents">
                <div class="user-meta-value"><?php echo (int)$overdueReviews; ?></div>
                <div class="user-meta-label">Overdue Reviews</div>
              </a>
              <a href="notifications.php?filter=unread" class="user-meta-box text-decoration-none" style="cursor:pointer;" title="View unread alerts">
                <div class="user-meta-value"><?php echo (int)$unreadAlerts; ?></div>
                <div class="user-meta-label">Unread Alerts</div>
              </a>
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
                  <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Search documents, IDs, owners, or keywords">
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
                    <h2 class="hero-title"><?php echo (int)$reviewQueueCount; ?> documents are due for periodic review</h2>
                    <p class="hero-copy mb-0">Open the review queue and assign owners before compliance due dates are missed.</p>
                  </div>
                  <a href="repository.php?filter=review_due" class="btn btn-light hero-btn">Open Review Queue</a>
                </div>
              </div>
              <div class="carousel-item h-100">
                <div class="hero-banner hero-banner-policy h-100">
                  <div>
                    <div class="hero-eyebrow">Repository Update</div>
                    <h2 class="hero-title"><?php echo (int)$ackPending; ?> acknowledgement assignments are still pending</h2>
                    <p class="hero-copy mb-0">Share the latest effective documents and track read confirmation across assigned users.</p>
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
                  <a href="repository.php" class="btn btn-light hero-btn">Open Repository</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card cp-card mb-4" id="actionRequiredPanel" style="border-left:4px solid #f59e0b;background:#fffbeb;">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div class="d-flex align-items-center gap-2">
            <span style="font-size:1.2rem;">⚠️</span>
            <div>
              <h2 class="card-title mb-0" style="color:#b45309;">Action Required — Pending Approvals</h2>
              <p class="card-subtitle mb-0">These documents are waiting for your electronic signature. They cannot proceed until reviewed.</p>
            </div>
          </div>
          <div class="d-flex gap-2 align-items-center">
            <a href="audit-trail.php?tab=approval" class="btn btn-sm btn-warning" style="font-weight:600;">View All Pending</a>
            <button class="btn btn-sm btn-outline-secondary" onclick="dismissActionPanel()" title="Dismiss for this session">✕</button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table align-middle mb-0" style="font-size:13px;">
            <thead>
              <tr>
                <th>Document ID</th>
                <th>Title</th>
                <th>Type</th>
                <th>Submitted By</th>
                <th>Submitted On</th>
                <th>Days Waiting</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($pendingApprovalRows)): ?>
                <?php foreach ($pendingApprovalRows as $row): ?>
                  <?php
                    $submittedByName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    if ($submittedByName === '') {
                        $submittedByName = $row['email'] ?? '—';
                    }
                    $submittedOn = !empty($row['submitted_at']) ? strtotime($row['submitted_at']) : null;
                    $daysWaiting = $submittedOn ? max(0, floor((time() - $submittedOn) / 86400)) : 0;
                    $daysBadge = $daysWaiting >= 4 ? 'badge-soft-danger' : 'badge-soft-warning';
                  ?>
                  <tr>
                    <td class="fw-semibold" style="color:#2563eb;"><?php echo e($row['document_number']); ?></td>
                    <td><?php echo e($row['title']); ?></td>
                    <td><span class="badge badge-soft-info"><?php echo e($row['type_name'] ?: 'Document'); ?></span></td>
                    <td><?php echo e($submittedByName); ?></td>
                    <td style="color:#6b7280;"><?php echo e($submittedOn ? date('d M Y', $submittedOn) : '—'); ?></td>
                    <td><span class="badge <?php echo e($daysBadge); ?>"><?php echo (int)$daysWaiting; ?> day<?php echo $daysWaiting !== 1 ? 's' : ''; ?></span></td>
                    <td><a href="audit-trail.php?tab=approval&doc_id=<?php echo urlencode((string)$row['document_number']); ?>" class="btn btn-sm btn-success" style="height:28px;padding:0 12px;font-size:12px;font-weight:600;">Review &amp; Sign</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center text-secondary py-4">No pending approvals for you.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
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
            <a href="repository.php" class="btn btn-sm btn-outline-primary">Open Queue</a>
          </div>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr><th>Document ID</th><th>Title</th><th>Task</th><th>Status</th><th>Due</th></tr>
              </thead>
              <tbody>
                <?php if (!empty($workQueue)): ?>
                  <?php foreach ($workQueue as $row): ?>
                    <?php
                      $task = 'Owner Task';
                      if (($row['workflow_status'] ?? '') === 'pending') {
                          $task = 'Approval Required';
                      } elseif (($row['current_status'] ?? '') === 'draft') {
                          $task = 'Draft Update';
                      } elseif (($row['current_status'] ?? '') === 'pending_approval') {
                          $task = 'Pending Workflow';
                      } else {
                          $task = 'Periodic Review';
                      }
                    ?>
                    <tr>
                      <td class="fw-semibold"><?php echo e($row['document_number']); ?></td>
                      <td><?php echo e($row['title']); ?></td>
                      <td><?php echo e($task); ?></td>
                      <td><?php echo status_badge((string)($row['current_status'] ?? 'draft')); ?></td>
                      <td><?php echo e(!empty($row['review_date']) ? date('d-M-Y', strtotime($row['review_date'])) : '—'); ?></td>
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
              <?php foreach ($recentActivity as $row): ?>
                <?php
                  $actor = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                  if ($actor === '') {
                      $actor = $row['email'] ?? 'System';
                  }
                  $timeLabel = !empty($row['performed_at']) ? date('H:i', strtotime($row['performed_at'])) : '--:--';
                  $actionLabel = ucwords(str_replace('_', ' ', (string)($row['action'] ?? 'Activity')));
                  $remark = $row['remarks'] ?: (($row['entity_type'] ?? 'item') . ' activity recorded.');
                ?>
                <div class="activity-item">
                  <span class="activity-time"><?php echo e($timeLabel); ?></span>
                  <div>
                    <div class="fw-semibold"><?php echo e($actionLabel); ?></div>
                    <div class="text-secondary"><?php echo e($remark . ' By ' . $actor . '.'); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-secondary">No recent activity found.</div>
            <?php endif; ?>
          </div>
        </div></div>
      </div>
    </div>

    <div class="row g-3 g-xxl-4">
      <div class="col-xl-8">
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
              <?php foreach (array_slice($recentDocuments, 0, 6) as $row): ?>
                <div class="col-md-4">
                  <div class="repo-box h-100">
                    <div class="small text-secondary mb-2"><?php echo e($row['type_name'] ?: 'Document'); ?></div>
                    <div class="fw-semibold mb-2"><?php echo e($row['title']); ?></div>
                    <div class="small text-secondary mb-3"><?php echo e($row['document_number']); ?></div>
                    <?php echo status_badge((string)($row['current_status'] ?? 'draft')); ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="col-12">
                <div class="text-center text-secondary py-4">No recent documents found.</div>
              </div>
            <?php endif; ?>
          </div>
        </div></div>
      </div>
      <div class="col-xl-4">
        <div class="card cp-card"><div class="card-body p-4">
          <h2 class="card-title mb-1">Quick Actions</h2>
          <p class="card-subtitle mb-3">Start the most common tasks from one place.</p>
          <div class="d-grid gap-2">
            <a class="btn btn-primary" href="create-document.php">Create Document</a>
            <a class="btn btn-outline-primary" href="update-document.php">Update Document</a>
            <a class="btn btn-outline-primary" href="repository.php">Open Repository</a>
            <a class="btn btn-outline-primary" href="audit-trail.php">View Audit Trail</a>
          </div>
        </div></div>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function dismissActionPanel() {
  document.getElementById('actionRequiredPanel').style.display = 'none';
  sessionStorage.setItem('actionPanelDismissed', '1');
}
if (sessionStorage.getItem('actionPanelDismissed') === '1') {
  var p = document.getElementById('actionRequiredPanel');
  if (p) p.style.display = 'none';
}
</script>
</body>
</html>