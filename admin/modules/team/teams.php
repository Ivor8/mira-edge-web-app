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
$user_id = $user['user_id'];

// Handle team actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                redirect(url('/admin/modules/team/teams.php'));
                
            } catch (PDOException $e) {
                error_log("Add Team Error: " . $e->getMessage());
                $session->setFlash('error', 'Error creating team: ' . $e->getMessage());
            }
        }
        
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
                redirect(url('/admin/modules/team/teams.php'));
                
            } catch (PDOException $e) {
                error_log("Update Team Error: " . $e->getMessage());
                $session->setFlash('error', 'Error updating team: ' . $e->getMessage());
            }
        }
        
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
        
    } elseif (isset($_POST['assign_member'])) {
        // Assign member to team
        $team_id = (int)($_POST['team_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        
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
            redirect(url('/admin/modules/team/teams.php?view=' . $team_id));
            
        } catch (PDOException $e) {
            error_log("Assign Member Error: " . $e->getMessage());
            $session->setFlash('error', 'Error assigning member to team: ' . $e->getMessage());
        }
        
    } elseif (isset($_POST['remove_member'])) {
        // Remove member from team
        $team_id = (int)($_POST['team_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        
        try {
            $stmt = $db->prepare("UPDATE user_teams SET is_active = 0 WHERE user_id = ? AND team_id = ?");
            $stmt->execute([$user_id, $team_id]);
            
            $session->setFlash('success', 'Member removed from team successfully!');
            redirect(url('/admin/modules/team/teams.php?view=' . $team_id));
            
        } catch (PDOException $e) {
            error_log("Remove Member Error: " . $e->getMessage());
            $session->setFlash('error', 'Error removing member from team: ' . $e->getMessage());
        }
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
    SELECT user_id, username, email, CONCAT(first_name, ' ', last_name) as full_name, role
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
               CONCAT(u.first_name, ' ', u.last_name) as leader_name,
               COUNT(DISTINCT ut.user_id) as member_count
        FROM teams t
        LEFT JOIN users u ON t.team_leader_id = u.user_id
        LEFT JOIN user_teams ut ON t.team_id = ut.team_id AND ut.is_active = 1
        WHERE t.team_id = ?
        GROUP BY t.team_id
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
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teams Management | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .teams-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
        }
        
        @media (max-width: 992px) {
            .teams-container {
                grid-template-columns: 1fr;
            }
        }
        
        .teams-sidebar {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-md);
        }
        
        .teams-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .team-item {
            padding: 15px;
            border-radius: var(--radius-md);
            margin-bottom: 10px;
            border: 1px solid var(--color-gray-200);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .team-item:hover {
            background: var(--color-gray-50);
            transform: translateX(5px);
        }
        
        .team-item.active {
            background: var(--color-gray-100);
            border-left: 4px solid var(--color-black);
        }
        
        .team-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--color-black);
        }
        
        .team-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--color-gray-600);
        }
        
        .team-content {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
        }
        
        .team-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--color-gray-200);
        }
        
        .team-title {
            margin: 0;
            font-size: 24px;
        }
        
        .department-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            background: var(--color-gray-200);
            color: var(--color-gray-700);
        }
        
        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .member-card {
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
            padding: 15px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid var(--color-gray-200);
        }
        
        .member-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .member-avatar {
            width: 60px;
            height: 60px;
            margin: 0 auto 10px;
            border-radius: 50%;
            overflow: hidden;
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
        
        .member-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .member-role {
            font-size: 12px;
            color: var(--color-gray-600);
            margin-bottom: 10px;
        }
        
        .remove-member-btn {
            background: none;
            border: none;
            color: var(--color-error);
            cursor: pointer;
            font-size: 14px;
        }
        
        .no-team-selected {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 400px;
            text-align: center;
            color: var(--color-gray-500);
        }
        
        .no-team-selected i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
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
            color: white;
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
                    <button type="button" class="btn btn-primary" data-modal="addTeamModal">
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

            <div class="teams-container">
                <!-- Teams List Sidebar -->
                <div class="teams-sidebar">
                    <h3>All Teams (<?php echo count($all_teams); ?>)</h3>
                    <div class="teams-list">
                        <?php if (!empty($all_teams)): ?>
                            <?php foreach ($all_teams as $team): ?>
                                <a href="<?php echo url('../admin/modules/team/teams.php?view=' . $team['team_id']); ?>" 
                                   class="team-item <?php echo ($current_team && $current_team['team_id'] == $team['team_id']) ? 'active' : ''; ?>">
                                    <div class="team-name"><?php echo e($team['team_name']); ?></div>
                                    <div class="team-meta">
                                        <span>
                                            <i class="fas fa-users"></i>
                                            <?php echo $team['member_count']; ?> members
                                        </span>
                                        <span class="department-badge department-<?php echo $team['department']; ?>">
                                            <?php echo str_replace('_', ' ', $team['department']); ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state" style="text-align: center; padding: 40px 20px;">
                                <i class="fas fa-users" style="font-size: 48px; opacity: 0.3; margin-bottom: 20px;"></i>
                                <p>No teams created yet</p>
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
                                <p><?php echo e($current_team['team_description']); ?></p>
                                <div style="margin-top: 10px;">
                                    <span class="department-badge department-<?php echo $current_team['department']; ?>">
                                        <?php echo str_replace('_', ' ', $current_team['department']); ?>
                                    </span>
                                    <span class="status-badge status-<?php echo strtolower($current_team['status']); ?>" style="margin-left: 10px;">
                                        <?php echo ucfirst($current_team['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div>
                                <button type="button" class="btn btn-outline" data-modal="editTeamModal">
                                    <i class="fas fa-edit"></i> Edit Team
                                </button>
                                <button type="button" class="btn btn-outline" data-modal="assignMemberModal">
                                    <i class="fas fa-user-plus"></i> Add Member
                                </button>
                            </div>
                        </div>
                        
                        <div class="team-info-grid">
                            <div class="info-item">
                                <label>Team Leader:</label>
                                <span>
                                    <?php if ($current_team['leader_name']): ?>
                                        <?php echo e($current_team['leader_name']); ?>
                                    <?php else: ?>
                                        <span class="text-gray-500">Not assigned</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <label>Total Members:</label>
                                <span><?php echo $current_team['member_count']; ?></span>
                            </div>
                            <div class="info-item">
                                <label>Created:</label>
                                <span><?php echo formatDate($current_team['created_at']); ?></span>
                            </div>
                        </div>
                        
                        <h3 style="margin-top: 30px; margin-bottom: 20px;">Team Members</h3>
                        
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
                                        <div class="member-name">
                                            <?php echo e($member['full_name']); ?>
                                        </div>
                                        <div class="member-role">
                                            <?php echo str_replace('_', ' ', $member['role']); ?>
                                            <?php if ($member['position']): ?>
                                                <br><?php echo e($member['position']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" action="" style="margin-top: 10px;">
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
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="text-align: center; padding: 40px 20px;">
                                <i class="fas fa-user-friends" style="font-size: 48px; opacity: 0.3; margin-bottom: 20px;"></i>
                                <p>No members in this team yet</p>
                                <button type="button" class="btn btn-primary" data-modal="assignMemberModal" style="margin-top: 15px;">
                                    <i class="fas fa-user-plus"></i> Add First Member
                                </button>
                            </div>
                        <?php endif; ?>
                        
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
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Team</h3>
                <button class="modal-close">&times;</button>
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
                    <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
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
            <div class="modal-backdrop"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Edit Team: <?php echo e($current_team['team_name']); ?></h3>
                    <button class="modal-close">&times;</button>
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
                                        <?php echo e($user['full_name']); ?> (<?php echo e($user['role']); ?>)
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
                        <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                        <button type="submit" name="update_team" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Team
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Delete Team Modal -->
        <div class="modal" id="deleteTeamModal">
            <div class="modal-backdrop"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Delete Team</h3>
                    <button class="modal-close">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="team_id" value="<?php echo $current_team['team_id']; ?>">
                        
                        <p>Are you sure you want to delete the team "<strong><?php echo e($current_team['team_name']); ?></strong>"?</p>
                        
                        <?php if ($current_team['member_count'] > 0): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                This team has <?php echo $current_team['member_count']; ?> active member(s). 
                                Deleting will remove all team assignments.
                            </div>
                        <?php endif; ?>
                        
                        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                        <button type="submit" name="delete_team" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Team
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Assign Member Modal -->
        <div class="modal" id="assignMemberModal">
            <div class="modal-backdrop"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Add Member to Team</h3>
                    <button class="modal-close">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="team_id" value="<?php echo $current_team['team_id']; ?>">
                        
                        <div class="form-group">
                            <label for="assign_user_id" class="form-label required">Select Member</label>
                            <select id="assign_user_id" name="user_id" class="form-control" required>
                                <option value="">Choose a team member...</option>
                                <?php 
                                // Get users not already in this team
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
                                $stmt->execute([$current_team['team_id']]);
                                $available_users = $stmt->fetchAll();
                                ?>
                                
                                <?php if (!empty($available_users)): ?>
                                    <?php foreach ($available_users as $user): ?>
                                        <option value="<?php echo $user['user_id']; ?>">
                                            <?php echo e($user['full_name']); ?> (<?php echo e($user['role']); ?>) - <?php echo e($user['email']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No available users</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <?php if (empty($available_users)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                All active users are already members of this team.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                        <button type="submit" name="assign_member" class="btn btn-primary" <?php echo empty($available_users) ? 'disabled' : ''; ?>>
                            <i class="fas fa-user-plus"></i> Add to Team
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- JavaScript -->
    <script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal functionality
            document.querySelectorAll('[data-modal]').forEach(button => {
                button.addEventListener('click', function() {
                    const modalId = this.dataset.modal;
                    const modal = document.getElementById(modalId);
                    if (modal) {
                        modal.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }
                });
            });
            
            // Close modals
            document.querySelectorAll('.modal-close, .modal-cancel, .modal-backdrop').forEach(element => {
                element.addEventListener('click', function() {
                    this.closest('.modal').classList.remove('active');
                    document.body.style.overflow = '';
                });
            });
            
            // Initialize select2 for selects in modals
            if (typeof $ !== 'undefined') {
                $('select').select2({
                    width: '100%',
                    dropdownParent: $('.modal')
                });
            }
        });
    </script>
</body>
</html>