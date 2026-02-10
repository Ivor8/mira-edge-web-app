<?php
/**
 * Reset Password for Team Member
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

// Get member ID
$member_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$member_id) {
    $session->setFlash('error', 'Invalid member ID.');
    redirect(url('/admin/modules/team/'));
}

// Get member data
$stmt = $db->prepare("SELECT user_id, email, first_name, last_name FROM users WHERE user_id = ?");
$stmt->execute([$member_id]);
$member = $stmt->fetch();

if (!$member) {
    $session->setFlash('error', 'Team member not found.');
    redirect(url('/admin/modules/team/'));
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Generate random password
    $password = generateRandomPassword(12);
    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    
    try {
        // Update password
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$password_hash, $member_id]);
        
        // Send password reset email (you would implement this)
        // $this->sendPasswordResetEmail($member['email'], $password);
        
        $session->setFlash('success', 'Password reset successfully!');
        $session->setFlash('info', "New password for {$member['first_name']} {$member['last_name']}: <strong>{$password}</strong><br>Send this to the user securely.");
        
        redirect(url('/admin/modules/team/edit.php?id=' . $member_id));
        
    } catch (PDOException $e) {
        error_log("Password Reset Error: " . $e->getMessage());
        $session->setFlash('error', 'Error resetting password: ' . $e->getMessage());
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
    <title>Reset Password | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('../../../assets/css/admin.css'); ?>">
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
                    <i class="fas fa-key"></i>
                    Reset Password
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/team/edit.php?id=' . $member_id); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Member
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
                    
                    <?php if ($session->hasFlash('info')): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <?php echo e($session->getFlash('info')); ?>
                            <button class="alert-close">&times;</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Reset Password Form -->
            <div class="card" style="max-width: 600px; margin: 0 auto;">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-user-shield"></i>
                        Reset Password for <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>
                    </h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> This will generate a new random password for the user. 
                        The old password will no longer work.
                    </div>
                    
                    <p>The new password will be automatically generated and displayed. You must send it to the user securely.</p>
                    
                    <div class="password-generator" style="background: var(--color-gray-50); padding: 20px; border-radius: var(--radius-md); margin: 20px 0;">
                        <h4>Generated Password Preview:</h4>
                        <div class="password-display" style="display: flex; gap: 10px; margin-top: 10px;">
                            <input type="text" 
                                   id="generatedPassword" 
                                   class="password-field" 
                                   readonly
                                   style="flex: 1; padding: 10px; font-family: monospace; font-size: 14px;"
                                   value="Click 'Generate & Reset' to create password">
                            <button type="button" id="copyPassword" class="copy-password" disabled>
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                    </div>
                    
                    <form method="POST" action="" id="resetPasswordForm">
                        <div class="form-group">
                            <label class="form-label">
                                <input type="checkbox" name="send_email" value="1" checked>
                                Send password reset email to <?php echo e($member['email']); ?>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <input type="checkbox" name="require_change" value="1" checked>
                                Require password change on next login
                            </label>
                        </div>
                        
                        <div class="form-actions" style="margin-top: 30px;">
                            <button type="submit" name="reset_password" class="btn btn-primary btn-lg">
                                <i class="fas fa-key"></i> Generate & Reset Password
                            </button>
                            <a href="<?php echo url('/admin/modules/team/edit.php?id=' . $member_id); ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('resetPasswordForm');
            const passwordField = document.getElementById('generatedPassword');
            const copyButton = document.getElementById('copyPassword');
            
            // Generate preview password
            function generatePassword(length = 12) {
                const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
                let password = '';
                for (let i = 0; i < length; i++) {
                    password += chars[Math.floor(Math.random() * chars.length)];
                }
                return password;
            }
            
            // Update password preview
            passwordField.value = generatePassword();
            copyButton.disabled = false;
            
            // Copy password to clipboard
            copyButton.addEventListener('click', function() {
                navigator.clipboard.writeText(passwordField.value).then(() => {
                    // Show success feedback
                    const originalText = copyButton.innerHTML;
                    copyButton.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    copyButton.classList.add('success');
                    
                    setTimeout(() => {
                        copyButton.innerHTML = originalText;
                        copyButton.classList.remove('success');
                    }, 2000);
                });
            });
            
            // Form submission
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to reset this user\'s password?')) {
                    e.preventDefault();
                    return;
                }
                
                // Disable submit button and show loading
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            });
        });
    </script>
</body>
</html>