<?php
/**
 * Edit Project Page
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';
require_once '../../../includes/functions/upload.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check if user is logged in and is admin
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

// Get project ID from URL
$project_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$project_id) {
    $session->setFlash('error', 'Project not found.');
    redirect(url('/admin/modules/projects/'));
}

// Fetch project data
$stmt = $db->prepare("SELECT * FROM portfolio_projects WHERE project_id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    $session->setFlash('error', 'Project not found.');
    redirect(url('/admin/modules/projects/'));
}

// Get categories for dropdown
$stmt = $db->query("SELECT category_id, category_name FROM portfolio_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $stmt->fetchAll();

// Get existing technologies
$stmt = $db->prepare("SELECT technology_name FROM project_technologies WHERE project_id = ? ORDER BY technology_name");
$stmt->execute([$project_id]);
$technologies_result = $stmt->fetchAll();
$technologies_list = array_column($technologies_result, 'technology_name');

// Get existing images
$stmt = $db->prepare("SELECT image_id, image_url FROM project_images WHERE project_id = ? ORDER BY display_order");
$stmt->execute([$project_id]);
$project_images = $stmt->fetchAll();

// Handle image deletion
if (isset($_POST['delete_image_id'])) {
    $image_id = (int)$_POST['delete_image_id'];
    
    try {
        // Get image URL
        $stmt = $db->prepare("SELECT image_url FROM project_images WHERE image_id = ? AND project_id = ?");
        $stmt->execute([$image_id, $project_id]);
        $image = $stmt->fetch();
        
        if ($image) {
            // Delete file
            $file_path = $_SERVER['DOCUMENT_ROOT'] . $image['image_url'];
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
            
            // Delete from database
            $stmt = $db->prepare("DELETE FROM project_images WHERE image_id = ?");
            $stmt->execute([$image_id]);
            
            $session->setFlash('success', 'Image deleted successfully.');
        }
    } catch (PDOException $e) {
        error_log("Delete Image Error: " . $e->getMessage());
        $session->setFlash('error', 'Error deleting image.');
    }
    
    // Refresh the page
    redirect(url('/admin/modules/projects/edit.php?id=' . $project_id));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validate inputs
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $short_description = trim($_POST['short_description'] ?? '');
    $full_description = trim($_POST['full_description'] ?? '');
    $client_name = trim($_POST['client_name'] ?? '');
    $project_url = trim($_POST['project_url'] ?? '');
    $github_url = trim($_POST['github_url'] ?? '');
    $project_date = $_POST['project_date'] ?? '';
    $completion_date = $_POST['completion_date'] ?? '';
    $category_id = $_POST['category_id'] ?? null;
    $status = $_POST['status'] ?? 'completed';
    $display_order = (int)($_POST['display_order'] ?? 0);
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $seo_title = trim($_POST['seo_title'] ?? '');
    $seo_description = trim($_POST['seo_description'] ?? '');
    $seo_keywords = trim($_POST['seo_keywords'] ?? '');
    
    // Validate required fields
    if (empty($title)) {
        $errors['title'] = 'Project title is required';
    }
    
    if (empty($slug)) {
        $slug = generateSlug($title);
    }
    
    if (empty($short_description)) {
        $errors['short_description'] = 'Short description is required';
    }
    
    if (empty($full_description)) {
        $errors['full_description'] = 'Full description is required';
    }
    
    // Check if slug already exists (exclude current project)
    $stmt = $db->prepare("SELECT project_id FROM portfolio_projects WHERE slug = ? AND project_id != ?");
    $stmt->execute([$slug, $project_id]);
    if ($stmt->fetch()) {
        $errors['slug'] = 'This slug is already in use. Please choose a different one.';
    }
    
    // Handle featured image upload
    $featured_image = $project['featured_image'];
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file_type = $_FILES['featured_image']['type'];
        $file_size = $_FILES['featured_image']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors['featured_image'] = 'Only JPG, PNG, GIF, and WebP images are allowed';
        } elseif ($file_size > $max_size) {
            $errors['featured_image'] = 'Image size must be less than 5MB';
        } else {
            // Generate unique filename
            $ext = pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . time() . '.' . $ext;
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/projects/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $upload_dir . '/' . $filename)) {
                $featured_image = '/assets/uploads/projects/' . $filename;
            } else {
                $errors['featured_image'] = 'Failed to upload image';
            }
        }
    }
    
    // If no errors, update project
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Update project
            $stmt = $db->prepare("
                UPDATE portfolio_projects 
                SET title = ?, slug = ?, short_description = ?, full_description = ?, 
                    client_name = ?, project_url = ?, github_url = ?, project_date = ?, 
                    completion_date = ?, category_id = ?, featured_image = ?, status = ?, 
                    display_order = ?, is_featured = ?, seo_title = ?, seo_description = ?, 
                    seo_keywords = ?
                WHERE project_id = ?
            ");
            
            $stmt->execute([
                $title, $slug, $short_description, $full_description, $client_name,
                $project_url, $github_url, $project_date, $completion_date, $category_id,
                $featured_image, $status, $display_order, $is_featured, $seo_title,
                $seo_description, $seo_keywords, $project_id
            ]);
            
            // Handle multiple images upload
            if (isset($_FILES['project_images']) && !empty($_FILES['project_images']['name'][0])) {
                $images = $_FILES['project_images'];
                
                for ($i = 0; $i < count($images['name']); $i++) {
                    if ($images['error'][$i] === UPLOAD_ERR_OK) {
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $file_type = $images['type'][$i];
                        
                        if (in_array($file_type, $allowed_types)) {
                            $ext = pathinfo($images['name'][$i], PATHINFO_EXTENSION);
                            $filename = uniqid() . '_' . time() . '_' . $i . '.' . $ext;
                            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/projects/gallery/';
                            
                            if (!is_dir($upload_dir)) {
                                mkdir($upload_dir, 0755, true);
                            }
                            
                            if (move_uploaded_file($images['tmp_name'][$i], $upload_dir . '/' . $filename)) {
                                $stmt = $db->prepare("
                                    INSERT INTO project_images (project_id, image_url, alt_text, display_order)
                                    VALUES (?, ?, ?, ?)
                                ");
                                
                                // Get max display order
                                $max_order_stmt = $db->prepare("SELECT MAX(display_order) as max_order FROM project_images WHERE project_id = ?");
                                $max_order_stmt->execute([$project_id]);
                                $max_order = $max_order_stmt->fetch()['max_order'] ?? 0;
                                
                                $stmt->execute([
                                    $project_id,
                                    '/assets/uploads/projects/gallery/' . $filename,
                                    $title . ' - Image ' . ($max_order + 2),
                                    $max_order + 1
                                ]);
                            }
                        }
                    }
                }
            }
            
            // Update technologies
            // Delete existing technologies
            $stmt = $db->prepare("DELETE FROM project_technologies WHERE project_id = ?");
            $stmt->execute([$project_id]);
            
            // Add new technologies
            if (isset($_POST['technologies']) && is_array($_POST['technologies'])) {
                foreach ($_POST['technologies'] as $technology) {
                    $tech_name = trim($technology);
                    if (!empty($tech_name)) {
                        $stmt = $db->prepare("
                            INSERT INTO project_technologies (project_id, technology_name)
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$project_id, $tech_name]);
                    }
                }
            }
            
            $db->commit();
            
            $session->setFlash('success', 'Project updated successfully!');
            redirect(url('/admin/modules/projects/'));
            
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Update Project Error: " . $e->getMessage());
            $errors['general'] = 'Error updating project: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Project | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/projects.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .editor-toolbar {
            background: var(--color-gray-100);
            padding: var(--space-sm);
            border-radius: var(--radius-md) var(--radius-md) 0 0;
            border: 1px solid var(--color-gray-300);
            border-bottom: none;
            display: flex;
            gap: var(--space-xs);
            flex-wrap: wrap;
        }
        
        .editor-toolbar button {
            background: var(--color-white);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-sm);
            padding: 6px 10px;
            cursor: pointer;
            color: var(--color-gray-700);
            transition: all var(--transition-fast);
        }
        
        .editor-toolbar button:hover {
            background: var(--color-gray-100);
            color: var(--color-black);
        }
        
        .image-preview {
            max-width: 200px;
            margin-top: var(--space-sm);
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 2px solid var(--color-gray-200);
        }
        
        .image-preview img {
            width: 100%;
            height: auto;
            display: block;
        }
        
        .technology-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--color-gray-100);
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 0.875rem;
            margin: 2px;
        }
        
        .technology-tag button {
            background: none;
            border: none;
            color: var(--color-gray-500);
            cursor: pointer;
            padding: 0;
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .technology-tag button:hover {
            color: var(--color-error);
        }

        .existing-images {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: var(--space-md);
            margin-top: var(--space-md);
        }

        .existing-image-item {
            position: relative;
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 2px solid var(--color-gray-200);
            transition: all var(--transition-fast);
        }

        .existing-image-item img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            display: block;
        }

        .existing-image-item .delete-image {
            position: absolute;
            top: 0;
            right: 0;
            background: rgba(255, 59, 48, 0.9);
            color: white;
            border: none;
            padding: 4px 8px;
            cursor: pointer;
            border-radius: 0;
            opacity: 0;
            transition: opacity var(--transition-fast);
        }

        .existing-image-item:hover .delete-image {
            opacity: 1;
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
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-edit"></i>
                    Edit Project
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/projects/'); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Projects
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

            <!-- Edit Project Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-edit"></i>
                        Project Details
                    </h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data" id="editProjectForm">
                        <?php if (isset($errors['general'])): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo e($errors['general']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-grid">
                            <!-- Left Column -->
                            <div class="form-column">
                                <!-- Basic Information -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-info-circle"></i>
                                        Basic Information
                                    </h4>
                                    
                                    <div class="form-group">
                                        <label for="title" class="form-label required">
                                            Project Title
                                        </label>
                                        <input type="text" 
                                               id="title" 
                                               name="title" 
                                               class="form-control <?php echo isset($errors['title']) ? 'error' : ''; ?>" 
                                               value="<?php echo e($project['title']); ?>"
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
                                                   value="<?php echo e($project['slug']); ?>">
                                            <button type="button" id="generateSlug" class="btn btn-outline">
                                                <i class="fas fa-sync-alt"></i> Generate
                                            </button>
                                        </div>
                                        <?php if (isset($errors['slug'])): ?>
                                            <div class="form-error"><?php echo e($errors['slug']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="short_description" class="form-label required">
                                            Short Description
                                        </label>
                                        <textarea id="short_description" 
                                                  name="short_description" 
                                                  class="form-control <?php echo isset($errors['short_description']) ? 'error' : ''; ?>" 
                                                  rows="3"
                                                  required><?php echo e($project['short_description']); ?></textarea>
                                        <small class="form-text">Brief description for listings (max 500 characters)</small>
                                        <?php if (isset($errors['short_description'])): ?>
                                            <div class="form-error"><?php echo e($errors['short_description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="full_description" class="form-label required">
                                            Full Description
                                        </label>
                                        <div class="editor-toolbar">
                                            <button type="button" data-command="bold"><i class="fas fa-bold"></i></button>
                                            <button type="button" data-command="italic"><i class="fas fa-italic"></i></button>
                                            <button type="button" data-command="underline"><i class="fas fa-underline"></i></button>
                                            <button type="button" data-command="insertUnorderedList"><i class="fas fa-list-ul"></i></button>
                                            <button type="button" data-command="insertOrderedList"><i class="fas fa-list-ol"></i></button>
                                            <button type="button" data-command="createLink"><i class="fas fa-link"></i></button>
                                            <button type="button" data-command="unlink"><i class="fas fa-unlink"></i></button>
                                        </div>
                                        <textarea id="full_description" 
                                                  name="full_description" 
                                                  class="form-control editor <?php echo isset($errors['full_description']) ? 'error' : ''; ?>" 
                                                  rows="10"
                                                  required><?php echo e($project['full_description']); ?></textarea>
                                        <?php if (isset($errors['full_description'])): ?>
                                            <div class="form-error"><?php echo e($errors['full_description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Client Information -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-user-tie"></i>
                                        Client Information
                                    </h4>
                                    
                                    <div class="form-group">
                                        <label for="client_name" class="form-label">
                                            Client Name
                                        </label>
                                        <input type="text" 
                                               id="client_name" 
                                               name="client_name" 
                                               class="form-control" 
                                               value="<?php echo e($project['client_name']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="project_url" class="form-label">
                                            Project URL
                                        </label>
                                        <input type="url" 
                                               id="project_url" 
                                               name="project_url" 
                                               class="form-control" 
                                               value="<?php echo e($project['project_url']); ?>"
                                               placeholder="https://example.com">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="github_url" class="form-label">
                                            GitHub URL
                                        </label>
                                        <input type="url" 
                                               id="github_url" 
                                               name="github_url" 
                                               class="form-control" 
                                               value="<?php echo e($project['github_url']); ?>"
                                               placeholder="https://github.com/username/project">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Column -->
                            <div class="form-column">
                                <!-- Project Media -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-images"></i>
                                        Project Media
                                    </h4>
                                    
                                    <div class="form-group">
                                        <label for="featured_image" class="form-label">
                                            Featured Image
                                        </label>
                                        <div class="file-upload">
                                            <input type="file" 
                                                   id="featured_image" 
                                                   name="featured_image" 
                                                   class="file-input <?php echo isset($errors['featured_image']) ? 'error' : ''; ?>"
                                                   accept="image/*">
                                            <label for="featured_image" class="file-upload-label">
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <span>Choose featured image...</span>
                                            </label>
                                            <div class="image-preview" id="featuredImagePreview">
                                                <img src="" alt="Featured image preview">
                                            </div>
                                        </div>
                                        <?php if (isset($errors['featured_image'])): ?>
                                            <div class="form-error"><?php echo e($errors['featured_image']); ?></div>
                                        <?php endif; ?>
                                        <small class="form-text">Recommended size: 1200x800 pixels, Max size: 5MB</small>
                                        
                                        <?php if ($project['featured_image']): ?>
                                            <div class="current-image">
                                                <p><strong>Current Image:</strong></p>
                                                <div class="image-preview">
                                                    <img src="<?php echo $project['featured_image']; ?>" alt="Featured image">
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="project_images" class="form-label">
                                            Additional Images
                                        </label>
                                        <div class="file-upload">
                                            <input type="file" 
                                                   id="project_images" 
                                                   name="project_images[]" 
                                                   class="file-input"
                                                   accept="image/*"
                                                   multiple>
                                            <label for="project_images" class="file-upload-label">
                                                <i class="fas fa-images"></i>
                                                <span>Choose multiple images...</span>
                                            </label>
                                        </div>
                                        <small class="form-text">Upload multiple images for project gallery</small>
                                        
                                        <?php if (!empty($project_images)): ?>
                                            <div class="existing-images">
                                                <?php foreach ($project_images as $image): ?>
                                                    <div class="existing-image-item">
                                                        <img src="<?php echo $image['image_url']; ?>" alt="Project image">
                                                        <button type="button" 
                                                                class="delete-image" 
                                                                data-image-id="<?php echo $image['image_id']; ?>"
                                                                title="Delete image">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Project Details -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-cog"></i>
                                        Project Details
                                    </h4>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="category_id" class="form-label">
                                                Category
                                            </label>
                                            <select id="category_id" 
                                                    name="category_id" 
                                                    class="form-control select2">
                                                <option value="">Select Category</option>
                                                <?php foreach ($categories as $category): ?>
                                                    <option value="<?php echo $category['category_id']; ?>"
                                                            <?php echo ($project['category_id'] == $category['category_id']) ? 'selected' : ''; ?>>
                                                        <?php echo e($category['category_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="status" class="form-label">
                                                Status
                                            </label>
                                            <select id="status" name="status" class="form-control">
                                                <option value="completed" <?php echo ($project['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                                <option value="ongoing" <?php echo ($project['status'] == 'ongoing') ? 'selected' : ''; ?>>Ongoing</option>
                                                <option value="upcoming" <?php echo ($project['status'] == 'upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="project_date" class="form-label">
                                                Project Date
                                            </label>
                                            <input type="date" 
                                                   id="project_date" 
                                                   name="project_date" 
                                                   class="form-control" 
                                                   value="<?php echo $project['project_date']; ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="completion_date" class="form-label">
                                                Completion Date
                                            </label>
                                            <input type="date" 
                                                   id="completion_date" 
                                                   name="completion_date" 
                                                   class="form-control" 
                                                   value="<?php echo $project['completion_date']; ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="display_order" class="form-label">
                                                Display Order
                                            </label>
                                            <input type="number" 
                                                   id="display_order" 
                                                   name="display_order" 
                                                   class="form-control" 
                                                   value="<?php echo $project['display_order']; ?>"
                                                   min="0"
                                                   step="1">
                                            <small class="form-text">Lower numbers display first</small>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">
                                                &nbsp;
                                            </label>
                                            <div class="checkbox-wrapper">
                                                <input type="checkbox" 
                                                       id="is_featured" 
                                                       name="is_featured" 
                                                       value="1"
                                                       <?php echo ($project['is_featured'] == 1) ? 'checked' : ''; ?>>
                                                <label for="is_featured" class="checkbox-label">
                                                    <i class="fas fa-star"></i> Mark as Featured
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Technologies -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-code"></i>
                                        Technologies Used
                                    </h4>
                                    
                                    <div class="form-group">
                                        <div class="input-with-button">
                                            <input type="text" 
                                                   id="technology_input" 
                                                   class="form-control" 
                                                   placeholder="Add a technology (e.g., PHP, React, Laravel)">
                                            <button type="button" id="addTechnology" class="btn btn-outline">
                                                <i class="fas fa-plus"></i> Add
                                            </button>
                                        </div>
                                        <div class="technologies-container" id="technologiesContainer">
                                            <!-- Technologies will be added here -->
                                        </div>
                                        <input type="hidden" name="technologies_json" id="technologiesInput">
                                    </div>
                                </div>
                                
                                <!-- SEO Settings -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-search"></i>
                                        SEO Settings
                                    </h4>
                                    
                                    <div class="form-group">
                                        <label for="seo_title" class="form-label">
                                            SEO Title
                                        </label>
                                        <input type="text" 
                                               id="seo_title" 
                                               name="seo_title" 
                                               class="form-control" 
                                               value="<?php echo e($project['seo_title']); ?>"
                                               placeholder="Auto-generated from title">
                                        <small class="form-text">Leave empty to auto-generate from title</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="seo_description" class="form-label">
                                            SEO Description
                                        </label>
                                        <textarea id="seo_description" 
                                                  name="seo_description" 
                                                  class="form-control" 
                                                  rows="2"
                                                  placeholder="Auto-generated from short description"><?php echo e($project['seo_description']); ?></textarea>
                                        <small class="form-text">Leave empty to auto-generate from short description</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="seo_keywords" class="form-label">
                                            SEO Keywords
                                        </label>
                                        <input type="text" 
                                               id="seo_keywords" 
                                               name="seo_keywords" 
                                               class="form-control" 
                                               value="<?php echo e($project['seo_keywords']); ?>"
                                               placeholder="comma, separated, keywords">
                                        <small class="form-text">Separate keywords with commas</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" name="update_project" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="reset" class="btn btn-outline">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                            <a href="<?php echo url('/admin/modules/projects/'); ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script src="<?php echo url('assets/js/projects.js'); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Edit Project specific JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2
            $('#category_id').select2({
                placeholder: 'Select Category',
                allowClear: true,
                width: '100%'
            });
            
            // Initialize technologies from existing data
            const technologies = <?php echo json_encode($technologies_list); ?>;
            
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
            document.getElementById('title').addEventListener('input', function() {
                const title = this.value;
                const seoTitle = document.getElementById('seo_title');
                
                if (!seoTitle.value && title) {
                    seoTitle.value = title + ' | Mira Edge Technologies';
                }
            });
            
            // Featured image preview
            document.getElementById('featured_image').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.getElementById('featuredImagePreview');
                        preview.querySelector('img').src = e.target.result;
                        preview.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
                }
            });
            
            // Technologies management
            const technologyInput = document.getElementById('technology_input');
            const technologiesContainer = document.getElementById('technologiesContainer');
            const technologiesInput = document.getElementById('technologiesInput');
            let techList = [...technologies];
            
            function updateTechnologiesUI() {
                technologiesContainer.innerHTML = '';
                
                techList.forEach((tech, index) => {
                    const tag = document.createElement('div');
                    tag.className = 'technology-tag';
                    tag.innerHTML = `
                        ${escapeHtml(tech)}
                        <button type="button" class="remove-technology" data-index="${index}">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    technologiesContainer.appendChild(tag);
                });
                
                // Create hidden inputs for technologies
                const form = document.getElementById('editProjectForm');
                const oldInputs = form.querySelectorAll('input[name="technologies[]"]');
                oldInputs.forEach(input => input.remove());
                
                techList.forEach(tech => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'technologies[]';
                    input.value = tech;
                    form.appendChild(input);
                });
                
                // Add event listeners to remove buttons
                document.querySelectorAll('.remove-technology').forEach(button => {
                    button.addEventListener('click', (e) => {
                        e.preventDefault();
                        const index = parseInt(button.dataset.index);
                        techList.splice(index, 1);
                        updateTechnologiesUI();
                    });
                });
            }
            
            document.getElementById('addTechnology').addEventListener('click', function(e) {
                e.preventDefault();
                const tech = technologyInput.value.trim();
                if (tech && !techList.includes(tech)) {
                    techList.push(tech);
                    updateTechnologiesUI();
                    technologyInput.value = '';
                    technologyInput.focus();
                }
            });
            
            technologyInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('addTechnology').click();
                }
            });
            
            // Handle delete image buttons
            document.querySelectorAll('.delete-image').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const imageId = this.dataset.imageId;
                    
                    if (confirm('Are you sure you want to delete this image?')) {
                        // Redirect to delete the image via POST
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.style.display = 'none';
                        
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'delete_image_id';
                        input.value = imageId;
                        
                        form.appendChild(input);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
            
            // Text editor toolbar
            document.querySelectorAll('.editor-toolbar button').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const command = this.dataset.command;
                    
                    if (command === 'createLink') {
                        const url = prompt('Enter URL:');
                        if (url) {
                            document.execCommand('createLink', false, url);
                        }
                    } else if (command === 'unlink') {
                        document.execCommand('unlink', false, null);
                    } else {
                        document.execCommand(command, false, null);
                    }
                    
                    document.getElementById('full_description').focus();
                });
            });
            
            // Form validation
            const form = document.getElementById('editProjectForm');
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
                        const error = field.nextElementSibling;
                        if (error && error.classList.contains('form-error')) {
                            error.remove();
                        }
                    }
                });
                
                if (!valid) {
                    e.preventDefault();
                    showNotification('Please fill in all required fields', 'error');
                }
            });
            
            // Character counters
            const shortDesc = document.getElementById('short_description');
            const seoDesc = document.getElementById('seo_description');
            
            function addCharacterCounter(textarea, maxLength) {
                const counter = document.createElement('div');
                counter.className = 'char-counter';
                counter.style.fontSize = '0.75rem';
                counter.style.color = 'var(--color-gray-500)';
                counter.style.textAlign = 'right';
                counter.style.marginTop = '4px';
                
                textarea.parentNode.insertBefore(counter, textarea.nextSibling);
                
                function updateCounter() {
                    const length = textarea.value.length;
                    counter.textContent = `${length} / ${maxLength}`;
                    
                    if (length > maxLength * 0.9) {
                        counter.style.color = 'var(--color-warning)';
                    } else if (length > maxLength) {
                        counter.style.color = 'var(--color-error)';
                    } else {
                        counter.style.color = 'var(--color-gray-500)';
                    }
                }
                
                textarea.addEventListener('input', updateCounter);
                updateCounter();
            }
            
            addCharacterCounter(shortDesc, 500);
            addCharacterCounter(seoDesc, 160);
            
            // Initialize technologies UI on load
            updateTechnologiesUI();
        });
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>
