<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Mpesa.php';

Auth::start();
Auth::requireLogin();

$importResult = null;
$errors = [];

// ── Step 2: Confirm and execute import ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {
    verifyCsrf();

    $token = $_POST['import_token'] ?? '';
    if (empty($token) || $token !== ($_SESSION['import_token'] ?? '')) {
        $errors[] = 'Invalid or expired upload session. Please re-upload your file.';
    } else {
        $filePath  = $_SESSION['import_file'] ?? '';
        $origName  = $_SESSION['import_original_name'] ?? 'unknown.csv';

        if (!$filePath || !file_exists($filePath)) {
            $errors[] = 'Uploaded file not found. Please re-upload.';
        } else {
            // Rebuild mapping from POST (field => col index)
            $mapping = [];
            $fields  = ['name', 'phone', 'email', 'account_number', 'group'];
            foreach ($fields as $f) {
                $v = $_POST['map_' . $f] ?? '';
                if ($v !== '') $mapping[$f] = (int)$v;
            }

            if (empty($mapping['name']) && !isset($mapping['name'])) {
                $errors[] = 'You must map the Name column.';
            }
            if (!isset($mapping['phone'])) {
                $errors[] = 'You must map the Phone column.';
            }

            if (empty($errors)) {
                $groupOverride  = trim($_POST['group_name']     ?? '');
                $updateExisting = !empty($_POST['update_existing']);
                $skipErrors     = !empty($_POST['skip_errors']);

                $parsed = parseCsvWithMapping($filePath, $mapping);

                if (!$skipErrors && !empty($parsed['errors'])) {
                    $errors = array_merge($errors, array_slice($parsed['errors'], 0, 20));
                    if (count($parsed['errors']) > 20) {
                        $errors[] = '… and ' . (count($parsed['errors']) - 20) . ' more row errors.';
                    }
                } else {
                    $imported = 0; $skipped = 0; $updated = 0; $errorCount = count($parsed['errors']);

                    // Pre-load all existing phone_formatted → id in one query (avoids N SELECT per row)
                    $existingRows = Database::fetchAll("SELECT id, phone_formatted FROM customers");
                    $existingMap  = array_column($existingRows, 'id', 'phone_formatted');

                    Database::beginTransaction();
                    try {
                        foreach ($parsed['rows'] as $row) {
                            $formatted = Mpesa::formatPhone($row['phone']);
                            $grp       = $groupOverride ?: ($row['group'] ?? null);

                            if (isset($existingMap[$formatted])) {
                                if ($updateExisting) {
                                    Database::update('customers', [
                                        'name'           => $row['name'],
                                        'email'          => $row['email']          ?: null,
                                        'account_number' => $row['account_number'] ?: null,
                                        'group_name'     => $grp ?: null,
                                        'status'         => 1,
                                    ], 'id = ?', [$existingMap[$formatted]]);
                                    $updated++;
                                } else {
                                    $skipped++;
                                }
                            } else {
                                Database::insert('customers', [
                                    'name'            => $row['name'],
                                    'phone'           => $row['phone'],
                                    'phone_formatted' => $formatted,
                                    'email'           => $row['email']          ?: null,
                                    'account_number'  => $row['account_number'] ?: null,
                                    'group_name'      => $grp ?: null,
                                    'status'          => 1,
                                ]);
                                $existingMap[$formatted] = true; // guard against duplicates within the same file
                                $imported++;
                            }
                        }

                        // Log this import
                        Database::insert('import_logs', [
                            'filename'    => $origName,
                            'total_rows'  => $imported + $skipped + $updated + $errorCount,
                            'imported'    => $imported,
                            'updated'     => $updated,
                            'skipped'     => $skipped,
                            'errors'      => $errorCount,
                            'group_name'  => $groupOverride ?: null,
                            'imported_by' => Auth::userId(),
                        ]);

                        Database::commit();

                        // Clean up temp file and session
                        @unlink($filePath);
                        unset($_SESSION['import_token'], $_SESSION['import_file'], $_SESSION['import_original_name']);

                        logActivity(Auth::userId(), 'customer_import', 'customers',
                            "Imported {$imported}, updated {$updated}, skipped {$skipped} from {$origName}");

                        $importResult = compact('imported', 'skipped', 'updated', 'errorCount', 'origName');
                        $importResult['parse_errors'] = $parsed['errors'];

                    } catch (Throwable $e) {
                        Database::rollback();
                        $errors[] = 'Database error: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// ── Load data ────────────────────────────────────────────────
$groups     = Database::fetchAll("SELECT name AS group_name, color FROM customer_groups ORDER BY name ASC");
$importHistory = Database::fetchAll("
    SELECT il.*, u.name AS imported_by_name
    FROM import_logs il
    LEFT JOIN admin_users u ON u.id = il.imported_by
    ORDER BY il.created_at DESC
    LIMIT 10
");

$pageTitle    = 'Import Customers';
$pageSubtitle = 'Customers &rsaquo; Import CSV';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="<?= APP_URL ?>/customers/index.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left"></i></a>
    <div>
      <h1><i class="fas fa-file-import" style="color:var(--secondary);margin-right:8px"></i>Import Customers</h1>
      <p>Upload a CSV file to bulk import — with column mapping and preview</p>
    </div>
  </div>
</div>

<!-- ── Import Result ───────────────────────────────────────── -->
<?php if ($importResult): ?>
  <div class="alert alert-success mb-3" style="align-items:flex-start">
    <i class="fas fa-check-circle" style="font-size:24px;margin-top:2px"></i>
    <div style="flex:1">
      <strong style="font-size:16px">Import Complete — <?= e($importResult['origName']) ?></strong>
      <div style="display:flex;gap:24px;margin-top:10px;flex-wrap:wrap">
        <div style="text-align:center">
          <div style="font-size:28px;font-weight:800;color:#15803D"><?= $importResult['imported'] ?></div>
          <div style="font-size:12px">New customers</div>
        </div>
        <div style="text-align:center">
          <div style="font-size:28px;font-weight:800;color:#0369A1"><?= $importResult['updated'] ?></div>
          <div style="font-size:12px">Updated</div>
        </div>
        <div style="text-align:center">
          <div style="font-size:28px;font-weight:800;color:#92400E"><?= $importResult['skipped'] ?></div>
          <div style="font-size:12px">Skipped (dup)</div>
        </div>
        <?php if ($importResult['errorCount']): ?>
        <div style="text-align:center">
          <div style="font-size:28px;font-weight:800;color:#DC2626"><?= $importResult['errorCount'] ?></div>
          <div style="font-size:12px">Row errors</div>
        </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($importResult['parse_errors'])): ?>
        <details style="margin-top:12px">
          <summary style="cursor:pointer;font-size:13px;color:#92400E">
            <?= count($importResult['parse_errors']) ?> rows skipped due to errors (click to expand)
          </summary>
          <ul style="margin-top:8px;padding-left:18px;font-size:12px;color:#92400E">
            <?php foreach (array_slice($importResult['parse_errors'], 0, 15) as $err): ?>
              <li><?= e($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
      <div style="margin-top:14px;display:flex;gap:10px">
        <a href="<?= APP_URL ?>/customers/index.php" class="btn btn-secondary btn-sm">
          <i class="fas fa-users"></i> View Customers
        </a>
        <a href="<?= APP_URL ?>/campaigns/create.php" class="btn btn-outline-primary btn-sm">
          <i class="fas fa-rocket"></i> Create Campaign
        </a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger mb-3">
    <i class="fas fa-exclamation-circle"></i>
    <div>
      <strong>Import Failed</strong>
      <ul style="margin-top:6px;padding-left:16px">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Step 1: Upload + Step 2: Map & Preview (driven by JS)      -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="grid-2" style="grid-template-columns:1.4fr 1fr;align-items:start">
<div>

  <!-- ── Step 1: Upload Card ─────────────────────────────── -->
  <div class="card mb-3" id="step1-card">
    <div class="card-header">
      <div class="card-title">
        <span class="step-badge">1</span> Upload CSV File
      </div>
      <span style="font-size:12px;color:var(--text-muted)">Max 5MB &mdash; CSV only</span>
    </div>
    <div class="card-body">

      <!-- Drop Zone -->
      <div class="upload-zone" id="upload-zone" onclick="document.getElementById('csv_file').click()">
        <div class="upload-icon"><i class="fas fa-file-csv" style="font-size:32px;color:var(--secondary)"></i></div>
        <p><strong>Click to browse</strong> or drag &amp; drop your CSV here</p>
        <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Accepts .csv files up to 5MB</p>
        <div id="file-name-display" style="display:none;margin-top:10px;font-weight:600;color:var(--primary);font-size:14px"></div>
      </div>
      <input type="file" id="csv_file" accept=".csv,.txt" style="display:none" onchange="onFileSelected(this)"/>

      <div id="upload-progress" style="display:none;margin-top:16px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <i class="fas fa-spinner fa-spin" style="color:var(--secondary)"></i>
          <span style="font-size:14px">Uploading and analysing…</span>
        </div>
        <div class="progress"><div class="progress-bar" id="upload-pbar" style="width:0%"></div></div>
      </div>

    </div>
  </div>

  <!-- ── Step 2: Column Mapping ──────────────────────────── -->
  <div class="card mb-3" id="step2-card" style="display:none">
    <div class="card-header">
      <div class="card-title">
        <span class="step-badge">2</span> Map Columns
        <span id="step2-filename" style="font-size:12px;font-weight:400;color:var(--text-muted);margin-left:8px"></span>
      </div>
      <button class="btn btn-light btn-sm" onclick="resetUpload()">
        <i class="fas fa-redo"></i> Change File
      </button>
    </div>
    <div class="card-body">

      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
        Match each field to the correct column in your CSV. Required fields are marked <span class="required">*</span>
      </p>

      <div id="mapping-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px"></div>

      <!-- Duplicate info -->
      <div id="duplicate-info" style="display:none;margin-top:14px"></div>

      <!-- Options -->
      <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
        <div class="form-group">
          <label class="form-label">Assign all to Group</label>
          <select name="group_name" id="import-group" class="form-select">
            <option value="">— Keep CSV value / No group —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= e($g['group_name']) ?>"><?= e($g['group_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">Overrides the group column in your CSV</div>
        </div>

        <div style="display:flex;flex-direction:column;gap:10px;margin-top:4px">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
            <input type="checkbox" id="opt-update" style="accent-color:var(--secondary);width:16px;height:16px"/>
            <div>
              <strong>Update existing customers</strong>
              <div style="font-size:12px;color:var(--text-muted)">Update name/email/group when phone already exists</div>
            </div>
          </label>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
            <input type="checkbox" id="opt-skip" style="accent-color:var(--secondary);width:16px;height:16px" checked/>
            <div>
              <strong>Skip invalid rows</strong>
              <div style="font-size:12px;color:var(--text-muted)">Continue even if some rows fail validation</div>
            </div>
          </label>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Step 3: Preview Table ───────────────────────────── -->
  <div class="card mb-3" id="step3-card" style="display:none">
    <div class="card-header">
      <div class="card-title">
        <span class="step-badge">3</span> Preview
        <span style="font-size:12px;font-weight:400;color:var(--text-muted);margin-left:8px">(first 10 rows)</span>
      </div>
      <span id="preview-total-badge" class="badge badge-primary"></span>
    </div>
    <div class="table-wrapper">
      <table class="table" id="preview-table">
        <thead id="preview-thead"></thead>
        <tbody  id="preview-tbody"></tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:flex-end">
      <form method="POST" action="" id="confirm-form">
        <input type="hidden" name="csrf_token"      value="<?= csrfToken() ?>"/>
        <input type="hidden" name="step"            value="confirm"/>
        <input type="hidden" name="import_token"    id="f-token"/>
        <input type="hidden" name="map_name"        id="f-map-name"/>
        <input type="hidden" name="map_phone"       id="f-map-phone"/>
        <input type="hidden" name="map_email"       id="f-map-email"/>
        <input type="hidden" name="map_account_number" id="f-map-account_number"/>
        <input type="hidden" name="map_group"       id="f-map-group"/>
        <input type="hidden" name="group_name"      id="f-group"/>
        <input type="hidden" name="update_existing" id="f-update"/>
        <input type="hidden" name="skip_errors"     id="f-skip"/>
        <button type="button" class="btn btn-secondary btn-lg" onclick="submitImport()">
          <i class="fas fa-file-import"></i> Confirm Import
          <span id="confirm-count"></span>
        </button>
      </form>
    </div>
  </div>

</div>

<!-- Right: Template + Format Guide + History -->
<div>
  <div class="card mb-3">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-download"></i> CSV Template</div>
    </div>
    <div class="card-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
        Download a ready-to-fill template with all supported columns.
      </p>
      <a href="<?= APP_URL ?>/assets/template.csv" download class="btn btn-outline-primary w-100 mb-2">
        <i class="fas fa-file-csv"></i> Download Template
      </a>
      <p style="font-size:11px;color:var(--text-muted);text-align:center">
        Column names auto-detected — no exact name needed
      </p>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-info-circle"></i> Accepted Column Names</div>
    </div>
    <div class="card-body p-0">
      <table style="width:100%;font-size:12px;border-collapse:collapse">
        <thead><tr style="background:var(--bg)">
          <th style="padding:8px 12px;text-align:left;border-bottom:1px solid var(--border)">Field</th>
          <th style="padding:8px 12px;text-align:left;border-bottom:1px solid var(--border)">Accepted headers</th>
        </tr></thead>
        <tbody>
          <?php foreach ([
            ['Name',           'badge-danger',   'name, full name, customer name'],
            ['Phone',          'badge-danger',   'phone, mobile, cell, telephone, msisdn'],
            ['Email',          'badge-secondary','email, e-mail, mail'],
            ['Account No.',    'badge-secondary','account, account_number, acc, ref'],
            ['Group',          'badge-secondary','group, category, segment, tier'],
          ] as [$f, $cls, $ex]): ?>
          <tr>
            <td style="padding:8px 12px;border-bottom:1px solid var(--border)">
              <strong><?= $f ?></strong>
              <span class="badge <?= $cls ?>" style="margin-left:4px;font-size:9px"><?= $cls === 'badge-danger' ? 'Required' : 'Optional' ?></span>
            </td>
            <td style="padding:8px 12px;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:11px"><?= $ex ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Import History -->
  <?php if (!empty($importHistory)): ?>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-history"></i> Import History</div>
    </div>
    <div class="card-body p-0">
      <table class="table" style="font-size:12px">
        <thead><tr>
          <th>File</th>
          <th style="text-align:center">New</th>
          <th style="text-align:center">Upd</th>
          <th style="text-align:center">Skip</th>
          <th>By</th>
          <th>When</th>
        </tr></thead>
        <tbody>
        <?php foreach ($importHistory as $h): ?>
          <tr>
            <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($h['filename']) ?>">
              <?= e($h['filename']) ?>
            </td>
            <td style="text-align:center;font-weight:700;color:var(--success)"><?= $h['imported'] ?></td>
            <td style="text-align:center;color:#0369A1"><?= $h['updated'] ?></td>
            <td style="text-align:center;color:var(--text-muted)"><?= $h['skipped'] ?></td>
            <td style="color:var(--text-muted)"><?= e($h['imported_by_name'] ?? '—') ?></td>
            <td style="color:var(--text-muted)"><?= timeAgo($h['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
</div><!-- /.grid-2 -->

<style>
.step-badge {
  display: inline-flex;
  width: 24px; height: 24px;
  border-radius: 50%;
  background: var(--secondary);
  color: #fff;
  font-size: 12px;
  font-weight: 800;
  align-items: center;
  justify-content: center;
  margin-right: 6px;
}
.map-select { border: 2px solid var(--border); border-radius: 8px; padding: 8px 10px; width: 100%; font-size: 13px; background: #fff; }
.map-select.required-field { border-color: var(--secondary); }
.map-select.unmapped { border-color: #DC2626; background: #FEF2F2; }
</style>

<script>
const APP_URL  = '<?= APP_URL ?>';
const CSRF     = '<?= csrfToken() ?>';
let csvData    = null; // holds server response after upload

// ── File selected ──────────────────────────────────────────
function onFileSelected(input) {
  const file = input.files[0];
  if (!file) return;
  document.getElementById('file-name-display').textContent = file.name;
  document.getElementById('file-name-display').style.display = 'block';
  uploadFile(file);
}

// ── Drag-and-drop ──────────────────────────────────────────
const zone = document.getElementById('upload-zone');
zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', e => {
  e.preventDefault();
  zone.classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  if (file) { document.getElementById('csv_file').files; onFileSelected({ files: [file] }); uploadFile(file); }
});

// ── Upload via fetch ───────────────────────────────────────
function uploadFile(file) {
  document.getElementById('upload-progress').style.display = 'block';
  document.getElementById('upload-zone').style.opacity = '0.5';

  // Simulate progress bar
  let prog = 0;
  const pbar = document.getElementById('upload-pbar');
  const progInterval = setInterval(() => {
    prog = Math.min(prog + 10, 85);
    pbar.style.width = prog + '%';
  }, 100);

  const fd = new FormData();
  fd.append('csv_file', file);

  fetch(APP_URL + '/api/preview_csv.php', {
    method: 'POST',
    credentials: 'same-origin',
    body: fd,
  })
  .then(r => r.json())
  .then(data => {
    clearInterval(progInterval);
    pbar.style.width = '100%';
    setTimeout(() => {
      document.getElementById('upload-progress').style.display = 'none';
      document.getElementById('upload-zone').style.opacity = '1';
      if (!data.success) {
        alert('Upload error: ' + data.message);
        resetUpload();
        return;
      }
      csvData = data;
      csvData.filename = file.name;
      renderStep2(data);
    }, 400);
  })
  .catch(err => {
    clearInterval(progInterval);
    alert('Upload failed. Please try again.');
    resetUpload();
  });
}

// ── Render step 2 (mapping) ────────────────────────────────
function renderStep2(data) {
  document.getElementById('step2-filename').textContent = data.filename || '';
  document.getElementById('step2-card').style.display = 'block';

  const grid = document.getElementById('mapping-grid');
  grid.innerHTML = '';

  const fields = [
    { key: 'name',           label: 'Name',           required: true  },
    { key: 'phone',          label: 'Phone',          required: true  },
    { key: 'email',          label: 'Email',          required: false },
    { key: 'account_number', label: 'Account No.',    required: false },
    { key: 'group',          label: 'Group',          required: false },
  ];

  fields.forEach(f => {
    const div = document.createElement('div');
    div.className = 'form-group';
    const autoIdx = data.auto_map[f.key] !== undefined ? data.auto_map[f.key] : '';

    const opts = data.headers.map((h, i) =>
      `<option value="${i}" ${autoIdx === i ? 'selected' : ''}>${h}</option>`
    ).join('');

    div.innerHTML = `
      <label class="form-label">${f.label} ${f.required ? '<span class="required">*</span>' : ''}</label>
      <select class="map-select ${f.required ? 'required-field' : ''}" id="map-${f.key}" onchange="rebuildPreview()">
        <option value="">— Not mapped —</option>
        ${opts}
      </select>`;
    grid.appendChild(div);
  });

  // Duplicate banner
  const dupDiv = document.getElementById('duplicate-info');
  if (data.duplicate_count > 0) {
    dupDiv.style.display = 'block';
    dupDiv.innerHTML = `<div class="alert alert-warning" style="font-size:13px">
      <i class="fas fa-exclamation-triangle"></i>
      <div><strong>${data.duplicate_count.toLocaleString()} phone number(s)</strong> already exist in your customers list.
      They will be skipped unless "Update existing" is checked.</div>
    </div>`;
  } else {
    dupDiv.style.display = 'none';
  }

  rebuildPreview();
}

// ── Rebuild preview table based on current mapping ────────
function rebuildPreview() {
  const data = csvData;
  if (!data) return;

  const fields = ['name', 'phone', 'email', 'account_number', 'group'];
  const mapping = {};
  fields.forEach(f => {
    const v = document.getElementById('map-' + f)?.value;
    if (v !== '' && v !== undefined) mapping[f] = parseInt(v);
  });

  // Validate required
  const nameOk  = mapping['name']  !== undefined;
  const phoneOk = mapping['phone'] !== undefined;
  document.getElementById('map-name').classList.toggle('unmapped', !nameOk);
  document.getElementById('map-phone').classList.toggle('unmapped', !phoneOk);

  if (!nameOk || !phoneOk) {
    document.getElementById('step3-card').style.display = 'none';
    return;
  }

  // Build preview table headers
  const mappedFields = Object.keys(mapping);
  const thead = document.getElementById('preview-thead');
  thead.innerHTML = '<tr>' + mappedFields.map(f =>
    `<th>${f.replace('_',' ')}</th>`
  ).join('') + '</tr>';

  // Build rows from sample_rows
  const tbody = document.getElementById('preview-tbody');
  tbody.innerHTML = '';
  data.sample_rows.forEach(row => {
    const tr = document.createElement('tr');
    mappedFields.forEach(f => {
      const td = document.createElement('td');
      td.style.fontSize = '13px';
      td.textContent = row[mapping[f]] ?? '';
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });

  document.getElementById('preview-total-badge').textContent =
    data.total_rows.toLocaleString() + ' rows total';
  document.getElementById('confirm-count').textContent =
    ' (' + data.total_rows.toLocaleString() + ' rows)';

  document.getElementById('step3-card').style.display = 'block';
}

// ── Submit confirm form ────────────────────────────────────
function submitImport() {
  if (!csvData) return;
  const fields = ['name', 'phone', 'email', 'account_number', 'group'];
  fields.forEach(f => {
    const v = document.getElementById('map-' + f)?.value ?? '';
    document.getElementById('f-map-' + f).value = v;
  });
  document.getElementById('f-token').value  = csvData.token;
  document.getElementById('f-group').value  = document.getElementById('import-group').value;
  document.getElementById('f-update').value = document.getElementById('opt-update').checked ? '1' : '';
  document.getElementById('f-skip').value   = document.getElementById('opt-skip').checked   ? '1' : '';

  // Quick validation
  if (!document.getElementById('f-map-name').value || !document.getElementById('f-map-phone').value) {
    alert('Please map both the Name and Phone columns before importing.');
    return;
  }
  document.getElementById('confirm-form').submit();
}

// ── Reset to step 1 ────────────────────────────────────────
function resetUpload() {
  document.getElementById('csv_file').value = '';
  document.getElementById('file-name-display').style.display = 'none';
  document.getElementById('upload-progress').style.display   = 'none';
  document.getElementById('upload-zone').style.opacity       = '1';
  document.getElementById('step2-card').style.display        = 'none';
  document.getElementById('step3-card').style.display        = 'none';
  csvData = null;
}
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
