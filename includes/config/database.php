<?php
/**
 * Database Configuration for Mira Edge Technologies
 * SECURITY WARNING: Change these credentials in production!
 */

class DatabaseConfig {
    // Database connection settings
    const DB_HOST = 'localhost';
    const DB_NAME = 'mira_edge_technologies';
    const DB_USER = 'root'; // Change this to your MySQL username
    const DB_PASS = ''; // Change this to your MySQL password
    
    // Application settings
    const SITE_NAME = 'Mira Edge Technologies';
    const SITE_URL = 'http://localhost/mira-edge-technologies'; // Change for production
    const ADMIN_EMAIL = 'admin@miraedgetech.com';
    
    // Security settings
    const SESSION_TIMEOUT = 3600; // 1 hour in seconds
    const PASSWORD_COST = 12; // bcrypt cost factor
    
    // File upload settings
    const MAX_FILE_SIZE = 5242880; // 5MB in bytes
    const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const ALLOWED_DOC_TYPES = ['application/pdf', 'application/msword', 
                               'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    
    // Email settings
    const SMTP_HOST = 'smtp.gmail.com'; // Change as needed
    const SMTP_PORT = 587;
    const SMTP_USER = 'noreply@miraedgetech.com';
    const SMTP_PASS = 'your-smtp-password';
    const SMTP_ENCRYPTION = 'tls';
}

// Enable error reporting for development
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error reporting settings
if (strpos(DatabaseConfig::SITE_URL, 'localhost') !== false) {
    // Development environment
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // Production environment
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set timezone for Cameroon
date_default_timezone_set('Africa/Douala');
?>