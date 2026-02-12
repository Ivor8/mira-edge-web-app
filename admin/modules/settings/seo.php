<?php
/**n * Settings - SEO */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

if (!$session->isLoggedIn()) { $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; redirect(url('/login.php')); }
if (!$session->isAdmin()) { $session->setFlash('error','Access denied.'); redirect(url('/')); }
// get user details
$user = $session->getUser();
$user_id = $user['user_id'];

$keys = ['default_meta_title','default_meta_description','default_meta_keywords'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'seo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($keys as $k) $stmt->execute([$k, $_POST[$k] ?? '']);
        $db->commit();
        $session->setFlash('success','SEO settings saved.');
        redirect(url('/admin/modules/settings/seo.php'));
    } catch (PDOException $e) { $db->rollBack(); error_log('SEO save:'.$e->getMessage()); $session->setFlash('error','Error saving SEO settings.'); }
}

$placeholders = implode(',', array_fill(0,count($keys),'?'));
$stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
$stmt->execute($keys); $rows = $stmt->fetchAll(); $current = []; foreach ($rows as $r) $current[$r['setting_key']] = $r['setting_value'];
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings — SEO</title>
<link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
<body><?php include '../../includes/admin-header.php'; ?><div class="admin-container"><?php include '../../includes/admin-sidebar.php'; ?><main class="admin-main"><div class="page-header"><h1 class="page-title"><i class="fas fa-search"></i> SEO Settings</h1></div>
<?php if ($session->hasFlash()): ?><div class="flash-messages"><?php if ($session->hasFlash('success')):?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif;?><?php if ($session->hasFlash('error')):?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif;?></div><?php endif; ?>
<form method="post"><label>Default Meta Title</label><input type="text" name="default_meta_title" value="<?php echo e($current['default_meta_title'] ?? ''); ?>"><label>Default Meta Description</label><textarea name="default_meta_description" rows="4"><?php echo e($current['default_meta_description'] ?? ''); ?></textarea><label>Default Meta Keywords</label><input type="text" name="default_meta_keywords" value="<?php echo e($current['default_meta_keywords'] ?? ''); ?>"><div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Save</button></div></form></main></div>
<script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
</body>
</html>