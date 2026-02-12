<?php
/**
 * Homepage Sliders Management
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

if (!$session->isLoggedIn()) { $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; redirect(url('/login.php')); }
if (!$session->isAdmin()) { $session->setFlash('error','Access denied.'); redirect(url('/')); }

// Ensure sliders table
$db->exec("CREATE TABLE IF NOT EXISTS sliders (
    slider_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255),
    subtitle VARCHAR(255),
    image VARCHAR(255),
    link VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_slider'])) {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $link = trim($_POST['link'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;
        $order = (int)($_POST['display_order'] ?? 0);

        // handle upload
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $ext;
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/sliders/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
                $imagePath = '/assets/uploads/sliders/' . $filename;
            }
        }

        try {
            if (!empty($_POST['slider_id'])) {
                $stmt = $db->prepare("UPDATE sliders SET title = ?, subtitle = ?, link = ?, is_active = ?, display_order = ? " . ($imagePath?", image = ?":"") . " WHERE slider_id = ?");
                $params = [$title, $subtitle, $link, $active, $order];
                if ($imagePath) $params[] = $imagePath;
                $params[] = $_POST['slider_id'];
                $stmt->execute($params);
                $session->setFlash('success','Slider updated.');
            } else {
                $stmt = $db->prepare("INSERT INTO sliders (title, subtitle, image, link, is_active, display_order) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $subtitle, $imagePath, $link, $active, $order]);
                $session->setFlash('success','Slider added.');
            }
            redirect(url('/admin/modules/website/sliders.php'));
        } catch (PDOException $e) { error_log('Slider save:'.$e->getMessage()); $errors[] = 'Error: '.$e->getMessage(); }
    }

    if (isset($_POST['bulk_action']) && isset($_POST['selected_sliders'])) {
        $action = $_POST['bulk_action']; $selected = $_POST['selected_sliders'];
        try { $placeholders = implode(',', array_fill(0,count($selected),'?'));
            if ($action=='delete') { $stmt=$db->prepare("DELETE FROM sliders WHERE slider_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Deleted.'); }
            if ($action=='activate') { $stmt=$db->prepare("UPDATE sliders SET is_active = 1 WHERE slider_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Activated.'); }
            if ($action=='deactivate') { $stmt=$db->prepare("UPDATE sliders SET is_active = 0 WHERE slider_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Deactivated.'); }
        } catch (PDOException $e) { error_log('Sliders bulk:'.$e->getMessage()); $session->setFlash('error','Bulk error.'); }
        redirect(url('/admin/modules/website/sliders.php'));
    }
}

// edit
$editing=false; $slider=null; if (isset($_GET['id'])) { $editing=true; $stmt=$db->prepare("SELECT * FROM sliders WHERE slider_id = ?"); $stmt->execute([$_GET['id']]); $slider=$stmt->fetch(); }
$stmt = $db->query("SELECT * FROM sliders ORDER BY display_order"); $sliders = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sliders | Admin</title>
<link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>"></head>
<body>
<?php include '../../includes/admin-header.php'; ?>
<div class="admin-container">
<?php include '../../includes/admin-sidebar.php'; ?>
<main class="admin-main">
    <div class="page-header"><h1 class="page-title"><i class="fas fa-images"></i> Sliders</h1></div>
    <?php if ($session->hasFlash()): ?><div class="flash-messages"><?php if ($session->hasFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif; ?><?php if ($session->hasFlash('error')): ?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif; ?></div><?php endif; ?>

    <div class="grid two-col">
        <div class="form-section">
            <h3><?php echo $editing? 'Edit Slider':'Add Slider'; ?></h3>
            <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>',$errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="slider_id" value="<?php echo e($slider['slider_id'] ?? ''); ?>">
                <label>Title</label><input type="text" name="title" value="<?php echo e($_POST['title'] ?? $slider['title'] ?? ''); ?>">
                <label>Subtitle</label><input type="text" name="subtitle" value="<?php echo e($_POST['subtitle'] ?? $slider['subtitle'] ?? ''); ?>">
                <label>Image</label><input type="file" name="image">
                <?php if (!empty($slider['image'])): ?><div class="slider-preview"><img src="<?php echo e($slider['image']); ?>" style="max-width:200px"></div><?php endif; ?>
                <label>Link</label><input type="text" name="link" value="<?php echo e($_POST['link'] ?? $slider['link'] ?? ''); ?>">
                <label>Order</label><input type="number" name="display_order" value="<?php echo e($_POST['display_order'] ?? $slider['display_order'] ?? 0); ?>">
                <label><input type="checkbox" name="is_active" <?php echo (($_POST['is_active'] ?? $slider['is_active'] ?? 1)?'checked':''); ?>> Active</label>
                <div style="margin-top:12px;"><button class="btn btn-primary" type="submit" name="save_slider">Save</button></div>
            </form>
        </div>

        <div class="form-section">
            <h3>Existing Sliders</h3>
            <form method="post">
                <table class="table"><thead><tr><th></th><th>Title</th><th>Image</th><th>Order</th><th>Active</th><th></th></tr></thead><tbody><?php foreach ($sliders as $s): ?><tr><td><input type="checkbox" name="selected_sliders[]" value="<?php echo $s['slider_id']; ?>"></td><td><?php echo e($s['title']); ?></td><td><?php if (!empty($s['image'])): ?><img src="<?php echo e($s['image']); ?>" style="max-width:80px"><?php endif; ?></td><td><?php echo e($s['display_order']); ?></td><td><?php echo $s['is_active']? 'Yes':'No'; ?></td><td><a href="<?php echo url('/admin/modules/website/sliders.php?id=' . $s['slider_id']); ?>" class="btn btn-sm">Edit</a></td></tr><?php endforeach; ?></tbody></table>
                <div class="bulk-actions"><select name="bulk_action"><option value="">Bulk Actions</option><option value="activate">Activate</option><option value="deactivate">Deactivate</option><option value="delete">Delete</option></select><button class="btn" type="submit">Apply</button></div>
            </form>
        </div>
    </div>

</main>
</div>
</body>
</html>