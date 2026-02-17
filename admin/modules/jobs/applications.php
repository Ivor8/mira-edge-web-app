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
$user = $session->getUser();
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
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .app-stats {
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
        
        .app-row {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .inline-form {
            display: flex;
            align-items: center;
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
                <h1 class="page-title"><i class="fas fa-file-alt"></i> Job Applications</h1>
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
                            <th>Job Position</th>
                            <th>Applied</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td><strong><?php echo e($app['applicant_name']); ?></strong></td>
                                <td><?php echo e($app['applicant_email']); ?></td>
                                <td><?php echo e($app['job_title'] ?? '-'); ?></td>
                                <td><?php echo formatDate($app['applied_at'], 'M d, Y'); ?></td>
                                <td>
                                    <?php 
                                    $status_class = match($app['application_status']) {
                                        'hired' => 'success',
                                        'shortlisted' => 'info',
                                        'interviewed' => 'warning',
                                        'reviewed' => 'primary',
                                        'rejected' => 'danger',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge badge-<?php echo $status_class; ?>"><?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?></span>
                                </td>
                                <td>
                                    <div class="inline-form">
                                        <form method="post" style="display:inline-block;">
                                            <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                                            <select name="application_status" class="form-control" style="width: auto; display: inline-block;">
                                                <option value="submitted" <?php echo ($app['application_status']=='submitted')? 'selected':''; ?>>Submitted</option>
                                                <option value="reviewed" <?php echo ($app['application_status']=='reviewed')? 'selected':''; ?>>Reviewed</option>
                                                <option value="shortlisted" <?php echo ($app['application_status']=='shortlisted')? 'selected':''; ?>>Shortlisted</option>
                                                <option value="interviewed" <?php echo ($app['application_status']=='interviewed')? 'selected':''; ?>>Interviewed</option>
                                                <option value="rejected" <?php echo ($app['application_status']=='rejected')? 'selected':''; ?>>Rejected</option>
                                                <option value="hired" <?php echo ($app['application_status']=='hired')? 'selected':''; ?>>Hired</option>
                                            </select>
                                            <button class="btn btn-sm btn-outline" type="submit" name="change_status" title="Update Status"><i class="fas fa-check"></i></button>
                                        </form>
                                        <?php if (!empty($app['resume_path'])): ?>
                                            <a href="<?php echo e($app['resume_path']); ?>" class="btn btn-sm btn-outline" target="_blank" title="Download Resume"><i class="fas fa-file-pdf"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
</body>
</html>