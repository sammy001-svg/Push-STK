<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$errors = [];

// ── Create ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    verifyCsrf();
    $name  = trim($_POST['name']        ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $color = trim($_POST['color']       ?? '#00A651');

    if (!$name)            $errors[] = 'Group name is required.';
    elseif (strlen($name) > 100) $errors[] = 'Group name must be 100 characters or less.';

    if (empty($errors)) {
        $exists = Database::fetchOne("SELECT id FROM customer_groups WHERE name = ?", [$name]);
        if ($exists) {
            $errors[] = "A group named \"{$name}\" already exists.";
        } else {
            Database::insert('customer_groups', [
                'name'        => $name,
                'description' => $desc ?: null,
                'color'       => $color,
            ]);
            logActivity(Auth::userId(), 'group_create', 'groups', "Created group: {$name}");
            flash('success', "Group \"{$name}\" created successfully.");
            redirect(APP_URL . '/customers/groups.php');
        }
    }
}

// ── Update ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    verifyCsrf();
    $id    = (int)($_POST['id']          ?? 0);
    $name  = trim($_POST['name']         ?? '');
    $desc  = trim($_POST['description']  ?? '');
    $color = trim($_POST['color']        ?? '#00A651');

    if (!$name) $errors[] = 'Group name is required.';

    if (empty($errors) && $id) {
        $old = Database::fetchOne("SELECT name FROM customer_groups WHERE id = ?", [$id]);
        // Check duplicate name (excluding self)
        $dup = Database::fetchOne("SELECT id FROM customer_groups WHERE name = ? AND id != ?", [$name, $id]);
        if ($dup) {
            $errors[] = "A group named \"{$name}\" already exists.";
        } else {
            Database::update('customer_groups', [
                'name'        => $name,
                'description' => $desc ?: null,
                'color'       => $color,
            ], 'id = ?', [$id]);

            // Keep customers in sync if name changed
            if ($old && $old['name'] !== $name) {
                Database::query(
                    "UPDATE customers SET group_name = ? WHERE group_name = ?",
                    [$name, $old['name']]
                );
            }
            logActivity(Auth::userId(), 'group_update', 'groups', "Updated group ID {$id}: {$name}");
            flash('success', "Group updated successfully.");
            redirect(APP_URL . '/customers/groups.php');
        }
    }
}

// ── Delete ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $group = Database::fetchOne("SELECT name FROM customer_groups WHERE id = ?", [$id]);
        if ($group) {
            // Unassign customers from this group
            Database::query("UPDATE customers SET group_name = NULL WHERE group_name = ?", [$group['name']]);
            Database::query("DELETE FROM customer_groups WHERE id = ?", [$id]);
            logActivity(Auth::userId(), 'group_delete', 'groups', "Deleted group: {$group['name']}");
            flash('success', "Group \"{$group['name']}\" deleted. Customers unassigned.");
        }
    }
    redirect(APP_URL . '/customers/groups.php');
}

// ── Bulk assign customers to group ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_assign') {
    verifyCsrf();
    $groupName   = trim($_POST['group_name'] ?? '');
    $customerIds = array_map('intval', (array)($_POST['customer_ids'] ?? []));

    if ($groupName && !empty($customerIds)) {
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        Database::query(
            "UPDATE customers SET group_name = ? WHERE id IN ({$placeholders})",
            array_merge([$groupName], $customerIds)
        );
        flash('success', count($customerIds) . " customer(s) assigned to \"{$groupName}\".");
    }
    redirect(APP_URL . '/customers/groups.php');
}

