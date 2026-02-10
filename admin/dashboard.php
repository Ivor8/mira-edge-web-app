<?php
/**
 * Admin Dashboard Page
 * This redirects to the main admin index page
 */

require_once '../includes/core/Database.php';
require_once '../includes/core/Session.php';
require_once '../includes/core/Auth.php';
require_once '../includes/functions/helpers.php';

// Initialize
$session = new Session();
$auth = new Auth();

// Check if user is logged in and is admin
if (!$session->isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    redirect('../login.php');
}

if (!$session->isAdmin()) {
    $session->setFlash('error', 'Access denied. Admin privileges required.');
    redirect('/');
}

// Simply redirect to the main admin index
redirect('/mira edge/admin/');
?>