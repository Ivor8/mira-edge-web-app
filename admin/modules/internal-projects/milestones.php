<?php
/**
 * Manage Project Milestones
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
    // If no project selected, show a simple project selector instead of redirecting
    $stmt = $db->query("SELECT internal_project_id, project_name, project_code FROM internal_projects ORDER BY project_name");
    $projects = $stmt->fetchAll();

    // Render selector UI
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <title>Select Project | Milestones</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </head>
    <body>
        <?php include '../../includes/admin-header.php'; ?>
        <div class="admin-container">
            <?php include '../../includes/admin-sidebar.php'; ?>
            <main class="admin-main">
                <div class="page-header">
                    <h1 class="page-title"><i class="fas fa-flag-checkered"></i> Select Project</h1>
                    <div class="page-actions"><a href="<?php echo url('/admin/modules/internal-projects/index.php'); ?>" class="btn btn-outline">Back</a></div>
                </div>

                <div class="form-section">
                    <h3>Choose a project to manage milestones</h3>
                    <ul class="project-list">
                        <?php foreach ($projects as $pr): ?>
                            <li><a href="<?php echo url('/admin/modules/internal-projects/milestones.php?project_id=' . $pr['internal_project_id']); ?>"><?php echo e($pr['project_name']); ?> (<?php echo e($pr['project_code']); ?>)</a></li>
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

$errors = [];

// Add milestone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_milestone'])) {
    $name = trim($_POST['milestone_name'] ?? '');
    $due = $_POST['due_date'] ?? null;
    if (empty($name)) $errors[] = 'Milestone name required';
    if (empty($errors)) {
        $stmt = $db->prepare("INSERT INTO project_milestones (internal_project_id, milestone_name, description, due_date) VALUES (?, ?, ?, ?)");
        $stmt->execute([$project_id, $name, $_POST['description'] ?? null, $due]);
        $session->setFlash('success', 'Milestone added.');
        redirect(url('/admin/modules/internal_projects/milestones.php?project_id=' . $project_id));
    }
}

// Toggle complete / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'toggle' && isset($_POST['milestone_id'])) {
            $stmt = $db->prepare("UPDATE project_milestones SET is_completed = NOT is_completed WHERE milestone_id = ?");
            $stmt->execute([$_POST['milestone_id']]);
            $session->setFlash('success', 'Milestone updated.');
        }
        if ($_POST['action'] === 'delete' && isset($_POST['milestone_id'])) {
            $stmt = $db->prepare("DELETE FROM project_milestones WHERE milestone_id = ?");
            $stmt->execute([$_POST['milestone_id']]);
            $session->setFlash('success', 'Milestone deleted.');
        }
    } catch (PDOException $e) {
        error_log('Milestone action: ' . $e->getMessage());
        $session->setFlash('error', 'Error performing action.');
    }
    redirect(url('/admin/modules/internal_projects/milestones.php?project_id=' . $project_id));
}

$stmt = $db->prepare("SELECT * FROM project_milestones WHERE internal_project_id = ? ORDER BY created_at DESC");
$stmt->execute([$project_id]);
$milestones = $stmt->fetchAll();

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
    <title>Project Milestones | Admin</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-flag-checkered"></i> Milestones for <?php echo e($project['project_name']); ?></h1>
                <div class="page-actions"><a href="<?php echo url('/admin/modules/internal_projects/index.php'); ?>" class="btn btn-outline">Back</a></div>
            </div>

            <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>', $errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>

            <div class="form-section">
                <h3>Add Milestone</h3>
                <form method="post">
                    <label>Milestone Name</label>
                    <input type="text" name="milestone_name" required>
                    <label>Due Date</label>
                    <input type="date" name="due_date">
                    <label>Description</label>
                    <textarea name="description"></textarea>
                    <input type="hidden" name="add_milestone" value="1">
                    <button class="btn btn-primary" type="submit">Add</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Name</th><th>Due</th><th>Completed</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($milestones as $m): ?>
                            <tr>
                                <td><?php echo e($m['milestone_name']); ?></td>
                                <td><?php echo e($m['due_date']); ?></td>
                                <td><?php echo $m['is_completed'] ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="milestone_id" value="<?php echo $m['milestone_id']; ?>">
                                        <button class="btn" type="submit" name="action" value="toggle">Toggle</button>
                                    </form>
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="milestone_id" value="<?php echo $m['milestone_id']; ?>">
                                        <button class="btn btn-danger" type="submit" name="action" value="delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
    <script src="<?php echo('assets/js/admin.js'); ?>"></script>
</body>
</html>