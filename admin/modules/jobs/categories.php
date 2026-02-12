<?php
/**
 * Job Categories Management
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

if (!in_array($session->getUserRole(), ['super_admin', 'admin'])) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}

$user = $session->getUser();

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        $name = trim($_POST['category_name']);
        $slug = trim($_POST['slug']);
        $description = trim($_POST['description']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if (empty($slug)) $slug = generateSlug($name);
        $stmt = $db->prepare("SELECT job_category_id FROM job_categories WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            $session->setFlash('error', 'Slug already used.');
        } else {
            $stmt = $db->prepare("INSERT INTO job_categories (category_name, slug, description, is_active) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $slug, $description, $is_active]);
            $session->setFlash('success', 'Category added.');
            redirect(url('/admin/modules/jobs/categories.php'));
        }
    }

    if (isset($_POST['bulk_action']) && isset($_POST['selected_categories'])) {
        $action = $_POST['bulk_action'];
        $selected = $_POST['selected_categories'];
        try {
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            if ($action == 'activate') {
                $stmt = $db->prepare("UPDATE job_categories SET is_active = 1 WHERE job_category_id IN ($placeholders)");
                $stmt->execute($selected);
                $session->setFlash('success', 'Categories activated.');
            } elseif ($action == 'deactivate') {
                $stmt = $db->prepare("UPDATE job_categories SET is_active = 0 WHERE job_category_id IN ($placeholders)");
                $stmt->execute($selected);
                $session->setFlash('success', 'Categories deactivated.');
            } elseif ($action == 'delete') {
                $stmt = $db->prepare("DELETE FROM job_categories WHERE job_category_id IN ($placeholders)");
                $stmt->execute($selected);
                $session->setFlash('success', 'Categories deleted.');
            }
        } catch (PDOException $e) {
            error_log('Job categories bulk: ' . $e->getMessage());
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }
}

// Fetch categories with counts
$sql = "SELECT jc.*, COUNT(jl.job_id) as job_count FROM job_categories jc LEFT JOIN job_listings jl ON jc.job_category_id = jl.job_category_id GROUP BY jc.job_category_id ORDER BY jc.category_name";
$stmt = $db->query($sql);
$categories = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Categories | Admin</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-tags"></i> Job Categories</h1>
                <div class="page-actions"></div>
            </div>

            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?>
                        <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div>
                    <?php endif; ?>
                    <?php if ($session->hasFlash('error')): ?>
                        <div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="grid two-col">
                <div class="form-section">
                    <h3>Add Category</h3>
                    <form method="post">
                        <label>Name</label>
                        <input type="text" name="category_name" required>
                        <label>Slug</label>
                        <input type="text" name="slug">
                        <label>Description</label>
                        <textarea name="description"></textarea>
                        <label><input type="checkbox" name="is_active" checked> Active</label>
                        <input type="hidden" name="add_category" value="1">
                        <button class="btn" type="submit">Add</button>
                    </form>
                </div>

                <div class="form-section">
                    <h3>Existing Categories</h3>
                    <form method="post">
                        <table class="table">
                            <thead>
                                <tr><th></th><th>Name</th><th>Slug</th><th>Jobs</th><th>Active</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $c): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_categories[]" value="<?php echo $c['job_category_id']; ?>"></td>
                                        <td><?php echo e($c['category_name']); ?></td>
                                        <td><?php echo e($c['slug']); ?></td>
                                        <td><?php echo e($c['job_count']); ?></td>
                                        <td><?php echo $c['is_active'] ? 'Yes' : 'No'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="bulk-actions">
                            <select name="bulk_action">
                                <option value="">Bulk Actions</option>
                                <option value="activate">Activate</option>
                                <option value="deactivate">Deactivate</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button class="btn" type="submit">Apply</button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
</body>
</html>