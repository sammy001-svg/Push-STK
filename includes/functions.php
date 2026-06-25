<?php
require_once __DIR__ . '/db.php';

// -------------------------------------------------------
// Output helpers
// -------------------------------------------------------

function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = compact('type', 'message');
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// -------------------------------------------------------
// Security helpers
// -------------------------------------------------------

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die(json_encode(['success' => false, 'message' => 'CSRF token mismatch.']));
    }
}

// -------------------------------------------------------
// Formatting
// -------------------------------------------------------

function formatMoney(float $amount, string $currency = 'KES'): string {
    return $currency . ' ' . number_format($amount, 2);
}

function formatPhone(string $phone): string {
    require_once __DIR__ . '/Mpesa.php';
    return Mpesa::formatPhone($phone);
}

function timeAgo(string $datetime): string {
    $time = time() - strtotime($datetime);
    if ($time < 60)     return 'Just now';
    if ($time < 3600)   return (int)($time/60)  . ' min ago';
    if ($time < 86400)  return (int)($time/3600) . ' hrs ago';
    if ($time < 604800) return (int)($time/86400) . ' days ago';
    return date('d M Y', strtotime($datetime));
}

function progressPercent(int $sent, int $total): float {
    return $total > 0 ? round(($sent / $total) * 100, 1) : 0;
}

function statusBadge(string $status): string {
    $map = [
        'pending'    => ['badge-warning',  'Pending'],
        'processing' => ['badge-info',     'Processing'],
        'success'    => ['badge-success',  'Success'],
        'failed'     => ['badge-danger',   'Failed'],
        'timeout'    => ['badge-secondary','Timeout'],
        'cancelled'  => ['badge-secondary','Cancelled'],
        'draft'      => ['badge-secondary','Draft'],
        'scheduled'  => ['badge-purple',   'Scheduled'],
        'queued'     => ['badge-info',     'Queued'],
        'running'    => ['badge-primary',  'Running'],
        'paused'     => ['badge-warning',  'Paused'],
        'completed'  => ['badge-success',  'Completed'],
    ];
    [$cls, $label] = $map[$status] ?? ['badge-secondary', ucfirst($status)];
    return "<span class=\"badge {$cls}\">{$label}</span>";
}

function campaignProgressBar(array $campaign): string {
    $pct  = progressPercent($campaign['sent_count'], $campaign['total_recipients']);
    $succ = progressPercent($campaign['success_count'], $campaign['total_recipients']);
    $fail = progressPercent($campaign['failed_count'], $campaign['total_recipients']);
    return "
        <div class='progress-stack' title='{$pct}% sent'>
            <div class='progress' style='height:10px'>
                <div class='progress-bar bg-success' style='width:{$succ}%'></div>
                <div class='progress-bar bg-danger'  style='width:{$fail}%'></div>
            </div>
            <small class='text-muted'>{$pct}% complete</small>
        </div>";
}

// -------------------------------------------------------
// Dashboard stats
// -------------------------------------------------------

function getDashboardStats(): array {
    $today = date('Y-m-d');
    return [
        'total_campaigns'    => Database::count("SELECT COUNT(*) FROM campaigns"),
        'active_campaigns'   => Database::count("SELECT COUNT(*) FROM campaigns WHERE status IN ('running','queued')"),
        'scheduled_campaigns'=> Database::count("SELECT COUNT(*) FROM campaigns WHERE status = 'scheduled'"),
        'total_customers'    => Database::count("SELECT COUNT(*) FROM customers WHERE status = 1"),
        'total_transactions' => Database::count("SELECT COUNT(*) FROM transactions"),
        'success_count'      => Database::count("SELECT COUNT(*) FROM transactions WHERE status = 'success'"),
        'failed_count'       => Database::count("SELECT COUNT(*) FROM transactions WHERE status = 'failed'"),
        'today_pushes'       => Database::count("SELECT COUNT(*) FROM transactions WHERE DATE(initiated_at) = ?", [$today]),
        'today_success'      => Database::count("SELECT COUNT(*) FROM transactions WHERE status = 'success' AND DATE(initiated_at) = ?", [$today]),
        'total_amount'       => (float)(Database::fetchOne("SELECT COALESCE(SUM(amount),0) AS v FROM transactions WHERE status = 'success'")['v'] ?? 0),
        'today_amount'       => (float)(Database::fetchOne("SELECT COALESCE(SUM(amount),0) AS v FROM transactions WHERE status='success' AND DATE(initiated_at) = ?", [$today])['v'] ?? 0),
    ];
}

/**
 * Returns stats + previous-period comparison for the period picker.
 * $period: today | 7d | 30d | month
 */
