<?php
/**
 * Dashboard Analytics API
 * Returns period stats, chart data, top campaigns, group performance.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
Auth::start();

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 401);
}

$period      = in_array($_GET['period'] ?? '', ['today','7d','30d','month','custom']) ? $_GET['period'] : '7d';
$customStart = trim($_GET['start_date'] ?? '');
$customEnd   = trim($_GET['end_date']   ?? '');
if ($period === 'custom' && (!$customStart || !$customEnd)) {
    $period = '7d';
}

$periodStats  = getPeriodStats($period, $customStart, $customEnd);
$chartData    = getChartDataPeriod($period, $customStart, $customEnd);
$topCampaigns = getTopCampaigns(5, $period, $customStart, $customEnd);
$groupPerf    = getGroupPerformance();
$cur  = $periodStats['current'];
$prev = $periodStats['previous'];

$trends = [
    'total'        => trendArrow($cur['total'],        $prev['total']),
    'success'      => trendArrow($cur['success'],      $prev['success']),
    'revenue'      => trendArrow($cur['revenue'],      $prev['revenue']),
    'success_rate' => trendArrow($cur['success_rate'], $prev['success_rate']),
];

$hasActive = Database::count(
    "SELECT COUNT(*) FROM campaigns WHERE status IN ('running','queued')"
) > 0;

jsonResponse([
    'success'       => true,
    'period'        => $period,
    'stats'         => $cur,
    'prev_stats'    => $prev,
    'trends'        => $trends,
    'chart'         => $chartData,
    'top_campaigns' => $topCampaigns,
    'group_perf'    => $groupPerf,
    'has_active'    => $hasActive,
]);
