<?php
/**
 * Website Pages Management
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
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}
// get user details
$user = $session->getUser();
$user_id = $user['user_id'];


// Ensure pages table exists
$db->exec("CREATE TABLE IF NOT EXISTS pages (
    page_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    content LONGTEXT,
    status ENUM('draft','published') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$errors = [];

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_page'])) {
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $content = $_POST['content'] ?? '';
        $status = $_POST['status'] ?? 'draft';

        if (empty($title)) $errors[] = 'Title required';
        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i','-', $title));
            $slug = trim($slug,'-');
        }

        // check slug
        $checkSql = isset($_POST['page_id']) ? "SELECT page_id FROM pages WHERE slug = ? AND page_id != ?" : "SELECT page_id FROM pages WHERE slug = ?";
        $stmt = $db->prepare($checkSql);
        $params = isset($_POST['page_id']) ? [$slug, $_POST['page_id']] : [$slug];
        $stmt->execute($params);
        if ($stmt->fetch()) $errors[] = 'Slug already in use';

        if (empty($errors)) {
            try {
                if (!empty($_POST['page_id'])) {
                    $stmt = $db->prepare("UPDATE pages SET title = ?, slug = ?, content = ?, status = ? WHERE page_id = ?");
                    $stmt->execute([$title, $slug, $content, $status, $_POST['page_id']]);
                    $session->setFlash('success','Page updated.');
                } else {
                    $stmt = $db->prepare("INSERT INTO pages (title, slug, content, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$title, $slug, $content, $status]);
                    $session->setFlash('success','Page created.');
                }
                redirect(url('/admin/modules/website/pages.php'));
            } catch (PDOException $e) {
                error_log('Pages save: ' . $e->getMessage());
                $errors[] = 'Error saving page: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['bulk_action']) && isset($_POST['selected_pages'])) {
        $action = $_POST['bulk_action'];
        $selected = $_POST['selected_pages'];
        try {
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            if ($action === 'delete') {
                $stmt = $db->prepare("DELETE FROM pages WHERE page_id IN ($placeholders)");
                $stmt->execute($selected);
                $session->setFlash('success','Selected pages deleted.');
            } elseif ($action === 'publish') {
                $stmt = $db->prepare("UPDATE pages SET status = 'published' WHERE page_id IN ($placeholders)");
                $stmt->execute($selected);
                $session->setFlash('success','Selected pages published.');
            }
        } catch (PDOException $e) {
            error_log('Pages bulk: ' . $e->getMessage());
            $session->setFlash('error','Error performing bulk action.');
        }
        redirect(url('/admin/modules/website/pages.php'));
    }
}

// Edit request
$editing = false;
$page = null;
if (isset($_GET['id'])) {
    $editing = true;
    $stmt = $db->prepare("SELECT * FROM pages WHERE page_id = ?");
    $stmt->execute([$_GET['id']]);
    $page = $stmt->fetch();
}

// Fetch pages
$stmt = $db->query("SELECT * FROM pages ORDER BY created_at DESC");
$pages = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" /> m
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Website Pages | Admin</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-file"></i> Pages</h1>
                <div class="page-actions"><a href="<?php echo url('/admin/modules/website/pages.php'); ?>" class="btn btn-outline">Refresh</a></div>
            </div>

            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                    <?php if ($session->hasFlash('error')): ?><div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="grid two-col">
                <div class="form-section">
                    <h3><?php echo $editing ? 'Edit Page' : 'Add Page'; ?></h3>
                    <?php if (!empty($errors)): ?><div class="alert alert-error"><?php echo e(implode('<br>', $errors)); ?><button class="alert-close">&times;</button></div><?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="page_id" value="<?php echo e($page['page_id'] ?? ''); ?>">
                        <label>Title</label>
                        <input type="text" name="title" value="<?php echo e($_POST['title'] ?? $page['title'] ?? ''); ?>" required>
                        <label>Slug</label>
                        <input type="text" name="slug" value="<?php echo e($_POST['slug'] ?? $page['slug'] ?? ''); ?>">
                        <label>Content</label>
                        <textarea name="content" rows="10"><?php echo e($_POST['content'] ?? $page['content'] ?? ''); ?></textarea>
                        <label>Status</label>
                        <select name="status"><option value="draft" <?php echo (($_POST['status'] ?? $page['status'] ?? '')=='draft')? 'selected':''; ?>>Draft</option><option value="published" <?php echo (($_POST['status'] ?? $page['status'] ?? '')=='published')? 'selected':''; ?>>Published</option></select>
                        <div style="margin-top:15px;"><button class="btn btn-primary" type="submit" name="save_page">Save</button></div>
                    </form>
                </div>

                <div class="form-section">
                    <h3>Existing Pages</h3>
                    <form method="post">
                        <table class="table">
                            <thead><tr><th></th><th>Title</th><th>Slug</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($pages as $p): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_pages[]" value="<?php echo $p['page_id']; ?>"></td>
                                        <td><?php echo e($p['title']); ?></td>
                                        <td><?php echo e($p['slug']); ?></td>
                                        <td><?php echo e($p['status']); ?></td>
                                        <td><?php echo e($p['created_at']); ?></td>
                                        <td><a href="<?php echo url('/admin/modules/website/pages.php?id=' . $p['page_id']); ?>" class="btn btn-sm">Edit</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="bulk-actions">
                            <select name="bulk_action"><option value="">Bulk Actions</option><option value="publish">Publish</option><option value="delete">Delete</option></select>
                            <button class="btn" type="submit">Apply</button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
        <script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
</body>
</html>