function getPeriodStats(string $period = '7d'): array {
    [$start, $end, $prevStart, $prevEnd] = periodDates($period);

    $cur  = periodQuery($start, $end);
    $prev = periodQuery($prevStart, $prevEnd);

    $cur['success_rate']  = $cur['total']  > 0 ? round($cur['success']  / $cur['total']  * 100, 1) : 0;
    $prev['success_rate'] = $prev['total'] > 0 ? round($prev['success'] / $prev['total'] * 100, 1) : 0;

    return ['current' => $cur, 'previous' => $prev, 'start' => $start, 'end' => $end];
}

function periodDates(string $period): array {
    switch ($period) {
        case 'today':
            $s = date('Y-m-d 00:00:00'); $e = date('Y-m-d 23:59:59');
            $ps = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $pe = date('Y-m-d 23:59:59', strtotime('-1 day'));
            break;
        case '30d':
            $s = date('Y-m-d 00:00:00', strtotime('-29 days'));
            $e = date('Y-m-d 23:59:59');
            $ps = date('Y-m-d 00:00:00', strtotime('-59 days'));
            $pe = date('Y-m-d 23:59:59', strtotime('-30 days'));
            break;
        case 'month':
            $s = date('Y-m-01 00:00:00'); $e = date('Y-m-d 23:59:59');
            $ps = date('Y-m-01 00:00:00', strtotime('first day of last month'));
            $pe = date('Y-m-t 23:59:59', strtotime('last month'));
            break;
        default: // 7d
            $s = date('Y-m-d 00:00:00', strtotime('-6 days'));
            $e = date('Y-m-d 23:59:59');
            $ps = date('Y-m-d 00:00:00', strtotime('-13 days'));
            $pe = date('Y-m-d 23:59:59', strtotime('-7 days'));
    }
    return [$s, $e, $ps, $pe];
}

function periodQuery(string $start, string $end): array {
    $row = Database::fetchOne("
        SELECT
            COUNT(*) AS total,
            SUM(status='success') AS success,
            SUM(status='failed')  AS failed,
            SUM(status='pending') AS pending,
            COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END), 0) AS revenue
        FROM transactions
        WHERE initiated_at BETWEEN ? AND ?
    ", [$start, $end]);
    return [
        'total'   => (int)($row['total']   ?? 0),
        'success' => (int)($row['success'] ?? 0),
        'failed'  => (int)($row['failed']  ?? 0),
        'pending' => (int)($row['pending'] ?? 0),
        'revenue' => (float)($row['revenue'] ?? 0),
    ];
}

function trendArrow(float $cur, float $prev): array {
    if ($prev == 0) return ['pct' => null, 'dir' => 'neutral'];
    $pct = round((($cur - $prev) / $prev) * 100, 1);
    return ['pct' => abs($pct), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'neutral')];
}

