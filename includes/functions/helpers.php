<?php
/**
 * Helper Functions
 */

require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/Auth.php';

// Initialize session
$session = new Session();

/**
 * Generate a URL for the application
 */
// function url($path = '') {
//     $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
//     $host = $_SERVER['HTTP_HOST'];
//     $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    
//     // Remove leading slash from path if present
//     $path = ltrim($path, '/');
    
//     return $protocol . '://' . $host . $basePath . '/' . $path;
// }

function url($path = '') {

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];

    // Detect project root folder automatically
    $projectFolder = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'))[0];

    // Remove leading slash from path
    $path = ltrim($path, '/');

    return $protocol . '://' . $host . '/' . $projectFolder . '/' . $path;
}

/**
 * Redirect to a URL
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    global $session;
    return $session->isLoggedIn();
}

/**
 * Require login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        redirect('/login.php');
    }
}

/**
 * Require specific role
 */
function requireRole($role) {
    global $session;
    requireLogin();
    
    $userRole = $session->getUserRole();
    
    if (is_array($role)) {
        if (!in_array($userRole, $role)) {
            $session->setFlash('error', 'Access denied. Insufficient permissions.');
            redirect('/');
        }
    } else {
        if ($userRole !== $role) {
            $session->setFlash('error', 'Access denied. Insufficient permissions.');
            redirect('/');
        }
    }
}

/**
 * Escape HTML output
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Format date
 */
function formatDate($date, $format = 'F j, Y') {
    if (!$date) return '';
    $dateTime = new DateTime($date);
    return $dateTime->format($format);
}

/**
 * Generate slug from string
 */
function generateSlug($string) {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

/**
 * Get current URL
 */
function currentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    return $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Sanitize input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return trim(htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8'));
}

/**
 * Upload file
 */
function uploadFile($file, $directory, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif']) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error'];
    }
    
    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type'];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $uploadPath = $directory . '/' . $filename;
    
    // Create directory if it doesn't exist
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return ['success' => true, 'filename' => $filename, 'path' => $uploadPath];
    }
    
    return ['success' => false, 'error' => 'Failed to move uploaded file'];
}

/**
 * Get pagination data
 */
function paginate($totalItems, $itemsPerPage, $currentPage) {
    $totalPages = ceil($totalItems / $itemsPerPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $itemsPerPage;
    
    return [
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages
    ];
}

/**
 * Generate SEO-friendly URL
 */
function seoUrl($string) {
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9-\s]/', '', $string);
    $string = preg_replace('/\s+/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

/**
 * Get site setting
 */
function getSetting($key, $default = '') {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}
?>