<?php
/**
 * Blog Categories Management
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check if user is logged in and is admin/content_manager
if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

if (!in_array($session->getUserRole(), ['super_admin', 'admin', 'content_manager'])) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}

$user = $session->getUser();

// Handle single category actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $category_id = (int)$_GET['id'];
    
    try {
        if ($action === 'edit') {
            // Get category data for editing
            $stmt = $db->prepare("SELECT * FROM blog_categories WHERE blog_category_id = ?");
            $stmt->execute([$category_id]);
            $edit_category = $stmt->fetch();
        } elseif ($action === 'delete') {
            // Check if category has posts
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM blog_posts WHERE blog_category_id = ?");
            $stmt->execute([$category_id]);
            $post_count = $stmt->fetch()['count'];
            
            if ($post_count > 0) {
                $session->setFlash('error', 'Cannot delete category that has posts. Move posts to other categories first.');
            } else {
                $stmt = $db->prepare("DELETE FROM blog_categories WHERE blog_category_id = ?");
                $stmt->execute([$category_id]);
                $session->setFlash('success', 'Category deleted successfully.');
            }
            redirect(url('/admin/modules/blog/categories.php'));
        } elseif ($action === 'activate') {
            $stmt = $db->prepare("UPDATE blog_categories SET is_active = 1 WHERE blog_category_id = ?");
            $stmt->execute([$category_id]);
            $session->setFlash('success', 'Category activated successfully.');
            redirect(url('/admin/modules/blog/categories.php'));
        } elseif ($action === 'deactivate') {
            $stmt = $db->prepare("UPDATE blog_categories SET is_active = 0 WHERE blog_category_id = ?");
            $stmt->execute([$category_id]);
            $session->setFlash('success', 'Category deactivated successfully.');
            redirect(url('/admin/modules/blog/categories.php'));
        }
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error processing request: ' . $e->getMessage());
        redirect(url('/admin/modules/blog/categories.php'));
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        // Add new category
        $category_name = trim($_POST['category_name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $seo_title = trim($_POST['seo_title'] ?? '');
        $seo_description = trim($_POST['seo_description'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($slug)) {
            $slug = generateSlug($category_name);
        }
        
        if (empty($category_name)) {
            $session->setFlash('error', 'Category name is required.');
        } else {
            // Check if slug exists
            $stmt = $db->prepare("SELECT blog_category_id FROM blog_categories WHERE slug = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetch()) {
                $session->setFlash('error', 'This slug is already in use. Please choose a different one.');
            } else {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO blog_categories 
                        (category_name, slug, description, seo_title, seo_description, display_order, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $category_name, $slug, $description, $seo_title, $seo_description, $display_order, $is_active
                    ]);
                    
                    $session->setFlash('success', 'Category added successfully!');
                    
                } catch (PDOException $e) {
                    $session->setFlash('error', 'Error adding category: ' . $e->getMessage());
                }
            }
        }
        redirect(url('/admin/modules/blog/categories.php'));
        
    } elseif (isset($_POST['update_category'])) {
        // Update category
        $category_id = (int)($_POST['category_id'] ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $seo_title = trim($_POST['seo_title'] ?? '');
        $seo_description = trim($_POST['seo_description'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($slug)) {
            $slug = generateSlug($category_name);
        }
        
        if (empty($category_name)) {
            $session->setFlash('error', 'Category name is required.');
        } else {
            // Check if slug exists (excluding current)
            $stmt = $db->prepare("SELECT blog_category_id FROM blog_categories WHERE slug = ? AND blog_category_id != ?");
            $stmt->execute([$slug, $category_id]);
            if ($stmt->fetch()) {
                $session->setFlash('error', 'This slug is already in use. Please choose a different one.');
            } else {
                try {
                    $stmt = $db->prepare("
                        UPDATE blog_categories 
                        SET category_name = ?, slug = ?, description = ?, 
                            seo_title = ?, seo_description = ?, display_order = ?, is_active = ?
                        WHERE blog_category_id = ?
                    ");
                    
                    $stmt->execute([
                        $category_name, $slug, $description, $seo_title, $seo_description, $display_order, $is_active, $category_id
                    ]);
                    
                    $session->setFlash('success', 'Category updated successfully!');
                    
                } catch (PDOException $e) {
                    $session->setFlash('error', 'Error updating category: ' . $e->getMessage());
                }
            }
        }
        redirect(url('/admin/modules/blog/categories.php'));
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_categories'])) {
        $action = $_POST['bulk_action'];
        $selected_categories = $_POST['selected_categories'];
        
        if (!empty($selected_categories)) {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_categories), '?'));
                
                switch ($action) {
                    case 'activate':
                        $stmt = $db->prepare("UPDATE blog_categories SET is_active = 1 WHERE blog_category_id IN ($placeholders)");
                        $stmt->execute($selected_categories);
                        $session->setFlash('success', 'Selected categories activated successfully.');
                        break;
                        
                    case 'deactivate':
                        $stmt = $db->prepare("UPDATE blog_categories SET is_active = 0 WHERE blog_category_id IN ($placeholders)");
                        $stmt->execute($selected_categories);
                        $session->setFlash('success', 'Selected categories deactivated successfully.');
                        break;
                        
                    case 'delete':
                        // Check if categories have posts
                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM blog_posts WHERE blog_category_id IN ($placeholders)");
                        $stmt->execute($selected_categories);
                        $result = $stmt->fetch();
                        
                        if ($result['count'] > 0) {
                            $session->setFlash('error', 'Cannot delete categories that have posts. Move posts to other categories first.');
                        } else {
                            $stmt = $db->prepare("DELETE FROM blog_categories WHERE blog_category_id IN ($placeholders)");
                            $stmt->execute($selected_categories);
                            $session->setFlash('success', 'Selected categories deleted successfully.');
                        }
                        break;
                }
            } catch (PDOException $e) {
                error_log("Bulk Action Error: " . $e->getMessage());
                $session->setFlash('error', 'Error performing bulk action.');
            }
        }
        redirect(url('/admin/modules/blog/categories.php'));
    }
}

// Get all categories with post counts
$sql = "
    SELECT bc.*, 
           COUNT(bp.post_id) as post_count
    FROM blog_categories bc
    LEFT JOIN blog_posts bp ON bc.blog_category_id = bp.blog_category_id
    GROUP BY bc.blog_category_id
    ORDER BY bc.display_order, bc.category_name
";

$stmt = $db->query($sql);
$categories = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog Categories | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .categories-container {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: var(--space-xl);
        }
        
        @media (max-width: 992px) {
            .categories-container {
                grid-template-columns: 1fr;
            }
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
        
        .input-with-button {
            display: flex;
            gap: var(--space-sm);
        }
        
        .input-with-button .form-control {
            flex: 1;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-lg);
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            margin-top: 8px;
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
        
        .char-counter {
            font-size: 0.75rem;
            color: var(--color-gray-500);
            text-align: right;
            margin-top: 4px;
        }
        
        .categories-list {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-md);
        }
        
        .categories-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-lg);
            padding-bottom: var(--space-sm);
            border-bottom: 1px solid var(--color-gray-200);
        }
        
        .categories-header h3 {
            margin: 0;
            font-size: 1.25rem;
        }
        
        .bulk-actions {
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
        }
        
        .category-item {
            display: flex;
            align-items: flex-start;
            gap: var(--space-md);
            padding: var(--space-md);
            border-bottom: 1px solid var(--color-gray-200);
            transition: background-color var(--transition-fast);
        }
        
        .category-item:hover {
            background-color: var(--color-gray-50);
        }
        
        .category-item:last-child {
            border-bottom: none;
        }
        
        .category-checkbox {
            padding-top: 4px;
        }
        
        .category-checkbox input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        .category-content {
            flex: 1;
        }
        
        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-xs);
        }
        
        .category-name {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-black);
        }
        
        .post-count {
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .post-count i {
            margin-right: 4px;
        }
        
        .category-description {
            margin: 0 0 var(--space-xs);
            font-size: 0.875rem;
            color: var(--color-gray-600);
            line-height: 1.5;
        }
        
        .category-meta {
            display: flex;
            gap: var(--space-lg);
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .category-slug i,
        .category-order i {
            margin-right: 4px;
        }
        
        .category-actions {
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
            background: none;
            cursor: pointer;
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
        
        .btn-activate:hover {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .btn-deactivate:hover {
            background-color: rgba(255, 152, 0, 0.1);
            color: var(--color-warning);
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: var(--space-sm);
        }
        
        .status-active {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success-dark);
        }
        
        .status-inactive {
            background-color: rgba(255, 152, 0, 0.1);
            color: var(--color-warning-dark);
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
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
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
        
        .empty-state {
            text-align: center;
            padding: var(--space-3xl) var(--space-xl);
        }
        
        .empty-state-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto var(--space-lg);
            background-color: var(--color-gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--color-gray-400);
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
        
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-xl);
            padding-bottom: var(--space-md);
            border-bottom: 1px solid var(--color-gray-200);
        }
        
        .page-title {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-black);
        }
        
        .page-actions {
            display: flex;
            align-items: center;
            gap: var(--space-lg);
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
                    <i class="fas fa-folder"></i>
                    Blog Categories
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/blog/index.php'); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Posts
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

            <div class="categories-container">
                <!-- Left Column: Add/Edit Category Form -->
                <div class="left-column">
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-<?php echo isset($edit_category) ? 'edit' : 'plus-circle'; ?>"></i>
                            <?php echo isset($edit_category) ? 'Edit Category' : 'Add New Category'; ?>
                        </h3>
                        
                        <form method="POST" action="" id="categoryForm">
                            <?php if (isset($edit_category)): ?>
                                <input type="hidden" name="category_id" value="<?php echo $edit_category['blog_category_id']; ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="category_name" class="form-label required">Category Name</label>
                                <input type="text" 
                                       id="category_name" 
                                       name="category_name" 
                                       class="form-control" 
                                       value="<?php echo e($edit_category['category_name'] ?? ''); ?>"
                                       required
                                       placeholder="Enter category name...">
                            </div>
                            
                            <div class="form-group">
                                <label for="slug" class="form-label">URL Slug</label>
                                <div class="input-with-button">
                                    <input type="text" 
                                           id="slug" 
                                           name="slug" 
                                           class="form-control" 
                                           value="<?php echo e($edit_category['slug'] ?? ''); ?>"
                                           placeholder="auto-generated-from-name">
                                    <button type="button" id="generateSlug" class="btn btn-outline">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                                <span class="form-text">Leave empty to auto-generate</span>
                            </div>
                            
                            <div class="form-group">
                                <label for="description" class="form-label">Description</label>
                                <textarea id="description" 
                                          name="description" 
                                          class="form-control" 
                                          rows="3"
                                          placeholder="Category description (optional)..."><?php echo e($edit_category['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="display_order" class="form-label">Display Order</label>
                                    <input type="number" 
                                           id="display_order" 
                                           name="display_order" 
                                           class="form-control" 
                                           min="0"
                                           value="<?php echo e($edit_category['display_order'] ?? 0); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="is_active" 
                                               name="is_active" 
                                               value="1"
                                               <?php echo (!isset($edit_category) || $edit_category['is_active']) ? 'checked' : ''; ?>>
                                        <label for="is_active" class="checkbox-label">
                                            <i class="fas fa-check-circle"></i> Active Category
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- SEO Section -->
                            <div class="form-section" style="margin-top: var(--space-lg);">
                                <h4 class="form-section-title">
                                    <i class="fas fa-search"></i> SEO Settings
                                </h4>
                                
                                <div class="form-group">
                                    <label for="seo_title" class="form-label">SEO Title</label>
                                    <input type="text" 
                                           id="seo_title" 
                                           name="seo_title" 
                                           class="form-control" 
                                           value="<?php echo e($edit_category['seo_title'] ?? ''); ?>"
                                           placeholder="Auto-generated from category name">
                                    <div class="char-counter" id="seoTitleCounter">0/60</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="seo_description" class="form-label">SEO Description</label>
                                    <textarea id="seo_description" 
                                              name="seo_description" 
                                              class="form-control" 
                                              rows="2"
                                              maxlength="160"
                                              placeholder="Auto-generated from description..."><?php echo e($edit_category['seo_description'] ?? ''); ?></textarea>
                                    <div class="char-counter" id="seoDescCounter">0/160</div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <?php if (isset($edit_category)): ?>
                                    <button type="submit" name="update_category" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Category
                                    </button>
                                    <a href="<?php echo url('/admin/modules/blog/categories.php'); ?>" class="btn btn-outline">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="add_category" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Category
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Right Column: Categories List -->
                <div class="right-column">
                    <div class="categories-list">
                        <div class="categories-header">
                            <h3>
                                <i class="fas fa-list"></i>
                                All Categories (<?php echo count($categories); ?>)
                            </h3>
                            
                            <!-- Bulk Actions -->
                            <form method="POST" action="" class="bulk-actions" id="bulkForm">
                                <select name="bulk_action" class="bulk-action-select">
                                    <option value="">Bulk Actions</option>
                                    <option value="activate">Activate</option>
                                    <option value="deactivate">Deactivate</option>
                                    <option value="delete">Delete</option>
                                </select>
                                <button type="submit" class="btn btn-outline btn-sm" onclick="return confirmBulkAction()">
                                    Apply
                                </button>
                            </form>
                        </div>
                        
                        <?php if (!empty($categories)): ?>
                            <div class="categories-items">
                                <?php foreach ($categories as $category): ?>
                                    <div class="category-item" data-category-id="<?php echo $category['blog_category_id']; ?>">
                                        <div class="category-checkbox">
                                            <input type="checkbox" 
                                                   name="selected_categories[]" 
                                                   value="<?php echo $category['blog_category_id']; ?>"
                                                   class="category-checkbox-input"
                                                   form="bulkForm">
                                        </div>
                                        
                                        <div class="category-content">
                                            <div class="category-header">
                                                <h4 class="category-name">
                                                    <?php echo e($category['category_name']); ?>
                                                    <?php if (!$category['is_active']): ?>
                                                        <span class="status-badge status-inactive">Inactive</span>
                                                    <?php endif; ?>
                                                </h4>
                                                <span class="post-count">
                                                    <i class="fas fa-newspaper"></i> <?php echo $category['post_count']; ?> posts
                                                </span>
                                            </div>
                                            
                                            <?php if ($category['description']): ?>
                                                <p class="category-description">
                                                    <?php echo e($category['description']); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <div class="category-meta">
                                                <span class="category-slug">
                                                    <i class="fas fa-link"></i> /blog/category/<?php echo e($category['slug']); ?>
                                                </span>
                                                <span class="category-order">
                                                    <i class="fas fa-sort-numeric-down"></i> Order: <?php echo $category['display_order']; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="category-actions">
                                            <a href="?action=edit&id=<?php echo $category['blog_category_id']; ?>" 
                                               class="btn-action btn-edit"
                                               title="Edit Category">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <?php if ($category['is_active']): ?>
                                                <a href="?action=deactivate&id=<?php echo $category['blog_category_id']; ?>" 
                                                   class="btn-action btn-deactivate"
                                                   title="Deactivate"
                                                   onclick="return confirm('Deactivate this category?')">
                                                    <i class="fas fa-eye-slash"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="?action=activate&id=<?php echo $category['blog_category_id']; ?>" 
                                                   class="btn-action btn-activate"
                                                   title="Activate"
                                                   onclick="return confirm('Activate this category?')">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($category['post_count'] == 0): ?>
                                                <a href="?action=delete&id=<?php echo $category['blog_category_id']; ?>" 
                                                   class="btn-action btn-delete"
                                                   title="Delete Category"
                                                   onclick="return confirm('Delete this category? This cannot be undone.')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-folder-open"></i>
                                </div>
                                <h3>No Categories Found</h3>
                                <p>Create your first category to organize your blog posts.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('/assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Generate slug from category name
            const generateBtn = document.getElementById('generateSlug');
            if (generateBtn) {
                generateBtn.addEventListener('click', function() {
                    const nameInput = document.getElementById('category_name');
                    const slugInput = document.getElementById('slug');
                    
                    if (nameInput && nameInput.value) {
                        const slug = nameInput.value.toLowerCase()
                            .replace(/[^a-z0-9\s-]/g, '')
                            .replace(/\s+/g, '-')
                            .replace(/-+/g, '-')
                            .replace(/^-|-$/g, '');
                        slugInput.value = slug;
                    }
                });
            }
            
            // Character counters
            const seoTitleInput = document.getElementById('seo_title');
            const seoDescInput = document.getElementById('seo_description');
            const seoTitleCounter = document.getElementById('seoTitleCounter');
            const seoDescCounter = document.getElementById('seoDescCounter');
            
            function updateCounter(input, counter, max) {
                if (!input || !counter) return;
                
                const length = input.value.length;
                counter.textContent = `${length}/${max}`;
                
                if (length > max * 0.9) {
                    counter.style.color = 'var(--color-warning)';
                } else if (length > max) {
                    counter.style.color = 'var(--color-error)';
                } else {
                    counter.style.color = 'var(--color-gray-500)';
                }
            }
            
            if (seoTitleInput && seoTitleCounter) {
                seoTitleInput.addEventListener('input', function() {
                    updateCounter(seoTitleInput, seoTitleCounter, 60);
                });
                updateCounter(seoTitleInput, seoTitleCounter, 60);
            }
            
            if (seoDescInput && seoDescCounter) {
                seoDescInput.addEventListener('input', function() {
                    updateCounter(seoDescInput, seoDescCounter, 160);
                });
                updateCounter(seoDescInput, seoDescCounter, 160);
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
        });
        
        // Confirm bulk action
        function confirmBulkAction() {
            const checkboxes = document.querySelectorAll('.category-checkbox-input:checked');
            const action = document.querySelector('.bulk-action-select').value;
            
            if (checkboxes.length === 0) {
                alert('Please select at least one category.');
                return false;
            }
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            if (action === 'delete') {
                return confirm('Are you sure you want to delete the selected categories? This cannot be undone.');
            }
            
            return true;
        }
    </script>
</body>
</html>