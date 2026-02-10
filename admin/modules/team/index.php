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

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_users'])) {
        $action = $_POST['bulk_action'];
        $selected_users = $_POST['selected_users'];
        
        try {
            if ($action === 'delete') {
                // Don't allow deletion of current user
                if (in_array($user_id, $selected_users)) {
                    $session->setFlash('error', 'You cannot delete your own account.');
                } else {
                    $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
                    $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE user_id IN ($placeholders)");
                    $stmt->execute($selected_users);
                    $session->setFlash('success', 'Selected users deactivated successfully.');
                }
            } elseif ($action === 'activate') {
                $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
                $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE user_id IN ($placeholders)");
                $stmt->execute($selected_users);
                $session->setFlash('success', 'Selected users activated successfully.');
            }
        } catch (PDOException $e) {
            error_log("Bulk Action Error: " . $e->getMessage());
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }
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
    $where_clauses[] = "ut.team_id = ?";
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
    LEFT JOIN user_teams ut ON u.user_id = ut.user_id
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
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/team.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .team-management-styles {
            /* Additional styles specific to team management */
        }
        
        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #000 0%, #333 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }
        
        .team-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #f0f0f0;
            border-radius: 20px;
            font-size: 12px;
            margin: 2px;
            color: #333;
            border: 1px solid #ddd;
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
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
        
        .status-active {
            color: #00c853;
        }
        
        .status-inactive {
            color: #ff9800;
        }
        
        .status-on_leave {
            color: #2196f3;
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
                                       placeholder="Search team members...">
                            </div>
                            
                            <!-- Status Filter -->
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
                            
                            <!-- Role Filter -->
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
                            
                            <!-- Team Filter -->
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
                            
                            <!-- Filter Actions -->
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="<?php echo url('/admin/modules/team/'); ?>" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
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
                    <form method="POST" action="" class="bulk-actions-form">
                        <div class="bulk-actions">
                            <select name="bulk_action" class="bulk-action-select">
                                <option value="">Bulk Actions</option>
                                <option value="activate">Activate</option>
                                <option value="delete">Deactivate</option>
                            </select>
                            <button type="submit" class="btn btn-outline" name="apply_bulk_action">
                                Apply
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($users)): ?>
                        <div class="table-responsive">
                            <table class="table team-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="select-all" class="select-all-checkbox">
                                        </th>
                                        <th class="avatar-cell">Avatar</th>
                                        <th class="name-cell">Name</th>
                                        <th class="role-cell">Role</th>
                                        <th class="team-cell">Team(s)</th>
                                        <th class="status-cell">Status</th>
                                        <th class="email-cell">Email</th>
                                        <th class="last-login-cell">Last Login</th>
                                        <th class="actions-cell">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $member): ?>
                                        <tr class="team-row" data-user-id="<?php echo $member['user_id']; ?>">
                                            <td class="checkbox-cell">
                                                <input type="checkbox" 
                                                       name="selected_users[]" 
                                                       value="<?php echo $member['user_id']; ?>"
                                                       class="user-checkbox"
                                                       <?php echo ($member['user_id'] == $user_id) ? 'disabled' : ''; ?>>
                                            </td>
                                            <td class="avatar-cell">
                                                <div class="user-avatar-small">
                                                    <?php if (!empty($member['profile_image'])): ?>
                                                        <img src="<?php echo e($member['profile_image']); ?>" 
                                                             alt="<?php echo e($member['first_name']); ?>"
                                                             class="avatar-img">
                                                    <?php else: ?>
                                                        <div class="avatar-placeholder">
                                                            <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="name-cell">
                                                <div class="user-info">
                                                    <h4 class="user-name">
                                                        <a href="<?php echo url('/admin/modules/team/edit.php?id=' . $member['user_id']); ?>">
                                                            <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>
                                                        </a>
                                                    </h4>
                                                    <p class="user-username">
                                                        @<?php echo e($member['username']); ?>
                                                    </p>
                                                </div>
                                            </td>
                                            <td class="role-cell">
                                                <span class="role-badge role-<?php echo strtolower($member['role']); ?>">
                                                    <?php echo str_replace('_', ' ', $member['role']); ?>
                                                </span>
                                            </td>
                                            <td class="team-cell">
                                                <?php if (!empty($member['team_names'])): ?>
                                                    <?php 
                                                    $team_names = explode(', ', $member['team_names']);
                                                    $team_ids = explode(',', $member['team_ids']);
                                                    foreach ($team_names as $index => $team_name): 
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
                                                    <span class="text-gray-500">No team assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="status-cell">
                                                <span class="status-badge status-<?php echo strtolower($member['status']); ?>">
                                                    <i class="fas fa-circle"></i>
                                                    <?php echo ucfirst($member['status']); ?>
                                                </span>
                                            </td>
                                            <td class="email-cell">
                                                <a href="mailto:<?php echo e($member['email']); ?>" class="email-link">
                                                    <?php echo e($member['email']); ?>
                                                </a>
                                            </td>
                                            <td class="last-login-cell">
                                                <div class="last-login-info">
                                                    <?php if ($member['last_login']): ?>
                                                        <?php echo formatDate($member['last_login'], 'M d, Y'); ?>
                                                        <small><?php echo formatDate($member['last_login'], 'h:i A'); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-gray-500">Never</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('/admin/modules/team/edit.php?id=' . $member['user_id']); ?>" 
                                                       class="btn-action btn-edit"
                                                       data-tooltip="Edit Member">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('/admin/modules/team/teams.php?assign=' . $member['user_id']); ?>" 
                                                       class="btn-action btn-team"
                                                       data-tooltip="Assign to Team">
                                                        <i class="fas fa-user-plus"></i>
                                                    </a>
                                                    
                                                    <?php if ($member['user_id'] != $user_id): ?>
                                                        <?php if ($member['status'] == 'active'): ?>
                                                            <button type="button" 
                                                                    class="btn-action btn-deactivate"
                                                                    data-user-id="<?php echo $member['user_id']; ?>"
                                                                    data-user-name="<?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>"
                                                                    data-tooltip="Deactivate">
                                                                <i class="fas fa-user-slash"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" 
                                                                    class="btn-action btn-activate"
                                                                    data-user-id="<?php echo $member['user_id']; ?>"
                                                                    data-user-name="<?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>"
                                                                    data-tooltip="Activate">
                                                                <i class="fas fa-user-check"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <a href="<?php echo url('/admin/modules/team/reset-password.php?id=' . $member['user_id']); ?>" 
                                                       class="btn-action btn-reset-password"
                                                       data-tooltip="Reset Password">
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

    <!-- Deactivation Confirmation Modal -->
    <div class="modal" id="deactivateModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Deactivate Member</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to deactivate the team member "<span id="userToDeactivate"></span>"?</p>
                <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> This user will no longer be able to log in.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deactivateForm">
                    <input type="hidden" name="user_id" id="deactivateUserId">
                    <input type="hidden" name="action" value="deactivate">
                    <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                    <button type="submit" name="deactivate_user" class="btn btn-warning">
                        <i class="fas fa-user-slash"></i> Deactivate User
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Activation Confirmation Modal -->
    <div class="modal" id="activateModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Activate Member</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to activate the team member "<span id="userToActivate"></span>"?</p>
                <p class="text-info"><i class="fas fa-info-circle"></i> This user will be able to log in again.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="activateForm">
                    <input type="hidden" name="user_id" id="activateUserId">
                    <input type="hidden" name="action" value="activate">
                    <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                    <button type="submit" name="activate_user" class="btn btn-success">
                        <i class="fas fa-user-check"></i> Activate User
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
    <script src="<?php echo url('../../../assets/js/team.js'); ?>"></script>
    <script>
        // Team specific JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            const teamManager = new TeamManager();
            teamManager.init();
        });
    </script>
</body>
</html>