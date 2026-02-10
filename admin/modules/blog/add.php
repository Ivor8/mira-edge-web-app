<?php
/**
 * Add New Blog Post
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

// Check permissions (admin, content_manager, or author)
if (!in_array($user['role'], ['super_admin', 'admin', 'content_manager'])) {
    $session->setFlash('error', 'Access denied. You need appropriate permissions to create posts.');
    redirect(url('/'));
}

// Get categories and tags
$stmt = $db->query("SELECT blog_category_id, category_name FROM blog_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $stmt->fetchAll();

$stmt = $db->query("SELECT tag_id, tag_name FROM blog_tags ORDER BY tag_name");
$tags = $stmt->fetchAll();

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $blog_category_id = $_POST['blog_category_id'] ?? null;
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
    
    // Check if slug exists
    $stmt = $db->prepare("SELECT post_id FROM blog_posts WHERE slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        $errors['slug'] = 'This slug is already in use. Please choose a different one.';
    }
    
    // Handle featured image upload
    $featured_image = null;
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
                $featured_image = '/assets/uploads/blog/' . $filename;
            } else {
                $errors['featured_image'] = 'Failed to upload image';
            }
        }
    }
    
    // If no errors, insert post
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Calculate reading time (assuming 200 words per minute)
            $word_count = str_word_count(strip_tags($content));
            $reading_time = ceil($word_count / 200);
            
            // Insert post
            $stmt = $db->prepare("
                INSERT INTO blog_posts 
                (title, slug, excerpt, content, blog_category_id, author_id, 
                 featured_image, image_alt, status, is_featured, reading_time,
                 seo_title, seo_description, seo_keywords, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $published_at = ($status === 'published') ? date('Y-m-d H:i:s') : null;
            
            $stmt->execute([
                $title, $slug, $excerpt, $content, $blog_category_id, $user['user_id'],
                $featured_image, $image_alt, $status, $is_featured, $reading_time,
                $seo_title, $seo_description, $seo_keywords, $published_at
            ]);
            
            $post_id = $db->lastInsertId();
            
            // Add tags
            if (!empty($selected_tags)) {
                foreach ($selected_tags as $tag_id) {
                    $tag_id = (int)$tag_id;
                    if ($tag_id > 0) {
                        $stmt = $db->prepare("
                            INSERT INTO blog_post_tags (post_id, tag_id)
                            VALUES (?, ?)
                        ");
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
                        $stmt = $db->prepare("INSERT INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)");
                        $stmt->execute([$post_id, $tag_id]);
                    }
                }
            }
            
            $db->commit();
            
            $session->setFlash('success', 'Post ' . ($status === 'published' ? 'published' : 'saved as draft') . ' successfully!');
            redirect(url('/admin/modules/blog/edit.php?id=' . $post_id));
            
        } catch (PDOException $e) {
            $db->rollBack();
            $errors['general'] = 'Error saving post: ' . $e->getMessage();
            error_log("Add Post Error: " . $e->getMessage());
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Post | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('/assets/css/blog.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
</head>
<body>
    <!-- Admin Header -->
    <?php include '../includes/admin-header.php'; ?>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <?php include '../includes/admin-sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-plus-circle"></i>
                    Add New Post
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/blog/'); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Posts
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo e($session->getFlash('success')); ?>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($session->hasFlash('error')): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo e($session->getFlash('error')); ?>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Add Post Form -->
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data" id="addPostForm">
                        <?php if (isset($errors['general'])): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo e($errors['general']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-columns">
                            <!-- Left Column (Main Content) -->
                            <div class="form-column main-column">
                                <!-- Title -->
                                <div class="form-group">
                                    <label for="title" class="form-label required">
                                        <i class="fas fa-heading"></i> Post Title
                                    </label>
                                    <input type="text" 
                                           id="title" 
                                           name="title" 
                                           class="form-control <?php echo isset($errors['title']) ? 'error' : ''; ?>" 
                                           value="<?php echo e($_POST['title'] ?? ''); ?>"
                                           required
                                           autofocus
                                           placeholder="Enter post title...">
                                    <?php if (isset($errors['title'])): ?>
                                        <div class="form-error"><?php echo e($errors['title']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Slug -->
                                <div class="form-group">
                                    <label for="slug" class="form-label">
                                        <i class="fas fa-link"></i> URL Slug
                                    </label>
                                    <div class="input-with-button">
                                        <input type="text" 
                                               id="slug" 
                                               name="slug" 
                                               class="form-control <?php echo isset($errors['slug']) ? 'error' : ''; ?>" 
                                               value="<?php echo e($_POST['slug'] ?? ''); ?>"
                                               placeholder="Auto-generated from title">
                                        <button type="button" id="generateSlug" class="btn btn-outline">
                                            <i class="fas fa-sync-alt"></i> Generate
                                        </button>
                                    </div>
                                    <?php if (isset($errors['slug'])): ?>
                                        <div class="form-error"><?php echo e($errors['slug']); ?></div>
                                    <?php endif; ?>
                                    <small class="form-text">Leave empty to auto-generate from title</small>
                                </div>
                                
                                <!-- Excerpt -->
                                <div class="form-group">
                                    <label for="excerpt" class="form-label required">
                                        <i class="fas fa-align-left"></i> Excerpt
                                    </label>
                                    <textarea id="excerpt" 
                                              name="excerpt" 
                                              class="form-control <?php echo isset($errors['excerpt']) ? 'error' : ''; ?>" 
                                              rows="4"
                                              maxlength="500"
                                              required
                                              placeholder="Brief summary of the post (max 500 characters)..."><?php echo e($_POST['excerpt'] ?? ''); ?></textarea>
                                    <div class="char-counter" id="excerptCounter">0/500</div>
                                    <?php if (isset($errors['excerpt'])): ?>
                                        <div class="form-error"><?php echo e($errors['excerpt']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Content Editor -->
                                <div class="form-group">
                                    <label for="content" class="form-label required">
                                        <i class="fas fa-edit"></i> Content
                                    </label>
                                    <textarea id="content" 
                                              name="content" 
                                              class="form-control summernote <?php echo isset($errors['content']) ? 'error' : ''; ?>" 
                                              rows="20"
                                              required><?php echo e($_POST['content'] ?? ''); ?></textarea>
                                    <?php if (isset($errors['content'])): ?>
                                        <div class="form-error"><?php echo e($errors['content']); ?></div>
                                    <?php endif; ?>
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
                                                <option value="draft" <?php echo (($_POST['status'] ?? 'draft') == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                                <option value="published" <?php echo (($_POST['status'] ?? '') == 'published') ? 'selected' : ''; ?>>Published</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-buttons">
                                            <button type="submit" name="save_draft" value="draft" class="btn btn-outline">
                                                <i class="fas fa-save"></i> Save Draft
                                            </button>
                                            <button type="submit" name="publish_post" value="publish" class="btn btn-primary">
                                                <i class="fas fa-paper-plane"></i> Publish
                                            </button>
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
                                            <input type="file" 
                                                   id="featured_image" 
                                                   name="featured_image" 
                                                   class="file-input <?php echo isset($errors['featured_image']) ? 'error' : ''; ?>"
                                                   accept="image/*">
                                            <label for="featured_image" class="image-upload-label">
                                                <div class="upload-placeholder">
                                                    <i class="fas fa-cloud-upload-alt"></i>
                                                    <span>Choose featured image</span>
                                                    <small>Click to browse or drag and drop</small>
                                                </div>
                                                <div class="image-preview" id="imagePreview">
                                                    <img src="" alt="Preview">
                                                    <button type="button" class="remove-image" id="removeImage">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            </label>
                                        </div>
                                        <?php if (isset($errors['featured_image'])): ?>
                                            <div class="form-error"><?php echo e($errors['featured_image']); ?></div>
                                        <?php endif; ?>
                                        <small class="form-text">Recommended: 1200x630 pixels, Max: 5MB</small>
                                    </div>
                                </div>
                                
                                <!-- Categories -->
                                <div class="form-section">
                                    <h3 class="form-section-title">
                                        <i class="fas fa-folder"></i> Categories
                                    </h3>
                                    
                                    <div class="form-group">
                                        <select name="blog_category_id" id="blog_category_id" class="form-control">
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category['blog_category_id']; ?>"
                                                        <?php echo (($_POST['blog_category_id'] ?? '') == $category['blog_category_id']) ? 'selected' : ''; ?>>
                                                    <?php echo e($category['category_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <a href="<?php echo url('/admin/modules/blog/categories.php'); ?>" class="btn-link">
                                        <i class="fas fa-plus"></i> Add New Category
                                    </a>
                                </div>
                                
                                <!-- Tags -->
                                <div class="form-section">
                                    <h3 class="form-section-title">
                                        <i class="fas fa-tags"></i> Tags
                                    </h3>
                                    
                                    <div class="form-group">
                                        <select name="tags[]" id="tags" class="form-control select2-tags" multiple>
                                            <?php foreach ($tags as $tag): ?>
                                                <option value="<?php echo $tag['tag_id']; ?>"
                                                        <?php echo (isset($_POST['tags']) && in_array($tag['tag_id'], $_POST['tags'])) ? 'selected' : ''; ?>>
                                                    <?php echo e($tag['tag_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="new_tags" id="newTags">
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
                                
                                <!-- Featured Post -->
                                <div class="form-section">
                                    <h3 class="form-section-title">
                                        <i class="fas fa-star"></i> Options
                                    </h3>
                                    
                                    <div class="form-group">
                                        <div class="checkbox-wrapper">
                                            <input type="checkbox" 
                                                   id="is_featured" 
                                                   name="is_featured" 
                                                   value="1"
                                                   <?php echo (($_POST['is_featured'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                            <label for="is_featured" class="checkbox-label">
                                                Mark as Featured Post
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SEO Settings -->
                                <div class="form-section">
                                    <h3 class="form-section-title">
                                        <i class="fas fa-search"></i> SEO Settings
                                    </h3>
                                    
                                    <div class="form-group">
                                        <label for="seo_title" class="form-label">SEO Title</label>
                                        <input type="text" 
                                               id="seo_title" 
                                               name="seo_title" 
                                               class="form-control" 
                                               value="<?php echo e($_POST['seo_title'] ?? ''); ?>"
                                               placeholder="Auto-generated from title">
                                        <div class="char-counter" id="seoTitleCounter">0/60</div>
                                        <small class="form-text">Leave empty to auto-generate (max 60 characters)</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="seo_description" class="form-label">SEO Description</label>
                                        <textarea id="seo_description" 
                                                  name="seo_description" 
                                                  class="form-control" 
                                                  rows="3"
                                                  maxlength="160"
                                                  placeholder="Auto-generated from excerpt..."><?php echo e($_POST['seo_description'] ?? ''); ?></textarea>
                                        <div class="char-counter" id="seoDescCounter">0/160</div>
                                        <small class="form-text">Leave empty to auto-generate (max 160 characters)</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="seo_keywords" class="form-label">SEO Keywords</label>
                                        <input type="text" 
                                               id="seo_keywords" 
                                               name="seo_keywords" 
                                               class="form-control" 
                                               value="<?php echo e($_POST['seo_keywords'] ?? ''); ?>"
                                               placeholder="comma, separated, keywords">
                                        <small class="form-text">Separate keywords with commas</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('/assets/js/admin.js'); ?>"></script>
    <script src="<?php echo url('/assets/js/blog.js'); ?>"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
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
                        id: term,
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
                        .trim();
                    document.getElementById('slug').value = slug;
                }
            });
            
            // Auto-generate SEO fields
            const titleInput = document.getElementById('title');
            const excerptInput = document.getElementById('excerpt');
            const seoTitleInput = document.getElementById('seo_title');
            const seoDescInput = document.getElementById('seo_description');
            
            function updateSeoFields() {
                // Update SEO Title
                if (!seoTitleInput.value && titleInput.value) {
                    seoTitleInput.value = titleInput.value + ' | Mira Edge Technologies';
                }
                
                // Update SEO Description
                if (!seoDescInput.value && excerptInput.value) {
                    seoDescInput.value = excerptInput.value.substring(0, 160);
                }
            }
            
            titleInput.addEventListener('input', updateSeoFields);
            excerptInput.addEventListener('input', updateSeoFields);
            
            // Featured image preview
            const featuredImageInput = document.getElementById('featured_image');
            const imagePreview = document.getElementById('imagePreview');
            const previewImg = imagePreview.querySelector('img');
            const removeImageBtn = document.getElementById('removeImage');
            const uploadPlaceholder = document.querySelector('.upload-placeholder');
            
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
            
            // Remove image
            removeImageBtn.addEventListener('click', function() {
                featuredImageInput.value = '';
                previewImg.src = '';
                imagePreview.style.display = 'none';
                uploadPlaceholder.style.display = 'flex';
            });
            
            // Character counters
            function setupCharCounter(element, counterId, maxLength) {
                const counter = document.getElementById(counterId);
                
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
            
            addNewTagBtn.addEventListener('click', function() {
                addNewTag();
            });
            
            newTagInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addNewTag();
                }
            });
            
            function addNewTag() {
                const tagName = newTagInput.value.trim();
                if (tagName) {
                    // Add to Select2
                    const $select = $('.select2-tags');
                    const option = new Option(tagName, tagName, true, true);
                    $select.append(option).trigger('change');
                    
                    // Add to hidden input
                    const currentTags = newTagsInput.value ? newTagsInput.value.split(',') : [];
                    if (!currentTags.includes(tagName)) {
                        currentTags.push(tagName);
                        newTagsInput.value = currentTags.join(',');
                    }
                    
                    // Clear input
                    newTagInput.value = '';
                    newTagInput.focus();
                }
            }
            
            // Form validation
            const form = document.getElementById('addPostForm');
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
                    }
                });
                
                if (!valid) {
                    e.preventDefault();
                    showNotification('Please fill in all required fields', 'error');
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
            
            // Auto-save draft every 30 seconds
            let autoSaveTimer;
            function autoSaveDraft() {
                const formData = new FormData(form);
                formData.append('auto_save', '1');
                
                fetch('<?php echo url("/admin/modules/blog/auto-save.php"); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Draft auto-saved');
                    }
                })
                .catch(error => console.error('Auto-save error:', error));
            }
            
            // Start auto-save timer
            // autoSaveTimer = setInterval(autoSaveDraft, 30000);
            
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
        });
    </script>
</body>
</html>