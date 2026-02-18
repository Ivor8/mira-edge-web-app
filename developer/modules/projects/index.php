<?php
/**
 * Developer Projects - All Projects
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

// Get filter from URL
$filter = $_GET['filter'] ?? 'all';
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Build query based on filters
$query = "
    SELECT DISTINCT 
        ip.*,
        (SELECT COUNT(*) FROM project_tasks WHERE internal_project_id = ip.internal_project_id) as total_tasks,
        (SELECT COUNT(*) FROM project_tasks WHERE internal_project_id = ip.internal_project_id AND assigned_to = ?) as my_tasks,
        (SELECT COUNT(*) FROM project_tasks WHERE internal_project_id = ip.internal_project_id AND assigned_to = ? AND status = 'completed') as my_completed_tasks,
        (SELECT COUNT(*) FROM project_milestones WHERE internal_project_id = ip.internal_project_id) as total_milestones,
        (SELECT COUNT(*) FROM project_milestones WHERE internal_project_id = ip.internal_project_id AND is_completed = 1) as completed_milestones,
        (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = ip.created_by) as created_by_name,
        GROUP_CONCAT(DISTINCT CONCAT(u2.first_name, ' ', u2.last_name) SEPARATOR ', ') as team_members
    FROM internal_projects ip
    LEFT JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
    LEFT JOIN users u2 ON pt.assigned_to = u2.user_id
    WHERE pt.assigned_to = ? OR ip.created_by = ?
";

$params = [$user_id, $user_id, $user_id, $user_id];

// Apply status filter
if (!empty($status)) {
    $query .= " AND ip.status = ?";
    $params[] = $status;
}

// Apply search filter
if (!empty($search)) {
    $query .= " AND (ip.project_name LIKE ? OR ip.project_code LIKE ? OR ip.description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Apply "my projects" filter
if ($filter === 'my') {
    $query .= " AND ip.created_by = ?";
    $params[] = $user_id;
}

$query .= " GROUP BY ip.internal_project_id ORDER BY 
            CASE ip.status 
                WHEN 'active' THEN 1
                WHEN 'planned' THEN 2
                WHEN 'on_hold' THEN 3
                WHEN 'completed' THEN 4
                ELSE 5
            END,
            ip.deadline ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Get project statistics for this developer
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT ip.internal_project_id) as total_projects,
        SUM(CASE WHEN ip.status = 'active' THEN 1 ELSE 0 END) as active_projects,
        SUM(CASE WHEN ip.status = 'completed' THEN 1 ELSE 0 END) as completed_projects,
        SUM(CASE WHEN ip.status = 'on_hold' THEN 1 ELSE 0 END) as on_hold_projects,
        SUM(CASE WHEN ip.deadline < CURDATE() AND ip.status != 'completed' THEN 1 ELSE 0 END) as overdue_projects
    FROM internal_projects ip
    LEFT JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
    WHERE pt.assigned_to = ? OR ip.created_by = ?
");
$stmt->execute([$user_id, $user_id]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Projects | Developer Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Projects Page Specific Styles */
        .projects-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .projects-title {
            font-size: 2rem;
            font-weight: 700;
            color: #000;
            margin: 0;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .projects-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 3px;
            background: #000;
            transition: width 0.3s ease;
        }

        .projects-title:hover::after {
            width: 100px;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            animation: slideInDown 0.5s ease-out;
        }

        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            background: white;
            color: #666;
        }

        .filter-tab:hover {
            border-color: #000;
            color: #000;
            transform: translateY(-2px);
        }

        .filter-tab.active {
            background: #000;
            color: white;
            border-color: #000;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
            margin-left: auto;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #e0e0e0;
            border-radius: 25px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: #000;
            box-shadow: 0 0 0 3px rgba(0,0,0,0.1);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #000, #333);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .stat-card:hover::before {
            transform: scaleX(1);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            font-size: 0.75rem;
            color: #00c853;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-trend.warning {
            color: #ff9800;
        }

        .stat-trend.danger {
            color: #f44336;
        }

        /* Projects Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            animation: fadeIn 0.5s ease-out;
        }

        .project-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            cursor: pointer;
            animation: slideInUp 0.5s ease-out;
            animation-fill-mode: both;
        }

        .project-card:nth-child(1) { animation-delay: 0.1s; }
        .project-card:nth-child(2) { animation-delay: 0.15s; }
        .project-card:nth-child(3) { animation-delay: 0.2s; }
        .project-card:nth-child(4) { animation-delay: 0.25s; }
        .project-card:nth-child(5) { animation-delay: 0.3s; }
        .project-card:nth-child(6) { animation-delay: 0.35s; }

        .project-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 30px rgba(0,0,0,0.15);
            border-color: #000;
        }

        .project-card::after {
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

        .project-card:hover::after {
            transform: translateX(100%);
        }

        .project-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            background: #fafafa;
        }

        .project-code {
            font-family: monospace;
            font-size: 0.875rem;
            font-weight: 600;
            color: #000;
            background: rgba(0,0,0,0.05);
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
        }

        .project-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .project-status.active {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .project-status.planned {
            background: rgba(156, 39, 176, 0.1);
            color: #9c27b0;
        }

        .project-status.on_hold {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .project-status.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .project-status.cancelled {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
        }

        .project-body {
            padding: 1.5rem;
        }

        .project-name {
            font-size: 1.25rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .project-description {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 1.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #666;
        }

        .meta-item i {
            color: #999;
            transition: transform 0.3s ease;
        }

        .project-card:hover .meta-item i {
            transform: scale(1.2);
        }

        .meta-item .deadline {
            color: #f44336;
            font-weight: 500;
        }

        .meta-item .deadline.warning {
            color: #ff9800;
        }

        .progress-section {
            margin-bottom: 1.5rem;
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

        .task-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: #666;
        }

        .team-members {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e0e0e0;
        }

        .member-avatars {
            display: flex;
            align-items: center;
        }

        .member-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: -10px;
            border: 2px solid white;
            transition: transform 0.3s ease;
        }

        .member-avatar:hover {
            transform: scale(1.2) translateY(-5px);
            z-index: 10;
        }

        .member-count {
            font-size: 0.75rem;
            color: #666;
        }

        .project-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
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
        }

        .btn-icon:hover {
            background: #000;
            color: white;
            border-color: #000;
            transform: scale(1.1) rotate(5deg);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            animation: fadeIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: #000;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: #666;
            margin-bottom: 1.5rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .page-item {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: white;
            border: 1px solid #e0e0e0;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .page-item:hover {
            border-color: #000;
            color: #000;
            transform: translateY(-2px);
        }

        .page-item.active {
            background: #000;
            color: white;
            border-color: #000;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .projects-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                width: 100%;
            }
            
            .project-header {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .project-footer {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .projects-title {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .project-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dev-header.php'; ?>
    
    <div class="admin-container">
        <?php include '../../includes/dev-sidebar.php'; ?>
        
        <main class="admin-main">
            <!-- Page Header -->
            <div class="projects-header">
                <h1 class="projects-title">
                    <i class="fas fa-project-diagram"></i> My Projects
                </h1>
                <div class="header-actions">
                    <a href="<?php echo url('developer/modules/projects/index.php?export'); ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-download"></i> Export
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

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_projects'] ?? 0; ?></div>
                    <div class="stat-label">Total Projects</div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i> All time
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['active_projects'] ?? 0; ?></div>
                    <div class="stat-label">Active Projects</div>
                    <div class="stat-trend">
                        <i class="fas fa-play"></i> In progress
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['completed_projects'] ?? 0; ?></div>
                    <div class="stat-label">Completed</div>
                    <div class="stat-trend">
                        <i class="fas fa-check"></i> Successfully delivered
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value <?php echo ($stats['overdue_projects'] ?? 0) > 0 ? 'danger' : ''; ?>">
                        <?php echo $stats['overdue_projects'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Overdue</div>
                    <?php if (($stats['overdue_projects'] ?? 0) > 0): ?>
                        <div class="stat-trend danger">
                            <i class="fas fa-exclamation-triangle"></i> Needs attention
                        </div>
                    <?php else: ?>
                        <div class="stat-trend">
                            <i class="fas fa-check-circle"></i> On track
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-tabs">
                    <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">All Projects</a>
                    <a href="?filter=my" class="filter-tab <?php echo $filter === 'my' ? 'active' : ''; ?>">Created by Me</a>
                    <a href="?status=active" class="filter-tab <?php echo $status === 'active' ? 'active' : ''; ?>">Active</a>
                    <a href="?status=completed" class="filter-tab <?php echo $status === 'completed' ? 'active' : ''; ?>">Completed</a>
                    <a href="?status=on_hold" class="filter-tab <?php echo $status === 'on_hold' ? 'active' : ''; ?>">On Hold</a>
                </div>
                
                <form method="GET" class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" 
                           name="search" 
                           placeholder="Search projects..." 
                           value="<?php echo e($search); ?>"
                           autocomplete="off">
                </form>
            </div>

            <!-- Projects Grid -->
            <?php if (!empty($projects)): ?>
                <div class="projects-grid">
                    <?php foreach ($projects as $project): 
                        $project_completion = $project['total_milestones'] > 0 
                            ? round(($project['completed_milestones'] / $project['total_milestones']) * 100) 
                            : 0;
                        
                        $my_task_completion = $project['my_tasks'] > 0 
                            ? round(($project['my_completed_tasks'] / $project['my_tasks']) * 100) 
                            : 0;
                        
                        $deadline_class = '';
                        if ($project['deadline'] && $project['status'] != 'completed') {
                            $deadline = new DateTime($project['deadline']);
                            $today = new DateTime();
                            $diff = $today->diff($deadline)->days;
                            if ($deadline < $today) {
                                $deadline_class = 'deadline';
                            } elseif ($diff <= 3) {
                                $deadline_class = 'warning';
                            }
                        }
                    ?>
                        <div class="project-card" onclick="location.href='view.php?id=<?php echo $project['internal_project_id']; ?>'">
                            <div class="project-header">
                                <span class="project-code"><?php echo e($project['project_code']); ?></span>
                                <span class="project-status <?php echo $project['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                                </span>
                            </div>
                            
                            <div class="project-body">
                                <h3 class="project-name"><?php echo e($project['project_name']); ?></h3>
                                <p class="project-description"><?php echo e(substr($project['description'] ?? 'No description', 0, 100)); ?><?php echo strlen($project['description'] ?? '') > 100 ? '...' : ''; ?></p>
                                
                                <div class="project-meta">
                                    <div class="meta-item">
                                        <i class="fas fa-calendar"></i>
                                        <span class="<?php echo $deadline_class; ?>">
                                            <?php echo $project['deadline'] ? formatDate($project['deadline'], 'M d, Y') : 'No deadline'; ?>
                                        </span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-tasks"></i>
                                        <span><?php echo $project['my_tasks']; ?>/<?php echo $project['total_tasks']; ?> tasks</span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-flag"></i>
                                        <span><?php echo $project['completed_milestones']; ?>/<?php echo $project['total_milestones']; ?> milestones</span>
                                    </div>
                                </div>
                                
                                <div class="progress-section">
                                    <div class="progress-header">
                                        <span>Overall Progress</span>
                                        <span><?php echo $project_completion; ?>%</span>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" style="width: <?php echo $project_completion; ?>%"></div>
                                    </div>
                                    <div class="task-stats">
                                        <span>Your tasks: <?php echo $my_task_completion; ?>% complete</span>
                                        <?php if ($project['priority']): ?>
                                            <span class="priority-<?php echo $project['priority']; ?>">
                                                <i class="fas fa-flag"></i> <?php echo ucfirst($project['priority']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($project['team_members'])): ?>
                                    <div class="team-members">
                                        <div class="member-avatars">
                                            <?php 
                                            $members = explode(', ', $project['team_members']);
                                            $display_count = min(3, count($members));
                                            for ($i = 0; $i < $display_count; $i++):
                                                $initials = '';
                                                $name_parts = explode(' ', $members[$i]);
                                                foreach ($name_parts as $part) {
                                                    $initials .= strtoupper(substr($part, 0, 1));
                                                }
                                            ?>
                                                <div class="member-avatar" title="<?php echo e($members[$i]); ?>">
                                                    <?php echo $initials; ?>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                        <?php if (count($members) > 3): ?>
                                            <span class="member-count">+<?php echo count($members) - 3; ?> more</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="project-footer">
                                <button class="btn-icon" onclick="event.stopPropagation(); location.href='tasks.php?project_id=<?php echo $project['internal_project_id']; ?>'" title="View Tasks">
                                    <i class="fas fa-tasks"></i>
                                </button>
                                <button class="btn-icon" onclick="event.stopPropagation(); location.href='milestones.php?project_id=<?php echo $project['internal_project_id']; ?>'" title="View Milestones">
                                    <i class="fas fa-flag"></i>
                                </button>
                                <button class="btn-icon" onclick="event.stopPropagation(); location.href='files.php?project_id=<?php echo $project['internal_project_id']; ?>'" title="View Files">
                                    <i class="fas fa-file"></i>
                                </button>
                                <button class="btn-icon" onclick="event.stopPropagation(); location.href='activity.php?project_id=<?php echo $project['internal_project_id']; ?>'" title="View Activity">
                                    <i class="fas fa-history"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if (count($projects) > 12): ?>
                    <div class="pagination">
                        <div class="page-item"><i class="fas fa-chevron-left"></i></div>
                        <div class="page-item active">1</div>
                        <div class="page-item">2</div>
                        <div class="page-item">3</div>
                        <div class="page-item">...</div>
                        <div class="page-item">8</div>
                        <div class="page-item"><i class="fas fa-chevron-right"></i></div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-project-diagram"></i>
                    <h3>No Projects Found</h3>
                    <p>You haven't been assigned to any projects yet, or no projects match your search criteria.</p>
                    <?php if (!empty($search) || !empty($status)): ?>
                        <a href="index.php" class="btn btn-primary">Clear Filters</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="<?php echo url('assets/js/developer.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-submit search on input (with debounce)
            let searchTimeout;
            const searchInput = document.querySelector('.search-box input');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        this.form.submit();
                    }, 500);
                });
            }

            // Alert close buttons
            document.querySelectorAll('.alert-close').forEach(button => {
                button.addEventListener('click', function() {
                    this.parentElement.style.opacity = '0';
                    setTimeout(() => {
                        this.parentElement.remove();
                    }, 300);
                });
            });

            // Add hover animations
            document.querySelectorAll('.project-card').forEach(card => {
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

        // Function to show notification
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
                document.querySelector('.admin-main').insertBefore(container, document.querySelector('.stats-grid'));
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