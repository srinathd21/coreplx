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
?>

<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item">
          <a class="nav-link <?php echo $currentPage === 'dashboard-admin.php' ? 'active' : ''; ?>" href="dashboard-admin.php">Dashboard</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?php echo in_array($currentPage, ['create-document.php','update-document.php','retire-document.php','repository.php','assigned-documents.php'], true) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?php echo $currentPage === 'create-document.php' ? 'active' : ''; ?>" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'update-document.php' ? 'active' : ''; ?>" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'retire-document.php' ? 'active' : ''; ?>" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'repository.php' ? 'active' : ''; ?>" href="repository.php">Repository</a></li>
            <li><a class="dropdown-item <?php echo $currentPage === 'assigned-documents.php' ? 'active' : ''; ?>" href="assigned-documents.php">Assigned Documents</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?php echo in_array($currentPage, ['audit-trail.php','document-assignment.php','user-management.php'], true) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a>
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
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small"><?php echo e($currentDisplayName); ?></span>
      </div>
    </div>
  </div>
</nav>