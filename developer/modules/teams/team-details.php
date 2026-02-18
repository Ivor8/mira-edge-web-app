<?php
/**
 * Developer Team Details
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

if (!$session->isLoggedIn()) {
    redirect(url('/login.php'));
}

if (!$session->isDeveloper()) {
    $session->setFlash('error', 'Access denied. Developer privileges required.');
    redirect(url('/'));
}

$user = $session->getUser();
$user_id = $user['user_id'];
$team_id = $_GET['id'] ?? 0;

if (!$team_id) {
    $session->setFlash('error', 'Invalid team ID.');
    redirect('/developer/modules/teams/index.php');
}

// Verify user belongs to this team
$stmt = $db->prepare("
    SELECT COUNT(*) as in_team
    FROM user_teams
    WHERE user_id = ? AND team_id = ? AND is_active = 1
");
$stmt->execute([$user_id, $team_id]);

if (!$stmt->fetch()['in_team']) {
    $session->setFlash('error', 'You do not have access to this team.');
    redirect('/developer/modules/teams/index.php');
}

// Get team details
$stmt = $db->prepare("
    SELECT 
        t.*,
        CONCAT(u.first_name, ' ', u.last_name) as leader_name,
        u.email as leader_email,
        u.profile_image as leader_image,
        u.phone as leader_phone,
        u.position as leader_position
    FROM teams t
    LEFT JOIN users u ON t.team_leader_id = u.user_id
    WHERE t.team_id = ?
");
$stmt->execute([$team_id]);
$team = $stmt->fetch();

if (!$team) {
    $session->setFlash('error', 'Team not found.');
    redirect('/developer/modules/teams/index.php');
}

// Get team members
$stmt = $db->prepare("
    SELECT 
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.profile_image,
        u.position,
        u.status,
        u.last_login,
        ut.joined_at,
        CASE WHEN u.user_id = t.team_leader_id THEN 1 ELSE 0 END as is_leader,
        (SELECT COUNT(*) FROM project_tasks WHERE assigned_to = u.user_id AND status = 'in_progress') as active_tasks
    FROM users u
    INNER JOIN user_teams ut ON u.user_id = ut.user_id
    INNER JOIN teams t ON ut.team_id = t.team_id
    WHERE ut.team_id = ? AND ut.is_active = 1
    ORDER BY is_leader DESC, u.first_name
");
$stmt->execute([$team_id]);
$members = $stmt->fetchAll();

// Get team projects
$stmt = $db->prepare("
    SELECT 
        ip.*,
        (SELECT COUNT(*) FROM project_tasks WHERE internal_project_id = ip.internal_project_id) as total_tasks,
        (SELECT COUNT(*) FROM project_tasks WHERE internal_project_id = ip.internal_project_id AND status = 'completed') as completed_tasks,
        (SELECT COUNT(*) FROM project_milestones WHERE internal_project_id = ip.internal_project_id AND is_completed = 1) as completed_milestones,
        (SELECT COUNT(*) FROM project_milestones WHERE internal_project_id = ip.internal_project_id) as total_milestones
    FROM internal_projects ip
    INNER JOIN project_team_assignments pta ON ip.internal_project_id = pta.internal_project_id
    WHERE pta.team_id = ?
    ORDER BY 
        CASE ip.status
            WHEN 'active' THEN 1
            WHEN 'planned' THEN 2
            WHEN 'on_hold' THEN 3
            WHEN 'completed' THEN 4
            ELSE 5
        END
");
$stmt->execute([$team_id]);
$projects = $stmt->fetchAll();

// Get team tasks
$stmt = $db->prepare("
    SELECT 
        pt.*,
        ip.project_name,
        ip.project_code,
        CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name
    FROM project_tasks pt
    INNER JOIN internal_projects ip ON pt.internal_project_id = ip.internal_project_id
    INNER JOIN project_team_assignments pta ON ip.internal_project_id = pta.internal_project_id
    LEFT JOIN users u ON pt.assigned_to = u.user_id
    WHERE pta.team_id = ?
    ORDER BY 
        CASE pt.status
            WHEN 'pending' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'review' THEN 3
            WHEN 'completed' THEN 4
            ELSE 5
        END,
        pt.due_date ASC
    LIMIT 20
");
$stmt->execute([$team_id]);
$tasks = $stmt->fetchAll();

// Get team statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT ip.internal_project_id) as total_projects,
        SUM(CASE WHEN ip.status = 'active' THEN 1 ELSE 0 END) as active_projects,
        SUM(CASE WHEN ip.status = 'completed' THEN 1 ELSE 0 END) as completed_projects,
        COUNT(DISTINCT pt.task_id) as total_tasks,
        SUM(CASE WHEN pt.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN pt.due_date < CURDATE() AND pt.status != 'completed' THEN 1 ELSE 0 END) as overdue_tasks,
        AVG(CASE WHEN pt.status = 'completed' THEN pt.actual_hours ELSE NULL END) as avg_completion_hours
    FROM teams t
    LEFT JOIN project_team_assignments pta ON t.team_id = pta.team_id
    LEFT JOIN internal_projects ip ON pta.internal_project_id = ip.internal_project_id
    LEFT JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
    WHERE t.team_id = ?
");
$stmt->execute([$team_id]);
$stats = $stmt->fetch();

// Get recent activity
$stmt = $db->prepare("
    SELECT 
        al.*,
        CONCAT(u.first_name, ' ', u.last_name) as user_name,
        u.profile_image as user_image
    FROM activity_logs al
    INNER JOIN users u ON al.user_id = u.user_id
    WHERE u.user_id IN (
        SELECT user_id FROM user_teams WHERE team_id = ?
    )
    ORDER BY al.created_at DESC
    LIMIT 15
");
$stmt->execute([$team_id]);
$activities = $stmt->fetchAll();

// Get upcoming milestones
$stmt = $db->prepare("
    SELECT 
        pm.*,
        ip.project_name,
        ip.project_code
    FROM project_milestones pm
    INNER JOIN internal_projects ip ON pm.internal_project_id = ip.internal_project_id
    INNER JOIN project_team_assignments pta ON ip.internal_project_id = pta.internal_project_id
    WHERE pta.team_id = ? AND pm.due_date >= CURDATE() AND pm.is_completed = 0
    ORDER BY pm.due_date ASC
    LIMIT 10
");
$stmt->execute([$team_id]);
$milestones = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($team['team_name']); ?> | Team Details</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Team Details Specific Styles */
        .team-details {
            animation: fadeIn 0.5s ease-out;
        }

        /* Header */
        .team-header {
            background: linear-gradient(135deg, #000, #333);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .team-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .team-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .team-title h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
        }

        .team-department {
            display: inline-block;
            padding: 0.35rem 1.25rem;
            background: rgba(255,255,255,0.2);
            border-radius: 25px;
            font-size: 0.875rem;
        }

        .team-status {
            padding: 0.5rem 1.5rem;
            background: rgba(255,255,255,0.1);
            border-radius: 25px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .team-status.active {
            background: rgba(0, 200, 83, 0.2);
        }

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
            border-color: #000;
        }

        .info-card-title {
            font-size: 0.875rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .info-card-value {
            font-size: 2rem;
            font-weight: 700;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .info-card-sub {
            font-size: 0.75rem;
            color: #999;
        }

        /* Tabs */
        .details-tabs {
            background: white;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .tab-nav {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            background: #fafafa;
            overflow-x: auto;
        }

        .tab-nav-item {
            padding: 1rem 2rem;
            font-size: 0.95rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }

        .tab-nav-item:hover {
            color: #000;
            background: rgba(0,0,0,0.02);
        }

        .tab-nav-item.active {
            color: #000;
            border-bottom-color: #000;
            background: white;
        }

        .tab-content {
            padding: 2rem;
        }

        .tab-pane {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }

        .tab-pane.active {
            display: block;
        }

        /* Members Grid */
        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }

        .member-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #fafafa;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .member-card:hover {
            background: white;
            transform: translateY(-3px) scale(1.02);
            border-color: #000;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .member-avatar {
            width: 50px;
            height: 50px;
            border-radius: 25px;
            background: #000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.125rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .member-card:hover .member-avatar {
            transform: scale(1.1) rotate(5deg);
            background: #333;
        }

        .member-info {
            flex: 1;
        }

        .member-name {
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .member-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            background: rgba(255, 215, 0, 0.1);
            color: #b8860b;
        }

        .member-position {
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 0.25rem;
        }

        .member-status {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 4px;
            margin-right: 0.5rem;
        }

        .member-status.online {
            background: #00c853;
            box-shadow: 0 0 10px #00c853;
        }

        .member-status.offline {
            background: #999;
        }

        /* Projects List */
        .projects-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .project-item {
            background: #fafafa;
            border-radius: 12px;
            padding: 1rem;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .project-item:hover {
            background: white;
            transform: translateX(5px);
            border-color: #000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .project-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #000;
        }

        .project-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .project-status.active {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .project-status.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .project-progress {
            margin-top: 0.5rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: #666;
            margin-bottom: 0.25rem;
        }

        .progress-bar-container {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #000, #333);
            border-radius: 3px;
            transition: width 1s ease;
        }

        /* Tasks Table */
        .tasks-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tasks-table th {
            text-align: left;
            padding: 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #666;
            border-bottom: 2px solid #e0e0e0;
        }

        .tasks-table td {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .tasks-table tr {
            transition: all 0.3s ease;
        }

        .tasks-table tr:hover {
            background: #fafafa;
            transform: scale(1.01);
        }

        .task-priority {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 5px;
            margin-right: 0.5rem;
        }

        .task-priority.high {
            background: #f44336;
        }

        .task-priority.medium {
            background: #ff9800;
        }

        .task-priority.low {
            background: #2196f3;
        }

        /* Milestones List */
        .milestones-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .milestone-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: #fafafa;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .milestone-item:hover {
            background: white;
            transform: translateX(5px);
            border-color: #000;
        }

        .milestone-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: #ff9800;
        }

        .milestone-content {
            flex: 1;
        }

        .milestone-name {
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .milestone-meta {
            font-size: 0.75rem;
            color: #666;
        }

        .milestone-date {
            padding: 0.25rem 1rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .milestone-date.urgent {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
        }

        /* Activity Feed */
        .activity-feed {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            background: #fafafa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background: white;
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .activity-avatar {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            background: #000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 600;
        }

        .activity-content {
            flex: 1;
        }

        .activity-user {
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .activity-description {
            font-size: 0.875rem;
            color: #444;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #999;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn-action {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        .btn-action.primary {
            background: #000;
            color: white;
        }

        .btn-action.primary:hover {
            background: #333;
        }

        .btn-action.secondary {
            background: #f0f0f0;
            color: #000;
        }

        .btn-action.secondary:hover {
            background: #e0e0e0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .team-title h1 {
                font-size: 2rem;
            }
            
            .members-grid {
                grid-template-columns: 1fr;
            }
            
            .tab-nav-item {
                padding: 0.75rem 1rem;
            }
            
            .tab-content {
                padding: 1rem;
            }
            
            .tasks-table {
                display: block;
                overflow-x: auto;
            }
        }

        @media (max-width: 480px) {
            .team-header-content {
                flex-direction: column;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dev-header.php'; ?>
    
    <div class="admin-container">
        <?php include '../../includes/dev-sidebar.php'; ?>
        
        <main class="admin-main">
            <div class="team-details">
                <!-- Team Header -->
                <div class="team-header">
                    <div class="team-header-content">
                        <div class="team-title">
                            <h1><?php echo e($team['team_name']); ?></h1>
                            <span class="team-department">
                                <?php echo ucfirst(str_replace('_', ' ', $team['department'])); ?>
                            </span>
                        </div>
                        <div class="team-status <?php echo $team['status']; ?>">
                            <i class="fas fa-circle"></i> <?php echo ucfirst($team['status']); ?>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn-action primary" onclick="messageTeam()">
                            <i class="fas fa-comments"></i> Message Team
                        </button>
                        <button class="btn-action secondary" onclick="scheduleMeeting()">
                            <i class="fas fa-calendar-plus"></i> Schedule Meeting
                        </button>
                        <button class="btn-action secondary" onclick="viewTeamFiles()">
                            <i class="fas fa-folder"></i> Team Files
                        </button>
                    </div>
                </div>

                <!-- Info Cards -->
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-card-title">Team Members</div>
                        <div class="info-card-value"><?php echo count($members); ?></div>
                        <div class="info-card-sub">
                            <?php 
                            $leaders = array_filter($members, fn($m) => $m['is_leader']);
                            echo count($leaders) . ' team lead' . (count($leaders) > 1 ? 's' : '');
                            ?>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-card-title">Active Projects</div>
                        <div class="info-card-value"><?php echo $stats['active_projects'] ?? 0; ?></div>
                        <div class="info-card-sub">
                            <?php echo $stats['completed_projects'] ?? 0; ?> completed
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-card-title">Task Completion</div>
                        <div class="info-card-value">
                            <?php 
                            $completion_rate = ($stats['total_tasks'] ?? 0) > 0 
                                ? round((($stats['completed_tasks'] ?? 0) / ($stats['total_tasks'] ?? 1)) * 100) 
                                : 0;
                            echo $completion_rate; ?>%
                        </div>
                        <div class="info-card-sub">
                            <?php echo $stats['completed_tasks'] ?? 0; ?>/<?php echo $stats['total_tasks'] ?? 0; ?> tasks
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-card-title">Overdue Tasks</div>
                        <div class="info-card-value" style="color: <?php echo ($stats['overdue_tasks'] ?? 0) > 0 ? '#f44336' : '#000'; ?>">
                            <?php echo $stats['overdue_tasks'] ?? 0; ?>
                        </div>
                        <div class="info-card-sub">
                            Avg. completion: <?php echo round($stats['avg_completion_hours'] ?? 0); ?> hrs
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="details-tabs">
                    <div class="tab-nav">
                        <div class="tab-nav-item active" data-tab="members">
                            <i class="fas fa-users"></i> Members
                        </div>
                        <div class="tab-nav-item" data-tab="projects">
                            <i class="fas fa-project-diagram"></i> Projects
                        </div>
                        <div class="tab-nav-item" data-tab="tasks">
                            <i class="fas fa-tasks"></i> Tasks
                        </div>
                        <div class="tab-nav-item" data-tab="milestones">
                            <i class="fas fa-flag"></i> Milestones
                        </div>
                        <div class="tab-nav-item" data-tab="activity">
                            <i class="fas fa-history"></i> Activity
                        </div>
                    </div>
                    
                    <div class="tab-content">
                        <!-- Members Tab -->
                        <div class="tab-pane active" id="members">
                            <div class="members-grid">
                                <?php foreach ($members as $member): ?>
                                    <div class="member-card" onclick="viewMember(<?php echo $member['user_id']; ?>)">
                                        <div class="member-avatar">
                                            <?php 
                                            if ($member['profile_image']) {
                                                echo '<img src="' . e($member['profile_image']) . '" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">';
                                            } else {
                                                echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
                                            }
                                            ?>
                                        </div>
                                        <div class="member-info">
                                            <div class="member-name">
                                                <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>
                                                <?php if ($member['is_leader']): ?>
                                                    <span class="member-badge">Lead</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="member-position"><?php echo e($member['position'] ?? 'Team Member'); ?></div>
                                            <div>
                                                <span class="member-status <?php echo $member['last_login'] && (time() - strtotime($member['last_login']) < 300) ? 'online' : 'offline'; ?>"></span>
                                                <span style="font-size: 0.7rem; color: #666;">
                                                    <?php echo $member['active_tasks'] ?? 0; ?> active tasks
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Projects Tab -->
                        <div class="tab-pane" id="projects">
                            <?php if (!empty($projects)): ?>
                                <div class="projects-list">
                                    <?php foreach ($projects as $project): 
                                        $progress = $project['total_milestones'] > 0 
                                            ? round(($project['completed_milestones'] / $project['total_milestones']) * 100) 
                                            : 0;
                                    ?>
                                        <div class="project-item" onclick="viewProject(<?php echo $project['internal_project_id']; ?>)">
                                            <div class="project-header">
                                                <span class="project-name"><?php echo e($project['project_name']); ?></span>
                                                <span class="project-status <?php echo $project['status']; ?>">
                                                    <?php echo ucfirst($project['status']); ?>
                                                </span>
                                            </div>
                                            <div class="project-progress">
                                                <div class="progress-header">
                                                    <span>Progress</span>
                                                    <span><?php echo $progress; ?>%</span>
                                                </div>
                                                <div class="progress-bar-container">
                                                    <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                                </div>
                                                <div style="margin-top: 0.5rem; font-size: 0.75rem; color: #666;">
                                                    Tasks: <?php echo $project['completed_tasks']; ?>/<?php echo $project['total_tasks']; ?> completed
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-project-diagram"></i>
                                    <p>No projects assigned to this team.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tasks Tab -->
                        <div class="tab-pane" id="tasks">
                            <?php if (!empty($tasks)): ?>
                                <table class="tasks-table">
                                    <thead>
                                        <tr>
                                            <th>Task</th>
                                            <th>Project</th>
                                            <th>Assigned To</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tasks as $task): ?>
                                            <tr onclick="viewTask(<?php echo $task['task_id']; ?>)">
                                                <td>
                                                    <span class="task-priority <?php echo $task['priority']; ?>"></span>
                                                    <?php echo e($task['task_name']); ?>
                                                </td>
                                                <td><?php echo e($task['project_name']); ?></td>
                                                <td><?php echo e($task['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                                <td>
                                                    <?php if ($task['due_date']): ?>
                                                        <span style="color: <?php echo (new DateTime($task['due_date']) < new DateTime() && $task['status'] != 'completed') ? '#f44336' : '#666'; ?>">
                                                            <?php echo formatDate($task['due_date'], 'M d, Y'); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        No deadline
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="task-status <?php echo $task['status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-tasks"></i>
                                    <p>No tasks assigned to this team.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Milestones Tab -->
                        <div class="tab-pane" id="milestones">
                            <?php if (!empty($milestones)): ?>
                                <div class="milestones-list">
                                    <?php foreach ($milestones as $milestone): 
                                        $days_left = (new DateTime())->diff(new DateTime($milestone['due_date']))->days;
                                    ?>
                                        <div class="milestone-item">
                                            <div class="milestone-icon">
                                                <i class="fas fa-flag"></i>
                                            </div>
                                            <div class="milestone-content">
                                                <div class="milestone-name"><?php echo e($milestone['milestone_name']); ?></div>
                                                <div class="milestone-meta">
                                                    <?php echo e($milestone['project_name']); ?> • 
                                                    Due <?php echo formatDate($milestone['due_date'], 'M d, Y'); ?>
                                                </div>
                                            </div>
                                            <div class="milestone-date <?php echo $days_left <= 2 ? 'urgent' : ''; ?>">
                                                <?php echo $days_left; ?> days left
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-flag"></i>
                                    <p>No upcoming milestones.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Activity Tab -->
                        <div class="tab-pane" id="activity">
                            <?php if (!empty($activities)): ?>
                                <div class="activity-feed">
                                    <?php foreach ($activities as $activity): 
                                        $time_diff = time() - strtotime($activity['created_at']);
                                        if ($time_diff < 60) {
                                            $time_ago = 'Just now';
                                        } elseif ($time_diff < 3600) {
                                            $minutes = floor($time_diff / 60);
                                            $time_ago = $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
                                        } elseif ($time_diff < 86400) {
                                            $hours = floor($time_diff / 3600);
                                            $time_ago = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
                                        } else {
                                            $days = floor($time_diff / 86400);
                                            $time_ago = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
                                        }
                                    ?>
                                        <div class="activity-item">
                                            <div class="activity-avatar">
                                                <?php 
                                                if ($activity['user_image']) {
                                                    echo '<img src="' . e($activity['user_image']) . '" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">';
                                                } else {
                                                    $name_parts = explode(' ', $activity['user_name']);
                                                    $initials = '';
                                                    foreach ($name_parts as $part) {
                                                        $initials .= strtoupper(substr($part, 0, 1));
                                                    }
                                                    echo $initials;
                                                }
                                                ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-user"><?php echo e($activity['user_name']); ?></div>
                                                <div class="activity-description"><?php echo e($activity['description']); ?></div>
                                                <div class="activity-time"><?php echo $time_ago; ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-history"></i>
                                    <p>No recent activity.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="<?php echo url('assets/js/developer.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabNavItems = document.querySelectorAll('.tab-nav-item');
            const tabPanes = document.querySelectorAll('.tab-pane');
            
            tabNavItems.forEach(item => {
                item.addEventListener('click', function() {
                    const tabId = this.dataset.tab;
                    
                    tabNavItems.forEach(nav => nav.classList.remove('active'));
                    tabPanes.forEach(pane => pane.classList.remove('active'));
                    
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                });
            });
        });

        // View functions
        function viewMember(userId) {
            window.location.href = `member-details.php?id=${userId}`;
        }

        function viewProject(projectId) {
            window.location.href = `../projects/view.php?id=${projectId}`;
        }

        function viewTask(taskId) {
            window.location.href = `../projects/task-details.php?id=${taskId}`;
        }

        function messageTeam() {
            window.location.href = `../messages/team.php?id=<?php echo $team_id; ?>`;
        }

        function scheduleMeeting() {
            window.location.href = `schedule-meeting.php?team_id=<?php echo $team_id; ?>`;
        }

        function viewTeamFiles() {
            window.location.href = `team-files.php?team_id=<?php echo $team_id; ?>`;
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                ${message}
                <button class="alert-close">&times;</button>
            `;
            
            const container = document.querySelector('.flash-messages') || document.createElement('div');
            if (!container.classList.contains('flash-messages')) {
                container.className = 'flash-messages';
                document.querySelector('.admin-main').insertBefore(container, document.querySelector('.team-header'));
            }
            
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
            
            notification.querySelector('.alert-close').addEventListener('click', () => {
                notification.remove();
            });
        }
    </script>
</body>
</html>