<?php
/**
 * Blog Tags Management
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

// Handle single tag actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $tag_id = (int)$_GET['id'];
    
    try {
        if ($action === 'edit') {
            // Get tag data for editing
            $stmt = $db->prepare("SELECT * FROM blog_tags WHERE tag_id = ?");
            $stmt->execute([$tag_id]);
            $edit_tag = $stmt->fetch();
        } elseif ($action === 'delete') {
            // Check if tag has posts
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM blog_post_tags WHERE tag_id = ?");
            $stmt->execute([$tag_id]);
            $post_count = $stmt->fetch()['count'];
            
            if ($post_count > 0) {
                $session->setFlash('error', 'Cannot delete tag that is used in posts. Remove it from posts first.');
            } else {
                $stmt = $db->prepare("DELETE FROM blog_tags WHERE tag_id = ?");
                $stmt->execute([$tag_id]);
                $session->setFlash('success', 'Tag deleted successfully.');
            }
            redirect(url('/admin/modules/blog/tags.php'));
        }
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error processing request: ' . $e->getMessage());
        redirect(url('/admin/modules/blog/tags.php'));
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_tag'])) {
        // Add new tag
        $tag_name = trim($_POST['tag_name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        
        if (empty($slug)) {
            $slug = generateSlug($tag_name);
        }
        
        if (empty($tag_name)) {
            $session->setFlash('error', 'Tag name is required.');
        } else {
            // Check if tag exists
            $stmt = $db->prepare("SELECT tag_id FROM blog_tags WHERE tag_name = ? OR slug = ?");
            $stmt->execute([$tag_name, $slug]);
            if ($stmt->fetch()) {
                $session->setFlash('error', 'This tag already exists.');
            } else {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO blog_tags (tag_name, slug)
                        VALUES (?, ?)
                    ");
                    
                    $stmt->execute([$tag_name, $slug]);
                    
                    $session->setFlash('success', 'Tag added successfully!');
                    
                } catch (PDOException $e) {
                    $session->setFlash('error', 'Error adding tag: ' . $e->getMessage());
                }
            }
        }
        redirect(url('/admin/modules/blog/tags.php'));
        
    } elseif (isset($_POST['update_tag'])) {
        // Update tag
        $tag_id = (int)($_POST['tag_id'] ?? 0);
        $tag_name = trim($_POST['tag_name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        
        if (empty($slug)) {
            $slug = generateSlug($tag_name);
        }
        
        if (empty($tag_name)) {
            $session->setFlash('error', 'Tag name is required.');
        } else {
            // Check if tag exists (excluding current)
            $stmt = $db->prepare("SELECT tag_id FROM blog_tags WHERE (tag_name = ? OR slug = ?) AND tag_id != ?");
            $stmt->execute([$tag_name, $slug, $tag_id]);
            if ($stmt->fetch()) {
                $session->setFlash('error', 'This tag already exists.');
            } else {
                try {
                    $stmt = $db->prepare("
                        UPDATE blog_tags 
                        SET tag_name = ?, slug = ?
                        WHERE tag_id = ?
                    ");
                    
                    $stmt->execute([$tag_name, $slug, $tag_id]);
                    
                    $session->setFlash('success', 'Tag updated successfully!');
                    
                } catch (PDOException $e) {
                    $session->setFlash('error', 'Error updating tag: ' . $e->getMessage());
                }
            }
        }
        redirect(url('/admin/modules/blog/tags.php'));
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_tags'])) {
        $action = $_POST['bulk_action'];
        $selected_tags = $_POST['selected_tags'];
        
        if (!empty($selected_tags)) {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_tags), '?'));
                
                if ($action === 'delete') {
                    // Check if tags have posts
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM blog_post_tags WHERE tag_id IN ($placeholders)");
                    $stmt->execute($selected_tags);
                    $result = $stmt->fetch();
                    
                    if ($result['count'] > 0) {
                        $session->setFlash('error', 'Cannot delete tags that are used in posts.');
                    } else {
                        $stmt = $db->prepare("DELETE FROM blog_tags WHERE tag_id IN ($placeholders)");
                        $stmt->execute($selected_tags);
                        $session->setFlash('success', 'Selected tags deleted successfully.');
                    }
                }
            } catch (PDOException $e) {
                error_log("Bulk Action Error: " . $e->getMessage());
                $session->setFlash('error', 'Error performing bulk action.');
            }
        }
        redirect(url('/admin/modules/blog/tags.php'));
    }
}

// Get all tags with post counts
$sql = "
    SELECT t.*, 
           COUNT(pt.post_id) as post_count
    FROM blog_tags t
    LEFT JOIN blog_post_tags pt ON t.tag_id = pt.tag_id
    GROUP BY t.tag_id
    ORDER BY t.tag_name
";

$stmt = $db->query($sql);
$tags = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog Tags | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tags-container {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: var(--space-xl);
        }
        
        @media (max-width: 992px) {
            .tags-container {
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
        
        .tags-list {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-md);
        }
        
        .tags-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-lg);
            padding-bottom: var(--space-sm);
            border-bottom: 1px solid var(--color-gray-200);
        }
        
        .tags-header h3 {
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
        
        .tag-item {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            padding: var(--space-md);
            border-bottom: 1px solid var(--color-gray-200);
            transition: background-color var(--transition-fast);
        }
        
        .tag-item:hover {
            background-color: var(--color-gray-50);
        }
        
        .tag-item:last-child {
            border-bottom: none;
        }
        
        .tag-checkbox {
            padding-top: 4px;
        }
        
        .tag-checkbox input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        .tag-content {
            flex: 1;
        }
        
        .tag-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-xs);
        }
        
        .tag-name {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-black);
        }
        
        .post-count {
            font-size: 0.75rem;
            color: var(--color-gray-500);
            background: var(--color-gray-100);
            padding: 2px 8px;
            border-radius: var(--radius-full);
        }
        
        .post-count i {
            margin-right: 4px;
        }
        
        .tag-meta {
            display: flex;
            gap: var(--space-lg);
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .tag-slug i {
            margin-right: 4px;
        }
        
        .tag-actions {
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
            text-decoration: none;
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
                    <i class="fas fa-tags"></i>
                    Blog Tags
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

            <div class="tags-container">
                <!-- Left Column: Add/Edit Tag Form -->
                <div class="left-column">
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-<?php echo isset($edit_tag) ? 'edit' : 'plus-circle'; ?>"></i>
                            <?php echo isset($edit_tag) ? 'Edit Tag' : 'Add New Tag'; ?>
                        </h3>
                        
                        <form method="POST" action="" id="tagForm">
                            <?php if (isset($edit_tag)): ?>
                                <input type="hidden" name="tag_id" value="<?php echo $edit_tag['tag_id']; ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="tag_name" class="form-label required">Tag Name</label>
                                <input type="text" 
                                       id="tag_name" 
                                       name="tag_name" 
                                       class="form-control" 
                                       value="<?php echo e($edit_tag['tag_name'] ?? ''); ?>"
                                       required
                                       placeholder="Enter tag name...">
                            </div>
                            
                            <div class="form-group">
                                <label for="slug" class="form-label">URL Slug</label>
                                <div class="input-with-button">
                                    <input type="text" 
                                           id="slug" 
                                           name="slug" 
                                           class="form-control" 
                                           value="<?php echo e($edit_tag['slug'] ?? ''); ?>"
                                           placeholder="auto-generated-from-name">
                                    <button type="button" id="generateSlug" class="btn btn-outline">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                                <span class="form-text">Leave empty to auto-generate</span>
                            </div>
                            
                            <div class="form-group">
                                <?php if (isset($edit_tag)): ?>
                                    <button type="submit" name="update_tag" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Tag
                                    </button>
                                    <a href="<?php echo url('/admin/modules/blog/tags.php'); ?>" class="btn btn-outline">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="add_tag" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Tag
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Right Column: Tags List -->
                <div class="right-column">
                    <div class="tags-list">
                        <div class="tags-header">
                            <h3>
                                <i class="fas fa-list"></i>
                                All Tags (<?php echo count($tags); ?>)
                            </h3>
                            
                            <!-- Bulk Actions -->
                            <form method="POST" action="" class="bulk-actions" id="bulkForm">
                                <select name="bulk_action" class="bulk-action-select">
                                    <option value="">Bulk Actions</option>
                                    <option value="delete">Delete</option>
                                </select>
                                <button type="submit" class="btn btn-outline btn-sm" onclick="return confirmBulkAction()">
                                    Apply
                                </button>
                            </form>
                        </div>
                        
                        <?php if (!empty($tags)): ?>
                            <div class="tags-items">
                                <?php foreach ($tags as $tag): ?>
                                    <div class="tag-item" data-tag-id="<?php echo $tag['tag_id']; ?>">
                                        <div class="tag-checkbox">
                                            <input type="checkbox" 
                                                   name="selected_tags[]" 
                                                   value="<?php echo $tag['tag_id']; ?>"
                                                   class="tag-checkbox-input"
                                                   form="bulkForm">
                                        </div>
                                        
                                        <div class="tag-content">
                                            <div class="tag-header">
                                                <h4 class="tag-name">
                                                    <?php echo e($tag['tag_name']); ?>
                                                </h4>
                                                <span class="post-count">
                                                    <i class="fas fa-newspaper"></i> <?php echo $tag['post_count']; ?> posts
                                                </span>
                                            </div>
                                            
                                            <div class="tag-meta">
                                                <span class="tag-slug">
                                                    <i class="fas fa-link"></i> /blog/tag/<?php echo e($tag['slug']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="tag-actions">
                                            <a href="?action=edit&id=<?php echo $tag['tag_id']; ?>" 
                                               class="btn-action btn-edit"
                                               title="Edit Tag">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <?php if ($tag['post_count'] == 0): ?>
                                                <a href="?action=delete&id=<?php echo $tag['tag_id']; ?>" 
                                                   class="btn-action btn-delete"
                                                   title="Delete Tag"
                                                   onclick="return confirm('Delete this tag? This cannot be undone.')">
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
                                    <i class="fas fa-tags"></i>
                                </div>
                                <h3>No Tags Found</h3>
                                <p>Create your first tag to organize your blog posts.</p>
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
            // Generate slug from tag name
            const generateBtn = document.getElementById('generateSlug');
            if (generateBtn) {
                generateBtn.addEventListener('click', function() {
                    const nameInput = document.getElementById('tag_name');
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
            const checkboxes = document.querySelectorAll('.tag-checkbox-input:checked');
            const action = document.querySelector('.bulk-action-select').value;
            
            if (checkboxes.length === 0) {
                alert('Please select at least one tag.');
                return false;
            }
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            if (action === 'delete') {
                return confirm('Are you sure you want to delete the selected tags? This cannot be undone.');
            }
            
            return true;
        }
    </script>
</body>
</html>