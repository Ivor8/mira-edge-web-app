<?php
/**
 * Developer Tasks Management
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
$priority = $_GET['priority'] ?? '';
$project_id = $_GET['project_id'] ?? '';
$search = $_GET['search'] ?? '';

// Build query based on filters
$query = "
    SELECT 
        pt.*,
        ip.project_name,
        ip.project_code,
        ip.internal_project_id,
        CONCAT(u.first_name, ' ', u.last_name) as assigned_by_name,
        u.email as assigned_by_email,
        (SELECT COUNT(*) FROM project_files WHERE task_id = pt.task_id) as file_count,
        (SELECT COUNT(*) FROM project_milestones pm WHERE pm.milestone_id = pt.milestone_id) as has_milestone
    FROM project_tasks pt
    INNER JOIN internal_projects ip ON pt.internal_project_id = ip.internal_project_id
    LEFT JOIN users u ON pt.assigned_by = u.user_id
    WHERE pt.assigned_to = ?
";

$params = [$user_id];

// Apply status filter
if (!empty($status)) {
    if ($status === 'overdue') {
        $query .= " AND pt.due_date < CURDATE() AND pt.status != 'completed'";
    } elseif ($status === 'due_today') {
        $query .= " AND DATE(pt.due_date) = CURDATE() AND pt.status != 'completed'";
    } elseif ($status === 'upcoming') {
        $query .= " AND pt.due_date > CURDATE() AND pt.status != 'completed'";
    } else {
        $query .= " AND pt.status = ?";
        $params[] = $status;
    }
}

// Apply priority filter
if (!empty($priority)) {
    $query .= " AND pt.priority = ?";
    $params[] = $priority;
}

// Apply project filter
if (!empty($project_id)) {
    $query .= " AND pt.internal_project_id = ?";
    $params[] = $project_id;
}

// Apply search filter
if (!empty($search)) {
    $query .= " AND (pt.task_name LIKE ? OR pt.description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
}

// Apply "my" filter (tasks assigned to me - already applied)
if ($filter === 'completed') {
    $query .= " AND pt.status = 'completed'";
} elseif ($filter === 'pending') {
    $query .= " AND pt.status IN ('pending', 'in_progress', 'review')";
}

$query .= " ORDER BY 
            CASE pt.status
                WHEN 'pending' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'review' THEN 3
                WHEN 'completed' THEN 4
                ELSE 5
            END,
            CASE pt.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END,
            pt.due_date ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// Get task statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
        SUM(CASE WHEN status = 'review' THEN 1 ELSE 0 END) as review_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN due_date < CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as overdue_tasks,
        SUM(CASE WHEN DATE(due_date) = CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as due_today,
        SUM(CASE WHEN due_date IS NOT NULL AND status != 'completed' THEN 1 ELSE 0 END) as has_deadline
    FROM project_tasks
    WHERE assigned_to = ?
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

// Handle task status update via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        $task_id = $_POST['task_id'] ?? 0;
        
        // Verify task belongs to user
        $stmt = $db->prepare("SELECT task_id FROM project_tasks WHERE task_id = ? AND assigned_to = ?");
        $stmt->execute([$task_id, $user_id]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit();
        }
        
        switch ($_POST['action']) {
            case 'update_status':
                $new_status = $_POST['status'] ?? '';
                if (in_array($new_status, ['pending', 'in_progress', 'review', 'completed'])) {
                    $stmt = $db->prepare("UPDATE project_tasks SET status = ?, updated_at = NOW() WHERE task_id = ?");
                    $stmt->execute([$new_status, $task_id]);
                    
                    // If completed, set completed date
                    if ($new_status === 'completed') {
                        $stmt = $db->prepare("UPDATE project_tasks SET completed_date = CURDATE() WHERE task_id = ?");
                        $stmt->execute([$task_id]);
                    }
                    
                    // Log activity
                    $stmt = $db->prepare("
                        INSERT INTO activity_logs (user_id, activity_type, description, ip_address, user_agent)
                        VALUES (?, 'task', ?, ?, ?)
                    ");
                    $description = "Updated task status to $new_status for task ID: $task_id";
                    $stmt->execute([$user_id, $description, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
                    
                    echo json_encode(['success' => true]);
                }
                break;
                
            case 'update_hours':
                $hours = $_POST['hours'] ?? 0;
                $stmt = $db->prepare("UPDATE project_tasks SET actual_hours = actual_hours + ? WHERE task_id = ?");
                $stmt->execute([$hours, $task_id]);
                echo json_encode(['success' => true]);
                break;
                
            case 'add_comment':
                $comment = $_POST['comment'] ?? '';
                // You might want to create a task_comments table for this
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
    <title>My Tasks | Developer Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Tasks Page Specific Styles */
        .tasks-page {
            animation: fadeIn 0.5s ease-out;
        }

        /* Header */
        .tasks-header {
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

        /* Tasks Grid */
        .tasks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            animation: fadeIn 0.5s ease-out;
        }

        .task-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            animation: slideInUp 0.5s ease-out;
            animation-fill-mode: both;
        }

        .task-card:nth-child(1) { animation-delay: 0.05s; }
        .task-card:nth-child(2) { animation-delay: 0.1s; }
        .task-card:nth-child(3) { animation-delay: 0.15s; }
        .task-card:nth-child(4) { animation-delay: 0.2s; }
        .task-card:nth-child(5) { animation-delay: 0.25s; }
        .task-card:nth-child(6) { animation-delay: 0.3s; }
        .task-card:nth-child(7) { animation-delay: 0.35s; }
        .task-card:nth-child(8) { animation-delay: 0.4s; }

        .task-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 30px rgba(0,0,0,0.15);
            border-color: #000;
        }

        .task-card::after {
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

        .task-card:hover::after {
            transform: translateX(100%);
        }

        .task-header {
            padding: 1.25rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            background: #fafafa;
        }

        .task-project {
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

        .task-priority {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .task-priority.urgent {
            background: rgba(244, 67, 54, 0.15);
            color: #f44336;
            animation: pulse 2s infinite;
        }

        .task-priority.high {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
        }

        .task-priority.medium {
            background: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        .task-priority.low {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; transform: scale(1.05); }
            100% { opacity: 1; }
        }

        .task-body {
            padding: 1.25rem;
        }

        .task-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #000;
            margin-bottom: 0.75rem;
            line-height: 1.4;
        }

        .task-description {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 1rem;
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .task-meta {
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

        .task-card:hover .meta-item i {
            transform: scale(1.2);
        }

        .meta-item .deadline {
            font-weight: 600;
        }

        .meta-item .deadline.overdue {
            color: #f44336;
            animation: shake 0.5s ease-in-out;
        }

        .meta-item .deadline.today {
            color: #ff9800;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .milestone-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background: rgba(156, 39, 176, 0.1);
            color: #9c27b0;
            border-radius: 12px;
            font-size: 0.7rem;
        }

        /* Progress Section */
        .progress-section {
            margin-top: 1rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 0.25rem;
        }

        .progress-bar-container {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #000, #333);
            border-radius: 3px;
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

        .time-log {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            font-size: 0.7rem;
            color: #666;
        }

        .time-log i {
            color: #999;
        }

        .task-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .task-status {
            padding: 0.35rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .task-status.pending {
            background: #e0e0e0;
            color: #666;
        }

        .task-status.in_progress {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
        }

        .task-status.in_progress i {
            animation: spin 2s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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

        .btn-icon.danger:hover {
            background: #f44336;
            border-color: #f44336;
        }

        /* Time Tracker Modal */
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
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: #000;
            transform: rotate(90deg);
        }

        .timer-display {
            font-size: 3rem;
            font-weight: 700;
            text-align: center;
            color: #000;
            margin: 2rem 0;
            font-family: monospace;
            animation: pulse 2s infinite;
        }

        .timer-controls {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-bottom: 2rem;
        }

        .timer-btn {
            width: 60px;
            height: 60px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .timer-btn:hover {
            transform: scale(1.1);
        }

        .timer-btn.start {
            background: #00c853;
            color: white;
        }

        .timer-btn.pause {
            background: #ff9800;
            color: white;
        }

        .timer-btn.reset {
            background: #f44336;
            color: white;
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
            
            .tasks-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                width: 100%;
            }
            
            .task-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .task-actions {
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
            
            .task-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .timer-display {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dev-header.php'; ?>
    
    <div class="admin-container">
        <?php include '../../includes/dev-sidebar.php'; ?>
        
        <main class="admin-main">
            <div class="tasks-page">
                <!-- Header -->
                <div class="tasks-header">
                    <h1 class="page-title">
                        <i class="fas fa-tasks"></i> My Tasks
                    </h1>
                    <div class="header-actions">
                        <a href="?export=1" class="btn btn-outline btn-sm">
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
                    <div class="stat-card" onclick="location.href='?status=pending'">
                        <div class="stat-value"><?php echo $stats['pending_tasks'] ?? 0; ?></div>
                        <div class="stat-label">Pending</div>
                        <div class="stat-trend warning">
                            <i class="fas fa-clock"></i> Awaiting action
                        </div>
                    </div>
                    
                    <div class="stat-card" onclick="location.href='?status=in_progress'">
                        <div class="stat-value"><?php echo $stats['in_progress_tasks'] ?? 0; ?></div>
                        <div class="stat-label">In Progress</div>
                        <div class="stat-trend">
                            <i class="fas fa-play"></i> Working now
                        </div>
                    </div>
                    
                    <div class="stat-card" onclick="location.href='?status=review'">
                        <div class="stat-value"><?php echo $stats['review_tasks'] ?? 0; ?></div>
                        <div class="stat-label">In Review</div>
                        <div class="stat-trend">
                            <i class="fas fa-search"></i> Need verification
                        </div>
                    </div>
                    
                    <div class="stat-card" onclick="location.href='?status=completed'">
                        <div class="stat-value"><?php echo $stats['completed_tasks'] ?? 0; ?></div>
                        <div class="stat-label">Completed</div>
                        <div class="stat-trend success">
                            <i class="fas fa-check-circle"></i> Done
                        </div>
                    </div>
                    
                    <?php if (($stats['overdue_tasks'] ?? 0) > 0): ?>
                        <div class="stat-card" onclick="location.href='?status=overdue'">
                            <div class="stat-value" style="color: #f44336;"><?php echo $stats['overdue_tasks']; ?></div>
                            <div class="stat-label">Overdue</div>
                            <div class="stat-trend danger">
                                <i class="fas fa-exclamation-triangle"></i> Needs attention
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (($stats['due_today'] ?? 0) > 0): ?>
                        <div class="stat-card" onclick="location.href='?status=due_today'">
                            <div class="stat-value" style="color: #ff9800;"><?php echo $stats['due_today']; ?></div>
                            <div class="stat-label">Due Today</div>
                            <div class="stat-trend warning">
                                <i class="fas fa-calendar-day"></i> Complete today
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-tabs">
                        <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">All Tasks</a>
                        <a href="?filter=pending" class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">Pending</a>
                        <a href="?status=in_progress" class="filter-tab <?php echo $status === 'in_progress' ? 'active' : ''; ?>">In Progress</a>
                        <a href="?status=review" class="filter-tab <?php echo $status === 'review' ? 'active' : ''; ?>">Review</a>
                        <a href="?filter=completed" class="filter-tab <?php echo $filter === 'completed' ? 'active' : ''; ?>">Completed</a>
                        <?php if (($stats['overdue_tasks'] ?? 0) > 0): ?>
                            <a href="?status=overdue" class="filter-tab danger <?php echo $status === 'overdue' ? 'active' : ''; ?>">Overdue</a>
                        <?php endif; ?>
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
                        
                        <select class="filter-select" id="priorityFilter" onchange="applyFilters()">
                            <option value="">All Priorities</option>
                            <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                            <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                        
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" 
                                   id="searchInput"
                                   placeholder="Search tasks..." 
                                   value="<?php echo e($search); ?>"
                                   autocomplete="off">
                        </div>
                    </div>
                </div>

                <!-- Tasks Grid -->
                <?php if (!empty($tasks)): ?>
                    <div class="tasks-grid">
                        <?php foreach ($tasks as $task): 
                            // Calculate progress (if task has estimated hours)
                            $progress = 0;
                            if ($task['status'] === 'completed') {
                                $progress = 100;
                            } elseif ($task['status'] === 'in_progress' && $task['estimated_hours'] > 0) {
                                $progress = min(round(($task['actual_hours'] / $task['estimated_hours']) * 100), 99);
                            }
                            
                            // Check deadline status
                            $deadline_class = '';
                            $deadline_text = '';
                            if ($task['due_date']) {
                                $due = new DateTime($task['due_date']);
                                $today = new DateTime();
                                if ($due < $today && $task['status'] != 'completed') {
                                    $deadline_class = 'overdue';
                                    $deadline_text = 'Overdue';
                                } elseif ($due->format('Y-m-d') === $today->format('Y-m-d') && $task['status'] != 'completed') {
                                    $deadline_class = 'today';
                                    $deadline_text = 'Due Today';
                                } else {
                                    $deadline_text = formatDate($task['due_date'], 'M d');
                                }
                            }
                        ?>
                            <div class="task-card" data-task-id="<?php echo $task['task_id']; ?>">
                                <div class="task-header">
                                    <div class="task-project">
                                        <span class="project-name"><?php echo e($task['project_name']); ?></span>
                                        <span class="project-code"><?php echo e($task['project_code']); ?></span>
                                    </div>
                                    <span class="task-priority <?php echo $task['priority']; ?>">
                                        <?php if ($task['priority'] === 'urgent'): ?>
                                            <i class="fas fa-exclamation-circle"></i>
                                        <?php elseif ($task['priority'] === 'high'): ?>
                                            <i class="fas fa-arrow-up"></i>
                                        <?php elseif ($task['priority'] === 'medium'): ?>
                                            <i class="fas fa-minus"></i>
                                        <?php else: ?>
                                            <i class="fas fa-arrow-down"></i>
                                        <?php endif; ?>
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                </div>
                                
                                <div class="task-body">
                                    <h3 class="task-title"><?php echo e($task['task_name']); ?></h3>
                                    <p class="task-description"><?php echo e($task['description'] ?? 'No description provided.'); ?></p>
                                    
                                    <div class="task-meta">
                                        <?php if ($task['due_date']): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-calendar"></i>
                                                <span class="deadline <?php echo $deadline_class; ?>" title="<?php echo $deadline_text; ?>">
                                                    <?php echo $deadline_text; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($task['assigned_by_name']): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-user-tie"></i>
                                                <span title="Assigned by <?php echo e($task['assigned_by_name']); ?>">
                                                    <?php echo e($task['assigned_by_name']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($task['has_milestone']): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-flag"></i>
                                                <span>Has Milestone</span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($task['file_count'] > 0): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-paperclip"></i>
                                                <span><?php echo $task['file_count']; ?> file(s)</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($task['estimated_hours'] > 0 || $task['actual_hours'] > 0): ?>
                                        <div class="progress-section">
                                            <div class="progress-header">
                                                <span>Progress</span>
                                                <span><?php echo $progress; ?>%</span>
                                            </div>
                                            <div class="progress-bar-container">
                                                <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                            <div class="time-log">
                                                <span><i class="far fa-clock"></i> Est: <?php echo $task['estimated_hours']; ?>h</span>
                                                <span><i class="fas fa-clock"></i> Actual: <?php echo $task['actual_hours'] ?? 0; ?>h</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="task-footer">
                                    <div class="task-status <?php echo $task['status']; ?>">
                                        <?php if ($task['status'] === 'in_progress'): ?>
                                            <i class="fas fa-spinner"></i>
                                        <?php elseif ($task['status'] === 'review'): ?>
                                            <i class="fas fa-search"></i>
                                        <?php elseif ($task['status'] === 'completed'): ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php endif; ?>
                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </div>
                                    
                                    <div class="task-actions">
                                        <?php if ($task['status'] !== 'completed'): ?>
                                            <?php if ($task['status'] === 'pending'): ?>
                                                <button class="btn-icon success" onclick="updateTaskStatus(<?php echo $task['task_id']; ?>, 'in_progress')" title="Start Task">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                            <?php elseif ($task['status'] === 'in_progress'): ?>
                                                <button class="btn-icon warning" onclick="updateTaskStatus(<?php echo $task['task_id']; ?>, 'review')" title="Mark for Review">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                            <?php elseif ($task['status'] === 'review'): ?>
                                                <button class="btn-icon success" onclick="updateTaskStatus(<?php echo $task['task_id']; ?>, 'completed')" title="Complete Task">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn-icon" onclick="openTimer(<?php echo $task['task_id']; ?>)" title="Track Time">
                                                <i class="fas fa-stopwatch"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button class="btn-icon" onclick="viewTaskDetails(<?php echo $task['task_id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <button class="btn-icon" onclick="addComment(<?php echo $task['task_id']; ?>)" title="Add Comment">
                                            <i class="fas fa-comment"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if (count($tasks) > 20): ?>
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
                        <i class="fas fa-tasks"></i>
                        <h3>No Tasks Found</h3>
                        <p>You don't have any tasks assigned yet, or no tasks match your filters.</p>
                        <?php if (!empty($search) || !empty($status) || !empty($priority) || !empty($project_id)): ?>
                            <a href="tasks.php" class="btn btn-primary">Clear Filters</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Time Tracker Modal -->
    <div class="modal" id="timerModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Track Time</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="timer-display" id="timerDisplay">00:00:00</div>
            <div class="timer-controls">
                <button class="timer-btn start" onclick="startTimer()">
                    <i class="fas fa-play"></i>
                </button>
                <button class="timer-btn pause" onclick="pauseTimer()">
                    <i class="fas fa-pause"></i>
                </button>
                <button class="timer-btn reset" onclick="resetTimer()">
                    <i class="fas fa-stop"></i>
                </button>
            </div>
            <div style="text-align: center; margin-top: 1rem;">
                <button class="btn btn-primary" onclick="saveTime()">Save Time</button>
            </div>
        </div>
    </div>

    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script>
        // Timer variables
        let currentTaskId = null;
        let timerInterval = null;
        let seconds = 0;
        let isRunning = false;
        
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
        });

        // Apply filters
        function applyFilters() {
            const project = document.getElementById('projectFilter').value;
            const priority = document.getElementById('priorityFilter').value;
            const search = document.getElementById('searchInput').value;
            
            let url = 'tasks.php?';
            if (project) url += `project_id=${project}&`;
            if (priority) url += `priority=${priority}&`;
            if (search) url += `search=${encodeURIComponent(search)}&`;
            
            // Remove trailing & or ?
            url = url.replace(/[&?]$/, '');
            
            window.location.href = url;
        }

        // Update task status
        function updateTaskStatus(taskId, status) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'update_status');
            formData.append('task_id', taskId);
            formData.append('status', status);
            
            fetch('tasks.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success notification
                    showNotification('Task status updated successfully', 'success');
                    
                    // Update UI
                    const taskCard = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                    if (taskCard) {
                        const statusEl = taskCard.querySelector('.task-status');
                        statusEl.className = `task-status ${status}`;
                        
                        if (status === 'completed') {
                            statusEl.innerHTML = '<i class="fas fa-check-circle"></i> Completed';
                        } else if (status === 'in_progress') {
                            statusEl.innerHTML = '<i class="fas fa-spinner"></i> In Progress';
                        } else if (status === 'review') {
                            statusEl.innerHTML = '<i class="fas fa-search"></i> Review';
                        }
                        
                        // Update action buttons
                        updateTaskActions(taskCard, status);
                    }
                } else {
                    showNotification('Failed to update task status', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        }

        // Update task actions based on status
        function updateTaskActions(taskCard, status) {
            const actionsDiv = taskCard.querySelector('.task-actions');
            const taskId = taskCard.dataset.taskId;
            
            let actions = '';
            
            if (status !== 'completed') {
                if (status === 'pending') {
                    actions = `
                        <button class="btn-icon success" onclick="updateTaskStatus(${taskId}, 'in_progress')" title="Start Task">
                            <i class="fas fa-play"></i>
                        </button>
                    `;
                } else if (status === 'in_progress') {
                    actions = `
                        <button class="btn-icon warning" onclick="updateTaskStatus(${taskId}, 'review')" title="Mark for Review">
                            <i class="fas fa-search"></i>
                        </button>
                    `;
                } else if (status === 'review') {
                    actions = `
                        <button class="btn-icon success" onclick="updateTaskStatus(${taskId}, 'completed')" title="Complete Task">
                            <i class="fas fa-check"></i>
                        </button>
                    `;
                }
                
                actions += `
                    <button class="btn-icon" onclick="openTimer(${taskId})" title="Track Time">
                        <i class="fas fa-stopwatch"></i>
                    </button>
                `;
            }
            
            actions += `
                <button class="btn-icon" onclick="viewTaskDetails(${taskId})" title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn-icon" onclick="addComment(${taskId})" title="Add Comment">
                    <i class="fas fa-comment"></i>
                </button>
            `;
            
            actionsDiv.innerHTML = actions;
        }

        // View task details
        function viewTaskDetails(taskId) {
            window.location.href = `task-details.php?id=${taskId}`;
        }

        // Open timer modal
        function openTimer(taskId) {
            currentTaskId = taskId;
            document.getElementById('timerModal').classList.add('active');
            resetTimer();
        }

        // Close modal
        function closeModal() {
            document.getElementById('timerModal').classList.remove('active');
            pauseTimer();
        }

        // Timer functions
        function startTimer() {
            if (!isRunning) {
                isRunning = true;
                timerInterval = setInterval(() => {
                    seconds++;
                    updateTimerDisplay();
                }, 1000);
            }
        }

        function pauseTimer() {
            if (isRunning) {
                isRunning = false;
                clearInterval(timerInterval);
            }
        }

        function resetTimer() {
            pauseTimer();
            seconds = 0;
            updateTimerDisplay();
        }

        function updateTimerDisplay() {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            
            const display = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            document.getElementById('timerDisplay').textContent = display;
        }

        // Save tracked time
        function saveTime() {
            if (seconds === 0) {
                closeModal();
                return;
            }
            
            const hours = seconds / 3600;
            
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'update_hours');
            formData.append('task_id', currentTaskId);
            formData.append('hours', hours.toFixed(2));
            
            fetch('tasks.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`Added ${hours.toFixed(2)} hours to task`, 'success');
                    closeModal();
                    
                    // Reload to show updated hours
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Failed to save time', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        }

        // Add comment
        function addComment(taskId) {
            const comment = prompt('Enter your comment:');
            if (comment) {
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('action', 'add_comment');
                formData.append('task_id', taskId);
                formData.append('comment', comment);
                
                fetch('tasks.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Comment added successfully', 'success');
                    } else {
                        showNotification('Failed to add comment', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
            }
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
            
            // Space to start/pause timer when modal is open
            if (e.key === ' ' && document.getElementById('timerModal').classList.contains('active')) {
                e.preventDefault();
                if (isRunning) {
                    pauseTimer();
                } else {
                    startTimer();
                }
            }
        });
    </script>
</body>
</html>