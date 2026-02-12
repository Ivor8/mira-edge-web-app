<?php
/**
 * Settings - Social Links
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session(); $auth = new Auth(); $db = Database::getInstance()->getConnection();
if (!$session->isLoggedIn()) { $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; redirect(url('/login.php')); }
if (!$session->isAdmin()) { $session->setFlash('error','Access denied.'); redirect(url('/')); }

// Manage social links in site_settings with keys social_facebook etc.
$keys = ['social_facebook','social_twitter','social_linkedin','social_instagram','social_github'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { $db->beginTransaction(); $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'social') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"); foreach ($keys as $k) $stmt->execute([$k, $_POST[$k] ?? '']); $db->commit(); $session->setFlash('success','Social links saved.'); redirect(url('/admin/modules/settings/social.php')); } catch (PDOException $e) { $db->rollBack(); error_log('Social save:'.$e->getMessage()); $session->setFlash('error','Error saving social links.'); }
}
$placeholders = implode(',', array_fill(0,count($keys),'?'));
$stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)"); $stmt->execute($keys); $rows = $stmt->fetchAll(); $current=[]; foreach($rows as $r) $current[$r['setting_key']]= $r['setting_value'];
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings — Social</title>
<link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>"></head>
<body><?php include '../../includes/admin-header.php'; ?><div class="admin-container"><?php include '../../includes/admin-sidebar.php'; ?><main class="admin-main"><div class="page-header"><h1 class="page-title"><i class="fab fa-share-alt"></i> Social Links</h1></div>
<?php if ($session->hasFlash()): ?><div class="flash-messages"><?php if ($session->hasFlash('success')):?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif;?><?php if ($session->hasFlash('error')):?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif;?></div><?php endif; ?>
<form method="post"><label>Facebook</label><input type="url" name="social_facebook" value="<?php echo e($current['social_facebook'] ?? ''); ?>"><label>Twitter</label><input type="url" name="social_twitter" value="<?php echo e($current['social_twitter'] ?? ''); ?>"><label>LinkedIn</label><input type="url" name="social_linkedin" value="<?php echo e($current['social_linkedin'] ?? ''); ?>"><label>Instagram</label><input type="url" name="social_instagram" value="<?php echo e($current['social_instagram'] ?? ''); ?>"><label>GitHub</label><input type="url" name="social_github" value="<?php echo e($current['social_github'] ?? ''); ?>"><div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Save</button></div></form></main></div></body></html>