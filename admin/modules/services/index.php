<?php
/**
 * Services Management - All Services
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check if user is logged in and is admin
if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}

$user = $session->getUser();

// Handle DELETE service
if (isset($_GET['delete'])) {
    $service_id = (int)$_GET['delete'];
    
    try {
        // First get the image to delete later
        $stmt = $db->prepare("SELECT featured_image FROM services WHERE service_id = ?");
        $stmt->execute([$service_id]);
        $service = $stmt->fetch();
        
        // Delete service (packages and features will be deleted by cascade)
        $stmt = $db->prepare("DELETE FROM services WHERE service_id = ?");
        $stmt->execute([$service_id]);
        
        // Delete image file if exists
        if ($service && $service['featured_image']) {
            $image_path = dirname(dirname(dirname(dirname(__FILE__)))) . $service['featured_image'];
            if (file_exists($image_path)) {
                unlink($image_path);
            }
        }
        
        $session->setFlash('success', 'Service deleted successfully!');
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error deleting service: ' . $e->getMessage());
        error_log("Delete Service Error: " . $e->getMessage());
    }
    
    redirect(url('/admin/modules/services/index.php'));
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_services'])) {
    $action = $_POST['bulk_action'];
    $selected_services = $_POST['selected_services'];
    
    if (!empty($selected_services)) {
        try {
            $placeholders = implode(',', array_fill(0, count($selected_services), '?'));
            
            if ($action === 'delete') {
                // Get images first
                $stmt = $db->prepare("SELECT featured_image FROM services WHERE service_id IN ($placeholders)");
                $stmt->execute($selected_services);
                $services = $stmt->fetchAll();
                
                // Delete services
                $stmt = $db->prepare("DELETE FROM services WHERE service_id IN ($placeholders)");
                $stmt->execute($selected_services);
                
                // Delete image files
                foreach ($services as $service) {
                    if ($service['featured_image']) {
                        $image_path = dirname(dirname(dirname(dirname(__FILE__)))) . $service['featured_image'];
                        if (file_exists($image_path)) {
                            unlink($image_path);
                        }
                    }
                }
                
                $session->setFlash('success', 'Selected services deleted successfully.');
                
            } elseif ($action === 'activate') {
                $stmt = $db->prepare("UPDATE services SET is_active = 1 WHERE service_id IN ($placeholders)");
                $stmt->execute($selected_services);
                $session->setFlash('success', 'Selected services activated successfully.');
                
            } elseif ($action === 'deactivate') {
                $stmt = $db->prepare("UPDATE services SET is_active = 0 WHERE service_id IN ($placeholders)");
                $stmt->execute($selected_services);
                $session->setFlash('success', 'Selected services deactivated successfully.');
            }
        } catch (PDOException $e) {
            error_log("Bulk Action Error: " . $e->getMessage());
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }
    
    redirect(url('/admin/modules/services/index.php'));
}

// Get all services with categories
$sql = "SELECT s.*, sc.category_name 
        FROM services s 
        LEFT JOIN service_categories sc ON s.service_category_id = sc.service_category_id 
        ORDER BY s.display_order, s.service_name";

$stmt = $db->query($sql);
$services = $stmt->fetchAll();

// Count stats
$total_services = count($services);
$active_services = count(array_filter($services, fn($s) => $s['is_active']));
$popular_services = count(array_filter($services, fn($s) => $s['is_popular']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services Management | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/services.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .services-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-lg);
            margin-bottom: var(--space-xl);
        }
        
        .stat-card-small {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-md);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-normal);
        }
        
        .stat-card-small:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card-small i {
            font-size: 1.5rem;
            width: 50px;
            height: 50px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-card-small.total i {
            background: rgba(0, 0, 0, 0.1);
            color: var(--color-black);
        }
        
        .stat-card-small.active i {
            background: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .stat-card-small.popular i {
            background: rgba(255, 193, 7, 0.1);
            color: #ff9800;
        }
        
        .stat-card-small .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            color: var(--color-black);
        }
        
        .stat-card-small .stat-label {
            font-size: 0.875rem;
            color: var(--color-gray-600);
            margin: 0;
        }
        
        .service-image {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--color-gray-100);
        }
        
        .service-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .price-badge {
            background: rgba(0, 200, 83, 0.1);
            color: var(--color-success-dark);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .package-count {
            background: rgba(33, 150, 243, 0.1);
            color: var(--color-info-dark);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .duration-badge {
            background: rgba(255, 152, 0, 0.1);
            color: var(--color-warning-dark);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            display: inline-block;
        }
        
        .service-title-info h4 {
            margin: 0 0 4px;
            font-size: 0.875rem;
        }
        
        .service-title-info p {
            margin: 0;
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .category-badge {
            background: var(--color-gray-100);
            color: var(--color-gray-700);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            display: inline-block;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(0, 200, 83, 0.1);
            color: var(--color-success-dark);
        }
        
        .status-inactive {
            background: var(--color-gray-100);
            color: var(--color-gray-600);
        }
        
        .popular-badge {
            background: rgba(255, 193, 7, 0.1);
            color: #ff9800;
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .order-number {
            background: var(--color-gray-100);
            color: var(--color-gray-700);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
        }
        
        .action-buttons {
            display: flex;
            gap: var(--space-xs);
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--color-gray-600);
            transition: all var(--transition-fast);
            border: 1px solid transparent;
        }
        
        .btn-action:hover {
            background-color: var(--color-gray-100);
            color: var(--color-black);
            border-color: var(--color-gray-300);
            transform: translateY(-2px);
        }
        
        .btn-edit:hover {
            background-color: rgba(33, 150, 243, 0.1);
            color: var(--color-info);
        }
        
        .btn-packages:hover {
            background-color: rgba(156, 39, 176, 0.1);
            color: #9c27b0;
        }
        
        .btn-view:hover {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .btn-delete:hover {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--color-error);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: var(--z-modal);
            align-items: center;
            justify-content: center;
            padding: var(--space-md);
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(3px);
        }
        
        .modal-content {
            position: relative;
            background-color: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-xl);
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: transform var(--transition-normal);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--color-gray-200);
        }
        
        .modal.active .modal-content {
            transform: scale(1);
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-lg);
            padding-bottom: var(--space-md);
            border-bottom: 1px solid var(--color-gray-200);
        }
        
        .modal-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--color-black);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--color-gray-500);
            transition: color var(--transition-fast);
            line-height: 1;
        }
        
        .modal-close:hover {
            color: var(--color-black);
        }
        
        .modal-body {
            margin-bottom: var(--space-xl);
        }
        
        .modal-body p {
            margin-bottom: var(--space-sm);
            color: var(--color-gray-700);
        }
        
        .text-warning {
            color: var(--color-warning);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: 0.875rem;
            margin-top: var(--space-sm);
        }
        
        .modal-footer {
            display: flex;
            gap: var(--space-md);
            justify-content: flex-end;
            padding-top: var(--space-md);
            border-top: 1px solid var(--color-gray-200);
        }
        
        .empty-state {
            text-align: center;
            padding: var(--space-3xl) var(--space-xl);
        }
        
        .empty-state-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto var(--space-lg);
            background-color: var(--color-gray-100);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--color-gray-400);
        }
        
        .empty-state h3 {
            margin-bottom: var(--space-md);
            color: var(--color-black);
            font-size: 1.25rem;
        }
        
        .empty-state p {
            max-width: 400px;
            margin: 0 auto var(--space-xl);
            color: var(--color-gray-600);
        }
        
        .alert {
            display: flex;
            align-items: flex-start;
            gap: var(--space-md);
            padding: var(--space-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-md);
            border: 1px solid transparent;
            animation: slideInDown 0.3s ease-out;
        }
        
        .alert-success {
            background-color: rgba(0, 200, 83, 0.1);
            border-color: rgba(0, 200, 83, 0.3);
            color: var(--color-success-dark);
        }
        
        .alert-error {
            background-color: rgba(244, 67, 54, 0.1);
            border-color: rgba(244, 67, 54, 0.3);
            color: var(--color-error-dark);
        }
        
        .alert-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            opacity: 0.7;
            cursor: pointer;
            margin-left: auto;
            padding: 0;
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .bulk-actions-form {
            display: flex;
            gap: var(--space-sm);
            align-items: center;
        }
        
        .bulk-action-select {
            padding: 8px 12px;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            background-color: var(--color-white);
            color: var(--color-gray-800);
        }
        
        .bulk-action-select:focus {
            outline: none;
            border-color: var(--color-black);
        }
    </style>
</head>
<body>
    <!-- Admin Header -->
    <?php include '../../includes/admin-header.php'; ?>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-cogs"></i>
                    Services Management
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/services/add.php'); ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Service
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <div class="alert-content"><?php echo $session->getFlash('success'); ?></div>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($session->hasFlash('error')): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <div class="alert-content"><?php echo $session->getFlash('error'); ?></div>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Services Stats -->
            <div class="services-stats">
                <div class="stat-card-small total">
                    <i class="fas fa-cogs"></i>
                    <div>
                        <h3 class="stat-value"><?php echo $total_services; ?></h3>
                        <p class="stat-label">Total Services</p>
                    </div>
                </div>
                
                <div class="stat-card-small active">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <h3 class="stat-value"><?php echo $active_services; ?></h3>
                        <p class="stat-label">Active Services</p>
                    </div>
                </div>
                
                <div class="stat-card-small popular">
                    <i class="fas fa-star"></i>
                    <div>
                        <h3 class="stat-value"><?php echo $popular_services; ?></h3>
                        <p class="stat-label">Popular Services</p>
                    </div>
                </div>
            </div>

            <!-- Services Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        All Services
                    </h3>
                    
                    <!-- Bulk Actions -->
                    <form method="POST" action="" class="bulk-actions-form">
                        <select name="bulk_action" class="bulk-action-select">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-outline" onclick="return confirmBulkAction()">
                            Apply
                        </button>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($services)): ?>
                        <div class="table-responsive">
                            <table class="table services-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell" width="40">
                                            <input type="checkbox" id="select-all">
                                        </th>
                                        <th width="80">Image</th>
                                        <th>Service Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Duration</th>
                                        <th>Packages</th>
                                        <th>Status</th>
                                        <th>Popular</th>
                                        <th>Order</th>
                                        <th width="120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($services as $service): 
                                        // Get package count
                                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM service_packages WHERE service_id = ?");
                                        $stmt->execute([$service['service_id']]);
                                        $package_count = $stmt->fetch()['count'];
                                    ?>
                                        <tr>
                                            <td class="checkbox-cell">
                                                <input type="checkbox" 
                                                       name="selected_services[]" 
                                                       value="<?php echo $service['service_id']; ?>"
                                                       class="service-checkbox">
                                            </td>
                                            <td>
                                                <div class="service-image">
                                                    <?php if ($service['featured_image']): ?>
                                                        <img src="<?php echo url($service['featured_image']); ?>" 
                                                             alt="<?php echo e($service['service_name']); ?>"
                                                             onerror="this.src='<?php echo url('assets/images/default-service.jpg'); ?>'">
                                                    <?php else: ?>
                                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--color-gray-400);">
                                                            <i class="fas fa-cog"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="service-title-info">
                                                    <h4>
                                                        <a href="<?php echo url('admin/modules/services/edit.php?id=' . $service['service_id']); ?>" style="color: var(--color-black);">
                                                            <?php echo e($service['service_name']); ?>
                                                        </a>
                                                    </h4>
                                                    <p><?php echo e(substr($service['short_description'], 0, 60)); ?>...</p>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="category-badge">
                                                    <?php echo e($service['category_name'] ?: 'Uncategorized'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($service['base_price']): ?>
                                                    <span class="price-badge">
                                                        <?php echo number_format($service['base_price'], 0); ?> XAF
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-gray-500">Custom</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($service['estimated_duration']): ?>
                                                    <span class="duration-badge">
                                                        <?php echo e($service['estimated_duration']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="<?php echo url('/admin/modules/services/packages.php?service_id=' . $service['service_id']); ?>" class="package-count">
                                                    <?php echo $package_count; ?> packages
                                                </a>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $service['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($service['is_popular']): ?>
                                                    <span class="popular-badge">
                                                        <i class="fas fa-star"></i> Popular
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="order-number">
                                                    <?php echo $service['display_order']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('admin/modules/services/edit.php?id=' . $service['service_id']); ?>" 
                                                       class="btn-action btn-edit"
                                                       title="Edit Service">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('admin/modules/services/packages.php?service_id=' . $service['service_id']); ?>" 
                                                       class="btn-action btn-packages"
                                                       title="Manage Packages">
                                                        <i class="fas fa-box"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('service/' . $service['slug']); ?>" 
                                                       target="_blank"
                                                       class="btn-action btn-view"
                                                       title="View Service">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-delete delete-service-btn"
                                                            data-service-id="<?php echo $service['service_id']; ?>"
                                                            data-service-name="<?php echo e($service['service_name']); ?>"
                                                            title="Delete Service">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <h3>No Services Found</h3>
                            <p>No services have been added yet. Add your first service to get started.</p>
                            <a href="<?php echo url('/admin/modules/services/add.php'); ?>" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Your First Service
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete Service</h3>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the service "<strong id="serviceToDelete"></strong>"?</p>
                <p class="text-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    This will also delete all packages and features under this service! This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="modalCancel">Cancel</button>
                <a href="#" id="confirmDelete" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete Service
                </a>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Select all checkbox
            const selectAll = document.getElementById('select-all');
            const serviceCheckboxes = document.querySelectorAll('.service-checkbox');
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    serviceCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
            
            // Delete service buttons
            const deleteButtons = document.querySelectorAll('.delete-service-btn');
            const modal = document.getElementById('deleteModal');
            const modalClose = document.getElementById('modalClose');
            const modalCancel = document.getElementById('modalCancel');
            const modalBackdrop = document.querySelector('.modal-backdrop');
            const serviceToDeleteSpan = document.getElementById('serviceToDelete');
            const confirmDeleteLink = document.getElementById('confirmDelete');
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const serviceId = this.dataset.serviceId;
                    const serviceName = this.dataset.serviceName;
                    
                    serviceToDeleteSpan.textContent = serviceName;
                    confirmDeleteLink.href = '?delete=' + serviceId;
                    
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });
            });
            
            // Close modal functions
            function closeModal() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
            
            if (modalClose) {
                modalClose.addEventListener('click', closeModal);
            }
            
            if (modalCancel) {
                modalCancel.addEventListener('click', closeModal);
            }
            
            if (modalBackdrop) {
                modalBackdrop.addEventListener('click', closeModal);
            }
            
            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    closeModal();
                }
            });
            
            // Alert close buttons
            document.querySelectorAll('.alert-close').forEach(button => {
                button.addEventListener('click', function() {
                    const alert = this.closest('.alert');
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
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
        });
        
        // Confirm bulk action
        function confirmBulkAction() {
            const checkboxes = document.querySelectorAll('.service-checkbox:checked');
            const action = document.querySelector('.bulk-action-select').value;
            
            if (checkboxes.length === 0) {
                alert('Please select at least one service.');
                return false;
            }
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            if (action === 'delete') {
                return confirm('Are you sure you want to delete the selected services? This action cannot be undone.');
            }
            
            return true;
        }
    </script>
</body>
</html>