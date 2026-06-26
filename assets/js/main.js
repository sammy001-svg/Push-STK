/* ============================================================
   BulkSTK Pro – Main JavaScript
   ============================================================ */

// ─── Toast Notifications ────────────────────────────────────
const Toast = {
  container: null,

  init() {
    if (!this.container) {
      this.container = document.createElement('div');
      this.container.className = 'toast-container';
      document.body.appendChild(this.container);
    }
  },

  show(message, type = 'info', title = null, duration = 4000) {
    this.init();
    const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    const toast  = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
      <span class="toast-icon">${icons[type] || icons.info}</span>
      <div class="toast-body">
        ${title ? `<div class="toast-title">${title}</div>` : ''}
        <div class="toast-message">${message}</div>
      </div>
      <button class="toast-close" onclick="this.closest('.toast').remove()">×</button>
    `;
    this.container.appendChild(toast);
    if (duration > 0) {
      setTimeout(() => {
        toast.style.animation = 'fadeOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
      }, duration);
    }
    return toast;
  },

  success(msg, title)  { return this.show(msg, 'success', title || 'Success'); },
  error(msg, title)    { return this.show(msg, 'error',   title || 'Error');   },
  warning(msg, title)  { return this.show(msg, 'warning', title || 'Warning'); },
  info(msg, title)     { return this.show(msg, 'info',    title || 'Info');    },
};

// ─── Modal ──────────────────────────────────────────────────
const Modal = {
  open(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('show');
    document.body.style.overflow = 'hidden';
  },
  close(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('show');
    document.body.style.overflow = '';
  },
  closeAll() {
    document.querySelectorAll('.modal-backdrop.show').forEach(m => {
      m.classList.remove('show');
    });
    document.body.style.overflow = '';
  }
};

// Close modal on backdrop click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-backdrop')) Modal.closeAll();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') Modal.closeAll();
});

// ─── Tabs ────────────────────────────────────────────────────
function initTabs(container = document) {
  container.querySelectorAll('[data-tab-target]').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tabTarget;
      const parent = btn.closest('.tab-scope') || document;
      parent.querySelectorAll('[data-tab-target]').forEach(b => b.classList.remove('active'));
      parent.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      const pane = document.getElementById(target);
      if (pane) pane.classList.add('active');
    });
  });
}

// ─── Sidebar toggle ─────────────────────────────────────────
function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('open');
}

// ─── Confirm Dialog ──────────────────────────────────────────
function confirmAction(message, callback) {
  if (confirm(message)) callback();
}

// ─── AJAX helper ─────────────────────────────────────────────
async function apiFetch(url, data = null, method = 'POST') {
  const opts = {
    method,
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
    },
  };
  if (data) {
    if (data instanceof FormData) {
      opts.body = data;
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(data);
    }
  }
  const res  = await fetch(url, opts);
  const json = await res.json();
  return json;
}

// ─── Bulk Send Engine ─────────────────────────────────────────
const BulkSender = {
  campaignId: null,
  running: false,
  paused: false,
  timer: null,
  batchDelay: 1200,

  init(campaignId, batchDelay = 1200) {
    this.campaignId = campaignId;
    this.batchDelay = batchDelay;
  },

  async start() {
    if (this.running) return;
    this.running = true;
    this.paused  = false;
    this.updateUI('running');
    await this.sendBatch();
  },

  pause() {
    this.paused = true;
    this.running = false;
    if (this.timer) clearTimeout(this.timer);
    this.updateUI('paused');
    this.saveCampaignState('paused');
  },

  async resume() {
    this.paused  = false;
    this.running = true;
    this.updateUI('running');
    await this.sendBatch();
  },

  async sendBatch() {
    if (this.paused) return;
    try {
      const res = await apiFetch((window.APP_URL || '') + '/api/send_bulk.php', { campaign_id: this.campaignId });

      if (!res.success && res.message) {
        // Hard stop — e.g. bad callback URL, auth failure
        Toast.show(res.message, 'error', 'Cannot Send', 0);
        this.running = false;
        this.updateUI('paused');
        return;
      }

      this.updateProgress(res);

      // Surface the first Daraja rejection so the user knows what went wrong
      if (res.first_error && !this._shownFirstError) {
        this._shownFirstError = true;
        Toast.show(res.first_error, 'error', 'M-Pesa Rejected', 8000);
      }

      if (res.done) {
        this._shownFirstError = false;
        this.running = false;
        this.updateUI('completed');
        // success_count is 0 at dispatch time — callbacks haven't returned yet.
        // Use dispatched (sent - failed) as the success indicator.
        const dispatched = (res.sent_count || 0) - (res.failed_count || 0);
        const msg = dispatched > 0
          ? `${dispatched} STK push${dispatched !== 1 ? 'es' : ''} dispatched — awaiting payment confirmations.`
          : 'All pushes failed — check the M-Pesa error shown above.';
        dispatched > 0 ? Toast.success(msg, 'Done') : Toast.show(msg, 'error', 'Done', 0);
        this.onComplete(res);
        return;
      }

      // Schedule next batch
      this.timer = setTimeout(() => this.sendBatch(), this.batchDelay);
    } catch (err) {
      console.error('Batch send error:', err);
      Toast.error('Network error while sending batch. Retrying…', 'Error');
      this.timer = setTimeout(() => this.sendBatch(), 3000);
    }
  },

  updateProgress(data) {
    const safe = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val ?? 0;
    };
    safe('stat-sent',      data.sent_count);
    safe('stat-success',   data.success_count);
    safe('stat-failed',    data.failed_count);
    safe('stat-pending',   data.pending_count);

    const pct = data.total > 0 ? Math.round((data.sent_count / data.total) * 100) : 0;
    const bar = document.getElementById('main-progress-bar');
    if (bar) {
      bar.style.width = pct + '%';
      bar.textContent = pct + '%';
    }
    const pctEl = document.getElementById('progress-pct');
    if (pctEl) pctEl.textContent = pct + '%';

    // Recent transactions list
    if (data.recent && data.recent.length) {
      this.appendRecentTx(data.recent);
    }
  },

  appendRecentTx(txList) {
    const container = document.getElementById('recent-tx-list');
    if (!container) return;
    txList.forEach(tx => {
      const cls  = tx.status === 'success' ? 'badge-success' : tx.status === 'failed' ? 'badge-danger' : 'badge-warning';
      const item = document.createElement('div');
      item.className = 'recent-tx-item';
      item.innerHTML = `
        <div class="customer-cell">
          <div class="customer-avatar">${(tx.name || tx.phone || '?')[0].toUpperCase()}</div>
          <div>
            <div class="customer-name">${tx.name || 'Unknown'}</div>
            <div class="customer-phone">${tx.phone}</div>
          </div>
        </div>
        <div style="text-align:right">
          <span class="badge ${cls}">${tx.status}</span>
          <div style="font-size:12px;color:#64748B;margin-top:3px">KES ${parseFloat(tx.amount || 0).toLocaleString()}</div>
        </div>
      `;
      container.insertBefore(item, container.firstChild);
      if (container.children.length > 20) container.lastChild.remove();
    });
  },

  async saveCampaignState(status) {
    await apiFetch((window.APP_URL || '') + '/api/campaign_status.php', {
      campaign_id: this.campaignId,
      action: 'set_status',
      status,
    });
  },

  updateUI(state) {
    const btnStart  = document.getElementById('btn-start');
    const btnPause  = document.getElementById('btn-pause');
    const btnResume = document.getElementById('btn-resume');
    const statusEl  = document.getElementById('campaign-status-label');

    if (btnStart)  btnStart.style.display  = state === 'running' || state === 'paused' ? 'none' : 'inline-flex';
    if (btnPause)  btnPause.style.display  = state === 'running'  ? 'inline-flex' : 'none';
    if (btnResume) btnResume.style.display = state === 'paused'   ? 'inline-flex' : 'none';

    const labels = { running: 'Running…', paused: 'Paused', completed: 'Completed', idle: 'Ready' };
    if (statusEl) statusEl.textContent = labels[state] || state;
  },

  onComplete(data) {
    // Update final stats display
    setTimeout(() => window.location.reload(), 3000);
  },
};

// ─── Upload zone drag-and-drop ───────────────────────────────
function initUploadZone(zoneId, inputId) {
  const zone  = document.getElementById(zoneId);
  const input = document.getElementById(inputId);
  if (!zone || !input) return;

  zone.addEventListener('click', () => input.click());
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    if (e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
      input.dispatchEvent(new Event('change'));
    }
  });
}

// ─── Select All checkbox ─────────────────────────────────────
function initSelectAll(masterSelector, itemSelector) {
  const master = document.querySelector(masterSelector);
  if (!master) return;
  master.addEventListener('change', () => {
    document.querySelectorAll(itemSelector).forEach(cb => {
      cb.checked = master.checked;
    });
  });
  document.querySelectorAll(itemSelector).forEach(cb => {
    cb.addEventListener('change', () => {
      const all   = document.querySelectorAll(itemSelector);
      const checked = document.querySelectorAll(itemSelector + ':checked');
      master.checked       = checked.length === all.length;
      master.indeterminate = checked.length > 0 && checked.length < all.length;
    });
  });
}

// ─── Phone formatter ─────────────────────────────────────────
function formatPhoneInput(input) {
  let v = input.value.replace(/\D/g, '');
  if (v.startsWith('0') && v.length === 10) {
    input.value = '254' + v.slice(1);
  } else if (v.length === 9) {
    input.value = '254' + v;
  }
}

// ─── Number formatter ────────────────────────────────────────
function formatAmount(val) {
  return 'KES ' + parseFloat(val || 0).toLocaleString('en-KE', { minimumFractionDigits: 2 });
}

// ─── Init on DOM ready ───────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initTabs();

  // Auto-dismiss flash messages
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
    const ms = parseInt(el.dataset.autoDismiss) || 5000;
    setTimeout(() => {
      el.style.animation = 'fadeOut 0.5s ease forwards';
      setTimeout(() => el.remove(), 500);
    }, ms);
  });

  // Phone inputs auto-format
  document.querySelectorAll('input[data-phone]').forEach(input => {
    input.addEventListener('blur', () => formatPhoneInput(input));
  });
});
