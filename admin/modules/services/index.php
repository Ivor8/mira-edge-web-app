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

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_services'])) {
        $action = $_POST['bulk_action'];
        $selected_services = $_POST['selected_services'];
        
        try {
            if ($action === 'delete') {
                $placeholders = implode(',', array_fill(0, count($selected_services), '?'));
                $stmt = $db->prepare("DELETE FROM services WHERE service_id IN ($placeholders)");
                $stmt->execute($selected_services);
                $session->setFlash('success', 'Selected services deleted successfully.');
            } elseif ($action === 'activate') {
                $placeholders = implode(',', array_fill(0, count($selected_services), '?'));
                $stmt = $db->prepare("UPDATE services SET is_active = 1 WHERE service_id IN ($placeholders)");
                $stmt->execute($selected_services);
                $session->setFlash('success', 'Selected services activated successfully.');
            } elseif ($action === 'deactivate') {
                $placeholders = implode(',', array_fill(0, count($selected_services), '?'));
                $stmt = $db->prepare("UPDATE services SET is_active = 0 WHERE service_id IN ($placeholders)");
                $stmt->execute($selected_services);
                $session->setFlash('success', 'Selected services deactivated successfully.');
            }
        } catch (PDOException $e) {
            error_log("Bulk Action Error: " . $e->getMessage());
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }
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
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/services.css'); ?>">
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
        }
        
        .package-count {
            background: rgba(33, 150, 243, 0.1);
            color: var(--color-info-dark);
            padding: 2px 6px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .duration-badge {
            background: rgba(255, 152, 0, 0.1);
            color: var(--color-warning-dark);
            padding: 2px 6px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
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
                        <div class="bulk-actions">
                            <select name="bulk_action" class="bulk-action-select">
                                <option value="">Bulk Actions</option>
                                <option value="activate">Activate</option>
                                <option value="deactivate">Deactivate</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button type="submit" class="btn btn-outline" name="apply_bulk_action">
                                Apply
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($services)): ?>
                        <div class="table-responsive">
                            <table class="table services-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="select-all" class="select-all-checkbox">
                                        </th>
                                        <th class="image-cell">Image</th>
                                        <th class="name-cell">Service Name</th>
                                        <th class="category-cell">Category</th>
                                        <th class="price-cell">Price</th>
                                        <th class="duration-cell">Duration</th>
                                        <th class="packages-cell">Packages</th>
                                        <th class="status-cell">Status</th>
                                        <th class="popular-cell">Popular</th>
                                        <th class="order-cell">Order</th>
                                        <th class="actions-cell">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($services as $service): 
                                        // Get package count
                                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM service_packages WHERE service_id = ?");
                                        $stmt->execute([$service['service_id']]);
                                        $package_count = $stmt->fetch()['count'];
                                    ?>
                                        <tr class="service-row" data-service-id="<?php echo $service['service_id']; ?>">
                                            <td class="checkbox-cell">
                                                <input type="checkbox" 
                                                       name="selected_services[]" 
                                                       value="<?php echo $service['service_id']; ?>"
                                                       class="service-checkbox">
                                            </td>
                                            <td class="image-cell">
                                                <div class="service-image">
                                                    <?php if ($service['featured_image']): ?>
                                                        <img src="<?php echo url($service['featured_image']); ?>" 
                                                             alt="<?php echo e($service['service_name']); ?>"
                                                             onerror="this.src='<?php echo url('/assets/images/default-service.jpg'); ?>'">
                                                    <?php else: ?>
                                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--color-gray-400);">
                                                            <i class="fas fa-cog fa-lg"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="name-cell">
                                                <div class="service-title-info">
                                                    <h4 class="service-title">
                                                        <a href="<?php echo url('/admin/modules/services/edit.php?id=' . $service['service_id']); ?>">
                                                            <?php echo e($service['service_name']); ?>
                                                        </a>
                                                    </h4>
                                                    <p class="service-description">
                                                        <?php echo e(substr($service['short_description'], 0, 80)); ?>...
                                                    </p>
                                                </div>
                                            </td>
                                            <td class="category-cell">
                                                <span class="category-badge">
                                                    <?php echo e($service['category_name'] ?: 'Uncategorized'); ?>
                                                </span>
                                            </td>
                                            <td class="price-cell">
                                                <?php if ($service['base_price']): ?>
                                                    <span class="price-badge">
                                                        <?php echo number_format($service['base_price'], 0); ?> XAF
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-gray-500">Custom</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="duration-cell">
                                                <?php if ($service['estimated_duration']): ?>
                                                    <span class="duration-badge">
                                                        <?php echo e($service['estimated_duration']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="packages-cell">
                                                <span class="package-count">
                                                    <?php echo $package_count; ?> packages
                                                </span>
                                            </td>
                                            <td class="status-cell">
                                                <span class="status-badge <?php echo $service['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="popular-cell">
                                                <?php if ($service['is_popular']): ?>
                                                    <span class="popular-badge">
                                                        <i class="fas fa-star"></i> Popular
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="order-cell">
                                                <span class="order-number">
                                                    <?php echo $service['display_order']; ?>
                                                </span>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('/admin/modules/services/edit.php?id=' . $service['service_id']); ?>" 
                                                       class="btn-action btn-edit"
                                                       data-tooltip="Edit Service">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('/admin/modules/services/packages.php?service_id=' . $service['service_id']); ?>" 
                                                       class="btn-action btn-packages"
                                                       data-tooltip="Manage Packages">
                                                        <i class="fas fa-box"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('/?page=service&slug=' . $service['slug']); ?>" 
                                                       target="_blank"
                                                       class="btn-action btn-view"
                                                       data-tooltip="View Service">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-delete"
                                                            data-service-id="<?php echo $service['service_id']; ?>"
                                                            data-service-name="<?php echo e($service['service_name']); ?>"
                                                            data-tooltip="Delete Service">
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
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the service "<span id="serviceToDelete"></span>"?</p>
                <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> This will also delete all packages under this service!</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="service_id" id="deleteServiceId">
                    <input type="hidden" name="delete_service" value="1">
                    <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Service
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
    <script src="<?php echo url('../../../assets/js/services.js'); ?>"></script>
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
            
            // Delete button handlers
            document.querySelectorAll('.btn-delete').forEach(button => {
                button.addEventListener('click', function() {
                    const serviceId = this.dataset.serviceId;
                    const serviceName = this.dataset.serviceName;
                    
                    document.getElementById('serviceToDelete').textContent = serviceName;
                    document.getElementById('deleteServiceId').value = serviceId;
                    
                    const deleteForm = document.getElementById('deleteForm');
                    deleteForm.action = `?delete=${serviceId}`;
                    
                    document.getElementById('deleteModal').classList.add('active');
                });
            });
            
            // Modal close handlers
            document.querySelectorAll('.modal-close, .modal-cancel, .modal-backdrop').forEach(element => {
                element.addEventListener('click', function() {
                    document.getElementById('deleteModal').classList.remove('active');
                });
            });
            
            // Table row animations
            const rows = document.querySelectorAll('.service-row');
            rows.forEach((row, index) => {
                row.style.animationDelay = `${index * 0.05}s`;
                row.classList.add('animate-in');
            });
        });
    </script>
</body>
</html>