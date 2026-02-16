<?php
/**
 * Settings - Contact
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

$keys = ['contact_email','contact_phone','whatsapp_number','working_hours'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { $db->beginTransaction(); $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'contact') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"); foreach ($keys as $k) $stmt->execute([$k, $_POST[$k] ?? '']); $db->commit(); $session->setFlash('success','Contact settings saved.'); redirect(url('/admin/modules/settings/contact.php')); } catch (PDOException $e) { $db->rollBack(); error_log('Contact save:'.$e->getMessage()); $session->setFlash('error','Error saving contact settings.'); }
}
$placeholders = implode(',', array_fill(0,count($keys),'?'));
$stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)"); $stmt->execute($keys); $rows = $stmt->fetchAll(); $current = []; foreach ($rows as $r) $current[$r['setting_key']] = $r['setting_value'];
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings — Contact</title><link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head><body><?php include '../../includes/admin-header.php'; ?><div class="admin-container"><?php include '../../includes/admin-sidebar.php'; ?><main class="admin-main"><div class="page-header"><h1 class="page-title"><i class="fas fa-address-book"></i> Contact Settings</h1></div><?php if ($session->hasFlash()): ?><div class="flash-messages"><?php if ($session->hasFlash('success')):?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif;?><?php if ($session->hasFlash('error')):?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif;?></div><?php endif; ?><form method="post"><label>Contact Email</label><input type="email" name="contact_email" value="<?php echo e($current['contact_email'] ?? ''); ?>"><label>Contact Phone</label><input type="text" name="contact_phone" value="<?php echo e($current['contact_phone'] ?? ''); ?>"><label>WhatsApp Number</label><input type="text" name="whatsapp_number" value="<?php echo e($current['whatsapp_number'] ?? ''); ?>"><label>Working Hours</label><input type="text" name="working_hours" value="<?php echo e($current['working_hours'] ?? ''); ?>"><div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Save</button></div></form></main></div>
<script src="<?php echo url('assets/js/admin.js'); ?>"></script>
</body>
</html>