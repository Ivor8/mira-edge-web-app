<?php
/**
 * Settings - Email
 */
require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session(); $auth = new Auth(); $db = Database::getInstance()->getConnection();
if (!$session->isLoggedIn()) { $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; redirect(url('/login.php')); }
if (!$session->isAdmin()) { $session->setFlash('error','Access denied.'); redirect(url('/')); }

$keys = ['email_from_name','email_from_address','email_smtp_host','email_smtp_port','email_smtp_user','email_smtp_pass'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { $db->beginTransaction(); $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'email') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"); foreach ($keys as $k) $stmt->execute([$k, $_POST[$k] ?? '']); $db->commit(); $session->setFlash('success','Email settings saved.'); redirect(url('/admin/modules/settings/email.php')); } catch (PDOException $e) { $db->rollBack(); error_log('Email save:'.$e->getMessage()); $session->setFlash('error','Error saving email settings.'); }
}
$placeholders = implode(',', array_fill(0,count($keys),'?'));
$stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)"); $stmt->execute($keys); $rows = $stmt->fetchAll(); $current=[]; foreach($rows as $r) $current[$r['setting_key']]= $r['setting_value'];
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings — Email</title>
<link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>"></head>
<body><?php include '../../includes/admin-header.php'; ?><div class="admin-container"><?php include '../../includes/admin-sidebar.php'; ?><main class="admin-main"><div class="page-header"><h1 class="page-title"><i class="fas fa-envelope"></i> Email Settings</h1></div>
<?php if ($session->hasFlash()): ?><div class="flash-messages"><?php if ($session->hasFlash('success')):?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif;?><?php if ($session->hasFlash('error')):?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif;?></div><?php endif; ?>
<form method="post"><label>From Name</label><input type="text" name="email_from_name" value="<?php echo e($current['email_from_name'] ?? ''); ?>"><label>From Address</label><input type="email" name="email_from_address" value="<?php echo e($current['email_from_address'] ?? ''); ?>"><label>SMTP Host</label><input type="text" name="email_smtp_host" value="<?php echo e($current['email_smtp_host'] ?? ''); ?>"><label>SMTP Port</label><input type="number" name="email_smtp_port" value="<?php echo e($current['email_smtp_port'] ?? ''); ?>"><label>SMTP User</label><input type="text" name="email_smtp_user" value="<?php echo e($current['email_smtp_user'] ?? ''); ?>"><label>SMTP Password</label><input type="password" name="email_smtp_pass" value="<?php echo e($current['email_smtp_pass'] ?? ''); ?>"><div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Save</button></div></form></main></div></body></html>