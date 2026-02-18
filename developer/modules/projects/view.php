<?php
/**
 * Developer Project Details View
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
    redirect('/');
}

$user = $session->getUser();
$user_id = $user['user_id'];

// Get project ID from URL
$project_id = $_GET['id'] ?? 0;
if (!$project_id) {
    $session->setFlash('error', 'Invalid project ID.');
    redirect('/developer/modules/projects/index.php');
}

// Verify user has access to this project
$stmt = $db->prepare("
    SELECT COUNT(*) as has_access
    FROM project_tasks 
    WHERE internal_project_id = ? AND assigned_to = ?
    UNION
    SELECT COUNT(*) as has_access
    FROM internal_projects 
    WHERE internal_project_id = ? AND created_by = ?
");
$stmt->execute([$project_id, $user_id, $project_id, $user_id]);
$access = $stmt->fetch();

if (!$access || $access['has_access'] == 0) {
    $session->setFlash('error', 'You do not have access to this project.');
    redirect('/developer/modules/projects/index.php');
}

// Get project details
$stmt = $db->prepare("
    SELECT 
        ip.*,
        CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
        u.email as created_by_email,
        so.client_name,
        so.client_email,
        so.client_phone,
        so.client_company
    FROM internal_projects ip
    LEFT JOIN users u ON ip.created_by = u.user_id
    LEFT JOIN service_orders so ON ip.client_id = so.order_id
    WHERE ip.internal_project_id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    $session->setFlash('error', 'Project not found.');
    redirect('/developer/modules/projects/index.php');
}

// Get project statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
        SUM(CASE WHEN status = 'review' THEN 1 ELSE 0 END) as review_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN assigned_to = ? THEN 1 ELSE 0 END) as my_tasks,
        SUM(CASE WHEN assigned_to = ? AND status = 'completed' THEN 1 ELSE 0 END) as my_completed_tasks
    FROM project_tasks
    WHERE internal_project_id = ?
");
$stmt->execute([$user_id, $user_id, $project_id]);
$task_stats = $stmt->fetch();

// Get milestones
$stmt = $db->prepare("
    SELECT * FROM project_milestones 
    WHERE internal_project_id = ?
    ORDER BY due_date ASC
");
$stmt->execute([$project_id]);
$milestones = $stmt->fetchAll();

// Get team members
$stmt = $db->prepare("
    SELECT DISTINCT 
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.profile_image,
        u.position,
        t.team_name
    FROM users u
    INNER JOIN project_tasks pt ON u.user_id = pt.assigned_to
    LEFT JOIN user_teams ut ON u.user_id = ut.user_id
    LEFT JOIN teams t ON ut.team_id = t.team_id
    WHERE pt.internal_project_id = ?
    ORDER BY u.first_name
");
$stmt->execute([$project_id]);
$team_members = $stmt->fetchAll();

// Get recent activity
$stmt = $db->prepare("
    SELECT 
        al.*,
        CONCAT(u.first_name, ' ', u.last_name) as user_name
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE al.description LIKE ? OR al.description LIKE ?
    ORDER BY al.created_at DESC
    LIMIT 10
");
$search_term = "%project_id=$project_id%";
$stmt->execute([$search_term, $search_term]);
$activities = $stmt->fetchAll();

// Get project files
$stmt = $db->prepare("
    SELECT pf.*, CONCAT(u.first_name, ' ', u.last_name) as uploaded_by_name
    FROM project_files pf
    LEFT JOIN users u ON pf.uploaded_by = u.user_id
    WHERE pf.internal_project_id = ?
    ORDER BY pf.uploaded_at DESC
");
$stmt->execute([$project_id]);
$files = $stmt->fetchAll();

// Calculate progress
$total_tasks = $task_stats['total_tasks'] ?? 0;
$completed_tasks = $task_stats['completed_tasks'] ?? 0;
$completion_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

$my_total = $task_stats['my_tasks'] ?? 0;
$my_completed = $task_stats['my_completed_tasks'] ?? 0;
$my_completion = $my_total > 0 ? round(($my_completed / $my_total) * 100) : 0;

// Handle task status update via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_task_status') {
        $task_id = $_POST['task_id'] ?? 0;
        $status = $_POST['status'] ?? '';
        
        // Verify task belongs to user
        $stmt = $db->prepare("SELECT task_id FROM project_tasks WHERE task_id = ? AND assigned_to = ?");
        $stmt->execute([$task_id, $user_id]);
        
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE project_tasks SET status = ?, updated_at = NOW() WHERE task_id = ?");
            $stmt->execute([$status, $task_id]);
            
            // Log activity
            $stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, activity_type, description, ip_address, user_agent)
                VALUES (?, 'task', ?, ?, ?)
            ");
            $description = "Updated task status to $status for project ID: $project_id";
            $stmt->execute([$user_id, $description, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($project['project_name']); ?> | Project Details</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Project Details Page Styles */
        .project-details {
            animation: fadeIn 0.5s ease-out;
        }

        /* Header */
        .details-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .details-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #000, #333);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .project-title-section {
            flex: 1;
        }

        .project-code-badge {
            display: inline-block;
            padding: 0.25rem 1rem;
            background: rgba(0,0,0,0.05);
            border-radius: 20px;
            font-family: monospace;
            font-size: 0.875rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 0.5rem;
        }

        .project-title {
            font-size: 2rem;
            font-weight: 700;
            color: #000;
            margin: 0 0 0.5rem 0;
        }

        .project-meta-badges {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .badge.status-active {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .badge.status-completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .badge.status-on_hold {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .badge.priority-high {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
        }

        .badge.priority-medium {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .badge.priority-low {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .header-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000;
            transition: all 0.3s ease;
        }

        .info-item:hover .info-icon {
            background: #000;
            color: white;
            transform: rotate(5deg) scale(1.1);
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 0.75rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #000;
        }

        .info-value.deadline {
            color: #f44336;
        }

        .info-value.deadline.warning {
            color: #ff9800;
        }

        /* Progress Section */
        .progress-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e0e0e0;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .progress-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #000;
        }

        .progress-percentage {
            font-size: 1.5rem;
            font-weight: 700;
            color: #000;
        }

        .progress-bars {
            display: grid;
            gap: 1rem;
        }

        .progress-item {
            margin-bottom: 0.5rem;
        }

        .progress-item-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
            color: #666;
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

        /* Tabs */
        .details-tabs {
            background: white;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            margin-top: 2rem;
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
            vertical-align: middle;
        }

        .tasks-table tr {
            transition: all 0.3s ease;
        }

        .tasks-table tr:hover {
            background: #fafafa;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .task-priority {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .task-priority.high {
            background: #f44336;
            box-shadow: 0 0 10px rgba(244, 67, 54, 0.5);
        }

        .task-priority.medium {
            background: #ff9800;
            box-shadow: 0 0 10px rgba(255, 152, 0, 0.5);
        }

        .task-priority.low {
            background: #2196f3;
            box-shadow: 0 0 10px rgba(33, 150, 243, 0.5);
        }

        .task-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .task-status.pending {
            background: #e0e0e0;
            color: #666;
        }

        .task-status.in_progress {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .task-status.review {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .task-status.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .task-actions {
            display: flex;
            gap: 0.5rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        tr:hover .task-actions {
            opacity: 1;
        }

        .btn-icon-sm {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            color: #666;
            border: 1px solid #e0e0e0;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-icon-sm:hover {
            background: #000;
            color: white;
            border-color: #000;
            transform: scale(1.1) rotate(5deg);
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

        .milestone-status {
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

        .milestone-item:hover .milestone-status {
            background: #ff9800;
            color: white;
            transform: rotate(15deg);
        }

        .milestone-info {
            flex: 1;
        }

        .milestone-name {
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .milestone-date {
            font-size: 0.75rem;
            color: #666;
        }

        .milestone-complete {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .milestone-complete.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        /* Team Grid */
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .team-card {
            background: #fafafa;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            text-align: center;
        }

        .team-card:hover {
            background: white;
            transform: translateY(-5px);
            border-color: #000;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .team-avatar {
            width: 80px;
            height: 80px;
            border-radius: 40px;
            background: #000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 600;
            margin: 0 auto 1rem;
            transition: all 0.3s ease;
        }

        .team-card:hover .team-avatar {
            transform: scale(1.1) rotate(5deg);
            background: #333;
        }

        .team-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .team-role {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .team-email {
            font-size: 0.75rem;
            color: #999;
        }

        /* Files Grid */
        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .file-card {
            background: #fafafa;
            border-radius: 10px;
            padding: 1rem;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .file-card:hover {
            background: white;
            transform: translateY(-3px);
            border-color: #000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .file-icon {
            font-size: 2rem;
            color: #000;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .file-card:hover .file-icon {
            transform: scale(1.2);
        }

        .file-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: #000;
            margin-bottom: 0.25rem;
            word-break: break-word;
        }

        .file-meta {
            font-size: 0.7rem;
            color: #666;
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
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
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

        .activity-description {
            font-size: 0.875rem;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #999;
        }

        /* Animations */
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-top {
                flex-direction: column;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .tab-nav-item {
                padding: 0.75rem 1rem;
            }
            
            .tasks-table {
                display: block;
                overflow-x: auto;
            }
            
            .team-grid {
                grid-template-columns: 1fr;
            }
            
            .files-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
            
            .task-actions {
                opacity: 1;
            }
        }

        @media (max-width: 480px) {
            .project-title {
                font-size: 1.5rem;
            }
            
            .badge {
                padding: 0.25rem 0.75rem;
                font-size: 0.75rem;
            }
            
            .info-item {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dev-header.php'; ?>
    
    <div class="admin-container">
        <?php include '../../includes/dev-sidebar.php'; ?>
        
        <main class="admin-main">
            <div class="project-details">
                <!-- Header -->
                <div class="details-header">
                    <div class="header-top">
                        <div class="project-title-section">
                            <div class="project-code-badge"><?php echo e($project['project_code']); ?></div>
                            <h1 class="project-title"><?php echo e($project['project_name']); ?></h1>
                            <div class="project-meta-badges">
                                <span class="badge status-<?php echo $project['status']; ?>">
                                    <i class="fas fa-circle"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                                </span>
                                <?php if ($project['priority']): ?>
                                    <span class="badge priority-<?php echo $project['priority']; ?>">
                                        <i class="fas fa-flag"></i>
                                        <?php echo ucfirst($project['priority']); ?> Priority
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="header-actions">
                            <a href="index.php" class="btn btn-outline btn-sm">
                                <i class="fas fa-arrow-left"></i> Back
                            </a>
                            <?php if ($project['status'] !== 'completed'): ?>
                                <button class="btn btn-primary btn-sm" onclick="updateProjectProgress()">
                                    <i class="fas fa-sync-alt"></i> Update Progress
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Info Grid -->
                    <div class="info-grid">
                        <?php if ($project['client_name']): ?>
                            <div class="info-item">
                                <div class="info-icon">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="info-content">
                                    <div class="info-label">Client</div>
                                    <div class="info-value"><?php echo e($project['client_name']); ?></div>
                                    <div style="font-size: 0.75rem; color: #666;"><?php echo e($project['client_email']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Timeline</div>
                                <div class="info-value">
                                    <?php echo $project['start_date'] ? formatDate($project['start_date'], 'M d, Y') : 'Not started'; ?>
                                    <?php if ($project['deadline']): ?>
                                        → <span class="<?php 
                                            $deadline = new DateTime($project['deadline']);
                                            $today = new DateTime();
                                            echo $deadline < $today ? 'deadline' : ($today->diff($deadline)->days <= 3 ? 'warning' : '');
                                        ?>"><?php echo formatDate($project['deadline'], 'M d, Y'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Budget</div>
                                <div class="info-value">
                                    <?php echo $project['budget'] ? number_format($project['budget']) . ' ' . $project['currency'] : 'Not specified'; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Project Lead</div>
                                <div class="info-value"><?php echo e($project['created_by_name'] ?? 'Unknown'); ?></div>
                                <div style="font-size: 0.75rem; color: #666;"><?php echo e($project['created_by_email'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Progress Section -->
                    <div class="progress-section">
                        <div class="progress-header">
                            <span class="progress-title">Project Progress</span>
                            <span class="progress-percentage"><?php echo $completion_percentage; ?>%</span>
                        </div>
                        
                        <div class="progress-bars">
                            <div class="progress-item">
                                <div class="progress-item-header">
                                    <span>Overall Completion</span>
                                    <span><?php echo $completed_tasks; ?>/<?php echo $total_tasks; ?> tasks</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar" style="width: <?php echo $completion_percentage; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="progress-item">
                                <div class="progress-item-header">
                                    <span>My Tasks</span>
                                    <span><?php echo $my_completed; ?>/<?php echo $my_total; ?> completed</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar" style="width: <?php echo $my_completion; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="details-tabs">
                    <div class="tab-nav">
                        <div class="tab-nav-item active" data-tab="overview">
                            <i class="fas fa-info-circle"></i> Overview
                        </div>
                        <div class="tab-nav-item" data-tab="tasks">
                            <i class="fas fa-tasks"></i> Tasks 
                            <span class="badge" style="margin-left: 0.5rem;"><?php echo $total_tasks; ?></span>
                        </div>
                        <div class="tab-nav-item" data-tab="milestones">
                            <i class="fas fa-flag"></i> Milestones
                            <span class="badge" style="margin-left: 0.5rem;"><?php echo count($milestones); ?></span>
                        </div>
                        <div class="tab-nav-item" data-tab="team">
                            <i class="fas fa-users"></i> Team
                            <span class="badge" style="margin-left: 0.5rem;"><?php echo count($team_members); ?></span>
                        </div>
                        <div class="tab-nav-item" data-tab="files">
                            <i class="fas fa-file"></i> Files
                            <span class="badge" style="margin-left: 0.5rem;"><?php echo count($files); ?></span>
                        </div>
                        <div class="tab-nav-item" data-tab="activity">
                            <i class="fas fa-history"></i> Activity
                        </div>
                    </div>
                    
                    <div class="tab-content">
                        <!-- Overview Tab -->
                        <div class="tab-pane active" id="overview">
                            <h3 style="margin-bottom: 1rem;">Project Description</h3>
                            <p style="line-height: 1.8; color: #444;"><?php echo nl2br(e($project['description'] ?? 'No description provided.')); ?></p>
                            
                            <?php if (!empty($project['notes'])): ?>
                                <h3 style="margin: 2rem 0 1rem;">Additional Notes</h3>
                                <p style="line-height: 1.8; color: #444;"><?php echo nl2br(e($project['notes'])); ?></p>
                            <?php endif; ?>
                            
                            <div style="margin-top: 2rem; padding: 1rem; background: #fafafa; border-radius: 10px;">
                                <h4 style="margin-bottom: 1rem;">Quick Stats</h4>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                                    <div>
                                        <div style="font-size: 0.75rem; color: #666;">Tasks Overview</div>
                                        <div style="display: flex; gap: 1rem; margin-top: 0.5rem;">
                                            <span><span style="color: #2196f3;">●</span> Pending: <?php echo $task_stats['pending_tasks'] ?? 0; ?></span>
                                            <span><span style="color: #ff9800;">●</span> In Progress: <?php echo $task_stats['in_progress_tasks'] ?? 0; ?></span>
                                            <span><span style="color: #00c853;">●</span> Completed: <?php echo $task_stats['completed_tasks'] ?? 0; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tasks Tab -->
                        <div class="tab-pane" id="tasks">
                            <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                                <h3>Project Tasks</h3>
                                <div>
                                    <select id="taskFilter" class="form-control" style="width: auto; display: inline-block; margin-right: 0.5rem;">
                                        <option value="all">All Tasks</option>
                                        <option value="my">My Tasks</option>
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                            
                            <table class="tasks-table">
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Assigned To</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="tasksList">
                                    <?php
                                    // Get all tasks for this project
                                    $stmt = $db->prepare("
                                        SELECT 
                                            pt.*,
                                            CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name,
                                            u.user_id as assigned_user_id
                                        FROM project_tasks pt
                                        LEFT JOIN users u ON pt.assigned_to = u.user_id
                                        WHERE pt.internal_project_id = ?
                                        ORDER BY 
                                            CASE pt.status
                                                WHEN 'pending' THEN 1
                                                WHEN 'in_progress' THEN 2
                                                WHEN 'review' THEN 3
                                                ELSE 4
                                            END,
                                            pt.due_date ASC
                                    ");
                                    $stmt->execute([$project_id]);
                                    $tasks = $stmt->fetchAll();
                                    
                                    foreach ($tasks as $task):
                                    ?>
                                        <tr class="task-row" data-assigned="<?php echo $task['assigned_user_id']; ?>" data-status="<?php echo $task['status']; ?>">
                                            <td>
                                                <span class="task-priority <?php echo $task['priority']; ?>" style="display: inline-block; vertical-align: middle;"></span>
                                                <span style="vertical-align: middle;"><?php echo e($task['task_name']); ?></span>
                                            </td>
                                            <td><?php echo e($task['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                            <td>
                                                <?php if ($task['due_date']): ?>
                                                    <span class="<?php 
                                                        $due = new DateTime($task['due_date']);
                                                        $today = new DateTime();
                                                        if ($due < $today && $task['status'] != 'completed') echo 'deadline';
                                                        elseif ($today->diff($due)->days <= 2 && $task['status'] != 'completed') echo 'warning';
                                                    ?>">
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
                                            <td>
                                                <div class="task-actions">
                                                    <?php if ($task['assigned_to'] == $user_id): ?>
                                                        <button class="btn-icon-sm" onclick="updateTaskStatus(<?php echo $task['task_id']; ?>, 'in_progress')" title="Start Task">
                                                            <i class="fas fa-play"></i>
                                                        </button>
                                                        <button class="btn-icon-sm" onclick="updateTaskStatus(<?php echo $task['task_id']; ?>, 'completed')" title="Complete Task">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn-icon-sm" onclick="viewTaskDetails(<?php echo $task['task_id']; ?>)" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Milestones Tab -->
                        <div class="tab-pane" id="milestones">
                            <h3 style="margin-bottom: 1.5rem;">Project Milestones</h3>
                            
                            <?php if (!empty($milestones)): ?>
                                <div class="milestones-list">
                                    <?php foreach ($milestones as $milestone): ?>
                                        <div class="milestone-item">
                                            <div class="milestone-status">
                                                <i class="fas fa-<?php echo $milestone['is_completed'] ? 'check' : 'flag'; ?>"></i>
                                            </div>
                                            <div class="milestone-info">
                                                <div class="milestone-name"><?php echo e($milestone['milestone_name']); ?></div>
                                                <div class="milestone-date">
                                                    Due: <?php echo $milestone['due_date'] ? formatDate($milestone['due_date'], 'M d, Y') : 'No deadline'; ?>
                                                </div>
                                            </div>
                                            <div class="milestone-complete <?php echo $milestone['is_completed'] ? 'completed' : ''; ?>">
                                                <?php echo $milestone['is_completed'] ? 'Completed' : 'Pending'; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-flag"></i>
                                    <p>No milestones defined for this project.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Team Tab -->
                        <div class="tab-pane" id="team">
                            <h3 style="margin-bottom: 1.5rem;">Team Members</h3>
                            
                            <?php if (!empty($team_members)): ?>
                                <div class="team-grid">
                                    <?php foreach ($team_members as $member): 
                                        $is_current_user = $member['user_id'] == $user_id;
                                    ?>
                                        <div class="team-card <?php echo $is_current_user ? 'current-user' : ''; ?>">
                                            <div class="team-avatar">
                                                <?php 
                                                $initials = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
                                                echo $initials;
                                                ?>
                                            </div>
                                            <div class="team-name">
                                                <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>
                                                <?php if ($is_current_user): ?>
                                                    <span style="font-size: 0.75rem; color: #00c853;">(You)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="team-role"><?php echo e($member['position'] ?? 'Developer'); ?></div>
                                            <div class="team-email"><?php echo e($member['email']); ?></div>
                                            <?php if ($member['team_name']): ?>
                                                <div style="margin-top: 0.5rem; font-size: 0.7rem; color: #999;">
                                                    <i class="fas fa-users"></i> <?php echo e($member['team_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>No team members assigned yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Files Tab -->
                        <div class="tab-pane" id="files">
                            <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                                <h3>Project Files</h3>
                                <button class="btn btn-primary btn-sm" onclick="uploadFile()">
                                    <i class="fas fa-upload"></i> Upload File
                                </button>
                            </div>
                            
                            <?php if (!empty($files)): ?>
                                <div class="files-grid">
                                    <?php foreach ($files as $file): 
                                        $extension = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                                        $icon = match(strtolower($extension)) {
                                            'pdf' => 'file-pdf',
                                            'doc', 'docx' => 'file-word',
                                            'xls', 'xlsx' => 'file-excel',
                                            'jpg', 'jpeg', 'png', 'gif' => 'file-image',
                                            'zip', 'rar' => 'file-archive',
                                            default => 'file'
                                        };
                                    ?>
                                        <div class="file-card" onclick="downloadFile(<?php echo $file['file_id']; ?>)">
                                            <div class="file-icon">
                                                <i class="fas fa-<?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="file-name"><?php echo e($file['file_name']); ?></div>
                                            <div class="file-meta">
                                                <?php echo round($file['file_size'] / 1024, 2); ?> KB
                                            </div>
                                            <div class="file-meta">
                                                Uploaded by <?php echo e($file['uploaded_by_name']); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-file"></i>
                                    <p>No files uploaded yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Activity Tab -->
                        <div class="tab-pane" id="activity">
                            <h3 style="margin-bottom: 1.5rem;">Recent Activity</h3>
                            
                            <?php if (!empty($activities)): ?>
                                <div class="activity-feed">
                                    <?php foreach ($activities as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <i class="fas fa-<?php 
                                                    echo match($activity['activity_type']) {
                                                        'task' => 'tasks',
                                                        'project' => 'project-diagram',
                                                        'file' => 'file',
                                                        default => 'bell'
                                                    };
                                                ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-description">
                                                    <?php echo e($activity['description']); ?>
                                                </div>
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
                                                        echo formatDate($activity['created_at'], 'M d, H:i');
                                                    }
                                                    ?>
                                                    <?php if ($activity['user_name']): ?>
                                                        by <?php echo e($activity['user_name']); ?>
                                                    <?php endif; ?>
                                                </div>
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

    <!-- Hidden form for file upload -->
    <form id="fileUploadForm" method="POST" enctype="multipart/form-data" style="display: none;">
        <input type="file" name="file" id="fileInput">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
    </form>

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
            
            // Task filtering
            const taskFilter = document.getElementById('taskFilter');
            if (taskFilter) {
                taskFilter.addEventListener('change', function() {
                    const filter = this.value;
                    const rows = document.querySelectorAll('.task-row');
                    
                    rows.forEach(row => {
                        if (filter === 'all') {
                            row.style.display = '';
                        } else if (filter === 'my') {
                            const assigned = row.dataset.assigned;
                            row.style.display = assigned === '<?php echo $user_id; ?>' ? '' : 'none';
                        } else {
                            const status = row.dataset.status;
                            row.style.display = status === filter ? '' : 'none';
                        }
                    });
                });
            }
            
            // Auto-dismiss alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                });
            }, 5000);
        });

        // Task status update
        function updateTaskStatus(taskId, status) {
            fetch('view.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=1&action=update_task_status&task_id=${taskId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to update task status');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }

        // View task details
        function viewTaskDetails(taskId) {
            window.location.href = 'task-details.php?id=' + taskId;
        }

        // Upload file
        function uploadFile() {
            document.getElementById('fileInput').click();
        }

        // Handle file selection
        document.getElementById('fileInput')?.addEventListener('change', function() {
            if (this.files.length > 0) {
                const formData = new FormData();
                formData.append('file', this.files[0]);
                formData.append('project_id', <?php echo $project_id; ?>);
                
                fetch('upload-file.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Upload failed: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Upload failed');
                });
            }
        });

        // Download file
        function downloadFile(fileId) {
            window.location.href = 'download-file.php?id=' + fileId;
        }

        // Update project progress
        function updateProjectProgress() {
            // This would typically recalculate progress based on tasks/milestones
            // For now, just show a notification
            alert('Progress updated!');
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
                document.querySelector('.admin-main').insertBefore(container, document.querySelector('.details-header'));
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