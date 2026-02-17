<?php
/**
 * API endpoint to accept service orders from the services page.
 * Expects JSON body with fields: service_id, package_id (optional), name, email, phone, company, requirements, budget, payment_method
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/core/Database.php';
require_once __DIR__ . '/../includes/functions/helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $service_id = isset($input['service_id']) ? (int)$input['service_id'] : 0;
    $package_id = isset($input['package_id']) && $input['package_id'] !== '' ? (int)$input['package_id'] : null;
    $name = isset($input['name']) ? sanitize($input['name']) : '';
    $email = isset($input['email']) ? sanitize($input['email']) : '';
    $phone = isset($input['phone']) ? sanitize($input['phone']) : '';
    $company = isset($input['company']) ? sanitize($input['company']) : '';
    $requirements = isset($input['requirements']) ? trim($input['requirements']) : '';
    $budget = isset($input['budget']) && $input['budget'] !== '' ? (float)$input['budget'] : null;
    $payment_method = isset($input['payment_method']) ? sanitize($input['payment_method']) : 'mobile_money';

    if (!$service_id || empty($name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $db = Database::getInstance()->getConnection();

    // Insert order
    $stmt = $db->prepare("INSERT INTO service_orders (order_number, client_name, client_email, client_phone, client_company, service_id, package_id, requirements, budget, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Temporarily set order_number as empty; will update after getting insert id
    $tempOrderNumber = '';
    $stmt->execute([
        $tempOrderNumber,
        $name,
        $email,
        $phone,
        $company,
        $service_id,
        $package_id,
        $requirements,
        $budget,
        $payment_method
    ]);

    $orderId = $db->lastInsertId();
    if (!$orderId) {
        echo json_encode(['success' => false, 'message' => 'Failed to create order']);
        exit;
    }

    // Generate order number: MIRA-YYYY-XXXXX
    $orderNumber = 'MIRA-' . date('Y') . '-' . str_pad($orderId, 5, '0', STR_PAD_LEFT);

    // Update record with order number
    $stmt = $db->prepare("UPDATE service_orders SET order_number = ? WHERE order_id = ?");
    $stmt->execute([$orderNumber, $orderId]);

    echo json_encode(['success' => true, 'order_number' => $orderNumber, 'order_id' => $orderId]);
    exit;

} catch (PDOException $e) {
    error_log('Orders API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}