// ── Load data ────────────────────────────────────────────────
$groups = Database::fetchAll("
    SELECT g.*,
           COUNT(c.id)        AS customer_count,
           SUM(c.status = 1)  AS active_count
    FROM customer_groups g
    LEFT JOIN customers c ON c.group_name = g.name
    GROUP BY g.id
    ORDER BY g.name ASC
");

// For edit modal — load group by id
$editGroup = null;
if (!empty($_GET['edit'])) {
    $editGroup = Database::fetchOne("SELECT * FROM customer_groups WHERE id = ?", [(int)$_GET['edit']]);
}

$pageTitle    = 'Customer Groups';
$pageSubtitle = 'Customers &rsaquo; Groups';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><i class="fas fa-layer-group" style="color:var(--secondary);margin-right:8px"></i>Customer Groups</h1>
      <p>Organise customers into groups for targeted campaign sending</p>
    </div>
    <button class="btn btn-secondary" onclick="Modal.open('modal-create')">
      <i class="fas fa-plus"></i> New Group
    </button>
  </div>
</div>

<!-- ── Groups Grid ──────────────────────────────────────────── -->
<?php if (empty($groups)): ?>
  <div class="empty-state card" style="padding:60px">
    <div class="empty-icon"><i class="fas fa-layer-group"></i></div>
    <h3>No groups yet</h3>
    <p>Create groups to organise customers and target campaigns</p>
    <button class="btn btn-secondary" style="margin-top:16px" onclick="Modal.open('modal-create')">
      <i class="fas fa-plus"></i> Create First Group
    </button>
  </div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;margin-bottom:28px">
    <?php foreach ($groups as $g):
      $pct = $g['customer_count'] > 0 ? round(($g['active_count'] / $g['customer_count']) * 100) : 0;
    ?>
      <div class="card" style="border-top:4px solid <?= e($g['color']) ?>">
        <div class="card-body">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px">
            <div style="display:flex;align-items:center;gap:10px">
              <div style="width:42px;height:42px;border-radius:10px;background:<?= e($g['color']) ?>22;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-users" style="color:<?= e($g['color']) ?>;font-size:18px"></i>
              </div>
              <div>
                <div style="font-weight:700;font-size:15px;color:var(--primary)"><?= e($g['name']) ?></div>
                <?php if ($g['description']): ?>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= e($g['description']) ?></div>
                <?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:6px">
              <a href="?edit=<?= $g['id'] ?>" class="btn btn-light btn-sm btn-icon" title="Edit">
                <i class="fas fa-edit"></i>
              </a>
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('Delete group \'<?= e(addslashes($g['name'])) ?>\'? Customers will be unassigned but not deleted.')">
                <input type="hidden" name="action"     value="delete"/>
                <input type="hidden" name="id"         value="<?= $g['id'] ?>"/>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Delete">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </div>
          </div>

          <!-- Stats row -->
          <div style="display:flex;gap:10px;margin-bottom:14px">
            <div style="flex:1;text-align:center;background:var(--bg);border-radius:8px;padding:10px">
              <div style="font-size:22px;font-weight:800;color:var(--primary)"><?= number_format($g['customer_count']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)">Total</div>
            </div>
            <div style="flex:1;text-align:center;background:rgba(0,166,81,0.08);border-radius:8px;padding:10px">
              <div style="font-size:22px;font-weight:800;color:var(--success)"><?= number_format($g['active_count']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)">Active</div>
            </div>
            <div style="flex:1;text-align:center;background:var(--bg);border-radius:8px;padding:10px">
              <div style="font-size:22px;font-weight:800;color:var(--primary)"><?= $pct ?>%</div>
              <div style="font-size:11px;color:var(--text-muted)">Active %</div>
            </div>
          </div>

          <!-- Active bar -->
          <div class="progress progress-sm" style="margin-bottom:14px">
            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= e($g['color']) ?>"></div>
          </div>

          <!-- Actions -->
          <div style="display:flex;gap:8px">
            <a href="<?= APP_URL ?>/customers/index.php?group=<?= urlencode($g['name']) ?>"
               class="btn btn-outline-primary btn-sm" style="flex:1;justify-content:center">
              <i class="fas fa-users"></i> View Members
            </a>
            <a href="<?= APP_URL ?>/campaigns/create.php?group=<?= urlencode($g['name']) ?>"
               class="btn btn-secondary btn-sm" style="flex:1;justify-content:center">
              <i class="fas fa-rocket"></i> Send Push
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ── Customers Without a Group ────────────────────────────── -->
<?php
$ungrouped = Database::count("SELECT COUNT(*) FROM customers WHERE (group_name IS NULL OR group_name = '') AND status = 1");
if ($ungrouped > 0):
  $ungroupedList = Database::fetchAll("SELECT id, name, phone_formatted FROM customers WHERE (group_name IS NULL OR group_name = '') AND status = 1 ORDER BY name LIMIT 50");
?>
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-user-slash"></i> Ungrouped Customers
      <span class="badge badge-warning" style="margin-left:6px"><?= number_format($ungrouped) ?></span>
    </div>
    <button class="btn btn-outline-primary btn-sm" onclick="Modal.open('modal-bulk-assign')">
      <i class="fas fa-layer-group"></i> Assign to Group
    </button>
  </div>
  <div class="table-wrapper">
    <table class="table">
      <thead><tr>
        <th><input type="checkbox" id="ug-check-all" style="accent-color:var(--secondary)" onchange="document.querySelectorAll('.ug-check').forEach(c=>c.checked=this.checked)"/></th>
        <th>Customer</th>
        <th>Phone</th>
        <th>Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ungroupedList as $c): ?>
        <tr>
          <td><input type="checkbox" class="ug-check" value="<?= $c['id'] ?>" style="accent-color:var(--secondary)"/></td>
          <td>
            <div class="customer-cell">
              <div class="customer-avatar"><?= strtoupper(substr($c['name'], 0, 1)) ?></div>
              <div class="customer-name"><?= e($c['name']) ?></div>
            </div>
          </td>
          <td><?= e($c['phone_formatted']) ?></td>
          <td>
            <a href="<?= APP_URL ?>/customers/edit.php?id=<?= $c['id'] ?>" class="btn btn-light btn-sm">
              <i class="fas fa-edit"></i> Assign Group
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($ungrouped > 50): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-muted);font-size:13px;padding:12px">
          … and <?= number_format($ungrouped - 50) ?> more.
          <a href="<?= APP_URL ?>/customers/index.php?group=__none__">View all</a>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════ -->
