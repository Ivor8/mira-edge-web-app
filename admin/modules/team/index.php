<?php
/**
 * Team Management - All Members
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

// Handle single user activation/deactivation
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $target_id = (int)$_GET['id'];
    
    if ($target_id == $user_id) {
        $session->setFlash('error', 'You cannot modify your own account.');
    } else {
        try {
            if ($action === 'activate') {
                $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE user_id = ?");
                $stmt->execute([$target_id]);
                $session->setFlash('success', 'User activated successfully.');
            } elseif ($action === 'deactivate') {
                $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE user_id = ?");
                $stmt->execute([$target_id]);
                $session->setFlash('success', 'User deactivated successfully.');
            }
        } catch (PDOException $e) {
            $session->setFlash('error', 'Error updating user status.');
        }
    }
    redirect(url('/admin/modules/team/index.php'));
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_users = $_POST['selected_users'] ?? [];
    
    if (!empty($selected_users) && $action) {
        // Remove current user from selection if present
        $selected_users = array_diff($selected_users, [$user_id]);
        
        if (!empty($selected_users)) {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
                
                if ($action === 'activate') {
                    $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE user_id IN ($placeholders)");
                    $stmt->execute($selected_users);
                    $session->setFlash('success', 'Selected users activated successfully.');
                } elseif ($action === 'deactivate') {
                    $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE user_id IN ($placeholders)");
                    $stmt->execute($selected_users);
                    $session->setFlash('success', 'Selected users deactivated successfully.');
                }
            } catch (PDOException $e) {
                $session->setFlash('error', 'Error performing bulk action.');
            }
        }
    }
    
    redirect(url('/admin/modules/team/index.php'));
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? '';
$role_filter = $_GET['role'] ?? '';
$team_filter = $_GET['team'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build query
$where_clauses = [];
$params = [];

if ($status_filter && $status_filter !== 'all') {
    $where_clauses[] = "u.status = ?";
    $params[] = $status_filter;
}

if ($role_filter && $role_filter !== 'all') {
    $where_clauses[] = "u.role = ?";
    $params[] = $role_filter;
}

if ($team_filter && $team_filter !== 'all') {
    $where_clauses[] = "EXISTS (SELECT 1 FROM user_teams ut WHERE ut.user_id = u.user_id AND ut.team_id = ? AND ut.is_active = 1)";
    $params[] = $team_filter;
}

if ($search_query) {
    $where_clauses[] = "(u.username LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "
    SELECT COUNT(DISTINCT u.user_id) as total 
    FROM users u
    $where_sql
";

$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_items = $stmt->fetch()['total'];
$total_pages = ceil($total_items / $per_page);

// Get users with pagination
$users_sql = "
    SELECT 
        u.*,
        GROUP_CONCAT(DISTINCT t.team_name ORDER BY t.team_name SEPARATOR ', ') as team_names,
        GROUP_CONCAT(DISTINCT t.team_id) as team_ids
    FROM users u
    LEFT JOIN user_teams ut ON u.user_id = ut.user_id AND ut.is_active = 1
    LEFT JOIN teams t ON ut.team_id = t.team_id
    $where_sql
    GROUP BY u.user_id
    ORDER BY 
        CASE u.role 
            WHEN 'super_admin' THEN 1
            WHEN 'admin' THEN 2
            WHEN 'team_leader' THEN 3
            WHEN 'developer' THEN 4
            ELSE 5
        END,
        u.last_name, u.first_name
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($users_sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get teams for filter
$stmt = $db->query("SELECT team_id, team_name FROM teams WHERE status = 'active' ORDER BY team_name");
$teams = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Management | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        
        .filter-label i {
            color: var(--color-gray-500);
            margin-right: var(--space-xs);
        }
        
        .filter-input,
        .filter-select {
            width: 100%;
            padding: 10px 12px;
            font-size: 0.875rem;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            background-color: var(--color-white);
            color: var(--color-gray-800);
            transition: all var(--transition-fast);
        }
        
        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--color-black);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: var(--space-sm);
            align-items: center;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .team-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .team-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--color-gray-700);
            border-bottom: 2px solid var(--color-gray-200);
            white-space: nowrap;
        }
        
        .team-table td {
            padding: 12px 16px;
            font-size: 0.875rem;
            color: var(--color-gray-800);
            border-bottom: 1px solid var(--color-gray-200);
            vertical-align: middle;
        }
        
        .team-table tbody tr:hover {
            background-color: var(--color-gray-50);
        }
        
        .checkbox-cell {
            width: 40px;
            text-align: center;
        }
        
        .avatar-cell {
            width: 60px;
        }
        
        .user-avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--color-gray-100);
        }
        
        .user-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-black), var(--color-gray-700));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }
        
        .user-info h4 {
            margin: 0 0 4px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .user-info h4 a {
            color: var(--color-black);
            text-decoration: none;
        }
        
        .user-info h4 a:hover {
            text-decoration: underline;
        }
        
        .user-username {
            margin: 0;
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
            white-space: nowrap;
        }
        
        .role-super_admin {
            background: linear-gradient(135deg, #000 0%, #444 100%);
            color: white;
        }
        
        .role-admin {
            background: #333;
            color: white;
        }
        
        .role-team_leader {
            background: #555;
            color: white;
        }
        
        .role-developer {
            background: #777;
            color: white;
        }
        
        .role-content_manager {
            background: #999;
            color: white;
        }
        
        .team-badge {
            display: inline-block;
            padding: 4px 10px;
            background: var(--color-gray-100);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            margin: 2px;
            color: var(--color-gray-700);
            border: 1px solid var(--color-gray-200);
            white-space: nowrap;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active {
            color: var(--color-success);
        }
        
        .status-inactive {
            color: var(--color-warning);
        }
        
        .status-on_leave {
            color: var(--color-info);
        }
        
        .email-link {
            color: var(--color-gray-600);
            text-decoration: none;
        }
        
        .email-link:hover {
            color: var(--color-black);
            text-decoration: underline;
        }
        
        .last-login-info {
            display: flex;
            flex-direction: column;
            font-size: 0.75rem;
        }
        
        .last-login-info small {
            color: var(--color-gray-500);
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
        
        .btn-team:hover {
            background-color: rgba(156, 39, 176, 0.1);
            color: #9c27b0;
        }
        
        .btn-activate:hover {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .btn-deactivate:hover {
            background-color: rgba(255, 152, 0, 0.1);
            color: var(--color-warning);
        }
        
        .btn-reset-password:hover {
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
            background-color: var(--color-white);
            color: var(--color-gray-800);
        }
        
        .bulk-action-select:focus {
            outline: none;
            border-color: var(--color-black);
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
            padding: 0 var(--space-sm);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            color: var(--color-gray-700);
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
        
        .pagination-link.first,
        .pagination-link.prev,
        .pagination-link.next,
        .pagination-link.last {
            font-size: 0.75rem;
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
        
        .empty-state h3 {
            margin-bottom: var(--space-md);
            color: var(--color-black);
        }
        
        .empty-state p {
            max-width: 400px;
            margin: 0 auto var(--space-xl);
            color: var(--color-gray-600);
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
        
        .alert-info {
            background-color: rgba(33, 150, 243, 0.1);
            border-color: rgba(33, 150, 243, 0.3);
            color: var(--color-info-dark);
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
        
        @media (max-width: 1200px) {
            .team-table {
                display: block;
            }
            
            .team-table thead {
                display: none;
            }
            
            .team-table tbody,
            .team-table tr,
            .team-table td {
                display: block;
                width: 100%;
            }
            
            .team-table tr {
                margin-bottom: var(--space-md);
                border: 1px solid var(--color-gray-200);
                border-radius: var(--radius-lg);
                padding: var(--space-md);
                background-color: var(--color-white);
                box-shadow: var(--shadow-md);
            }
            
            .team-table td {
                padding: var(--space-sm) 0;
                border: none;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .team-table td::before {
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
                    <i class="fas fa-users"></i>
                    Team Management
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/team/add.php'); ?>" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Add New Member
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

            <!-- Filters & Search -->
            <div class="card filters-card">
                <div class="card-body">
                    <form method="GET" action="" class="filters-form">
                        <div class="filter-group">
                            <label for="search" class="filter-label">
                                <i class="fas fa-search"></i> Search
                            </label>
                            <input type="text" 
                                   id="search" 
                                   name="search" 
                                   class="filter-input" 
                                   value="<?php echo e($search_query); ?>"
                                   placeholder="Search team members...">
                        </div>
                        
                        <div class="filter-group">
                            <label for="status" class="filter-label">
                                <i class="fas fa-user-check"></i> Status
                            </label>
                            <select id="status" name="status" class="filter-select">
                                <option value="all" <?php echo ($status_filter === 'all' || !$status_filter) ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="on_leave" <?php echo ($status_filter === 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="role" class="filter-label">
                                <i class="fas fa-user-tag"></i> Role
                            </label>
                            <select id="role" name="role" class="filter-select">
                                <option value="all" <?php echo ($role_filter === 'all' || !$role_filter) ? 'selected' : ''; ?>>All Roles</option>
                                <option value="super_admin" <?php echo ($role_filter === 'super_admin') ? 'selected' : ''; ?>>Super Admin</option>
                                <option value="admin" <?php echo ($role_filter === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                <option value="team_leader" <?php echo ($role_filter === 'team_leader') ? 'selected' : ''; ?>>Team Leader</option>
                                <option value="developer" <?php echo ($role_filter === 'developer') ? 'selected' : ''; ?>>Developer</option>
                                <option value="content_manager" <?php echo ($role_filter === 'content_manager') ? 'selected' : ''; ?>>Content Manager</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="team" class="filter-label">
                                <i class="fas fa-users"></i> Team
                            </label>
                            <select id="team" name="team" class="filter-select">
                                <option value="all" <?php echo ($team_filter === 'all' || !$team_filter) ? 'selected' : ''; ?>>All Teams</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team['team_id']; ?>" 
                                            <?php echo ($team_filter == $team['team_id']) ? 'selected' : ''; ?>>
                                        <?php echo e($team['team_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="<?php echo url('/admin/modules/team/index.php'); ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Team Members Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        Team Members (<?php echo $total_items; ?>)
                    </h3>
                    
                    <!-- Bulk Actions -->
                    <form method="POST" action="" class="bulk-actions-form" onsubmit="return confirmBulkAction()">
                        <select name="bulk_action" class="bulk-action-select">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                        </select>
                        <button type="submit" class="btn btn-outline">Apply</button>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($users)): ?>
                        <div class="table-responsive">
                            <table class="team-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="select-all">
                                        </th>
                                        <th class="avatar-cell">Avatar</th>
                                        <th>Name</th>
                                        <th>Role</th>
                                        <th>Team(s)</th>
                                        <th>Status</th>
                                        <th>Email</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $member): ?>
                                        <tr>
                                            <td class="checkbox-cell" data-label="Select">
                                                <input type="checkbox" 
                                                       name="selected_users[]" 
                                                       value="<?php echo $member['user_id']; ?>"
                                                       class="user-checkbox"
                                                       form="bulk-form"
                                                       <?php echo ($member['user_id'] == $user_id) ? 'disabled' : ''; ?>>
                                            </td>
                                            <td class="avatar-cell" data-label="Avatar">
                                                <div class="user-avatar-small">
                                                    <?php if (!empty($member['profile_image'])): ?>
                                                        <img src="<?php echo e($member['profile_image']); ?>" 
                                                             alt="<?php echo e($member['first_name']); ?>">
                                                    <?php else: ?>
                                                        <div class="avatar-placeholder">
                                                            <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="Name">
                                                <div class="user-info">
                                                    <h4>
                                                        <a href="<?php echo url('/admin/modules/team/edit.php?id=' . $member['user_id']); ?>">
                                                            <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>
                                                        </a>
                                                    </h4>
                                                    <p class="user-username">@<?php echo e($member['username']); ?></p>
                                                </div>
                                            </td>
                                            <td data-label="Role">
                                                <span class="role-badge role-<?php echo strtolower($member['role']); ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', $member['role'])); ?>
                                                </span>
                                            </td>
                                            <td data-label="Team(s)">
                                                <?php if (!empty($member['team_names'])): ?>
                                                    <?php 
                                                    $team_names = explode(', ', $member['team_names']);
                                                    foreach ($team_names as $team_name): 
                                                        if (!empty($team_name)):
                                                    ?>
                                                        <span class="team-badge">
                                                            <?php echo e($team_name); ?>
                                                        </span>
                                                    <?php 
                                                        endif;
                                                    endforeach; 
                                                    ?>
                                                <?php else: ?>
                                                    <span class="text-gray-500">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Status">
                                                <span class="status-badge status-<?php echo strtolower($member['status']); ?>">
                                                    <i class="fas fa-circle"></i>
                                                    <?php echo ucfirst($member['status']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Email">
                                                <a href="mailto:<?php echo e($member['email']); ?>" class="email-link">
                                                    <?php echo e($member['email']); ?>
                                                </a>
                                            </td>
                                            <td data-label="Last Login">
                                                <div class="last-login-info">
                                                    <?php if ($member['last_login']): ?>
                                                        <span><?php echo formatDate($member['last_login'], 'M d, Y'); ?></span>
                                                        <small><?php echo formatDate($member['last_login'], 'h:i A'); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-gray-500">Never</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('/admin/modules/team/edit.php?id=' . $member['user_id']); ?>" 
                                                       class="btn-action btn-edit"
                                                       title="Edit Member">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('/admin/modules/team/teams.php?assign=' . $member['user_id']); ?>" 
                                                       class="btn-action btn-team"
                                                       title="Assign to Team">
                                                        <i class="fas fa-user-plus"></i>
                                                    </a>
                                                    
                                                    <?php if ($member['user_id'] != $user_id): ?>
                                                        <?php if ($member['status'] == 'active'): ?>
                                                            <a href="<?php echo url('/admin/modules/team/index.php?action=deactivate&id=' . $member['user_id']); ?>" 
                                                               class="btn-action btn-deactivate"
                                                               title="Deactivate"
                                                               onclick="return confirm('Deactivate <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>?')">
                                                                <i class="fas fa-user-slash"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?php echo url('/admin/modules/team/index.php?action=activate&id=' . $member['user_id']); ?>" 
                                                               class="btn-action btn-activate"
                                                               title="Activate"
                                                               onclick="return confirm('Activate <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>?')">
                                                                <i class="fas fa-user-check"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <a href="<?php echo url('/admin/modules/team/reset-password.php?id=' . $member['user_id']); ?>" 
                                                       class="btn-action btn-reset-password"
                                                       title="Reset Password">
                                                        <i class="fas fa-key"></i>
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
                                    Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $per_page, $total_items); ?> of <?php echo $total_items; ?> members
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
                        
                        <!-- Hidden form for bulk actions -->
                        <form method="POST" action="" id="bulk-form" style="display: none;">
                            <input type="hidden" name="bulk_action" id="bulk_action" value="">
                            <div id="selected-users-container"></div>
                        </form>
                        
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <h3>No Team Members Found</h3>
                            <p>No team members match your search criteria. Try adjusting your filters or add a new member.</p>
                            <a href="<?php echo url('/admin/modules/team/add.php'); ?>" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Add Your First Team Member
                            </a>
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
            const userCheckboxes = document.querySelectorAll('.user-checkbox');
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    userCheckboxes.forEach(checkbox => {
                        if (!checkbox.disabled) {
                            checkbox.checked = this.checked;
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
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            const action = document.querySelector('.bulk-action-select').value;
            
            if (checkboxes.length === 0) {
                alert('Please select at least one user.');
                return false;
            }
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            // Create hidden inputs for selected users
            const container = document.getElementById('selected-users-container');
            container.innerHTML = '';
            
            checkboxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_users[]';
                input.value = checkbox.value;
                container.appendChild(input);
            });
            
            // Set bulk action
            document.getElementById('bulk_action').value = action;
            
            return confirm(`Are you sure you want to ${action} the selected users?`);
        }
    </script>
</body>
</html>