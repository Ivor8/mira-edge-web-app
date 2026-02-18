<?php
/**
 * Developer Projects - Main Entry Point
 * Redirects to the projects module
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
    redirect(url('/'));
}

// Redirect to the projects module
redirect(url('/developer/modules/projects/index.php'));
?>