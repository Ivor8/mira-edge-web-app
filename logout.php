<?php
/**
 * Logout - destroys session and clears remember-me token
 */

require_once 'includes/core/Database.php';
require_once 'includes/core/Session.php';
require_once 'includes/core/Auth.php';
require_once 'includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();

$auth->logout();

// Ensure session is destroyed
$session->destroy();

// Redirect to login page
redirect(url('/login.php'));
