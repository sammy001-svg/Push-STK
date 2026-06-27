<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

// ── Edit / Clone detection ────────────────────────────────
$editId    = (int)($_GET['edit']  ?? 0);
$cloneId   = (int)($_GET['clone'] ?? 0);
$editMode  = ($editId  > 0);
$cloneMode = ($cloneId > 0);
$sourceId  = $editId ?: $cloneId;

$sourceCamp = null;
if ($sourceId) {
    $sourceCamp = Database::fetchOne("SELECT * FROM campaigns WHERE id = ?", [$sourceId]);
    if ($editMode) {
        if (!$sourceCamp) {
            flash('error', 'Campaign not found.');
            redirect(APP_URL . '/campaigns/index.php');
        }
        if ($sourceCamp['status'] !== 'draft') {
            flash('error', 'Only draft campaigns can be edited.');
            redirect(APP_URL . '/campaigns/view.php?id=' . $editId);
        }
    }
}

$errors = [];
$data   = [
    'name'             => '',
    'description'      => '',
    'amount'           => '',
    'account_ref'      => '',
    'transaction_desc' => 'STK Push Payment',
    'recipient_type'   => 'all',
    'group_name'       => '',
    'custom_phones'    => '',
    'send_timing'      => 'now',
    'scheduled_at'     => '',
];

