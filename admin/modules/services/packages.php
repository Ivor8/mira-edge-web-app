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
        redirect(url('/admin/modules/services/index.php'));
    }
}

// Handle DELETE package
if (isset($_GET['delete_package'])) {
    $package_id = (int)$_GET['delete_package'];
    
    try {
        // First delete features (foreign key will handle it with ON DELETE CASCADE)
        $stmt = $db->prepare("DELETE FROM service_packages WHERE package_id = ?");
        $stmt->execute([$package_id]);
        
        $session->setFlash('success', 'Package deleted successfully!');
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error deleting package: ' . $e->getMessage());
    }
    
    redirect(url('/admin/modules/services/packages.php?service_id=' . $service_id));
}

// Handle ADD package
// Accept POST when package fields are present or when the add button was used.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_package']) || !empty($_POST['package_name']))) {
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
    
    if (empty($errors) && $service_id) {
        try {
            $db->beginTransaction();
            
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
            
            $db->commit();
            $session->setFlash('success', 'Package added successfully!');
            
        } catch (PDOException $e) {
            $db->rollBack();
            $session->setFlash('error', 'Error adding package: ' . $e->getMessage());
            error_log("Add Package Error: " . $e->getMessage());
        }
    } else {
        $session->setFlash('error', implode('<br>', $errors));
    }
    
    redirect(url('/admin/modules/services/packages.php?service_id=' . $service_id));
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
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/services.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Form Styles */
        .form-section {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-lg);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-sm);
        }
        
        .form-section-title {
            font-size: 1.25rem;
            margin-bottom: var(--space-lg);
            color: var(--color-black);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding-bottom: var(--space-sm);
            border-bottom: 1px solid var(--color-gray-200);
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
            padding: 10px 12px;
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
            width: 100px;
            padding: 10px 12px;
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
            padding: 8px 12px;
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
            padding: 10px 16px;
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
            padding: 10px 20px;
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
        
        .btn-danger {
            background-color: var(--color-error);
            color: var(--color-white);
        }
        
        .btn-danger:hover {
            background-color: var(--color-error-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
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
        
        .btn-delete:hover {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--color-error);
        }
        
        /* Service Header */
        .service-header {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-xl);
            border: 1px solid var(--color-gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-sm);
        }
        
        .service-info h2 {
            margin: 0 0 var(--space-xs);
            font-size: 1.5rem;
            color: var(--color-black);
        }
        
        .service-info p {
            margin: 0;
            color: var(--color-gray-600);
            font-size: 0.875rem;
        }
        
        /* Packages Grid */
        .packages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: var(--space-lg);
        }
        
        @media (max-width: 768px) {
            .packages-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .package-card {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-normal);
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }
        
        .package-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--color-gray-300);
        }
        
        .package-card.featured {
            border-left: 4px solid #ff9800;
        }
        
        .package-card.featured::before {
            content: 'Featured';
            position: absolute;
            top: 10px;
            right: -30px;
            background: #ff9800;
            color: white;
            padding: 5px 30px;
            font-size: 0.75rem;
            font-weight: 600;
            transform: rotate(45deg);
            box-shadow: var(--shadow-sm);
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
            font-size: 0.875rem;
            color: var(--color-gray-500);
            font-weight: 400;
        }
        
        .package-description {
            color: var(--color-gray-600);
            margin-bottom: var(--space-lg);
            line-height: 1.6;
            font-size: 0.875rem;
        }
        
        .features-list {
            margin: 0 0 var(--space-lg);
            padding: 0;
            list-style: none;
        }
        
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: var(--space-sm);
            margin-bottom: var(--space-sm);
            color: var(--color-gray-700);
            font-size: 0.875rem;
        }
        
        .feature-item i {
            color: var(--color-success);
            font-size: 0.875rem;
            margin-top: 3px;
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
            gap: var(--space-md);
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .package-stats span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .package-actions {
            display: flex;
            gap: var(--space-xs);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: var(--space-3xl) var(--space-xl);
            background: var(--color-white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-gray-200);
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
        
        /* Alert */
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
        
        /* Modal Styles - Fixed */
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
        
        .service-name-badge {
            background: var(--color-gray-100);
            color: var(--color-gray-700);
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
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
                        <a href="<?php echo url('/admin/modules/services/edit.php?id=' . $service_id); ?>" class="btn btn-outline">
                            <i class="fas fa-edit"></i> Edit Service
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo url('/admin/modules/services/index.php'); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Services
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

            <!-- Service Header -->
            <?php if ($service_id && isset($service)): ?>
                <div class="service-header">
                    <div class="service-info">
                        <h2><?php echo e($service['service_name']); ?></h2>
                        <p>Manage pricing packages for this service</p>
                    </div>
                    <div class="service-actions">
                        <button class="btn btn-primary" id="showAddPackageForm">
                            <i class="fas fa-plus"></i> Add New Package
                        </button>
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
                        <button type="button" class="btn btn-outline btn-sm" id="hideAddPackageForm">
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
                                               placeholder="0">
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
                                    <div id="featuresContainer" style="margin-bottom: var(--space-md);">
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
                                        <i class="fas fa-plus"></i> Add Feature
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
                        <?php
                        // Get package features
                        $stmt = $db->prepare("SELECT feature_text FROM package_features WHERE package_id = ? ORDER BY display_order");
                        $stmt->execute([$package['package_id']]);
                        $features = $stmt->fetchAll();
                        ?>
                        
                        <div class="package-card <?php echo $package['is_featured'] ? 'featured' : ''; ?>">
                            <div class="package-header">
                                <h3 class="package-title"><?php echo e($package['package_name']); ?></h3>
                                <div class="package-price">
                                    <?php echo number_format($package['price'], 0); ?>
                                    <span class="currency"><?php echo $package['currency']; ?></span>
                                </div>
                            </div>
                            
                            <?php if (!empty($package['description'])): ?>
                                <p class="package-description"><?php echo e($package['description']); ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($features)): ?>
                                <ul class="features-list">
                                    <?php foreach ($features as $feature): ?>
                                        <li class="feature-item">
                                            <i class="fas fa-check-circle"></i>
                                            <span><?php echo e($feature['feature_text']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            
                            <div class="package-footer">
                                <div class="package-stats">
                                    <?php if (!$service_id): ?>
                                        <span class="service-name-badge">
                                            <i class="fas fa-cog"></i> <?php echo e($package['service_name'] ?? 'N/A'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span>
                                        <i class="fas fa-shopping-cart"></i> <?php echo $package['order_count']; ?> orders
                                    </span>
                                    <span>
                                        <i class="fas fa-sort-numeric-down"></i> Order: <?php echo $package['display_order']; ?>
                                    </span>
                                </div>
                                
                                <div class="package-actions">
                                    <a href="<?php echo url('/admin/modules/services/edit-package.php?id=' . $package['package_id'] . '&service_id=' . $service_id); ?>" 
                                       class="btn-action btn-edit"
                                       title="Edit Package">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <button type="button" 
                                            class="btn-action btn-delete delete-package-btn"
                                            data-package-id="<?php echo $package['package_id']; ?>"
                                            data-package-name="<?php echo e($package['package_name']); ?>"
                                            data-service-id="<?php echo $service_id; ?>"
                                            title="Delete Package">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="grid-column: 1/-1;">
                        <div class="empty-state-icon">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <h3>No Packages Found</h3>
                        <p>
                            <?php if ($service_id): ?>
                                This service doesn't have any packages yet. Click the button above to add your first package.
                            <?php else: ?>
                                No packages have been created yet.
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
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the package "<strong id="packageToDelete"></strong>"?</p>
                <p class="text-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    This action cannot be undone! All features under this package will also be deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="modalCancel">Cancel</button>
                <a href="#" id="confirmDelete" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete Package
                </a>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get elements
            const showBtn = document.getElementById('showAddPackageForm');
            const showBtnAlt = document.getElementById('showAddPackageFormAlt');
            const hideBtn = document.getElementById('hideAddPackageForm');
            const form = document.getElementById('addPackageForm');
            const modal = document.getElementById('deleteModal');
            const modalClose = document.getElementById('modalClose');
            const modalCancel = document.getElementById('modalCancel');
            const modalBackdrop = document.querySelector('.modal-backdrop');
            const packageToDeleteSpan = document.getElementById('packageToDelete');
            const confirmDeleteLink = document.getElementById('confirmDelete');
            
            // Show add package form
            if (showBtn && form) {
                showBtn.addEventListener('click', function() {
                    form.style.display = 'block';
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
            
            if (showBtnAlt && form) {
                showBtnAlt.addEventListener('click', function() {
                    form.style.display = 'block';
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
            
            // Hide add package form
            if (hideBtn && form) {
                hideBtn.addEventListener('click', function() {
                    form.style.display = 'none';
                });
            }
            
            // Add/remove feature inputs
            const addFeatureBtn = document.getElementById('addFeature');
            const featuresContainer = document.getElementById('featuresContainer');
            
            function updateRemoveButtons() {
                const featureGroups = featuresContainer.querySelectorAll('.feature-input-group');
                featureGroups.forEach((group, index) => {
                    const removeBtn = group.querySelector('.remove-feature');
                    if (featureGroups.length > 1) {
                        removeBtn.style.display = 'flex';
                    } else {
                        removeBtn.style.display = 'none';
                    }
                });
            }
            
            if (addFeatureBtn && featuresContainer) {
                addFeatureBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const featureGroup = document.createElement('div');
                    featureGroup.className = 'feature-input-group';
                    featureGroup.innerHTML = `
                        <input type="text" 
                               name="features[]" 
                               class="form-control" 
                               placeholder="Add a feature"
                               required>
                        <button type="button" class="remove-feature">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    featuresContainer.appendChild(featureGroup);
                    
                    // Add event listener to new remove button
                    featureGroup.querySelector('.remove-feature').addEventListener('click', function(e) {
                        e.preventDefault();
                        featureGroup.remove();
                        updateRemoveButtons();
                    });
                    
                    updateRemoveButtons();
                    featureGroup.querySelector('input').focus();
                });
                
                // Add event listeners to existing remove buttons
                featuresContainer.querySelectorAll('.remove-feature').forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        this.closest('.feature-input-group').remove();
                        updateRemoveButtons();
                    });
                });
                
                // Initialize button visibility on load
                updateRemoveButtons();
            }
            
            // Delete package buttons
            document.querySelectorAll('.delete-package-btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const packageId = this.dataset.packageId;
                    const packageName = this.dataset.packageName;
                    const serviceId = this.dataset.serviceId;
                    
                    packageToDeleteSpan.textContent = packageName;
                    confirmDeleteLink.href = '?delete_package=' + packageId + '&service_id=' + serviceId;
                    
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
    </script>
</body>
</html>