<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('Asia/Kolkata');

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('table_exists')) {
    function table_exists(mysqli $conn, string $table): bool {
        $tableEsc = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableEsc}'");
        $exists = $res && mysqli_num_rows($res) > 0;
        if ($res) mysqli_free_result($res);
        return $exists;
    }
}

if (!function_exists('has_column')) {
    function has_column(mysqli $conn, string $table, string $column): bool {
        if (!table_exists($conn, $table)) {
            return false;
        }

        $tableEsc = mysqli_real_escape_string($conn, $table);
        $columnEsc = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
        $exists = $res && mysqli_num_rows($res) > 0;
        if ($res) mysqli_free_result($res);
        return $exists;
    }
}

if (!function_exists('bind_params_dynamic')) {
    function bind_params_dynamic(mysqli_stmt $stmt, string $types, array &$params): bool {
        if ($types === '' || empty($params)) {
            return true;
        }

        $refs = [];
        $refs[] = $types;

        foreach ($params as $key => &$value) {
            $refs[] = &$value;
        }

        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('formatDateDisplay')) {
    function formatDateDisplay($date): string {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '—';
        }

        $ts = strtotime((string)$date);
        return $ts ? date('d M Y', $ts) : e((string)$date);
    }
}

if (!function_exists('formatDateExport')) {
    function formatDateExport($date): string {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '-';
        }

        $ts = strtotime((string)$date);
        return $ts ? date('d-m-Y', $ts) : (string)$date;
    }
}

if (!function_exists('isOverdueDate')) {
    function isOverdueDate($date): bool {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return false;
        }

        $reviewTs = strtotime((string)$date);
        if (!$reviewTs) {
            return false;
        }

        $today = strtotime(date('Y-m-d'));
        return $reviewTs < $today;
    }
}

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass($status, $reviewDate = ''): string {
        $status = strtolower(trim((string)$status));

        if ($status === 'deleted') return 'badge badge-soft-danger';
        if ($status === 'retired') return 'badge badge-soft-secondary';
        if ($status === 'pending_retirement') return 'badge badge-soft-warning';
        if ($status === 'pending_approval') return 'badge badge-soft-warning';
        if ($status === 'draft') return 'badge badge-soft-secondary';
        if ($status === 'rejected') return 'badge badge-soft-danger';
        if ($status === 'approved') return 'badge badge-soft-success';
        if ($status === 'effective') return 'badge badge-soft-success';

        if (isOverdueDate($reviewDate)) {
            return 'badge badge-soft-danger';
        }

        return 'badge badge-soft-success';
    }
}

if (!function_exists('displayStatusLabel')) {
    function displayStatusLabel($status, $reviewDate = ''): string {
        $status = strtolower(trim((string)$status));

        if ($status === 'deleted') return 'Deleted';
        if ($status === 'retired') return 'Retired';
        if ($status === 'pending_retirement') return 'Pending Retirement';
        if ($status === 'pending_approval') return 'Pending Approval';
        if ($status === 'draft') return 'Draft';
        if ($status === 'rejected') return 'Rejected';
        if ($status === 'approved') return 'Approved';
        if ($status === 'effective') return 'Effective';

        if (isOverdueDate($reviewDate)) {
            return 'Overdue Review';
        }

        return 'Effective';
    }
}

if (!function_exists('parseDocumentContent')) {
    function parseDocumentContent($contentText): array {
        $result = [
            'is_json_form'   => false,
            'purpose_scope'  => '',
            'form_responses' => [],
            'raw_text'       => trim((string)$contentText),
        ];

        $raw = trim((string)$contentText);
        if ($raw === '') {
            return $result;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (array_key_exists('purpose_scope', $decoded) || array_key_exists('form_responses', $decoded)) {
                $result['is_json_form'] = true;
                $result['purpose_scope'] = trim((string)($decoded['purpose_scope'] ?? ''));
                $result['form_responses'] = is_array($decoded['form_responses'] ?? null) ? $decoded['form_responses'] : [];
            }
        }

        return $result;
    }
}

if (!function_exists('formatFormResponseLabel')) {
    function formatFormResponseLabel($key): string {
        $key = trim((string)$key);
        if ($key === '') {
            return 'Field';
        }

        return ucwords(str_replace(['_', '-'], ' ', $key));
    }
}

