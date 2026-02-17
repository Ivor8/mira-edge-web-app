<?php
/**
 * Teams Management
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

// Handle team actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Infer action if submit button names are missing (some browsers/JS can omit them)
    if (!isset($_POST['add_team']) && !isset($_POST['update_team']) && !isset($_POST['delete_team']) && !isset($_POST['assign_member']) && !isset($_POST['remove_member'])) {
        // Add team when team_name present and no team_id
        if (!empty($_POST['team_name']) && empty($_POST['team_id'])) {
            $_POST['add_team'] = 1;
        }

        // Update team when team_id and team_name present
        if (!empty($_POST['team_id']) && !empty($_POST['team_name'])) {
            $_POST['update_team'] = 1;
        }

        // Delete team when team_id provided and delete flag not sent
        if (!empty($_POST['team_id']) && isset($_POST['confirm_delete'])) {
            $_POST['delete_team'] = 1;
        }

        // Assign/remove member when both team_id and user_id present
        if (!empty($_POST['team_id']) && !empty($_POST['user_id'])) {
            // If remove_member explicitly present, keep it; otherwise default to assign
            if (!isset($_POST['remove_member'])) {
                $_POST['assign_member'] = 1;
            }
        }
    }
    if (isset($_POST['add_team'])) {
        // Add new team
        $team_name = trim($_POST['team_name'] ?? '');
        $team_description = trim($_POST['team_description'] ?? '');
        $department = $_POST['department'] ?? 'web_dev';
        
        if (empty($team_name)) {
            $session->setFlash('error', 'Team name is required.');
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO teams (team_name, team_description, department, status)
                    VALUES (?, ?, ?, 'active')
                ");
                $stmt->execute([$team_name, $team_description, $department]);
                
                $session->setFlash('success', 'Team created successfully!');
                
            } catch (PDOException $e) {
                error_log("Add Team Error: " . $e->getMessage());
                $session->setFlash('error', 'Error creating team: ' . $e->getMessage());
            }
        }
        redirect(url('/admin/modules/team/teams.php'));
        
    } elseif (isset($_POST['update_team'])) {
        // Update team
        $team_id = (int)($_POST['team_id'] ?? 0);
        $team_name = trim($_POST['team_name'] ?? '');
        $team_description = trim($_POST['team_description'] ?? '');
        $department = $_POST['department'] ?? 'web_dev';
        $team_leader_id = !empty($_POST['team_leader_id']) ? (int)$_POST['team_leader_id'] : null;
        $status = $_POST['status'] ?? 'active';
        
        if (empty($team_name)) {
            $session->setFlash('error', 'Team name is required.');
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE teams 
                    SET team_name = ?, team_description = ?, department = ?, 
                        team_leader_id = ?, status = ?
                    WHERE team_id = ?
                ");
                $stmt->execute([$team_name, $team_description, $department, $team_leader_id, $status, $team_id]);
                
                $session->setFlash('success', 'Team updated successfully!');
                
            } catch (PDOException $e) {
                error_log("Update Team Error: " . $e->getMessage());
                $session->setFlash('error', 'Error updating team: ' . $e->getMessage());
            }
        }
        redirect(url('/admin/modules/team/teams.php?view=' . $team_id));
        
    } elseif (isset($_POST['delete_team'])) {
        // Delete team
        $team_id = (int)($_POST['team_id'] ?? 0);
        
        try {
            // Check if team has members
            $stmt = $db->prepare("SELECT COUNT(*) as member_count FROM user_teams WHERE team_id = ? AND is_active = 1");
            $stmt->execute([$team_id]);
            $result = $stmt->fetch();
            
            if ($result['member_count'] > 0) {
                $session->setFlash('error', 'Cannot delete team that has active members. Remove members first.');
            } else {
                $stmt = $db->prepare("DELETE FROM teams WHERE team_id = ?");
                $stmt->execute([$team_id]);
                
                $session->setFlash('success', 'Team deleted successfully!');
            }
            
        } catch (PDOException $e) {
            error_log("Delete Team Error: " . $e->getMessage());
            $session->setFlash('error', 'Error deleting team: ' . $e->getMessage());
        }
        redirect(url('/admin/modules/team/teams.php'));
        
    } elseif (isset($_POST['assign_member'])) {
        // Assign member to team
        $team_id = (int)($_POST['team_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        
        if ($team_id && $user_id) {
            try {
                // Check if assignment already exists
                $stmt = $db->prepare("SELECT user_team_id FROM user_teams WHERE user_id = ? AND team_id = ?");
                $stmt->execute([$user_id, $team_id]);
                
                if ($stmt->fetch()) {
                    // Update existing
                    $stmt = $db->prepare("UPDATE user_teams SET is_active = 1 WHERE user_id = ? AND team_id = ?");
                    $stmt->execute([$user_id, $team_id]);
                } else {
                    // Insert new
                    $stmt = $db->prepare("INSERT INTO user_teams (user_id, team_id, is_active) VALUES (?, ?, 1)");
                    $stmt->execute([$user_id, $team_id]);
                }
                
                $session->setFlash('success', 'Member assigned to team successfully!');
                
            } catch (PDOException $e) {
                error_log("Assign Member Error: " . $e->getMessage());
                $session->setFlash('error', 'Error assigning member to team: ' . $e->getMessage());
            }
        } else {
            $session->setFlash('error', 'Invalid team or user ID.');
        }
        redirect(url('/admin/modules/team/teams.php?view=' . $team_id));
        
    } elseif (isset($_POST['remove_member'])) {
        // Remove member from team
        $team_id = (int)($_POST['team_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        
        if ($team_id && $user_id) {
            try {
                $stmt = $db->prepare("UPDATE user_teams SET is_active = 0 WHERE user_id = ? AND team_id = ?");
                $stmt->execute([$user_id, $team_id]);
                
                $session->setFlash('success', 'Member removed from team successfully!');
                
            } catch (PDOException $e) {
                error_log("Remove Member Error: " . $e->getMessage());
                $session->setFlash('error', 'Error removing member from team: ' . $e->getMessage());
            }
        }
        redirect(url('/admin/modules/team/teams.php?view=' . $team_id));
    }
}

// Get all teams
$stmt = $db->query("
    SELECT t.*, 
           CONCAT(u.first_name, ' ', u.last_name) as leader_name,
           COUNT(DISTINCT ut.user_id) as member_count
    FROM teams t
    LEFT JOIN users u ON t.team_leader_id = u.user_id
    LEFT JOIN user_teams ut ON t.team_id = ut.team_id AND ut.is_active = 1
    GROUP BY t.team_id
    ORDER BY t.team_name
");
$all_teams = $stmt->fetchAll();

// Get all active users for team assignment
$stmt = $db->query("
    SELECT user_id, username, email, CONCAT(first_name, ' ', last_name) as full_name, role, profile_image
    FROM users 
    WHERE status = 'active'
    ORDER BY first_name, last_name
");
$all_users = $stmt->fetchAll();

// Get specific team details if viewing
$current_team = null;
$team_members = [];
if (isset($_GET['view'])) {
    $team_id = (int)$_GET['view'];
    
    $stmt = $db->prepare("
        SELECT t.*, 
               CONCAT(u.first_name, ' ', u.last_name) as leader_name
        FROM teams t
        LEFT JOIN users u ON t.team_leader_id = u.user_id
        WHERE t.team_id = ?
    ");
    $stmt->execute([$team_id]);
    $current_team = $stmt->fetch();
    
    if ($current_team) {
        // Get team members
        $stmt = $db->prepare("
            SELECT u.user_id, u.username, u.email, 
                   CONCAT(u.first_name, ' ', u.last_name) as full_name,
                   u.role, u.position, u.profile_image
            FROM users u
            JOIN user_teams ut ON u.user_id = ut.user_id
            WHERE ut.team_id = ? AND ut.is_active = 1
            ORDER BY u.first_name, u.last_name
        ");
        $stmt->execute([$team_id]);
        $team_members = $stmt->fetchAll();
        
        // Get available users for this team (not already members)
        $stmt = $db->prepare("
            SELECT u.user_id, CONCAT(u.first_name, ' ', u.last_name) as full_name, u.role, u.email
            FROM users u
            WHERE u.status = 'active'
            AND u.user_id NOT IN (
                SELECT ut.user_id 
                FROM user_teams ut 
                WHERE ut.team_id = ? AND ut.is_active = 1
            )
            ORDER BY u.first_name, u.last_name
        ");
        $stmt->execute([$team_id]);
        $available_users = $stmt->fetchAll();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teams Management | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .teams-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: var(--space-xl);
        }
        
        @media (max-width: 992px) {
            .teams-container {
                grid-template-columns: 1fr;
            }
        }
        
        .teams-sidebar {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-gray-200);
        }
        
        .teams-sidebar h3 {
            margin-bottom: var(--space-lg);
            padding-bottom: var(--space-sm);
            border-bottom: 2px solid var(--color-gray-200);
        }
        
        .teams-list {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .team-item {
            display: block;
            padding: var(--space-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-sm);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-fast);
            text-decoration: none;
            color: inherit;
        }
        
        .team-item:hover {
            background: var(--color-gray-50);
            transform: translateX(5px);
            border-color: var(--color-gray-300);
        }
        
        .team-item.active {
            background: var(--color-gray-100);
            border-left: 4px solid var(--color-black);
        }
        
        .team-name {
            font-weight: 600;
            margin-bottom: var(--space-xs);
            color: var(--color-black);
        }
        
        .team-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--color-gray-600);
        }
        
        .department-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .department-web_dev {
            background: linear-gradient(135deg, #000 0%, #333 100%);
            color: white;
        }
        
        .department-mobile_dev {
            background: linear-gradient(135deg, #333 0%, #555 100%);
            color: white;
        }
        
        .department-digital_marketing {
            background: linear-gradient(135deg, #555 0%, #777 100%);
            color: white;
        }
        
        .department-design {
            background: linear-gradient(135deg, #777 0%, #999 100%);
            color: white;
        }
        
        .department-content {
            background: linear-gradient(135deg, #999 0%, #bbb 100%);
            color: white;
        }
        
        .department-sales {
            background: linear-gradient(135deg, #bbb 0%, #ddd 100%);
            color: black;
        }
        
        .team-content {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-gray-200);
        }
        
        .team-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--space-xl);
            padding-bottom: var(--space-lg);
            border-bottom: 2px solid var(--color-gray-200);
        }
        
        .team-title {
            margin: 0 0 var(--space-sm);
            font-size: 1.75rem;
            color: var(--color-black);
        }
        
        .team-description {
            margin: 0 0 var(--space-md);
            color: var(--color-gray-600);
            line-height: 1.6;
        }
        
        .team-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-md);
            margin-bottom: var(--space-xl);
            padding: var(--space-md);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .info-item label {
            font-size: 0.75rem;
            color: var(--color-gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-item span {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--color-gray-800);
        }
        
        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: var(--space-md);
            margin-top: var(--space-lg);
        }
        
        .member-card {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            padding: var(--space-md);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-fast);
        }
        
        .member-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: var(--color-gray-300);
        }
        
        .member-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--color-black), var(--color-gray-700));
        }
        
        .member-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .member-avatar .placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
        }
        
        .member-info {
            flex: 1;
            min-width: 0;
        }
        
        .member-name {
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--color-black);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .member-role {
            font-size: 0.75rem;
            color: var(--color-gray-600);
            margin-bottom: 4px;
        }
        
        .remove-member-btn {
            background: none;
            border: none;
            color: var(--color-error);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all var(--transition-fast);
        }
        
        .remove-member-btn:hover {
            background-color: rgba(244, 67, 54, 0.1);
        }
        
        .no-team-selected {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 400px;
            text-align: center;
            color: var(--color-gray-500);
        }
        
        .no-team-selected i {
            font-size: 4rem;
            margin-bottom: var(--space-lg);
            opacity: 0.3;
        }
        
        .no-team-selected h3 {
            margin-bottom: var(--space-sm);
            color: var(--color-gray-700);
        }
        
        .no-team-selected p {
            max-width: 300px;
            margin: 0 auto;
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
        
        .btn i {
            font-size: 0.875rem;
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
        
        .btn-danger {
            background-color: var(--color-error);
            color: var(--color-white);
        }
        
        .btn-danger:hover {
            background-color: var(--color-error-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
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
        
        .modal-footer {
            display: flex;
            gap: var(--space-md);
            justify-content: flex-end;
            padding-top: var(--space-md);
            border-top: 1px solid var(--color-gray-200);
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
                    <i class="fas fa-users-cog"></i>
                    Teams Management
                </h1>
                <div class="page-actions">
                    <button type="button" class="btn btn-primary" onclick="openModal('addTeamModal')">
                        <i class="fas fa-plus"></i> Create New Team
                    </button>
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

            <div class="teams-container">
                <!-- Teams List Sidebar -->
                <div class="teams-sidebar">
                    <h3>All Teams (<?php echo count($all_teams); ?>)</h3>
                    <div class="teams-list">
                        <?php if (!empty($all_teams)): ?>
                            <?php foreach ($all_teams as $team): ?>
                                <a href="<?php echo url('/admin/modules/team/teams.php?view=' . $team['team_id']); ?>" 
                                   class="team-item <?php echo ($current_team && $current_team['team_id'] == $team['team_id']) ? 'active' : ''; ?>">
                                    <div class="team-name"><?php echo e($team['team_name']); ?></div>
                                    <div class="team-meta">
                                        <span>
                                            <i class="fas fa-users"></i>
                                            <?php echo $team['member_count']; ?> members
                                        </span>
                                        <span class="department-badge department-<?php echo $team['department']; ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $team['department'])); ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state" style="text-align: center; padding: 40px 20px;">
                                <i class="fas fa-users" style="font-size: 48px; opacity: 0.3; margin-bottom: 20px;"></i>
                                <p>No teams created yet</p>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addTeamModal')">
                                    Create Your First Team
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Team Details Content -->
                <div class="team-content">
                    <?php if ($current_team): ?>
                        <div class="team-header">
                            <div>
                                <h2 class="team-title"><?php echo e($current_team['team_name']); ?></h2>
                                <?php if ($current_team['team_description']): ?>
                                    <p class="team-description"><?php echo e($current_team['team_description']); ?></p>
                                <?php endif; ?>
                                <div>
                                    <span class="department-badge department-<?php echo $current_team['department']; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $current_team['department'])); ?>
                                    </span>
                                    <span class="status-badge status-<?php echo strtolower($current_team['status']); ?>" style="margin-left: var(--space-sm);">
                                        <i class="fas fa-circle"></i> <?php echo ucfirst($current_team['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div style="display: flex; gap: var(--space-sm);">
                                <button type="button" class="btn btn-outline" onclick="openEditTeamModal()">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="btn btn-outline" onclick="openModal('assignMemberModal')">
                                    <i class="fas fa-user-plus"></i> Add Member
                                </button>
                            </div>
                        </div>
                        
                        <div class="team-info-grid">
                            <div class="info-item">
                                <label>Team Leader</label>
                                <span>
                                    <?php if ($current_team['leader_name']): ?>
                                        <?php echo e($current_team['leader_name']); ?>
                                    <?php else: ?>
                                        <span class="text-gray-500">Not assigned</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <label>Total Members</label>
                                <span><?php echo count($team_members); ?></span>
                            </div>
                            <div class="info-item">
                                <label>Created</label>
                                <span><?php echo formatDate($current_team['created_at'], 'M d, Y'); ?></span>
                            </div>
                        </div>
                        
                        <h3 style="margin-bottom: var(--space-lg);">Team Members (<?php echo count($team_members); ?>)</h3>
                        
                        <?php if (!empty($team_members)): ?>
                            <div class="members-grid">
                                <?php foreach ($team_members as $member): ?>
                                    <div class="member-card">
                                        <div class="member-avatar">
                                            <?php if (!empty($member['profile_image'])): ?>
                                                <img src="<?php echo e($member['profile_image']); ?>" alt="<?php echo e($member['full_name']); ?>">
                                            <?php else: ?>
                                                <div class="placeholder">
                                                    <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="member-info">
                                            <div class="member-name"><?php echo e($member['full_name']); ?></div>
                                            <div class="member-role">
                                                <?php echo ucwords(str_replace('_', ' ', $member['role'])); ?>
                                                <?php if ($member['position']): ?>
                                                    • <?php echo e($member['position']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="team_id" value="<?php echo $current_team['team_id']; ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $member['user_id']; ?>">
                                                <button type="submit" 
                                                        name="remove_member" 
                                                        class="remove-member-btn"
                                                        onclick="return confirm('Remove <?php echo e($member['full_name']); ?> from this team?')">
                                                    <i class="fas fa-user-minus"></i> Remove
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="text-align: center; padding: 60px 20px;">
                                <i class="fas fa-user-friends" style="font-size: 48px; opacity: 0.3; margin-bottom: 20px;"></i>
                                <p>No members in this team yet</p>
                                <button type="button" class="btn btn-primary" onclick="openModal('assignMemberModal')" style="margin-top: 15px;">
                                    <i class="fas fa-user-plus"></i> Add First Member
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Delete Team Button -->
                        <div style="margin-top: var(--space-xl); padding-top: var(--space-lg); border-top: 1px solid var(--color-gray-200);">
                            <button type="button" class="btn btn-danger" onclick="openDeleteTeamModal()">
                                <i class="fas fa-trash"></i> Delete Team
                            </button>
                        </div>
                        
                    <?php else: ?>
                        <div class="no-team-selected">
                            <i class="fas fa-hand-pointer"></i>
                            <h3>Select a Team</h3>
                            <p>Choose a team from the list to view and manage its details</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Team Modal -->
    <div class="modal" id="addTeamModal">
        <div class="modal-backdrop" onclick="closeModal('addTeamModal')"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Team</h3>
                <button class="modal-close" onclick="closeModal('addTeamModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="team_name" class="form-label required">Team Name</label>
                        <input type="text" id="team_name" name="team_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="department" class="form-label required">Department</label>
                        <select id="department" name="department" class="form-control" required>
                            <option value="web_dev">Web Development</option>
                            <option value="mobile_dev">Mobile Development</option>
                            <option value="digital_marketing">Digital Marketing</option>
                            <option value="design">Design</option>
                            <option value="content">Content</option>
                            <option value="sales">Sales</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="team_description" class="form-label">Description</label>
                        <textarea id="team_description" name="team_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addTeamModal')">Cancel</button>
                    <button type="submit" name="add_team" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Team
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Team Modal -->
    <?php if ($current_team): ?>
        <div class="modal" id="editTeamModal">
            <div class="modal-backdrop" onclick="closeModal('editTeamModal')"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Edit Team: <?php echo e($current_team['team_name']); ?></h3>
                    <button class="modal-close" onclick="closeModal('editTeamModal')">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="team_id" value="<?php echo $current_team['team_id']; ?>">
                        
                        <div class="form-group">
                            <label for="edit_team_name" class="form-label required">Team Name</label>
                            <input type="text" id="edit_team_name" name="team_name" class="form-control" 
                                   value="<?php echo e($current_team['team_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_department" class="form-label required">Department</label>
                            <select id="edit_department" name="department" class="form-control" required>
                                <option value="web_dev" <?php echo ($current_team['department'] == 'web_dev') ? 'selected' : ''; ?>>Web Development</option>
                                <option value="mobile_dev" <?php echo ($current_team['department'] == 'mobile_dev') ? 'selected' : ''; ?>>Mobile Development</option>
                                <option value="digital_marketing" <?php echo ($current_team['department'] == 'digital_marketing') ? 'selected' : ''; ?>>Digital Marketing</option>
                                <option value="design" <?php echo ($current_team['department'] == 'design') ? 'selected' : ''; ?>>Design</option>
                                <option value="content" <?php echo ($current_team['department'] == 'content') ? 'selected' : ''; ?>>Content</option>
                                <option value="sales" <?php echo ($current_team['department'] == 'sales') ? 'selected' : ''; ?>>Sales</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_team_description" class="form-label">Description</label>
                            <textarea id="edit_team_description" name="team_description" class="form-control" rows="3"><?php echo e($current_team['team_description']); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="team_leader_id" class="form-label">Team Leader</label>
                            <select id="team_leader_id" name="team_leader_id" class="form-control">
                                <option value="">Select Team Leader</option>
                                <?php foreach ($all_users as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>" 
                                            <?php echo ($current_team['team_leader_id'] == $user['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo e($user['full_name']); ?> (<?php echo ucwords(str_replace('_', ' ', $user['role'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_status" class="form-label">Status</label>
                            <select id="edit_status" name="status" class="form-control">
                                <option value="active" <?php echo ($current_team['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($current_team['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('editTeamModal')">Cancel</button>
                        <button type="submit" name="update_team" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Team
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Delete Team Modal -->
        <div class="modal" id="deleteTeamModal">
            <div class="modal-backdrop" onclick="closeModal('deleteTeamModal')"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Delete Team</h3>
                    <button class="modal-close" onclick="closeModal('deleteTeamModal')">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="team_id" value="<?php echo $current_team['team_id']; ?>">
                        
                        <p>Are you sure you want to delete the team "<strong><?php echo e($current_team['team_name']); ?></strong>"?</p>
                        
                        <?php if (count($team_members) > 0): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                This team has <?php echo count($team_members); ?> active member(s). 
                                Deleting will remove all team assignments.
                            </div>
                        <?php endif; ?>
                        
                        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('deleteTeamModal')">Cancel</button>
                        <button type="submit" name="delete_team" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Team
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Assign Member Modal -->
        <div class="modal" id="assignMemberModal">
            <div class="modal-backdrop" onclick="closeModal('assignMemberModal')"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Add Member to Team</h3>
                    <button class="modal-close" onclick="closeModal('assignMemberModal')">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="team_id" value="<?php echo $current_team['team_id']; ?>">
                        
                        <div class="form-group">
                            <label for="assign_user_id" class="form-label required">Select Member</label>
                            <select id="assign_user_id" name="user_id" class="form-control" required>
                                <option value="">Choose a team member...</option>
                                <?php if (isset($available_users) && !empty($available_users)): ?>
                                    <?php foreach ($available_users as $user): ?>
                                        <option value="<?php echo $user['user_id']; ?>">
                                            <?php echo e($user['full_name']); ?> (<?php echo ucwords(str_replace('_', ' ', $user['role'])); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No available users</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <?php if (isset($available_users) && empty($available_users)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                All active users are already members of this team.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('assignMemberModal')">Cancel</button>
                        <button type="submit" name="assign_member" class="btn btn-primary" <?php echo (isset($available_users) && empty($available_users)) ? 'disabled' : ''; ?>>
                            <i class="fas fa-user-plus"></i> Add to Team
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- JavaScript -->
    <script src="<?php echo url('/assets/js/admin.js'); ?>"></script>
    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }
        
        function openEditTeamModal() {
            openModal('editTeamModal');
        }
        
        function openDeleteTeamModal() {
            openModal('deleteTeamModal');
        }
        
        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = '';
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
    </script>
</body>
</html>