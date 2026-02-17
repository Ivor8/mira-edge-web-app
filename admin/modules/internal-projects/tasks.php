<?php
/**
 * Project Tasks Management
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}
$user = $session->getUser();

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    // If no project selected, show project selector
    $stmt = $db->query("SELECT internal_project_id, project_name, project_code FROM internal_projects ORDER BY project_name");
    $projects = $stmt->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <title>Select Project | Tasks</title>
        <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </head>
    <body>
        <?php include '../../includes/admin-header.php'; ?>
        <div class="admin-container">
            <?php include '../../includes/admin-sidebar.php'; ?>
            <main class="admin-main">
                <div class="page-header">
                    <h1 class="page-title"><i class="fas fa-tasks"></i> Select Project</h1>
                    <div class="page-actions"><a href="<?php echo url('admin/modules/internal-projects/index.php'); ?>" class="btn btn-outline">Back</a></div>
                </div>

                <div class="form-section">
                    <h3>Choose a project to manage tasks</h3>
                    <ul class="project-list">
                        <?php foreach ($projects as $pr): ?>
                            <li><a href="<?php echo url('admin/modules/internal-projects/tasks.php?project_id=' . $pr['internal_project_id']); ?>"><?php echo e($pr['project_name']); ?> (<?php echo e($pr['project_code']); ?>)</a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </main>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Fetch users for assignment
$stmt = $db->query("SELECT user_id, first_name, last_name FROM users ORDER BY first_name");
$users = $stmt->fetchAll();

$errors = [];

// Add task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
    $name = trim($_POST['task_name'] ?? '');
    $milestone_id = $_POST['milestone_id'] ?? null;
    $assigned_to = $_POST['assigned_to'] ?? null;
    $due_date = $_POST['due_date'] ?? null;
    if (empty($name)) $errors[] = 'Task name required';
    if (empty($errors)) {
        $stmt = $db->prepare("INSERT INTO project_tasks (internal_project_id, milestone_id, task_name, description, assigned_to, assigned_by, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$project_id, $milestone_id, $name, $_POST['description'] ?? null, $assigned_to, $session->getUser()['user_id'], $due_date]);
        $session->setFlash('success', 'Task added.');
        redirect(url('/admin/modules/internal_projects/tasks.php?project_id=' . $project_id));
    }
}

// Fetch milestones for project
$stmt = $db->prepare("SELECT milestone_id, milestone_name FROM project_milestones WHERE internal_project_id = ? ORDER BY display_order");
$stmt->execute([$project_id]);
$milestones = $stmt->fetchAll();

// Handle actions (toggle complete, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'toggle' && isset($_POST['task_id'])) {
            $stmt = $db->prepare("UPDATE project_tasks SET status = (CASE WHEN status = 'completed' THEN 'pending' ELSE 'completed' END) WHERE task_id = ?");
            $stmt->execute([$_POST['task_id']]);
            $session->setFlash('success', 'Task status toggled.');
        }
        if ($_POST['action'] === 'delete' && isset($_POST['task_id'])) {
            $stmt = $db->prepare("DELETE FROM project_tasks WHERE task_id = ?");
            $stmt->execute([$_POST['task_id']]);
            $session->setFlash('success', 'Task deleted.');
        }
    } catch (PDOException $e) {
        error_log('Task action: ' . $e->getMessage());
        $session->setFlash('error', 'Error performing action.');
    }
    redirect(url('/admin/modules/internal_projects/tasks.php?project_id=' . $project_id));
}

$stmt = $db->prepare("SELECT pt.*, CONCAT(u.first_name,' ',u.last_name) as assignee FROM project_tasks pt LEFT JOIN users u ON pt.assigned_to = u.user_id WHERE pt.internal_project_id = ? ORDER BY pt.created_at DESC");
$stmt->execute([$project_id]);
$tasks = $stmt->fetchAll();

// Fetch project
$stmt = $db->prepare("SELECT * FROM internal_projects WHERE internal_project_id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Project Tasks | Admin</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-section {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-gray-200);
        }
        
        .form-section h3 {
            margin-top: 0;
            margin-bottom: var(--space-md);
            color: var(--color-gray-900);
            font-size: 1.125rem;
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .form-section h3 i {
            color: var(--color-primary);
        }
        
        .form-group {
            margin-bottom: var(--space-lg);
        }
        
        .form-group label {
            display: block;
            margin-bottom: var(--space-sm);
            font-weight: 500;
            color: var(--color-gray-700);
            font-size: 0.95rem;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: var(--space-md);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.95rem;
            transition: border-color var(--transition-normal), box-shadow var(--transition-normal);
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-50);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--space-lg);
        }
        
        .task-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-md);
            border-bottom: 1px solid var(--color-gray-200);
            transition: background-color var(--transition-normal);
        }
        
        .task-row:hover {
            background-color: var(--color-gray-50);
        }
        
        .task-row.completed .task-name {
            text-decoration: line-through;
            color: var(--color-gray-500);
        }
        
        .task-info {
            flex: 1;
        }
        
        .task-name {
            font-weight: 500;
            color: var(--color-gray-900);
            margin-bottom: var(--space-xs);
        }
        
        .task-meta {
            display: flex;
            gap: var(--space-md);
            font-size: 0.875rem;
            color: var(--color-gray-600);
            flex-wrap: wrap;
        }
        
        .task-meta span {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }
        
        .task-actions {
            display: flex;
            gap: var(--space-sm);
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .task-row {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .task-actions {
                margin-top: var(--space-md);
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-tasks"></i> Tasks for <?php echo e($project['project_name']); ?></h1>
                <div class="page-actions"><a href="<?php echo url('/admin/modules/internal_projects/index.php'); ?>" class="btn btn-outline">Back</a></div>
            </div>

            <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>', $errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>

            <div class="form-section">
                <h3><i class="fas fa-plus-circle"></i> Add Task</h3>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Task Name</label>
                            <input type="text" name="task_name" required>
                        </div>
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" name="due_date">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Milestone</label>
                            <select name="milestone_id">
                                <option value="">-- None --</option>
                                <?php foreach ($milestones as $m): ?>
                                    <option value="<?php echo $m['milestone_id']; ?>"><?php echo e($m['milestone_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Assign To</label>
                            <select name="assigned_to">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo $u['user_id']; ?>"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description"></textarea>
                    </div>
                    <input type="hidden" name="add_task" value="1">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Add Task</button>
                </form>
            </div>

            <div class="form-section">
                <h3><i class="fas fa-list-check"></i> Tasks (<?php echo count($tasks); ?>)</h3>
                <?php if (empty($tasks)): ?>
                    <p class="text-gray-500">No tasks yet. Create one to get started.</p>
                <?php else: ?>
                    <div class="tasks-list">
                        <?php foreach ($tasks as $t): ?>
                            <div class="task-row <?php echo $t['status'] === 'completed' ? 'completed' : ''; ?>">
                                <div class="task-info">
                                    <div class="task-name"><?php echo e($t['task_name']); ?></div>
                                    <div class="task-meta">
                                        <?php if ($t['assigned_to']): ?><span><i class="fas fa-user"></i> <?php echo e($t['assignee'] ?? 'Unassigned'); ?></span><?php endif; ?>
                                        <?php if ($t['due_date']): ?><span><i class="fas fa-calendar"></i> <?php echo formatDate($t['due_date'], 'M d, Y'); ?></span><?php endif; ?>
                                        <span><i class="fas fa-circle" style="font-size: 0.5rem; color: <?php echo match($t['status']) { 'completed' => '#10b981', 'in_progress' => '#f59e0b', default => '#6b7280' }; ?>"></i> <?php echo ucfirst($t['status']); ?></span>
                                    </div>
                                </div>
                                <div class="task-actions">
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                                        <button class="btn btn-sm <?php echo $t['status'] === 'completed' ? 'btn-outline' : 'btn-success'; ?>" type="submit" name="action" value="toggle" title="<?php echo $t['status'] === 'completed' ? 'Mark Incomplete' : 'Mark Complete'; ?>"><i class="fas fa-<?php echo $t['status'] === 'completed' ? 'undo' : 'check'; ?>"></i></button>
                                    </form>
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                                        <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
</body>
</html>