<!-- Modal: Create Group                                        -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop<?= (!empty($errors) && $action === 'create') ? ' show' : '' ?>" id="modal-create">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-plus-circle" style="color:var(--secondary)"></i> Create New Group</div>
      <button class="modal-close" onclick="Modal.close('modal-create')">×</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action"     value="create"/>
      <div class="modal-body">

        <?php if (!empty($errors) && $action === 'create'): ?>
          <div class="alert alert-danger mb-3">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
          </div>
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label">Group Name <span class="required">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Premium Members"
                 value="<?= e($_POST['name'] ?? '') ?>" required maxlength="100" autofocus/>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"
                    placeholder="Optional group description…"><?= e($_POST['description'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Group Color</label>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <?php foreach (['#0D2B55','#00A651','#F59E0B','#6366F1','#EC4899','#0EA5E9','#DC2626','#14B8A6','#F97316','#8B5CF6'] as $c): ?>
              <label style="cursor:pointer">
                <input type="radio" name="color" value="<?= $c ?>" style="display:none"
                       onchange="document.getElementById('color-preview').style.background=this.value"
                       <?= ($_POST['color'] ?? '#00A651') === $c ? 'checked' : '' ?>/>
                <div style="width:32px;height:32px;border-radius:50%;background:<?= $c ?>;border:3px solid transparent;transition:all 0.15s"
                     onclick="this.previousElementSibling.click();this.style.border='3px solid #000'"></div>
              </label>
            <?php endforeach; ?>
            <div id="color-preview" style="width:32px;height:32px;border-radius:8px;background:<?= $_POST['color'] ?? '#00A651' ?>;border:2px dashed #ccc"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" onclick="Modal.close('modal-create')">Cancel</button>
        <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Create Group</button>
      </div>
    </form>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════ -->
<!-- Modal: Edit Group                                          -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if ($editGroup): ?>
<div class="modal-backdrop show" id="modal-edit">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-edit" style="color:var(--secondary)"></i> Edit Group</div>
      <a href="?" class="modal-close">×</a>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action"     value="update"/>
      <input type="hidden" name="id"         value="<?= $editGroup['id'] ?>"/>
      <div class="modal-body">

        <?php if (!empty($errors) && $action === 'update'): ?>
          <div class="alert alert-danger mb-3">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
          </div>
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label">Group Name <span class="required">*</span></label>
          <input type="text" name="name" class="form-control" required maxlength="100"
                 value="<?= e($editGroup['name']) ?>"/>
          <div class="form-hint">Renaming will automatically update all customers in this group.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"><?= e($editGroup['description'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Group Color</label>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <?php foreach (['#0D2B55','#00A651','#F59E0B','#6366F1','#EC4899','#0EA5E9','#DC2626','#14B8A6','#F97316','#8B5CF6'] as $c): ?>
              <label style="cursor:pointer">
                <input type="radio" name="color" value="<?= $c ?>"
                       style="display:none"
                       onchange="document.getElementById('edit-color-preview').style.background=this.value"
                       <?= $editGroup['color'] === $c ? 'checked' : '' ?>/>
                <div style="width:32px;height:32px;border-radius:50%;background:<?= $c ?>;border:<?= $editGroup['color']===$c?'3px solid #333':'3px solid transparent' ?>;transition:all 0.15s"
                     onclick="this.previousElementSibling.click()"></div>
              </label>
            <?php endforeach; ?>
            <div id="edit-color-preview" style="width:32px;height:32px;border-radius:8px;background:<?= e($editGroup['color']) ?>;border:2px dashed #ccc"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="?" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════ -->
<!-- Modal: Bulk Assign                                         -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-bulk-assign">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-layer-group" style="color:var(--secondary)"></i> Bulk Assign to Group</div>
      <button class="modal-close" onclick="Modal.close('modal-bulk-assign')">×</button>
    </div>
    <form method="POST" id="bulk-assign-form">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action"     value="bulk_assign"/>
      <div id="bulk-ids-container"></div>
      <div class="modal-body">
        <p style="font-size:14px;color:var(--text-muted);margin-bottom:16px">
          Select the group to assign the checked customers to.
        </p>
        <div class="form-group">
          <label class="form-label">Assign to Group <span class="required">*</span></label>
          <select name="group_name" class="form-select" required>
            <option value="">— Select a group —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= e($g['name']) ?>"><?= e($g['name']) ?> (<?= $g['customer_count'] ?> members)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="bulk-count-label" style="font-size:13px;color:var(--text-muted)">No customers selected.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" onclick="Modal.close('modal-bulk-assign')">Cancel</button>
        <button type="submit" class="btn btn-secondary"><i class="fas fa-check"></i> Assign</button>
      </div>
    </form>
  </div>
</div>

<script>
// Bulk assign — inject selected IDs into form before submit
document.getElementById('modal-bulk-assign').addEventListener('show', () => {
  const checked = [...document.querySelectorAll('.ug-check:checked')];
  const container = document.getElementById('bulk-ids-container');
  container.innerHTML = '';
  checked.forEach(cb => {
    const inp = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = 'customer_ids[]';
    inp.value = cb.value;
    container.appendChild(inp);
  });
  const label = document.getElementById('bulk-count-label');
  if (label) label.textContent = checked.length + ' customer(s) selected.';
});

// Intercept Bulk Assign button click to populate IDs first
document.querySelector('[onclick="Modal.open(\'modal-bulk-assign\')"]')?.addEventListener('click', () => {
  const checked = [...document.querySelectorAll('.ug-check:checked')];
  const container = document.getElementById('bulk-ids-container');
  container.innerHTML = '';
  checked.forEach(cb => {
    const inp = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = 'customer_ids[]';
    inp.value = cb.value;
    container.appendChild(inp);
  });
  document.getElementById('bulk-count-label').textContent =
    checked.length + ' customer(s) selected.';
});

// Color swatch selection highlight
document.querySelectorAll('input[name="color"]').forEach(radio => {
  radio.addEventListener('change', function() {
    const group = this.closest('.form-group, .modal-body');
    group.querySelectorAll('input[name="color"] + div').forEach(d => d.style.border = '3px solid transparent');
    this.nextElementSibling.style.border = '3px solid #333';
  });
});
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
