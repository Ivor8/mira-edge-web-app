<?php
/**
 * Reviews Management
 * Allows admin to add/edit/approve/decline reviews submitted via website or manually.
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

// make sure reviews table exists
$db->exec("CREATE TABLE IF NOT EXISTS reviews (
    review_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    link VARCHAR(500) DEFAULT NULL,
    source ENUM('admin','website') DEFAULT 'website',
    status ENUM('pending','approved','declined') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$errors = [];
// handle add/edit submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_review'])) {
        $name = trim($_POST['name'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $link = trim($_POST['link'] ?? '');
        $status = $_POST['status'] ?? 'approved';
        $source = isset($_POST['source']) ? $_POST['source'] : 'admin';
        if (empty($name) || empty($content)) {
            $errors[] = 'Name and content are required.';
        }
        if (empty($errors)) {
            try {
                if (!empty($_POST['review_id'])) {
                    $stmt = $db->prepare("UPDATE reviews SET name = ?, content = ?, link = ?, status = ?, source = ? WHERE review_id = ?");
                    $stmt->execute([$name, $content, $link ?: null, $status, $source, $_POST['review_id']]);
                    $session->setFlash('success','Review updated.');
                } else {
                    $stmt = $db->prepare("INSERT INTO reviews (name, content, link, status, source) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $content, $link ?: null, $status, $source]);
                    $session->setFlash('success','Review added.');
                }
                redirect(url('/admin/modules/website/reviews.php'));
            } catch (PDOException $e) {
                error_log('Review save:'.$e->getMessage());
                $errors[] = 'Error saving review: '.$e->getMessage();
            }
        }
    }
    if (isset($_POST['bulk_action']) && isset($_POST['selected_reviews'])) {
        $action = $_POST['bulk_action']; $selected = $_POST['selected_reviews'];
        try {
            $placeholders = implode(',', array_fill(0,count($selected),'?'));
            if ($action=='delete') { $stmt=$db->prepare("DELETE FROM reviews WHERE review_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Deleted.'); }
            if ($action=='approve') { $stmt=$db->prepare("UPDATE reviews SET status='approved' WHERE review_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Approved.'); }
            if ($action=='decline') { $stmt=$db->prepare("UPDATE reviews SET status='declined' WHERE review_id IN ($placeholders)"); $stmt->execute($selected); $session->setFlash('success','Declined.'); }
        } catch (PDOException $e) { error_log('Reviews bulk:'.$e->getMessage()); $session->setFlash('error','Bulk error.'); }
        redirect(url('/admin/modules/website/reviews.php'));
    }
}

// editing
$editing = false;
$rev = null;
if (isset($_GET['id'])) {
    $editing = true;
    $stmt = $db->prepare("SELECT * FROM reviews WHERE review_id = ?");
    $stmt->execute([$_GET['id']]);
    $rev = $stmt->fetch();
}

// filter by status
$filterStatus = $_GET['status'] ?? 'all';
$sql = "SELECT * FROM reviews";
$params = [];
if (in_array($filterStatus,['pending','approved','declined'])) {
    $sql .= " WHERE status = ?";
    $params[] = $filterStatus;
}
$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reviews | Admin</title>
<link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include '../../includes/admin-header.php'; ?>
<div class="admin-container">
<?php include '../../includes/admin-sidebar.php'; ?>
<main class="admin-main">
    <div class="page-header"><h1 class="page-title"><i class="fas fa-star"></i> Reviews</h1></div>
    <?php if ($session->hasFlash()): ?><div class="flash-messages"><?php if ($session->hasFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif; ?><?php if ($session->hasFlash('error')): ?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif; ?></div><?php endif; ?>

    <div class="grid two-col">
        <div class="form-section">
            <h3><?php echo $editing? 'Edit':'Add'; ?> Review</h3>
            <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>',$errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="review_id" value="<?php echo e($rev['review_id'] ?? ''); ?>">
                <label>Name</label><input type="text" name="name" value="<?php echo e($_POST['name'] ?? $rev['name'] ?? ''); ?>" required>
                <label>Review Content</label><textarea name="content"><?php echo e($_POST['content'] ?? $rev['content'] ?? ''); ?></textarea>
                <label>Link (optional)</label><input type="url" name="link" value="<?php echo e($_POST['link'] ?? $rev['link'] ?? ''); ?>">
                <label>Status</label>
                <select name="status">
                    <option value="pending" <?php echo (($rev['status'] ?? '')=='pending' ? 'selected':''); ?>>Pending</option>
                    <option value="approved" <?php echo (($rev['status'] ?? '')=='approved' ? 'selected':''); ?>>Approved</option>
                    <option value="declined" <?php echo (($rev['status'] ?? '')=='declined' ? 'selected':''); ?>>Declined</option>
                </select>
                <input type="hidden" name="source" value="<?php echo $editing?($rev['source']??'admin'):'admin'; ?>">
                <div style="margin-top:12px;"><button class="btn btn-primary" type="submit" name="save_review">Save</button></div>
            </form>
        </div>

        <div class="form-section">
            <h3>Existing Reviews</h3>
            <div style="margin-bottom:12px;">
                <form method="get" style="display:inline-block;">
                    <select name="status">
                        <option value="all" <?php echo $filterStatus=='all'?'selected':''; ?>>All</option>
                        <option value="pending" <?php echo $filterStatus=='pending'?'selected':''; ?>>Pending</option>
                        <option value="approved" <?php echo $filterStatus=='approved'?'selected':''; ?>>Approved</option>
                        <option value="declined" <?php echo $filterStatus=='declined'?'selected':''; ?>>Declined</option>
                    </select>
                    <button class="btn" type="submit">Filter</button>
                </form>
            </div>
            <form method="post">
                <table class="table"><thead><tr><th></th><th>Name</th><th>Source</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody><?php foreach ($reviews as $r): ?><tr><td><input type="checkbox" name="selected_reviews[]" value="<?php echo $r['review_id']; ?>"></td><td><?php echo e($r['name']); ?></td><td><?php echo e($r['source']); ?></td><td><?php echo e($r['status']); ?></td><td><?php echo e($r['created_at']); ?></td><td><a href="<?php echo url('/admin/modules/website/reviews.php?id=' . $r['review_id']); ?>" class="btn btn-sm">Edit</a></td></tr><?php endforeach; ?></tbody></table>
                <div class="bulk-actions"><select name="bulk_action"><option value="">Bulk Actions</option><option value="approve">Approve</option><option value="decline">Decline</option><option value="delete">Delete</option></select><button class="btn" type="submit">Apply</button></div>
            </form>
        </div>
    </div>

</main>
</div>
<script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
</body>
</html>
