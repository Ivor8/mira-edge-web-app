<?php
/**
 * Service Orders Management
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

// Handle assignment to user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_order'])) {
    $order_id = (int)$_POST['order_id'];
    $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;

    try {
        $stmt = $db->prepare("UPDATE service_orders SET assigned_to = ?, updated_at = NOW() WHERE order_id = ?");
        $stmt->execute([$assigned_to, $order_id]);
        $session->setFlash('success', 'Order assigned successfully!');
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error assigning order: ' . $e->getMessage());
        error_log("Assign Order Error: " . $e->getMessage());
    }

    redirect(url('/admin/modules/services/orders.php'));
}

// Handle status update
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $order_id = (int)$_GET['id'];

    try {
        if ($action === 'pending') {
            $stmt = $db->prepare("UPDATE service_orders SET order_status = 'pending' WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $session->setFlash('success', 'Order status updated to Pending.');
        } elseif ($action === 'in_progress') {
            $stmt = $db->prepare("UPDATE service_orders SET order_status = 'in_progress' WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $session->setFlash('success', 'Order status updated to In Progress.');
        } elseif ($action === 'completed') {
            $stmt = $db->prepare("UPDATE service_orders SET order_status = 'completed' WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $session->setFlash('success', 'Order completed successfully.');
        } elseif ($action === 'cancelled') {
            $stmt = $db->prepare("UPDATE service_orders SET order_status = 'cancelled' WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $session->setFlash('success', 'Order cancelled.');
        } elseif ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM service_orders WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $session->setFlash('success', 'Order deleted successfully.');
        }
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error updating order: ' . $e->getMessage());
        error_log("Update Order Error: " . $e->getMessage());
    }

    redirect(url('/admin/modules/services/orders.php'));
}

// Handle POST delete from modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && !isset($_POST['assign_order'])) {
    $order_id = (int)$_POST['order_id'];

    try {
        $stmt = $db->prepare("DELETE FROM service_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $session->setFlash('success', 'Order deleted successfully.');
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error deleting order: ' . $e->getMessage());
        error_log("Delete Order Error: " . $e->getMessage());
    }

    redirect(url('/admin/modules/services/orders.php'));
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_orders'])) {
    $action = $_POST['bulk_action'];
    $selected_orders = $_POST['selected_orders'];

    if (!empty($selected_orders)) {
        try {
            $placeholders = implode(',', array_fill(0, count($selected_orders), '?'));

            if ($action === 'delete') {
                $stmt = $db->prepare("DELETE FROM service_orders WHERE order_id IN ($placeholders)");
                $stmt->execute($selected_orders);
                $session->setFlash('success', 'Selected orders deleted successfully.');
            } elseif ($action === 'pending') {
                $stmt = $db->prepare("UPDATE service_orders SET order_status = 'pending' WHERE order_id IN ($placeholders)");
                $stmt->execute($selected_orders);
                $session->setFlash('success', 'Selected orders marked as Pending.');
            } elseif ($action === 'in_progress') {
                $stmt = $db->prepare("UPDATE service_orders SET order_status = 'in_progress' WHERE order_id IN ($placeholders)");
                $stmt->execute($selected_orders);
                $session->setFlash('success', 'Selected orders marked as In Progress.');
            } elseif ($action === 'completed') {
                $stmt = $db->prepare("UPDATE service_orders SET order_status = 'completed' WHERE order_id IN ($placeholders)");
                $stmt->execute($selected_orders);
                $session->setFlash('success', 'Selected orders marked as Completed.');
            } elseif ($action === 'cancelled') {
                $stmt = $db->prepare("UPDATE service_orders SET order_status = 'cancelled' WHERE order_id IN ($placeholders)");
                $stmt->execute($selected_orders);
                $session->setFlash('success', 'Selected orders cancelled.');
            }
        } catch (PDOException $e) {
            error_log("Bulk Action Error: " . $e->getMessage());
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }

    redirect(url('/admin/modules/services/orders.php'));
}

// Pagination and filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? '';
$assigned_filter = $_GET['assigned'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build query
$where_clauses = [];
$params = [];

if ($status_filter && $status_filter !== 'all') {
    $where_clauses[] = "so.order_status = ?";
    $params[] = $status_filter;
}

if ($search_query) {
    $where_clauses[] = "(so.client_name LIKE ? OR so.client_email LIKE ? OR so.client_phone LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

if ($assigned_filter === 'assigned') {
    $where_clauses[] = "so.assigned_to IS NOT NULL";
} elseif ($assigned_filter === 'unassigned') {
    $where_clauses[] = "so.assigned_to IS NULL";
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM service_orders so $where_sql";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_items = $stmt->fetch()['total'];
$total_pages = ceil($total_items / $per_page);

// Get orders with user info
$orders_sql = "
    SELECT so.*, 
           s.service_name,
           CONCAT(u.first_name, ' ', u.last_name) as assigned_user_name,
           u.profile_image as user_image
    FROM service_orders so
    LEFT JOIN services s ON so.service_id = s.service_id
    LEFT JOIN users u ON so.assigned_to = u.user_id
    $where_sql
    ORDER BY so.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($orders_sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get stats
$stats_sql = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN order_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_orders,
        SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned_orders
    FROM service_orders
";
$stmt = $db->prepare($stats_sql);
$stmt->execute();
$stats = $stmt->fetch();

// Get users for assignment
$stmt = $db->query("SELECT user_id, CONCAT(first_name, ' ', last_name) as user_name FROM users WHERE role IN ('super_admin', 'admin', 'developer') ORDER BY first_name");
$users = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Orders | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/services.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .orders-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-lg);
            margin-bottom: var(--space-xl);
        }
        
        .stat-card-small {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-md);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-normal);
        }
        
        .stat-card-small:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card-small i {
            font-size: 1.5rem;
            width: 50px;
            height: 50px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-card-small.total i {
            background: rgba(0, 0, 0, 0.1);
            color: var(--color-black);
        }
        
        .stat-card-small.pending i {
            background: rgba(255, 152, 0, 0.1);
            color: var(--color-warning);
        }
        
        .stat-card-small.in-progress i {
            background: rgba(33, 150, 243, 0.1);
            color: var(--color-info);
        }
        
        .stat-card-small.completed i {
            background: rgba(0, 200, 83, 0.1);
            color: var(--color-success);
        }
        
        .stat-card-small.unassigned i {
            background: rgba(158, 158, 158, 0.1);
            color: var(--color-gray-600);
        }
        
        .stat-card-small .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            color: var(--color-black);
        }
        
        .stat-card-small .stat-label {
            font-size: 0.875rem;
            color: var(--color-gray-600);
            margin: 0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-pending {
            background: rgba(255, 152, 0, 0.1);
            color: var(--color-warning-dark);
        }
        
        .status-in_progress {
            background: rgba(33, 150, 243, 0.1);
            color: var(--color-info-dark);
        }
        
        .status-completed {
            background: rgba(0, 200, 83, 0.1);
            color: var(--color-success-dark);
        }
        
        .status-cancelled {
            background: rgba(244, 67, 54, 0.1);
            color: var(--color-error-dark);
        }
        
        .client-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .client-name {
            font-weight: 600;
            color: var(--color-black);
        }
        
        .client-email {
            font-size: 0.75rem;
            color: var(--color-gray-600);
        }
        
        .client-phone {
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .assigned-info {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .assigned-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--color-gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--color-gray-600);
            font-size: 0.75rem;
        }
        
        .assigned-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .assigned-name {
            font-size: 0.875rem;
            color: var(--color-gray-700);
        }
        
        .assigned-badge {
            background: rgba(156, 39, 176, 0.1);
            color: #9c27b0;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .service-name {
            background: var(--color-gray-100);
            color: var(--color-gray-700);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            display: inline-block;
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
        }
        
        .btn-action:hover {
            background-color: var(--color-gray-100);
            color: var(--color-black);
            border-color: var(--color-gray-300);
            transform: translateY(-2px);
        }
        
        .btn-assign:hover {
            background-color: rgba(156, 39, 176, 0.1);
            color: #9c27b0;
        }
        
        .btn-delete:hover {
            background-color: rgba(244, 67, 54, 0.1);
            color: var(--color-error);
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
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            background-color: var(--color-white);
            color: var(--color-gray-800);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--color-black);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .modal-footer {
            display: flex;
            gap: var(--space-md);
            justify-content: flex-end;
            padding-top: var(--space-md);
            border-top: 1px solid var(--color-gray-200);
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
        
        .filter-input,
        .filter-select {
            width: 100%;
            padding: 10px 12px;
            font-size: 0.875rem;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            background-color: var(--color-white);
        }

        @media (max-width: 768px) {
            .orders-stats {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            
            .action-buttons {
                flex-wrap: wrap;
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
                    <i class="fas fa-shopping-cart"></i>
                    Service Orders
                </h1>
                <div class="page-actions">
                    <!-- No direct add button; orders come from frontend -->
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

            <!-- Orders Stats -->
            <div class="orders-stats">
                <div class="stat-card-small total">
                    <i class="fas fa-shopping-cart"></i>
                    <div>
                        <h3 class="stat-value"><?php echo $stats['total_orders']; ?></h3>
                        <p class="stat-label">Total Orders</p>
                    </div>
                </div>
                
                <div class="stat-card-small pending">
                    <i class="fas fa-clock"></i>
                    <div>
                        <h3 class="stat-value"><?php echo $stats['pending_orders']; ?></h3>
                        <p class="stat-label">Pending</p>
                    </div>
                </div>
                
                <div class="stat-card-small in-progress">
                    <i class="fas fa-spinner"></i>
                    <div>
                        <h3 class="stat-value"><?php echo $stats['in_progress_orders']; ?></h3>
                        <p class="stat-label">In Progress</p>
                    </div>
                </div>
                
                <div class="stat-card-small completed">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <h3 class="stat-value"><?php echo $stats['completed_orders']; ?></h3>
                        <p class="stat-label">Completed</p>
                    </div>
                </div>
                
                <div class="stat-card-small unassigned">
                    <i class="fas fa-user-slash"></i>
                    <div>
                        <h3 class="stat-value"><?php echo $stats['unassigned_orders']; ?></h3>
                        <p class="stat-label">Unassigned</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card">
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
                                       placeholder="Search by name, email, or phone...">
                            </div>
                            
                            <!-- Status Filter -->
                            <div class="filter-group">
                                <label for="status" class="filter-label">
                                    <i class="fas fa-filter"></i> Status
                                </label>
                                <select id="status" name="status" class="filter-select">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="in_progress" <?php echo ($status_filter === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo ($status_filter === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo ($status_filter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            
                            <!-- Assignment Filter -->
                            <div class="filter-group">
                                <label for="assigned" class="filter-label">
                                    <i class="fas fa-user"></i> Assignment
                                </label>
                                <select id="assigned" name="assigned" class="filter-select">
                                    <option value="">All Orders</option>
                                    <option value="assigned" <?php echo ($assigned_filter === 'assigned') ? 'selected' : ''; ?>>Assigned</option>
                                    <option value="unassigned" <?php echo ($assigned_filter === 'unassigned') ? 'selected' : ''; ?>>Unassigned</option>
                                </select>
                            </div>
                            
                            <!-- Filter Actions -->
                            <div class="filter-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="<?php echo url('/admin/modules/services/orders.php'); ?>" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        All Orders (<?php echo $total_items; ?>)
                    </h3>
                    
                    <!-- Bulk Actions -->
                    <form method="POST" action="" class="bulk-actions-form" id="bulkOrderForm">
                        <select name="bulk_action" class="bulk-action-select">
                            <option value="">Bulk Actions</option>
                            <option value="pending">Mark Pending</option>
                            <option value="in_progress">Mark In Progress</option>
                            <option value="completed">Mark Completed</option>
                            <option value="cancelled">Cancel</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-outline" onclick="return confirmBulkAction()">
                            Apply
                        </button>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($orders)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th width="40" class="checkbox-cell">
                                            <input type="checkbox" id="select-all">
                                        </th>
                                        <th>Client Info</th>
                                        <th>Service</th>
                                        <th>Status</th>
                                        <th>Assigned To</th>
                                        <th>Date Created</th>
                                        <th width="120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td class="checkbox-cell">
                                                <input type="checkbox" 
                                                       name="selected_orders[]" 
                                                       value="<?php echo $order['order_id']; ?>"
                                                       class="order-checkbox">
                                            </td>
                                            <td>
                                                <div class="client-info">
                                                    <span class="client-name"><?php echo e($order['client_name']); ?></span>
                                                    <span class="client-email"><?php echo e($order['client_email']); ?></span>
                                                    <span class="client-phone"><?php echo e($order['client_phone']); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="service-name">
                                                    <?php echo e($order['service_name'] ?: 'General'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo str_replace(' ', '_', strtolower($order['order_status'])); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $order['order_status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($order['assigned_to']): ?>
                                                    <div class="assigned-info">
                                                        <?php if ($order['user_image']): ?>
                                                            <img src="<?php echo url($order['user_image']); ?>" alt="<?php echo e($order['assigned_user_name']); ?>" class="assigned-avatar">
                                                        <?php else: ?>
                                                            <div class="assigned-avatar">
                                                                <?php 
                                                                $names = explode(' ', $order['assigned_user_name'] ?? '');
                                                                $initials = '';
                                                                foreach ($names as $n) {
                                                                    if (!empty($n)) $initials .= strtoupper(substr($n, 0, 1));
                                                                }
                                                                echo $initials ?: 'U';
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <span class="assigned-name"><?php echo e($order['assigned_user_name']); ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-500">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo formatDate($order['created_at'], 'M d, Y'); ?></small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" 
                                                            class="btn-action btn-assign"
                                                            data-order-id="<?php echo $order['order_id']; ?>"
                                                            data-client-name="<?php echo e($order['client_name']); ?>"
                                                            title="Assign Order">
                                                        <i class="fas fa-user-plus"></i>
                                                    </button>
                                                    
                                                    <a href="?action=pending&id=<?php echo $order['order_id']; ?>" 
                                                       class="btn-action"
                                                       title="Mark Pending">
                                                        <i class="fas fa-clock"></i>
                                                    </a>
                                                    
                                                    <a href="?action=in_progress&id=<?php echo $order['order_id']; ?>" 
                                                       class="btn-action"
                                                       title="Mark In Progress">
                                                        <i class="fas fa-spinner"></i>
                                                    </a>
                                                    
                                                    <a href="?action=completed&id=<?php echo $order['order_id']; ?>" 
                                                       class="btn-action"
                                                       title="Mark Completed">
                                                        <i class="fas fa-check-circle"></i>
                                                    </a>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-delete delete-order-btn"
                                                            data-order-id="<?php echo $order['order_id']; ?>"
                                                            data-client-name="<?php echo e($order['client_name']); ?>"
                                                            title="Delete Order">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <h3>No Orders Found</h3>
                            <p>No service orders match your search criteria. Orders will appear here when clients submit service requests.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Assign Modal -->
    <div class="modal" id="assignModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Assign Order</h3>
                <button class="modal-close" id="assignModalClose">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Assigning order for: <strong id="assignClientName"></strong></p>
                    
                    <div class="form-group">
                        <label for="assigned_to" class="form-label">Assign to User</label>
                        <select name="assigned_to" id="assigned_to" class="form-control">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['user_id']; ?>"><?php echo e($u['user_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="order_id" id="assignOrderId" value="">
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" id="assignModalCancel">Cancel</button>
                    <button type="submit" name="assign_order" class="btn btn-primary">
                        <i class="fas fa-check"></i> Assign
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete Order</h3>
                <button class="modal-close" id="deleteModalClose">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the order for <strong id="deleteClientName"></strong>?</p>
                <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="deleteModalCancel">Cancel</button>
                <form method="POST" action="" style="display:inline;">
                    <input type="hidden" name="order_id" id="deleteOrderId" value="">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Select all checkbox
            const selectAll = document.getElementById('select-all');
            const orderCheckboxes = document.querySelectorAll('.order-checkbox');
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    orderCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
            
            // Assign order modal
            const assignModal = document.getElementById('assignModal');
            const assignModalClose = document.getElementById('assignModalClose');
            const assignModalCancel = document.getElementById('assignModalCancel');
            const assignBackdrop = document.querySelector('#assignModal .modal-backdrop');
            const assignButtons = document.querySelectorAll('.btn-assign');
            const assignOrderId = document.getElementById('assignOrderId');
            const assignClientName = document.getElementById('assignClientName');
            
            assignButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const orderId = this.dataset.orderId;
                    const clientName = this.dataset.clientName;
                    
                    assignOrderId.value = orderId;
                    assignClientName.textContent = clientName;
                    
                    // Reset form
                document.getElementById('assigned_to').value = '';
                    assignModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });
            });
            
            function closeAssignModal() {
                assignModal.classList.remove('active');
                document.body.style.overflow = '';
            }
            
            if (assignModalClose) assignModalClose.addEventListener('click', closeAssignModal);
            if (assignModalCancel) assignModalCancel.addEventListener('click', closeAssignModal);
            if (assignBackdrop) assignBackdrop.addEventListener('click', closeAssignModal);
            
            // Delete order modal
            const deleteModal = document.getElementById('deleteModal');
            const deleteModalClose = document.getElementById('deleteModalClose');
            const deleteModalCancel = document.getElementById('deleteModalCancel');
            const deleteBackdrop = document.querySelector('#deleteModal .modal-backdrop');
            const deleteButtons = document.querySelectorAll('.delete-order-btn');
            const deleteOrderId = document.getElementById('deleteOrderId');
            const deleteClientName = document.getElementById('deleteClientName');
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const orderId = this.dataset.orderId;
                    const clientName = this.dataset.clientName;
                    
                    deleteOrderId.value = orderId;
                    deleteClientName.textContent = clientName;
                    
                    deleteModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });
            });
            
            function closeDeleteModal() {
                deleteModal.classList.remove('active');
                document.body.style.overflow = '';
            }
            
            if (deleteModalClose) deleteModalClose.addEventListener('click', closeDeleteModal);
            if (deleteModalCancel) deleteModalCancel.addEventListener('click', closeDeleteModal);
            if (deleteBackdrop) deleteBackdrop.addEventListener('click', closeDeleteModal);
            
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
            
            // Auto-dismiss alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        if (alert.parentElement) alert.remove();
                    }, 300);
                });
            }, 5000);
            
            // Escape key to close modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (assignModal.classList.contains('active')) closeAssignModal();
                    if (deleteModal.classList.contains('active')) closeDeleteModal();
                }
            });
        });
        
        // Confirm bulk action
        function confirmBulkAction() {
            const checkboxes = document.querySelectorAll('.order-checkbox:checked');
            const action = document.querySelector('.bulk-action-select').value;
            
            if (checkboxes.length === 0) {
                alert('Please select at least one order.');
                return false;
            }
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            if (action === 'delete') {
                if (!confirm('Are you sure you want to delete the selected orders? This action cannot be undone.')) {
                    return false;
                }
            }
            
            const bulkForm = document.getElementById('bulkOrderForm');
            if (bulkForm) {
                bulkForm.querySelectorAll('input[name="selected_orders[]"]').forEach(i => i.remove());
                checkboxes.forEach(cb => {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'selected_orders[]';
                    hidden.value = cb.value;
                    bulkForm.appendChild(hidden);
                });
            }
            
            return true;
        }
    </script>
</body>
</html>
