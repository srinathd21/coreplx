<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4() {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('write_audit_log')) {
    function write_audit_log(mysqli $conn, string $entityType, $entityId, string $action, $oldValue, $newValue, $performedBy, string $remarks = ''): void
    {
        $eventId = generate_uuid_v4();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $oldJson = $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $newJson = $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $entityId = $entityId !== null ? (int)$entityId : null;
        $performedBy = $performedBy !== null ? (int)$performedBy : null;

        $stmt = mysqli_prepare($conn, "
            INSERT INTO audit_logs
            (event_id, entity_type, entity_id, action, old_value, new_value, performed_by, performed_at, remarks, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                "ssisssisss",
                $eventId,
                $entityType,
                $entityId,
                $action,
                $oldJson,
                $newJson,
                $performedBy,
                $remarks,
                $ipAddress,
                $userAgent
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
$sessionToken = (string)($_SESSION['session_token'] ?? '');
$portalType = (string)($_SESSION['portal_type'] ?? 'admin');
$currentRoleCode = (string)($_SESSION['role_code'] ?? '');
$currentRoleName = (string)($_SESSION['role_name'] ?? '');
$currentDisplayName = (string)($_SESSION['full_name'] ?? $_SESSION['admin_name'] ?? '');

if ($currentUserId > 0) {
    if ($sessionToken !== '') {
        $selectStmt = mysqli_prepare($conn, "
            SELECT id, user_id, portal_type, ip_address, user_agent, created_at, expires_at, revoked_at
            FROM user_sessions
            WHERE session_token = ? AND user_id = ?
            LIMIT 1
        ");
        $oldSession = null;

        if ($selectStmt) {
            mysqli_stmt_bind_param($selectStmt, "si", $sessionToken, $currentUserId);
            mysqli_stmt_execute($selectStmt);
            $result = mysqli_stmt_get_result($selectStmt);
            $oldSession = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($selectStmt);
        }

        $updateStmt = mysqli_prepare($conn, "
            UPDATE user_sessions
            SET revoked_at = NOW(), last_activity_at = NOW()
            WHERE session_token = ? AND user_id = ? AND revoked_at IS NULL
        ");
        if ($updateStmt) {
            mysqli_stmt_bind_param($updateStmt, "si", $sessionToken, $currentUserId);
            mysqli_stmt_execute($updateStmt);
            mysqli_stmt_close($updateStmt);
        }

        write_audit_log(
            $conn,
            'user',
            $currentUserId,
            'logout',
            $oldSession,
            [
                'portal_type' => $portalType,
                'session_token' => $sessionToken,
                'role_code' => $currentRoleCode,
                'role_name' => $currentRoleName,
                'display_name' => $currentDisplayName,
                'logged_out_at' => date('Y-m-d H:i:s')
            ],
            $currentUserId,
            'User logged out successfully.'
        );
    } else {
        write_audit_log(
            $conn,
            'user',
            $currentUserId,
            'logout',
            null,
            [
                'portal_type' => $portalType,
                'role_code' => $currentRoleCode,
                'role_name' => $currentRoleName,
                'display_name' => $currentDisplayName,
                'logged_out_at' => date('Y-m-d H:i:s')
            ],
            $currentUserId,
            'User logged out successfully.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| CLEAR ALL EXISTING SESSION DATA
|--------------------------------------------------------------------------
*/
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CorePlx Quality DMS - Portal Selection</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/styles.css" rel="stylesheet">
<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;}</style>
</head>
<body>
<div class="container">
  <div class="portal-card mx-auto p-4 p-md-5">
    <div class="text-center mb-4">
      <h2 class="mb-2" style="color:#0D2144;font-weight:700;">Select Portal</h2>
      <p class="text-muted mb-0">Choose the login path for your role</p>
    </div>
    <div class="row g-3">
      <div class="col-md-6">
        <a class="portal-option h-100" href="login-employee.php">
          <div class="fw-bold fs-5 mb-2">Employee</div>
          <small class="text-muted">View assigned documents and complete acknowledgements.</small>
        </a>
      </div>
      <div class="col-md-6">
        <a class="portal-option primary h-100" href="login-admin.php">
          <div class="fw-bold fs-5 mb-2">Admin</div>
          <small>Manage documents, workflow, approvals, and access.</small>
        </a>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const employeeOption = document.querySelector('.portal-option:not(.primary)');
    const adminOption = document.querySelector('.portal-option.primary');
    const container = document.querySelector('.portal-card .row');
    
    if (employeeOption && adminOption) {
        // When hovering over Employee
        employeeOption.addEventListener('mouseenter', function() {
            adminOption.style.background = '#fff';
            adminOption.style.borderColor = 'var(--cp-line)';
            adminOption.style.color = 'var(--cp-text)';
            const adminSmall = adminOption.querySelector('small, .text-muted');
            if (adminSmall) adminSmall.style.color = 'var(--cp-muted)';
            
            this.style.background = 'var(--cp-navy)';
            this.style.borderColor = 'var(--cp-navy)';
            this.style.color = '#fff';
            const empSmall = this.querySelector('small, .text-muted');
            if (empSmall) empSmall.style.color = '#d8e4ff';
        });
        
        // When hovering over Admin
        adminOption.addEventListener('mouseenter', function() {
            employeeOption.style.background = '#fff';
            employeeOption.style.borderColor = 'var(--cp-line)';
            employeeOption.style.color = 'var(--cp-text)';
            const empSmall = employeeOption.querySelector('small, .text-muted');
            if (empSmall) empSmall.style.color = 'var(--cp-muted)';
            
            this.style.background = 'var(--cp-navy)';
            this.style.borderColor = 'var(--cp-navy)';
            this.style.color = '#fff';
            const adminSmall = this.querySelector('small, .text-muted');
            if (adminSmall) adminSmall.style.color = '#d8e4ff';
        });
        
        // Reset on mouse leave
        const resetStyles = function() {
            // Reset Employee
            employeeOption.style.background = '';
            employeeOption.style.borderColor = '';
            employeeOption.style.color = '';
            const empSmall = employeeOption.querySelector('small, .text-muted');
            if (empSmall) empSmall.style.color = '';
            
            // Reset Admin to primary
            adminOption.style.background = '';
            adminOption.style.borderColor = '';
            adminOption.style.color = '';
            const adminSmall = adminOption.querySelector('small, .text-muted');
            if (adminSmall) adminSmall.style.color = '';
        };
        
        container.addEventListener('mouseleave', resetStyles);
    }
});
</script>
</body></html>