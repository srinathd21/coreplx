<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

/* ---------------- HELPERS ---------------- */

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

if (!function_exists('column_exists')) {
    function column_exists(mysqli $conn, string $table, string $column): bool
    {
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
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

if (!function_exists('send_reset_otp_mail')) {
    function send_reset_otp_mail(string $toEmail, string $otp): bool
    {
        $subject = 'CorePlx Quality DMS - Password Reset OTP';

        $message = "Hello,\n\n";
        $message .= "Your OTP for admin password reset is: {$otp}\n\n";
        $message .= "This OTP is valid for 10 minutes.\n";
        $message .= "If you did not request this, please ignore this email.\n\n";
        $message .= "Regards,\nCorePlx Quality DMS";

        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/plain; charset=UTF-8';
        $headers[] = 'From: CorePlx Quality DMS <no-reply@coreplx.com>';

        return @mail($toEmail, $subject, $message, implode("\r\n", $headers));
    }
}

/* ---------------- RESTART FLOW ---------------- */

if (isset($_GET['restart']) && $_GET['restart'] === '1') {
    unset($_SESSION['forgot_password_email']);
    unset($_SESSION['forgot_password_user_id']);
    unset($_SESSION['forgot_password_verified']);
    unset($_SESSION['forgot_password_step']);
    unset($_SESSION['forgot_password_token_id']);
    header('Location: forgot-password.php');
    exit;
}

/* ---------------- LOGIN CHECK ---------------- */

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard-admin.php');
    exit;
}

/* ---------------- REQUIRED TABLE CHECK ---------------- */

if (!table_exists($conn, 'password_reset_tokens')) {
    die('Table "password_reset_tokens" not found. Please create it first.');
}

/* ---------------- PAGE STATE ---------------- */

$errorMessage = '';
$successMessage = '';
$email = (string)($_SESSION['forgot_password_email'] ?? '');
$step = (string)($_SESSION['forgot_password_step'] ?? 'email');

/* ---------------- FORM PROCESS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    /* STEP 1: SEND OTP */
    if ($action === 'send_otp') {
        $email = trim((string)($_POST['email'] ?? ''));
        $step = 'email';

        if ($email === '') {
            $errorMessage = 'Please enter email.';
        } else {
            $stmt = mysqli_prepare($conn, "
                SELECT
                    u.id,
                    u.email,
                    u.status,
                    r.role_code
                FROM users u
                LEFT JOIN roles r ON r.id = u.current_role_id
                WHERE u.email = ?
                LIMIT 1
            ");

            if (!$stmt) {
                $errorMessage = 'Unable to verify email.';
            } else {
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $user = ($res && mysqli_num_rows($res) > 0) ? mysqli_fetch_assoc($res) : null;
                mysqli_stmt_close($stmt);

                if (!$user) {
                    $errorMessage = 'Email not found.';
                } elseif ((string)$user['status'] !== 'active') {
                    $errorMessage = 'Your account is not active.';
                } elseif (!in_array((string)$user['role_code'], ['qa_admin', 'super_admin'], true)) {
                    $errorMessage = 'Only admin accounts can reset password here.';
                } else {
                    $userId = (int)$user['id'];
                    $otp = (string)random_int(100000, 999999);
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                    $clearStmt = mysqli_prepare($conn, "
                        UPDATE password_reset_tokens
                        SET used_at = NOW()
                        WHERE user_id = ? AND used_at IS NULL
                    ");
                    if ($clearStmt) {
                        mysqli_stmt_bind_param($clearStmt, "i", $userId);
                        mysqli_stmt_execute($clearStmt);
                        mysqli_stmt_close($clearStmt);
                    }

                    $insertSql = "INSERT INTO password_reset_tokens (user_id, reset_token, expires_at";
                    $insertValues = " VALUES (?, ?, ?";

                    if (column_exists($conn, 'password_reset_tokens', 'created_at')) {
                        $insertSql .= ", created_at";
                        $insertValues .= ", NOW()";
                    }

                    $insertSql .= ")";
                    $insertValues .= ")";
                    $insertSql .= $insertValues;

                    $insertStmt = mysqli_prepare($conn, $insertSql);

                    if (!$insertStmt) {
                        $errorMessage = 'Unable to generate OTP.';
                    } else {
                        mysqli_stmt_bind_param($insertStmt, "iss", $userId, $otp, $expiresAt);
                        mysqli_stmt_execute($insertStmt);
                        $tokenId = (int)mysqli_insert_id($conn);
                        mysqli_stmt_close($insertStmt);

                        $mailSent = send_reset_otp_mail($email, $otp);

                        $_SESSION['forgot_password_email'] = $email;
                        $_SESSION['forgot_password_user_id'] = $userId;
                        $_SESSION['forgot_password_step'] = 'otp';
                        $_SESSION['forgot_password_token_id'] = $tokenId;

                        $step = 'otp';

                        if ($mailSent) {
                            $successMessage = 'OTP sent to your email.';
                        } else {
                            $errorMessage = 'OTP created, but email sending failed. Check mail configuration.';
                        }

                        write_audit_log(
                            $conn,
                            'user',
                            $userId,
                            'forgot_password_otp_sent',
                            null,
                            ['email' => $email, 'expires_at' => $expiresAt],
                            $userId,
                            'Forgot password OTP sent.'
                        );
                    }
                }
            }
        }
    }

    /* STEP 2: VERIFY OTP */
    if ($action === 'verify_otp') {
        $email = trim((string)($_SESSION['forgot_password_email'] ?? $_POST['email'] ?? ''));
        $otp = trim((string)($_POST['otp'] ?? ''));
        $step = 'otp';

        if ($email === '') {
            $errorMessage = 'Please start again.';
            $step = 'email';
        } elseif ($otp === '') {
            $errorMessage = 'Please enter OTP.';
        } else {
            $userId = (int)($_SESSION['forgot_password_user_id'] ?? 0);
            $tokenId = (int)($_SESSION['forgot_password_token_id'] ?? 0);

            if ($userId <= 0 || $tokenId <= 0) {
                $errorMessage = 'Session expired. Please send OTP again.';
                $step = 'email';
            } else {
                $otpStmt = mysqli_prepare($conn, "
                    SELECT id, reset_token, expires_at, used_at
                    FROM password_reset_tokens
                    WHERE id = ? AND user_id = ?
                    LIMIT 1
                ");

                if (!$otpStmt) {
                    $errorMessage = 'Unable to verify OTP.';
                } else {
                    mysqli_stmt_bind_param($otpStmt, "ii", $tokenId, $userId);
                    mysqli_stmt_execute($otpStmt);
                    $otpRes = mysqli_stmt_get_result($otpStmt);
                    $otpRow = ($otpRes && mysqli_num_rows($otpRes) > 0) ? mysqli_fetch_assoc($otpRes) : null;
                    mysqli_stmt_close($otpStmt);

                    if (!$otpRow) {
                        $errorMessage = 'OTP record not found. Please send OTP again.';
                        $step = 'email';
                    } elseif (!empty($otpRow['used_at'])) {
                        $errorMessage = 'OTP already used. Please send OTP again.';
                        $step = 'email';
                    } elseif (strtotime((string)$otpRow['expires_at']) < time()) {
                        $errorMessage = 'OTP expired. Please send OTP again.';
                        $step = 'email';
                    } elseif ((string)$otpRow['reset_token'] !== $otp) {
                        $errorMessage = 'Invalid OTP.';
                    } else {
                        $_SESSION['forgot_password_verified'] = true;
                        $_SESSION['forgot_password_step'] = 'password';
                        $step = 'password';
                        $successMessage = 'OTP verified successfully. Now set your new password.';

                        write_audit_log(
                            $conn,
                            'user',
                            $userId,
                            'forgot_password_otp_verified',
                            null,
                            ['email' => $email],
                            $userId,
                            'Forgot password OTP verified.'
                        );
                    }
                }
            }
        }
    }

    /* STEP 3: RESET PASSWORD */
    if ($action === 'reset_password') {
        $email = trim((string)($_SESSION['forgot_password_email'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $step = 'password';

        if ($email === '') {
            $errorMessage = 'Session expired. Please start again.';
            $step = 'email';
        } elseif (empty($_SESSION['forgot_password_verified'])) {
            $errorMessage = 'Please verify OTP first.';
            $step = 'otp';
        } elseif ($newPassword === '' || $confirmPassword === '') {
            $errorMessage = 'Please enter new password and confirm password.';
        } elseif (strlen($newPassword) < 6) {
            $errorMessage = 'Password must be at least 6 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = 'New password and confirm password do not match.';
        } else {
            $userId = (int)($_SESSION['forgot_password_user_id'] ?? 0);
            $tokenId = (int)($_SESSION['forgot_password_token_id'] ?? 0);

            if ($userId <= 0 || $tokenId <= 0) {
                $errorMessage = 'Session expired. Please start again.';
                $step = 'email';
            } else {
                $otpStmt = mysqli_prepare($conn, "
                    SELECT id, expires_at, used_at
                    FROM password_reset_tokens
                    WHERE id = ? AND user_id = ?
                    LIMIT 1
                ");

                if (!$otpStmt) {
                    $errorMessage = 'Unable to verify reset session.';
                } else {
                    mysqli_stmt_bind_param($otpStmt, "ii", $tokenId, $userId);
                    mysqli_stmt_execute($otpStmt);
                    $otpRes = mysqli_stmt_get_result($otpStmt);
                    $otpRow = ($otpRes && mysqli_num_rows($otpRes) > 0) ? mysqli_fetch_assoc($otpRes) : null;
                    mysqli_stmt_close($otpStmt);

                    if (!$otpRow) {
                        $errorMessage = 'Reset session expired. Please send OTP again.';
                        $step = 'email';
                    } elseif (!empty($otpRow['used_at'])) {
                        $errorMessage = 'OTP already used. Please send OTP again.';
                        $step = 'email';
                    } elseif (strtotime((string)$otpRow['expires_at']) < time()) {
                        $errorMessage = 'OTP expired. Please send OTP again.';
                        $step = 'email';
                    } else {
                        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                        mysqli_begin_transaction($conn);

                        try {
                            $updateUser = mysqli_prepare($conn, "
                                UPDATE users
                                SET password_hash = ?,
                                    failed_login_attempts = 0,
                                    locked_until = NULL,
                                    must_change_password = 0
                                WHERE id = ?
                            ");
                            if (!$updateUser) {
                                throw new Exception('Failed to update password.');
                            }

                            mysqli_stmt_bind_param($updateUser, "si", $passwordHash, $userId);
                            mysqli_stmt_execute($updateUser);
                            mysqli_stmt_close($updateUser);

                            $useOtp = mysqli_prepare($conn, "
                                UPDATE password_reset_tokens
                                SET used_at = NOW()
                                WHERE id = ?
                            ");
                            if ($useOtp) {
                                mysqli_stmt_bind_param($useOtp, "i", $tokenId);
                                mysqli_stmt_execute($useOtp);
                                mysqli_stmt_close($useOtp);
                            }

                            if (table_exists($conn, 'user_sessions')) {
                                if (column_exists($conn, 'user_sessions', 'revoked_at')) {
                                    $revokeSql = "
                                        UPDATE user_sessions
                                        SET revoked_at = NOW()
                                        WHERE user_id = ? AND portal_type = 'admin' AND revoked_at IS NULL
                                    ";
                                } else {
                                    $revokeSql = "
                                        DELETE FROM user_sessions
                                        WHERE user_id = ? AND portal_type = 'admin'
                                    ";
                                }

                                $revokeStmt = mysqli_prepare($conn, $revokeSql);
                                if ($revokeStmt) {
                                    mysqli_stmt_bind_param($revokeStmt, "i", $userId);
                                    mysqli_stmt_execute($revokeStmt);
                                    mysqli_stmt_close($revokeStmt);
                                }
                            }

                            write_audit_log(
                                $conn,
                                'user',
                                $userId,
                                'password_reset_success',
                                null,
                                ['email' => $email],
                                $userId,
                                'Admin password reset completed.'
                            );

                            mysqli_commit($conn);

                            unset($_SESSION['forgot_password_email']);
                            unset($_SESSION['forgot_password_user_id']);
                            unset($_SESSION['forgot_password_verified']);
                            unset($_SESSION['forgot_password_step']);
                            unset($_SESSION['forgot_password_token_id']);

                            $_SESSION['flash_success'] = 'Password changed successfully. Please login with your new password.';
                            header('Location: login-admin.php');
                            exit;
                        } catch (Throwable $e) {
                            mysqli_rollback($conn);
                            $errorMessage = 'Failed to reset password.';
                        }
                    }
                }
            }
        }
    }
}

/* ---------------- STEP VISIBILITY ---------------- */

$showEmailStep = ($step === 'email');
$showOtpStep = ($step === 'otp');
$showPasswordStep = ($step === 'password');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CorePlx Quality DMS - Forgot Password</title>
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
        <p class="text-muted mb-0">Receive OTP by email and reset your admin password.</p>
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

    <?php if ($showEmailStep): ?>
        <form method="post" action="">
            <input type="hidden" name="action" value="send_otp">

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="Enter email" value="<?php echo e($email); ?>" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">Send OTP</button>

            <div class="text-center footer-help">
                <a href="login-admin.php" class="d-block mb-2">Back to Login</a>
                <span>Need help? <a href="mailto:support@coreplx.com">Contact Support</a></span>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($showOtpStep): ?>
        <form method="post" action="">
            <input type="hidden" name="action" value="verify_otp">

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="Enter email" value="<?php echo e($email); ?>" readonly>
            </div>

            <div class="mb-3">
                <label class="form-label">OTP</label>
                <input type="text" name="otp" class="form-control" placeholder="Enter OTP" maxlength="6" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">Verify OTP</button>

            <div class="text-center footer-help">
                <a href="forgot-password.php?restart=1" class="d-block mb-2">Change Email</a>
                <span>Need help? <a href="mailto:support@coreplx.com">Contact Support</a></span>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($showPasswordStep): ?>
        <form method="post" action="">
            <input type="hidden" name="action" value="reset_password">

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="Enter email" value="<?php echo e($email); ?>" readonly>
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
                <a href="login-admin.php" class="d-block mb-2">Back to Login</a>
                <span>Need help? <a href="mailto:support@coreplx.com">Contact Support</a></span>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>