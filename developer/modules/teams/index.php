<?php
/**
 * Developer Teams - My Teams
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

// Get all teams the user belongs to
$stmt = $db->prepare("
    SELECT 
        t.*,
        COUNT(DISTINCT ut.user_id) as member_count,
        CONCAT(u.first_name, ' ', u.last_name) as leader_name,
        u.email as leader_email,
        u.profile_image as leader_image,
        (SELECT COUNT(*) FROM internal_projects ip 
         INNER JOIN project_team_assignments pta ON ip.internal_project_id = pta.internal_project_id 
         WHERE pta.team_id = t.team_id AND ip.status = 'active') as active_projects,
        (SELECT COUNT(*) FROM internal_projects ip 
         INNER JOIN project_team_assignments pta ON ip.internal_project_id = pta.internal_project_id 
         WHERE pta.team_id = t.team_id AND ip.status = 'completed') as completed_projects
    FROM teams t
    INNER JOIN user_teams ut ON t.team_id = ut.team_id
    LEFT JOIN users u ON t.team_leader_id = u.user_id
    WHERE ut.user_id = ? AND ut.is_active = 1
    GROUP BY t.team_id
    ORDER BY t.team_name
");
$stmt->execute([$user_id]);
$teams = $stmt->fetchAll();

// Get team performance metrics
$team_performance = [];
foreach ($teams as $team) {
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT pt.task_id) as total_tasks,
            SUM(CASE WHEN pt.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
            AVG(CASE WHEN pt.status = 'completed' THEN pt.actual_hours ELSE NULL END) as avg_completion_hours,
            SUM(CASE WHEN pt.due_date < CURDATE() AND pt.status != 'completed' THEN 1 ELSE 0 END) as overdue_tasks
        FROM project_tasks pt
        INNER JOIN project_team_assignments pta ON pt.internal_project_id = pta.internal_project_id
        WHERE pta.team_id = ?
    ");
    $stmt->execute([$team['team_id']]);
    $team_performance[$team['team_id']] = $stmt->fetch();
}

// Get team members for each team
$team_members = [];
foreach ($teams as $team) {
    $stmt = $db->prepare("
        SELECT 
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.profile_image,
            u.position,
            u.status,
            ut.joined_at,
            CASE WHEN u.user_id = t.team_leader_id THEN 1 ELSE 0 END as is_leader
        FROM users u
        INNER JOIN user_teams ut ON u.user_id = ut.user_id
        INNER JOIN teams t ON ut.team_id = t.team_id
        WHERE ut.team_id = ? AND ut.is_active = 1
        ORDER BY is_leader DESC, u.first_name
    ");
    $stmt->execute([$team['team_id']]);
    $team_members[$team['team_id']] = $stmt->fetchAll();
}

// Get recent team activities
$stmt = $db->prepare("
    SELECT 
        al.*,
        CONCAT(u.first_name, ' ', u.last_name) as user_name,
        u.profile_image as user_image,
        t.team_name
    FROM activity_logs al
    INNER JOIN users u ON al.user_id = u.user_id
    INNER JOIN user_teams ut ON u.user_id = ut.user_id
    INNER JOIN teams t ON ut.team_id = t.team_id
    WHERE ut.team_id IN (
        SELECT team_id FROM user_teams WHERE user_id = ?
    )
    ORDER BY al.created_at DESC
    LIMIT 20
");
$stmt->execute([$user_id]);
$recent_activities = $stmt->fetchAll();

// Get upcoming team events/milestones
$stmt = $db->prepare("
    SELECT 
        pm.*,
        ip.project_name,
        ip.project_code,
        t.team_name
    FROM project_milestones pm
    INNER JOIN internal_projects ip ON pm.internal_project_id = ip.internal_project_id
    INNER JOIN project_team_assignments pta ON ip.internal_project_id = pta.internal_project_id
    INNER JOIN teams t ON pta.team_id = t.team_id
    WHERE pta.team_id IN (
        SELECT team_id FROM user_teams WHERE user_id = ?
    )
    AND pm.due_date >= CURDATE()
    AND pm.is_completed = 0
    ORDER BY pm.due_date ASC
    LIMIT 10
");
$stmt->execute([$user_id]);
$upcoming_milestones = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Teams | Developer Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Teams Page Specific Styles */
        .teams-page {
            animation: fadeIn 0.5s ease-out;
        }

        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: #000;
            margin: 0;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .page-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 3px;
            background: #000;
            transition: width 0.3s ease;
        }

        .page-title:hover::after {
            width: 100px;
        }

        /* Teams Grid */
        .teams-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .team-card {
            background: white;
            border-radius: 20px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            animation: slideInUp 0.5s ease-out;
            animation-fill-mode: both;
            cursor: pointer;
        }

        .team-card:nth-child(1) { animation-delay: 0.1s; }
        .team-card:nth-child(2) { animation-delay: 0.15s; }
        .team-card:nth-child(3) { animation-delay: 0.2s; }
        .team-card:nth-child(4) { animation-delay: 0.25s; }
        .team-card:nth-child(5) { animation-delay: 0.3s; }

        .team-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 30px rgba(0,0,0,0.15);
            border-color: #000;
        }

        .team-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255,255,255,0.1) 50%, transparent 70%);
            transform: translateX(-100%);
            transition: transform 0.6s;
        }

        .team-card:hover::after {
            transform: translateX(100%);
        }

        .team-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #000, #333);
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

        .team-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .team-department {
            display: inline-block;
            padding: 0.25rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }

        .team-body {
            padding: 1.5rem;
        }

        .team-description {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        .team-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f5f5f5;
            border-radius: 12px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #000;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #666;
            text-transform: uppercase;
        }

        .team-leader {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #fafafa;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .team-leader:hover {
            background: #f0f0f0;
            transform: translateX(5px);
        }

        .leader-avatar {
            width: 50px;
            height: 50px;
            border-radius: 25px;
            background: #000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .team-leader:hover .leader-avatar {
            transform: scale(1.1) rotate(5deg);
            background: #333;
        }

        .leader-info {
            flex: 1;
        }

        .leader-name {
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .leader-title {
            font-size: 0.75rem;
            color: #666;
        }

        /* Members Preview */
        .members-preview {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .member-avatars {
            display: flex;
            align-items: center;
        }

        .member-avatar {
            width: 35px;
            height: 35px;
            border-radius: 17.5px;
            background: #000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: -10px;
            border: 2px solid white;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .member-avatar:hover {
            transform: scale(1.2) translateY(-5px);
            z-index: 10;
            background: #333;
        }

        .member-avatar.more {
            background: #f0f0f0;
            color: #666;
            font-size: 0.7rem;
        }

        .member-count {
            font-size: 0.75rem;
            color: #666;
        }

        /* Progress Bar */
        .team-progress {
            margin-top: 1rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .progress-bar-container {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #000, #333);
            border-radius: 4px;
            transition: width 1s ease;
            position: relative;
            overflow: hidden;
        }

        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .team-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            color: #666;
            border: 1px solid #e0e0e0;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-icon::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }

        .btn-icon:active::after {
            animation: ripple 1s ease-out;
        }

        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 1;
            }
            20% {
                transform: scale(25, 25);
                opacity: 1;
            }
            100% {
                opacity: 0;
                transform: scale(40, 40);
            }
        }

        .btn-icon:hover {
            background: #000;
            color: white;
            border-color: #000;
            transform: scale(1.1) rotate(5deg);
        }

        /* Upcoming Milestones */
        .milestones-section {
            background: white;
            border-radius: 20px;
            border: 1px solid #e0e0e0;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: #000;
            transition: transform 0.3s ease;
        }

        .section-title:hover i {
            transform: rotate(15deg) scale(1.2);
        }

        .milestones-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .milestone-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: #fafafa;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .milestone-item:hover {
            background: white;
            transform: translateX(5px);
            border-color: #000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .milestone-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: #ff9800;
            transition: all 0.3s ease;
        }

        .milestone-item:hover .milestone-icon {
            background: #ff9800;
            color: white;
            transform: rotate(15deg);
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
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: #666;
        }

        .milestone-days {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .milestone-days.urgent {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
        }

        .milestone-days.warning {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .milestone-days.normal {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        /* Recent Activities */
        .activities-section {
            background: white;
            border-radius: 20px;
            border: 1px solid #e0e0e0;
            padding: 1.5rem;
        }

        .activities-list {
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
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background: white;
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: all 0.3s ease;
        }

        .activity-item:hover .activity-icon {
            background: #000;
            color: white;
            transform: rotate(5deg);
        }

        .activity-content {
            flex: 1;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.25rem;
        }

        .activity-user {
            font-weight: 600;
            color: #000;
        }

        .activity-team {
            font-size: 0.7rem;
            color: #666;
            background: #f0f0f0;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
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

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            background: #fafafa;
            border-radius: 16px;
            border: 1px dashed #e0e0e0;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            color: #000;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: #666;
            margin-bottom: 1.5rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: slideInUp 0.3s ease-out;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #000;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            color: #000;
            transform: rotate(90deg);
        }

        /* Members Grid */
        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
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
        }

        .member-card:hover {
            background: white;
            transform: translateY(-3px);
            border-color: #000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .member-avatar-sm {
            width: 45px;
            height: 45px;
            border-radius: 22.5px;
            background: #000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .member-card:hover .member-avatar-sm {
            transform: scale(1.1) rotate(5deg);
            background: #333;
        }

        .member-info-sm {
            flex: 1;
        }

        .member-name-sm {
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .member-position-sm {
            font-size: 0.7rem;
            color: #666;
        }

        .member-leader-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: rgba(255, 215, 0, 0.1);
            color: #b8860b;
            border-radius: 10px;
            font-size: 0.6rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .teams-grid {
                grid-template-columns: 1fr;
            }
            
            .milestones-list {
                grid-template-columns: 1fr;
            }
            
            .members-grid {
                grid-template-columns: 1fr;
            }
            
            .team-stats {
                grid-template-columns: repeat(3, 1fr);
                gap: 0.5rem;
            }
            
            .stat-value {
                font-size: 1.25rem;
            }
            
            .modal-content {
                padding: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .team-stats {
                grid-template-columns: 1fr;
            }
            
            .team-footer {
                flex-direction: column;
                gap: 1rem;
            }
            
            .activity-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dev-header.php'; ?>
    
    <div class="admin-container">
        <?php include '../../includes/dev-sidebar.php'; ?>
        
        <main class="admin-main">
            <div class="teams-page">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-users"></i> My Teams
                    </h1>
                    <div class="header-actions">
                        <button class="btn btn-outline btn-sm" onclick="refreshTeams()">
                            <i class="fas fa-sync-alt"></i> Refresh
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

                <!-- Teams Grid -->
                <?php if (!empty($teams)): ?>
                    <div class="teams-grid">
                        <?php foreach ($teams as $team): 
                            $performance = $team_performance[$team['team_id']] ?? [];
                            $total_tasks = $performance['total_tasks'] ?? 0;
                            $completed_tasks = $performance['completed_tasks'] ?? 0;
                            $completion_rate = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
                            $members = $team_members[$team['team_id']] ?? [];
                        ?>
                            <div class="team-card" onclick="viewTeam(<?php echo $team['team_id']; ?>)">
                                <div class="team-header">
                                    <h3 class="team-name"><?php echo e($team['team_name']); ?></h3>
                                    <span class="team-department">
                                        <?php echo ucfirst(str_replace('_', ' ', $team['department'])); ?>
                                    </span>
                                </div>
                                
                                <div class="team-body">
                                    <p class="team-description">
                                        <?php echo e($team['team_description'] ?? 'No description available.'); ?>
                                    </p>
                                    
                                    <div class="team-stats">
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo count($members); ?></div>
                                            <div class="stat-label">Members</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $team['active_projects'] ?? 0; ?></div>
                                            <div class="stat-label">Active</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $team['completed_projects'] ?? 0; ?></div>
                                            <div class="stat-label">Completed</div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($team['leader_name']): ?>
                                        <div class="team-leader">
                                            <div class="leader-avatar">
                                                <?php 
                                                if ($team['leader_image']) {
                                                    echo '<img src="' . e($team['leader_image']) . '" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">';
                                                } else {
                                                    $initials = '';
                                                    $name_parts = explode(' ', $team['leader_name']);
                                                    foreach ($name_parts as $part) {
                                                        $initials .= strtoupper(substr($part, 0, 1));
                                                    }
                                                    echo $initials;
                                                }
                                                ?>
                                            </div>
                                            <div class="leader-info">
                                                <div class="leader-name"><?php echo e($team['leader_name']); ?></div>
                                                <div class="leader-title">Team Lead</div>
                                            </div>
                                            <i class="fas fa-crown" style="color: #ffd700;"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="members-preview">
                                        <div class="member-avatars">
                                            <?php 
                                            $display_count = min(5, count($members));
                                            for ($i = 0; $i < $display_count; $i++):
                                                $member = $members[$i];
                                                $initials = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
                                            ?>
                                                <div class="member-avatar" 
                                                     title="<?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>"
                                                     onclick="event.stopPropagation(); viewMember(<?php echo $member['user_id']; ?>)">
                                                    <?php echo $initials; ?>
                                                </div>
                                            <?php endfor; ?>
                                            
                                            <?php if (count($members) > 5): ?>
                                                <div class="member-avatar more">
                                                    +<?php echo count($members) - 5; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="member-count"><?php echo count($members); ?> members</span>
                                    </div>
                                    
                                    <?php if ($total_tasks > 0): ?>
                                        <div class="team-progress">
                                            <div class="progress-header">
                                                <span>Task Completion</span>
                                                <span><?php echo $completion_rate; ?>%</span>
                                            </div>
                                            <div class="progress-bar-container">
                                                <div class="progress-bar" style="width: <?php echo $completion_rate; ?>%"></div>
                                            </div>
                                            <?php if (($performance['overdue_tasks'] ?? 0) > 0): ?>
                                                <div style="margin-top: 0.5rem; font-size: 0.7rem; color: #f44336;">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                    <?php echo $performance['overdue_tasks']; ?> overdue tasks
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="team-footer">
                                    <span class="team-status" style="color: <?php echo $team['status'] === 'active' ? '#00c853' : '#999'; ?>">
                                        <i class="fas fa-circle"></i> <?php echo ucfirst($team['status']); ?>
                                    </span>
                                    
                                    <div class="team-actions" onclick="event.stopPropagation();">
                                        <button class="btn-icon" onclick="viewTeamDetails(<?php echo $team['team_id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon" onclick="viewTeamMembers(<?php echo $team['team_id']; ?>)" title="View Members">
                                            <i class="fas fa-users"></i>
                                        </button>
                                        <button class="btn-icon" onclick="messageTeam(<?php echo $team['team_id']; ?>)" title="Message Team">
                                            <i class="fas fa-comments"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Teams Found</h3>
                        <p>You haven't been assigned to any teams yet.</p>
                    </div>
                <?php endif; ?>

                <!-- Upcoming Milestones -->
                <?php if (!empty($upcoming_milestones)): ?>
                    <div class="milestones-section">
                        <h3 class="section-title">
                            <i class="fas fa-flag-checkered"></i>
                            Upcoming Team Milestones
                        </h3>
                        
                        <div class="milestones-list">
                            <?php foreach ($upcoming_milestones as $milestone):
                                $days_left = (new DateTime())->diff(new DateTime($milestone['due_date']))->days;
                                $is_urgent = $days_left <= 2;
                                $is_warning = $days_left <= 5 && $days_left > 2;
                            ?>
                                <div class="milestone-item">
                                    <div class="milestone-icon">
                                        <i class="fas fa-flag"></i>
                                    </div>
                                    <div class="milestone-content">
                                        <div class="milestone-name"><?php echo e($milestone['milestone_name']); ?></div>
                                        <div class="milestone-meta">
                                            <span><?php echo e($milestone['project_name']); ?></span>
                                            <span>•</span>
                                            <span><?php echo e($milestone['team_name']); ?></span>
                                        </div>
                                    </div>
                                    <div class="milestone-days <?php echo $is_urgent ? 'urgent' : ($is_warning ? 'warning' : 'normal'); ?>">
                                        <?php echo $days_left; ?> days left
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Activities -->
                <?php if (!empty($recent_activities)): ?>
                    <div class="activities-section">
                        <h3 class="section-title">
                            <i class="fas fa-history"></i>
                            Recent Team Activities
                        </h3>
                        
                        <div class="activities-list">
                            <?php foreach ($recent_activities as $activity): 
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
                                    <div class="activity-icon">
                                        <i class="fas fa-<?php 
                                            echo match($activity['activity_type']) {
                                                'task' => 'tasks',
                                                'project' => 'project-diagram',
                                                'file' => 'file',
                                                'comment' => 'comment',
                                                default => 'bell'
                                            };
                                        ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-header">
                                            <span class="activity-user"><?php echo e($activity['user_name']); ?></span>
                                            <span class="activity-team"><?php echo e($activity['team_name']); ?></span>
                                        </div>
                                        <div class="activity-description">
                                            <?php echo e($activity['description']); ?>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo $time_ago; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Team Members Modal -->
    <div class="modal" id="membersModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTeamName">Team Members</h3>
                <button class="modal-close" onclick="closeMembersModal()">&times;</button>
            </div>
            <div class="members-grid" id="membersList">
                <!-- Members will be loaded here via JavaScript -->
            </div>
        </div>
    </div>

    <script src="<?php echo url('assets/js/developer.js'); ?>"></script>
    <script>
        // Store team members data
        const teamMembers = <?php echo json_encode($team_members); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Alert close buttons
            document.querySelectorAll('.alert-close').forEach(button => {
                button.addEventListener('click', function() {
                    this.parentElement.style.opacity = '0';
                    setTimeout(() => {
                        this.parentElement.remove();
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
            
            // Add hover animations
            document.querySelectorAll('.team-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    const progressBar = this.querySelector('.progress-bar');
                    if (progressBar) {
                        const width = progressBar.style.width;
                        progressBar.style.width = '0';
                        setTimeout(() => {
                            progressBar.style.width = width;
                        }, 10);
                    }
                });
            });
        });

        // View team details
        function viewTeam(teamId) {
            window.location.href = `team-details.php?id=${teamId}`;
        }

        // View team details (alternative)
        function viewTeamDetails(teamId) {
            window.location.href = `team-details.php?id=${teamId}`;
        }

        // View team members
        function viewTeamMembers(teamId) {
            const members = teamMembers[teamId] || [];
            const teamName = document.querySelector(`.team-card[onclick*="${teamId}"] .team-name`).textContent;
            
            document.getElementById('modalTeamName').textContent = teamName + ' - Members';
            
            let html = '';
            members.forEach(member => {
                const initials = member.first_name.charAt(0) + member.last_name.charAt(0);
                html += `
                    <div class="member-card" onclick="viewMember(${member.user_id})">
                        <div class="member-avatar-sm">
                            ${member.profile_image ? 
                                `<img src="${member.profile_image}" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">` : 
                                initials.toUpperCase()}
                        </div>
                        <div class="member-info-sm">
                            <div class="member-name-sm">${member.first_name} ${member.last_name}</div>
                            <div class="member-position-sm">${member.position || 'Team Member'}</div>
                            ${member.is_leader ? '<div class="member-leader-badge">Team Lead</div>' : ''}
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('membersList').innerHTML = html;
            document.getElementById('membersModal').classList.add('active');
        }

        // View individual member
        function viewMember(userId) {
            window.location.href = `../team/member-details.php?id=${userId}`;
        }

        // Close members modal
        function closeMembersModal() {
            document.getElementById('membersModal').classList.remove('active');
        }

        // Message team
        function messageTeam(teamId) {
            window.location.href = `../messages/team.php?id=${teamId}`;
        }

        // Refresh teams data
        function refreshTeams() {
            location.reload();
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
                document.querySelector('.admin-main').insertBefore(container, document.querySelector('.teams-grid') || document.querySelector('.admin-main').firstChild);
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modal
            if (e.key === 'Escape') {
                closeMembersModal();
            }
        });
    </script>
</body>
</html>