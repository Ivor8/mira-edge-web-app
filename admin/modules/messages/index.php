<?php
/**
 * Admin - Contact Messages
 */

require_once '../../../includes/core/Database.php';
require_once '../../../includes/core/Session.php';
require_once '../../../includes/core/Auth.php';
require_once '../../../includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();
$db = Database::getInstance()->getConnection();

if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect(url('/login.php'));
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect(url('/'));
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'], $_POST['delete_key'])) {
    $delete_id = (int)$_POST['delete_id'];
    $delete_key = $_POST['delete_key'];
    $allowed_keys = ['id','message_id','contact_id','contact_message_id','messageid','contactmessageid'];
    if (!in_array($delete_key, $allowed_keys)) {
        $session->setFlash('error', 'Invalid delete request.');
        redirect(url('/admin/modules/messages/index.php'));
    }

    try {
        $sql = "DELETE FROM contact_messages WHERE `" . str_replace('`', '', $delete_key) . "` = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$delete_id]);
        $session->setFlash('success', 'Message deleted successfully.');
    } catch (PDOException $e) {
        error_log('Delete message error: ' . $e->getMessage());
        $session->setFlash('error', 'Error deleting message.');
    }
    redirect(url('/admin/modules/messages/index.php'));
}

// Fetch messages
$stmt = $db->query("SELECT * FROM contact_messages ORDER BY received_at DESC");
$messages = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Messages | Admin</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .message-preview { color: var(--color-gray-700); }
        .message-row:hover { background: var(--color-gray-50); }
        .msg-actions .btn { margin-right: var(--space-sm); }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; }
        .message-modal { background:#fff; padding:20px; width:90%; max-width:800px; border-radius:8px; box-shadow:var(--shadow-lg); }
        .message-modal .modal-header { display:flex; justify-content:space-between; align-items:center; }
    </style>
</head>
<body>
    <?php include '../../includes/admin-header.php'; ?>
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        <main class="admin-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-envelope"></i> Contact Messages</h1>
            </div>

            <?php if ($session->hasFlash()): ?>
                <div class="flash-messages">
                    <?php if ($session->hasFlash('success')): ?>
                        <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?><button class="alert-close">&times;</button></div>
                    <?php endif; ?>
                    <?php if ($session->hasFlash('error')): ?>
                        <div class="alert alert-error"><?php echo e($session->getFlash('error')); ?><button class="alert-close">&times;</button></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Subject</th>
                            <th>Message</th>
                            <th>Phone</th>
                            <th>Received</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($messages)): ?>
                            <tr><td colspan="7">No messages yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($messages as $m): ?>
                            <tr class="message-row">
                                <td><strong><?php echo e($m['name']); ?></strong></td>
                                <td><?php echo e($m['email']); ?></td>
                                <td><?php echo e($m['subject']); ?></td>
                                <td class="message-preview"><?php echo e(substr($m['message'], 0, 120)) . (strlen($m['message'])>120 ? '...' : ''); ?></td>
                                <td><?php echo e($m['phone'] ?? '-'); ?></td>
                                <td><?php echo formatDate($m['received_at'] ?? $m['created_at'] ?? date('Y-m-d H:i:s'), 'M d, Y H:i'); ?></td>
                                <td class="msg-actions">
                                    <button class="btn btn-sm btn-outline" onclick='openMessageModal(<?php echo json_encode(array(
                                        'id' => $m['id'] ?? $m['message_id'] ?? null,
                                        'name' => $m['name'],
                                        'email' => $m['email'],
                                        'phone' => $m['phone'] ?? '',
                                        'subject' => $m['subject'],
                                        'message' => $m['message'],
                                        'company' => $m['company'] ?? '',
                                        'received_at' => $m['received_at'] ?? $m['created_at'] ?? ''
                                    )); ?>)'><i class="fas fa-eye"></i> View</button>

                                    <?php
                                        $id_key = null;
                                        foreach (['id','message_id','contact_id','contact_message_id','messageid','contactmessageid'] as $k) {
                                            if (isset($m[$k])) { $id_key = $k; break; }
                                        }
                                        $delete_val = $id_key ? $m[$id_key] : '';
                                    ?>
                                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete this message?');">
                                        <input type="hidden" name="delete_key" value="<?php echo e($id_key); ?>">
                                        <input type="hidden" name="delete_id" value="<?php echo e($delete_val); ?>">
                                        <button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Message Modal -->
    <div id="messageModalBackdrop" class="modal-backdrop">
        <div class="message-modal" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h3 id="modalSubject">Message</h3>
                <button onclick="closeMessageModal()" class="btn btn-outline">&times;</button>
            </div>
            <div class="modal-body">
                <p><strong>From:</strong> <span id="modalName"></span> (<span id="modalEmail"></span>)</p>
                <p><strong>Phone:</strong> <span id="modalPhone"></span></p>
                <p><strong>Company:</strong> <span id="modalCompany"></span></p>
                <p><strong>Received:</strong> <span id="modalReceived"></span></p>
                <hr>
                <div id="modalMessage" style="white-space:pre-wrap;"></div>
            </div>
        </div>
    </div>

    <script>
    function openMessageModal(data) {
        document.getElementById('modalSubject').textContent = data.subject || 'Message';
        document.getElementById('modalName').textContent = data.name || '-';
        document.getElementById('modalEmail').textContent = data.email || '-';
        document.getElementById('modalPhone').textContent = data.phone || '-';
        document.getElementById('modalCompany').textContent = data.company || '-';
        document.getElementById('modalReceived').textContent = data.received_at || '-';
        document.getElementById('modalMessage').textContent = data.message || '';
        document.getElementById('messageModalBackdrop').style.display = 'flex';
    }
    function closeMessageModal(){
        document.getElementById('messageModalBackdrop').style.display = 'none';
    }
    </script>
    <script src="<?php echo url('assets/js/admin.js'); ?>"></script>
</body>
</html>
