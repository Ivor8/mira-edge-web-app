<?php
/**
 * Add New Service
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
$user_id = $user['user_id'];

// Get categories
$stmt = $db->query("SELECT * FROM service_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $stmt->fetchAll();

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $service_name = trim($_POST['service_name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $service_category_id = $_POST['service_category_id'] ?? null;
    $short_description = trim($_POST['short_description'] ?? '');
    $full_description = trim($_POST['full_description'] ?? '');
    $base_price = $_POST['base_price'] ?? null;
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
    
    // Check if slug exists
    $stmt = $db->prepare("SELECT service_id FROM services WHERE slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        $errors['slug'] = 'This slug is already in use. Please choose a different one.';
    }
    
    // Handle file upload
    $featured_image = null;
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
            $upload_dir = dirname(dirname(dirname(dirname(__FILE__)))) . '/assets/uploads/services/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $upload_dir . $filename)) {
                $featured_image = '/assets/uploads/services/' . $filename;
            } else {
                $errors['featured_image'] = 'Failed to upload image';
            }
        }
    }
    
    // If no errors, insert service
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("
                INSERT INTO services 
                (service_name, slug, service_category_id, short_description, full_description, 
                 base_price, featured_image, estimated_duration, is_popular, is_active, 
                 display_order, seo_title, seo_description, seo_keywords)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $service_name, $slug, $service_category_id, $short_description, $full_description,
                $base_price, $featured_image, $estimated_duration, $is_popular, $is_active,
                $display_order, $seo_title, $seo_description, $seo_keywords
            ]);
            
            $service_id = $db->lastInsertId();
            
            $session->setFlash('success', 'Service added successfully!');
            redirect(url('/admin/modules/services/edit.php?id=' . $service_id));
            
        } catch (PDOException $e) {
            $errors['general'] = 'Error adding service: ' . $e->getMessage();
            error_log("Add Service Error: " . $e->getMessage());
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Service | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/services.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-section {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-lg);
            border: 1px solid var(--color-gray-200);
        }
        
        .form-section-title {
            font-size: 1.25rem;
            margin-bottom: var(--space-lg);
            color: var(--color-black);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
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
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-md);
        }
        
        .form-group {
            margin-bottom: var(--space-md);
        }
        
        .form-group:last-child {
            margin-bottom: 0;
        }
        
        .form-label {
            display: block;
            margin-bottom: var(--space-sm);
            font-weight: 600;
            color: var(--color-gray-700);
            font-size: 0.875rem;
        }
        
        .form-label.required::after {
            content: '*';
            color: var(--color-error);
            margin-left: 2px;
        }
        
        .form-control {
            display: block;
            width: 100%;
            padding: 10px 12px;
            font-family: var(--font-primary);
            font-size: 0.875rem;
            color: var(--color-gray-800);
            background-color: var(--color-white);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--color-black);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-control.error {
            border-color: var(--color-error);
            background-color: rgba(244, 67, 54, 0.02);
        }
        
        .form-error {
            font-size: 0.75rem;
            color: var(--color-error);
            font-weight: 500;
            margin-top: 4px;
        }
        
        .form-text {
            font-size: 0.75rem;
            color: var(--color-gray-500);
            display: block;
            margin-top: 4px;
        }
        
        .image-preview {
            max-width: 200px;
            margin-top: var(--space-sm);
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 2px solid var(--color-gray-200);
            display: none;
        }
        
        .image-preview img {
            width: 100%;
            height: auto;
            display: block;
        }
        
        .file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        
        .file-input {
            display: none;
        }
        
        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-sm);
            flex-direction: column;
            padding: var(--space-xl);
            border: 2px dashed var(--color-gray-300);
            border-radius: var(--radius-md);
            background-color: var(--color-gray-50);
            color: var(--color-gray-600);
            cursor: pointer;
            transition: all var(--transition-fast);
            font-weight: 500;
            text-align: center;
        }
        
        .file-upload-label:hover,
        .file-upload .file-input:focus + .file-upload-label {
            border-color: var(--color-black);
            background-color: var(--color-white);
            color: var(--color-black);
        }
        
        .file-upload .file-input:focus + .file-upload-label {
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .file-upload-label i {
            font-size: 1.5rem;
        }
        
        .char-counter {
            font-size: 0.75rem;
            color: var(--color-gray-500);
            text-align: right;
            margin-top: 4px;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--color-black);
            border-radius: 4px;
        }
        
        .checkbox-label {
            font-size: 0.875rem;
            color: var(--color-gray-700);
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }
        
        .checkbox-label:hover {
            color: var(--color-black);
        }
        
        .form-actions {
            display: flex;
            gap: var(--space-md);
            justify-content: flex-start;
            padding-top: var(--space-lg);
            border-top: 1px solid var(--color-gray-200);
            margin-top: var(--space-xl);
        }
        
        .btn-lg {
            padding: 12px 24px;
            font-weight: 600;
        }
        
        .alert {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
            padding: var(--space-md) var(--space-lg);
            border-radius: var(--radius-md);
            border-left: 4px solid;
            animation: slideInDown 0.3s ease-out;
        }
        
        .alert-success {
            background-color: rgba(0, 200, 83, 0.1);
            border-left-color: var(--color-success);
            color: var(--color-success-dark);
        }
        
        .alert-error {
            background-color: rgba(244, 67, 54, 0.1);
            border-left-color: var(--color-error);
            color: var(--color-error-dark);
        }
        
        .alert i {
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .alert-close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .alert-close:hover {
            opacity: 0.7;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
                justify-content: center;
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
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-plus-circle"></i>
                    Add New Service
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/services/'); ?>" class="btn btn-outline">
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

            <!-- Add Service Form -->
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data" id="addServiceForm">
                        <?php if (isset($errors['general'])): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo e($errors['general']); ?>
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
                                               value="<?php echo e($_POST['service_name'] ?? ''); ?>"
                                               required
                                               autofocus>
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
                                                   value="<?php echo e($_POST['slug'] ?? ''); ?>"
                                                   placeholder="Auto-generated from name">
                                            <button type="button" id="generateSlug" class="btn btn-outline">
                                                <i class="fas fa-sync-alt"></i> Generate
                                            </button>
                                        </div>
                                        <?php if (isset($errors['slug'])): ?>
                                            <div class="form-error"><?php echo e($errors['slug']); ?></div>
                                        <?php endif; ?>
                                        <small class="form-text">Leave empty to auto-generate from service name</small>
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
                                                        <?php echo (($_POST['service_category_id'] ?? '') == $category['service_category_id']) ? 'selected' : ''; ?>>
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
                                                  required><?php echo e($_POST['short_description'] ?? ''); ?></textarea>
                                        <div class="char-counter" id="shortDescCounter">0/500</div>
                                        <?php if (isset($errors['short_description'])): ?>
                                            <div class="form-error"><?php echo e($errors['short_description']); ?></div>
                                        <?php endif; ?>
                                        <small class="form-text">Brief description for listings (max 500 characters)</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="full_description" class="form-label required">
                                            Full Description
                                        </label>
                                        <textarea id="full_description" 
                                                  name="full_description" 
                                                  class="form-control <?php echo isset($errors['full_description']) ? 'error' : ''; ?>" 
                                                  rows="10"
                                                  required><?php echo e($_POST['full_description'] ?? ''); ?></textarea>
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
                                                   value="<?php echo e($_POST['base_price'] ?? ''); ?>"
                                                   min="0"
                                                   step="1000"
                                                   placeholder="Leave empty for custom pricing">
                                            <small class="form-text">Leave empty for "Contact for quote"</small>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="estimated_duration" class="form-label">
                                                Estimated Duration
                                            </label>
                                            <input type="text" 
                                                   id="estimated_duration" 
                                                   name="estimated_duration" 
                                                   class="form-control" 
                                                   value="<?php echo e($_POST['estimated_duration'] ?? ''); ?>"
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
                                    
                                    <div class="form-group">
                                        <label for="featured_image" class="form-label">
                                            Featured Image
                                        </label>
                                        <input type="file" 
                                               id="featured_image" 
                                               name="featured_image" 
                                               class="file-input <?php echo isset($errors['featured_image']) ? 'error' : ''; ?>"
                                               accept="image/*">
                                        <label for="featured_image" class="file-upload-label">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <div>Choose an image</div>
                                            <small>Click to browse or drag and drop</small>
                                        </label>
                                        <div class="image-preview" id="imagePreview">
                                            <img src="" alt="Preview">
                                        </div>
                                        <?php if (isset($errors['featured_image'])): ?>
                                            <div class="form-error"><?php echo e($errors['featured_image']); ?></div>
                                        <?php endif; ?>
                                        <small class="form-text">Recommended: 800x600 pixels, Max: 5MB</small>
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
                                                   <?php echo (($_POST['is_popular'] ?? 0) == 1) ? 'checked' : ''; ?>>
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
                                                   <?php echo (($_POST['is_active'] ?? 1) == 1) ? 'checked' : ''; ?>>
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
                                               value="<?php echo e($_POST['display_order'] ?? 0); ?>"
                                               min="0"
                                               step="1">
                                        <small class="form-text">Lower numbers display first</small>
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
                                               value="<?php echo e($_POST['seo_title'] ?? ''); ?>"
                                               placeholder="Auto-generated from service name">
                                        <div class="char-counter" id="seoTitleCounter">0/60</div>
                                        <small class="form-text">Leave empty to auto-generate (max 60 characters)</small>
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
                                                  placeholder="Auto-generated from short description"><?php echo e($_POST['seo_description'] ?? ''); ?></textarea>
                                        <div class="char-counter" id="seoDescCounter">0/160</div>
                                        <small class="form-text">Leave empty to auto-generate (max 160 characters)</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="seo_keywords" class="form-label">
                                            SEO Keywords
                                        </label>
                                        <input type="text" 
                                               id="seo_keywords" 
                                               name="seo_keywords" 
                                               class="form-control" 
                                               value="<?php echo e($_POST['seo_keywords'] ?? ''); ?>"
                                               placeholder="web development, cameroon, mobile apps">
                                        <small class="form-text">Separate keywords with commas</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" name="add_service" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Save Service
                            </button>
                            <button type="reset" class="btn btn-outline">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                            <a href="<?php echo url('/admin/modules/services/'); ?>" class="btn btn-outline">
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
            // Generate slug from service name
            document.getElementById('generateSlug').addEventListener('click', function() {
                const serviceName = document.getElementById('service_name').value;
                if (serviceName) {
                    const slug = serviceName.toLowerCase()
                        .replace(/[^a-z0-9\s-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-')
                        .trim();
                    document.getElementById('slug').value = slug;
                }
            });
            
            // Auto-generate SEO fields
            const serviceNameInput = document.getElementById('service_name');
            const shortDescInput = document.getElementById('short_description');
            const seoTitleInput = document.getElementById('seo_title');
            const seoDescInput = document.getElementById('seo_description');
            
            function updateSeoFields() {
                // Update SEO Title
                if (!seoTitleInput.value && serviceNameInput.value) {
                    seoTitleInput.value = serviceNameInput.value + ' | Mira Edge Technologies';
                }
                
                // Update SEO Description
                if (!seoDescInput.value && shortDescInput.value) {
                    seoDescInput.value = shortDescInput.value.substring(0, 160);
                }
            }
            
            serviceNameInput.addEventListener('input', updateSeoFields);
            shortDescInput.addEventListener('input', updateSeoFields);
            
            // Image preview
            const featuredImageInput = document.getElementById('featured_image');
            const imagePreview = document.getElementById('imagePreview');
            const previewImg = imagePreview.querySelector('img');
            
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
            
            // Character counters
            function setupCharCounter(textarea, counterId, maxLength) {
                const counter = document.getElementById(counterId);
                
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
            
            setupCharCounter(shortDescInput, 'shortDescCounter', 500);
            setupCharCounter(seoTitleInput, 'seoTitleCounter', 60);
            setupCharCounter(seoDescInput, 'seoDescCounter', 160);
            
            // Form validation
            const form = document.getElementById('addServiceForm');
            form.addEventListener('submit', function(e) {
                let valid = true;
                
                // Check required fields
                const requiredFields = form.querySelectorAll('[required]');
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        valid = false;
                        field.classList.add('error');
                        
                        if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('form-error')) {
                            const error = document.createElement('div');
                            error.className = 'form-error';
                            error.textContent = 'This field is required';
                            field.parentNode.insertBefore(error, field.nextSibling);
                        }
                    } else {
                        field.classList.remove('error');
                        const error = field.nextElementSibling;
                        if (error && error.classList.contains('form-error')) {
                            error.remove();
                        }
                    }
                });
                
                if (!valid) {
                    e.preventDefault();
                    showNotification('Please fill in all required fields', 'error');
                }
            });
        });
    </script>
</body>
</html>