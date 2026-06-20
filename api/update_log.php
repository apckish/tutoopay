<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

// Admin-only check
if (($_SESSION['admin_role'] ?? '') !== 'admin') {
    json_error('Admin access required', 403);
}

$body = get_json_body();
$log_id = intval($body['log_id'] ?? 0);

if ($log_id <= 0) {
    json_error('Invalid log ID');
}

$db = get_db();

// Fetch existing log to verify it exists and is editable (admin_paid or direct_paid)
$stmt = $db->prepare("SELECT * FROM payment_logs WHERE id = ?");
$stmt->bind_param("i", $log_id);
$stmt->execute();
$log = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$log) {
    $db->close();
    json_error('Log entry not found');
}

$editable_actions = ['admin_paid', 'direct_paid', 'admin_paid_failed'];
if (!in_array($log['action'], $editable_actions)) {
    $db->close();
    json_error('Only direct/admin payment logs can be edited');
}

// Build update fields
$updates = [];
$types = '';
$values = [];

if (isset($body['usdt_amount'])) {
    $updates[] = 'usdt_amount = ?';
    $types .= 'd';
    $values[] = floatval($body['usdt_amount']);
}
if (isset($body['wallet_address'])) {
    $updates[] = 'wallet_address = ?';
    $types .= 's';
    $values[] = trim($body['wallet_address']);
}
if (isset($body['user_name'])) {
    $updates[] = 'user_name = ?';
    $types .= 's';
    $values[] = trim($body['user_name']);
}
if (isset($body['user_id'])) {
    $updates[] = 'user_id = ?';
    $types .= 'i';
    $values[] = intval($body['user_id']);
}
if (isset($body['user_email'])) {
    $updates[] = 'user_email = ?';
    $types .= 's';
    $values[] = trim($body['user_email']);
}
if (isset($body['coinex_withdraw_id'])) {
    $updates[] = 'coinex_withdraw_id = ?';
    $types .= 's';
    $values[] = trim($body['coinex_withdraw_id']);
}

if (empty($updates)) {
    $db->close();
    json_error('No fields to update');
}

$types .= 'i';
$values[] = $log_id;

$sql = "UPDATE payment_logs SET " . implode(', ', $updates) . " WHERE id = ?";
$upd_stmt = $db->prepare($sql);
$upd_stmt->bind_param($types, ...$values);
$upd_stmt->execute();
$upd_stmt->close();

$db->close();

json_response([
    'success' => true,
    'message' => 'Log entry updated successfully',
    'log_id' => $log_id,
]);
?>
