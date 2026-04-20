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

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        $ok = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $ok;
    }
}

if (!function_exists('viewExists')) {
    function viewExists(mysqli $conn, string $viewName): bool
    {
        $viewName = mysqli_real_escape_string($conn, $viewName);
        $sql = "
            SELECT 1
            FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$viewName}'
            LIMIT 1
        ";
        $res = mysqli_query($conn, $sql);
        $ok = ($res && mysqli_num_rows($res) > 0);
        if ($res) {
            mysqli_free_result($res);
        }
        return $ok;
    }
}

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string
    {
        $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $parts = explode(',', (string)$_SERVER[$key]);
                return trim($parts[0]);
            }
        }
        return '';
    }
}

if (!function_exists('resolveUserDisplayName')) {
    function resolveUserDisplayName(array $row): string
    {
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if (!empty($row['full_name']) && trim((string)$row['full_name']) !== '' && (string)$row['full_name'] !== '0') {
            return trim((string)$row['full_name']);
        }
        if (!empty($row['email'])) {
            return (string)$row['email'];
        }
        return 'User #' . (int)($row['id'] ?? 0);
    }
}

if (!function_exists('writeAuditLog')) {
    function writeAuditLog(
        mysqli $conn,
        string $entityType,
        int $entityId,
        string $action,
        $oldValue,
        $newValue,
        int $performedBy,
        string $remarks = ''
    ): void {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $eventId = generate_uuid_v4();
        $oldJson = $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $newJson = $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $ipAddress = get_client_ip();
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        $stmt = mysqli_prepare($conn, "
            INSERT INTO audit_logs
            (event_id, entity_type, entity_id, action, old_value, new_value, performed_by, remarks, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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

if (!function_exists('formatDateTimeDisplay')) {
    function formatDateTimeDisplay($date): string
    {
        $date = trim((string)($date ?? ''));
        if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($date);
        return $ts ? date('d M Y H:i', $ts) : '—';
    }
}

if (!function_exists('formatDateDisplay')) {
    function formatDateDisplay($date): string
    {
        $date = trim((string)($date ?? ''));
        if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($date);
        return $ts ? date('d M Y', $ts) : '—';
    }
}

/* ---------------- AUTH ---------------- */
if (
    (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) &&
    (int)($_SESSION['user_id'] ?? 0) <= 0
) {
    header('Location: login-admin.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

$currentUser = null;
$userStmt = mysqli_prepare($conn, "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.full_name,
        u.email,
        r.role_name,
        r.role_code
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
");
if ($userStmt) {
    mysqli_stmt_bind_param($userStmt, "i", $userId);
    mysqli_stmt_execute($userStmt);
    $userRes = mysqli_stmt_get_result($userStmt);
    $currentUser = ($userRes && mysqli_num_rows($userRes) > 0) ? mysqli_fetch_assoc($userRes) : null;
    mysqli_stmt_close($userStmt);
}

if (!$currentUser) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

$roleCode = strtolower(trim((string)($currentUser['role_code'] ?? '')));
if (!in_array($roleCode, ['qa_admin', 'super_admin'], true)) {
    die('Access denied.');
}

$displayName = resolveUserDisplayName($currentUser);
$roleName = trim((string)($currentUser['role_name'] ?? 'QA Admin'));
$successMessage = '';
$errorMessage = '';

/* ---------------- MASTER DATA ---------------- */
$documentTypes = [];
$typesRes = mysqli_query($conn, "
    SELECT id, type_name, prefix
    FROM document_types
    WHERE status = 'active'
    ORDER BY type_name ASC
");
if ($typesRes) {
    while ($row = mysqli_fetch_assoc($typesRes)) {
        $documentTypes[] = $row;
    }
    mysqli_free_result($typesRes);
}

$departments = [];
$deptRes = mysqli_query($conn, "
    SELECT id, department_name, department_code
    FROM departments
    WHERE is_active = 1
    ORDER BY department_name ASC
");
if ($deptRes) {
    while ($row = mysqli_fetch_assoc($deptRes)) {
        $departments[] = $row;
    }
    mysqli_free_result($deptRes);
}

$employees = [];
$empRes = mysqli_query($conn, "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.full_name,
        u.email,
        d.department_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.status = 'active'
      AND LOWER(COALESCE(r.role_code,'')) = 'employee'
    ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC
");
if ($empRes) {
    while ($row = mysqli_fetch_assoc($empRes)) {
        $name = resolveUserDisplayName($row);
        $first = trim((string)($row['first_name'] ?? ''));
        $last = trim((string)($row['last_name'] ?? ''));
        $initials = strtoupper(substr($first !== '' ? $first : $name, 0, 1) . substr($last, 0, 1));
        if ($initials === '') {
            $initials = strtoupper(substr($name, 0, 2));
        }

        $employees[] = [
            'id' => (int)$row['id'],
            'name' => $name,
            'dept' => (string)($row['department_name'] ?? 'No Department'),
            'initials' => $initials !== '' ? $initials : 'U'
        ];
    }
    mysqli_free_result($empRes);
}

/* ---------------- EFFECTIVE DOCUMENTS ---------------- */
$effectiveDocs = [];

if (viewExists($conn, 'vw_repository_effective')) {
    $docsSql = "
        SELECT
            vre.document_id AS id,
            vre.document_version_id,
            vre.document_number,
            vre.title,
            vre.document_type AS type_name,
            vre.version_label,
            vre.effective_date,
            CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,'')) AS owner_name
        FROM vw_repository_effective vre
        LEFT JOIN documents d ON d.id = vre.document_id
        LEFT JOIN users owner ON owner.id = d.owner_user_id
        ORDER BY vre.document_type ASC, vre.document_number ASC, vre.document_id DESC
    ";
} else {
    $docsSql = "
        SELECT
            d.id,
            dv.id AS document_version_id,
            d.document_number,
            d.title,
            dt.type_name,
            dv.version_label,
            dv.effective_date,
            CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,'')) AS owner_name
        FROM documents d
        INNER JOIN document_versions dv ON dv.id = d.current_version_id
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        LEFT JOIN users owner ON owner.id = d.owner_user_id
        WHERE d.current_status = 'effective'
          AND dv.status = 'effective'
        ORDER BY dt.type_name ASC, d.document_number ASC, d.id DESC
    ";
}

$docsRes = mysqli_query($conn, $docsSql);
if ($docsRes) {
    while ($row = mysqli_fetch_assoc($docsRes)) {
        $type = trim((string)($row['type_name'] ?? 'Other'));
        if ($type === '') {
            $type = 'Other';
        }
        if (!isset($effectiveDocs[$type])) {
            $effectiveDocs[$type] = [];
        }

        $effectiveDocs[$type][] = [
            'id' => (int)$row['id'],
            'document_version_id' => (int)$row['document_version_id'],
            'docId' => (string)($row['document_number'] ?? ''),
            'topic' => (string)($row['title'] ?? 'Untitled'),
            'version' => (string)($row['version_label'] ?? '01'),
            'owner' => trim((string)($row['owner_name'] ?? '')) !== '' ? (string)$row['owner_name'] : '—',
            'effectiveDate' => (string)($row['effective_date'] ?? '')
        ];
    }
    mysqli_free_result($docsRes);
}

/* ---------------- SAVE ASSIGNMENT ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_assignment') {
    $documentId = (int)($_POST['document_id'] ?? 0);
    $documentType = trim((string)($_POST['document_type'] ?? ''));
    $assignMode = trim((string)($_POST['assign_mode'] ?? 'dept'));
    $departmentName = trim((string)($_POST['department_name'] ?? ''));
    $deadline = trim((string)($_POST['deadline'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? 'normal'));
    $note = trim((string)($_POST['note'] ?? ''));
    $selectedEmployees = $_POST['employee_ids'] ?? [];

    if (!is_array($selectedEmployees)) {
        $selectedEmployees = [];
    }
    $selectedEmployees = array_values(array_unique(array_filter(array_map('intval', $selectedEmployees))));

    if ($documentId <= 0) {
        $errorMessage = 'Please select a document.';
    } elseif ($deadline === '') {
        $errorMessage = 'Please select a read-by deadline.';
    } elseif ($assignMode === 'dept' && $departmentName === '') {
        $errorMessage = 'Please select a department.';
    } elseif ($assignMode === 'individual' && empty($selectedEmployees)) {
        $errorMessage = 'Please select at least one employee.';
    } elseif (!tableExists($conn, 'acknowledgement_assignments')) {
        $errorMessage = 'acknowledgement_assignments table not found in database.';
    } else {
        $assignedUserIds = [];

        if ($assignMode === 'all') {
            foreach ($employees as $emp) {
                $assignedUserIds[] = (int)$emp['id'];
            }
        } elseif ($assignMode === 'dept') {
            foreach ($employees as $emp) {
                if ((string)$emp['dept'] === $departmentName) {
                    $assignedUserIds[] = (int)$emp['id'];
                }
            }
        } else {
            $assignedUserIds = $selectedEmployees;
        }

        $assignedUserIds = array_values(array_unique(array_filter($assignedUserIds)));

        if (empty($assignedUserIds)) {
            $errorMessage = 'No active employees found for the selected assignment mode.';
        } else {
            $docStmt = mysqli_prepare($conn, "
                SELECT
                    d.id,
                    d.document_number,
                    d.title,
                    d.topic,
                    dv.id AS document_version_id,
                    dv.version_label
                FROM documents d
                INNER JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE d.id = ?
                  AND d.current_status = 'effective'
                  AND dv.status = 'effective'
                LIMIT 1
            ");

            $documentRow = null;
            if ($docStmt) {
                mysqli_stmt_bind_param($docStmt, "i", $documentId);
                mysqli_stmt_execute($docStmt);
                $docRes = mysqli_stmt_get_result($docStmt);
                $documentRow = ($docRes && mysqli_num_rows($docRes) > 0) ? mysqli_fetch_assoc($docRes) : null;
                mysqli_stmt_close($docStmt);
            }

            if (!$documentRow) {
                $errorMessage = 'Only effective documents can be assigned for acknowledgement.';
            } else {
                mysqli_begin_transaction($conn);

                try {
                    $documentVersionId = (int)$documentRow['document_version_id'];
                    $documentNumber = (string)$documentRow['document_number'];
                    $documentTitle = trim((string)($documentRow['title'] ?: $documentRow['topic'] ?: 'Untitled Document'));
                    $assignedAt = date('Y-m-d H:i:s');

                    $insertStmt = mysqli_prepare($conn, "
                        INSERT INTO acknowledgement_assignments
                        (document_version_id, assigned_user_id, assigned_by, assigned_at, due_at, status, reminder_count, last_reminder_at)
                        VALUES (?, ?, ?, ?, ?, 'pending', 0, NULL)
                    ");
                    if (!$insertStmt) {
                        throw new RuntimeException('Unable to prepare assignment insert.');
                    }

                    $notifStmt = null;
                    if (tableExists($conn, 'notifications')) {
                        $notifStmt = mysqli_prepare($conn, "
                            INSERT INTO notifications
                            (user_id, notification_type, reference_type, reference_id, title, message, is_read, created_at)
                            VALUES (?, 'ack_assignment', 'acknowledgement', ?, ?, ?, 0, NOW())
                        ");
                    }

                    foreach ($assignedUserIds as $employeeId) {
                        mysqli_stmt_bind_param(
                            $insertStmt,
                            "iiiss",
                            $documentVersionId,
                            $employeeId,
                            $userId,
                            $assignedAt,
                            $deadline
                        );

                        if (!mysqli_stmt_execute($insertStmt)) {
                            $sqlState = mysqli_sqlstate($conn);
                            $sqlError = mysqli_error($conn);

                            if ($sqlState === '23000') {
                                continue; // already assigned for this version/user
                            }
                            throw new RuntimeException('Failed to save assignment row. ' . $sqlError);
                        }

                        $assignmentId = (int)mysqli_insert_id($conn);

                        if ($notifStmt) {
                            $notifTitle = 'Document Assignment';
                            $notifMessage = 'You have been assigned to read and acknowledge document "' . $documentTitle . '".';
                            mysqli_stmt_bind_param($notifStmt, "iiss", $employeeId, $assignmentId, $notifTitle, $notifMessage);
                            mysqli_stmt_execute($notifStmt);
                        }
                    }

                    mysqli_stmt_close($insertStmt);
                    if ($notifStmt) {
                        mysqli_stmt_close($notifStmt);
                    }

                    writeAuditLog(
                        $conn,
                        'document_assignment',
                        $documentId,
                        'create',
                        null,
                        [
                            'document_id' => $documentId,
                            'document_version_id' => $documentVersionId,
                            'document_number' => $documentNumber,
                            'document_type' => $documentType,
                            'assigned_user_ids' => $assignedUserIds,
                            'assigned_count' => count($assignedUserIds),
                            'assign_mode' => $assignMode,
                            'department_name' => $departmentName,
                            'deadline' => $deadline,
                            'priority' => $priority,
                            'message' => $note
                        ],
                        $userId,
                        'Document assigned to employees for acknowledgement.'
                    );

                    mysqli_commit($conn);
                    $successMessage = 'Assignment created successfully and employees have been notified.';
                } catch (Throwable $e) {
                    mysqli_rollback($conn);
                    $errorMessage = $e->getMessage();
                }
            }
        }
    }
}

/* ---------------- TRACKER ---------------- */
$trackerRows = [];
$detailMap = [];

if (tableExists($conn, 'acknowledgement_assignments')) {
    $trackerSql = "
        SELECT
            aa.id,
            aa.document_version_id,
            aa.assigned_user_id,
            aa.assigned_by,
            aa.assigned_at,
            aa.due_at,
            aa.status,
            d.id AS document_id,
            d.document_number,
            dt.type_name,
            dv.version_label,
            dv.title_snapshot,
            u.id AS employee_id,
            u.first_name,
            u.last_name,
            u.full_name,
            u.email,
            dept.department_name,
            ae.acknowledged_at,
            ae.ip_address
        FROM acknowledgement_assignments aa
        INNER JOIN document_versions dv ON dv.id = aa.document_version_id
        INNER JOIN documents d ON d.id = dv.document_id
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        LEFT JOIN users u ON u.id = aa.assigned_user_id
        LEFT JOIN departments dept ON dept.id = u.department_id
        LEFT JOIN acknowledgement_events ae ON ae.acknowledgement_assignment_id = aa.id
        ORDER BY aa.assigned_at DESC, aa.id DESC
    ";

    $trackerRes = mysqli_query($conn, $trackerSql);
    $rawAssignments = [];
    if ($trackerRes) {
        while ($row = mysqli_fetch_assoc($trackerRes)) {
            $rawAssignments[] = $row;
        }
        mysqli_free_result($trackerRes);
    }

    $grouped = [];
    $groupMembers = [];

    foreach ($rawAssignments as $row) {
        $groupKey = implode('|', [
            (int)$row['document_version_id'],
            (int)$row['assigned_by'],
            (string)$row['assigned_at'],
            (string)$row['due_at']
        ]);

        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'id' => count($grouped) + 1,
                'batch_token' => $groupKey,
                'document_id' => (int)$row['document_id'],
                'docId' => (string)$row['document_number'],
                'type' => (string)($row['type_name'] ?: 'Document'),
                'assignedTo' => 'Selected Employees',
                'deadline' => (string)($row['due_at'] ?? ''),
                'confirmed' => 0,
                'total' => 0,
                'status' => 'In Progress'
            ];
            $groupMembers[$groupKey] = [];
            $detailMap[$groupKey] = [];
        }

        $grouped[$groupKey]['total']++;

        $status = strtolower(trim((string)($row['status'] ?? 'pending')));
        $isConfirmed = ($status === 'acknowledged');

        if ($isConfirmed) {
            $grouped[$groupKey]['confirmed']++;
        }

        $employeeName = resolveUserDisplayName($row);
        $department = (string)($row['department_name'] ?? '—');
        $groupMembers[$groupKey][] = [
            'user_id' => (int)$row['employee_id'],
            'dept' => $department
        ];

        $detailMap[$groupKey][] = [
            'employee' => $employeeName,
            'department' => $department,
            'status' => $isConfirmed ? 'Confirmed' : (($status === 'overdue') ? 'Pending' : 'Pending'),
            'confirmed_on' => $isConfirmed ? formatDateTimeDisplay($row['acknowledged_at'] ?? '') : '—',
            'ip' => $isConfirmed ? ((string)($row['ip_address'] ?? '') !== '' ? (string)$row['ip_address'] : '—') : '—'
        ];
    }

    foreach ($grouped as $groupKey => &$g) {
        $uniqueUsers = [];
        $deptNames = [];

        foreach ($groupMembers[$groupKey] as $member) {
            $uniqueUsers[] = (int)$member['user_id'];
            if (trim((string)$member['dept']) !== '' && (string)$member['dept'] !== '—') {
                $deptNames[] = (string)$member['dept'];
            }
        }

        $uniqueUsers = array_values(array_unique($uniqueUsers));
        $deptNames = array_values(array_unique($deptNames));

        if (count($employees) > 0 && count($uniqueUsers) === count($employees)) {
            $g['assignedTo'] = 'All Employees';
        } elseif (count($deptNames) === 1 && count($uniqueUsers) > 1) {
            $deptEmployeeCount = 0;
            foreach ($employees as $emp) {
                if ($emp['dept'] === $deptNames[0]) {
                    $deptEmployeeCount++;
                }
            }
            if ($deptEmployeeCount > 0 && $deptEmployeeCount === count($uniqueUsers)) {
                $g['assignedTo'] = $deptNames[0];
            } else {
                $g['assignedTo'] = count($uniqueUsers) . ' employee' . (count($uniqueUsers) !== 1 ? 's' : '');
            }
        } else {
            $g['assignedTo'] = count($uniqueUsers) . ' employee' . (count($uniqueUsers) !== 1 ? 's' : '');
        }

        $deadlineTs = !empty($g['deadline']) ? strtotime($g['deadline']) : false;
        $nowTs = time();

        if ($g['total'] > 0 && $g['confirmed'] >= $g['total']) {
            $g['status'] = 'Completed';
        } elseif ($deadlineTs && $deadlineTs < $nowTs) {
            $g['status'] = 'Overdue';
        } else {
            $g['status'] = 'In Progress';
        }

        $trackerRows[] = $g;
    }
    unset($g);
}

$effectiveDocsJson = json_encode($effectiveDocs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$employeesJson = json_encode($employees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$trackerRowsJson = json_encode($trackerRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$detailMapJson = json_encode($detailMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Document Assignment</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .field-locked {
      background: #f5f7fa !important;
      color: #6b7280 !important;
      cursor: not-allowed;
    }
    select:disabled { background: #f5f7fa; color: #aaa; cursor: not-allowed; }

    /* Employee selection checkboxes */
    .emp-check-list {
      max-height: 220px;
      overflow-y: auto;
      border: 1px solid #dde3ec;
      border-radius: 8px;
      background: #fff;
    }
    .emp-check-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 14px;
      border-bottom: 1px solid #f0f2f5;
      font-size: 13px;
      cursor: pointer;
      transition: background 0.12s;
    }
    .emp-check-item:last-child { border-bottom: none; }
    .emp-check-item:hover { background: #f4f7ff; }
    .emp-check-item input[type=checkbox] { cursor: pointer; flex-shrink: 0; }
    .emp-check-item .emp-name  { font-weight: 600; color: #1e2a3a; }
    .emp-check-item .emp-dept  { font-size: 11px; color: #6b7280; margin-top: 1px; }
    .emp-check-item .emp-avatar {
      width: 30px; height: 30px; border-radius: 50%;
      background: #dbeafe; color: #1d4ed8;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; flex-shrink: 0;
    }

    /* Assignment tracker table */
    .progress-thin {
      height: 6px;
      border-radius: 3px;
      background: #e5e7eb;
      overflow: hidden;
      min-width: 80px;
    }
    .progress-thin .bar {
      height: 100%;
      border-radius: 3px;
      background: #16a34a;
      transition: width 0.3s;
    }
    .progress-thin .bar.warn { background: #f59e0b; }
    .progress-thin .bar.danger { background: #dc2626; }

    /* Tab strip for existing assignments */
    .assign-tab {
      display: inline-block;
      padding: 7px 18px;
      border-radius: 6px 6px 0 0;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid #e0e7ef;
      border-bottom: none;
      margin-right: 4px;
    }
    .assign-tab.active { background: #fff; color: #1a3a6e; }
    .assign-tab.inactive { background: #f3f4f6; color: #6b7280; }

    .selected-count {
      display: inline-block;
      background: #2563eb;
      color: #fff;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
      padding: 1px 8px;
      margin-left: 6px;
    }
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
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-trail.php">Audit Trail</a></li>
            <li><a class="dropdown-item active" href="document-assignment.php">Document Assignment</a></li>
            <li><a class="dropdown-item" href="user-management.php">User Management</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($roleName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small"><?php echo e($displayName); ?></span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <div class="mb-4">
    <h1 class="page-title mb-2">Document Assignment</h1>
    <p class="page-subtitle mb-0">Assign mandatory read &amp; confirm tasks to employees or departments for effective controlled documents.</p>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?php echo e($successMessage); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?php echo e($errorMessage); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- ── Left: New Assignment Form ── -->
    <div class="col-lg-8">

      <form method="post" id="assignmentForm">
        <input type="hidden" name="action" value="create_assignment">
        <input type="hidden" name="document_id" id="document_id">
        <input type="hidden" name="document_type" id="document_type">
        <input type="hidden" name="assign_mode" id="assign_mode" value="dept">
        <input type="hidden" name="department_name" id="department_name">
        <div id="selectedEmployeeInputs"></div>

        <!-- Step 1 & 2 — Document selection -->
        <div class="card cp-card mb-3">
          <div class="card-body">
            <h2 class="card-title mb-1">Select Document</h2>
            <p class="card-subtitle mb-3">Only Effective documents can be assigned for acknowledgement.</p>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Step 1 — Document Type <span class="text-danger">*</span></label>
                <select class="form-select" id="docTypeSelect" onchange="onTypeChange(this.value)">
                  <option value="">-- Select Type --</option>
                  <?php foreach ($documentTypes as $type): ?>
                    <option value="<?php echo e($type['type_name']); ?>"><?php echo e($type['type_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Step 2 — Select Document <span class="text-danger">*</span></label>
                <select class="form-select" id="docSelect" disabled onchange="onDocSelect(this.value)">
                  <option value="">-- Select Type first --</option>
                </select>
                <div class="form-text" id="docSelectHint">Choose a type above to load effective documents.</div>
              </div>
            </div>

            <!-- Doc info strip -->
            <div id="docInfoPanel" class="d-none mt-3 p-3 rounded-3" style="background:#f0f4ff;border:1px solid #c7d7f8;font-size:13px;">
              <div class="fw-bold text-primary" id="diId">—</div>
              <div class="text-secondary mt-1" id="diMeta">—</div>
            </div>

          </div>
        </div>

        <!-- Step 3 — Assign to -->
        <div class="card cp-card mb-3 d-none" id="assignCard">
          <div class="card-body">
            <h2 class="card-title mb-1">Assign To</h2>
            <p class="card-subtitle mb-3">Choose to assign by department (all employees in that dept) or select individual employees.</p>

            <!-- Assign mode toggle -->
            <div class="d-flex gap-2 mb-3" id="assignModeBtns">
              <button type="button" class="btn btn-primary btn-sm" id="btnDept" onclick="setAssignMode('dept')">By Department</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="btnIndividual" onclick="setAssignMode('individual')">Individual Employees</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAll" onclick="setAssignMode('all')">All Employees</button>
            </div>

            <!-- Department mode -->
            <div id="deptMode">
              <label class="form-label">Select Department <span class="text-danger">*</span></label>
              <select class="form-select" id="deptSelect" onchange="onDeptChange(this.value)">
                <option value="">-- Select Department --</option>
                <?php foreach ($departments as $dept): ?>
                  <option value="<?php echo e($dept['department_name']); ?>"><?php echo e($dept['department_name']); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="deptHint">&nbsp;</div>
            </div>

            <!-- Individual mode -->
            <div id="individualMode" class="d-none">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0">
                  Select Employees
                  <span class="selected-count" id="selectedCount">0</span>
                </label>
                <div class="d-flex gap-2">
                  <input type="text" class="form-control form-control-sm" id="empSearch"
                         placeholder="Search name or dept..." oninput="filterEmployees(this.value)"
                         style="max-width:200px;">
                  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll()">Select All</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearAll()">Clear</button>
                </div>
              </div>
              <div class="emp-check-list" id="empCheckList">
                <!-- Populated by JS -->
              </div>
            </div>

            <!-- All employees mode -->
            <div id="allMode" class="d-none">
              <div class="p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:13px;">
                ✓ &nbsp;This document will be assigned to <strong>all active employees</strong> in the system.
              </div>
            </div>

          </div>
        </div>

        <!-- Step 4 — Deadline & note -->
        <div class="card cp-card mb-3 d-none" id="deadlineCard">
          <div class="card-body">
            <h2 class="card-title mb-1">Deadline &amp; Instructions</h2>
            <p class="card-subtitle mb-3">Set a read-by date and optional message for employees.</p>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Read-by Deadline <span class="text-danger">*</span></label>
                <input class="form-control" id="fDeadline" name="deadline" type="date">
                <div class="form-text">Employees will receive a reminder email if not completed by this date.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Priority</label>
                <select class="form-select" id="fPriority" name="priority">
                  <option value="normal">Normal</option>
                  <option value="high">High — highlighted in employee dashboard</option>
                  <option value="urgent">Urgent — email sent immediately</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Message to Employees <span class="text-secondary fw-normal">(optional)</span></label>
                <textarea class="form-control" id="fNote" name="note" rows="2"
                  placeholder="e.g. Please read this updated SOP before your next shift and confirm acknowledgement by the deadline."></textarea>
              </div>
            </div>

            <div class="d-flex gap-2 mt-4">
              <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">Cancel</button>
              <button type="submit" class="btn btn-success">
                ✓ Assign &amp; Notify Employees
              </button>
            </div>

          </div>
        </div>
      </form>

    </div>

    <!-- ── Right: summary card ── -->
    <div class="col-lg-4">
      <div class="card cp-card mb-3">
        <div class="card-body">
          <h2 class="card-title mb-1">How It Works</h2>
          <p class="card-subtitle mb-3">What happens after you assign.</p>
          <ul class="small text-secondary note-list mb-0">
            <li>Selected employees receive an email notification with a direct link to the document.</li>
            <li>The document appears in their <strong>Pending Acknowledgements</strong> list on their dashboard.</li>
            <li>Employee reads the document and clicks <strong>Confirm Read</strong>.</li>
            <li>Admin can track completion status in the tracker below.</li>
            <li>Overdue employees receive an automatic reminder email.</li>
            <li>All acknowledgements are captured in the audit trail.</li>
          </ul>
        </div>
      </div>
      <div class="card cp-card">
        <div class="card-body">
          <h2 class="card-title mb-1">Audit Note</h2>
          <p class="card-subtitle mb-3">Compliance traceability.</p>
          <ul class="small text-secondary note-list mb-0">
            <li>Assignment logged with: assigned by, date, document ID, version.</li>
            <li>Each employee confirmation logged with: name, timestamp, IP address.</li>
            <li>Records are immutable — cannot be deleted.</li>
          </ul>
        </div>
      </div>
    </div>

  </div><!-- /row -->

  <!-- ── Acknowledgement Tracker ── -->
  <div class="mt-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
      <div>
        <h2 class="page-title mb-0" style="font-size:1.2rem;">Acknowledgement Tracker</h2>
        <p class="page-subtitle mb-0 small">Track read confirmation status for all active assignments.</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <select class="form-select form-select-sm" style="max-width:160px;" id="filterStatus" onchange="filterTracker()">
          <option value="">All Statuses</option>
          <option value="In Progress">In Progress</option>
          <option value="Completed">Completed</option>
          <option value="Overdue">Overdue</option>
        </select>
        <select class="form-select form-select-sm" style="max-width:160px;" id="filterType" onchange="filterTracker()">
          <option value="">All Types</option>
          <?php foreach ($documentTypes as $type): ?>
            <option value="<?php echo e($type['type_name']); ?>"><?php echo e($type['type_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-sm btn-outline-secondary">&#11015; Export</button>
      </div>
    </div>

    <div class="card cp-card" style="padding:0;">
      <table class="table mb-0" id="trackerTable">
        <thead>
          <tr>
            <th>Document</th>
            <th>Type</th>
            <th>Assigned To</th>
            <th>Deadline</th>
            <th>Progress</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="trackerBody">
          <!-- Populated by JS -->
        </tbody>
      </table>
      <div id="trackerEmpty" class="text-center text-secondary py-4 small d-none">No assignments found.</div>
    </div>
  </div>

</div>
</main>

<!-- ── Detail Modal ── -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold" id="detailModalTitle">Assignment Detail</h5>
          <div class="small text-secondary" id="detailModalSub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <table class="table mb-0" id="detailTable">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th>Status</th>
              <th>Confirmed On</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody id="detailBody"></tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm">&#11015; Export this list</button>
        <button type="button" class="btn btn-primary btn-sm" id="sendReminderBtn">Send Reminder to Pending</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var EFFECTIVE_DOCS = <?php echo $effectiveDocsJson ?: '{}'; ?>;
var ALL_EMPLOYEES = <?php echo $employeesJson ?: '[]'; ?>;
var ASSIGNMENTS = <?php echo $trackerRowsJson ?: '[]'; ?>;
var ASSIGNMENT_DETAILS = <?php echo $detailMapJson ?: '{}'; ?>;

var assignMode = 'dept';
var selectedEmployeeIds = [];

// ── Type → Document cascade ──────────────────────────────────────────────
function onTypeChange(type) {
  var docSel = document.getElementById('docSelect');
  var hint   = document.getElementById('docSelectHint');
  resetBelowDoc();

  if (!type) {
    docSel.disabled = true;
    docSel.innerHTML = '<option value="">-- Select Type first --</option>';
    hint.textContent = 'Choose a type above to load effective documents.';
    return;
  }

  var docs = EFFECTIVE_DOCS[type] || [];
  docSel.innerHTML = '<option value="">-- Select a ' + type + ' document --</option>';

  docs.forEach(function(d) {
    var o = document.createElement('option');
    o.value = d.id;
    o.textContent = d.docId + '  —  ' + d.topic;
    docSel.appendChild(o);
  });

  docSel.disabled = false;
  hint.textContent = docs.length + ' effective ' + type + ' document' + (docs.length !== 1 ? 's' : '') + ' available.';
}

function onDocSelect(docId) {
  resetBelowDoc();
  if (!docId) return;

  var type = document.getElementById('docTypeSelect').value;
  var doc = (EFFECTIVE_DOCS[type] || []).find(function(d){ return String(d.id) === String(docId); });
  if (!doc) return;

  document.getElementById('document_id').value = doc.id;
  document.getElementById('document_type').value = type;

  document.getElementById('diId').textContent = doc.docId;
  document.getElementById('diMeta').textContent = 'Topic: ' + doc.topic + '  ·  Version: ' + doc.version + '  ·  Owner: ' + doc.owner;
  document.getElementById('docInfoPanel').classList.remove('d-none');
  document.getElementById('assignCard').classList.remove('d-none');
  document.getElementById('deadlineCard').classList.remove('d-none');

  var d = new Date();
  d.setDate(d.getDate() + 7);
  document.getElementById('fDeadline').value = toInputDate(d);

  renderEmployeeList('');
}

function resetBelowDoc() {
  document.getElementById('docInfoPanel').classList.add('d-none');
  document.getElementById('assignCard').classList.add('d-none');
  document.getElementById('deadlineCard').classList.add('d-none');
  document.getElementById('document_id').value = '';
  document.getElementById('document_type').value = '';
  document.getElementById('department_name').value = '';
  var deptEl = document.getElementById('deptSelect');
  if (deptEl) deptEl.value = '';
  document.getElementById('deptHint').textContent = '\u00A0';
  selectedEmployeeIds = [];
  updateSelectedCount();
  syncSelectedEmployeeInputs();
}

// ── Assign mode ──────────────────────────────────────────────────────────
function setAssignMode(mode) {
  assignMode = mode;
  document.getElementById('assign_mode').value = mode;
  document.getElementById('deptMode').classList.toggle('d-none', mode !== 'dept');
  document.getElementById('individualMode').classList.toggle('d-none', mode !== 'individual');
  document.getElementById('allMode').classList.toggle('d-none', mode !== 'all');

  document.getElementById('btnDept').className       = mode === 'dept' ? 'btn btn-primary btn-sm' : 'btn btn-outline-secondary btn-sm';
  document.getElementById('btnIndividual').className = mode === 'individual' ? 'btn btn-primary btn-sm' : 'btn btn-outline-secondary btn-sm';
  document.getElementById('btnAll').className        = mode === 'all' ? 'btn btn-primary btn-sm' : 'btn btn-outline-secondary btn-sm';
}

function onDeptChange(dept) {
  document.getElementById('department_name').value = dept;
  var hint = document.getElementById('deptHint');
  if (dept) {
    var n = ALL_EMPLOYEES.filter(function(e){ return e.dept === dept; }).length;
    hint.textContent = n + ' active employee' + (n !== 1 ? 's' : '') + ' in this department will be assigned.';
    hint.className = 'form-text text-success';
  } else {
    hint.textContent = '\u00A0';
    hint.className = 'form-text';
  }
}

// ── Employee list ────────────────────────────────────────────────────────
function renderEmployeeList(filter) {
  var list = document.getElementById('empCheckList');
  var q = String(filter || '').toLowerCase();

  var filtered = ALL_EMPLOYEES.filter(function(e) {
    return !q || e.name.toLowerCase().includes(q) || e.dept.toLowerCase().includes(q);
  });

  list.innerHTML = filtered.map(function(e) {
    var checked = selectedEmployeeIds.indexOf(Number(e.id)) > -1;
    return '<div class="emp-check-item" onclick="toggleEmployee(' + e.id + ')">' +
      '<input type="checkbox" ' + (checked ? 'checked' : '') + ' onclick="event.stopPropagation();toggleEmployee(' + e.id + ')">' +
      '<div class="emp-avatar">' + e.initials + '</div>' +
      '<div><div class="emp-name">' + e.name + '</div><div class="emp-dept">' + e.dept + '</div></div>' +
      '</div>';
  }).join('');
}

function toggleEmployee(id) {
  id = Number(id);
  var idx = selectedEmployeeIds.indexOf(id);
  if (idx > -1) selectedEmployeeIds.splice(idx, 1);
  else selectedEmployeeIds.push(id);

  updateSelectedCount();
  syncSelectedEmployeeInputs();
  renderEmployeeList(document.getElementById('empSearch').value);
}

function selectAll() {
  selectedEmployeeIds = ALL_EMPLOYEES.map(function(e){ return Number(e.id); });
  updateSelectedCount();
  syncSelectedEmployeeInputs();
  renderEmployeeList(document.getElementById('empSearch').value);
}

function clearAll() {
  selectedEmployeeIds = [];
  updateSelectedCount();
  syncSelectedEmployeeInputs();
  renderEmployeeList(document.getElementById('empSearch').value);
}

function filterEmployees(val) {
  renderEmployeeList(val);
}

function updateSelectedCount() {
  document.getElementById('selectedCount').textContent = selectedEmployeeIds.length;
}

function syncSelectedEmployeeInputs() {
  var wrap = document.getElementById('selectedEmployeeInputs');
  wrap.innerHTML = '';
  selectedEmployeeIds.forEach(function(id) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'employee_ids[]';
    input.value = id;
    wrap.appendChild(input);
  });
}

// ── Reset ────────────────────────────────────────────────────────────────
function resetForm() {
  document.getElementById('assignmentForm').reset();
  document.getElementById('docTypeSelect').value = '';
  document.getElementById('docSelect').innerHTML = '<option value="">-- Select Type first --</option>';
  document.getElementById('docSelect').disabled = true;
  document.getElementById('docSelectHint').textContent = 'Choose a type above to load effective documents.';
  document.getElementById('fNote').value = '';
  setAssignMode('dept');
  resetBelowDoc();
  renderEmployeeList('');
}

// ── Tracker ──────────────────────────────────────────────────────────────
function renderTracker(data) {
  var body  = document.getElementById('trackerBody');
  var empty = document.getElementById('trackerEmpty');

  if (!data.length) {
    body.innerHTML = '';
    empty.classList.remove('d-none');
    return;
  }
  empty.classList.add('d-none');

  body.innerHTML = data.map(function(a) {
    var pct      = a.total ? Math.round((a.confirmed / a.total) * 100) : 0;
    var barClass = pct === 100 ? '' : (a.status === 'Overdue' ? 'danger' : pct > 50 ? '' : 'warn');
    var statusBadge = a.status === 'Completed'
      ? '<span class="badge badge-soft-success">Completed</span>'
      : a.status === 'Overdue'
        ? '<span class="badge badge-soft-danger">Overdue</span>'
        : '<span class="badge badge-soft-warning">In Progress</span>';

    return '<tr>' +
      '<td><span class="fw-semibold text-primary" style="font-size:13px;">' + a.docId + '</span></td>' +
      '<td><span class="badge badge-soft-info">' + a.type + '</span></td>' +
      '<td style="font-size:13px;">' + a.assignedTo + '</td>' +
      '<td style="font-size:12px;color:#6b7280;">' + formatDate(a.deadline) + '</td>' +
      '<td>' +
        '<div class="d-flex align-items-center gap-2">' +
          '<div class="progress-thin"><div class="bar ' + barClass + '" style="width:' + pct + '%"></div></div>' +
          '<span style="font-size:12px;color:#6b7280;white-space:nowrap;">' + a.confirmed + ' / ' + a.total + '</span>' +
        '</div>' +
      '</td>' +
      '<td>' + statusBadge + '</td>' +
      '<td>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" style="height:28px;padding:0 10px;font-size:12px;" onclick="openDetail(\'' + escapeJs(a.batch_token) + '\', \'' + escapeJs(a.docId) + '\', \'' + escapeJs(a.assignedTo) + '\', \'' + escapeJs(formatDate(a.deadline)) + '\')">View Detail</button>' +
        (a.status !== 'Completed' ? '&nbsp;<button type="button" class="btn btn-sm btn-outline-warning" style="height:28px;padding:0 10px;font-size:12px;" onclick="sendReminder(\'' + escapeJs(a.batch_token) + '\')">Remind</button>' : '') +
      '</td>' +
    '</tr>';
  }).join('');
}

function filterTracker() {
  var st = document.getElementById('filterStatus').value;
  var type = document.getElementById('filterType').value;
  var filtered = ASSIGNMENTS.filter(function(a) {
    return (!st || a.status === st) && (!type || a.type === type);
  });
  renderTracker(filtered);
}

function openDetail(batchToken, docId, assignedTo, deadline) {
  var rows = ASSIGNMENT_DETAILS[batchToken] || [];
  document.getElementById('detailModalTitle').textContent = docId;
  document.getElementById('detailModalSub').textContent = 'Assigned to: ' + assignedTo + '  ·  Deadline: ' + deadline;

  document.getElementById('detailBody').innerHTML = rows.map(function(r) {
    return '<tr>' +
      '<td class="fw-semibold" style="font-size:13px;">' + r.employee + '</td>' +
      '<td style="font-size:12px;color:#6b7280;">' + r.department + '</td>' +
      '<td>' + (r.status === 'Confirmed'
        ? '<span class="badge badge-soft-success">Confirmed</span>'
        : '<span class="badge badge-soft-warning">Pending</span>') + '</td>' +
      '<td style="font-size:12px;color:#6b7280;">' + r.confirmed_on + '</td>' +
      '<td style="font-size:12px;color:#6b7280;">' + r.ip + '</td>' +
    '</tr>';
  }).join('');

  new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function sendReminder(batchToken) {
  alert('Reminder email feature is not connected yet in this page.');
}

// ── Helpers ───────────────────────────────────────────────────────────────
function toInputDate(d) {
  return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}
function formatDate(str) {
  if (!str) return '—';
  var d = new Date(str);
  if (isNaN(d.getTime())) return str;
  return d.toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'});
}
function escapeJs(str) {
  return String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// Initial render
renderTracker(ASSIGNMENTS);
renderEmployeeList('');
syncSelectedEmployeeInputs();
</script>
</body>
</html>