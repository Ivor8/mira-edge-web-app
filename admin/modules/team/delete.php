<?php
/**
 * Delete Team Member
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

// Don't allow deletion of current user
if ($member_id == $user_id) {
    $session->setFlash('error', 'You cannot delete your own account.');
    redirect(url('/admin/modules/team/'));
}

// Get member data
$stmt = $db->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
$stmt->execute([$member_id]);
$member = $stmt->fetch();

if (!$member) {
    $session->setFlash('error', 'Team member not found.');
    redirect(url('/admin/modules/team/'));
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = $_POST['confirm'] ?? '';
    
    if ($confirm === 'DELETE') {
        try {
            // Instead of deleting, we'll deactivate and anonymize the account
            $stmt = $db->prepare("
                UPDATE users 
                SET status = 'inactive', 
                    email = CONCAT('deleted_', UNIX_TIMESTAMP(), '_', email),
                    username = CONCAT('deleted_', UNIX_TIMESTAMP(), '_', username),
                    first_name = 'Deleted',
                    last_name = 'User',
                    phone = NULL,
                    profile_image = NULL,
                    position = NULL,
                    bio = NULL,
                    linkedin_url = NULL,
                    github_url = NULL,
                    updated_at = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$member_id]);
            
            // Remove from all teams
            $stmt = $db->prepare("DELETE FROM user_teams WHERE user_id = ?");
            $stmt->execute([$member_id]);
            
            $session->setFlash('success', 'Team member deleted successfully!');
            redirect(url('/admin/modules/team/'));
            
        } catch (PDOException $e) {
            error_log("Delete Member Error: " . $e->getMessage());
            $session->setFlash('error', 'Error deleting team member: ' . $e->getMessage());
            redirect(url('/admin/modules/team/'));
        }
    } else {
        $session->setFlash('error', 'Confirmation text did not match.');
        redirect(url('/admin/modules/team/edit.php?id=' . $member_id));
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Team Member | Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
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
                    <i class="fas fa-user-times"></i>
                    Delete Team Member
                </h1>
                <div class="page-actions">
                    <a href="<?php echo url('/admin/modules/team/edit.php?id=' . $member_id); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Member
                    </a>
                </div>
            </div>

            <div class="card" style="max-width: 600px; margin: 0 auto;">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle" style="color: var(--color-error);"></i>
                        Confirm Deletion
                    </h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Warning:</strong> This action is irreversible!
                    </div>
                    
                    <p>You are about to delete the team member:</p>
                    <div class="alert alert-warning" style="font-size: 1.2rem; font-weight: bold; text-align: center; margin: 20px 0;">
                        <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?>
                    </div>
                    
                    <p><strong>This will:</strong></p>
                    <ul style="margin: 15px 0 15px 20px; color: var(--color-gray-700);">
                        <li>Anonymize all user data</li>
                        <li>Remove user from all teams</li>
                        <li>Prevent user from logging in</li>
                        <li>Preserve user's activity logs for auditing</li>
                    </ul>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        For security and auditing purposes, accounts are anonymized rather than permanently deleted from the database.
                    </div>
                    
                    <form method="POST" action="" id="deleteForm">
                        <div class="form-group">
                            <label for="confirm" class="form-label required">
                                To confirm, type <strong>DELETE</strong> in the box below:
                            </label>
                            <input type="text" 
                                   id="confirm" 
                                   name="confirm" 
                                   class="form-control" 
                                   required
                                   placeholder="Type DELETE to confirm">
                        </div>
                        
                        <div class="form-actions" style="margin-top: 30px;">
                            <button type="submit" 
                                    name="delete_member" 
                                    class="btn btn-danger btn-lg"
                                    onclick="return confirm('Are you absolutely sure? This cannot be undone!')">
                                <i class="fas fa-trash"></i> Permanently Delete Member
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
            const form = document.getElementById('deleteForm');
            const confirmInput = document.getElementById('confirm');
            
            form.addEventListener('submit', function(e) {
                if (confirmInput.value !== 'DELETE') {
                    e.preventDefault();
                    alert('You must type DELETE to confirm deletion.');
                    confirmInput.focus();
                }
            });
        });
    </script>
</body>
</html>