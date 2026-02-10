<?php
/**
 * Service Packages Management
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

// Get service ID from query string
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

if ($service_id) {
    // Get service info
    $stmt = $db->prepare("SELECT service_name FROM services WHERE service_id = ?");
    $stmt->execute([$service_id]);
    $service = $stmt->fetch();
    
    if (!$service) {
        $session->setFlash('error', 'Service not found.');
        redirect(url('/admin/modules/services/'));
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_package'])) {
        $package_name = trim($_POST['package_name']);
        $description = trim($_POST['description']);
        $price = (float)$_POST['price'];
        $currency = $_POST['currency'];
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        $display_order = (int)$_POST['display_order'];
        
        if (!empty($package_name) && $price > 0 && $service_id) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO service_packages 
                    (service_id, package_name, description, price, currency, is_featured, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $service_id, $package_name, $description, $price, $currency, 
                    $is_featured, $display_order
                ]);
                
                $package_id = $db->lastInsertId();
                
                // Add features if provided
                if (isset($_POST['features']) && is_array($_POST['features'])) {
                    foreach ($_POST['features'] as $feature) {
                        $feature_text = trim($feature);
                        if (!empty($feature_text)) {
                            $stmt = $db->prepare("
                                INSERT INTO package_features (package_id, feature_text)
                                VALUES (?, ?)
                            ");
                            $stmt->execute([$package_id, $feature_text]);
                        }
                    }
                }
                
                $session->setFlash('success', 'Package added successfully!');
                redirect(url('/admin/modules/services/packages.php?service_id=' . $service_id));
                
            } catch (PDOException $e) {
                $session->setFlash('error', 'Error adding package: ' . $e->getMessage());
            }
        } else {
            $session->setFlash('error', 'Please fill in all required fields.');
        }
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_packages'])) {
        $action = $_POST['bulk_action'];
        $selected_packages = $_POST['selected_packages'];
        
        try {
            if ($action === 'delete') {
                $placeholders = implode(',', array_fill(0, count($selected_packages), '?'));
                $stmt = $db->prepare("DELETE FROM service_packages WHERE package_id IN ($placeholders)");
                $stmt->execute($selected_packages);
                $session->setFlash('success', 'Selected packages deleted successfully.');
            } elseif ($action === 'feature') {
                $placeholders = implode(',', array_fill(0, count($selected_packages), '?'));
                $stmt = $db->prepare("UPDATE service_packages SET is_featured = 1 WHERE package_id IN ($placeholders)");
                $stmt->execute($selected_packages);
                $session->setFlash('success', 'Selected packages featured successfully.');
            } elseif ($action === 'unfeature') {
                $placeholders = implode(',', array_fill(0, count($selected_packages), '?'));
                $stmt = $db->prepare("UPDATE service_packages SET is_featured = 0 WHERE package_id IN ($placeholders)");
                $stmt->execute($selected_packages);
                $session->setFlash('success', 'Selected packages unfeatured successfully.');
            }
        } catch (PDOException $e) {
            error_log("Bulk Action Error: " . $e->getMessage());
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }
}

// Get packages for this service
if ($service_id) {
    $stmt = $db->prepare("
        SELECT sp.*, 
               (SELECT COUNT(*) FROM service_orders WHERE package_id = sp.package_id) as order_count
        FROM service_packages sp
        WHERE sp.service_id = ?
        ORDER BY sp.display_order, sp.package_name
    ");
    $stmt->execute([$service_id]);
    $packages = $stmt->fetchAll();
} else {
    // Get all packages
    $stmt = $db->query("
        SELECT sp.*, s.service_name,
               (SELECT COUNT(*) FROM service_orders WHERE package_id = sp.package_id) as order_count
        FROM service_packages sp
        LEFT JOIN services s ON sp.service_id = s.service_id
        ORDER BY s.service_name, sp.display_order
    ");
    $packages = $stmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Packages | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/services.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .service-header {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-xl);
            border: 1px solid var(--color-gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .service-info h2 {
            margin: 0 0 var(--space-sm);
            font-size: 1.5rem;
        }
        
        .service-info p {
            margin: 0;
            color: var(--color-gray-600);
        }
        
        .package-card {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-lg);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-normal);
        }
        
        .package-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .package-card.featured {
            border-left: 4px solid #ff9800;
        }
        
        .package-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--space-md);
        }
        
        .package-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            color: var(--color-black);
        }
        
        .package-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-black);
        }
        
        .package-price .currency {
            font-size: 1rem;
            color: var(--color-gray-600);
        }
        
        .package-description {
            color: var(--color-gray-600);
            margin-bottom: var(--space-lg);
            line-height: 1.6;
        }
        
        .features-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            margin-bottom: var(--space-sm);
            color: var(--color-gray-700);
        }
        
        .feature-item i {
            color: var(--color-success);
        }
        
        .package-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: var(--space-lg);
            padding-top: var(--space-md);
            border-top: 1px solid var(--color-gray-200);
        }
        
        .package-stats {
            display: flex;
            gap: var(--space-lg);
            font-size: 0.875rem;
            color: var(--color-gray-600);
        }
        
        .package-actions {
            display: flex;
            gap: var(--space-sm);
        }
        
        .add-feature-btn {
            background: var(--color-gray-100);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            padding: 8px 16px;
            cursor: pointer;
            color: var(--color-gray-700);
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: var(--space-sm);
            margin-top: var(--space-sm);
        }
        
        .add-feature-btn:hover {
            background: var(--color-gray-200);
            color: var(--color-black);
        }
        
        .feature-input-group {
            display: flex;
            gap: var(--space-sm);
            margin-bottom: var(--space-sm);
        }
        
        .remove-feature {
            background: none;
            border: none;
            color: var(--color-error);
            cursor: pointer;
            padding: 8px;
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
                    <i class="fas fa-box"></i>
                    Service Packages
                </h1>
                <div class="page-actions">
                    <?php if ($service_id): ?>
                        <a href="<?php echo url('/admin/modules/services/'); ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Services
                        </a>
                    <?php else: ?>
                        <a href="<?php echo url('/admin/modules/services/'); ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Services
                        </a>
                    <?php endif; ?>
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

            <!-- Service Header -->
            <?php if ($service_id && $service): ?>
                <div class="service-header">
                    <div class="service-info">
                        <h2><?php echo e($service['service_name']); ?></h2>
                        <p>Manage packages for this service</p>
                    </div>
                    <div class="service-actions">
                        <button class="btn btn-primary" id="showAddPackageForm">
                            <i class="fas fa-plus"></i> Add New Package
                        </button>
                    </div>
                </div>
            <?php elseif (!$service_id): ?>
                <div class="service-header">
                    <div class="service-info">
                        <h2>All Packages</h2>
                        <p>Viewing packages from all services</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Add Package Form (Initially Hidden) -->
            <?php if ($service_id): ?>
                <div class="card" id="addPackageForm" style="display: none; margin-bottom: var(--space-xl);">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-plus-circle"></i>
                            Add New Package
                        </h3>
                        <button class="btn btn-outline btn-sm" id="hideAddPackageForm">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="packageForm">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="package_name" class="form-label required">Package Name</label>
                                    <input type="text" 
                                           id="package_name" 
                                           name="package_name" 
                                           class="form-control" 
                                           required
                                           placeholder="e.g., Basic, Standard, Premium">
                                </div>
                                
                                <div class="form-group">
                                    <label for="price" class="form-label required">Price</label>
                                    <div class="input-with-currency">
                                        <input type="number" 
                                               id="price" 
                                               name="price" 
                                               class="form-control" 
                                               min="0" 
                                               step="1000"
                                               required
                                               placeholder="0.00">
                                        <select name="currency" class="currency-select">
                                            <option value="XAF">XAF</option>
                                            <option value="USD">USD</option>
                                            <option value="EUR">EUR</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="display_order" class="form-label">Display Order</label>
                                    <input type="number" 
                                           id="display_order" 
                                           name="display_order" 
                                           class="form-control" 
                                           min="0"
                                           value="0">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Options</label>
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="is_featured" 
                                               name="is_featured" 
                                               value="1">
                                        <label for="is_featured" class="checkbox-label">
                                            <i class="fas fa-star"></i> Mark as Featured
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group form-group-full">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea id="description" 
                                              name="description" 
                                              class="form-control" 
                                              rows="3"
                                              placeholder="Package description..."></textarea>
                                </div>
                                
                                <!-- Features -->
                                <div class="form-group form-group-full">
                                    <label class="form-label">Package Features</label>
                                    <div id="featuresContainer">
                                        <div class="feature-input-group">
                                            <input type="text" 
                                                   name="features[]" 
                                                   class="form-control" 
                                                   placeholder="Add a feature (e.g., 5 pages website)">
                                            <button type="button" class="remove-feature" style="display: none;">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <button type="button" class="add-feature-btn" id="addFeature">
                                        <i class="fas fa-plus"></i> Add Another Feature
                                    </button>
                                </div>
                                
                                <div class="form-group form-group-full">
                                    <button type="submit" name="add_package" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Package
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Packages Grid -->
            <div class="packages-grid">
                <?php if (!empty($packages)): ?>
                    <?php foreach ($packages as $package): ?>
                        <div class="package-card <?php echo $package['is_featured'] ? 'featured' : ''; ?>">
                            <div class="package-header">
                                <h3 class="package-title"><?php echo e($package['package_name']); ?></h3>
                                <div class="package-price">
                                    <?php echo number_format($package['price'], 0); ?>
                                    <span class="currency"><?php echo $package['currency']; ?></span>
                                </div>
                            </div>
                            
                            <?php if ($package['description']): ?>
                                <p class="package-description"><?php echo e($package['description']); ?></p>
                            <?php endif; ?>
                            
                            <!-- Get package features -->
                            <?php 
                            $stmt = $db->prepare("SELECT feature_text FROM package_features WHERE package_id = ? ORDER BY display_order");
                            $stmt->execute([$package['package_id']]);
                            $features = $stmt->fetchAll();
                            ?>
                            
                            <?php if (!empty($features)): ?>
                                <ul class="features-list">
                                    <?php foreach ($features as $feature): ?>
                                        <li class="feature-item">
                                            <i class="fas fa-check"></i>
                                            <span><?php echo e($feature['feature_text']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            
                            <div class="package-footer">
                                <div class="package-stats">
                                    <?php if (!$service_id): ?>
                                        <span class="service-name">
                                            <i class="fas fa-cog"></i> <?php echo e($package['service_name']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="order-count">
                                        <i class="fas fa-shopping-cart"></i> <?php echo $package['order_count']; ?> orders
                                    </span>
                                    <span class="display-order">
                                        <i class="fas fa-sort-numeric-down"></i> Order: <?php echo $package['display_order']; ?>
                                    </span>
                                </div>
                                
                                <div class="package-actions">
                                    <?php if ($package['is_featured']): ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-star"></i> Featured
                                        </span>
                                    <?php endif; ?>
                                    
                                    <a href="<?php echo url('/admin/modules/services/edit-package.php?id=' . $package['package_id']); ?>" 
                                       class="btn-action btn-edit"
                                       data-tooltip="Edit Package">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <button type="button" 
                                            class="btn-action btn-delete"
                                            data-package-id="<?php echo $package['package_id']; ?>"
                                            data-package-name="<?php echo e($package['package_name']); ?>"
                                            data-tooltip="Delete Package">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-box"></i>
                        </div>
                        <h3>No Packages Found</h3>
                        <p>
                            <?php if ($service_id): ?>
                                No packages have been added for this service yet.
                            <?php else: ?>
                                No packages found in the system.
                            <?php endif; ?>
                        </p>
                        <?php if ($service_id): ?>
                            <button class="btn btn-primary" id="showAddPackageFormAlt">
                                <i class="fas fa-plus"></i> Add Your First Package
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete Package</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the package "<span id="packageToDelete"></span>"?</p>
                <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="package_id" id="deletePackageId">
                    <input type="hidden" name="delete_package" value="1">
                    <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Package
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show/hide add package form
            const showBtn = document.getElementById('showAddPackageForm');
            const showBtnAlt = document.getElementById('showAddPackageFormAlt');
            const hideBtn = document.getElementById('hideAddPackageForm');
            const form = document.getElementById('addPackageForm');
            
            if (showBtn && form) {
                showBtn.addEventListener('click', function() {
                    form.style.display = 'block';
                    form.scrollIntoView({ behavior: 'smooth' });
                });
            }
            
            if (showBtnAlt && form) {
                showBtnAlt.addEventListener('click', function() {
                    form.style.display = 'block';
                    form.scrollIntoView({ behavior: 'smooth' });
                });
            }
            
            if (hideBtn && form) {
                hideBtn.addEventListener('click', function() {
                    form.style.display = 'none';
                });
            }
            
            // Add/remove feature inputs
            const addFeatureBtn = document.getElementById('addFeature');
            const featuresContainer = document.getElementById('featuresContainer');
            
            if (addFeatureBtn && featuresContainer) {
                addFeatureBtn.addEventListener('click', function() {
                    const featureGroup = document.createElement('div');
                    featureGroup.className = 'feature-input-group';
                    featureGroup.innerHTML = `
                        <input type="text" 
                               name="features[]" 
                               class="form-control" 
                               placeholder="Add a feature">
                        <button type="button" class="remove-feature">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    featuresContainer.appendChild(featureGroup);
                    
                    // Add event listener to new remove button
                    featureGroup.querySelector('.remove-feature').addEventListener('click', function() {
                        featureGroup.remove();
                    });
                });
                
                // Add event listeners to existing remove buttons
                featuresContainer.querySelectorAll('.remove-feature').forEach(button => {
                    button.addEventListener('click', function() {
                        this.parentElement.remove();
                    });
                });
            }
            
            // Delete package buttons
            document.querySelectorAll('.btn-delete').forEach(button => {
                button.addEventListener('click', function() {
                    const packageId = this.dataset.packageId;
                    const packageName = this.dataset.packageName;
                    
                    document.getElementById('packageToDelete').textContent = packageName;
                    document.getElementById('deletePackageId').value = packageId;
                    
                    const deleteForm = document.getElementById('deleteForm');
                    deleteForm.action = `?delete_package=${packageId}`;
                    
                    document.getElementById('deleteModal').classList.add('active');
                });
            });
            
            // Modal close handlers
            document.querySelectorAll('.modal-close, .modal-cancel, .modal-backdrop').forEach(element => {
                element.addEventListener('click', function() {
                    document.getElementById('deleteModal').classList.remove('active');
                });
            });
            
            // Form validation
            const packageForm = document.getElementById('packageForm');
            if (packageForm) {
                packageForm.addEventListener('submit', function(e) {
                    const priceInput = document.getElementById('price');
                    if (priceInput && parseFloat(priceInput.value) <= 0) {
                        e.preventDefault();
                        showNotification('Price must be greater than 0', 'error');
                        priceInput.focus();
                    }
                });
            }
        });
    </script>
</body>
</html>