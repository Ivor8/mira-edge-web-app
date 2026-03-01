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
        
        if ($result['success'] !== false) {
            $session->setFlash('success', 'Profile updated successfully!');
            redirect(url('/admin/profile.php'));
        } else {
            $session->setFlash('error', $result['message'] ?? 'Failed to update profile');
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
            
            if ($result['success'] !== false) {
                $session->setFlash('success', 'Password changed successfully!');
            } else {
                $session->setFlash('error', $result['message'] ?? 'Failed to change password');
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
    <style>
        .profile-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: var(--space-lg);
            align-items: start;
        }

        @media (max-width: 1024px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        .profile-card {
            text-align: center;
            padding: 0;
        }

        .profile-avatar-section {
            padding: var(--space-lg);
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            background: linear-gradient(135deg, var(--color-primary-50), var(--color-secondary-50));
        }

        .profile-avatar-display {
            width: 140px;
            height: 140px;
            margin: 0 auto var(--space-md);
            background: var(--color-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            color: white;
            overflow: hidden;
            border: 4px solid white;
            box-shadow: var(--shadow-sm);
        }

        .profile-avatar-display img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0 0 var(--space-xs);
            color: var(--color-gray-900);
        }

        .profile-position {
            color: var(--color-gray-600);
            margin: 0 0 var(--space-md);
            font-size: 0.95rem;
        }

        .profile-badge {
            display: inline-block;
            padding: var(--space-xs) var(--space-sm);
            background: var(--color-primary-100);
            color: var(--color-primary);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 500;
            margin-top: var(--space-sm);
        }

        .profile-details {
            padding: var(--space-lg);
            border-top: 1px solid var(--color-gray-200);
        }

        .profile-detail-item {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            padding: var(--space-md) 0;
            border-bottom: 1px solid var(--color-gray-100);
            text-align: left;
            font-size: 0.95rem;
        }

        .profile-detail-item:last-child {
            border-bottom: none;
        }

        .profile-detail-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--color-gray-100);
            border-radius: var(--radius-md);
            color: var(--color-primary);
        }

        .profile-detail-content {
            flex: 1;
        }

        .profile-detail-label {
            font-size: 0.8rem;
            color: var(--color-gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-detail-value {
            font-weight: 500;
            color: var(--color-gray-900);
            margin-top: var(--space-xs);
        }

        .form-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-md);
        }

        .form-section-full {
            grid-column: 1 / -1;
        }

        .form-action-buttons {
            margin-top: var(--space-lg);
            display: flex;
            gap: var(--space-sm);
        }

        .password-field {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: var(--space-md);
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--color-gray-500);
            cursor: pointer;
            padding: var(--space-xs) var(--space-sm);
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: var(--color-primary);
        }
    </style>
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

            <!-- Profile Content Grid -->
            <div class="profile-grid">
                <!-- Left Column: Profile Card + Info -->
                <div class="card profile-card">
                    <div class="profile-avatar-section">
                        <div class="profile-avatar-display">
                            <?php if (!empty($user['profile_image'])): ?>
                                <img src="<?php echo e($user['profile_image']); ?>" alt="<?php echo e($user['first_name']); ?>">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <h2 class="profile-name"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                        <p class="profile-position">
                            <i class="fas fa-briefcase"></i>
                            <?php echo e($user['position'] ?: 'No position set'); ?>
                        </p>
                        <span class="profile-badge">
                            <i class="fas fa-user-tag"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $user['role'] ?? 'User')); ?>
                        </span>
                        <button class="btn btn-outline btn-sm" id="changeAvatarBtn" style="width: calc(100% - var(--space-md)); margin-top: var(--space-md);">
                            <i class="fas fa-camera"></i> Change Photo
                        </button>
                    </div>

                    <div class="profile-details">
                        <div class="profile-detail-item">
                            <div class="profile-detail-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="profile-detail-content">
                                <div class="profile-detail-label">Email</div>
                                <div class="profile-detail-value"><?php echo e($user['email']); ?></div>
                            </div>
                        </div>

                        <div class="profile-detail-item">
                            <div class="profile-detail-icon">
                                <i class="fas fa-id-badge"></i>
                            </div>
                            <div class="profile-detail-content">
                                <div class="profile-detail-label">User ID</div>
                                <div class="profile-detail-value">#<?php echo e($user['user_id']); ?></div>
                            </div>
                        </div>

                        <div class="profile-detail-item">
                            <div class="profile-detail-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="profile-detail-content">
                                <div class="profile-detail-label">Username</div>
                                <div class="profile-detail-value"><?php echo e($user['username']); ?></div>
                            </div>
                        </div>

                        <div class="profile-detail-item">
                            <div class="profile-detail-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="profile-detail-content">
                                <div class="profile-detail-label">Member Since</div>
                                <div class="profile-detail-value"><?php echo formatDate($user['created_at'], 'M d, Y'); ?></div>
                            </div>
                        </div>

                        <div class="profile-detail-item">
                            <div class="profile-detail-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="profile-detail-content">
                                <div class="profile-detail-label">Account Status</div>
                                <div class="profile-detail-value">
                                    <span class="status-badge status-<?php echo strtolower($user['status'] ?? 'active'); ?>">
                                        <?php echo ucfirst($user['status'] ?? 'Active'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Forms -->
                <div style="display: flex; flex-direction: column; gap: var(--space-lg);">
                    <!-- Update Profile Form -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-edit"></i>
                                Update Profile
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="form-section">
                                    <div class="form-group">
                                        <label for="first_name" class="form-label required">First Name</label>
                                        <input type="text" id="first_name" name="first_name" class="form-control" value="<?php echo e($user['first_name']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="last_name" class="form-label required">Last Name</label>
                                        <input type="text" id="last_name" name="last_name" class="form-control" value="<?php echo e($user['last_name']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo e($user['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="position" class="form-label">Position</label>
                                        <input type="text" id="position" name="position" class="form-control" value="<?php echo e($user['position'] ?? ''); ?>" placeholder="e.g., Senior Developer">
                                    </div>
                                    <div class="form-group">
                                        <label for="linkedin_url" class="form-label">LinkedIn Profile</label>
                                        <input type="url" id="linkedin_url" name="linkedin_url" class="form-control" value="<?php echo e($user['linkedin_url'] ?? ''); ?>" placeholder="https://linkedin.com/in/username">
                                    </div>
                                    <div class="form-group">
                                        <label for="github_url" class="form-label">GitHub Profile</label>
                                        <input type="url" id="github_url" name="github_url" class="form-control" value="<?php echo e($user['github_url'] ?? ''); ?>" placeholder="https://github.com/username">
                                    </div>
                                    <div class="form-group form-section-full">
                                        <label for="bio" class="form-label">Bio</label>
                                        <textarea id="bio" name="bio" class="form-control" rows="3" placeholder="Tell us about yourself..."><?php echo e($user['bio'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="form-action-buttons">
                                    <button type="submit" name="update_profile" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Change Password Form -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-lock"></i>
                                Change Password
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="form-section form-section-full">
                                    <div class="form-group">
                                        <label for="current_password" class="form-label required">Current Password</label>
                                        <div class="password-field">
                                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                                            <button type="button" class="password-toggle" data-target="current_password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="new_password" class="form-label required">New Password</label>
                                        <div class="password-field">
                                            <input type="password" id="new_password" name="new_password" class="form-control" required>
                                            <button type="button" class="password-toggle" data-target="new_password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <small class="form-text">Minimum 8 characters</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="confirm_password" class="form-label required">Confirm New Password</label>
                                        <div class="password-field">
                                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                                            <button type="button" class="password-toggle" data-target="confirm_password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-action-buttons">
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        <i class="fas fa-key"></i> Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality
            document.querySelectorAll('.password-toggle').forEach(btn => {
                btn.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    const icon = this.querySelector('i');
                    
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        input.type = 'password';
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
                            alert('Avatar upload functionality will be implemented soon!');
                        }
                    };
                    input.click();
                });
            }
        });
    </script>
</body>
</html>