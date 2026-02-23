<?php
/**
 * Contact Messages API
 * Handles contact form submission
 */

// Ensure no HTML/notice output is sent
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/core/Database.php';
require_once '../includes/core/Session.php';
require_once '../includes/functions/helpers.php';

$session = new Session();
$db = Database::getInstance()->getConnection();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Accept JSON or form-encoded submissions
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
        } else {
            // Try normal POST first, then parse raw body (for form-urlencoded)
            $input = $_POST;
            if (empty($input)) {
                parse_str(file_get_contents('php://input'), $input);
            }
        }

        if (!is_array($input)) {
            $input = [];
        }

        // Log the input for debugging
        error_log("Contact API received: " . json_encode($input));

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $message = trim($input['message'] ?? '');
        $company = trim($input['company'] ?? '');

        // Validation
        if (!$name || !$email || !$subject || !$message) {
            throw new Exception('All required fields must be filled');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address');
        }

        if (strlen($message) < 10) {
            throw new Exception('Message must be at least 10 characters long');
        }

        // Insert into database
        $stmt = $db->prepare(
            "INSERT INTO contact_messages (name, email, subject, message, phone, company) VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([$name, $email, $subject, $message, $phone, $company]);
        $insertId = $db->lastInsertId();

        // Send success response
        $payload = json_encode([
            'success' => true,
            'message' => 'Message sent successfully',
            'id' => $insertId
        ]);

        // Clean any buffered output (avoid accidental HTML) and log it if present
        $extra = trim(ob_get_clean());
        if (!empty($extra)) {
            error_log("Contact API extra output (success): " . $extra);
        }
        echo $payload;
        exit();

    } else {
        http_response_code(405);
        $payload = json_encode(['success' => false, 'message' => 'Method not allowed']);
        $extra = trim(ob_get_clean());
        if (!empty($extra)) {
            error_log("Contact API extra output (method not allowed): " . $extra);
        }
        echo $payload;
        exit();
    }

} catch (Exception $e) {
    error_log("Contact API Error: " . $e->getMessage());
    http_response_code(400);
    $payload = json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    $extra = trim(ob_get_clean());
    if (!empty($extra)) {
        error_log("Contact API extra output (exception): " . $extra);
    }
    echo $payload;
    exit();
}
<parameter name="filePath">c:\xampp\htdocs\Mira-Edge\api\contact.php