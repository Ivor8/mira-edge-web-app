<?php
/**
 * Edit Blog Post
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check if user is logged in and has permission
if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

$user = $session->getUser();

// Determine whether we're editing or creating
$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $post_id > 0;

if ($is_edit) {
    // Get post data
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();

    if (!$post) {
        $session->setFlash('error', 'Post not found.');
        redirect(url('/admin/modules/blog/index.php'));
    }

    // Check permission (only admins, content managers, or the author can edit)
    if (!in_array($user['role'], ['super_admin', 'admin', 'content_manager']) && $post['author_id'] != $user['user_id']) {
        $session->setFlash('error', 'You do not have permission to edit this post.');
        redirect(url('/admin/modules/blog/index.php'));
    }

} else {
    // Prepare empty defaults for creating a new post
    $post = [
        'title' => '', 'slug' => '', 'excerpt' => '', 'content' => '',
        'blog_category_id' => null, 'featured_image' => null, 'image_alt' => '',
        'status' => 'draft', 'is_featured' => 0, 'reading_time' => 0,
        'seo_title' => '', 'seo_description' => '', 'seo_keywords' => ''
    ];
    $post_tags = [];
    $post_tag_ids = [];
}

// Get categories and tags
$stmt = $db->query("SELECT blog_category_id, category_name FROM blog_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $stmt->fetchAll();

$stmt = $db->query("SELECT tag_id, tag_name FROM blog_tags ORDER BY tag_name");
$all_tags = $stmt->fetchAll();

// Get post's current tags
$stmt = $db->prepare("
    SELECT t.tag_id, t.tag_name 
    FROM blog_post_tags pt 
    JOIN blog_tags t ON pt.tag_id = t.tag_id 
    WHERE pt.post_id = ?
");
$stmt->execute([$post_id]);
$post_tags = $stmt->fetchAll();
$post_tag_ids = array_column($post_tags, 'tag_id');

// Handle form submission (create or update)
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_post']) || isset($_POST['create_post']) || isset($_POST['title']))) {
    // Get form data
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $blog_category_id = !empty($_POST['blog_category_id']) ? $_POST['blog_category_id'] : null;
    $status = $_POST['status'] ?? 'draft';
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $seo_title = trim($_POST['seo_title'] ?? '');
    $seo_description = trim($_POST['seo_description'] ?? '');
    $seo_keywords = trim($_POST['seo_keywords'] ?? '');
    $selected_tags = $_POST['tags'] ?? [];
    
    // Validation
    if (empty($title)) {
        $errors['title'] = 'Post title is required';
    }
    
    if (empty($excerpt)) {
        $errors['excerpt'] = 'Excerpt is required';
    }
    
    if (empty($content)) {
        $errors['content'] = 'Content is required';
    }
    
    // Generate slug if empty
    if (empty($slug)) {
        $slug = generateSlug($title);
    }
    
    // Check if slug exists (excluding current post)
    $stmt = $db->prepare("SELECT post_id FROM blog_posts WHERE slug = ? AND post_id != ?");
    $stmt->execute([$slug, $post_id]);
    if ($stmt->fetch()) {
        $errors['slug'] = 'This slug is already in use. Please choose a different one.';
    }
    
    // Handle featured image upload
    $featured_image = $post['featured_image']; // Keep existing by default
    $image_alt = $title;
    
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['featured_image']['type'];
        $file_size = $_FILES['featured_image']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors['featured_image'] = 'Only JPG, PNG, GIF, and WebP images are allowed';
        } elseif ($file_size > 5 * 1024 * 1024) { // 5MB
            $errors['featured_image'] = 'Image size must be less than 5MB';
        } else {
            $ext = pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . time() . '.' . $ext;
            $upload_dir = dirname(dirname(dirname(dirname(__FILE__)))) . '/assets/uploads/blog/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $upload_dir . $filename)) {
                // Delete old image if exists
                if ($post['featured_image']) {
                    $old_image_path = dirname(dirname(dirname(dirname(__FILE__)))) . $post['featured_image'];
                    if (file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }
                }
                $featured_image = '/assets/uploads/blog/' . $filename;
            } else {
                $errors['featured_image'] = 'Failed to upload image';
            }
        }
    }
    
    // Remove image if requested
    if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
        if ($post['featured_image']) {
            $old_image_path = dirname(dirname(dirname(dirname(__FILE__)))) . $post['featured_image'];
            if (file_exists($old_image_path)) {
                unlink($old_image_path);
            }
        }
        $featured_image = null;
    }
    
    // If no errors, perform create or update
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Calculate reading time (assuming 200 words per minute)
            $word_count = str_word_count(strip_tags($content));
            $reading_time = ceil($word_count / 200);

            // Update published_at if status changes to published
            if ($is_edit) {
                $published_at = $post['published_at'];
                if ($status === 'published' && $post['status'] !== 'published') {
                    $published_at = date('Y-m-d H:i:s');
                }

                // Update post
                $stmt = $db->prepare("UPDATE blog_posts SET
                    title = ?, slug = ?, excerpt = ?, content = ?, 
                    blog_category_id = ?, featured_image = ?, image_alt = ?,
                    status = ?, is_featured = ?, reading_time = ?,
                    seo_title = ?, seo_description = ?, seo_keywords = ?,
                    published_at = ?, updated_at = NOW()
                WHERE post_id = ?");

                $stmt->execute([
                    $title, $slug, $excerpt, $content, $blog_category_id,
                    $featured_image, $image_alt, $status, $is_featured, $reading_time,
                    $seo_title, $seo_description, $seo_keywords, $published_at, $post_id
                ]);

                // Update tags - delete existing
                $stmt = $db->prepare("DELETE FROM blog_post_tags WHERE post_id = ?");
                $stmt->execute([$post_id]);

                // Add selected tags
                if (!empty($selected_tags)) {
                    foreach ($selected_tags as $tag_id) {
                        $tag_id = (int)$tag_id;
                        if ($tag_id > 0) {
                            $stmt = $db->prepare("INSERT INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)");
                            $stmt->execute([$post_id, $tag_id]);
                        }
                    }
                }

                // Add new tags if any
                if (isset($_POST['new_tags']) && !empty($_POST['new_tags'])) {
                    $new_tags = explode(',', $_POST['new_tags']);
                    foreach ($new_tags as $new_tag) {
                        $tag_name = trim($new_tag);
                        if (!empty($tag_name)) {
                            // Check if tag exists
                            $stmt = $db->prepare("SELECT tag_id FROM blog_tags WHERE tag_name = ?");
                            $stmt->execute([$tag_name]);
                            $existing_tag = $stmt->fetch();

                            if ($existing_tag) {
                                $tag_id = $existing_tag['tag_id'];
                            } else {
                                // Create new tag
                                $tag_slug = generateSlug($tag_name);
                                $stmt = $db->prepare("INSERT INTO blog_tags (tag_name, slug) VALUES (?, ?)");
                                $stmt->execute([$tag_name, $tag_slug]);
                                $tag_id = $db->lastInsertId();
                            }

                            // Link tag to post
                            $stmt = $db->prepare("INSERT IGNORE INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)");
                            $stmt->execute([$post_id, $tag_id]);
                        }
                    }
                }

                $db->commit();

                $session->setFlash('success', 'Post updated successfully!');
                redirect(url('/admin/modules/blog/edit.php?id=' . $post_id));

            } else {
                // Create new post
                $published_at = ($status === 'published') ? date('Y-m-d H:i:s') : null;

                $stmt = $db->prepare("INSERT INTO blog_posts
                    (title, slug, excerpt, content, blog_category_id, featured_image, image_alt,
                     status, is_featured, reading_time, seo_title, seo_description, seo_keywords,
                     author_id, published_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

                $stmt->execute([
                    $title, $slug, $excerpt, $content, $blog_category_id, $featured_image, $image_alt,
                    $status, $is_featured, $reading_time, $seo_title, $seo_description, $seo_keywords,
                    $user['user_id'], $published_at
                ]);

                $new_post_id = $db->lastInsertId();

                // Add selected tags
                if (!empty($selected_tags)) {
                    foreach ($selected_tags as $tag_id) {
                        $tag_id = (int)$tag_id;
                        if ($tag_id > 0) {
                            $stmt = $db->prepare("INSERT INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)");
                            $stmt->execute([$new_post_id, $tag_id]);
                        }
                    }
                }

                // Add new tags
                if (isset($_POST['new_tags']) && !empty($_POST['new_tags'])) {
                    $new_tags = explode(',', $_POST['new_tags']);
                    foreach ($new_tags as $new_tag) {
                        $tag_name = trim($new_tag);
                        if (!empty($tag_name)) {
                            $stmt = $db->prepare("SELECT tag_id FROM blog_tags WHERE tag_name = ?");
                            $stmt->execute([$tag_name]);
                            $existing_tag = $stmt->fetch();

                            if ($existing_tag) {
                                $tag_id = $existing_tag['tag_id'];
                            } else {
                                $tag_slug = generateSlug($tag_name);
                                $stmt = $db->prepare("INSERT INTO blog_tags (tag_name, slug) VALUES (?, ?)");
                                $stmt->execute([$tag_name, $tag_slug]);
                                $tag_id = $db->lastInsertId();
                            }

                            $stmt = $db->prepare("INSERT IGNORE INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)");
                            $stmt->execute([$new_post_id, $tag_id]);
                        }
                    }
                }

                $db->commit();

                $session->setFlash('success', 'Post created successfully!');
                redirect(url('/admin/modules/blog/edit.php?id=' . $new_post_id));
            }

        } catch (PDOException $e) {
            $db->rollBack();
            $errors['general'] = 'Error saving post: ' . $e->getMessage();
            error_log("Save Post Error: " . $e->getMessage());
        }
    }
}

// Handle publish action
if (isset($_POST['publish_post'])) {
    try {
        $stmt = $db->prepare("UPDATE blog_posts SET status = 'published', published_at = NOW() WHERE post_id = ?");
        $stmt->execute([$post_id]);
        $session->setFlash('success', 'Post published successfully!');
        redirect(url('/admin/modules/blog/edit.php?id=' . $post_id));
    } catch (PDOException $e) {
        $session->setFlash('error', 'Error publishing post: ' . $e->getMessage());
    }
}

// If we have a valid post_id, get fresh post data after potential update
if ($post_id > 0) {
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $fetched = $stmt->fetch();
    if ($fetched) {
        $post = $fetched;
    }

    // Get updated tags
    $stmt = $db->prepare("SELECT t.tag_id, t.tag_name 
        FROM blog_post_tags pt 
        JOIN blog_tags t ON pt.tag_id = t.tag_id 
        WHERE pt.post_id = ?");
    $stmt->execute([$post_id]);
    $post_tags = $stmt->fetchAll();
    $post_tag_ids = array_column($post_tags, 'tag_id');
} else {
    // ensure defaults remain set for new post
    $post_tags = $post_tags ?? [];
    $post_tag_ids = $post_tag_ids ?? [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Post | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <style>
        .edit-post-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .post-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-xl);
            padding: var(--space-lg);
            background: var(--color-white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-sm);
        }
        
        .post-header-info h1 {
            margin: 0 0 var(--space-xs);
            font-size: 1.75rem;
            color: var(--color-black);
        }
        
        .post-meta {
            display: flex;
            gap: var(--space-lg);
            color: var(--color-gray-600);
            font-size: 0.875rem;
        }
        
        .post-meta i {
            margin-right: 4px;
            color: var(--color-gray-500);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-published {
            background-color: rgba(0, 200, 83, 0.1);
            color: var(--color-success-dark);
        }
        
        .status-draft {
            background-color: rgba(255, 152, 0, 0.1);
            color: var(--color-warning-dark);
        }
        
        .status-archived {
            background-color: rgba(158, 158, 158, 0.1);
            color: var(--color-gray-600);
        }
        
        .form-columns {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: var(--space-xl);
        }
        
        @media (max-width: 1200px) {
            .form-columns {
                grid-template-columns: 1fr;
            }
        }
        
        .form-section {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-lg);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-sm);
        }
        
        .form-section-title {
            font-size: 1.1rem;
            margin-bottom: var(--space-lg);
            color: var(--color-black);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding-bottom: var(--space-sm);
            border-bottom: 1px solid var(--color-gray-200);
        }
        
        .form-section-title i {
            color: var(--color-gray-500);
        }
        
        .form-group {
            margin-bottom: var(--space-lg);
        }
        
        .form-label {
            display: block;
            margin-bottom: var(--space-sm);
            font-weight: 600;
            color: var(--color-gray-700);
            font-size: 0.875rem;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--color-error);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            font-size: 0.875rem;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
            background-color: var(--color-white);
            color: var(--color-gray-800);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--color-black);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-control.error {
            border-color: var(--color-error);
        }
        
        .form-error {
            color: var(--color-error);
            font-size: 0.75rem;
            margin-top: 4px;
        }
        
        .form-text {
            display: block;
            margin-top: 4px;
            font-size: 0.75rem;
            color: var(--color-gray-500);
        }
        
        .input-with-button {
            display: flex;
            gap: var(--space-sm);
        }
        
        .input-with-button .form-control {
            flex: 1;
        }
        
        .char-counter {
            font-size: 0.75rem;
            color: var(--color-gray-500);
            text-align: right;
            margin-top: 4px;
        }
        
        .publish-box {
            background: var(--color-gray-50);
        }
        
        .publish-actions {
            display: flex;
            flex-direction: column;
            gap: var(--space-md);
        }
        
        .publish-status {
            display: flex;
            align-items: center;
            gap: var(--space-md);
        }
        
        .status-label {
            font-weight: 600;
            color: var(--color-gray-700);
            min-width: 80px;
        }
        
        .form-buttons {
            display: flex;
            gap: var(--space-sm);
        }
        
        .form-buttons .btn {
            flex: 1;
        }
        
        .image-upload {
            position: relative;
        }
        
        .file-input {
            display: none;
        }
        
        .image-upload-label {
            display: block;
            cursor: pointer;
        }
        
        .current-image {
            margin-bottom: var(--space-md);
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--color-gray-200);
            position: relative;
        }
        
        .current-image img {
            width: 100%;
            height: auto;
            display: block;
        }
        
        .image-actions {
            position: absolute;
            top: 10px;
            right: 10px;
            display: flex;
            gap: var(--space-xs);
        }
        
        .image-action-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
        }
        
        .image-action-btn:hover {
            transform: scale(1.1);
        }
        
        .image-action-btn.remove:hover {
            background: rgba(244, 67, 54, 0.9);
        }
        
        .upload-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: var(--space-xl);
            background: var(--color-gray-50);
            border: 2px dashed var(--color-gray-300);
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }
        
        .upload-placeholder:hover {
            background: var(--color-gray-100);
            border-color: var(--color-gray-400);
        }
        
        .upload-placeholder i {
            font-size: 2rem;
            color: var(--color-gray-500);
            margin-bottom: var(--space-sm);
        }
        
        .upload-placeholder span {
            font-weight: 600;
            color: var(--color-gray-700);
            margin-bottom: var(--space-xs);
        }
        
        .upload-placeholder small {
            color: var(--color-gray-500);
        }
        
        .image-preview {
            position: relative;
            display: none;
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--color-gray-200);
        }
        
        .image-preview img {
            width: 100%;
            height: auto;
            display: block;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            cursor: pointer;
            font-size: 0.875rem;
            color: var(--color-gray-700);
        }
        
        .btn-link {
            color: var(--color-gray-600);
            text-decoration: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            margin-top: var(--space-xs);
        }
        
        .btn-link:hover {
            color: var(--color-black);
        }
        
        .tags-input {
            display: flex;
            gap: var(--space-sm);
            margin-top: var(--space-sm);
        }
        
        .tags-input .form-control {
            flex: 1;
        }
        
        .tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-xs);
            margin-top: var(--space-sm);
        }
        
        .tag-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: var(--color-gray-100);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            color: var(--color-gray-700);
            border: 1px solid var(--color-gray-200);
        }
        
        .tag-item i {
            cursor: pointer;
            color: var(--color-gray-500);
        }
        
        .tag-item i:hover {
            color: var(--color-error);
        }
        
        .select2-container--default .select2-selection--multiple {
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            min-height: 38px;
        }
        
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: var(--color-black);
            outline: none;
        }
        
        .note-editor.note-frame {
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
        }
        
        .note-toolbar {
            background: var(--color-gray-50);
            border-bottom: 1px solid var(--color-gray-200);
            border-radius: var(--radius-md) var(--radius-md) 0 0;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
            gap: var(--space-sm);
        }
        
        .btn i {
            font-size: 0.875rem;
        }
        
        .btn-primary {
            background-color: var(--color-black);
            color: var(--color-white);
        }
        
        .btn-primary:hover {
            background-color: var(--color-gray-800);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-outline {
            background-color: transparent;
            color: var(--color-gray-700);
            border: 1px solid var(--color-gray-300);
        }
        
        .btn-outline:hover {
            background-color: var(--color-gray-50);
            border-color: var(--color-gray-400);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background-color: var(--color-success);
            color: var(--color-white);
        }
        
        .btn-success:hover {
            background-color: var(--color-success-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .alert {
            display: flex;
            align-items: flex-start;
            gap: var(--space-md);
            padding: var(--space-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-md);
            border: 1px solid transparent;
            animation: slideInDown 0.3s ease-out;
        }
        
        .alert-success {
            background-color: rgba(0, 200, 83, 0.1);
            border-color: rgba(0, 200, 83, 0.3);
            color: var(--color-success-dark);
        }
        
        .alert-error {
            background-color: rgba(244, 67, 54, 0.1);
            border-color: rgba(244, 67, 54, 0.3);
            color: var(--color-error-dark);
        }
        
        .alert-info {
            background-color: rgba(33, 150, 243, 0.1);
            border-color: rgba(33, 150, 243, 0.3);
            color: var(--color-info-dark);
        }
        
        .alert-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            opacity: 0.7;
            cursor: pointer;
            margin-left: auto;
            padding: 0;
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-xl);
            padding-bottom: var(--space-md);
            border-bottom: 1px solid var(--color-gray-200);
        }
        
        .page-title {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-black);
        }
        
        .page-actions {
            display: flex;
            align-items: center;
            gap: var(--space-lg);
        }
    </style>
</head>
<body>
    <!-- Admin Header -->
    <?php include '../../includes/admin-header.php'; ?>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="admin-main">
            <div class="edit-post-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-edit"></i>
                        Edit Post
                    </h1>
                    <div class="page-actions">
                        <a href="<?php echo url('/admin/modules/blog/index.php'); ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Posts
                        </a>
                        <a href="<?php echo url('/?page=blog&slug=' . $post['slug']); ?>" target="_blank" class="btn btn-outline">
                            <i class="fas fa-external-link-alt"></i> View Post
                        </a>
                    </div>
                </div>

                <!-- Flash Messages -->
                <?php if ($session->hasFlash()): ?>
                    <div class="flash-messages">
                        <?php if ($session->hasFlash('success')): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <div class="alert-content"><?php echo $session->getFlash('success'); ?></div>
                                <button class="alert-close">&times;</button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($session->hasFlash('error')): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <div class="alert-content"><?php echo $session->getFlash('error'); ?></div>
                                <button class="alert-close">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Post Header -->
                <div class="post-header">
                    <div class="post-header-info">
                        <h1><?php echo e($post['title']); ?></h1>
                        <div class="post-meta">
                            <span>
                                <i class="fas fa-user"></i>
                                Author ID: <?php echo $post['author_id'] ?? 'New'; ?>
                            </span>
                            <?php if (isset($post['created_at'])): ?>
                            <span>
                                <i class="fas fa-calendar"></i>
                                Created: <?php echo formatDate($post['created_at'], 'M d, Y'); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (isset($post['published_at']) && $post['published_at']): ?>
                                <span>
                                    <i class="fas fa-check-circle"></i>
                                    Published: <?php echo formatDate($post['published_at'], 'M d, Y'); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (isset($post['views_count'])): ?>
                            <span>
                                <i class="fas fa-eye"></i>
                                Views: <?php echo number_format($post['views_count'] ?? 0); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <span class="status-badge status-<?php echo strtolower($post['status']); ?>">
                            <?php echo ucfirst($post['status']); ?>
                        </span>
                    </div>
                </div>

                <!-- Edit Post Form -->
                <form method="POST" action="" enctype="multipart/form-data" id="editPostForm">
                    <?php if (isset($errors['general'])): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <div class="alert-content"><?php echo e($errors['general']); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-columns">
                        <!-- Left Column (Main Content) -->
                        <div class="form-column main-column">
                            <!-- Title -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class="fas fa-heading"></i> Title & Content
                                </h3>
                                
                                <div class="form-group">
                                    <label for="title" class="form-label required">
                                        Post Title
                                    </label>
                                    <input type="text" 
                                           id="title" 
                                           name="title" 
                                           class="form-control <?php echo isset($errors['title']) ? 'error' : ''; ?>" 
                                           value="<?php echo e($_POST['title'] ?? $post['title']); ?>"
                                           required
                                           autofocus>
                                    <?php if (isset($errors['title'])): ?>
                                        <div class="form-error"><?php echo e($errors['title']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label for="slug" class="form-label">
                                        URL Slug
                                    </label>
                                    <div class="input-with-button">
                                        <input type="text" 
                                               id="slug" 
                                               name="slug" 
                                               class="form-control <?php echo isset($errors['slug']) ? 'error' : ''; ?>" 
                                               value="<?php echo e($_POST['slug'] ?? $post['slug']); ?>">
                                        <button type="button" id="generateSlug" class="btn btn-outline">
                                            <i class="fas fa-sync-alt"></i> Generate
                                        </button>
                                    </div>
                                    <?php if (isset($errors['slug'])): ?>
                                        <div class="form-error"><?php echo e($errors['slug']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label for="excerpt" class="form-label required">
                                        Excerpt
                                    </label>
                                    <textarea id="excerpt" 
                                              name="excerpt" 
                                              class="form-control <?php echo isset($errors['excerpt']) ? 'error' : ''; ?>" 
                                              rows="4"
                                              maxlength="500"
                                              required><?php echo e($_POST['excerpt'] ?? $post['excerpt']); ?></textarea>
                                    <div class="char-counter" id="excerptCounter">0/500</div>
                                    <?php if (isset($errors['excerpt'])): ?>
                                        <div class="form-error"><?php echo e($errors['excerpt']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label for="content" class="form-label required">
                                        Content
                                    </label>
                                    <textarea id="content" 
                                              name="content" 
                                              class="form-control summernote <?php echo isset($errors['content']) ? 'error' : ''; ?>" 
                                              rows="20"
                                              required><?php echo e($_POST['content'] ?? $post['content']); ?></textarea>
                                    <?php if (isset($errors['content'])): ?>
                                        <div class="form-error"><?php echo e($errors['content']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column (Sidebar) -->
                        <div class="form-column sidebar-column">
                            <!-- Publish Box -->
                            <div class="form-section publish-box">
                                <h3 class="form-section-title">
                                    <i class="fas fa-paper-plane"></i> Publish
                                </h3>
                                
                                <div class="publish-actions">
                                    <div class="publish-status">
                                        <span class="status-label">Status:</span>
                                        <select name="status" id="status" class="form-control">
                                            <option value="draft" <?php echo (($_POST['status'] ?? $post['status']) == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                            <option value="published" <?php echo (($_POST['status'] ?? $post['status']) == 'published') ? 'selected' : ''; ?>>Published</option>
                                            <option value="archived" <?php echo (($_POST['status'] ?? $post['status']) == 'archived') ? 'selected' : ''; ?>>Archived</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-buttons">
                                        <?php if ($is_edit): ?>
                                            <button type="submit" name="update_post" value="update" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Update
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="create_post" value="create" class="btn btn-primary">
                                                <i class="fas fa-plus"></i> Create
                                            </button>
                                        <?php endif; ?>

                                        <?php if (($_POST['status'] ?? $post['status']) !== 'published'): ?>
                                            <button type="submit" name="publish_post" value="publish" class="btn btn-success">
                                                <i class="fas fa-paper-plane"></i> Publish
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Featured Image -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class="fas fa-image"></i> Featured Image
                                </h3>
                                
                                <div class="form-group">
                                    <div class="image-upload">
                                        <?php if ($post['featured_image']): ?>
                                            <div class="current-image">
                                                <img src="<?php echo url($post['featured_image']); ?>" alt="<?php echo e($post['image_alt']); ?>">
                                                <div class="image-actions">
                                                    <button type="button" class="image-action-btn remove" id="removeCurrentImage" title="Remove Image">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <input type="hidden" name="remove_image" id="removeImage" value="0">
                                        <input type="file" 
                                               id="featured_image" 
                                               name="featured_image" 
                                               class="file-input <?php echo isset($errors['featured_image']) ? 'error' : ''; ?>"
                                               accept="image/*">
                                        <label for="featured_image" class="image-upload-label">
                                            <div class="upload-placeholder" id="uploadPlaceholder" style="<?php echo $post['featured_image'] ? 'display: none;' : ''; ?>">
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <span>Choose new image</span>
                                                <small>Click to browse</small>
                                            </div>
                                            <div class="image-preview" id="imagePreview">
                                                <img src="" alt="Preview">
                                            </div>
                                        </label>
                                    </div>
                                    <?php if (isset($errors['featured_image'])): ?>
                                        <div class="form-error"><?php echo e($errors['featured_image']); ?></div>
                                    <?php endif; ?>
                                    <span class="form-text">Recommended: 1200x630 pixels, Max: 5MB</span>
                                </div>
                            </div>
                            
                            <!-- Categories -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class="fas fa-folder"></i> Categories
                                </h3>
                                
                                <div class="form-group">
                                    <select name="blog_category_id" id="blog_category_id" class="form-control">
                                        <option value="">Uncategorized</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['blog_category_id']; ?>"
                                                    <?php echo (($_POST['blog_category_id'] ?? $post['blog_category_id']) == $category['blog_category_id']) ? 'selected' : ''; ?>>
                                                <?php echo e($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <a href="<?php echo url('/admin/modules/blog/categories.php'); ?>" class="btn-link" target="_blank">
                                    <i class="fas fa-plus"></i> Manage Categories
                                </a>
                            </div>
                            
                            <!-- Tags -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class="fas fa-tags"></i> Tags
                                </h3>
                                
                                <div class="form-group">
                                    <select name="tags[]" id="tags" class="form-control select2-tags" multiple>
                                        <?php foreach ($all_tags as $tag): ?>
                                            <option value="<?php echo $tag['tag_id']; ?>"
                                                    <?php echo (in_array($tag['tag_id'], $post_tag_ids)) ? 'selected' : ''; ?>>
                                                <?php echo e($tag['tag_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="new_tags" id="newTags" value="">
                                </div>
                                
                                <div class="tags-input">
                                    <input type="text" 
                                           id="newTagInput" 
                                           class="form-control" 
                                           placeholder="Add new tag...">
                                    <button type="button" id="addNewTag" class="btn btn-outline">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                                
                                <div class="tags-container" id="tagsContainer">
                                    <!-- New tags will be added here -->
                                </div>
                            </div>
                            
                            <!-- Options -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <i class="fas fa-cog"></i> Options
                                </h3>
                                
                                <div class="form-group">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="is_featured" 
                                               name="is_featured" 
                                               value="1"
                                               <?php echo (($_POST['is_featured'] ?? $post['is_featured']) == 1) ? 'checked' : ''; ?>>
                                        <label for="is_featured" class="checkbox-label">
                                            <i class="fas fa-star"></i> Mark as Featured Post
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- SEO Settings -->
                            <div class="form-section">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg); padding-bottom: var(--space-sm); border-bottom: 2px solid var(--color-gray-200);">
                                    <h3 class="form-section-title" style="margin: 0; border: none; padding: 0;">
                                        <i class="fas fa-search"></i> SEO Settings
                                    </h3>
                                    <button type="button" id="generateAllSeo" class="btn btn-outline" style="flex-shrink: 0;">
                                        <i class="fas fa-magic"></i> Auto-Generate All
                                    </button>
                                </div>
                                
                                <div class="form-group">
                                    <div style="display: flex; justify-content: space-between; align-items: center; gap: var(--space-md);">
                                        <label for="seo_title" class="form-label" style="margin: 0;">SEO Title</label>
                                        <button type="button" id="generateSeoTitle" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.75rem; flex-shrink: 0;">
                                            <i class="fas fa-bolt"></i> Generate
                                        </button>
                                    </div>
                                    <input type="text" 
                                           id="seo_title" 
                                           name="seo_title" 
                                           class="form-control" 
                                           value="<?php echo e($_POST['seo_title'] ?? $post['seo_title']); ?>">
                                    <div class="char-counter" id="seoTitleCounter">0/60</div>
                                </div>
                                
                                <div class="form-group">
                                    <div style="display: flex; justify-content: space-between; align-items: center; gap: var(--space-md);">
                                        <label for="seo_description" class="form-label" style="margin: 0;">SEO Description</label>
                                        <button type="button" id="generateSeoDesc" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.75rem; flex-shrink: 0;">
                                            <i class="fas fa-bolt"></i> Generate
                                        </button>
                                    </div>
                                    <textarea id="seo_description" 
                                              name="seo_description" 
                                              class="form-control" 
                                              rows="3"
                                              maxlength="160"><?php echo e($_POST['seo_description'] ?? $post['seo_description']); ?></textarea>
                                    <div class="char-counter" id="seoDescCounter">0/160</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="seo_keywords" class="form-label">SEO Keywords</label>
                                    <input type="text" 
                                           id="seo_keywords" 
                                           name="seo_keywords" 
                                           class="form-control" 
                                           value="<?php echo e($_POST['seo_keywords'] ?? $post['seo_keywords']); ?>"
                                           placeholder="comma, separated, keywords">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <script src="<?php echo url('/assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Summernote editor
            $('.summernote').summernote({
                height: 400,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['fontname', ['fontname']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ],
                callbacks: {
                    onImageUpload: function(files) {
                        for (let i = 0; i < files.length; i++) {
                            uploadImage(files[i]);
                        }
                    }
                }
            });
            
            // Initialize Select2 for tags
            $('.select2-tags').select2({
                tags: true,
                placeholder: 'Select tags or add new ones',
                tokenSeparators: [',', ' '],
                createTag: function(params) {
                    var term = $.trim(params.term);
                    if (term === '') {
                        return null;
                    }
                    return {
                        id: 'new_' + term,
                        text: term,
                        newTag: true
                    };
                }
            }).on('select2:select', function(e) {
                var data = e.params.data;
                if (data.newTag) {
                    // Add new tag to hidden input
                    var newTagsInput = document.getElementById('newTags');
                    var currentTags = newTagsInput.value ? newTagsInput.value.split(',') : [];
                    if (!currentTags.includes(data.text)) {
                        currentTags.push(data.text);
                        newTagsInput.value = currentTags.join(',');
                    }
                    
                    // Add tag to container
                    addTagToContainer(data.text);
                }
            });
            
            // Generate slug from title
            document.getElementById('generateSlug').addEventListener('click', function() {
                const title = document.getElementById('title').value;
                if (title) {
                    const slug = title.toLowerCase()
                        .replace(/[^a-z0-9\s-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-')
                        .replace(/^-|-$/g, '');
                    document.getElementById('slug').value = slug;
                }
            });
            
            // Generate SEO Title
            document.getElementById('generateSeoTitle')?.addEventListener('click', function(e) {
                e.preventDefault();
                const title = document.getElementById('title').value;
                if (title) {
                    const seoTitle = (title + ' | Mira Edge Technologies').substring(0, 60);
                    document.getElementById('seo_title').value = seoTitle;
                    updateCharCounter('seo_title', 'seoTitleCounter', 60);
                }
            });
            
            // Generate SEO Description
            document.getElementById('generateSeoDesc')?.addEventListener('click', function(e) {
                e.preventDefault();
                const excerpt = document.getElementById('excerpt').value;
                if (excerpt) {
                    const seoDesc = excerpt.substring(0, 160);
                    document.getElementById('seo_description').value = seoDesc;
                    updateCharCounter('seo_description', 'seoDescCounter', 160);
                }
            });
            
            // Generate All SEO Fields
            document.getElementById('generateAllSeo')?.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('generateSeoTitle').click();
                document.getElementById('generateSeoDesc').click();
            });
            
            // Update character counter
            function updateCharCounter(inputId, counterId, maxLength) {
                const input = document.getElementById(inputId);
                const counter = document.getElementById(counterId);
                if (input && counter) {
                    const length = input.value.length;
                    counter.textContent = `${length}/${maxLength}`;
                }
            }
            
            // Auto-generate SEO fields
            const titleInput = document.getElementById('title');
            const excerptInput = document.getElementById('excerpt');
            const seoTitleInput = document.getElementById('seo_title');
            const seoDescInput = document.getElementById('seo_description');
            
            // Track if user manually edited these fields
            let seoTitleManuallyEdited = seoTitleInput?.value?.length > 0;
            let seoDescManuallyEdited = seoDescInput?.value?.length > 0;
            
            seoTitleInput?.addEventListener('change', function() {
                seoTitleManuallyEdited = true;
            });
            
            seoDescInput?.addEventListener('change', function() {
                seoDescManuallyEdited = true;
            });
            
            function updateSeoFields() {
                // Auto-generate SEO Title if not manually edited
                if (!seoTitleManuallyEdited && titleInput?.value) {
                    seoTitleInput.value = (titleInput.value + ' | Mira Edge Technologies').substring(0, 60);
                    updateCharCounter('seo_title', 'seoTitleCounter', 60);
                }
                
                // Auto-generate SEO Description if not manually edited
                if (!seoDescManuallyEdited && excerptInput?.value) {
                    seoDescInput.value = excerptInput.value.substring(0, 160);
                    updateCharCounter('seo_description', 'seoDescCounter', 160);
                }
            }
            
            titleInput?.addEventListener('input', updateSeoFields);
            excerptInput?.addEventListener('input', updateSeoFields);
            
            // Remove current image
            const removeCurrentBtn = document.getElementById('removeCurrentImage');
            const removeImageInput = document.getElementById('removeImage');
            
            if (removeCurrentBtn) {
                removeCurrentBtn.addEventListener('click', function() {
                    if (confirm('Remove the current featured image?')) {
                        removeImageInput.value = '1';
                        this.closest('.current-image').style.display = 'none';
                        document.getElementById('uploadPlaceholder').style.display = 'flex';
                    }
                });
            }
            
            // Featured image preview
            const featuredImageInput = document.getElementById('featured_image');
            const imagePreview = document.getElementById('imagePreview');
            const previewImg = imagePreview.querySelector('img');
            const uploadPlaceholder = document.getElementById('uploadPlaceholder');
            
            if (featuredImageInput) {
                featuredImageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            previewImg.src = e.target.result;
                            imagePreview.style.display = 'block';
                            uploadPlaceholder.style.display = 'none';
                        }
                        reader.readAsDataURL(file);
                    }
                });
            }
            
            // Character counters
            function setupCharCounter(element, counterId, maxLength) {
                const counter = document.getElementById(counterId);
                if (!counter || !element) return;
                
                function updateCounter() {
                    const length = element.value.length;
                    counter.textContent = `${length}/${maxLength}`;
                    
                    if (length > maxLength * 0.9) {
                        counter.style.color = 'var(--color-warning)';
                    } else if (length > maxLength) {
                        counter.style.color = 'var(--color-error)';
                    } else {
                        counter.style.color = 'var(--color-gray-500)';
                    }
                }
                
                element.addEventListener('input', updateCounter);
                updateCounter();
            }
            
            setupCharCounter(excerptInput, 'excerptCounter', 500);
            setupCharCounter(seoTitleInput, 'seoTitleCounter', 60);
            setupCharCounter(seoDescInput, 'seoDescCounter', 160);
            
            // Add new tag functionality
            const newTagInput = document.getElementById('newTagInput');
            const addNewTagBtn = document.getElementById('addNewTag');
            const tagsContainer = document.getElementById('tagsContainer');
            const newTagsInput = document.getElementById('newTags');
            
            function addTagToContainer(tagName) {
                const tagItem = document.createElement('span');
                tagItem.className = 'tag-item';
                tagItem.innerHTML = `
                    ${tagName}
                    <i class="fas fa-times" onclick="this.parentElement.remove()"></i>
                `;
                tagsContainer.appendChild(tagItem);
            }
            
            if (addNewTagBtn) {
                addNewTagBtn.addEventListener('click', function() {
                    addNewTag();
                });
            }
            
            if (newTagInput) {
                newTagInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addNewTag();
                    }
                });
            }
            
            function addNewTag() {
                const tagName = newTagInput.value.trim();
                if (tagName) {
                    // Add to Select2
                    const $select = $('.select2-tags');
                    if ($select.find('option[value="' + tagName + '"]').length === 0) {
                        const option = new Option(tagName, 'new_' + tagName, true, true);
                        $select.append(option).trigger('change');
                    }
                    
                    // Add to hidden input
                    const currentTags = newTagsInput.value ? newTagsInput.value.split(',') : [];
                    if (!currentTags.includes(tagName)) {
                        currentTags.push(tagName);
                        newTagsInput.value = currentTags.join(',');
                    }
                    
                    // Add to container
                    addTagToContainer(tagName);
                    
                    // Clear input
                    newTagInput.value = '';
                    newTagInput.focus();
                }
            }
            
            // Form validation
            const form = document.getElementById('editPostForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    let valid = true;
                    
                    // Check required fields
                    const requiredFields = form.querySelectorAll('[required]');
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            valid = false;
                            field.classList.add('error');
                            
                            if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('form-error')) {
                                const error = document.createElement('div');
                                error.className = 'form-error';
                                error.textContent = 'This field is required';
                                field.parentNode.insertBefore(error, field.nextSibling);
                            }
                        } else {
                            field.classList.remove('error');
                            const error = field.parentNode.querySelector('.form-error');
                            if (error && error.textContent === 'This field is required') {
                                error.remove();
                            }
                        }
                    });
                    
                    if (!valid) {
                        e.preventDefault();
                        const firstError = form.querySelector('.error');
                        if (firstError) {
                            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            firstError.focus();
                        }
                    }
                });
            }
            
            // Image upload for Summernote
            function uploadImage(file) {
                const formData = new FormData();
                formData.append('image', file);
                
                fetch('<?php echo url("/admin/modules/blog/upload-image.php"); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        $('.summernote').summernote('insertImage', data.url);
                    } else {
                        console.error('Upload failed:', data.error);
                    }
                })
                .catch(error => console.error('Upload error:', error));
            }
            
            // Alert close buttons
            document.querySelectorAll('.alert-close').forEach(button => {
                button.addEventListener('click', function() {
                    const alert = this.closest('.alert');
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                });
            });
        });
    </script>
</body>
</html>