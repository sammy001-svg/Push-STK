<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

$templates = Database::fetchAll("
    SELECT t.*, u.name AS created_by_name
    FROM campaign_templates t
    LEFT JOIN admin_users u ON u.id = t.created_by
    ORDER BY t.name ASC
");

$pageTitle    = 'Campaign Templates';
$pageSubtitle = 'Campaigns &rsaquo; Templates';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><i class="fas fa-layer-group" style="color:var(--secondary);margin-right:8px"></i>Campaign Templates</h1>
      <p>Save reusable settings — one click to pre-fill a new campaign</p>
    </div>
    <button class="btn btn-secondary" onclick="openNewModal()">
      <i class="fas fa-plus"></i> New Template
    </button>
  </div>
</div>

<?php if (empty($templates)): ?>
  <div class="card">
    <div class="empty-state" style="padding:60px 20px">
      <div class="empty-icon"><i class="fas fa-layer-group"></i></div>
      <h3>No templates yet</h3>
      <p>Save a campaign's settings as a template to speed up future campaigns.</p>
      <div style="display:flex;gap:10px;justify-content:center;margin-top:16px">
        <button class="btn btn-secondary" onclick="openNewModal()">
          <i class="fas fa-plus"></i> Create Template
        </button>
        <a href="<?= APP_URL ?>/campaigns/index.php" class="btn btn-light">
          <i class="fas fa-bullhorn"></i> View Campaigns
        </a>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="grid-3">
    <?php foreach ($templates as $t): ?>
      <div class="card" id="tpl-card-<?= $t['id'] ?>" style="display:flex;flex-direction:column">
        <div class="card-body" style="flex:1">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:12px">
            <div>
              <div style="font-weight:700;font-size:16px;color:var(--primary)"><?= e($t['name']) ?></div>
              <?php if ($t['description']): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-top:3px"><?= e(mb_strimwidth($t['description'], 0, 70, '…')) ?></div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:4px;flex-shrink:0">
              <button class="btn btn-sm btn-light" title="Edit template"
                      onclick="openEditModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-sm btn-light" title="Delete template"
                      onclick="deleteTemplate(<?= $t['id'] ?>, <?= htmlspecialchars(json_encode($t['name']), ENT_QUOTES) ?>, this)">
                <i class="fas fa-trash" style="color:var(--danger)"></i>
              </button>
            </div>
          </div>

          <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
            <div style="display:flex;justify-content:space-between;padding:8px 12px;background:var(--bg);border-radius:8px">
              <span style="color:var(--text-muted)">Amount</span>
              <span style="font-weight:700;color:var(--primary);font-size:15px">KES <?= number_format((float)$t['amount'], 2) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:6px 12px">
              <span style="color:var(--text-muted)">Account Ref</span>
              <code style="font-size:12px;background:var(--bg);padding:2px 8px;border-radius:5px"><?= e($t['account_ref']) ?></code>
            </div>
            <div style="display:flex;justify-content:space-between;padding:6px 12px">
              <span style="color:var(--text-muted)">Description</span>
              <span style="font-weight:500"><?= e($t['transaction_desc']) ?></span>
            </div>
          </div>
        </div>

        <div class="card-footer" style="padding:12px 16px;border-top:1px solid var(--border)">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
            <div style="font-size:11px;color:var(--text-muted)">
              <i class="fas fa-user" style="font-size:10px"></i>
              <?= e($t['created_by_name'] ?? 'Unknown') ?>
              &middot; <?= date('d M Y', strtotime($t['created_at'])) ?>
            </div>
            <a href="<?= APP_URL ?>/campaigns/create.php?template_id=<?= $t['id'] ?>"
               class="btn btn-sm btn-secondary">
              <i class="fas fa-rocket"></i> Use Template
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ─── New / Edit Template Modal ─────────────────────────── -->
<div class="modal-backdrop" id="tpl-modal" style="display:none">
  <div class="modal" style="max-width:500px;width:95vw">
    <div class="modal-header">
      <div class="modal-title" id="tpl-modal-title">New Template</div>
      <button class="modal-close" onclick="Modal.close('tpl-modal')">×</button>
    </div>
    <div class="modal-body" style="padding:24px">
      <input type="hidden" id="tpl-id" value=""/>

      <div class="form-group">
        <label class="form-label">Template Name <span class="required">*</span></label>
        <input type="text" id="tpl-name" class="form-control" placeholder="e.g. Monthly Loan Repayment" maxlength="200"/>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea id="tpl-description" class="form-control" rows="2" placeholder="Optional notes…"></textarea>
      </div>
      <div class="grid-2">
        <div class="form-group">
          <label class="form-label">Amount (KES) <span class="required">*</span></label>
          <div class="input-group">
            <span class="input-group-text">KES</span>
            <input type="number" id="tpl-amount" class="form-control" placeholder="0.00" min="1" step="0.01"/>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Account Ref <span class="required">*</span></label>
          <input type="text" id="tpl-account-ref" class="form-control" placeholder="e.g. LOAN001" maxlength="12"
                 oninput="this.value=this.value.slice(0,12)"/>
          <div class="form-hint">Max 12 characters</div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Transaction Description</label>
        <input type="text" id="tpl-transaction-desc" class="form-control" placeholder="Payment" maxlength="13"
               oninput="this.value=this.value.slice(0,13)"/>
        <div class="form-hint">Max 13 characters</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="Modal.close('tpl-modal')">Cancel</button>
      <button class="btn btn-secondary" id="tpl-save-btn" onclick="saveTemplate()">
        <i class="fas fa-save"></i> Save Template
      </button>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

function openNewModal() {
  document.getElementById('tpl-id').value              = '';
  document.getElementById('tpl-name').value            = '';
  document.getElementById('tpl-description').value     = '';
  document.getElementById('tpl-amount').value          = '';
  document.getElementById('tpl-account-ref').value     = '';
  document.getElementById('tpl-transaction-desc').value = 'Payment';
  document.getElementById('tpl-modal-title').textContent = 'New Template';
  document.getElementById('tpl-save-btn').innerHTML = '<i class="fas fa-save"></i> Save Template';
  Modal.open('tpl-modal');
  setTimeout(() => document.getElementById('tpl-name').focus(), 80);
}

function openEditModal(t) {
  document.getElementById('tpl-id').value              = t.id;
  document.getElementById('tpl-name').value            = t.name;
  document.getElementById('tpl-description').value     = t.description || '';
  document.getElementById('tpl-amount').value          = t.amount;
  document.getElementById('tpl-account-ref').value     = t.account_ref;
  document.getElementById('tpl-transaction-desc').value = t.transaction_desc;
  document.getElementById('tpl-modal-title').textContent = 'Edit Template';
  document.getElementById('tpl-save-btn').innerHTML = '<i class="fas fa-save"></i> Update Template';
  Modal.open('tpl-modal');
  setTimeout(() => document.getElementById('tpl-name').focus(), 80);
}

async function saveTemplate() {
  const id   = document.getElementById('tpl-id').value;
  const name = document.getElementById('tpl-name').value.trim();
  const amt  = document.getElementById('tpl-amount').value;
  const ref  = document.getElementById('tpl-account-ref').value.trim();
  if (!name) { Toast.warning('Template name is required.'); return; }
  if (!amt || parseFloat(amt) < 1) { Toast.warning('Amount must be at least KES 1.'); return; }
  if (!ref) { Toast.warning('Account reference is required.'); return; }

  const btn = document.getElementById('tpl-save-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner spinner-sm"></span> Saving…';

  const res = await apiFetch((window.APP_URL || '') + '/api/template_action.php', {
    action:           id ? 'update' : 'save',
    id:               id ? parseInt(id) : undefined,
    name,
    description:      document.getElementById('tpl-description').value.trim(),
    amount:           parseFloat(amt),
    account_ref:      ref,
    transaction_desc: document.getElementById('tpl-transaction-desc').value.trim() || 'Payment',
  });

  if (res.success) {
    Toast.success(res.message, id ? 'Updated' : 'Saved');
    Modal.close('tpl-modal');
    setTimeout(() => location.reload(), 800);
  } else {
    Toast.error(res.message || 'Save failed.', 'Error');
    btn.disabled = false;
    btn.innerHTML = id ? '<i class="fas fa-save"></i> Update Template' : '<i class="fas fa-save"></i> Save Template';
  }
}

async function deleteTemplate(id, name, btn) {
  if (!confirm(`Delete template "${name}"? This cannot be undone.`)) return;
  btn.disabled = true;

  const res = await apiFetch((window.APP_URL || '') + '/api/template_action.php', {
    action: 'delete', id,
  });

  if (res.success) {
    Toast.success(res.message, 'Deleted');
    const card = document.getElementById('tpl-card-' + id);
    if (card) { card.style.opacity = '0'; card.style.transition = 'opacity .3s'; setTimeout(() => card.remove(), 300); }
  } else {
    Toast.error(res.message || 'Delete failed.', 'Error');
    btn.disabled = false;
  }
}
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
