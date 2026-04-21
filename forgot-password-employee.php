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

if (!function_exists('table_exists')) {
    function table_exists(mysqli $conn, string $table): bool
    {
        $table = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
        $exists = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $exists;
    }
}

if (!function_exists('ensure_password_reset_table')) {
    function ensure_password_reset_table(mysqli $conn): void
    {
        if (table_exists($conn, 'password_reset_otps')) {
            return;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS password_reset_otps (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                email VARCHAR(255) NOT NULL,
                portal_type VARCHAR(50) NOT NULL DEFAULT 'employee',
                otp_code VARCHAR(10) NOT NULL,
                is_verified TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                verified_at DATETIME NULL,
                INDEX idx_email (email),
                INDEX idx_user_id (user_id),
                INDEX idx_portal_type (portal_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        mysqli_query($conn, $sql);
    }
}

$errorMessage = '';
$successMessage = '';
$email = '';
$step = 'email';

if (isset($_SESSION['employee_logged_in']) && $_SESSION['employee_logged_in'] === true) {
    header('Location: dashboard-employee.php');
    exit;
}

ensure_password_reset_table($conn);

if (isset($_SESSION['employee_reset_step'])) {
    $step = (string)$_SESSION['employee_reset_step'];
}
if (isset($_SESSION['employee_reset_email'])) {
    $email = (string)$_SESSION['employee_reset_email'];
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    unset($_SESSION['employee_reset_step'], $_SESSION['employee_reset_email'], $_SESSION['employee_reset_user_id']);
    $step = 'email';
    $email = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'send_otp') {
        $email = trim((string)($_POST['email'] ?? ''));

        if ($email === '') {
            $errorMessage = 'Please enter your email.';
            $step = 'email';
        } else {
            $stmt = mysqli_prepare($conn, "
                SELECT id, email, first_name, last_name, status, current_role_id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $user = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
            } else {
                $user = null;
            }

            if (!$user) {
                $errorMessage = 'Email not found.';
                $step = 'email';
            } elseif ((string)$user['status'] !== 'active') {
                $errorMessage = 'Your account is not active.';
                $step = 'email';
            } elseif ((int)$user['current_role_id'] !== 1) {
                $errorMessage = 'Only employee accounts can reset password here.';
                $step = 'email';
            } else {
                $userId = (int)$user['id'];
                $otp = (string)random_int(100000, 999999);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                $deleteStmt = mysqli_prepare($conn, "
                    DELETE FROM password_reset_otps
                    WHERE email = ? AND portal_type = 'employee'
                ");
                if ($deleteStmt) {
                    mysqli_stmt_bind_param($deleteStmt, "s", $email);
                    mysqli_stmt_execute($deleteStmt);
                    mysqli_stmt_close($deleteStmt);
                }

                $insertStmt = mysqli_prepare($conn, "
                    INSERT INTO password_reset_otps
                    (user_id, email, portal_type, otp_code, is_verified, expires_at, created_at)
                    VALUES (?, ?, 'employee', ?, 0, ?, NOW())
                ");
                $saved = false;
                if ($insertStmt) {
                    mysqli_stmt_bind_param($insertStmt, "isss", $userId, $email, $otp, $expiresAt);
                    $saved = mysqli_stmt_execute($insertStmt);
                    mysqli_stmt_close($insertStmt);
                }

                if (!$saved) {
                    $errorMessage = 'Unable to generate OTP. Please try again.';
                    $step = 'email';
                } else {
                    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    if ($fullName === '') {
                        $fullName = 'Employee';
                    }

                    $subject = 'CorePlx Quality DMS - Employee Password Reset OTP';
                    $message = "Hello {$fullName},\n\n"
                             . "Your OTP for employee password reset is: {$otp}\n\n"
                             . "This OTP is valid for 10 minutes.\n"
                             . "If you did not request this, please ignore this email.\n\n"
                             . "CorePlx Quality DMS";
                    $headers = "MIME-Version: 1.0\r\n";
                    $headers .= "Content-type:text/plain;charset=UTF-8\r\n";
                    $headers .= "From: no-reply@coreplx.com\r\n";

                    $mailSent = @mail($email, $subject, $message, $headers);

                    if (!$mailSent) {
                        $errorMessage = 'OTP mail could not be sent. Please check server mail settings.';
                        $step = 'email';
                    } else {
                        $_SESSION['employee_reset_email'] = $email;
                        $_SESSION['employee_reset_user_id'] = $userId;
                        $_SESSION['employee_reset_step'] = 'otp';
                        $step = 'otp';
                        $successMessage = 'OTP sent successfully to your email.';
                    }
                }
            }
        }
    } elseif ($action === 'verify_otp') {
        $email = trim((string)($_POST['email'] ?? ''));
        $otp = trim((string)($_POST['otp'] ?? ''));

        if ($email === '' || $otp === '') {
            $errorMessage = 'Please enter OTP.';
            $step = 'otp';
        } else {
            $stmt = mysqli_prepare($conn, "
                SELECT id, user_id, otp_code, expires_at, is_verified
                FROM password_reset_otps
                WHERE email = ? AND portal_type = 'employee'
                ORDER BY id DESC
                LIMIT 1
            ");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $otpRow = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
            } else {
                $otpRow = null;
            }

            if (!$otpRow) {
                $errorMessage = 'OTP not found. Please request a new OTP.';
                $step = 'email';
            } elseif (strtotime((string)$otpRow['expires_at']) < time()) {
                $errorMessage = 'OTP expired. Please request a new OTP.';
                $step = 'email';
            } elseif ((string)$otpRow['otp_code'] !== $otp) {
                $errorMessage = 'Invalid OTP.';
                $step = 'otp';
            } else {
                $updateStmt = mysqli_prepare($conn, "
                    UPDATE password_reset_otps
                    SET is_verified = 1, verified_at = NOW()
                    WHERE id = ?
                ");
                if ($updateStmt) {
                    $otpId = (int)$otpRow['id'];
                    mysqli_stmt_bind_param($updateStmt, "i", $otpId);
                    mysqli_stmt_execute($updateStmt);
                    mysqli_stmt_close($updateStmt);
                }

                $_SESSION['employee_reset_email'] = $email;
                $_SESSION['employee_reset_user_id'] = (int)$otpRow['user_id'];
                $_SESSION['employee_reset_step'] = 'password';
                $step = 'password';
                $successMessage = 'OTP verified successfully.';
            }
        }
    } elseif ($action === 'reset_password') {
        $email = trim((string)($_POST['email'] ?? ''));
        $newPassword = trim((string)($_POST['new_password'] ?? ''));
        $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));

        if ($email === '' || $newPassword === '' || $confirmPassword === '') {
            $errorMessage = 'Please fill all password fields.';
            $step = 'password';
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = 'New password and confirm password do not match.';
            $step = 'password';
        } elseif (strlen($newPassword) < 6) {
            $errorMessage = 'Password must be at least 6 characters.';
            $step = 'password';
        } else {
            $stmt = mysqli_prepare($conn, "
                SELECT id, user_id, is_verified, expires_at
                FROM password_reset_otps
                WHERE email = ? AND portal_type = 'employee'
                ORDER BY id DESC
                LIMIT 1
            ");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $otpRow = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
            } else {
                $otpRow = null;
            }

            if (!$otpRow || (int)$otpRow['is_verified'] !== 1) {
                $errorMessage = 'OTP verification required first.';
                $step = 'email';
            } elseif (strtotime((string)$otpRow['expires_at']) < time()) {
                $errorMessage = 'OTP expired. Please restart the process.';
                $step = 'email';
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $userId = (int)$otpRow['user_id'];

                $updateUserStmt = mysqli_prepare($conn, "
                    UPDATE users
                    SET password_hash = ?, password = NULL, must_change_password = 0
                    WHERE id = ?
                ");

                $updated = false;
                if ($updateUserStmt) {
                    mysqli_stmt_bind_param($updateUserStmt, "si", $passwordHash, $userId);
                    $updated = mysqli_stmt_execute($updateUserStmt);
                    mysqli_stmt_close($updateUserStmt);
                }

                if (!$updated) {
                    $errorMessage = 'Password reset failed. Please try again.';
                    $step = 'password';
                } else {
                    $deleteStmt = mysqli_prepare($conn, "
                        DELETE FROM password_reset_otps
                        WHERE email = ? AND portal_type = 'employee'
                    ");
                    if ($deleteStmt) {
                        mysqli_stmt_bind_param($deleteStmt, "s", $email);
                        mysqli_stmt_execute($deleteStmt);
                        mysqli_stmt_close($deleteStmt);
                    }

                    unset($_SESSION['employee_reset_step'], $_SESSION['employee_reset_email'], $_SESSION['employee_reset_user_id']);
                    $_SESSION['flash_success'] = 'Password reset successful. Please login with your new password.';
                    header('Location: login-employee.php');
                    exit;
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CorePlx Quality DMS - Employee Forgot Password</title>
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
        <h3 class="mb-2" style="color:#0D2144;font-weight:700;">Forgot Password</h3>
        <p class="text-muted mb-0">Receive OTP by email and reset your employee password.</p>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success py-2 mb-3"><?php echo e($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger py-2 mb-3"><?php echo e($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($step === 'email'): ?>
        <form method="post" action="">
            <input type="hidden" name="action" value="send_otp">

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="Enter email" value="<?php echo e($email); ?>" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">Send OTP</button>

            <div class="text-center footer-help">
                <a href="login-employee.php" class="d-block mb-2">Back to Login</a>
            </div>
        </form>
    <?php elseif ($step === 'otp'): ?>
        <form method="post" action="">
            <input type="hidden" name="action" value="verify_otp">

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo e($email); ?>" readonly>
            </div>

            <div class="mb-3">
                <label class="form-label">OTP</label>
                <input type="text" name="otp" class="form-control" placeholder="Enter OTP" maxlength="6" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">Verify OTP</button>

            <div class="text-center footer-help">
                <a href="forgot-password-employee.php?reset=1" class="d-block mb-2">Change Email / Resend OTP</a>
            </div>
        </form>
    <?php else: ?>
        <form method="post" action="">
            <input type="hidden" name="action" value="reset_password">

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo e($email); ?>" readonly>
            </div>

            <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">Reset Password</button>

            <div class="text-center footer-help">
                <a href="forgot-password-employee.php?reset=1" class="d-block mb-2">Start Again</a>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>