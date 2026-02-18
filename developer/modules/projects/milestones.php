<?php
/**
 * Developer Milestones Management
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

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$status = $_GET['status'] ?? '';
$project_id = $_GET['project_id'] ?? '';
$search = $_GET['search'] ?? '';
$view = $_GET['view'] ?? 'grid'; // grid or list

// Build query to get milestones for projects the user is assigned to
$query = "
    SELECT 
        pm.*,
        ip.project_name,
        ip.project_code,
        ip.status as project_status,
        ip.priority as project_priority,
        ip.deadline as project_deadline,
        (SELECT COUNT(*) FROM project_tasks WHERE milestone_id = pm.milestone_id) as total_tasks,
        (SELECT COUNT(*) FROM project_tasks WHERE milestone_id = pm.milestone_id AND status = 'completed') as completed_tasks,
        (SELECT COUNT(*) FROM project_tasks WHERE milestone_id = pm.milestone_id AND assigned_to = ?) as my_tasks,
        (SELECT GROUP_CONCAT(CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') 
         FROM project_tasks pt2 
         INNER JOIN users u ON pt2.assigned_to = u.user_id 
         WHERE pt2.milestone_id = pm.milestone_id AND pt2.assigned_to IS NOT NULL) as assigned_members
    FROM project_milestones pm
    INNER JOIN internal_projects ip ON pm.internal_project_id = ip.internal_project_id
    INNER JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
    WHERE pt.assigned_to = ?
";

$params = [$user_id, $user_id];

// Apply project filter
if (!empty($project_id)) {
    $query .= " AND pm.internal_project_id = ?";
    $params[] = $project_id;
}

// Apply status filter
if ($status === 'completed') {
    $query .= " AND pm.is_completed = 1";
} elseif ($status === 'pending') {
    $query .= " AND pm.is_completed = 0";
} elseif ($status === 'overdue') {
    $query .= " AND pm.due_date < CURDATE() AND pm.is_completed = 0";
} elseif ($status === 'upcoming') {
    $query .= " AND pm.due_date >= CURDATE() AND pm.is_completed = 0";
} elseif ($status === 'this_week') {
    $query .= " AND pm.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND pm.is_completed = 0";
} elseif ($status === 'next_week') {
    $query .= " AND pm.due_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 8 DAY) AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND pm.is_completed = 0";
}

// Apply search filter
if (!empty($search)) {
    $query .= " AND (pm.milestone_name LIKE ? OR pm.description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
}

// Apply "my" filter (milestones where user has tasks)
if ($filter === 'my') {
    $query .= " AND pm.milestone_id IN (
        SELECT DISTINCT milestone_id FROM project_tasks 
        WHERE assigned_to = ? AND milestone_id IS NOT NULL
    )";
    $params[] = $user_id;
}

$query .= " GROUP BY pm.milestone_id ORDER BY 
            CASE 
                WHEN pm.is_completed = 1 THEN 2
                ELSE 1
            END,
            pm.due_date ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$milestones = $stmt->fetchAll();

// Get milestone statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT pm.milestone_id) as total_milestones,
        SUM(CASE WHEN pm.is_completed = 1 THEN 1 ELSE 0 END) as completed_milestones,
        SUM(CASE WHEN pm.is_completed = 0 AND pm.due_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming_milestones,
        SUM(CASE WHEN pm.is_completed = 0 AND pm.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_milestones,
        SUM(CASE WHEN pm.is_completed = 0 AND pm.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
        SUM(CASE WHEN pm.is_completed = 0 AND pm.due_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 8 DAY) AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as next_week
    FROM project_milestones pm
    INNER JOIN internal_projects ip ON pm.internal_project_id = ip.internal_project_id
    INNER JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
    WHERE pt.assigned_to = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

// Get projects for filter dropdown
$stmt = $db->prepare("
    SELECT DISTINCT 
        ip.internal_project_id,
        ip.project_name,
        ip.project_code
    FROM internal_projects ip
    INNER JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
    WHERE pt.assigned_to = ?
    ORDER BY ip.project_name
");
$stmt->execute([$user_id]);
$projects = $stmt->fetchAll();

// Get upcoming milestones (next 30 days)
$stmt = $db->prepare("
    SELECT 
        pm.*,
        ip.project_name,
        ip.project_code,
        DATEDIFF(pm.due_date, CURDATE()) as days_left
    FROM project_milestones pm
    INNER JOIN internal_projects ip ON pm.internal_project_id = ip.internal_project_id
    INNER JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
    WHERE pt.assigned_to = ? 
        AND pm.is_completed = 0 
        AND pm.due_date IS NOT NULL
        AND pm.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY pm.due_date ASC
    LIMIT 5
");
$stmt->execute([$user_id]);
$upcoming = $stmt->fetchAll();

// Handle milestone status update via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        $milestone_id = $_POST['milestone_id'] ?? 0;
        
        // Verify user has access to this milestone
        $stmt = $db->prepare("
            SELECT COUNT(*) as has_access
            FROM project_milestones pm
            INNER JOIN internal_projects ip ON pm.internal_project_id = ip.internal_project_id
            INNER JOIN project_tasks pt ON ip.internal_project_id = pt.internal_project_id
            WHERE pm.milestone_id = ? AND pt.assigned_to = ?
        ");
        $stmt->execute([$milestone_id, $user_id]);
        
        if (!$stmt->fetch()['has_access']) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit();
        }
        
        switch ($_POST['action']) {
            case 'toggle_complete':
                $stmt = $db->prepare("
                    UPDATE project_milestones 
                    SET is_completed = NOT is_completed,
                        completed_date = CASE WHEN is_completed = 0 THEN CURDATE() ELSE NULL END
                    WHERE milestone_id = ?
                ");
                $stmt->execute([$milestone_id]);
                
                // Log activity
                $stmt = $db->prepare("
                    INSERT INTO activity_logs (user_id, activity_type, description, ip_address, user_agent)
                    VALUES (?, 'milestone', ?, ?, ?)
                ");
                $description = "Toggled milestone completion for milestone ID: $milestone_id";
                $stmt->execute([$user_id, $description, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'update_progress':
                $progress = $_POST['progress'] ?? 0;
                $stmt = $db->prepare("UPDATE project_milestones SET completion_percentage = ? WHERE milestone_id = ?");
                $stmt->execute([$progress, $milestone_id]);
                echo json_encode(['success' => true]);
                break;
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
    <title>Milestones | Developer Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Milestones Page Specific Styles */
        .milestones-page {
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
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
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #000;
            margin-bottom: 0.25rem;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
        }

        .stat-trend.warning {
            color: #ff9800;
        }

        .stat-trend.danger {
            color: #f44336;
        }

        .stat-trend.success {
            color: #00c853;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            animation: slideInDown 0.5s ease-out;
        }

        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .filter-tab {
            padding: 0.5rem 1.25rem;
            border-radius: 25px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            background: white;
            color: #666;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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

        .filter-tab i {
            font-size: 0.875rem;
        }

        .filter-tab.danger:hover {
            border-color: #f44336;
            color: #f44336;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-select {
            padding: 0.5rem 2rem 0.5rem 1rem;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.875rem;
            color: #333;
            background: white;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
            transition: all 0.3s ease;
        }

        .filter-select:hover {
            border-color: #000;
        }

        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .view-btn {
            width: 40px;
            height: 40px;
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

        .view-btn:hover {
            background: #000;
            color: white;
            border-color: #000;
            transform: scale(1.1);
        }

        .view-btn.active {
            background: #000;
            color: white;
            border-color: #000;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            transition: color 0.3s ease;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: #000;
            box-shadow: 0 0 0 3px rgba(0,0,0,0.1);
        }

        .search-box input:focus + i {
            color: #000;
        }

        /* Grid View */
        .milestones-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            animation: fadeIn 0.5s ease-out;
        }

        .milestone-card {
            background: white;
            border-radius: 20px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            animation: slideInUp 0.5s ease-out;
            animation-fill-mode: both;
        }

        .milestone-card:nth-child(1) { animation-delay: 0.05s; }
        .milestone-card:nth-child(2) { animation-delay: 0.1s; }
        .milestone-card:nth-child(3) { animation-delay: 0.15s; }
        .milestone-card:nth-child(4) { animation-delay: 0.2s; }
        .milestone-card:nth-child(5) { animation-delay: 0.25s; }
        .milestone-card:nth-child(6) { animation-delay: 0.3s; }

        .milestone-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 30px rgba(0,0,0,0.15);
            border-color: #000;
        }

        .milestone-card::after {
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

        .milestone-card:hover::after {
            transform: translateX(100%);
        }

        .milestone-header {
            padding: 1.25rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            background: #fafafa;
        }

        .milestone-project {
            display: flex;
            flex-direction: column;
        }

        .project-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .project-code {
            font-size: 0.7rem;
            color: #666;
            font-family: monospace;
        }

        .milestone-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .milestone-status.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .milestone-status.pending {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .milestone-status.overdue {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }

        .milestone-body {
            padding: 1.25rem;
        }

        .milestone-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 0.75rem;
            line-height: 1.4;
        }

        .milestone-description {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 1rem;
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .milestone-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px dashed #e0e0e0;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: #666;
        }

        .meta-item i {
            color: #999;
            transition: transform 0.3s ease;
        }

        .milestone-card:hover .meta-item i {
            transform: scale(1.2);
        }

        .meta-item .due-date {
            font-weight: 600;
        }

        .meta-item .due-date.overdue {
            color: #f44336;
        }

        .meta-item .due-date.today {
            color: #ff9800;
        }

        .meta-item .due-date.tomorrow {
            color: #2196f3;
        }

        /* Progress Section */
        .progress-section {
            margin-bottom: 1rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 0.25rem;
        }

        .progress-bar-container {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 0.5rem;
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
            font-size: 0.7rem;
            color: #666;
        }

        /* Team Members */
        .team-members {
            display: flex;
            align-items: center;
            justify-content: space-between;
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
            border-radius: 15px;
            background: #000;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: -8px;
            border: 2px solid white;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .member-avatar:hover {
            transform: scale(1.2) translateY(-3px);
            z-index: 10;
            background: #333;
        }

        .member-avatar.more {
            background: #f0f0f0;
            color: #666;
        }

        .milestone-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .btn-icon.success:hover {
            background: #00c853;
            border-color: #00c853;
        }

        .btn-icon.warning:hover {
            background: #ff9800;
            border-color: #ff9800;
        }

        /* List View */
        .milestones-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .milestone-list-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: white;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            animation: slideInLeft 0.3s ease-out;
        }

        .milestone-list-item:hover {
            background: #fafafa;
            transform: translateX(5px);
            border-color: #000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .milestone-list-status {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.25rem;
        }

        .milestone-list-status.completed {
            background: rgba(0, 200, 83, 0.1);
            color: #00c853;
        }

        .milestone-list-status.pending {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .milestone-list-status.overdue {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
        }

        .milestone-list-content {
            flex: 1;
        }

        .milestone-list-title {
            font-weight: 600;
            color: #000;
            margin-bottom: 0.25rem;
        }

        .milestone-list-meta {
            display: flex;
            gap: 1.5rem;
            font-size: 0.75rem;
            color: #666;
        }

        .milestone-list-progress {
            width: 150px;
            margin: 0 1rem;
        }

        .milestone-list-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Timeline View */
        .timeline {
            position: relative;
            padding: 1rem 0;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }

        .timeline-item {
            position: relative;
            padding-left: 50px;
            margin-bottom: 2rem;
        }

        .timeline-marker {
            position: absolute;
            left: 0;
            width: 40px;
            height: 40px;
            border-radius: 20px;
            background: white;
            border: 2px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
        }

        .timeline-marker.completed {
            background: #00c853;
            border-color: #00c853;
            color: white;
        }

        .timeline-marker.overdue {
            border-color: #f44336;
            color: #f44336;
        }

        .timeline-content {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .timeline-content:hover {
            transform: translateX(5px);
            border-color: #000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .timeline-date {
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 0.25rem;
        }

        .timeline-title {
            font-size: 1rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 0.5rem;
        }

        .timeline-project {
            display: inline-block;
            padding: 0.2rem 0.75rem;
            background: #f0f0f0;
            border-radius: 12px;
            font-size: 0.7rem;
            color: #666;
        }

        /* Upcoming Milestones Sidebar */
        .upcoming-sidebar {
            background: white;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .sidebar-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .upcoming-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }

        .upcoming-item:last-child {
            border-bottom: none;
        }

        .upcoming-item:hover {
            background: #fafafa;
            transform: translateX(5px);
        }

        .upcoming-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            color: #ff9800;
        }

        .upcoming-content {
            flex: 1;
        }

        .upcoming-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: #000;
            margin-bottom: 0.125rem;
        }

        .upcoming-project {
            font-size: 0.7rem;
            color: #666;
        }

        .upcoming-days {
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .upcoming-days.urgent {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
        }

        .upcoming-days.warning {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .upcoming-days.normal {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
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
            max-width: 500px;
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
            font-size: 1.25rem;
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

        .progress-slider {
            width: 100%;
            margin: 1rem 0;
        }

        .progress-value {
            text-align: center;
            font-size: 2rem;
            font-weight: 700;
            color: #000;
            margin: 1rem 0;
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
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .milestones-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .view-toggle {
                margin-left: 0;
                justify-content: flex-end;
            }
            
            .search-box {
                width: 100%;
            }
            
            .milestone-list-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .milestone-list-progress {
                width: 100%;
                margin: 1rem 0;
            }
            
            .milestone-list-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-tabs {
                justify-content: center;
            }
            
            .milestone-footer {
                flex-direction: column;
                gap: 1rem;
            }
            
            .milestone-actions {
                width: 100%;
                justify-content: center;
            }
            
            .timeline::before {
                left: 15px;
            }
            
            .timeline-marker {
                width: 30px;
                height: 30px;
            }
            
            .timeline-item {
                padding-left: 40px;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dev-header.php'; ?>
    
    <div class="admin-container">
        <?php include '../../includes/dev-sidebar.php'; ?>
        
        <main class="admin-main">
            <div class="milestones-page">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-flag-checkered"></i> Milestones
                    </h1>
                    <div class="header-actions">
                        <button class="btn btn-outline btn-sm" onclick="exportMilestones()">
                            <i class="fas fa-download"></i> Export
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

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card" onclick="location.href='?status=pending'">
                        <div class="stat-value"><?php echo ($stats['total_milestones'] ?? 0) - ($stats['completed_milestones'] ?? 0); ?></div>
                        <div class="stat-label">Pending</div>
                        <div class="stat-trend warning">
                            <i class="fas fa-clock"></i> Awaiting completion
                        </div>
                    </div>
                    
                    <div class="stat-card" onclick="location.href='?status=completed'">
                        <div class="stat-value"><?php echo $stats['completed_milestones'] ?? 0; ?></div>
                        <div class="stat-label">Completed</div>
                        <div class="stat-trend success">
                            <i class="fas fa-check-circle"></i> Done
                        </div>
                    </div>
                    
                    <div class="stat-card" onclick="location.href='?status=upcoming'">
                        <div class="stat-value"><?php echo $stats['upcoming_milestones'] ?? 0; ?></div>
                        <div class="stat-label">Upcoming</div>
                        <div class="stat-trend">
                            <i class="fas fa-calendar"></i> Future
                        </div>
                    </div>
                    
                    <?php if (($stats['overdue_milestones'] ?? 0) > 0): ?>
                        <div class="stat-card" onclick="location.href='?status=overdue'">
                            <div class="stat-value" style="color: #f44336;"><?php echo $stats['overdue_milestones']; ?></div>
                            <div class="stat-label">Overdue</div>
                            <div class="stat-trend danger">
                                <i class="fas fa-exclamation-triangle"></i> Needs attention
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="stat-card" onclick="location.href='?status=this_week'">
                        <div class="stat-value"><?php echo $stats['this_week'] ?? 0; ?></div>
                        <div class="stat-label">This Week</div>
                        <div class="stat-trend warning">
                            <i class="fas fa-calendar-week"></i> Due soon
                        </div>
                    </div>
                    
                    <div class="stat-card" onclick="location.href='?status=next_week'">
                        <div class="stat-value"><?php echo $stats['next_week'] ?? 0; ?></div>
                        <div class="stat-label">Next Week</div>
                        <div class="stat-trend">
                            <i class="fas fa-calendar"></i> Upcoming
                        </div>
                    </div>
                </div>

                <!-- Upcoming Milestones Sidebar (if any) -->
                <?php if (!empty($upcoming)): ?>
                    <div class="upcoming-sidebar">
                        <h3 class="sidebar-title">
                            <i class="fas fa-clock"></i>
                            Next 5 Milestones
                        </h3>
                        <div class="upcoming-list">
                            <?php foreach ($upcoming as $item): ?>
                                <?php
                                $days = $item['days_left'];
                                $status_class = $days <= 2 ? 'urgent' : ($days <= 5 ? 'warning' : 'normal');
                                ?>
                                <div class="upcoming-item" onclick="viewMilestone(<?php echo $item['milestone_id']; ?>)">
                                    <div class="upcoming-icon">
                                        <i class="fas fa-flag"></i>
                                    </div>
                                    <div class="upcoming-content">
                                        <div class="upcoming-name"><?php echo e($item['milestone_name']); ?></div>
                                        <div class="upcoming-project"><?php echo e($item['project_name']); ?></div>
                                    </div>
                                    <div class="upcoming-days <?php echo $status_class; ?>">
                                        <?php echo $days; ?> day<?php echo $days > 1 ? 's' : ''; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-tabs">
                        <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            <i class="fas fa-list"></i> All
                        </a>
                        <a href="?status=pending" class="filter-tab <?php echo $status === 'pending' ? 'active' : ''; ?>">
                            <i class="fas fa-clock"></i> Pending
                        </a>
                        <a href="?status=completed" class="filter-tab <?php echo $status === 'completed' ? 'active' : ''; ?>">
                            <i class="fas fa-check-circle"></i> Completed
                        </a>
                        <a href="?status=upcoming" class="filter-tab <?php echo $status === 'upcoming' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar"></i> Upcoming
                        </a>
                        <?php if (($stats['overdue_milestones'] ?? 0) > 0): ?>
                            <a href="?status=overdue" class="filter-tab danger <?php echo $status === 'overdue' ? 'active' : ''; ?>">
                                <i class="fas fa-exclamation-triangle"></i> Overdue
                            </a>
                        <?php endif; ?>
                        <a href="?filter=my" class="filter-tab <?php echo $filter === 'my' ? 'active' : ''; ?>">
                            <i class="fas fa-user"></i> My Milestones
                        </a>
                    </div>
                    
                    <div class="filter-row">
                        <select class="filter-select" id="projectFilter" onchange="applyFilters()">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?php echo $proj['internal_project_id']; ?>" <?php echo $project_id == $proj['internal_project_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($proj['project_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" 
                                   id="searchInput"
                                   placeholder="Search milestones..." 
                                   value="<?php echo e($search); ?>"
                                   autocomplete="off">
                        </div>
                        
                        <div class="view-toggle">
                            <button class="view-btn <?php echo $view === 'grid' ? 'active' : ''; ?>" onclick="setView('grid')" title="Grid View">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button class="view-btn <?php echo $view === 'list' ? 'active' : ''; ?>" onclick="setView('list')" title="List View">
                                <i class="fas fa-list"></i>
                            </button>
                            <button class="view-btn <?php echo $view === 'timeline' ? 'active' : ''; ?>" onclick="setView('timeline')" title="Timeline View">
                                <i class="fas fa-stream"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Milestones Display -->
                <?php if (!empty($milestones)): ?>
                    <?php if ($view === 'grid'): ?>
                        <!-- Grid View -->
                        <div class="milestones-grid">
                            <?php foreach ($milestones as $milestone): 
                                $is_completed = $milestone['is_completed'];
                                $is_overdue = !$is_completed && $milestone['due_date'] && strtotime($milestone['due_date']) < time();
                                $total_tasks = $milestone['total_tasks'] ?? 0;
                                $completed_tasks = $milestone['completed_tasks'] ?? 0;
                                $task_progress = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
                                $days_left = $milestone['due_date'] ? (new DateTime())->diff(new DateTime($milestone['due_date']))->days : null;
                                
                                // Parse assigned members
                                $members = [];
                                if ($milestone['assigned_members']) {
                                    $members = explode(', ', $milestone['assigned_members']);
                                }
                            ?>
                                <div class="milestone-card" data-milestone-id="<?php echo $milestone['milestone_id']; ?>">
                                    <div class="milestone-header">
                                        <div class="milestone-project">
                                            <span class="project-name"><?php echo e($milestone['project_name']); ?></span>
                                            <span class="project-code"><?php echo e($milestone['project_code']); ?></span>
                                        </div>
                                        <span class="milestone-status <?php 
                                            echo $is_completed ? 'completed' : ($is_overdue ? 'overdue' : 'pending'); 
                                        ?>">
                                            <?php if ($is_completed): ?>
                                                <i class="fas fa-check-circle"></i> Completed
                                            <?php elseif ($is_overdue): ?>
                                                <i class="fas fa-exclamation-circle"></i> Overdue
                                            <?php else: ?>
                                                <i class="fas fa-clock"></i> Pending
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="milestone-body">
                                        <h3 class="milestone-title"><?php echo e($milestone['milestone_name']); ?></h3>
                                        <p class="milestone-description">
                                            <?php echo e($milestone['description'] ?? 'No description provided.'); ?>
                                        </p>
                                        
                                        <div class="milestone-meta">
                                            <?php if ($milestone['due_date']): ?>
                                                <div class="meta-item">
                                                    <i class="fas fa-calendar"></i>
                                                    <span class="due-date <?php 
                                                        echo $is_overdue ? 'overdue' : 
                                                            ($days_left == 0 ? 'today' : 
                                                            ($days_left == 1 ? 'tomorrow' : '')); 
                                                    ?>">
                                                        <?php 
                                                        if ($is_completed) {
                                                            echo 'Completed: ' . formatDate($milestone['completed_date'] ?? $milestone['due_date'], 'M d, Y');
                                                        } elseif ($is_overdue) {
                                                            echo 'Overdue: ' . formatDate($milestone['due_date'], 'M d, Y');
                                                        } else {
                                                            echo 'Due: ' . formatDate($milestone['due_date'], 'M d, Y');
                                                            if ($days_left !== null) {
                                                                echo ' (' . $days_left . ' day' . ($days_left > 1 ? 's' : '') . ' left)';
                                                            }
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="meta-item">
                                                <i class="fas fa-tasks"></i>
                                                <span><?php echo $completed_tasks; ?>/<?php echo $total_tasks; ?> tasks</span>
                                            </div>
                                            
                                            <?php if ($milestone['my_tasks'] > 0): ?>
                                                <div class="meta-item">
                                                    <i class="fas fa-user"></i>
                                                    <span><?php echo $milestone['my_tasks']; ?> assigned to you</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($total_tasks > 0): ?>
                                            <div class="progress-section">
                                                <div class="progress-header">
                                                    <span>Task Progress</span>
                                                    <span><?php echo $task_progress; ?>%</span>
                                                </div>
                                                <div class="progress-bar-container">
                                                    <div class="progress-bar" style="width: <?php echo $task_progress; ?>%"></div>
                                                </div>
                                                <div class="task-stats">
                                                    <span>Completed: <?php echo $completed_tasks; ?></span>
                                                    <span>Remaining: <?php echo $total_tasks - $completed_tasks; ?></span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($members)): ?>
                                            <div class="team-members">
                                                <div class="member-avatars">
                                                    <?php 
                                                    $display_count = min(5, count($members));
                                                    for ($i = 0; $i < $display_count; $i++):
                                                        $name_parts = explode(' ', $members[$i]);
                                                        $initials = '';
                                                        foreach ($name_parts as $part) {
                                                            $initials .= strtoupper(substr($part, 0, 1));
                                                        }
                                                    ?>
                                                        <div class="member-avatar" title="<?php echo e($members[$i]); ?>">
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
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="milestone-footer">
                                        <div class="milestone-progress">
                                            <span style="font-size: 0.875rem; font-weight: 600; color: #000;">
                                                <?php echo $milestone['completion_percentage'] ?? 0; ?>%
                                            </span>
                                        </div>
                                        
                                        <div class="milestone-actions">
                                            <?php if (!$is_completed): ?>
                                                <button class="btn-icon success" onclick="toggleComplete(<?php echo $milestone['milestone_id']; ?>)" title="Mark Complete">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn-icon" onclick="updateProgress(<?php echo $milestone['milestone_id']; ?>)" title="Update Progress">
                                                    <i class="fas fa-sliders-h"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-icon warning" onclick="toggleComplete(<?php echo $milestone['milestone_id']; ?>)" title="Mark Incomplete">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn-icon" onclick="viewMilestone(<?php echo $milestone['milestone_id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn-icon" onclick="viewTasks(<?php echo $milestone['milestone_id']; ?>)" title="View Tasks">
                                                <i class="fas fa-tasks"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                    <?php elseif ($view === 'list'): ?>
                        <!-- List View -->
                        <div class="milestones-list">
                            <?php foreach ($milestones as $milestone): 
                                $is_completed = $milestone['is_completed'];
                                $is_overdue = !$is_completed && $milestone['due_date'] && strtotime($milestone['due_date']) < time();
                                $total_tasks = $milestone['total_tasks'] ?? 0;
                                $completed_tasks = $milestone['completed_tasks'] ?? 0;
                                $task_progress = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
                            ?>
                                <div class="milestone-list-item">
                                    <div class="milestone-list-status <?php 
                                        echo $is_completed ? 'completed' : ($is_overdue ? 'overdue' : 'pending'); 
                                    ?>">
                                        <i class="fas fa-<?php echo $is_completed ? 'check' : 'flag'; ?>"></i>
                                    </div>
                                    
                                    <div class="milestone-list-content">
                                        <div class="milestone-list-title"><?php echo e($milestone['milestone_name']); ?></div>
                                        <div class="milestone-list-meta">
                                            <span><i class="fas fa-project-diagram"></i> <?php echo e($milestone['project_name']); ?></span>
                                            <?php if ($milestone['due_date']): ?>
                                                <span><i class="fas fa-calendar"></i> <?php echo formatDate($milestone['due_date'], 'M d, Y'); ?></span>
                                            <?php endif; ?>
                                            <span><i class="fas fa-tasks"></i> <?php echo $completed_tasks; ?>/<?php echo $total_tasks; ?> tasks</span>
                                        </div>
                                    </div>
                                    
                                    <div class="milestone-list-progress">
                                        <div class="progress-bar-container">
                                            <div class="progress-bar" style="width: <?php echo $task_progress; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="milestone-list-actions">
                                        <?php if (!$is_completed): ?>
                                            <button class="btn-icon success" onclick="toggleComplete(<?php echo $milestone['milestone_id']; ?>)" title="Mark Complete">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-icon warning" onclick="toggleComplete(<?php echo $milestone['milestone_id']; ?>)" title="Mark Incomplete">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn-icon" onclick="viewMilestone(<?php echo $milestone['milestone_id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                    <?php else: ?>
                        <!-- Timeline View -->
                        <div class="timeline">
                            <?php 
                            // Group milestones by month
                            $grouped = [];
                            foreach ($milestones as $milestone) {
                                if ($milestone['due_date']) {
                                    $month = date('F Y', strtotime($milestone['due_date']));
                                    $grouped[$month][] = $milestone;
                                } else {
                                    $grouped['No Date'][] = $milestone;
                                }
                            }
                            ?>
                            
                            <?php foreach ($grouped as $month => $month_milestones): ?>
                                <h3 style="margin: 2rem 0 1rem; color: #000;"><?php echo $month; ?></h3>
                                
                                <?php foreach ($month_milestones as $milestone): 
                                    $is_completed = $milestone['is_completed'];
                                    $is_overdue = !$is_completed && $milestone['due_date'] && strtotime($milestone['due_date']) < time();
                                ?>
                                    <div class="timeline-item">
                                        <div class="timeline-marker <?php echo $is_completed ? 'completed' : ($is_overdue ? 'overdue' : ''); ?>">
                                            <i class="fas fa-<?php echo $is_completed ? 'check' : 'flag'; ?>"></i>
                                        </div>
                                        
                                        <div class="timeline-content" onclick="viewMilestone(<?php echo $milestone['milestone_id']; ?>)">
                                            <div class="timeline-date">
                                                <?php echo $milestone['due_date'] ? formatDate($milestone['due_date'], 'l, F j, Y') : 'No due date'; ?>
                                            </div>
                                            <div class="timeline-title"><?php echo e($milestone['milestone_name']); ?></div>
                                            <span class="timeline-project"><?php echo e($milestone['project_name']); ?></span>
                                            <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #666;">
                                                <?php echo e(substr($milestone['description'] ?? 'No description', 0, 100)); ?>...
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Pagination -->
                    <?php if (count($milestones) > 20): ?>
                        <div class="pagination">
                            <div class="page-item"><i class="fas fa-chevron-left"></i></div>
                            <div class="page-item active">1</div>
                            <div class="page-item">2</div>
                            <div class="page-item">3</div>
                            <div class="page-item">4</div>
                            <div class="page-item">5</div>
                            <div class="page-item"><i class="fas fa-chevron-right"></i></div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-flag-checkered"></i>
                        <h3>No Milestones Found</h3>
                        <p>No milestones match your current filters.</p>
                        <?php if (!empty($search) || !empty($status) || !empty($project_id)): ?>
                            <a href="milestones.php" class="btn btn-primary">Clear Filters</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Progress Update Modal -->
    <div class="modal" id="progressModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Update Milestone Progress</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="progress-value" id="progressDisplay">0%</div>
            <input type="range" class="progress-slider" id="progressSlider" min="0" max="100" value="0" step="5">
            <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 2rem;">
                <button class="btn btn-primary" onclick="saveProgress()">Save Progress</button>
                <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script src="<?php echo url('assets/js/developer.js'); ?>"></script>
    <script>
        let currentMilestoneId = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-submit search on input (with debounce)
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        applyFilters();
                    }, 500);
                });
            }
            
            // Progress slider
            const slider = document.getElementById('progressSlider');
            const display = document.getElementById('progressDisplay');
            if (slider) {
                slider.addEventListener('input', function() {
                    display.textContent = this.value + '%';
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
            
            // Add hover animations to progress bars
            document.querySelectorAll('.milestone-card').forEach(card => {
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

        // Apply filters
        function applyFilters() {
            const project = document.getElementById('projectFilter').value;
            const search = document.getElementById('searchInput').value;
            const view = '<?php echo $view; ?>';
            
            let url = 'milestones.php?';
            if (project) url += `project_id=${project}&`;
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (view) url += `view=${view}&`;
            
            // Remove trailing & or ?
            url = url.replace(/[&?]$/, '');
            
            window.location.href = url;
        }

        // Set view mode
        function setView(view) {
            const project = document.getElementById('projectFilter').value;
            const search = document.getElementById('searchInput').value;
            const status = '<?php echo $status; ?>';
            const filter = '<?php echo $filter; ?>';
            
            let url = 'milestones.php?';
            if (filter) url += `filter=${filter}&`;
            if (status) url += `status=${status}&`;
            if (project) url += `project_id=${project}&`;
            if (search) url += `search=${encodeURIComponent(search)}&`;
            url += `view=${view}`;
            
            window.location.href = url;
        }

        // Toggle milestone completion
        function toggleComplete(milestoneId) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'toggle_complete');
            formData.append('milestone_id', milestoneId);
            
            fetch('milestones.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Milestone status updated', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification('Failed to update milestone', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        }

        // Update progress
        function updateProgress(milestoneId) {
            currentMilestoneId = milestoneId;
            
            // Get current progress
            const card = document.querySelector(`.milestone-card[data-milestone-id="${milestoneId}"]`);
            if (card) {
                const progressText = card.querySelector('.milestone-progress span').textContent;
                const currentProgress = parseInt(progressText) || 0;
                
                document.getElementById('progressSlider').value = currentProgress;
                document.getElementById('progressDisplay').textContent = currentProgress + '%';
            }
            
            document.getElementById('progressModal').classList.add('active');
        }

        // Save progress
        function saveProgress() {
            if (!currentMilestoneId) return;
            
            const progress = document.getElementById('progressSlider').value;
            
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'update_progress');
            formData.append('milestone_id', currentMilestoneId);
            formData.append('progress', progress);
            
            fetch('milestones.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Progress updated', 'success');
                    closeModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification('Failed to update progress', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        }

        // Close modal
        function closeModal() {
            document.getElementById('progressModal').classList.remove('active');
            currentMilestoneId = null;
        }

        // View milestone details
        function viewMilestone(milestoneId) {
            window.location.href = `milestone-details.php?id=${milestoneId}`;
        }

        // View tasks for milestone
        function viewTasks(milestoneId) {
            window.location.href = `../tasks.php?milestone_id=${milestoneId}`;
        }

        // Export milestones
        function exportMilestones() {
            const status = '<?php echo $status; ?>';
            const project = document.getElementById('projectFilter').value;
            
            let url = 'export-milestones.php?';
            if (status) url += `status=${status}&`;
            if (project) url += `project_id=${project}&`;
            
            window.location.href = url;
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modal
            if (e.key === 'Escape') {
                closeModal();
            }
            
            // Ctrl+F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
        });
    </script>
</body>
</html>