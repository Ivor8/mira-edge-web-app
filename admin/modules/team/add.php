<?php
/**
 * Add New Team Member
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

// Get teams for assignment
$stmt = $db->query("SELECT team_id, team_name FROM teams WHERE status = 'active' ORDER BY team_name");
$teams = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_member'])) {
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
    } else {
        // Check if username exists
        $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $errors['username'] = 'Username already exists';
        }
    }
    
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } else {
        // Check if email exists
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Email already registered';
        }
    }
    
    // Generate random password
    $password = generateRandomPassword(12);
    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    
    // Handle profile image upload
    $profile_image = null;
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
            $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . time() . '.' . $ext;
            $upload_dir = dirname(dirname(dirname(dirname(__FILE__)))) . '/assets/uploads/team/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $filename)) {
                $profile_image = '/assets/uploads/team/' . $filename;
            } else {
                $errors['profile_image'] = 'Failed to upload image';
            }
        }
    }
    
    // If no errors, insert user
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                INSERT INTO users (
                    username, email, password_hash, first_name, last_name, phone,
                    profile_image, position, role, bio, linkedin_url, github_url,
                    status, email_verified, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, NOW())
            ");
            
            $stmt->execute([
                $username, $email, $password_hash, $first_name, $last_name, $phone,
                $profile_image, $position, $role, $bio, $linkedin_url, $github_url
            ]);
            
            $new_user_id = $db->lastInsertId();
            
            // Assign to teams
            if (!empty($selected_teams)) {
                foreach ($selected_teams as $team_id) {
                    $stmt = $db->prepare("
                        INSERT INTO user_teams (user_id, team_id, is_active)
                        VALUES (?, ?, 1)
                    ");
                    $stmt->execute([$new_user_id, $team_id]);
                }
            }
            
            $db->commit();
            
            $session->setFlash('success', 'Team member added successfully!');
            $session->setFlash('info', "Login credentials:<br>Username: <strong>{$username}</strong><br>Password: <strong>{$password}</strong>");
            redirect(url('/admin/modules/team/edit.php?id=' . $new_user_id));
            
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Add Member Error: " . $e->getMessage());
            $errors['general'] = 'Error adding team member: ' . $e->getMessage();
        }
    }
}

function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Team Member | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .add-member-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .form-section {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-xl);
            margin-bottom: var(--space-xl);
            border: 1px solid var(--color-gray-200);
            box-shadow: var(--shadow-md);
        }
        
        .form-section-title {
            font-size: 1.25rem;
            margin-bottom: var(--space-lg);
            color: var(--color-black);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding-bottom: var(--space-sm);
            border-bottom: 2px solid var(--color-gray-200);
        }
        
        .form-section-title i {
            color: var(--color-gray-500);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-xl);
        }
        
        @media (max-width: 992px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
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
            padding: 12px 16px;
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
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-lg);
        }
        
        .image-upload-wrapper {
            text-align: center;
            margin-bottom: var(--space-xl);
        }
        
        .profile-image-preview {
            width: 150px;
            height: 150px;
            margin: 0 auto var(--space-md);
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--color-gray-200);
            position: relative;
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--color-gray-100);
        }
        
        .profile-image-preview:hover {
            border-color: var(--color-black);
            transform: scale(1.05);
        }
        
        .profile-image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-image-preview .placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--color-black), var(--color-gray-700));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: bold;
        }
        
        .file-input {
            display: none;
        }
        
        .image-upload-text {
            font-size: 0.875rem;
            color: var(--color-gray-600);
            margin-top: var(--space-sm);
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm) 0;
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
        
        .checkbox-label i {
            color: var(--color-gray-500);
            width: 16px;
        }
        
        .team-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: var(--space-sm);
            margin-top: var(--space-sm);
            max-height: 300px;
            overflow-y: auto;
            padding: var(--space-sm);
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-md);
        }
        
        .team-checkbox-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }
        
        .team-checkbox-item:hover {
            background: var(--color-gray-100);
            transform: translateY(-2px);
        }
        
        .team-checkbox-item input[type="checkbox"] {
            margin: 0;
        }
        
        .team-checkbox-label {
            cursor: pointer;
            flex: 1;
            font-size: 0.875rem;
        }
        
        .form-actions {
            display: flex;
            gap: var(--space-md);
            justify-content: flex-end;
            margin-top: var(--space-xl);
            padding-top: var(--space-lg);
            border-top: 2px solid var(--color-gray-200);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
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
        
        .btn-lg {
            padding: 14px 28px;
            font-size: 1rem;
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
        
        .password-generator {
            background: var(--color-gray-50);
            padding: var(--space-lg);
            border-radius: var(--radius-md);
            margin-top: var(--space-lg);
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
            <div class="add-member-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-user-plus"></i>
                        Add New Team Member
                    </h1>
                    <div class="page-actions">
                        <a href="<?php echo url('/admin/modules/team/index.php'); ?>" class="btn btn-outline">
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
                        
                        <?php if ($session->hasFlash('info')): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <div class="alert-content"><?php echo $session->getFlash('info'); ?></div>
                                <button class="alert-close">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Add Member Form -->
                <form method="POST" action="" enctype="multipart/form-data" id="addMemberForm">
                    <?php if (isset($errors['general'])): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <div class="alert-content"><?php echo e($errors['general']); ?></div>
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
                                        <div class="placeholder">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>
                                    <input type="file" 
                                           id="profile_image" 
                                           name="profile_image" 
                                           class="file-input"
                                           accept="image/*">
                                    <button type="button" class="btn btn-outline" onclick="document.getElementById('profile_image').click()">
                                        <i class="fas fa-camera"></i> Upload Profile Image
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
                                               value="<?php echo e($_POST['first_name'] ?? ''); ?>"
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
                                               value="<?php echo e($_POST['last_name'] ?? ''); ?>"
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
                                               value="<?php echo e($_POST['position'] ?? ''); ?>"
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
                                               value="<?php echo e($_POST['phone'] ?? ''); ?>"
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
                                              placeholder="Tell us about this team member..."><?php echo e($_POST['bio'] ?? ''); ?></textarea>
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
                                    <input type="url" 
                                           id="linkedin_url" 
                                           name="linkedin_url" 
                                           class="form-control" 
                                           value="<?php echo e($_POST['linkedin_url'] ?? ''); ?>"
                                           placeholder="https://linkedin.com/in/username">
                                </div>
                                
                                <div class="form-group">
                                    <label for="github_url" class="form-label">
                                        GitHub Profile
                                    </label>
                                    <input type="url" 
                                           id="github_url" 
                                           name="github_url" 
                                           class="form-control" 
                                           value="<?php echo e($_POST['github_url'] ?? ''); ?>"
                                           placeholder="https://github.com/username">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="form-column">
                            <!-- Login Credentials -->
                            <div class="form-section">
                                <h4 class="form-section-title">
                                    <i class="fas fa-key"></i>
                                    Login Credentials
                                </h4>
                                
                                <div class="password-generator">
                                    <p class="text-gray-600">A strong random password will be generated automatically.</p>
                                </div>
                                
                                <div class="form-group">
                                    <label for="username" class="form-label required">
                                        Username
                                    </label>
                                    <input type="text" 
                                           id="username" 
                                           name="username" 
                                           class="form-control <?php echo isset($errors['username']) ? 'error' : ''; ?>" 
                                           value="<?php echo e($_POST['username'] ?? ''); ?>"
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
                                           value="<?php echo e($_POST['email'] ?? ''); ?>"
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
                                        <option value="developer" <?php echo (($_POST['role'] ?? 'developer') == 'developer') ? 'selected' : ''; ?>>Developer</option>
                                        <option value="team_leader" <?php echo (($_POST['role'] ?? '') == 'team_leader') ? 'selected' : ''; ?>>Team Leader</option>
                                        <option value="content_manager" <?php echo (($_POST['role'] ?? '') == 'content_manager') ? 'selected' : ''; ?>>Content Manager</option>
                                        <option value="admin" <?php echo (($_POST['role'] ?? '') == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                        <?php if ($session->isSuperAdmin()): ?>
                                            <option value="super_admin" <?php echo (($_POST['role'] ?? '') == 'super_admin') ? 'selected' : ''; ?>>Super Admin</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Team Assignment -->
                            <div class="form-section">
                                <h4 class="form-section-title">
                                    <i class="fas fa-users"></i>
                                    Team Assignment
                                </h4>
                                
                                <p class="text-gray-600">Assign this member to one or more teams:</p>
                                
                                <div class="team-checkboxes">
                                    <?php if (!empty($teams)): ?>
                                        <?php foreach ($teams as $team): ?>
                                            <div class="team-checkbox-item">
                                                <input type="checkbox" 
                                                       id="team_<?php echo $team['team_id']; ?>" 
                                                       name="teams[]" 
                                                       value="<?php echo $team['team_id']; ?>"
                                                       <?php echo (isset($_POST['teams']) && in_array($team['team_id'], $_POST['teams'])) ? 'checked' : ''; ?>>
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
                            
                            <!-- Account Settings -->
                            <div class="form-section">
                                <h4 class="form-section-title">
                                    <i class="fas fa-cog"></i>
                                    Account Settings
                                </h4>
                                
                                <div class="checkbox-wrapper">
                                    <input type="checkbox" 
                                           id="send_welcome_email" 
                                           name="send_welcome_email" 
                                           value="1" 
                                           checked>
                                    <label for="send_welcome_email" class="checkbox-label">
                                        <i class="fas fa-envelope"></i>
                                        Send welcome email with login credentials
                                    </label>
                                </div>
                                
                                <div class="checkbox-wrapper">
                                    <input type="checkbox" 
                                           id="require_password_change" 
                                           name="require_password_change" 
                                           value="1" 
                                           checked>
                                    <label for="require_password_change" class="checkbox-label">
                                        <i class="fas fa-sync-alt"></i>
                                        Require password change on first login
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" name="add_member" class="btn btn-primary btn-lg">
                            <i class="fas fa-user-plus"></i> Create Team Member
                        </button>
                        <a href="<?php echo url('/admin/modules/team/index.php'); ?>" class="btn btn-outline btn-lg">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('/assets/js/admin.js'); ?>"></script>
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
                            const placeholder = profileImagePreview.querySelector('.placeholder');
                            if (placeholder) {
                                placeholder.remove();
                            }
                            
                            let img = profileImagePreview.querySelector('img');
                            if (!img) {
                                img = document.createElement('img');
                                profileImagePreview.appendChild(img);
                            }
                            img.src = e.target.result;
                            img.alt = 'Profile preview';
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
            
            // Username generation from email
            const emailInput = document.getElementById('email');
            const usernameInput = document.getElementById('username');
            
            if (emailInput && usernameInput) {
                emailInput.addEventListener('blur', function() {
                    if (!usernameInput.value && this.value) {
                        const email = this.value;
                        const atIndex = email.indexOf('@');
                        if (atIndex > 0) {
                            let username = email.substring(0, atIndex);
                            username = username.replace(/[^a-zA-Z0-9_]/g, '_').toLowerCase();
                            usernameInput.value = username;
                        }
                    }
                });
            }
            
            // Form validation
            const form = document.getElementById('addMemberForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    let valid = true;
                    
                    const requiredFields = form.querySelectorAll('[required]');
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            valid = false;
                            field.classList.add('error');
                            
                            const existingError = field.parentNode.querySelector('.form-error');
                            if (!existingError) {
                                const error = document.createElement('div');
                                error.className = 'form-error';
                                error.textContent = 'This field is required';
                                field.parentNode.appendChild(error);
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