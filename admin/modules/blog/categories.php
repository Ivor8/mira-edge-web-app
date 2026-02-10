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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        // Add new category
        $category_name = trim($_POST['category_name']);
        $slug = trim($_POST['slug']);
        $description = trim($_POST['description']);
        $seo_title = trim($_POST['seo_title']);
        $seo_description = trim($_POST['seo_description']);
        $display_order = (int)$_POST['display_order'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($slug)) {
            $slug = generateSlug($category_name);
        }
        
        // Check if slug exists
        $stmt = $db->prepare("SELECT blog_category_id FROM blog_categories WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            $session->setFlash('error', 'This slug is already in use. Please choose a different one.');
        } elseif (!empty($category_name)) {
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
                redirect(url('/admin/modules/blog/categories.php'));
                
            } catch (PDOException $e) {
                $session->setFlash('error', 'Error adding category: ' . $e->getMessage());
            }
        }
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_categories'])) {
        $action = $_POST['bulk_action'];
        $selected_categories = $_POST['selected_categories'];
        
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
                    $post_count = $stmt->fetch()['count'];
                    
                    if ($post_count > 0) {
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
    <link rel="stylesheet" href="<?php echo url('/assets/css/blog.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Admin Header -->
    <?php include '../includes/admin-header.php'; ?>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <?php include '../includes/admin-sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-folder"></i>
                    Blog Categories
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/blog/'); ?>" class="btn btn-outline">
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

            <!-- Two Column Layout -->
            <div class="two-column-layout">
                <!-- Left Column: Add/Edit Category Form -->
                <div class="left-column">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-plus-circle"></i>
                                Add New Category
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" id="categoryForm">
                                <div class="form-group">
                                    <label for="category_name" class="form-label required">Category Name</label>
                                    <input type="text" 
                                           id="category_name" 
                                           name="category_name" 
                                           class="form-control" 
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
                                               placeholder="Auto-generated from name">
                                        <button type="button" id="generateSlug" class="btn btn-outline">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                    <small class="form-text">Leave empty to auto-generate</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea id="description" 
                                              name="description" 
                                              class="form-control" 
                                              rows="3"
                                              placeholder="Category description (optional)..."></textarea>
                                </div>
                                
                                <div class="form-row">
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
                                        <label class="form-label">Status</label>
                                        <div class="checkbox-wrapper">
                                            <input type="checkbox" 
                                                   id="is_active" 
                                                   name="is_active" 
                                                   value="1"
                                                   checked>
                                            <label for="is_active" class="checkbox-label">
                                                Active Category
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SEO Section -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-search"></i> SEO Settings
                                    </h4>
                                    
                                    <div class="form-group">
                                        <label for="seo_title" class="form-label">SEO Title</label>
                                        <input type="text" 
                                               id="seo_title" 
                                               name="seo_title" 
                                               class="form-control" 
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
                                                  placeholder="Auto-generated from description..."></textarea>
                                        <div class="char-counter" id="seoDescCounter">0/160</div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" name="add_category" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Category
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Categories List -->
                <div class="right-column">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-list"></i>
                                All Categories (<?php echo count($categories); ?>)
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
                            <?php if (!empty($categories)): ?>
                                <div class="categories-list">
                                    <?php foreach ($categories as $category): ?>
                                        <div class="category-item" data-category-id="<?php echo $category['blog_category_id']; ?>">
                                            <div class="category-checkbox">
                                                <input type="checkbox" 
                                                       name="selected_categories[]" 
                                                       value="<?php echo $category['blog_category_id']; ?>"
                                                       class="category-checkbox-input">
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
                                                <a