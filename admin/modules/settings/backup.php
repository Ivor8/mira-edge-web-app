<?php
/**
 * Settings - Backup
 */
require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session(); $auth = new Auth(); $db = Database::getInstance()->getConnection();
if (!$session->isLoggedIn()) { $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; redirect(url('/login.php')); }
if (!$session->isAdmin()) { $session->setFlash('error','Access denied.'); redirect(url('/')); }
// get user details
$user = $session->getUser();
$user_id = $user['user_id'];

$backups = [];
$backup_dir = $_SERVER['DOCUMENT_ROOT'] . '/backups/';
if (is_dir($backup_dir)) {
    $files = array_diff(scandir($backup_dir), ['.', '..']);
    foreach ($files as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) === 'sql') {
            $backups[] = ['name' => $f, 'size' => filesize($backup_dir . $f), 'date' => filemtime($backup_dir . $f)];
        }
    }
}
usort($backups, fn($a, $b) => $b['date'] - $a['date']);

// Create backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    try {
        if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
        $timestamp = date('Y-m-d_H-i-s');
        $db_name = 'mira_edge_technologies';
        $backup_file = $backup_dir . $db_name . '_' . $timestamp . '.sql';
        $cmd = "mysqldump -h localhost -u root mira_edge_technologies > " . escapeshellarg($backup_file);
        exec($cmd, $output, $return_var);
        if ($return_var === 0) {
            $session->setFlash('success', 'Database backup created: ' . basename($backup_file));
        } else {
            $session->setFlash('error', 'Backup failed. Check server logs.');
        }
    } catch (Exception $e) {
        error_log('Backup error: ' . $e->getMessage());
        $session->setFlash('error', 'Backup error: ' . $e->getMessage());
    }
    redirect(url('/admin/modules/settings/backup.php'));
}

// Download backup
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filepath = $backup_dir . $file;
    if (file_exists($filepath) && strpos($filepath, $backup_dir) === 0) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($filepath));
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

// Delete backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup'])) {
    $file = basename($_POST['delete_backup']);
    $filepath = $backup_dir . $file;
    if (file_exists($filepath) && strpos($filepath, $backup_dir) === 0) {
        unlink($filepath);
        $session->setFlash('success', 'Backup deleted.');
        redirect(url('/admin/modules/settings/backup.php'));
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings — Backup</title>
<link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>"></head>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<body><?php include '../../includes/admin-header.php'; ?><div class="admin-container"><?php include '../../includes/admin-sidebar.php'; ?><main class="admin-main"><div class="page-header"><h1 class="page-title"><i class="fas fa-database"></i> Database Backup</h1></div>
<?php if ($session->hasFlash()): ?><div class="flash-messages"><?php if ($session->hasFlash('success')):?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif;?><?php if ($session->hasFlash('error')):?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif;?></div><?php endif; ?>

<div class="grid two-col">
    <div class="form-section">
        <h3>Create Backup</h3>
        <p>Click the button below to create a new database backup.</p>
        <form method="post">
            <input type="hidden" name="create_backup" value="1">
            <button class="btn btn-primary" type="submit">Create Backup</button>
        </form>
    </div>

    <div class="form-section">
        <h3>Existing Backups</h3>
        <table class="table"><thead><tr><th>Filename</th><th>Size</th><th>Date</th><th>Actions</th></tr></thead><tbody><?php foreach ($backups as $b): ?><tr><td><?php echo e($b['name']); ?></td><td><?php echo e(round($b['size']/1024, 2)) . ' KB'; ?></td><td><?php echo date('Y-m-d H:i:s', $b['date']); ?></td><td><a href="<?php echo url('/admin/modules/settings/backup.php?download=' . urlencode($b['name'])); ?>" class="btn btn-sm">Download</a><form method="post" style="display:inline-block;"><input type="hidden" name="delete_backup" value="<?php echo e($b['name']); ?>"><button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Delete this backup?');">Delete</button></form></td></tr><?php endforeach; ?></tbody></table>
    </div>
</div>

</main></div>
<script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
</body>
</html>