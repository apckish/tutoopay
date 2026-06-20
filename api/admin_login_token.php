<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$user_id = intval($body['user_id'] ?? 0);

if (!$user_id) {
    json_error('user_id is required');
}

$db = get_db();

// Verify user exists
$stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$db->close();

if (!$user) {
    json_error('User not found', 404);
}

// Generate signed token: user_id + timestamp + HMAC
$secret = 'TutooPay_AdminLogin_Secret_2026!';
$timestamp = time();
$payload = $user_id . ':' . $timestamp;
$signature = hash_hmac('sha256', $payload, $secret);
$token = base64_encode($payload . ':' . $signature);

json_response([
    'success' => true,
    'token' => $token,
    'url' => 'https://upload.tutoopay.com/admin_login_as.php?token=' . urlencode($token),
]);
?>
