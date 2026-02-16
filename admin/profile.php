<?php
/**
 * Admin Profile Page
 */

require_once '../includes/core/Database.php';
require_once '../includes/core/Session.php';
require_once '../includes/core/Auth.php';
require_once '../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

// Check if user is logged in
if (!$session->isLoggedIn()) {
    redirect('/login.php');
}

$user = $session->getUser();
$user_id = $user['user_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Update profile
        $data = [
            'first_name' => sanitize($_POST['first_name']),
            'last_name' => sanitize($_POST['last_name']),
            'phone' => sanitize($_POST['phone']),
            'position' => sanitize($_POST['position']),
            'bio' => sanitize($_POST['bio']),
            'linkedin_url' => sanitize($_POST['linkedin_url']),
            'github_url' => sanitize($_POST['github_url'])
        ];
        
        $result = $auth->updateProfile($user_id, $data);
        
        if ($result['success']) {
            $session->setFlash('success', 'Profile updated successfully!');
            redirect('/admin/profile.php');
        } else {
            $session->setFlash('error', $result['message']);
        }
        
    } elseif (isset($_POST['change_password'])) {
        // Change password
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $session->setFlash('error', 'New passwords do not match.');
        } elseif (strlen($new_password) < 8) {
            $session->setFlash('error', 'Password must be at least 8 characters.');
        } else {
            $result = $auth->changePassword($user_id, $current_password, $new_password);
            
            if ($result['success']) {
                $session->setFlash('success', 'Password changed successfully!');
            } else {
                $session->setFlash('error', $result['message']);
            }
        }
    }
}

