<?php
/**
 * Admin Dashboard - Main Page
 */

require_once '../includes/core/Database.php';
require_once '../includes/core/Session.php';
require_once '../includes/core/Auth.php';
require_once '../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check if user is logged in and is admin
if (!$session->isLoggedIn()) {
    redirect(url('/login.php'));
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect('/');
}

$user = $session->getUser();
$user_id = $user['user_id'];
$user_role = $user['role'];

// Get dashboard statistics
try {
    // Total projects
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM portfolio_projects");
    $stmt->execute();
    $total_projects = $stmt->fetch()['total'];

    // Total services
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM services WHERE is_active = 1");
    $stmt->execute();
    $total_services = $stmt->fetch()['total'];

    // Total orders
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM service_orders");
    $stmt->execute();
    $total_orders = $stmt->fetch()['total'];

    // Pending orders
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM service_orders WHERE order_status = 'pending'");
    $stmt->execute();
    $pending_orders = $stmt->fetch()['total'];

    // Total blog posts
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM blog_posts");
    $stmt->execute();
    $total_blog_posts = $stmt->fetch()['total'];

    // Total job listings
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM job_listings WHERE is_active = 1");
    $stmt->execute();
    $total_jobs = $stmt->fetch()['total'];

    // Total team members
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
    $stmt->execute();
    $total_team = $stmt->fetch()['total'];

    // Recent orders
    $stmt = $db->prepare("
        SELECT so.*, s.service_name 
        FROM service_orders so 
        LEFT JOIN services s ON so.service_id = s.service_id 
        ORDER BY so.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_orders = $stmt->fetchAll();

    // Recent projects
    $stmt = $db->prepare("
        SELECT * FROM portfolio_projects 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_projects = $stmt->fetchAll();

    // Recent blog posts
    $stmt = $db->prepare("
        SELECT * FROM blog_posts 
        WHERE status = 'published' 
        ORDER BY published_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_blog_posts = $stmt->fetchAll();

    // Recent job applications
    $stmt = $db->prepare("
        SELECT ja.*, jl.job_title 
        FROM job_applications ja 
        LEFT JOIN job_listings jl ON ja.job_id = jl.job_id 
        ORDER BY ja.applied_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_applications = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
    $session->setFlash('error', 'Error loading dashboard statistics');
}

// Get current date info
$current_month = date('m');
$current_year = date('Y');

// Get monthly revenue
try {
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN currency = 'XAF' THEN budget ELSE budget * 650 END) as total_revenue,
            COUNT(*) as total_orders
        FROM service_orders 
        WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?
        AND payment_status = 'paid'
    ");
    $stmt->execute([$current_month, $current_year]);
    $monthly_revenue = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Revenue Stats Error: " . $e->getMessage());
    $monthly_revenue = ['total_revenue' => 0, 'total_orders' => 0];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Mira Edge Technologies</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Admin Header -->
    <?php include 'includes/admin-header.php'; ?>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <?php include 'includes/admin-sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Dashboard</h1>
                <div class="page-actions">
                    <div class="date-display">
                        <i class="fas fa-calendar-alt"></i>
                        <span><?php echo date('F j, Y'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo e($session->getFlash('success')); ?>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($session->hasFlash('error')): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo e($session->getFlash('error')); ?>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="stats-overview">
                <div class="stats-grid">
                    <!-- Total Projects -->
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                        <div class="stat-content">
                            <h3 class="stat-value"><?php echo $total_projects; ?></h3>
                            <p class="stat-label">Total Projects</p>
                            <div class="stat-trend">
                                <i class="fas fa-chart-line"></i>
                                <span>View Portfolio</span>
                            </div>
                        </div>
                    </div>

                    <!-- Total Services -->
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <div class="stat-content">
                            <h3 class="stat-value"><?php echo $total_services; ?></h3>
                            <p class="stat-label">Active Services</p>
                            <div class="stat-trend">
                                <i class="fas fa-chart-line"></i>
                                <span>Manage Services</span>
                            </div>
                        </div>
                    </div>

                    <!-- Total Orders -->
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-content">
                            <h3 class="stat-value"><?php echo $total_orders; ?></h3>
                            <p class="stat-label">Total Orders</p>
                            <div class="stat-trend">
                                <span class="badge badge-danger"><?php echo $pending_orders; ?> pending</span>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Revenue -->
                    <div class="stat-card stat-success">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-content">
                            <h3 class="stat-value">
                                <?php echo number_format($monthly_revenue['total_revenue'] ?? 0); ?> XAF
                            </h3>
                            <p class="stat-label">Monthly Revenue</p>
                            <div class="stat-trend">
                                <i class="fas fa-chart-bar"></i>
                                <span><?php echo $monthly_revenue['total_orders'] ?? 0; ?> orders</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="content-column">
                    <!-- Recent Orders -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-shopping-cart"></i>
                                Recent Orders
                            </h3>
                            <a href="modules/services/orders.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Client</th>
                                            <th>Service</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($recent_orders)): ?>
                                            <?php foreach ($recent_orders as $order): ?>
                                                <tr>
                                                    <td>
                                                        <a href="modules/services/order-details.php?id=<?php echo $order['order_id']; ?>" 
                                                           class="order-number">
                                                            <?php echo e($order['order_number']); ?>
                                                        </a>
                                                    </td>
                                                    <td><?php echo e($order['client_name']); ?></td>
                                                    <td><?php echo e($order['service_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo strtolower($order['order_status']); ?>">
                                                            <?php echo ucfirst($order['order_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo formatDate($order['created_at'], 'M d'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No orders found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Projects -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-project-diagram"></i>
                                Recent Projects
                            </h3>
                            <a href="modules/projects/index.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <div class="projects-list">
                                <?php if (!empty($recent_projects)): ?>
                                    <?php foreach ($recent_projects as $project): ?>
                                        <div class="project-item">
                                            <div class="project-image">
                                                <img src="<?php echo e($project['featured_image'] ?: '/assets/images/default-project.jpg'); ?>" 
                                                     alt="<?php echo e($project['title']); ?>">
                                            </div>
                                            <div class="project-info">
                                                <h4 class="project-title">
                                                    <a href="modules/projects/edit.php?id=<?php echo $project['project_id']; ?>">
                                                        <?php echo e($project['title']); ?>
                                                    </a>
                                                </h4>
                                                <p class="project-description">
                                                    <?php echo e(substr($project['short_description'], 0, 100)); ?>...
                                                </p>
                                                <div class="project-meta">
                                                    <span class="project-status status-<?php echo strtolower($project['status']); ?>">
                                                        <?php echo ucfirst($project['status']); ?>
                                                    </span>
                                                    <span class="project-date">
                                                        <?php echo formatDate($project['created_at'], 'M d, Y'); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-project-diagram"></i>
                                        <p>No projects found</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="content-column">
                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-bolt"></i>
                                Quick Actions
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions-grid">
                                <a href="modules/projects/add.php" class="quick-action">
                                    <div class="quick-action-icon">
                                        <i class="fas fa-plus-circle"></i>
                                    </div>
                                    <div class="quick-action-content">
                                        <h4>Add Project</h4>
                                        <p>Add new portfolio project</p>
                                    </div>
                                </a>

                                <a href="modules/services/add.php" class="quick-action">
                                    <div class="quick-action-icon">
                                        <i class="fas fa-cog"></i>
                                    </div>
                                    <div class="quick-action-content">
                                        <h4>Add Service</h4>
                                        <p>Create new service</p>
                                    </div>
                                </a>

                                <a href="modules/blog/add.php" class="quick-action">
                                    <div class="quick-action-icon">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                    <div class="quick-action-content">
                                        <h4>Write Blog</h4>
                                        <p>Create new blog post</p>
                                    </div>
                                </a>

                                <a href="modules/team/add.php" class="quick-action">
                                    <div class="quick-action-icon">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <div class="quick-action-content">
                                        <h4>Add Team</h4>
                                        <p>Add new team member</p>
                                    </div>
                                </a>

                                <a href="modules/jobs/add.php" class="quick-action">
                                    <div class="quick-action-icon">
                                        <i class="fas fa-briefcase"></i>
                                    </div>
                                    <div class="quick-action-content">
                                        <h4>Post Job</h4>
                                        <p>Create job listing</p>
                                    </div>
                                </a>

                                <a href="modules/settings/index.php" class="quick-action">
                                    <div class="quick-action-icon">
                                        <i class="fas fa-sliders-h"></i>
                                    </div>
                                    <div class="quick-action-content">
                                        <h4>Settings</h4>
                                        <p>Manage settings</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Blog Posts -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-blog"></i>
                                Recent Blog Posts
                            </h3>
                            <a href="modules/blog/index.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <div class="blog-list">
                                <?php if (!empty($recent_blog_posts)): ?>
                                    <?php foreach ($recent_blog_posts as $post): ?>
                                        <div class="blog-item">
                                            <div class="blog-image">
                                                <img src="<?php echo e($post['featured_image'] ?: '/assets/images/default-blog.jpg'); ?>" 
                                                     alt="<?php echo e($post['title']); ?>">
                                            </div>
                                            <div class="blog-info">
                                                <h4 class="blog-title">
                                                    <a href="modules/blog/edit.php?id=<?php echo $post['post_id']; ?>">
                                                        <?php echo e($post['title']); ?>
                                                    </a>
                                                </h4>
                                                <div class="blog-meta">
                                                    <span class="blog-date">
                                                        <?php echo formatDate($post['published_at'], 'M d'); ?>
                                                    </span>
                                                    <span class="blog-views">
                                                        <i class="fas fa-eye"></i> <?php echo $post['views_count']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-blog"></i>
                                        <p>No blog posts found</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Job Applications -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-file-alt"></i>
                                Recent Applications
                            </h3>
                            <a href="modules/jobs/applications.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <div class="applications-list">
                                <?php if (!empty($recent_applications)): ?>
                                    <?php foreach ($recent_applications as $application): ?>
                                        <div class="application-item">
                                            <div class="application-info">
                                                <h4 class="application-name">
                                                    <?php echo e($application['applicant_name']); ?>
                                                </h4>
                                                <p class="application-job">
                                                    <?php echo e($application['job_title']); ?>
                                                </p>
                                                <div class="application-meta">
                                                    <span class="application-status status-<?php echo strtolower($application['application_status']); ?>">
                                                        <?php echo ucfirst($application['application_status']); ?>
                                                    </span>
                                                    <span class="application-date">
                                                        <?php echo formatDate($application['applied_at'], 'M d'); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="application-actions">
                                                <a href="modules/jobs/application-details.php?id=<?php echo $application['application_id']; ?>" 
                                                   class="btn btn-xs btn-outline">
                                                    View
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-file-alt"></i>
                                        <p>No applications found</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats -->
            <div class="additional-stats">
                <div class="stats-row">
                    <div class="stat-box">
                        <div class="stat-box-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-box-content">
                            <h3><?php echo $total_team; ?></h3>
                            <p>Team Members</p>
                        </div>
                    </div>

                    <div class="stat-box">
                        <div class="stat-box-icon">
                            <i class="fas fa-newspaper"></i>
                        </div>
                        <div class="stat-box-content">
                            <h3><?php echo $total_blog_posts; ?></h3>
                            <p>Blog Posts</p>
                        </div>
                    </div>

                    <div class="stat-box">
                        <div class="stat-box-icon">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <div class="stat-box-content">
                            <h3><?php echo $total_jobs; ?></h3>
                            <p>Active Jobs</p>
                        </div>
                    </div>

                    <div class="stat-box">
                        <div class="stat-box-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="stat-box-content">
                            <h3>24</h3>
                            <p>Unread Messages</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js') ; ?>"></script>
    <script>
        // Dashboard specific JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Chart initialization would go here
            // For now, we'll just handle basic interactions
            
            // Alert close buttons
            document.querySelectorAll('.alert-close').forEach(button => {
                button.addEventListener('click', function() {
                    this.parentElement.style.opacity = '0';
                    setTimeout(() => {
                        this.parentElement.remove();
                    }, 300);
                });
            });

            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        if (alert.parentElement) {
                            alert.remove();
                        }
                    }, 300);
                });
            }, 5000);

            // Quick action hover effects
            document.querySelectorAll('.quick-action').forEach(action => {
                action.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                action.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Update time display
            function updateTime() {
                const now = new Date();
                const options = { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                };
                const timeDisplay = document.querySelector('.date-display span');
                if (timeDisplay) {
                    timeDisplay.textContent = now.toLocaleDateString('en-US', options);
                }
            }

            // Update time every minute
            updateTime();
            setInterval(updateTime, 60000);
        });
    </script>
</body>
</html>