// Pre-fill form from source campaign on GET requests
if ($sourceCamp && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $data = [
        'name'             => $sourceCamp['name'] . ($cloneMode ? ' (Copy)' : ''),
        'description'      => $sourceCamp['description'] ?? '',
        'amount'           => $sourceCamp['amount'],
        'account_ref'      => $sourceCamp['account_ref'],
        'transaction_desc' => $sourceCamp['transaction_desc'],
        'recipient_type'   => 'all',
        'group_name'       => '',
        'custom_phones'    => '',
        'send_timing'      => ($editMode && $sourceCamp['scheduled_at']) ? 'scheduled' : 'now',
        'scheduled_at'     => $editMode ? ($sourceCamp['scheduled_at'] ?? '') : '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postEditId = (int)($_POST['edit_id'] ?? 0);
    $editMode   = ($postEditId > 0);

    $data = [
        'name'             => trim($_POST['name']             ?? ''),
        'description'      => trim($_POST['description']      ?? ''),
        'amount'           => trim($_POST['amount']           ?? ''),
        'account_ref'      => trim($_POST['account_ref']      ?? ''),
        'transaction_desc' => trim($_POST['transaction_desc'] ?? 'STK Push Payment'),
        'recipient_type'   => $_POST['recipient_type']        ?? 'all',
        'group_name'       => trim($_POST['group_name']       ?? ''),
        'custom_phones'    => trim($_POST['custom_phones']    ?? ''),
        'send_timing'      => $_POST['send_timing']           ?? 'now',
        'scheduled_at'     => trim($_POST['scheduled_at']     ?? ''),
    ];

    // Extra validation for edit mode
    if ($editMode) {
        $editTarget = Database::fetchOne("SELECT id, status FROM campaigns WHERE id = ?", [$postEditId]);
        if (!$editTarget || $editTarget['status'] !== 'draft') {
            $errors[] = 'Campaign not found or is no longer a draft.';
        }
    }

    // Validation
    if (!$data['name'])   $errors[] = 'Campaign name is required.';
    if (!$data['amount']) {
        $errors[] = 'Amount is required.';
    } elseif (!is_numeric($data['amount']) || (float)$data['amount'] < 1) {
        $errors[] = 'Amount must be at least KES 1.';
    } elseif ((float)$data['amount'] > 150000) {
        $errors[] = 'Amount cannot exceed KES 150,000 (M-Pesa transaction limit).';
    }
    if (!$data['account_ref']) {
        $errors[] = 'Account reference is required.';
    } elseif (strlen($data['account_ref']) > 12) {
        $errors[] = 'Account reference must be 12 characters or less.';
    } elseif (!preg_match('/^[A-Za-z0-9\-_ ]+$/', $data['account_ref'])) {
        $errors[] = 'Account reference may only contain letters, numbers, hyphens, and spaces.';
    }
    if (strlen($data['transaction_desc']) > 13) {
        $errors[] = 'Transaction description must be 13 characters or less.';
    } elseif ($data['transaction_desc'] && !preg_match('/^[A-Za-z0-9\-_ ]+$/', $data['transaction_desc'])) {
        $errors[] = 'Transaction description may only contain letters, numbers, hyphens, and spaces.';
    }

    // Schedule validation
    $scheduledAt = null;
    $campaignStatus = 'draft';
    if ($data['send_timing'] === 'scheduled') {
        if (empty($data['scheduled_at'])) {
            $errors[] = 'Please select a date and time for the scheduled send.';
        } else {
            $ts = strtotime($data['scheduled_at']);
            if (!$ts || $ts <= time()) {
                $errors[] = 'Scheduled time must be in the future.';
            } else {
                $scheduledAt    = date('Y-m-d H:i:s', $ts);
                $campaignStatus = 'scheduled';
            }
        }
    }

    // Resolve recipients
    $recipientIds = [];

    if ($data['recipient_type'] === 'all') {
        $rows = Database::fetchAll("SELECT id FROM customers WHERE status = 1");
        $recipientIds = array_column($rows, 'id');
    } elseif ($data['recipient_type'] === 'group' && $data['group_name']) {
        $rows = Database::fetchAll("SELECT id FROM customers WHERE group_name = ? AND status = 1", [$data['group_name']]);
        $recipientIds = array_column($rows, 'id');
    } elseif ($data['recipient_type'] === 'custom' && $data['custom_phones']) {
        require_once __DIR__ . '/../includes/Mpesa.php';
        $phones = preg_split('/[\s,;\n]+/', $data['custom_phones'], -1, PREG_SPLIT_NO_EMPTY);
        foreach ($phones as $phone) {
            if (Mpesa::isValidPhone($phone)) {
                $formatted = Mpesa::formatPhone($phone);
                $cust = Database::fetchOne("SELECT id FROM customers WHERE phone_formatted = ? AND status = 1", [$formatted]);
                if ($cust) {
                    $recipientIds[] = $cust['id'];
                }
            }
        }
        $recipientIds = array_unique($recipientIds);
    }

    if (empty($recipientIds)) {
        $errors[] = 'No valid recipients found for the selected criteria.';
    } elseif (count($recipientIds) > 1000) {
        // Limit to first 1000 for safety
        $recipientIds = array_slice($recipientIds, 0, 1000);
    }

    if (empty($errors)) {
        Database::beginTransaction();
        try {
            $amount   = (float)$data['amount'];
            $recCount = count($recipientIds);

            if ($editMode) {
                // ── Update existing draft ─────────────────────────────
                Database::update('campaigns', [
                    'name'             => $data['name'],
                    'description'      => $data['description'] ?: null,
                    'amount'           => $amount,
                    'account_ref'      => $data['account_ref'],
                    'transaction_desc' => $data['transaction_desc'],
                    'scheduled_at'     => $scheduledAt,
                    'total_recipients' => $recCount,
                    'pending_count'    => $recCount,
                    'total_amount'     => $amount * $recCount,
                    'status'           => $campaignStatus,
                ], 'id = ?', [$postEditId]);

                // Replace recipient list
                Database::query("DELETE FROM campaign_recipients WHERE campaign_id = ?", [$postEditId]);

                foreach (array_chunk($recipientIds, 500) as $chunk) {
                    $customers = Database::fetchAll(
                        "SELECT id, phone_formatted FROM customers WHERE id IN (" . implode(',', $chunk) . ")"
                    );
                    $rows = [];
                    foreach ($customers as $cust) {
                        $rows[] = [
                            'campaign_id' => $postEditId,
                            'customer_id' => $cust['id'],
                            'phone'       => $cust['phone_formatted'],
                            'amount'      => $amount,
                            'status'      => 'pending',
                        ];
                    }
                    Database::bulkInsert('campaign_recipients', $rows);
                }

                Database::commit();
                $scheduleNote = $scheduledAt ? " Scheduled for " . date('D j M Y, g:ia', strtotime($scheduledAt)) . "." : " Ready to launch!";
                logActivity(Auth::userId(), 'campaign_edit', 'campaigns',
                    "Updated draft campaign '{$data['name']}' — {$recCount} recipients");
                flash('success', "Campaign updated with {$recCount} recipients.{$scheduleNote}");
                redirect(APP_URL . '/campaigns/view.php?id=' . $postEditId);

            } else {
                // ── Create new campaign ───────────────────────────────
                $campaignId = Database::insert('campaigns', [
                    'name'             => $data['name'],
                    'description'      => $data['description'] ?: null,
                    'amount'           => $amount,
                    'account_ref'      => $data['account_ref'],
                    'transaction_desc' => $data['transaction_desc'],
                    'scheduled_at'     => $scheduledAt,
                    'total_recipients' => $recCount,
                    'pending_count'    => $recCount,
                    'total_amount'     => $amount * $recCount,
                    'status'           => $campaignStatus,
                    'created_by'       => Auth::userId(),
                ]);

                foreach (array_chunk($recipientIds, 500) as $chunk) {
                    $customers = Database::fetchAll(
                        "SELECT id, phone_formatted FROM customers WHERE id IN (" . implode(',', $chunk) . ")"
                    );
                    $rows = [];
                    foreach ($customers as $cust) {
                        $rows[] = [
                            'campaign_id' => $campaignId,
                            'customer_id' => $cust['id'],
                            'phone'       => $cust['phone_formatted'],
                            'amount'      => $amount,
                            'status'      => 'pending',
                        ];
                    }
                    Database::bulkInsert('campaign_recipients', $rows);
                }

                Database::commit();
                $scheduleNote = $scheduledAt ? " Scheduled for " . date('D j M Y, g:ia', strtotime($scheduledAt)) . "." : " Ready to launch!";
                logActivity(Auth::userId(), 'campaign_create', 'campaigns',
                    "Created campaign '{$data['name']}' with {$recCount} recipients" . ($scheduledAt ? "; scheduled for {$scheduledAt}" : ''));
                flash('success', "Campaign '{$data['name']}' created with {$recCount} recipients.{$scheduleNote}");
                redirect(APP_URL . '/campaigns/view.php?id=' . $campaignId);
            }

        } catch (Throwable $e) {
            Database::rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$groups         = Database::fetchAll("SELECT g.name AS group_name, COUNT(c.id) AS cnt FROM customer_groups g LEFT JOIN customers c ON c.group_name = g.name AND c.status = 1 GROUP BY g.id ORDER BY g.name");
$totalCustomers = Database::count("SELECT COUNT(*) FROM customers WHERE status = 1");
$templates      = Database::fetchAll("SELECT * FROM campaign_templates ORDER BY name ASC");

// Pre-fill from template on GET (only when not already in clone/edit mode)
$templateId = (int)($_GET['template_id'] ?? 0);
if ($templateId && !$sourceId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $tpl = Database::fetchOne("SELECT * FROM campaign_templates WHERE id = ?", [$templateId]);
    if ($tpl) {
        $data['amount']           = $tpl['amount'];
        $data['account_ref']      = $tpl['account_ref'];
        $data['transaction_desc'] = $tpl['transaction_desc'];
        $data['description']      = $tpl['description'] ?? '';
    }
}

if ($editMode)       { $pageTitle = 'Edit Campaign';  $pageSubtitle = 'Campaigns &rsaquo; Edit'; }
elseif ($cloneMode)  { $pageTitle = 'Clone Campaign'; $pageSubtitle = 'Campaigns &rsaquo; Clone'; }
else                 { $pageTitle = 'New Campaign';   $pageSubtitle = 'Campaigns &rsaquo; Create'; }
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="<?= $editMode || $cloneMode ? APP_URL . '/campaigns/view.php?id=' . $sourceId : APP_URL . '/campaigns/index.php' ?>"
       class="btn btn-light btn-sm"><i class="fas fa-arrow-left"></i></a>
    <div>
      <?php if ($editMode): ?>
        <h1><i class="fas fa-edit" style="color:var(--secondary);margin-right:8px"></i>Edit Draft Campaign</h1>
        <p>Update settings for <strong><?= e($sourceCamp['name']) ?></strong> &mdash; <?= number_format($sourceCamp['total_recipients']) ?> current recipients</p>
      <?php elseif ($cloneMode): ?>
        <h1><i class="fas fa-copy" style="color:var(--secondary);margin-right:8px"></i>Clone Campaign</h1>
        <p>Pre-filled from <strong><?= e($sourceCamp['name']) ?></strong> &mdash; choose recipients to create a new draft</p>
      <?php else: ?>
        <h1><i class="fas fa-rocket" style="color:var(--secondary);margin-right:8px"></i>Create Campaign</h1>
        <p>Set up a new bulk STK push campaign</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger mb-3">
    <i class="fas fa-exclamation-circle"></i>
    <div><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
  </div>
<?php endif; ?>

<form method="POST" action="">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
  <?php if ($editMode): ?>
    <input type="hidden" name="edit_id" value="<?= $editId ?>"/>
  <?php endif; ?>
  <?php if (!empty($templates) && !$editMode): ?>
  <!-- ─── Template Picker ───────────────────────────────────── -->
  <div class="card mb-3" style="padding:14px 20px">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <span style="font-size:13px;font-weight:600;color:var(--text-muted);white-space:nowrap">
        <i class="fas fa-layer-group" style="color:var(--secondary)"></i> Load template:
      </span>
      <div style="display:flex;gap:8px;flex-wrap:wrap;flex:1">
        <?php foreach ($templates as $t): ?>
          <button type="button"
                  class="btn btn-sm <?= $templateId === (int)$t['id'] ? 'btn-secondary' : 'btn-light' ?>"
                  title="KES <?= number_format((float)$t['amount'], 2) ?> · <?= e($t['account_ref']) ?>"
                  onclick="applyTemplate(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)">
            <?= e($t['name']) ?>
          </button>
        <?php endforeach; ?>
      </div>
      <a href="<?= APP_URL ?>/campaigns/templates.php" class="btn btn-sm btn-light" style="white-space:nowrap">
        <i class="fas fa-cog"></i> Manage
      </a>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid-2" style="grid-template-columns:1.3fr 1fr;align-items:start">

    <!-- Left: Campaign Details -->
    <div>
      <div class="card mb-3">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-info-circle"></i> Campaign Details</div>
        </div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Campaign Name <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="e.g. June Loan Repayment" value="<?= e($data['name']) ?>" required/>
          </div>

          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Optional campaign notes…"><?= e($data['description']) ?></textarea>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Amount (KES) <span class="required">*</span></label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="amount" class="form-control" placeholder="0.00" min="1" step="0.01" value="<?= e($data['amount']) ?>" required
                       oninput="updatePreview()"/>
              </div>
              <div class="form-hint">Amount to request from each recipient</div>
            </div>
            <div class="form-group">
              <label class="form-label">Account Reference <span class="required">*</span></label>
              <input type="text" name="account_ref" class="form-control" placeholder="e.g. LOAN001" maxlength="12"
                     value="<?= e($data['account_ref']) ?>" required oninput="this.value=this.value.slice(0,12)"/>
              <div class="form-hint">Max 12 characters — appears on customer receipt</div>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Transaction Description <span class="required">*</span></label>
            <input type="text" name="transaction_desc" class="form-control" placeholder="e.g. Payment" maxlength="13"
                   value="<?= e($data['transaction_desc']) ?>" oninput="this.value=this.value.slice(0,13)"/>
            <div class="form-hint">Max 13 characters</div>
          </div>
        </div>
      </div>

      <!-- Recipients -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-users"></i> Select Recipients</div>
          <span style="font-size:13px;color:var(--text-muted)"><?= number_format($totalCustomers) ?> available</span>
        </div>
        <div class="card-body">
          <!-- Recipient type selector -->
          <div style="display:flex;gap:10px;margin-bottom:20px">
            <?php foreach ([
              ['all',    'fas fa-users',      'All Customers',    "All {$totalCustomers} active"],
              ['group',  'fas fa-layer-group', 'By Group',        'Target a specific group'],
              ['custom', 'fas fa-list-ol',    'Custom List',      'Enter phone numbers manually'],
            ] as [$val, $icon, $label, $desc]): ?>
              <label style="flex:1;cursor:pointer">
                <input type="radio" name="recipient_type" value="<?= $val ?>" style="display:none"
                       onchange="toggleRecipientType('<?= $val ?>')"
                       <?= $data['recipient_type'] === $val ? 'checked' : '' ?>/>
                <div class="recipient-type-card" id="rtype-<?= $val ?>" style="border:2px solid var(--border);border-radius:10px;padding:14px;text-align:center;transition:all 0.2s;<?= $data['recipient_type'] === $val ? 'border-color:var(--secondary);background:rgba(0,166,81,0.05)' : '' ?>">
                  <i class="<?= $icon ?>" style="font-size:20px;color:var(--secondary);margin-bottom:6px;display:block"></i>
                  <div style="font-weight:700;font-size:13px"><?= $label ?></div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:3px"><?= $desc ?></div>
                </div>
              </label>
            <?php endforeach; ?>
          </div>

          <!-- Group selector -->
          <div id="group-selector" style="display:<?= $data['recipient_type'] === 'group' ? 'block' : 'none' ?>">
            <div class="form-group">
              <label class="form-label">Select Group</label>
              <select name="group_name" class="form-select" onchange="updateGroupCount(this)">
                <option value="">— Choose a group —</option>
                <?php foreach ($groups as $g): ?>
                  <option value="<?= e($g['group_name']) ?>" data-count="<?= $g['cnt'] ?>"
                          <?= $data['group_name'] === $g['group_name'] ? 'selected' : '' ?>>
                    <?= e($g['group_name']) ?> (<?= $g['cnt'] ?> customers)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Custom phone list -->
          <div id="custom-selector" style="display:<?= $data['recipient_type'] === 'custom' ? 'block' : 'none' ?>">
            <div class="form-group">
              <label class="form-label">Phone Numbers</label>
              <textarea name="custom_phones" class="form-control" rows="6"
                        placeholder="Enter phone numbers, one per line or comma-separated:&#10;0712345678&#10;0723456789&#10;0734567890"><?= e($data['custom_phones']) ?></textarea>
              <div class="form-hint">Phones must already exist in the customers list. Only registered customers will receive pushes.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: Summary -->
    <div>
      <div class="card" style="position:sticky;top:80px">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-calculator"></i> Campaign Summary</div>
        </div>
        <div class="card-body">
          <div id="campaign-preview" style="font-size:14px">
            <div style="background:var(--bg);border-radius:10px;padding:18px;margin-bottom:18px">
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px">Per Recipient</div>
              <div style="font-size:32px;font-weight:800;color:var(--primary)" id="preview-amount">KES 0.00</div>
            </div>

            <div style="display:flex;flex-direction:column;gap:10px">
              <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)">
                <span style="color:var(--text-muted)">Recipient Type</span>
                <span id="preview-type" style="font-weight:600">All Customers</span>
              </div>
              <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)">
                <span style="color:var(--text-muted)">Est. Recipients</span>
                <span id="preview-count" style="font-weight:700;font-size:18px;color:var(--primary)"><?= number_format($totalCustomers) ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)">
                <span style="color:var(--text-muted)">Total Amount</span>
                <span id="preview-total" style="font-weight:700;font-size:16px;color:var(--success)">KES 0.00</span>
              </div>
              <div style="display:flex;justify-content:space-between;padding:10px 0">
                <span style="color:var(--text-muted)">Est. Duration</span>
                <span id="preview-duration" style="font-weight:600">~0 min</span>
              </div>
            </div>
          </div>

          <div class="alert alert-info mt-3" style="font-size:12px">
            <i class="fas fa-info-circle"></i>
            <div>
              Bulk sends are processed in batches of <strong><?= BATCH_SIZE ?></strong> with rate limiting to comply with Safaricom's API limits.
            </div>
          </div>

          <!-- Send Timing -->
          <div style="border:1px solid var(--border);border-radius:10px;padding:16px;margin-top:16px">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:12px">
              <i class="fas fa-clock"></i> Send Timing
            </div>
            <div style="display:flex;gap:8px;margin-bottom:12px">
              <label style="flex:1;cursor:pointer">
                <input type="radio" name="send_timing" value="now" style="display:none"
                       onchange="toggleSchedule(this.value)"
                       <?= ($data['send_timing'] ?? 'now') === 'now' ? 'checked' : '' ?>/>
                <div class="timing-opt" id="timing-now" style="border:2px solid <?= ($data['send_timing'] ?? 'now') === 'now' ? 'var(--secondary)' : 'var(--border)' ?>;background:<?= ($data['send_timing'] ?? 'now') === 'now' ? 'rgba(0,166,81,0.05)' : '' ?>;border-radius:8px;padding:10px;text-align:center;transition:all 0.15s">
                  <i class="fas fa-rocket" style="color:var(--secondary);font-size:18px;display:block;margin-bottom:4px"></i>
                  <div style="font-size:12px;font-weight:700">Send Now</div>
                  <div style="font-size:10px;color:var(--text-muted)">Launch as draft</div>
                </div>
              </label>
              <label style="flex:1;cursor:pointer">
                <input type="radio" name="send_timing" value="scheduled" style="display:none"
                       onchange="toggleSchedule(this.value)"
                       <?= ($data['send_timing'] ?? 'now') === 'scheduled' ? 'checked' : '' ?>/>
                <div class="timing-opt" id="timing-scheduled" style="border:2px solid <?= ($data['send_timing'] ?? 'now') === 'scheduled' ? 'var(--secondary)' : 'var(--border)' ?>;background:<?= ($data['send_timing'] ?? 'now') === 'scheduled' ? 'rgba(0,166,81,0.05)' : '' ?>;border-radius:8px;padding:10px;text-align:center;transition:all 0.15s">
                  <i class="fas fa-calendar-alt" style="color:#6D28D9;font-size:18px;display:block;margin-bottom:4px"></i>
                  <div style="font-size:12px;font-weight:700">Schedule</div>
                  <div style="font-size:10px;color:var(--text-muted)">Auto-launch later</div>
                </div>
              </label>
            </div>
            <div id="schedule-picker" style="display:<?= ($data['send_timing'] ?? 'now') === 'scheduled' ? 'block' : 'none' ?>">
              <input type="datetime-local" name="scheduled_at" class="form-control"
                     min="<?= date('Y-m-d\TH:i', strtotime('+5 minutes')) ?>"
                     value="<?= e($data['scheduled_at']) ?>"/>
              <div class="form-hint">Campaign will auto-launch at this time.</div>
            </div>
          </div>

          <button type="submit" id="submit-btn" class="btn btn-secondary btn-xl w-100 mt-3" style="justify-content:center"
                  data-baselabel="<?= $editMode ? 'Update Campaign' : ($cloneMode ? 'Clone Campaign' : 'Create Campaign') ?>">
            <i class="fas fa-check" id="submit-icon"></i>
            <span id="submit-label">
              <?php if ($editMode): ?>Update Campaign<?php elseif ($cloneMode): ?>Clone Campaign<?php else: ?>Create Campaign<?php endif; ?>
            </span>
          </button>
          <a href="<?= $editMode || $cloneMode ? APP_URL . '/campaigns/view.php?id=' . $sourceId : APP_URL . '/campaigns/index.php' ?>"
             class="btn btn-light w-100 mt-2" style="justify-content:center">Cancel</a>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
const totalCustomers = <?= $totalCustomers ?>;
const groups = <?= json_encode(array_column($groups, 'cnt', 'group_name')) ?>;
const batchSize = <?= BATCH_SIZE ?>;

function toggleRecipientType(type) {
  document.getElementById('group-selector').style.display  = type === 'group'  ? 'block' : 'none';
  document.getElementById('custom-selector').style.display = type === 'custom' ? 'block' : 'none';
  // Update card styles
  ['all','group','custom'].forEach(t => {
    const card = document.getElementById('rtype-'+t);
    card.style.borderColor = t === type ? 'var(--secondary)' : 'var(--border)';
    card.style.background  = t === type ? 'rgba(0,166,81,0.05)' : '';
  });
  updatePreview();
}

function updateGroupCount(sel) {
  updatePreview();
}

function updatePreview() {
  const type   = document.querySelector('[name=recipient_type]:checked')?.value || 'all';
  const amount = parseFloat(document.querySelector('[name=amount]')?.value) || 0;

  let count = totalCustomers;
  if (type === 'group') {
    const grp = document.querySelector('[name=group_name]')?.value;
    count = grp ? (groups[grp] || 0) : 0;
  } else if (type === 'custom') {
    const phones = document.querySelector('[name=custom_phones]')?.value || '';
    count = phones.split(/[\s,;\n]+/).filter(p => p.trim().length > 6).length;
  }

  const total    = amount * count;
  const batchMs  = 1200;
  const batches  = Math.ceil(count / batchSize);
  const durSec   = Math.ceil(batches * batchMs / 1000);
  const durMin   = durSec < 60 ? durSec + 's' : Math.ceil(durSec/60) + ' min';
  const typeLabels = { all:'All Customers', group:'By Group', custom:'Custom List' };

  document.getElementById('preview-amount').textContent   = 'KES ' + amount.toLocaleString('en-KE', {minimumFractionDigits:2});
  document.getElementById('preview-count').textContent    = count.toLocaleString();
  document.getElementById('preview-total').textContent    = 'KES ' + total.toLocaleString('en-KE', {minimumFractionDigits:2});
  document.getElementById('preview-duration').textContent = '~' + durMin;
  document.getElementById('preview-type').textContent     = typeLabels[type] || type;
}

function toggleSchedule(val) {
  document.getElementById('schedule-picker').style.display = val === 'scheduled' ? 'block' : 'none';
  const dn = document.getElementById('timing-now');
  const ds = document.getElementById('timing-scheduled');
  dn.style.borderColor = val === 'now'       ? 'var(--secondary)' : 'var(--border)';
  dn.style.background  = val === 'now'       ? 'rgba(0,166,81,0.05)' : '';
  ds.style.borderColor = val === 'scheduled' ? 'var(--secondary)' : 'var(--border)';
  ds.style.background  = val === 'scheduled' ? 'rgba(0,166,81,0.05)' : '';
  const baseLabel = document.getElementById('submit-btn').dataset.baselabel || 'Create Campaign';
  document.getElementById('submit-icon').className  = val === 'scheduled' ? 'fas fa-calendar-check' : 'fas fa-check';
  document.getElementById('submit-label').textContent = val === 'scheduled' ? 'Schedule ' + baseLabel.replace(/^(Update |Clone |Create )/, '') : baseLabel;
  if (val === 'scheduled') {
    const dtInput = document.querySelector('[name=scheduled_at]');
    if (dtInput && !dtInput.value) {
      // default to 1 hour from now
      const d = new Date(Date.now() + 3600000);
      dtInput.value = d.getFullYear() + '-' +
        String(d.getMonth()+1).padStart(2,'0') + '-' +
        String(d.getDate()).padStart(2,'0') + 'T' +
        String(d.getHours()).padStart(2,'0') + ':' +
        String(d.getMinutes()).padStart(2,'0');
    }
  }
}

function applyTemplate(t) {
  document.querySelector('[name=amount]').value           = t.amount;
  document.querySelector('[name=account_ref]').value      = t.account_ref;
  document.querySelector('[name=transaction_desc]').value = t.transaction_desc;
  if (!document.querySelector('[name=name]').value) {
    document.querySelector('[name=name]').value = t.name;
  }
  updatePreview();
  Toast.info('Template "' + t.name + '" applied.', 'Template');
}

// Init
document.addEventListener('DOMContentLoaded', () => {
  toggleRecipientType('<?= $data['recipient_type'] ?>');
  toggleSchedule('<?= $data['send_timing'] ?? 'now' ?>');
  updatePreview();
});
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
