<?php
/**
 * Blog Comments Management
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
    $session->setFlash('error', 'Access denied. You need appropriate permissions.');
    redirect(url('/'));
}

// Handle comment actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $comment_id = (int)$_GET['id'];
    
    try {
        if ($action === 'approve') {
            $stmt = $db->prepare("UPDATE blog_comments SET is_approved = 1 WHERE comment_id = ?");
            $stmt->execute([$comment_id]);
            $session->setFlash('success', 'Comment approved successfully.');
        } elseif ($action === 'unapprove') {
            $stmt = $db->prepare("UPDATE blog_comments SET is_approved = 0 WHERE comment_id = ?");
            $stmt->execute([$comment_id]);
            $session->setFlash('success', 'Comment unapproved successfully.');
        } elseif ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM blog_comments WHERE comment_id = ?");
            $stmt->execute([$comment_id]);
            $session->setFlash('success', 'Comment deleted successfully.');
        } elseif ($action === 'spam') {
            $stmt = $db->prepare("UPDATE blog_comments SET is_spam = 1, is_approved = 0 WHERE comment_id = ?");
            $stmt->execute([$comment_id]);
            $session->setFlash('success', 'Comment marked as spam.');
        }
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error processing request: ' . $e->getMessage());
    }
    redirect(url('/admin/modules/blog/comments.php'));
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_comments'])) {
    $action = $_POST['bulk_action'];
    $selected_comments = $_POST['selected_comments'];
    
    if (!empty($selected_comments)) {
        try {
            $placeholders = implode(',', array_fill(0, count($selected_comments), '?'));
            
            switch ($action) {
                case 'approve':
                    $stmt = $db->prepare("UPDATE blog_comments SET is_approved = 1 WHERE comment_id IN ($placeholders)");
                    $stmt->execute($selected_comments);
                    $session->setFlash('success', 'Selected comments approved successfully.');
                    break;
                    
                case 'unapprove':
                    $stmt = $db->prepare("UPDATE blog_comments SET is_approved = 0 WHERE comment_id IN ($placeholders)");
                    $stmt->execute($selected_comments);
                    $session->setFlash('success', 'Selected comments unapproved successfully.');
                    break;
                    
                case 'delete':
                    $stmt = $db->prepare("DELETE FROM blog_comments WHERE comment_id IN ($placeholders)");
                    $stmt->execute($selected_comments);
                    $session->setFlash('success', 'Selected comments deleted successfully.');
                    break;
                    
                case 'spam':
                    $stmt = $db->prepare("UPDATE blog_comments SET is_spam = 1, is_approved = 0 WHERE comment_id IN ($placeholders)");
                    $stmt->execute($selected_comments);
                    $session->setFlash('success', 'Selected comments marked as spam.');
                    break;
            }
        } catch (PDOException $e) {
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }
    redirect(url('/admin/modules/blog/comments.php'));
}

// Pagination and filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? 'all';
$post_filter = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
$search_query = $_GET['search'] ?? '';

// Build query
$where_clauses = [];
$params = [];

if ($status_filter === 'pending') {
    $where_clauses[] = "c.is_approved = 0 AND c.is_spam = 0";
} elseif ($status_filter === 'approved') {
    $where_clauses[] = "c.is_approved = 1";
} elseif ($status_filter === 'spam') {
    $where_clauses[] = "c.is_spam = 1";
}

if ($post_filter > 0) {
    $where_clauses[] = "c.post_id = ?";
    $params[] = $post_filter;
}

if ($search_query) {
    $where_clauses[] = "(c.name LIKE ? OR c.email LIKE ? OR c.comment LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM blog_comments c
    $where_sql
";

$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_items = $stmt->fetch()['total'];
$total_pages = ceil($total_items / $per_page);

// Get comments with pagination
$comments_sql = "
    SELECT c.*, bp.title as post_title, bp.slug as post_slug
    FROM blog_comments c
    LEFT JOIN blog_posts bp ON c.post_id = bp.post_id
    $where_sql
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($comments_sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

// Get posts for filter
$stmt = $db->query("SELECT post_id, title FROM blog_posts ORDER BY created_at DESC LIMIT 50");
$posts = $stmt->fetchAll();

// Get stats
$stats_sql = "
    SELECT 
        COUNT(*) as total_comments,
        SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved_comments,
        SUM(CASE WHEN is_approved = 0 AND is_spam = 0 THEN 1 ELSE 0 END) as pending_comments,
        SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_comments
    FROM blog_comments
";

$stmt = $db->query($stats_sql);
$stats = $stmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog Comments | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .comments-stats {
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
        }
        
        .stat-icon.total {
            background: rgba(0, 0, 0, 0.1);
            color: var(--color-black);
        }
        
        .stat-icon.approved {
            background: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .stat-icon.pending {
            background: rgba(255, 152, 0, 0.1);
            color: var(--color-warning);
        }
        
        .stat-icon.spam {
            background: rgba(244, 67, 54, 0.1);
            color: var(--color-error);
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
        
        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-md);
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-label {
            display: block;
            margin-bottom: var(--space-xs);
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--color-gray-700);
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
        }
        
        .comments-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .comments-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--color-gray-700);
            border-bottom: 2px solid var(--color-gray-200);
        }
        
        .comments-table td {
            padding: 16px;
            font-size: 0.875rem;
            border-bottom: 1px solid var(--color-gray-200);
            vertical-align: top;
        }
        
        .comments-table tbody tr:hover {
            background-color: var(--color-gray-50);
        }
        
        .commenter-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .commenter-name {
            font-weight: 600;
            color: var(--color-black);
        }
        
        .commenter-email {
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .commenter-email a {
            color: var(--color-gray-500);
            text-decoration: none;
        }
        
        .commenter-email a:hover {
            color: var(--color-black);
            text-decoration: underline;
        }
        
        .comment-content {
            max-width: 400px;
        }
        
        .comment-text {
            margin: 0 0 8px;
            line-height: 1.6;
            color: var(--color-gray-800);
        }
        
        .comment-meta {
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .comment-meta i {
            margin-right: 4px;
        }
        
        .post-link {
            color: var(--color-gray-600);
            text-decoration: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .post-link:hover {
            color: var(--color-black);
            text-decoration: underline;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .status-approved {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success-dark);
        }
        
        .status-pending {
            background-color: rgba(255, 152, 0, 0.1);
            color: var(--color-warning-dark);
        }
        
        .status-spam {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--color-error-dark);
        }
        
        .action-buttons {
            display: flex;
            gap: var(--space-xs);
            flex-wrap: wrap;
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
        
        .btn-approve:hover {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .btn-unapprove:hover {
            background-color: rgba(255, 152, 0, 0.1);
            color: var(--color-warning);
        }
        
        .btn-spam:hover {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--color-error);
        }
        
        .btn-delete:hover {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--color-error);
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
        }
        
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: var(--space-xl);
        }
        
        .empty-state {
            text-align: center;
            padding: var(--space-3xl) var(--space-xl);
        }
        
        .alert {
            display: flex;
            align-items: flex-start;
            gap: var(--space-md);
            padding: var(--space-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-md);
            border: 1px solid transparent;
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
        
        @media (max-width: 1200px) {
            .comments-table {
                display: block;
            }
            
            .comments-table thead {
                display: none;
            }
            
            .comments-table tbody,
            .comments-table tr,
            .comments-table td {
                display: block;
                width: 100%;
            }
            
            .comments-table tr {
                margin-bottom: var(--space-md);
                border: 1px solid var(--color-gray-200);
                border-radius: var(--radius-lg);
                padding: var(--space-md);
            }
            
            .comments-table td {
                padding: var(--space-sm) 0;
                border: none;
            }
            
            .comments-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--color-gray-700);
                display: inline-block;
                width: 100px;
                margin-right: var(--space-md);
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
                    <i class="fas fa-comments"></i>
                    Blog Comments
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

            <!-- Comments Stats -->
            <div class="comments-stats">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-comments"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['total_comments']; ?></h3>
                        <p>Total Comments</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon approved">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['approved_comments']; ?></h3>
                        <p>Approved</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon pending">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['pending_comments']; ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon spam">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['spam_comments']; ?></h3>
                        <p>Spam</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card filters-card">
                <div class="card-body">
                    <form method="GET" action="" class="filters-form">
                        <div class="filter-group">
                            <label for="status" class="filter-label">Status</label>
                            <select id="status" name="status" class="filter-select">
                                <option value="all" <?php echo ($status_filter === 'all') ? 'selected' : ''; ?>>All Comments</option>
                                <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo ($status_filter === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                <option value="spam" <?php echo ($status_filter === 'spam') ? 'selected' : ''; ?>>Spam</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="post_id" class="filter-label">Post</label>
                            <select id="post_id" name="post_id" class="filter-select">
                                <option value="0">All Posts</option>
                                <?php foreach ($posts as $post): ?>
                                    <option value="<?php echo $post['post_id']; ?>" 
                                            <?php echo ($post_filter == $post['post_id']) ? 'selected' : ''; ?>>
                                        <?php echo e(substr($post['title'], 0, 50)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="search" class="filter-label">Search</label>
                            <input type="text" 
                                   id="search" 
                                   name="search" 
                                   class="filter-input" 
                                   value="<?php echo e($search_query); ?>"
                                   placeholder="Search by name, email, or comment...">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="<?php echo url('/admin/modules/blog/comments.php'); ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Comments Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        Comments (<?php echo $total_items; ?>)
                    </h3>
                    
                    <!-- Bulk Actions -->
                    <form method="POST" action="" class="bulk-actions" id="bulkForm">
                        <select name="bulk_action" class="bulk-action-select">
                            <option value="">Bulk Actions</option>
                            <option value="approve">Approve</option>
                            <option value="unapprove">Unapprove</option>
                            <option value="spam">Mark as Spam</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-outline btn-sm" onclick="return confirmBulkAction()">
                            Apply
                        </button>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($comments)): ?>
                        <div class="table-responsive">
                            <table class="comments-table">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="select-all">
                                        </th>
                                        <th>Commenter</th>
                                        <th>Comment</th>
                                        <th>Post</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($comments as $comment): ?>
                                        <tr>
                                            <td data-label="Select">
                                                <input type="checkbox" 
                                                       name="selected_comments[]" 
                                                       value="<?php echo $comment['comment_id']; ?>"
                                                       class="comment-checkbox"
                                                       form="bulkForm">
                                            </td>
                                            <td data-label="Commenter">
                                                <div class="commenter-info">
                                                    <span class="commenter-name"><?php echo e($comment['name']); ?></span>
                                                    <span class="commenter-email">
                                                        <a href="mailto:<?php echo e($comment['email']); ?>">
                                                            <?php echo e($comment['email']); ?>
                                                        </a>
                                                    </span>
                                                    <?php if ($comment['website']): ?>
                                                        <a href="<?php echo e($comment['website']); ?>" target="_blank" class="commenter-email">
                                                            <i class="fas fa-external-link-alt"></i> Website
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="Comment">
                                                <div class="comment-content">
                                                    <p class="comment-text"><?php echo nl2br(e($comment['comment'])); ?></p>
                                                    <div class="comment-meta">
                                                        <span><i class="fas fa-ip"></i> IP: <?php echo e($comment['ip_address']); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Post">
                                                <a href="<?php echo url('/?page=blog&slug=' . $comment['post_slug']); ?>" 
                                                   target="_blank"
                                                   class="post-link">
                                                    <i class="fas fa-external-link-alt"></i>
                                                    <?php echo e(substr($comment['post_title'], 0, 30)); ?>...
                                                </a>
                                            </td>
                                            <td data-label="Status">
                                                <?php if ($comment['is_spam']): ?>
                                                    <span class="status-badge status-spam">Spam</span>
                                                <?php elseif ($comment['is_approved']): ?>
                                                    <span class="status-badge status-approved">Approved</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-pending">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Date">
                                                <div class="comment-meta">
                                                    <?php echo formatDate($comment['created_at'], 'M d, Y'); ?>
                                                    <br>
                                                    <small><?php echo formatDate($comment['created_at'], 'h:i A'); ?></small>
                                                </div>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="action-buttons">
                                                    <?php if (!$comment['is_approved'] && !$comment['is_spam']): ?>
                                                        <a href="?action=approve&id=<?php echo $comment['comment_id']; ?>" 
                                                           class="btn-action btn-approve"
                                                           title="Approve">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                    <?php elseif ($comment['is_approved']): ?>
                                                        <a href="?action=unapprove&id=<?php echo $comment['comment_id']; ?>" 
                                                           class="btn-action btn-unapprove"
                                                           title="Unapprove">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!$comment['is_spam']): ?>
                                                        <a href="?action=spam&id=<?php echo $comment['comment_id']; ?>" 
                                                           class="btn-action btn-spam"
                                                           title="Mark as Spam"
                                                           onclick="return confirm('Mark this comment as spam?')">
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="?action=delete&id=<?php echo $comment['comment_id']; ?>" 
                                                       class="btn-action btn-delete"
                                                       title="Delete"
                                                       onclick="return confirm('Delete this comment? This cannot be undone.')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
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
                                    Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $per_page, $total_items); ?> of <?php echo $total_items; ?> comments
                                </div>
                                
                                <div class="pagination-links">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" 
                                           class="pagination-link">&laquo;</a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                           class="pagination-link">&lsaquo;</a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                           class="pagination-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                           class="pagination-link">&rsaquo;</a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" 
                                           class="pagination-link">&raquo;</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-comments"></i>
                            </div>
                            <h3>No Comments Found</h3>
                            <p>No comments match your search criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('/assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Select all checkbox
            const selectAll = document.getElementById('select-all');
            const commentCheckboxes = document.querySelectorAll('.comment-checkbox');
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    commentCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
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
        });
        
        // Confirm bulk action
        function confirmBulkAction() {
            const checkboxes = document.querySelectorAll('.comment-checkbox:checked');
            const action = document.querySelector('.bulk-action-select').value;
            
            if (checkboxes.length === 0) {
                alert('Please select at least one comment.');
                return false;
            }
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            if (action === 'delete') {
                return confirm('Are you sure you want to delete the selected comments? This cannot be undone.');
            }
            
            return true;
        }
    </script>
</body>
</html>