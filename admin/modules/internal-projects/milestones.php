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
        .form-group textarea {
            width: 100%;
            padding: var(--space-md);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.95rem;
            transition: border-color var(--transition-normal), box-shadow var(--transition-normal);
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
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
            grid-template-columns: 1fr 1fr;
            gap: var(--space-lg);
        }
        
        .milestone-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-md);
            border-bottom: 1px solid var(--color-gray-200);
            transition: background-color var(--transition-normal);
        }
        
        .milestone-row:hover {
            background-color: var(--color-gray-50);
        }
        
        .milestone-row.completed .milestone-name {
            text-decoration: line-through;
            color: var(--color-gray-500);
        }
        
        .milestone-info {
            flex: 1;
        }
        
        .milestone-name {
            font-weight: 500;
            color: var(--color-gray-900);
            margin-bottom: var(--space-xs);
        }
        
        .milestone-due {
            font-size: 0.875rem;
            color: var(--color-gray-600);
        }
        
        .milestone-actions {
            display: flex;
            gap: var(--space-sm);
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .milestone-row {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .milestone-actions {
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
                <h1 class="page-title"><i class="fas fa-flag-checkered"></i> Milestones for <?php echo e($project['project_name']); ?></h1>
                <div class="page-actions"><a href="<?php echo url('/admin/modules/internal_projects/index.php'); ?>" class="btn btn-outline">Back</a></div>
            </div>

            <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>', $errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>

            <div class="form-section">
                <h3><i class="fas fa-plus-circle"></i> Add Milestone</h3>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Milestone Name</label>
                            <input type="text" name="milestone_name" required>
                        </div>
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" name="due_date">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description"></textarea>
                    </div>
                    <input type="hidden" name="add_milestone" value="1">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Add Milestone</button>
                </form>
            </div>

            <div class="form-section">
                <h3><i class="fas fa-list"></i> Milestones (<?php echo count($milestones); ?>)</h3>
                <?php if (empty($milestones)): ?>
                    <p class="text-gray-500">No milestones yet. Create one to get started.</p>
                <?php else: ?>
                    <div class="milestones-list">
                        <?php foreach ($milestones as $m): ?>
                            <div class="milestone-row <?php echo $m['is_completed'] ? 'completed' : ''; ?>">
                                <div class="milestone-info">
                                    <div class="milestone-name"><?php echo e($m['milestone_name']); ?></div>
                                    <div class="milestone-due"><?php if ($m['due_date']): ?>Due: <?php echo formatDate($m['due_date'], 'M d, Y'); ?><?php endif; ?></div>
                                </div>
                                <div class="milestone-actions">
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="milestone_id" value="<?php echo $m['milestone_id']; ?>">
                                        <button class="btn btn-sm <?php echo $m['is_completed'] ? 'btn-outline' : 'btn-success'; ?>" type="submit" name="action" value="toggle" title="<?php echo $m['is_completed'] ? 'Mark Incomplete' : 'Mark Complete'; ?>"><i class="fas fa-<?php echo $m['is_completed'] ? 'undo' : 'check'; ?>"></i></button>
                                    </form>
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="milestone_id" value="<?php echo $m['milestone_id']; ?>">
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