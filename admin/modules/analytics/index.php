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

// get user details
$user = $session->getUser();
$user_id = $user['user_id'];

// Handle Google Analytics configuration save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ga_config'])) {
    $ga_property_id = trim($_POST['ga_property_id'] ?? '');
    $ga_api_key = trim($_POST['ga_api_key'] ?? '');

    // Validate GA Property ID format
    if (!empty($ga_property_id) && !preg_match('/^G-[A-Z0-9]+$/', $ga_property_id)) {
        $session->setFlash('error', 'Invalid Google Analytics Property ID format. Should be G-XXXXXXXXXX');
    } else {
        // Save settings
        updateSetting('google_analytics_id', $ga_property_id);
        updateSetting('google_analytics_api_key', $ga_api_key);

        $session->setFlash('success', 'Google Analytics configuration saved successfully.');

        // Log activity
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, activity_type, description, ip_address, user_agent)
            VALUES (?, 'ga_config_updated', ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            'Google Analytics configuration updated',
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // Redirect to refresh the page
        redirect(url('/admin/modules/analytics/index.php'));
    }
}

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

// Check if Google Analytics is configured
$ga_property_id = getSetting('google_analytics_id', '');
$ga_api_key = getSetting('google_analytics_api_key', '');
$ga_connected = !empty($ga_property_id) && !empty($ga_api_key);

// Get Google Analytics settings
$ga_settings = [
    'property_id' => $ga_property_id,
    'api_key' => $ga_api_key,
    'connected' => $ga_connected
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Analytics | Admin</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .ga-status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            border-radius: 8px;
            font-weight: 500;
        }
        .ga-status.connected {
            background: #e8f5e8;
            color: #2e7d32;
            border: 1px solid #4caf50;
        }
        .ga-status.not-connected {
            background: #fff3e0;
            color: #f57c00;
            border: 1px solid #ff9800;
        }
        .ga-metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .ga-metric {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .ga-metric h4 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 0.9rem;
        }
        .ga-value {
            font-size: 2rem;
            font-weight: bold;
            color: #2196f3;
            margin-bottom: 5px;
        }
        .ga-keywords, .ga-sources {
            font-size: 0.9rem;
            color: #666;
        }
        .ga-keywords ul, .ga-sources ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .ga-keywords li, .ga-sources li {
            padding: 3px 0;
            border-bottom: 1px solid #eee;
        }
        .ga-setup-instructions ol {
            padding-left: 20px;
        }
        .ga-setup-instructions li {
            margin-bottom: 8px;
        }
        .ga-setup-instructions a {
            color: #2196f3;
            text-decoration: none;
        }
        .ga-config-form h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
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

            <!-- Google Analytics Integration -->
            <div class="list-section">
                <h3><i class="fab fa-google"></i> Google Analytics Integration</h3>
                <?php if ($ga_connected): ?>
                    <div class="ga-status connected">
                        <i class="fas fa-check-circle"></i>
                        <span>Connected to Google Analytics (Property: <?php echo e($ga_property_id); ?>)</span>
                    </div>
                    <div class="ga-metrics" style="margin-top: 20px;">
                        <div class="ga-metric-grid">
                            <div class="ga-metric">
                                <h4>Organic Search Traffic</h4>
                                <div class="ga-value" id="ga-organic">Loading...</div>
                                <small>Last 30 days</small>
                            </div>
                            <div class="ga-metric">
                                <h4>Top Keywords</h4>
                                <div class="ga-keywords" id="ga-keywords">Loading...</div>
                            </div>
                            <div class="ga-metric">
                                <h4>Traffic Sources</h4>
                                <div class="ga-sources" id="ga-sources">Loading...</div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="ga-status not-connected">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Google Analytics Not Connected</span>
                    </div>
                    <div class="ga-setup-instructions" style="margin-top: 20px;">
                        <h4>How to Connect Google Analytics:</h4>
                        <ol>
                            <li><strong>Create Google Analytics Account:</strong> Go to <a href="https://analytics.google.com" target="_blank">analytics.google.com</a></li>
                            <li><strong>Create Property:</strong> Add your website domain</li>
                            <li><strong>Get Property ID:</strong> Copy the "G-XXXXXXXXXX" ID</li>
                            <li><strong>Get API Key:</strong> Go to Google Cloud Console → APIs & Services → Credentials</li>
                            <li><strong>Configure Below:</strong> Enter your Property ID and API Key</li>
                        </ol>

                        <form method="post" class="ga-config-form" style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                            <h4>Configure Google Analytics</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                <div>
                                    <label for="ga_property_id" style="display: block; margin-bottom: 5px; font-weight: 500;">Property ID (G-XXXXXXXXXX)</label>
                                    <input type="text" id="ga_property_id" name="ga_property_id" value="<?php echo e($ga_property_id); ?>"
                                           placeholder="G-XXXXXXXXXX" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                <div>
                                    <label for="ga_api_key" style="display: block; margin-bottom: 5px; font-weight: 500;">API Key</label>
                                    <input type="password" id="ga_api_key" name="ga_api_key" value="<?php echo e($ga_api_key); ?>"
                                           placeholder="Your API Key" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                            </div>
                            <button type="submit" name="save_ga_config" class="btn" style="background: #4285f4; color: white; border: none;">
                                <i class="fas fa-save"></i> Save Configuration
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
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
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
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

        <?php if ($ga_connected): ?>
        // Google Analytics Data Fetching
        async function fetchGoogleAnalytics() {
            try {
                // This is a placeholder for Google Analytics API integration
                // In a real implementation, you would use the Google Analytics Data API

                // For now, show placeholder data
                document.getElementById('ga-organic').textContent = 'API Integration Required';
                document.getElementById('ga-keywords').innerHTML = '<ul><li>Setup required</li><li>API key needed</li></ul>';
                document.getElementById('ga-sources').innerHTML = '<ul><li>Google: Setup required</li><li>Direct: Setup required</li></ul>';

                // Uncomment and modify this when you have the API set up:
                /*
                const response = await fetch('/admin/api/ga-data.php', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (response.ok) {
                    const data = await response.json();

                    // Update the UI with real data
                    document.getElementById('ga-organic').textContent = data.organicTraffic || '0';
                    document.getElementById('ga-keywords').innerHTML = data.topKeywords.map(k =>
                        `<li>${k.keyword}: ${k.clicks} clicks</li>`
                    ).join('');
                    document.getElementById('ga-sources').innerHTML = data.trafficSources.map(s =>
                        `<li>${s.source}: ${s.sessions} sessions</li>`
                    ).join('');
                }
                */

            } catch (error) {
                console.error('GA fetch error:', error);
                document.getElementById('ga-organic').textContent = 'Error loading data';
            }
        }

        // Load GA data when page loads
        fetchGoogleAnalytics();
        <?php endif; ?>
    </script>
</body>
</html>