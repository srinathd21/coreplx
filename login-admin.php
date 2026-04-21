<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('table_exists')) {
    function table_exists(mysqli $conn, string $table): bool
    {
        $table = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
        $ok = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $ok;
    }
}

if (!function_exists('write_audit_log')) {
    function write_audit_log(mysqli $conn, string $entityType, $entityId, string $action, $oldValue, $newValue, $performedBy, string $remarks = ''): void
    {
        if (!table_exists($conn, 'audit_logs')) {
            return;
        }

        $eventId = generate_uuid_v4();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $oldJson = $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $newJson = $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

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

if (!function_exists('write_login_attempt')) {
    function write_login_attempt(mysqli $conn, string $email, ?int $userId, string $status, string $ipAddress, string $userAgent, ?string $reason = null): void
    {
        if (!table_exists($conn, 'login_attempts')) {
            return;
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO login_attempts
            (email, portal_type, user_id, attempt_status, ip_address, user_agent, failure_reason)
            VALUES (?, 'admin', ?, ?, ?, ?, ?)
        ");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sissss", $email, $userId, $status, $ipAddress, $userAgent, $reason);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

$errorMessage = '';
$successMessage = '';
$email = '';

if (!empty($_SESSION['flash_success'])) {
    $successMessage = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard-admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if ($email === '' || $password === '') {
        $errorMessage = 'Please enter email and password.';

        write_login_attempt($conn, $email, null, 'failed', $ipAddress, $userAgent, 'Missing email or password');
        write_audit_log(
            $conn,
            'auth',
            null,
            'login_failed',
            null,
            ['portal' => 'admin', 'email' => $email, 'reason' => 'Missing email or password'],
            null,
            'Admin login failed because email or password was missing.'
        );
    } else {
        $sql = "
            SELECT
                u.id,
                u.employee_code,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.password_hash,
                u.current_role_id,
                u.department_id,
                u.status,
                u.failed_login_attempts,
                u.locked_until,
                u.must_change_password,
                u.last_login_at,
                r.role_code,
                r.role_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.current_role_id
            WHERE u.email = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if (!$user) {
                $errorMessage = 'Invalid email or password.';

                write_login_attempt($conn, $email, null, 'failed', $ipAddress, $userAgent, 'User not found');
                write_audit_log(
                    $conn,
                    'auth',
                    null,
                    'login_failed',
                    null,
                    ['portal' => 'admin', 'email' => $email, 'reason' => 'User not found'],
                    null,
                    'Admin login failed because the email was not found.'
                );
            } else {
                $userId = (int)$user['id'];
                $roleCode = trim((string)($user['role_code'] ?? ''));
                $lockedUntil = trim((string)($user['locked_until'] ?? ''));
                $failedAttempts = (int)($user['failed_login_attempts'] ?? 0);

                if ($lockedUntil !== '' && strtotime($lockedUntil) > time()) {
                    $errorMessage = 'Your account is temporarily locked. Please try again later.';

                    write_login_attempt($conn, $email, $userId, 'blocked', $ipAddress, $userAgent, 'Account locked until ' . $lockedUntil);
                    write_audit_log(
                        $conn,
                        'user',
                        $userId,
                        'login_blocked',
                        ['failed_login_attempts' => $failedAttempts, 'locked_until' => $lockedUntil],
                        ['portal' => 'admin', 'email' => $email],
                        $userId,
                        'Admin login blocked because the account is locked.'
                    );
                } elseif ((string)$user['status'] !== 'active') {
                    $errorMessage = 'Your account is not active.';

                    write_login_attempt($conn, $email, $userId, 'blocked', $ipAddress, $userAgent, 'Account status is ' . $user['status']);
                    write_audit_log(
                        $conn,
                        'user',
                        $userId,
                        'login_blocked',
                        ['status' => $user['status']],
                        ['portal' => 'admin', 'email' => $email],
                        $userId,
                        'Admin login blocked because the account is not active.'
                    );
                } elseif (!in_array($roleCode, ['qa_admin', 'super_admin'], true)) {
                    $errorMessage = 'Only admin accounts can login here.';

                    write_login_attempt($conn, $email, $userId, 'blocked', $ipAddress, $userAgent, 'Non-admin role attempted admin login');
                    write_audit_log(
                        $conn,
                        'user',
                        $userId,
                        'login_blocked',
                        ['role_code' => $roleCode, 'role_name' => $user['role_name'] ?? ''],
                        ['portal' => 'admin', 'email' => $email],
                        $userId,
                        'Admin login blocked because the user role is not allowed on admin portal.'
                    );
                } else {
                    $passwordValid = false;

                    if (!empty($user['password_hash'])) {
                        $passwordValid = password_verify($password, $user['password_hash']);
                    }

                    if ($passwordValid) {
                        $updateSql = "
                            UPDATE users
                            SET failed_login_attempts = 0,
                                locked_until = NULL,
                                last_login_at = NOW()
                            WHERE id = ?
                        ";
                        $updateStmt = mysqli_prepare($conn, $updateSql);
                        if ($updateStmt) {
                            mysqli_stmt_bind_param($updateStmt, "i", $userId);
                            mysqli_stmt_execute($updateStmt);
                            mysqli_stmt_close($updateStmt);
                        }

                        $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
                        if ($displayName === '') {
                            $displayName = (string)$user['email'];
                        }

                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['portal_type'] = 'admin';
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['admin_id'] = $userId;
                        $_SESSION['employee_code'] = $user['employee_code'] ?? '';
                        $_SESSION['full_name'] = $displayName;
                        $_SESSION['admin_name'] = $displayName;
                        $_SESSION['email'] = $user['email'] ?? '';
                        $_SESSION['role_id'] = (int)$user['current_role_id'];
                        $_SESSION['role_code'] = $roleCode;
                        $_SESSION['role_name'] = $user['role_name'] ?? 'Admin';

                        $sessionToken = bin2hex(random_bytes(32));
                        $_SESSION['session_token'] = $sessionToken;

                        if (table_exists($conn, 'user_sessions')) {
                            $sessionSql = "
                                INSERT INTO user_sessions
                                (user_id, session_token, portal_type, ip_address, user_agent, expires_at, last_activity_at)
                                VALUES (?, ?, 'admin', ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())
                            ";
                            $sessionStmt = mysqli_prepare($conn, $sessionSql);
                            if ($sessionStmt) {
                                mysqli_stmt_bind_param($sessionStmt, "isss", $userId, $sessionToken, $ipAddress, $userAgent);
                                mysqli_stmt_execute($sessionStmt);
                                mysqli_stmt_close($sessionStmt);
                            }
                        }

                        write_login_attempt($conn, $email, $userId, 'success', $ipAddress, $userAgent, null);

                        write_audit_log(
                            $conn,
                            'user',
                            $userId,
                            'login_success',
                            ['last_login_at' => $user['last_login_at'] ?? null],
                            [
                                'portal' => 'admin',
                                'email' => $email,
                                'role_code' => $roleCode,
                                'role_name' => $user['role_name'] ?? '',
                                'session_token_created' => true
                            ],
                            $userId,
                            'Admin login successful.'
                        );

                        header('Location: dashboard-admin.php');
                        exit;
                    } else {
                        $newFailedAttempts = $failedAttempts + 1;
                        $lockUntilValue = null;

                        if ($newFailedAttempts >= 5) {
                            $lockUntilValue = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                            $failSql = "
                                UPDATE users
                                SET failed_login_attempts = ?,
                                    locked_until = ?
                                WHERE id = ?
                            ";
                            $failStmt = mysqli_prepare($conn, $failSql);
                            if ($failStmt) {
                                mysqli_stmt_bind_param($failStmt, "isi", $newFailedAttempts, $lockUntilValue, $userId);
                                mysqli_stmt_execute($failStmt);
                                mysqli_stmt_close($failStmt);
                            }

                            $errorMessage = 'Too many failed attempts. Your account has been locked for 30 minutes.';

                            write_login_attempt($conn, $email, $userId, 'blocked', $ipAddress, $userAgent, 'Wrong password - account locked');
                            write_audit_log(
                                $conn,
                                'user',
                                $userId,
                                'login_blocked',
                                ['failed_login_attempts' => $failedAttempts, 'locked_until' => $user['locked_until'] ?? null],
                                ['failed_login_attempts' => $newFailedAttempts, 'locked_until' => $lockUntilValue, 'portal' => 'admin'],
                                $userId,
                                'Admin login blocked after too many failed password attempts.'
                            );
                        } else {
                            $failSql = "
                                UPDATE users
                                SET failed_login_attempts = ?
                                WHERE id = ?
                            ";
                            $failStmt = mysqli_prepare($conn, $failSql);
                            if ($failStmt) {
                                mysqli_stmt_bind_param($failStmt, "ii", $newFailedAttempts, $userId);
                                mysqli_stmt_execute($failStmt);
                                mysqli_stmt_close($failStmt);
                            }

                            $errorMessage = 'Invalid email or password.';

                            write_login_attempt($conn, $email, $userId, 'failed', $ipAddress, $userAgent, 'Wrong password');
                            write_audit_log(
                                $conn,
                                'user',
                                $userId,
                                'login_failed',
                                ['failed_login_attempts' => $failedAttempts],
                                ['failed_login_attempts' => $newFailedAttempts, 'portal' => 'admin'],
                                $userId,
                                'Admin login failed because of wrong password.'
                            );
                        }
                    }
                }
            }
        } else {
            $errorMessage = 'Failed to prepare login query.';

            write_audit_log(
                $conn,
                'auth',
                null,
                'login_error',
                null,
                ['portal' => 'admin', 'email' => $email],
                null,
                'Admin login failed because the login query could not be prepared.'
            );
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CorePlx Quality DMS - Admin Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/styles.css" rel="stylesheet">
<style>
body{
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:100vh;
}
</style>
</head>
<body>
<div class="login-card p-4 p-md-5">
    <div class="mb-4">
        <div class="text-uppercase small fw-semibold mb-2" style="color:#6B7280;">CorePlx Quality DMS</div>
        <h3 class="mb-2" style="color:#0D2144;font-weight:700;">Admin Login</h3>
        <p class="text-muted mb-0">Manage documents, workflow, and system access.</p>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success py-2 mb-3">
            <?php echo e($successMessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger py-2 mb-3">
            <?php echo e($errorMessage); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" placeholder="Enter email" value="<?php echo e($email); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" placeholder="Enter password" required>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">Login</button>

        <div class="text-center footer-help">
            <a href="forgot-password.php" class="d-block mb-2">Forgot Password?</a>
            <span>Need help? <a href="mailto:support@coreplx.com">Contact Support</a></span>
        </div>
    </form>
</div>
</body>
</html>