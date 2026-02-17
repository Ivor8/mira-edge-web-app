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
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .projects-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-lg);
            margin-bottom: var(--space-xl);
        }
        
        .stat-card {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-md);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-gray-200);
            transition: all var(--transition-normal);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card i {
            font-size: 1.5rem;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md);
            background: var(--color-primary-50);
            color: var(--color-primary);
        }
        
        .stat-value {
            display: flex;
            flex-direction: column;
        }
        
        .stat-value .number {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--color-gray-900);
        }
        
        .stat-value .label {
            font-size: 0.875rem;
            color: var(--color-gray-600);
        }

        .table tbody tr:hover {
            background-color: var(--color-gray-50);
        }

        .btn-group {
            display: flex;
            gap: var(--space-sm);
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-project-diagram"></i> Internal Projects</h1>
                <div class="page-actions">
                    <a href="<?php echo url('admin/modules/internal-projects/add.php'); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> New Project</a>
                </div>
            </div>

            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                    <?php if ($session->hasFlash('error')): ?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" id="projectsForm">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
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
                                    <td><span class="badge"><?php echo e($p['project_code']); ?></span></td>
                                    <td><strong><?php echo e($p['project_name']); ?></strong></td>
                                    <td>
                                        <?php 
                                        $status_class = match($p['status']) {
                                            'completed' => 'success',
                                            'active' => 'info',
                                            'on_hold' => 'warning',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge badge-<?php echo $status_class; ?>"><?php echo ucfirst($p['status']); ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $priority_class = match($p['priority']) {
                                            'urgent' => 'danger',
                                            'high' => 'warning',
                                            'medium' => 'info',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge badge-<?php echo $priority_class; ?>"><?php echo ucfirst($p['priority']); ?></span>
                                    </td>
                                    <td><?php echo e($p['creator_name'] ?? '-'); ?></td>
                                    <td><?php echo e(formatDate($p['deadline'] ?? '', 'M d, Y')); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="<?php echo url('admin/modules/internal-projects/add.php?id=' . $p['internal_project_id']); ?>" class="btn btn-sm btn-outline" title="Edit"><i class="fas fa-edit"></i></a>
                                            <a href="<?php echo url('admin/modules/internal-projects/milestones.php?project_id=' . $p['internal_project_id']); ?>" class="btn btn-sm btn-outline" title="Milestones"><i class="fas fa-flag-checkered"></i></a>
                                            <a href="<?php echo url('admin/modules/internal-projects/tasks.php?project_id=' . $p['internal_project_id']); ?>" class="btn btn-sm btn-outline" title="Tasks"><i class="fas fa-tasks"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bulk-actions">
                    <select name="bulk_action" class="form-control">
                        <option value="">Bulk Actions</option>
                        <option value="complete">Mark Completed</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button class="btn btn-primary" type="submit">Apply</button>
                </div>
            </form>
        </main>
    </div>
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
</body>
</html>