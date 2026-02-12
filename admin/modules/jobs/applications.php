<?php
/**
 * Job Applications Listing
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

$job_id = $_GET['job_id'] ?? null;

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['change_status']) && isset($_POST['application_id'])) {
        try {
            $stmt = $db->prepare("UPDATE job_applications SET application_status = ? WHERE application_id = ?");
            $stmt->execute([$_POST['application_status'], $_POST['application_id']]);
            $session->setFlash('success', 'Application status updated.');
        } catch (PDOException $e) {
            error_log('Application status error: ' . $e->getMessage());
            $session->setFlash('error', 'Error updating status.');
        }
    }
}

$sql = "SELECT ja.*, jl.job_title FROM job_applications ja LEFT JOIN job_listings jl ON ja.job_id = jl.job_id";
if ($job_id) {
    $sql .= " WHERE ja.job_id = " . (int)$job_id;
}
$sql .= " ORDER BY ja.applied_at DESC";
$stmt = $db->query($sql);
$applications = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Applications | Admin</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-file-alt"></i> Applications</h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/jobs/index.php'); ?>" class="btn btn-outline">Back to Jobs</a>
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

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Job</th>
                            <th>Applied At</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td><?php echo e($app['applicant_name']); ?></td>
                                <td><?php echo e($app['applicant_email']); ?></td>
                                <td><?php echo e($app['job_title'] ?? '-'); ?></td>
                                <td><?php echo e($app['applied_at']); ?></td>
                                <td><?php echo e($app['application_status']); ?></td>
                                <td>
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                                        <select name="application_status">
                                            <option value="submitted" <?php echo ($app['application_status']=='submitted')? 'selected':''; ?>>Submitted</option>
                                            <option value="reviewed" <?php echo ($app['application_status']=='reviewed')? 'selected':''; ?>>Reviewed</option>
                                            <option value="shortlisted" <?php echo ($app['application_status']=='shortlisted')? 'selected':''; ?>>Shortlisted</option>
                                            <option value="interviewed" <?php echo ($app['application_status']=='interviewed')? 'selected':''; ?>>Interviewed</option>
                                            <option value="rejected" <?php echo ($app['application_status']=='rejected')? 'selected':''; ?>>Rejected</option>
                                            <option value="hired" <?php echo ($app['application_status']=='hired')? 'selected':''; ?>>Hired</option>
                                        </select>
                                        <button class="btn" type="submit" name="change_status">Update</button>
                                    </form>

                                    <?php if (!empty($app['resume_path'])): ?>
                                        <a href="<?php echo e($app['resume_path']); ?>" class="btn btn-sm" target="_blank">Resume</a>
                                    <?php endif; ?>
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