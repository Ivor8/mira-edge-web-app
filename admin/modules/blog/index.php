<?php
/**
 * Blog Management - All Posts
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check if user is logged in and has permission
if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

$user = $session->getUser();

// Check permissions (admin, content_manager, or author)
if (!in_array($user['role'], ['super_admin', 'admin', 'content_manager'])) {
    // Check if user is author of any posts
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM blog_posts WHERE author_id = ?");
    $stmt->execute([$user['user_id']]);
    $post_count = $stmt->fetch()['count'];
    
    if ($post_count === 0) {
        $session->setFlash('error', 'Access denied. You need appropriate permissions.');
        redirect(url('/'));
    }
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_posts'])) {
        $action = $_POST['bulk_action'];
        $selected_posts = $_POST['selected_posts'];
        
        // Filter posts based on user role
        if (!in_array($user['role'], ['super_admin', 'admin', 'content_manager'])) {
            // Authors can only edit/delete their own posts
            $stmt = $db->prepare("SELECT post_id FROM blog_posts WHERE author_id = ? AND post_id IN (" . 
                implode(',', array_fill(0, count($selected_posts), '?')) . ")");
            $params = array_merge([$user['user_id']], $selected_posts);
            $stmt->execute($params);
            $author_posts = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $selected_posts = $author_posts;
        }
        
        if (!empty($selected_posts)) {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_posts), '?'));
                
                switch ($action) {
                    case 'publish':
                        $stmt = $db->prepare("UPDATE blog_posts SET status = 'published', published_at = NOW() WHERE post_id IN ($placeholders)");
                        $stmt->execute($selected_posts);
                        $session->setFlash('success', 'Selected posts published successfully.');
                        break;
                        
                    case 'draft':
                        $stmt = $db->prepare("UPDATE blog_posts SET status = 'draft' WHERE post_id IN ($placeholders)");
                        $stmt->execute($selected_posts);
                        $session->setFlash('success', 'Selected posts moved to draft.');
                        break;
                        
                    case 'archive':
                        $stmt = $db->prepare("UPDATE blog_posts SET status = 'archived' WHERE post_id IN ($placeholders)");
                        $stmt->execute($selected_posts);
                        $session->setFlash('success', 'Selected posts archived.');
                        break;
                        
                    case 'delete':
                        $stmt = $db->prepare("DELETE FROM blog_posts WHERE post_id IN ($placeholders)");
                        $stmt->execute($selected_posts);
                        $session->setFlash('success', 'Selected posts deleted successfully.');
                        break;
                        
                    case 'feature':
                        $stmt = $db->prepare("UPDATE blog_posts SET is_featured = 1 WHERE post_id IN ($placeholders)");
                        $stmt->execute($selected_posts);
                        $session->setFlash('success', 'Selected posts featured successfully.');
                        break;
                        
                    case 'unfeature':
                        $stmt = $db->prepare("UPDATE blog_posts SET is_featured = 0 WHERE post_id IN ($placeholders)");
                        $stmt->execute($selected_posts);
                        $session->setFlash('success', 'Selected posts unfeatured successfully.');
                        break;
                }
            } catch (PDOException $e) {
                error_log("Bulk Action Error: " . $e->getMessage());
                $session->setFlash('error', 'Error performing bulk action.');
            }
        }
    }
}

// Pagination and filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$author_filter = $_GET['author'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build query
$where_clauses = [];
$params = [];

if ($status_filter && $status_filter !== 'all') {
    $where_clauses[] = "bp.status = ?";
    $params[] = $status_filter;
}

if ($category_filter && $category_filter !== 'all') {
    $where_clauses[] = "bp.blog_category_id = ?";
    $params[] = $category_filter;
}

if ($author_filter && $author_filter !== 'all') {
    $where_clauses[] = "bp.author_id = ?";
    $params[] = $author_filter;
}

if ($search_query) {
    $where_clauses[] = "(bp.title LIKE ? OR bp.excerpt LIKE ? OR bp.content LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

// Restrict authors to their own posts
if (!in_array($user['role'], ['super_admin', 'admin', 'content_manager'])) {
    $where_clauses[] = "bp.author_id = ?";
    $params[] = $user['user_id'];
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM blog_posts bp $where_sql";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_items = $stmt->fetch()['total'];
$total_pages = ceil($total_items / $per_page);

// Get posts with pagination
$posts_sql = "
    SELECT bp.*, bc.category_name, 
           CONCAT(u.first_name, ' ', u.last_name) as author_name,
           u.profile_image as author_image,
           (SELECT COUNT(*) FROM blog_post_tags bpt WHERE bpt.post_id = bp.post_id) as tag_count,
           (SELECT COUNT(*) FROM blog_comments bc WHERE bc.post_id = bp.post_id AND bc.is_approved = 1) as comment_count
    FROM blog_posts bp
    LEFT JOIN blog_categories bc ON bp.blog_category_id = bc.blog_category_id
    LEFT JOIN users u ON bp.author_id = u.user_id
    $where_sql
    ORDER BY bp.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($posts_sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Get categories for filter
$stmt = $db->query("SELECT blog_category_id, category_name FROM blog_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $stmt->fetchAll();

// Get authors for filter
$stmt = $db->query("
    SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) as author_name
    FROM blog_posts bp
    JOIN users u ON bp.author_id = u.user_id
    ORDER BY author_name
");
$authors = $stmt->fetchAll();

// Get stats
$stats_sql = "
    SELECT 
        COUNT(*) as total_posts,
        SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_posts,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_posts,
        SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured_posts,
        SUM(views_count) as total_views
    FROM blog_posts
";

if (!in_array($user['role'], ['super_admin', 'admin', 'content_manager'])) {
    $stats_sql .= " WHERE author_id = ?";
    $stmt = $db->prepare($stats_sql);
    $stmt->execute([$user['user_id']]);
} else {
    $stmt = $db->query($stats_sql);
}

$stats = $stmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog Management | Admin Dashboard</title>
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
                    <i class="fas fa-blog"></i>
                    Blog Management
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/blog/add.php'); ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Post
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

            <!-- Blog Stats -->
            <div class="blog-stats">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo $stats['total_posts']; ?></h3>
                        <p class="stat-label">Total Posts</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo $stats['published_posts']; ?></h3>
                        <p class="stat-label">Published</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo $stats['draft_posts']; ?></h3>
                        <p class="stat-label">Drafts</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo number_format($stats['total_views']); ?></h3>
                        <p class="stat-label">Total Views</p>
                    </div>
                </div>
            </div>

            <!-- Filters & Search -->
            <div class="card filters-card">
                <div class="card-body">
                    <form method="GET" action="" class="filters-form">
                        <div class="filters-grid">
                            <!-- Search -->
                            <div class="filter-group">
                                <label for="search" class="filter-label">
                                    <i class="fas fa-search"></i> Search
                                </label>
                                <input type="text" 
                                       id="search" 
                                       name="search" 
                                       class="filter-input" 
                                       value="<?php echo e($search_query); ?>"
                                       placeholder="Search posts...">
                            </div>
                            
                            <!-- Status Filter -->
                            <div class="filter-group">
                                <label for="status" class="filter-label">
                                    <i class="fas fa-filter"></i> Status
                                </label>
                                <select id="status" name="status" class="filter-select">
                                    <option value="all" <?php echo ($status_filter === 'all' || !$status_filter) ? 'selected' : ''; ?>>All Status</option>
                                    <option value="published" <?php echo ($status_filter === 'published') ? 'selected' : ''; ?>>Published</option>
                                    <option value="draft" <?php echo ($status_filter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="archived" <?php echo ($status_filter === 'archived') ? 'selected' : ''; ?>>Archived</option>
                                </select>
                            </div>
                            
                            <!-- Category Filter -->
                            <div class="filter-group">
                                <label for="category" class="filter-label">
                                    <i class="fas fa-tag"></i> Category
                                </label>
                                <select id="category" name="category" class="filter-select">
                                    <option value="all" <?php echo ($category_filter === 'all' || !$category_filter) ? 'selected' : ''; ?>>All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['blog_category_id']; ?>" 
                                                <?php echo ($category_filter == $category['blog_category_id']) ? 'selected' : ''; ?>>
                                            <?php echo e($category['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Author Filter (only for admins) -->
                            <?php if (in_array($user['role'], ['super_admin', 'admin', 'content_manager'])): ?>
                                <div class="filter-group">
                                    <label for="author" class="filter-label">
                                        <i class="fas fa-user"></i> Author
                                    </label>
                                    <select id="author" name="author" class="filter-select">
                                        <option value="all" <?php echo ($author_filter === 'all' || !$author_filter) ? 'selected' : ''; ?>>All Authors</option>
                                        <?php foreach ($authors as $author): ?>
                                            <option value="<?php echo $author['user_id']; ?>" 
                                                    <?php echo ($author_filter == $author['user_id']) ? 'selected' : ''; ?>>
                                                <?php echo e($author['author_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Filter Actions -->
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="<?php echo url('/admin/modules/blog/'); ?>" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Posts Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        All Posts (<?php echo $total_items; ?>)
                    </h3>
                    
                    <!-- Bulk Actions -->
                    <form method="POST" action="" class="bulk-actions-form">
                        <div class="bulk-actions">
                            <select name="bulk_action" class="bulk-action-select">
                                <option value="">Bulk Actions</option>
                                <option value="publish">Publish</option>
                                <option value="draft">Move to Draft</option>
                                <option value="archive">Archive</option>
                                <option value="feature">Feature</option>
                                <option value="unfeature">Remove Featured</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button type="submit" class="btn btn-outline" name="apply_bulk_action">
                                Apply
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($posts)): ?>
                        <div class="table-responsive">
                            <table class="table posts-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="select-all" class="select-all-checkbox">
                                        </th>
                                        <th class="title-cell">Post Title</th>
                                        <th class="author-cell">Author</th>
                                        <th class="category-cell">Category</th>
                                        <th class="status-cell">Status</th>
                                        <th class="date-cell">Date</th>
                                        <th class="stats-cell">Stats</th>
                                        <th class="actions-cell">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($posts as $post): ?>
                                        <tr class="post-row" data-post-id="<?php echo $post['post_id']; ?>">
                                            <td class="checkbox-cell">
                                                <input type="checkbox" 
                                                       name="selected_posts[]" 
                                                       value="<?php echo $post['post_id']; ?>"
                                                       class="post-checkbox">
                                            </td>
                                            <td class="title-cell">
                                                <div class="post-title-info">
                                                    <h4 class="post-title">
                                                        <a href="<?php echo url('/admin/modules/blog/edit.php?id=' . $post['post_id']); ?>">
                                                            <?php echo e($post['title']); ?>
                                                        </a>
                                                        <?php if ($post['is_featured']): ?>
                                                            <span class="featured-badge">
                                                                <i class="fas fa-star"></i> Featured
                                                            </span>
                                                        <?php endif; ?>
                                                    </h4>
                                                    <p class="post-excerpt">
                                                        <?php echo e(substr($post['excerpt'], 0, 100)); ?>...
                                                    </p>
                                                    <div class="post-tags">
                                                        <span class="tag-count">
                                                            <i class="fas fa-tags"></i> <?php echo $post['tag_count']; ?> tags
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="author-cell">
                                                <div class="author-info">
                                                    <?php if ($post['author_image']): ?>
                                                        <img src="<?php echo url($post['author_image']); ?>" 
                                                             alt="<?php echo e($post['author_name']); ?>"
                                                             class="author-avatar">
                                                    <?php else: ?>
                                                        <div class="author-avatar placeholder">
                                                            <?php echo strtoupper(substr($post['author_name'], 0, 2)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span class="author-name"><?php echo e($post['author_name']); ?></span>
                                                </div>
                                            </td>
                                            <td class="category-cell">
                                                <span class="category-badge">
                                                    <?php echo e($post['category_name'] ?: 'Uncategorized'); ?>
                                                </span>
                                            </td>
                                            <td class="status-cell">
                                                <span class="status-badge status-<?php echo strtolower($post['status']); ?>">
                                                    <?php echo ucfirst($post['status']); ?>
                                                </span>
                                            </td>
                                            <td class="date-cell">
                                                <div class="date-info">
                                                    <div class="created-date">
                                                        <?php echo formatDate($post['created_at'], 'M d, Y'); ?>
                                                    </div>
                                                    <?php if ($post['published_at']): ?>
                                                        <div class="published-date">
                                                            Published: <?php echo formatDate($post['published_at'], 'M d'); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="stats-cell">
                                                <div class="post-stats">
                                                    <span class="views-stat">
                                                        <i class="fas fa-eye"></i> <?php echo number_format($post['views_count']); ?>
                                                    </span>
                                                    <span class="comments-stat">
                                                        <i class="fas fa-comment"></i> <?php echo $post['comment_count']; ?>
                                                    </span>
                                                    <span class="shares-stat">
                                                        <i class="fas fa-share"></i> <?php echo $post['share_count']; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('/admin/modules/blog/edit.php?id=' . $post['post_id']); ?>" 
                                                       class="btn-action btn-edit"
                                                       data-tooltip="Edit Post">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('/?page=blog&slug=' . $post['slug']); ?>" 
                                                       target="_blank"
                                                       class="btn-action btn-view"
                                                       data-tooltip="View Post">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                    
                                                    <?php if ($post['status'] === 'draft'): ?>
                                                        <form method="POST" action="" style="display: inline;">
                                                            <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                                            <button type="submit" 
                                                                    name="publish_post" 
                                                                    class="btn-action btn-publish"
                                                                    data-tooltip="Publish Now">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-feature <?php echo $post['is_featured'] ? 'featured' : ''; ?>"
                                                            data-post-id="<?php echo $post['post_id']; ?>"
                                                            data-featured="<?php echo $post['is_featured']; ?>"
                                                            data-tooltip="<?php echo $post['is_featured'] ? 'Unfeature' : 'Feature'; ?>">
                                                        <i class="fas fa-star"></i>
                                                    </button>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-delete"
                                                            data-post-id="<?php echo $post['post_id']; ?>"
                                                            data-post-title="<?php echo e($post['title']); ?>"
                                                            data-tooltip="Delete Post">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <div class="pagination-info">
                                    Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $per_page, $total_items); ?> of <?php echo $total_items; ?> posts
                                </div>
                                
                                <div class="pagination-links">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" 
                                           class="pagination-link first">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                           class="pagination-link prev">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): 
                                    ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                           class="pagination-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                           class="pagination-link next">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" 
                                           class="pagination-link last">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-newspaper"></i>
                            </div>
                            <h3>No Blog Posts Found</h3>
                            <p>No posts match your search criteria. Try adjusting your filters or create a new post.</p>
                            <a href="<?php echo url('/admin/modules/blog/add.php'); ?>" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Write Your First Post
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
                <h3 class="modal-title">Delete Post</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the post "<span id="postToDelete"></span>"?</p>
                <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="post_id" id="deletePostId">
                    <input type="hidden" name="delete_post" value="1">
                    <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Post
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('/assets/js/admin.js'); ?>"></script>
    <script src="<?php echo url('/assets/js/blog.js'); ?>"></script>
    <script>
        // Blog specific JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            const blog = new BlogManager();
            blog.init();
        });
    </script>
</body>
</html>