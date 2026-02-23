<?php
/**
 * Blog Comments API
 * Handles comment submission and retrieval
 */

header('Content-Type: application/json');
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
        // Handle comment submission
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            throw new Exception('Invalid JSON data');
        }

        // Log the input for debugging
        error_log("Comment API received: " . json_encode($input));

        $post_id = (int)($input['post_id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $website = trim($input['website'] ?? '');
        $comment = trim($input['comment'] ?? '');

        // Validation
        if (!$post_id || !$name || !$email || !$comment) {
            throw new Exception('All required fields must be filled');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address');
        }

        if (strlen($name) < 2 || strlen($name) > 100) {
            throw new Exception('Name must be between 2 and 100 characters');
        }

        if (strlen($comment) < 10 || strlen($comment) > 2000) {
            throw new Exception('Comment must be between 10 and 2000 characters');
        }

        // Check if post exists and is published
        $stmt = $db->prepare("SELECT post_id FROM blog_posts WHERE post_id = ? AND status = 'published'");
        $stmt->execute([$post_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Blog post not found or not published');
        }

        // Basic spam check
        $is_spam = 0;
        $spam_keywords = ['viagra', 'casino', 'lottery', 'winner', 'free money', 'bitcoin', 'crypto'];
        $comment_lower = strtolower($comment);
        foreach ($spam_keywords as $keyword) {
            if (strpos($comment_lower, $keyword) !== false) {
                $is_spam = 1;
                break;
            }
        }

        // Insert comment
        $stmt = $db->prepare("
            INSERT INTO blog_comments (post_id, name, email, website, comment, is_approved, is_spam, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt->execute([
            $post_id,
            $name,
            $email,
            $website ?: null,
            $comment,
            0, // is_approved - requires admin approval
            $is_spam,
            $ip_address,
            $user_agent
        ]);

        $comment_id = $db->lastInsertId();

        // Log activity
        if ($session->isLoggedIn()) {
            $user = $session->getUser();
            $stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, activity_type, description, ip_address, user_agent)
                VALUES (?, 'comment_submitted', ?, ?, ?)
            ");
            $stmt->execute([
                $user['user_id'],
                "Comment submitted on blog post ID: $post_id",
                $ip_address,
                $user_agent
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Your comment has been submitted and is awaiting approval.',
            'comment_id' => $comment_id
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Handle comment retrieval (optional - for admin moderation)
        $post_id = (int)($_GET['post_id'] ?? 0);

        if (!$post_id) {
            throw new Exception('Post ID is required');
        }

        // Check if user is admin
        if (!$session->isLoggedIn() || !$session->isAdmin()) {
            throw new Exception('Unauthorized access');
        }

        $stmt = $db->prepare("
            SELECT * FROM blog_comments
            WHERE post_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$post_id]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'comments' => $comments
        ]);

    } else {
        throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    error_log("Comment API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.'
    ]);
}
?>