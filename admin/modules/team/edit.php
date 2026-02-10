<?php
/**
 * Edit Team Member
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

$user = $session->getUser();
$user_id = $user['user_id'];

// Get member ID
$member_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$member_id) {
    $session->setFlash('error', 'Invalid member ID.');
    redirect(url('/admin/modules/team/'));
}

// Get member data
$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$member_id]);
$member = $stmt->fetch();

if (!$member) {
    $session->setFlash('error', 'Team member not found.');
    redirect(url('/admin/modules/team/'));
}

// Get teams for assignment
$stmt = $db->query("SELECT team_id, team_name FROM teams WHERE status = 'active' ORDER BY team_name");
$teams = $stmt->fetchAll();

// Get member's current teams
$stmt = $db->prepare("
    SELECT t.team_id, t.team_name 
    FROM user_teams ut 
    JOIN teams t ON ut.team_id = t.team_id 
    WHERE ut.user_id = ? AND ut.is_active = 1
");
$stmt->execute([$member_id]);
$member_teams = $stmt->fetchAll();
$current_team_ids = array_column($member_teams, 'team_id');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Collect form data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $role = $_POST['role'] ?? 'developer';
    $selected_teams = $_POST['teams'] ?? [];
    $bio = trim($_POST['bio'] ?? '');
    $linkedin_url = trim($_POST['linkedin_url'] ?? '');
    $github_url = trim($_POST['github_url'] ?? '');
    $status = $_POST['status'] ?? 'active';
    
    // Validate required fields
    if (empty($first_name)) {
        $errors['first_name'] = 'First name is required';
    }
    
    if (empty($last_name)) {
        $errors['last_name'] = 'Last name is required';
    }
    
    if (empty($username)) {
        $errors['username'] = 'Username is required';
    } elseif (strlen($username) < 3) {
        $errors['username'] = 'Username must be at least 3 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors['username'] = 'Username can only contain letters, numbers, and underscores';
    } elseif ($username != $member['username']) {
        // Check if username exists (excluding current user)
        $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $stmt->execute([$username, $member_id]);
        if ($stmt->fetch()) {
            $errors['username'] = 'Username already exists';
        }
    }
    
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } elseif ($email != $member['email']) {
        // Check if email exists (excluding current user)
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->execute([$email, $member_id]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Email already registered';
        }
    }
    
    // Handle profile image upload
    $profile_image = $member['profile_image'];
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        $file_type = $_FILES['profile_image']['type'];
        $file_size = $_FILES['profile_image']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors['profile_image'] = 'Only JPG, PNG, GIF, and WebP images are allowed';
        } elseif ($file_size > $max_size) {
            $errors['profile_image'] = 'Image size must be less than 2MB';
        } else {
            // Generate unique filename
            $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . time() . '.' . $ext;
            $upload_path = '/assets/uploads/team/' . $filename;
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/team/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $filename)) {
                // Delete old image if it exists and is not default
                if ($profile_image && !str_contains($profile_image, 'default')) {
                    $old_image_path = $_SERVER['DOCUMENT_ROOT'] . $profile_image;
                    if (file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }
                }
                $profile_image = $upload_path;
            } else {
                $errors['profile_image'] = 'Failed to upload image';
            }
        }
    }
    
    // If no errors, update user
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Update user
            $stmt = $db->prepare("
                UPDATE users 
                SET username = ?, email = ?, first_name = ?, last_name = ?, phone = ?, 
                    profile_image = ?, position = ?, role = ?, bio = ?, 
                    linkedin_url = ?, github_url = ?, status = ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $username, $email, $first_name, $last_name, $phone,
                $profile_image, $position, $role, $bio, 
                $linkedin_url, $github_url, $status, $member_id
            ]);
            
            // Update team assignments
            // First, deactivate all current team assignments
            $stmt = $db->prepare("UPDATE user_teams SET is_active = 0 WHERE user_id = ?");
            $stmt->execute([$member_id]);
            
            // Add new team assignments
            if (!empty($selected_teams)) {
                foreach ($selected_teams as $team_id) {
                    // Check if assignment already exists
                    $stmt = $db->prepare("SELECT user_team_id FROM user_teams WHERE user_id = ? AND team_id = ?");
                    $stmt->execute([$member_id, $team_id]);
                    
                    if ($stmt->fetch()) {
                        // Update existing
                        $stmt = $db->prepare("UPDATE user_teams SET is_active = 1 WHERE user_id = ? AND team_id = ?");
                        $stmt->execute([$member_id, $team_id]);
                    } else {
                        // Insert new
                        $stmt = $db->prepare("INSERT INTO user_teams (user_id, team_id, is_active) VALUES (?, ?, 1)");
                        $stmt->execute([$member_id, $team_id]);
                    }
                }
            }
            
            $db->commit();
            
            $session->setFlash('success', 'Team member updated successfully!');
            redirect(url('/admin/modules/team/edit.php?id=' . $member_id));
            
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Update Member Error: " . $e->getMessage());
            $errors['general'] = 'Error updating team member: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Team Member | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .member-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--color-gray-200);
        }
        
        .member-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--color-black);
        }
        
        .member-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .member-avatar-large .placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--color-black), var(--color-gray-700));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 36px;
            font-weight: bold;
        }
        
        .member-info-header h2 {
            margin: 0 0 5px;
            font-size: 24px;
        }
        
        .member-meta {
            display: flex;
            gap: 15px;
            color: var(--color-gray-600);
            font-size: 14px;
        }
        
        .member-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .danger-zone {
            margin-top: 40px;
            padding: 20px;
            border: 2px solid var(--color-error);
            border-radius: var(--radius-lg);
            background: rgba(244, 67, 54, 0.05);
        }
        
        .danger-zone h4 {
            color: var(--color-error);
            margin-bottom: 15px;
        }
        
        .danger-zone .btn-danger {
            background: var(--color-error);
            color: white;
        }
        
        .danger-zone .btn-danger:hover {
            background: #d32f2f;
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
                    <i class="fas fa-user-edit"></i>
                    Edit Team Member
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/team/'); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Team
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

            <!-- Member Header -->
            <div class="member-header">
                <div class="member-avatar-large">
                    <?php if (!empty($member['profile_image'])): ?>
                        <img src="<?php echo e($member['profile_image']); ?>" alt="<?php echo e($member['first_name']); ?>">
                    <?php else: ?>
                        <div class="placeholder">
                            <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="member-info-header">
                    <h2><?php echo e($member['first_name'] . ' ' . $member['last_name']); ?></h2>
                    <div class="member-meta">
                        <div class="member-meta-item">
                            <i class="fas fa-user-tag"></i>
                            <span class="role-badge role-<?php echo strtolower($member['role']); ?>">
                                <?php echo str_replace('_', ' ', $member['role']); ?>
                            </span>
                        </div>
                        <div class="member-meta-item">
                            <i class="fas fa-circle"></i>
                            <span class="status-<?php echo strtolower($member['status']); ?>">
                                <?php echo ucfirst($member['status']); ?>
                            </span>
                        </div>
                        <div class="member-meta-item">
                            <i class="fas fa-calendar"></i>
                            Member since <?php echo formatDate($member['created_at'], 'M d, Y'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Member Form -->
            <div class="card team-form-container">
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data" id="editMemberForm">
                        <?php if (isset($errors['general'])): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo e($errors['general']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-grid">
                            <!-- Left Column -->
                            <div class="form-column">
                                <!-- Personal Information -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-user"></i>
                                        Personal Information
                                    </h4>
                                    
                                    <div class="image-upload-wrapper">
                                        <div class="profile-image-preview" id="profileImagePreview">
                                            <?php if (!empty($member['profile_image'])): ?>
                                                <img src="<?php echo e($member['profile_image']); ?>" alt="Profile preview">
                                            <?php else: ?>
                                                <div class="placeholder">
                                                    <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <input type="file" 
                                               id="profile_image" 
                                               name="profile_image" 
                                               class="file-input"
                                               accept="image/*"
                                               style="display: none;">
                                        <button type="button" class="btn btn-outline" onclick="document.getElementById('profile_image').click()">
                                            <i class="fas fa-camera"></i> Change Profile Image
                                        </button>
                                        <p class="image-upload-text">Recommended: 400x400px, max 2MB</p>
                                        <?php if (isset($errors['profile_image'])): ?>
                                            <div class="form-error"><?php echo e($errors['profile_image']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="first_name" class="form-label required">
                                                First Name
                                            </label>
                                            <input type="text" 
                                                   id="first_name" 
                                                   name="first_name" 
                                                   class="form-control <?php echo isset($errors['first_name']) ? 'error' : ''; ?>" 
                                                   value="<?php echo e(htmlspecialchars($member['first_name'])); ?>"
                                                   required>
                                            <?php if (isset($errors['first_name'])): ?>
                                                <div class="form-error"><?php echo e($errors['first_name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="last_name" class="form-label required">
                                                Last Name
                                            </label>
                                            <input type="text" 
                                                   id="last_name" 
                                                   name="last_name" 
                                                   class="form-control <?php echo isset($errors['last_name']) ? 'error' : ''; ?>" 
                                                   value="<?php echo e(htmlspecialchars($member['last_name'])); ?>"
                                                   required>
                                            <?php if (isset($errors['last_name'])): ?>
                                                <div class="form-error"><?php echo e($errors['last_name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="position" class="form-label">
                                                Position/Title
                                            </label>
                                            <input type="text" 
                                                   id="position" 
                                                   name="position" 
                                                   class="form-control" 
                                                   value="<?php echo e(htmlspecialchars($member['position'] ?? '')); ?>"
                                                   placeholder="e.g., Senior Web Developer">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="phone" class="form-label">
                                                Phone Number
                                            </label>
                                            <input type="tel" 
                                                   id="phone" 
                                                   name="phone" 
                                                   class="form-control" 
                                                   value="<?php echo e(htmlspecialchars($member['phone'] ?? '')); ?>"
                                                   placeholder="+237 6XX XXX XXX">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="bio" class="form-label">
                                            Bio
                                        </label>
                                        <textarea id="bio" 
                                                  name="bio" 
                                                  class="form-control" 
                                                  rows="4"
                                                  placeholder="Tell us about this team member..."><?php echo e(htmlspecialchars($member['bio'] ?? '')); ?></textarea>
                                    </div>
                                </div>
                                
                                <!-- Account Status -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-user-cog"></i>
                                        Account Status
                                    </h4>
                                    
                                    <div class="form-group">
                                        <label for="status" class="form-label required">
                                            Account Status
                                        </label>
                                        <select id="status" name="status" class="form-control" required>
                                            <option value="active" <?php echo ($member['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo ($member['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="on_leave" <?php echo ($member['status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                                        </select>
                                    </div>
                                    
                                    <div class="account-stats">
                                        <div class="stat-item">
                                            <span class="stat-label">Last Login:</span>
                                            <span class="stat-value">
                                                <?php echo $member['last_login'] ? formatDate($member['last_login']) : 'Never'; ?>
                                            </span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Account Created:</span>
                                            <span class="stat-value"><?php echo formatDate($member['created_at']); ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Last Updated:</span>
                                            <span class="stat-value"><?php echo formatDate($member['updated_at']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Column -->
                            <div class="form-column">
                                <!-- Login Information -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-key"></i>
                                        Login Information
                                    </h4>
                                    
                                    <div class="form-group">
                                        <label for="username" class="form-label required">
                                            Username
                                        </label>
                                        <input type="text" 
                                               id="username" 
                                               name="username" 
                                               class="form-control <?php echo isset($errors['username']) ? 'error' : ''; ?>" 
                                               value="<?php echo e(htmlspecialchars($member['username'])); ?>"
                                               required>
                                        <?php if (isset($errors['username'])): ?>
                                            <div class="form-error"><?php echo e($errors['username']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="email" class="form-label required">
                                            Email Address
                                        </label>
                                        <input type="email" 
                                               id="email" 
                                               name="email" 
                                               class="form-control <?php echo isset($errors['email']) ? 'error' : ''; ?>" 
                                               value="<?php echo e(htmlspecialchars($member['email'])); ?>"
                                               required>
                                        <?php if (isset($errors['email'])): ?>
                                            <div class="form-error"><?php echo e($errors['email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="role" class="form-label required">
                                            Role
                                        </label>
                                        <select id="role" name="role" class="form-control" required>
                                            <option value="developer" <?php echo ($member['role'] == 'developer') ? 'selected' : ''; ?>>Developer</option>
                                            <option value="team_leader" <?php echo ($member['role'] == 'team_leader') ? 'selected' : ''; ?>>Team Leader</option>
                                            <option value="content_manager" <?php echo ($member['role'] == 'content_manager') ? 'selected' : ''; ?>>Content Manager</option>
                                            <option value="admin" <?php echo ($member['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                            <?php if ($session->isSuperAdmin()): ?>
                                                <option value="super_admin" <?php echo ($member['role'] == 'super_admin') ? 'selected' : ''; ?>>Super Admin</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <a href="<?php echo url('/admin/modules/team/reset-password.php?id=' . $member_id); ?>" 
                                           class="btn btn-outline">
                                            <i class="fas fa-key"></i> Reset Password
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Social Links -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-share-alt"></i>
                                        Social Links
                                    </h4>
                                    
                                    <div class="form-group">
                                        <label for="linkedin_url" class="form-label">
                                            LinkedIn Profile
                                        </label>
                                        <div class="input-with-icon">
                                            <i class="fab fa-linkedin"></i>
                                            <input type="url" 
                                                   id="linkedin_url" 
                                                   name="linkedin_url" 
                                                   class="form-control" 
                                                   value="<?php echo e(htmlspecialchars($member['linkedin_url'] ?? '')); ?>"
                                                   placeholder="https://linkedin.com/in/username">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="github_url" class="form-label">
                                            GitHub Profile
                                        </label>
                                        <div class="input-with-icon">
                                            <i class="fab fa-github"></i>
                                            <input type="url" 
                                                   id="github_url" 
                                                   name="github_url" 
                                                   class="form-control" 
                                                   value="<?php echo e(htmlspecialchars($member['github_url'] ?? '')); ?>"
                                                   placeholder="https://github.com/username">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Team Assignment -->
                                <div class="form-section">
                                    <h4 class="form-section-title">
                                        <i class="fas fa-users"></i>
                                        Team Assignment
                                    </h4>
                                    
                                    <p>Assign this member to one or more teams:</p>
                                    
                                    <div class="team-checkboxes">
                                        <?php if (!empty($teams)): ?>
                                            <?php foreach ($teams as $team): ?>
                                                <div class="team-checkbox-item">
                                                    <input type="checkbox" 
                                                           id="team_<?php echo $team['team_id']; ?>" 
                                                           name="teams[]" 
                                                           value="<?php echo $team['team_id']; ?>"
                                                           <?php echo (in_array($team['team_id'], $current_team_ids)) ? 'checked' : ''; ?>>
                                                    <label for="team_<?php echo $team['team_id']; ?>" class="team-checkbox-label">
                                                        <?php echo e($team['team_name']); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-gray-500">No teams available. <a href="<?php echo url('/admin/modules/team/teams.php'); ?>">Create a team first</a></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" name="update_member" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Update Member
                            </button>
                            <a href="<?php echo url('/admin/modules/team/'); ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Danger Zone -->
            <?php if ($member_id != $user_id): ?>
                <div class="danger-zone">
                    <h4><i class="fas fa-exclamation-triangle"></i> Danger Zone</h4>
                    <p>Once you delete a team member's account, there is no going back. Please be certain.</p>
                    <div style="margin-top: 15px;">
                        <a href="<?php echo url('/admin/modules/team/delete.php?id=' . $member_id); ?>" 
                           class="btn btn-danger"
                           onclick="return confirm('Are you sure you want to permanently delete this team member? This action cannot be undone.')">
                            <i class="fas fa-trash"></i> Permanently Delete Member
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('../../../assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Profile image preview
            const profileImageInput = document.getElementById('profile_image');
            const profileImagePreview = document.getElementById('profileImagePreview');
            
            if (profileImageInput && profileImagePreview) {
                profileImageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            // Clear existing content
                            profileImagePreview.innerHTML = '';
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.alt = 'Profile preview';
                            profileImagePreview.appendChild(img);
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
            
            // Form validation
            const form = document.getElementById('editMemberForm');
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
                        }
                    });
                    
                    if (!valid) {
                        e.preventDefault();
                        showNotification('Please fill in all required fields', 'error');
                    }
                });
            }
        });
    </script>
</body>
</html>