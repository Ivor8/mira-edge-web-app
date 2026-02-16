<?php
/**
 * Edit Service Package
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
$package_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

// Get package data
$stmt = $db->prepare("
    SELECT sp.*, s.service_name 
    FROM service_packages sp
    LEFT JOIN services s ON sp.service_id = s.service_id
    WHERE sp.package_id = ?
");
$stmt->execute([$package_id]);
$package = $stmt->fetch();

if (!$package) {
    $session->setFlash('error', 'Package not found.');
    redirect(url('/admin/modules/services/packages.php?service_id=' . $service_id));
}

// Get package features
$stmt = $db->prepare("SELECT * FROM package_features WHERE package_id = ? ORDER BY display_order");
$stmt->execute([$package_id]);
$features = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_package'])) {
    $package_name = trim($_POST['package_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $currency = $_POST['currency'] ?? 'XAF';
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $display_order = (int)($_POST['display_order'] ?? 0);
    
    $errors = [];
    
    if (empty($package_name)) {
        $errors[] = 'Package name is required';
    }
    
    if ($price <= 0) {
        $errors[] = 'Price must be greater than 0';
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Update package
            $stmt = $db->prepare("
                UPDATE service_packages SET
                    package_name = ?, description = ?, price = ?,
                    currency = ?, is_featured = ?, display_order = ?
                WHERE package_id = ?
            ");
            
            $stmt->execute([
                $package_name, $description, $price,
                $currency, $is_featured, $display_order,
                $package_id
            ]);
            
            // Delete existing features
            $stmt = $db->prepare("DELETE FROM package_features WHERE package_id = ?");
            $stmt->execute([$package_id]);
            
            // Add new features
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
            
            $db->commit();
            $session->setFlash('success', 'Package updated successfully!');
            redirect(url('/admin/modules/services/packages.php?service_id=' . $package['service_id']));
            
        } catch (PDOException $e) {
            $db->rollBack();
            $session->setFlash('error', 'Error updating package: ' . $e->getMessage());
            error_log("Edit Package Error: " . $e->getMessage());
        }
    } else {
        $session->setFlash('error', implode('<br>', $errors));
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Package | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/services.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .edit-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-section {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-xl);
            margin-bottom: var(--space-xl);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-md);
        }
        
        .form-section-title {
            font-size: 1.25rem;
            margin-bottom: var(--space-lg);
            color: var(--color-black);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding-bottom: var(--space-sm);
            border-bottom: 2px solid var(--color-gray-200);
        }
        
        .form-section-title i {
            color: var(--color-gray-500);
        }
        
        .form-group {
            margin-bottom: var(--space-lg);
        }
        
        .form-label {
            display: block;
            margin-bottom: var(--space-sm);
            font-weight: 600;
            color: var(--color-gray-700);
            font-size: 0.875rem;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--color-error);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            font-size: 0.875rem;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
            background-color: var(--color-white);
            color: var(--color-gray-800);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--color-black);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-control.error {
            border-color: var(--color-error);
        }
        
        .form-error {
            color: var(--color-error);
            font-size: 0.75rem;
            margin-top: 4px;
        }
        
        .form-text {
            display: block;
            margin-top: 4px;
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .input-with-currency {
            display: flex;
            gap: var(--space-sm);
        }
        
        .input-with-currency .form-control {
            flex: 1;
        }
        
        .currency-select {
            width: 120px;
            padding: 12px 16px;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            background-color: var(--color-white);
            color: var(--color-gray-800);
            font-size: 0.875rem;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm) 0;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            cursor: pointer;
            font-size: 0.875rem;
            color: var(--color-gray-700);
        }
        
        .checkbox-label i {
            color: var(--color-gray-500);
            width: 16px;
        }
        
        .feature-input-group {
            display: flex;
            gap: var(--space-sm);
            margin-bottom: var(--space-sm);
        }
        
        .feature-input-group .form-control {
            flex: 1;
        }
        
        .remove-feature {
            background: none;
            border: none;
            color: var(--color-error);
            cursor: pointer;
            padding: 0 12px;
            border-radius: var(--radius-md);
            font-size: 1rem;
        }
        
        .remove-feature:hover {
            background-color: rgba(244, 67, 54, 0.1);
        }
        
        .add-feature-btn {
            background: var(--color-gray-100);
            border: 1px dashed var(--color-gray-300);
            border-radius: var(--radius-md);
            padding: 12px 16px;
            cursor: pointer;
            color: var(--color-gray-700);
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: var(--space-sm);
            margin-top: var(--space-sm);
            width: 100%;
            justify-content: center;
        }
        
        .add-feature-btn:hover {
            background: var(--color-gray-200);
            color: var(--color-black);
            border-color: var(--color-gray-400);
        }
        
        .form-actions {
            display: flex;
            gap: var(--space-md);
            justify-content: flex-end;
            margin-top: var(--space-xl);
            padding-top: var(--space-lg);
            border-top: 2px solid var(--color-gray-200);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
            gap: var(--space-sm);
        }
        
        .btn i {
            font-size: 0.875rem;
        }
        
        .btn-primary {
            background-color: var(--color-black);
            color: var(--color-white);
        }
        
        .btn-primary:hover {
            background-color: var(--color-gray-800);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-outline {
            background-color: transparent;
            color: var(--color-gray-700);
            border: 1px solid var(--color-gray-300);
        }
        
        .btn-outline:hover {
            background-color: var(--color-gray-50);
            border-color: var(--color-gray-400);
            transform: translateY(-2px);
        }
        
        .btn-lg {
            padding: 14px 28px;
            font-size: 1rem;
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
        
        .service-badge {
            display: inline-block;
            padding: 4px 12px;
            background: var(--color-gray-100);
            color: var(--color-gray-700);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
            margin-bottom: var(--space-md);
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
            <div class="edit-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-edit"></i>
                        Edit Package
                    </h1>
                    <div class="page-actions">
                        <a href="<?php echo url('/admin/modules/services/packages.php?service_id=' . $package['service_id']); ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Packages
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

                <!-- Edit Package Form -->
                <div class="form-section">
                    <div class="service-badge">
                        <i class="fas fa-cog"></i> Service: <?php echo e($package['service_name']); ?>
                    </div>
                    
                    <h4 class="form-section-title">
                        <i class="fas fa-box"></i>
                        Package Information
                    </h4>
                    
                    <form method="POST" action="" id="editPackageForm">
                        <div class="form-group">
                            <label for="package_name" class="form-label required">Package Name</label>
                            <input type="text" 
                                   id="package_name" 
                                   name="package_name" 
                                   class="form-control" 
                                   value="<?php echo e($_POST['package_name'] ?? $package['package_name']); ?>"
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
                                       value="<?php echo e($_POST['price'] ?? $package['price']); ?>"
                                       min="0" 
                                       step="1000"
                                       required>
                                <select name="currency" class="currency-select">
                                    <option value="XAF" <?php echo (($_POST['currency'] ?? $package['currency']) == 'XAF') ? 'selected' : ''; ?>>XAF</option>
                                    <option value="USD" <?php echo (($_POST['currency'] ?? $package['currency']) == 'USD') ? 'selected' : ''; ?>>USD</option>
                                    <option value="EUR" <?php echo (($_POST['currency'] ?? $package['currency']) == 'EUR') ? 'selected' : ''; ?>>EUR</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="display_order" class="form-label">Display Order</label>
                            <input type="number" 
                                   id="display_order" 
                                   name="display_order" 
                                   class="form-control" 
                                   value="<?php echo e($_POST['display_order'] ?? $package['display_order']); ?>"
                                   min="0">
                            <span class="form-text">Lower numbers display first</span>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-wrapper">
                                <input type="checkbox" 
                                       id="is_featured" 
                                       name="is_featured" 
                                       value="1"
                                       <?php echo (($_POST['is_featured'] ?? $package['is_featured']) == 1) ? 'checked' : ''; ?>>
                                <label for="is_featured" class="checkbox-label">
                                    <i class="fas fa-star"></i> Mark as Featured Package
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description" class="form-label">Description</label>
                            <textarea id="description" 
                                      name="description" 
                                      class="form-control" 
                                      rows="4"
                                      placeholder="Package description..."><?php echo e($_POST['description'] ?? $package['description']); ?></textarea>
                        </div>
                        
                        <!-- Features -->
                        <div class="form-group">
                            <label class="form-label">Package Features</label>
                            <div id="featuresContainer">
                                <?php 
                                $feature_list = [];
                                if (!empty($_POST['features'])) {
                                    $feature_list = $_POST['features'];
                                } elseif (!empty($features)) {
                                    foreach ($features as $feature) {
                                        $feature_list[] = $feature['feature_text'];
                                    }
                                }
                                
                                if (!empty($feature_list)): 
                                    foreach ($feature_list as $feature_text): 
                                ?>
                                        <div class="feature-input-group">
                                            <input type="text" 
                                                   name="features[]" 
                                                   class="form-control" 
                                                   value="<?php echo e($feature_text); ?>"
                                                   placeholder="Add a feature">
                                            <button type="button" class="remove-feature">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="feature-input-group">
                                        <input type="text" 
                                               name="features[]" 
                                               class="form-control" 
                                               placeholder="Add a feature (e.g., 5 pages website)">
                                        <button type="button" class="remove-feature" style="display: none;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="add-feature-btn" id="addFeature">
                                <i class="fas fa-plus"></i> Add Another Feature
                            </button>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" name="update_package" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Update Package
                            </button>
                            <a href="<?php echo url('/admin/modules/services/packages.php?service_id=' . $package['service_id']); ?>" class="btn btn-outline btn-lg">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
                        const group = this.closest('.feature-input-group');
                        // Don't remove if it's the last one
                        if (featuresContainer.children.length > 1) {
                            group.remove();
                        } else {
                            // Clear the input instead
                            group.querySelector('input').value = '';
                        }
                    });
                });
            }
            
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
            
            // Form validation
            const form = document.getElementById('editPackageForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const packageName = document.getElementById('package_name');
                    const price = document.getElementById('price');
                    let isValid = true;
                    
                    if (!packageName.value.trim()) {
                        packageName.classList.add('error');
                        isValid = false;
                    } else {
                        packageName.classList.remove('error');
                    }
                    
                    if (!price.value || parseFloat(price.value) <= 0) {
                        price.classList.add('error');
                        isValid = false;
                    } else {
                        price.classList.remove('error');
                    }
                    
                    if (!isValid) {
                        e.preventDefault();
                        showNotification('Please fill in all required fields', 'error');
                    }
                });
            }
            
            // Simple notification function
            function showNotification(message, type) {
                const notification = document.createElement('div');
                notification.className = `alert alert-${type}`;
                notification.innerHTML = `
                    <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    <div class="alert-content">${message}</div>
                    <button class="alert-close">&times;</button>
                `;
                
                const container = document.querySelector('.flash-messages') || document.querySelector('.edit-container');
                if (container) {
                    container.insertBefore(notification, container.firstChild);
                    
                    setTimeout(() => {
                        notification.style.opacity = '0';
                        setTimeout(() => notification.remove(), 300);
                    }, 5000);
                    
                    notification.querySelector('.alert-close').addEventListener('click', function() {
                        notification.remove();
                    });
                }
            }
        });
    </script>
</body>
</html>