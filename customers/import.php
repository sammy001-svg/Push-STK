<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Mpesa.php';

Auth::start();
Auth::requireLogin();

$importResult       = null;
$errors             = [];
$errorDownloadToken = null;

// ── Step 2: Confirm and execute import ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {
    @ini_set('max_execution_time', '300');
    @ini_set('memory_limit', '256M');
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

                // Helper: open CSV, strip BOM, skip header row
                $openCsv = function(string $path) {
                    $h = fopen($path, 'r');
                    $bom = fread($h, 3);
                    if ($bom !== "\xEF\xBB\xBF") rewind($h);
                    fgetcsv($h); // skip header
                    return $h;
                };

                $parseErrors = [];
                $errorRows   = [];

                // If "stop on errors" is checked, do a validation-only pass first
                if (!$skipErrors) {
                    $h      = $openCsv($filePath);
                    $lineNo = 1;
                    while (($data = fgetcsv($h)) !== false) {
                        $lineNo++;
                        $data = array_map('trim', $data);
                        $row  = [];
                        foreach ($mapping as $field => $colIdx) {
                            $row[$field] = $data[$colIdx] ?? '';
                        }
                        $rowErrs = validateCsvRow($row);
                        if ($rowErrs) {
                            $errMsg      = implode('; ', $rowErrs);
                            $parseErrors[] = "Row {$lineNo}: {$errMsg}";
                            $errorRows[]   = array_merge(['_row' => $lineNo, '_error' => $errMsg], $row);
                        }
                    }
                    fclose($h);

                    if (!empty($parseErrors)) {
                        $errors = array_merge($errors, array_slice($parseErrors, 0, 20));
                        if (count($parseErrors) > 20) {
                            $errors[] = '… and ' . (count($parseErrors) - 20) . ' more row errors.';
                        }
                    }
                }

                if (empty($errors)) {
                    $imported   = 0;
                    $skipped    = 0;
                    $updated    = 0;
                    $errorCount = 0;
                    $parseErrors = [];
                    $errorRows   = [];

                    // Pre-load all existing phone_formatted → id (one query, avoids N lookups)
                    $existingRows = Database::fetchAll("SELECT id, phone_formatted FROM customers");
                    $existingMap  = array_column($existingRows, 'id', 'phone_formatted');
                    unset($existingRows);

                    $h           = $openCsv($filePath);
                    $insertBatch = [];
                    $lineNo      = 1;

                    Database::beginTransaction();
                    try {
                        while (($data = fgetcsv($h)) !== false) {
                            $lineNo++;
                            $data = array_map('trim', $data);
                            $row  = [];
                            foreach ($mapping as $field => $colIdx) {
                                $row[$field] = $data[$colIdx] ?? '';
                            }

                            $rowErrs = validateCsvRow($row);
                            if ($rowErrs) {
                                if ($skipErrors) {
                                    $errMsg      = implode('; ', $rowErrs);
                                    $parseErrors[] = "Row {$lineNo}: {$errMsg}";
                                    $errorRows[]   = array_merge(['_row' => $lineNo, '_error' => $errMsg], $row);
                                    $errorCount++;
                                }
                                continue;
                            }

                            $formatted = Mpesa::formatPhone($row['phone']);
                            $grp       = $groupOverride ?: ($row['group'] ?? null);

                            if (isset($existingMap[$formatted])) {
                                $existingId = $existingMap[$formatted];
                                if ($updateExisting && is_int($existingId)) {
                                    Database::update('customers', [
                                        'name'           => $row['name'],
                                        'email'          => $row['email']          ?: null,
                                        'account_number' => $row['account_number'] ?: null,
                                        'group_name'     => $grp ?: null,
                                        'status'         => 1,
                                    ], 'id = ?', [$existingId]);
                                    $updated++;
                                } else {
                                    $skipped++;
                                }
                            } else {
                                $insertBatch[] = [
                                    'name'            => $row['name'],
                                    'phone'           => $row['phone'],
                                    'phone_formatted' => $formatted,
                                    'email'           => $row['email']          ?: null,
                                    'account_number'  => $row['account_number'] ?: null,
                                    'group_name'      => $grp ?: null,
                                    'status'          => 1,
                                ];
                                $existingMap[$formatted] = true; // intra-file duplicate guard
                                if (count($insertBatch) === 500) {
                                    Database::bulkInsert('customers', $insertBatch);
                                    $imported += 500;
                                    $insertBatch = [];
                                }
                            }
                        }
                        fclose($h);

                        // Flush remaining batch
                        if (!empty($insertBatch)) {
                            Database::bulkInsert('customers', $insertBatch);
                            $imported += count($insertBatch);
                            $insertBatch = [];
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
                        $importResult['parse_errors'] = $parseErrors;
                        $importResult['error_rows']   = $errorRows;

                        // Write downloadable CSV of failed rows
                        if (!empty($errorRows)) {
                            $errToken    = bin2hex(random_bytes(16));
                            $errFilePath = __DIR__ . '/../storage/imports/errors_' . $errToken . '.csv';
                            $ef = fopen($errFilePath, 'w');
                            if ($ef) {
                                fputcsv($ef, ['Row #', 'Name', 'Phone', 'Email', 'Account No.', 'Group', 'Error']);
                                foreach ($errorRows as $eRow) {
                                    fputcsv($ef, [
                                        $eRow['_row']            ?? '',
                                        $eRow['name']            ?? '',
                                        $eRow['phone']           ?? '',
                                        $eRow['email']           ?? '',
                                        $eRow['account_number']  ?? '',
                                        $eRow['group']           ?? '',
                                        $eRow['_error']          ?? '',
                                    ]);
                                }
                                fclose($ef);
                                $_SESSION['error_download_token'] = $errToken;
                                $_SESSION['error_download_file']  = $errFilePath;
                                $errorDownloadToken = $errToken;
                            }
                        }

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
      <?php if (!empty($importResult['error_rows'])): ?>
        <div style="margin-top:14px">
          <div style="font-size:13px;font-weight:700;color:#92400E;margin-bottom:6px">
            <i class="fas fa-exclamation-triangle"></i>
            <?= number_format(count($importResult['error_rows'])) ?> rows skipped — fix and re-import:
          </div>
          <div style="max-height:190px;overflow-y:auto;border-radius:6px;border:1px solid #FDE68A">
            <table style="width:100%;font-size:12px;border-collapse:collapse">
              <thead><tr style="background:#FFFBEB;position:sticky;top:0">
                <th style="padding:5px 8px;text-align:left">Row</th>
                <th style="padding:5px 8px;text-align:left">Name</th>
                <th style="padding:5px 8px;text-align:left">Phone</th>
                <th style="padding:5px 8px;text-align:left;color:#DC2626">Error</th>
              </tr></thead>
              <tbody>
              <?php foreach (array_slice($importResult['error_rows'], 0, 50) as $er): ?>
                <tr>
                  <td style="padding:4px 8px;font-weight:700;color:#92400E">#<?= $er['_row'] ?></td>
                  <td style="padding:4px 8px"><?= e($er['name'] ?? '—') ?></td>
                  <td style="padding:4px 8px;font-family:monospace"><?= e($er['phone'] ?? '—') ?></td>
                  <td style="padding:4px 8px;color:#DC2626"><?= e($er['_error'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (!empty($errorDownloadToken)): ?>
            <a href="<?= APP_URL ?>/api/download_import_errors.php?token=<?= urlencode($errorDownloadToken) ?>"
               class="btn btn-light btn-sm" style="margin-top:10px">
              <i class="fas fa-download"></i>
              Download All Failed Rows (<?= number_format(count($importResult['error_rows'])) ?>)
            </a>
          <?php endif; ?>
        </div>
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
      <span style="font-size:12px;color:var(--text-muted)">Max 20MB &mdash; CSV only</span>
    </div>
    <div class="card-body">

      <!-- Drop Zone -->
      <div class="upload-zone" id="upload-zone" onclick="document.getElementById('csv_file').click()">
        <div class="upload-icon"><i class="fas fa-file-csv" style="font-size:32px;color:var(--secondary)"></i></div>
        <p><strong>Click to browse</strong> or drag &amp; drop your CSV here</p>
        <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Accepts .csv files up to 20MB</p>
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
  .then(r => r.text())
  .then(text => {
    clearInterval(progInterval);
    pbar.style.width = '100%';

    let data;
    try { data = JSON.parse(text); }
    catch (e) {
      // Server returned a PHP error page instead of JSON
      document.getElementById('upload-progress').style.display = 'none';
      document.getElementById('upload-zone').style.opacity = '1';
      alert('Server error while processing the file.\n\nThis usually means the file is too large to process in one go or a timeout occurred. Try splitting your CSV into smaller batches (e.g. 50,000 rows each).');
      resetUpload();
      return;
    }

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
    document.getElementById('upload-progress').style.display = 'none';
    document.getElementById('upload-zone').style.opacity = '1';
    alert('Connection lost during upload. Please check your internet connection and try again.');
    resetUpload();
  });
}

// ── Phone format validation (client-side) ─────────────────
function isValidKenyanPhone(phone) {
  phone = String(phone).replace(/[\s\-\(\)\+]/g, '');
  return /^(254|0)(7[0-9]{8}|1[01][0-9]{7})$/.test(phone);
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

  // Validation summary banner (replaces simple duplicate count)
  renderValidationBanner(data);

  rebuildPreview();
}

function renderValidationBanner(data) {
  const dupDiv = document.getElementById('duplicate-info');
  const total  = data.total_rows     || 0;
  const valid  = data.valid_new_count || 0;
  const dup    = data.duplicate_count || 0;
  const inv    = data.invalid_count  || 0;

  if (total === 0) { dupDiv.style.display = 'none'; return; }

  const parts = [];
  if (valid  > 0) parts.push(`<span style="color:#15803D"><i class="fas fa-check-circle"></i> <strong>${valid.toLocaleString()}</strong> new valid</span>`);
  if (dup    > 0) parts.push(`<span style="color:#B45309"><i class="fas fa-copy"></i> <strong>${dup.toLocaleString()}</strong> duplicate</span>`);
  if (inv    > 0) parts.push(`<span style="color:#DC2626"><i class="fas fa-times-circle"></i> <strong>${inv.toLocaleString()}</strong> invalid phone</span>`);

  const cls  = inv > 0 ? 'alert-danger' : (dup > 0 ? 'alert-warning' : 'alert-success');
  const icon = inv > 0 ? 'fas fa-exclamation-circle' : (dup > 0 ? 'fas fa-exclamation-triangle' : 'fas fa-check-circle');
  dupDiv.style.display = 'block';
  dupDiv.innerHTML = `<div class="alert ${cls}" style="font-size:13px">
    <i class="${icon}"></i>
    <div>
      <div style="display:flex;gap:16px;flex-wrap:wrap">${parts.join('')}</div>
      ${dup > 0 ? '<div style="margin-top:4px;font-size:12px;opacity:.8">Duplicates will be skipped unless "Update existing" is checked.</div>' : ''}
      ${inv > 0 ? '<div style="margin-top:4px;font-size:12px;opacity:.8">Rows with invalid phone numbers will always be skipped.</div>' : ''}
    </div>
  </div>`;
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

  const phoneIdx     = mapping['phone'];
  const autoPhoneIdx = data.auto_map?.phone;
  const useServerStatus = (phoneIdx === autoPhoneIdx);

  // Build preview table headers (with Status column)
  const mappedFields = Object.keys(mapping);
  const thead = document.getElementById('preview-thead');
  thead.innerHTML = '<tr>'
    + mappedFields.map(f => `<th>${f.replace(/_/g,' ')}</th>`).join('')
    + '<th style="text-align:center;width:72px">Status</th></tr>';

  // Build rows with colour-coding
  const tbody = document.getElementById('preview-tbody');
  tbody.innerHTML = '';

  data.sample_rows.forEach(row => {
    const tr = document.createElement('tr');

    // Determine status for this row
    let status, hint;
    if (useServerStatus) {
      status = row['_status'] || 'unknown';
      hint   = row['_hint']   || '';
    } else {
      // Re-validate format in JS (no duplicate DB check when column changes)
      const phone = row[phoneIdx] ?? '';
      if (!phone || !isValidKenyanPhone(phone)) {
        status = 'invalid';
        hint   = phone ? 'Bad format: ' + phone : 'Missing phone';
      } else {
        status = 'valid';
        hint   = '';
      }
    }

    // Row background
    if      (status === 'valid')     tr.style.background = '#F0FDF4';
    else if (status === 'duplicate') tr.style.background = '#FFFBEB';
    else if (status === 'invalid')   tr.style.background = '#FEF2F2';

    mappedFields.forEach(f => {
      const td = document.createElement('td');
      td.style.fontSize = '13px';
      td.textContent = row[mapping[f]] ?? '';
      tr.appendChild(td);
    });

    // Status cell
    const statusTd = document.createElement('td');
    statusTd.style.textAlign = 'center';
    statusTd.title = hint;
    if (status === 'valid') {
      statusTd.innerHTML = '<span style="color:#15803D;font-size:12px;font-weight:700">✓ New</span>';
    } else if (status === 'duplicate') {
      statusTd.innerHTML = '<span style="color:#B45309;font-size:12px;font-weight:700">⚠ Dup</span>';
    } else if (status === 'invalid') {
      statusTd.innerHTML = '<span style="color:#DC2626;font-size:12px;font-weight:700">✗ Bad</span>';
    } else {
      statusTd.innerHTML = '<span style="color:#94A3B8;font-size:12px">—</span>';
    }
    tr.appendChild(statusTd);
    tbody.appendChild(tr);
  });

  // Validation summary above table
  const showingAll = data.sample_rows.length >= data.total_rows;
  let summaryEl = document.getElementById('preview-summary');
  if (!summaryEl) {
    summaryEl = document.createElement('div');
    summaryEl.id = 'preview-summary';
    document.getElementById('step3-card').querySelector('.card-header').insertAdjacentElement('afterend', summaryEl);
  }
  const valid  = data.valid_new_count || 0;
  const dup    = data.duplicate_count || 0;
  const inv    = data.invalid_count   || 0;
  summaryEl.innerHTML = `<div style="padding:8px 16px;border-bottom:1px solid var(--border);background:#F8FAFC;display:flex;gap:16px;flex-wrap:wrap;font-size:12px">
    <span style="color:#15803D"><strong>${valid.toLocaleString()}</strong> new</span>
    ${dup > 0 ? `<span style="color:#B45309"><strong>${dup.toLocaleString()}</strong> duplicate</span>` : ''}
    ${inv > 0 ? `<span style="color:#DC2626"><strong>${inv.toLocaleString()}</strong> invalid</span>` : ''}
    <span style="color:var(--text-muted);margin-left:auto">${showingAll ? 'All' : 'First ' + data.sample_rows.length + ' of ' + data.total_rows.toLocaleString()} rows shown</span>
  </div>`;

  document.getElementById('preview-total-badge').textContent =
    data.total_rows.toLocaleString() + ' rows total';
  document.getElementById('confirm-count').textContent =
    ' (' + valid.toLocaleString() + ' new)';

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
