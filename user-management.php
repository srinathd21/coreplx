<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Please check db.php');
}

mysqli_set_charset($conn, 'utf8mb4');

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function db_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

$hasDesignationColumn = db_has_column($conn, 'users', 'designation');

function generate_event_id(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function current_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function current_user_agent(): string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash_message'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }
    $msg = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
    return $msg;
}

function audit_log(
    mysqli $conn,
    string $entityType,
    ?int $entityId,
    string $action,
    $oldValue,
    $newValue,
    ?int $performedBy,
    string $remarks = ''
): void {
    $eventId = generate_event_id();
    $oldJson = $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $newJson = $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $ip = current_ip();
    $ua = current_user_agent();

    $stmt = $conn->prepare("
        INSERT INTO audit_logs
        (event_id, entity_type, entity_id, action, old_value, new_value, performed_by, remarks, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param(
            'ssisssisss',
            $eventId,
            $entityType,
            $entityId,
            $action,
            $oldJson,
            $newJson,
            $performedBy,
            $remarks,
            $ip,
            $ua
        );
        $stmt->execute();
        $stmt->close();
    }
}

function get_setting(mysqli $conn, string $key, ?string $default = null): ?string
{
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($value);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? (string)$value : $default;
}

function save_setting(mysqli $conn, string $key, string $value, string $type = 'number', ?int $updatedBy = null): void
{
    $check = $conn->prepare("SELECT id, setting_value, value_type, updated_by FROM system_settings WHERE setting_key = ? LIMIT 1");
    $old = null;
    $existingId = 0;
    if ($check) {
        $check->bind_param('s', $key);
        $check->execute();
        $res = $check->get_result();
        if ($row = $res->fetch_assoc()) {
            $existingId = (int)$row['id'];
            $old = $row;
        }
        $check->close();
    }

    if ($existingId > 0) {
        $stmt = $conn->prepare("
            UPDATE system_settings
            SET setting_value = ?, value_type = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('ssii', $value, $type, $updatedBy, $existingId);
            $stmt->execute();
            $stmt->close();
        }
        audit_log(
            $conn,
            'system_setting',
            $existingId,
            'update',
            $old,
            ['setting_key' => $key, 'setting_value' => $value, 'value_type' => $type, 'updated_by' => $updatedBy],
            $updatedBy,
            "System setting '{$key}' updated."
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO system_settings (setting_key, setting_value, value_type, is_active, updated_by)
            VALUES (?, ?, ?, 1, ?)
        ");
        if ($stmt) {
            $stmt->bind_param('sssi', $key, $value, $type, $updatedBy);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            audit_log(
                $conn,
                'system_setting',
                $newId,
                'create',
                null,
                ['setting_key' => $key, 'setting_value' => $value, 'value_type' => $type, 'updated_by' => $updatedBy],
                $updatedBy,
                "System setting '{$key}' created."
            );
        }
    }
}

function get_role_map(mysqli $conn): array
{
    $map = [];
    $res = $conn->query("SELECT id, role_code, role_name FROM roles WHERE is_active = 1 ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int)$row['id']] = $row;
        }
        $res->free();
    }
    return $map;
}

function role_name_to_id(mysqli $conn, string $roleName): int
{
    $stmt = $conn->prepare("SELECT id FROM roles WHERE role_name = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('s', $roleName);
    $stmt->execute();
    $stmt->bind_result($roleId);
    $stmt->fetch();
    $stmt->close();
    return (int)$roleId;
}

function get_user_by_id(mysqli $conn, int $userId, bool $hasDesignationColumn): ?array
{
    $designationSelect = $hasDesignationColumn ? "u.designation," : "NULL AS designation,";
    $sql = "
        SELECT
            u.id,
            u.employee_code,
            u.first_name,
            u.last_name,
            u.email,
            {$designationSelect}
            u.current_role_id,
            r.role_name,
            r.role_code,
            u.department_id,
            d.department_name,
            d.department_code,
            u.status,
            u.created_at,
            u.created_by,
            u.updated_by,
            u.must_change_password,
            u.failed_login_attempts
        FROM users u
        LEFT JOIN roles r ON r.id = u.current_role_id
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function get_department_by_id(mysqli $conn, int $deptId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, department_code, department_name, description, is_active, created_at, updated_at
        FROM departments
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $deptId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function get_user_display_name(?array $row): string
{
    if (!$row) return 'System';
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($name !== '') return $name;
    if (!empty($row['full_name'])) return trim((string)$row['full_name']);
    if (!empty($row['email'])) return (string)$row['email'];
    return 'User #' . (int)($row['id'] ?? 0);
}

function count_active_users(mysqli $conn, ?string $roleCode = null, ?int $departmentId = null, ?int $excludeUserId = null): int
{
    $sql = "
        SELECT COUNT(*) AS cnt
        FROM users u
        LEFT JOIN roles r ON r.id = u.current_role_id
        WHERE u.status = 'active'
    ";
    $types = '';
    $params = [];

    if ($roleCode !== null) {
        $sql .= " AND r.role_code = ?";
        $types .= 's';
        $params[] = $roleCode;
    }
    if ($departmentId !== null) {
        $sql .= " AND u.department_id = ?";
        $types .= 'i';
        $params[] = $departmentId;
    }
    if ($excludeUserId !== null) {
        $sql .= " AND u.id <> ?";
        $types .= 'i';
        $params[] = $excludeUserId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    return (int)$cnt;
}

function redirect_self(): void
{
    header('Location: ' . basename(__FILE__));
    exit;
}

/* --------------------------------------------------------------------------
   AUTH / CURRENT USER
-------------------------------------------------------------------------- */
$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_user_id'] ?? $_SESSION['admin_id'] ?? 0);
$currentUser = null;
$currentRoleCode = '';
$currentRoleName = 'QA Admin';

if ($currentUserId > 0) {
    $sql = "
        SELECT u.*, r.role_code, r.role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.current_role_id
        WHERE u.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $currentUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $currentUser = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($currentUser) {
    $currentRoleCode = (string)($currentUser['role_code'] ?? '');
    $currentRoleName = (string)($currentUser['role_name'] ?? 'QA Admin');
}

if ($currentUserId <= 0) {
    header('Location: login-admin.php');
    exit;
}

if (!in_array($currentRoleCode, ['qa_admin', 'super_admin'], true)) {
    die('Access denied. This page is restricted to QA Admin and Super Admin.');
}

/* --------------------------------------------------------------------------
   SETTINGS / LIMITS
-------------------------------------------------------------------------- */
$limitTotal = (int)(get_setting($conn, 'max_total_active_users', '25') ?: 25);
$limitAdmins = (int)(get_setting($conn, 'max_qa_admins', '5') ?: 5);
$limitEmployees = (int)(get_setting($conn, 'max_employees', '20') ?: 20);

/* --------------------------------------------------------------------------
   POST ACTIONS
-------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $designation = trim((string)($_POST['designation'] ?? ''));
        $roleName = trim((string)($_POST['role_name'] ?? 'Employee'));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($firstName === '' || $lastName === '' || $email === '' || $departmentId <= 0 || $roleName === '') {
            set_flash('danger', 'First name, last name, email, department, and role are required.');
            redirect_self();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('danger', 'Please enter a valid email address.');
            redirect_self();
        }

        if ($userId === 0) {
            if ($password === '' || $confirmPassword === '') {
                set_flash('danger', 'Password and confirm password are required for new user.');
                redirect_self();
            }
        }

        if ($password !== '' || $confirmPassword !== '') {
            if ($password !== $confirmPassword) {
                set_flash('danger', 'Password and confirm password do not match.');
                redirect_self();
            }
            if (strlen($password) < 6) {
                set_flash('danger', 'Password must be at least 6 characters.');
                redirect_self();
            }
        }

        $department = get_department_by_id($conn, $departmentId);
        if (!$department || (int)$department['is_active'] !== 1) {
            set_flash('danger', 'Please choose an active department.');
            redirect_self();
        }

        $roleId = role_name_to_id($conn, $roleName);
        if ($roleId <= 0) {
            set_flash('danger', 'Invalid role selected.');
            redirect_self();
        }

        $roleStmt = $conn->prepare("SELECT role_code, role_name FROM roles WHERE id = ? LIMIT 1");
        $selectedRoleCode = '';
        if ($roleStmt) {
            $roleStmt->bind_param('i', $roleId);
            $roleStmt->execute();
            $roleStmt->bind_result($selectedRoleCode, $selectedRoleName);
            $roleStmt->fetch();
            $roleStmt->close();
        }

        if ($selectedRoleCode === 'super_admin' && $currentRoleCode !== 'super_admin') {
            set_flash('danger', 'Only Super Admin can assign the Super Admin role.');
            redirect_self();
        }

        $dupSql = "SELECT id FROM users WHERE email = ? " . ($userId > 0 ? "AND id <> ?" : "") . " LIMIT 1";
        $dupStmt = $conn->prepare($dupSql);
        if ($dupStmt) {
            if ($userId > 0) {
                $dupStmt->bind_param('si', $email, $userId);
            } else {
                $dupStmt->bind_param('s', $email);
            }
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            if ($dupRes && $dupRes->num_rows > 0) {
                $dupStmt->close();
                set_flash('danger', 'This email address already exists.');
                redirect_self();
            }
            $dupStmt->close();
        }

        $fullName = trim($firstName . ' ' . $lastName);

        if ($userId > 0) {
            $oldUser = get_user_by_id($conn, $userId, $hasDesignationColumn);
            if (!$oldUser) {
                set_flash('danger', 'User not found.');
                redirect_self();
            }

            $currentStatus = (string)($oldUser['status'] ?? 'active');

            if ($currentStatus === 'active') {
                $activeOthers = count_active_users($conn, null, null, $userId);
                if (($activeOthers + 1) > $limitTotal) {
                    set_flash('danger', 'Total active user limit would be exceeded.');
                    redirect_self();
                }

                if ($selectedRoleCode === 'qa_admin') {
                    $qaOthers = count_active_users($conn, 'qa_admin', null, $userId);
                    if (($qaOthers + 1) > $limitAdmins) {
                        set_flash('danger', 'QA Admin limit would be exceeded.');
                        redirect_self();
                    }
                }

                if ($selectedRoleCode === 'employee') {
                    $empOthers = count_active_users($conn, 'employee', null, $userId);
                    if (($empOthers + 1) > $limitEmployees) {
                        set_flash('danger', 'Employee limit would be exceeded.');
                        redirect_self();
                    }
                }

                $deptCap = (int)(get_setting($conn, 'dept_user_cap_' . $departmentId, '0') ?: 0);
                if ($deptCap > 0) {
                    $deptOthers = count_active_users($conn, null, $departmentId, $userId);
                    if (($deptOthers + 1) > $deptCap) {
                        set_flash('danger', 'Department cap would be exceeded for the selected department.');
                        redirect_self();
                    }
                }
            }

            if ($hasDesignationColumn) {
                if ($password !== '') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET first_name = ?, last_name = ?, email = ?, department_id = ?, designation = ?, current_role_id = ?, full_name = ?, password_hash = ?, must_change_password = 0, updated_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param(
                            'sssisisisii',
                            $firstName,
                            $lastName,
                            $email,
                            $departmentId,
                            $designation,
                            $roleId,
                            $fullName,
                            $passwordHash,
                            $currentUserId,
                            $userId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET first_name = ?, last_name = ?, email = ?, department_id = ?, designation = ?, current_role_id = ?, full_name = ?, updated_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param(
                            'sssisisii',
                            $firstName,
                            $lastName,
                            $email,
                            $departmentId,
                            $designation,
                            $roleId,
                            $fullName,
                            $currentUserId,
                            $userId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            } else {
                if ($password !== '') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET first_name = ?, last_name = ?, email = ?, department_id = ?, current_role_id = ?, full_name = ?, password_hash = ?, must_change_password = 0, updated_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param(
                            'sssisisii',
                            $firstName,
                            $lastName,
                            $email,
                            $departmentId,
                            $roleId,
                            $fullName,
                            $passwordHash,
                            $currentUserId,
                            $userId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET first_name = ?, last_name = ?, email = ?, department_id = ?, current_role_id = ?, full_name = ?, updated_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param(
                            'sssisiii',
                            $firstName,
                            $lastName,
                            $email,
                            $departmentId,
                            $roleId,
                            $fullName,
                            $currentUserId,
                            $userId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            if ((int)$oldUser['current_role_id'] !== $roleId) {
                $historyStmt = $conn->prepare("
                    INSERT INTO user_role_history (user_id, old_role_id, new_role_id, reason_for_change, changed_by)
                    VALUES (?, ?, ?, ?, ?)
                ");
                if ($historyStmt) {
                    $reason = 'Role updated from User Management page';
                    $oldRoleId = (int)$oldUser['current_role_id'];
                    $historyStmt->bind_param('iiisi', $userId, $oldRoleId, $roleId, $reason, $currentUserId);
                    $historyStmt->execute();
                    $historyStmt->close();
                }
            }

            $newUser = get_user_by_id($conn, $userId, $hasDesignationColumn);

            audit_log(
                $conn,
                'user',
                $userId,
                'update',
                $oldUser,
                $newUser,
                $currentUserId,
                $password !== '' ? "User '{$fullName}' updated and password changed from User Management." : "User '{$fullName}' updated from User Management."
            );

            set_flash('success', $password !== '' ? 'User and password updated successfully.' : 'User updated successfully.');
            redirect_self();
        } else {
            $activeCount = count_active_users($conn);
            if (($activeCount + 1) > $limitTotal) {
                set_flash('danger', 'Maximum total active user limit reached.');
                redirect_self();
            }

            if ($selectedRoleCode === 'qa_admin') {
                $qaCount = count_active_users($conn, 'qa_admin');
                if (($qaCount + 1) > $limitAdmins) {
                    set_flash('danger', 'Maximum QA Admin limit reached.');
                    redirect_self();
                }
            }

            if ($selectedRoleCode === 'employee') {
                $empCount = count_active_users($conn, 'employee');
                if (($empCount + 1) > $limitEmployees) {
                    set_flash('danger', 'Maximum Employee limit reached.');
                    redirect_self();
                }
            }

            $deptCap = (int)(get_setting($conn, 'dept_user_cap_' . $departmentId, '0') ?: 0);
            if ($deptCap > 0) {
                $deptCount = count_active_users($conn, null, $departmentId);
                if (($deptCount + 1) > $deptCap) {
                    set_flash('danger', 'Department cap reached for the selected department.');
                    redirect_self();
                }
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            if ($hasDesignationColumn) {
                $stmt = $conn->prepare("
                    INSERT INTO users
                    (first_name, last_name, email, password_hash, current_role_id, department_id, designation, status, must_change_password, timezone, created_by, updated_by, full_name, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, 'Asia/Kolkata', ?, ?, ?, NOW(), NOW())
                ");
                if ($stmt) {
                    $stmt->bind_param(
                        'ssssiisiss',
                        $firstName,
                        $lastName,
                        $email,
                        $passwordHash,
                        $roleId,
                        $departmentId,
                        $designation,
                        $currentUserId,
                        $currentUserId,
                        $fullName
                    );
                    $stmt->execute();
                    $newUserId = (int)$stmt->insert_id;
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO users
                    (first_name, last_name, email, password_hash, current_role_id, department_id, status, must_change_password, timezone, created_by, updated_by, full_name, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'active', 0, 'Asia/Kolkata', ?, ?, ?, NOW(), NOW())
                ");
                if ($stmt) {
                    $stmt->bind_param(
                        'ssssiiiss',
                        $firstName,
                        $lastName,
                        $email,
                        $passwordHash,
                        $roleId,
                        $departmentId,
                        $currentUserId,
                        $currentUserId,
                        $fullName
                    );
                    $stmt->execute();
                    $newUserId = (int)$stmt->insert_id;
                    $stmt->close();
                }
            }

            if (!empty($newUserId)) {
                $employeeCode = 'EMP' . str_pad((string)$newUserId, 3, '0', STR_PAD_LEFT);
                $codeStmt = $conn->prepare("UPDATE users SET employee_code = ? WHERE id = ?");
                if ($codeStmt) {
                    $codeStmt->bind_param('si', $employeeCode, $newUserId);
                    $codeStmt->execute();
                    $codeStmt->close();
                }

                $roleHistoryStmt = $conn->prepare("
                    INSERT INTO user_role_history (user_id, old_role_id, new_role_id, reason_for_change, changed_by)
                    VALUES (?, NULL, ?, ?, ?)
                ");
                if ($roleHistoryStmt) {
                    $reason = 'Initial role assigned during user creation';
                    $roleHistoryStmt->bind_param('iisi', $newUserId, $roleId, $reason, $currentUserId);
                    $roleHistoryStmt->execute();
                    $roleHistoryStmt->close();
                }

                $newUser = get_user_by_id($conn, $newUserId, $hasDesignationColumn);

                audit_log(
                    $conn,
                    'user',
                    $newUserId,
                    'create',
                    null,
                    $newUser,
                    $currentUserId,
                    "User '{$fullName}' created from User Management."
                );

                set_flash('success', 'User created successfully.');
            } else {
                set_flash('danger', 'Unable to create user.');
            }

            redirect_self();
        }
    }

    if ($action === 'toggle_user_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = get_user_by_id($conn, $userId, $hasDesignationColumn);

        if (!$user) {
            set_flash('danger', 'User not found.');
            redirect_self();
        }

        if ($userId === $currentUserId && (string)$user['status'] === 'active') {
            set_flash('danger', 'You cannot deactivate your own account.');
            redirect_self();
        }

        $oldUser = $user;
        $newStatus = ((string)$user['status'] === 'active') ? 'inactive' : 'active';

        if ($newStatus === 'active') {
            $activeCount = count_active_users($conn);
            if (($activeCount + 1) > $limitTotal) {
                set_flash('danger', 'Maximum total active user limit reached.');
                redirect_self();
            }

            $roleCode = (string)($user['role_code'] ?? '');
            if ($roleCode === 'qa_admin') {
                $qaCount = count_active_users($conn, 'qa_admin');
                if (($qaCount + 1) > $limitAdmins) {
                    set_flash('danger', 'Maximum QA Admin limit reached.');
                    redirect_self();
                }
            }

            if ($roleCode === 'employee') {
                $empCount = count_active_users($conn, 'employee');
                if (($empCount + 1) > $limitEmployees) {
                    set_flash('danger', 'Maximum Employee limit reached.');
                    redirect_self();
                }
            }

            $deptId = (int)($user['department_id'] ?? 0);
            $deptCap = (int)(get_setting($conn, 'dept_user_cap_' . $deptId, '0') ?: 0);
            if ($deptCap > 0) {
                $deptCount = count_active_users($conn, null, $deptId);
                if (($deptCount + 1) > $deptCap) {
                    set_flash('danger', 'Department cap reached for this user’s department.');
                    redirect_self();
                }
            }
        }

        $stmt = $conn->prepare("
            UPDATE users
            SET status = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('sii', $newStatus, $currentUserId, $userId);
            $stmt->execute();
            $stmt->close();
        }

        $newUser = get_user_by_id($conn, $userId, $hasDesignationColumn);

        audit_log(
            $conn,
            'user',
            $userId,
            $newStatus === 'active' ? 'activate' : 'deactivate',
            $oldUser,
            $newUser,
            $currentUserId,
            "User status changed to '{$newStatus}'."
        );

        set_flash('success', 'User status updated successfully.');
        redirect_self();
    }

    if ($action === 'save_department') {
        $deptId = (int)($_POST['department_id'] ?? 0);
        $deptName = trim((string)($_POST['department_name'] ?? ''));
        $deptCode = strtoupper(trim((string)($_POST['department_code'] ?? '')));

        if ($deptName === '') {
            set_flash('danger', 'Department name is required.');
            redirect_self();
        }

        $dupSql = "
            SELECT id
            FROM departments
            WHERE (department_name = ? OR department_code = ?)
            " . ($deptId > 0 ? "AND id <> ?" : "") . "
            LIMIT 1
        ";
        $dupStmt = $conn->prepare($dupSql);
        if ($dupStmt) {
            if ($deptId > 0) {
                $dupStmt->bind_param('ssi', $deptName, $deptCode, $deptId);
            } else {
                $dupStmt->bind_param('ss', $deptName, $deptCode);
            }
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            if ($dupRes && $dupRes->num_rows > 0) {
                $dupStmt->close();
                set_flash('danger', 'Department name or code already exists.');
                redirect_self();
            }
            $dupStmt->close();
        }

        if ($deptId > 0) {
            $oldDept = get_department_by_id($conn, $deptId);
            if (!$oldDept) {
                set_flash('danger', 'Department not found.');
                redirect_self();
            }

            $stmt = $conn->prepare("
                UPDATE departments
                SET department_name = ?, department_code = ?, updated_at = NOW()
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('ssi', $deptName, $deptCode, $deptId);
                $stmt->execute();
                $stmt->close();
            }

            $newDept = get_department_by_id($conn, $deptId);

            audit_log(
                $conn,
                'department',
                $deptId,
                'update',
                $oldDept,
                $newDept,
                $currentUserId,
                "Department '{$deptName}' updated."
            );

            set_flash('success', 'Department updated successfully.');
            redirect_self();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO departments (department_code, department_name, is_active, created_at, updated_at)
                VALUES (?, ?, 1, NOW(), NOW())
            ");
            if ($stmt) {
                $stmt->bind_param('ss', $deptCode, $deptName);
                $stmt->execute();
                $newDeptId = (int)$stmt->insert_id;
                $stmt->close();

                $newDept = get_department_by_id($conn, $newDeptId);

                audit_log(
                    $conn,
                    'department',
                    $newDeptId,
                    'create',
                    null,
                    $newDept,
                    $currentUserId,
                    "Department '{$deptName}' created."
                );

                set_flash('success', 'Department created successfully.');
            } else {
                set_flash('danger', 'Unable to create department.');
            }

            redirect_self();
        }
    }

    if ($action === 'toggle_department_status') {
        $deptId = (int)($_POST['department_id'] ?? 0);
        $dept = get_department_by_id($conn, $deptId);

        if (!$dept) {
            set_flash('danger', 'Department not found.');
            redirect_self();
        }

        $oldDept = $dept;
        $newActive = ((int)$dept['is_active'] === 1) ? 0 : 1;

        $stmt = $conn->prepare("UPDATE departments SET is_active = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $newActive, $deptId);
            $stmt->execute();
            $stmt->close();
        }

        $newDept = get_department_by_id($conn, $deptId);

        audit_log(
            $conn,
            'department',
            $deptId,
            $newActive === 1 ? 'activate' : 'deactivate',
            $oldDept,
            $newDept,
            $currentUserId,
            "Department '{$dept['department_name']}' status changed."
        );

        set_flash('success', 'Department status updated successfully.');
        redirect_self();
    }

    if ($action === 'save_limits') {
        $newTotal = (int)($_POST['limit_total'] ?? 0);
        $newAdmins = (int)($_POST['limit_admins'] ?? 0);
        $newEmployees = (int)($_POST['limit_employees'] ?? 0);

        if ($newTotal <= 0 || $newAdmins <= 0 || $newEmployees <= 0) {
            set_flash('danger', 'All limits must be greater than zero.');
            redirect_self();
        }

        save_setting($conn, 'max_total_active_users', (string)$newTotal, 'number', $currentUserId);
        save_setting($conn, 'max_qa_admins', (string)$newAdmins, 'number', $currentUserId);
        save_setting($conn, 'max_employees', (string)$newEmployees, 'number', $currentUserId);

        audit_log(
            $conn,
            'system_limits',
            null,
            'update',
            ['max_total_active_users' => $limitTotal, 'max_qa_admins' => $limitAdmins, 'max_employees' => $limitEmployees],
            ['max_total_active_users' => $newTotal, 'max_qa_admins' => $newAdmins, 'max_employees' => $newEmployees],
            $currentUserId,
            'System limits updated from User Management.'
        );

        set_flash('success', 'System limits saved successfully.');
        redirect_self();
    }

    if ($action === 'save_department_caps') {
        $deptCaps = $_POST['dept_caps'] ?? [];
        if (!is_array($deptCaps)) {
            $deptCaps = [];
        }

        $oldCaps = [];
        $newCaps = [];

        foreach ($deptCaps as $deptIdRaw => $capRaw) {
            $deptId = (int)$deptIdRaw;
            if ($deptId <= 0) continue;

            $oldValue = get_setting($conn, 'dept_user_cap_' . $deptId, '0');
            $oldCaps[$deptId] = (int)($oldValue ?: 0);

            $cap = trim((string)$capRaw);
            $capValue = ($cap === '') ? 0 : max(0, (int)$cap);

            save_setting($conn, 'dept_user_cap_' . $deptId, (string)$capValue, 'number', $currentUserId);
            $newCaps[$deptId] = $capValue;
        }

        audit_log(
            $conn,
            'department_caps',
            null,
            'update',
            $oldCaps,
            $newCaps,
            $currentUserId,
            'Department user caps updated from User Management.'
        );

        set_flash('success', 'Department caps saved successfully.');
        redirect_self();
    }
}

/* --------------------------------------------------------------------------
   FETCH PAGE DATA
-------------------------------------------------------------------------- */
$flash = get_flash();

$departments = [];
$deptRes = $conn->query("
    SELECT d.*,
           (
               SELECT COUNT(*)
               FROM users u
               WHERE u.department_id = d.id AND u.status = 'active'
           ) AS active_user_count
    FROM departments d
    ORDER BY d.department_name ASC
");
if ($deptRes) {
    while ($row = $deptRes->fetch_assoc()) {
        $row['cap'] = (int)(get_setting($conn, 'dept_user_cap_' . (int)$row['id'], '0') ?: 0);
        $departments[] = $row;
    }
    $deptRes->free();
}

$users = [];
$designationSelect = $hasDesignationColumn ? "u.designation," : "NULL AS designation,";
$userSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        {$designationSelect}
        u.status,
        u.created_at,
        u.created_by,
        u.current_role_id,
        u.department_id,
        d.department_name,
        r.role_name,
        r.role_code,
        creator.first_name AS creator_first_name,
        creator.last_name AS creator_last_name,
        creator.email AS creator_email
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN roles r ON r.id = u.current_role_id
    LEFT JOIN users creator ON creator.id = u.created_by
    ORDER BY u.created_at DESC, u.id DESC
";
$userRes = $conn->query($userSql);
if ($userRes) {
    while ($row = $userRes->fetch_assoc()) {
        $creatorName = trim(($row['creator_first_name'] ?? '') . ' ' . ($row['creator_last_name'] ?? ''));
        if ($creatorName === '') {
            $creatorName = $row['creator_email'] ?: 'System';
        }
        $row['assigned_by_name'] = $creatorName;

        $statusText = ((string)$row['status'] === 'active') ? 'Active' : ucfirst((string)$row['status']);
        $row['status_text'] = $statusText;

        $users[] = $row;
    }
    $userRes->free();
}

$totalUsersShown = count($users);
$activeUsersCount = count(array_filter($users, fn($u) => strtolower((string)$u['status']) === 'active'));
$inactiveUsersCount = $totalUsersShown - $activeUsersCount;

$activeQaCount = count_active_users($conn, 'qa_admin');
$activeEmpCount = count_active_users($conn, 'employee');
$activeTotalCount = count_active_users($conn);

$activeDeptCount = count(array_filter($departments, fn($d) => (int)$d['is_active'] === 1));
$inactiveDeptCount = count($departments) - $activeDeptCount;

$roleOptions = [];
$roleRes = $conn->query("SELECT id, role_name, role_code FROM roles WHERE is_active = 1 ORDER BY id ASC");
if ($roleRes) {
    while ($r = $roleRes->fetch_assoc()) {
        if ($r['role_code'] === 'super_admin' && $currentRoleCode !== 'super_admin') {
            continue;
        }
        $roleOptions[] = $r;
    }
    $roleRes->free();
}

$departmentsJson = json_encode(array_map(function ($d) {
    return [
        'id' => (int)$d['id'],
        'name' => $d['department_name'],
        'code' => $d['department_code'],
        'active' => (int)$d['is_active'] === 1,
        'cap' => (int)$d['cap'],
    ];
}, $departments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$usersJson = json_encode(array_map(function ($u) {
    return [
        'id' => (int)$u['id'],
        'first_name' => $u['first_name'],
        'last_name' => $u['last_name'],
        'email' => $u['email'],
        'designation' => $u['designation'] ?? '',
        'department_id' => (int)$u['department_id'],
        'department_name' => $u['department_name'],
        'role_name' => $u['role_name'],
        'status' => $u['status'],
    ];
}, $users), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$currentDisplayName = get_user_display_name($currentUser);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - User Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .field-locked { background:#f5f7fa !important; color:#6b7280 !important; cursor:not-allowed; }
    .mgmt-tab-strip { display:flex; gap:4px; border-bottom:2px solid #e0e7ef; margin-bottom:20px; }
    .mgmt-tab { padding:10px 22px; font-size:13px; font-weight:600; color:#6b7280; border:none; background:none; border-bottom:3px solid transparent; margin-bottom:-2px; cursor:pointer; transition:color .15s, border-color .15s; }
    .mgmt-tab:hover { color:#1a3a6e; }
    .mgmt-tab.active { color:#1a3a6e; border-bottom-color:#2563eb; }

    .limit-bar { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
    .limit-bar .fill { height:100%; border-radius:4px; transition:width .3s; }
    .fill-ok { background:#16a34a; }
    .fill-warn { background:#f59e0b; }
    .fill-danger { background:#dc2626; }

    .limit-card { border:1px solid #e0e7ef; border-radius:10px; padding:18px 20px; background:#fff; }
    .limit-val { font-size:2rem; font-weight:800; color:#1a3a6e; line-height:1; }
    .limit-of { font-size:13px; color:#6b7280; margin-top:2px; }

    .dept-row { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f0f2f5; font-size:13px; }
    .dept-row:last-child { border-bottom:none; }
    .dept-color-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }

    .alert-custom { border-radius:10px; font-size:13px; }
    .table td, .table th { vertical-align:middle; }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item"><a class="nav-link" href="dashboard-admin.php">Dashboard</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item" href="repository.php">Repository</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-trail.php">Audit Trail</a></li>
            <li><a class="dropdown-item" href="document-assignment.php">Document Assignment</a></li>
            <li><a class="dropdown-item active" href="user-management.php">User Management</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($currentRoleName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small"><?php echo e($currentDisplayName); ?></span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <div class="mb-4">
    <h1 class="page-title mb-2">User Management</h1>
    <p class="page-subtitle mb-0">Manage users, departments and system limits from one place.</p>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?php echo e($flash['type'] === 'danger' ? 'danger' : 'success'); ?> alert-custom mb-4">
      <?php echo e($flash['message']); ?>
    </div>
  <?php endif; ?>

  <div class="mgmt-tab-strip">
    <button class="mgmt-tab active" id="tabUsers" onclick="switchTab('users')">Users</button>
    <button class="mgmt-tab" id="tabDepts" onclick="switchTab('depts')">Departments</button>
    <button class="mgmt-tab" id="tabLimits" onclick="switchTab('limits')">System Limits</button>
  </div>

  <div id="panelUsers">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div class="d-flex gap-2 flex-wrap">
        <input type="text" class="form-control form-control-sm" id="userSearch"
               placeholder="Search name or email..." oninput="filterUsers()"
               style="max-width:240px;">
        <select class="form-select form-select-sm" id="filterDept" onchange="filterUsers()" style="max-width:180px;">
          <option value="">All Departments</option>
          <?php foreach ($departments as $dept): ?>
            <?php if ((int)$dept['is_active'] === 1): ?>
              <option value="<?php echo e($dept['department_name']); ?>"><?php echo e($dept['department_name']); ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" id="filterRole" onchange="filterUsers()" style="max-width:160px;">
          <option value="">All Roles</option>
          <?php foreach ($roleOptions as $role): ?>
            <option value="<?php echo e($role['role_name']); ?>"><?php echo e($role['role_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" id="filterUserStatus" onchange="filterUsers()" style="max-width:140px;">
          <option value="">All Statuses</option>
          <option value="Active">Active</option>
          <option value="Inactive">Inactive</option>
          <option value="Locked">Locked</option>
          <option value="Suspended">Suspended</option>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" onclick="openAddUserModal()">+ Add User</button>
    </div>

    <div class="card cp-card" style="padding:0;">
      <div class="table-responsive">
        <table class="table mb-0" id="usersTable">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Department</th>
              <th>Role</th>
              <th>Assigned By</th>
              <th>Joined</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="usersBody">
            <?php foreach ($users as $u): ?>
              <?php
                $first = (string)$u['first_name'];
                $last = (string)$u['last_name'];
                $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
                $roleName = (string)($u['role_name'] ?? '');
                $statusLower = strtolower((string)$u['status']);
                $statusText = $u['status_text'];
                $designation = trim((string)($u['designation'] ?? ''));
                $displayName = trim($first . ' ' . $last);
                $badgeRole = 'badge-soft-secondary';
                if ($roleName === 'QA Admin') $badgeRole = 'badge-soft-info';
                if ($roleName === 'Super Admin') $badgeRole = 'badge-dark-soft';
                $statusBadge = $statusLower === 'active' ? 'badge-soft-success' : ($statusLower === 'inactive' ? 'badge-soft-danger' : 'badge-soft-secondary');
              ?>
              <tr
                class="user-row"
                data-search="<?php echo e(strtolower($displayName . ' ' . $u['email'])); ?>"
                data-dept="<?php echo e($u['department_name']); ?>"
                data-role="<?php echo e($roleName); ?>"
                data-status="<?php echo e($statusText); ?>"
              >
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div style="width:30px;height:30px;border-radius:50%;background:#dbeafe;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">
                      <?php echo e($initials ?: 'U'); ?>
                    </div>
                    <div>
                      <div class="fw-semibold" style="font-size:13px;"><?php echo e($displayName); ?></div>
                      <div class="text-secondary" style="font-size:11px;"><?php echo e($designation !== '' ? $designation : '—'); ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-size:12px;color:#6b7280;"><?php echo e($u['email']); ?></td>
                <td style="font-size:13px;"><?php echo e($u['department_name'] ?: '—'); ?></td>
                <td><span class="badge <?php echo e($badgeRole); ?>"><?php echo e($roleName ?: '—'); ?></span></td>
                <td style="font-size:12px;color:#6b7280;"><?php echo e($u['assigned_by_name']); ?></td>
                <td style="font-size:12px;color:#6b7280;"><?php echo e(date('d M Y', strtotime((string)$u['created_at']))); ?></td>
                <td><span class="badge <?php echo e($statusBadge); ?>"><?php echo e($statusText); ?></span></td>
                <td style="white-space:nowrap;">
                  <button class="btn btn-sm btn-outline-secondary"
                          style="height:28px;padding:0 10px;font-size:12px;"
                          onclick="editUser(<?php echo (int)$u['id']; ?>)">Edit</button>

                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="toggle_user_status">
                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                    <?php if ($statusLower === 'active'): ?>
                      <button type="submit"
                              class="btn btn-sm"
                              style="height:28px;padding:0 10px;font-size:12px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;"
                              onclick="return confirm('Are you sure you want to deactivate <?php echo e($displayName); ?>? They will lose access immediately. Audit trail is preserved.');">
                        Deactivate
                      </button>
                    <?php else: ?>
                      <button type="submit"
                              class="btn btn-sm btn-outline-success"
                              style="height:28px;padding:0 10px;font-size:12px;"
                              onclick="return confirm('Are you sure you want to activate <?php echo e($displayName); ?>?');">
                        Activate
                      </button>
                    <?php endif; ?>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="text-center text-secondary py-4 small <?php echo $totalUsersShown > 0 ? 'd-none' : ''; ?>" id="usersEmpty">No users found.</div>
      <div class="px-3 py-2 border-top" style="font-size:12px;color:#6b7280;" id="userCountLine">
        Showing <?php echo (int)$totalUsersShown; ?> user<?php echo $totalUsersShown !== 1 ? 's' : ''; ?> · <?php echo (int)$activeUsersCount; ?> active · <?php echo (int)$inactiveUsersCount; ?> inactive
      </div>
    </div>
  </div>

  <div id="panelDepts" class="d-none">
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card cp-card" style="padding:0;">
          <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom">
            <div>
              <div class="fw-bold" style="color:#1a3a6e;font-size:14px;">Departments</div>
              <div class="text-secondary" style="font-size:12px;" id="deptCountLine">
                <?php echo (int)$activeDeptCount; ?> active · <?php echo (int)$inactiveDeptCount; ?> inactive
              </div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="openAddDeptModal()">+ Add Department</button>
          </div>

          <div id="deptList">
            <?php
            $deptColors = ['#2563eb','#16a34a','#d97706','#9333ea','#0891b2','#dc2626','#ea580c'];
            foreach ($departments as $i => $d):
              $userCount = (int)$d['active_user_count'];
              $active = (int)$d['is_active'] === 1;
              $deptName = (string)$d['department_name'];
            ?>
              <div class="dept-row">
                <div class="d-flex align-items-center gap-3">
                  <div class="dept-color-dot" style="background:<?php echo e($deptColors[$i % count($deptColors)]); ?>;"></div>
                  <div>
                    <div class="fw-semibold" style="font-size:13px;<?php echo !$active ? 'color:#9ca3af;' : ''; ?>">
                      <?php echo e($deptName); ?>
                      <?php if (!empty($d['department_code'])): ?>
                        <span style="font-size:11px;color:#6b7280;font-weight:400;">(<?php echo e($d['department_code']); ?>)</span>
                      <?php endif; ?>
                      <?php if (!$active): ?>
                        <span class="badge badge-soft-secondary ms-1" style="font-size:10px;">Inactive</span>
                      <?php endif; ?>
                    </div>
                    <div style="font-size:11px;color:#9ca3af;">
                      <?php echo (int)$userCount; ?> active user<?php echo $userCount !== 1 ? 's' : ''; ?>
                      <?php if ((int)$d['cap'] > 0): ?>
                        · cap <?php echo (int)$d['cap']; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="d-flex gap-2">
                  <button class="btn btn-sm btn-outline-secondary"
                          style="height:28px;padding:0 10px;font-size:12px;"
                          onclick="editDept(<?php echo (int)$d['id']; ?>)">Edit</button>

                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="toggle_department_status">
                    <input type="hidden" name="department_id" value="<?php echo (int)$d['id']; ?>">
                    <?php if ($active): ?>
                      <button type="submit"
                              class="btn btn-sm"
                              style="height:28px;padding:0 10px;font-size:12px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;"
                              onclick="return confirm('Are you sure you want to deactivate the &quot;<?php echo e($deptName); ?>&quot; department?');">
                        Deactivate
                      </button>
                    <?php else: ?>
                      <button type="submit"
                              class="btn btn-sm btn-outline-success"
                              style="height:28px;padding:0 10px;font-size:12px;"
                              onclick="return confirm('Are you sure you want to activate the &quot;<?php echo e($deptName); ?>&quot; department?');">
                        Activate
                      </button>
                    <?php endif; ?>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card cp-card mb-3">
          <div class="card-body">
            <h2 class="card-title mb-1">About Departments</h2>
            <p class="card-subtitle mb-3">How departments are used across the system.</p>
            <ul class="small text-secondary note-list mb-0">
              <li>Departments appear in the <strong>Create Document</strong> form.</li>
              <li>Departments appear in <strong>Document Assignment</strong> for bulk assign.</li>
              <li>Users are linked to a department on creation.</li>
              <li>Deactivating a department does not delete users — it hides it from new selections.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="panelLimits" class="d-none">

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="limit-card">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div class="text-secondary" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Total Active Users</div>
              <div class="limit-val mt-1" id="lcTotalVal"><?php echo (int)$activeTotalCount; ?></div>
              <div class="limit-of" id="lcTotalOf">of <?php echo (int)$limitTotal; ?> limit</div>
            </div>
            <span style="font-size:1.6rem;">👥</span>
          </div>
          <div class="limit-bar"><div class="fill" id="lcTotalBar" style="width:0%"></div></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="limit-card">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div class="text-secondary" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">QA Admins</div>
              <div class="limit-val mt-1" id="lcAdminVal"><?php echo (int)$activeQaCount; ?></div>
              <div class="limit-of" id="lcAdminOf">of <?php echo (int)$limitAdmins; ?> limit</div>
            </div>
            <span style="font-size:1.6rem;">🔑</span>
          </div>
          <div class="limit-bar"><div class="fill" id="lcAdminBar" style="width:0%"></div></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="limit-card">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div class="text-secondary" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Employees</div>
              <div class="limit-val mt-1" id="lcEmpVal"><?php echo (int)$activeEmpCount; ?></div>
              <div class="limit-of" id="lcEmpOf">of <?php echo (int)$limitEmployees; ?> limit</div>
            </div>
            <span style="font-size:1.6rem;">👤</span>
          </div>
          <div class="limit-bar"><div class="fill" id="lcEmpBar" style="width:0%"></div></div>
        </div>
      </div>
    </div>

    <div class="card cp-card">
      <div class="card-body">
        <h2 class="card-title mb-1">Configure Limits</h2>
        <p class="card-subtitle mb-4">Set the maximum number of users allowed per role. Changes take effect immediately.</p>

        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="save_limits">

          <div class="col-md-4">
            <label class="form-label">Max Total Active Users <span class="text-danger">*</span></label>
            <input class="form-control" type="number" name="limit_total" id="limTotal" min="1" max="500" value="<?php echo (int)$limitTotal; ?>">
            <div class="form-text">Includes all roles. Prevents new users being added when reached.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Max QA Admins <span class="text-danger">*</span></label>
            <input class="form-control" type="number" name="limit_admins" id="limAdmin" min="1" max="50" value="<?php echo (int)$limitAdmins; ?>">
            <div class="form-text">Controls how many QA Admin roles can be active at once.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Max Employees <span class="text-danger">*</span></label>
            <input class="form-control" type="number" name="limit_employees" id="limEmp" min="1" max="500" value="<?php echo (int)$limitEmployees; ?>">
            <div class="form-text">Controls how many Employee roles can be active at once.</div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary" type="submit">Save Limits</button>
          </div>
        </form>

        <hr class="my-4">
        <h2 class="card-title mb-1" style="font-size:.95rem;">Per-Department User Cap <span class="text-secondary fw-normal">(optional)</span></h2>
        <p class="card-subtitle mb-3">Set a maximum number of users per department. Leave blank or 0 for no limit.</p>

        <form method="post">
          <input type="hidden" name="action" value="save_department_caps">
          <div id="deptLimitRows" class="row g-3">
            <?php foreach ($departments as $d): ?>
              <?php if ((int)$d['is_active'] === 1): ?>
                <div class="col-md-4">
                  <label class="form-label">
                    <?php echo e($d['department_name']); ?>
                    <span style="font-size:11px;color:#9ca3af;">(<?php echo (int)$d['active_user_count']; ?> current)</span>
                  </label>
                  <input class="form-control"
                         type="number"
                         placeholder="No limit"
                         min="0"
                         max="200"
                         id="dlim_<?php echo (int)$d['id']; ?>"
                         name="dept_caps[<?php echo (int)$d['id']; ?>]"
                         value="<?php echo (int)$d['cap'] > 0 ? (int)$d['cap'] : ''; ?>">
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <div class="mt-3">
            <button class="btn btn-outline-primary btn-sm" type="submit">Save Department Caps</button>
          </div>
        </form>
      </div>
    </div>

  </div>

</div>
</main>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="save_user">
      <input type="hidden" name="user_id" id="uId" value="0">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="userModalTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">First Name <span class="text-danger">*</span></label>
            <input class="form-control" id="uFirstName" name="first_name" type="text">
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name <span class="text-danger">*</span></label>
            <input class="form-control" id="uLastName" name="last_name" type="text">
          </div>
          <div class="col-12">
            <label class="form-label">Email Address <span class="text-danger">*</span></label>
            <input class="form-control" id="uEmail" name="email" type="email" placeholder="name@company.com">
            <div class="form-text">This will be used as their login ID.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Department <span class="text-danger">*</span></label>
            <select class="form-select" id="uDept" name="department_id">
              <?php foreach ($departments as $dept): ?>
                <?php if ((int)$dept['is_active'] === 1): ?>
                  <option value="<?php echo (int)$dept['id']; ?>"><?php echo e($dept['department_name']); ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Designation</label>
            <input class="form-control" id="uDesignation" name="designation" type="text" placeholder="e.g. QA Engineer">
          </div>
          <div class="col-12">
            <label class="form-label">Role <span class="text-danger">*</span></label>
            <select class="form-select" id="uRole" name="role_name">
              <?php foreach ($roleOptions as $role): ?>
                <option value="<?php echo e($role['role_name']); ?>"><?php echo e($role['role_name']); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Super Admin role can only be assigned by another Super Admin.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Password <span class="text-danger" id="passwordRequiredMark">*</span></label>
            <input class="form-control" id="uPassword" name="password" type="password" placeholder="Enter password">
            <div class="form-text" id="uPasswordHelp">Required for new user.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Confirm Password <span class="text-danger" id="confirmPasswordRequiredMark">*</span></label>
            <input class="form-control" id="uConfirmPassword" name="confirm_password" type="password" placeholder="Confirm password">
            <div class="form-text" id="uConfirmPasswordHelp">Must match password.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save User</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="deptModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="save_department">
      <input type="hidden" name="department_id" id="deptId" value="0">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="deptModalTitle">Add Department</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Department Name <span class="text-danger">*</span></label>
          <input class="form-control" id="deptName" name="department_name" type="text" placeholder="e.g. Quality Assurance">
        </div>
        <div class="mb-3">
          <label class="form-label">Short Code</label>
          <input class="form-control" id="deptCode" name="department_code" type="text" placeholder="e.g. QA" maxlength="30" style="text-transform:uppercase;">
          <div class="form-text">Used in document ID prefix if applicable.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const USERS = <?php echo $usersJson ?: '[]'; ?>;
const DEPARTMENTS = <?php echo $departmentsJson ?: '[]'; ?>;

function switchTab(tab) {
  ['users','depts','limits'].forEach(function(t) {
    document.getElementById('panel' + cap(t)).classList.toggle('d-none', t !== tab);
    document.getElementById('tab' + cap(t)).classList.toggle('active', t === tab);
  });
}
function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

function filterUsers() {
  const q = document.getElementById('userSearch').value.toLowerCase().trim();
  const dept = document.getElementById('filterDept').value;
  const role = document.getElementById('filterRole').value;
  const stat = document.getElementById('filterUserStatus').value;

  const rows = Array.from(document.querySelectorAll('.user-row'));
  let visible = 0;
  let active = 0;

  rows.forEach(function(row) {
    const search = row.getAttribute('data-search') || '';
    const rowDept = row.getAttribute('data-dept') || '';
    const rowRole = row.getAttribute('data-role') || '';
    const rowStatus = row.getAttribute('data-status') || '';

    const show = (!q || search.includes(q)) &&
                 (!dept || rowDept === dept) &&
                 (!role || rowRole === role) &&
                 (!stat || rowStatus === stat);

    row.style.display = show ? '' : 'none';
    if (show) {
      visible++;
      if (rowStatus === 'Active') active++;
    }
  });

  document.getElementById('usersEmpty').classList.toggle('d-none', visible > 0);
  document.getElementById('userCountLine').textContent =
    'Showing ' + visible + ' user' + (visible !== 1 ? 's' : '') +
    ' · ' + active + ' active · ' + (visible - active) + ' inactive';
}

function setPasswordMode(isEdit) {
  const passwordRequiredMark = document.getElementById('passwordRequiredMark');
  const confirmPasswordRequiredMark = document.getElementById('confirmPasswordRequiredMark');
  const passwordHelp = document.getElementById('uPasswordHelp');
  const confirmHelp = document.getElementById('uConfirmPasswordHelp');

  if (isEdit) {
    passwordRequiredMark.style.display = 'none';
    confirmPasswordRequiredMark.style.display = 'none';
    passwordHelp.textContent = 'Optional. Fill only if you want to change password.';
    confirmHelp.textContent = 'Fill only when changing password.';
  } else {
    passwordRequiredMark.style.display = '';
    confirmPasswordRequiredMark.style.display = '';
    passwordHelp.textContent = 'Required for new user.';
    confirmHelp.textContent = 'Must match password.';
  }
}

function openAddUserModal() {
  document.getElementById('userModalTitle').textContent = 'Add User';
  document.getElementById('uId').value = '0';
  document.getElementById('uFirstName').value = '';
  document.getElementById('uLastName').value = '';
  document.getElementById('uEmail').value = '';
  document.getElementById('uDesignation').value = '';
  document.getElementById('uPassword').value = '';
  document.getElementById('uConfirmPassword').value = '';
  if (document.getElementById('uRole').options.length) {
    document.getElementById('uRole').selectedIndex = 0;
  }
  if (document.getElementById('uDept').options.length) {
    document.getElementById('uDept').selectedIndex = 0;
  }
  setPasswordMode(false);
  new bootstrap.Modal(document.getElementById('userModal')).show();
}

function editUser(id) {
  const u = USERS.find(x => Number(x.id) === Number(id));
  if (!u) return;

  document.getElementById('userModalTitle').textContent = 'Edit User — ' + (u.first_name || '') + ' ' + (u.last_name || '');
  document.getElementById('uId').value = u.id || 0;
  document.getElementById('uFirstName').value = u.first_name || '';
  document.getElementById('uLastName').value = u.last_name || '';
  document.getElementById('uEmail').value = u.email || '';
  document.getElementById('uDesignation').value = u.designation || '';
  document.getElementById('uPassword').value = '';
  document.getElementById('uConfirmPassword').value = '';
  document.getElementById('uRole').value = u.role_name || 'Employee';
  document.getElementById('uDept').value = String(u.department_id || '');
  setPasswordMode(true);
  new bootstrap.Modal(document.getElementById('userModal')).show();
}

function openAddDeptModal() {
  document.getElementById('deptModalTitle').textContent = 'Add Department';
  document.getElementById('deptId').value = '0';
  document.getElementById('deptName').value = '';
  document.getElementById('deptCode').value = '';
  new bootstrap.Modal(document.getElementById('deptModal')).show();
}

function editDept(id) {
  const d = DEPARTMENTS.find(x => Number(x.id) === Number(id));
  if (!d) return;

  document.getElementById('deptModalTitle').textContent = 'Edit Department';
  document.getElementById('deptId').value = d.id || 0;
  document.getElementById('deptName').value = d.name || '';
  document.getElementById('deptCode').value = d.code || '';
  new bootstrap.Modal(document.getElementById('deptModal')).show();
}

function setLimitCard(prefix, current, limit) {
  var pct = limit ? Math.round(current / limit * 100) : 0;
  var bar = document.getElementById(prefix + 'Bar');
  if (!bar) return;
  bar.style.width = pct + '%';
  bar.className = 'fill ' + (pct >= 100 ? 'fill-danger' : pct >= 80 ? 'fill-warn' : 'fill-ok');
}

document.addEventListener('DOMContentLoaded', function() {
  setLimitCard('lcTotal', <?php echo (int)$activeTotalCount; ?>, <?php echo (int)$limitTotal; ?>);
  setLimitCard('lcAdmin', <?php echo (int)$activeQaCount; ?>, <?php echo (int)$limitAdmins; ?>);
  setLimitCard('lcEmp', <?php echo (int)$activeEmpCount; ?>, <?php echo (int)$limitEmployees; ?>);
});
</script>
</body>
</html>