<?php
/**
 * Projects Management - All Projects
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

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

// Handle AJAX requests
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'toggle_feature' && isset($_GET['id'])) {
        $project_id = (int)$_GET['id'];
        
        try {
            // Get current featured status
            $stmt = $db->prepare("SELECT is_featured FROM portfolio_projects WHERE project_id = ?");
            $stmt->execute([$project_id]);
            $result = $stmt->fetch();
            
            if ($result) {
                $new_status = $result['is_featured'] ? 0 : 1;
                $stmt = $db->prepare("UPDATE portfolio_projects SET is_featured = ? WHERE project_id = ?");
                $stmt->execute([$new_status, $project_id]);
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'featured' => $new_status]);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Toggle Feature Error: " . $e->getMessage());
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error updating project']);
        exit;
    }
}

// Backwards-compatible GET delete handler (supports links/bookmarks that use ?delete=ID)
if (isset($_GET['delete'])) {
    $project_id = (int)$_GET['delete'];

    try {
        $db->beginTransaction();

        // Get featured image to delete
        $stmt = $db->prepare("SELECT featured_image FROM portfolio_projects WHERE project_id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();

        if ($project && $project['featured_image']) {
            $file_path = $_SERVER['DOCUMENT_ROOT'] . $project['featured_image'];
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }

        // Delete gallery images
        $stmt = $db->prepare("SELECT image_url FROM project_images WHERE project_id = ?");
        $stmt->execute([$project_id]);
        $images = $stmt->fetchAll();
        foreach ($images as $img) {
            $file_path = $_SERVER['DOCUMENT_ROOT'] . $img['image_url'];
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }

        // Remove DB records
        $stmt = $db->prepare("DELETE FROM project_images WHERE project_id = ?");
        $stmt->execute([$project_id]);

        $stmt = $db->prepare("DELETE FROM project_technologies WHERE project_id = ?");
        $stmt->execute([$project_id]);

        $stmt = $db->prepare("DELETE FROM portfolio_projects WHERE project_id = ?");
        $stmt->execute([$project_id]);

        $db->commit();
        $session->setFlash('success', 'Project deleted successfully.');
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Delete Project (GET) Error: " . $e->getMessage());
        $session->setFlash('error', 'Error deleting project.');
    }

    redirect(url('/admin/modules/projects/'));
}

$user = $session->getUser();
$user_id = $user['user_id'];

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_projects'])) {
        $action = $_POST['bulk_action'];
        $selected_projects = $_POST['selected_projects'];
        
        try {
            if ($action === 'delete') {
                $placeholders = implode(',', array_fill(0, count($selected_projects), '?'));
                
                // Delete associated images first
                $stmt = $db->prepare("SELECT project_id FROM portfolio_projects WHERE project_id IN ($placeholders)");
                $stmt->execute($selected_projects);
                $projects_to_delete = $stmt->fetchAll();
                
                foreach ($projects_to_delete as $proj) {
                    $img_stmt = $db->prepare("SELECT image_url FROM project_images WHERE project_id = ?");
                    $img_stmt->execute([$proj['project_id']]);
                    $images = $img_stmt->fetchAll();
                    
                    foreach ($images as $img) {
                        $file_path = $_SERVER['DOCUMENT_ROOT'] . $img['image_url'];
                        if (file_exists($file_path)) {
                            @unlink($file_path);
                        }
                    }
                }
                
                // Delete project images
                $stmt = $db->prepare("DELETE FROM project_images WHERE project_id IN ($placeholders)");
                $stmt->execute($selected_projects);
                
                // Delete project technologies
                $stmt = $db->prepare("DELETE FROM project_technologies WHERE project_id IN ($placeholders)");
                $stmt->execute($selected_projects);
                
                // Delete projects
                $stmt = $db->prepare("DELETE FROM portfolio_projects WHERE project_id IN ($placeholders)");
                $stmt->execute($selected_projects);
                
                $session->setFlash('success', 'Selected projects deleted successfully.');
            } elseif ($action === 'feature') {
                $placeholders = implode(',', array_fill(0, count($selected_projects), '?'));
                $stmt = $db->prepare("UPDATE portfolio_projects SET is_featured = 1 WHERE project_id IN ($placeholders)");
                $stmt->execute($selected_projects);
                $session->setFlash('success', 'Selected projects featured successfully.');
            } elseif ($action === 'unfeature') {
                $placeholders = implode(',', array_fill(0, count($selected_projects), '?'));
                $stmt = $db->prepare("UPDATE portfolio_projects SET is_featured = 0 WHERE project_id IN ($placeholders)");
                $stmt->execute($selected_projects);
                $session->setFlash('success', 'Selected projects unfeatured successfully.');
            }
        } catch (PDOException $e) {
            error_log("Bulk Action Error: " . $e->getMessage());
            $session->setFlash('error', 'Error performing bulk action.');
        }
    }
    
    // Handle single project delete
    if (isset($_POST['delete_project']) && isset($_POST['project_id'])) {
        $project_id = (int)$_POST['project_id'];
        
        try {
            $db->beginTransaction();
            
            // Get project to delete featured image
            $stmt = $db->prepare("SELECT featured_image FROM portfolio_projects WHERE project_id = ?");
            $stmt->execute([$project_id]);
            $project = $stmt->fetch();
            
            if ($project && $project['featured_image']) {
                $file_path = $_SERVER['DOCUMENT_ROOT'] . $project['featured_image'];
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
            
            // Delete project images
            $stmt = $db->prepare("SELECT image_url FROM project_images WHERE project_id = ?");
            $stmt->execute([$project_id]);
            $images = $stmt->fetchAll();
            
            foreach ($images as $img) {
                $file_path = $_SERVER['DOCUMENT_ROOT'] . $img['image_url'];
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
            
            // Delete from database
            $stmt = $db->prepare("DELETE FROM project_images WHERE project_id = ?");
            $stmt->execute([$project_id]);
            
            $stmt = $db->prepare("DELETE FROM project_technologies WHERE project_id = ?");
            $stmt->execute([$project_id]);
            
            $stmt = $db->prepare("DELETE FROM portfolio_projects WHERE project_id = ?");
            $stmt->execute([$project_id]);
            
            $db->commit();
            
            $session->setFlash('success', 'Project deleted successfully.');
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Delete Project Error: " . $e->getMessage());
            $session->setFlash('error', 'Error deleting project.');
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build query
$where_clauses = [];
$params = [];

if ($status_filter && $status_filter !== 'all') {
    $where_clauses[] = "pp.status = ?";
    $params[] = $status_filter;
}

if ($category_filter && $category_filter !== 'all') {
    $where_clauses[] = "pp.category_id = ?";
    $params[] = $category_filter;
}

if ($search_query) {
    $where_clauses[] = "(pp.title LIKE ? OR pp.short_description LIKE ? OR pp.client_name LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM portfolio_projects pp $where_sql";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_items = $stmt->fetch()['total'];
$total_pages = ceil($total_items / $per_page);

// Get projects with pagination
$projects_sql = "
    SELECT pp.*, pc.category_name, 
           CONCAT(u.first_name, ' ', u.last_name) as creator_name
    FROM portfolio_projects pp
    LEFT JOIN portfolio_categories pc ON pp.category_id = pc.category_id
    LEFT JOIN users u ON pp.created_by = u.user_id
    $where_sql
    ORDER BY pp.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($projects_sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Get categories for filter
$stmt = $db->query("SELECT category_id, category_name FROM portfolio_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects Management | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/projects.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <i class="fas fa-project-diagram"></i>
                    Projects Management
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/projects/add.php'); ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Project
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

            <!-- Filters & Search -->
            <div class="card filters-card">
                <div class="card-body">
                    <form method="GET" action="" class="filters-form">
                        <div class="filters-grid">
                            <!-- Search -->
                            <div class="filter-group">
                                <label for="search" class="filter-label">
                                    <i class="fas fa-search"></i> Search
                                </label>
                                <input type="text" 
                                       id="search" 
                                       name="search" 
                                       class="filter-input" 
                                       value="<?php echo e($search_query); ?>"
                                       placeholder="Search projects...">
                            </div>
                            
                            <!-- Status Filter -->
                            <div class="filter-group">
                                <label for="status" class="filter-label">
                                    <i class="fas fa-filter"></i> Status
                                </label>
                                <select id="status" name="status" class="filter-select">
                                    <option value="all" <?php echo ($status_filter === 'all' || !$status_filter) ? 'selected' : ''; ?>>All Status</option>
                                    <option value="completed" <?php echo ($status_filter === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                    <option value="ongoing" <?php echo ($status_filter === 'ongoing') ? 'selected' : ''; ?>>Ongoing</option>
                                    <option value="upcoming" <?php echo ($status_filter === 'upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                                </select>
                            </div>
                            
                            <!-- Category Filter -->
                            <div class="filter-group">
                                <label for="category" class="filter-label">
                                    <i class="fas fa-tag"></i> Category
                                </label>
                                <select id="category" name="category" class="filter-select">
                                    <option value="all" <?php echo ($category_filter === 'all' || !$category_filter) ? 'selected' : ''; ?>>All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['category_id']; ?>" 
                                                <?php echo ($category_filter == $category['category_id']) ? 'selected' : ''; ?>>
                                            <?php echo e($category['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Filter Actions -->
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="<?php echo url('/admin/modules/projects/'); ?>" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Projects Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        All Projects (<?php echo $total_items; ?>)
                    </h3>
                    
                    <!-- Bulk Actions -->
                    <form method="POST" action="" class="bulk-actions-form">
                        <div class="bulk-actions">
                            <select name="bulk_action" class="bulk-action-select">
                                <option value="">Bulk Actions</option>
                                <option value="feature">Mark as Featured</option>
                                <option value="unfeature">Remove Featured</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button type="submit" class="btn btn-outline" name="apply_bulk_action">
                                Apply
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($projects)): ?>
                        <div class="table-responsive">
                            <table class="table projects-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="select-all" class="select-all-checkbox">
                                        </th>
                                        <th class="image-cell">Image</th>
                                        <th class="title-cell">Project Title</th>
                                        <th class="category-cell">Category</th>
                                        <th class="status-cell">Status</th>
                                        <th class="featured-cell">Featured</th>
                                        <th class="date-cell">Date</th>
                                        <th class="views-cell">Views</th>
                                        <th class="actions-cell">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $project): ?>
                                        <tr class="project-row" data-project-id="<?php echo $project['project_id']; ?>">
                                            <td class="checkbox-cell">
                                                <input type="checkbox" 
                                                       name="selected_projects[]" 
                                                       value="<?php echo $project['project_id']; ?>"
                                                       class="project-checkbox">
                                            </td>
                                            <td class="image-cell">
                                                <div class="project-image-thumb">
                                                      <?php
                                                         $imgSrc = $project['featured_image'] ? url(ltrim($project['featured_image'], '/')) : url('assets/images/default-project.jpg');
                                                      ?>
                                                      <img src="<?php echo e($imgSrc); ?>"
                                                          alt="<?php echo e($project['title']); ?>"
                                                          onerror="this.src='<?php echo url('assets/images/default-project.jpg'); ?>'">
                                                </div>
                                            </td>
                                            <td class="title-cell">
                                                <div class="project-title-info">
                                                    <h4 class="project-title">
                                                        <a href="<?php echo url('/admin/modules/projects/edit.php?id=' . $project['project_id']); ?>">
                                                            <?php echo e($project['title']); ?>
                                                        </a>
                                                    </h4>
                                                    <p class="project-description">
                                                        <?php echo e(substr($project['short_description'], 0, 100)); ?>...
                                                    </p>
                                                    <div class="project-client">
                                                        <i class="fas fa-user"></i>
                                                        <?php echo e($project['client_name'] ?: 'No client'); ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="category-cell">
                                                <span class="category-badge">
                                                    <?php echo e($project['category_name'] ?: 'Uncategorized'); ?>
                                                </span>
                                            </td>
                                            <td class="status-cell">
                                                <span class="status-badge status-<?php echo strtolower($project['status']); ?>">
                                                    <?php echo ucfirst($project['status']); ?>
                                                </span>
                                            </td>
                                            <td class="featured-cell">
                                                <span class="featured-badge <?php echo $project['is_featured'] ? 'featured' : 'not-featured'; ?>">
                                                    <i class="fas fa-star"></i>
                                                    <?php echo $project['is_featured'] ? 'Featured' : 'No'; ?>
                                                </span>
                                            </td>
                                            <td class="date-cell">
                                                <div class="date-info">
                                                    <div class="created-date">
                                                        <?php echo formatDate($project['created_at'], 'M d, Y'); ?>
                                                    </div>
                                                    <?php if ($project['completion_date']): ?>
                                                        <div class="completed-date">
                                                            Completed: <?php echo formatDate($project['completion_date'], 'M d'); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="views-cell">
                                                <div class="views-info">
                                                    <i class="fas fa-eye"></i>
                                                    <?php echo number_format($project['views_count']); ?>
                                                </div>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('/admin/modules/projects/edit.php?id=' . $project['project_id']); ?>" 
                                                       class="btn-action btn-edit"
                                                       data-tooltip="Edit Project">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <a href="<?php echo url('/?page=project&slug=' . $project['slug']); ?>" 
                                                       target="_blank"
                                                       class="btn-action btn-view"
                                                       data-tooltip="View Project">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-feature <?php echo $project['is_featured'] ? 'featured' : ''; ?>"
                                                            data-project-id="<?php echo $project['project_id']; ?>"
                                                            data-featured="<?php echo $project['is_featured']; ?>"
                                                            data-tooltip="<?php echo $project['is_featured'] ? 'Unfeature' : 'Feature'; ?>">
                                                        <i class="fas fa-star"></i>
                                                    </button>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-delete"
                                                            data-project-id="<?php echo $project['project_id']; ?>"
                                                            data-project-title="<?php echo e($project['title']); ?>"
                                                            data-tooltip="Delete Project">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <div class="pagination-info">
                                    Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $per_page, $total_items); ?> of <?php echo $total_items; ?> projects
                                </div>
                                
                                <div class="pagination-links">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" 
                                           class="pagination-link first">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                           class="pagination-link prev">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): 
                                    ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                           class="pagination-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                           class="pagination-link next">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" 
                                           class="pagination-link last">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-project-diagram"></i>
                            </div>
                            <h3>No Projects Found</h3>
                            <p>No projects match your search criteria. Try adjusting your filters or add a new project.</p>
                            <a href="<?php echo url('/admin/modules/projects/add.php'); ?>" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Your First Project
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete Project</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the project "<span id="projectToDelete"></span>"?</p>
                <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="project_id" id="deleteProjectId">
                    <button type="button" class="btn btn-outline modal-cancel">Cancel</button>
                    <button type="submit" name="delete_project" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Project
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script src="<?php echo url('assets/js/projects.js'); ?>"></script>
    <script>
        // Projects specific JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            const projects = new ProjectsManager();
            projects.init();
        });
    </script>
</body>
</html>