// Get updated user data
$user = $auth->getUserById($user_id);
$session->setUser($user);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Admin Header -->
    <?php include 'includes/admin-header.php'; ?>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <?php include 'includes/admin-sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">My Profile</h1>
                <div class="page-actions">
                    <a href="/admin/" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
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

            <!-- Profile Content -->
            <div class="profile-content">
                <!-- Profile Overview -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user"></i>
                            Profile Information
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <?php if (!empty($user['profile_image'])): ?>
                                    <img src="<?php echo e($user['profile_image']); ?>" alt="<?php echo e($user['first_name']); ?>">
                                <?php else: ?>
                                    <div class="avatar-placeholder-large">
                                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <button class="btn btn-outline btn-sm" id="changeAvatarBtn">
                                    <i class="fas fa-camera"></i> Change Photo
                                </button>
                            </div>
                            <div class="profile-info">
                                <h2 class="profile-name"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                                <p class="profile-position">
                                    <i class="fas fa-briefcase"></i>
                                    <?php echo e($user['position'] ?: 'No position set'); ?>
                                </p>
                                <p class="profile-email">
                                    <i class="fas fa-envelope"></i>
                                    <?php echo e($user['email']); ?>
                                </p>
                                <p class="profile-role">
                                    <i class="fas fa-user-tag"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                </p>
                                <div class="profile-stats">
                                    <div class="stat">
                                        <span class="stat-value">12</span>
                                        <span class="stat-label">Projects</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-value">45</span>
                                        <span class="stat-label">Tasks</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-value">3</span>
                                        <span class="stat-label">Teams</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Forms -->
                <div class="content-grid">
                    <!-- Update Profile Form -->
                    <div class="content-column">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-edit"></i>
                                    Update Profile
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="first_name" class="form-label">First Name</label>
                                            <input type="text" 
                                                   id="first_name" 
                                                   name="first_name" 
                                                   class="form-control" 
                                                   value="<?php echo e($user['first_name']); ?>"
                                                   required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="last_name" class="form-label">Last Name</label>
                                            <input type="text" 
                                                   id="last_name" 
                                                   name="last_name" 
                                                   class="form-control" 
                                                   value="<?php echo e($user['last_name']); ?>"
                                                   required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" 
                                                   id="email" 
                                                   class="form-control" 
                                                   value="<?php echo e($user['email']); ?>"
                                                   disabled>
                                            <small class="form-text">Email cannot be changed</small>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="phone" class="form-label">Phone Number</label>
                                            <input type="tel" 
                                                   id="phone" 
                                                   name="phone" 
                                                   class="form-control" 
                                                   value="<?php echo e($user['phone'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="position" class="form-label">Position</label>
                                            <input type="text" 
                                                   id="position" 
                                                   name="position" 
                                                   class="form-control" 
                                                   value="<?php echo e($user['position'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="linkedin_url" class="form-label">LinkedIn Profile</label>
                                            <input type="url" 
                                                   id="linkedin_url" 
                                                   name="linkedin_url" 
                                                   class="form-control" 
                                                   value="<?php echo e($user['linkedin_url'] ?? ''); ?>"
                                                   placeholder="https://linkedin.com/in/username">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="github_url" class="form-label">GitHub Profile</label>
                                            <input type="url" 
                                                   id="github_url" 
                                                   name="github_url" 
                                                   class="form-control" 
                                                   value="<?php echo e($user['github_url'] ?? ''); ?>"
                                                   placeholder="https://github.com/username">
                                        </div>
                                        
                                        <div class="form-group form-group-full">
                                            <label for="bio" class="form-label">Bio</label>
                                            <textarea id="bio" 
                                                      name="bio" 
                                                      class="form-control" 
                                                      rows="4"><?php echo e($user['bio'] ?? ''); ?></textarea>
                                        </div>
                                        
                                        <div class="form-group form-group-full">
                                            <button type="submit" 
                                                    name="update_profile" 
                                                    class="btn btn-primary">
                                                <i class="fas fa-save"></i> Update Profile
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Change Password Form -->
                    <div class="content-column">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-lock"></i>
                                    Change Password
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="form-group">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <div class="password-input">
                                            <input type="password" 
                                                   id="current_password" 
                                                   name="current_password" 
                                                   class="form-control" 
                                                   required>
                                            <button type="button" class="password-toggle">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <div class="password-input">
                                            <input type="password" 
                                                   id="new_password" 
                                                   name="new_password" 
                                                   class="form-control" 
                                                   required>
                                            <button type="button" class="password-toggle">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <small class="form-text">Minimum 8 characters</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <div class="password-input">
                                            <input type="password" 
                                                   id="confirm_password" 
                                                   name="confirm_password" 
                                                   class="form-control" 
                                                   required>
                                            <button type="button" class="password-toggle">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <button type="submit" 
                                                name="change_password" 
                                                class="btn btn-primary">
                                            <i class="fas fa-key"></i> Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Account Information -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-info-circle"></i>
                                    Account Information
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="info-list">
                                    <div class="info-item">
                                        <span class="info-label">User ID:</span>
                                        <span class="info-value">#<?php echo e($user['user_id']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Username:</span>
                                        <span class="info-value"><?php echo e($user['username']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Account Created:</span>
                                        <span class="info-value"><?php echo formatDate($user['created_at']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Last Login:</span>
                                        <span class="info-value">
                                            <?php echo $user['last_login'] ? formatDate($user['last_login']) : 'Never'; ?>
                                        </span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Account Status:</span>
                                        <span class="info-value">
                                            <span class="status-badge status-<?php echo strtolower($user['status']); ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="/assets/js/admin.js"></script>
    <script>
        // Profile page specific JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality
            document.querySelectorAll('.password-toggle').forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const input = this.parentElement.querySelector('input');
                    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                    input.setAttribute('type', type);
                    
                    const icon = this.querySelector('i');
                    if (type === 'text') {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });
            
            // Change avatar button
            const changeAvatarBtn = document.getElementById('changeAvatarBtn');
            if (changeAvatarBtn) {
                changeAvatarBtn.addEventListener('click', function() {
                    const input = document.createElement('input');
                    input.type = 'file';
                    input.accept = 'image/*';
                    input.onchange = function(e) {
                        const file = e.target.files[0];
                        if (file) {
                            // Here you would upload the file via AJAX
                            // For now, just show a notification
                            showNotification('Avatar upload functionality will be implemented soon!', 'info');
                        }
                    };
                    input.click();
                });
            }
            
            // Form validation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                const passwordForm = form.querySelector('#new_password');
                const confirmPasswordForm = form.querySelector('#confirm_password');
                
                if (passwordForm && confirmPasswordForm) {
                    form.addEventListener('submit', function(e) {
                        if (passwordForm.value !== confirmPasswordForm.value) {
                            e.preventDefault();
                            showNotification('Passwords do not match!', 'error');
                            confirmPasswordForm.focus();
                        }
                        
                        if (passwordForm.value.length < 8) {
                            e.preventDefault();
                            showNotification('Password must be at least 8 characters!', 'error');
                            passwordForm.focus();
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>