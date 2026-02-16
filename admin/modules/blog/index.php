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

// Handle single post actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $post_id = (int)$_GET['id'];
    
    // Check permission for this post
    if (!in_array($user['role'], ['super_admin', 'admin', 'content_manager'])) {
        $stmt = $db->prepare("SELECT author_id FROM blog_posts WHERE post_id = ?");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();
        
        if (!$post || $post['author_id'] != $user['user_id']) {
            $session->setFlash('error', 'You do not have permission to modify this post.');
            redirect(url('/admin/modules/blog/index.php'));
        }
    }
    
    try {
        if ($action === 'publish') {
            $stmt = $db->prepare("UPDATE blog_posts SET status = 'published', published_at = NOW() WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $session->setFlash('success', 'Post published successfully.');
        } elseif ($action === 'draft') {
            $stmt = $db->prepare("UPDATE blog_posts SET status = 'draft', published_at = NULL WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $session->setFlash('success', 'Post moved to draft.');
        } elseif ($action === 'archive') {
            $stmt = $db->prepare("UPDATE blog_posts SET status = 'archived' WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $session->setFlash('success', 'Post archived.');
        } elseif ($action === 'feature') {
            $stmt = $db->prepare("UPDATE blog_posts SET is_featured = 1 WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $session->setFlash('success', 'Post featured successfully.');
        } elseif ($action === 'unfeature') {
            $stmt = $db->prepare("UPDATE blog_posts SET is_featured = 0 WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $session->setFlash('success', 'Post unfeatured successfully.');
        } elseif ($action === 'delete') {
            // Get featured image to delete later
            $stmt = $db->prepare("SELECT featured_image FROM blog_posts WHERE post_id = ?");
            $stmt->execute([$post_id]);
            $post = $stmt->fetch();
            
            // Delete post tags first
            $stmt = $db->prepare("DELETE FROM blog_post_tags WHERE post_id = ?");
            $stmt->execute([$post_id]);
            
            // Delete post
            $stmt = $db->prepare("DELETE FROM blog_posts WHERE post_id = ?");
            $stmt->execute([$post_id]);
            
            // Delete featured image if exists
            if ($post && $post['featured_image']) {
                $image_path = dirname(dirname(dirname(dirname(__FILE__)))) . $post['featured_image'];
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            $session->setFlash('success', 'Post deleted successfully.');
        }
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error processing request: ' . $e->getMessage());
    }
    redirect(url('/admin/modules/blog/index.php'));
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_posts'])) {
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
                    $stmt = $db->prepare("UPDATE blog_posts SET status = 'draft', published_at = NULL WHERE post_id IN ($placeholders)");
                    $stmt->execute($selected_posts);
                    $session->setFlash('success', 'Selected posts moved to draft.');
                    break;
                    
                case 'archive':
                    $stmt = $db->prepare("UPDATE blog_posts SET status = 'archived' WHERE post_id IN ($placeholders)");
                    $stmt->execute($selected_posts);
                    $session->setFlash('success', 'Selected posts archived.');
                    break;
                    
                case 'delete':
                    // Get featured images first
                    $stmt = $db->prepare("SELECT featured_image FROM blog_posts WHERE post_id IN ($placeholders)");
                    $stmt->execute($selected_posts);
                    $posts = $stmt->fetchAll();
                    
                    // Delete post tags
                    $stmt = $db->prepare("DELETE FROM blog_post_tags WHERE post_id IN ($placeholders)");
                    $stmt->execute($selected_posts);
                    
                    // Delete posts
                    $stmt = $db->prepare("DELETE FROM blog_posts WHERE post_id IN ($placeholders)");
                    $stmt->execute($selected_posts);
                    
                    // Delete featured images
                    foreach ($posts as $post) {
                        if ($post['featured_image']) {
                            $image_path = dirname(dirname(dirname(dirname(__FILE__)))) . $post['featured_image'];
                            if (file_exists($image_path)) {
                                unlink($image_path);
                            }
                        }
                    }
                    
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
    redirect(url('/admin/modules/blog/index.php'));
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

// Get posts with pagination - REMOVED references to blog_comments
$posts_sql = "
    SELECT bp.*, bc.category_name, 
           CONCAT(u.first_name, ' ', u.last_name) as author_name,
           u.profile_image as author_image,
           (SELECT COUNT(*) FROM blog_post_tags bpt WHERE bpt.post_id = bp.post_id) as tag_count
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .blog-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-lg);
            margin-bottom: var(--space-xl);
        }
        
        .stat-card {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-md);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-gray-200);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: var(--color-gray-100);
            color: var(--color-gray-600);
        }
        
        .stat-content h3 {
            margin: 0 0 4px;
            font-size: 1.5rem;
            color: var(--color-black);
        }
        
        .stat-content p {
            margin: 0;
            font-size: 0.875rem;
            color: var(--color-gray-600);
        }
        
        .filters-card {
            margin-bottom: var(--space-xl);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-md);
            align-items: flex-end;
        }
        
        .filter-group {
            margin-bottom: 0;
        }
        
        .filter-label {
            display: block;
            margin-bottom: var(--space-xs);
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--color-gray-700);
        }
        
        .filter-label i {
            color: var(--color-gray-500);
            margin-right: 4px;
        }
        
        .filter-input,
        .filter-select {
            width: 100%;
            padding: 10px 12px;
            font-size: 0.875rem;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            background-color: var(--color-white);
        }
        
        .filter-actions {
            display: flex;
            gap: var(--space-sm);
            align-items: center;
            justify-content: flex-end;
        }
        
        .posts-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .posts-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--color-gray-700);
            border-bottom: 2px solid var(--color-gray-200);
        }
        
        .posts-table td {
            padding: 16px;
            font-size: 0.875rem;
            border-bottom: 1px solid var(--color-gray-200);
            vertical-align: middle;
        }
        
        .posts-table tbody tr:hover {
            background-color: var(--color-gray-50);
        }
        
        .checkbox-cell {
            width: 40px;
            text-align: center;
        }
        
        .post-title-info h4 {
            margin: 0 0 4px;
            font-size: 1rem;
        }
        
        .post-title-info h4 a {
            color: var(--color-black);
            text-decoration: none;
        }
        
        .post-title-info h4 a:hover {
            text-decoration: underline;
        }
        
        .post-excerpt {
            margin: 0 0 8px;
            font-size: 0.75rem;
            color: var(--color-gray-600);
        }
        
        .featured-badge {
            display: inline-block;
            padding: 2px 8px;
            background: rgba(255, 193, 7, 0.1);
            color: #ff9800;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: var(--space-xs);
        }
        
        .featured-badge i {
            margin-right: 2px;
        }
        
        .post-tags {
            font-size: 0.7rem;
            color: var(--color-gray-500);
        }
        
        .tag-count i {
            margin-right: 2px;
        }
        
        .author-info {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .author-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--color-gray-100);
        }
        
        .author-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .author-avatar.placeholder {
            background: linear-gradient(135deg, var(--color-black), var(--color-gray-700));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
        }
        
        .author-name {
            font-size: 0.875rem;
            color: var(--color-gray-700);
        }
        
        .category-badge {
            display: inline-block;
            padding: 4px 8px;
            background: var(--color-gray-100);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            color: var(--color-gray-700);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-published {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success-dark);
        }
        
        .status-draft {
            background-color: rgba(255, 152, 0, 0.1);
            color: var(--color-warning-dark);
        }
        
        .status-archived {
            background-color: rgba(158, 158, 158, 0.1);
            color: var(--color-gray-600);
        }
        
        .date-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .created-date {
            font-size: 0.875rem;
            color: var(--color-gray-800);
        }
        
        .published-date {
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .post-stats {
            display: flex;
            gap: var(--space-md);
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .post-stats span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .action-buttons {
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
        
        .btn-view:hover {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .btn-publish:hover {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .btn-feature:hover {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ff9800;
        }
        
        .btn-feature.featured {
            color: #ff9800;
        }
        
        .btn-delete:hover {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--color-error);
        }
        
        .bulk-actions-form {
            display: flex;
            gap: var(--space-sm);
            align-items: center;
        }
        
        .bulk-action-select {
            padding: 8px 12px;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
        }
        
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: var(--space-xl);
            padding-top: var(--space-lg);
            border-top: 1px solid var(--color-gray-200);
        }
        
        .pagination-info {
            font-size: 0.875rem;
            color: var(--color-gray-600);
        }
        
        .pagination-links {
            display: flex;
            gap: var(--space-xs);
        }
        
        .pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 var(--space-xs);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            color: var(--color-gray-700);
            text-decoration: none;
            transition: all var(--transition-fast);
        }
        
        .pagination-link:hover {
            background-color: var(--color-gray-100);
            border-color: var(--color-gray-400);
        }
        
        .pagination-link.active {
            background-color: var(--color-black);
            border-color: var(--color-black);
            color: var(--color-white);
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
        
        @media (max-width: 1200px) {
            .posts-table {
                display: block;
            }
            
            .posts-table thead {
                display: none;
            }
            
            .posts-table tbody,
            .posts-table tr,
            .posts-table td {
                display: block;
                width: 100%;
            }
            
            .posts-table tr {
                margin-bottom: var(--space-md);
                border: 1px solid var(--color-gray-200);
                border-radius: var(--radius-lg);
                padding: var(--space-md);
                background-color: var(--color-white);
                box-shadow: var(--shadow-md);
            }
            
            .posts-table td {
                padding: var(--space-sm) 0;
                border: none;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .posts-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--color-gray-700);
                font-size: 0.875rem;
                margin-right: var(--space-md);
            }
            
            .action-buttons {
                justify-content: flex-end;
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

            <!-- Blog Stats -->
            <div class="blog-stats">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['total_posts']; ?></h3>
                        <p>Total Posts</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['published_posts']; ?></h3>
                        <p>Published</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['draft_posts']; ?></h3>
                        <p>Drafts</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($stats['total_views']); ?></h3>
                        <p>Total Views</p>
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
                                <a href="<?php echo url('/admin/modules/blog/index.php'); ?>" class="btn btn-outline">
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
                    <form method="POST" action="" class="bulk-actions-form" id="bulkForm">
                        <select name="bulk_action" class="bulk-action-select">
                            <option value="">Bulk Actions</option>
                            <option value="publish">Publish</option>
                            <option value="draft">Move to Draft</option>
                            <option value="archive">Archive</option>
                            <option value="feature">Feature</option>
                            <option value="unfeature">Remove Featured</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-outline" onclick="return confirmBulkAction()">
                            Apply
                        </button>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($posts)): ?>
                        <div class="table-responsive">
                            <table class="posts-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="select-all" class="select-all-checkbox">
                                        </th>
                                        <th>Post Title</th>
                                        <th>Author</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Stats</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($posts as $post): ?>
                                        <tr class="post-row" data-post-id="<?php echo $post['post_id']; ?>">
                                            <td class="checkbox-cell" data-label="Select">
                                                <input type="checkbox" 
                                                       name="selected_posts[]" 
                                                       value="<?php echo $post['post_id']; ?>"
                                                       class="post-checkbox"
                                                       form="bulkForm">
                                            </td>
                                            <td data-label="Post Title">
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
                                            <td data-label="Author">
                                                <div class="author-info">
                                                    <?php if ($post['author_image']): ?>
                                                        <img src="<?php echo url($post['author_image']); ?>" 
                                                             alt="<?php echo e($post['author_name']); ?>"
                                                             class="author-avatar">
                                                    <?php else: ?>
                                                        <div class="author-avatar placeholder">
                                                            <?php 
                                                            $name_parts = explode(' ', $post['author_name'] ?? '');
                                                            $initials = '';
                                                            foreach ($name_parts as $part) {
                                                                if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                                                            }
                                                            echo $initials ?: 'U';
                                                            ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span class="author-name"><?php echo e($post['author_name']); ?></span>
                                                </div>
                                            </td>
                                            <td data-label="Category">
                                                <span class="category-badge">
                                                    <?php echo e($post['category_name'] ?: 'Uncategorized'); ?>
                                                </span>
                                            </td>
                                            <td data-label="Status">
                                                <span class="status-badge status-<?php echo strtolower($post['status']); ?>">
                                                    <?php echo ucfirst($post['status']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Date">
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
                                            <td data-label="Stats">
                                                <div class="post-stats">
                                                    <span>
                                                        <i class="fas fa-eye"></i> <?php echo number_format($post['views_count']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('/admin/modules/blog/edit.php?id=' . $post['post_id']); ?>" 
                                                       class="btn-action btn-edit"
                                                       title="Edit Post">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('/?page=blog&slug=' . $post['slug']); ?>" 
                                                       target="_blank"
                                                       class="btn-action btn-view"
                                                       title="View Post">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                    
                                                    <?php if ($post['status'] === 'draft'): ?>
                                                        <a href="?action=publish&id=<?php echo $post['post_id']; ?>" 
                                                           class="btn-action btn-publish"
                                                           title="Publish Now">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="?action=<?php echo $post['is_featured'] ? 'unfeature' : 'feature'; ?>&id=<?php echo $post['post_id']; ?>" 
                                                       class="btn-action btn-feature <?php echo $post['is_featured'] ? 'featured' : ''; ?>"
                                                       title="<?php echo $post['is_featured'] ? 'Unfeature' : 'Feature'; ?>">
                                                        <i class="fas fa-star"></i>
                                                    </a>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-delete"
                                                            data-post-id="<?php echo $post['post_id']; ?>"
                                                            data-post-title="<?php echo e($post['title']); ?>"
                                                            title="Delete Post">
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
                                           class="pagination-link first" title="First Page">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                           class="pagination-link prev" title="Previous Page">
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
                                           class="pagination-link next" title="Next Page">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" 
                                           class="pagination-link last" title="Last Page">
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
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the post "<strong id="postToDelete"></strong>"?</p>
                <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="modalCancel">Cancel</button>
                <a href="#" id="confirmDelete" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete Post
                </a>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('/assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Select all checkbox
            const selectAll = document.getElementById('select-all');
            const postCheckboxes = document.querySelectorAll('.post-checkbox');
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    postCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
            
            // Delete post buttons
            const deleteButtons = document.querySelectorAll('.btn-delete');
            const modal = document.getElementById('deleteModal');
            const modalClose = document.getElementById('modalClose');
            const modalCancel = document.getElementById('modalCancel');
            const modalBackdrop = document.querySelector('.modal-backdrop');
            const postToDeleteSpan = document.getElementById('postToDelete');
            const confirmDeleteLink = document.getElementById('confirmDelete');
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const postId = this.dataset.postId;
                    const postTitle = this.dataset.postTitle;
                    
                    postToDeleteSpan.textContent = postTitle;
                    confirmDeleteLink.href = '?action=delete&id=' + postId;
                    
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
        
        // Confirm bulk action
        function confirmBulkAction() {
            const checkboxes = document.querySelectorAll('.post-checkbox:checked');
            const action = document.querySelector('.bulk-action-select').value;
            
            if (checkboxes.length === 0) {
                alert('Please select at least one post.');
                return false;
            }
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            if (action === 'delete') {
                return confirm('Are you sure you want to delete the selected posts? This action cannot be undone.');
            }
            
            return true;
        }
    </script>
</body>
</html>