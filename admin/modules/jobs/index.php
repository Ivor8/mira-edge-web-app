<?php
/**
 * Jobs Management - All Listings
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check auth
if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}

$user = $session->getUser();

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_jobs'])) {
        $action = $_POST['bulk_action'];
        $selected = $_POST['selected_jobs'];
        try {
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            switch ($action) {
                case 'activate':
                    $stmt = $db->prepare("UPDATE job_listings SET is_active = 1 WHERE job_id IN ($placeholders)");
                    $stmt->execute($selected);
                    $session->setFlash('success', 'Selected jobs activated.');
                    break;
                case 'deactivate':
                    $stmt = $db->prepare("UPDATE job_listings SET is_active = 0 WHERE job_id IN ($placeholders)");
                    $stmt->execute($selected);
                    $session->setFlash('success', 'Selected jobs deactivated.');
                    break;
                case 'delete':
                    $stmt = $db->prepare("DELETE FROM job_listings WHERE job_id IN ($placeholders)");
                    $stmt->execute($selected);
                    $session->setFlash('success', 'Selected jobs deleted.');
                    break;
            }
        } catch (PDOException $e) {
            error_log('Jobs bulk error: ' . $e->getMessage());
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }
}

// Fetch jobs with category name
$sql = "SELECT jl.*, jc.category_name FROM job_listings jl LEFT JOIN job_categories jc ON jl.job_category_id = jc.job_category_id ORDER BY jl.created_at DESC";
$stmt = $db->query($sql);
$jobs = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Listings | Admin</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/jobs.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-briefcase"></i> Job Listings</h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/jobs/add.php'); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Add Job</a>
                </div>
            </div>

            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?>
                        <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div>
                    <?php endif; ?>
                    <?php if ($session->hasFlash('error')): ?>
                        <div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Vacancies</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_jobs[]" value="<?php echo $job['job_id']; ?>"></td>
                                    <td><?php echo e($job['job_title']); ?></td>
                                    <td><?php echo e($job['category_name'] ?? '-'); ?></td>
                                    <td><?php echo e($job['job_type']); ?></td>
                                    <td><?php echo e($job['location']); ?></td>
                                    <td><?php echo e($job['vacancy_count']); ?></td>
                                    <td><?php echo $job['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>'; ?></td>
                                    <td>
                                        <a href="<?php echo url('/admin/modules/jobs/add.php?id=' . $job['job_id']); ?>" class="btn btn-sm">Edit</a>
                                        <a href="<?php echo url('/admin/modules/jobs/applications.php?job_id=' . $job['job_id']); ?>" class="btn btn-sm">Applications</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bulk-actions">
                    <select name="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate</option>
                        <option value="deactivate">Deactivate</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button class="btn" type="submit">Apply</button>
                </div>
            </form>
        </main>
    </div>
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
</body>
</html>