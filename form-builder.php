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

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, $tableName) {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('columnExists')) {
    function columnExists(mysqli $conn, $tableName, $columnName) {
        $tableName = mysqli_real_escape_string($conn, $tableName);
        $columnName = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserName = isset($_SESSION['full_name']) && trim($_SESSION['full_name']) !== ''
    ? $_SESSION['full_name']
    : 'QA Admin';

$successMessage = '';
$errorMessage = '';

$formId = trim($_GET['form_id'] ?? $_POST['form_id'] ?? 'default_form');

$hasFormFieldsTable = tableExists($conn, 'form_builder_fields');

if (!isset($_SESSION['form_builder_fields']) || !is_array($_SESSION['form_builder_fields'])) {
    $_SESSION['form_builder_fields'] = [];
}

if (!isset($_SESSION['form_builder_fields'][$formId])) {
    $_SESSION['form_builder_fields'][$formId] = [
        [
            'id' => 1,
            'field_label' => 'Employee Name',
            'field_type' => 'Text',
            'is_required' => 1,
            'field_options' => ''
        ],
        [
            'id' => 2,
            'field_label' => 'Review Date',
            'field_type' => 'Date',
            'is_required' => 0,
            'field_options' => ''
        ],
        [
            'id' => 3,
            'field_label' => 'Department',
            'field_type' => 'Dropdown',
            'is_required' => 1,
            'field_options' => 'QA, Ops, IT'
        ],
        [
            'id' => 4,
            'field_label' => 'Read & Understood',
            'field_type' => 'Checkbox',
            'is_required' => 1,
            'field_options' => 'Yes'
        ],
        [
            'id' => 5,
            'field_label' => 'Manager Signature',
            'field_type' => 'Signature',
            'is_required' => 1,
            'field_options' => ''
        ]
    ];
}

$editFieldId = (int)($_GET['edit_id'] ?? $_POST['edit_id'] ?? 0);
$editField = [
    'id' => 0,
    'field_label' => '',
    'field_type' => 'Text',
    'is_required' => 0,
    'field_options' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_field') {
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $fieldLabel = trim($_POST['field_label'] ?? '');
        $fieldType = trim($_POST['field_type'] ?? 'Text');
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $fieldOptions = trim($_POST['field_options'] ?? '');

        $allowedTypes = ['Text', 'Date', 'Dropdown', 'Checkbox', 'Signature'];

        if ($fieldLabel === '') {
            $errorMessage = 'Field label is required.';
        } elseif (!in_array($fieldType, $allowedTypes, true)) {
            $errorMessage = 'Invalid field type selected.';
        } else {
            if ($hasFormFieldsTable) {
                $formIdCol = columnExists($conn, 'form_builder_fields', 'form_id') ? 'form_id' : '';
                $labelCol = columnExists($conn, 'form_builder_fields', 'field_label') ? 'field_label' : '';
                $typeCol = columnExists($conn, 'form_builder_fields', 'field_type') ? 'field_type' : '';
                $requiredCol = columnExists($conn, 'form_builder_fields', 'is_required') ? 'is_required' : '';
                $optionsCol = columnExists($conn, 'form_builder_fields', 'field_options') ? 'field_options' : '';
                $sortOrderCol = columnExists($conn, 'form_builder_fields', 'sort_order') ? 'sort_order' : '';
                $updatedByCol = columnExists($conn, 'form_builder_fields', 'updated_by') ? 'updated_by' : '';
                $createdByCol = columnExists($conn, 'form_builder_fields', 'created_by') ? 'created_by' : '';

                if ($formIdCol === '' || $labelCol === '' || $typeCol === '' || $requiredCol === '' || $optionsCol === '') {
                    $errorMessage = 'form_builder_fields table structure is incomplete.';
                } else {
                    if ($fieldId > 0) {
                        $updateParts = [];
                        $updateParts[] = "`{$labelCol}` = ?";
                        $updateParts[] = "`{$typeCol}` = ?";
                        $updateParts[] = "`{$requiredCol}` = ?";
                        $updateParts[] = "`{$optionsCol}` = ?";

                        if ($updatedByCol !== '') {
                            $updateParts[] = "`{$updatedByCol}` = ?";
                            $sql = "UPDATE `form_builder_fields` SET " . implode(', ', $updateParts) . " WHERE `id` = ? AND `{$formIdCol}` = ?";
                            $stmt = mysqli_prepare($conn, $sql);

                            if ($stmt) {
                                mysqli_stmt_bind_param(
                                    $stmt,
                                    "ssisiis",
                                    $fieldLabel,
                                    $fieldType,
                                    $isRequired,
                                    $fieldOptions,
                                    $currentUserId,
                                    $fieldId,
                                    $formId
                                );
                            }
                        } else {
                            $sql = "UPDATE `form_builder_fields` SET " . implode(', ', $updateParts) . " WHERE `id` = ? AND `{$formIdCol}` = ?";
                            $stmt = mysqli_prepare($conn, $sql);

                            if ($stmt) {
                                mysqli_stmt_bind_param(
                                    $stmt,
                                    "ssisis",
                                    $fieldLabel,
                                    $fieldType,
                                    $isRequired,
                                    $fieldOptions,
                                    $fieldId,
                                    $formId
                                );
                            }
                        }

                        if (isset($stmt) && $stmt) {
                            if (mysqli_stmt_execute($stmt)) {
                                $successMessage = 'Field updated successfully.';
                            } else {
                                $errorMessage = 'Failed to update field: ' . mysqli_error($conn);
                            }
                            mysqli_stmt_close($stmt);
                        }
                    } else {
                        $sortOrder = 1;
                        if ($sortOrderCol !== '') {
                            $sortRes = mysqli_query($conn, "SELECT COALESCE(MAX(`{$sortOrderCol}`), 0) + 1 AS next_sort FROM `form_builder_fields`");
                            if ($sortRes) {
                                $sortRow = mysqli_fetch_assoc($sortRes);
                                $sortOrder = (int)($sortRow['next_sort'] ?? 1);
                            }
                        }

                        $cols = ["`{$formIdCol}`", "`{$labelCol}`", "`{$typeCol}`", "`{$requiredCol}`", "`{$optionsCol}`"];
                        $vals = ["?", "?", "?", "?", "?"];
                        $types = "sssis";
                        $binds = [$formId, $fieldLabel, $fieldType, $isRequired, $fieldOptions];

                        if ($sortOrderCol !== '') {
                            $cols[] = "`{$sortOrderCol}`";
                            $vals[] = "?";
                            $types .= "i";
                            $binds[] = $sortOrder;
                        }

                        if ($createdByCol !== '') {
                            $cols[] = "`{$createdByCol}`";
                            $vals[] = "?";
                            $types .= "i";
                            $binds[] = $currentUserId;
                        }

                        if ($updatedByCol !== '') {
                            $cols[] = "`{$updatedByCol}`";
                            $vals[] = "?";
                            $types .= "i";
                            $binds[] = $currentUserId;
                        }

                        $sql = "INSERT INTO `form_builder_fields` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
                        $stmt = mysqli_prepare($conn, $sql);

                        if ($stmt) {
                            mysqli_stmt_bind_param($stmt, $types, ...$binds);

                            if (mysqli_stmt_execute($stmt)) {
                                $successMessage = 'Field added successfully.';
                            } else {
                                $errorMessage = 'Failed to add field: ' . mysqli_error($conn);
                            }
                            mysqli_stmt_close($stmt);
                        }
                    }
                }
            } else {
                $sessionFields = $_SESSION['form_builder_fields'][$formId];

                if ($fieldId > 0) {
                    foreach ($sessionFields as $k => $f) {
                        if ((int)$f['id'] === $fieldId) {
                            $sessionFields[$k]['field_label'] = $fieldLabel;
                            $sessionFields[$k]['field_type'] = $fieldType;
                            $sessionFields[$k]['is_required'] = $isRequired;
                            $sessionFields[$k]['field_options'] = $fieldOptions;
                            break;
                        }
                    }
                    $successMessage = 'Field updated successfully.';
                } else {
                    $nextId = 1;
                    foreach ($sessionFields as $f) {
                        if ((int)$f['id'] >= $nextId) {
                            $nextId = (int)$f['id'] + 1;
                        }
                    }

                    $sessionFields[] = [
                        'id' => $nextId,
                        'field_label' => $fieldLabel,
                        'field_type' => $fieldType,
                        'is_required' => $isRequired,
                        'field_options' => $fieldOptions
                    ];
                    $successMessage = 'Field added successfully.';
                }

                $_SESSION['form_builder_fields'][$formId] = array_values($sessionFields);
            }
        }
    }

    if ($action === 'delete_field') {
        $fieldId = (int)($_POST['field_id'] ?? 0);

        if ($fieldId <= 0) {
            $errorMessage = 'Invalid field selected.';
        } else {
            if ($hasFormFieldsTable) {
                $formIdCol = columnExists($conn, 'form_builder_fields', 'form_id') ? 'form_id' : '';

                if ($formIdCol === '') {
                    $errorMessage = 'form_builder_fields table structure is incomplete.';
                } else {
                    $sql = "DELETE FROM `form_builder_fields` WHERE `id` = ? AND `{$formIdCol}` = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "is", $fieldId, $formId);
                        if (mysqli_stmt_execute($stmt)) {
                            $successMessage = 'Field deleted successfully.';
                        } else {
                            $errorMessage = 'Failed to delete field: ' . mysqli_error($conn);
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
            } else {
                $sessionFields = $_SESSION['form_builder_fields'][$formId];
                $newFields = [];

                foreach ($sessionFields as $f) {
                    if ((int)$f['id'] !== $fieldId) {
                        $newFields[] = $f;
                    }
                }

                $_SESSION['form_builder_fields'][$formId] = $newFields;
                $successMessage = 'Field deleted successfully.';
            }
        }
    }

    if ($action === 'save_form_draft') {
        $successMessage = 'Form draft saved successfully.';
    }
}

$fields = [];

if ($hasFormFieldsTable) {
    $formIdCol = columnExists($conn, 'form_builder_fields', 'form_id') ? 'form_id' : '';
    $labelCol = columnExists($conn, 'form_builder_fields', 'field_label') ? 'field_label' : '';
    $typeCol = columnExists($conn, 'form_builder_fields', 'field_type') ? 'field_type' : '';
    $requiredCol = columnExists($conn, 'form_builder_fields', 'is_required') ? 'is_required' : '';
    $optionsCol = columnExists($conn, 'form_builder_fields', 'field_options') ? 'field_options' : '';
    $sortOrderCol = columnExists($conn, 'form_builder_fields', 'sort_order') ? 'sort_order' : '';

    if ($formIdCol !== '' && $labelCol !== '' && $typeCol !== '' && $requiredCol !== '' && $optionsCol !== '') {
        $orderBy = $sortOrderCol !== '' ? "`{$sortOrderCol}` ASC, `id` ASC" : "`id` ASC";
        $sql = "
            SELECT
                `id`,
                `{$labelCol}` AS field_label,
                `{$typeCol}` AS field_type,
                `{$requiredCol}` AS is_required,
                `{$optionsCol}` AS field_options
            FROM `form_builder_fields`
            WHERE `{$formIdCol}` = ?
            ORDER BY {$orderBy}
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $formId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $fields[] = $row;
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
} else {
    $fields = $_SESSION['form_builder_fields'][$formId];
}

if ($editFieldId > 0) {
    foreach ($fields as $field) {
        if ((int)$field['id'] === $editFieldId) {
            $editField = [
                'id' => (int)$field['id'],
                'field_label' => (string)($field['field_label'] ?? ''),
                'field_type' => (string)($field['field_type'] ?? 'Text'),
                'is_required' => (int)($field['is_required'] ?? 0),
                'field_options' => (string)($field['field_options'] ?? '')
            ];
            break;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Form Builder</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .cp-card {
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 18px;
      box-shadow: 0 6px 24px rgba(0,0,0,.06);
      background: #fff;
    }
    .page-title {
      font-size: 1.75rem;
      font-weight: 700;
    }
    .page-subtitle,
    .card-subtitle {
      color: #6c757d;
    }
    .badge-soft-success {
      background: rgba(25,135,84,.12);
      color: #198754;
    }
    .badge-soft-secondary {
      background: rgba(108,117,125,.12);
      color: #6c757d;
    }
    .note-list {
      padding-left: 1rem;
    }
    .preview-box {
      border: 1px dashed rgba(0,0,0,.12);
      border-radius: 14px;
      background: #f8f9fa;
      padding: 1rem;
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-xl navbar-coreplx sticky-top">
  <div class="container-fluid px-4 px-xxl-5">
    <a class="navbar-brand fw-bold" href="dashboard-admin.php">CorePlx Quality DMS</a>
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav ms-xl-4 me-auto mb-2 mb-xl-0 gap-xl-2">
        <li class="nav-item"><a class="nav-link active" href="dashboard-admin.php">Dashboard</a></li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Documents</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="create-document.php">Create Document</a></li>
            <li><a class="dropdown-item" href="update-document.php">Update Document</a></li>
            <li><a class="dropdown-item" href="retire-document.php">Retire Document</a></li>
            <li><a class="dropdown-item" href="repository.php">Repository</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Workflow</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="document-types.php">Document Types</a></li>
            <li><a class="dropdown-item" href="document-id.php">Document ID</a></li>
            <li><a class="dropdown-item" href="content-editor.php">Content Editor</a></li>
            <li><a class="dropdown-item" href="form-builder.php">Form Builder</a></li>
            <li><a class="dropdown-item" href="form-type-name.php">Form Type &amp; Name</a></li>
            <li><a class="dropdown-item" href="approver-selection.php">Approver Selection</a></li>
            <li><a class="dropdown-item" href="submit-review.php">Submit for Review</a></li>
            <li><a class="dropdown-item" href="electronic-signature.php">Electronic Signature</a></li>
            <li><a class="dropdown-item" href="approver-comments.php">Approver Comments</a></li>
            <li><a class="dropdown-item" href="notifications.php">Notifications</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="audit-creation.php">Audit - Creation</a></li>
            <li><a class="dropdown-item" href="audit-approval.php">Audit - Approval</a></li>
            <li><a class="dropdown-item" href="audit-comments.php">Audit - Comments</a></li>
            <li><a class="dropdown-item" href="qa-admin.php">QA Admin</a></li>
            <li><a class="dropdown-item" href="employee-role.php">Employee Role</a></li>
            <li><a class="dropdown-item" href="super-admin.php">Super Admin</a></li>
            <li><a class="dropdown-item" href="user-management.php">User Management</a></li>
            <li><a class="dropdown-item" href="role-assignment.php">Role Assignment</a></li>
          </ul>
        </li>

        <li class="nav-item"><a class="nav-link" href="portal-select.php">Switch to User</a></li>
      </ul>
      <div class="d-flex align-items-center gap-3 ms-xl-3">
        <span class="navbar-text small"><?php echo e($currentUserName); ?></span>
        <a class="nav-link px-0" href="notifications.php">Notifications</a>
        <span class="navbar-text small">Profile</span>
      </div>
    </div>
  </div>
</nav>

<main class="app-shell">
  <div class="content-wrap px-4 py-4 mx-auto">
    <div class="mb-4">
      <h1 class="page-title mb-2">Form Builder</h1>
      <p class="page-subtitle mb-0">Design controlled quality forms with configurable fields and validation rules.</p>
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
      <div class="col-lg-8">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Form Builder Canvas</h2>
            <p class="card-subtitle mb-3">Create forms like Google Forms with controlled field definitions.</p>

            <div class="mb-3">
              <form method="get" class="row g-2">
                <div class="col-md-8">
                  <input type="text" name="form_id" class="form-control" value="<?php echo e($formId); ?>" placeholder="Enter form id">
                </div>
                <div class="col-md-4">
                  <button type="submit" class="btn btn-outline-primary w-100">Load Form</button>
                </div>
              </form>
            </div>

            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>Field Label</th>
                    <th>Type</th>
                    <th>Required</th>
                    <th>Options</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($fields)): ?>
                    <?php foreach ($fields as $field): ?>
                      <tr>
                        <td><?php echo e($field['field_label'] ?? '-'); ?></td>
                        <td><?php echo e($field['field_type'] ?? '-'); ?></td>
                        <td>
                          <?php if ((int)($field['is_required'] ?? 0) === 1): ?>
                            <span class="badge badge-soft-success">Required</span>
                          <?php else: ?>
                            <span class="badge badge-soft-secondary">Optional</span>
                          <?php endif; ?>
                        </td>
                        <td><?php echo e(trim((string)($field['field_options'] ?? '')) !== '' ? $field['field_options'] : '-'); ?></td>
                        <td class="d-flex gap-1 flex-wrap">
                          <a href="form-builder.php?form_id=<?php echo urlencode($formId); ?>&edit_id=<?php echo (int)$field['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                          <form method="post" onsubmit="return confirm('Delete this field?');" class="d-inline">
                            <input type="hidden" name="action" value="delete_field">
                            <input type="hidden" name="form_id" value="<?php echo e($formId); ?>">
                            <input type="hidden" name="field_id" value="<?php echo (int)$field['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-4">No fields added yet.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="d-flex gap-2 flex-wrap mt-2">
              <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#fieldBuilderPanel">Add Field</button>
              <button type="button" class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#previewPanel">Preview Form</button>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="save_form_draft">
                <input type="hidden" name="form_id" value="<?php echo e($formId); ?>">
                <button type="submit" class="btn btn-outline-secondary">Save Form Draft</button>
              </form>
            </div>

            <div class="collapse <?php echo ($editField['id'] > 0) ? 'show' : ''; ?> mt-4" id="fieldBuilderPanel">
              <div class="border rounded-4 p-3">
                <h5 class="mb-3"><?php echo $editField['id'] > 0 ? 'Edit Field' : 'Add Field'; ?></h5>
                <form method="post">
                  <input type="hidden" name="action" value="save_field">
                  <input type="hidden" name="form_id" value="<?php echo e($formId); ?>">
                  <input type="hidden" name="field_id" value="<?php echo (int)$editField['id']; ?>">

                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label">Field Label</label>
                      <input type="text" name="field_label" class="form-control" value="<?php echo e($editField['field_label']); ?>" required>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Type</label>
                      <select name="field_type" class="form-select" id="field_type_select" required>
                        <?php
                        $types = ['Text', 'Date', 'Dropdown', 'Checkbox', 'Signature'];
                        foreach ($types as $type):
                        ?>
                          <option value="<?php echo e($type); ?>" <?php echo $editField['field_type'] === $type ? 'selected' : ''; ?>>
                            <?php echo e($type); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-md-6">
                      <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="is_required" id="is_required" value="1" <?php echo ((int)$editField['is_required'] === 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_required">Required field</label>
                      </div>
                    </div>

                    <div class="col-md-12">
                      <label class="form-label">Options</label>
                      <input type="text" name="field_options" class="form-control" value="<?php echo e($editField['field_options']); ?>" placeholder="For Dropdown or Checkbox, enter comma separated values">
                    </div>
                  </div>

                  <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary"><?php echo $editField['id'] > 0 ? 'Update Field' : 'Save Field'; ?></button>
                    <a href="form-builder.php?form_id=<?php echo urlencode($formId); ?>" class="btn btn-outline-secondary">Cancel</a>
                  </div>
                </form>
              </div>
            </div>

            <div class="collapse mt-4" id="previewPanel">
              <div class="preview-box">
                <h5 class="mb-3">Form Preview</h5>

                <?php if (!empty($fields)): ?>
                  <form>
                    <div class="row g-3">
                      <?php foreach ($fields as $field): ?>
                        <?php
                          $label = (string)($field['field_label'] ?? '');
                          $type = strtolower((string)($field['field_type'] ?? 'text'));
                          $required = ((int)($field['is_required'] ?? 0) === 1);
                          $options = array_filter(array_map('trim', explode(',', (string)($field['field_options'] ?? ''))));
                        ?>
                        <div class="col-12">
                          <label class="form-label">
                            <?php echo e($label); ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                          </label>

                          <?php if ($type === 'text'): ?>
                            <input type="text" class="form-control" <?php echo $required ? 'required' : ''; ?>>

                          <?php elseif ($type === 'date'): ?>
                            <input type="date" class="form-control" <?php echo $required ? 'required' : ''; ?>>

                          <?php elseif ($type === 'dropdown'): ?>
                            <select class="form-select" <?php echo $required ? 'required' : ''; ?>>
                              <option value="">Select</option>
                              <?php foreach ($options as $opt): ?>
                                <option value="<?php echo e($opt); ?>"><?php echo e($opt); ?></option>
                              <?php endforeach; ?>
                            </select>

                          <?php elseif ($type === 'checkbox'): ?>
                            <div>
                              <?php if (!empty($options)): ?>
                                <?php foreach ($options as $i => $opt): ?>
                                  <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="chk_<?php echo md5($label . $i); ?>">
                                    <label class="form-check-label" for="chk_<?php echo md5($label . $i); ?>">
                                      <?php echo e($opt); ?>
                                    </label>
                                  </div>
                                <?php endforeach; ?>
                              <?php else: ?>
                                <div class="form-check">
                                  <input class="form-check-input" type="checkbox" id="chk_<?php echo md5($label); ?>">
                                  <label class="form-check-label" for="chk_<?php echo md5($label); ?>">
                                    Yes
                                  </label>
                                </div>
                              <?php endif; ?>
                            </div>

                          <?php elseif ($type === 'signature'): ?>
                            <input type="text" class="form-control" placeholder="Signature / Sign here" <?php echo $required ? 'required' : ''; ?>>

                          <?php else: ?>
                            <input type="text" class="form-control" <?php echo $required ? 'required' : ''; ?>>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </form>
                <?php else: ?>
                  <div class="text-muted">No fields available to preview.</div>
                <?php endif; ?>
              </div>
            </div>

          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card cp-card">
          <div class="card-body">
            <h2 class="card-title mb-1">Supported Field Types</h2>
            <p class="card-subtitle mb-3">Field configuration should remain fully traceable.</p>
            <ul class="small text-secondary note-list mb-0">
              <li>Text</li>
              <li>Date</li>
              <li>Dropdown</li>
              <li>Checkbox</li>
              <li>Signature</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>