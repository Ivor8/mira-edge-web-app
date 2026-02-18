<?php
/**
 * Developer Dashboard
 */

require_once '../includes/core/Database.php';
require_once '../includes/core/Session.php';
require_once '../includes/core/Auth.php';
require_once '../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check if logged in and is developer
if (!$session->isLoggedIn()) {
    redirect('/login.php');
}

if (!$session->isDeveloper()) {
    $session->setFlash('error', 'Access denied. Developer privileges required.');
    redirect('/');
}

$user = $session->getUser();
$user_id = $user['user_id'];

// Get developer's dashboard statistics
try {
    // 1. Get assigned projects
    $stmt = $db->prepare("
        SELECT DISTINCT 
            ip.*,
            (SELECT COUNT(*) FROM project_tasks WHERE internal_project_id = ip.internal_project_id) as total_tasks,
            (SELECT COUNT(*) FROM project_tasks WHERE internal_project_id = ip.internal_project_id AND assigned_to = ?) as my_tasks,
            (SELECT COUNT(*) FROM project_milestones WHERE internal_project_id = ip.internal_project_id AND is_completed = 1) as completed_milestones,
            (SELECT COUNT(*) FROM project_milestones WHERE internal_project_id = ip.internal_project_id) as total_milestones
        FROM internal_projects ip
        LEFT JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
        WHERE pt.assigned_to = ?
        GROUP BY ip.internal_project_id
        ORDER BY 
            CASE ip.status 
                WHEN 'active' THEN 1
                WHEN 'planned' THEN 2
                WHEN 'on_hold' THEN 3
                ELSE 4
            END,
            ip.deadline ASC
        LIMIT 5
    ");
    $stmt->execute([$user_id, $user_id]);
    $my_projects = $stmt->fetchAll();

    // 2. Get assigned tasks
    $stmt = $db->prepare("
        SELECT 
            pt.*,
            ip.project_name,
            ip.project_code,
            CONCAT(u.first_name, ' ', u.last_name) as assigned_by_name
        FROM project_tasks pt
        INNER JOIN internal_projects ip ON pt.internal_project_id = ip.internal_project_id
        LEFT JOIN users u ON pt.assigned_by = u.user_id
        WHERE pt.assigned_to = ?
        ORDER BY 
            CASE pt.status
                WHEN 'pending' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'review' THEN 3
                ELSE 4
            END,
            pt.due_date ASC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $my_tasks = $stmt->fetchAll();

    // 3. Get task statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_tasks,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
            SUM(CASE WHEN status = 'review' THEN 1 ELSE 0 END) as review_tasks,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN DATE(due_date) = CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as due_today,
            SUM(CASE WHEN due_date < CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as overdue_tasks
        FROM project_tasks
        WHERE assigned_to = ?
    ");
    $stmt->execute([$user_id]);
    $task_stats = $stmt->fetch();

    // 4. Get project statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT ip.internal_project_id) as total_projects,
            SUM(CASE WHEN ip.status = 'active' THEN 1 ELSE 0 END) as active_projects,
            SUM(CASE WHEN ip.status = 'completed' THEN 1 ELSE 0 END) as completed_projects,
            SUM(CASE WHEN ip.deadline < CURDATE() AND ip.status != 'completed' THEN 1 ELSE 0 END) as overdue_projects
        FROM internal_projects ip
        INNER JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
        WHERE pt.assigned_to = ?
    ");
    $stmt->execute([$user_id]);
    $project_stats = $stmt->fetch();

    // 5. Get recent activity (notifications)
    $stmt = $db->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_activity = $stmt->fetchAll();

    // 6. Get team information
    $stmt = $db->prepare("
        SELECT 
            t.*,
            COUNT(DISTINCT ut.user_id) as member_count,
            CONCAT(u.first_name, ' ', u.last_name) as leader_name
        FROM teams t
        INNER JOIN user_teams ut ON t.team_id = ut.team_id
        LEFT JOIN users u ON t.team_leader_id = u.user_id
        WHERE ut.user_id = ?
        GROUP BY t.team_id
    ");
    $stmt->execute([$user_id]);
    $my_teams = $stmt->fetchAll();

    // 7. Get upcoming milestones
    $stmt = $db->prepare("
        SELECT 
            pm.*,
            ip.project_name,
            ip.project_code
        FROM project_milestones pm
        INNER JOIN internal_projects ip ON pm.internal_project_id = ip.internal_project_id
        INNER JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
        WHERE pt.assigned_to = ? 
            AND pm.due_date >= CURDATE() 
            AND pm.is_completed = 0
        GROUP BY pm.milestone_id
        ORDER BY pm.due_date ASC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $upcoming_milestones = $stmt->fetchAll();

    // 8. Get performance metrics
    $stmt = $db->prepare("
        SELECT 
            AVG(CASE WHEN status = 'completed' THEN actual_hours ELSE NULL END) as avg_completion_time,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as tasks_completed_this_month,
            COUNT(*) as total_tasks_this_month
        FROM project_tasks
        WHERE assigned_to = ? 
            AND MONTH(created_at) = MONTH(CURDATE())
            AND YEAR(created_at) = YEAR(CURDATE())
    ");
    $stmt->execute([$user_id]);
    $performance = $stmt->fetch();

    // 9. Get daily progress
    $stmt = $db->prepare("
        SELECT 
            DAYOFWEEK(created_at) as day,
            COUNT(*) as task_count
        FROM project_tasks
        WHERE assigned_to = ?
            AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DAYOFWEEK(created_at)
        ORDER BY day
    ");
    $stmt->execute([$user_id]);
    $weekly_progress = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Developer Dashboard Error: " . $e->getMessage());
    $session->setFlash('error', 'Error loading dashboard data');
}

// Get greeting based on time
$hour = date('H');
if ($hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour < 17) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}

// Calculate task completion percentage
$total_tasks = $task_stats['total_tasks'] ?? 0;
$completed_tasks = $task_stats['completed_tasks'] ?? 0;
$completion_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

// Check for overdue tasks warning
$overdue_tasks = $task_stats['overdue_tasks'] ?? 0;
$due_today = $task_stats['due_today'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Dashboard | Mira Edge Technologies</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Developer Dashboard Specific Styles */
        :root {
            --developer-primary: #000000;
            --developer-secondary: #333333;
            --developer-accent: #666666;
            --developer-success: #00c853;
            --developer-warning: #ff9800;
            --developer-danger: #f44336;
            --developer-info: #2196f3;
        }

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, #ffffff 0%, #f5f5f5 100%);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid #e0e0e0;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            animation: slideInDown 0.6s ease-out;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(0,0,0,0.02) 0%, transparent 70%);
            animation: rotate 30s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .welcome-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .welcome-text h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #000000 0%, #444444 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: fadeIn 1s ease-out;
        }

        .welcome-text p {
            font-size: 1.1rem;
            color: #666666;
            margin: 0;
        }

        .date-badge {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .date-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .date-badge i {
            font-size: 2rem;
            color: #000000;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .date-badge .date-info {
            text-align: right;
        }

        .date-badge .day {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: #000000;
        }

        .date-badge .month-year {
            font-size: 0.875rem;
            color: #666666;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card::after {
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

        .stat-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
            border-color: #000000;
        }

        .stat-card:hover::after {
            transform: translateX(100%);
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-icon.tasks {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .stat-icon.projects {
            background: rgba(0, 0, 0, 0.05);
            color: #000000;
        }

        .stat-icon.milestones {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .stat-icon.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
            color: #000000;
            animation: countUp 2s ease-out;
        }

        @keyframes countUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-label {
            font-size: 0.875rem;
            color: #666666;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.875rem;
        }

        .stat-trend.positive {
            color: #00c853;
        }

        .stat-trend.warning {
            color: #ff9800;
        }

        .stat-trend.danger {
            color: #f44336;
        }

        /* Progress Bar */
        .task-progress {
            margin-top: 1rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: #666666;
        }

        .progress-bar-container {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #000000, #333333);
            border-radius: 4px;
            transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
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

        /* Alert Badges */
        .alert-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 1rem;
            animation: slideInRight 0.5s ease-out;
        }

        .alert-badge.warning {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
            border: 1px solid rgba(255, 152, 0, 0.3);
        }

        .alert-badge.danger {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        .alert-badge i {
            margin-right: 0.5rem;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            overflow: hidden;
            transition: all 0.3s ease;
            animation: slideInUp 0.6s ease-out;
        }

        .card:hover {
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            background: #fafafa;
        }

        .card-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #000000;
        }

        .card-title i {
            color: #000000;
            transition: transform 0.3s ease;
        }

        .card:hover .card-title i {
            transform: rotate(5deg) scale(1.1);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Tasks List */
        .tasks-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .task-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: #fafafa;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            animation: slideInLeft 0.5s ease-out;
            animation-fill-mode: both;
        }

        .task-item:nth-child(1) { animation-delay: 0.1s; }
        .task-item:nth-child(2) { animation-delay: 0.2s; }
        .task-item:nth-child(3) { animation-delay: 0.3s; }
        .task-item:nth-child(4) { animation-delay: 0.4s; }
        .task-item:nth-child(5) { animation-delay: 0.5s; }

        .task-item:hover {
            transform: translateX(5px) scale(1.02);
            background: white;
            border-color: #000000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .task-priority-indicator {
            width: 4px;
            height: 40px;
            border-radius: 2px;
            margin-right: 1rem;
        }

        .task-priority-indicator.high {
            background: #f44336;
            box-shadow: 0 0 10px rgba(244, 67, 54, 0.5);
        }

        .task-priority-indicator.medium {
            background: #ff9800;
            box-shadow: 0 0 10px rgba(255, 152, 0, 0.5);
        }

        .task-priority-indicator.low {
            background: #2196f3;
            box-shadow: 0 0 10px rgba(33, 150, 243, 0.5);
        }

        .task-content {
            flex: 1;
        }

        .task-title {
            font-weight: 600;
            color: #000000;
            margin-bottom: 0.25rem;
        }

        .task-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: #666666;
        }

        .task-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .task-status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .task-status-badge.pending {
            background: #e0e0e0;
            color: #666666;
        }

        .task-status-badge.in_progress {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .task-status-badge.review {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .task-status-badge.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .task-actions {
            display: flex;
            gap: 0.5rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .task-item:hover .task-actions {
            opacity: 1;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            color: #666666;
            border: 1px solid #e0e0e0;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-icon:hover {
            background: #000000;
            color: white;
            border-color: #000000;
            transform: scale(1.1) rotate(5deg);
        }

        /* Projects List */
        .projects-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .project-item {
            padding: 1rem;
            background: #fafafa;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .project-item:hover {
            transform: translateY(-2px);
            background: white;
            border-color: #000000;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .project-code {
            font-family: monospace;
            font-size: 0.875rem;
            font-weight: 600;
            color: #000000;
            background: rgba(0,0,0,0.05);
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
        }

        .project-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .project-status.active {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .project-status.on_hold {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .project-status.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .project-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #000000;
        }

        .project-progress {
            margin: 0.75rem 0;
        }

        .progress-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 0.25rem;
            font-size: 0.75rem;
            color: #666666;
        }

        /* Milestones List */
        .milestones-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .milestone-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: #fafafa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .milestone-item:hover {
            background: white;
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .milestone-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ff9800;
            transition: all 0.3s ease;
        }

        .milestone-item:hover .milestone-icon {
            transform: rotate(15deg) scale(1.1);
            background: #ff9800;
            color: white;
        }

        .milestone-info {
            flex: 1;
        }

        .milestone-name {
            font-weight: 600;
            color: #000000;
            margin-bottom: 0.25rem;
        }

        .milestone-project {
            font-size: 0.75rem;
            color: #666666;
        }

        .milestone-due {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .milestone-due.urgent {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
        }

        /* Activity Feed */
        .activity-feed {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 0.75rem;
            background: #fafafa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background: white;
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666666;
            transition: all 0.3s ease;
        }

        .activity-item:hover .activity-icon {
            background: #000000;
            color: white;
            transform: rotate(5deg);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: #000000;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.75rem;
            color: #999999;
        }

        /* Team Members */
        .team-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .team-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: #fafafa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .team-item:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .team-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #000000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .team-item:hover .team-avatar {
            transform: scale(1.1) rotate(5deg);
        }

        .team-info {
            flex: 1;
        }

        .team-name {
            font-weight: 600;
            color: #000000;
            margin-bottom: 0.25rem;
        }

        .team-role {
            font-size: 0.75rem;
            color: #666666;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem;
            background: #fafafa;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            cursor: pointer;
            text-align: center;
        }

        .quick-action:hover {
            background: white;
            border-color: #000000;
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .quick-action i {
            font-size: 1.5rem;
            color: #000000;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .quick-action:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        .quick-action span {
            font-size: 0.875rem;
            font-weight: 500;
            color: #000000;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #999999;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state p {
            margin: 0;
            font-size: 0.875rem;
        }

        /* Animations */
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Loading States */
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #fafafa 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .welcome-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .task-item {
                flex-wrap: wrap;
            }
            
            .task-actions {
                opacity: 1;
                margin-top: 0.5rem;
                width: 100%;
                justify-content: flex-end;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .welcome-text h1 {
                font-size: 1.8rem;
            }
            
            .stat-card {
                flex-direction: column;
                text-align: center;
            }
            
            .task-meta {
                flex-wrap: wrap;
            }
        }

        /* Print Styles */
        @media print {
            .admin-header,
            .admin-sidebar,
            .quick-actions,
            .task-actions,
            .btn-icon {
                display: none !important;
            }
            
            .admin-main {
                margin: 0;
                padding: 0;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #000;
            }
        }
    </style>
</head>
<body>
    <!-- Developer Header -->
    <?php include 'includes/dev-header.php'; ?>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <?php include 'includes/dev-sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Welcome Section -->
            <div class="welcome-section">
                <div class="welcome-content">
                    <div class="welcome-text">
                        <h1><?php echo $greeting; ?>, <?php echo e($user['first_name']); ?>! 👋</h1>
                        <p>Here's what's happening with your projects today.</p>
                    </div>
                    <div class="date-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <div class="date-info">
                            <div class="day"><?php echo date('d'); ?></div>
                            <div class="month-year"><?php echo date('M Y'); ?></div>
                        </div>
                    </div>
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

            <!-- Alert Badges -->
            <?php if ($overdue_tasks > 0 || $due_today > 0): ?>
                <div class="alert-badge <?php echo $overdue_tasks > 0 ? 'danger' : 'warning'; ?>">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php if ($overdue_tasks > 0): ?>
                        You have <?php echo $overdue_tasks; ?> overdue task<?php echo $overdue_tasks > 1 ? 's' : ''; ?>!
                    <?php elseif ($due_today > 0): ?>
                        You have <?php echo $due_today; ?> task<?php echo $due_today > 1 ? 's' : ''; ?> due today!
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="stats-grid">
                <!-- Tasks Card -->
                <div class="stat-card" onclick="location.href='tasks.php'">
                    <div class="stat-icon tasks">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo $total_tasks; ?></h3>
                        <p class="stat-label">Total Tasks</p>
                        <div class="stat-trend <?php echo $completion_percentage > 70 ? 'positive' : ($completion_percentage > 40 ? 'warning' : 'danger'); ?>">
                            <i class="fas fa-<?php echo $completion_percentage > 70 ? 'arrow-up' : ($completion_percentage > 40 ? 'minus' : 'arrow-down'); ?>"></i>
                            <span><?php echo $completion_percentage; ?>% completed</span>
                        </div>
                        <div class="task-progress">
                            <div class="progress-header">
                                <span>Progress</span>
                                <span><?php echo $completed_tasks; ?>/<?php echo $total_tasks; ?></span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo $completion_percentage; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Projects Card -->
                <div class="stat-card" onclick="location.href='projects.php'">
                    <div class="stat-icon projects">
                        <i class="fas fa-project-diagram"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo $project_stats['total_projects'] ?? 0; ?></h3>
                        <p class="stat-label">Active Projects</p>
                        <div class="stat-trend positive">
                            <i class="fas fa-check-circle"></i>
                            <span><?php echo $project_stats['completed_projects'] ?? 0; ?> completed</span>
                        </div>
                    </div>
                </div>

                <!-- Milestones Card -->
                <div class="stat-card" onclick="location.href='milestones.php'">
                    <div class="stat-icon milestones">
                        <i class="fas fa-flag-checkered"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo count($upcoming_milestones); ?></h3>
                        <p class="stat-label">Upcoming Milestones</p>
                        <div class="stat-trend warning">
                            <i class="fas fa-clock"></i>
                            <span>Next 7 days</span>
                        </div>
                    </div>
                </div>

                <!-- Performance Card -->
                <div class="stat-card" onclick="location.href='reports/performance.php'">
                    <div class="stat-icon completed">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo $performance['tasks_completed_this_month'] ?? 0; ?></h3>
                        <p class="stat-label">Tasks This Month</p>
                        <div class="stat-trend positive">
                            <i class="fas fa-calendar-check"></i>
                            <span><?php echo round($performance['avg_completion_time'] ?? 0); ?>hrs avg</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="content-column">
                    <!-- My Tasks -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-tasks"></i>
                                My Tasks
                            </h3>
                            <a href="tasks.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($my_tasks)): ?>
                                <div class="tasks-list">
                                    <?php foreach ($my_tasks as $task): ?>
                                        <div class="task-item" data-task-id="<?php echo $task['task_id']; ?>">
                                            <div class="task-priority-indicator <?php echo $task['priority']; ?>"></div>
                                            <div class="task-content">
                                                <div class="task-title"><?php echo e($task['task_name']); ?></div>
                                                <div class="task-meta">
                                                    <span>
                                                        <i class="fas fa-project-diagram"></i>
                                                        <?php echo e($task['project_name']); ?>
                                                    </span>
                                                    <?php if ($task['due_date']): ?>
                                                        <span>
                                                            <i class="fas fa-calendar"></i>
                                                            <?php 
                                                            $due_date = new DateTime($task['due_date']);
                                                            $today = new DateTime();
                                                            $diff = $today->diff($due_date)->days;
                                                            $class = '';
                                                            if ($due_date < $today) {
                                                                $class = 'danger';
                                                            } elseif ($diff <= 2) {
                                                                $class = 'warning';
                                                            }
                                                            ?>
                                                            <span class="text-<?php echo $class; ?>">
                                                                <?php echo formatDate($task['due_date'], 'M d'); ?>
                                                            </span>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="task-status-badge <?php echo $task['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                            </div>
                                            <div class="task-actions">
                                                <button class="btn-icon" onclick="updateTaskStatus(<?php echo $task['task_id']; ?>, 'in_progress')" title="Start Task">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                                <button class="btn-icon" onclick="viewTaskDetails(<?php echo $task['task_id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-tasks"></i>
                                    <p>No tasks assigned yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- My Projects -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-project-diagram"></i>
                                My Projects
                            </h3>
                            <a href="projects.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($my_projects)): ?>
                                <div class="projects-list">
                                    <?php foreach ($my_projects as $project): 
                                        $project_completion = $project['total_milestones'] > 0 
                                            ? round(($project['completed_milestones'] / $project['total_milestones']) * 100) 
                                            : 0;
                                    ?>
                                        <div class="project-item" onclick="location.href='project-details.php?id=<?php echo $project['internal_project_id']; ?>'">
                                            <div class="project-header">
                                                <span class="project-code"><?php echo e($project['project_code']); ?></span>
                                                <span class="project-status <?php echo $project['status']; ?>">
                                                    <?php echo ucfirst($project['status']); ?>
                                                </span>
                                            </div>
                                            <h4 class="project-title"><?php echo e($project['project_name']); ?></h4>
                                            <div class="project-progress">
                                                <div class="progress-bar-container">
                                                    <div class="progress-bar" style="width: <?php echo $project_completion; ?>%"></div>
                                                </div>
                                                <div class="progress-stats">
                                                    <span>Milestones: <?php echo $project['completed_milestones']; ?>/<?php echo $project['total_milestones']; ?></span>
                                                    <span>Your tasks: <?php echo $project['my_tasks']; ?>/<?php echo $project['total_tasks']; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-project-diagram"></i>
                                    <p>No projects assigned yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="content-column">
                    <!-- Upcoming Milestones -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-flag-checkered"></i>
                                Upcoming Milestones
                            </h3>
                            <a href="milestones.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($upcoming_milestones)): ?>
                                <div class="milestones-list">
                                    <?php foreach ($upcoming_milestones as $milestone): 
                                        $days_left = (new DateTime($milestone['due_date']))->diff(new DateTime())->days;
                                        $urgent = $days_left <= 2;
                                    ?>
                                        <div class="milestone-item">
                                            <div class="milestone-icon">
                                                <i class="fas fa-flag"></i>
                                            </div>
                                            <div class="milestone-info">
                                                <div class="milestone-name"><?php echo e($milestone['milestone_name']); ?></div>
                                                <div class="milestone-project"><?php echo e($milestone['project_name']); ?></div>
                                            </div>
                                            <div class="milestone-due <?php echo $urgent ? 'urgent' : ''; ?>">
                                                <?php echo $days_left; ?> days left
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-flag-checkered"></i>
                                    <p>No upcoming milestones</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- My Teams -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-users"></i>
                                My Teams
                            </h3>
                            <a href="teams.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($my_teams)): ?>
                                <div class="team-list">
                                    <?php foreach ($my_teams as $team): ?>
                                        <div class="team-item" onclick="location.href='team-details.php?id=<?php echo $team['team_id']; ?>'">
                                            <div class="team-avatar">
                                                <?php echo strtoupper(substr($team['team_name'], 0, 2)); ?>
                                            </div>
                                            <div class="team-info">
                                                <div class="team-name"><?php echo e($team['team_name']); ?></div>
                                                <div class="team-role">
                                                    <i class="fas fa-users"></i> <?php echo $team['member_count']; ?> members
                                                </div>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: #999;"></i>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>Not assigned to any team</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-history"></i>
                                Recent Activity
                            </h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_activity)): ?>
                                <div class="activity-feed">
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <i class="fas fa-<?php echo match($activity['notification_type']) {
                                                    'task' => 'tasks',
                                                    'project' => 'project-diagram',
                                                    'milestone' => 'flag',
                                                    default => 'bell'
                                                }; ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title"><?php echo e($activity['title']); ?></div>
                                                <div class="activity-time">
                                                    <?php 
                                                    $time = strtotime($activity['created_at']);
                                                    $diff = time() - $time;
                                                    if ($diff < 60) {
                                                        echo 'Just now';
                                                    } elseif ($diff < 3600) {
                                                        echo floor($diff / 60) . ' minutes ago';
                                                    } elseif ($diff < 86400) {
                                                        echo floor($diff / 3600) . ' hours ago';
                                                    } else {
                                                        echo floor($diff / 86400) . ' days ago';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-history"></i>
                                    <p>No recent activity</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-bolt"></i>
                                Quick Actions
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <div class="quick-action" onclick="location.href='tasks.php?action=start'">
                                    <i class="fas fa-play"></i>
                                    <span>Start Task</span>
                                </div>
                                <div class="quick-action" onclick="location.href='time-tracking.php'">
                                    <i class="fas fa-clock"></i>
                                    <span>Log Time</span>
                                </div>
                                <div class="quick-action" onclick="location.href='projects.php'">
                                    <i class="fas fa-chart-bar"></i>
                                    <span>View Progress</span>
                                </div>
                                <div class="quick-action" onclick="location.href='messages.php'">
                                    <i class="fas fa-envelope"></i>
                                    <span>Messages</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/developer.js'); ?>"></script>
    <script>
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

            // Add hover animations to cards
            document.querySelectorAll('.stat-card, .task-item, .project-item, .milestone-item').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = this.classList.contains('stat-card') ? 
                        'translateY(-5px) scale(1.02)' : 
                        this.classList.contains('task-item') ? 
                            'translateX(5px) scale(1.02)' : 
                            'translateY(-2px)';
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                });
            });
        });

        // Task status update function
        function updateTaskStatus(taskId, status) {
            fetch('/api/tasks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    task_id: taskId,
                    action: 'update_status',
                    status: status
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showNotification('Failed to update task status', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        }

        // View task details
        function viewTaskDetails(taskId) {
            window.location.href = 'task-details.php?id=' + taskId;
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