function getChartDataPeriod(string $period = '7d'): array {
    [$start, $end] = periodDates($period);

    // For 'today' show hourly buckets, otherwise daily
    if ($period === 'today') {
        $rows = Database::fetchAll("
            SELECT
                HOUR(initiated_at) AS bucket,
                SUM(status='success') AS success,
                SUM(status='failed')  AS failed,
                SUM(status='pending') AS pending
            FROM transactions
            WHERE initiated_at BETWEEN ? AND ?
            GROUP BY HOUR(initiated_at)
            ORDER BY bucket ASC
        ", [$start, $end]);
        $map = [];
        foreach ($rows as $r) $map[(int)$r['bucket']] = $r;
        $labels = $success = $failed = $pending = [];
        for ($h = 0; $h < 24; $h++) {
            $labels[]  = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            $success[] = (int)($map[$h]['success'] ?? 0);
            $failed[]  = (int)($map[$h]['failed']  ?? 0);
            $pending[] = (int)($map[$h]['pending'] ?? 0);
        }
    } else {
        $rows = Database::fetchAll("
            SELECT
                DATE(initiated_at) AS day,
                SUM(status='success') AS success,
                SUM(status='failed')  AS failed,
                SUM(status='pending') AS pending
            FROM transactions
            WHERE initiated_at BETWEEN ? AND ?
            GROUP BY DATE(initiated_at)
            ORDER BY day ASC
        ", [$start, $end]);
        $map = [];
        foreach ($rows as $r) $map[$r['day']] = $r;

        $labels = $success = $failed = $pending = [];
        $cur = strtotime(substr($start, 0, 10));
        $fin = strtotime(substr($end,   0, 10));
        $fmt = ($period === '30d' || $period === 'month') ? 'd M' : 'D d';
        while ($cur <= $fin) {
            $key = date('Y-m-d', $cur);
            $labels[]  = date($fmt, $cur);
            $success[] = (int)($map[$key]['success'] ?? 0);
            $failed[]  = (int)($map[$key]['failed']  ?? 0);
            $pending[] = (int)($map[$key]['pending'] ?? 0);
            $cur = strtotime('+1 day', $cur);
        }
    }
    return compact('labels', 'success', 'failed', 'pending');
}

function getTopCampaigns(int $limit = 5, string $period = '7d'): array {
    [$start, $end] = periodDates($period);
    return Database::fetchAll("
        SELECT
            c.id, c.name, c.amount,
            c.total_recipients,
            c.success_count,
            c.failed_count,
            c.sent_count,
            CASE WHEN c.sent_count > 0 THEN ROUND(c.success_count/c.sent_count*100,1) ELSE 0 END AS success_rate,
            c.total_amount,
            c.status
        FROM campaigns c
        WHERE c.created_at BETWEEN ? AND ?
           OR c.started_at BETWEEN ? AND ?
        GROUP BY c.id
        ORDER BY c.success_count DESC, c.total_amount DESC
        LIMIT ?
    ", [$start, $end, $start, $end, $limit]);
}

function getGroupPerformance(): array {
    return Database::fetchAll("
        SELECT
            g.name,
            g.color,
            COUNT(DISTINCT cu.id) AS customer_count,
            COUNT(t.id)          AS tx_count,
            SUM(t.status='success') AS success_count,
            COALESCE(SUM(CASE WHEN t.status='success' THEN t.amount ELSE 0 END), 0) AS revenue
        FROM customer_groups g
        LEFT JOIN customers cu ON cu.group_name = g.name AND cu.status = 1
        LEFT JOIN transactions t ON t.customer_id = cu.id
        GROUP BY g.id
        ORDER BY revenue DESC
        LIMIT 6
    ");
}

function getRecentTransactions(int $limit = 10): array {
    return Database::fetchAll("
        SELECT t.*, c.name AS customer_name
        FROM transactions t
        LEFT JOIN customers c ON c.id = t.customer_id
        ORDER BY t.initiated_at DESC
        LIMIT ?
    ", [$limit]);
}

function getChartData(): array {
    return getChartDataPeriod('7d');
}

// -------------------------------------------------------
// Logging
// -------------------------------------------------------

function logActivity(int $userId, string $action, string $module = 'general', string $details = ''): void {
    try {
        Database::insert('activity_logs', [
            'user_id'    => $userId ?: null,
            'action'     => $action,
            'module'     => $module,
            'details'    => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable) { /* non-critical */ }
}

// -------------------------------------------------------
// Settings
// -------------------------------------------------------

function getSetting(string $key, string $default = ''): string {
    $row = Database::fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $row ? ($row['setting_value'] ?? $default) : $default;
}

function saveSetting(string $key, string $value, int $userId = 0): void {
    Database::query("
        INSERT INTO settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ", [$key, $value, $userId ?: null]);
}

// -------------------------------------------------------
// CSV / Import
// -------------------------------------------------------

function validateCsvRow(array $row): array {
    $errors = [];
    require_once __DIR__ . '/Mpesa.php';

    if (empty($row['phone'])) {
        $errors[] = 'Phone is required.';
    } elseif (!Mpesa::isValidPhone($row['phone'])) {
        $errors[] = 'Invalid Kenyan phone number: ' . $row['phone'];
    }
    if (empty($row['name'])) {
        $errors[] = 'Name is required.';
    }
    return $errors;
}

function parseCsv(string $filePath): array {
    $rows   = [];
    $errors = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) return ['rows' => [], 'errors' => ['Cannot open file.']];

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $headers = array_map('strtolower', array_map('trim', fgetcsv($handle)));
    $lineNo  = 1;

    while (($data = fgetcsv($handle)) !== false) {
        $lineNo++;
        if (count($data) < 2) continue;
        $row     = array_combine($headers, array_pad($data, count($headers), ''));
        $rowErrs = validateCsvRow($row);
        if ($rowErrs) {
            $errors[] = "Line {$lineNo}: " . implode('; ', $rowErrs);
        } else {
            $rows[] = $row;
        }
    }
    fclose($handle);
    return compact('rows', 'errors');
}

/**
 * Parse CSV using a user-defined column index mapping.
 * $mapping = ['name' => 0, 'phone' => 2, 'email' => 3, ...]  (CSV column indices)
 */
function parseCsvWithMapping(string $filePath, array $mapping): array {
    require_once __DIR__ . '/Mpesa.php';
    $rows   = [];
    $errors = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) return ['rows' => [], 'errors' => ['Cannot open file.']];

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    fgetcsv($handle); // skip header row
    $lineNo = 1;

    while (($data = fgetcsv($handle)) !== false) {
        $lineNo++;
        $data = array_map('trim', $data);
        $row  = [];
        foreach ($mapping as $field => $colIdx) {
            $row[$field] = $data[$colIdx] ?? '';
        }

        $rowErrs = validateCsvRow($row);
        if ($rowErrs) {
            $errors[] = "Row {$lineNo}: " . implode('; ', $rowErrs);
        } else {
            $rows[] = $row;
        }
    }
    fclose($handle);
    return compact('rows', 'errors');
}
