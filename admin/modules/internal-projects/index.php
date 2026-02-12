<?php
/**
 * Internal Projects - All
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

// Bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_projects'])) {
    $action = $_POST['bulk_action'];
    $selected = $_POST['selected_projects'];
    try {
        $placeholders = implode(',', array_fill(0, count($selected), '?'));
        switch ($action) {
            case 'delete':
                $stmt = $db->prepare("DELETE FROM internal_projects WHERE internal_project_id IN ($placeholders)");
                $stmt->execute($selected);
                $session->setFlash('success', 'Selected projects deleted.');
                break;
            case 'complete':
                $stmt = $db->prepare("UPDATE internal_projects SET status = 'completed' WHERE internal_project_id IN ($placeholders)");
                $stmt->execute($selected);
                $session->setFlash('success', 'Selected projects marked completed.');
                break;
        }
    } catch (PDOException $e) {
        error_log('Internal projects bulk: ' . $e->getMessage());
        $session->setFlash('error', 'Error performing bulk action.');
    }
}

// Fetch projects with lead name
$sql = "SELECT ip.*, CONCAT(u.first_name, ' ', u.last_name) as creator_name FROM internal_projects ip LEFT JOIN users u ON ip.created_by = u.user_id ORDER BY ip.created_at DESC";
$stmt = $db->query($sql);
$projects = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Internal Projects | Admin</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-project-diagram"></i> Internal Projects</h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/internal_projects/add.php'); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> New Project</a>
                </div>
            </div>

            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                    <?php if ($session->hasFlash('error')): ?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>Project Code</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Created By</th>
                                <th>Deadline</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $p): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_projects[]" value="<?php echo $p['internal_project_id']; ?>"></td>
                                    <td><?php echo e($p['project_code']); ?></td>
                                    <td><?php echo e($p['project_name']); ?></td>
                                    <td><?php echo e($p['status']); ?></td>
                                    <td><?php echo e($p['priority']); ?></td>
                                    <td><?php echo e($p['creator_name'] ?? '-'); ?></td>
                                    <td><?php echo e($p['deadline']); ?></td>
                                    <td>
                                        <a href="<?php echo url('/admin/modules/internal_projects/add.php?id=' . $p['internal_project_id']); ?>" class="btn btn-sm">Edit</a>
                                        <a href="<?php echo url('/admin/modules/internal_projects/milestones.php?project_id=' . $p['internal_project_id']); ?>" class="btn btn-sm">Milestones</a>
                                        <a href="<?php echo url('/admin/modules/internal_projects/tasks.php?project_id=' . $p['internal_project_id']); ?>" class="btn btn-sm">Tasks</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bulk-actions">
                    <select name="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="complete">Mark Completed</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button class="btn" type="submit">Apply</button>
                </div>
            </form>
        </main>
    </div>
    <script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
</body>
</html>