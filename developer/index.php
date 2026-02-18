<?php
/**
 * Developer Dashboard Entry Point
 * Redirects to dashboard
 */

require_once '../includes/core/Database.php';
require_once '../includes/core/Session.php';
require_once '../includes/core/Auth.php';
require_once '../includes/functions/helpers.php';

$session = new Session();

// Check if logged in and is developer
if (!$session->isLoggedIn()) {
    redirect(url('/login.php'));
}

if (!$session->isDeveloper()) {
    $session->setFlash('error', 'Access denied. Developer privileges required.');
    redirect('/');
}

redirect('/developer/dashboard.php');
?>