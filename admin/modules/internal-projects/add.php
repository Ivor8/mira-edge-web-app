<?php
/**
 * Add / Edit Internal Project
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
$user_id = $user['user_id'];

// Load project if editing
$editing = false;
$project = null;
if (isset($_GET['id'])) {
    $editing = true;
    $stmt = $db->prepare("SELECT * FROM internal_projects WHERE internal_project_id = ?");
    $stmt->execute([$_GET['id']]);
    $project = $stmt->fetch();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['project_name'] ?? '');
    $code = trim($_POST['project_code'] ?? '');
    $client_id = $_POST['client_id'] ?? null;
    $start_date = $_POST['start_date'] ?? null;
    $deadline = $_POST['deadline'] ?? null;
    $budget = $_POST['budget'] ?? null;
    $currency = $_POST['currency'] ?? 'XAF';
    $status = $_POST['status'] ?? 'planned';
    $priority = $_POST['priority'] ?? 'medium';

    if (empty($name)) $errors[] = 'Project name is required';
    if (empty($code)) {
        // generate code
        $code = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $name), 0, 4)) . '-' . rand(100,999);
    }

    if (empty($errors)) {
        try {
            if ($editing) {
                $stmt = $db->prepare("UPDATE internal_projects SET project_name = ?, project_code = ?, client_id = ?, start_date = ?, deadline = ?, budget = ?, currency = ?, status = ?, priority = ? WHERE internal_project_id = ?");
                $stmt->execute([$name, $code, $client_id, $start_date, $deadline, $budget, $currency, $status, $priority, $project['internal_project_id']]);
                $session->setFlash('success', 'Project updated.');
                redirect(url('/admin/modules/internal_projects/index.php'));
            } else {
                $stmt = $db->prepare("INSERT INTO internal_projects (project_name, project_code, description, client_id, start_date, deadline, budget, currency, status, priority, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $code, $_POST['description'] ?? null, $client_id, $start_date, $deadline, $budget, $currency, $status, $priority, $user_id]);
                $session->setFlash('success', 'Project created.');
                redirect(url('/admin/modules/internal_projects/index.php'));
            }
        } catch (PDOException $e) {
            error_log('Project save error: ' . $e->getMessage());
            $errors[] = 'Error saving project: ' . $e->getMessage();
        }
    }
}

// Fetch clients (service_orders)
$stmt = $db->query("SELECT order_id, client_name, client_email FROM service_orders ORDER BY created_at DESC");
$clients = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?php echo $editing ? 'Edit Project' : 'New Project'; ?> | Admin</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><?php echo $editing ? 'Edit Project' : 'Create Project'; ?></h1>
                <div class="page-actions"><a href="<?php echo url('/admin/modules/internal_projects/index.php'); ?>" class="btn btn-outline">Back</a></div>
            </div>

            <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>', $errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>

            <form method="post">
                <div class="form-grid">
                    <div class="form-section">
                        <label>Project Name</label>
                        <input type="text" name="project_name" value="<?php echo e($_POST['project_name'] ?? $project['project_name'] ?? ''); ?>" required>

                        <label>Project Code</label>
                        <input type="text" name="project_code" value="<?php echo e($_POST['project_code'] ?? $project['project_code'] ?? ''); ?>">

                        <label>Client</label>
                        <select name="client_id">
                            <option value="">-- None --</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['order_id']; ?>" <?php echo (($_POST['client_id'] ?? $project['client_id'] ?? '') == $c['order_id']) ? 'selected' : ''; ?>><?php echo e($c['client_name'] . ' (' . $c['client_email'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?php echo e($_POST['start_date'] ?? $project['start_date'] ?? ''); ?>">

                        <label>Deadline</label>
                        <input type="date" name="deadline" value="<?php echo e($_POST['deadline'] ?? $project['deadline'] ?? ''); ?>">

                        <label>Budget</label>
                        <input type="number" step="0.01" name="budget" value="<?php echo e($_POST['budget'] ?? $project['budget'] ?? ''); ?>">

                    </div>

                    <div class="form-section">
                        <label>Description</label>
                        <textarea name="description" rows="10"><?php echo e($_POST['description'] ?? $project['description'] ?? ''); ?></textarea>

                        <label>Status</label>
                        <select name="status">
                            <option value="planned" <?php echo (($_POST['status'] ?? $project['status'] ?? '') == 'planned') ? 'selected' : ''; ?>>Planned</option>
                            <option value="active" <?php echo (($_POST['status'] ?? $project['status'] ?? '') == 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="on_hold" <?php echo (($_POST['status'] ?? $project['status'] ?? '') == 'on_hold') ? 'selected' : ''; ?>>On Hold</option>
                            <option value="completed" <?php echo (($_POST['status'] ?? $project['status'] ?? '') == 'completed') ? 'selected' : ''; ?>>Completed</option>
                        </select>

                        <label>Priority</label>
                        <select name="priority">
                            <option value="low" <?php echo (($_POST['priority'] ?? $project['priority'] ?? '') == 'low') ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo (($_POST['priority'] ?? $project['priority'] ?? '') == 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo (($_POST['priority'] ?? $project['priority'] ?? '') == 'high') ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo (($_POST['priority'] ?? $project['priority'] ?? '') == 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                        </select>

                        <div style="margin-top:20px;"><button class="btn btn-primary" type="submit"><?php echo $editing ? 'Update Project' : 'Create Project'; ?></button></div>
                    </div>
                </div>
            </form>
        </main>
    </div>
</body>
</html>