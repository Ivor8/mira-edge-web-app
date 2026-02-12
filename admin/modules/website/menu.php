<?php
/**
 * Website Menu Management
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
    $session->setFlash('error','Access denied.');
    redirect(url('/'));
}
// get user details
$user = $session->getUser();
$user_id = $user['user_id'];


// Ensure menu_items table exists
$db->exec("CREATE TABLE IF NOT EXISTS menu_items (
    menu_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    url VARCHAR(255) NOT NULL,
    parent_id INT DEFAULT NULL,
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE
)");

// Handle add/edit
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_menu'])) {
        $title = trim($_POST['title'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $parent = $_POST['parent_id'] ?: null;
        $order = (int)($_POST['display_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if (empty($title) || empty($url)) $errors[] = 'Title and URL required';
        if (empty($errors)) {
            try {
                if (!empty($_POST['menu_id'])) {
                    $stmt = $db->prepare("UPDATE menu_items SET title = ?, url = ?, parent_id = ?, display_order = ?, is_active = ? WHERE menu_id = ?");
                    $stmt->execute([$title,$url,$parent,$order,$active,$_POST['menu_id']]);
                    $session->setFlash('success','Menu updated.');
                } else {
                    $stmt = $db->prepare("INSERT INTO menu_items (title,url,parent_id,display_order,is_active) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$title,$url,$parent,$order,$active]);
                    $session->setFlash('success','Menu item added.');
                }
                redirect(url('/admin/modules/website/menu.php'));
            } catch (PDOException $e) {
                error_log('Menu save: ' . $e->getMessage());
                $errors[] = 'Error saving menu: '.$e->getMessage();
            }
        }
    }
    if (isset($_POST['bulk_action']) && isset($_POST['selected_items'])) {
        $action = $_POST['bulk_action']; $selected = $_POST['selected_items'];
        try {
            $placeholders = implode(',', array_fill(0,count($selected),'?'));
            if ($action=='delete') { $stmt = $db->prepare("DELETE FROM menu_items WHERE menu_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Deleted.'); }
            if ($action=='activate') { $stmt = $db->prepare("UPDATE menu_items SET is_active = 1 WHERE menu_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Activated.'); }
            if ($action=='deactivate') { $stmt = $db->prepare("UPDATE menu_items SET is_active = 0 WHERE menu_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Deactivated.'); }
        } catch (PDOException $e) { error_log('Menu bulk:'.$e->getMessage()); $session->setFlash('error','Bulk error.'); }
        redirect(url('/admin/modules/website/menu.php'));
    }
}

// edit
$editing=false; $item=null;
if (isset($_GET['id'])) { $editing=true; $stmt=$db->prepare("SELECT * FROM menu_items WHERE menu_id = ?"); $stmt->execute([$_GET['id']]); $item = $stmt->fetch(); }

// list
$stmt = $db->query("SELECT * FROM menu_items ORDER BY display_order, title"); $items = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Menu | Admin</title>
<link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include '../../includes/admin-header.php'; ?>
<div class="admin-container">
<?php include '../../includes/admin-sidebar.php'; ?>
<main class="admin-main">
    <div class="page-header"><h1 class="page-title"><i class="fas fa-list"></i> Menu</h1></div>

    <?php if ($session->hasFlash()): ?>
        <div class="flash-messages"><?php if ($session->hasFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif; ?><?php if ($session->hasFlash('error')): ?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif; ?></div>
    <?php endif; ?>

    <div class="grid two-col">
        <div class="form-section">
            <h3><?php echo $editing? 'Edit Item':'Add Item'; ?></h3>
            <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>',$errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="menu_id" value="<?php echo e($item['menu_id'] ?? ''); ?>">
                <label>Title</label><input type="text" name="title" value="<?php echo e($_POST['title'] ?? $item['title'] ?? ''); ?>" required>
                <label>URL</label><input type="text" name="url" value="<?php echo e($_POST['url'] ?? $item['url'] ?? ''); ?>" required>
                <label>Parent</label><select name="parent_id"><option value="">-- None --</option><?php foreach ($items as $it): ?><option value="<?php echo $it['menu_id']; ?>" <?php echo ((($_POST['parent_id'] ?? $item['parent_id'] ?? '')==$it['menu_id'])?'selected':''); ?>><?php echo e($it['title']); ?></option><?php endforeach; ?></select>
                <label>Order</label><input type="number" name="display_order" value="<?php echo e($_POST['display_order'] ?? $item['display_order'] ?? 0); ?>">
                <label><input type="checkbox" name="is_active" <?php echo (($_POST['is_active'] ?? $item['is_active'] ?? 1)?'checked':''); ?>> Active</label>
                <div style="margin-top:12px;"><button class="btn btn-primary" type="submit" name="save_menu">Save</button></div>
            </form>
        </div>

        <div class="form-section">
            <h3>Menu Items</h3>
            <form method="post">
                <table class="table"><thead><tr><th></th><th>Title</th><th>URL</th><th>Parent</th><th>Order</th><th>Active</th><th></th></tr></thead><tbody><?php foreach ($items as $it): ?><tr><td><input type="checkbox" name="selected_items[]" value="<?php echo $it['menu_id']; ?>"></td><td><?php echo e($it['title']); ?></td><td><?php echo e($it['url']); ?></td><td><?php echo e($it['parent_id'] ?? '-'); ?></td><td><?php echo e($it['display_order']); ?></td><td><?php echo $it['is_active']? 'Yes':'No'; ?></td><td><a href="<?php echo url('/admin/modules/website/menu.php?id=' . $it['menu_id']); ?>" class="btn btn-sm">Edit</a></td></tr><?php endforeach; ?></tbody></table>
                <div class="bulk-actions"><select name="bulk_action"><option value="">Bulk Actions</option><option value="activate">Activate</option><option value="deactivate">Deactivate</option><option value="delete">Delete</option></select><button class="btn" type="submit">Apply</button></div>
            </form>
        </div>
    </div>

</main>
</div>
<script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
</body>
</html>