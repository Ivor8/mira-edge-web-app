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
        <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    </head>
    <body>
        <?php include '../../includes/admin-header.php'; ?>
        <div class="admin-container">
            <?php include '../../includes/admin-sidebar.php'; ?>
            <main class="admin-main">
                <div class="page-header">
                    <h1 class="page-title"><i class="fas fa-tasks"></i> Select Project</h1>
                    <div class="page-actions"><a href="<?php echo url('/admin/modules/internal-projects/index.php'); ?>" class="btn btn-outline">Back</a></div>
                </div>

                <div class="form-section">
                    <h3>Choose a project to manage tasks</h3>
                    <ul class="project-list">
                        <?php foreach ($projects as $pr): ?>
                            <li><a href="<?php echo url('/admin/modules/internal-projects/tasks.php?project_id=' . $pr['internal_project_id']); ?>"><?php echo e($pr['project_name']); ?> (<?php echo e($pr['project_code']); ?>)</a></li>
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
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
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
                <h3>Add Task</h3>
                <form method="post">
                    <label>Task Name</label>
                    <input type="text" name="task_name" required>
                    <label>Milestone</label>
                    <select name="milestone_id">
                        <option value="">-- None --</option>
                        <?php foreach ($milestones as $m): ?>
                            <option value="<?php echo $m['milestone_id']; ?>"><?php echo e($m['milestone_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Assign To</label>
                    <select name="assigned_to">
                        <option value="">-- Select --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['user_id']; ?>"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Due Date</label>
                    <input type="date" name="due_date">
                    <label>Description</label>
                    <textarea name="description"></textarea>
                    <input type="hidden" name="add_task" value="1">
                    <button class="btn btn-primary" type="submit">Add Task</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Name</th><th>Milestone</th><th>Assignee</th><th>Due</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td><?php echo e($t['task_name']); ?></td>
                                <td><?php echo e($t['milestone_id']); ?></td>
                                <td><?php echo e($t['assignee'] ?? '-'); ?></td>
                                <td><?php echo e($t['due_date']); ?></td>
                                <td><?php echo e($t['status']); ?></td>
                                <td>
                                    <form method="post" style="display:inline-block;"><input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>"><button class="btn" type="submit" name="action" value="toggle">Toggle</button></form>
                                    <form method="post" style="display:inline-block;"><input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>"><button class="btn btn-danger" type="submit" name="action" value="delete">Delete</button></form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</body>
</html>