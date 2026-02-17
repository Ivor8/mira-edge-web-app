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
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-section {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-gray-200);
        }
        
        .form-section h3 {
            margin-top: 0;
            margin-bottom: var(--space-md);
            color: var(--color-gray-900);
            font-size: 1.125rem;
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .form-section h3 i {
            color: var(--color-primary);
        }
        
        .form-group {
            margin-bottom: var(--space-lg);
        }
        
        .form-group label {
            display: block;
            margin-bottom: var(--space-sm);
            font-weight: 500;
            color: var(--color-gray-700);
            font-size: 0.95rem;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: var(--space-md);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.95rem;
            transition: border-color var(--transition-normal), box-shadow var(--transition-normal);
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-50);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-group .form-check {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            margin-top: var(--space-md);
        }
        
        .form-group .form-check input {
            width: auto;
            margin: 0;
        }
        
        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-lg);
        }
        
        .table tbody tr:hover {
            background-color: var(--color-gray-50);
        }
        
        @media (max-width: 768px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-tags"></i> Job Categories</h1>
                <div class="page-actions"><a href="<?php echo url('/admin/modules/jobs/index.php'); ?>" class="btn btn-outline">Back to Jobs</a></div>
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

            <div class="grid-layout">
                <div class="form-section">
                    <h3><i class="fas fa-plus-circle"></i> Add Category</h3>
                    <form method="post">
                        <div class="form-group">
                            <label>Category Name</label>
                            <input type="text" name="category_name" required>
                        </div>
                        <div class="form-group">
                            <label>Slug</label>
                            <input type="text" name="slug">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description"></textarea>
                        </div>
                        <div class="form-group form-check">
                            <input type="checkbox" id="is_active" name="is_active" checked>
                            <label for="is_active">Active</label>
                        </div>
                        <input type="hidden" name="add_category" value="1">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Add Category</button>
                    </form>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-list"></i> Existing Categories (<?php echo count($categories); ?>)</h3>
                    <form method="post">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
                                        <th>Name</th>
                                        <th>Jobs</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $c): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_categories[]" value="<?php echo $c['job_category_id']; ?>"></td>
                                            <td><strong><?php echo e($c['category_name']); ?></strong></td>
                                            <td><span class="badge"><?php echo e($c['job_count']); ?></span></td>
                                            <td><?php echo $c['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="bulk-actions">
                            <select name="bulk_action" class="form-control">
                                <option value="">Bulk Actions</option>
                                <option value="activate">Activate</option>
                                <option value="deactivate">Deactivate</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button class="btn btn-primary" type="submit">Apply</button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
</body>
</html>