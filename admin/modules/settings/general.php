<?php
/**
 * Settings - General
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

if (!$session->isLoggedIn()) { $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; redirect(url('/login.php')); }
if (!$session->getUserRole() !== null && !$session->isAdmin()) { $session->setFlash('error','Access denied.'); redirect(url('/')); }

// Keys we manage here
$keys = [
    'company_name','company_email','company_phone','company_address','company_about'
];

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        $stmtIns = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($keys as $k) {
            $val = $_POST[$k] ?? '';
            $stmtIns->execute([$k, $val]);
        }
        $db->commit();
        $session->setFlash('success','General settings saved.');
        redirect(url('/admin/modules/settings/general.php'));
    } catch (PDOException $e) {
        $db->rollBack();
        error_log('General settings save: ' . $e->getMessage());
        $session->setFlash('error','Error saving settings: ' . $e->getMessage());
    }
}

// Load current values
$placeholders = implode(',', array_fill(0, count($keys), '?'));
$stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
$stmt->execute($keys);
$rows = $stmt->fetchAll();
$current = [];
foreach ($rows as $r) $current[$r['setting_key']] = $r['setting_value'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Settings — General</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-sliders-h"></i> General Settings</h1>
            </div>

            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                    <?php if ($session->hasFlash('error')): ?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <label>Company Name</label>
                <input type="text" name="company_name" value="<?php echo e($current['company_name'] ?? ''); ?>">

                <label>Company Email</label>
                <input type="email" name="company_email" value="<?php echo e($current['company_email'] ?? ''); ?>">

                <label>Company Phone</label>
                <input type="text" name="company_phone" value="<?php echo e($current['company_phone'] ?? ''); ?>">

                <label>Company Address</label>
                <input type="text" name="company_address" value="<?php echo e($current['company_address'] ?? ''); ?>">

                <label>About</label>
                <textarea name="company_about" rows="6"><?php echo e($current['company_about'] ?? ''); ?></textarea>

                <div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Save</button></div>
            </form>
        </main>
    </div>
</body>
</html>