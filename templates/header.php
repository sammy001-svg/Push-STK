<?php
// Determine active nav item from current script
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

function isActive(string $page, string $dir = ''): string {
    global $currentPage, $currentDir;
    if ($dir && $currentDir === $dir) return ' active';
    if (!$dir && $currentPage === $page) return ' active';
    return '';
}

$pageTitle    = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$flashData    = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= csrfToken() ?>"/>
  <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css"/>

  <script>window.APP_URL = '<?= APP_URL ?>';</script>
  <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body>
<div class="app-wrapper">

<!-- ─── Sidebar ─────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
  <a href="<?= APP_URL ?>/dashboard.php" class="sidebar-brand">
    <div class="brand-icon">💸</div>
    <div class="brand-text">
      <div class="app-name"><?= e(APP_NAME) ?></div>
      <div class="app-sub">M-Pesa Dashboard</div>
    </div>
  </a>

  <nav class="sidebar-nav">
    <div class="nav-section-title">Main Menu</div>

    <div class="nav-item">
      <a href="<?= APP_URL ?>/dashboard.php" class="nav-link<?= isActive('dashboard') ?>">
        <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
        Dashboard
      </a>
    </div>

    <div class="nav-section-title">Campaigns</div>

    <div class="nav-item">
      <a href="<?= APP_URL ?>/campaigns/index.php" class="nav-link<?= isActive('index','campaigns') ?>">
        <span class="nav-icon"><i class="fas fa-bullhorn"></i></span>
        All Campaigns
      </a>
    </div>
    <div class="nav-item">
      <a href="<?= APP_URL ?>/campaigns/create.php" class="nav-link<?= isActive('create','campaigns') ?>">
        <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
        New Campaign
      </a>
    </div>

    <div class="nav-section-title">Customers</div>

    <div class="nav-item">
      <a href="<?= APP_URL ?>/customers/index.php" class="nav-link<?= isActive('index','customers') ?>">
        <span class="nav-icon"><i class="fas fa-users"></i></span>
        All Customers
      </a>
    </div>
    <div class="nav-item">
      <a href="<?= APP_URL ?>/customers/add.php" class="nav-link<?= isActive('add','customers') ?>">
        <span class="nav-icon"><i class="fas fa-user-plus"></i></span>
        Add Customer
      </a>
    </div>
    <div class="nav-item">
      <a href="<?= APP_URL ?>/customers/import.php" class="nav-link<?= isActive('import','customers') ?>">
        <span class="nav-icon"><i class="fas fa-file-import"></i></span>
        Import CSV
      </a>
    </div>
    <div class="nav-item">
      <a href="<?= APP_URL ?>/customers/groups.php" class="nav-link<?= isActive('groups','customers') ?>">
        <span class="nav-icon"><i class="fas fa-layer-group"></i></span>
        Groups
      </a>
    </div>

    <div class="nav-section-title">Reports</div>

    <div class="nav-item">
      <a href="<?= APP_URL ?>/transactions/index.php" class="nav-link<?= isActive('index','transactions') ?>">
        <span class="nav-icon"><i class="fas fa-receipt"></i></span>
        Transactions
      </a>
    </div>

    <div class="nav-section-title">System</div>

    <div class="nav-item">
      <a href="<?= APP_URL ?>/settings/index.php" class="nav-link<?= isActive('index','settings') ?>">
        <span class="nav-icon"><i class="fas fa-cog"></i></span>
        Settings
      </a>
    </div>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= e($_SESSION['user_name'] ?? 'Admin') ?></div>
        <div class="user-role"><?= ucfirst(str_replace('_', ' ', $_SESSION['user_role'] ?? 'admin')) ?></div>
      </div>
      <a href="<?= APP_URL ?>/logout.php" title="Logout" style="color:rgba(255,255,255,0.4);font-size:16px;margin-left:auto">
        <i class="fas fa-sign-out-alt"></i>
      </a>
    </div>
  </div>
</aside>

<!-- ─── Main Content ─────────────────────────────────────── -->
<div class="main-content">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-btn" onclick="toggleSidebar()" title="Menu" style="display:none" id="sidebar-toggle">
        <i class="fas fa-bars"></i>
      </button>
      <div>
        <div class="topbar-title"><?= e($pageTitle) ?></div>
        <?php if ($pageSubtitle): ?>
          <div class="topbar-breadcrumb"><?= $pageSubtitle ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="topbar-right">
      <a href="<?= APP_URL ?>/campaigns/create.php" class="btn btn-secondary btn-sm">
        <i class="fas fa-plus"></i> New Campaign
      </a>
      <div class="topbar-user" onclick="window.location='<?= APP_URL ?>/settings/index.php'">
        <div class="avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?></div>
        <span style="font-size:13px;font-weight:600;color:var(--text)"><?= e(explode(' ', $_SESSION['user_name'] ?? 'Admin')[0]) ?></span>
        <i class="fas fa-chevron-down" style="font-size:11px;color:var(--text-muted)"></i>
      </div>
    </div>
  </header>

  <!-- Page Content Start -->
  <div class="page-content">

    <?php if ($flashData): ?>
      <div class="alert alert-<?= $flashData['type'] === 'error' ? 'danger' : $flashData['type'] ?>" data-auto-dismiss="5000">
        <i class="fas fa-<?= $flashData['type'] === 'success' ? 'check-circle' : ($flashData['type'] === 'error' ? 'times-circle' : 'info-circle') ?>"></i>
        <span><?= e($flashData['message']) ?></span>
      </div>
    <?php endif; ?>
