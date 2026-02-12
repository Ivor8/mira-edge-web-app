<?php
/**
 * Analytics Dashboard
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}

$user = $session->getUser();

// Date range filter
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['to'] ?? date('Y-m-d');

// Overall stats
$stmt = $db->prepare("SELECT COUNT(*) as total_visits, COUNT(DISTINCT ip_address) as unique_visitors, COUNT(DISTINCT page_url) as unique_pages FROM page_visits WHERE DATE(visited_at) BETWEEN ? AND ?");
$stmt->execute([$date_from, $date_to]);
$stats = $stmt->fetch();

// Total visits today
$stmt = $db->prepare("SELECT COUNT(*) as today_visits FROM page_visits WHERE DATE(visited_at) = ?");
$stmt->execute([date('Y-m-d')]);
$today = $stmt->fetch()['today_visits'];

// Popular pages
$stmt = $db->prepare("SELECT page_url, page_title, COUNT(*) as visits FROM page_visits WHERE DATE(visited_at) BETWEEN ? AND ? GROUP BY page_url, page_title ORDER BY visits DESC LIMIT 10");
$stmt->execute([$date_from, $date_to]);
$popular_pages = $stmt->fetchAll();

// Traffic by country
$stmt = $db->prepare("SELECT country, COUNT(*) as visits FROM page_visits WHERE DATE(visited_at) BETWEEN ? AND ? AND country IS NOT NULL GROUP BY country ORDER BY visits DESC LIMIT 10");
$stmt->execute([$date_from, $date_to]);
$countries = $stmt->fetchAll();

// Traffic by device
$stmt = $db->prepare("SELECT device_type, COUNT(*) as visits FROM page_visits WHERE DATE(visited_at) BETWEEN ? AND ? AND device_type IS NOT NULL GROUP BY device_type ORDER BY visits DESC");
$stmt->execute([$date_from, $date_to]);
$devices = $stmt->fetchAll();

// Traffic by browser
$stmt = $db->prepare("SELECT browser, COUNT(*) as visits FROM page_visits WHERE DATE(visited_at) BETWEEN ? AND ? AND browser IS NOT NULL GROUP BY browser ORDER BY visits DESC LIMIT 8");
$stmt->execute([$date_from, $date_to]);
$browsers = $stmt->fetchAll();

// Top referrers
$stmt = $db->prepare("SELECT referrer_url, COUNT(*) as visits FROM page_visits WHERE DATE(visited_at) BETWEEN ? AND ? AND referrer_url IS NOT NULL AND referrer_url != '' GROUP BY referrer_url ORDER BY visits DESC LIMIT 10");
$stmt->execute([$date_from, $date_to]);
$referrers = $stmt->fetchAll();

// Daily visits (last 7 days)
$stmt = $db->prepare("SELECT DATE(visited_at) as date, COUNT(*) as visits FROM page_visits WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(visited_at) ORDER BY date");
$stmt->execute();
$daily_visits = $stmt->fetchAll();

// Recent visits
$stmt = $db->prepare("SELECT * FROM page_visits WHERE DATE(visited_at) BETWEEN ? AND ? ORDER BY visited_at DESC LIMIT 20");
$stmt->execute([$date_from, $date_to]);
$recent_visits = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Analytics | Admin</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analytics-header {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }
        .date-picker {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .date-picker input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #000;
        }
        .stat-card.blue { border-left-color: #2196F3; }
        .stat-card.green { border-left-color: #4CAF50; }
        .stat-card.orange { border-left-color: #FF9800; }
        .stat-card h3 {
            margin: 0;
            font-size: 14px;
            color: #999;
            text-transform: uppercase;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0 0;
            color: #333;
        }
        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            position: relative;
            height: 350px;
        }
        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 1024px) {
            .two-column { grid-template-columns: 1fr; }
        }
        .list-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .list-section h3 {
            margin-top: 0;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-chart-bar"></i> Analytics</h1>
            </div>

            <div class="analytics-header">
                <form method="get" class="date-picker" style="flex:1;">
                    <label>From:</label>
                    <input type="date" name="from" value="<?php echo e($date_from); ?>">
                    <label>To:</label>
                    <input type="date" name="to" value="<?php echo e($date_to); ?>">
                    <button class="btn" type="submit">Filter</button>
                </form>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card blue">
                    <h3>Total Visits</h3>
                    <div class="stat-value"><?php echo number_format($stats['total_visits']); ?></div>
                </div>
                <div class="stat-card green">
                    <h3>Unique Visitors</h3>
                    <div class="stat-value"><?php echo number_format($stats['unique_visitors']); ?></div>
                </div>
                <div class="stat-card orange">
                    <h3>Unique Pages</h3>
                    <div class="stat-value"><?php echo number_format($stats['unique_pages']); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Today's Visits</h3>
                    <div class="stat-value"><?php echo $today; ?></div>
                </div>
            </div>

            <!-- Daily Traffic Chart -->
            <div class="chart-container">
                <canvas id="dailyChart"></canvas>
            </div>

            <div class="two-column">
                <!-- Popular Pages -->
                <div class="list-section">
                    <h3><i class="fas fa-file"></i> Top Pages</h3>
                    <table class="table">
                        <thead><tr><th>Page</th><th>Visits</th></tr></thead>
                        <tbody>
                            <?php foreach ($popular_pages as $p): ?>
                                <tr>
                                    <td><?php echo e(strlen($p['page_url']) > 40 ? substr($p['page_url'], 0, 40) . '...' : $p['page_url']); ?></td>
                                    <td><strong><?php echo number_format($p['visits']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Countries -->
                <div class="list-section">
                    <h3><i class="fas fa-globe"></i> Traffic by Country</h3>
                    <table class="table">
                        <thead><tr><th>Country</th><th>Visits</th></tr></thead>
                        <tbody>
                            <?php foreach ($countries as $c): ?>
                                <tr>
                                    <td><?php echo e($c['country']); ?></td>
                                    <td><strong><?php echo number_format($c['visits']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="two-column">
                <!-- Devices -->
                <div class="list-section">
                    <h3><i class="fas fa-mobile-alt"></i> By Device</h3>
                    <table class="table">
                        <thead><tr><th>Device</th><th>Visits</th><th>%</th></tr></thead>
                        <tbody>
                            <?php 
                            $total_device_visits = array_sum(array_column($devices, 'visits'));
                            foreach ($devices as $d): 
                                $pct = $total_device_visits > 0 ? round(($d['visits']/$total_device_visits)*100) : 0;
                            ?>
                                <tr>
                                    <td><?php echo e(ucfirst($d['device_type'])); ?></td>
                                    <td><?php echo number_format($d['visits']); ?></td>
                                    <td><?php echo $pct; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Browsers -->
                <div class="list-section">
                    <h3><i class="fas fa-chrome"></i> By Browser</h3>
                    <table class="table">
                        <thead><tr><th>Browser</th><th>Visits</th></tr></thead>
                        <tbody>
                            <?php foreach ($browsers as $b): ?>
                                <tr>
                                    <td><?php echo e($b['browser']); ?></td>
                                    <td><?php echo number_format($b['visits']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Referrers -->
            <div class="list-section">
                <h3><i class="fas fa-link"></i> Top Referrers</h3>
                <table class="table">
                    <thead><tr><th>Referrer</th><th>Visits</th></tr></thead>
                    <tbody>
                        <?php foreach ($referrers as $r): ?>
                            <tr>
                                <td><?php echo e(strlen($r['referrer_url']) > 60 ? substr($r['referrer_url'], 0, 60) . '...' : $r['referrer_url']); ?></td>
                                <td><?php echo number_format($r['visits']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Visits -->
            <div class="list-section">
                <h3><i class="fas fa-history"></i> Recent Visits</h3>
                <table class="table">
                    <thead><tr><th>Page</th><th>IP</th><th>Country</th><th>Device</th><th>Time</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_visits as $v): ?>
                            <tr>
                                <td><?php echo e(strlen($v['page_url']) > 30 ? substr($v['page_url'], 0, 30) . '...' : $v['page_url']); ?></td>
                                <td><?php echo e($v['ip_address']); ?></td>
                                <td><?php echo e($v['country'] ?? '-'); ?></td>
                                <td><?php echo e($v['device_type'] ?? '-'); ?></td>
                                <td><?php echo e($v['visited_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <script>
        // Daily traffic chart
        const dailyData = <?php echo json_encode($daily_visits); ?>;
        const labels = dailyData.map(d => d.date);
        const data = dailyData.map(d => d.visits);

        const ctx = document.getElementById('dailyChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Visits',
                    data: data,
                    borderColor: '#2196F3',
                    backgroundColor: 'rgba(33, 150, 243, 0.1)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 5,
                    pointBackgroundColor: '#2196F3'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true },
                    title: { display: true, text: 'Daily Visits (Last 7 Days)' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    </script>
</body>
</html>