if (!function_exists('formatFormResponseValue')) {
    function formatFormResponseValue($value): string {
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($value === 1 || $value === '1' || $value === true || $value === 'true' || $value === 'checked') {
            return 'Checked';
        }

        if ($value === 0 || $value === '0' || $value === false || $value === 'false') {
            return 'Not Checked';
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : '—';
    }
}

if (!function_exists('getFileExtensionSafe')) {
    function getFileExtensionSafe($path): string {
        $path = (string)$path;
        $cleanPath = strtok($path, '?');
        return strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
    }
}

if (!function_exists('canPreviewInline')) {
    function canPreviewInline($path): bool {
        $ext = getFileExtensionSafe($path);
        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }
}

if (!function_exists('isImageFile')) {
    function isImageFile($path): bool {
        $ext = getFileExtensionSafe($path);
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }
}

/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login-admin.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($userId <= 0) {
    session_destroy();
    header('Location: login-admin.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| COLUMN CHECKS
|--------------------------------------------------------------------------
*/
$hasDocumentsDepartmentId = has_column($conn, 'documents', 'department_id');
$hasDocumentsTopic        = has_column($conn, 'documents', 'topic');
$hasDocumentsTitle        = has_column($conn, 'documents', 'title');
$hasDocumentsApprover     = has_column($conn, 'documents', 'approver');
$hasApproverUserId        = has_column($conn, 'documents', 'approver_user_id');

$hasVersionPrimaryName    = has_column($conn, 'document_versions', 'primary_file_name');
$hasVersionPrimaryPath    = has_column($conn, 'document_versions', 'primary_file_path');
$hasVersionPrimaryMime    = has_column($conn, 'document_versions', 'primary_file_mime');
$hasVersionPrimarySize    = has_column($conn, 'document_versions', 'primary_file_size');
$hasVersionContentText    = has_column($conn, 'document_versions', 'content_text');
$hasVersionEffectiveDate  = has_column($conn, 'document_versions', 'effective_date');
$hasVersionReviewDate     = has_column($conn, 'document_versions', 'review_date');
$hasVersionLabel          = has_column($conn, 'document_versions', 'version_label');

/*
|--------------------------------------------------------------------------
| CURRENT USER
|--------------------------------------------------------------------------
*/
$currentUser = null;

$userSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.current_role_id
    WHERE u.id = ?
    LIMIT 1
";

$userStmt = mysqli_prepare($conn, $userSql);
if ($userStmt) {
    mysqli_stmt_bind_param($userStmt, "i", $userId);
    mysqli_stmt_execute($userStmt);
    $userRes = mysqli_stmt_get_result($userStmt);
    $currentUser = ($userRes && mysqli_num_rows($userRes) > 0) ? mysqli_fetch_assoc($userRes) : null;
    mysqli_stmt_close($userStmt);
}

$displayName = 'QA Admin';
$roleName = 'Profile';

if ($currentUser) {
    $displayName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
    if ($displayName === '') {
        $displayName = 'QA Admin';
    }

    $roleName = trim((string)($currentUser['role_name'] ?? 'Profile'));
    if ($roleName === '') {
        $roleName = 'Profile';
    }
}

/*
|--------------------------------------------------------------------------
| FILTER INPUTS
|--------------------------------------------------------------------------
*/
$search       = trim((string)($_GET['search'] ?? ''));
$typeFilter   = trim((string)($_GET['type'] ?? ''));
$deptFilter   = trim((string)($_GET['department'] ?? ''));
$reviewFilter = trim((string)($_GET['review'] ?? ''));
$viewId       = (int)($_GET['view_id'] ?? 0);
$export       = trim((string)($_GET['export'] ?? ''));

/*
|--------------------------------------------------------------------------
| FILTER MASTER DATA
|--------------------------------------------------------------------------
*/
$types = [];
$typeRes = mysqli_query($conn, "SELECT DISTINCT type_name FROM document_types WHERE status = 'active' ORDER BY type_name ASC");
if ($typeRes) {
    while ($row = mysqli_fetch_assoc($typeRes)) {
        $types[] = $row['type_name'];
    }
    mysqli_free_result($typeRes);
}

$departments = [];
if (table_exists($conn, 'departments')) {
    $deptRes = mysqli_query($conn, "SELECT DISTINCT department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC");
    if ($deptRes) {
        while ($row = mysqli_fetch_assoc($deptRes)) {
            $departments[] = $row['department_name'];
        }
        mysqli_free_result($deptRes);
    }
}

/*
|--------------------------------------------------------------------------
| SELECT FIELDS
|--------------------------------------------------------------------------
*/
$titleSelect = $hasDocumentsTitle ? "d.title" : "'' AS title";
$topicSelect = $hasDocumentsTopic ? "d.topic" : "'' AS topic";

$departmentJoin = "";
$departmentSelect = "'' AS department_name";
if ($hasDocumentsDepartmentId && table_exists($conn, 'departments')) {
    $departmentJoin = " LEFT JOIN departments dept ON dept.id = d.department_id ";
    $departmentSelect = "dept.department_name";
}

$versionLabelSelect = $hasVersionLabel ? "dv.version_label" : "'' AS version_label";
$effectiveSelect = $hasVersionEffectiveDate ? "dv.effective_date" : "NULL AS effective_date";
$reviewSelect = $hasVersionReviewDate ? "dv.review_date" : "NULL AS review_date";
$contentSelect = $hasVersionContentText ? "dv.content_text" : "'' AS content_text";
$fileNameSelect = $hasVersionPrimaryName ? "dv.primary_file_name" : "'' AS primary_file_name";
$filePathSelect = $hasVersionPrimaryPath ? "dv.primary_file_path" : "'' AS primary_file_path";
$fileMimeSelect = $hasVersionPrimaryMime ? "dv.primary_file_mime" : "'' AS primary_file_mime";
$fileSizeSelect = $hasVersionPrimarySize ? "dv.primary_file_size" : "0 AS primary_file_size";

/*
|--------------------------------------------------------------------------
| VIEW DOCUMENT
|--------------------------------------------------------------------------
*/
$viewDocument = null;
$viewParsedContent = null;

if ($viewId > 0 && $export === '') {
    $viewSql = "
        SELECT
            d.id,
            d.document_number,
            {$titleSelect},
            {$topicSelect},
            d.current_status,
            dt.type_name,
            {$departmentSelect},
            {$versionLabelSelect},
            {$effectiveSelect},
            {$reviewSelect},
            {$contentSelect},
            {$fileNameSelect},
            {$filePathSelect},
            {$fileMimeSelect},
            {$fileSizeSelect},
            CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,'')) AS owner_name
        FROM documents d
        LEFT JOIN document_types dt ON dt.id = d.document_type_id
        {$departmentJoin}
        LEFT JOIN document_versions dv ON dv.id = d.current_version_id
        LEFT JOIN users owner ON owner.id = d.owner_user_id
        WHERE d.id = ?
        LIMIT 1
    ";

    $viewStmt = mysqli_prepare($conn, $viewSql);
    if ($viewStmt) {
        mysqli_stmt_bind_param($viewStmt, "i", $viewId);
        mysqli_stmt_execute($viewStmt);
        $viewRes = mysqli_stmt_get_result($viewStmt);
        $viewDocument = ($viewRes && mysqli_num_rows($viewRes) > 0) ? mysqli_fetch_assoc($viewRes) : null;
        mysqli_stmt_close($viewStmt);

        if ($viewDocument) {
            $viewParsedContent = parseDocumentContent($viewDocument['content_text'] ?? '');
        }
    }
}

/*
|--------------------------------------------------------------------------
| REPOSITORY QUERY
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        d.id,
        d.document_number,
        {$titleSelect},
        {$topicSelect},
        d.current_status,
        d.created_at,
        dt.type_name,
        {$departmentSelect},
        {$versionLabelSelect},
        {$effectiveSelect},
        {$reviewSelect},
        {$contentSelect},
        {$fileNameSelect},
        {$filePathSelect},
        {$fileMimeSelect},
        {$fileSizeSelect},
        CONCAT(COALESCE(owner.first_name,''), ' ', COALESCE(owner.last_name,'')) AS owner_name
    FROM documents d
    LEFT JOIN document_types dt ON dt.id = d.document_type_id
    {$departmentJoin}
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    LEFT JOIN users owner ON owner.id = d.owner_user_id
    WHERE LOWER(COALESCE(d.current_status, 'effective')) <> 'deleted'
";

$params = [];
$bindTypes = '';

if ($search !== '') {
    $searchFields = ["d.document_number LIKE ?"];
    $params[] = '%' . $search . '%';
    $bindTypes .= 's';

    if ($hasDocumentsTitle) {
        $searchFields[] = "d.title LIKE ?";
        $params[] = '%' . $search . '%';
        $bindTypes .= 's';
    }

    if ($hasDocumentsTopic) {
        $searchFields[] = "d.topic LIKE ?";
        $params[] = '%' . $search . '%';
        $bindTypes .= 's';
    }

    $sql .= " AND (" . implode(" OR ", $searchFields) . ")";
}

if ($typeFilter !== '') {
    $sql .= " AND dt.type_name = ?";
    $params[] = $typeFilter;
    $bindTypes .= 's';
}

if ($deptFilter !== '' && $hasDocumentsDepartmentId && table_exists($conn, 'departments')) {
    $sql .= " AND dept.department_name = ?";
    $params[] = $deptFilter;
    $bindTypes .= 's';
}

if ($reviewFilter === 'overdue' && $hasVersionReviewDate) {
    $sql .= " AND dv.review_date IS NOT NULL AND dv.review_date <> '0000-00-00' AND dv.review_date < CURDATE()";
} elseif ($reviewFilter === 'current' && $hasVersionReviewDate) {
    $sql .= " AND (
        dv.review_date IS NULL
        OR dv.review_date = '0000-00-00'
        OR dv.review_date >= CURDATE()
    )";
}

$sql .= " ORDER BY d.id DESC LIMIT 500";

$rows = [];

$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    bind_params_dynamic($stmt, $bindTypes, $params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        mysqli_free_result($res);
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| EXPORT EXCEL
|--------------------------------------------------------------------------
*/
if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=repository-export-' . date('Y-m-d-H-i-s') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo '<table border="1">';
    echo '<tr>';
    echo '<th>Document ID</th>';
    echo '<th>Title</th>';
    echo '<th>Type</th>';
    echo '<th>Version</th>';
    echo '<th>Department</th>';
    echo '<th>Owner</th>';
    echo '<th>Effective Date</th>';
    echo '<th>Next Review</th>';
    echo '<th>Status</th>';
    echo '</tr>';

    foreach ($rows as $row) {
        $docId = $row['document_number'] ?: ('DOC-' . (int)$row['id']);
        $title = $row['title'] ?: ($row['topic'] ?: 'Untitled');
        $type = $row['type_name'] ?: '-';
        $version = $row['version_label'] ?: '-';
        $dept = $row['department_name'] ?: '-';
        $owner = trim((string)$row['owner_name']) !== '' ? $row['owner_name'] : '-';
        $effectiveDate = formatDateExport($row['effective_date'] ?? '');
        $reviewDate = formatDateExport($row['review_date'] ?? '');
        $statusLabel = displayStatusLabel($row['current_status'] ?? '', $row['review_date'] ?? '');

        echo '<tr>';
        echo '<td>' . e($docId) . '</td>';
        echo '<td>' . e($title) . '</td>';
        echo '<td>' . e($type) . '</td>';
        echo '<td>' . e($version) . '</td>';
        echo '<td>' . e($dept) . '</td>';
        echo '<td>' . e($owner) . '</td>';
        echo '<td>' . e($effectiveDate) . '</td>';
        echo '<td>' . e($reviewDate) . '</td>';
        echo '<td>' . e($statusLabel) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    exit;
}

/*
|--------------------------------------------------------------------------
| EXPORT PDF
|--------------------------------------------------------------------------
*/
if ($export === 'pdf') {
    $fpdfPath = '';

    if (file_exists(__DIR__ . '/lib/fpdf.php')) {
        $fpdfPath = __DIR__ . '/lib/fpdf.php';
    } elseif (file_exists(__DIR__ . '/libs/fpdf.php')) {
        $fpdfPath = __DIR__ . '/libs/fpdf.php';
    }

    if ($fpdfPath !== '') {
        require_once $fpdfPath;

        class RepositoryPDF extends FPDF
        {
            public function Header()
            {
                $this->SetFont('Arial', 'B', 12);
                $this->Cell(0, 8, 'CorePlx Quality DMS - Repository Export', 0, 1, 'C');
                $this->SetFont('Arial', '', 9);
                $this->Cell(0, 6, 'Generated on ' . date('d-m-Y H:i'), 0, 1, 'C');
                $this->Ln(2);
            }

            public function Footer()
            {
                $this->SetY(-12);
                $this->SetFont('Arial', '', 8);
                $this->Cell(0, 6, 'Page ' . $this->PageNo(), 0, 0, 'C');
            }
        }

        $pdf = new RepositoryPDF('L', 'mm', 'A4');
        $pdf->SetMargins(8, 8, 8);
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 8);

        $widths = [30, 55, 22, 15, 30, 32, 24, 24, 28];
        $headers = ['Document ID', 'Title', 'Type', 'Ver', 'Department', 'Owner', 'Effective', 'Review', 'Status'];

        foreach ($headers as $i => $header) {
            $pdf->Cell($widths[$i], 8, $header, 1, 0, 'C');
        }

        $pdf->Ln();
        $pdf->SetFont('Arial', '', 7);

        foreach ($rows as $row) {
            $docId = $row['document_number'] ?: ('DOC-' . (int)$row['id']);
            $title = $row['title'] ?: ($row['topic'] ?: 'Untitled');
            $type = $row['type_name'] ?: '-';
            $version = $row['version_label'] ?: '-';
            $dept = $row['department_name'] ?: '-';
            $owner = trim((string)$row['owner_name']) !== '' ? $row['owner_name'] : '-';
            $effectiveDate = formatDateExport($row['effective_date'] ?? '');
            $reviewDate = formatDateExport($row['review_date'] ?? '');
            $statusLabel = displayStatusLabel($row['current_status'] ?? '', $row['review_date'] ?? '');

            $pdf->Cell($widths[0], 7, substr($docId, 0, 22), 1);
            $pdf->Cell($widths[1], 7, substr($title, 0, 38), 1);
            $pdf->Cell($widths[2], 7, substr($type, 0, 14), 1);
            $pdf->Cell($widths[3], 7, substr($version, 0, 8), 1, 0, 'C');
            $pdf->Cell($widths[4], 7, substr($dept, 0, 18), 1);
            $pdf->Cell($widths[5], 7, substr($owner, 0, 20), 1);
            $pdf->Cell($widths[6], 7, $effectiveDate, 1, 0, 'C');
            $pdf->Cell($widths[7], 7, $reviewDate, 1, 0, 'C');
            $pdf->Cell($widths[8], 7, substr($statusLabel, 0, 18), 1, 0, 'C');
            $pdf->Ln();
        }

        $pdf->Output('I', 'repository-export-' . date('Y-m-d-H-i-s') . '.pdf');
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Repository Export</title></head><body onload="window.print()">';
    echo '<h2>CorePlx Quality DMS - Repository Export</h2>';
    echo '<p>Generated on ' . e(date('d-m-Y H:i')) . '</p>';
    echo '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;">';
    echo '<tr><th>Document ID</th><th>Title</th><th>Type</th><th>Version</th><th>Department</th><th>Owner</th><th>Effective Date</th><th>Next Review</th><th>Status</th></tr>';

    foreach ($rows as $row) {
        $docId = $row['document_number'] ?: ('DOC-' . (int)$row['id']);
        $title = $row['title'] ?: ($row['topic'] ?: 'Untitled');
        $type = $row['type_name'] ?: '-';
        $version = $row['version_label'] ?: '-';
        $dept = $row['department_name'] ?: '-';
        $owner = trim((string)$row['owner_name']) !== '' ? $row['owner_name'] : '-';
        $effectiveDate = formatDateExport($row['effective_date'] ?? '');
        $reviewDate = formatDateExport($row['review_date'] ?? '');
        $statusLabel = displayStatusLabel($row['current_status'] ?? '', $row['review_date'] ?? '');

        echo '<tr>';
        echo '<td>' . e($docId) . '</td>';
        echo '<td>' . e($title) . '</td>';
        echo '<td>' . e($type) . '</td>';
        echo '<td>' . e($version) . '</td>';
        echo '<td>' . e($dept) . '</td>';
        echo '<td>' . e($owner) . '</td>';
        echo '<td>' . e($effectiveDate) . '</td>';
        echo '<td>' . e($reviewDate) . '</td>';
        echo '<td>' . e($statusLabel) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</body></html>';
    exit;
}

/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/
$totalEffective = 0;
$overdueReview = 0;
$addedThisMonth = 0;
$pendingRetirement = 0;

$summarySql = "
    SELECT
        d.id,
        d.current_status,
        d.created_at,
        " . ($hasVersionReviewDate ? "dv.review_date" : "NULL AS review_date") . "
    FROM documents d
    LEFT JOIN document_versions dv ON dv.id = d.current_version_id
    WHERE LOWER(COALESCE(d.current_status, 'effective')) <> 'deleted'
";

$summaryRes = mysqli_query($conn, $summarySql);

if ($summaryRes) {
    $monthStart = date('Y-m-01 00:00:00');
    $monthEnd = date('Y-m-t 23:59:59');

    while ($row = mysqli_fetch_assoc($summaryRes)) {
        $status = strtolower(trim((string)($row['current_status'] ?? 'effective')));

        if (in_array($status, ['effective', 'approved'], true) || $status === '') {
            $totalEffective++;
        }

        if (isOverdueDate($row['review_date'] ?? '')) {
            $overdueReview++;
        }

        if (!empty($row['created_at']) && $row['created_at'] >= $monthStart && $row['created_at'] <= $monthEnd) {
            $addedThisMonth++;
        }

        if ($status === 'pending_retirement') {
            $pendingRetirement++;
        }
    }

    mysqli_free_result($summaryRes);
}

$filteredCount = count($rows);

$totalRepoCount = 0;
$totalCountSql = "SELECT COUNT(*) AS cnt FROM documents WHERE LOWER(COALESCE(current_status,'effective')) <> 'deleted'";
$totalCountRes = mysqli_query($conn, $totalCountSql);

if ($totalCountRes && ($cntRow = mysqli_fetch_assoc($totalCountRes))) {
    $totalRepoCount = (int)$cntRow['cnt'];
    mysqli_free_result($totalCountRes);
}

$baseQuery = [
    'search' => $search,
    'type' => $typeFilter,
    'department' => $deptFilter,
    'review' => $reviewFilter
];

$pdfUrl = 'repository.php?' . http_build_query(array_merge($baseQuery, ['export' => 'pdf']));
$excelUrl = 'repository.php?' . http_build_query(array_merge($baseQuery, ['export' => 'excel']));

/*
|--------------------------------------------------------------------------
| DOCUMENT VIEW PAGE
|--------------------------------------------------------------------------
*/
if ($viewDocument && $export === '') {
    $modalDocNumber = $viewDocument['document_number'] ?: ('DOC-' . (int)$viewDocument['id']);
    $modalTitle = $viewDocument['title'] ?: ($viewDocument['topic'] ?: 'Untitled');
    $modalStatusLabel = displayStatusLabel($viewDocument['current_status'] ?? '', $viewDocument['review_date'] ?? '');
    $modalStatusClass = statusBadgeClass($viewDocument['current_status'] ?? '', $viewDocument['review_date'] ?? '');
    $modalOwner = trim((string)$viewDocument['owner_name']) !== '' ? $viewDocument['owner_name'] : '—';

    $filePath = trim((string)($viewDocument['primary_file_path'] ?? ''));
    $fileName = trim((string)($viewDocument['primary_file_name'] ?? ''));
    $fileExt = getFileExtensionSafe($filePath);
    $canInlinePreview = $filePath !== '' && canPreviewInline($filePath);
    $isImagePreview = $filePath !== '' && isImageFile($filePath);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Document View</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">

  <style>
    .repo-modal-label {
      font-size: 12px;
      color: #6b7280;
      font-weight: 600;
      margin-bottom: 4px;
      text-transform: uppercase;
      letter-spacing: .3px;
    }

    .repo-modal-value {
      font-size: 14px;
      color: #1f2937;
      font-weight: 500;
    }

    .repo-modal-box {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      padding: 14px;
      height: 100%;
    }

    .repo-purpose-box {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      padding: 14px;
      font-size: 14px;
      color: #1f2937;
      white-space: pre-wrap;
      word-break: break-word;
    }

    .repo-form-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    .repo-form-table th,
    .repo-form-table td {
      border: 1px solid #e5e7eb;
      padding: 10px 12px;
      vertical-align: top;
    }

    .repo-form-table th {
      background: #eef3fb;
      color: #0D2144;
      font-weight: 700;
    }

    .repo-json-pre {
      white-space: pre-wrap;
      word-wrap: break-word;
      margin: 0;
      font-family: inherit;
      font-size: 14px;
      color: #1f2937;
    }

    .repo-file-preview-wrap {
      background: #f8f9fb;
      border: 1px solid #dde3ec;
      border-radius: 12px;
      padding: 12px;
    }

    .repo-file-preview-frame {
      width: 100%;
      height: 560px;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      background: #fff;
    }

    .repo-file-preview-image {
      width: 100%;
      max-height: 560px;
      object-fit: contain;
      border: 1px solid #dde3ec;
      border-radius: 10px;
      background: #fff;
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
          <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item active" href="repository.php">Repository</a></li>
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

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
      <div>
        <h1 class="page-title mb-2">Document View</h1>
        <p class="page-subtitle mb-0"><?php echo e($modalDocNumber); ?> - <?php echo e($modalTitle); ?></p>
      </div>

      <a href="repository.php?<?php echo e(http_build_query($baseQuery)); ?>" class="btn btn-outline-secondary">Back</a>
    </div>

    <div class="card cp-card">
      <div class="card-body">

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Document ID</div>
              <div class="repo-modal-value"><?php echo e($modalDocNumber); ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Type</div>
              <div class="repo-modal-value"><?php echo e($viewDocument['type_name'] ?: '—'); ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Version</div>
              <div class="repo-modal-value"><?php echo e($viewDocument['version_label'] ?: '—'); ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Status</div>
              <div class="repo-modal-value">
                <span class="<?php echo e($modalStatusClass); ?>"><?php echo e($modalStatusLabel); ?></span>
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Department</div>
              <div class="repo-modal-value"><?php echo e($viewDocument['department_name'] ?: '—'); ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Owner</div>
              <div class="repo-modal-value"><?php echo e($modalOwner); ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Effective Date</div>
              <div class="repo-modal-value"><?php echo e(formatDateDisplay($viewDocument['effective_date'] ?? '')); ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Next Review</div>
              <div class="repo-modal-value"><?php echo e(formatDateDisplay($viewDocument['review_date'] ?? '')); ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="repo-modal-box">
              <div class="repo-modal-label">Title / Topic</div>
              <div class="repo-modal-value"><?php echo e($modalTitle); ?></div>
            </div>
          </div>
        </div>

        <?php if ($viewParsedContent && $viewParsedContent['is_json_form']): ?>

          <div class="mb-3">
            <label class="form-label fw-semibold">Purpose & Scope</label>
            <div class="repo-purpose-box">
              <?php echo $viewParsedContent['purpose_scope'] !== '' ? nl2br(e($viewParsedContent['purpose_scope'])) : '—'; ?>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Form / Checklist Response Details</label>

            <?php if (!empty($viewParsedContent['form_responses'])): ?>
              <div class="table-responsive">
                <table class="repo-form-table">
                  <thead>
                    <tr>
                      <th style="width:30%;">Field</th>
                      <th>Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($viewParsedContent['form_responses'] as $fieldKey => $fieldValue): ?>
                      <tr>
                        <td><?php echo e(formatFormResponseLabel($fieldKey)); ?></td>
                        <td>
                          <?php
                            $displayValue = formatFormResponseValue($fieldValue);

                            if (is_array($fieldValue)) {
                                echo '<pre class="repo-json-pre">' . e($displayValue) . '</pre>';
                            } else {
                                echo nl2br(e($displayValue));
                            }
                          ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="repo-purpose-box">No form response details available.</div>
            <?php endif; ?>
          </div>

        <?php elseif (!empty($viewDocument['content_text'])): ?>

          <div class="mb-3">
            <label class="form-label fw-semibold">Document Content</label>
            <div class="repo-purpose-box">
              <pre class="repo-json-pre"><?php echo e($viewDocument['content_text']); ?></pre>
            </div>
          </div>

        <?php endif; ?>

        <?php if ($filePath !== ''): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Attached File</label>

            <div class="repo-file-preview-wrap">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                  <strong><?php echo e($fileName !== '' ? $fileName : basename($filePath)); ?></strong>
                  <div class="small text-secondary"><?php echo e(strtoupper($fileExt !== '' ? $fileExt : 'FILE')); ?> file preview</div>
                </div>

                <a class="btn btn-sm btn-outline-primary" href="<?php echo e($filePath); ?>" target="_blank" rel="noopener">Open File</a>
              </div>

              <?php if ($canInlinePreview): ?>
                <?php if ($isImagePreview): ?>
                  <img src="<?php echo e($filePath); ?>" alt="<?php echo e($fileName); ?>" class="repo-file-preview-image">
                <?php else: ?>
                  <iframe src="<?php echo e($filePath); ?>" class="repo-file-preview-frame"></iframe>
                <?php endif; ?>
              <?php else: ?>
                <div class="repo-purpose-box">
                  Inline preview is not available for this file type.
                  <div class="mt-2">
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo e($filePath); ?>" target="_blank" rel="noopener">Open File</a>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif (empty($viewDocument['content_text'])): ?>
          <div class="repo-purpose-box">No document content available.</div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Repository</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">

  <style>
    .table td,
    .table th {
      vertical-align: middle;
    }

    .repo-filter-card .card-body {
      padding: 18px 16px;
    }

    .repo-filter-grid {
      display: grid;
      grid-template-columns: 1.4fr 1fr 1fr 0.9fr auto;
      gap: 10px;
      align-items: end;
    }

    .repo-filter-group label {
      font-size: 12px;
      font-weight: 600;
      color: #5f6b7a;
      margin-bottom: 6px;
      display: block;
    }

    .repo-filter-group .form-control-sm,
    .repo-filter-group .form-select-sm {
      height: 40px;
      border-radius: 8px;
      font-size: 14px;
      padding-left: 12px;
      padding-right: 12px;
    }

    .repo-inline-actions {
      display: flex;
      align-items: end;
      gap: 8px;
      justify-content: flex-start;
      flex-wrap: nowrap;
      min-width: 190px;
    }

    .repo-inline-actions .btn {
      height: 40px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      padding: 0 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      white-space: nowrap;
    }

    .repo-export-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      justify-content: flex-end;
      margin-top: 10px;
      flex-wrap: wrap;
    }

    .repo-export-actions .btn {
      height: 40px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      padding: 0 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      white-space: nowrap;
    }

    .repo-table-wrap {
      overflow-x: auto;
    }

    .repo-table-wrap table {
      min-width: 1100px;
    }

    @media (max-width: 1199.98px) {
      .repo-filter-grid {
        grid-template-columns: 1fr 1fr;
      }

      .repo-inline-actions {
        grid-column: 1 / -1;
        min-width: 100%;
      }

      .repo-export-actions {
        justify-content: flex-start;
      }
    }

    @media (max-width: 767.98px) {
      .repo-filter-grid {
        grid-template-columns: 1fr;
      }

      .repo-inline-actions,
      .repo-export-actions {
        width: 100%;
      }

      .repo-inline-actions .btn,
      .repo-export-actions .btn {
        flex: 1 1 auto;
      }
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
        <li class="nav-item">
          <a class="nav-link" href="dashboard-admin.php">Dashboard</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item active" href="repository.php">Repository</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-trail.php">Audit Trail</a></li>
            <li><a class="dropdown-item" href="document-assignment.php">Document Assignment</a></li>
            <li><a class="dropdown-item" href="user-management.php">User Management</a></li>
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link" href="portal-select.php">Switch to User</a>
        </li>
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
    <h1 class="page-title mb-2">Effective Documents Repository</h1>
    <p class="page-subtitle mb-0">Organisation-wide library of approved, pending, and controlled documents.</p>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Total Effective</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$totalEffective; ?></div>
          </div>
          <span style="font-size:1.8rem;">📋</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Overdue Review</div>
            <div class="stat-value" style="font-size:1.6rem;color:#dc2626;"><?php echo (int)$overdueReview; ?></div>
          </div>
          <span style="font-size:1.8rem;">⚠️</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Added This Month</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$addedThisMonth; ?></div>
          </div>
          <span style="font-size:1.8rem;">✅</span>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="card cp-card">
        <div class="card-body py-3 px-4 d-flex justify-content-between align-items-center">
          <div>
            <div class="section-kicker mb-1">Pending Retirement</div>
            <div class="stat-value" style="font-size:1.6rem;"><?php echo (int)$pendingRetirement; ?></div>
          </div>
          <span style="font-size:1.8rem;">🗄️</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card cp-card mb-3">
    <div class="card-body">
      <form method="get" id="repoFilterForm">
        <div class="repo-filter-grid">
          <div class="repo-filter-group">
            <label class="form-label mb-1">Search</label>
            <input type="text" class="form-control form-control-sm" name="search" value="<?php echo e($search); ?>" placeholder="Search document ID, title, or topic...">
          </div>

          <div class="repo-filter-group">
            <label class="form-label mb-1">Type</label>
            <select class="form-select form-select-sm auto-submit-filter" name="type">
              <option value="">All Types</option>
              <?php foreach ($types as $type): ?>
                <option value="<?php echo e($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                  <?php echo e($type); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="repo-filter-group">
            <label class="form-label mb-1">Department</label>
            <select class="form-select form-select-sm auto-submit-filter" name="department" <?php echo !$hasDocumentsDepartmentId ? 'disabled' : ''; ?>>
              <option value="">All Departments</option>
              <?php foreach ($departments as $department): ?>
                <option value="<?php echo e($department); ?>" <?php echo $deptFilter === $department ? 'selected' : ''; ?>>
                  <?php echo e($department); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="repo-filter-group">
            <label class="form-label mb-1">Review Status</label>
            <select class="form-select form-select-sm auto-submit-filter" name="review" <?php echo !$hasVersionReviewDate ? 'disabled' : ''; ?>>
              <option value="">All</option>
              <option value="overdue" <?php echo $reviewFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
              <option value="current" <?php echo $reviewFilter === 'current' ? 'selected' : ''; ?>>Current</option>
            </select>
          </div>

          <div class="repo-inline-actions">
            <a class="btn btn-sm btn-outline-secondary" href="repository.php">Reset</a>
            <button class="btn btn-sm btn-primary" type="submit">Search</button>
          </div>
        </div>

        <div class="repo-export-actions">
          <a class="btn btn-sm btn-outline-primary" href="<?php echo e($pdfUrl); ?>" target="_blank" rel="noopener">↓ Export PDF</a>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo e($excelUrl); ?>" target="_blank" rel="noopener">↓ Export Excel</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card cp-card" style="padding:0;">
    <div class="repo-table-wrap">
      <table class="table mb-0" id="repoTable">
        <thead>
          <tr>
            <th>Document ID</th>
            <th>Title / Topic</th>
            <th>Type</th>
            <th>Version</th>
            <th>Department</th>
            <th>Owner</th>
            <th>Effective Date</th>
            <th>Next Review</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>

        <tbody id="repoBody">
          <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $docId = (int)$row['id'];
                $docNumber = $row['document_number'] ?: ('DOC-' . $docId);
                $title = $row['title'] ?: ($row['topic'] ?: 'Untitled');
                $type = $row['type_name'] ?: '—';
                $version = $row['version_label'] ?: '—';
                $dept = $row['department_name'] ?: '—';
                $owner = trim((string)$row['owner_name']) !== '' ? $row['owner_name'] : '—';

                $effectiveDate = formatDateDisplay($row['effective_date'] ?? '');
                $reviewDate = formatDateDisplay($row['review_date'] ?? '');

                $isOverdue = isOverdueDate($row['review_date'] ?? '');
                $reviewStyle = $isOverdue ? 'color:#dc2626;font-weight:600;' : 'color:#6b7280;';

                $statusLabel = displayStatusLabel($row['current_status'] ?? '', $row['review_date'] ?? '');
                $statusClass = statusBadgeClass($row['current_status'] ?? '', $row['review_date'] ?? '');

                $filePath = trim((string)($row['primary_file_path'] ?? ''));
                $pdfLink = $filePath !== '' ? $filePath : ('repository.php?view_id=' . $docId);
                $viewUrl = 'repository.php?' . http_build_query(array_merge($baseQuery, ['view_id' => $docId]));
              ?>

              <tr>
                <td class="fw-semibold" style="color:#2563eb;font-size:13px;">
                  <?php echo e($docNumber); ?>
                </td>

                <td style="font-size:13px;">
                  <?php echo e($title); ?>
                </td>

                <td>
                  <span class="badge badge-soft-info"><?php echo e($type); ?></span>
                </td>

                <td style="font-size:13px;">
                  <?php echo e($version); ?>
                </td>

                <td style="font-size:12px;color:#6b7280;">
                  <?php echo e($dept); ?>
                </td>

                <td style="font-size:12px;color:#6b7280;">
                  <?php echo e($owner); ?>
                </td>

                <td style="font-size:12px;color:#6b7280;">
                  <?php echo e($effectiveDate); ?>
                </td>

                <td style="font-size:12px;<?php echo e($reviewStyle); ?>">
                  <?php echo e($reviewDate); ?>
                </td>

                <td>
                  <span class="<?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span>
                </td>

                <td style="white-space:nowrap;">
                  <a class="btn btn-sm btn-outline-primary" style="height:28px;padding:0 10px;font-size:12px;" href="<?php echo e($viewUrl); ?>">View</a>

                  <?php if ($filePath !== ''): ?>
                    <a class="btn btn-sm btn-outline-secondary" style="height:28px;padding:0 10px;font-size:12px;" href="<?php echo e($pdfLink); ?>" target="_blank" rel="noopener">Open File</a>
                  <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary" style="height:28px;padding:0 10px;font-size:12px;" href="<?php echo e($viewUrl); ?>">Details</a>
                  <?php endif; ?>
                </td>
              </tr>

            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="10" class="text-center text-secondary py-4 small">No documents found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="px-4 py-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span class="small text-secondary" id="repoCount">
        Showing <?php echo (int)$filteredCount; ?> of <?php echo (int)$totalRepoCount; ?> documents
      </span>

      <span class="small" style="color:#dc2626;font-weight:600;">
        🔒 Read-only — no documents can be edited or deleted from this view.
      </span>
    </div>
  </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.querySelectorAll('.auto-submit-filter').forEach(function(el) {
  el.addEventListener('change', function() {
    document.getElementById('repoFilterForm').submit();
  });
});
</script>

</body>
</html>