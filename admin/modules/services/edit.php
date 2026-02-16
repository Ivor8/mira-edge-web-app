<?php
/**
 * Edit Service
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
    redirect(url('/login.php')); // Added leading slash
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/')); // Added leading slash
}

$user = $session->getUser();

// Get service ID
$service_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$service_id) {
    $session->setFlash('error', 'Invalid service ID.');
    redirect(url('/admin/modules/services/index.php')); // Fixed path
}

// Get service data
$stmt = $db->prepare("SELECT * FROM services WHERE service_id = ?");
$stmt->execute([$service_id]);
$service = $stmt->fetch();

if (!$service) {
    $session->setFlash('error', 'Service not found.');
    redirect(url('/admin/modules/services/index.php')); // Fixed path
}

// Get categories
$stmt = $db->query("SELECT * FROM service_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $stmt->fetchAll();

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service'])) {
    // Get form data
    $service_name = trim($_POST['service_name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $service_category_id = !empty($_POST['service_category_id']) ? $_POST['service_category_id'] : null;
    $short_description = trim($_POST['short_description'] ?? '');
    $full_description = trim($_POST['full_description'] ?? '');
    $base_price = !empty($_POST['base_price']) ? $_POST['base_price'] : null;
    $estimated_duration = trim($_POST['estimated_duration'] ?? '');
    $is_popular = isset($_POST['is_popular']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $display_order = (int)($_POST['display_order'] ?? 0);
    $seo_title = trim($_POST['seo_title'] ?? '');
    $seo_description = trim($_POST['seo_description'] ?? '');
    $seo_keywords = trim($_POST['seo_keywords'] ?? '');
    
    // Validation
    if (empty($service_name)) {
        $errors['service_name'] = 'Service name is required';
    }
    
    if (empty($short_description)) {
        $errors['short_description'] = 'Short description is required';
    }
    
    if (empty($full_description)) {
        $errors['full_description'] = 'Full description is required';
    }
    
    // Generate slug if empty
    if (empty($slug)) {
        $slug = generateSlug($service_name);
    }
    
    // Check if slug exists (excluding current service)
    $stmt = $db->prepare("SELECT service_id FROM services WHERE slug = ? AND service_id != ?");
    $stmt->execute([$slug, $service_id]);
    if ($stmt->fetch()) {
        $errors['slug'] = 'This slug is already in use. Please choose a different one.';
    }
    
    // Handle file upload
    $featured_image = $service['featured_image']; // Keep existing by default
    
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['featured_image']['type'];
        $file_size = $_FILES['featured_image']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors['featured_image'] = 'Only JPG, PNG, GIF, and WebP images are allowed';
        } elseif ($file_size > 5 * 1024 * 1024) { // 5MB
            $errors['featured_image'] = 'Image size must be less than 5MB';
        } else {
            $ext = pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . time() . '.' . $ext;
            
            $upload_dir = dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR;
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $upload_path)) {
                // Delete old image if exists
                if ($service['featured_image']) {
                    $old_image_path = dirname(dirname(dirname(dirname(__FILE__)))) . $service['featured_image'];
                    if (file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }
                }
                $featured_image = '/assets/uploads/services/' . $filename;
            } else {
                $errors['featured_image'] = 'Failed to upload image';
            }
        }
    }
    
    // If no errors, update service
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("
                UPDATE services SET
                    service_name = ?, slug = ?, service_category_id = ?, short_description = ?,
                    full_description = ?, base_price = ?, featured_image = ?, estimated_duration = ?,
                    is_popular = ?, is_active = ?, display_order = ?, seo_title = ?,
                    seo_description = ?, seo_keywords = ?
                WHERE service_id = ?
            ");
            
            $stmt->execute([
                $service_name, $slug, $service_category_id, $short_description, $full_description,
                $base_price, $featured_image, $estimated_duration, $is_popular, $is_active,
                $display_order, $seo_title, $seo_description, $seo_keywords, $service_id
            ]);
            
            $session->setFlash('success', 'Service updated successfully!');
            redirect(url('/admin/modules/services/edit.php?id=' . $service_id)); // Added leading slash
            
        } catch (PDOException $e) {
            $errors['general'] = 'Error updating service: ' . $e->getMessage();
            error_log("Update Service Error: " . $e->getMessage());
        }
    }
}

// Get fresh service data after potential update
$stmt = $db->prepare("SELECT * FROM services WHERE service_id = ?");
$stmt->execute([$service_id]);
$service = $stmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Service | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/services.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Copy the styles from your previous edit.php */
        .edit-container {
            max-width: 1200px;
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
        
        .form-columns {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--space-xl);
        }
        
        @media (max-width: 992px) {
            .form-columns {
                grid-template-columns: 1fr;
            }
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
        
        .input-with-button {
            display: flex;
            gap: var(--space-sm);
        }
        
        .input-with-button .form-control {
            flex: 1;
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
        
        .file-upload-label {
            display: block;
            padding: var(--space-lg);
            background: var(--color-gray-50);
            border: 2px dashed var(--color-gray-300);
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        
        .file-upload-label:hover {
            background: var(--color-gray-100);
            border-color: var(--color-gray-400);
        }
        
        .file-upload-label i {
            font-size: 2rem;
            color: var(--color-gray-500);
            margin-bottom: var(--space-sm);
            display: block;
        }
        
        .file-input {
            display: none;
        }
        
        .current-image {
            width: 200px;
            height: 150px;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--color-gray-100);
            margin-bottom: var(--space-md);
            border: 1px solid var(--color-gray-200);
        }
        
        .current-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .image-preview {
            width: 200px;
            height: 150px;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--color-gray-100);
            margin-top: var(--space-md);
            display: none;
            border: 1px solid var(--color-gray-200);
        }
        
        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .char-counter {
            font-size: 0.75rem;
            color: var(--color-gray-500);
            text-align: right;
            margin-top: 4px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-lg);
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
                        Edit Service
                    </h1>
                    <div class="page-actions">
                        <a href="<?php echo url('/admin/modules/services/index.php'); ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Services
                        </a>
                        <a href="<?php echo url('/admin/modules/services/packages.php?service_id=' . $service_id); ?>" class="btn btn-outline">
                            <i class="fas fa-box"></i> Manage Packages
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

                <!-- Edit Service Form -->
                <form method="POST" action="" enctype="multipart/form-data" id="editServiceForm">
                    <?php if (isset($errors['general'])): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <div class="alert-content"><?php echo e($errors['general']); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-columns">
                        <!-- Left Column -->
                        <div class="form-column">
                            <!-- Basic Information -->
                            <div class="form-section">
                                <h4 class="form-section-title">
                                    <i class="fas fa-info-circle"></i>
                                    Basic Information
                                </h4>
                                
                                <div class="form-group">
                                    <label for="service_name" class="form-label required">
                                        Service Name
                                    </label>
                                    <input type="text" 
                                           id="service_name" 
                                           name="service_name" 
                                           class="form-control <?php echo isset($errors['service_name']) ? 'error' : ''; ?>" 
                                           value="<?php echo e($_POST['service_name'] ?? $service['service_name']); ?>"
                                           required>
                                    <?php if (isset($errors['service_name'])): ?>
                                        <div class="form-error"><?php echo e($errors['service_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label for="slug" class="form-label">
                                        URL Slug
                                    </label>
                                    <div class="input-with-button">
                                        <input type="text" 
                                               id="slug" 
                                               name="slug" 
                                               class="form-control <?php echo isset($errors['slug']) ? 'error' : ''; ?>" 
                                               value="<?php echo e($_POST['slug'] ?? $service['slug']); ?>"
                                               placeholder="Auto-generated from name">
                                        <button type="button" id="generateSlug" class="btn btn-outline">
                                            <i class="fas fa-sync-alt"></i> Generate
                                        </button>
                                    </div>
                                    <?php if (isset($errors['slug'])): ?>
                                        <div class="form-error"><?php echo e($errors['slug']); ?></div>
                                    <?php endif; ?>
                                    <span class="form-text">Leave empty to auto-generate from service name</span>
                                </div>
                                
                                <div class="form-group">
                                    <label for="service_category_id" class="form-label">
                                        Category
                                    </label>
                                    <select id="service_category_id" 
                                            name="service_category_id" 
                                            class="form-control">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['service_category_id']; ?>"
                                                    <?php echo (($_POST['service_category_id'] ?? $service['service_category_id']) == $category['service_category_id']) ? 'selected' : ''; ?>>
                                                <?php echo e($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="short_description" class="form-label required">
                                        Short Description
                                    </label>
                                    <textarea id="short_description" 
                                              name="short_description" 
                                              class="form-control <?php echo isset($errors['short_description']) ? 'error' : ''; ?>" 
                                              rows="3"
                                              maxlength="500"
                                              required><?php echo e($_POST['short_description'] ?? $service['short_description']); ?></textarea>
                                    <div class="char-counter" id="shortDescCounter">0/500</div>
                                    <?php if (isset($errors['short_description'])): ?>
                                        <div class="form-error"><?php echo e($errors['short_description']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label for="full_description" class="form-label required">
                                        Full Description
                                    </label>
                                    <textarea id="full_description" 
                                              name="full_description" 
                                              class="form-control <?php echo isset($errors['full_description']) ? 'error' : ''; ?>" 
                                              rows="10"
                                              required><?php echo e($_POST['full_description'] ?? $service['full_description']); ?></textarea>
                                    <?php if (isset($errors['full_description'])): ?>
                                        <div class="form-error"><?php echo e($errors['full_description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Pricing & Duration -->
                            <div class="form-section">
                                <h4 class="form-section-title">
                                    <i class="fas fa-money-bill-wave"></i>
                                    Pricing & Duration
                                </h4>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="base_price" class="form-label">
                                            Base Price (XAF)
                                        </label>
                                        <input type="number" 
                                               id="base_price" 
                                               name="base_price" 
                                               class="form-control" 
                                               value="<?php echo e($_POST['base_price'] ?? $service['base_price']); ?>"
                                               min="0"
                                               step="1000"
                                               placeholder="Leave empty for custom pricing">
                                        <span class="form-text">Leave empty for "Contact for quote"</span>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="estimated_duration" class="form-label">
                                            Estimated Duration
                                        </label>
                                        <input type="text" 
                                               id="estimated_duration" 
                                               name="estimated_duration" 
                                               class="form-control" 
                                               value="<?php echo e($_POST['estimated_duration'] ?? $service['estimated_duration']); ?>"
                                               placeholder="e.g., 2-4 weeks, 1 month">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="form-column">
                            <!-- Service Image -->
                            <div class="form-section">
                                <h4 class="form-section-title">
                                    <i class="fas fa-image"></i>
                                    Service Image
                                </h4>
                                
                                <?php if ($service['featured_image']): ?>
                                    <div class="current-image">
                                        <img src="<?php echo url($service['featured_image']); ?>" 
                                             alt="<?php echo e($service['service_name']); ?>">
                                    </div>
                                <?php endif; ?>
                                
                                <div class="form-group">
                                    <label for="featured_image" class="form-label">
                                        Change Image
                                    </label>
                                    <input type="file" 
                                           id="featured_image" 
                                           name="featured_image" 
                                           class="file-input <?php echo isset($errors['featured_image']) ? 'error' : ''; ?>"
                                           accept="image/*">
                                    <label for="featured_image" class="file-upload-label">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <div>Click to browse or drag and drop</div>
                                        <span class="form-text">PNG, JPG, GIF up to 5MB</span>
                                    </label>
                                    <div class="image-preview" id="imagePreview">
                                        <img src="" alt="Preview">
                                    </div>
                                    <?php if (isset($errors['featured_image'])): ?>
                                        <div class="form-error"><?php echo e($errors['featured_image']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Settings -->
                            <div class="form-section">
                                <h4 class="form-section-title">
                                    <i class="fas fa-cog"></i>
                                    Settings
                                </h4>
                                
                                <div class="form-group">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="is_popular" 
                                               name="is_popular" 
                                               value="1"
                                               <?php echo (($_POST['is_popular'] ?? $service['is_popular']) == 1) ? 'checked' : ''; ?>>
                                        <label for="is_popular" class="checkbox-label">
                                            <i class="fas fa-star"></i> Mark as Popular Service
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="is_active" 
                                               name="is_active" 
                                               value="1"
                                               <?php echo (($_POST['is_active'] ?? $service['is_active']) == 1) ? 'checked' : ''; ?>>
                                        <label for="is_active" class="checkbox-label">
                                            <i class="fas fa-check-circle"></i> Active Service
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="display_order" class="form-label">
                                        Display Order
                                    </label>
                                    <input type="number" 
                                           id="display_order" 
                                           name="display_order" 
                                           class="form-control" 
                                           value="<?php echo e($_POST['display_order'] ?? $service['display_order']); ?>"
                                           min="0"
                                           step="1">
                                    <span class="form-text">Lower numbers display first</span>
                                </div>
                            </div>
                            
                            <!-- SEO Settings -->
                            <div class="form-section">
                                <h4 class="form-section-title">
                                    <i class="fas fa-search"></i>
                                    SEO Settings
                                </h4>
                                
                                <div class="form-group">
                                    <label for="seo_title" class="form-label">
                                        SEO Title
                                    </label>
                                    <input type="text" 
                                           id="seo_title" 
                                           name="seo_title" 
                                           class="form-control" 
                                           value="<?php echo e($_POST['seo_title'] ?? $service['seo_title']); ?>"
                                           placeholder="Auto-generated from service name">
                                    <div class="char-counter" id="seoTitleCounter">0/60</div>
                                    <span class="form-text">Leave empty to auto-generate (max 60 characters)</span>
                                </div>
                                
                                <div class="form-group">
                                    <label for="seo_description" class="form-label">
                                        SEO Description
                                    </label>
                                    <textarea id="seo_description" 
                                              name="seo_description" 
                                              class="form-control" 
                                              rows="3"
                                              maxlength="160"
                                              placeholder="Auto-generated from short description"><?php echo e($_POST['seo_description'] ?? $service['seo_description']); ?></textarea>
                                    <div class="char-counter" id="seoDescCounter">0/160</div>
                                    <span class="form-text">Leave empty to auto-generate (max 160 characters)</span>
                                </div>
                                
                                <div class="form-group">
                                    <label for="seo_keywords" class="form-label">
                                        SEO Keywords
                                    </label>
                                    <input type="text" 
                                           id="seo_keywords" 
                                           name="seo_keywords" 
                                           class="form-control" 
                                           value="<?php echo e($_POST['seo_keywords'] ?? $service['seo_keywords']); ?>"
                                           placeholder="web development, cameroon, mobile apps">
                                    <span class="form-text">Separate keywords with commas</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" name="update_service" class="btn btn-primary btn-lg">
                            <i class="fas fa-save"></i> Update Service
                        </button>
                        <a href="<?php echo url('/admin/modules/services/index.php'); ?>" class="btn btn-outline btn-lg">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Generate slug from service name
            document.getElementById('generateSlug')?.addEventListener('click', function() {
                const serviceName = document.getElementById('service_name').value;
                if (serviceName) {
                    const slug = serviceName.toLowerCase()
                        .replace(/[^a-z0-9\s-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-')
                        .replace(/^-|-$/g, '');
                    document.getElementById('slug').value = slug;
                }
            });
            
            // Image preview
            const featuredImageInput = document.getElementById('featured_image');
            const imagePreview = document.getElementById('imagePreview');
            const previewImg = imagePreview.querySelector('img');
            
            if (featuredImageInput) {
                featuredImageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            previewImg.src = e.target.result;
                            imagePreview.style.display = 'block';
                        }
                        reader.readAsDataURL(file);
                    } else {
                        imagePreview.style.display = 'none';
                    }
                });
            }
            
            // Character counters
            function setupCharCounter(textarea, counterId, maxLength) {
                const counter = document.getElementById(counterId);
                if (!counter || !textarea) return;
                
                function updateCounter() {
                    const length = textarea.value.length;
                    counter.textContent = `${length}/${maxLength}`;
                    
                    if (length > maxLength * 0.9) {
                        counter.style.color = 'var(--color-warning)';
                    } else if (length > maxLength) {
                        counter.style.color = 'var(--color-error)';
                    } else {
                        counter.style.color = 'var(--color-gray-500)';
                    }
                }
                
                textarea.addEventListener('input', updateCounter);
                updateCounter();
            }
            
            const shortDescInput = document.getElementById('short_description');
            const seoTitleInput = document.getElementById('seo_title');
            const seoDescInput = document.getElementById('seo_description');
            
            setupCharCounter(shortDescInput, 'shortDescCounter', 500);
            setupCharCounter(seoTitleInput, 'seoTitleCounter', 60);
            setupCharCounter(seoDescInput, 'seoDescCounter', 160);
            
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
        });
    </script>
</body>
</html>