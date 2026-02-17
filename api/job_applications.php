<?php
/**
 * API endpoint to accept job applications from the careers page.
 * Expects multipart/form-data with fields: job_id, name, email, phone, portfolio, linkedin, cover_letter and file resume
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/core/Database.php';
require_once __DIR__ . '/../includes/functions/helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit;
    }

    $db = Database::getInstance()->getConnection();

    // Debug: log incoming request data for troubleshooting
    error_log('Job app POST data: ' . print_r($_POST, true));
    error_log('Job app FILES data: ' . print_r($_FILES, true));

    // Validate required fields
    $job_id = isset($_POST['job_id']) ? (int) $_POST['job_id'] : 0;
    $name = isset($_POST['name']) ? sanitize($_POST['name']) : '';
    $email = isset($_POST['email']) ? sanitize($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize($_POST['phone']) : '';
    $portfolio = isset($_POST['portfolio']) ? sanitize($_POST['portfolio']) : '';
    $linkedin = isset($_POST['linkedin']) ? sanitize($_POST['linkedin']) : '';
    $cover = isset($_POST['cover_letter']) ? trim($_POST['cover_letter']) : '';

    if (!$job_id || empty($name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    // Handle resume upload
    $resume_web_path = null;
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE) {
        $allowed = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];

        $uploadResult = uploadFile($_FILES['resume'], __DIR__ . '/../uploads/resumes', $allowed);

        if (!$uploadResult['success']) {
            echo json_encode(['success' => false, 'message' => $uploadResult['error']]);
            exit;
        }

        $resume_web_path = url('/uploads/resumes/' . $uploadResult['filename']);
    }

    // Insert into database
    $stmt = $db->prepare("INSERT INTO job_applications (job_id, applicant_name, applicant_email, applicant_phone, cover_letter, resume_path, portfolio_url, linkedin_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $job_id,
        $name,
        $email,
        $phone,
        $cover,
        $resume_web_path,
        $portfolio,
        $linkedin
    ]);

    $insertId = $db->lastInsertId();
    error_log('Job application inserted with ID: ' . $insertId);

    echo json_encode(['success' => true, 'message' => 'Application submitted successfully', 'application_id' => $insertId]);
    exit;

} catch (PDOException $e) {
    error_log('Job application API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}
