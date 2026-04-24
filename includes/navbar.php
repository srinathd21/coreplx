<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
$currentRoleCode = (string)($_SESSION['role_code'] ?? '');
$currentRoleName = (string)($_SESSION['role_name'] ?? 'QA Admin');
$currentDisplayName = (string)($_SESSION['full_name'] ?? $_SESSION['admin_name'] ?? 'Profile');

$currentPage = basename($_SERVER['PHP_SELF']);

/*
|--------------------------------------------------------------------------
| LOAD DB CONNECTION IF NOT ALREADY AVAILABLE
|--------------------------------------------------------------------------
*/
if (!isset($conn) || !($conn instanceof mysqli)) {
    $possibleDbPaths = [
        __DIR__ . '/db.php',
        dirname(__DIR__) . '/includes/db.php',
    ];

    foreach ($possibleDbPaths as $dbPath) {
        if (file_exists($dbPath)) {
            require_once $dbPath;
            break;
        }
    }
}

$pendingDocsCount = 0;

if ($currentUserId > 0 && isset($conn) && ($conn instanceof mysqli)) {
    mysqli_set_charset($conn, 'utf8mb4');

    $hasWorkflowSteps = false;
    $hasDocuments = false;
    $hasDocumentVersions = false;

    $res = mysqli_query($conn, "SHOW TABLES LIKE 'workflow_steps'");
    if ($res && mysqli_num_rows($res) > 0) {
        $hasWorkflowSteps = true;
    }
    if ($res) {
        mysqli_free_result($res);
    }

    $res = mysqli_query($conn, "SHOW TABLES LIKE 'documents'");
    if ($res && mysqli_num_rows($res) > 0) {
        $hasDocuments = true;
    }
    if ($res) {
        mysqli_free_result($res);
    }

    $res = mysqli_query($conn, "SHOW TABLES LIKE 'document_versions'");
    if ($res && mysqli_num_rows($res) > 0) {
        $hasDocumentVersions = true;
    }
    if ($res) {
        mysqli_free_result($res);
    }

    /*
    |--------------------------------------------------------------------------
    | FIRST PRIORITY: workflow_steps based count
    |--------------------------------------------------------------------------
    | This is the proper approval queue if workflow is created correctly.
    */
    if ($hasWorkflowSteps) {
        $sql = "
            SELECT COUNT(*) AS cnt
            FROM workflow_steps ws
            WHERE ws.approver_user_id = ?
              AND ws.status = 'pending'
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $currentUserId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($result && ($row = mysqli_fetch_assoc($result))) {
                $pendingDocsCount = (int)($row['cnt'] ?? 0);
            }
            mysqli_stmt_close($stmt);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FALLBACK / EXTRA SAFETY:
    | Some of your documents are directly marked pending_approval in documents
    | but workflow_steps is not always inserted correctly.
    |--------------------------------------------------------------------------
    */
    if ($hasDocuments) {
        $fallbackCount = 0;

        if ($hasDocumentVersions) {
            $sql = "
                SELECT COUNT(DISTINCT d.id) AS cnt
                FROM documents d
                LEFT JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE d.approver = ?
                  AND (
                        d.current_status = 'pending_approval'
                        OR d.status = 'pending_approval'
                        OR dv.status = 'pending_approval'
                  )
            ";
        } else {
            $sql = "
                SELECT COUNT(DISTINCT d.id) AS cnt
                FROM documents d
                WHERE d.approver = ?
                  AND (
                        d.current_status = 'pending_approval'
                        OR d.status = 'pending_approval'
                  )
            ";
        }

        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            $approverIdAsString = (string)$currentUserId;
            mysqli_stmt_bind_param($stmt, "s", $approverIdAsString);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($result && ($row = mysqli_fetch_assoc($result))) {
                $fallbackCount = (int)($row['cnt'] ?? 0);
            }
            mysqli_stmt_close($stmt);
        }

        /*
        |--------------------------------------------------------------------------
        | Use the larger value.
        | Because your system data is inconsistent. Some records exist in workflow,
        | some only in documents table.
        |--------------------------------------------------------------------------
        */
        if ($fallbackCount > $pendingDocsCount) {
            $pendingDocsCount = $fallbackCount;
        }
    }
}
?>

<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>

    <button
      class="navbar-toggler border-0 shadow-none"
      type="button"
      data-bs-toggle="collapse"
      data-bs-target="#topNav"
      aria-controls="topNav"
      aria-expanded="false"
      aria-label="Toggle navigation"
    >
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item">
          <a class="nav-link <?php echo $currentPage === 'dashboard-admin.php' ? 'active' : ''; ?>" href="dashboard-admin.php">
            Dashboard
          </a>
        </li>

        <li class="nav-item dropdown">
          <a
            class="nav-link dropdown-toggle <?php echo in_array($currentPage, ['create-document.php','update-document.php','retire-document.php','repository.php','assigned-documents.php'], true) ? 'active' : ''; ?>"
            href="#"
            role="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
          >
            Documents
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?php echo $currentPage === 'create-document.php' ? 'active' : ''; ?>" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'update-document.php' ? 'active' : ''; ?>" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'retire-document.php' ? 'active' : ''; ?>" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'repository.php' ? 'active' : ''; ?>" href="repository.php">Repository</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'assigned-documents.php' ? 'active' : ''; ?>" href="assigned-documents.php">Assigned Documents</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a
            class="nav-link dropdown-toggle <?php echo in_array($currentPage, ['audit-trail.php','document-assignment.php','user-management.php'], true) ? 'active' : ''; ?>"
            href="#"
            role="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
          >
            Administration
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?php echo $currentPage === 'audit-trail.php' ? 'active' : ''; ?>" href="audit-trail.php">Audit Trail</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'document-assignment.php' ? 'active' : ''; ?>" href="document-assignment.php">Document Assignment</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'user-management.php' ? 'active' : ''; ?>" href="user-management.php">User Management</a></li>
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link" href="portal-select.php">Switch to User</a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($currentRoleName); ?></span>

        <a class="nav-link px-0 d-inline-flex align-items-center gap-2 <?php echo $currentPage === 'notifications.php' ? 'active' : ''; ?>" href="notifications.php">
          <span>Notifications</span>
          <?php if ($pendingDocsCount > 0): ?>
            <span
              class="badge rounded-pill bg-danger"
              style="font-size:11px; min-width:22px; height:22px; display:inline-flex; align-items:center; justify-content:center; padding:0 7px;"
              title="Pending documents for approval"
            >
              <?php echo $pendingDocsCount > 99 ? '99+' : (int)$pendingDocsCount; ?>
            </span>
          <?php endif; ?>
        </a>

        <span class="navbar-text small"><?php echo e($currentDisplayName); ?></span>
      </div>
    </div>
  </div>
</nav>