<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check includes/db.php");
}

mysqli_set_charset($conn, 'utf8mb4');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
$currentRoleCode = (string)($_SESSION['role_code'] ?? '');
$currentRoleName = (string)($_SESSION['role_name'] ?? 'QA Admin');
$currentDisplayName = (string)($_SESSION['full_name'] ?? $_SESSION['admin_name'] ?? 'Profile');

if ($currentUserId <= 0) {
    header('Location: login-admin.php');
    exit;
}
if (!in_array($currentRoleCode, ['qa_admin', 'super_admin'], true)) {
    die('Access denied.');
}

$draftId = trim((string)($_GET['draft_id'] ?? $_POST['draft_id'] ?? 'new'));
$formId = 'document_draft_' . $draftId;
$documentTypeId = trim((string)($_GET['document_type_id'] ?? $_POST['document_type_id'] ?? ''));
$documentTopic = trim((string)($_GET['document_topic'] ?? $_POST['document_topic'] ?? ''));
$documentNumber = trim((string)($_GET['document_number'] ?? $_POST['document_number'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CorePlx Quality DMS - Form Builder</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .fb-palette-btn {
      display:flex;
      align-items:center;
      gap:8px;
      width:100%;
      padding:9px 14px;
      border:1px solid var(--cp-border, #dde3ec);
      border-radius:8px;
      background:var(--cp-surface, #f8f9fb);
      color:var(--cp-text, #1e2a3a);
      font-size:13px;
      font-weight:500;
      cursor:pointer;
      transition:background 0.15s, border-color 0.15s, transform 0.1s;
      text-align:left;
    }
    .fb-palette-btn:hover {
      background:#e8edf5;
      border-color:#b0bcce;
      transform:translateX(2px);
    }
    .fb-palette-btn .fb-icon {
      width:28px;
      height:28px;
      border-radius:6px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:14px;
      flex-shrink:0;
      background:#dde3ec;
    }
    .fb-field-row {
      display:flex;
      align-items:flex-start;
      gap:10px;
      padding:12px 14px;
      border:1px solid var(--cp-border, #dde3ec);
      border-radius:8px;
      background:#fff;
      margin-bottom:8px;
      transition:box-shadow 0.15s;
    }
    .fb-field-row:hover {
      box-shadow:0 2px 8px rgba(30,42,58,0.08);
    }
    .fb-drag-handle {
      color:#b0bcce;
      cursor:grab;
      font-size:16px;
      padding-top:2px;
      flex-shrink:0;
      user-select:none;
    }
    .fb-field-body {
      flex:1;
      min-width:0;
    }
    .fb-field-label-input {
      width:100%;
      border:none;
      border-bottom:1px solid #dde3ec;
      padding:2px 0 4px;
      font-size:13px;
      font-weight:600;
      color:var(--cp-text, #1e2a3a);
      background:transparent;
      outline:none;
    }
    .fb-field-label-input:focus {
      border-bottom-color:#2563eb;
    }
    .fb-field-type-badge {
      display:inline-block;
      font-size:11px;
      font-weight:600;
      padding:2px 8px;
      border-radius:20px;
      margin-top:4px;
      background:#e8edf5;
      color:#4a6080;
    }
    .fb-field-preview {
      margin-top:8px;
    }
    .fb-field-preview input[type=text],
    .fb-field-preview textarea,
    .fb-field-preview select {
      width:100%;
      padding:6px 10px;
      border:1px solid #dde3ec;
      border-radius:6px;
      font-size:12px;
      color:#aaa;
      background:#fafbfc;
      pointer-events:none;
    }
    .fb-field-preview .fb-sig-placeholder {
      border:1px dashed #b0bcce;
      border-radius:6px;
      padding:14px 10px;
      text-align:center;
      font-size:12px;
      color:#b0bcce;
      background:#fafbfc;
    }
    .fb-field-actions {
      display:flex;
      flex-direction:column;
      gap:4px;
      flex-shrink:0;
    }
    .fb-field-actions button {
      border:none;
      background:none;
      cursor:pointer;
      color:#b0bcce;
      padding:3px 5px;
      border-radius:4px;
      font-size:14px;
      transition:color 0.15s, background 0.15s;
    }
    .fb-field-actions button:hover { color:#dc2626; background:#fee2e2; }
    .fb-field-actions button.up-btn:hover,
    .fb-field-actions button.down-btn:hover { color:#2563eb; background:#dbeafe; }
    .fb-required-toggle {
      display:flex;
      align-items:center;
      gap:6px;
      margin-top:6px;
      font-size:12px;
      color:#6b7280;
    }
    .fb-empty-state {
      border:2px dashed #dde3ec;
      border-radius:10px;
      padding:40px 20px;
      text-align:center;
      color:#a0aab8;
    }
    .fb-section-row {
      border-left:4px solid #2563eb;
      background:#f0f4ff;
    }
    .preview-field-wrap { margin-bottom:16px; }
    .preview-field-wrap label { font-size:13px; font-weight:600; margin-bottom:4px; display:block; }
    .preview-field-wrap input,
    .preview-field-wrap select,
    .preview-field-wrap textarea {
      width:100%;
      padding:8px 12px;
      border:1px solid #dde3ec;
      border-radius:6px;
      font-size:13px;
    }
    .preview-field-wrap .sig-box {
      border:1px dashed #b0bcce;
      border-radius:6px;
      height:70px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:12px;
      color:#b0bcce;
      background:#fafbfc;
    }
    .preview-section-head {
      font-size:15px;
      font-weight:700;
      color:#1a3a6e;
      border-bottom:2px solid #2563eb;
      padding-bottom:4px;
      margin:20px 0 12px;
    }
  </style>
</head>
<body>

<?php include('includes/navbar.php'); ?>

<main class="app-shell">
<div class="content-wrap px-4 py-4 mx-auto">

  <nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="create-document.php?draft_id=<?php echo urlencode($draftId); ?>" class="text-decoration-none">Create Document</a></li>
      <li class="breadcrumb-item active">Form Builder</li>
    </ol>
  </nav>

  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-4">
    <div>
      <h1 class="page-title mb-1">Form / Checklist Builder</h1>
      <p class="page-subtitle mb-0">Build your form by adding fields from the left panel. Name it, then preview and confirm.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-outline-secondary" type="button"
              onclick="window.location.href='create-document.php?draft_id=<?php echo urlencode($draftId); ?>'">
        ← Back to Document
      </button>
      <button class="btn btn-outline-primary" type="button" onclick="openPreview()">
        Preview Form
      </button>
      <button class="btn btn-success" type="button" onclick="confirmAndReturn()">
        ✓ Confirm &amp; Attach to Document
      </button>
    </div>
  </div>

  <div class="card cp-card mb-3">
    <div class="card-body py-3">
      <div class="row g-3 align-items-start">
        <div class="col-md-5">
          <label class="form-label mb-1">Form / Checklist Name <span class="text-danger">*</span></label>
          <input class="form-control" id="fbFormName" type="text" placeholder="e.g. CAPA Verification Checklist">
          <div class="form-text" id="fbNameValidation">&nbsp;</div>
        </div>
        <div class="col-md-4">
          <label class="form-label mb-1">Form Type</label>
          <input class="form-control" id="fbFormType" type="text" placeholder="e.g. Checklist, Inspection Form">
          <div class="form-text">&nbsp;</div>
        </div>
        <div class="col-md-3">
          <label class="form-label mb-1">Description <span class="text-secondary fw-normal">(optional)</span></label>
          <input class="form-control" id="fbFormDesc" type="text" placeholder="Brief purpose">
          <div class="form-text">&nbsp;</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-3">
      <div class="card cp-card h-100">
        <div class="card-body">
          <h2 class="card-title mb-1">Add Field</h2>
          <p class="card-subtitle mb-3">Click to add to your form</p>
          <div class="d-flex flex-column gap-2">
            <button class="fb-palette-btn" type="button" onclick="addField('text')"><span class="fb-icon">T</span> Text Input</button>
            <button class="fb-palette-btn" type="button" onclick="addField('textarea')"><span class="fb-icon">¶</span> Text Area</button>
            <button class="fb-palette-btn" type="button" onclick="addField('number')"><span class="fb-icon">#</span> Number</button>
            <button class="fb-palette-btn" type="button" onclick="addField('date')"><span class="fb-icon">📅</span> Date</button>
            <button class="fb-palette-btn" type="button" onclick="addField('dropdown')"><span class="fb-icon">▾</span> Dropdown</button>
            <button class="fb-palette-btn" type="button" onclick="addField('checkbox')"><span class="fb-icon">☑</span> Checkbox</button>
            <button class="fb-palette-btn" type="button" onclick="addField('yesno')"><span class="fb-icon">?</span> Yes / No</button>
            <button class="fb-palette-btn" type="button" onclick="addField('signature')"><span class="fb-icon">✍</span> Signature</button>
            <hr class="my-1">
            <button class="fb-palette-btn" type="button" onclick="addField('section')" style="border-color:#2563eb;color:#2563eb;">
              <span class="fb-icon" style="background:#dbeafe;color:#2563eb;">§</span> Section Heading
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-9">
      <div class="card cp-card">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <h2 class="card-title mb-0">Form Canvas</h2>
              <p class="card-subtitle mb-0" id="fieldCountLabel">0 fields added</p>
            </div>
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="clearAll()" id="clearBtn" style="display:none;">
              Clear All
            </button>
          </div>

          <div class="fb-empty-state" id="emptyState">
            <div style="font-size:2.5rem;margin-bottom:8px;">📋</div>
            <div class="fw-semibold mb-1">No fields added yet</div>
            <div class="small">Click a field type on the left to add it here</div>
          </div>

          <div id="fieldCanvas"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mt-3 flex-wrap">
    <button class="btn btn-outline-secondary" type="button"
            onclick="window.location.href='create-document.php?draft_id=<?php echo urlencode($draftId); ?>'">
      ← Back without saving
    </button>
    <button class="btn btn-outline-primary" type="button" onclick="openPreview()">Preview Form</button>
    <button class="btn btn-success" type="button" onclick="confirmAndReturn()">
      ✓ Confirm &amp; Attach to Document
    </button>
  </div>

</div>
</main>

<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold" id="previewModalLabel">Form Preview</h5>
          <div class="small text-secondary" id="previewSubtitle">Review how your form will appear to users</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body px-4 py-4" id="previewModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close Preview</button>
        <button type="button" class="btn btn-success" data-bs-dismiss="modal" onclick="confirmAndReturn()">
          ✓ Confirm &amp; Attach to Document
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="dropdownOptionsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Dropdown Options</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-secondary mb-2">Enter each option on a new line</p>
        <textarea class="form-control" id="dropdownOptionsInput" rows="6" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary btn-sm" type="button" onclick="saveDropdownOptions()">Save Options</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var fields = [];
var fieldIdSeq = 0;
var editingDropdownFieldId = null;

var TYPE_META = {
  text:      { label: 'Text Input',      badge: 'Text'      },
  textarea:  { label: 'Text Area',       badge: 'Text Area' },
  number:    { label: 'Number',          badge: 'Number'    },
  date:      { label: 'Date',            badge: 'Date'      },
  dropdown:  { label: 'Dropdown',        badge: 'Dropdown'  },
  checkbox:  { label: 'Checkbox',        badge: 'Checkbox'  },
  yesno:     { label: 'Yes / No',        badge: 'Yes / No'  },
  signature: { label: 'Signature',       badge: 'Signature' },
  section:   { label: 'Section Heading', badge: 'Section'   }
};

(function() {
  var raw = sessionStorage.getItem('cpBuiltForm');
  if (!raw) return;

  try {
    var data = JSON.parse(raw);
    var currentDraftId = <?php echo json_encode($draftId); ?>;

    if ((data.draftId || 'new') !== currentDraftId) {
      return;
    }

    if (data.fields && data.fields.length) {
      fields = data.fields;
      fieldIdSeq = fields.reduce(function(m, f) {
        return Math.max(m, parseInt(f.id || 0, 10));
      }, 0) + 1;
    }

    if (data.formName) document.getElementById('fbFormName').value = data.formName;
    if (data.formType) document.getElementById('fbFormType').value = data.formType;
    if (data.formDesc) document.getElementById('fbFormDesc').value = data.formDesc;

    renderCanvas();
  } catch(e) {
    console.warn('Restore failed:', e);
  }
})();

function addField(type) {
  var meta = TYPE_META[type];
  var field = {
    id: ++fieldIdSeq,
    type: type,
    label: meta.label,
    required: (type !== 'section'),
    options: type === 'dropdown' ? ['Option 1', 'Option 2', 'Option 3'] : []
  };
  fields.push(field);
  renderCanvas();

  if (type === 'dropdown') {
    editingDropdownFieldId = field.id;
    document.getElementById('dropdownOptionsInput').value = field.options.join('\n');
    new bootstrap.Modal(document.getElementById('dropdownOptionsModal')).show();
  }
}

function removeField(id) {
  fields = fields.filter(function(f) { return f.id !== id; });
  renderCanvas();
}

function moveField(id, dir) {
  var idx = fields.findIndex(function(f) { return f.id === id; });
  if (idx < 0) return;
  var swapIdx = idx + dir;
  if (swapIdx < 0 || swapIdx >= fields.length) return;
  var tmp = fields[idx];
  fields[idx] = fields[swapIdx];
  fields[swapIdx] = tmp;
  renderCanvas();
}

function toggleRequired(id, checked) {
  var f = fields.find(function(x) { return x.id === id; });
  if (f) f.required = checked;
}

function updateLabel(id, val) {
  var f = fields.find(function(x) { return x.id === id; });
  if (f) f.label = val;
  updateFieldCount();
}

function openDropdownOptions(id) {
  var f = fields.find(function(x) { return x.id === id; });
  if (!f) return;
  editingDropdownFieldId = id;
  document.getElementById('dropdownOptionsInput').value = (f.options || []).join('\n');
  new bootstrap.Modal(document.getElementById('dropdownOptionsModal')).show();
}

function saveDropdownOptions() {
  var f = fields.find(function(x) { return x.id === editingDropdownFieldId; });
  if (!f) return;
  var raw = document.getElementById('dropdownOptionsInput').value;
  f.options = raw.split('\n').map(function(s) { return s.trim(); }).filter(Boolean);
  bootstrap.Modal.getInstance(document.getElementById('dropdownOptionsModal')).hide();
  renderCanvas();
}

function clearAll() {
  if (!confirm('Remove all fields?')) return;
  fields = [];
  renderCanvas();
}

function renderCanvas() {
  var canvas = document.getElementById('fieldCanvas');
  var empty = document.getElementById('emptyState');
  var clearBtn = document.getElementById('clearBtn');

  if (!fields.length) {
    canvas.innerHTML = '';
    empty.style.display = '';
    clearBtn.style.display = 'none';
    updateFieldCount();
    return;
  }

  empty.style.display = 'none';
  clearBtn.style.display = '';

  var html = '';
  fields.forEach(function(f, idx) {
    var isSection = f.type === 'section';
    html += '<div class="fb-field-row' + (isSection ? ' fb-section-row' : '') + '">';
    html += '<div class="fb-drag-handle">⠿</div>';
    html += '<div class="fb-field-body">';

    if (isSection) {
      html += '<input class="fb-field-label-input" style="font-size:15px;color:#1a3a6e;" value="' + escHtml(f.label) + '" placeholder="Section heading..." oninput="updateLabel(' + f.id + ', this.value)">';
      html += '<span class="fb-field-type-badge" style="background:#dbeafe;color:#1e40af;">Section</span>';
    } else {
      html += '<input class="fb-field-label-input" value="' + escHtml(f.label) + '" placeholder="Field label..." oninput="updateLabel(' + f.id + ', this.value)">';
      html += '<span class="fb-field-type-badge">' + TYPE_META[f.type].badge + '</span>';
      html += '<div class="fb-field-preview">';

      if (f.type === 'text' || f.type === 'number') {
        html += '<input type="text" placeholder="Field preview" disabled>';
      } else if (f.type === 'textarea') {
        html += '<textarea rows="2" placeholder="Field preview" disabled></textarea>';
      } else if (f.type === 'date') {
        html += '<input type="text" placeholder="DD / MM / YYYY" disabled>';
      } else if (f.type === 'dropdown') {
        html += '<select disabled>';
        (f.options || ['Option 1']).forEach(function(o) {
          html += '<option>' + escHtml(o) + '</option>';
        });
        html += '</select>';
        html += '<div class="mt-1"><a href="#" class="small text-primary" style="font-size:11px;" onclick="openDropdownOptions(' + f.id + ');return false;">Edit options (' + (f.options || []).length + ')</a></div>';
      } else if (f.type === 'checkbox') {
        html += '<div class="d-flex gap-2 align-items-center" style="font-size:13px;"><input type="checkbox" disabled class="form-check-input mt-0"> <span class="text-secondary">Checkbox field</span></div>';
      } else if (f.type === 'yesno') {
        html += '<div class="d-flex gap-3" style="font-size:13px;"><label><input type="radio" disabled class="form-check-input me-1"> Yes</label><label><input type="radio" disabled class="form-check-input me-1"> No</label></div>';
      } else if (f.type === 'signature') {
        html += '<div class="fb-sig-placeholder">✍ Signature block — user signs here</div>';
      }

      html += '</div>';
      html += '<div class="fb-required-toggle">';
      html += '<input type="checkbox" class="form-check-input" id="req_' + f.id + '" ' + (f.required ? 'checked' : '') + ' onchange="toggleRequired(' + f.id + ', this.checked)">';
      html += '<label for="req_' + f.id + '">Required field</label>';
      html += (f.required ? '<span class="text-danger ms-1" style="font-size:11px;">*</span>' : '');
      html += '</div>';
    }

    html += '</div>';
    html += '<div class="fb-field-actions">';
    if (idx > 0) html += '<button class="up-btn" type="button" onclick="moveField(' + f.id + ',-1)">↑</button>';
    if (idx < fields.length - 1) html += '<button class="down-btn" type="button" onclick="moveField(' + f.id + ',1)">↓</button>';
    html += '<button type="button" onclick="removeField(' + f.id + ')">✕</button>';
    html += '</div>';
    html += '</div>';
  });

  canvas.innerHTML = html;
  updateFieldCount();
}

function updateFieldCount() {
  var nonSection = fields.filter(function(f) { return f.type !== 'section'; }).length;
  var sections = fields.filter(function(f) { return f.type === 'section'; }).length;
  var parts = [];
  if (nonSection) parts.push(nonSection + ' field' + (nonSection !== 1 ? 's' : ''));
  if (sections) parts.push(sections + ' section' + (sections !== 1 ? 's' : ''));
  document.getElementById('fieldCountLabel').textContent = parts.length ? parts.join(', ') + ' added' : '0 fields added';
}

function openPreview() {
  var formName = document.getElementById('fbFormName').value.trim() || 'Untitled Form';
  document.getElementById('previewModalLabel').textContent = formName;
  document.getElementById('previewSubtitle').textContent =
    'Preview — ' + fields.filter(function(f){ return f.type !== 'section'; }).length + ' fields';

  var html = '';
  if (!fields.length) {
    html = '<div class="text-center text-secondary py-4">No fields added yet.</div>';
  } else {
    fields.forEach(function(f) {
      if (f.type === 'section') {
        html += '<div class="preview-section-head">' + escHtml(f.label) + '</div>';
        return;
      }

      html += '<div class="preview-field-wrap">';
      html += '<label>' + escHtml(f.label) + (f.required ? ' <span class="text-danger">*</span>' : '') + '</label>';

      if (f.type === 'text' || f.type === 'number') {
        html += '<input type="text" placeholder="Enter value">';
      } else if (f.type === 'textarea') {
        html += '<textarea rows="3" placeholder="Enter details"></textarea>';
      } else if (f.type === 'date') {
        html += '<input type="date">';
      } else if (f.type === 'dropdown') {
        html += '<select><option value="">-- Select --</option>';
        (f.options || []).forEach(function(o) {
          html += '<option>' + escHtml(o) + '</option>';
        });
        html += '</select>';
      } else if (f.type === 'checkbox') {
        html += '<div class="form-check"><input class="form-check-input" type="checkbox" id="pv_' + f.id + '"><label class="form-check-label" for="pv_' + f.id + '">' + escHtml(f.label) + '</label></div>';
      } else if (f.type === 'yesno') {
        html += '<div class="d-flex gap-4">';
        html += '<div class="form-check"><input class="form-check-input" type="radio" name="pvyn_' + f.id + '"><label class="form-check-label">Yes</label></div>';
        html += '<div class="form-check"><input class="form-check-input" type="radio" name="pvyn_' + f.id + '"><label class="form-check-label">No</label></div>';
        html += '</div>';
      } else if (f.type === 'signature') {
        html += '<div class="sig-box">✍ Sign here</div>';
      }

      html += '</div>';
    });
  }

  document.getElementById('previewModalBody').innerHTML = html;
  new bootstrap.Modal(document.getElementById('previewModal')).show();
}

document.getElementById('fbFormName').addEventListener('input', function() {
  var val = this.value.trim();
  var hint = document.getElementById('fbNameValidation');

  if (!val) {
    hint.textContent = 'Form name is required.';
    hint.className = 'form-text text-danger';
  } else {
    hint.textContent = '✓ Name looks good.';
    hint.className = 'form-text text-success';
  }
});

function confirmAndReturn() {
  var formName = document.getElementById('fbFormName').value.trim();
  if (!formName) {
    alert('Please enter a Form / Checklist Name before confirming.');
    document.getElementById('fbFormName').focus();
    return;
  }
  if (!fields.length) {
    alert('Please add at least one field to your form before confirming.');
    return;
  }

  var sections = [];
  fields.forEach(function(f) {
    if (f.type === 'section') sections.push(f);
  });

  var draftId = <?php echo json_encode($draftId); ?>;

  var data = {
    draftId: draftId,
    formId: <?php echo json_encode($formId); ?>,
    formName: formName,
    formType: document.getElementById('fbFormType').value.trim(),
    formDesc: document.getElementById('fbFormDesc').value.trim(),
    documentTypeId: <?php echo json_encode($documentTypeId); ?>,
    documentTopic: <?php echo json_encode($documentTopic); ?>,
    documentNumber: <?php echo json_encode($documentNumber); ?>,
    fields: fields,
    sections: sections,
    savedAt: new Date().toISOString()
  };

  sessionStorage.setItem('cpBuiltForm', JSON.stringify(data));
  window.location.href = 'create-document.php?draft_id=' + encodeURIComponent(draftId);
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

renderCanvas();
</script>
</body>
</html>