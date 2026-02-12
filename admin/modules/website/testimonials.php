<?php
/**
 * Testimonials Management
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
// get user details
$user = $session->getUser();
$user_id = $user['user_id'];

// Ensure testimonials table
$db->exec("CREATE TABLE IF NOT EXISTS testimonials (
    testimonial_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    position VARCHAR(150),
    company VARCHAR(150),
    quote TEXT,
    avatar VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_testimonial'])) {
        $name = trim($_POST['name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $quote = trim($_POST['quote'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;

        $avatarPath = null;
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $ext;
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/testimonials/';
            if (!is_dir($upload_dir)) mkdir($upload_dir,0755,true);
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_dir . $filename)) $avatarPath = '/assets/uploads/testimonials/' . $filename;
        }

        try {
            if (!empty($_POST['testimonial_id'])) {
                $stmt = $db->prepare("UPDATE testimonials SET name = ?, position = ?, company = ?, quote = ?, is_active = ? " . ($avatarPath?", avatar = ?":"") . " WHERE testimonial_id = ?");
                $params = [$name,$position,$company,$quote,$active]; if ($avatarPath) $params[] = $avatarPath; $params[] = $_POST['testimonial_id'];
                $stmt->execute($params);
                $session->setFlash('success','Testimonial updated.');
            } else {
                $stmt = $db->prepare("INSERT INTO testimonials (name, position, company, quote, avatar, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name,$position,$company,$quote,$avatarPath,$active]);
                $session->setFlash('success','Testimonial added.');
            }
            redirect(url('/admin/modules/website/testimonials.php'));
        } catch (PDOException $e) { error_log('Testimonial save:'.$e->getMessage()); $errors[] = 'Error: '.$e->getMessage(); }
    }

    if (isset($_POST['bulk_action']) && isset($_POST['selected_testimonials'])) {
        $action = $_POST['bulk_action']; $selected = $_POST['selected_testimonials'];
        try { $placeholders = implode(',', array_fill(0,count($selected),'?'));
            if ($action=='delete') { $stmt=$db->prepare("DELETE FROM testimonials WHERE testimonial_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Deleted.'); }
            if ($action=='activate') { $stmt=$db->prepare("UPDATE testimonials SET is_active = 1 WHERE testimonial_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Activated.'); }
            if ($action=='deactivate') { $stmt=$db->prepare("UPDATE testimonials SET is_active = 0 WHERE testimonial_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Deactivated.'); }
        } catch (PDOException $e) { error_log('Testimonials bulk:'.$e->getMessage()); $session->setFlash('error','Bulk error.'); }
        redirect(url('/admin/modules/website/testimonials.php'));
    }
}

// edit
$editing=false; $t=null; if (isset($_GET['id'])) { $editing=true; $stmt=$db->prepare("SELECT * FROM testimonials WHERE testimonial_id = ?"); $stmt->execute([$_GET['id']]); $t=$stmt->fetch(); }
$stmt = $db->query("SELECT * FROM testimonials ORDER BY created_at DESC"); $testimonials = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Testimonials | Admin</title>
<link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include '../../includes/admin-header.php'; ?>
<div class="admin-container">
<?php include '../../includes/admin-sidebar.php'; ?>
<main class="admin-main">
    <div class="page-header"><h1 class="page-title"><i class="fas fa-quote-left"></i> Testimonials</h1></div>
    <?php if ($session->hasFlash()): ?><div class="flash-messages"><?php if ($session->hasFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif; ?><?php if ($session->hasFlash('error')): ?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif; ?></div><?php endif; ?>

    <div class="grid two-col">
        <div class="form-section">
            <h3><?php echo $editing? 'Edit':'Add'; ?> Testimonial</h3>
            <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>',$errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="testimonial_id" value="<?php echo e($t['testimonial_id'] ?? ''); ?>">
                <label>Name</label><input type="text" name="name" value="<?php echo e($_POST['name'] ?? $t['name'] ?? ''); ?>" required>
                <label>Position</label><input type="text" name="position" value="<?php echo e($_POST['position'] ?? $t['position'] ?? ''); ?>">
                <label>Company</label><input type="text" name="company" value="<?php echo e($_POST['company'] ?? $t['company'] ?? ''); ?>">
                <label>Quote</label><textarea name="quote"><?php echo e($_POST['quote'] ?? $t['quote'] ?? ''); ?></textarea>
                <label>Avatar</label><input type="file" name="avatar"><?php if (!empty($t['avatar'])): ?><div><img src="<?php echo e($t['avatar']); ?>" style="max-width:120px"></div><?php endif; ?>
                <label><input type="checkbox" name="is_active" <?php echo (($_POST['is_active'] ?? $t['is_active'] ?? 1)?'checked':''); ?>> Active</label>
                <div style="margin-top:12px;"><button class="btn btn-primary" type="submit" name="save_testimonial">Save</button></div>
            </form>
        </div>

        <div class="form-section">
            <h3>Existing Testimonials</h3>
            <form method="post">
                <table class="table"><thead><tr><th></th><th>Name</th><th>Company</th><th>Active</th><th></th></tr></thead><tbody><?php foreach ($testimonials as $tt): ?><tr><td><input type="checkbox" name="selected_testimonials[]" value="<?php echo $tt['testimonial_id']; ?>"></td><td><?php echo e($tt['name']); ?></td><td><?php echo e($tt['company']); ?></td><td><?php echo $tt['is_active']? 'Yes':'No'; ?></td><td><a href="<?php echo url('/admin/modules/website/testimonials.php?id=' . $tt['testimonial_id']); ?>" class="btn btn-sm">Edit</a></td></tr><?php endforeach; ?></tbody></table>
                <div class="bulk-actions"><select name="bulk_action"><option value="">Bulk Actions</option><option value="activate">Activate</option><option value="deactivate">Deactivate</option><option value="delete">Delete</option></select><button class="btn" type="submit">Apply</button></div>
            </form>
        </div>
    </div>

</main>
</div>
<script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
</body>
</html>