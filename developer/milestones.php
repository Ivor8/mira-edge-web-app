<?php
/**
 * Developer Milestones - Main Entry Point
 * Redirects to the milestones management
 */

require_once '../includes/core/Database.php';
require_once '../includes/core/Session.php';
require_once '../includes/core/Auth.php';
require_once '../includes/functions/helpers.php';

$session = new Session();

if (!$session->isLoggedIn()) {
    redirect(url('/login.php'));
}

if (!$session->isDeveloper()) {
    $session->setFlash('error', 'Access denied. Developer privileges required.');
    redirect(url('/'));
}

// Redirect to milestones module
redirect(url('/developer/modules/projects/milestones.php'));
?>