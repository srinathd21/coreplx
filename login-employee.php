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

$errorMessage = '';
$email = '';

if (isset($_SESSION['employee_logged_in']) && $_SESSION['employee_logged_in'] === true) {
    header('Location: dashboard-employee.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if ($email === '' || $password === '') {
        $errorMessage = 'Please enter email and password.';
    } else {
        $sql = "SELECT id, employee_code, first_name, last_name, full_name, email, phone, password_hash, password, current_role_id, department_id, status, failed_login_attempts, locked_until, must_change_password
                FROM users
                WHERE email = ?
                LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if (!$user) {
                $errorMessage = 'Invalid email or password.';

                $logSql = "INSERT INTO login_attempts (email, portal_type, user_id, attempt_status, ip_address, user_agent, failure_reason)
                           VALUES (?, 'employee', NULL, 'failed', ?, ?, ?)";
                $logStmt = mysqli_prepare($conn, $logSql);
                if ($logStmt) {
                    $reason = 'User not found';
                    mysqli_stmt_bind_param($logStmt, "ssss", $email, $ipAddress, $userAgent, $reason);
                    mysqli_stmt_execute($logStmt);
                    mysqli_stmt_close($logStmt);
                }
            } else {
                if ($user['status'] !== 'active') {
                    $errorMessage = 'Your account is not active.';
                } elseif ((int)$user['current_role_id'] !== 1) {
                    $errorMessage = 'Only employee accounts can login here.';
                } else {
                    $passwordValid = false;

                    if (!empty($user['password_hash'])) {
                        $passwordValid = password_verify($password, $user['password_hash']);
                    }

                    if (!$passwordValid && !empty($user['password'])) {
                        $passwordValid = ((string)$user['password'] === (string)$password);
                    }

                    if ($passwordValid) {
                        $updateSql = "UPDATE users
                                      SET failed_login_attempts = 0,
                                          locked_until = NULL,
                                          last_login_at = NOW()
                                      WHERE id = ?";
                        $updateStmt = mysqli_prepare($conn, $updateSql);
                        if ($updateStmt) {
                            $userId = (int)$user['id'];
                            mysqli_stmt_bind_param($updateStmt, "i", $userId);
                            mysqli_stmt_execute($updateStmt);
                            mysqli_stmt_close($updateStmt);
                        }

                        $displayName = trim($user['full_name'] ?? '');
                        if ($displayName === '') {
                            $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                        }
                        if ($displayName === '') {
                            $displayName = $user['email'];
                        }

                        $_SESSION['employee_logged_in'] = true;
                        $_SESSION['portal_type'] = 'employee';
                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['employee_id'] = (int)$user['id'];
                        $_SESSION['employee_code'] = $user['employee_code'] ?? '';
                        $_SESSION['full_name'] = $displayName;
                        $_SESSION['employee_name'] = $displayName;
                        $_SESSION['email'] = $user['email'] ?? '';
                        $_SESSION['role_id'] = (int)$user['current_role_id'];

                        $sessionToken = bin2hex(random_bytes(32));
                        $_SESSION['session_token'] = $sessionToken;

                        $sessionSql = "INSERT INTO user_sessions (user_id, session_token, portal_type, ip_address, user_agent, expires_at, last_activity_at)
                                       VALUES (?, ?, 'employee', ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())";
                        $sessionStmt = mysqli_prepare($conn, $sessionSql);
                        if ($sessionStmt) {
                            $userId = (int)$user['id'];
                            mysqli_stmt_bind_param($sessionStmt, "isss", $userId, $sessionToken, $ipAddress, $userAgent);
                            mysqli_stmt_execute($sessionStmt);
                            mysqli_stmt_close($sessionStmt);
                        }

                        $logSql = "INSERT INTO login_attempts (email, portal_type, user_id, attempt_status, ip_address, user_agent, failure_reason)
                                   VALUES (?, 'employee', ?, 'success', ?, ?, NULL)";
                        $logStmt = mysqli_prepare($conn, $logSql);
                        if ($logStmt) {
                            $userId = (int)$user['id'];
                            mysqli_stmt_bind_param($logStmt, "siss", $email, $userId, $ipAddress, $userAgent);
                            mysqli_stmt_execute($logStmt);
                            mysqli_stmt_close($logStmt);
                        }

                        header('Location: dashboard-employee.php');
                        exit;
                    } else {
                        $errorMessage = 'Invalid email or password.';

                        $failSql = "UPDATE users
                                    SET failed_login_attempts = failed_login_attempts + 1
                                    WHERE id = ?";
                        $failStmt = mysqli_prepare($conn, $failSql);
                        if ($failStmt) {
                            $userId = (int)$user['id'];
                            mysqli_stmt_bind_param($failStmt, "i", $userId);
                            mysqli_stmt_execute($failStmt);
                            mysqli_stmt_close($failStmt);
                        }

                        $logSql = "INSERT INTO login_attempts (email, portal_type, user_id, attempt_status, ip_address, user_agent, failure_reason)
                                   VALUES (?, 'employee', ?, 'failed', ?, ?, ?)";
                        $logStmt = mysqli_prepare($conn, $logSql);
                        if ($logStmt) {
                            $userId = (int)$user['id'];
                            $reason = 'Wrong password';
                            mysqli_stmt_bind_param($logStmt, "sisss", $email, $userId, $ipAddress, $userAgent, $reason);
                            mysqli_stmt_execute($logStmt);
                            mysqli_stmt_close($logStmt);
                        }
                    }
                }
            }
        } else {
            $errorMessage = 'Failed to prepare login query.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CorePlx Quality DMS - Employee Login</title>
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
        <h3 class="mb-2" style="color:#0D2144;font-weight:700;">Employee Login</h3>
        <p class="text-muted mb-0">Access assigned documents and complete acknowledgements.</p>
    </div>

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
            <a href="#" class="d-block mb-2">Forgot Password?</a>
            <span>Need help? <a href="mailto:admin@company.com">Contact Admin</a></span>
        </div>
    </form>
</div>
</body>
</html>