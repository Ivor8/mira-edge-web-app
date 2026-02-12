<?php
/**
 * Add / Edit Job Listing
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

$user = $session->getUser();
$user_id = $user['user_id'];

// Get categories
$catStmt = $db->query("SELECT job_category_id, category_name FROM job_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $catStmt->fetchAll();

$errors = [];
$success = false;

// Editing
$editing = false;
$job = null;
if (isset($_GET['id'])) {
    $editing = true;
    $stmt = $db->prepare("SELECT * FROM job_listings WHERE job_id = ?");
    $stmt->execute([$_GET['id']]);
    $job = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['job_title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $category = $_POST['job_category_id'] ?? null;
    $type = $_POST['job_type'] ?? 'full_time';
    $location = trim($_POST['location'] ?? '');
    $short = trim($_POST['short_description'] ?? '');
    $full = trim($_POST['full_description'] ?? '');
    $vacancies = (int)($_POST['vacancy_count'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;

    if (empty($title)) $errors['job_title'] = 'Job title required';
    if (empty($short)) $errors['short_description'] = 'Short description required';

    if (empty($slug)) $slug = generateSlug($title);

    // Check slug uniqueness
    $checkSql = $editing ? "SELECT job_id FROM job_listings WHERE slug = ? AND job_id != ?" : "SELECT job_id FROM job_listings WHERE slug = ?";
    $params = $editing ? [$slug, $job['job_id']] : [$slug];
    $stmt = $db->prepare($checkSql);
    $stmt->execute($params);
    if ($stmt->fetch()) $errors['slug'] = 'Slug already in use.';

    if (empty($errors)) {
        try {
            if ($editing) {
                $stmt = $db->prepare("UPDATE job_listings SET job_title = ?, slug = ?, job_category_id = ?, job_type = ?, location = ?, short_description = ?, full_description = ?, vacancy_count = ?, is_active = ?, is_featured = ? WHERE job_id = ?");
                $stmt->execute([$title, $slug, $category, $type, $location, $short, $full, $vacancies, $is_active, $is_featured, $job['job_id']]);
                $session->setFlash('success', 'Job updated successfully.');
                redirect(url('/admin/modules/jobs/index.php'));
            } else {
                $stmt = $db->prepare("INSERT INTO job_listings (job_title, slug, job_category_id, job_type, location, short_description, full_description, vacancy_count, is_active, is_featured, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $category, $type, $location, $short, $full, $vacancies, $is_active, $is_featured, $user_id]);
                $session->setFlash('success', 'Job added successfully.');
                redirect(url('/admin/modules/jobs/index.php'));
            }
        } catch (PDOException $e) {
            error_log('Job save error: ' . $e->getMessage());
            $errors['general'] = 'Error saving job: ' . $e->getMessage();
        }
    }
}

// Render form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editing ? 'Edit Job' : 'Add Job'; ?> | Admin</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><?php echo $editing ? 'Edit Job' : 'Add Job'; ?></h1>
                <div class="page-actions"><a href="<?php echo url('/admin/modules/jobs/index.php'); ?>" class="btn btn-outline">Back</a></div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error"><?php echo e(implode('<br>', $errors)); ?><button class="alert-close">&times;</button></div>
            <?php endif; ?>

            <form method="post">
                <div class="form-grid">
                    <div class="form-section">
                        <label>Job Title</label>
                        <input type="text" name="job_title" value="<?php echo e($_POST['job_title'] ?? $job['job_title'] ?? ''); ?>" required>

                        <label>Slug</label>
                        <input type="text" name="slug" value="<?php echo e($_POST['slug'] ?? $job['slug'] ?? ''); ?>">

                        <label>Category</label>
                        <select name="job_category_id">
                            <option value="">-- Select --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo $c['job_category_id']; ?>" <?php echo ((($_POST['job_category_id'] ?? $job['job_category_id'] ?? '') == $c['job_category_id']) ? 'selected' : ''); ?>><?php echo e($c['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label>Type</label>
                        <select name="job_type">
                            <option value="full_time" <?php echo (($_POST['job_type'] ?? $job['job_type'] ?? '') == 'full_time') ? 'selected' : ''; ?>>Full Time</option>
                            <option value="part_time" <?php echo (($_POST['job_type'] ?? $job['job_type'] ?? '') == 'part_time') ? 'selected' : ''; ?>>Part Time</option>
                            <option value="contract" <?php echo (($_POST['job_type'] ?? $job['job_type'] ?? '') == 'contract') ? 'selected' : ''; ?>>Contract</option>
                            <option value="internship" <?php echo (($_POST['job_type'] ?? $job['job_type'] ?? '') == 'internship') ? 'selected' : ''; ?>>Internship</option>
                        </select>

                        <label>Location</label>
                        <input type="text" name="location" value="<?php echo e($_POST['location'] ?? $job['location'] ?? ''); ?>">

                        <label>Vacancy Count</label>
                        <input type="number" name="vacancy_count" value="<?php echo e($_POST['vacancy_count'] ?? $job['vacancy_count'] ?? 1); ?>">

                        <label>Short Description</label>
                        <textarea name="short_description"><?php echo e($_POST['short_description'] ?? $job['short_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-section">
                        <label>Full Description</label>
                        <textarea name="full_description" rows="12"><?php echo e($_POST['full_description'] ?? $job['full_description'] ?? ''); ?></textarea>

                        <label>Options</label>
                        <div>
                            <label><input type="checkbox" name="is_active" <?php echo (($_POST['is_active'] ?? $job['is_active'] ?? 0) ? 'checked' : ''); ?>> Active</label>
                            <label><input type="checkbox" name="is_featured" <?php echo (($_POST['is_featured'] ?? $job['is_featured'] ?? 0) ? 'checked' : ''); ?>> Featured</label>
                        </div>

                        <div style="margin-top:20px;">
                            <button class="btn btn-primary" type="submit"><?php echo $editing ? 'Update Job' : 'Create Job'; ?></button>
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>
</body>
</html>